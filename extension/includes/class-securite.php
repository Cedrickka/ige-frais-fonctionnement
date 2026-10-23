<?php
/**
 * Sécurité des connexions : comptes désactivés, verrouillage après échecs, première connexion,
 * déconnexion après inactivité, accès à l'administration WordPress réservé aux administrateurs.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Règles de sécurité.
 */
final class IGE_FFM_Securite {

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'authenticate' ), 99, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 5, 2 );
		add_action( 'wp_login', array( __CLASS__, 'login_ok' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'inactivite' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'premiere_connexion' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'bloquer_admin' ) );
		add_filter( 'login_form_top', array( __CLASS__, 'messages_connexion' ) );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'duree_session' ), 10, 3 );
		add_filter( 'ige_ff_menu_location', array( __CLASS__, 'menu_premiere_connexion' ) );
		add_action( 'validate_password_reset', array( __CLASS__, 'valider_reinitialisation' ), 10, 2 );
	}

	/**
	 * Refuse la connexion d'un compte désactivé ou verrouillé.
	 *
	 * @param WP_User|WP_Error|null $user     Résultat de l'authentification.
	 * @param string                $username Identifiant.
	 * @param string                $password Mot de passe.
	 * @return WP_User|WP_Error|null
	 */
	public static function authenticate( $user, $username, $password ) {
		$target = $user instanceof WP_User ? $user : ( $username ? get_user_by( 'login', $username ) : null );
		if ( ! $target ) {
			return $user;
		}
		$verrou = (int) get_user_meta( $target->ID, '_ige_verrou', true );
		if ( $verrou > time() ) {
			return new WP_Error( 'ige_verrouille', sprintf( 'Compte verrouillé après plusieurs essais. Réessayez dans %d minutes.', (int) ceil( ( $verrou - time() ) / 60 ) ) );
		}
		if ( $user instanceof WP_User ) {
			$c = IGE_FFM_Comptes::get( $user->ID );
			if ( $c && ! (int) $c['actif'] ) {
				return new WP_Error( 'ige_inactif', "Ce compte est désactivé. Contactez l'administrateur de l'application." );
			}
		}
		return $user;
	}

	/**
	 * Compte les échecs et verrouille le compte au-delà du seuil.
	 *
	 * @param string        $username Identifiant saisi.
	 * @param WP_Error|null $error    Erreur.
	 */
	public static function login_failed( $username, $error = null ) {
		$code = $error instanceof WP_Error ? $error->get_error_code() : '';
		$user = get_user_by( 'login', $username ) ?: get_user_by( 'email', $username );

		if ( $user && ! in_array( $code, array( 'ige_verrouille', 'ige_inactif' ), true ) ) {
			$n = (int) get_user_meta( $user->ID, '_ige_echecs', true ) + 1;
			update_user_meta( $user->ID, '_ige_echecs', $n );
			IGE_FFM_Audit::log( 'echec_connexion', 'compte', $user->ID, null, array( 'essai' => $n ), 0 );
			if ( $n >= (int) IGE_FFM_Reglages::get( 'verrouillage_essais' ) ) {
				update_user_meta( $user->ID, '_ige_verrou', time() + 60 * (int) IGE_FFM_Reglages::get( 'verrouillage_minutes' ) );
				update_user_meta( $user->ID, '_ige_echecs', 0 );
				IGE_FFM_Audit::log( 'verrouillage', 'compte', $user->ID, null, array( 'minutes' => (int) IGE_FFM_Reglages::get( 'verrouillage_minutes' ) ), 0 );
				$code = 'ige_verrouille';
			}
		} elseif ( ! $user ) {
			IGE_FFM_Audit::log( 'echec_connexion', 'identifiant', substr( sanitize_user( $username ), 0, 60 ), null, null, 0 );
		}

		// Depuis la page d'accueil : revenir avec un message précis.
		if ( in_array( $code, array( 'ige_verrouille', 'ige_inactif' ), true ) ) {
			$ref = wp_get_referer();
			if ( $ref && 0 === strpos( $ref, home_url() ) && false === strpos( $ref, 'wp-login' ) ) {
				wp_safe_redirect( add_query_arg( 'login', 'ige_verrouille' === $code ? 'verrouille' : 'inactif', remove_query_arg( 'login', $ref ) ) );
				exit;
			}
		}
	}

	/**
	 * Connexion réussie : remise à zéro des échecs, date de dernière connexion.
	 *
	 * @param string  $login Identifiant.
	 * @param WP_User $user  Utilisateur.
	 */
	public static function login_ok( $login, $user ) {
		global $wpdb;
		delete_user_meta( $user->ID, '_ige_echecs' );
		delete_user_meta( $user->ID, '_ige_verrou' );
		update_user_meta( $user->ID, '_ige_activite', time() );
		$wpdb->update( IGE_FFM_DB::t( 'compte' ), array( 'derniere_connexion' => ige_ffm_now() ), array( 'user_id' => $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'connexion', 'compte', $user->ID, null, null, $user->ID );
	}

	/**
	 * Déconnexion automatique après inactivité.
	 */
	public static function inactivite() {
		if ( ! is_user_logged_in() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$uid  = get_current_user_id();
		$last = (int) get_user_meta( $uid, '_ige_activite', true );
		$max  = 60 * (int) IGE_FFM_Reglages::get( 'inactivite_minutes' );
		if ( $last && time() - $last > $max ) {
			IGE_FFM_Audit::log( 'deconnexion_inactivite', 'compte', $uid, null, null, $uid );
			delete_user_meta( $uid, '_ige_activite' );
			wp_logout();
			if ( wp_doing_ajax() ) {
				wp_send_json_error( array( 'session' => 'expiree' ), 401 );
			}
			wp_safe_redirect( add_query_arg( 'session', 'expiree', home_url( '/' ) ) );
			exit;
		}
		if ( time() - $last > 60 ) {
			update_user_meta( $uid, '_ige_activite', time() );
		}
	}

	/**
	 * Oblige à choisir un mot de passe personnel à la première connexion.
	 */
	public static function premiere_connexion() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$c = IGE_FFM_Comptes::get( get_current_user_id() );
		if ( ! $c || ! (int) $c['mdp_a_changer'] ) {
			if ( is_page( 'premiere-connexion' ) ) {
				wp_safe_redirect( home_url( '/' ) );
				exit;
			}
			return;
		}
		if ( ! is_page( 'premiere-connexion' ) ) {
			wp_safe_redirect( ige_ffm_url( 'premiere-connexion' ) );
			exit;
		}
	}

	/**
	 * Les utilisateurs de l'application n'ont pas accès à l'administration de WordPress.
	 */
	public static function bloquer_admin() {
		if ( current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) ) : '';
		if ( in_array( $script, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	/**
	 * Messages au-dessus du formulaire de connexion (page d'accueil du thème).
	 *
	 * @param string $html HTML existant.
	 * @return string
	 */
	public static function messages_connexion( $html ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$login   = isset( $_GET['login'] ) ? sanitize_key( $_GET['login'] ) : '';
		$session = isset( $_GET['session'] ) ? sanitize_key( $_GET['session'] ) : '';
		// phpcs:enable
		$msg = '';
		if ( 'verrouille' === $login ) {
			$msg = ige_ffm_banner( 'ko', 'Compte verrouillé', ' ' . sprintf( 'Trop d’essais erronés. Réessayez dans %d minutes ou contactez l’administrateur.', (int) IGE_FFM_Reglages::get( 'verrouillage_minutes' ) ) );
		} elseif ( 'inactif' === $login ) {
			$msg = ige_ffm_banner( 'ko', 'Compte désactivé', ' Contactez l’administrateur de l’application.' );
		} elseif ( 'expiree' === $session ) {
			$msg = ige_ffm_banner( 'info', 'Session expirée', ' ' . sprintf( 'Vous avez été déconnecté après %d minutes d’inactivité.', (int) IGE_FFM_Reglages::get( 'inactivite_minutes' ) ) );
		}
		return $html . ( $msg ? '<div style="margin-bottom:16px">' . $msg . '</div>' : '' );
	}

	/**
	 * Durée maximale d'une session : 12 heures (une journée de travail), même avec « rester connecté ».
	 *
	 * @param int  $length   Durée proposée.
	 * @param int  $user_id  Utilisateur.
	 * @param bool $remember Rester connecté.
	 * @return int
	 */
	public static function duree_session( $length, $user_id, $remember ) {
		return min( $length, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Applique la politique de mot de passe au formulaire WordPress de réinitialisation.
	 *
	 * @param WP_Error $errors Erreurs.
	 * @param WP_User  $user   Utilisateur.
	 */
	public static function valider_reinitialisation( $errors, $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
		if ( '' === $pass || ! $user instanceof WP_User ) {
			return;
		}
		$check = IGE_FFM_Comptes::check_strength( $pass, $user );
		if ( is_wp_error( $check ) && 'mdp' === $check->get_error_code() && false === strpos( $check->get_error_message(), 'provisoire' ) ) {
			$errors->add( 'ige_mdp', $check->get_error_message() );
		}
	}

	/**
	 * Pas de menu tant que le mot de passe provisoire n'a pas été changé.
	 *
	 * @param string $location Emplacement de menu.
	 * @return string
	 */
	public static function menu_premiere_connexion( $location ) {
		$c = is_user_logged_in() ? IGE_FFM_Comptes::get( get_current_user_id() ) : null;
		return $c && (int) $c['mdp_a_changer'] ? '' : $location;
	}
}
