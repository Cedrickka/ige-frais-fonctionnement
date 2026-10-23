<?php
/**
 * Écran Caisses (Administration et Finance) : soldes, arrêtés mensuels, écarts, comptages inopinés.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Caisses des services.
 */
final class IGE_FFM_Ecran_Caisses {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_inopine', array( __CLASS__, 'handle_inopine' ) );
	}

	/**
	 * Mois de référence : le mois précédent (janvier si l'exercice commence).
	 *
	 * @param array $ex Exercice.
	 * @return int
	 */
	private static function mois_ref( array $ex ) {
		$auj = IGE_FFM_Contexte::aujourdhui();
		if ( $auj > $ex['date_fin'] ) {
			return 12;
		}
		return max( 1, (int) substr( $auj, 5, 2 ) - 1 );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Caisses des services';
		$args['subtitle'] = 'Soldes, arrêtés mensuels et comptages inopinés';
		$args['actions']  = current_user_can( 'ige_gerer_fonds' ) ? '<a class="ige-btn ige-btn-jaune" href="#ige-inopine">Déclencher un comptage inopiné</a>' : '';
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
		$exo   = (int) $ex['id'];
		$ref   = self::mois_ref( $ex );
		$noms  = IGE_FFM_Ecran_Livre::mois_noms();
		$tous  = IGE_FFM_Arretes::liste( $exo );
		$inop  = array_values( array_filter( $tous, static fn( $a ) => 'inopine' === $a['type'] ) );
		$lignes = array();
		$signes = 0;
		$ecarts = array();
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			if ( ! (int) $s['actif'] ) {
				continue;
			}
			$sid  = (int) $s['id'];
			$clos = IGE_FFM_Arretes::dernier_mois_clos( $exo, $sid );
			$mr   = IGE_FFM_Arretes::mensuel( $exo, $sid, $ref );
			$di   = null;
			foreach ( $inop as $a ) {
				if ( (int) $a['service_id'] === $sid ) {
					$di = $a;
					break;
				}
			}
			foreach ( $tous as $a ) {
				if ( (int) $a['service_id'] === $sid && (int) $a['ecart'] && in_array( $a['statut'], array( 'cloture', 'constate', 'contresigne' ), true ) ) {
					$ecarts[ $sid ] = ( $ecarts[ $sid ] ?? 0 ) + (int) $a['ecart'];
				}
			}
			$signes  += $clos >= $ref ? 1 : 0;
			$lignes[] = array( 's' => $s, 'clos' => $clos, 'mr' => $mr, 'di' => $di, 'caisse' => IGE_FFM_Budget::caisse( $exo, $sid ) );
		}
		$sel = isset( $_GET['comptage'] ) ? (int) $_GET['comptage'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		?>
		<div class="ige-grid ige-cols-3">
			<div class="ige-card ige-kpi ige-ok"><div class="ige-lbl">Arrêtés de <?php echo esc_html( strtolower( $noms[ $ref ] ) ); ?> signés</div><div class="ige-val ige-num"><?php echo esc_html( $signes ); ?> <span>sur <?php echo esc_html( count( $lignes ) ); ?></span></div><div class="ige-meta">Mois clôturés par l’agent et le Responsable</div></div>
			<div class="ige-card ige-kpi ige-alerte"><div class="ige-lbl">Écarts constatés en <?php echo esc_html( $ex['annee'] ); ?></div><div class="ige-val ige-num"><?php echo esc_html( count( $ecarts ) ); ?> <span>service(s)</span></div><div class="ige-meta"><?php echo $ecarts ? esc_html( 'total ' . ige_ffm_montant( array_sum( $ecarts ) ) . ', expliqués et signés' ) : 'Aucun écart'; ?></div></div>
			<div class="ige-card ige-kpi ige-accent"><div class="ige-lbl">Comptages inopinés en <?php echo esc_html( $ex['annee'] ); ?></div><div class="ige-val ige-num"><?php echo esc_html( count( $inop ) ); ?></div><div class="ige-meta"><?php echo $inop ? esc_html( 'dernier : ' . IGE_FFM_Comptes::service_code( (int) $inop[0]['service_id'] ) . ', le ' . wp_date( 'd/m', strtotime( $inop[0]['created_at'] ) ) . ( (int) $inop[0]['ecart'] ? ', écart ' . ige_ffm_montant( (int) $inop[0]['ecart'] ) : ', conforme' ) ) : 'Aucun pour le moment'; ?></div></div>
		</div>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Service</th><th class="ige-r">Solde du livre</th><th>Arrêté de <?php echo esc_html( strtolower( $noms[ $ref ] ) ); ?></th><th class="ige-r">Écart</th><th>Dernier comptage inopiné</th><th>Mois clôturés</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $lignes as $l ) : ?>
						<?php
						$sid  = (int) $l['s']['id'];
						$lien = add_query_arg( 'service', $sid, ige_ffm_url( 'arrete-de-caisse' ) );
						$mr   = $l['mr'];
						if ( $l['clos'] >= $ref ) {
							$pill = $mr && 'cloture' === $mr['statut'] ? ige_ffm_pill( ( (int) $mr['ecart'] ? 'Écart signé' : 'Signé' ) . ' le ' . wp_date( 'd/m', strtotime( $mr['signe_resp_le'] ) ), (int) $mr['ecart'] ? 'wait' : 'ok' ) : ige_ffm_pill( 'Clôturé', 'ok' );
						} elseif ( $mr ) {
							$pill = ige_ffm_pill( IGE_FFM_Arretes::etat( $mr )[0], 'wait' );
						} elseif ( ! $l['caisse'] && ! IGE_FFM_Ecritures::dernieres( $exo, $sid, 1 ) ) {
							$pill = ige_ffm_pill( 'Sans activité', 'neutre' );
						} else {
							$pill = ige_ffm_pill( 'Non fait', 'ko' );
						}
						?>
						<tr<?php echo $sel === $sid ? ' class="ige-sel"' : ''; ?>>
							<td><?php echo esc_html( $l['s']['code'] ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['caisse'], '' ) ); ?></td>
							<td><?php echo $pill; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="ige-r"<?php echo $mr && (int) $mr['ecart'] ? ' style="color:var(--ige-rouge);font-weight:700"' : ''; ?>><?php echo $mr ? esc_html( ige_ffm_montant( (int) $mr['ecart'], '' ) ) : '—'; ?></td>
							<td><?php echo $l['di'] ? esc_html( wp_date( 'd/m', strtotime( $l['di']['created_at'] ) ) . ' · ' . ( (int) $l['di']['ecart'] ? 'écart ' . ige_ffm_montant( (int) $l['di']['ecart'], '' ) : 'conforme' ) ) : '—'; ?></td>
							<td><?php echo $l['clos'] ? esc_html( 'Janv. → ' . IGE_FFM_Ecran_Livre::mois_courts()[ $l['clos'] ] ) : '—'; ?></td>
							<td>
								<div class="ige-row" style="gap:8px;flex-wrap:nowrap">
									<?php if ( $mr ) : ?><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'pv', $mr['id'], $lien ) ); ?>">PV</a><?php endif; ?>
									<a class="ige-act" href="<?php echo esc_url( add_query_arg( 'mois', $ref, $lien ) ); ?>">Voir</a>
									<?php if ( current_user_can( 'ige_gerer_fonds' ) ) : ?><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'comptage', $sid, ige_ffm_url( 'caisses' ) ) . '#ige-inopine' ); ?>">Compter</a><?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</section>
			<?php echo current_user_can( 'ige_gerer_fonds' ) ? self::formulaire_inopine( $exo, $sel ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire de comptage inopiné.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service choisi.
	 * @return string
	 */
	private static function formulaire_inopine( $exo, $sid ) {
		$old = IGE_FFM_Ecrans::old();
		$sid = $sid ?: (int) ( $old['service'] ?? 0 );
		ob_start();
		?>
		<section class="ige-card" id="ige-inopine">
			<h2>Comptage inopiné</h2>
			<?php if ( ! $sid ) : ?>
				<p class="ige-small ige-muted" style="margin-top:0">Choisissez le service sans le prévenir : le comptage se fait sur place, en présence de l’agent qui tient la caisse.</p>
				<form method="get" action="<?php echo esc_url( ige_ffm_url( 'caisses' ) ); ?>">
					<?php
					$opts = array( '' => '— Choisir le service —' );
					foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
						if ( (int) $s['actif'] ) {
							$opts[ $s['id'] ] = $s['code'];
						}
					}
					echo IGE_FFM_Ecrans::field( 'comptage', 'Service', '', array( 'type' => 'select', 'options' => $opts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					<button type="submit" class="ige-btn ige-btn-bleu">Commencer le comptage</button>
				</form>
			<?php else : ?>
				<?php
				$theo   = IGE_FFM_Arretes::solde_theorique( $exo, $sid, '9999-12-31' );
				$agents = array( '' => '— Agent présent —' );
				foreach ( IGE_FFM_Comptes::search( array( 'service_id' => $sid, 'actif' => 1 ) ) as $c ) {
					$agents[ $c['user_id'] ] = $c['display_name'] ?? ( get_userdata( (int) $c['user_id'] )->display_name ?? $c['user_id'] );
				}
				?>
				<p class="ige-small" style="margin-top:0">Service <b><?php echo esc_html( IGE_FFM_Comptes::service_code( $sid ) ); ?></b> · solde du livre à l’instant : <b class="ige-num"><?php echo esc_html( ige_ffm_montant( $theo ) ); ?></b> · <a href="<?php echo esc_url( ige_ffm_url( 'caisses' ) ); ?>">changer</a></p>
				<?php echo IGE_FFM_Ecrans::form_open( 'inopine', 'data-ige-billetage data-theorique="' . esc_attr( $theo ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="service" value="<?php echo esc_attr( $sid ); ?>">
					<div class="ige-inopine-billet"><?php echo IGE_FFM_Ecran_Arrete::billetage( $old ? $old : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<dl class="ige-kv ige-num" style="grid-template-columns:1fr auto;margin:14px 0">
						<dt>Total compté</dt><dd class="ige-right" data-ige-total>0 CDF</dd>
						<dt><b style="color:var(--ige-ink)">Écart</b></dt><dd class="ige-right" data-ige-ecart style="font-weight:700">—</dd>
					</dl>
					<?php echo IGE_FFM_Ecrans::field( 'agent_present', 'Agent présent pendant le comptage', (string) ( $old['agent_present'] ?? '' ), array( 'type' => 'select', 'options' => $agents, 'required' => true, 'help' => 'Il contresignera le procès-verbal depuis son compte.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-field"><label for="ige-explication-ci">Constatations et explications de l’agent</label><textarea id="ige-explication-ci" name="explication" rows="3" maxlength="1000" placeholder="Obligatoire en cas d’écart"><?php echo esc_textarea( $old['explication'] ?? '' ); ?></textarea></div>
					<button type="submit" class="ige-btn ige-btn-jaune ige-btn-block">Enregistrer le procès-verbal</button>
				</form>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enregistrement d'un comptage inopiné.
	 */
	public static function handle_inopine() {
		ige_ffm_check_form( 'inopine' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$sid = (int) ige_ffm_post( 'service' );
		$in  = IGE_FFM_Ecran_Arrete::saisies();
		$res = IGE_FFM_Arretes::inopine( $sid, $in );
		if ( is_wp_error( $res ) ) {
			IGE_FFM_Ecrans::remember( $in + array( 'service' => $sid ) );
			ige_ffm_flash( 'ko', 'Procès-verbal non enregistré', $res->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'caisses', array( 'comptage' => $sid ) ) . '#ige-inopine' );
			exit;
		}
		$a = IGE_FFM_Arretes::get( $res );
		ige_ffm_flash( (int) $a['ecart'] ? 'warn' : 'ok', 'Comptage inopiné enregistré : ' . ( (int) $a['ecart'] ? 'écart de ' . ige_ffm_montant( (int) $a['ecart'] ) : 'caisse conforme' ), 'L’agent présent doit contresigner le procès-verbal depuis son compte.' );
		wp_safe_redirect( ige_ffm_url( 'arrete-de-caisse', array( 'service' => $sid, 'pv' => $res ) ) );
		exit;
	}
}
