<?php
/**
 * Invitations et création des comptes en nombre (déploiement aux services).
 *
 * L'invitation est un e-mail envoyé depuis la boîte de la plateforme avec un lien personnel
 * (valable 24 h, usage unique) pour choisir son mot de passe : aucun mot de passe ne circule
 * par e-mail ni sur papier. Le choix du mot de passe par ce lien vaut « première connexion ».
 *
 * Import CSV (séparateur ; ou ,) : identifiant;nom;email;profil;service
 * Profils acceptés : AGENT, RESPONSABLE, ADMIN (Administration et Finance), LECTEUR (Direction).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Invitations.
 */
final class IGE_FFM_Invitations {

	const PROFILS = array(
		'agent'          => 'ige_agent',
		'responsable'    => 'ige_responsable',
		'admin'          => 'ige_admin',
		'administration' => 'ige_admin',
		'finance'        => 'ige_admin',
		'lecteur'        => 'ige_lecteur',
		'direction'      => 'ige_lecteur',
	);

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'after_password_reset', array( __CLASS__, 'mot_de_passe_choisi' ) );
		add_filter( 'password_reset_expiration', array( __CLASS__, 'duree_lien' ) );
	}

	/**
	 * Durée du lien : 24 heures.
	 *
	 * @return int
	 */
	public static function duree_lien() {
		return DAY_IN_SECONDS;
	}

	/**
	 * Mot de passe choisi via le lien : la première connexion est faite.
	 *
	 * @param WP_User $user Utilisateur.
	 */
	public static function mot_de_passe_choisi( $user ) {
		if ( IGE_FFM_Comptes::get( $user->ID ) ) {
			IGE_FFM_Comptes::mot_de_passe_change( $user->ID );
			delete_user_meta( $user->ID, '_ige_echecs' );
			delete_user_meta( $user->ID, '_ige_verrou' );
		}
	}

	/**
	 * Envoie (ou renvoie) l'invitation à un compte.
	 *
	 * @param int $user_id Compte.
	 * @return true|WP_Error
	 */
	public static function inviter( $user_id ) {
		$u = get_userdata( $user_id );
		$c = IGE_FFM_Comptes::get( $user_id );
		if ( ! $u || ! $c || ! IGE_FFM_Comptes::modifiable( $user_id ) ) {
			return new WP_Error( 'compte', 'Compte introuvable.' );
		}
		if ( ! (int) $c['actif'] ) {
			return new WP_Error( 'compte', 'Ce compte est désactivé.' );
		}
		if ( ! IGE_FFM_Messagerie::adresse_valide( $u ) ) {
			return new WP_Error( 'email', 'Ce compte n’a pas d’adresse e-mail : renseignez-la d’abord.' );
		}
		if ( ! IGE_FFM_Messagerie::reglages()['actif'] && ! has_filter( 'pre_wp_mail' ) ) {
			return new WP_Error( 'smtp', 'La messagerie de la plateforme n’est pas configurée (Paramètres > Messagerie).' );
		}
		$cle = get_password_reset_key( $u );
		if ( is_wp_error( $cle ) ) {
			return $cle;
		}
		$lien    = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $cle ) . '&login=' . rawurlencode( $u->user_login ), 'login' );
		$service = $c['service_id'] ? IGE_FFM_Comptes::service_code( (int) $c['service_id'] ) : '';
		$texte   = sprintf( "Bonjour %s,\n\n", $u->display_name )
			. sprintf( "Un compte a été créé pour vous dans l’application de gestion des frais de fonctionnement de l’IGE.\n\nProfil : %s%s\nIdentifiant : %s\n\n", IGE_FFM_Roles::libelle( $c['profil'] ), $service ? ' · ' . $service : '', $u->user_login )
			. "Pour activer votre compte, choisissez votre mot de passe personnel en ouvrant ce lien (valable 24 heures, utilisable une seule fois) :\n\n" . $lien . "\n\n"
			. 'Ensuite, connectez-vous sur ' . home_url( '/' ) . ". À chaque connexion, un code de vérification vous sera envoyé à cette adresse.\n\n"
			. 'Si le lien a expiré, demandez une nouvelle invitation à Administration et Finance.';
		if ( ! IGE_FFM_Messagerie::envoyer( $u->user_email, 'Activez votre compte', $texte ) ) {
			return new WP_Error( 'envoi', 'L’e-mail n’a pas pu être envoyé. Vérifiez les réglages de messagerie.' );
		}
		update_user_meta( $user_id, '_ige_invite_le', ige_ffm_now() );
		IGE_FFM_Audit::log( 'invitation', 'compte', $user_id, null, array( 'email' => IGE_FFM_Messagerie::masquer( $u->user_email ) ) );
		return true;
	}

	/**
	 * Date de la dernière invitation.
	 *
	 * @param int $user_id Compte.
	 * @return string
	 */
	public static function invite_le( $user_id ) {
		return (string) get_user_meta( $user_id, '_ige_invite_le', true );
	}

	/**
	 * Analyse un fichier CSV de comptes.
	 *
	 * @param string $chemin Fichier.
	 * @return array|WP_Error lignes (prêtes à créer), erreurs.
	 */
	public static function analyser_csv( $chemin ) {
		if ( ! is_readable( $chemin ) || filesize( $chemin ) > 1048576 ) {
			return new WP_Error( 'fichier', 'Fichier illisible ou trop volumineux (1 Mo au plus).' );
		}
		$brut = (string) file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$brut = preg_replace( '/^\xEF\xBB\xBF/', '', $brut );
		if ( ! mb_check_encoding( $brut, 'UTF-8' ) ) {
			$brut = mb_convert_encoding( $brut, 'UTF-8', 'Windows-1252' );
		}
		$lignes = preg_split( '/\r\n|\r|\n/', trim( $brut ) );
		$sep    = substr_count( $lignes[0] ?? '', ';' ) >= substr_count( $lignes[0] ?? '', ',' ) ? ';' : ',';
		$services = array();
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$services[ IGE_FFM_Xlsx::norm( $s['code'] ) ] = $s;
		}
		$ok     = array();
		$err    = array();
		$vus    = array();
		$emails = array();
		foreach ( $lignes as $i => $l ) {
			$n = $i + 1;
			if ( '' === trim( $l ) ) {
				continue;
			}
			$c = array_map( 'trim', str_getcsv( $l, $sep, '"', '\\' ) );
			if ( 1 === $n && 'identifiant' === IGE_FFM_Xlsx::norm( $c[0] ?? '' ) ) {
				continue;
			}
			list( $login, $nom, $email, $profil, $service ) = array_pad( $c, 5, '' );
			$login  = sanitize_user( $login, true );
			$email  = sanitize_email( $email );
			$role   = self::PROFILS[ IGE_FFM_Xlsx::norm( $profil ) ] ?? '';
			$prefix = sprintf( 'Ligne %d (%s) : ', $n, $login ?: '?' );
			if ( strlen( $login ) < 3 ) {
				$err[] = $prefix . 'identifiant manquant ou trop court.';
				continue;
			}
			if ( isset( $vus[ $login ] ) ) {
				$err[] = $prefix . 'identifiant en double dans le fichier.';
				continue;
			}
			$vus[ $login ] = true;
			if ( username_exists( $login ) ) {
				$err[] = $prefix . 'ce compte existe déjà (ignoré).';
				continue;
			}
			if ( '' === $nom ) {
				$err[] = $prefix . 'nom manquant.';
				continue;
			}
			if ( ! is_email( $email ) ) {
				$err[] = $prefix . 'adresse e-mail manquante ou invalide (obligatoire pour recevoir les codes de connexion).';
				continue;
			}
			if ( email_exists( $email ) || isset( $emails[ strtolower( $email ) ] ) ) {
				$err[] = $prefix . 'adresse e-mail déjà utilisée.';
				continue;
			}
			$emails[ strtolower( $email ) ] = true;
			if ( ! $role ) {
				$err[] = $prefix . 'profil « ' . $profil . ' » inconnu (AGENT, RESPONSABLE, ADMIN ou LECTEUR).';
				continue;
			}
			$sid = 0;
			if ( 'service' === IGE_FFM_Roles::profil( $role ) ) {
				$s = $services[ IGE_FFM_Xlsx::norm( $service ) ] ?? null;
				if ( ! $s || ! (int) $s['actif'] ) {
					$err[] = $prefix . 'service « ' . $service . ' » inconnu ou inactif.';
					continue;
				}
				$sid = (int) $s['id'];
			}
			$ok[] = array(
				'user_login'   => $login,
				'display_name' => sanitize_text_field( $nom ),
				'user_email'   => $email,
				'profil'       => $role,
				'service_id'   => $sid,
			);
		}
		if ( ! $ok && ! $err ) {
			return new WP_Error( 'fichier', 'Le fichier est vide.' );
		}
		return array( 'lignes' => $ok, 'erreurs' => $err );
	}

	/**
	 * Crée les comptes analysés et envoie les invitations.
	 *
	 * @param array $lignes  Lignes.
	 * @param bool  $inviter Envoyer les invitations.
	 * @return array{crees:int, invites:int, erreurs:string[]}
	 */
	public static function creer( array $lignes, $inviter = true ) {
		$res = array( 'crees' => 0, 'invites' => 0, 'erreurs' => array() );
		foreach ( $lignes as $d ) {
			$r = IGE_FFM_Comptes::create( $d );
			if ( is_wp_error( $r ) ) {
				$res['erreurs'][] = $d['user_login'] . ' : ' . $r->get_error_message();
				continue;
			}
			++$res['crees'];
			if ( $inviter ) {
				$i = self::inviter( $r['user_id'] );
				if ( is_wp_error( $i ) ) {
					$res['erreurs'][] = $d['user_login'] . ' : compte créé, invitation non envoyée (' . $i->get_error_message() . ')';
				} else {
					++$res['invites'];
				}
			}
		}
		IGE_FFM_Audit::log( 'import_comptes', 'compte', '', null, array( 'crees' => $res['crees'], 'invites' => $res['invites'], 'erreurs' => count( $res['erreurs'] ) ) );
		return $res;
	}
}
