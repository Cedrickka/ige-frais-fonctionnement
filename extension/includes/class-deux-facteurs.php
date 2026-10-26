<?php
/**
 * Double authentification (EF-07). Deux méthodes :
 * - « email » (par défaut) : un code à 6 chiffres, valable 10 minutes, est envoyé par la
 *   messagerie de la plateforme à l'adresse du compte à chaque connexion ;
 * - « application » : codes TOTP (RFC 6238) d'une application d'authentification.
 * Dans les deux cas, dix codes de secours à usage unique.
 *
 * Connexion : après le mot de passe, la session n'est pas ouverte ; un jeton de 10 minutes
 * mène à l'écran du code. Obligatoire pour Administration et Finance et les administrateurs
 * du site, et, si le réglage est coché, pour tous les comptes ; facultative sinon.
 *
 * En cas d'urgence (téléphone perdu et plus de codes de secours pour le dernier administrateur),
 * ajouter define( 'IGE_FFM_DESACTIVER_2FA', true ); dans wp-config.php, se connecter,
 * réinitialiser, puis retirer la ligne.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Double authentification.
 */
final class IGE_FFM_Deux_Facteurs {

	const PERIODE = 30;
	const CHIFFRES = 6;

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'intercepter' ), 1, 2 );
		add_action( 'login_form_ige_2fa', array( __CLASS__, 'ecran_code' ) );
		add_action( 'template_redirect', array( __CLASS__, 'obliger' ), 2 );
		add_action( 'admin_init', array( __CLASS__, 'obliger_admin' ), 1 );
	}

	/**
	 * Le dispositif est-il désactivé en urgence ?
	 *
	 * @return bool
	 */
	public static function desactive() {
		return defined( 'IGE_FFM_DESACTIVER_2FA' ) && IGE_FFM_DESACTIVER_2FA;
	}

	/**
	 * Le compte a-t-il activé la double authentification ?
	 *
	 * @param int $uid Utilisateur.
	 * @return bool
	 */
	public static function actif( $uid ) {
		return (bool) get_user_meta( $uid, '_ige_2fa_actif', true );
	}

	/**
	 * La double authentification est-elle obligatoire pour ce compte ?
	 *
	 * @param int $uid Utilisateur.
	 * @return bool
	 */
	public static function obligatoire( $uid ) {
		if ( IGE_FFM_Reglages::get( 'deux_facteurs_tous' ) && user_can( $uid, 'ige_acceder' ) ) {
			return true;
		}
		return (bool) IGE_FFM_Reglages::get( 'deux_facteurs_admin' ) && user_can( $uid, 'ige_administrer' );
	}

	/**
	 * Méthode du compte : « email » ou « application ».
	 *
	 * @param int $uid Utilisateur.
	 * @return string
	 */
	public static function methode( $uid ) {
		$m = (string) get_user_meta( $uid, '_ige_2fa_methode', true );
		if ( '' === $m ) {
			return get_user_meta( $uid, '_ige_2fa_secret', true ) ? 'application' : 'email';
		}
		return 'application' === $m ? 'application' : 'email';
	}

	// ---------------------------------------------------------------------
	// Codes envoyés par e-mail.
	// ---------------------------------------------------------------------

	/**
	 * Empreinte d'un code e-mail (le code lui-même n'est jamais conservé).
	 *
	 * @param string $code Code.
	 * @param int    $uid  Utilisateur.
	 * @return string
	 */
	private static function empreinte_otp( $code, $uid ) {
		return hash_hmac( 'sha256', preg_replace( '/\D/', '', (string) $code ) . '|' . (int) $uid, wp_salt( 'auth' ) );
	}

	/**
	 * Génère un code et l'envoie à l'adresse du compte.
	 *
	 * @param int    $uid   Utilisateur.
	 * @param string $objet connexion | activation.
	 * @return array|WP_Error [ empreinte, expire ].
	 */
	public static function envoyer_otp( $uid, $objet = 'connexion' ) {
		$u = get_userdata( $uid );
		if ( ! IGE_FFM_Messagerie::adresse_valide( $u ) ) {
			return new WP_Error( 'adresse', 'Ce compte n’a pas d’adresse e-mail valide. Demandez à Administration et Finance de l’ajouter.' );
		}
		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$txt  = 'activation' === $objet
			? "Bonjour {$u->display_name},\n\nVoici le code pour activer la double authentification de votre compte {$u->user_login} :\n\n**{$code}**\n\nIl est valable 10 minutes."
			: "Bonjour {$u->display_name},\n\nVoici votre code de connexion à l’application « " . ige_ffm_nom_appli() . " » (compte {$u->user_login}) :\n\n**{$code}**\n\nIl est valable 10 minutes et ne sert qu’une fois.\n\nSi vous n’essayez pas de vous connecter, quelqu’un connaît votre mot de passe : changez-le et prévenez Administration et Finance.";
		$ok   = IGE_FFM_Messagerie::envoyer( $u->user_email, 'activation' === $objet ? 'Activation de la double authentification' : 'Votre code de connexion', $txt );
		if ( ! $ok ) {
			return new WP_Error( 'envoi', 'L’e-mail n’a pas pu être envoyé. Réessayez dans un instant, utilisez un code de secours, ou prévenez Administration et Finance.' );
		}
		return array( self::empreinte_otp( $code, $uid ), time() + 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Vérifie un code e-mail.
	 *
	 * @param int    $uid       Utilisateur.
	 * @param string $saisi     Code saisi.
	 * @param string $empreinte Empreinte attendue.
	 * @param int    $expire    Échéance.
	 * @return bool
	 */
	public static function verifier_otp( $uid, $saisi, $empreinte, $expire ) {
		$saisi = preg_replace( '/\D/', '', (string) $saisi );
		return 6 === strlen( $saisi ) && $empreinte && time() <= (int) $expire && hash_equals( (string) $empreinte, self::empreinte_otp( $saisi, $uid ) );
	}

	/**
	 * Envoie le code d'activation par e-mail (au plus un envoi par minute).
	 *
	 * @param int $uid Utilisateur.
	 * @return true|WP_Error
	 */
	public static function demander_activation_email( $uid ) {
		$o = (array) get_user_meta( $uid, '_ige_2fa_otp', true );
		if ( ! empty( $o['envoye'] ) && time() - (int) $o['envoye'] < 60 ) {
			return new WP_Error( 'delai', 'Un code vient d’être envoyé : attendez une minute avant d’en demander un autre.' );
		}
		$r = self::envoyer_otp( $uid, 'activation' );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		update_user_meta( $uid, '_ige_2fa_otp', array( 'empreinte' => $r[0], 'expire' => $r[1], 'envoye' => time(), 'essais' => 0 ) );
		return true;
	}

	/**
	 * Active la double authentification par e-mail après saisie du code reçu.
	 *
	 * @param int    $uid  Utilisateur.
	 * @param string $code Code.
	 * @return string[]|WP_Error Codes de secours.
	 */
	public static function activer_email( $uid, $code ) {
		$o = (array) get_user_meta( $uid, '_ige_2fa_otp', true );
		if ( empty( $o['empreinte'] ) ) {
			return new WP_Error( 'code', 'Demandez d’abord l’envoi du code.' );
		}
		if ( (int) ( $o['essais'] ?? 0 ) >= 5 ) {
			delete_user_meta( $uid, '_ige_2fa_otp' );
			return new WP_Error( 'code', 'Trop d’essais : demandez un nouveau code.' );
		}
		if ( ! self::verifier_otp( $uid, $code, $o['empreinte'], $o['expire'] ) ) {
			$o['essais'] = (int) ( $o['essais'] ?? 0 ) + 1;
			update_user_meta( $uid, '_ige_2fa_otp', $o );
			return new WP_Error( 'code', time() > (int) $o['expire'] ? 'Ce code a expiré : demandez-en un nouveau.' : 'Code incorrect.' );
		}
		delete_user_meta( $uid, '_ige_2fa_otp' );
		delete_user_meta( $uid, '_ige_2fa_secret' );
		delete_user_meta( $uid, '_ige_2fa_attente' );
		update_user_meta( $uid, '_ige_2fa_methode', 'email' );
		update_user_meta( $uid, '_ige_2fa_actif', 1 );
		IGE_FFM_Audit::log( 'activation_2fa', 'compte', $uid, null, array( 'methode' => 'e-mail' ), $uid );
		return self::nouveaux_codes_secours( $uid );
	}

	// ---------------------------------------------------------------------
	// Algorithme TOTP.
	// ---------------------------------------------------------------------

	/**
	 * Encode en base 32 (RFC 4648, sans remplissage).
	 *
	 * @param string $bin Octets.
	 * @return string
	 */
	public static function base32_encode( $bin ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits  = '';
		foreach ( str_split( $bin ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alpha[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	/**
	 * Décode du base 32.
	 *
	 * @param string $b32 Texte.
	 * @return string
	 */
	public static function base32_decode( $b32 ) {
		$alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32   = strtoupper( preg_replace( '/[^A-Za-z2-7]/', '', (string) $b32 ) );
		$bits  = '';
		foreach ( str_split( $b32 ) as $c ) {
			$bits .= str_pad( decbin( strpos( $alpha, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	/**
	 * Nouveau secret (160 bits).
	 *
	 * @return string Base 32.
	 */
	public static function nouveau_secret() {
		return self::base32_encode( random_bytes( 20 ) );
	}

	/**
	 * Code TOTP pour un pas de temps.
	 *
	 * @param string $secret Secret base 32.
	 * @param int    $pas    Pas de temps (time() / 30).
	 * @return string
	 */
	public static function code( $secret, $pas ) {
		$h      = hash_hmac( 'sha1', pack( 'N*', 0 ) . pack( 'N*', $pas ), self::base32_decode( $secret ), true );
		$o      = ord( $h[19] ) & 0xf;
		$valeur = ( ( ( ord( $h[ $o ] ) & 0x7f ) << 24 ) | ( ( ord( $h[ $o + 1 ] ) & 0xff ) << 16 ) | ( ( ord( $h[ $o + 2 ] ) & 0xff ) << 8 ) | ( ord( $h[ $o + 3 ] ) & 0xff ) ) % ( 10 ** self::CHIFFRES );
		return str_pad( (string) $valeur, self::CHIFFRES, '0', STR_PAD_LEFT );
	}

	/**
	 * Vérifie un code (tolérance d'un pas avant ou après, pour les horloges décalées).
	 *
	 * @param string   $secret  Secret.
	 * @param string   $saisi   Code saisi.
	 * @param int      $dernier Dernier pas utilisé (anti-rejeu).
	 * @param int|null $maint   Horodatage (tests).
	 * @return int|false Pas validé, ou false.
	 */
	public static function verifier_code( $secret, $saisi, $dernier = 0, $maint = null ) {
		$saisi = preg_replace( '/\D/', '', (string) $saisi );
		if ( strlen( $saisi ) !== self::CHIFFRES || '' === (string) $secret ) {
			return false;
		}
		$pas = (int) floor( ( $maint ?? time() ) / self::PERIODE );
		foreach ( array( 0, -1, 1 ) as $d ) {
			if ( $pas + $d > (int) $dernier && hash_equals( self::code( $secret, $pas + $d ), $saisi ) ) {
				return $pas + $d;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------------
	// Stockage du secret (chiffré si possible).
	// ---------------------------------------------------------------------

	/**
	 * Clé de chiffrement propre au site.
	 *
	 * @return string
	 */
	private static function cle() {
		return hash( 'sha256', wp_salt( 'auth' ) . 'ige-ffm-2fa', true );
	}

	/**
	 * Chiffre un secret.
	 *
	 * @param string $s Secret.
	 * @return string
	 */
	public static function chiffrer( $s ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return 'p:' . $s;
		}
		$iv = random_bytes( 16 );
		return 'c:' . base64_encode( $iv . openssl_encrypt( $s, 'aes-256-cbc', self::cle(), OPENSSL_RAW_DATA, $iv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Déchiffre un secret.
	 *
	 * @param string $v Valeur stockée.
	 * @return string
	 */
	public static function dechiffrer( $v ) {
		$v = (string) $v;
		if ( 0 === strpos( $v, 'p:' ) ) {
			return substr( $v, 2 );
		}
		if ( 0 === strpos( $v, 'c:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $v, 2 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return (string) openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::cle(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
		}
		return '';
	}

	/**
	 * Secret en attente d'activation (créé au premier affichage de l'écran d'activation).
	 *
	 * @param int $uid Utilisateur.
	 * @return string
	 */
	public static function secret_en_attente( $uid ) {
		$s = self::dechiffrer( get_user_meta( $uid, '_ige_2fa_attente', true ) );
		if ( '' === $s ) {
			$s = self::nouveau_secret();
			update_user_meta( $uid, '_ige_2fa_attente', self::chiffrer( $s ) );
		}
		return $s;
	}

	/**
	 * Adresse otpauth:// pour le code QR d'activation.
	 *
	 * @param WP_User $u      Utilisateur.
	 * @param string  $secret Secret.
	 * @return string
	 */
	public static function uri( $u, $secret ) {
		$emetteur = 'IGE Frais';
		return 'otpauth://totp/' . rawurlencode( $emetteur . ':' . $u->user_login ) . '?secret=' . $secret . '&issuer=' . rawurlencode( $emetteur ) . '&digits=' . self::CHIFFRES . '&period=' . self::PERIODE;
	}

	/**
	 * Active la double authentification après vérification d'un premier code.
	 *
	 * @param int    $uid  Utilisateur.
	 * @param string $code Code saisi.
	 * @return string[]|WP_Error Codes de secours (à afficher une seule fois).
	 */
	public static function activer( $uid, $code ) {
		$s   = self::secret_en_attente( $uid );
		$pas = self::verifier_code( $s, $code );
		if ( false === $pas ) {
			return new WP_Error( 'code', 'Code incorrect. Vérifiez l’heure du téléphone et saisissez le code affiché maintenant.' );
		}
		update_user_meta( $uid, '_ige_2fa_secret', self::chiffrer( $s ) );
		update_user_meta( $uid, '_ige_2fa_dernier', $pas );
		update_user_meta( $uid, '_ige_2fa_actif', 1 );
		update_user_meta( $uid, '_ige_2fa_methode', 'application' );
		delete_user_meta( $uid, '_ige_2fa_attente' );
		IGE_FFM_Audit::log( 'activation_2fa', 'compte', $uid, null, array( 'methode' => 'application' ), $uid );
		return self::nouveaux_codes_secours( $uid );
	}

	/**
	 * Génère dix codes de secours (seules leurs empreintes sont conservées).
	 *
	 * @param int $uid Utilisateur.
	 * @return string[]
	 */
	public static function nouveaux_codes_secours( $uid ) {
		$codes = array();
		$hash  = array();
		$alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		for ( $i = 0; $i < 10; $i++ ) {
			$c = '';
			for ( $j = 0; $j < 8; $j++ ) {
				$c .= $alpha[ random_int( 0, strlen( $alpha ) - 1 ) ];
			}
			$codes[] = substr( $c, 0, 4 ) . '-' . substr( $c, 4 );
			$hash[]  = wp_hash_password( $c );
		}
		update_user_meta( $uid, '_ige_2fa_secours', $hash );
		return $codes;
	}

	/**
	 * Nombre de codes de secours restants.
	 *
	 * @param int $uid Utilisateur.
	 * @return int
	 */
	public static function codes_restants( $uid ) {
		return count( (array) get_user_meta( $uid, '_ige_2fa_secours', true ) );
	}

	/**
	 * Vérifie un code (e-mail, application ou secours) pour un compte actif.
	 *
	 * @param int    $uid   Utilisateur.
	 * @param string $saisi Code.
	 * @param array  $otp   Code e-mail attendu : empreinte, expire.
	 * @return string|false « email », « totp », « secours » ou false.
	 */
	public static function controler( $uid, $saisi, array $otp = array() ) {
		if ( 'email' === self::methode( $uid ) ) {
			if ( $otp && self::verifier_otp( $uid, $saisi, $otp['empreinte'] ?? '', $otp['expire'] ?? 0 ) ) {
				return 'email';
			}
		} else {
			$s   = self::dechiffrer( get_user_meta( $uid, '_ige_2fa_secret', true ) );
			$pas = self::verifier_code( $s, $saisi, (int) get_user_meta( $uid, '_ige_2fa_dernier', true ) );
			if ( false !== $pas ) {
				update_user_meta( $uid, '_ige_2fa_dernier', $pas );
				return 'totp';
			}
		}
		$c = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $saisi ) );
		if ( 8 === strlen( $c ) ) {
			$liste = (array) get_user_meta( $uid, '_ige_2fa_secours', true );
			foreach ( $liste as $i => $h ) {
				if ( wp_check_password( $c, $h ) ) {
					unset( $liste[ $i ] );
					update_user_meta( $uid, '_ige_2fa_secours', array_values( $liste ) );
					IGE_FFM_Audit::log( 'code_secours_2fa', 'compte', $uid, null, array( 'restants' => count( $liste ) ), $uid );
					return 'secours';
				}
			}
		}
		return false;
	}

	/**
	 * Désactive (par l'intéressé avec un code valide, ou par un administrateur).
	 *
	 * @param int      $uid    Compte.
	 * @param int|null $par    Auteur.
	 * @param string   $motif  Motif (réinitialisation par un administrateur).
	 * @return true|WP_Error
	 */
	public static function desactiver( $uid, $par = null, $motif = '' ) {
		$par = $par ?? get_current_user_id();
		if ( (int) $par !== (int) $uid && ! user_can( $par, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'Action réservée à l’administration.' );
		}
		if ( (int) $par === (int) $uid && self::obligatoire( $uid ) ) {
			return new WP_Error( 'obligatoire', 'La double authentification est obligatoire pour votre profil.' );
		}
		foreach ( array( '_ige_2fa_actif', '_ige_2fa_secret', '_ige_2fa_secours', '_ige_2fa_dernier', '_ige_2fa_attente', '_ige_2fa_methode', '_ige_2fa_otp' ) as $k ) {
			delete_user_meta( $uid, $k );
		}
		IGE_FFM_Audit::log( (int) $par === (int) $uid ? 'desactivation_2fa' : 'reinitialisation_2fa', 'compte', $uid, null, $motif ? array( 'motif' => $motif ) : null, $par );
		return true;
	}

	// ---------------------------------------------------------------------
	// Connexion en deux étapes.
	// ---------------------------------------------------------------------

	/**
	 * Après le mot de passe : si la double authentification est active, la session n'est pas
	 * ouverte ; l'utilisateur est envoyé vers l'écran du code.
	 *
	 * @param string  $login Identifiant.
	 * @param WP_User $user  Utilisateur.
	 */
	public static function intercepter( $login, $user ) {
		if ( self::desactive() || ! self::actif( $user->ID ) ) {
			return;
		}
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
		$jeton = wp_generate_password( 40, false, false );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$redir = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		$etat  = array(
			'uid'      => (int) $user->ID,
			'login'    => $login,
			'remember' => ! empty( $_POST['rememberme'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'redirect' => $redir,
			'essais'   => 0,
			'envois'   => 0,
		);
		if ( 'email' === self::methode( $user->ID ) ) {
			$etat = self::envoyer_otp_connexion( $etat );
		}
		set_transient( 'ige_ffm_2fa_' . hash( 'sha256', $jeton ), $etat, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'action' => 'ige_2fa', 'jeton' => $jeton ), wp_login_url() ) );
		exit;
	}

	/**
	 * Envoie (ou renvoie) le code de connexion et met à jour l'état de la connexion en cours.
	 *
	 * @param array $etat État.
	 * @return array
	 */
	private static function envoyer_otp_connexion( array $etat ) {
		$r = self::envoyer_otp( (int) $etat['uid'], 'connexion' );
		++$etat['envois'];
		$etat['envoye'] = time();
		if ( is_wp_error( $r ) ) {
			$etat['erreur_envoi'] = $r->get_error_message();
			IGE_FFM_Audit::log( 'echec_code_2fa', 'compte', (int) $etat['uid'], null, array( 'erreur' => $r->get_error_code() ), (int) $etat['uid'] );
		} else {
			$etat['erreur_envoi'] = '';
			$etat['otp']          = array( 'empreinte' => $r[0], 'expire' => $r[1] );
		}
		return $etat;
	}

	/**
	 * Écran du code (wp-login.php?action=ige_2fa).
	 */
	public static function ecran_code() {
		// phpcs:disable WordPress.Security.NonceVerification
		$jeton = isset( $_REQUEST['jeton'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', wp_unslash( $_REQUEST['jeton'] ) ) : '';
		$cle   = 'ige_ffm_2fa_' . hash( 'sha256', $jeton );
		$etat  = $jeton ? get_transient( $cle ) : false;
		if ( ! $etat ) {
			wp_safe_redirect( add_query_arg( 'login', 'code_expire', home_url( '/' ) ) );
			exit;
		}
		$erreur  = '';
		$info    = '';
		$email   = 'email' === self::methode( (int) $etat['uid'] );
		$u       = get_userdata( (int) $etat['uid'] );
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && $email && ! empty( $_POST['ige_renvoyer'] ) ) {
			if ( (int) $etat['envois'] >= 3 ) {
				$erreur = 'Trois codes ont déjà été envoyés. Utilisez un code de secours ou reconnectez-vous plus tard.';
			} elseif ( time() - (int) ( $etat['envoye'] ?? 0 ) < 60 ) {
				$erreur = 'Attendez une minute avant de demander un nouveau code.';
			} else {
				$etat = self::envoyer_otp_connexion( $etat );
				set_transient( $cle, $etat, 10 * MINUTE_IN_SECONDS );
				$info = $etat['erreur_envoi'] ? '' : 'Un nouveau code a été envoyé ; le précédent ne fonctionne plus.';
			}
		} elseif ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$code = isset( $_POST['ige_code'] ) ? sanitize_text_field( wp_unslash( $_POST['ige_code'] ) ) : '';
			$ok   = self::controler( (int) $etat['uid'], $code, (array) ( $etat['otp'] ?? array() ) );
			if ( $ok ) {
				delete_transient( $cle );
				$user = get_userdata( (int) $etat['uid'] );
				wp_set_auth_cookie( $user->ID, (bool) $etat['remember'], is_ssl() );
				wp_set_current_user( $user->ID );
				IGE_FFM_Securite::login_ok( $user->user_login, $user );
				$dest = $etat['redirect'] ? wp_validate_redirect( $etat['redirect'], home_url( '/' ) ) : home_url( '/' );
				wp_safe_redirect( apply_filters( 'login_redirect', $dest, $etat['redirect'], $user ) );
				exit;
			}
			++$etat['essais'];
			IGE_FFM_Audit::log( 'echec_2fa', 'compte', (int) $etat['uid'], null, array( 'essai' => $etat['essais'] ), (int) $etat['uid'] );
			if ( $etat['essais'] >= 5 ) {
				delete_transient( $cle );
				wp_safe_redirect( add_query_arg( 'login', 'code_expire', home_url( '/' ) ) );
				exit;
			}
			set_transient( $cle, $etat, 10 * MINUTE_IN_SECONDS );
			$erreur = 'Code incorrect ou expiré. Il vous reste ' . ( 5 - $etat['essais'] ) . ' essai(s).';
		}
		// phpcs:enable
		if ( $email && ! empty( $etat['erreur_envoi'] ) && ! $erreur ) {
			$erreur = $etat['erreur_envoi'];
		}
		login_header( 'Double authentification', $info ? '<p class="message">' . esc_html( $info ) . '</p>' : '', $erreur ? new WP_Error( 'code', $erreur ) : null );
		?>
		<form name="ige2fa" id="loginform" action="<?php echo esc_url( add_query_arg( array( 'action' => 'ige_2fa', 'jeton' => $jeton ), wp_login_url() ) ); ?>" method="post" autocomplete="off">
			<?php if ( $email ) : ?>
				<p style="margin-bottom:14px">Un code à 6 chiffres vient d’être envoyé à <b><?php echo esc_html( IGE_FFM_Messagerie::masquer( $u ? $u->user_email : '' ) ); ?></b> depuis l’adresse de la plateforme. Il est valable 10 minutes. Pensez à regarder dans les courriers indésirables. Sans accès à votre messagerie, utilisez un code de secours.</p>
			<?php else : ?>
				<p style="margin-bottom:14px">Ouvrez votre application d’authentification et saisissez le code à 6 chiffres affiché pour <b>IGE Frais</b>. Sans votre téléphone, utilisez un code de secours.</p>
			<?php endif; ?>
			<p>
				<label for="ige_code">Code de vérification</label>
				<input type="text" name="ige_code" id="ige_code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required autofocus>
			</p>
			<p class="submit"><input type="submit" class="button button-primary button-large" value="Valider"></p>
		</form>
		<?php if ( $email ) : ?>
			<form method="post" action="<?php echo esc_url( add_query_arg( array( 'action' => 'ige_2fa', 'jeton' => $jeton ), wp_login_url() ) ); ?>" style="text-align:center;margin-top:12px;background:none;border:0;box-shadow:none;padding:0">
				<input type="hidden" name="ige_renvoyer" value="1">
				<button type="submit" class="button-link" style="background:none;border:0;color:#007fc7;cursor:pointer;text-decoration:underline">Je n’ai rien reçu : renvoyer un code</button>
			</form>
		<?php endif; ?>
		<?php
		login_footer( 'ige_code' );
		exit;
	}

	/**
	 * Comptes obligés : renvoi vers l'écran d'activation tant qu'elle n'est pas faite.
	 */
	public static function obliger() {
		if ( ! is_user_logged_in() || self::desactive() ) {
			return;
		}
		$uid = get_current_user_id();
		$c   = IGE_FFM_Comptes::get( $uid );
		if ( ( $c && (int) $c['mdp_a_changer'] ) || is_page( 'premiere-connexion' ) ) {
			return; // Le mot de passe personnel d'abord.
		}
		if ( self::obligatoire( $uid ) && ! self::actif( $uid ) && ! is_page( 'double-authentification' ) ) {
			wp_safe_redirect( ige_ffm_url( 'double-authentification' ) );
			exit;
		}
	}

	/**
	 * Même obligation pour l'administration de WordPress et les formulaires (sauf l'activation).
	 */
	public static function obliger_admin() {
		if ( ! is_user_logged_in() || self::desactive() || wp_doing_ajax() ) {
			return;
		}
		$uid = get_current_user_id();
		if ( ! self::obligatoire( $uid ) || self::actif( $uid ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( 0 === strpos( $action, 'ige_ffm_2fa' ) || 'ige_ffm_premier_mdp' === $action ) {
			return;
		}
		wp_safe_redirect( ige_ffm_url( 'double-authentification' ) );
		exit;
	}
}
