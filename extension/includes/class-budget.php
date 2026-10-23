<?php
/**
 * Budget du service, solde initial de caisse, calculs de consommation, de disponible et de caisse.
 *
 * Définitions (cahier des charges, RG-06 et RG-07) :
 * - caisse = solde initial + entrées payées − sorties payées ;
 * - consommé = sorties engagées non payées + sorties payées ;
 * - disponible = budget − consommé (uniquement si une ligne de budget existe pour la rubrique).
 * Les contrepassations sont enregistrées en montant négatif : elles s'annulent naturellement dans ces sommes.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Budget et soldes.
 */
final class IGE_FFM_Budget {

	/**
	 * Lignes de budget existantes : rubrique_id => montant.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array<int,int>
	 */
	public static function lignes( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'budget' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT rubrique_id, montant FROM $t WHERE exercice_id = %d AND service_id = %d", $exo, $sid ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['rubrique_id'] ] = (int) $r['montant'];
		}
		return $out;
	}

	/**
	 * Consommation par rubrique : rubrique_id => [ engage, paye ].
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array<int,array{engage:int,paye:int}>
	 */
	public static function consommation( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT rubrique_id, statut, SUM(montant) AS total FROM $t WHERE exercice_id = %d AND service_id = %d AND type = 'sortie' GROUP BY rubrique_id, statut", $exo, $sid ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$rid = (int) $r['rubrique_id'];
			if ( ! isset( $out[ $rid ] ) ) {
				$out[ $rid ] = array( 'engage' => 0, 'paye' => 0 );
			}
			$out[ $rid ][ 'payee' === $r['statut'] ? 'paye' : 'engage' ] += (int) $r['total'];
		}
		return $out;
	}

	/**
	 * Disponible d'une rubrique (null si aucune ligne de budget).
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @param int $rid Rubrique.
	 * @return int|null
	 */
	public static function disponible( $exo, $sid, $rid ) {
		$lignes = self::lignes( $exo, $sid );
		if ( ! array_key_exists( (int) $rid, $lignes ) ) {
			return null;
		}
		$c = self::consommation( $exo, $sid )[ (int) $rid ] ?? array( 'engage' => 0, 'paye' => 0 );
		return $lignes[ (int) $rid ] - $c['engage'] - $c['paye'];
	}

	/**
	 * Tableau de suivi : une ligne par rubrique active ou utilisée.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array{lignes:array, total:array}
	 */
	public static function suivi( $exo, $sid ) {
		$budget = self::lignes( $exo, $sid );
		$conso  = self::consommation( $exo, $sid );
		$lignes = array();
		$total  = array( 'budget' => 0, 'engage' => 0, 'paye' => 0, 'disponible' => 0, 'avec_budget' => false );
		foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) {
			$rid = (int) $r['id'];
			if ( ! (int) $r['actif'] && ! isset( $budget[ $rid ] ) && ! isset( $conso[ $rid ] ) ) {
				continue;
			}
			$c       = $conso[ $rid ] ?? array( 'engage' => 0, 'paye' => 0 );
			$has     = array_key_exists( $rid, $budget );
			$b       = $has ? $budget[ $rid ] : null;
			$dispo   = $has ? $b - $c['engage'] - $c['paye'] : null;
			$taux    = $has && $b > 0 ? ( $c['engage'] + $c['paye'] ) / $b * 100 : null;
			$lignes[] = array(
				'rubrique'   => $r,
				'budget'     => $b,
				'engage'     => $c['engage'],
				'paye'       => $c['paye'],
				'disponible' => $dispo,
				'taux'       => $taux,
			);
			$total['engage'] += $c['engage'];
			$total['paye']   += $c['paye'];
			if ( $has ) {
				$total['budget']     += $b;
				$total['disponible'] += $dispo;
				$total['avec_budget'] = true;
			}
		}
		$total['taux'] = $total['budget'] > 0 ? ( $total['engage'] + $total['paye'] ) / $total['budget'] * 100 : null;
		return array( 'lignes' => $lignes, 'total' => $total );
	}

	/**
	 * Solde initial de caisse.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return int
	 */
	public static function solde_initial( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'solde_initial' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT montant FROM $t WHERE exercice_id = %d AND service_id = %d AND devise = 'CDF'", $exo, $sid ) );
	}

	/**
	 * Solde de caisse : solde initial + entrées payées − sorties payées.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return int
	 */
	public static function caisse( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN type = 'entree' THEN montant ELSE 0 END) AS e, SUM(CASE WHEN type = 'sortie' AND statut = 'payee' THEN montant ELSE 0 END) AS s FROM $t WHERE exercice_id = %d AND service_id = %d", $exo, $sid ), ARRAY_A );
		return self::solde_initial( $exo, $sid ) + (int) $row['e'] - (int) $row['s'];
	}

	/**
	 * Sorties payées par mois.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array<int,int> mois => montant.
	 */
	public static function mensuel( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT mois, SUM(montant) AS total FROM $t WHERE exercice_id = %d AND service_id = %d AND type = 'sortie' AND statut = 'payee' GROUP BY mois", $exo, $sid ), ARRAY_A );
		$out  = array_fill( 1, 12, 0 );
		foreach ( $rows as $r ) {
			$out[ (int) $r['mois'] ] = (int) $r['total'];
		}
		return $out;
	}

	/**
	 * Le service a-t-il déjà des écritures sur l'exercice ?
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return bool
	 */
	public static function a_des_ecritures( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d AND service_id = %d", $exo, $sid ) );
	}

	/**
	 * Enregistre le budget du service. Après la première écriture, toute modification est une
	 * révision : motif obligatoire, ancien et nouveau montants conservés.
	 *
	 * @param int    $exo     Exercice.
	 * @param int    $sid     Service.
	 * @param array  $saisies rubrique_id => montant saisi (vide = pas de budget pour la rubrique).
	 * @param string $motif   Motif de la révision.
	 * @param int    $user_id Auteur.
	 * @return int|WP_Error Nombre de lignes modifiées.
	 */
	public static function enregistrer( $exo, $sid, array $saisies, $motif, $user_id ) {
		global $wpdb;
		if ( ! IGE_FFM_Contexte::peut_budgeter( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Seul le Responsable du service peut modifier son budget.' );
		}
		$t        = IGE_FFM_DB::t( 'budget' );
		$actuel   = self::lignes( $exo, $sid );
		$revision = self::a_des_ecritures( $exo, $sid );
		$conso    = self::consommation( $exo, $sid );
		$changes  = array();

		foreach ( $saisies as $rid => $val ) {
			$rid = (int) $rid;
			$val = trim( (string) $val );
			$new = '' === $val ? null : ige_ffm_parse_montant( $val );
			if ( '' !== $val && null === $new ) {
				return new WP_Error( 'montant', 'Montant invalide pour une rubrique : saisissez un nombre entier en CDF.' );
			}
			$old = array_key_exists( $rid, $actuel ) ? $actuel[ $rid ] : null;
			if ( $old === $new ) {
				continue;
			}
			$c = $conso[ $rid ] ?? array( 'engage' => 0, 'paye' => 0 );
			if ( null !== $new && $new < $c['engage'] + $c['paye'] ) {
				$r = IGE_FFM_Referentiels::get( 'rubrique', $rid );
				return new WP_Error( 'montant', sprintf( 'Le budget de %s ne peut pas être inférieur à ce qui est déjà consommé (%s).', $r ? $r['code'] : $rid, ige_ffm_montant( $c['engage'] + $c['paye'] ) ) );
			}
			$changes[ $rid ] = array( $old, $new );
		}
		if ( ! $changes ) {
			return 0;
		}
		$motif = trim( sanitize_text_field( $motif ) );
		if ( $revision && '' === $motif ) {
			return new WP_Error( 'motif', 'Le service a déjà des écritures : indiquez le motif de la révision budgétaire.' );
		}
		$now = ige_ffm_now();
		foreach ( $changes as $rid => list( $old, $new ) ) {
			if ( null === $new ) {
				$wpdb->delete( $t, array( 'exercice_id' => $exo, 'service_id' => $sid, 'rubrique_id' => $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$bid = 0;
			} elseif ( null === $old ) {
				$wpdb->insert( $t, array( 'exercice_id' => $exo, 'service_id' => $sid, 'rubrique_id' => $rid, 'montant' => $new, 'updated_by' => $user_id, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$bid = (int) $wpdb->insert_id;
			} else {
				$wpdb->update( $t, array( 'montant' => $new, 'updated_by' => $user_id, 'updated_at' => $now ), array( 'exercice_id' => $exo, 'service_id' => $sid, 'rubrique_id' => $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE exercice_id = %d AND service_id = %d AND rubrique_id = %d", $exo, $sid, $rid ) );
			}
			if ( $revision ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					IGE_FFM_DB::t( 'revision_budget' ),
					array(
						'budget_id'       => $bid,
						'exercice_id'     => $exo,
						'service_id'      => $sid,
						'rubrique_id'     => $rid,
						'ancien_montant'  => (int) $old,
						'nouveau_montant' => (int) $new,
						'motif'           => $motif,
						'statut'          => 'appliquee',
						'demande_par'     => $user_id,
						'decide_par'      => $user_id,
						'created_at'      => $now,
						'decided_at'      => $now,
					)
				);
			}
		}
		$rub = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
		$av  = array();
		$ap  = array();
		foreach ( $changes as $rid => list( $old, $new ) ) {
			$code        = $rub[ $rid ] ?? $rid;
			$av[ $code ] = null === $old ? 'aucun' : $old;
			$ap[ $code ] = null === $new ? 'aucun' : $new;
		}
		if ( $revision ) {
			$ap['motif'] = $motif;
		}
		IGE_FFM_Audit::log( $revision ? 'revision_budget' : 'modification_budget', 'budget', IGE_FFM_Comptes::service_code( $sid ), $av, $ap, $user_id );
		return count( $changes );
	}

	/**
	 * Enregistre le solde initial (report), seulement tant qu'aucune écriture n'existe.
	 *
	 * @param int    $exo     Exercice.
	 * @param int    $sid     Service.
	 * @param string $montant Montant saisi.
	 * @param int    $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function enregistrer_solde_initial( $exo, $sid, $montant, $user_id ) {
		global $wpdb;
		if ( ! IGE_FFM_Contexte::peut_budgeter( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Seul le Responsable du service peut saisir le solde initial.' );
		}
		$new = ige_ffm_parse_montant( $montant );
		if ( null === $new ) {
			return new WP_Error( 'montant', 'Solde initial invalide.' );
		}
		$old = self::solde_initial( $exo, $sid );
		if ( $old === $new ) {
			return true;
		}
		if ( self::a_des_ecritures( $exo, $sid ) ) {
			return new WP_Error( 'verrou', 'Le solde initial ne peut plus être modifié : le service a déjà des écritures. Corrigez par une écriture.' );
		}
		$t = IGE_FFM_DB::t( 'solde_initial' );
		$wpdb->delete( $t, array( 'exercice_id' => $exo, 'service_id' => $sid, 'devise' => 'CDF' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $t, array( 'exercice_id' => $exo, 'service_id' => $sid, 'devise' => 'CDF', 'montant' => $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'solde_initial', 'service', IGE_FFM_Comptes::service_code( $sid ), array( 'solde_initial' => $old ), array( 'solde_initial' => $new ), $user_id );
		return true;
	}
}
