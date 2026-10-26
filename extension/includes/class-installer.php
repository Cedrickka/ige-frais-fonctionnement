<?php
/**
 * Installation, mise à jour du schéma et données initiales.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installateur.
 */
final class IGE_FFM_Installer {

	/**
	 * Activation de l'extension.
	 */
	public static function activate() {
		self::create_tables();
		IGE_FFM_Roles::install();
		IGE_FFM_Reglages::install_defaults();
		$seeded = self::seed();
		self::create_pages();
		IGE_FFM_Pieces::dossier();
		update_option( 'ige_ffm_db_version', IGE_FFM_DB_VERSION );
		IGE_FFM_Audit::log( 'installation', 'extension', IGE_FFM_VERSION, null, array( 'donnees_initiales' => $seeded ) );
	}

	/**
	 * Désactivation : aucune donnée n'est supprimée.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ige_ffm_quotidien' );
		wp_clear_scheduled_hook( 'ige_ffm_horaire' );
	}

	/**
	 * Met à jour le schéma si la version a changé (après mise à jour de l'extension).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ige_ffm_db_version' ) !== IGE_FFM_DB_VERSION ) {
			self::create_tables();
			IGE_FFM_Roles::install();
			self::migrer_pieces( (string) get_option( 'ige_ffm_db_version' ) );
			IGE_FFM_Reglages::install_defaults();
			IGE_FFM_Pieces::dossier();
			self::un_seul_exercice_ouvert();
			// Les pages se créent une fois WordPress complètement chargé (permaliens disponibles).
			add_action( 'init', array( __CLASS__, 'create_pages' ), 20 );
			add_action( 'init', array( __CLASS__, 'menus_v21' ), 21 );
			if ( false === get_option( IGE_FFM_Fonctions::OPTION, false ) && false === IGE_FFM_Reglages::get( 'saisie_usd' ) ) {
				update_option( IGE_FFM_Fonctions::OPTION, array( 'devise_usd' => false ), true );
			}
			update_option( 'ige_ffm_db_version', IGE_FFM_DB_VERSION );
		}
	}

	/**
	 * Un seul exercice ouvert à la fois : un exercice postérieur déjà ouvert devient « préparé ».
	 */
	public static function un_seul_exercice_ouvert() {
		global $wpdb;
		$t   = IGE_FFM_DB::t( 'exercice' );
		$ids = $wpdb->get_col( "SELECT id FROM $t WHERE statut = 'ouvert' ORDER BY annee ASC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_slice( $ids, 1 ) as $id ) {
			$wpdb->update( $t, array( 'statut' => 'prepare' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Crée ou met à jour les tables.
	 */
	public static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( IGE_FFM_DB::schema() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Données initiales, uniquement si les tables sont vides.
	 *
	 * @return bool Vrai si des données ont été créées.
	 */
	public static function seed() {
		global $wpdb;
		$now = ige_ffm_now();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'service' ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return false;
		}

		$wpdb->insert(
			IGE_FFM_DB::t( 'exercice' ),
			array(
				'annee'              => 2026,
				'date_debut'         => '2026-01-01',
				'date_fin'           => '2026-12-31',
				'statut'             => 'ouvert',
				'budget_obligatoire' => 0,
				'created_at'         => $now,
			)
		);

		$services = array( '8000 IG', '8000 IGA', '8000C OF', '8000C DD', '8000C CAJ', '8000C PP' );
		for ( $i = 1; $i <= 18; $i++ ) {
			$services[] = (string) ( 8000 + $i );
		}
		$services[] = 'Bureau contrôleur budgétaire';
		$services[] = 'Bureau sous-gestionnaire';
		foreach ( $services as $i => $code ) {
			$wpdb->insert( IGE_FFM_DB::t( 'service' ), array( 'code' => $code, 'libelle' => $code, 'actif' => 1, 'ordre' => $i, 'created_at' => $now ) );
		}

		$categories = array( 'Fournitures et petits matériels', 'Carburant et lubrifiants', 'Entretien et réparation', 'Communication et internet', 'Transport et déplacements', 'Consommables informatiques', 'Produits chimiques et organiques', 'Divers' );
		$cat_ids    = array();
		foreach ( $categories as $i => $lib ) {
			$wpdb->insert( IGE_FFM_DB::t( 'categorie' ), array( 'libelle' => $lib, 'actif' => 1, 'ordre' => $i, 'created_at' => $now ) );
			$cat_ids[ $lib ] = (int) $wpdb->insert_id;
		}

		$rubriques = array(
			array( 'R01', 'Fournitures de bureau', 'Fournitures et petits matériels', 'Facture, bon de réception' ),
			array( 'R02', 'Consommables informatiques', 'Consommables informatiques', 'Facture, bon de réception' ),
			array( 'R03', 'Carburant véhicules de service', 'Carburant et lubrifiants', 'Bon carburant, reçu' ),
			array( 'R04', 'Entretien des véhicules', 'Entretien et réparation', 'Devis, facture' ),
			array( 'R05', 'Entretien des locaux', 'Entretien et réparation', 'Facture' ),
			array( 'R06', 'Crédit téléphonique et internet', 'Communication et internet', 'Facture ou reçu' ),
			array( 'R07', 'Frais de mission et transport', 'Transport et déplacements', 'Ordre de mission, état de paiement' ),
			array( 'R08', 'Réception et restauration', 'Divers', 'Facture, liste des participants' ),
			array( 'R09', 'Frais bancaires', 'Divers', 'Relevé bancaire' ),
			array( 'R10', 'Dépenses diverses', 'Divers', 'Facture ou reçu' ),
		);
		foreach ( $rubriques as $i => $r ) {
			$wpdb->insert(
				IGE_FFM_DB::t( 'rubrique' ),
				array(
					'code'           => $r[0],
					'libelle'        => $r[1],
					'categorie_id'   => $cat_ids[ $r[2] ],
					'pieces_exigees' => $r[3],
					'actif'          => 1,
					'ordre'          => $i,
					'created_at'     => $now,
				)
			);
		}

		foreach ( array( 'Espèces', 'Chèque', 'Virement', 'Mobile money' ) as $i => $lib ) {
			$wpdb->insert( IGE_FFM_DB::t( 'mode_paiement' ), array( 'libelle' => $lib, 'actif' => 1, 'ordre' => $i, 'created_at' => $now ) );
		}
		// phpcs:enable
		return true;
	}

	/**
	 * Pages propres à l'extension (si absentes) : première connexion, double authentification.
	 */
	public static function create_pages() {
		$pages = array(
			'premiere-connexion'      => array( 'Première connexion', 'Choisissez votre mot de passe personnel' ),
			'double-authentification' => array( 'Double authentification', 'Un code à usage unique en plus du mot de passe' ),
			'deploiement'             => array( 'Déploiement', 'Comptes, invitations et reprise des fichiers Excel', 'templates/administration.php' ),
			'visas'                   => array( 'Visas', 'Demandes de fonds, ordonnancements et ordres de mission à viser', 'templates/liste.php' ),
			'fonctions-du-site'       => array( 'Fonctions du site', 'Activer ou désactiver les modules et leurs menus', 'templates/administration.php' ),
			'vehicules'               => array( 'Véhicules', 'Demandes de véhicule, départs et retours, pleins', 'templates/liste.php' ),
		);
		foreach ( $pages as $slug => $p ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => $p[0],
					'post_name'   => $slug,
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_wp_page_template', $p[2] ?? 'templates/formulaire.php' );
				update_post_meta( $id, '_ige_sous_titre', $p[1] );
				if ( 'deploiement' === $slug ) {
					self::ajouter_au_menu_admin( $id, 'Déploiement', 'Services et reprise Excel' );
				}
				if ( 'visas' === $slug ) {
					self::ajouter_au_menu( 'menu-direction', $id, 'Visas' );
				}
				if ( 'fonctions-du-site' === $slug ) {
					self::ajouter_sous_groupe( 'menu-admin', 'Règles', $id, 'Fonctions du site' );
				}
				if ( 'vehicules' === $slug ) {
					self::ajouter_au_menu( 'menu-service', $id, 'Véhicules', 7 );
				}
			}
		}
	}

	/**
	 * Ajoute une page en tête d'un menu (premier niveau).
	 *
	 * @param string $location Emplacement.
	 * @param int    $page_id  Page.
	 * @param string $titre    Libellé.
	 */
	private static function ajouter_au_menu( $location, $page_id, $titre, $position = 1 ) {
		$loc = get_nav_menu_locations();
		if ( empty( $loc[ $location ] ) ) {
			return;
		}
		wp_update_nav_menu_item(
			(int) $loc[ $location ],
			0,
			array(
				'menu-item-title'     => $titre,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => $position,
			)
		);
	}

	/**
	 * Ajoute une page sous une rubrique existante d'un menu (créée si absente).
	 *
	 * @param string $location Emplacement.
	 * @param string $groupe   Titre de la rubrique.
	 * @param int    $page_id  Page.
	 * @param string $titre    Libellé du lien.
	 */
	private static function ajouter_sous_groupe( $location, $groupe, $page_id, $titre ) {
		$loc = get_nav_menu_locations();
		if ( empty( $loc[ $location ] ) ) {
			return;
		}
		$menu = (int) $loc[ $location ];
		$gid  = 0;
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $it ) {
			if ( 0 === (int) $it->menu_item_parent && $groupe === $it->title ) {
				$gid = (int) $it->ID;
			}
			if ( 'page' === $it->object && (int) $it->object_id === (int) $page_id ) {
				return;
			}
		}
		if ( ! $gid ) {
			$gid = wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => $groupe, 'menu-item-url' => '#', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
			if ( is_wp_error( $gid ) ) {
				return;
			}
		}
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => $titre,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-type'      => 'post_type',
				'menu-item-parent-id' => $gid,
				'menu-item-status'    => 'publish',
			)
		);
	}

	/**
	 * Liens de menu des pages ajoutées par les versions successives.
	 *
	 * Une page créée avant les menus du thème (extension activée avant le thème, ou menus
	 * recréés) n'avait pas son lien : « Véhicules » pour les services, « Visas », « Risques »
	 * et « Parc automobile » pour la Direction, « Fonctions du site » et « Déploiement » dans
	 * l'administration. Vérifié une fois par version, quand les menus du thème existent ;
	 * un lien déjà présent n'est jamais dupliqué.
	 */
	public static function assurer_menus() {
		if ( IGE_FFM_VERSION === get_option( 'ige_ffm_menus_ok' ) ) {
			return;
		}
		$loc = get_nav_menu_locations();
		foreach ( array( 'menu-service', 'menu-direction', 'menu-admin' ) as $l ) {
			if ( empty( $loc[ $l ] ) ) {
				return; // Menus du thème pas encore créés : nouvel essai plus tard.
			}
		}
		$present = static function ( $location, $page_id ) use ( $loc ) {
			foreach ( (array) wp_get_nav_menu_items( (int) $loc[ $location ] ) as $it ) {
				if ( 'page' === $it->object && (int) $it->object_id === (int) $page_id ) {
					return true;
				}
			}
			return false;
		};
		$liens = array(
			array( 'menu-service', 'vehicules', 'Véhicules', '', 7 ),
			array( 'menu-direction', 'visas', 'Visas', '', 2 ),
			array( 'menu-direction', 'risques', 'Risques', '', 10 ),
			array( 'menu-direction', 'parc-automobile', 'Parc automobile', '', 11 ),
			array( 'menu-admin', 'fonctions-du-site', 'Fonctions du site', 'Règles', 0 ),
			array( 'menu-admin', 'deploiement', 'Services et reprise Excel', 'Déploiement', 0 ),
		);
		foreach ( $liens as $l ) {
			$p = get_page_by_path( $l[1] );
			if ( ! $p || $present( $l[0], $p->ID ) ) {
				continue;
			}
			if ( '' !== $l[3] ) {
				self::ajouter_sous_groupe( $l[0], $l[3], (int) $p->ID, $l[2] );
			} else {
				self::ajouter_au_menu( $l[0], (int) $p->ID, $l[2], $l[4] );
			}
		}
		update_option( 'ige_ffm_menus_ok', IGE_FFM_VERSION, false );
	}

	/**
	 * Version 2.3.0 (schéma 12) : le n° de pièce et le fichier joint deviennent deux règles.
	 * Le n° garde la valeur de l'ancienne règle ; la photo ou le PDF devient facultatif.
	 *
	 * @param string $avant Version du schéma avant la mise à jour.
	 */
	private static function migrer_pieces( $avant ) {
		if ( '' === $avant || version_compare( $avant, '12', '>=' ) ) {
			return;
		}
		$r = get_option( IGE_FFM_Reglages::OPTION, null );
		if ( ! is_array( $r ) || array_key_exists( 'num_piece_obligatoire', $r ) ) {
			return;
		}
		$r['num_piece_obligatoire'] = array_key_exists( 'piece_obligatoire', $r ) ? (bool) $r['piece_obligatoire'] : true;
		$r['piece_obligatoire']     = false;
		update_option( IGE_FFM_Reglages::OPTION, $r, false );
		IGE_FFM_Audit::log( 'modification_reglages', 'reglages', 'mise à jour 2.3.0', array( 'piece_obligatoire' => true ), array( 'piece_obligatoire' => false, 'num_piece_obligatoire' => $r['num_piece_obligatoire'] ), 0 );
	}

	/**
	 * Menus du lot V2 sur un site déjà installé (une seule fois) : Risques et Parc pour la Direction.
	 */
	public static function menus_v21() {
		if ( get_option( 'ige_ffm_menus_v21' ) ) {
			return;
		}
		$loc = get_nav_menu_locations();
		if ( empty( $loc['menu-direction'] ) ) {
			return;
		}
		$deja = array();
		foreach ( (array) wp_get_nav_menu_items( (int) $loc['menu-direction'] ) as $it ) {
			$deja[] = (int) $it->object_id;
		}
		$pos = 10;
		foreach ( array( 'risques' => 'Risques', 'parc-automobile' => 'Parc automobile' ) as $slug => $titre ) {
			$p = get_page_by_path( $slug );
			if ( $p && ! in_array( (int) $p->ID, $deja, true ) ) {
				self::ajouter_au_menu( 'menu-direction', (int) $p->ID, $titre, $pos++ );
			}
		}
		update_option( 'ige_ffm_menus_v21', 1, false );
	}

	/**
	 * Ajoute une page à la barre latérale de l'administration, sous un groupe.
	 *
	 * @param int    $page_id Page.
	 * @param string $groupe  Titre du groupe.
	 * @param string $titre   Libellé du lien.
	 */
	private static function ajouter_au_menu_admin( $page_id, $groupe, $titre ) {
		$loc = get_nav_menu_locations();
		if ( empty( $loc['menu-admin'] ) ) {
			return;
		}
		$menu = (int) $loc['menu-admin'];
		$gid  = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => $groupe,
				'menu-item-url'    => '#',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		if ( is_wp_error( $gid ) ) {
			return;
		}
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => $titre,
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page_id,
				'menu-item-type'      => 'post_type',
				'menu-item-parent-id' => $gid,
				'menu-item-status'    => 'publish',
			)
		);
	}
}
