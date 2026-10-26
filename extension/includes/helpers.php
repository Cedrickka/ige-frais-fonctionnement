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
	static $cache = array();
	if ( ! isset( $cache[ $slug ] ) ) {
		$page = get_page_by_path( $slug );
		if ( ! $page && false === strpos( $slug, '/' ) ) {
			// Page enfant (ex. « nouvelle-ecriture/hors-connexion ») : recherche par son seul identifiant.
			$ids  = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );
			$page = $ids ? get_post( $ids[0] ) : null;
		}
		$cache[ $slug ] = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
	}
	$url = $cache[ $slug ];
	return $query ? add_query_arg( $query, $url ) : $url;
}

/**
 * Enregistre un message à afficher au prochain écran de l'utilisateur.
 *
 * @param string $type  ok | info | warn | ko.
 * @param string $titre Titre.
 * @param string $texte Texte.
 */
function ige_ffm_flash( $type, $titre, $texte = '', $lien = null ) {
	global $ige_ffm_flash_jeton;
	$key  = 'ige_ffm_flash_' . get_current_user_id();
	$list = get_transient( $key ) ?: array();
	$list[] = array( $type, $titre, $texte, $lien );
	set_transient( $key, $list, 300 );
	// Copie liée à l'adresse de retour : le message s'affiche même si un cache de page
	// ou d'objets fait perdre la première, et l'adresse unique contourne les caches.
	if ( empty( $ige_ffm_flash_jeton ) ) {
		$ige_ffm_flash_jeton = strtolower( wp_generate_password( 12, false, false ) );
	}
	set_transient( 'ige_ffm_fj_' . $ige_ffm_flash_jeton, array( 'u' => get_current_user_id(), 'l' => $list ), 300 );
}

/**
 * Ajoute le jeton du message à l'adresse de retour d'un formulaire.
 *
 * @param string $location Adresse.
 * @return string
 */
function ige_ffm_flash_redirect( $location ) {
	global $ige_ffm_flash_jeton;
	if ( ! empty( $ige_ffm_flash_jeton ) && is_string( $location ) && 0 === strpos( $location, home_url() ) ) {
		$location = add_query_arg( 'ige_msg', $ige_ffm_flash_jeton, remove_query_arg( 'ige_msg', $location ) );
	}
	return $location;
}
add_filter( 'wp_redirect', 'ige_ffm_flash_redirect', 5 );

/**
 * Affiche (une seule fois) les messages en attente.
 *
 * @return string HTML.
 */
function ige_ffm_flash_render() {
	$key  = 'ige_ffm_flash_' . get_current_user_id();
	$list = get_transient( $key );
	$jet  = isset( $_GET['ige_msg'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) wp_unslash( $_GET['ige_msg'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	if ( '' !== $jet ) {
		$copie = get_transient( 'ige_ffm_fj_' . $jet );
		if ( is_array( $copie ) && (int) $copie['u'] === get_current_user_id() ) {
			$list = $list ?: $copie['l'];
			delete_transient( 'ige_ffm_fj_' . $jet );
		}
	}
	if ( ! $list ) {
		return '';
	}
	delete_transient( $key );
	$html = '';
	foreach ( $list as $m ) {
		$lien = ! empty( $m[3] ) && is_array( $m[3] ) ? ' <a href="' . esc_url( $m[3][1] ) . '">' . esc_html( $m[3][0] ) . '</a>' : '';
		$html .= ige_ffm_banner( $m[0], $m[1], ( $m[2] ? ' ' . esc_html( $m[2] ) : '' ) . $lien );
	}
	return '<div class="ige-flash" id="ige-flash" tabindex="-1">' . $html . '</div>';
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

/**
 * Nom officiel de l'application (modifiable dans Apparence › Personnaliser › Identité IGE).
 *
 * @param bool $court Forme courte « IGE · … » (expéditeur, fichiers).
 * @return string
 */
function ige_ffm_nom_appli( $court = false ) {
	$nom = function_exists( 'ige_ff_texte' ) ? trim( ige_ff_texte( 'ige_application' ) ) : '';
	if ( '' === $nom || 'Gestion des frais de fonctionnement' === $nom ) {
		$nom = 'Gestion des frais de fonctionnement du trésor public';
	}
	if ( $court ) {
		$f = preg_replace( '/^Gestion des /u', '', $nom );
		return 'IGE · ' . mb_strtoupper( mb_substr( $f, 0, 1 ) ) . mb_substr( $f, 1 );
	}
	return $nom;
}

/**
 * Pages de l'application jamais mises en cache pour un utilisateur connecté
 * (soldes, messages et données personnelles toujours à jour, y compris derrière
 * un cache d'hébergeur de type LiteSpeed).
 */
function ige_ffm_pas_de_cache() {
	if ( ! is_user_logged_in() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	if ( ! headers_sent() ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}
}
add_action( 'template_redirect', 'ige_ffm_pas_de_cache', 0 );
