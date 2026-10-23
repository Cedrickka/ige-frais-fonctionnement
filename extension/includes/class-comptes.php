<?php
/**
 * Comptes utilisateurs de l'application.
 *
 * Un compte = un utilisateur WordPress + une ligne dans {prefix}ige_compte (service, profil,
 * état, obligation de changer le mot de passe). L'Admin de l'application ne peut créer que
 * des comptes Agent, Responsable, Admin ou Lecteur : jamais un administrateur du site.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gestion des comptes.
 */
final class IGE_FFM_Comptes {

	/**
	 * Crochets.
	 */
	public static function init() {
		// Libellé du service affiché par le thème sous le nom de l'utilisateur.
		add_filter( 'ige_ff_profil_libelle', array( __CLASS__, 'libelle_theme' ), 10, 2 );
	}

	/**
	 * Libellé pour l'en-tête du thème.
	 *
	 * @param string  $label Libellé proposé.
	 * @param WP_User $user  Utilisateur.
	 * @return string
	 */
	public static function libelle_theme( $label, $user ) {
		$c = self::get( $user->ID );
		if ( ! $c ) {
			return $label;
		}
		$txt = IGE_FFM_Roles::libelle( $c['profil'] );
		if ( $c['service_id'] ) {
			$s    = IGE_FFM_Referentiels::get( 'service', (int) $c['service_id'] );
			$txt  = preg_replace( '/ \(service\)$/', '', $txt );
			$txt .= $s ? ' · ' . $s['code'] : '';
		}
		return $txt;
	}

	/**
	 * Ligne de compte.
	 *
	 * @param int $user_id Utilisateur.
	 * @return array|null
	 */
	public static function get( $user_id ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'compte' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE user_id = %d", $user_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Service rattaché à un utilisateur (0 si aucun).
	 *
	 * @param int|null $user_id Utilisateur (courant par défaut).
	 * @return int
	 */
	public static function service_id( $user_id = null ) {
		$c = self::get( $user_id ?? get_current_user_id() );
		return $c ? (int) $c['service_id'] : 0;
	}

	/**
	 * Liste des comptes de l'application.
	 *
	 * @param array $f Filtres : profil, service_id, actif.
	 * @return array[] Lignes enrichies (login, nom, email).
	 */
	public static function search( array $f = array() ) {
		global $wpdb;
		$t     = IGE_FFM_DB::t( 'compte' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['profil'] ) ) {
			$where[] = 'c.profil = %s';
			$args[]  = $f['profil'];
		}
		if ( ! empty( $f['service_id'] ) ) {
			$where[] = 'c.service_id = %d';
			$args[]  = (int) $f['service_id'];
		}
		if ( isset( $f['actif'] ) && '' !== $f['actif'] ) {
			$where[] = 'c.actif = %d';
			$args[]  = (int) $f['actif'];
		}
		$sql = "SELECT c.*, u.user_login, u.display_name, u.user_email FROM $t c INNER JOIN {$wpdb->users} u ON u.ID = c.user_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY c.profil, u.user_login';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
	}

	/**
	 * Valide les données d'un compte.
	 *
	 * @param array $d       Données.
	 * @param int   $user_id Compte existant (0 pour une création).
	 * @return true|WP_Error
	 */
	private static function validate( array $d, $user_id = 0 ) {
		if ( ! isset( IGE_FFM_Roles::roles()[ $d['profil'] ] ) ) {
			return new WP_Error( 'profil', 'Choisissez un profil.' );
		}
		if ( 'service' === IGE_FFM_Roles::profil( $d['profil'] ) ) {
			$s = $d['service_id'] ? IGE_FFM_Referentiels::get( 'service', $d['service_id'] ) : null;
			if ( ! $s || ! (int) $s['actif'] ) {
				return new WP_Error( 'service_id', 'Un compte de service doit être rattaché à un service actif.' );
			}
		}
		if ( '' === $d['display_name'] ) {
			return new WP_Error( 'display_name', 'Le nom complet est obligatoire.' );
		}
		if ( '' !== $d['user_email'] && ! is_email( $d['user_email'] ) ) {
			return new WP_Error( 'user_email', "L'adresse e-mail n'est pas valide." );
		}
		if ( '' !== $d['user_email'] ) {
			$other = email_exists( $d['user_email'] );
			if ( $other && (int) $other !== (int) $user_id ) {
				return new WP_Error( 'user_email', 'Cette adresse e-mail est déjà utilisée par un autre compte.' );
			}
		}
		return true;
	}

	/**
	 * Crée un compte. Le mot de passe provisoire est renvoyé une seule fois pour être remis à la personne.
	 *
	 * @param array $data Données : user_login, display_name, user_email, profil, service_id.
	 * @return array{user_id:int, password:string}|WP_Error
	 */
	public static function create( array $data ) {
		global $wpdb;
		$d = array(
			'user_login'   => sanitize_user( (string) ( $data['user_login'] ?? '' ), true ),
			'display_name' => sanitize_text_field( (string) ( $data['display_name'] ?? '' ) ),
			'user_email'   => sanitize_email( (string) ( $data['user_email'] ?? '' ) ),
			'profil'       => sanitize_key( (string) ( $data['profil'] ?? '' ) ),
			'service_id'   => (int) ( $data['service_id'] ?? 0 ),
		);
		if ( strlen( $d['user_login'] ) < 3 ) {
			return new WP_Error( 'user_login', "L'identifiant doit compter au moins 3 caractères (lettres, chiffres, point, tiret)." );
		}
		if ( username_exists( $d['user_login'] ) ) {
			return new WP_Error( 'user_login', 'Cet identifiant existe déjà.' );
		}
		$valid = self::validate( $d );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'service' !== IGE_FFM_Roles::profil( $d['profil'] ) ) {
			$d['service_id'] = 0;
		}

		$password = self::generate_password();
		$user_id  = wp_insert_user(
			array(
				'user_login'   => $d['user_login'],
				'user_pass'    => $password,
				'display_name' => $d['display_name'],
				'user_email'   => $d['user_email'] ?: $d['user_login'] . '@invalid.local',
				'role'         => $d['profil'],
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'compte' ),
			array(
				'user_id'       => $user_id,
				'service_id'    => $d['service_id'] ?: null,
				'profil'        => $d['profil'],
				'actif'         => 1,
				'mdp_a_changer' => 1,
				'created_at'    => ige_ffm_now(),
			)
		);
		self::sync_meta( $user_id );
		IGE_FFM_Audit::log(
			'creation_compte',
			'compte',
			$user_id,
			null,
			array(
				'identifiant' => $d['user_login'],
				'nom'         => $d['display_name'],
				'profil'      => $d['profil'],
				'service'     => self::service_code( $d['service_id'] ),
			)
		);
		return array(
			'user_id'  => (int) $user_id,
			'password' => $password,
		);
	}

	/**
	 * Modifie un compte (profil, service, nom, e-mail).
	 *
	 * @param int   $user_id Compte.
	 * @param array $data    Données.
	 * @return true|WP_Error
	 */
	public static function update( $user_id, array $data ) {
		global $wpdb;
		$c = self::get( $user_id );
		if ( ! $c || ! self::modifiable( $user_id ) ) {
			return new WP_Error( 'compte', 'Ce compte ne peut pas être modifié ici.' );
		}
		$user = get_userdata( $user_id );
		$d    = array(
			'display_name' => sanitize_text_field( (string) ( $data['display_name'] ?? $user->display_name ) ),
			'user_email'   => sanitize_email( (string) ( $data['user_email'] ?? '' ) ),
			'profil'       => sanitize_key( (string) ( $data['profil'] ?? $c['profil'] ) ),
			'service_id'   => (int) ( $data['service_id'] ?? $c['service_id'] ),
		);
		$valid = self::validate( $d, $user_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		if ( 'service' !== IGE_FFM_Roles::profil( $d['profil'] ) ) {
			$d['service_id'] = 0;
		}
		if ( (int) $user_id === get_current_user_id() && $d['profil'] !== $c['profil'] ) {
			return new WP_Error( 'profil', 'Vous ne pouvez pas changer votre propre profil.' );
		}
		$avant = array(
			'nom'     => $user->display_name,
			'email'   => $user->user_email,
			'profil'  => $c['profil'],
			'service' => self::service_code( (int) $c['service_id'] ),
		);
		wp_update_user(
			array(
				'ID'           => $user_id,
				'display_name' => $d['display_name'],
				'user_email'   => $d['user_email'] ?: $user->user_email,
				'role'         => $d['profil'],
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'compte' ),
			array(
				'profil'     => $d['profil'],
				'service_id' => $d['service_id'] ?: null,
				'updated_at' => ige_ffm_now(),
			),
			array( 'user_id' => $user_id )
		);
		self::sync_meta( $user_id );
		$apres = array(
			'nom'     => $d['display_name'],
			'email'   => $d['user_email'] ?: $user->user_email,
			'profil'  => $d['profil'],
			'service' => self::service_code( $d['service_id'] ),
		);
		if ( $avant !== $apres ) {
			IGE_FFM_Audit::log( 'modification_compte', 'compte', $user_id, $avant, $apres );
		}
		return true;
	}

	/**
	 * Active ou désactive un compte. Un compte désactivé ne peut plus se connecter ;
	 * ses sessions ouvertes sont fermées.
	 *
	 * @param int  $user_id Compte.
	 * @param bool $actif   Nouvel état.
	 * @return true|WP_Error
	 */
	public static function set_actif( $user_id, $actif ) {
		global $wpdb;
		if ( (int) $user_id === get_current_user_id() ) {
			return new WP_Error( 'compte', 'Vous ne pouvez pas désactiver votre propre compte.' );
		}
		if ( ! self::get( $user_id ) || ! self::modifiable( $user_id ) ) {
			return new WP_Error( 'compte', 'Compte introuvable.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'compte' ), array( 'actif' => $actif ? 1 : 0, 'updated_at' => ige_ffm_now() ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $actif ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		IGE_FFM_Audit::log( $actif ? 'reactivation_compte' : 'desactivation_compte', 'compte', $user_id );
		return true;
	}

	/**
	 * Réinitialise le mot de passe : nouveau mot de passe provisoire, à changer à la connexion.
	 *
	 * @param int $user_id Compte.
	 * @return string|WP_Error Mot de passe provisoire.
	 */
	public static function reset_password( $user_id ) {
		global $wpdb;
		if ( ! self::get( $user_id ) || ! self::modifiable( $user_id ) ) {
			return new WP_Error( 'compte', 'Compte introuvable.' );
		}
		$password = self::generate_password();
		wp_set_password( $password, $user_id );
		$wpdb->update( IGE_FFM_DB::t( 'compte' ), array( 'mdp_a_changer' => 1, 'updated_at' => ige_ffm_now() ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		delete_user_meta( $user_id, '_ige_echecs' );
		delete_user_meta( $user_id, '_ige_verrou' );
		IGE_FFM_Audit::log( 'reinitialisation_mdp', 'compte', $user_id );
		return $password;
	}

	/**
	 * Enregistre le changement de mot de passe d'un utilisateur par lui-même.
	 *
	 * @param int $user_id Utilisateur.
	 */
	public static function mot_de_passe_change( $user_id ) {
		global $wpdb;
		$wpdb->update( IGE_FFM_DB::t( 'compte' ), array( 'mdp_a_changer' => 0, 'updated_at' => ige_ffm_now() ), array( 'user_id' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'changement_mdp', 'compte', $user_id, null, null, $user_id );
	}

	/**
	 * Un compte est modifiable depuis l'application s'il n'est pas administrateur du site.
	 *
	 * @param int $user_id Compte.
	 * @return bool
	 */
	public static function modifiable( $user_id ) {
		return ! user_can( $user_id, 'manage_options' );
	}

	/**
	 * Comptes actifs sans connexion depuis N jours.
	 *
	 * @param int $jours Jours.
	 * @return array[]
	 */
	public static function inactifs( $jours = 90 ) {
		$limite = gmdate( 'Y-m-d H:i:s', strtotime( ige_ffm_now() ) - $jours * DAY_IN_SECONDS );
		return array_values(
			array_filter(
				self::search( array( 'actif' => 1 ) ),
				static fn( $c ) => ( $c['derniere_connexion'] ?: $c['created_at'] ) < $limite
			)
		);
	}

	/**
	 * Code d'un service.
	 *
	 * @param int $service_id Service.
	 * @return string
	 */
	public static function service_code( $service_id ) {
		if ( ! $service_id ) {
			return '';
		}
		$s = IGE_FFM_Referentiels::get( 'service', $service_id );
		return $s ? $s['code'] : '';
	}

	/**
	 * Copie le code du service dans la méta « ige_service » lue par le thème.
	 *
	 * @param int $user_id Utilisateur.
	 */
	private static function sync_meta( $user_id ) {
		$c = self::get( $user_id );
		update_user_meta( $user_id, 'ige_service', $c ? self::service_code( (int) $c['service_id'] ) : '' );
	}

	/**
	 * Mot de passe provisoire lisible (sans caractères ambigus : 0/O, 1/l/I).
	 *
	 * @return string
	 */
	public static function generate_password() {
		$sets  = array( 'ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789' );
		$all   = implode( '', $sets );
		$chars = array();
		// Au moins une majuscule, une minuscule et un chiffre, puis 9 caractères au hasard.
		foreach ( $sets as $set ) {
			$chars[] = $set[ random_int( 0, strlen( $set ) - 1 ) ];
		}
		for ( $i = 0; $i < 9; $i++ ) {
			$chars[] = $all[ random_int( 0, strlen( $all ) - 1 ) ];
		}
		// Mélange cryptographiquement sûr (Fisher-Yates).
		for ( $i = count( $chars ) - 1; $i > 0; $i-- ) {
			$j           = random_int( 0, $i );
			$tmp         = $chars[ $i ];
			$chars[ $i ] = $chars[ $j ];
			$chars[ $j ] = $tmp;
		}
		return implode( '', $chars );
	}

	/**
	 * Vérifie la robustesse d'un mot de passe choisi par l'utilisateur.
	 *
	 * @param string  $password Mot de passe.
	 * @param WP_User $user     Utilisateur.
	 * @return true|WP_Error
	 */
	public static function check_strength( $password, $user ) {
		$min = (int) IGE_FFM_Reglages::get( 'mdp_longueur' );
		if ( mb_strlen( $password ) < $min ) {
			return new WP_Error( 'mdp', sprintf( 'Le mot de passe doit compter au moins %d caractères.', $min ) );
		}
		if ( ! preg_match( '/[A-Z]/', $password ) || ! preg_match( '/[a-z]/', $password ) || ! preg_match( '/\d/', $password ) ) {
			return new WP_Error( 'mdp', 'Le mot de passe doit contenir au moins une majuscule, une minuscule et un chiffre.' );
		}
		if ( false !== stripos( $password, $user->user_login ) ) {
			return new WP_Error( 'mdp', "Le mot de passe ne doit pas contenir l'identifiant." );
		}
		if ( wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'mdp', "Choisissez un mot de passe différent du mot de passe provisoire." );
		}
		return true;
	}
}
