<?php
/**
 * Écran Budget du service : prévisions par rubrique (Responsable), solde initial,
 * suivi de l'exécution, révisions motivées.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Budget.
 */
final class IGE_FFM_Ecran_Budget {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_budget', array( __CLASS__, 'handle_budget' ) );
		add_action( 'admin_post_ige_ffm_solde_initial', array( __CLASS__, 'handle_solde' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$sid = IGE_FFM_Contexte::service_courant();
		$ex  = IGE_FFM_Contexte::exercice();
		$args['title']    = 'Budget du service';
		$args['subtitle'] = $sid && $ex ? IGE_FFM_Comptes::service_code( $sid ) . ' · exercice ' . $ex['annee'] . ( (int) $ex['budget_obligatoire'] ? ' · budget obligatoire' : ' · budget facultatif cette année' ) : '';
		$args['actions']  = '';
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
			return $ctx['html'];
		}
		$exo      = (int) $ex['id'];
		$suivi    = IGE_FFM_Budget::suivi( $exo, $sid );
		$tot      = $suivi['total'];
		$peut     = IGE_FFM_Contexte::peut_budgeter( $sid );
		$edit     = $peut && isset( $_GET['modifier'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$revision = IGE_FFM_Budget::a_des_ecritures( $exo, $sid );
		$old      = IGE_FFM_Ecrans::old();
		$mensuel  = IGE_FFM_Budget::mensuel( $exo, $sid );
		$base     = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'budget' ), $sid );

		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( ! (int) $ex['budget_obligatoire'] && ! $tot['avec_budget'] ) {
			echo ige_ffm_banner( 'info', 'Exercice ' . esc_html( $ex['annee'] ) . ' sans budget obligatoire', ' Le service peut saisir ses dépenses sans prévisions. La consommation par rubrique ci-dessous servira de base au budget de l’exercice suivant.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi ige-accent"><div class="ige-lbl">Budget annuel</div><div class="ige-val"><?php echo $tot['avec_budget'] ? esc_html( ige_ffm_montant( $tot['budget'], '' ) ) . ' <span>CDF</span>' : '—'; ?></div><div class="ige-meta"><?php echo $tot['avec_budget'] ? 'Rubriques budgétées seulement' : 'Pas encore de prévisions'; ?></div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Consommé</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $tot['engage'] + $tot['paye'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo esc_html( 'dont engagé ' . ige_ffm_montant( $tot['engage'] ) ); ?></div></div>
			<div class="ige-card ige-kpi ige-ok"><div class="ige-lbl">Disponible</div><div class="ige-val"><?php echo $tot['avec_budget'] ? esc_html( ige_ffm_montant( $tot['disponible'], '' ) ) . ' <span>CDF</span>' : '—'; ?></div><div class="ige-meta"><?php echo null !== $tot['taux'] ? esc_html( round( $tot['taux'] ) . ' % consommés' ) : '&nbsp;'; ?></div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Solde initial de caisse</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( IGE_FFM_Budget::solde_initial( $exo, $sid ), '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo $revision ? 'Verrouillé (écritures saisies)' : 'Report de l’exercice précédent'; ?></div></div>
		</div>

		<section class="ige-card">
			<h2>Exécution par rubrique
				<span class="ige-sub">
					<?php if ( $peut && ! $edit ) : ?>
						<a class="ige-btn ige-btn-bleu" href="<?php echo esc_url( add_query_arg( 'modifier', 1, $base ) ); ?>"><?php echo $tot['avec_budget'] ? ( $revision ? 'Réviser le budget' : 'Modifier le budget' ) : 'Saisir le budget'; ?></a>
					<?php elseif ( ! $peut ) : ?>
						Lecture seule · le budget est saisi par le Responsable du service
					<?php endif; ?>
				</span>
			</h2>
			<?php if ( $edit ) : ?>
				<?php echo IGE_FFM_Ecrans::form_open( 'budget' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="ige-small ige-muted" style="margin-top:0">Laissez vide une rubrique sans budget. <?php echo $revision ? 'Le service a déjà des écritures : chaque changement est une révision, conservée avec son motif.' : ''; ?> Un budget ne peut pas être inférieur à ce qui est déjà consommé.</p>
			<?php endif; ?>
			<div class="ige-table-wrap"><table class="ige-table ige-num">
				<thead><tr><th>Rubrique</th><th class="ige-r">Budget</th><th class="ige-r">Engagé</th><th class="ige-r">Payé</th><th class="ige-r">Disponible</th><th style="min-width:150px">Exécution</th></tr></thead>
				<tbody>
				<?php foreach ( $suivi['lignes'] as $l ) : ?>
					<?php $rid = (int) $l['rubrique']['id']; ?>
					<tr>
						<td><b><?php echo esc_html( $l['rubrique']['code'] ); ?></b> <?php echo esc_html( $l['rubrique']['libelle'] ); ?></td>
						<td class="ige-r">
							<?php if ( $edit ) : ?>
								<?php $val = isset( $old['budget'][ $rid ] ) ? $old['budget'][ $rid ] : ( null === $l['budget'] ? '' : $l['budget'] ); ?>
								<label class="screen-reader-text" for="ige-b-<?php echo esc_attr( $rid ); ?>"><?php echo esc_html( 'Budget ' . $l['rubrique']['code'] ); ?></label>
								<input type="text" inputmode="numeric" class="ige-input ige-num" style="width:150px;text-align:right;padding:7px 9px" id="ige-b-<?php echo esc_attr( $rid ); ?>" name="budget[<?php echo esc_attr( $rid ); ?>]" value="<?php echo esc_attr( $val ); ?>" placeholder="—">
							<?php else : ?>
								<?php echo null === $l['budget'] ? '<span class="ige-muted">—</span>' : esc_html( ige_ffm_montant( $l['budget'], '' ) ); ?>
							<?php endif; ?>
						</td>
						<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['engage'], '' ) ); ?></td>
						<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['paye'], '' ) ); ?></td>
						<td class="ige-r"><?php echo null === $l['disponible'] ? '<span class="ige-muted">—</span>' : esc_html( ige_ffm_montant( $l['disponible'], '' ) ); ?></td>
						<td><?php echo IGE_FFM_Ecran_Service::barre( $l['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="ige-total"><td>Total</td><td class="ige-r"><?php echo $tot['avec_budget'] ? esc_html( ige_ffm_montant( $tot['budget'], '' ) ) : '—'; ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $tot['engage'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $tot['paye'], '' ) ); ?></td><td class="ige-r"><?php echo $tot['avec_budget'] ? esc_html( ige_ffm_montant( $tot['disponible'], '' ) ) : '—'; ?></td><td><?php echo IGE_FFM_Ecran_Service::barre( $tot['taux'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
				</tbody>
			</table></div>
			<?php if ( $edit ) : ?>
				<div class="ige-row" style="margin-top:14px;align-items:flex-end">
					<?php if ( $revision ) : ?>
						<div style="flex:1;min-width:240px"><?php echo IGE_FFM_Ecrans::field( 'motif', 'Motif de la révision', $old['motif'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="200" placeholder="Ex. : mission supplémentaire décidée par l’Inspecteur Général"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
					<div class="ige-row" style="margin-bottom:16px">
						<button type="submit" class="ige-btn ige-btn-bleu">Enregistrer le budget</button>
						<a class="ige-btn ige-btn-line" href="<?php echo esc_url( $base ); ?>">Annuler</a>
					</div>
				</div>
				</form>
			<?php endif; ?>
		</section>

		<div class="ige-grid ige-cols-2 ige-start">
			<section class="ige-card">
				<h2>Sorties payées par mois</h2>
				<?php echo self::histogramme( $mensuel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
			<section class="ige-card">
				<h2>Solde initial et révisions</h2>
				<?php if ( $peut && ! $revision ) : ?>
					<?php echo IGE_FFM_Ecrans::form_open( 'solde_initial', 'class="ige-row" style="align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div style="flex:1;min-width:200px"><?php echo IGE_FFM_Ecrans::field( 'solde_initial', 'Solde initial de caisse (CDF)', (string) IGE_FFM_Budget::solde_initial( $exo, $sid ), array( 'attrs' => 'inputmode="numeric" class="ige-num"', 'help' => 'Argent en caisse au 1er janvier. Verrouillé dès la première écriture.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
						<button type="submit" class="ige-btn ige-btn-line" style="margin-bottom:16px">Enregistrer</button>
					</form>
				<?php endif; ?>
				<?php $revs = self::revisions( $exo, $sid ); ?>
				<?php if ( $revs ) : ?>
					<div class="ige-table-wrap"><table class="ige-table ige-num">
						<thead><tr><th>Date</th><th>Rubrique</th><th class="ige-r">Avant</th><th class="ige-r">Après</th><th>Motif</th></tr></thead>
						<tbody>
						<?php foreach ( $revs as $r ) : ?>
							<tr><td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $r['created_at'] ) ) ); ?></td><td><?php echo esc_html( $r['code'] ?: '—' ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $r['ancien_montant'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $r['nouveau_montant'], '' ) ); ?></td><td><?php echo esc_html( $r['motif'] ); ?></td></tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php else : ?>
					<p class="ige-small ige-muted" style="margin:0">Aucune révision budgétaire sur l’exercice.</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Histogramme simple des sorties payées par mois.
	 *
	 * @param array<int,int> $mensuel Montants.
	 * @return string
	 */
	private static function histogramme( array $mensuel ) {
		if ( ! array_sum( $mensuel ) ) {
			return '<p class="ige-small ige-muted" style="margin:0">Aucune sortie payée pour le moment.</p>';
		}
		$max = max( 1, max( $mensuel ) );
		$ini = array( 1 => 'J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D' );
		$h   = '<div class="ige-hist" role="img" aria-label="Sorties payées par mois">';
		foreach ( $mensuel as $m => $v ) {
			$h .= '<div class="ige-hist__col" title="' . esc_attr( IGE_FFM_Ecran_Livre::mois_noms()[ $m ] . ' : ' . ige_ffm_montant( $v ) ) . '"><div class="ige-hist__bar" style="height:' . esc_attr( round( $v / $max * 100 ) ) . '%"></div><span>' . esc_html( $ini[ $m ] ) . '</span></div>';
		}
		return $h . '</div><p class="ige-small ige-muted" style="margin:8px 0 0">Plus haut mois : ' . esc_html( ige_ffm_montant( max( $mensuel ) ) ) . '. Survolez une barre pour le montant.</p>';
	}

	/**
	 * Révisions budgétaires du service.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array[]
	 */
	private static function revisions( $exo, $sid ) {
		global $wpdb;
		$rv = IGE_FFM_DB::t( 'revision_budget' );
		$r  = IGE_FFM_DB::t( 'rubrique' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT rv.*, r.code FROM $rv rv LEFT JOIN $r r ON r.id = rv.rubrique_id WHERE rv.exercice_id = %d AND rv.service_id = %d ORDER BY rv.id DESC LIMIT 30", $exo, $sid ), ARRAY_A );
	}

	/**
	 * Enregistrement du budget.
	 */
	public static function handle_budget() {
		ige_ffm_check_form( 'budget' );
		ige_ffm_require( 'ige_gerer_budget' );
		$sid = IGE_FFM_Contexte::service_utilisateur();
		$ex  = IGE_FFM_Contexte::exercice();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw     = isset( $_POST['budget'] ) && is_array( $_POST['budget'] ) ? wp_unslash( $_POST['budget'] ) : array();
		$saisies = array();
		foreach ( $raw as $rid => $v ) {
			$saisies[ (int) $rid ] = sanitize_text_field( (string) $v );
		}
		$motif = ige_ffm_post( 'motif' );
		$res   = $ex ? IGE_FFM_Budget::enregistrer( (int) $ex['id'], $sid, $saisies, $motif, get_current_user_id() ) : new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		if ( is_wp_error( $res ) ) {
			IGE_FFM_Ecrans::remember( array( 'budget' => $saisies, 'motif' => $motif ) );
			ige_ffm_flash( 'ko', 'Budget non enregistré', $res->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'budget', array( 'modifier' => 1 ) ) );
			exit;
		}
		ige_ffm_flash( $res ? 'ok' : 'info', $res ? 'Budget enregistré' : 'Aucun changement', $res ? sprintf( '%d rubrique(s) mise(s) à jour.', $res ) : '' );
		wp_safe_redirect( ige_ffm_url( 'budget' ) );
		exit;
	}

	/**
	 * Enregistrement du solde initial.
	 */
	public static function handle_solde() {
		ige_ffm_check_form( 'solde_initial' );
		ige_ffm_require( 'ige_gerer_budget' );
		$sid = IGE_FFM_Contexte::service_utilisateur();
		$ex  = IGE_FFM_Contexte::exercice();
		$res = $ex ? IGE_FFM_Budget::enregistrer_solde_initial( (int) $ex['id'], $sid, ige_ffm_post( 'solde_initial' ), get_current_user_id() ) : new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Solde initial non enregistré', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Solde initial enregistré', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'budget' ) );
		exit;
	}
}
