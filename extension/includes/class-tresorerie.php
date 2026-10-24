<?php
/**
 * Plan de trésorerie (VA-07) : besoins prévus par mois comparés aux fonds attendus.
 *
 * Besoins d'un mois : demandes de fonds en circuit ou accordées non décaissées (selon leur date
 * de besoin ; celles en retard comptent pour le mois en cours), missions planifiées non payées
 * (selon leur départ), engagements non payés (mois en cours) et dépenses récurrentes (moyenne des
 * sorties payées des trois derniers mois complets, hors missions).
 * Fonds : disponible de départ (caisses des services et banque principale) puis fonds attendus
 * saisis par Administration et Finance (dotations, allocations).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trésorerie.
 */
final class IGE_FFM_Tresorerie {

	/**
	 * Fonds attendus saisis.
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function previsions( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'prevision' ) . ' WHERE exercice_id = %d ORDER BY mois, id', $exo ), ARRAY_A );
	}

	/**
	 * Ajoute des fonds attendus.
	 *
	 * @param array    $d       mois, libelle, montant.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function ajouter( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$ex   = IGE_FFM_Contexte::exercice();
		$mois = (int) ( $d['mois'] ?? 0 );
		$lib  = trim( sanitize_text_field( (string) ( $d['libelle'] ?? '' ) ) );
		$m    = ige_ffm_parse_montant( $d['montant'] ?? '' );
		if ( ! $ex || $mois < 1 || $mois > 12 ) {
			return new WP_Error( 'mois', 'Choisissez le mois.' );
		}
		if ( mb_strlen( $lib ) < 3 || null === $m || $m <= 0 ) {
			return new WP_Error( 'prevision', 'Indiquez l’origine des fonds et un montant positif.' );
		}
		$wpdb->insert( IGE_FFM_DB::t( 'prevision' ), array( 'exercice_id' => (int) $ex['id'], 'mois' => $mois, 'libelle' => mb_substr( $lib, 0, 190 ), 'montant' => $m, 'created_by' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'prevision_tresorerie', 'tresorerie', IGE_FFM_Ecran_Livre::mois_noms()[ $mois ], null, array( 'libelle' => $lib, 'montant' => $m ), $user_id );
		return $id;
	}

	/**
	 * Supprime des fonds attendus.
	 *
	 * @param int      $id      Prévision.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function supprimer( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'prevision' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $p ) {
			return new WP_Error( 'prevision', 'Prévision introuvable.' );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'prevision' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'prevision_tresorerie_suppr', 'tresorerie', $p['libelle'], array( 'montant' => (int) $p['montant'], 'mois' => (int) $p['mois'] ), null, $user_id );
		return true;
	}

	/**
	 * Moyenne mensuelle des dépenses payées (hors missions) des trois derniers mois complets.
	 *
	 * @param int    $exo Exercice.
	 * @param string $auj Date du jour.
	 * @return int
	 */
	public static function recurrent( $exo, $auj ) {
		global $wpdb;
		$fin = gmdate( 'Y-m-01', strtotime( $auj ) );
		$deb = gmdate( 'Y-m-01', strtotime( $fin . ' -3 months' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$s = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(montant),0) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND type = 'sortie' AND statut = 'payee' AND mission_id IS NULL AND date_paiement >= %s AND date_paiement < %s", $exo, $deb, $fin ) );
		return (int) round( max( 0, $s ) / 3 );
	}

	/**
	 * Disponible au départ : caisses des services et banque principale.
	 *
	 * @param int $exo Exercice.
	 * @return array{caisses:int, banque:?int, total:int}
	 */
	public static function disponible( $exo ) {
		$caisses = 0;
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$caisses += IGE_FFM_Budget::caisse( $exo, (int) $s['id'] );
		}
		$banque = null;
		foreach ( IGE_FFM_Banque::comptes() as $c ) {
			if ( (int) $c['principal'] ) {
				$banque = IGE_FFM_Banque::solde_comptable( $c, IGE_FFM_Contexte::aujourdhui() );
			}
		}
		return array( 'caisses' => $caisses, 'banque' => $banque, 'total' => $caisses + (int) $banque );
	}

	/**
	 * Plan des prochains mois.
	 *
	 * @param int $exo Exercice.
	 * @param int $nb  Nombre de mois (mois en cours compris).
	 * @return array{mois:array, disponible:array, recurrent:int, tension:?array}
	 */
	public static function plan( $exo, $nb = 4 ) {
		global $wpdb;
		$ex   = IGE_FFM_Contexte::exercice();
		$auj  = IGE_FFM_Contexte::aujourdhui();
		$m0   = (int) substr( $auj, 5, 2 );
		$an   = (int) substr( $auj, 0, 4 );
		$dern = (int) $ex['annee'] === $an ? 12 : 12;
		$mois = array();
		for ( $i = 0; $i < $nb && $m0 + $i <= $dern; $i++ ) {
			$mois[ $m0 + $i ] = array( 'demandes' => 0, 'missions' => 0, 'engagements' => 0, 'recurrent' => 0, 'fonds' => 0, 'detail_fonds' => array(), 'nb_missions' => 0, 'nb_demandes' => 0 );
		}
		if ( (int) $ex['annee'] !== $an ) {
			$mois = array();
		}
		$cle = static function ( $date ) use ( $m0, $an ) {
			$m = (int) substr( $date, 5, 2 );
			return (int) substr( $date, 0, 4 ) < $an || $m < $m0 ? $m0 : $m;
		};
		foreach ( IGE_FFM_Demandes::besoins( $exo ) as $d ) {
			$k = $cle( $d['date_besoin'] );
			if ( isset( $mois[ $k ] ) ) {
				$mois[ $k ]['demandes'] += null === $d['montant_accorde'] ? (int) $d['montant'] : (int) $d['montant_accorde'];
				++$mois[ $k ]['nb_demandes'];
			}
		}
		foreach ( IGE_FFM_Missions::a_payer( $exo ) as $m ) {
			$k = $cle( $m['date_depart'] );
			if ( isset( $mois[ $k ] ) ) {
				$mois[ $k ]['missions'] += (int) $m['montant'];
				++$mois[ $k ]['nb_missions'];
			}
		}
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$eng = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(e.montant),0) FROM $t e WHERE e.exercice_id = %d AND e.type = 'sortie' AND e.statut = 'engagee' AND e.ecriture_origine_id IS NULL AND NOT EXISTS (SELECT 1 FROM $t c WHERE c.ecriture_origine_id = e.id)", $exo ) );
		if ( isset( $mois[ $m0 ] ) ) {
			$mois[ $m0 ]['engagements'] = $eng;
		}
		$rec = self::recurrent( $exo, $auj );
		foreach ( self::previsions( $exo ) as $p ) {
			if ( isset( $mois[ (int) $p['mois'] ] ) ) {
				$mois[ (int) $p['mois'] ]['fonds']         += (int) $p['montant'];
				$mois[ (int) $p['mois'] ]['detail_fonds'][] = $p;
			}
		}
		$dispo   = self::disponible( $exo );
		$solde   = $dispo['total'];
		$tension = null;
		foreach ( $mois as $k => &$x ) {
			$x['recurrent'] = $rec;
			$x['besoins']   = $x['demandes'] + $x['missions'] + $x['engagements'] + $x['recurrent'];
			$x['debut']     = $solde;
			$x['fin']       = $solde + $x['fonds'] - $x['besoins'];
			$solde          = $x['fin'];
			if ( ! $tension && $x['fin'] < 0 ) {
				$tension = array( 'mois' => $k, 'manque' => -1 * $x['fin'] );
			}
		}
		unset( $x );
		return array( 'mois' => $mois, 'disponible' => $dispo, 'recurrent' => $rec, 'tension' => $tension );
	}
}
