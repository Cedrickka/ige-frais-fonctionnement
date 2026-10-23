<?php
/**
 * Détection des anomalies à la réception par le central (RG-10 et contrôles avancés).
 *
 * Les anomalies ne bloquent pas : elles signalent au vérificateur ce qu'il doit regarder.
 * Les contrôles bloquants ont déjà eu lieu à la saisie ; ceux-ci rattrapent ce qui a pu
 * changer depuis (budget révisé, règles assouplies) et repèrent les cas suspects.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anomalies.
 */
final class IGE_FFM_Anomalies {

	/**
	 * Libellés courts des anomalies.
	 *
	 * @return array<string,string>
	 */
	public static function libelles() {
		return array(
			'hors_exercice'   => 'Date hors exercice',
			'rubrique'        => 'Rubrique inconnue ou inactive',
			'montant'         => 'Montant nul ou négatif',
			'piece'           => 'Pièce manquante',
			'doublon'         => 'Doublon possible',
			'depassement'     => 'Dépasse le budget de la rubrique',
			'caisse'          => 'Caisse négative',
			'fractionnement'  => 'Fractionnement possible',
			'jour_non_ouvre'  => 'Jour non ouvré',
			'beneficiaire'    => 'Bénéficiaire = agent',
			'saisie_tardive'  => 'Saisie tardive',
		);
	}

	/**
	 * Jours fériés fixes de la RDC (mois-jour). À compléter par le filtre « ige_ffm_jours_feries ».
	 *
	 * @param int $annee Année.
	 * @return string[]
	 */
	public static function jours_feries( $annee ) {
		$fixes = array( '01-01', '01-04', '01-16', '01-17', '04-06', '05-01', '05-17', '06-30', '08-01', '12-25' );
		return (array) apply_filters( 'ige_ffm_jours_feries', $fixes, (int) $annee );
	}

	/**
	 * Normalise un nom de personne ou de bénéficiaire pour les comparaisons.
	 *
	 * @param string $s Texte.
	 * @return string
	 */
	private static function norm( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $s ) ) );
	}

	/**
	 * Clé du bénéficiaire d'une écriture (fournisseur ou nom libre).
	 *
	 * @param array $e Écriture.
	 * @return string
	 */
	private static function cle_beneficiaire( array $e ) {
		return $e['fournisseur_id'] ? 'f' . (int) $e['fournisseur_id'] : ( '' !== trim( (string) $e['beneficiaire'] ) ? 'b' . self::norm( $e['beneficiaire'] ) : '' );
	}

	/**
	 * Anomalies des écritures d'un service, calculées ensemble (une seule lecture du livre).
	 *
	 * @param int   $exo Exercice.
	 * @param int   $sid Service.
	 * @param int[] $ids Écritures à analyser (vide = toutes).
	 * @return array<int,array<string,string>> id => [ code => détail ].
	 */
	public static function pour_service( $exo, $sid, array $ids = array() ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ex     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'exercice' ) . ' WHERE id = %d', $exo ), ARRAY_A );
		$livre  = IGE_FFM_Ecritures::livre( $exo, $sid );
		$rows   = $livre['rows'];
		$par_id = array();
		foreach ( $rows as $r ) {
			$par_id[ (int) $r['id'] ] = $r;
		}
		$cibles = $ids ? array_intersect_key( $par_id, array_flip( array_map( 'intval', $ids ) ) ) : $par_id;
		if ( ! $cibles ) {
			return array();
		}
		$on      = static fn( $k ) => (bool) IGE_FFM_Reglages::get( 'anomalie_' . $k );
		$budget  = IGE_FFM_Budget::lignes( $exo, $sid );
		$conso   = IGE_FFM_Budget::consommation( $exo, $sid );
		$pieces  = IGE_FFM_Pieces::compter_ecritures( array_keys( $cibles ) );
		$plafond = (int) IGE_FFM_Reglages::get( 'plafond_especes' );
		$tardif  = (int) IGE_FFM_Reglages::get( 'delai_saisie_tardive' );
		$annule  = array();
		foreach ( $rows as $r ) {
			if ( $r['ecriture_origine_id'] ) {
				$annule[ (int) $r['ecriture_origine_id'] ] = true;
				$annule[ (int) $r['id'] ]                  = true;
			}
		}

		// Doublons de pièce : sur tout l'exercice, tous services confondus (même fournisseur ou bénéficiaire et même n° de pièce).
		$t       = IGE_FFM_DB::t( 'ecriture' );
		$pieces_num = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tous = $wpdb->get_results( $wpdb->prepare( "SELECT id, id_ecriture, num_piece, fournisseur_id, beneficiaire, montant, date_operation, ecriture_origine_id FROM $t WHERE exercice_id = %d AND type = 'sortie' AND montant > 0 AND num_piece <> ''", $exo ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$contre = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ecriture_origine_id FROM $t WHERE exercice_id = %d AND ecriture_origine_id IS NOT NULL", $exo ) ) );
		$contre = array_flip( $contre );
		foreach ( $tous as $x ) {
			if ( isset( $contre[ (int) $x['id'] ] ) ) {
				continue;
			}
			$k = self::norm( $x['num_piece'] ) . '|' . self::cle_beneficiaire( $x );
			$pieces_num[ $k ][] = $x;
		}

		$out = array();
		foreach ( $cibles as $id => $e ) {
			$a        = array();
			$sortie   = 'sortie' === $e['type'];
			$est_cp   = (bool) $e['ecriture_origine_id'];
			$date     = $e['date_operation'];
			if ( $ex && ( $date < $ex['date_debut'] || $date > $ex['date_fin'] ) ) {
				$a['hors_exercice'] = 'Le ' . wp_date( 'd/m/Y', strtotime( $date ) ) . ' n’appartient pas à l’exercice ' . $ex['annee'] . '.';
			}
			if ( (int) $e['montant'] <= 0 && ! $est_cp ) {
				$a['montant'] = 'Montant de ' . ige_ffm_montant( (int) $e['montant'] ) . '.';
			}
			if ( (int) $par_id[ $id ]['solde'] < 0 ) {
				$a['caisse'] = 'Solde de caisse après l’opération : ' . ige_ffm_montant( (int) $par_id[ $id ]['solde'] ) . '.';
			}
			if ( $sortie && ! $est_cp ) {
				$rid = (int) $e['rubrique_id'];
				$r   = $rid ? IGE_FFM_Referentiels::get( 'rubrique', $rid ) : null;
				if ( ! $r || ! (int) $r['actif'] ) {
					$a['rubrique'] = 'Rubrique absente ou désactivée.';
				}
				if ( '' === trim( (string) $e['num_piece'] ) || empty( $pieces[ $id ] ) ) {
					$a['piece'] = '' === trim( (string) $e['num_piece'] ) ? 'Aucun n° de pièce.' : 'Aucun fichier joint.';
				}
				if ( $rid && array_key_exists( $rid, $budget ) ) {
					$c = ( $conso[ $rid ]['engage'] ?? 0 ) + ( $conso[ $rid ]['paye'] ?? 0 );
					if ( $c > $budget[ $rid ] ) {
						$a['depassement'] = sprintf( 'Consommé %s pour un budget de %s.', ige_ffm_montant( $c ), ige_ffm_montant( $budget[ $rid ] ) );
					}
				}
				if ( $on( 'doublon' ) && '' !== trim( (string) $e['num_piece'] ) && ! isset( $annule[ $id ] ) ) {
					$k     = self::norm( $e['num_piece'] ) . '|' . self::cle_beneficiaire( $e );
					$autres = array_filter( $pieces_num[ $k ] ?? array(), static fn( $x ) => (int) $x['id'] !== $id );
					if ( $autres ) {
						$a['doublon'] = 'Même pièce ' . $e['num_piece'] . ' et même bénéficiaire que ' . implode( ', ', wp_list_pluck( $autres, 'id_ecriture' ) ) . '.';
					}
				}
				if ( $on( 'jour_non_ouvre' ) ) {
					$ts = strtotime( $date );
					if ( (int) gmdate( 'N', $ts ) >= 6 ) {
						$a['jour_non_ouvre'] = ( 6 === (int) gmdate( 'N', $ts ) ? 'Samedi' : 'Dimanche' ) . ' ' . wp_date( 'd/m/Y', $ts ) . '.';
					} elseif ( in_array( gmdate( 'm-d', $ts ), self::jours_feries( (int) gmdate( 'Y', $ts ) ), true ) ) {
						$a['jour_non_ouvre'] = 'Jour férié (' . wp_date( 'd/m/Y', $ts ) . ').';
					}
				}
				if ( $on( 'beneficiaire' ) && ! $e['fournisseur_id'] && '' !== trim( (string) $e['beneficiaire'] ) ) {
					$u = get_userdata( (int) $e['saisi_par'] );
					$b = self::norm( $e['beneficiaire'] );
					if ( $u && ( self::norm( $u->display_name ) === $b || self::norm( $u->user_login ) === $b || ( strlen( self::norm( $u->last_name ) ) > 2 && false !== strpos( $b, self::norm( $u->last_name ) ) ) ) ) {
						$a['beneficiaire'] = 'Le bénéficiaire est l’agent qui a saisi l’écriture (' . $u->display_name . ').';
					}
				}
				if ( $on( 'fractionnement' ) && $plafond > 0 && (int) $e['montant'] < $plafond && ! isset( $annule[ $id ] ) ) {
					$cle   = self::cle_beneficiaire( $e );
					$debut = gmdate( 'Y-m-d', strtotime( $date . ' -6 days' ) );
					$fin   = gmdate( 'Y-m-d', strtotime( $date . ' +6 days' ) );
					$total = 0;
					$n     = 0;
					foreach ( $rows as $x ) {
						if ( 'sortie' === $x['type'] && ! isset( $annule[ (int) $x['id'] ] ) && '' !== $cle && self::cle_beneficiaire( $x ) === $cle && $x['date_operation'] >= $debut && $x['date_operation'] <= $fin && (int) $x['montant'] < $plafond ) {
							$total += (int) $x['montant'];
							++$n;
						}
					}
					if ( $n >= 2 && $total > $plafond ) {
						$a['fractionnement'] = sprintf( '%d achats au même bénéficiaire en 7 jours, total %s (plafond %s).', $n, ige_ffm_montant( $total ), ige_ffm_montant( $plafond ) );
					}
				}
			}
			if ( $on( 'saisie_tardive' ) && $tardif > 0 && ! $est_cp ) {
				$jours = (int) floor( ( strtotime( substr( $e['saisi_le'], 0, 10 ) ) - strtotime( $date ) ) / DAY_IN_SECONDS );
				if ( $jours > $tardif ) {
					$a['saisie_tardive'] = sprintf( 'Saisie %d jours après l’opération.', $jours );
				}
			}
			$out[ $id ] = $a;
		}
		return $out;
	}

	/**
	 * Anomalies enregistrées sur une écriture (JSON).
	 *
	 * @param array $e Écriture.
	 * @return array<string,string>
	 */
	public static function lire( array $e ) {
		$a = json_decode( (string) $e['anomalies'], true );
		return is_array( $a ) ? $a : array();
	}

	/**
	 * Pastilles HTML des anomalies.
	 *
	 * @param array<string,string> $a Anomalies.
	 * @return string
	 */
	public static function pastilles( array $a ) {
		if ( ! $a ) {
			return ige_ffm_pill( 'Aucune anomalie', 'ok' );
		}
		$l = self::libelles();
		$h = array();
		foreach ( $a as $code => $detail ) {
			$h[] = '<span class="ige-anom" title="' . esc_attr( $detail ) . '">' . esc_html( $l[ $code ] ?? $code ) . '</span>';
		}
		return implode( '<br>', $h );
	}
}
