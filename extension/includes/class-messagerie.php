<?php
/**
 * Messagerie de la plateforme : tous les e-mails de l'application partent de la boîte
 * Hostinger de la plateforme (contact@ige-gestion.com par défaut), en SMTP authentifié,
 * ce qui évite qu'ils soient classés en indésirables.
 *
 * Réglages (Administration > Exercice et règles de contrôle > Messagerie), ou constantes
 * dans wp-config.php, prioritaires : IGE_FFM_SMTP_HOTE, IGE_FFM_SMTP_PORT, IGE_FFM_SMTP_UTILISATEUR,
 * IGE_FFM_SMTP_MOT_DE_PASSE, IGE_FFM_SMTP_EXPEDITEUR.
 *
 * Le mot de passe de la boîte est conservé chiffré ; il n'est jamais réaffiché.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Messagerie.
 */
final class IGE_FFM_Messagerie {

	const OPTION = 'ige_ffm_smtp';

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configurer' ) );
		add_filter( 'wp_mail_from', array( __CLASS__, 'expediteur' ) );
		add_filter( 'wp_mail_from_name', array( __CLASS__, 'nom_expediteur' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'echec' ) );
	}

	/**
	 * Réglages effectifs (les constantes l'emportent).
	 *
	 * @return array{hote:string, port:int, utilisateur:string, mot_de_passe:string, expediteur:string, nom:string, actif:bool}
	 */
	public static function reglages() {
		$o = (array) get_option( self::OPTION, array() );
		$r = array(
			'hote'         => defined( 'IGE_FFM_SMTP_HOTE' ) ? IGE_FFM_SMTP_HOTE : ( $o['hote'] ?? 'smtp.hostinger.com' ),
			'port'         => (int) ( defined( 'IGE_FFM_SMTP_PORT' ) ? IGE_FFM_SMTP_PORT : ( $o['port'] ?? 465 ) ),
			'utilisateur'  => defined( 'IGE_FFM_SMTP_UTILISATEUR' ) ? IGE_FFM_SMTP_UTILISATEUR : ( $o['utilisateur'] ?? '' ),
			'mot_de_passe' => defined( 'IGE_FFM_SMTP_MOT_DE_PASSE' ) ? IGE_FFM_SMTP_MOT_DE_PASSE : IGE_FFM_Deux_Facteurs::dechiffrer( (string) ( $o['mot_de_passe'] ?? '' ) ),
			'expediteur'   => defined( 'IGE_FFM_SMTP_EXPEDITEUR' ) ? IGE_FFM_SMTP_EXPEDITEUR : ( $o['expediteur'] ?? '' ),
			'nom'          => ( empty( $o['nom'] ) || 'IGE · Frais de fonctionnement' === $o['nom'] ) ? ige_ffm_nom_appli( true ) : $o['nom'],
		);
		if ( '' === $r['expediteur'] ) {
			$r['expediteur'] = $r['utilisateur'];
		}
		$r['actif'] = '' !== $r['hote'] && '' !== $r['utilisateur'] && '' !== $r['mot_de_passe'];
		return $r;
	}

	/**
	 * Le mot de passe vient-il de wp-config.php ?
	 *
	 * @return bool
	 */
	public static function par_constantes() {
		return defined( 'IGE_FFM_SMTP_MOT_DE_PASSE' );
	}

	/**
	 * Enregistre les réglages (mot de passe chiffré ; vide = inchangé).
	 *
	 * @param array $in Saisies.
	 * @return true|WP_Error
	 */
	public static function enregistrer( array $in ) {
		$o    = (array) get_option( self::OPTION, array() );
		$brut = trim( (string) ( $in['utilisateur'] ?? '' ) );
		$user = sanitize_email( $brut );
		$exp  = sanitize_email( (string) ( $in['expediteur'] ?? '' ) );
		$port = (int) ( $in['port'] ?? 465 );
		if ( '' !== $brut && ! is_email( $user ) ) {
			return new WP_Error( 'utilisateur', 'L’identifiant SMTP doit être l’adresse complète de la boîte (ex. contact@ige-gestion.com).' );
		}
		if ( ! in_array( $port, array( 465, 587 ), true ) ) {
			return new WP_Error( 'port', 'Port 465 (SSL, recommandé par Hostinger) ou 587 (TLS).' );
		}
		$avant = array_diff_key( $o, array( 'mot_de_passe' => 1 ) );
		$o['hote']        = sanitize_text_field( (string) ( $in['hote'] ?? 'smtp.hostinger.com' ) ) ?: 'smtp.hostinger.com';
		$o['port']        = $port;
		$o['utilisateur'] = $user;
		$o['expediteur']  = $exp;
		$o['nom']         = sanitize_text_field( (string) ( $in['nom'] ?? '' ) ) ?: ige_ffm_nom_appli( true );
		$mdp              = (string) ( $in['mot_de_passe'] ?? '' );
		if ( '' !== $mdp ) {
			$o['mot_de_passe'] = IGE_FFM_Deux_Facteurs::chiffrer( $mdp );
		}
		update_option( self::OPTION, $o, false );
		IGE_FFM_Audit::log( 'reglage_messagerie', 'reglages', 'smtp', $avant, array_diff_key( $o, array( 'mot_de_passe' => 1 ) ) + ( '' !== $mdp ? array( 'mot_de_passe' => 'modifié' ) : array() ) );
		return true;
	}

	/**
	 * Configure PHPMailer pour la boîte de la plateforme.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $m Expéditeur.
	 */
	public static function configurer( $m ) {
		$r = self::reglages();
		if ( ! $r['actif'] ) {
			return;
		}
		$m->isSMTP();
		$m->Host       = $r['hote']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->Port       = $r['port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->SMTPAuth   = true; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->SMTPSecure = 465 === $r['port'] ? 'ssl' : 'tls'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->Username   = $r['utilisateur']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->Password   = $r['mot_de_passe']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->Timeout    = 20; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->CharSet    = 'UTF-8'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$m->Sender     = $r['expediteur']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Adresse d'expédition.
	 *
	 * @param string $from Adresse proposée.
	 * @return string
	 */
	public static function expediteur( $from ) {
		$r = self::reglages();
		return $r['expediteur'] && is_email( $r['expediteur'] ) ? $r['expediteur'] : $from;
	}

	/**
	 * Nom d'expédition.
	 *
	 * @param string $nom Nom proposé.
	 * @return string
	 */
	public static function nom_expediteur( $nom ) {
		$r = self::reglages();
		return $r['utilisateur'] ? $r['nom'] : $nom;
	}

	/**
	 * Trace les échecs d'envoi (sans le contenu du message, qui peut contenir un code).
	 *
	 * @param WP_Error $e Erreur.
	 */
	public static function echec( $e ) {
		$d = (array) $e->get_error_data();
		IGE_FFM_Audit::log( 'echec_envoi_courriel', 'messagerie', '', null, array( 'erreur' => mb_substr( $e->get_error_message(), 0, 180 ), 'destinataires' => implode( ', ', (array) ( $d['to'] ?? array() ) ) ) );
	}

	/**
	 * Adresse utilisable pour envoyer un e-mail à un compte ?
	 *
	 * @param WP_User|false $u Utilisateur.
	 * @return bool
	 */
	public static function adresse_valide( $u ) {
		return $u && is_email( $u->user_email ) && ! str_ends_with( strtolower( $u->user_email ), '@invalid.local' );
	}

	/**
	 * Adresse masquée (c•••t@ige-gestion.com).
	 *
	 * @param string $mail Adresse.
	 * @return string
	 */
	public static function masquer( $mail ) {
		list( $l, $d ) = array_pad( explode( '@', (string) $mail, 2 ), 2, '' );
		$m             = mb_strlen( $l ) <= 2 ? mb_substr( $l, 0, 1 ) . '•' : mb_substr( $l, 0, 1 ) . str_repeat( '•', min( 5, mb_strlen( $l ) - 2 ) ) . mb_substr( $l, -1 );
		return $m . '@' . $d;
	}

	/**
	 * Envoie un e-mail au format de la plateforme (texte et HTML simple).
	 *
	 * @param string|string[] $a     Destinataire(s).
	 * @param string          $sujet Sujet (sans préfixe).
	 * @param string          $texte Corps en texte (paragraphes séparés par une ligne vide).
	 * @return bool
	 */
	public static function envoyer( $a, $sujet, $texte ) {
		$html  = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1d2433;max-width:560px">';
		$html .= '<div style="border-left:4px solid #007fc7;padding-left:10px;margin-bottom:18px"><b style="color:#0f2f6b">Ministère de l’Éducation Nationale et Nouvelle Citoyenneté</b><br><span style="font-size:13px">Inspection Générale de l’Enseignement · ' . esc_html( ige_ffm_nom_appli() ) . '</span></div>';
		foreach ( preg_split( "/\n\s*\n/", trim( $texte ) ) as $p ) {
			$p     = esc_html( $p );
			$p     = preg_replace( '#(https?://[^\s<]+)#', '<a href="$1" style="color:#007fc7">$1</a>', $p );
			$p     = preg_replace( '/\*\*(.+?)\*\*/', '<b style="font-size:24px;letter-spacing:4px">$1</b>', $p );
			$html .= '<p style="margin:0 0 14px;line-height:1.5">' . nl2br( $p ) . '</p>';
		}
		$html .= '<p style="font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;padding-top:10px;margin-top:20px">Message automatique de l’application « ' . esc_html( ige_ffm_nom_appli() ) . ' ». Ne répondez pas à ce message si vous ne l’attendiez pas : prévenez Administration et Finance.</p></div>';
		$types = static fn() => 'text/html';
		add_filter( 'wp_mail_content_type', $types );
		$ok = wp_mail( $a, '[IGE] ' . $sujet, $html );
		remove_filter( 'wp_mail_content_type', $types );
		return (bool) $ok;
	}
}
