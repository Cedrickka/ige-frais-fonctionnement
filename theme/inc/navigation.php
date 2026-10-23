<?php
/**
 * Menus : barre principale avec sous-menus, barre secondaire, barre latérale d'administration.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rendu du menu principal : éléments de premier niveau dans la barre bleue,
 * sous-menus en liste déroulante (bureau) ou dépliés (téléphone).
 */
class IGE_FF_Walker_Nav extends Walker_Nav_Menu {

	/**
	 * Ouvre une liste de sous-menu.
	 *
	 * @param string   $output Sortie.
	 * @param int      $depth  Profondeur.
	 * @param stdClass $args   Arguments.
	 */
	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '<ul class="ige-nav__sub">';
	}

	/**
	 * Ferme une liste de sous-menu.
	 *
	 * @param string   $output Sortie.
	 * @param int      $depth  Profondeur.
	 * @param stdClass $args   Arguments.
	 */
	public function end_lvl( &$output, $depth = 0, $args = null ) {
		$output .= '</ul>';
	}

	/**
	 * Ouvre un élément.
	 *
	 * @param string   $output Sortie.
	 * @param WP_Post  $item   Élément de menu.
	 * @param int      $depth  Profondeur.
	 * @param stdClass $args   Arguments.
	 * @param int      $id     Identifiant.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
		$classes    = (array) $item->classes;
		$is_current = (bool) array_intersect( $classes, array( 'current-menu-item', 'current-menu-ancestor', 'current-menu-parent', 'current_page_item', 'current_page_ancestor' ) );
		$has_sub    = in_array( 'menu-item-has-children', $classes, true );
		// Toutes les pages au modèle « Administration » rendent actif l'élément qui mène à l'une d'elles.
		if ( ! $is_current && 'post_type' === $item->type && is_page() ) {
			$tpl = 'templates/administration.php';
			if ( get_page_template_slug( (int) $item->object_id ) === $tpl && get_page_template_slug() === $tpl ) {
				$is_current = true;
			}
		}

		$li = array( 'ige-nav__item' );
		if ( $is_current ) {
			$li[] = 'is-current';
		}
		if ( $has_sub ) {
			$li[] = 'has-sub';
		}

		$aria  = in_array( 'current-menu-item', $classes, true ) ? ' aria-current="page"' : '';
		$title = apply_filters( 'the_title', $item->title, $item->ID );

		$output .= '<li class="' . esc_attr( implode( ' ', $li ) ) . '">';
		$output .= '<a class="ige-nav__link" href="' . esc_url( $item->url ?: '#' ) . '"' . $aria . '>' . esc_html( $title ) . '</a>';
		if ( $has_sub && 0 === $depth ) {
			/* translators: %s: libellé du menu. */
			$label   = sprintf( __( 'Ouvrir le sous-menu %s', 'ige-ff' ), $title );
			$output .= '<button type="button" class="ige-nav__toggle" aria-expanded="false" aria-label="' . esc_attr( $label ) . '">▾</button>';
		}
	}

	/**
	 * Ferme un élément.
	 *
	 * @param string   $output Sortie.
	 * @param WP_Post  $item   Élément.
	 * @param int      $depth  Profondeur.
	 * @param stdClass $args   Arguments.
	 */
	public function end_el( &$output, $item, $depth = 0, $args = null ) {
		$output .= '</li>';
	}
}

/**
 * Affiche le menu principal de l'utilisateur.
 */
function ige_ff_primary_menu() {
	$location = ige_ff_menu_location();
	if ( ! $location ) {
		return;
	}
	if ( ! has_nav_menu( $location ) ) {
		if ( current_user_can( 'edit_theme_options' ) ) {
			printf(
				'<ul class="ige-nav__list"><li class="ige-nav__item"><a class="ige-nav__link" href="%s">%s</a></li></ul>',
				esc_url( admin_url( 'themes.php?page=ige-ff-structure' ) ),
				esc_html__( 'Créer les menus de l’application', 'ige-ff' )
			);
		}
		return;
	}
	wp_nav_menu(
		array(
			'theme_location' => $location,
			'container'      => false,
			'menu_class'     => 'ige-nav__list',
			'depth'          => 2,
			'walker'         => new IGE_FF_Walker_Nav(),
			'fallback_cb'    => false,
		)
	);
}

/**
 * Éléments d'un emplacement de menu, avec les indicateurs « courant » calculés par WordPress.
 *
 * @param string $location Emplacement.
 * @return WP_Post[]
 */
function ige_ff_menu_items( $location ) {
	$locations = get_nav_menu_locations();
	if ( empty( $locations[ $location ] ) ) {
		return array();
	}
	$items = wp_get_nav_menu_items( $locations[ $location ], array( 'update_post_term_cache' => false ) );
	if ( ! $items ) {
		return array();
	}
	_wp_menu_item_classes_by_context( $items );
	return $items;
}

/**
 * Barre secondaire : sous-pages de la rubrique courante (ex. Fonds > Demandes, Avances…).
 */
function ige_ff_sub_bar() {
	$location = ige_ff_menu_location();
	if ( ! $location ) {
		return;
	}
	$items   = ige_ff_menu_items( $location );
	$by_id   = array();
	$current = null;
	foreach ( $items as $item ) {
		$by_id[ $item->ID ] = $item;
		if ( $item->current ) {
			$current = $item;
		}
	}
	if ( ! $current ) {
		return;
	}
	$top = $current;
	while ( $top->menu_item_parent && isset( $by_id[ $top->menu_item_parent ] ) ) {
		$top = $by_id[ $top->menu_item_parent ];
	}
	$children = array_filter( $items, static fn( $i ) => (int) $i->menu_item_parent === (int) $top->ID );
	if ( ! $children ) {
		return;
	}
	echo '<nav class="ige-sub-bar" aria-label="' . esc_attr( $top->title ) . '"><ul>';
	foreach ( $children as $child ) {
		$cls = $child->current ? ' class="is-current"' : '';
		$cur = $child->current ? ' aria-current="page"' : '';
		printf( '<li%s><a href="%s"%s>%s</a></li>', $cls, esc_url( $child->url ), $cur, esc_html( $child->title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</ul></nav>';
}

/**
 * Barre latérale de l'administration : les éléments de premier niveau sont des titres de groupe,
 * les éléments de second niveau des liens.
 */
function ige_ff_admin_sidebar() {
	$items = ige_ff_menu_items( 'menu-admin' );
	echo '<nav class="ige-adm-side" aria-label="' . esc_attr__( 'Administration', 'ige-ff' ) . '">';
	if ( ! $items ) {
		echo '<p class="ige-small ige-muted" style="padding:8px 12px">' . esc_html__( 'Assignez un menu à l’emplacement « Barre latérale Administration ».', 'ige-ff' ) . '</p></nav>';
		return;
	}
	echo '<ul>';
	foreach ( $items as $item ) {
		$is_group = 0 === (int) $item->menu_item_parent && ( '#' === $item->url || '' === $item->url );
		if ( $is_group ) {
			echo '<li class="ige-grp">' . esc_html( $item->title ) . '</li>';
			continue;
		}
		$cls = $item->current ? ' class="is-current"' : '';
		$cur = $item->current ? ' aria-current="page"' : '';
		printf( '<li%s><a href="%s"%s>%s</a></li>', $cls, esc_url( $item->url ), $cur, esc_html( $item->title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	echo '</ul></nav>';
}

/**
 * Liens du pied de page.
 */
function ige_ff_footer_menu() {
	if ( has_nav_menu( 'menu-pied' ) ) {
		wp_nav_menu(
			array(
				'theme_location' => 'menu-pied',
				'container'      => false,
				'depth'          => 1,
				'fallback_cb'    => false,
			)
		);
	}
}

/**
 * Accueil : les visiteurs voient la connexion, les utilisateurs connectés sont
 * dirigés vers la page d'accueil de leur profil.
 */
function ige_ff_front_redirect() {
	if ( is_front_page() && is_user_logged_in() ) {
		$url = ige_ff_dashboard_url();
		if ( $url && untrailingslashit( $url ) !== untrailingslashit( home_url( '/' ) ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}
}
add_action( 'template_redirect', 'ige_ff_front_redirect' );

/**
 * Pages de l'application réservées aux utilisateurs connectés.
 * Exceptions : l'accueil (connexion) et les pages au modèle « Page publique ».
 */
function ige_ff_require_login() {
	if ( is_user_logged_in() || is_front_page() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( is_page() && 'templates/page-publique.php' === get_page_template_slug() ) {
		return;
	}
	/** Permet de désactiver la protection (false) ou d'ajouter des exceptions. */
	if ( ! apply_filters( 'ige_ff_require_login', true ) ) {
		return;
	}
	$target = is_singular() ? get_permalink() : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $target ), home_url( '/' ) ) );
	exit;
}
add_action( 'template_redirect', 'ige_ff_require_login', 5 );
