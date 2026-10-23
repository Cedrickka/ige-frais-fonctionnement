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
	}

	/**
	 * Met à jour le schéma si la version a changé (après mise à jour de l'extension).
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'ige_ffm_db_version' ) !== IGE_FFM_DB_VERSION ) {
			self::create_tables();
			IGE_FFM_Roles::install();
			IGE_FFM_Reglages::install_defaults();
			IGE_FFM_Pieces::dossier();
			self::un_seul_exercice_ouvert();
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
	 * Pages propres à l'extension (si absentes) : première connexion.
	 */
	public static function create_pages() {
		if ( ! get_page_by_path( 'premiere-connexion' ) ) {
			$id = wp_insert_post(
				array(
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => 'Première connexion',
					'post_name'   => 'premiere-connexion',
				)
			);
			if ( $id && ! is_wp_error( $id ) ) {
				update_post_meta( $id, '_wp_page_template', 'templates/formulaire.php' );
				update_post_meta( $id, '_ige_sous_titre', 'Choisissez votre mot de passe personnel' );
			}
		}
	}
}
