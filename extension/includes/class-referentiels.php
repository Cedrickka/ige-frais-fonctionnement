<?php
/**
 * Référentiels : services, catégories, rubriques, modes de paiement, exercices.
 *
 * Règles : aucun élément n'est supprimé (il est désactivé) ; les codes sont uniques ;
 * chaque création ou modification est inscrite au journal d'audit.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accès aux référentiels.
 */
final class IGE_FFM_Referentiels {

	/**
	 * Types de référentiels gérés et leurs champs.
	 *
	 * @return array<string,array>
	 */
	public static function types() {
		return array(
			'service'       => array(
				'label'  => 'Services',
				'single' => 'service',
				'fields' => array( 'code', 'libelle', 'effectif', 'actif', 'ordre' ),
			),
			'categorie'     => array(
				'label'  => 'Catégories',
				'single' => 'catégorie',
				'fields' => array( 'libelle', 'actif', 'ordre' ),
			),
			'rubrique'      => array(
				'label'  => 'Rubriques',
				'single' => 'rubrique',
				'fields' => array( 'code', 'libelle', 'categorie_id', 'pieces_exigees', 'nomenclature', 'actif', 'ordre' ),
			),
			'mode_paiement' => array(
				'label'  => 'Modes de paiement',
				'single' => 'mode de paiement',
				'fields' => array( 'libelle', 'actif', 'ordre' ),
			),
			'nomenclature'  => array(
				'label'  => 'Nomenclature de l’État',
				'single' => 'ligne de nomenclature',
				'fields' => array( 'code', 'libelle', 'actif', 'ordre' ),
			),
		);
	}

	/**
	 * Liste les éléments d'un référentiel.
	 *
	 * @param string $type        Type.
	 * @param bool   $actifs_seul Seulement les éléments actifs.
	 * @return array[]
	 */
	public static function all( $type, $actifs_seul = false ) {
		global $wpdb;
		if ( ! isset( self::types()[ $type ] ) ) {
			return array();
		}
		$table = IGE_FFM_DB::t( $type );
		$where = $actifs_seul ? 'WHERE actif = 1' : '';
		$order = isset( self::types()[ $type ]['fields'] ) && in_array( 'code', self::types()[ $type ]['fields'], true ) ? 'ordre, code' : 'ordre, libelle';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $table $where ORDER BY $order", ARRAY_A );
	}

	/**
	 * Lit un élément.
	 *
	 * @param string $type Type.
	 * @param int    $id   Identifiant.
	 * @return array|null
	 */
	public static function get( $type, $id ) {
		global $wpdb;
		if ( ! isset( self::types()[ $type ] ) ) {
			return null;
		}
		$table = IGE_FFM_DB::t( $type );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Crée ou modifie un élément.
	 *
	 * @param string $type Type.
	 * @param array  $data Données saisies.
	 * @param int    $id   Identifiant (0 pour une création).
	 * @return int|WP_Error Identifiant ou erreur.
	 */
	public static function save( $type, array $data, $id = 0 ) {
		global $wpdb;
		$types = self::types();
		if ( ! isset( $types[ $type ] ) ) {
			return new WP_Error( 'type', 'Référentiel inconnu.' );
		}
		$fields = $types[ $type ]['fields'];
		$row    = array();
		foreach ( $fields as $f ) {
			if ( 'actif' === $f ) {
				$row['actif'] = empty( $data['actif'] ) ? 0 : 1;
			} elseif ( 'ordre' === $f ) {
				$row['ordre'] = (int) ( $data['ordre'] ?? 0 );
			} elseif ( 'categorie_id' === $f ) {
				$row['categorie_id'] = (int) ( $data['categorie_id'] ?? 0 );
			} elseif ( 'effectif' === $f ) {
				$row['effectif'] = '' === trim( (string) ( $data['effectif'] ?? '' ) ) ? null : max( 0, (int) $data['effectif'] );
			} else {
				$row[ $f ] = trim( sanitize_text_field( (string) ( $data[ $f ] ?? '' ) ) );
			}
		}

		// Validation.
		if ( isset( $row['code'] ) && '' === $row['code'] ) {
			return new WP_Error( 'code', 'Le code est obligatoire.' );
		}
		if ( isset( $row['code'] ) && mb_strlen( $row['code'] ) > ( 'rubrique' === $type ? 20 : 60 ) ) {
			return new WP_Error( 'code', 'Le code est trop long.' );
		}
		if ( '' === $row['libelle'] ) {
			return new WP_Error( 'libelle', 'Le libellé est obligatoire.' );
		}
		if ( 'service' === $type && null !== $row['effectif'] && $row['effectif'] > 5000 ) {
			return new WP_Error( 'effectif', 'Effectif invalide.' );
		}
		if ( 'rubrique' === $type && '' !== $row['nomenclature'] && self::all( 'nomenclature' ) && ! in_array( $row['nomenclature'], array_column( self::all( 'nomenclature' ), 'code' ), true ) ) {
			return new WP_Error( 'nomenclature', 'Cette ligne n’existe pas dans la nomenclature : ajoutez-la d’abord dans l’onglet « Nomenclature de l’État ».' );
		}
		if ( 'rubrique' === $type ) {
			$cat = self::get( 'categorie', $row['categorie_id'] );
			if ( ! $cat ) {
				return new WP_Error( 'categorie_id', 'Choisissez une catégorie existante.' );
			}
		}
		$table  = IGE_FFM_DB::t( $type );
		$unique = isset( $row['code'] ) ? 'code' : 'libelle';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE $unique = %s AND id <> %d", $row[ $unique ], (int) $id ) );
		if ( $dupe ) {
			return new WP_Error( $unique, sprintf( 'Ce %s existe déjà.', 'code' === $unique ? 'code' : 'libellé' ) );
		}

		$now = ige_ffm_now();
		if ( $id ) {
			$before = self::get( $type, $id );
			if ( ! $before ) {
				return new WP_Error( 'id', 'Élément introuvable.' );
			}
			$row['updated_at'] = $now;
			$wpdb->update( $table, $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$avant = array();
			$apres = array();
			foreach ( $fields as $f ) {
				if ( (string) $before[ $f ] !== (string) $row[ $f ] ) {
					$avant[ $f ] = $before[ $f ];
					$apres[ $f ] = $row[ $f ];
				}
			}
			if ( $apres ) {
				IGE_FFM_Audit::log( 'modification_referentiel', $type, $id, $avant, $apres );
			}
			return (int) $id;
		}
		$row['created_at'] = $now;
		if ( empty( $row['ordre'] ) ) {
			$row['ordre'] = 1 + (int) $wpdb->get_var( "SELECT MAX(ordre) FROM $table" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new_id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'creation_referentiel', $type, $new_id, null, array_intersect_key( $row, array_flip( $fields ) ) );
		return $new_id;
	}

	/**
	 * Nombre d'écritures qui utilisent un élément (pour expliquer pourquoi on désactive au lieu de supprimer).
	 *
	 * @param string $type Type.
	 * @param int    $id   Identifiant.
	 * @return int
	 */
	public static function usage( $type, $id ) {
		global $wpdb;
		$cols = array(
			'service'       => 'service_id',
			'rubrique'      => 'rubrique_id',
			'mode_paiement' => 'mode_paiement_id',
		);
		if ( 'nomenclature' === $type ) {
			$n = self::get( 'nomenclature', $id );
			return $n ? count( array_filter( self::all( 'rubrique' ), static fn( $r ) => $r['nomenclature'] === $n['code'] ) ) : 0;
		}
		if ( ! isset( $cols[ $type ] ) ) {
			return 0;
		}
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE {$cols[ $type ]} = %d", $id ) );
	}

	/**
	 * Libellé d'une catégorie par identifiant (avec cache).
	 *
	 * @param int $id Identifiant.
	 * @return string
	 */
	public static function categorie_libelle( $id ) {
		static $cache = null;
		if ( null === $cache ) {
			$cache = wp_list_pluck( self::all( 'categorie' ), 'libelle', 'id' );
		}
		return $cache[ $id ] ?? '';
	}

	/**
	 * Exercices.
	 *
	 * @return array[]
	 */
	public static function exercices() {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'exercice' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t ORDER BY annee DESC", ARRAY_A );
	}

	/**
	 * Exercice ouvert (le plus ancien s'il y en avait plusieurs).
	 *
	 * @return array|null
	 */
	public static function exercice_courant() {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'exercice' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM $t WHERE statut = 'ouvert' ORDER BY annee ASC LIMIT 1", ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Prépare l'exercice suivant (statut « préparé » : budgets saisissables, aucune écriture ;
	 * il s'ouvre à la clôture de l'exercice en cours). Budget obligatoire par défaut.
	 *
	 * @return int|WP_Error
	 */
	public static function creer_exercice_suivant() {
		global $wpdb;
		$t    = IGE_FFM_DB::t( 'exercice' );
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE statut = 'prepare'" ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'exercice', "L'exercice suivant est déjà préparé." );
		}
		$last = (int) $wpdb->get_var( "SELECT MAX(annee) FROM $t" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$year = $last ? $last + 1 : (int) wp_date( 'Y' );
		$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'annee'              => $year,
				'date_debut'         => $year . '-01-01',
				'date_fin'           => $year . '-12-31',
				'statut'             => $last ? 'prepare' : 'ouvert',
				'budget_obligatoire' => 1,
				'created_at'         => ige_ffm_now(),
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'exercice', "Impossible de créer l'exercice $year." );
		}
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'creation_exercice', 'exercice', $id, null, array( 'annee' => $year ) );
		return $id;
	}

	/**
	 * Change l'obligation de budget d'un exercice.
	 *
	 * @param int  $id          Exercice.
	 * @param bool $obligatoire Budget obligatoire.
	 */
	public static function set_budget_obligatoire( $id, $obligatoire ) {
		global $wpdb;
		$t      = IGE_FFM_DB::t( 'exercice' );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT budget_obligatoire FROM $t WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$after  = $obligatoire ? 1 : 0;
		if ( $before !== $after ) {
			$wpdb->update( $t, array( 'budget_obligatoire' => $after ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'modification_referentiel', 'exercice', $id, array( 'budget_obligatoire' => $before ), array( 'budget_obligatoire' => $after ) );
		}
	}
}
