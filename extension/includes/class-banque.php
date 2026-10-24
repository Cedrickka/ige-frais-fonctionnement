<?php
/**
 * Rapprochement bancaire (VA-06).
 *
 * Côté « comptabilité », le compte principal porte : les paiements des services faits par chèque
 * ou virement, les avances remises par chèque ou virement, et les mouvements saisis par
 * Administration et Finance (dotations reçues, frais bancaires…). Les autres comptes ne portent
 * que leurs mouvements.
 *
 * Côté « banque », le relevé est importé (CSV ou Excel). Chaque ligne est rapprochée
 * automatiquement (même montant, dates proches, référence reconnue) ou à la main ; une ligne
 * de la banque absente de la comptabilité devient un mouvement en un clic (frais bancaires).
 * Les éléments en suspens s'expliquent ; au-delà du délai réglé, ils remontent en alerte.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banque.
 */
final class IGE_FFM_Banque {

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'ige_ffm_alertes', array( __CLASS__, 'alertes' ), 10, 2 );
	}

	/**
	 * Comptes bancaires.
	 *
	 * @param bool $actifs Actifs seulement.
	 * @return array[]
	 */
	public static function comptes( $actifs = true ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'banque' ) . ( $actifs ? ' WHERE actif = 1' : '' ) . ' ORDER BY principal DESC, id', ARRAY_A );
	}

	/**
	 * Un compte.
	 *
	 * @param int $id Compte.
	 * @return array|null
	 */
	public static function compte( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'banque' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Numéro de compte masqué (•••• 1234).
	 *
	 * @param string $n Numéro.
	 * @return string
	 */
	public static function masquer( $n ) {
		$n = preg_replace( '/\s+/', '', (string) $n );
		return strlen( $n ) > 4 ? '•••• ' . substr( $n, -4 ) : $n;
	}

	/**
	 * Crée un compte.
	 *
	 * @param array    $d       banque, intitule, numero, solde_initial, date_solde, principal.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function creer_compte( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$banque = trim( sanitize_text_field( (string) ( $d['banque'] ?? '' ) ) );
		$int    = trim( sanitize_text_field( (string) ( $d['intitule'] ?? '' ) ) );
		$num    = preg_replace( '/[^A-Za-z0-9 -]/', '', (string) ( $d['numero'] ?? '' ) );
		$si     = ige_ffm_parse_montant( $d['solde_initial'] ?? '0' );
		$date   = (string) ( $d['date_solde'] ?? '' );
		if ( '' === $banque || '' === $int ) {
			return new WP_Error( 'banque', 'Indiquez la banque et l’intitulé du compte.' );
		}
		if ( null === $si ) {
			return new WP_Error( 'solde', 'Solde de départ invalide (nombre entier en CDF, éventuellement négatif).' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) ) {
			return new WP_Error( 'date', 'Indiquez la date du solde de départ (celle du dernier relevé rapproché).' );
		}
		$principal = ! empty( $d['principal'] ) || ! self::comptes();
		if ( $principal ) {
			$wpdb->query( 'UPDATE ' . IGE_FFM_DB::t( 'banque' ) . ' SET principal = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		}
		$wpdb->insert( IGE_FFM_DB::t( 'banque' ), array( 'banque' => mb_substr( $banque, 0, 120 ), 'intitule' => mb_substr( $int, 0, 190 ), 'numero' => mb_substr( $num, 0, 60 ), 'devise' => 'CDF', 'solde_initial' => $si, 'date_solde' => $date, 'principal' => $principal ? 1 : 0, 'actif' => 1, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'compte_bancaire', 'banque', $banque . ' ' . self::masquer( $num ), null, array( 'intitule' => $int, 'solde_initial' => $si, 'date' => $date, 'principal' => $principal ), $user_id );
		return $id;
	}

	/**
	 * Modes de paiement qui passent par la banque (chèque, virement).
	 *
	 * @return int[]
	 */
	public static function modes_bancaires() {
		$out = array();
		foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
			$n = sanitize_title( remove_accents( $m['libelle'] ) );
			if ( false !== strpos( $n, 'cheque' ) || false !== strpos( $n, 'virement' ) ) {
				$out[] = (int) $m['id'];
			}
		}
		return (array) apply_filters( 'ige_ffm_modes_bancaires', $out );
	}

	/**
	 * Opérations de la comptabilité sur un compte (signées : sortie négative).
	 *
	 * @param array $c Compte.
	 * @return array[] type, id, date, libelle, reference, montant, rapproche.
	 */
	public static function operations( array $c ) {
		global $wpdb;
		$out   = array();
		$modes = self::modes_bancaires();
		$deb   = $c['date_solde'];
		if ( (int) $c['principal'] && $modes ) {
			$t   = IGE_FFM_DB::t( 'ecriture' );
			$in  = implode( ',', array_map( 'intval', $modes ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.id, e.id_ecriture, e.service_id, e.date_paiement, e.description, e.num_piece, e.montant, e.beneficiaire FROM $t e WHERE e.type = 'sortie' AND e.statut = 'payee' AND e.avance_id IS NULL AND e.ecriture_origine_id IS NULL AND e.mode_paiement_id IN ($in) AND e.date_paiement > %s AND NOT EXISTS (SELECT 1 FROM $t c WHERE c.ecriture_origine_id = e.id)", $deb ), ARRAY_A );
			$svc  = wp_list_pluck( IGE_FFM_Referentiels::all( 'service' ), 'code', 'id' );
			foreach ( $rows as $r ) {
				$out[] = array( 'type' => 'ecriture', 'id' => (int) $r['id'], 'date' => $r['date_paiement'], 'libelle' => ( $svc[ $r['service_id'] ] ?? '' ) . ' · ' . $r['description'] . ( $r['beneficiaire'] ? ' · ' . $r['beneficiaire'] : '' ), 'reference' => $r['num_piece'] ?: $r['id_ecriture'], 'montant' => -1 * (int) $r['montant'] );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$avs = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'avance' ) . " WHERE mode_paiement_id IN ($in) AND date_remise > %s", $deb ), ARRAY_A );
			foreach ( $avs as $a ) {
				if ( IGE_FFM_Avances::annulee( $a ) ) {
					continue;
				}
				$out[] = array( 'type' => 'avance', 'id' => (int) $a['id'], 'date' => $a['date_remise'], 'libelle' => 'Avance ' . $a['numero'] . ' · ' . ( $svc[ $a['service_id'] ] ?? '' ), 'reference' => $a['numero'], 'montant' => -1 * (int) $a['montant'] );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mv = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mouvement' ) . ' WHERE compte_id = %d', $c['id'] ), ARRAY_A );
		foreach ( $mv as $m ) {
			$out[] = array( 'type' => 'mouvement', 'id' => (int) $m['id'], 'date' => $m['date_operation'], 'libelle' => $m['libelle'], 'reference' => $m['reference'], 'montant' => (int) $m['montant'] );
		}
		// Rapprochements existants.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rap = $wpdb->get_results( $wpdb->prepare( 'SELECT rapproche_type, rapproche_id, id FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . " WHERE compte_id = %d AND rapproche_type <> ''", $c['id'] ), ARRAY_A );
		$fait = array();
		foreach ( $rap as $r ) {
			$fait[ $r['rapproche_type'] . ':' . $r['rapproche_id'] ] = (int) $r['id'];
		}
		foreach ( $out as &$o ) {
			$o['rapproche'] = $fait[ $o['type'] . ':' . $o['id'] ] ?? 0;
		}
		unset( $o );
		usort( $out, static fn( $a, $b ) => strcmp( $a['date'], $b['date'] ) ?: ( $a['id'] <=> $b['id'] ) );
		return $out;
	}

	/**
	 * Solde comptable à une date.
	 *
	 * @param array  $c    Compte.
	 * @param string $date Date.
	 * @return int
	 */
	public static function solde_comptable( array $c, $date ) {
		$s = (int) $c['solde_initial'];
		foreach ( self::operations( $c ) as $o ) {
			if ( $o['date'] <= $date ) {
				$s += $o['montant'];
			}
		}
		return $s;
	}

	/**
	 * Lignes de relevé d'un compte.
	 *
	 * @param int  $cid       Compte.
	 * @param bool $libres    Non rapprochées seulement.
	 * @return array[]
	 */
	public static function lignes( $cid, $libres = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . ' WHERE compte_id = %d' . ( $libres ? " AND rapproche_type = ''" : '' ) . ' ORDER BY date_operation, id', $cid ), ARRAY_A );
	}

	/**
	 * Dernier relevé importé.
	 *
	 * @param int $cid Compte.
	 * @return array|null
	 */
	public static function dernier_releve( $cid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve' ) . ' WHERE compte_id = %d ORDER BY date_fin DESC, id DESC LIMIT 1', $cid ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Lit un relevé (CSV ou Excel) : date, libellé, référence, montant signé.
	 *
	 * @param string $chemin Fichier.
	 * @param string $nom    Nom d'origine (extension).
	 * @return array[]|WP_Error
	 */
	public static function lire_releve( $chemin, $nom ) {
		$ext    = strtolower( pathinfo( (string) $nom, PATHINFO_EXTENSION ) );
		$lignes = array();
		if ( in_array( $ext, array( 'xlsx', 'xlsm' ), true ) ) {
			$x = IGE_FFM_Xlsx::lire( $chemin );
			if ( is_wp_error( $x ) ) {
				return $x;
			}
			$lignes = array_values( (array) reset( $x ) );
		} elseif ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			$brut = (string) file_get_contents( $chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$brut = preg_replace( '/^\xEF\xBB\xBF/', '', $brut );
			if ( ! mb_check_encoding( $brut, 'UTF-8' ) ) {
				$brut = mb_convert_encoding( $brut, 'UTF-8', 'Windows-1252' );
			}
			$rows = preg_split( '/\r\n|\r|\n/', trim( $brut ) );
			$sep  = substr_count( $rows[0] ?? '', ';' ) >= substr_count( $rows[0] ?? '', ',' ) ? ';' : ',';
			foreach ( $rows as $r ) {
				$lignes[] = str_getcsv( $r, $sep, '"', '\\' );
			}
		} else {
			return new WP_Error( 'format', 'Format attendu : relevé en CSV ou en Excel (.xlsx).' );
		}
		// En-tête : la première ligne qui contient une date et un libellé.
		$col = null;
		foreach ( $lignes as $i => $l ) {
			$map = array();
			foreach ( $l as $c => $v ) {
				$n = IGE_FFM_Xlsx::norm( (string) $v );
				if ( '' === $n ) {
					continue;
				}
				if ( ! isset( $map['date'] ) && ( 0 === strpos( $n, 'date' ) ) ) {
					$map['date'] = $c;
				} elseif ( ! isset( $map['libelle'] ) && ( false !== strpos( $n, 'libelle' ) || false !== strpos( $n, 'description' ) || false !== strpos( $n, 'operation' ) ) ) {
					$map['libelle'] = $c;
				} elseif ( ! isset( $map['reference'] ) && ( false !== strpos( $n, 'ref' ) || 'numero' === $n || 'ncheque' === $n || false !== strpos( $n, 'cheque' ) ) ) {
					$map['reference'] = $c;
				} elseif ( ! isset( $map['debit'] ) && ( false !== strpos( $n, 'debit' ) || false !== strpos( $n, 'retrait' ) ) ) {
					$map['debit'] = $c;
				} elseif ( ! isset( $map['credit'] ) && ( false !== strpos( $n, 'credit' ) || false !== strpos( $n, 'versement' ) ) ) {
					$map['credit'] = $c;
				} elseif ( ! isset( $map['montant'] ) && false !== strpos( $n, 'montant' ) ) {
					$map['montant'] = $c;
				}
			}
			if ( isset( $map['date'], $map['libelle'] ) && ( isset( $map['montant'] ) || isset( $map['debit'] ) || isset( $map['credit'] ) ) ) {
				$col   = $map;
				$debut = $i + 1;
				break;
			}
		}
		if ( ! $col ) {
			return new WP_Error( 'colonnes', 'Colonnes introuvables : le relevé doit avoir une colonne Date, une colonne Libellé et soit Débit et Crédit, soit Montant.' );
		}
		$nb  = static function ( $v ) {
			if ( null === $v || '' === $v ) {
				return 0;
			}
			if ( is_int( $v ) || is_float( $v ) ) {
				return (int) round( $v );
			}
			$s = str_replace( array( ' ', "\u{00A0}", "\u{202F}" ), '', (string) $v );
			$s = preg_replace( '/[^0-9,.\-]/', '', $s );
			if ( preg_match( '/,\d{1,2}$/', $s ) ) {
				$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
			} else {
				$s = str_replace( ',', '', $s );
			}
			return is_numeric( $s ) ? (int) round( (float) $s ) : 0;
		};
		$out = array();
		foreach ( array_slice( $lignes, $debut ) as $l ) {
			$date = IGE_FFM_Xlsx::date( $l[ $col['date'] ] ?? '' );
			if ( '' === $date ) {
				continue;
			}
			$m = isset( $col['montant'] ) ? $nb( $l[ $col['montant'] ] ?? '' ) : $nb( $l[ $col['credit'] ] ?? '' ) - abs( $nb( $l[ $col['debit'] ] ?? '' ) );
			if ( 0 === $m ) {
				continue;
			}
			$out[] = array(
				'date'      => $date,
				'libelle'   => mb_substr( trim( (string) ( $l[ $col['libelle'] ] ?? '' ) ), 0, 255 ),
				'reference' => isset( $col['reference'] ) ? mb_substr( trim( (string) ( $l[ $col['reference'] ] ?? '' ) ), 0, 80 ) : '',
				'montant'   => $m,
			);
		}
		if ( ! $out ) {
			return new WP_Error( 'vide', 'Aucune opération lisible dans ce relevé.' );
		}
		return $out;
	}

	/**
	 * Importe un relevé et rapproche automatiquement.
	 *
	 * @param int      $cid         Compte.
	 * @param string   $chemin      Fichier.
	 * @param string   $nom         Nom d'origine.
	 * @param string   $solde_final Solde final du relevé (facultatif).
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error lus, nouvelles, doublons, rapprochees.
	 */
	public static function importer( $cid, $chemin, $nom, $solde_final = '', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$c = self::compte( (int) $cid );
		if ( ! $c ) {
			return new WP_Error( 'compte', 'Compte introuvable.' );
		}
		$ops = self::lire_releve( $chemin, $nom );
		if ( is_wp_error( $ops ) ) {
			return $ops;
		}
		$sf = '' === trim( (string) $solde_final ) ? null : ige_ffm_parse_montant( $solde_final );
		if ( '' !== trim( (string) $solde_final ) && null === $sf ) {
			return new WP_Error( 'solde', 'Solde final du relevé invalide.' );
		}
		$dates = array_column( $ops, 'date' );
		$wpdb->insert( IGE_FFM_DB::t( 'releve' ), array( 'compte_id' => (int) $cid, 'fichier' => mb_substr( sanitize_file_name( $nom ), 0, 190 ), 'date_debut' => min( $dates ), 'date_fin' => max( $dates ), 'solde_final' => $sf, 'nb_lignes' => count( $ops ), 'importe_par' => (int) $user_id, 'importe_le' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rid  = (int) $wpdb->insert_id;
		$vus  = array();
		$neuf = 0;
		foreach ( $ops as $o ) {
			$k          = $o['date'] . '|' . $o['libelle'] . '|' . $o['reference'] . '|' . $o['montant'];
			$vus[ $k ]  = ( $vus[ $k ] ?? 0 ) + 1;
			$emp        = sha1( $k . '|' . $vus[ $k ] );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( IGE_FFM_DB::t( 'releve_ligne' ), array( 'releve_id' => $rid, 'compte_id' => (int) $cid, 'date_operation' => $o['date'], 'libelle' => $o['libelle'], 'reference' => $o['reference'], 'montant' => $o['montant'], 'empreinte' => $emp ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				++$neuf;
			}
		}
		$auto = self::rapprocher_auto( (int) $cid, $user_id );
		$res  = array( 'lus' => count( $ops ), 'nouvelles' => $neuf, 'doublons' => count( $ops ) - $neuf, 'rapprochees' => $auto );
		IGE_FFM_Audit::log( 'import_releve', 'banque', $c['banque'] . ' ' . self::masquer( $c['numero'] ), null, $res + array( 'periode' => min( $dates ) . ' → ' . max( $dates ) ), $user_id );
		return $res;
	}

	/**
	 * Rapprochement automatique : même montant, dates proches ; la référence départage.
	 *
	 * @param int $cid     Compte.
	 * @param int $user_id Auteur.
	 * @return int Lignes rapprochées.
	 */
	public static function rapprocher_auto( $cid, $user_id ) {
		global $wpdb;
		$c = self::compte( (int) $cid );
		if ( ! $c ) {
			return 0;
		}
		$jours = max( 0, (int) IGE_FFM_Reglages::get( 'rappro_jours' ) );
		$ops   = array_values( array_filter( self::operations( $c ), static fn( $o ) => ! $o['rapproche'] ) );
		$n     = 0;
		$norm  = static fn( $s ) => preg_replace( '/[^A-Z0-9]/', '', strtoupper( remove_accents( (string) $s ) ) );
		foreach ( self::lignes( (int) $cid, true ) as $l ) {
			$cands = array();
			foreach ( $ops as $i => $o ) {
				if ( $o['montant'] !== (int) $l['montant'] || abs( strtotime( $o['date'] ) - strtotime( $l['date_operation'] ) ) > $jours * DAY_IN_SECONDS ) {
					continue;
				}
				$ref   = $norm( $o['reference'] );
				$score = ( strlen( $ref ) >= 3 && ( false !== strpos( $norm( $l['libelle'] . ' ' . $l['reference'] ), $ref ) ) ) ? 2 : 1;
				$cands[ $i ] = $score;
			}
			if ( ! $cands ) {
				continue;
			}
			arsort( $cands );
			$best = array_key_first( $cands );
			$tops = array_keys( $cands, $cands[ $best ], true );
			if ( count( $tops ) > 1 ) {
				continue; // Ambigu : à rapprocher à la main.
			}
			$o = $ops[ $best ];
			$wpdb->update( IGE_FFM_DB::t( 'releve_ligne' ), array( 'rapproche_type' => $o['type'], 'rapproche_id' => $o['id'], 'rapproche_par' => (int) $user_id, 'rapproche_le' => ige_ffm_now() ), array( 'id' => (int) $l['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			unset( $ops[ $best ] );
			++$n;
		}
		return $n;
	}

	/**
	 * Rapprochement manuel d'une ligne du relevé avec une opération de la comptabilité.
	 *
	 * @param int      $lid     Ligne de relevé.
	 * @param string   $op      « type:id ».
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function rapprocher( $lid, $op, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$l = self::ligne( (int) $lid );
		if ( ! $l || '' !== $l['rapproche_type'] ) {
			return new WP_Error( 'ligne', 'Ligne déjà rapprochée ou introuvable.' );
		}
		list( $type, $id ) = array_pad( explode( ':', (string) $op ), 2, 0 );
		$c                  = self::compte( (int) $l['compte_id'] );
		$cible              = null;
		foreach ( self::operations( $c ) as $o ) {
			if ( $o['type'] === $type && $o['id'] === (int) $id ) {
				$cible = $o;
			}
		}
		if ( ! $cible || $cible['rapproche'] ) {
			return new WP_Error( 'operation', 'Opération introuvable ou déjà rapprochée.' );
		}
		if ( $cible['montant'] !== (int) $l['montant'] ) {
			return new WP_Error( 'montant', sprintf( 'Montants différents (banque %s, comptabilité %s) : corrigez l’écriture ou expliquez l’écart.', ige_ffm_montant( (int) $l['montant'] ), ige_ffm_montant( $cible['montant'] ) ) );
		}
		$wpdb->update( IGE_FFM_DB::t( 'releve_ligne' ), array( 'rapproche_type' => $type, 'rapproche_id' => (int) $id, 'rapproche_par' => (int) $user_id, 'rapproche_le' => ige_ffm_now() ), array( 'id' => (int) $lid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'rapprochement', 'banque', $cible['reference'] ?: $type . ':' . $id, null, array( 'releve' => $l['date_operation'] . ' ' . $l['libelle'], 'montant' => (int) $l['montant'] ), $user_id );
		return true;
	}

	/**
	 * Annule un rapprochement.
	 *
	 * @param int      $lid     Ligne.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function defaire( $lid, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$l       = self::ligne( (int) $lid );
		if ( ! $l || ! user_can( $user_id, 'ige_gerer_fonds' ) || '' === $l['rapproche_type'] ) {
			return new WP_Error( 'ligne', 'Rapprochement introuvable.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'releve_ligne' ), array( 'rapproche_type' => '', 'rapproche_id' => null, 'rapproche_par' => null, 'rapproche_le' => null ), array( 'id' => (int) $lid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'annulation_rapprochement', 'banque', $l['date_operation'] . ' ' . $l['libelle'], array( 'avec' => $l['rapproche_type'] . ':' . $l['rapproche_id'] ), null, $user_id );
		return true;
	}

	/**
	 * Une ligne de relevé.
	 *
	 * @param int $id Ligne.
	 * @return array|null
	 */
	public static function ligne( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Mouvement saisi par Administration et Finance (dotation reçue, frais bancaires…).
	 *
	 * @param array    $d       compte_id, date, libelle, reference, montant (signé), nature.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function mouvement( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$c   = self::compte( (int) ( $d['compte_id'] ?? 0 ) );
		$lib = trim( sanitize_text_field( (string) ( $d['libelle'] ?? '' ) ) );
		$m   = is_int( $d['montant'] ?? null ) ? $d['montant'] : ige_ffm_parse_montant( $d['montant'] ?? '' );
		$dt  = (string) ( $d['date'] ?? '' );
		if ( ! $c ) {
			return new WP_Error( 'compte', 'Compte introuvable.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $dt ) || $dt <= $c['date_solde'] ) {
			return new WP_Error( 'date', 'La date doit être postérieure au solde de départ du compte.' );
		}
		if ( '' === $lib || ! $m ) {
			return new WP_Error( 'mouvement', 'Indiquez le libellé et un montant non nul (négatif pour une sortie).' );
		}
		$nature = in_array( $d['nature'] ?? '', array( 'dotation', 'frais', 'autre' ), true ) ? $d['nature'] : 'autre';
		$wpdb->insert( IGE_FFM_DB::t( 'mouvement' ), array( 'compte_id' => (int) $c['id'], 'date_operation' => $dt, 'libelle' => mb_substr( $lib, 0, 255 ), 'reference' => mb_substr( trim( sanitize_text_field( (string) ( $d['reference'] ?? '' ) ) ), 0, 80 ), 'montant' => (int) $m, 'nature' => $nature, 'created_by' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'mouvement_bancaire', 'banque', $lib, null, array( 'date' => $dt, 'montant' => (int) $m, 'nature' => $nature ), $user_id );
		return $id;
	}

	/**
	 * Crée le mouvement correspondant à une ligne du relevé et les rapproche (frais bancaires…).
	 *
	 * @param int      $lid     Ligne.
	 * @param string   $nature  Nature.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function mouvement_depuis_ligne( $lid, $nature, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		$l       = self::ligne( (int) $lid );
		if ( ! $l || '' !== $l['rapproche_type'] ) {
			return new WP_Error( 'ligne', 'Ligne déjà rapprochée ou introuvable.' );
		}
		$id = self::mouvement( array( 'compte_id' => (int) $l['compte_id'], 'date' => $l['date_operation'], 'libelle' => $l['libelle'] ?: 'Opération bancaire', 'reference' => $l['reference'], 'montant' => (int) $l['montant'], 'nature' => $nature ), $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::rapprocher( (int) $lid, 'mouvement:' . $id, $user_id );
		return $id;
	}

	/**
	 * Explication d'un élément en suspens.
	 *
	 * @param string   $objet       ligne | ecriture | avance | mouvement.
	 * @param int      $id          Identifiant.
	 * @param string   $texte       Explication.
	 * @param int|null $user_id     Auteur.
	 * @return true|WP_Error
	 */
	public static function expliquer( $objet, $id, $texte, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) || ! in_array( $objet, array( 'ligne', 'ecriture', 'avance', 'mouvement' ), true ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$texte = mb_substr( trim( sanitize_text_field( (string) $texte ) ), 0, 255 );
		if ( mb_strlen( $texte ) < 3 ) {
			return new WP_Error( 'texte', 'Écrivez l’explication.' );
		}
		$t = IGE_FFM_DB::t( 'suspens' );
		$wpdb->delete( $t, array( 'objet' => $objet, 'objet_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $t, array( 'objet' => $objet, 'objet_id' => (int) $id, 'explication' => $texte, 'user_id' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'suspens_bancaire', 'banque', $objet . ':' . $id, null, array( 'explication' => $texte ), $user_id );
		return true;
	}

	/**
	 * Explications enregistrées.
	 *
	 * @return array<string,string> « objet:id » => texte.
	 */
	public static function explications() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT objet, objet_id, explication FROM ' . IGE_FFM_DB::t( 'suspens' ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$out[ $r['objet'] . ':' . $r['objet_id'] ] = $r['explication'];
		}
		return $out;
	}

	/**
	 * État de rapprochement d'un compte à la date du dernier relevé.
	 *
	 * @param int $cid Compte.
	 * @return array|null
	 */
	public static function etat( $cid ) {
		$c = self::compte( (int) $cid );
		if ( ! $c ) {
			return null;
		}
		$r     = self::dernier_releve( (int) $cid );
		$date  = $r ? $r['date_fin'] : IGE_FFM_Contexte::aujourdhui();
		$ops   = self::operations( $c );
		$lig   = self::lignes( (int) $cid );
		$exp   = self::explications();
		$sc    = (int) $c['solde_initial'];
		$compta_libres = array();
		foreach ( $ops as $o ) {
			if ( $o['date'] <= $date ) {
				$sc += $o['montant'];
				if ( ! $o['rapproche'] ) {
					$o['explication'] = $exp[ $o['type'] . ':' . $o['id'] ] ?? '';
					$compta_libres[]  = $o;
				}
			}
		}
		$banque_libres = array();
		foreach ( $lig as $l ) {
			if ( '' === $l['rapproche_type'] && $l['date_operation'] <= $date ) {
				$l['explication'] = $exp[ 'ligne:' . $l['id'] ] ?? '';
				$banque_libres[]  = $l;
			}
		}
		$sr      = $r && null !== $r['solde_final'] ? (int) $r['solde_final'] : null;
		$s_banq  = array_sum( array_map( static fn( $l ) => (int) $l['montant'], $banque_libres ) );
		$s_comp  = array_sum( array_column( $compta_libres, 'montant' ) );
		$residu  = null === $sr ? null : ( $sr - $sc ) - ( $s_banq - $s_comp );
		return array(
			'compte'        => $c,
			'releve'        => $r,
			'date'          => $date,
			'solde_releve'  => $sr,
			'solde_compta'  => $sc,
			'compta_libres' => $compta_libres,
			'banque_libres' => $banque_libres,
			'nb_lignes'     => count( array_filter( $lig, static fn( $l ) => $l['date_operation'] <= $date ) ),
			'nb_rapprochees' => count( array_filter( $lig, static fn( $l ) => '' !== $l['rapproche_type'] && $l['date_operation'] <= $date ) ),
			'residu'        => $residu,
		);
	}

	/**
	 * État de rapprochement en PDF.
	 *
	 * @param int $cid Compte.
	 * @return string
	 */
	public static function etat_pdf( $cid ) {
		$e = self::etat( (int) $cid );
		if ( ! $e ) {
			wp_die( 'Compte introuvable.' );
		}
		$c = $e['compte'];
		$h = '<h1>État de rapprochement bancaire</h1><div class="sous">' . esc_html( $c['banque'] . ' · ' . $c['intitule'] . ' · ' . self::masquer( $c['numero'] ) . ' · au ' . wp_date( 'd/m/Y', strtotime( $e['date'] ) ) ) . '</div>';
		$h .= '<table class="tab"><tbody>';
		$h .= '<tr><td>Solde du relevé bancaire</td><td class="n">' . esc_html( null === $e['solde_releve'] ? 'non indiqué' : ige_ffm_montant( $e['solde_releve'] ) ) . '</td></tr>';
		$h .= '<tr><td>Solde de la comptabilité</td><td class="n">' . esc_html( ige_ffm_montant( $e['solde_compta'] ) ) . '</td></tr>';
		$h .= '<tr><td>Lignes du relevé rapprochées</td><td class="n">' . (int) $e['nb_rapprochees'] . ' sur ' . (int) $e['nb_lignes'] . '</td></tr>';
		$h .= '<tr class="tot"><td>Écart restant à expliquer</td><td class="n">' . esc_html( null === $e['residu'] ? '—' : ige_ffm_montant( $e['residu'] ) ) . '</td></tr></tbody></table>';
		$h .= '<h2>Opérations de la comptabilité non encore passées en banque</h2><table class="tab"><thead><tr><th>Date</th><th>Libellé</th><th>Référence</th><th class="n">Montant</th><th>Explication</th></tr></thead><tbody>';
		foreach ( $e['compta_libres'] as $o ) {
			$h .= '<tr><td class="nw">' . esc_html( wp_date( 'd/m/Y', strtotime( $o['date'] ) ) ) . '</td><td>' . esc_html( $o['libelle'] ) . '</td><td>' . esc_html( $o['reference'] ) . '</td><td class="n">' . esc_html( ige_ffm_montant( $o['montant'] ) ) . '</td><td>' . esc_html( $o['explication'] ) . '</td></tr>';
		}
		$h .= ( $e['compta_libres'] ? '' : '<tr><td colspan="5">Aucune.</td></tr>' ) . '</tbody></table>';
		$h .= '<h2>Opérations de la banque absentes de la comptabilité</h2><table class="tab"><thead><tr><th>Date</th><th>Libellé</th><th>Référence</th><th class="n">Montant</th><th>Explication</th></tr></thead><tbody>';
		foreach ( $e['banque_libres'] as $l ) {
			$h .= '<tr><td class="nw">' . esc_html( wp_date( 'd/m/Y', strtotime( $l['date_operation'] ) ) ) . '</td><td>' . esc_html( $l['libelle'] ) . '</td><td>' . esc_html( $l['reference'] ) . '</td><td class="n">' . esc_html( ige_ffm_montant( (int) $l['montant'] ) ) . '</td><td>' . esc_html( $l['explication'] ) . '</td></tr>';
		}
		$h .= ( $e['banque_libres'] ? '' : '<tr><td colspan="5">Aucune.</td></tr>' ) . '</tbody></table>';
		$h .= '<table class="ige-sign"><tr><td>Établi par<br><br><br>' . esc_html( wp_get_current_user()->display_name ) . '</td><td>Vu, le Chef de service Administration et Finance<br><br><br>Signature</td></tr></table>';
		return IGE_FFM_Pdf::generer(
			$h,
			array(
				'titre'         => 'État de rapprochement bancaire',
				'entete_droite' => 'Kinshasa, le ' . esc_html( IGE_FFM_Pdf::date_fr( current_time( 'mysql' ) ) ),
				'pied'          => esc_html( IGE_FFM_Classeur::extrait_par() ),
			)
		);
	}

	/**
	 * Alertes : éléments en suspens depuis trop longtemps.
	 *
	 * @param array $out Alertes.
	 * @param int   $exo Exercice.
	 * @return array
	 */
	public static function alertes( $out, $exo ) {
		$jours = max( 1, (int) IGE_FFM_Reglages::get( 'suspens_alerte_jours' ) );
		$lim   = gmdate( 'Y-m-d', strtotime( IGE_FFM_Contexte::aujourdhui() . ' -' . $jours . ' days' ) );
		$n     = 0;
		foreach ( self::comptes() as $c ) {
			$e = self::etat( (int) $c['id'] );
			if ( ! $e['releve'] ) {
				continue;
			}
			foreach ( $e['compta_libres'] as $o ) {
				$n += $o['date'] < $lim ? 1 : 0;
			}
			foreach ( $e['banque_libres'] as $l ) {
				$n += $l['date_operation'] < $lim ? 1 : 0;
			}
		}
		if ( $n ) {
			$out[] = array( 'warn', sprintf( '%d élément(s) bancaire(s) en suspens depuis plus de %d jours.', $n, $jours ), ige_ffm_url( 'rapprochement-bancaire' ) );
		}
		return $out;
	}
}
