<?php
/**
 * Analyses du lot V2 :
 * - score de risque par service (VA-21) ;
 * - comparaison des services : dépense rapportée au budget et aux effectifs (VA-23) ;
 * - consolidation par ligne de la nomenclature budgétaire de l'État (VA-33) ;
 * - rapport hebdomadaire par e-mail à Administration et Finance (VA-34).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analyses.
 */
final class IGE_FFM_Analyses {

	const OPTION_HEBDO = 'ige_ffm_rapport_hebdo';

	/**
	 * Facteurs du score : clé => [ libellé, poids, explication ].
	 *
	 * @return array<string,array>
	 */
	public static function facteurs() {
		return (array) apply_filters(
			'ige_ffm_facteurs_risque',
			array(
				'rejets'       => array( 'Rejets', 20, 'Part des dépenses transmises rejetées au moins une fois (25 % ou plus = risque maximal).' ),
				'transmission' => array( 'Retard de transmission', 15, 'Âge de la plus ancienne écriture non transmise, au-delà du délai de relance (30 jours de plus = risque maximal).' ),
				'reliquats'    => array( 'Avances non justifiées', 20, 'Part des avances encore non justifiées après 60 jours.' ),
				'anomalies'    => array( 'Anomalies', 20, 'Part des dépenses transmises signalées par les contrôles automatiques (33 % ou plus = risque maximal).' ),
				'arretes'      => array( 'Arrêtés en retard', 15, 'Mois écoulés sans arrêté de caisse signé (3 mois = risque maximal).' ),
				'ecarts'       => array( 'Écarts de caisse', 10, 'Arrêtés de l’exercice avec un écart entre la caisse comptée et le livre (4 = risque maximal).' ),
			)
		);
	}

	/**
	 * Niveau d'un score.
	 *
	 * @param float $s Score.
	 * @return array [ libellé, ton ].
	 */
	public static function niveau( $s ) {
		if ( $s >= 60 ) {
			return array( 'Élevé', 'ko' );
		}
		if ( $s >= 35 ) {
			return array( 'Moyen', 'wait' );
		}
		return array( 'Faible', 'ok' );
	}

	/**
	 * Scores de risque des services, du plus élevé au plus faible.
	 *
	 * @param int $exo Exercice.
	 * @return array[] service, score, niveau, facteurs (clé => [ risque 0-100, mesure lisible ]), activite.
	 */
	public static function risques( $exo ) {
		global $wpdb;
		$t    = IGE_FFM_DB::t( 'ecriture' );
		$fac  = self::facteurs();
		$auj  = IGE_FFM_Contexte::aujourdhui();
		$ex   = IGE_FFM_Contexte::exercice();
		$del  = (int) IGE_FFM_Reglages::get( 'delai_relance' );
		$av   = IGE_FFM_Avances::synthese( $exo );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tr = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) n, SUM(etat_verification = 'rejetee' OR nb_transmissions > 1) r, SUM(anomalies IS NOT NULL AND anomalies NOT IN ('', '[]', '{}')) a FROM $t WHERE exercice_id = %d AND type = 'sortie' AND etat_transmission <> 'non_transmise' GROUP BY service_id", $exo ), ARRAY_A );
		$nt = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, MIN(saisi_le) d, COUNT(*) n FROM $t WHERE exercice_id = %d AND etat_transmission IN ('non_transmise','a_retransmettre') GROUP BY service_id", $exo ), ARRAY_A );
		$ec = $wpdb->get_results( $wpdb->prepare( 'SELECT service_id, COUNT(*) n, SUM(ABS(ecart)) m FROM ' . IGE_FFM_DB::t( 'arrete' ) . " WHERE exercice_id = %d AND ecart <> 0 AND statut <> 'brouillon' GROUP BY service_id", $exo ), ARRAY_A );
		$nb = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) n FROM $t WHERE exercice_id = %d GROUP BY service_id", $exo ), ARRAY_A );
		// phpcs:enable
		$tr = array_column( $tr, null, 'service_id' );
		$nt = array_column( $nt, null, 'service_id' );
		$ec = array_column( $ec, null, 'service_id' );
		$nb = array_column( $nb, 'n', 'service_id' );
		$mref = $ex && (int) wp_date( 'Y' ) === (int) $ex['annee'] ? (int) wp_date( 'n' ) - 1 : 12;
		$out  = array();
		foreach ( IGE_FFM_Referentiels::all( 'service', true ) as $s ) {
			$sid = (int) $s['id'];
			$f   = array();
			$n   = (int) ( $tr[ $sid ]['n'] ?? 0 );
			$r   = (int) ( $tr[ $sid ]['r'] ?? 0 );
			$a   = (int) ( $tr[ $sid ]['a'] ?? 0 );
			$tx  = $n ? $r / $n : 0;
			$f['rejets'] = array( min( 100, $tx * 400 ), $n ? sprintf( '%d sur %d (%d %%)', $r, $n, round( $tx * 100 ) ) : 'aucune transmission' );
			$age = isset( $nt[ $sid ] ) ? (int) floor( ( strtotime( $auj ) - strtotime( substr( $nt[ $sid ]['d'], 0, 10 ) ) ) / DAY_IN_SECONDS ) : 0;
			$f['transmission'] = array( min( 100, max( 0, ( $age - $del ) / 30 * 100 ) ), isset( $nt[ $sid ] ) ? sprintf( '%d en attente, la plus ancienne depuis %d jours', $nt[ $sid ]['n'], $age ) : 'à jour' );
			$x   = $av[ $sid ] ?? null;
			$vieux = $x ? $x['tranches']['de60a90'] + $x['tranches']['plus90'] : 0;
			$f['reliquats'] = array( $x && $x['remis'] > 0 ? min( 100, $vieux / $x['remis'] * 200 ) : 0, $vieux ? ige_ffm_montant( $vieux ) . ' non justifiés depuis plus de 60 jours' : 'aucun reliquat ancien' );
			$ta  = $n ? $a / $n : 0;
			$f['anomalies'] = array( min( 100, $ta * 300 ), $n ? sprintf( '%d écriture(s) signalée(s) (%d %%)', $a, round( $ta * 100 ) ) : '—' );
			$clos   = IGE_FFM_Arretes::dernier_mois_clos( $exo, $sid );
			$retard = ! empty( $nb[ $sid ] ) ? max( 0, $mref - $clos ) : 0;
			$f['arretes'] = array( min( 100, $retard * 34 ), $retard ? sprintf( '%d mois sans arrêté signé', $retard ) : 'à jour' );
			$ne = (int) ( $ec[ $sid ]['n'] ?? 0 );
			$f['ecarts'] = array( min( 100, $ne * 25 ), $ne ? sprintf( '%d arrêté(s), %s d’écart cumulé', $ne, ige_ffm_montant( (int) $ec[ $sid ]['m'] ) ) : 'aucun' );
			$score = 0;
			$poids = 0;
			foreach ( $fac as $k => $d ) {
				$score += ( $f[ $k ][0] ?? 0 ) * $d[1];
				$poids += $d[1];
			}
			$score = $poids ? round( $score / $poids, 1 ) : 0;
			$out[] = array( 'service' => $s, 'score' => $score, 'niveau' => self::niveau( $score ), 'facteurs' => $f, 'activite' => ! empty( $nb[ $sid ] ) );
		}
		usort( $out, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return $out;
	}

	/**
	 * Médiane.
	 *
	 * @param float[] $v Valeurs.
	 * @return float|null
	 */
	public static function mediane( array $v ) {
		$v = array_values( array_filter( $v, static fn( $x ) => null !== $x ) );
		if ( ! $v ) {
			return null;
		}
		sort( $v );
		$n = count( $v );
		return $n % 2 ? (float) $v[ intdiv( $n, 2 ) ] : ( $v[ $n / 2 - 1 ] + $v[ $n / 2 ] ) / 2;
	}

	/**
	 * Comparaison des services (dépenses validées), éventuellement pour une rubrique.
	 *
	 * @param int $exo Exercice.
	 * @param int $rid Rubrique (0 = toutes).
	 * @return array{lignes:array, mediane:?float, sans_effectif:int}
	 */
	public static function comparaison( $exo, $rid = 0 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		$w = $rid ? $wpdb->prepare( ' AND rubrique_id = %d', $rid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$dep = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, SUM(montant) m FROM $t WHERE exercice_id = %d AND type = 'sortie' AND etat_verification = 'validee' $w GROUP BY service_id", $exo ), ARRAY_A );
		$dep = array_map( 'intval', array_column( $dep, 'm', 'service_id' ) );
		$bt  = IGE_FFM_DB::t( 'budget' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$bud = $wpdb->get_results( $wpdb->prepare( "SELECT service_id, SUM(montant) m FROM $bt WHERE exercice_id = %d" . ( $rid ? $wpdb->prepare( ' AND rubrique_id = %d', $rid ) : '' ) . ' GROUP BY service_id', $exo ), ARRAY_A );
		$bud = array_map( 'intval', array_column( $bud, 'm', 'service_id' ) );
		$l   = array();
		$sans = 0;
		foreach ( IGE_FFM_Referentiels::all( 'service', true ) as $s ) {
			$sid = (int) $s['id'];
			$eff = (int) ( $s['effectif'] ?? 0 );
			$d   = $dep[ $sid ] ?? 0;
			$b   = $bud[ $sid ] ?? 0;
			if ( ! $eff ) {
				++$sans;
			}
			$l[ $sid ] = array(
				'service'   => $s,
				'effectif'  => $eff ?: null,
				'depense'   => $d,
				'budget'    => $b ?: null,
				'taux'      => $b ? round( $d / $b * 100, 1 ) : null,
				'par_agent' => $eff ? (int) round( $d / $eff ) : null,
				'budget_agent' => $eff && $b ? (int) round( $b / $eff ) : null,
			);
		}
		$med = self::mediane( array_column( array_filter( $l, static fn( $x ) => $x['par_agent'] > 0 ), 'par_agent' ) );
		foreach ( $l as &$x ) {
			$x['indice'] = $med && null !== $x['par_agent'] ? round( $x['par_agent'] / $med * 100 ) : null;
			$x['signal'] = null === $x['indice'] ? '' : ( $x['indice'] >= 150 ? 'haut' : ( $x['indice'] <= 50 && $x['par_agent'] > 0 ? 'bas' : '' ) );
		}
		unset( $x );
		uasort( $l, static fn( $a, $b ) => ( $b['par_agent'] ?? -1 ) <=> ( $a['par_agent'] ?? -1 ) );
		return array( 'lignes' => $l, 'mediane' => $med, 'sans_effectif' => $sans );
	}

	/**
	 * Consolidation par ligne de la nomenclature (budget et dépenses validées).
	 *
	 * @param int $exo Exercice.
	 * @return array[] code, libelle, rubriques, budget, consomme, taux.
	 */
	public static function par_nomenclature( $exo ) {
		global $wpdb;
		$noms = array();
		foreach ( IGE_FFM_Referentiels::all( 'nomenclature' ) as $n ) {
			$noms[ $n['code'] ] = $n['libelle'];
		}
		$lignes = array();
		$rub    = array();
		foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) {
			$code             = '' !== $r['nomenclature'] ? $r['nomenclature'] : '';
			$rub[ $r['id'] ]  = $code;
			if ( ! isset( $lignes[ $code ] ) ) {
				$lignes[ $code ] = array( 'code' => $code, 'libelle' => '' === $code ? 'Rubriques non rattachées' : ( $noms[ $code ] ?? '(ligne absente de la nomenclature)' ), 'rubriques' => array(), 'budget' => 0, 'consomme' => 0 );
			}
			$lignes[ $code ]['rubriques'][] = $r['code'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT rubrique_id, SUM(montant) m FROM ' . IGE_FFM_DB::t( 'budget' ) . ' WHERE exercice_id = %d GROUP BY rubrique_id', $exo ), ARRAY_A ) as $b ) {
			if ( isset( $rub[ $b['rubrique_id'] ] ) ) {
				$lignes[ $rub[ $b['rubrique_id'] ] ]['budget'] += (int) $b['m'];
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT rubrique_id, SUM(montant) m FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND type = 'sortie' AND etat_verification = 'validee' GROUP BY rubrique_id", $exo ), ARRAY_A ) as $e ) {
			if ( isset( $rub[ $e['rubrique_id'] ] ) ) {
				$lignes[ $rub[ $e['rubrique_id'] ] ]['consomme'] += (int) $e['m'];
			}
		}
		foreach ( $lignes as &$x ) {
			$x['taux'] = $x['budget'] ? round( $x['consomme'] / $x['budget'] * 100, 1 ) : null;
		}
		unset( $x );
		uksort( $lignes, static fn( $a, $b ) => ( '' === $a ) <=> ( '' === $b ) ?: strnatcmp( $a, $b ) );
		return array_values( $lignes );
	}

	/* ------------------------------------------------------------------ Rapport hebdomadaire */

	/**
	 * Réglages du rapport hebdomadaire.
	 *
	 * @return array{actif:bool, jour:int, heure:int, copie:string, dernier:string}
	 */
	public static function reglages_hebdo() {
		$o = get_option( self::OPTION_HEBDO, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'actif'   => array_key_exists( 'actif', $o ) ? (bool) $o['actif'] : true,
			'jour'    => isset( $o['jour'] ) ? max( 1, min( 7, (int) $o['jour'] ) ) : 1,
			'heure'   => isset( $o['heure'] ) ? max( 5, min( 20, (int) $o['heure'] ) ) : 7,
			'copie'   => (string) ( $o['copie'] ?? '' ),
			'dernier' => (string) ( $o['dernier'] ?? '' ),
		);
	}

	/**
	 * Enregistre les réglages.
	 *
	 * @param array    $in      actif, jour, heure, copie.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function enregistrer_hebdo( array $in, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$avant = self::reglages_hebdo();
		$copie = array();
		foreach ( preg_split( '/[\s,;]+/', (string) ( $in['copie'] ?? '' ) ) as $m ) {
			if ( '' === $m ) {
				continue;
			}
			if ( ! is_email( $m ) ) {
				return new WP_Error( 'copie', 'Adresse invalide : ' . $m );
			}
			$copie[] = strtolower( $m );
		}
		$o = array(
			'actif'   => ! empty( $in['actif'] ),
			'jour'    => max( 1, min( 7, (int) ( $in['jour'] ?? 1 ) ) ),
			'heure'   => max( 5, min( 20, (int) ( $in['heure'] ?? 7 ) ) ),
			'copie'   => implode( ', ', array_slice( array_unique( $copie ), 0, 10 ) ),
			'dernier' => $avant['dernier'],
		);
		update_option( self::OPTION_HEBDO, $o, false );
		IGE_FFM_Audit::log( 'reglage_rapport_hebdo', 'reglages', 'rapport_hebdo', array_diff_key( $avant, array( 'dernier' => 1 ) ), array_diff_key( $o, array( 'dernier' => 1 ) ), $user_id );
		return true;
	}

	/**
	 * Destinataires : comptes Administration et Finance (droit de vérifier), plus les copies.
	 *
	 * @return string[]
	 */
	public static function destinataires() {
		$a = array();
		foreach ( get_users( array( 'capability' => 'ige_verifier', 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
			$c = IGE_FFM_Comptes::get( (int) $u->ID );
			if ( is_email( $u->user_email ) && ( ! $c || (int) $c['actif'] ) ) {
				$a[] = strtolower( $u->user_email );
			}
		}
		foreach ( preg_split( '/[\s,;]+/', self::reglages_hebdo()['copie'] ) as $m ) {
			if ( is_email( $m ) ) {
				$a[] = $m;
			}
		}
		return array_values( array_unique( $a ) );
	}

	/**
	 * Contenu du rapport (texte, paragraphes séparés par une ligne vide).
	 *
	 * @return array{sujet:string, texte:string, chiffres:array}
	 */
	public static function contenu_hebdo() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return array( 'sujet' => 'Rapport de la semaine', 'texte' => 'Aucun exercice ouvert.', 'chiffres' => array() );
		}
		$exo = (int) $ex['id'];
		$syn = IGE_FFM_Verification::synthese( $exo );
		$p   = array();
		$p[] = 'Bonjour,' . "\n\n" . 'Voici les priorités de la semaine du ' . wp_date( 'd/m/Y' ) . ' pour la ' . lcfirst( ige_ffm_nom_appli() ) . ' (exercice ' . $ex['annee'] . ').';
		$p[] = 'À VÉRIFIER' . "\n" . sprintf( '%d écriture(s) transmise(s) pour %s, dont %d avec une anomalie.', $syn['a_verifier']['n'] ?? 0, ige_ffm_montant( (int) ( $syn['a_verifier']['montant'] ?? 0 ) ), $syn['anomalies']['n'] ?? 0 ) . "\n" . ige_ffm_url( 'verification' );
		$al  = IGE_FFM_Consolidation::alertes( $exo );
		if ( $al ) {
			$l = array();
			foreach ( array_slice( $al, 0, 12 ) as $a ) {
				$l[] = '- ' . ( 'ko' === $a[0] ? '[URGENT] ' : '' ) . $a[1];
			}
			$p[] = 'ALERTES (' . count( $al ) . ')' . "\n" . implode( "\n", $l ) . ( count( $al ) > 12 ? "\n- …" : '' ) . "\n" . ige_ffm_url( 'tableau-de-bord' );
		} else {
			$p[] = 'ALERTES' . "\n" . 'Aucune alerte en cours.';
		}
		$vis = IGE_FFM_Fonctions::active( 'demandes_fonds' ) ? IGE_FFM_Demandes::liste( $exo, 0, 'visee' ) : array();
		if ( $vis ) {
			$p[] = 'FONDS À DÉCAISSER' . "\n" . sprintf( '%d demande(s) accordée(s) pour %s.', count( $vis ), ige_ffm_montant( array_sum( array_map( static fn( $d ) => (int) $d['montant_accorde'], $vis ) ) ) ) . "\n" . ige_ffm_url( 'demandes-de-fonds', array( 'vue' => 'a_decaisser' ) );
		}
		if ( IGE_FFM_Fonctions::active( 'risques' ) ) {
			$r = array_filter( self::risques( $exo ), static fn( $x ) => $x['score'] >= 35 );
			if ( $r ) {
				$l = array();
				foreach ( array_slice( $r, 0, 5 ) as $x ) {
					$l[] = sprintf( '- %s : %s/100 (%s)', $x['service']['code'], number_format( $x['score'], 0 ), strtolower( $x['niveau'][0] ) );
				}
				$p[] = 'SERVICES À RISQUE' . "\n" . implode( "\n", $l ) . "\n" . ige_ffm_url( 'risques' );
			}
		}
		$p[] = 'Ce rapport est envoyé chaque semaine à Administration et Finance. Il se règle dans Administration > Notifications.';
		return array(
			'sujet'    => 'Priorités de la semaine · ' . ( $syn['a_verifier']['n'] ?? 0 ) . ' écriture(s) à vérifier · ' . count( $al ) . ' alerte(s)',
			'texte'    => implode( "\n\n", $p ),
			'chiffres' => array( 'a_verifier' => (int) ( $syn['a_verifier']['n'] ?? 0 ), 'alertes' => count( $al ) ),
		);
	}

	/**
	 * Envoie le rapport.
	 *
	 * @param string[]|null $a Destinataires (par défaut : tous).
	 * @return int Nombre de destinataires.
	 */
	public static function envoyer_hebdo( $a = null ) {
		$a = $a ?? self::destinataires();
		if ( ! $a ) {
			return 0;
		}
		$c = self::contenu_hebdo();
		IGE_FFM_Messagerie::envoyer( $a, $c['sujet'], $c['texte'] );
		IGE_FFM_Audit::log( 'rapport_hebdo', 'notification', 'rapport hebdomadaire', null, array( 'destinataires' => count( $a ) ) + $c['chiffres'], 0 );
		return count( $a );
	}

	/**
	 * Tâche quotidienne : envoie le rapport le jour et à l'heure prévus, une fois par semaine.
	 */
	public static function quotidien() {
		if ( ! IGE_FFM_Fonctions::active( 'rapport_hebdo' ) ) {
			return;
		}
		$r = self::reglages_hebdo();
		if ( ! $r['actif'] || (int) wp_date( 'N' ) !== $r['jour'] || (int) wp_date( 'G' ) < $r['heure'] ) {
			return;
		}
		$semaine = wp_date( 'o-W' );
		if ( $r['dernier'] === $semaine ) {
			return;
		}
		$o            = get_option( self::OPTION_HEBDO, array() );
		$o            = is_array( $o ) ? $o : array();
		$o['dernier'] = $semaine;
		update_option( self::OPTION_HEBDO, $o, false );
		self::envoyer_hebdo();
	}

	/**
	 * Crochets : la tâche tourne toutes les heures (WP-Cron) et n'envoie qu'au créneau prévu.
	 */
	public static function init() {
		add_action( 'ige_ffm_horaire', array( __CLASS__, 'quotidien' ) );
		if ( ! wp_next_scheduled( 'ige_ffm_horaire' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ige_ffm_horaire' );
		}
	}
}
