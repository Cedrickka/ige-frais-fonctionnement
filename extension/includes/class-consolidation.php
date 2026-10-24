<?php
/**
 * Consolidation (EF-71, RG-14) : seules les écritures validées par le central comptent.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consolidation.
 */
final class IGE_FFM_Consolidation {

	/**
	 * Agrégats bruts des écritures validées.
	 *
	 * @param int $exo      Exercice.
	 * @param int $mois_max Jusqu'à ce mois inclus (12 = tout l'exercice).
	 * @return array[]
	 */
	private static function lignes( $exo, $mois_max = 12 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT service_id, rubrique_id, mois, type, statut, SUM(montant) AS total FROM $t WHERE exercice_id = %d AND mois <= %d AND etat_verification = 'validee' AND etat_transmission IN ('transmise','a_retransmettre') GROUP BY service_id, rubrique_id, mois, type, statut", $exo, $mois_max ), ARRAY_A );
	}

	/**
	 * Budgets par service et rubrique.
	 *
	 * @param int $exo Exercice.
	 * @return array<int,array<int,int>>
	 */
	private static function budgets( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT service_id, rubrique_id, montant FROM ' . IGE_FFM_DB::t( 'budget' ) . ' WHERE exercice_id = %d', $exo ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['service_id'] ][ (int) $r['rubrique_id'] ] = (int) $r['montant'];
		}
		return $out;
	}

	/**
	 * Consolidation complète.
	 *
	 * @param int $exo      Exercice.
	 * @param int $mois_max Jusqu'à ce mois inclus.
	 * @param int $mois_seul Mois isolé (colonne « dont ce mois »), 0 = aucun.
	 * @return array{services:array, categories:array, rubriques:array, mois:array, total:array}
	 */
	public static function calculer( $exo, $mois_max = 12, $mois_seul = 0 ) {
		global $wpdb;
		$rubs = array();
		foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) {
			$rubs[ (int) $r['id'] ] = $r;
		}
		$cats = array();
		foreach ( IGE_FFM_Referentiels::all( 'categorie' ) as $c ) {
			$cats[ (int) $c['id'] ] = array( 'libelle' => $c['libelle'], 'consomme' => 0, 'budget' => 0 );
		}
		$bud  = self::budgets( $exo );
		$vide = array( 'budget' => 0, 'avec_budget' => false, 'entrees' => 0, 'paye' => 0, 'engage' => 0, 'mois' => 0 );
		$svc  = array();
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$svc[ (int) $s['id'] ] = array( 'service' => $s ) + $vide;
		}
		$par_rub  = array();
		$par_mois = array_fill( 1, 12, 0 );
		foreach ( self::lignes( $exo, $mois_max ) as $l ) {
			$sid = (int) $l['service_id'];
			$m   = (int) $l['total'];
			if ( ! isset( $svc[ $sid ] ) ) {
				continue;
			}
			if ( 'entree' === $l['type'] ) {
				$svc[ $sid ]['entrees'] += $m;
				continue;
			}
			$k                  = 'payee' === $l['statut'] ? 'paye' : 'engage';
			$svc[ $sid ][ $k ] += $m;
			if ( $mois_seul && (int) $l['mois'] === (int) $mois_seul ) {
				$svc[ $sid ]['mois'] += $m;
			}
			$rid                       = (int) $l['rubrique_id'];
			$par_rub[ $rid ]           = ( $par_rub[ $rid ] ?? 0 ) + $m;
			$par_mois[ (int) $l['mois'] ] += $m;
			$cid                       = isset( $rubs[ $rid ] ) ? (int) $rubs[ $rid ]['categorie_id'] : 0;
			if ( isset( $cats[ $cid ] ) ) {
				$cats[ $cid ]['consomme'] += $m;
			}
		}
		foreach ( $bud as $sid => $lignes ) {
			if ( ! isset( $svc[ $sid ] ) ) {
				continue;
			}
			foreach ( $lignes as $rid => $b ) {
				$svc[ $sid ]['budget']     += $b;
				$svc[ $sid ]['avec_budget'] = true;
				$cid                        = isset( $rubs[ $rid ] ) ? (int) $rubs[ $rid ]['categorie_id'] : 0;
				if ( isset( $cats[ $cid ] ) ) {
					$cats[ $cid ]['budget'] += $b;
				}
			}
		}
		// État de la vérification et dernier lot, par service.
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$av = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) AS n FROM $t WHERE exercice_id = %d AND etat_transmission = 'transmise' AND etat_verification = 'a_verifier' GROUP BY service_id", $exo ), ARRAY_A );
		$av = array_map( 'intval', wp_list_pluck( $av, 'n', 'service_id' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dl = $wpdb->get_results( $wpdb->prepare( 'SELECT service_id, MAX(envoye_le) AS d FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE exercice_id = %d GROUP BY service_id', $exo ), ARRAY_A );
		$dl = wp_list_pluck( $dl, 'd', 'service_id' );

		$tot = array( 'budget' => 0, 'entrees' => 0, 'paye' => 0, 'engage' => 0, 'mois' => 0, 'caisse' => 0, 'a_verifier' => 0, 'disponible' => 0 );
		foreach ( $svc as $sid => &$s ) {
			$s['consomme']   = $s['paye'] + $s['engage'];
			$s['disponible'] = $s['avec_budget'] ? $s['budget'] - $s['consomme'] : null;
			$s['taux']       = $s['avec_budget'] && $s['budget'] > 0 ? $s['consomme'] / $s['budget'] * 100 : null;
			$s['caisse']     = IGE_FFM_Budget::caisse( $exo, $sid );
			$s['a_verifier'] = $av[ $sid ] ?? 0;
			$s['dernier_lot'] = $dl[ $sid ] ?? null;
			$s['activite']   = $s['budget'] || $s['consomme'] || $s['entrees'] || $s['caisse'];
			$s['actif']      = (int) $s['service']['actif'] || $s['activite'];
			foreach ( array( 'budget', 'entrees', 'paye', 'engage', 'mois', 'caisse', 'a_verifier' ) as $k ) {
				$tot[ $k ] += $s[ $k ];
			}
			if ( null !== $s['disponible'] ) {
				$tot['disponible'] += $s['disponible'];
			}
		}
		unset( $s );
		$svc               = array_filter( $svc, static fn( $s ) => $s['actif'] );
		$tot['consomme']   = $tot['paye'] + $tot['engage'];
		$tot['taux']       = $tot['budget'] > 0 ? array_sum( array_map( static fn( $s ) => $s['avec_budget'] ? $s['consomme'] : 0, $svc ) ) / $tot['budget'] * 100 : null;
		$tot['nb_services'] = count( $svc );
		$tot['nb_activite'] = count( array_filter( $svc, static fn( $s ) => $s['activite'] ) );

		$rub_out = array();
		foreach ( $par_rub as $rid => $m ) {
			$rub_out[ $rid ] = array( 'rubrique' => $rubs[ $rid ] ?? array( 'code' => '?', 'libelle' => '' ), 'consomme' => $m );
		}
		uasort( $rub_out, static fn( $a, $b ) => $b['consomme'] <=> $a['consomme'] );
		uasort( $cats, static fn( $a, $b ) => $b['consomme'] <=> $a['consomme'] );
		return array(
			'services'   => $svc,
			'categories' => $cats,
			'rubriques'  => $rub_out,
			'mois'       => $par_mois,
			'total'      => $tot,
		);
	}

	/**
	 * Part de l'exercice écoulée à une date (%).
	 *
	 * @param array  $ex   Exercice.
	 * @param string $date Date.
	 * @return float
	 */
	public static function temps_ecoule( array $ex, $date = '' ) {
		$date = $date ?: IGE_FFM_Contexte::aujourdhui();
		$d    = strtotime( $ex['date_debut'] );
		$f    = strtotime( $ex['date_fin'] ) + DAY_IN_SECONDS;
		return max( 0, min( 100, ( strtotime( $date ) + DAY_IN_SECONDS - $d ) / ( $f - $d ) * 100 ) );
	}

	/**
	 * Alertes prioritaires du central (EF-74).
	 *
	 * @param int $exo Exercice.
	 * @return array[] [ niveau (ko|warn|info), texte, lien ].
	 */
	public static function alertes( $exo ) {
		global $wpdb;
		$out    = array();
		$alerte = (int) IGE_FFM_Reglages::get( 'alerte_rubrique' );
		$svc    = wp_list_pluck( IGE_FFM_Referentiels::all( 'service' ), 'code', 'id' );
		foreach ( array_keys( $svc ) as $sid ) {
			$suivi = IGE_FFM_Budget::suivi( $exo, (int) $sid );
			foreach ( $suivi['lignes'] as $l ) {
				if ( null === $l['taux'] ) {
					continue;
				}
				if ( $l['taux'] > 100 ) {
					$out[] = array( 'ko', sprintf( '%s a dépassé le budget de la rubrique %s (%d %%).', $svc[ $sid ], $l['rubrique']['code'], round( $l['taux'] ) ), add_query_arg( 'service', $sid, ige_ffm_url( 'budget' ) ) );
				} elseif ( $l['taux'] >= $alerte ) {
					$out[] = array( 'warn', sprintf( '%s : rubrique %s consommée à %d %%.', $svc[ $sid ], $l['rubrique']['code'], round( $l['taux'] ) ), add_query_arg( 'service', $sid, ige_ffm_url( 'budget' ) ) );
				}
			}
		}
		foreach ( IGE_FFM_Avances::synthese( $exo ) as $l ) {
			if ( $l['tranches']['plus90'] > 0 ) {
				$out[] = array( 'ko', sprintf( '%s : %s d’avance non justifiés depuis plus de 90 jours.', $l['service']['code'], ige_ffm_montant( $l['tranches']['plus90'] ) ), add_query_arg( 'service', $l['service']['id'], ige_ffm_url( 'avances' ) ) );
			}
		}
		// Services qui ont des écritures non transmises depuis plus de N jours.
		$delai = (int) IGE_FFM_Reglages::get( 'delai_relance' );
		$t     = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, MIN(saisi_le) AS d, COUNT(*) AS n FROM $t WHERE exercice_id = %d AND etat_transmission IN ('non_transmise','a_retransmettre') GROUP BY service_id", $exo ), ARRAY_A );
		$tard = array();
		foreach ( $rows as $r ) {
			if ( strtotime( $r['d'] ) < time() - $delai * DAY_IN_SECONDS ) {
				$tard[] = $svc[ $r['service_id'] ] ?? $r['service_id'];
			}
		}
		if ( $tard ) {
			$out[] = array( 'warn', sprintf( '%d service(s) n’ont pas transmis des écritures saisies il y a plus de %d jours : %s.', count( $tard ), $delai, implode( ', ', $tard ) ), ige_ffm_url( 'consolidation' ) );
		}
		// Anomalies en attente.
		$syn = IGE_FFM_Verification::synthese( $exo );
		if ( $syn['anomalies']['n'] ) {
			$out[] = array( 'warn', sprintf( '%d écriture(s) à vérifier présentent une anomalie.', $syn['anomalies']['n'] ), ige_ffm_url( 'verification', array( 'vue' => 'anomalies' ) ) );
		}
		// Arrêtés du mois précédent.
		$ex = IGE_FFM_Contexte::exercice();
		if ( $ex && (int) wp_date( 'n' ) > 1 && (int) wp_date( 'Y' ) === (int) $ex['annee'] ) {
			$ref     = (int) wp_date( 'n' ) - 1;
			$manque  = 0;
			foreach ( array_keys( $svc ) as $sid ) {
				if ( IGE_FFM_Arretes::dernier_mois_clos( $exo, (int) $sid ) < $ref && IGE_FFM_Ecritures::dernieres( $exo, (int) $sid, 1 ) ) {
					++$manque;
				}
			}
			if ( $manque ) {
				$out[] = array( 'info', sprintf( '%d arrêté(s) de caisse de %s restent à signer.', $manque, strtolower( IGE_FFM_Ecran_Livre::mois_noms()[ $ref ] ) ), ige_ffm_url( 'caisses' ) );
			}
		}
		$out   = (array) apply_filters( 'ige_ffm_alertes', $out, $exo );
		$ordre = array( 'ko' => 0, 'warn' => 1, 'info' => 2 );
		usort( $out, static fn( $a, $b ) => ( $ordre[ $a[0] ] ?? 3 ) <=> ( $ordre[ $b[0] ] ?? 3 ) );
		return $out;
	}
}
