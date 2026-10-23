<?php
/**
 * Reprise des données des classeurs Excel (fichiers de service et classeur central).
 *
 * 1. Le classeur central (facultatif) donne l'état de vérification de chaque écriture déjà
 *    transmise (À vérifier, Validée, Rejetée et motif).
 * 2. Chaque fichier de service donne : le solde initial, le budget par rubrique et le livre de
 *    caisse. Les écritures gardent leur identifiant (8000IG-2026-000015) et leur numéro ; la
 *    numérotation de l'application continue à la suite.
 *
 * Toujours en deux temps : analyse (aperçu, erreurs, avertissements), puis import. Une écriture
 * déjà présente n'est jamais importée deux fois. L'import d'un service est refusé s'il a déjà
 * des écritures saisies dans l'application (hors reprise).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reprise Excel.
 */
final class IGE_FFM_Reprise {

	const OPTION_CENTRAL = 'ige_ffm_reprise_central';

	/**
	 * Valeur d'une colonne par son libellé.
	 *
	 * @param array  $ligne Ligne.
	 * @param array  $cols  Colonnes (libellé normalisé => index).
	 * @param string $nom   Libellé.
	 * @return mixed
	 */
	private static function v( array $ligne, array $cols, $nom ) {
		$k = IGE_FFM_Xlsx::norm( $nom );
		return isset( $cols[ $k ] ) ? ( $ligne[ $cols[ $k ] ] ?? null ) : null;
	}

	/**
	 * Montant entier (accepte « 1 250 000 », 1250000.0).
	 *
	 * @param mixed $v Valeur.
	 * @return int|null
	 */
	private static function montant( $v ) {
		if ( null === $v || '' === $v ) {
			return null;
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return (int) round( $v );
		}
		$s = preg_replace( '/[\s\x{00A0}\x{202F}]/u', '', (string) $v );
		return is_numeric( $s ) ? (int) round( (float) $s ) : null;
	}

	// ---------------------------------------------------------------------
	// Classeur central.
	// ---------------------------------------------------------------------

	/**
	 * Lit les états de vérification du classeur central et les conserve pour les imports.
	 *
	 * @param string $chemin Fichier.
	 * @return array|WP_Error Résumé : nb, validees, rejetees, a_verifier.
	 */
	public static function charger_central( $chemin ) {
		$x = IGE_FFM_Xlsx::lire( $chemin );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		if ( ! isset( $x['RECEPTION'] ) ) {
			return new WP_Error( 'feuille', 'Feuille RECEPTION introuvable : choisissez le classeur central IGE_FF_CENTRAL.' );
		}
		$h = IGE_FFM_Xlsx::entetes( $x['RECEPTION'], 'ID_ECRITURE' );
		if ( ! $h ) {
			return new WP_Error( 'feuille', 'En-têtes de la feuille RECEPTION introuvables.' );
		}
		$map = array();
		$res = array( 'nb' => 0, 'validee' => 0, 'rejetee' => 0, 'a_verifier' => 0 );
		foreach ( $x['RECEPTION'] as $n => $l ) {
			if ( $n <= $h['ligne'] ) {
				continue;
			}
			$id = strtoupper( trim( (string) self::v( $l, $h['colonnes'], 'ID_ECRITURE' ) ) );
			if ( '' === $id ) {
				continue;
			}
			$e = IGE_FFM_Xlsx::norm( (string) self::v( $l, $h['colonnes'], 'ETAT_VERIFICATION' ) );
			$e = 'validee' === $e ? 'validee' : ( 'rejetee' === $e ? 'rejetee' : 'a_verifier' );
			$map[ $id ] = array( $e, mb_substr( trim( (string) self::v( $l, $h['colonnes'], 'ANOMALIES / MOTIF' ) ), 0, 255 ) );
			++$res['nb'];
			++$res[ $e ];
		}
		update_option( self::OPTION_CENTRAL, array( 'etats' => $map, 'charge_le' => ige_ffm_now(), 'resume' => $res ), false );
		IGE_FFM_Audit::log( 'reprise_central', 'reprise', 'IGE_FF_CENTRAL', null, $res );
		return $res;
	}

	/**
	 * États du classeur central chargé.
	 *
	 * @return array
	 */
	public static function central() {
		return (array) get_option( self::OPTION_CENTRAL, array() );
	}

	// ---------------------------------------------------------------------
	// Fichier de service.
	// ---------------------------------------------------------------------

	/**
	 * Analyse un fichier de service et prépare l'import.
	 *
	 * @param string $chemin Fichier.
	 * @return array|WP_Error Analyse : service, solde_initial, budget, ecritures, erreurs, avertissements, totaux.
	 */
	public static function analyser( $chemin ) {
		$x = IGE_FFM_Xlsx::lire( $chemin );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		if ( ! isset( $x['LIVRE_DE_CAISSE'] ) ) {
			return new WP_Error( 'feuille', 'Feuille LIVRE_DE_CAISSE introuvable : choisissez un fichier de service (SERVICE_….xlsm).' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$err   = array();
		$avert = array();

		// Paramètres : code service, exercice, solde initial.
		$p = array();
		foreach ( $x['PARAMETRES'] ?? array() as $l ) {
			if ( isset( $l[1] ) && is_string( $l[1] ) ) {
				$p[ IGE_FFM_Xlsx::norm( $l[1] ) ] = $l[2] ?? null;
			}
		}
		$code  = trim( (string) ( $p['codeservice'] ?? '' ) );
		$annee = (int) ( $p['exercice'] ?? 0 );
		$si    = self::montant( $p['soldeinitialdecaissereport'] ?? null );
		foreach ( $x['LIVRE_DE_CAISSE'] as $l ) {
			foreach ( $l as $c => $v ) {
				if ( is_string( $v ) && 'soldeinitial' === IGE_FFM_Xlsx::norm( $v ) && isset( $l[ $c + 1 ] ) ) {
					$si = self::montant( $l[ $c + 1 ] ) ?? $si;
				}
			}
		}
		$h = IGE_FFM_Xlsx::entetes( $x['LIVRE_DE_CAISSE'], 'ID_ECRITURE' );
		if ( ! $h ) {
			return new WP_Error( 'feuille', 'En-têtes du livre de caisse introuvables.' );
		}
		if ( '' === $code ) {
			foreach ( $x['LIVRE_DE_CAISSE'] as $n => $l ) {
				if ( $n > $h['ligne'] && self::v( $l, $h['colonnes'], 'Service' ) ) {
					$code = trim( (string) self::v( $l, $h['colonnes'], 'Service' ) );
					break;
				}
			}
		}
		$service = null;
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			if ( IGE_FFM_Xlsx::norm( $s['code'] ) === IGE_FFM_Xlsx::norm( $code ) || IGE_FFM_Xlsx::norm( $s['libelle'] ) === IGE_FFM_Xlsx::norm( $code ) ) {
				$service = $s;
				break;
			}
		}
		if ( ! $service ) {
			return new WP_Error( 'service', sprintf( 'Le service « %s » du fichier n’existe pas dans l’application. Créez-le d’abord dans Référentiels.', $code ) );
		}
		if ( $annee && $annee !== (int) $ex['annee'] ) {
			return new WP_Error( 'exercice', sprintf( 'Le fichier concerne l’exercice %d ; l’exercice ouvert est %d.', $annee, $ex['annee'] ) );
		}
		$sid = (int) $service['id'];

		// Budget.
		$budget = array();
		$rubs   = array();
		foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) {
			$rubs[ strtoupper( $r['code'] ) ] = $r;
		}
		$hb = isset( $x['BUDGET'] ) ? IGE_FFM_Xlsx::entetes( $x['BUDGET'], 'Code rubrique' ) : null;
		if ( $hb ) {
			foreach ( $x['BUDGET'] as $n => $l ) {
				if ( $n <= $hb['ligne'] ) {
					continue;
				}
				$rc = strtoupper( trim( (string) self::v( $l, $hb['colonnes'], 'Code rubrique' ) ) );
				$m  = self::montant( self::v( $l, $hb['colonnes'], 'Budget annuel (CDF)' ) );
				if ( '' === $rc ) {
					continue;
				}
				if ( ! isset( $rubs[ $rc ] ) ) {
					$err[] = sprintf( 'Budget : rubrique %s inconnue dans l’application.', $rc );
					continue;
				}
				if ( $m ) {
					$budget[ (int) $rubs[ $rc ]['id'] ] = $m;
				}
			}
		}

		// Livre de caisse.
		$modes = array();
		foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
			$modes[ IGE_FFM_Xlsx::norm( $m['libelle'] ) ] = (int) $m['id'];
		}
		$central = self::central()['etats'] ?? array();
		$ecr     = array();
		$ids     = array();
		foreach ( $x['LIVRE_DE_CAISSE'] as $n => $l ) {
			if ( $n <= $h['ligne'] ) {
				continue;
			}
			$c   = $h['colonnes'];
			$id  = strtoupper( trim( (string) self::v( $l, $c, 'ID_ECRITURE' ) ) );
			$ent = self::montant( self::v( $l, $c, 'Entrée' ) );
			$sor = self::montant( self::v( $l, $c, 'Sortie' ) );
			if ( '' === $id && ! $ent && ! $sor ) {
				continue;
			}
			$lig = 'ligne ' . $n . ( $id ? ' (' . $id . ')' : '' );
			if ( '' === $id ) {
				$err[] = ucfirst( $lig ) . ' : identifiant manquant.';
				continue;
			}
			if ( isset( $ids[ $id ] ) ) {
				$err[] = ucfirst( $lig ) . ' : identifiant en double dans le fichier.';
				continue;
			}
			$ids[ $id ] = true;
			$date       = IGE_FFM_Xlsx::date( self::v( $l, $c, 'Date' ) );
			$type       = $ent ? 'entree' : 'sortie';
			$mnt        = $ent ? $ent : (int) $sor;
			$desc       = trim( (string) self::v( $l, $c, 'Description' ) );
			$rc         = strtoupper( trim( (string) self::v( $l, $c, 'Code rubrique' ) ) );
			$mode       = $modes[ IGE_FFM_Xlsx::norm( (string) self::v( $l, $c, 'Mode de paiement' ) ) ] ?? 0;
			$statut     = 'engagee' === IGE_FFM_Xlsx::norm( (string) self::v( $l, $c, 'Statut' ) ) && 'sortie' === $type ? 'engagee' : 'payee';
			$num        = (int) self::v( $l, $c, 'N°' );
			if ( ! $num && preg_match( '/-(\d+)$/', $id, $mm ) ) {
				$num = (int) $mm[1];
			}
			if ( ! $date || $date < $ex['date_debut'] || $date > $ex['date_fin'] ) {
				$err[] = ucfirst( $lig ) . ' : date absente ou hors de l’exercice.';
				continue;
			}
			if ( ! $mnt ) {
				$err[] = ucfirst( $lig ) . ' : ni entrée ni sortie.';
				continue;
			}
			if ( 'sortie' === $type && ! isset( $rubs[ $rc ] ) ) {
				$err[] = ucfirst( $lig ) . ' : rubrique « ' . $rc . ' » inconnue.';
				continue;
			}
			if ( ! $mode ) {
				$avert[] = ucfirst( $lig ) . ' : mode de paiement inconnu, « Espèces » retenu.';
				$mode    = $modes['especes'] ?? (int) current( $modes );
			}
			if ( '' === $desc ) {
				$desc    = 'entree' === $type ? 'Entrée (reprise)' : 'Sortie (reprise)';
				$avert[] = ucfirst( $lig ) . ' : description vide.';
			}
			if ( 'sortie' === $type && '' === trim( (string) self::v( $l, $c, 'N° pièce' ) ) && $mnt > 0 ) {
				$avert[] = ucfirst( $lig ) . ' : sortie sans n° de pièce.';
			}
			// État : classeur central d'abord, sinon état de transmission du fichier de service.
			$tr = IGE_FFM_Xlsx::norm( (string) self::v( $l, $c, 'ETAT_TRANSMISSION' ) );
			if ( isset( $central[ $id ] ) ) {
				$et = array( 'aretransmettre' === $tr ? 'a_retransmettre' : 'transmise', $central[ $id ][0], 'rejetee' === $central[ $id ][0] ? $central[ $id ][1] : '' );
			} elseif ( in_array( $tr, array( 'transmise', 'aretransmettre' ), true ) ) {
				$et = array( 'aretransmettre' === $tr ? 'a_retransmettre' : 'transmise', 'a_verifier', '' );
			} else {
				$et = array( 'non_transmise', '', '' );
			}
			$origine_ref = '';
			if ( $mnt < 0 && preg_match( '/([A-Z0-9]+-\d{4}-\d{6})/', strtoupper( $desc . ' ' . (string) self::v( $l, $c, 'Observations' ) ), $mm ) ) {
				$origine_ref = $mm[1];
			}
			$ecr[] = array(
				'id_ecriture'      => $id,
				'numero'           => $num,
				'date_operation'   => $date,
				'mois'             => (int) substr( $date, 5, 2 ),
				'type'             => $type,
				'statut'           => $statut,
				'rubrique_id'      => 'sortie' === $type ? (int) $rubs[ $rc ]['id'] : null,
				'description'      => mb_substr( $desc, 0, 255 ),
				'beneficiaire'     => mb_substr( trim( (string) self::v( $l, $c, 'Fournisseur / bénéficiaire' ) ), 0, 190 ),
				'num_piece'        => mb_substr( trim( (string) self::v( $l, $c, 'N° pièce' ) ), 0, 60 ),
				'mode_paiement_id' => $mode,
				'montant'          => $mnt,
				'observations'     => trim( 'Reprise du fichier Excel. ' . trim( (string) self::v( $l, $c, 'Observations' ) ) ),
				'date_paiement'    => 'payee' === $statut ? $date : null,
				'etat_transmission' => $et[0],
				'etat_verification' => $et[1],
				'motif_rejet'      => $et[2],
				'utilisateur'      => trim( (string) self::v( $l, $c, 'Utilisateur' ) ),
				'saisi_le'         => self::v( $l, $c, 'Date de saisie' ),
				'origine_ref'      => $origine_ref,
			);
		}
		usort( $ecr, static fn( $a, $b ) => $a['numero'] <=> $b['numero'] );

		// Cohérence avec la base.
		global $wpdb;
		$t      = IGE_FFM_DB::t( 'ecriture' );
		$exo    = (int) $ex['id'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$app    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d AND service_id = %d AND origine <> 'excel'", $exo, $sid ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deja   = array_flip( $wpdb->get_col( $wpdb->prepare( "SELECT id_ecriture FROM $t WHERE exercice_id = %d AND service_id = %d", $exo, $sid ) ) );
		$nouv   = array_values( array_filter( $ecr, static fn( $e ) => ! isset( $deja[ $e['id_ecriture'] ] ) ) );
		if ( $app ) {
			$err[] = sprintf( 'Le service a déjà %d écriture(s) saisie(s) dans l’application : la reprise doit se faire avant le démarrage du service.', $app );
		}
		$tot  = array( 'entrees' => 0, 'sorties' => 0, 'engagees' => 0 );
		$solde = (int) $si;
		foreach ( $ecr as $e ) {
			if ( 'entree' === $e['type'] ) {
				$tot['entrees'] += $e['montant'];
				$solde          += $e['montant'];
			} elseif ( 'payee' === $e['statut'] ) {
				$tot['sorties'] += $e['montant'];
				$solde          -= $e['montant'];
			} else {
				$tot['engagees'] += $e['montant'];
			}
		}
		if ( $solde < 0 ) {
			$avert[] = sprintf( 'Le solde de caisse calculé est négatif (%s) : vérifiez le fichier.', ige_ffm_montant( $solde ) );
		}
		$etats = array_count_values( array_map( static fn( $e ) => $e['etat_verification'] ?: $e['etat_transmission'], $ecr ) );
		return array(
			'service'        => $service,
			'solde_initial'  => (int) $si,
			'budget'         => $budget,
			'ecritures'      => $nouv,
			'nb_fichier'     => count( $ecr ),
			'deja'           => count( $ecr ) - count( $nouv ),
			'erreurs'        => $err,
			'avertissements' => $avert,
			'totaux'         => $tot + array( 'solde_final' => $solde ),
			'etats'          => $etats,
			'central'        => (bool) $central,
		);
	}

	/**
	 * Importe une analyse sans erreur.
	 *
	 * @param array    $a       Analyse.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error Résumé.
	 */
	public static function importer( array $a, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'La reprise est réservée à Administration et Finance.' );
		}
		if ( $a['erreurs'] ) {
			return new WP_Error( 'erreurs', 'Corrigez d’abord les erreurs signalées dans le fichier.' );
		}
		$ex  = IGE_FFM_Contexte::exercice();
		$exo = (int) $ex['id'];
		$sid = (int) $a['service']['id'];
		$t   = IGE_FFM_DB::t( 'ecriture' );
		$now = ige_ffm_now();

		// Solde initial et budget.
		if ( null !== $a['solde_initial'] ) {
			$st = IGE_FFM_DB::t( 'solde_initial' );
			$wpdb->delete( $st, array( 'exercice_id' => $exo, 'service_id' => $sid, 'devise' => 'CDF' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $st, array( 'exercice_id' => $exo, 'service_id' => $sid, 'devise' => 'CDF', 'montant' => (int) $a['solde_initial'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$bt = IGE_FFM_DB::t( 'budget' );
		foreach ( $a['budget'] as $rid => $m ) {
			$wpdb->delete( $bt, array( 'exercice_id' => $exo, 'service_id' => $sid, 'rubrique_id' => (int) $rid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $bt, array( 'exercice_id' => $exo, 'service_id' => $sid, 'rubrique_id' => (int) $rid, 'montant' => (int) $m, 'updated_by' => $user_id, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		// Écritures.
		$n        = 0;
		$par_ref  = array();
		$a_lier   = array();
		foreach ( $a['ecritures'] as $e ) {
			$u        = $e['utilisateur'] ? get_user_by( 'login', $e['utilisateur'] ) : false;
			$saisi_le = $e['saisi_le'] ? IGE_FFM_Xlsx::date( $e['saisi_le'] ) : '';
			$row      = array(
				'id_ecriture'       => $e['id_ecriture'],
				'numero'            => (int) $e['numero'],
				'exercice_id'       => $exo,
				'service_id'        => $sid,
				'date_operation'    => $e['date_operation'],
				'mois'              => $e['mois'],
				'type'              => $e['type'],
				'statut'            => $e['statut'],
				'rubrique_id'       => $e['rubrique_id'],
				'description'       => $e['description'],
				'beneficiaire'      => $e['beneficiaire'],
				'num_piece'         => $e['num_piece'],
				'mode_paiement_id'  => $e['mode_paiement_id'],
				'montant'           => $e['montant'],
				'observations'      => $e['observations'] . ( $e['utilisateur'] && ! $u ? ' Saisi dans Excel par ' . $e['utilisateur'] . '.' : '' ),
				'date_paiement'     => $e['date_paiement'],
				'etat_transmission' => $e['etat_transmission'],
				'etat_verification' => $e['etat_verification'],
				'motif_rejet'       => $e['motif_rejet'],
				'anomalies'         => '[]',
				'nb_transmissions'  => 'non_transmise' === $e['etat_transmission'] ? 0 : 1,
				'origine'           => 'excel',
				'valide_par'        => in_array( $e['etat_verification'], array( 'validee', 'rejetee' ), true ) ? $user_id : null,
				'valide_le'         => in_array( $e['etat_verification'], array( 'validee', 'rejetee' ), true ) ? $now : null,
				'saisi_par'         => $u ? $u->ID : $user_id,
				'saisi_le'          => ( $saisi_le ?: $e['date_operation'] ) . ' 12:00:00',
			);
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				++$n;
				$par_ref[ $e['id_ecriture'] ] = (int) $wpdb->insert_id;
				if ( $e['origine_ref'] ) {
					$a_lier[ (int) $wpdb->insert_id ] = $e['origine_ref'];
				}
			}
		}
		// Contrepassations : lien vers l'écriture d'origine.
		foreach ( $a_lier as $id => $ref ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$orig = $par_ref[ $ref ] ?? (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE id_ecriture = %s", $ref ) );
			if ( $orig ) {
				$wpdb->update( $t, array( 'ecriture_origine_id' => $orig ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		$res = array(
			'service'       => $a['service']['code'],
			'ecritures'     => $n,
			'budget'        => count( $a['budget'] ),
			'solde_initial' => (int) $a['solde_initial'],
			'caisse'        => IGE_FFM_Budget::caisse( $exo, $sid ),
		);
		IGE_FFM_Audit::log( 'reprise_service', 'reprise', $a['service']['code'], null, $res, $user_id );
		return $res;
	}

	/**
	 * État de la reprise par service (pour le suivi du déploiement).
	 *
	 * @param int $exo Exercice.
	 * @return array<int,int> service => écritures reprises.
	 */
	public static function par_service( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_results( $wpdb->prepare( 'SELECT service_id, COUNT(*) AS n FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND origine = 'excel' GROUP BY service_id", $exo ), ARRAY_A );
		return array_map( 'intval', wp_list_pluck( $r, 'n', 'service_id' ) );
	}
}
