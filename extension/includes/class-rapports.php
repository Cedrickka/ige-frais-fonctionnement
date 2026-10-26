<?php
/**
 * Rapports officiels : contenu figé au moment de l'émission (EF-75, VA rapports en un clic).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rapports.
 */
final class IGE_FFM_Rapports {

	/**
	 * Signataires proposés.
	 *
	 * @return string[]
	 */
	public static function signataires() {
		return (array) apply_filters( 'ige_ffm_signataires', array( 'Le Chef de service Administration et Finance', 'L’Inspecteur Général de l’Enseignement' ) );
	}

	/**
	 * Montant sans devise.
	 *
	 * @param int $m Montant.
	 * @return string
	 */
	private static function n( $m ) {
		return esc_html( ige_ffm_montant( (int) $m, '' ) );
	}

	/**
	 * Services ayant une activité (budget, dépenses, entrées ou caisse) ; les autres sont résumés.
	 *
	 * @param array $c Consolidation.
	 * @return array
	 */
	private static function actifs( array $c ) {
		return array_filter( $c['services'], static fn( $s ) => $s['activite'] );
	}

	/**
	 * Pourcentage.
	 *
	 * @param float|null $t Taux.
	 * @return string
	 */
	private static function p( $t ) {
		return null === $t ? '—' : esc_html( number_format( $t, 1, ',', '' ) . ' %' );
	}

	/**
	 * Produit le contenu d'un rapport.
	 *
	 * @param string $type Type.
	 * @param array  $p    Paramètres : mois, signataire.
	 * @return array|WP_Error titre, periode, contenu.
	 */
	public static function produire( $type, array $p ) {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		switch ( $type ) {
			case 'situation_mensuelle':
				$m = (int) ( $p['mois'] ?? 0 );
				if ( $m < 1 || $m > 12 || IGE_FFM_Arretes::fin_de_mois( $ex, $m ) > IGE_FFM_Contexte::aujourdhui() && $m !== (int) wp_date( 'n' ) ) {
					return new WP_Error( 'mois', 'Choisissez un mois écoulé ou le mois en cours.' );
				}
				return array(
					'titre'   => 'Situation mensuelle d’exécution',
					'periode' => strtolower( $noms[ $m ] ) . ' ' . $ex['annee'],
					'contenu' => self::situation_mensuelle( $ex, $m ),
				);
			case 'etat_consolide':
				return array(
					'titre'   => 'État consolidé de l’exercice ' . $ex['annee'],
					'periode' => 'au ' . wp_date( 'd/m/Y' ),
					'contenu' => self::etat_consolide( $ex ),
				);
			case 'avances':
				return array(
					'titre'   => 'État des avances non justifiées',
					'periode' => 'au ' . wp_date( 'd/m/Y' ),
					'contenu' => self::avances( $ex ),
				);
			case 'rapport_annuel':
				return array(
					'titre'   => 'Rapport annuel sur les frais de fonctionnement du trésor public ' . $ex['annee'],
					'periode' => 'exercice ' . $ex['annee'] . ' au ' . wp_date( 'd/m/Y' ),
					'contenu' => self::rapport_annuel( $ex ),
				);
		}
		return new WP_Error( 'type', 'Type de rapport inconnu.' );
	}

	/**
	 * Émet un rapport (contenu figé, référence, empreinte).
	 *
	 * @param string   $type    Type.
	 * @param array    $p       Paramètres.
	 * @param int|null $user_id Émetteur.
	 * @return int|WP_Error
	 */
	public static function emettre( $type, array $p, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'Les rapports officiels sont émis par Administration et Finance.' );
		}
		$r = self::produire( $type, $p );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$sig = in_array( $p['signataire'] ?? '', self::signataires(), true ) ? $p['signataire'] : self::signataires()[0];
		$ex  = IGE_FFM_Contexte::exercice();
		return IGE_FFM_Documents::emettre(
			array(
				'type'        => $type,
				'titre'       => $r['titre'],
				'periode'     => $r['periode'],
				'contenu'     => $r['contenu'],
				'signataire'  => $sig,
				'exercice_id' => (int) $ex['id'],
			),
			$user_id
		);
	}

	/**
	 * Situation mensuelle d'exécution par service.
	 *
	 * @param array $ex Exercice.
	 * @param int   $m  Mois.
	 * @return string
	 */
	private static function situation_mensuelle( array $ex, $m ) {
		$exo   = (int) $ex['id'];
		$noms  = IGE_FFM_Ecran_Livre::mois_noms();
		$fin   = IGE_FFM_Arretes::fin_de_mois( $ex, $m );
		$c     = IGE_FFM_Consolidation::calculer( $exo, $m, $m );
		$tps   = IGE_FFM_Consolidation::temps_ecoule( $ex, min( $fin, IGE_FFM_Contexte::aujourdhui() ) );
		$haut  = array_filter( $c['services'], static fn( $s ) => null !== $s['taux'] && $s['taux'] > 85 );
		$avs   = IGE_FFM_Avances::synthese( $exo );
		$reliq = array_sum( array_column( $avs, 'reliquat' ) );
		$r90   = array_sum( array_map( static fn( $l ) => $l['tranches']['plus90'], $avs ) );
		ob_start();
		?>
		<div class="ige-subt">Frais de fonctionnement du trésor public · <?php echo esc_html( strtolower( $noms[ $m ] ) . ' ' . $ex['annee'] ); ?> · écritures validées · montants en francs congolais</div>
		<table class="ige-table ige-num">
			<thead><tr><th>Service</th><th class="ige-r">Budget</th><th class="ige-r">Consommé cumulé</th><th class="ige-r">Dont <?php echo esc_html( strtolower( $noms[ $m ] ) ); ?></th><th class="ige-r">Exécution</th><th class="ige-r">Caisse au <?php echo esc_html( wp_date( 'd/m', strtotime( $fin ) ) ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( self::actifs( $c ) as $sid => $s ) : ?>
				<tr><td><?php echo esc_html( $s['service']['code'] ); ?></td><td class="ige-r"><?php echo $s['avec_budget'] ? self::n( $s['budget'] ) : 'Sans budget'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['consomme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['mois'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $s['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( IGE_FFM_Arretes::solde_theorique( $exo, $sid, $fin ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php endforeach; ?>
			<tr class="ige-tot"><td>Total (<?php echo esc_html( $c['total']['nb_activite'] ); ?> service(s) actif(s))</td><td class="ige-r"><?php echo self::n( $c['total']['budget'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['consomme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['mois'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $c['total']['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"></td></tr>
			</tbody>
		</table>
		<p style="margin-top:18px">
			<?php
			$sans = $c['total']['nb_services'] - $c['total']['nb_activite'];
			echo esc_html(
				( $sans ? sprintf( '%d service(s) sans budget ni opération ne figurent pas dans le tableau. ', $sans ) : '' )
				. sprintf( 'Au %s, %d %% de l’exercice était écoulé. ', wp_date( 'd/m/Y', strtotime( min( $fin, IGE_FFM_Contexte::aujourdhui() ) ) ), round( $tps ) )
				. ( $haut ? sprintf( '%d service(s) présentent une exécution supérieure à 85 %% : %s. ', count( $haut ), implode( ', ', array_map( static fn( $s ) => $s['service']['code'], $haut ) ) ) : 'Aucun service ne dépasse 85 % d’exécution. ' )
				. sprintf( 'Les avances non justifiées s’élèvent à %s', ige_ffm_montant( $reliq ) )
				. ( $r90 ? sprintf( ', dont %s depuis plus de 90 jours.', ige_ffm_montant( $r90 ) ) : '.' )
			);
			?>
		</p>
		<?php
		return ob_get_clean();
	}

	/**
	 * État consolidé : par service et par catégorie.
	 *
	 * @param array $ex Exercice.
	 * @return string
	 */
	private static function etat_consolide( array $ex ) {
		$c = IGE_FFM_Consolidation::calculer( (int) $ex['id'] );
		ob_start();
		?>
		<div class="ige-subt">Écritures validées par Administration et Finance · montants en francs congolais</div>
		<table class="ige-table ige-num">
			<thead><tr><th>Service</th><th class="ige-r">Budget</th><th class="ige-r">Entrées</th><th class="ige-r">Payé</th><th class="ige-r">Engagé</th><th class="ige-r">Disponible</th><th class="ige-r">Exécution</th><th class="ige-r">Caisse</th></tr></thead>
			<tbody>
			<?php foreach ( self::actifs( $c ) as $s ) : ?>
				<tr><td><?php echo esc_html( $s['service']['code'] ); ?></td><td class="ige-r"><?php echo $s['avec_budget'] ? self::n( $s['budget'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['entrees'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['paye'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['engage'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo null === $s['disponible'] ? '—' : self::n( $s['disponible'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $s['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['caisse'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php endforeach; ?>
			<tr class="ige-tot"><td>Total</td><td class="ige-r"><?php echo self::n( $c['total']['budget'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['entrees'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['paye'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['engage'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['disponible'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $c['total']['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $c['total']['caisse'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			</tbody>
		</table>
		<h2>Par catégorie</h2>
		<table class="ige-table ige-num">
			<thead><tr><th>Catégorie</th><th class="ige-r">Budget</th><th class="ige-r">Consommé</th><th class="ige-r">Part du consommé</th></tr></thead>
			<tbody>
			<?php foreach ( $c['categories'] as $k ) : ?>
				<tr><td><?php echo esc_html( $k['libelle'] ); ?></td><td class="ige-r"><?php echo self::n( $k['budget'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $k['consomme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $c['total']['consomme'] ? $k['consomme'] / $c['total']['consomme'] * 100 : null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return ob_get_clean();
	}

	/**
	 * État des avances non justifiées.
	 *
	 * @param array $ex Exercice.
	 * @return string
	 */
	private static function avances( array $ex ) {
		$exo = (int) $ex['id'];
		$tot = array( 'montant' => 0, 'reliquat' => 0 );
		$tr  = array( 'moins30' => 0, 'de30a60' => 0, 'de60a90' => 0, 'plus90' => 0 );
		ob_start();
		?>
		<div class="ige-subt">Avances remises par l’Inspection Générale, justifiées par les dépenses validées (premier entré, premier sorti) · seuil de réapprovisionnement <?php echo esc_html( (int) IGE_FFM_Reglages::get( 'seuil_justification' ) ); ?> %</div>
		<table class="ige-table ige-num">
			<thead><tr><th>Service</th><th>Avance</th><th>Remise le</th><th class="ige-r">Montant</th><th class="ige-r">Justifié</th><th class="ige-r">Reste</th><th class="ige-r">Ancienneté</th></tr></thead>
			<tbody>
			<?php
			foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) :
				$j = IGE_FFM_Avances::justification( $exo, (int) $s['id'] );
				foreach ( $j['avances'] as $a ) :
					if ( $a['reliquat'] <= 0 ) {
						continue;
					}
					$tot['montant']  += $a['montant'];
					$tot['reliquat'] += $a['reliquat'];
					$tr[ $a['age'] < 30 ? 'moins30' : ( $a['age'] < 60 ? 'de30a60' : ( $a['age'] < 90 ? 'de60a90' : 'plus90' ) ) ] += $a['reliquat'];
					?>
					<tr><td><?php echo esc_html( $s['code'] ); ?></td><td><?php echo esc_html( $a['libelle'] ); ?></td><td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $a['date'] ) ) ); ?></td><td class="ige-r"><?php echo self::n( $a['montant'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $a['justifie'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><b><?php echo self::n( $a['reliquat'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></b></td><td class="ige-r"><?php echo esc_html( $a['age'] . ' j' ); ?></td></tr>
					<?php
				endforeach;
			endforeach;
			?>
			<?php if ( ! $tot['montant'] ) : ?>
				<tr><td colspan="7">Aucune avance en attente de justification.</td></tr>
			<?php endif; ?>
			<tr class="ige-tot"><td colspan="3">Total</td><td class="ige-r"><?php echo self::n( $tot['montant'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td></td><td class="ige-r"><?php echo self::n( $tot['reliquat'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td></td></tr>
			</tbody>
		</table>
		<p style="margin-top:16px"><?php echo esc_html( sprintf( 'Reste à justifier par ancienneté : moins de 30 jours %s ; 30 à 60 jours %s ; 60 à 90 jours %s ; plus de 90 jours %s.', ige_ffm_montant( $tr['moins30'] ), ige_ffm_montant( $tr['de30a60'] ), ige_ffm_montant( $tr['de60a90'] ), ige_ffm_montant( $tr['plus90'] ) ) ); ?></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Rapport annuel (ou situation de l'exercice à date).
	 *
	 * @param array $ex Exercice.
	 * @return string
	 */
	private static function rapport_annuel( array $ex ) {
		global $wpdb;
		$exo = (int) $ex['id'];
		$c   = IGE_FFM_Consolidation::calculer( $exo );
		$syn = IGE_FFM_Verification::synthese( $exo );
		$t   = IGE_FFM_DB::t( 'arrete' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ar = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(CASE WHEN type = 'mensuel' AND statut = 'cloture' THEN 1 ELSE 0 END) AS mensuels, SUM(CASE WHEN type = 'inopine' THEN 1 ELSE 0 END) AS inopines, SUM(CASE WHEN ecart <> 0 AND statut IN ('cloture','constate','contresigne') THEN 1 ELSE 0 END) AS ecarts FROM $t WHERE exercice_id = %d", $exo ), ARRAY_A );
		$avs = IGE_FFM_Avances::synthese( $exo );
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		ob_start();
		?>
		<div class="ige-subt">Exercice <?php echo esc_html( $ex['annee'] ); ?> · <?php echo esc_html( round( IGE_FFM_Consolidation::temps_ecoule( $ex ) ) ); ?> % de l’exercice écoulé · écritures validées</div>
		<h2>1. Exécution budgétaire</h2>
		<p><?php echo esc_html( sprintf( 'Budget total des services : %s. Consommé validé : %s (payé %s, engagé %s), soit %s du budget. Entrées validées : %s. Caisse consolidée : %s.', ige_ffm_montant( $c['total']['budget'] ), ige_ffm_montant( $c['total']['consomme'] ), ige_ffm_montant( $c['total']['paye'] ), ige_ffm_montant( $c['total']['engage'] ), null === $c['total']['taux'] ? '—' : number_format( $c['total']['taux'], 1, ',', '' ) . ' %', ige_ffm_montant( $c['total']['entrees'] ), ige_ffm_montant( $c['total']['caisse'] ) ) ); ?></p>
		<table class="ige-table ige-num">
			<thead><tr><th>Catégorie</th><th class="ige-r">Consommé</th><th class="ige-r">Part</th></tr></thead>
			<tbody>
			<?php foreach ( $c['categories'] as $k ) : ?>
				<?php if ( $k['consomme'] ) : ?><tr><td><?php echo esc_html( $k['libelle'] ); ?></td><td class="ige-r"><?php echo self::n( $k['consomme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $k['consomme'] / max( 1, $c['total']['consomme'] ) * 100 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr><?php endif; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
		<h2>2. Évolution mensuelle des dépenses validées</h2>
		<table class="ige-table ige-num">
			<thead><tr><?php foreach ( IGE_FFM_Ecran_Livre::mois_courts() as $mc ) : ?><th class="ige-r"><?php echo esc_html( $mc ); ?></th><?php endforeach; ?></tr></thead>
			<tbody><tr><?php foreach ( $c['mois'] as $v ) : ?><td class="ige-r"><?php echo esc_html( $v ? ige_ffm_montant( (int) round( $v / 1000 ), '' ) : '—' ); ?></td><?php endforeach; ?></tr></tbody>
		</table>
		<p class="ige-small">En milliers de francs congolais.</p>
		<h2>3. Contrôle interne</h2>
		<p><?php echo esc_html( sprintf( 'Vérification : %d écriture(s) validée(s), %d rejetée(s), %d en attente. Arrêtés de caisse mensuels signés : %d. Comptages inopinés : %d. Arrêtés ou comptages avec écart : %d. Avances remises : %s ; reste à justifier : %s.', $syn['validee']['n'], $syn['rejetee']['n'], $syn['a_verifier']['n'], (int) $ar['mensuels'], (int) $ar['inopines'], (int) $ar['ecarts'], ige_ffm_montant( array_sum( array_column( $avs, 'remis' ) ) ), ige_ffm_montant( array_sum( array_column( $avs, 'reliquat' ) ) ) ) ); ?></p>
		<h2>4. Exécution par service</h2>
		<table class="ige-table ige-num">
			<thead><tr><th>Service</th><th class="ige-r">Budget</th><th class="ige-r">Consommé</th><th class="ige-r">Exécution</th></tr></thead>
			<tbody>
			<?php foreach ( self::actifs( $c ) as $s ) : ?>
				<tr><td><?php echo esc_html( $s['service']['code'] ); ?></td><td class="ige-r"><?php echo $s['avec_budget'] ? self::n( $s['budget'] ) : 'Sans budget'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::n( $s['consomme'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><td class="ige-r"><?php echo self::p( $s['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		unset( $noms );
		return ob_get_clean();
	}

	/**
	 * Enregistre le procès-verbal d'un arrêté clôturé ou d'un comptage inopiné dans le registre.
	 *
	 * @param int $arrete_id Arrêté.
	 * @param int $user_id   Auteur.
	 * @return int|WP_Error
	 */
	public static function enregistrer_pv( $arrete_id, $user_id ) {
		$a = IGE_FFM_Arretes::get( $arrete_id );
		if ( ! $a ) {
			return new WP_Error( 'arrete', 'Arrêté introuvable.' );
		}
		$type = 'mensuel' === $a['type'] ? 'pv_arrete' : 'pv_inopine';
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		return IGE_FFM_Documents::emettre(
			array(
				'type'        => $type,
				'titre'       => ( 'pv_arrete' === $type ? 'Procès-verbal d’arrêté de caisse' : 'Procès-verbal de comptage inopiné' ) . ' · ' . IGE_FFM_Comptes::service_code( (int) $a['service_id'] ),
				'periode'     => 'pv_arrete' === $type ? strtolower( $noms[ (int) $a['mois'] ] ) : wp_date( 'd/m/Y', strtotime( $a['created_at'] ) ),
				'contenu'     => wp_json_encode( array_intersect_key( $a, array_flip( array( 'id', 'service_id', 'mois', 'type', 'solde_theorique', 'total_compte', 'ecart', 'explication', 'billetage', 'signe_agent_par', 'signe_agent_le', 'signe_resp_par', 'signe_resp_le' ) ) ) ),
				'exercice_id' => (int) $a['exercice_id'],
				'service_id'  => (int) $a['service_id'],
				'objet_id'    => (int) $a['id'],
			),
			$user_id
		);
	}
}
