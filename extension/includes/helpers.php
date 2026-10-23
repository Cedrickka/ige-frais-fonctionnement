<?php
/**
 * Fonctions utilitaires.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Date et heure courantes (fuseau du site) au format MySQL.
 *
 * @return string
 */
function ige_ffm_now() {
	return current_time( 'mysql' );
}

/**
 * Adresse IP du visiteur (pour le journal d'audit).
 *
 * @return string
 */
function ige_ffm_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Montant saisi (« 1 234 567 », « 1.234.567 », « 1234567 ») converti en entier de CDF.
 *
 * @param mixed $value Valeur saisie.
 * @return int|null Null si la valeur n'est pas un montant entier positif ou nul.
 */
function ige_ffm_parse_montant( $value ) {
	$v = preg_replace( '/[\s\x{00A0}\x{202F}.]/u', '', (string) $value );
	if ( '' === $v || ! preg_match( '/^\d{1,15}$/', $v ) ) {
		return null;
	}
	return (int) $v;
}

/**
 * Montant formaté : 1 234 567 CDF.
 *
 * @param int    $montant Montant.
 * @param string $devise  Devise (vide pour le nombre seul).
 * @return string
 */
function ige_ffm_montant( $montant, $devise = 'CDF' ) {
	if ( function_exists( 'ige_ff_montant' ) ) {
		return ige_ff_montant( $montant, $devise );
	}
	$txt = number_format( (float) $montant, 0, ',', "\u{202F}" );
	return $devise ? $txt . "\u{00A0}" . $devise : $txt;
}

/**
 * Adresse d'une page de l'application par son identifiant (slug).
 *
 * @param string $slug  Slug, éventuellement « parent/enfant ».
 * @param array  $query Paramètres de requête.
 * @return string
 */
function ige_ffm_url( $slug, $query = array() ) {
	$page = get_page_by_path( $slug );
	$url  = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	return $query ? add_query_arg( $query, $url ) : $url;
}

/**
 * Enregistre un message à afficher au prochain écran de l'utilisateur.
 *
 * @param string $type  ok | info | warn | ko.
 * @param string $titre Titre.
 * @param string $texte Texte.
 */
function ige_ffm_flash( $type, $titre, $texte = '' ) {
	$key  = 'ige_ffm_flash_' . get_current_user_id();
	$list = get_transient( $key ) ?: array();
	$list[] = array( $type, $titre, $texte );
	set_transient( $key, $list, 300 );
}

/**
 * Affiche (une seule fois) les messages en attente.
 *
 * @return string HTML.
 */
function ige_ffm_flash_render() {
	$key  = 'ige_ffm_flash_' . get_current_user_id();
	$list = get_transient( $key );
	if ( ! $list ) {
		return '';
	}
	delete_transient( $key );
	$html = '';
	foreach ( $list as $m ) {
		$html .= ige_ffm_banner( $m[0], $m[1], $m[2] ? ' ' . esc_html( $m[2] ) : '' );
	}
	return $html;
}

/**
 * Bannière (utilise la fonction du thème si présent).
 *
 * @param string $type  Type.
 * @param string $titre Titre.
 * @param string $texte Texte (HTML déjà échappé).
 * @return string
 */
function ige_ffm_banner( $type, $titre, $texte = '' ) {
	if ( function_exists( 'ige_ff_banner' ) ) {
		return ige_ff_banner( $type, $titre, $texte );
	}
	return '<div class="ige-banner ige-' . esc_attr( $type ) . '"><div><b>' . esc_html( $titre ) . '</b>' . wp_kses_post( $texte ) . '</div></div>';
}

/**
 * Pastille d'état (utilise la fonction du thème si présent).
 *
 * @param string $libelle Libellé.
 * @param string $etat    ok | wait | ko | info | neutre.
 * @return string
 */
function ige_ffm_pill( $libelle, $etat = 'neutre' ) {
	if ( function_exists( 'ige_ff_pill' ) ) {
		return ige_ff_pill( $libelle, $etat );
	}
	return '<span class="ige-pill ige-p-' . esc_attr( $etat ) . '">' . esc_html( $libelle ) . '</span>';
}

/**
 * Interrupteur visuel (lecture seule).
 *
 * @param bool $on Actif.
 * @return string
 */
function ige_ffm_switch( $on ) {
	return '<span class="ige-switch' . ( $on ? ' ige-on' : '' ) . '" role="img" aria-label="' . esc_attr( $on ? 'Actif' : 'Inactif' ) . '"></span>';
}

/**
 * Arrête la requête si l'utilisateur n'a pas la capacité demandée.
 *
 * @param string $cap Capacité.
 */
function ige_ffm_require( $cap ) {
	if ( ! current_user_can( $cap ) ) {
		wp_die( esc_html__( "Vous n'avez pas les droits nécessaires pour cette action.", 'ige-ff-metier' ), '', array( 'response' => 403 ) );
	}
}

/**
 * Champ caché de sécurité et d'action pour les formulaires de l'application.
 *
 * @param string $action Action (ige_ffm_{action}).
 * @return string
 */
function ige_ffm_form_fields( $action ) {
	return '<input type="hidden" name="action" value="ige_ffm_' . esc_attr( $action ) . '">'
		. wp_nonce_field( 'ige_ffm_' . $action, '_ige_nonce', true, false );
}

/**
 * Vérifie le jeton de sécurité d'un formulaire.
 *
 * @param string $action Action.
 */
function ige_ffm_check_form( $action ) {
	if ( ! isset( $_POST['_ige_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_ige_nonce'] ) ), 'ige_ffm_' . $action ) ) {
		wp_die( esc_html__( 'Le formulaire a expiré. Revenez en arrière et recommencez.', 'ige-ff-metier' ), '', array( 'response' => 403 ) );
	}
}

/**
 * Lit un champ texte POST nettoyé.
 *
 * @param string $key Clé.
 * @return string
 */
function ige_ffm_post( $key ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/**
 * Redirige vers la page d'origine du formulaire (motif « POST, redirection, GET »).
 *
 * @param string $fallback Adresse de secours.
 */
function ige_ffm_back( $fallback = '' ) {
	$ref = wp_get_referer();
	wp_safe_redirect( $ref ? remove_query_arg( array( 'modifier' ), $ref ) : ( $fallback ?: home_url( '/' ) ) );
	exit;
}
