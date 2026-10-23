<?php
/**
 * Transmission des écritures d'un service au central, par lots numérotés.
 *
 * Une écriture « Non transmise » part comme nouvelle ligne ; une écriture « À retransmettre »
 * (engagement payé après envoi, rejet corrigé sans contrepassation) met à jour la même ligne
 * au central : elle repasse « À vérifier », sans doublon (RG-13).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transmission.
 */
final class IGE_FFM_Transmission {

	/**
	 * Crochets : transmission automatique à l'enregistrement (si le réglage est actif).
	 */
	public static function init() {
		add_action( 'ige_ffm_ecriture_enregistree', array( __CLASS__, 'auto' ), 10, 2 );
	}

	/**
	 * Transmission automatique.
	 *
	 * @param int $id      Écriture.
	 * @param int $user_id Auteur.
	 */
	public static function auto( $id, $user_id ) {
		if ( ! IGE_FFM_Reglages::get( 'transmission_auto' ) ) {
			return;
		}
		$e = IGE_FFM_Ecritures::get( $id );
		if ( $e ) {
			self::transmettre( (int) $e['service_id'], $user_id, array( (int) $id ) );
		}
	}

	/**
	 * Écritures à transmettre.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array[]
	 */
	public static function a_transmettre( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d AND service_id = %d AND etat_transmission IN ('non_transmise','a_retransmettre') ORDER BY date_operation ASC, id ASC", $exo, $sid ), ARRAY_A );
	}

	/**
	 * Transmet un lot.
	 *
	 * @param int        $sid     Service.
	 * @param int|null   $user_id Auteur.
	 * @param int[]|null $ids     Limiter à ces écritures (transmission automatique).
	 * @return int|WP_Error Identifiant du lot.
	 */
	public static function transmettre( $sid, $user_id = null, $ids = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = (int) $sid;
		if ( ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Seuls les agents et le Responsable du service peuvent transmettre ses écritures.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$exo  = (int) $ex['id'];
		$rows = self::a_transmettre( $exo, $sid );
		if ( null !== $ids ) {
			$ids  = array_map( 'intval', $ids );
			$rows = array_values( array_filter( $rows, static fn( $r ) => in_array( (int) $r['id'], $ids, true ) ) );
		}
		if ( ! $rows ) {
			return new WP_Error( 'vide', 'Aucune écriture à transmettre.' );
		}
		$anom  = IGE_FFM_Anomalies::pour_service( $exo, $sid, wp_list_pluck( $rows, 'id' ) );
		$code  = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( $sid ) ) ?: 'S' . $sid;
		$lt    = IGE_FFM_DB::t( 'lot' );
		$et    = IGE_FFM_DB::t( 'ecriture' );
		$nb    = count( $rows );
		$maj   = count( array_filter( $rows, static fn( $r ) => 'a_retransmettre' === $r['etat_transmission'] ) );
		$ent   = 0;
		$sor   = 0;
		foreach ( $rows as $r ) {
			if ( 'entree' === $r['type'] ) {
				$ent += (int) $r['montant'];
			} else {
				$sor += (int) $r['montant'];
			}
		}
		$now    = ige_ffm_now();
		$lot_id = 0;
		for ( $essai = 0; $essai < 5 && ! $lot_id; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n   = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $lt WHERE service_id = %d AND exercice_id = %d", $sid, $exo ) ) + $essai;
			$num = sprintf( 'TR-%s-%d-%03d', $code, $ex['annee'], $n );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$lt,
				array(
					'id_transmission' => $num,
					'service_id'      => $sid,
					'exercice_id'     => $exo,
					'nb_lignes'       => $nb,
					'nb_mises_a_jour' => $maj,
					'total_entrees'   => $ent,
					'total_sorties'   => $sor,
					'envoye_par'      => (int) $user_id,
					'envoye_le'       => $now,
				)
			);
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$lot_id = (int) $wpdb->insert_id;
			}
		}
		if ( ! $lot_id ) {
			return new WP_Error( 'lot', 'Transmission impossible pour le moment. Réessayez.' );
		}
		foreach ( $rows as $r ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$et,
				array(
					'etat_transmission' => 'transmise',
					'etat_verification' => 'a_verifier',
					'anomalies'         => wp_json_encode( $anom[ (int) $r['id'] ] ?? array(), JSON_UNESCAPED_UNICODE ),
					'motif_rejet'       => '',
					'lot_id'            => $lot_id,
					'nb_transmissions'  => (int) $r['nb_transmissions'] + 1,
					'valide_par'        => null,
					'valide_le'         => null,
				),
				array(
					'id'                => (int) $r['id'],
					'etat_transmission' => $r['etat_transmission'],
				)
			);
		}
		$nb_anom = count( array_filter( $anom ) );
		IGE_FFM_Audit::log(
			'transmission',
			'lot',
			$num,
			null,
			array(
				'lignes'        => $nb,
				'mises_a_jour'  => $maj,
				'total_entrees' => $ent,
				'total_sorties' => $sor,
				'anomalies'     => $nb_anom,
			),
			$user_id
		);
		return $lot_id;
	}

	/**
	 * Remet une écriture rejetée en transmission sans contrepassation
	 * (seulement si le réglage « contrepassation obligatoire après un rejet » est désactivé).
	 *
	 * @param int      $id      Écriture.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function remettre( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = IGE_FFM_Ecritures::get( $id );
		if ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Écriture introuvable dans votre service.' );
		}
		if ( 'rejetee' !== $e['etat_verification'] || IGE_FFM_Ecritures::contrepassation_de( $id ) ) {
			return new WP_Error( 'etat', 'Seule une écriture rejetée et non contrepassée peut être renvoyée.' );
		}
		if ( IGE_FFM_Reglages::get( 'contrepassation_rejet' ) ) {
			return new WP_Error( 'regle', 'Une écriture rejetée se corrige par contrepassation puis nouvelle écriture.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etat_transmission' => 'a_retransmettre' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'remise_transmission', 'ecriture', $e['id_ecriture'], array( 'etat' => 'rejetee' ), array( 'etat' => 'a_retransmettre' ), $user_id );
		return true;
	}

	/**
	 * Rejets que le service doit traiter (rejetées, ni contrepassées ni renvoyées).
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array[]
	 */
	public static function rejets_a_traiter( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM $t e WHERE e.exercice_id = %d AND e.service_id = %d AND e.etat_verification = 'rejetee' AND e.etat_transmission = 'transmise' AND NOT EXISTS (SELECT 1 FROM $t c WHERE c.ecriture_origine_id = e.id) ORDER BY e.valide_le DESC", $exo, $sid ), ARRAY_A );
	}

	/**
	 * Lots, avec l'état de leurs lignes.
	 *
	 * @param int      $exo   Exercice.
	 * @param int|null $sid   Service (null = tous).
	 * @param int      $limit Nombre.
	 * @return array[]
	 */
	public static function lots( $exo, $sid = null, $limit = 50 ) {
		global $wpdb;
		$lt = IGE_FFM_DB::t( 'lot' );
		$et = IGE_FFM_DB::t( 'ecriture' );
		$w  = $sid ? $wpdb->prepare( ' AND l.service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$lots = $wpdb->get_results( $wpdb->prepare( "SELECT l.* FROM $lt l WHERE l.exercice_id = %d $w ORDER BY l.id DESC LIMIT %d", $exo, $limit ), ARRAY_A );
		if ( ! $lots ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', wp_list_pluck( $lots, 'id' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$etats = $wpdb->get_results( "SELECT lot_id, etat_verification, COUNT(*) AS n FROM $et WHERE lot_id IN ($in) AND etat_transmission = 'transmise' GROUP BY lot_id, etat_verification", ARRAY_A );
		$par   = array();
		foreach ( $etats as $r ) {
			$par[ (int) $r['lot_id'] ][ $r['etat_verification'] ] = (int) $r['n'];
		}
		foreach ( $lots as &$l ) {
			$l['etats'] = $par[ (int) $l['id'] ] ?? array();
		}
		return $lots;
	}

	/**
	 * Libellé et couleur de l'état d'un lot.
	 *
	 * @param array $l Lot (avec « etats »).
	 * @return array{0:string,1:string}
	 */
	public static function etat_lot( array $l ) {
		$e = $l['etats'];
		if ( ! empty( $e['a_verifier'] ) ) {
			return array( 'En vérification', 'wait' );
		}
		if ( ! empty( $e['rejetee'] ) ) {
			return array( $e['rejetee'] . ' rejet' . ( $e['rejetee'] > 1 ? 's' : '' ), 'ko' );
		}
		if ( ! empty( $e['validee'] ) ) {
			return array( 'Validé', 'ok' );
		}
		return array( 'Remplacé', 'neutre' );
	}
}
