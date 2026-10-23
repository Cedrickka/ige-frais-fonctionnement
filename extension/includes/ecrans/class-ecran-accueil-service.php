<?php
/**
 * Écran Accueil du service.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accueil du service.
 */
final class IGE_FFM_Ecran_Accueil_Service {

	/**
	 * Pas de formulaire propre.
	 */
	public static function register() {}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$sid  = IGE_FFM_Contexte::service_courant();
		$ex   = IGE_FFM_Contexte::exercice();
		$user = wp_get_current_user();
		$args['title']    = $sid ? 'Service ' . IGE_FFM_Comptes::service_code( $sid ) : 'Accueil du service';
		$args['subtitle'] = 'Bonjour ' . $user->display_name . ' · ' . ucfirst( ige_ff_date_fr_safe() ) . ( $ex ? ' · Exercice ' . $ex['annee'] : '' );
		$args['actions']  = '';
		if ( $sid ) {
			$args['actions'] .= '<a class="ige-btn ige-btn-ghost" href="' . esc_url( IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse' ), $sid ) ) . '">Livre de caisse</a>';
		}
		if ( $sid && IGE_FFM_Contexte::peut_saisir( $sid ) ) {
			$args['actions'] .= '<a class="ige-btn ige-btn-jaune" href="' . esc_url( ige_ffm_url( 'nouvelle-ecriture' ) ) . '">+ Nouvelle écriture</a>';
		}
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ctx = IGE_FFM_Ecran_Service::contexte();
		$sid = $ctx['sid'];
		$ex  = IGE_FFM_Contexte::exercice();
		if ( ! $sid || ! $ex ) {
			return $ctx['html'] . ( $ex ? '' : ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' ) );
		}
		$exo    = (int) $ex['id'];
		$caisse = IGE_FFM_Budget::caisse( $exo, $sid );
		$suivi  = IGE_FFM_Budget::suivi( $exo, $sid );
		$tot    = $suivi['total'];
		$etats  = IGE_FFM_Ecritures::compter_etats( $exo, $sid );
		$a_trans = ( $etats['non_transmise'] ?? 0 ) + ( $etats['a_retransmettre'] ?? 0 );
		$alerte = (int) IGE_FFM_Reglages::get( 'alerte_rubrique' );
		$tension = array_filter( $suivi['lignes'], static fn( $l ) => null !== $l['taux'] && ( $l['taux'] >= $alerte || $l['disponible'] < 0 ) );
		$livre  = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse' ), $sid );
		$trans  = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'transmission' ), $sid );
		$rejets = IGE_FFM_Transmission::rejets_a_traiter( $exo, $sid );

		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $rejets ) {
			echo ige_ffm_banner( 'ko', count( $rejets ) . ' écriture(s) rejetée(s) par le central', ' <a href="' . esc_url( $trans ) . '">Voir les motifs et corriger</a>.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi ige-ok">
				<div class="ige-lbl">Solde de caisse</div>
				<div class="ige-val"><?php echo esc_html( ige_ffm_montant( $caisse, '' ) ); ?> <span>CDF</span></div>
				<div class="ige-meta">Solde initial <?php echo esc_html( ige_ffm_montant( IGE_FFM_Budget::solde_initial( $exo, $sid ) ) ); ?></div>
			</div>
			<div class="ige-card ige-kpi ige-accent">
				<div class="ige-lbl">Budget consommé</div>
				<div class="ige-val"><?php echo esc_html( ige_ffm_montant( $tot['engage'] + $tot['paye'], '' ) ); ?> <span>CDF</span></div>
				<?php if ( null !== $tot['taux'] ) : ?>
					<div class="ige-bar"><i style="width:<?php echo esc_attr( min( 100, round( $tot['taux'] ) ) ); ?>%"></i></div>
					<div class="ige-meta"><?php echo esc_html( round( $tot['taux'] ) . ' % de ' . ige_ffm_montant( $tot['budget'] ) . ' · disponible ' . ige_ffm_montant( $tot['disponible'], '' ) ); ?></div>
				<?php else : ?>
					<div class="ige-meta">Sans budget pour cet exercice : la consommation servira de base au budget suivant.</div>
				<?php endif; ?>
			</div>
			<div class="ige-card ige-kpi ige-alerte">
				<div class="ige-lbl">Engagements en cours</div>
				<div class="ige-val"><?php echo esc_html( ige_ffm_montant( $tot['engage'], '' ) ); ?> <span>CDF</span></div>
				<div class="ige-meta">Engagés, pas encore payés</div>
			</div>
			<div class="ige-card ige-kpi <?php echo $a_trans ? 'ige-risque' : 'ige-ok'; ?>">
				<div class="ige-lbl">À transmettre</div>
				<div class="ige-val"><?php echo esc_html( $a_trans ); ?> <span>écriture(s)</span></div>
				<div class="ige-meta"><a href="<?php echo esc_url( $trans ); ?>"><?php echo $a_trans ? 'Transmettre au central' : 'Voir les lots transmis'; ?></a><?php echo $rejets ? esc_html( ' · ' . count( $rejets ) . ' rejet(s) à corriger' ) : ''; ?></div>
			</div>
		</div>
		<div class="ige-grid ige-cols-21">
			<section class="ige-card">
				<h2>Dernières écritures <span class="ige-sub"><a href="<?php echo esc_url( $livre ); ?>">Tout le livre de caisse</a></span></h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>N°</th><th>Date</th><th>Rubrique</th><th>Description</th><th class="ige-r">Montant</th><th>État</th></tr></thead>
					<tbody>
					<?php
					$rubs = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
					foreach ( IGE_FFM_Ecritures::dernieres( $exo, $sid, 6 ) as $e ) :
						list( $lbl, $tone ) = IGE_FFM_Ecritures::etat( $e );
						$signe = 'entree' === $e['type'] ? '+ ' : '';
						?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $livre ) ); ?>"><?php echo esc_html( sprintf( '%06d', $e['numero'] ) ); ?></a></td>
							<td><?php echo esc_html( wp_date( 'd/m', strtotime( $e['date_operation'] ) ) ); ?></td>
							<td><?php echo esc_html( $rubs[ $e['rubrique_id'] ] ?? '—' ); ?></td>
							<td><?php echo esc_html( $e['description'] ); ?><?php echo 'engagee' === $e['statut'] ? ' ' . ige_ffm_pill( 'Engagée', 'info' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="ige-r"<?php echo 'entree' === $e['type'] ? ' style="color:var(--ige-ok-txt)"' : ''; ?>><?php echo esc_html( $signe . ige_ffm_montant( (int) $e['montant'], '' ) ); ?></td>
							<td><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! IGE_FFM_Ecritures::dernieres( $exo, $sid, 1 ) ) : ?>
						<tr><td colspan="6" class="ige-empty">Aucune écriture pour le moment.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
			</section>
			<section class="ige-card">
				<h2>Rubriques sous tension</h2>
				<?php if ( $tension ) : ?>
					<ul class="ige-alert-list">
					<?php foreach ( $tension as $l ) : ?>
						<li><span class="ige-dot" style="background:<?php echo esc_attr( $l['disponible'] < 0 || $l['taux'] > 100 ? 'var(--ige-rouge)' : 'var(--ige-orange)' ); ?>"></span><div><b><?php echo esc_html( $l['rubrique']['code'] . ' ' . $l['rubrique']['libelle'] ); ?></b> : <?php echo esc_html( round( $l['taux'] ) . ' % consommés, ' . ige_ffm_montant( max( 0, $l['disponible'] ) ) . ' disponibles.' ); ?></div></li>
					<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="ige-muted ige-small" style="margin:0">Aucune rubrique au-dessus de <?php echo esc_html( $alerte ); ?> % de son budget.</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}
}

/**
 * Date du jour en toutes lettres (fonction du thème si présent).
 *
 * @return string
 */
function ige_ff_date_fr_safe() {
	return function_exists( 'ige_ff_date_fr' ) ? ige_ff_date_fr() : wp_date( 'd/m/Y' );
}
