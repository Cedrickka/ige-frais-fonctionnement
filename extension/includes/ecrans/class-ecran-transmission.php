<?php
/**
 * Écran Transmission (service) : lot à transmettre, récapitulatif, rejets à corriger, lots précédents.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transmission au central.
 */
final class IGE_FFM_Ecran_Transmission {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_transmettre', array( __CLASS__, 'handle_transmettre' ) );
		add_action( 'admin_post_ige_ffm_remettre', array( __CLASS__, 'handle_remettre' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$sid              = IGE_FFM_Contexte::service_courant();
		$args['title']    = 'Transmettre au central';
		$args['subtitle'] = ( $sid ? IGE_FFM_Comptes::service_code( $sid ) . ' · ' : '' ) . 'les écritures transmises passent en vérification chez Administration et Finance';
		$args['actions']  = $sid ? '<a class="ige-btn ige-btn-ghost" href="' . esc_url( IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse' ), $sid ) ) . '">Livre de caisse</a>' : '';
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
		$exo     = (int) $ex['id'];
		$peut    = IGE_FFM_Contexte::peut_saisir( $sid );
		$rows    = IGE_FFM_Transmission::a_transmettre( $exo, $sid );
		$anom    = $rows ? IGE_FFM_Anomalies::pour_service( $exo, $sid, wp_list_pluck( $rows, 'id' ) ) : array();
		$rejets  = IGE_FFM_Transmission::rejets_a_traiter( $exo, $sid );
		$lots    = IGE_FFM_Transmission::lots( $exo, $sid, 12 );
		$rubs    = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
		$pcs     = IGE_FFM_Pieces::compter_ecritures( wp_list_pluck( $rows, 'id' ) );
		$livre   = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse' ), $sid );
		$cp_obl  = (bool) IGE_FFM_Reglages::get( 'contrepassation_rejet' );
		$ent     = 0;
		$sor     = 0;
		$maj     = 0;
		$n_pcs   = 0;
		$n_sort  = 0;
		$n_anom  = 0;

		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $rejets ) {
			$txt = $cp_obl ? ' Ouvrez chaque écriture, contrepassez-la avec un motif, saisissez l’écriture juste puis transmettez de nouveau.' : ' Corrigez (par exemple en ajoutant la pièce manquante) puis renvoyez-la, ou contrepassez-la.';
			echo ige_ffm_banner( 'ko', count( $rejets ) . ' écriture(s) rejetée(s) à corriger', $txt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
			<section class="ige-card">
				<h2>Rejets à corriger</h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Écriture</th><th>Date</th><th>Description</th><th class="ige-r">Montant</th><th>Motif du rejet</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $rejets as $e ) : ?>
						<tr>
							<td><b><?php echo esc_html( $e['id_ecriture'] ); ?></b></td>
							<td><?php echo esc_html( wp_date( 'd/m', strtotime( $e['date_operation'] ) ) ); ?></td>
							<td><?php echo esc_html( $e['description'] ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $e['montant'], '' ) ); ?></td>
							<td><span class="ige-anom"><?php echo esc_html( $e['motif_rejet'] ); ?></span></td>
							<td>
								<div class="ige-row" style="gap:6px;flex-wrap:nowrap">
									<a class="ige-act" href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $livre ) . '#ige-detail' ); ?>"><?php echo $peut ? 'Corriger' : 'Voir'; ?></a>
									<?php
									if ( $peut && ! $cp_obl ) {
										echo IGE_FFM_Ecrans::action_button( 'remettre', array( 'ecriture' => $e['id'] ), 'Renvoyer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									}
									?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</section>
			<?php
		}
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<h2>Écritures prêtes à transmettre <span class="ige-sub"><?php echo esc_html( count( $rows ) . ' ligne' . ( count( $rows ) > 1 ? 's' : '' ) ); ?></span></h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>N°</th><th>Date</th><th>Rubrique</th><th>Description</th><th class="ige-r">Entrée</th><th class="ige-r">Sortie</th><th>Envoi</th><th>Contrôles</th></tr></thead>
					<tbody>
					<?php
					foreach ( $rows as $e ) :
						$m      = (int) $e['montant'];
						$is_maj = 'a_retransmettre' === $e['etat_transmission'];
						$a      = $anom[ (int) $e['id'] ] ?? array();
						if ( 'entree' === $e['type'] ) {
							$ent += $m;
						} else {
							$sor += $m;
							if ( ! $e['ecriture_origine_id'] ) {
								++$n_sort;
								$n_pcs += empty( $pcs[ $e['id'] ] ) ? 0 : 1;
							}
						}
						$maj    += $is_maj ? 1 : 0;
						$n_anom += $a ? 1 : 0;
						?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $livre ) ); ?>"><?php echo esc_html( sprintf( '%06d', $e['numero'] ) ); ?></a></td>
							<td><?php echo esc_html( wp_date( 'd/m', strtotime( $e['date_operation'] ) ) ); ?></td>
							<td><?php echo esc_html( $rubs[ $e['rubrique_id'] ] ?? '—' ); ?></td>
							<td><?php echo esc_html( $e['description'] ); ?><?php echo $is_maj && 'payee' === $e['statut'] && $e['date_paiement'] ? ' <span class="ige-small ige-muted">(payé le ' . esc_html( wp_date( 'd/m', strtotime( $e['date_paiement'] ) ) ) . ')</span>' : ''; ?></td>
							<td class="ige-r"><?php echo 'entree' === $e['type'] ? esc_html( ige_ffm_montant( $m, '' ) ) : ''; ?></td>
							<td class="ige-r"><?php echo 'sortie' === $e['type'] ? esc_html( ige_ffm_montant( $m, '' ) ) : ''; ?></td>
							<td><?php echo $is_maj ? ige_ffm_pill( 'Mise à jour', 'info' ) : ige_ffm_pill( 'Nouvelle', 'neutre' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo $a ? IGE_FFM_Anomalies::pastilles( $a ) : '<span class="ige-small ige-muted">—</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="8" class="ige-empty">Tout est transmis. Les nouvelles écritures apparaîtront ici.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
				<?php if ( $n_anom ) : ?>
					<p class="ige-small ige-muted" style="margin:12px 0 0"><?php echo esc_html( $n_anom ); ?> ligne(s) signalée(s) : le central les examinera de près. Vous pouvez transmettre quand même, ou corriger d’abord (ajouter une pièce, contrepasser une erreur).</p>
				<?php endif; ?>
			</section>

			<div class="ige-grid">
				<section class="ige-card">
					<h2>Récapitulatif du lot</h2>
					<dl class="ige-kv ige-num" style="grid-template-columns:1fr auto">
						<dt>Lignes</dt><dd class="ige-right"><?php echo esc_html( count( $rows ) . ( $maj ? ' (' . ( count( $rows ) - $maj ) . ' nouvelle(s), ' . $maj . ' mise(s) à jour)' : '' ) ); ?></dd>
						<dt>Total entrées</dt><dd class="ige-right"><?php echo esc_html( ige_ffm_montant( $ent ) ); ?></dd>
						<dt>Total sorties</dt><dd class="ige-right"><?php echo esc_html( ige_ffm_montant( $sor ) ); ?></dd>
						<dt>Pièces jointes</dt><dd class="ige-right"><?php echo esc_html( $n_pcs . ' sur ' . $n_sort ); ?></dd>
						<dt>Anomalies signalées</dt><dd class="ige-right"><?php echo esc_html( $n_anom ); ?></dd>
					</dl>
					<?php if ( $peut && $rows ) : ?>
						<?php echo IGE_FFM_Ecrans::form_open( 'transmettre', 'data-ige-confirm="Transmettre ' . esc_attr( count( $rows ) ) . ' écriture(s) au central ? Elles ne pourront plus être corrigées que par contrepassation."' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="nb" value="<?php echo esc_attr( count( $rows ) ); ?>">
							<button type="submit" class="ige-btn ige-btn-jaune ige-btn-block" style="margin-top:16px;padding:12px">Transmettre le lot</button>
						</form>
					<?php elseif ( ! $peut ) : ?>
						<p class="ige-small ige-muted" style="margin:12px 0 0">Lecture seule : la transmission est faite par le service.</p>
					<?php endif; ?>
					<?php if ( IGE_FFM_Reglages::get( 'transmission_auto' ) ) : ?>
						<p class="ige-small ige-muted" style="margin:12px 0 0">Transmission automatique activée : chaque écriture part au central dès son enregistrement.</p>
					<?php endif; ?>
				</section>
				<section class="ige-card">
					<h2>Lots précédents</h2>
					<?php if ( $lots ) : ?>
						<div class="ige-table-wrap"><table class="ige-table ige-num"><tbody>
						<?php foreach ( $lots as $l ) : ?>
							<?php list( $lbl, $tone ) = IGE_FFM_Transmission::etat_lot( $l ); ?>
							<tr>
								<td title="<?php echo esc_attr( $l['id_transmission'] ); ?>"><?php echo esc_html( preg_replace( '/^TR-.*-(\d+)$/', 'TR-…-$1', $l['id_transmission'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( 'd/m', strtotime( $l['envoye_le'] ) ) ); ?></td>
								<td class="ige-r"><?php echo esc_html( $l['nb_lignes'] ); ?> l.</td>
								<td><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table></div>
					<?php else : ?>
						<p class="ige-small ige-muted" style="margin:0">Aucun lot transmis sur l’exercice.</p>
					<?php endif; ?>
				</section>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Transmission du lot.
	 */
	public static function handle_transmettre() {
		ige_ffm_check_form( 'transmettre' );
		ige_ffm_require( 'ige_saisir' );
		$sid = IGE_FFM_Contexte::service_utilisateur();
		$res = IGE_FFM_Transmission::transmettre( $sid );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Transmission refusée', $res->get_error_message() );
		} else {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE id = %d', $res ), ARRAY_A );
			ige_ffm_flash( 'ok', 'Lot ' . $l['id_transmission'] . ' transmis', sprintf( '%d écriture(s) envoyée(s) à Administration et Finance pour vérification.', $l['nb_lignes'] ) );
		}
		wp_safe_redirect( ige_ffm_url( 'transmission' ) );
		exit;
	}

	/**
	 * Renvoi d'un rejet corrigé sans contrepassation.
	 */
	public static function handle_remettre() {
		ige_ffm_check_form( 'remettre' );
		ige_ffm_require( 'ige_saisir' );
		$res = IGE_FFM_Transmission::remettre( (int) ige_ffm_post( 'ecriture' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Renvoi refusé', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Écriture remise dans le prochain lot', 'Elle partira au central avec la prochaine transmission.' );
		}
		wp_safe_redirect( ige_ffm_url( 'transmission' ) );
		exit;
	}
}
