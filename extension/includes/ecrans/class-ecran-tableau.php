<?php
/**
 * Écran Tableau de bord central (EF-72, EF-73, EF-74) et Synthèse de l'Inspecteur Général.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tableau de bord.
 */
final class IGE_FFM_Ecran_Tableau {

	/**
	 * Pas de formulaire.
	 */
	public static function register() {}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$ex = IGE_FFM_Contexte::exercice();
		$args['title']    = 'Tableau de bord' . ( $ex ? ' — Exercice ' . $ex['annee'] : '' );
		$args['subtitle'] = $ex ? sprintf( 'Écritures validées au %s · %d %% de l’exercice écoulé', ige_ff_date_fr_safe(), round( IGE_FFM_Consolidation::temps_ecoule( $ex ) ) ) : '';
		$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_conso_export&vue=services' ), 'ige_ffm_conso_export' ) ) . '">Exporter (Excel)</a>';
		if ( current_user_can( 'ige_rapports' ) ) {
			$args['actions'] .= '<a class="ige-btn ige-btn-jaune" href="' . esc_url( ige_ffm_url( 'rapports' ) ) . '">Rapports officiels</a>';
		}
		return $args;
	}

	/**
	 * Barres d'exécution par service, avec le repère du temps écoulé.
	 *
	 * @param array $services Services consolidés.
	 * @param float $tps      Temps écoulé (%).
	 * @param int   $max      Nombre de lignes.
	 * @return string
	 */
	public static function execution( array $services, $tps, $max = 12 ) {
		$services = array_filter( $services, static fn( $s ) => $s['budget'] || $s['consomme'] );
		if ( ! $services ) {
			return '<p class="ige-small ige-muted" style="margin:0">Aucun service n’a encore de budget ni de dépense validée.</p>';
		}
		uasort( $services, static fn( $a, $b ) => ( $b['taux'] ?? -1 ) <=> ( $a['taux'] ?? -1 ) );
		$h = '<div class="ige-exec ige-num">';
		$i = 0;
		foreach ( $services as $s ) {
			if ( ++$i > $max ) {
				break;
			}
			$h .= '<span>' . esc_html( $s['service']['code'] ) . '</span>';
			if ( null === $s['taux'] ) {
				$h .= '<div class="ige-track"><div class="ige-temps" style="left:' . esc_attr( round( $tps ) ) . '%"></div></div><span class="ige-small ige-muted">Sans budget</span>';
				continue;
			}
			$cls = $s['taux'] > 100 ? ' ige-depasse' : ( $s['taux'] > $tps + 5 ? ' ige-haut' : '' );
			$h  .= '<div class="ige-track"><div class="ige-fill' . $cls . '" style="width:' . esc_attr( min( 100, round( $s['taux'], 1 ) ) ) . '%"></div><div class="ige-temps" style="left:' . esc_attr( round( $tps ) ) . '%"></div></div>';
			$h  .= '<span' . ( $s['taux'] > 100 ? ' style="color:var(--ige-rouge);font-weight:700"' : '' ) . '>' . esc_html( round( $s['taux'] ) ) . '&nbsp;%</span>';
		}
		$h .= '</div><div class="ige-legend"><span><i style="background:var(--ige-bleu)"></i>Dans le rythme</span><span><i style="background:var(--ige-orange)"></i>En avance sur le temps écoulé</span><span><i style="background:var(--ige-rouge)"></i>Budget dépassé</span><span><i style="border:2px dashed var(--ige-bleu-inst);background:none;width:0;height:12px;border-radius:0"></i>' . esc_html( round( $tps ) ) . ' % de l’exercice écoulé</span></div>';
		if ( count( $services ) > $max ) {
			$h .= '<p class="ige-small ige-muted" style="margin:10px 0 0">' . esc_html( $max . ' services sur ' . count( $services ) . ' affichés' ) . ' · <a href="' . esc_url( ige_ffm_url( 'consolidation' ) ) . '">voir tous les services</a></p>';
		}
		return $h;
	}

	/**
	 * Liste d'alertes.
	 *
	 * @param array $alertes Alertes.
	 * @param int   $max     Nombre.
	 * @return string
	 */
	public static function alertes( array $alertes, $max = 8 ) {
		if ( ! $alertes ) {
			return '<p class="ige-small ige-muted" style="margin:0">Aucune alerte : budgets, avances, transmissions et arrêtés sont dans les règles.</p>';
		}
		$col = array( 'ko' => 'var(--ige-rouge)', 'warn' => 'var(--ige-orange)', 'info' => 'var(--ige-bleu)' );
		$h   = '<ul class="ige-alert-list">';
		foreach ( array_slice( $alertes, 0, $max ) as $a ) {
			$h .= '<li><span class="ige-dot" style="background:' . $col[ $a[0] ] . '"></span><div>' . esc_html( $a[1] ) . ( $a[2] ? ' <a href="' . esc_url( $a[2] ) . '">Voir</a>' : '' ) . '</div></li>';
		}
		$h .= '</ul>';
		if ( count( $alertes ) > $max ) {
			$h .= '<p class="ige-small ige-muted" style="margin:8px 0 0">' . esc_html( ( count( $alertes ) - $max ) . ' autre(s) alerte(s).' ) . '</p>';
		}
		return $h;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$exo  = (int) $ex['id'];
		$c    = IGE_FFM_Consolidation::calculer( $exo );
		$tot  = $c['total'];
		$tps  = IGE_FFM_Consolidation::temps_ecoule( $ex );
		$syn  = IGE_FFM_Verification::synthese( $exo );
		$avs  = IGE_FFM_Avances::synthese( $exo );
		$tr   = array( 'moins30' => 0, 'de30a60' => 0, 'de60a90' => 0, 'plus90' => 0 );
		foreach ( $avs as $l ) {
			foreach ( $tr as $k => $v ) {
				$tr[ $k ] += $l['tranches'][ $k ];
			}
		}
		$reliq = array_sum( $tr );
		$nb_r  = count( array_filter( $avs, static fn( $l ) => $l['reliquat'] > 0 ) );
		$trans = count( array_filter( $c['services'], static fn( $s ) => $s['dernier_lot'] && strtotime( $s['dernier_lot'] ) >= strtotime( wp_date( 'Y-m-01' ) ) ) );
		$noms  = IGE_FFM_Ecran_Livre::mois_courts();

		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi ige-accent">
				<div class="ige-lbl">Budget consommé</div>
				<div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['consomme'], '' ) ); ?> <span>CDF</span></div>
				<?php if ( null !== $tot['taux'] ) : ?>
					<div class="ige-bar"><i style="width:<?php echo esc_attr( min( 100, round( $tot['taux'], 1 ) ) ); ?>%"></i></div>
					<div class="ige-meta ige-num"><?php echo esc_html( number_format( $tot['taux'], 1, ',', '' ) . ' % de ' . ige_ffm_montant( $tot['budget'] ) . ' · disponible ' . ige_ffm_montant( $tot['disponible'], '' ) ); ?></div>
				<?php else : ?>
					<div class="ige-meta">Pas encore de budget saisi par les services</div>
				<?php endif; ?>
			</div>
			<div class="ige-card ige-kpi ige-ok"><div class="ige-lbl">Solde de caisse consolidé</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['caisse'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo esc_html( 'Entrées validées : ' . ige_ffm_montant( $tot['entrees'] ) ); ?></div></div>
			<div class="ige-card ige-kpi ige-risque"><div class="ige-lbl">Avances à justifier</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $reliq, '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo esc_html( $nb_r . ' service(s)' . ( $tr['plus90'] ? ' · dont ' . ige_ffm_montant( $tr['plus90'] ) . ' depuis plus de 90 jours' : '' ) ); ?></div></div>
			<div class="ige-card ige-kpi ige-alerte"><div class="ige-lbl">À vérifier</div><div class="ige-val ige-num"><?php echo esc_html( $syn['a_verifier']['n'] ); ?> <span>écritures</span></div><div class="ige-meta"><?php echo esc_html( $syn['anomalies']['n'] . ' avec anomalie · ' . $trans . ' services sur ' . $tot['nb_services'] . ' ont transmis ce mois' ); ?></div></div>
		</div>
		<div class="ige-grid" style="grid-template-columns:minmax(0,1.35fr) minmax(0,1fr)">
			<section class="ige-card">
				<h2>Exécution budgétaire par service <span class="ige-sub">consommé / budget annuel</span></h2>
				<?php echo self::execution( $c['services'], $tps ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
			<section class="ige-card">
				<h2>Alertes prioritaires</h2>
				<?php echo self::alertes( IGE_FFM_Consolidation::alertes( $exo ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		</div>
		<div class="ige-grid ige-cols-2 ige-start">
			<section class="ige-card">
				<h2>Avances à justifier par ancienneté <span class="ige-sub"><?php echo esc_html( ige_ffm_montant( $reliq ) ); ?></span></h2>
				<?php if ( $reliq ) : ?>
					<div class="ige-age ige-num">
						<?php
						$cols = array( 'moins30' => array( 'var(--ige-bleu)', '0–30 j' ), 'de30a60' => array( '#0f6f9a', '31–60 j' ), 'de60a90' => array( 'var(--ige-orange)', '61–90 j' ), 'plus90' => array( 'var(--ige-rouge)', '+90 j' ) );
						foreach ( $cols as $k => $cv ) {
							if ( $tr[ $k ] ) {
								echo '<span style="background:' . esc_attr( $cv[0] ) . ';width:' . esc_attr( round( $tr[ $k ] / $reliq * 100, 1 ) ) . '%" title="' . esc_attr( ige_ffm_montant( $tr[ $k ] ) ) . '">' . esc_html( $cv[1] ) . '</span>';
							}
						}
						?>
					</div>
					<div class="ige-table-wrap"><table class="ige-table ige-num" style="margin-top:14px">
						<thead><tr><th>Service</th><th class="ige-r">Remis</th><th class="ige-r">Justifié</th><th class="ige-r">Reste</th><th>Plus ancien</th></tr></thead>
						<tbody>
						<?php
						uasort( $avs, static fn( $a, $b ) => $b['plus_ancien'] <=> $a['plus_ancien'] );
						foreach ( array_slice( array_filter( $avs, static fn( $l ) => $l['reliquat'] > 0 ), 0, 6, true ) as $id => $l ) :
							?>
							<tr><td><a href="<?php echo esc_url( add_query_arg( 'service', $id, ige_ffm_url( 'avances' ) ) ); ?>"><?php echo esc_html( $l['service']['code'] ); ?></a></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['remis'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['justifie'], '' ) ); ?></td><td class="ige-r"><b><?php echo esc_html( ige_ffm_montant( $l['reliquat'], '' ) ); ?></b></td><td><?php echo ige_ffm_pill( $l['plus_ancien'] . ' j', $l['plus_ancien'] >= 90 ? 'ko' : ( $l['plus_ancien'] >= 60 ? 'wait' : 'info' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php else : ?>
					<p class="ige-small ige-muted" style="margin:0">Aucune avance en attente de justification.</p>
				<?php endif; ?>
			</section>
			<section class="ige-card">
				<h2>Consommation par catégorie <span class="ige-sub">payé + engagé, CDF</span></h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Catégorie</th><th class="ige-r">Consommé</th><th style="width:38%">Part</th></tr></thead>
					<tbody>
					<?php foreach ( $c['categories'] as $k ) : ?>
						<?php if ( $k['consomme'] ) : ?>
							<tr><td><?php echo esc_html( $k['libelle'] ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $k['consomme'], '' ) ); ?></td><td><div class="ige-bar" style="margin:0"><i style="width:<?php echo esc_attr( round( $k['consomme'] / max( 1, $tot['consomme'] ) * 100, 1 ) ); ?>%"></i></div></td></tr>
						<?php endif; ?>
					<?php endforeach; ?>
					<?php if ( ! $tot['consomme'] ) : ?>
						<tr><td colspan="3" class="ige-empty">Aucune dépense validée pour le moment.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
				<h2 style="margin-top:20px">Dépenses validées par mois</h2>
				<?php echo self::mensuel( $c['mois'], $noms ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Histogramme mensuel.
	 *
	 * @param array<int,int>    $mois Montants.
	 * @param array<int,string> $noms Noms courts.
	 * @return string
	 */
	public static function mensuel( array $mois, array $noms ) {
		if ( ! array_sum( $mois ) ) {
			return '<p class="ige-small ige-muted" style="margin:0">Aucune dépense validée pour le moment.</p>';
		}
		$max = max( 1, max( $mois ) );
		$h   = '<div class="ige-hist" role="img" aria-label="Dépenses validées par mois">';
		foreach ( $mois as $m => $v ) {
			$h .= '<div class="ige-hist__col" title="' . esc_attr( $noms[ $m ] . ' : ' . ige_ffm_montant( (int) $v ) ) . '"><div class="ige-hist__bar" style="height:' . esc_attr( round( max( 0, $v ) / $max * 100 ) ) . '%"></div><span>' . esc_html( mb_substr( $noms[ $m ], 0, 1 ) ) . '</span></div>';
		}
		return $h . '</div>';
	}
}

/**
 * Synthèse de l'Inspecteur Général (profil Direction).
 */
final class IGE_FFM_Ecran_Synthese {

	/**
	 * Pas de formulaire.
	 */
	public static function register() {}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$ex               = IGE_FFM_Contexte::exercice();
		$args['title']    = 'Synthèse de l’Inspecteur Général';
		$args['subtitle'] = $ex ? 'Exercice ' . $ex['annee'] . ' · au ' . ige_ff_date_fr_safe() : '';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$exo  = (int) $ex['id'];
		$c    = IGE_FFM_Consolidation::calculer( $exo );
		$tot  = $c['total'];
		$tps  = IGE_FFM_Consolidation::temps_ecoule( $ex );
		$avs  = IGE_FFM_Avances::synthese( $exo );
		$rel  = array_sum( array_column( $avs, 'reliquat' ) );
		$al   = array_values( array_filter( IGE_FFM_Consolidation::alertes( $exo ), static fn( $a ) => 'info' !== $a[0] || true ) );
		$cats = array_slice( array_filter( $c['categories'], static fn( $k ) => $k['consomme'] > 0 ), 0, 3 );
		$top  = $cats ? max( array_column( $cats, 'consomme' ) ) : 1;
		$docs = array_slice( array_filter( IGE_FFM_Documents::liste( 20 ), static fn( $d ) => 0 !== strpos( $d['type'], 'pv_' ) ), 0, 3 );
		$m    = static fn( $v ) => number_format( $v / 1000000, 1, ',', ' ' ) . ' M';

		ob_start();
		?>
		<div class="ige-card">
			<div class="ige-ig-kpi"><small>Budget consommé</small><b><?php echo null === $tot['taux'] ? '—' : esc_html( number_format( $tot['taux'], 1, ',', '' ) . ' %' ); ?></b> <span class="ige-small ige-muted"><?php echo $tot['budget'] ? esc_html( 'de ' . $m( $tot['budget'] ) . ' CDF' ) : 'pas de budget saisi'; ?></span><?php if ( null !== $tot['taux'] ) : ?><div class="ige-bar"><i style="width:<?php echo esc_attr( min( 100, round( $tot['taux'], 1 ) ) ); ?>%"></i></div><?php endif; ?><small style="margin-top:6px"><?php echo esc_html( round( $tps ) . ' % de l’exercice écoulé' ); ?></small></div>
			<div class="ige-soldes"><div><small>Caisse consolidée</small><b><?php echo esc_html( $m( $tot['caisse'] ) ); ?></b></div><div><small>Avances à justifier</small><b<?php echo $rel ? ' style="color:var(--ige-rouge)"' : ''; ?>><?php echo esc_html( $m( $rel ) ); ?></b></div></div>
			<div class="ige-ig-kpi"><small>Points d’attention</small>
				<?php echo IGE_FFM_Ecran_Tableau::alertes( $al, 4 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<?php if ( $cats ) : ?>
				<div class="ige-ig-kpi"><small>Principales catégories de dépense</small>
					<?php foreach ( $cats as $k ) : ?>
						<div class="ige-small" style="margin-top:6px"><?php echo esc_html( $k['libelle'] . ' · ' . $m( $k['consomme'] ) ); ?></div><div class="ige-bar" style="margin:4px 0 8px"><i style="width:<?php echo esc_attr( round( $k['consomme'] / $top * 100 ) ); ?>%"></i></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php if ( $docs ) : ?>
				<div class="ige-ig-kpi"><small>Derniers rapports officiels</small>
					<?php foreach ( $docs as $d ) : ?>
						<div class="ige-small" style="margin-top:6px"><a href="<?php echo esc_url( ige_ffm_url( 'rapports', array( 'doc' => $d['id'] ) ) ); ?>"><?php echo esc_html( $d['titre'] . ' · ' . $d['periode'] ); ?></a></div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<a class="ige-btn ige-btn-jaune ige-btn-block" href="<?php echo esc_url( ige_ffm_url( 'tableau-de-bord' ) ); ?>">Tableau de bord détaillé</a>
		</div>
		<?php
		return ob_get_clean();
	}
}
