<?php
/**
 * Réglages du thème, chargement des styles et scripts.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Déclare les fonctionnalités du thème et les emplacements de menus.
 */
function ige_ff_setup() {
	load_theme_textdomain( 'ige-ff', IGE_FF_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script', 'navigation-widgets' ) );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'assets/css/editor.css' );

	register_nav_menus(
		array(
			'menu-central'   => __( 'Menu Administration et Finance', 'ige-ff' ),
			'menu-service'   => __( 'Menu des services (Agent, Responsable)', 'ige-ff' ),
			'menu-direction' => __( 'Menu de la Direction (Lecteur)', 'ige-ff' ),
			'menu-admin'     => __( 'Barre latérale Administration', 'ige-ff' ),
			'menu-pied'      => __( 'Liens du pied de page', 'ige-ff' ),
		)
	);
}
add_action( 'after_setup_theme', 'ige_ff_setup' );

/**
 * Charge la feuille de style et le script du thème.
 */
function ige_ff_assets() {
	$css = IGE_FF_DIR . '/assets/css/ige.css';
	$js  = IGE_FF_DIR . '/assets/js/ige.js';

	wp_enqueue_style( 'ige-ff', IGE_FF_URI . '/assets/css/ige.css', array(), (string) filemtime( $css ) );
	wp_enqueue_script( 'ige-ff', IGE_FF_URI . '/assets/js/ige.js', array(), (string) filemtime( $js ), array( 'strategy' => 'defer', 'in_footer' => true ) );
}
add_action( 'wp_enqueue_scripts', 'ige_ff_assets' );

/**
 * Précharge la police principale (réseau lent : éviter le changement de police à l'affichage).
 */
function ige_ff_preload_fonts() {
	printf(
		'<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n",
		esc_url( IGE_FF_URI . '/assets/fonts/cooper-hewitt-latin-400-normal.woff2' )
	);
	printf( '<meta name="theme-color" content="#17418a">' . "\n" );
}
add_action( 'wp_head', 'ige_ff_preload_fonts', 1 );

/**
 * Allège les pages : l'application est utilisée sur des connexions lentes.
 */
function ige_ff_cleanup() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
}
add_action( 'init', 'ige_ff_cleanup' );

/**
 * La barre d'administration WordPress n'est montrée qu'aux administrateurs du site.
 *
 * @param bool $show Valeur proposée.
 * @return bool
 */
function ige_ff_admin_bar( $show ) {
	return current_user_can( 'manage_options' ) ? $show : false;
}
add_filter( 'show_admin_bar', 'ige_ff_admin_bar' );

/**
 * Ajoute des classes au <body> : profil de l'utilisateur et modèle de page.
 *
 * @param string[] $classes Classes existantes.
 * @return string[]
 */
function ige_ff_body_class( $classes ) {
	$classes[] = 'ige-body';
	$classes[] = 'ige-profil-' . sanitize_html_class( ige_ff_profil() );
	return $classes;
}
add_filter( 'body_class', 'ige_ff_body_class' );

/**
 * Icône du site par défaut : les armoiries (si aucune icône n'est définie dans l'outil de personnalisation).
 */
function ige_ff_favicon() {
	if ( ! has_site_icon() ) {
		printf( '<link rel="icon" href="%s" type="image/png">' . "\n", esc_url( IGE_FF_URI . '/assets/img/armoirie-couleur.png' ) );
	}
}
add_action( 'wp_head', 'ige_ff_favicon', 2 );
