<?php
/**
 * Apparence > Structure IGE : crée en un clic les pages de l'application (avec leur modèle),
 * les cinq menus et leur emplacement. Option : contenu de démonstration issu des maquettes.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pages de l'application.
 * slug => [ titre du menu, modèle, fichier de démonstration, slug du parent ].
 *
 * @return array<string,array>
 */
function ige_ff_structure_pages() {
	return array(
		// Administration et Finance.
		'tableau-de-bord'        => array( 'Tableau de bord', 'tableau-de-bord', 's2', '' ),
		'verification'           => array( 'Vérification', 'liste-detail', 's3', '' ),
		'dossier-de-depense'     => array( 'Dossier de dépense', 'liste', 'a-dossier', 'verification' ),
		'demandes-de-fonds'      => array( 'Demandes de fonds', 'liste-detail', 'a-demandes', '' ),
		'avances'                => array( 'Avances', 'liste', 'a-avances', '' ),
		'tresorerie'             => array( 'Trésorerie', 'tableau-de-bord', 'a-treso', '' ),
		'rapprochement-bancaire' => array( 'Rapprochement bancaire', 'liste', 'a-rappro', '' ),
		'caisses'                => array( 'Caisses', 'liste', 'a-caisses', '' ),
		'missions'               => array( 'Missions', 'liste', 'a-missions', '' ),
		'parc-automobile'        => array( 'Parc automobile', 'liste', 'a-parc', '' ),
		'stocks'                 => array( 'Stocks', 'liste', 'a-stocks', '' ),
		'consolidation'          => array( 'Consolidation', 'liste', 'a-conso', '' ),
		'risques'                => array( 'Risques', 'tableau-de-bord', 'a-risques', '' ),
		'rapports'               => array( 'Rapports', 'liste', 'a-rapports', '' ),
		'modele-de-rapport'      => array( 'Modèle de rapport', 'document', 'a-pdf', 'rapports' ),
		// Administration.
		'referentiels'           => array( 'Services, catégories, rubriques', 'administration', 'b-ref', '' ),
		'fournisseurs'           => array( 'Fournisseurs', 'administration', 'b-fourn', '' ),
		'comptes'                => array( 'Comptes utilisateurs', 'administration', 'b-comptes', '' ),
		'parametres'             => array( 'Exercice et règles de contrôle', 'administration', 'b-params', '' ),
		'notifications'          => array( 'Notifications', 'administration', 'b-notif', '' ),
		'journal-audit'          => array( "Journal d'audit", 'administration', 'b-audit', '' ),
		// Services.
		'accueil-service'        => array( 'Accueil', 'tableau-de-bord', 's-acc', '' ),
		'nouvelle-ecriture'      => array( 'Nouvelle écriture', 'formulaire', 's4', '' ),
		'hors-connexion'         => array( 'Saisie hors connexion', 'formulaire', 's-offline', 'nouvelle-ecriture' ),
		'livre-de-caisse'        => array( 'Livre de caisse', 'liste', 's-livre', '' ),
		'budget'                 => array( 'Budget', 'liste', 's-budget', '' ),
		'mes-demandes-de-fonds'  => array( 'Demandes de fonds', 'liste', 's-demande', '' ),
		'arrete-de-caisse'       => array( 'Arrêté de caisse', 'liste', 's-arrete', '' ),
		'transmission'           => array( 'Transmission', 'liste', 's-trans', '' ),
		// Direction.
		'synthese'               => array( 'Synthèse', 'formulaire', 'd-ig', '' ),
		// Public et outils.
		'verifier-un-document'   => array( 'Vérifier un document', 'page-publique', 'p-verif', '' ),
		'guide-des-composants'   => array( 'Guide des composants', 'guide-composants', '', '' ),
	);
}

/**
 * Menus : emplacement => liste d'éléments [ libellé, slug de page ou « # », enfants ].
 *
 * @return array<string,array>
 */
function ige_ff_structure_menus() {
	return array(
		'menu-central'   => array(
			'IGE · Administration et Finance',
			array(
				array( 'Tableau de bord', 'tableau-de-bord' ),
				array( 'Vérification', 'verification' ),
				array( 'Fonds', '#', array( array( 'Demandes de fonds', 'demandes-de-fonds' ), array( 'Avances', 'avances' ), array( 'Trésorerie', 'tresorerie' ), array( 'Rapprochement bancaire', 'rapprochement-bancaire' ) ) ),
				array( 'Caisses', 'caisses' ),
				array( 'Missions', 'missions' ),
				array( 'Logistique', '#', array( array( 'Parc automobile', 'parc-automobile' ), array( 'Stocks', 'stocks' ) ) ),
				array( 'Analyses', '#', array( array( 'Consolidation', 'consolidation' ), array( 'Risques', 'risques' ) ) ),
				array( 'Rapports', 'rapports' ),
				array( 'Administration', 'referentiels' ),
			),
		),
		'menu-service'   => array(
			'IGE · Services',
			array(
				array( 'Accueil', 'accueil-service' ),
				array( 'Nouvelle écriture', 'nouvelle-ecriture' ),
				array( 'Livre de caisse', 'livre-de-caisse' ),
				array( 'Budget', 'budget' ),
				array( 'Demandes de fonds', 'mes-demandes-de-fonds' ),
				array( 'Missions', 'missions' ),
				array( 'Arrêté de caisse', 'arrete-de-caisse' ),
				array( 'Transmission', 'transmission' ),
			),
		),
		'menu-direction' => array(
			'IGE · Direction',
			array(
				array( 'Synthèse', 'synthese' ),
				array( 'Consolidation', 'consolidation' ),
				array( 'Rapports', 'rapports' ),
			),
		),
		'menu-admin'     => array(
			'IGE · Barre Administration',
			array(
				array( 'Référentiels', '#', array( array( 'Services, catégories, rubriques', 'referentiels' ), array( 'Fournisseurs', 'fournisseurs' ) ) ),
				array( 'Accès', '#', array( array( 'Comptes utilisateurs', 'comptes' ) ) ),
				array( 'Règles', '#', array( array( 'Exercice et règles de contrôle', 'parametres' ), array( 'Notifications', 'notifications' ) ) ),
				array( 'Traçabilité', '#', array( array( "Journal d'audit", 'journal-audit' ) ) ),
			),
		),
		'menu-pied'      => array(
			'IGE · Pied de page',
			array(
				array( 'Vérifier un document', 'verifier-un-document' ),
				array( 'Guide des composants', 'guide-des-composants' ),
			),
		),
	);
}

/**
 * Crée les pages manquantes. Les pages existantes ne sont jamais modifiées.
 *
 * @param bool $demo Ajouter le contenu de démonstration.
 * @return array{created:int, skipped:int}
 */
function ige_ff_install_pages( $demo ) {
	$created = 0;
	$skipped = 0;
	$pages   = ige_ff_structure_pages();
	// Parents d'abord.
	uasort( $pages, static fn( $a, $b ) => strcmp( (string) $a[3], (string) $b[3] ) );
	foreach ( $pages as $slug => $def ) {
		list( $title, $template, $demo_id, $parent_slug ) = $def;
		$parent_id = 0;
		if ( $parent_slug ) {
			$parent    = get_page_by_path( $parent_slug );
			$parent_id = $parent ? $parent->ID : 0;
		}
		$path = $parent_slug ? $parent_slug . '/' . $slug : $slug;
		if ( get_page_by_path( $path ) ) {
			++$skipped;
			continue;
		}
		$data  = $demo && $demo_id ? ige_ff_demo_data( $demo_id ) : array();
		$title = $data['title'] ?? $title;

		$content = '';
		if ( ! empty( $data['content'] ) ) {
			$notice  = in_array( $template, array( 'document', 'page-publique' ), true ) ? '' : ige_ff_banner( 'info', __( 'Données de démonstration', 'ige-ff' ), ' ' . __( 'Contenu fictif issu des maquettes. L’extension métier remplacera ce contenu par les données réelles.', 'ige-ff' ) );
			$content = "<!-- wp:html -->\n" . $notice . $data['content'] . "\n<!-- /wp:html -->";
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_parent'  => $parent_id,
				'post_content' => $content,
				'menu_order'   => $created,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			continue;
		}
		update_post_meta( $id, '_wp_page_template', 'templates/' . $template . '.php' );
		update_post_meta( $id, '_ige_sous_titre', sanitize_text_field( $data['subtitle'] ?? '' ) );
		update_post_meta( $id, '_ige_actions', ige_ff_kses_ui( $data['actions'] ?? '' ) );
		if ( ! empty( $data['panneau'] ) ) {
			update_post_meta( $id, '_ige_panneau', ige_ff_kses_ui( $data['panneau'] ) );
		}
		++$created;
	}
	return array(
		'created' => $created,
		'skipped' => $skipped,
	);
}

/**
 * Lit un fichier de démonstration (demo/<id>.json).
 *
 * @param string $id Identifiant de l'écran de maquette.
 * @return array
 */
function ige_ff_demo_data( $id ) {
	$file = IGE_FF_DIR . '/demo/' . sanitize_file_name( $id ) . '.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return is_array( $data ) ? $data : array();
}

/**
 * Crée les menus manquants et les assigne à leur emplacement.
 *
 * @return int Nombre de menus créés.
 */
function ige_ff_install_menus() {
	$count     = 0;
	$locations = get_theme_mod( 'nav_menu_locations', array() );
	$by_slug   = array();
	foreach ( ige_ff_structure_pages() as $slug => $def ) {
		$path             = $def[3] ? $def[3] . '/' . $slug : $slug;
		$page             = get_page_by_path( $path );
		$by_slug[ $slug ] = $page ? $page->ID : 0;
	}

	foreach ( ige_ff_structure_menus() as $location => $menu ) {
		list( $name, $items ) = $menu;
		if ( wp_get_nav_menu_object( $name ) ) {
			$existing = wp_get_nav_menu_object( $name );
			$locations[ $location ] = $existing->term_id;
			continue;
		}
		$menu_id = wp_create_nav_menu( $name );
		if ( is_wp_error( $menu_id ) ) {
			continue;
		}
		ige_ff_add_menu_items( $menu_id, $items, 0, $by_slug );
		$locations[ $location ] = $menu_id;
		++$count;
	}
	set_theme_mod( 'nav_menu_locations', $locations );
	return $count;
}

/**
 * Ajoute récursivement des éléments de menu.
 *
 * @param int   $menu_id Menu.
 * @param array $items   Éléments.
 * @param int   $parent  Élément parent.
 * @param array $by_slug Correspondance slug => ID de page.
 */
function ige_ff_add_menu_items( $menu_id, $items, $parent, $by_slug ) {
	foreach ( $items as $item ) {
		$label    = $item[0];
		$target   = $item[1];
		$children = $item[2] ?? array();
		if ( '#' === $target || empty( $by_slug[ $target ] ) ) {
			$args = array(
				'menu-item-title'     => $label,
				'menu-item-url'       => '#',
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
			);
		} else {
			$args = array(
				'menu-item-title'     => $label,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $by_slug[ $target ],
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $parent,
			);
		}
		$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
		if ( $children && ! is_wp_error( $item_id ) ) {
			ige_ff_add_menu_items( $menu_id, $children, $item_id, $by_slug );
		}
	}
}

/**
 * Page Apparence > Structure IGE.
 */
function ige_ff_structure_menu_page() {
	add_theme_page( __( 'Structure IGE', 'ige-ff' ), __( 'Structure IGE', 'ige-ff' ), 'edit_theme_options', 'ige-ff-structure', 'ige_ff_structure_screen' );
}
add_action( 'admin_menu', 'ige_ff_structure_menu_page' );

/**
 * Écran de la page.
 */
function ige_ff_structure_screen() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$result = get_transient( 'ige_ff_structure_result' );
	delete_transient( 'ige_ff_structure_result' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Structure de l’application IGE', 'ige-ff' ); ?></h1>
		<?php if ( $result ) : ?>
			<div class="notice notice-success"><p>
				<?php
				printf(
					/* translators: 1: pages créées, 2: pages existantes, 3: menus créés. */
					esc_html__( '%1$d pages créées, %2$d pages déjà présentes conservées, %3$d menus créés et assignés.', 'ige-ff' ),
					(int) $result['created'],
					(int) $result['skipped'],
					(int) $result['menus']
				);
				?>
			</p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Crée les pages de l’application avec leur modèle, puis les menus des trois profils (Administration et Finance, services, Direction), la barre latérale d’administration et les liens du pied de page. Les pages et menus déjà présents ne sont pas modifiés.', 'ige-ff' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ige_ff_structure">
			<?php wp_nonce_field( 'ige_ff_structure' ); ?>
			<p><label><input type="checkbox" name="ige_demo" value="1"> <?php esc_html_e( 'Remplir les pages avec le contenu de démonstration des maquettes (données fictives, pour la recette du thème uniquement)', 'ige-ff' ); ?></label></p>
			<?php submit_button( __( 'Créer les pages et les menus', 'ige-ff' ) ); ?>
		</form>
		<h2><?php esc_html_e( 'Pages créées', 'ige-ff' ); ?></h2>
		<table class="widefat striped" style="max-width:900px"><thead><tr><th><?php esc_html_e( 'Page', 'ige-ff' ); ?></th><th><?php esc_html_e( 'Adresse', 'ige-ff' ); ?></th><th><?php esc_html_e( 'Modèle', 'ige-ff' ); ?></th></tr></thead><tbody>
		<?php foreach ( ige_ff_structure_pages() as $slug => $def ) : ?>
			<tr><td><?php echo esc_html( $def[0] ); ?></td><td><code>/<?php echo esc_html( $def[3] ? $def[3] . '/' . $slug : $slug ); ?>/</code></td><td><?php echo esc_html( $def[1] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
	</div>
	<?php
}

/**
 * Traitement du formulaire.
 */
function ige_ff_structure_handle() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'Action non autorisée.', 'ige-ff' ), 403 );
	}
	check_admin_referer( 'ige_ff_structure' );
	$pages = ige_ff_install_pages( ! empty( $_POST['ige_demo'] ) );
	$menus = ige_ff_install_menus();
	set_transient( 'ige_ff_structure_result', $pages + array( 'menus' => $menus ), 60 );
	wp_safe_redirect( admin_url( 'themes.php?page=ige-ff-structure' ) );
	exit;
}
add_action( 'admin_post_ige_ff_structure', 'ige_ff_structure_handle' );
