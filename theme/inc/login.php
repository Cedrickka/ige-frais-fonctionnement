<?php
/**
 * Pages WordPress de connexion (wp-login.php : mot de passe oublié, réinitialisation,
 * double authentification d'une extension) habillées aux couleurs de la charte.
 * La connexion habituelle se fait sur la page d'accueil (front-page.php).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Feuille de style de wp-login.php.
 */
function ige_ff_login_assets() {
	$css = IGE_FF_DIR . '/assets/css/login.css';
	wp_enqueue_style( 'ige-ff-login', IGE_FF_URI . '/assets/css/login.css', array( 'login' ), (string) filemtime( $css ) );
	wp_add_inline_style( 'ige-ff-login', '.login h1 a{background-image:url(' . esc_url( IGE_FF_URI . '/assets/img/armoirie-couleur.png' ) . ')}' );
}
add_action( 'login_enqueue_scripts', 'ige_ff_login_assets' );

add_filter( 'login_headerurl', static fn() => home_url( '/' ) );
add_filter( 'login_headertext', static fn() => ige_ff_texte( 'ige_entite' ) );

/**
 * Intitulé officiel sous les armoiries.
 *
 * @param string $message Message existant.
 * @return string
 */
function ige_ff_login_message( $message ) {
	$intitule = '<div class="ige-login-intitule"><span class="ige-login-ligne"></span><span>' . nl2br( esc_html( ige_ff_texte( 'ige_ministere' ) ) ) . '<small>' . esc_html( ige_ff_texte( 'ige_entite' ) ) . '</small></span></div>';
	return $intitule . $message;
}
add_filter( 'login_message', 'ige_ff_login_message' );

/**
 * Après connexion, chaque profil arrive sur sa page d'accueil.
 *
 * @param string           $redirect_to URL demandée.
 * @param string           $requested   URL demandée (brute).
 * @param WP_User|WP_Error $user        Utilisateur.
 * @return string
 */
function ige_ff_login_redirect( $redirect_to, $requested, $user ) {
	if ( $user instanceof WP_User && ( ! $requested || admin_url() === $redirect_to ) && ! user_can( $user, 'manage_options' ) ) {
		$url = ige_ff_dashboard_url( ige_ff_profil( $user ) );
		return $url ?: home_url( '/' );
	}
	return $redirect_to;
}
add_filter( 'login_redirect', 'ige_ff_login_redirect', 10, 3 );

/**
 * Échec de connexion depuis la page d'accueil : revenir sur cette page avec un message,
 * plutôt que sur wp-login.php.
 */
function ige_ff_login_failed() {
	$ref = wp_get_referer();
	if ( $ref && 0 === strpos( $ref, home_url() ) && false === strpos( $ref, 'wp-login' ) ) {
		wp_safe_redirect( add_query_arg( 'login', 'failed', remove_query_arg( 'login', $ref ) ) );
		exit;
	}
}
add_action( 'wp_login_failed', 'ige_ff_login_failed' );
