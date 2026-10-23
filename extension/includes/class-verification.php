<?php
/**
 * Vérification par le central (Administration et Finance) : file, synthèse, validation, rejet.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vérification.
 */
final class IGE_FFM_Verification {

	/**
	 * File de vérification.
	 *
	 * @param int   $exo Exercice.
	 * @param array $f   Filtres : vue (a_verifier | anomalies | validee | rejetee), service_id, lot_id.
	 * @param int   $max Nombre maximum de lignes.
	 * @return array{rows:array, total:int}
	 */
	public static function file( $exo, array $f, $max = 300 ) {
		global $wpdb;
		$t     = IGE_FFM_DB::t( 'ecriture' );
		$vue   = $f['vue'] ?? 'a_verifier';
		$where = array( $wpdb->prepare( 'exercice_id = %d', $exo ), "etat_transmission = 'transmise'" );
		if ( 'anomalies' === $vue ) {
			$where[] = "etat_verification = 'a_verifier'";
			$where[] = "anomalies IS NOT NULL AND anomalies NOT IN ('', '[]', '{}')";
		} elseif ( in_array( $vue, array( 'a_verifier', 'validee', 'rejetee' ), true ) ) {
			$where[] = $wpdb->prepare( 'etat_verification = %s', $vue );
		}
		if ( ! empty( $f['service_id'] ) ) {
			$where[] = $wpdb->prepare( 'service_id = %d', $f['service_id'] );
		}
		if ( ! empty( $f['lot_id'] ) ) {
			$where[] = $wpdb->prepare( 'lot_id = %d', $f['lot_id'] );
		}
		$w     = implode( ' AND ', $where );
		$ordre = in_array( $vue, array( 'validee', 'rejetee' ), true ) ? 'valide_le DESC, id DESC' : 'date_operation ASC, id ASC';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $w" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $w ORDER BY $ordre LIMIT %d", $max ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Synthèse par état : nombre et montant des sorties (EF-65), et nombre d'anomalies.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = tous).
	 * @return array<string,array{n:int,montant:int}>
	 */
	public static function synthese( $exo, $sid = 0 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		$w = $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT etat_verification AS e, COUNT(*) AS n, SUM(CASE WHEN type = 'sortie' THEN montant ELSE 0 END) AS m, SUM(CASE WHEN anomalies IS NOT NULL AND anomalies NOT IN ('', '[]', '{}') THEN 1 ELSE 0 END) AS a FROM $t WHERE exercice_id = %d AND etat_transmission = 'transmise' $w GROUP BY etat_verification", $exo ), ARRAY_A );
		$out  = array(
			'a_verifier' => array( 'n' => 0, 'montant' => 0 ),
			'validee'    => array( 'n' => 0, 'montant' => 0 ),
			'rejetee'    => array( 'n' => 0, 'montant' => 0 ),
			'anomalies'  => array( 'n' => 0, 'montant' => 0 ),
		);
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r['e'] ] ) ) {
				$out[ $r['e'] ] = array( 'n' => (int) $r['n'], 'montant' => (int) $r['m'] );
			}
			if ( 'a_verifier' === $r['e'] ) {
				$out['anomalies']['n'] = (int) $r['a'];
			}
		}
		return $out;
	}

	/**
	 * Contrôles communs avant validation ou rejet.
	 *
	 * @param int[] $ids     Écritures.
	 * @param int   $user_id Vérificateur.
	 * @return array{ok:array, refus:array}
	 */
	private static function eligibles( array $ids, $user_id ) {
		$ok    = array();
		$refus = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$e = IGE_FFM_Ecritures::get( $id );
			if ( ! $e || 'transmise' !== $e['etat_transmission'] || 'a_verifier' !== $e['etat_verification'] ) {
				$refus[] = $e ? $e['id_ecriture'] . ' (déjà traitée ou pas transmise)' : '#' . $id;
				continue;
			}
			if ( (int) $e['saisi_par'] === (int) $user_id ) {
				$refus[] = $e['id_ecriture'] . ' (vous l’avez saisie vous-même)';
				continue;
			}
			$ok[] = $e;
		}
		return array( 'ok' => $ok, 'refus' => $refus );
	}

	/**
	 * Valide des écritures.
	 *
	 * @param int[]    $ids     Écritures.
	 * @param int|null $user_id Vérificateur.
	 * @return array{n:int, refus:array}|WP_Error
	 */
	public static function valider( array $ids, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_verifier' ) ) {
			return new WP_Error( 'droits', 'Validation réservée à Administration et Finance.' );
		}
		$el  = self::eligibles( $ids, $user_id );
		$now = ige_ffm_now();
		foreach ( $el['ok'] as $e ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				IGE_FFM_DB::t( 'ecriture' ),
				array( 'etat_verification' => 'validee', 'valide_par' => $user_id, 'valide_le' => $now ),
				array( 'id' => (int) $e['id'], 'etat_verification' => 'a_verifier' )
			);
		}
		if ( $el['ok'] ) {
			IGE_FFM_Audit::log( 'validation', 'ecriture', count( $el['ok'] ) . ' écriture(s)', null, array( 'ecritures' => implode( ', ', wp_list_pluck( $el['ok'], 'id_ecriture' ) ) ), $user_id );
		}
		return array( 'n' => count( $el['ok'] ), 'refus' => $el['refus'] );
	}

	/**
	 * Valide toutes les écritures à vérifier sans anomalie (éventuellement d'un service ou d'un lot).
	 *
	 * @param int      $exo     Exercice.
	 * @param array    $f       Filtres (service_id, lot_id).
	 * @param int|null $user_id Vérificateur.
	 * @return array{n:int, refus:array}|WP_Error
	 */
	public static function valider_sans_anomalie( $exo, array $f, $user_id = null ) {
		$file = self::file( $exo, array( 'vue' => 'a_verifier' ) + $f, 5000 );
		$ids  = array();
		foreach ( $file['rows'] as $e ) {
			if ( ! IGE_FFM_Anomalies::lire( $e ) ) {
				$ids[] = (int) $e['id'];
			}
		}
		return self::valider( $ids, $user_id );
	}

	/**
	 * Rejette des écritures avec un motif ; le service est prévenu.
	 *
	 * @param int[]    $ids     Écritures.
	 * @param string   $motif   Motif (obligatoire).
	 * @param int|null $user_id Vérificateur.
	 * @return array{n:int, refus:array}|WP_Error
	 */
	public static function rejeter( array $ids, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_verifier' ) ) {
			return new WP_Error( 'droits', 'Rejet réservé à Administration et Finance.' );
		}
		$motif = trim( sanitize_text_field( (string) $motif ) );
		if ( mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Le motif du rejet est obligatoire : le service doit savoir quoi corriger.' );
		}
		$el  = self::eligibles( $ids, $user_id );
		$now = ige_ffm_now();
		foreach ( $el['ok'] as $e ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				IGE_FFM_DB::t( 'ecriture' ),
				array( 'etat_verification' => 'rejetee', 'motif_rejet' => mb_substr( $motif, 0, 255 ), 'valide_par' => $user_id, 'valide_le' => $now ),
				array( 'id' => (int) $e['id'], 'etat_verification' => 'a_verifier' )
			);
		}
		if ( $el['ok'] ) {
			IGE_FFM_Audit::log( 'rejet', 'ecriture', count( $el['ok'] ) . ' écriture(s)', null, array( 'ecritures' => implode( ', ', wp_list_pluck( $el['ok'], 'id_ecriture' ) ), 'motif' => $motif ), $user_id );
			$par_service = array();
			foreach ( $el['ok'] as $e ) {
				$par_service[ (int) $e['service_id'] ][] = $e;
			}
			foreach ( $par_service as $sid => $liste ) {
				self::notifier_rejet( $sid, $liste, $motif );
			}
		}
		return array( 'n' => count( $el['ok'] ), 'refus' => $el['refus'] );
	}

	/**
	 * Prévient par courriel les comptes actifs du service (le rejet apparaît aussi à l'accueil du service).
	 *
	 * @param int    $sid   Service.
	 * @param array  $liste Écritures rejetées.
	 * @param string $motif Motif.
	 */
	private static function notifier_rejet( $sid, array $liste, $motif ) {
		global $wpdb;
		if ( ! apply_filters( 'ige_ffm_notifier_par_courriel', true, 'rejet' ) ) {
			return;
		}
		$ct    = IGE_FFM_DB::t( 'compte' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$uids  = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM $ct WHERE service_id = %d AND actif = 1", $sid ) );
		$mails = array();
		foreach ( $uids as $uid ) {
			$u = get_userdata( (int) $uid );
			if ( IGE_FFM_Messagerie::adresse_valide( $u ) ) {
				$mails[] = $u->user_email;
			}
		}
		if ( ! $mails ) {
			return;
		}
		$lignes = array();
		foreach ( $liste as $e ) {
			$lignes[] = '- ' . $e['id_ecriture'] . ' : ' . $e['description'] . ' (' . ige_ffm_montant( (int) $e['montant'] ) . ')';
		}
		$corps = "Bonjour,\n\nAdministration et Finance a rejeté les écritures suivantes :\n\n" . implode( "\n", $lignes )
			. "\n\nMotif : " . $motif
			. "\n\n" . ( IGE_FFM_Reglages::get( 'contrepassation_rejet' ) ? "Corrigez-les par contrepassation puis saisissez l'écriture juste, et transmettez de nouveau.\n" : "Corrigez-les puis transmettez de nouveau.\n" )
			. ige_ffm_url( 'transmission' );
		IGE_FFM_Messagerie::envoyer( $mails, 'Écriture(s) rejetée(s) – ' . IGE_FFM_Comptes::service_code( $sid ), $corps );
	}
}
