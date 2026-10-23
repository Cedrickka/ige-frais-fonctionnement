<?php
/**
 * Profils d'utilisateurs : quel menu, quelle page d'accueil, quel libellé.
 *
 * Les rôles eux-mêmes (ige_agent, ige_responsable, ige_admin, ige_lecteur) sont créés
 * par l'extension métier. Le thème ne fait que lire le rôle pour adapter l'interface ;
 * il ne donne aucun droit.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Correspondance rôle WordPress => profil d'interface.
 *
 * @return array<string,string>
 */
function ige_ff_roles_profils() {
	/**
	 * Filtre la correspondance entre rôles et profils d'interface.
	 *
	 * @param array<string,string> $map rôle => central|service|direction.
	 */
	return apply_filters(
		'ige_ff_roles_profils',
		array(
			'administrator'   => 'central',
			'ige_admin'       => 'central',
			'ige_responsable' => 'service',
			'ige_agent'       => 'service',
			'ige_lecteur'     => 'direction',
		)
	);
}

/**
 * Profil d'interface de l'utilisateur : central, service, direction ou visiteur.
 *
 * @param WP_User|null $user Utilisateur (courant par défaut).
 * @return string
 */
function ige_ff_profil( $user = null ) {
	$user = $user ?: wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return 'visiteur';
	}
	$map = ige_ff_roles_profils();
	foreach ( (array) $user->roles as $role ) {
		if ( isset( $map[ $role ] ) ) {
			return $map[ $role ];
		}
	}
	return 'service';
}

/**
 * Libellé affiché sous le nom de l'utilisateur dans l'en-tête.
 *
 * @param WP_User|null $user Utilisateur.
 * @return string
 */
function ige_ff_profil_libelle( $user = null ) {
	$user    = $user ?: wp_get_current_user();
	$service = (string) get_user_meta( $user->ID, 'ige_service', true );
	$labels  = array(
		'administrator'   => __( 'Administration et Finance', 'ige-ff' ),
		'ige_admin'       => __( 'Administration et Finance', 'ige-ff' ),
		'ige_responsable' => __( 'Responsable', 'ige-ff' ),
		'ige_agent'       => __( 'Agent', 'ige-ff' ),
		'ige_lecteur'     => __( 'Direction', 'ige-ff' ),
	);
	$label = '';
	foreach ( (array) $user->roles as $role ) {
		if ( isset( $labels[ $role ] ) ) {
			$label = $labels[ $role ];
			break;
		}
	}
	if ( $service && in_array( ige_ff_profil( $user ), array( 'service' ), true ) ) {
		$label .= ' · ' . $service;
	}
	/** Filtre le libellé de profil affiché dans l'en-tête. */
	return (string) apply_filters( 'ige_ff_profil_libelle', $label, $user );
}

/**
 * Emplacement de menu à afficher pour l'utilisateur courant.
 *
 * @return string
 */
function ige_ff_menu_location() {
	$locations = array(
		'central'   => 'menu-central',
		'service'   => 'menu-service',
		'direction' => 'menu-direction',
	);
	$profil = ige_ff_profil();
	return (string) apply_filters( 'ige_ff_menu_location', $locations[ $profil ] ?? '', $profil );
}

/**
 * Page d'accueil de chaque profil (après connexion).
 *
 * @param string $profil Profil.
 * @return string URL ou chaîne vide.
 */
function ige_ff_dashboard_url( $profil = '' ) {
	$profil = $profil ?: ige_ff_profil();
	$slugs  = array(
		'central'   => 'tableau-de-bord',
		'service'   => 'accueil-service',
		'direction' => 'synthese',
	);
	$url = '';
	if ( isset( $slugs[ $profil ] ) ) {
		$page = get_page_by_path( $slugs[ $profil ] );
		if ( $page && 'publish' === $page->post_status ) {
			$url = get_permalink( $page );
		}
	}
	/** Filtre la page d'accueil d'un profil. */
	return (string) apply_filters( 'ige_ff_dashboard_url', $url, $profil );
}

/**
 * Initiales pour l'avatar de l'en-tête.
 *
 * @param WP_User $user Utilisateur.
 * @return string
 */
function ige_ff_initiales( $user ) {
	$name  = trim( $user->display_name ?: $user->user_login );
	$parts = preg_split( '/[\s._-]+/', $name );
	$ini   = '';
	foreach ( array_slice( array_filter( $parts ), 0, 2 ) as $p ) {
		$ini .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
	}
	return $ini ?: '?';
}
