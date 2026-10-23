<?php
/**
 * Avances de fonds aux services (VA-01, VA-02) et règle de réapprovisionnement sur justification.
 *
 * Une avance enregistrée par Administration et Finance crée automatiquement l'entrée dans la
 * caisse du service (déjà validée). La justification se calcule « premier entré, premier sorti » :
 * les sorties payées et validées par le central apurent d'abord le solde initial, puis les fonds
 * reçus dans l'ordre chronologique. Une nouvelle avance n'est accordée que si la précédente est
 * justifiée au moins au seuil réglé (80 % par défaut), sauf dérogation motivée.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Avances.
 */
final class IGE_FFM_Avances {

	/**
	 * Une avance.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'avance' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Avances d'un exercice (et d'un service).
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = tous).
	 * @return array[]
	 */
	public static function liste( $exo, $sid = 0 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'avance' );
		$w = $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d $w ORDER BY date_remise ASC, id ASC", $exo ), ARRAY_A );
	}

	/**
	 * L'avance est-elle annulée (son écriture contrepassée) ?
	 *
	 * @param array $a Avance.
	 * @return bool
	 */
	public static function annulee( array $a ) {
		return $a['ecriture_id'] && IGE_FFM_Ecritures::contrepassation_de( (int) $a['ecriture_id'] );
	}

	/**
	 * Justification des fonds d'un service (premier entré, premier sorti).
	 *
	 * @param int    $exo Exercice.
	 * @param int    $sid Service.
	 * @param string $au  Date de calcul de l'ancienneté (aujourd'hui par défaut).
	 * @return array{sources:array, avances:array, justifie:int, remis:int, reliquat:int, taux_derniere:?float, derniere:?array}
	 */
	public static function justification( $exo, $sid, $au = '' ) {
		global $wpdb;
		$au = $au ?: IGE_FFM_Contexte::aujourdhui();
		$t  = IGE_FFM_DB::t( 'ecriture' );
		// Dépenses justifiées : sorties payées validées (les contrepassations validées viennent en déduction).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$justifie = max( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(montant),0) FROM $t WHERE exercice_id = %d AND service_id = %d AND type = 'sortie' AND statut = 'payee' AND etat_verification = 'validee' AND etat_transmission IN ('transmise','a_retransmettre')", $exo, $sid ) ) );

		// Sources de fonds dans l'ordre : solde initial, puis avances et entrées saisies par le service.
		$sources = array();
		$ex      = IGE_FFM_Contexte::exercice();
		$si      = IGE_FFM_Budget::solde_initial( $exo, $sid );
		if ( $si > 0 ) {
			$sources[] = array( 'type' => 'report', 'libelle' => 'Solde initial (report)', 'date' => $ex ? $ex['date_debut'] : '', 'montant' => $si, 'avance' => null );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$entrees = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d AND service_id = %d AND type = 'entree' ORDER BY date_operation ASC, id ASC", $exo, $sid ), ARRAY_A );
		$annule  = array();
		foreach ( $entrees as $e ) {
			if ( $e['ecriture_origine_id'] ) {
				$annule[ (int) $e['ecriture_origine_id'] ] = true;
			}
		}
		$avances = array();
		foreach ( self::liste( $exo, $sid ) as $a ) {
			$avances[ (int) $a['ecriture_id'] ] = $a;
		}
		foreach ( $entrees as $e ) {
			if ( $e['ecriture_origine_id'] || isset( $annule[ (int) $e['id'] ] ) || (int) $e['montant'] <= 0 ) {
				continue;
			}
			$a         = $avances[ (int) $e['id'] ] ?? null;
			$sources[] = array(
				'type'    => $a ? 'avance' : 'entree',
				'libelle' => $a ? $a['numero'] : 'Entrée ' . $e['id_ecriture'],
				'date'    => $e['date_operation'],
				'montant' => (int) $e['montant'],
				'avance'  => $a,
			);
		}

		$reste = $justifie;
		$remis = 0;
		$out   = array();
		foreach ( $sources as $src ) {
			$part            = min( $reste, $src['montant'] );
			$reste          -= $part;
			$src['justifie'] = $part;
			$src['reliquat'] = $src['montant'] - $part;
			$src['taux']     = $src['montant'] > 0 ? $part / $src['montant'] * 100 : 100.0;
			$src['age']      = $src['reliquat'] > 0 && $src['date'] ? max( 0, (int) floor( ( strtotime( $au ) - strtotime( $src['date'] ) ) / DAY_IN_SECONDS ) ) : 0;
			$out[]           = $src;
			$remis          += $src['montant'];
		}
		$av       = array_values( array_filter( $out, static fn( $s ) => 'avance' === $s['type'] ) );
		$derniere = $av ? end( $av ) : null;
		return array(
			'sources'       => $out,
			'avances'       => $av,
			'justifie'      => min( $justifie, $remis ),
			'remis'         => $remis,
			'reliquat'      => max( 0, $remis - $justifie ),
			'taux_derniere' => $derniere ? $derniere['taux'] : null,
			'derniere'      => $derniere,
		);
	}

	/**
	 * Une nouvelle avance est-elle permise sans dérogation ?
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return true|WP_Error
	 */
	public static function eligible( $exo, $sid ) {
		$j     = self::justification( $exo, $sid );
		$seuil = (int) IGE_FFM_Reglages::get( 'seuil_justification' );
		if ( $j['derniere'] && $j['taux_derniere'] < $seuil ) {
			return new WP_Error(
				'justification',
				sprintf( 'La dernière avance (%s) n’est justifiée qu’à %d %% ; le seuil est de %d %%. Il reste %s à justifier par des dépenses validées.', $j['derniere']['libelle'], floor( $j['taux_derniere'] ), $seuil, ige_ffm_montant( $j['derniere']['reliquat'] ) )
			);
		}
		return true;
	}

	/**
	 * Enregistre une avance et l'entrée correspondante dans la caisse du service.
	 *
	 * @param array    $data    service_id, montant, date_remise, mode_paiement_id, objet, derogation (motif).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Identifiant de l'avance.
	 */
	public static function creer( array $data, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Les avances sont enregistrées par Administration et Finance.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$exo  = (int) $ex['id'];
		$sid  = (int) ( $data['service_id'] ?? 0 );
		$s    = IGE_FFM_Referentiels::get( 'service', $sid );
		$mnt  = ige_ffm_parse_montant( $data['montant'] ?? '' );
		$date = (string) ( $data['date_remise'] ?? '' );
		$mode = (int) ( $data['mode_paiement_id'] ?? 0 );
		$obj  = trim( sanitize_text_field( (string) ( $data['objet'] ?? '' ) ) );
		$der  = trim( sanitize_text_field( (string) ( $data['derogation'] ?? '' ) ) );
		if ( ! $s || ! (int) $s['actif'] ) {
			return new WP_Error( 'service', 'Choisissez un service actif.' );
		}
		if ( null === $mnt || $mnt <= 0 ) {
			return new WP_Error( 'montant', 'Le montant doit être un nombre entier supérieur à 0 (en CDF).' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date < $ex['date_debut'] || $date > $ex['date_fin'] || $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', sprintf( 'La date de remise doit appartenir à l’exercice %d et ne pas être future.', $ex['annee'] ) );
		}
		if ( IGE_FFM_Ecritures::mois_clos( $exo, $sid, $date ) ) {
			return new WP_Error( 'date', 'Ce mois est clôturé pour le service : datez la remise d’un mois ouvert.' );
		}
		$m = IGE_FFM_Referentiels::get( 'mode_paiement', $mode );
		if ( ! $m || ! (int) $m['actif'] ) {
			return new WP_Error( 'mode', 'Choisissez le mode de remise.' );
		}
		if ( mb_strlen( $obj ) < 3 ) {
			return new WP_Error( 'objet', 'Indiquez l’objet de l’avance (période, mission, besoin couvert).' );
		}
		$ok = self::eligible( $exo, $sid );
		if ( is_wp_error( $ok ) ) {
			if ( mb_strlen( $der ) < 10 ) {
				return new WP_Error( 'justification', $ok->get_error_message() . ' Pour accorder l’avance quand même, indiquez le motif de la dérogation.' );
			}
		} else {
			$der = '';
		}

		$t = IGE_FFM_DB::t( 'avance' );
		$id = 0;
		for ( $essai = 0; $essai < 5 && ! $id; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n   = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d", $exo ) ) + $essai;
			$num = sprintf( 'AV-%d-%03d', $ex['annee'], $n );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'numero'           => $num,
					'exercice_id'      => $exo,
					'service_id'       => $sid,
					'montant'          => $mnt,
					'date_remise'      => $date,
					'mode_paiement_id' => $mode,
					'objet'            => mb_substr( $obj . ( $der ? ' [dérogation : ' . $der . ']' : '' ), 0, 255 ),
					'created_by'       => (int) $user_id,
					'created_at'       => ige_ffm_now(),
				)
			);
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$id = (int) $wpdb->insert_id;
			}
		}
		if ( ! $id ) {
			return new WP_Error( 'numero', 'Enregistrement impossible pour le moment. Réessayez.' );
		}
		$eid = IGE_FFM_Ecritures::inserer_avance(
			array(
				'exercice_id'      => $exo,
				'service_id'       => $sid,
				'date_operation'   => $date,
				'montant'          => $mnt,
				'description'      => mb_substr( 'Avance de fonds ' . $num . ' : ' . $obj, 0, 255 ),
				'num_piece'        => $num,
				'mode_paiement_id' => $mode,
				'avance_id'        => $id,
				'observations'     => $der ? 'Dérogation à la règle de justification : ' . $der : '',
			),
			(int) $ex['annee'],
			$user_id
		);
		if ( is_wp_error( $eid ) ) {
			$wpdb->delete( $t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $eid;
		}
		$wpdb->update( $t, array( 'ecriture_id' => $eid ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log(
			'avance_fonds',
			'avance',
			$num,
			null,
			array_filter(
				array(
					'service'    => $s['code'],
					'montant'    => $mnt,
					'date'       => $date,
					'mode'       => $m['libelle'],
					'objet'      => $obj,
					'derogation' => $der,
				)
			),
			$user_id
		);
		return $id;
	}

	/**
	 * Annule une avance (contrepassation de son entrée, si la caisse du service le permet).
	 *
	 * @param int      $id      Avance.
	 * @param string   $motif   Motif.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function annuler( $id, $motif, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		$a       = self::get( $id );
		if ( ! $a || ! $a['ecriture_id'] ) {
			return new WP_Error( 'avance', 'Avance introuvable.' );
		}
		$r = IGE_FFM_Ecritures::contrepasser( (int) $a['ecriture_id'], $motif, $user_id, true );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		IGE_FFM_Audit::log( 'annulation_avance', 'avance', $a['numero'], array( 'montant' => (int) $a['montant'] ), array( 'motif' => trim( sanitize_text_field( $motif ) ) ), $user_id );
		return true;
	}

	/**
	 * Synthèse par service pour le central.
	 *
	 * @param int $exo Exercice.
	 * @return array<int,array>
	 */
	public static function synthese( $exo ) {
		$out   = array();
		$seuil = (int) IGE_FFM_Reglages::get( 'seuil_justification' );
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$sid = (int) $s['id'];
			$j   = self::justification( $exo, $sid );
			if ( ! (int) $s['actif'] && ! $j['avances'] ) {
				continue;
			}
			$remis_av = array_sum( array_column( $j['avances'], 'montant' ) );
			$just_av  = array_sum( array_column( $j['avances'], 'justifie' ) );
			$tranches = array( 'moins30' => 0, 'de30a60' => 0, 'de60a90' => 0, 'plus90' => 0 );
			$ancien   = 0;
			foreach ( $j['avances'] as $a ) {
				if ( $a['reliquat'] <= 0 ) {
					continue;
				}
				$k              = $a['age'] < 30 ? 'moins30' : ( $a['age'] < 60 ? 'de30a60' : ( $a['age'] < 90 ? 'de60a90' : 'plus90' ) );
				$tranches[ $k ] += $a['reliquat'];
				$ancien         = max( $ancien, $a['age'] );
			}
			$out[ $sid ] = array(
				'service'       => $s,
				'remis'         => $remis_av,
				'justifie'      => $just_av,
				'reliquat'      => $remis_av - $just_av,
				'taux'          => $remis_av > 0 ? $just_av / $remis_av * 100 : null,
				'taux_derniere' => $j['taux_derniere'],
				'eligible'      => ! $j['derniere'] || $j['taux_derniere'] >= $seuil,
				'tranches'      => $tranches,
				'plus_ancien'   => $ancien,
				'nb'            => count( $j['avances'] ),
			);
		}
		return $out;
	}
}
