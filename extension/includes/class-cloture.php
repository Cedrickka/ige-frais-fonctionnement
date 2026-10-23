<?php
/**
 * Clôture d'exercice (RG-16) : contrôles préalables, verrouillage, report des soldes de caisse,
 * ouverture de l'exercice suivant.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clôture.
 */
final class IGE_FFM_Cloture {

	/**
	 * Exercice par identifiant.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function exercice( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'exercice' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Exercice préparé (l'année suivante, saisie des budgets possible), s'il existe.
	 *
	 * @return array|null
	 */
	public static function exercice_prepare() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( 'SELECT * FROM ' . IGE_FFM_DB::t( 'exercice' ) . " WHERE statut = 'prepare' ORDER BY annee ASC LIMIT 1", ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Situation de chaque service avant clôture.
	 *
	 * @param int $exo Exercice.
	 * @return array{services:array, bloquants:int, avertissements:int}
	 */
	public static function bilan( $exo ) {
		global $wpdb;
		$t    = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, SUM(CASE WHEN etat_transmission IN ('non_transmise','a_retransmettre') THEN 1 ELSE 0 END) AS non_transmises, SUM(CASE WHEN etat_transmission = 'transmise' AND etat_verification = 'a_verifier' THEN 1 ELSE 0 END) AS a_verifier, SUM(CASE WHEN type = 'sortie' AND statut = 'engagee' AND ecriture_origine_id IS NULL AND NOT EXISTS (SELECT 1 FROM $t c WHERE c.ecriture_origine_id = $t.id) THEN 1 ELSE 0 END) AS engagements, COUNT(*) AS n FROM $t WHERE exercice_id = %d GROUP BY service_id", $exo ), ARRAY_A );
		$par  = array();
		foreach ( $rows as $r ) {
			$par[ (int) $r['service_id'] ] = $r;
		}
		$out   = array();
		$bloq  = 0;
		$avert = 0;
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$sid = (int) $s['id'];
			$r   = $par[ $sid ] ?? null;
			$si  = IGE_FFM_Budget::solde_initial( $exo, $sid );
			if ( ! $r && ! $si && ! (int) $s['actif'] ) {
				continue;
			}
			$b = array(
				'service'        => $s,
				'ecritures'      => $r ? (int) $r['n'] : 0,
				'non_transmises' => $r ? (int) $r['non_transmises'] : 0,
				'a_verifier'     => $r ? (int) $r['a_verifier'] : 0,
				'engagements'    => $r ? (int) $r['engagements'] : 0,
				'mois_clos'      => IGE_FFM_Arretes::dernier_mois_clos( $exo, $sid ),
				'caisse'         => IGE_FFM_Budget::caisse( $exo, $sid ),
			);
			$b['bloquant']      = $b['non_transmises'] + $b['a_verifier'] + $b['engagements'] > 0;
			$b['avertissement'] = $b['ecritures'] && $b['mois_clos'] < 12;
			$bloq              += $b['bloquant'] ? 1 : 0;
			$avert             += $b['avertissement'] ? 1 : 0;
			$out[]              = $b;
		}
		return array( 'services' => $out, 'bloquants' => $bloq, 'avertissements' => $avert );
	}

	/**
	 * Clôture l'exercice : plus aucune écriture, soldes reportés, exercice suivant ouvert.
	 *
	 * @param int      $exo          Exercice à clôturer.
	 * @param string   $confirmation Texte « CLÔTURER AAAA ».
	 * @param int|null $user_id      Auteur.
	 * @return int|WP_Error Identifiant de l'exercice ouvert.
	 */
	public static function cloturer( $exo, $confirmation, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'La clôture est réservée à Administration et Finance.' );
		}
		$ex = self::exercice( $exo );
		if ( ! $ex || 'ouvert' !== $ex['statut'] ) {
			return new WP_Error( 'exercice', 'Cet exercice n’est pas ouvert.' );
		}
		if ( trim( (string) $confirmation ) !== 'CLÔTURER ' . $ex['annee'] ) {
			return new WP_Error( 'confirmation', 'Tapez exactement « CLÔTURER ' . $ex['annee'] . ' » pour confirmer.' );
		}
		$bilan = self::bilan( $exo );
		if ( $bilan['bloquants'] ) {
			return new WP_Error( 'bloquants', sprintf( '%d service(s) ont encore des écritures non transmises, à vérifier ou des engagements non payés.', $bilan['bloquants'] ) );
		}
		$et   = IGE_FFM_DB::t( 'exercice' );
		$next = self::exercice_prepare();
		if ( $next && (int) $next['annee'] === (int) $ex['annee'] + 1 ) {
			$nid = (int) $next['id'];
		} else {
			$y  = (int) $ex['annee'] + 1;
			$ok = $wpdb->insert( $et, array( 'annee' => $y, 'date_debut' => $y . '-01-01', 'date_fin' => $y . '-12-31', 'statut' => 'prepare', 'budget_obligatoire' => 1, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $ok ) {
				return new WP_Error( 'exercice', 'Impossible de créer l’exercice suivant.' );
			}
			$nid = (int) $wpdb->insert_id;
		}
		$st      = IGE_FFM_DB::t( 'solde_initial' );
		$reports = array();
		foreach ( $bilan['services'] as $b ) {
			$sid = (int) $b['service']['id'];
			$wpdb->delete( $st, array( 'exercice_id' => $nid, 'service_id' => $sid, 'devise' => 'CDF' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 0 !== $b['caisse'] ) {
				$wpdb->insert( $st, array( 'exercice_id' => $nid, 'service_id' => $sid, 'devise' => 'CDF', 'montant' => $b['caisse'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$reports[ $b['service']['code'] ] = $b['caisse'];
			}
		}
		$wpdb->update( $et, array( 'statut' => 'clos' ), array( 'id' => $exo ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $et, array( 'statut' => 'ouvert' ), array( 'id' => $nid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'cloture_exercice', 'exercice', $ex['annee'], array( 'statut' => 'ouvert' ), array( 'statut' => 'clos', 'exercice_ouvert' => (int) $ex['annee'] + 1, 'soldes_reportes' => wp_json_encode( $reports, JSON_UNESCAPED_UNICODE ) ), $user_id );
		return $nid;
	}
}
