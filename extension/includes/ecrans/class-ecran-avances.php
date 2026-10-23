<?php
/**
 * Écran Avances (Administration et Finance) : fonds remis aux services, justification,
 * ancienneté des reliquats, nouvelle avance soumise à la règle de justification.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Avances de fonds.
 */
final class IGE_FFM_Ecran_Avances {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_avance', array( __CLASS__, 'handle_creer' ) );
		add_action( 'admin_post_ige_ffm_avance_annuler', array( __CLASS__, 'handle_annuler' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$ex               = IGE_FFM_Contexte::exercice();
		$args['title']    = 'Avances de fonds aux services';
		$args['subtitle'] = 'Fonds remis par l’Inspection Générale' . ( $ex ? ' · exercice ' . $ex['annee'] : '' ) . ' · justification « premier entré, premier sorti » par les dépenses validées';
		$args['actions']  = current_user_can( 'ige_gerer_fonds' ) ? '<a class="ige-btn ige-btn-jaune" href="#ige-nouvelle-avance">+ Nouvelle avance</a>' : '';
		return $args;
	}

	/**
	 * Pastille d'ancienneté.
	 *
	 * @param int $jours Jours.
	 * @return string
	 */
	private static function age( $jours ) {
		return ige_ffm_pill( $jours . ' j', $jours >= 90 ? 'ko' : ( $jours >= 60 ? 'wait' : 'info' ) );
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
		$syn   = IGE_FFM_Avances::synthese( $exo );
		$seuil = (int) IGE_FFM_Reglages::get( 'seuil_justification' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$vue = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : '';
		$sid = isset( $_GET['service'] ) ? (int) $_GET['service'] : 0;
		// phpcs:enable
		$tot = array( 'remis' => 0, 'justifie' => 0, 'reliquat' => 0, 'plus90' => 0 );
		foreach ( $syn as $l ) {
			$tot['remis']    += $l['remis'];
			$tot['justifie'] += $l['justifie'];
			$tot['reliquat'] += $l['reliquat'];
			$tot['plus90']   += $l['tranches']['plus90'];
		}
		$filtres = array(
			''         => 'Tous les services (' . count( $syn ) . ')',
			'reliquat' => 'Reliquat à justifier',
			'plus90'   => '+ 90 jours',
			'sans'     => 'Sans avance',
		);
		$lignes = array_filter(
			$syn,
			static function ( $l ) use ( $vue ) {
				switch ( $vue ) {
					case 'reliquat':
						return $l['reliquat'] > 0;
					case 'plus90':
						return $l['tranches']['plus90'] > 0;
					case 'sans':
						return 0 === $l['nb'];
					default:
						return true;
				}
			}
		);
		uasort( $lignes, static fn( $a, $b ) => $b['reliquat'] <=> $a['reliquat'] ?: strcmp( $a['service']['code'], $b['service']['code'] ) );
		$page = ige_ffm_url( 'avances' );

		ob_start();
		if ( IGE_FFM_Reglages::get( 'entrees_manuelles' ) && current_user_can( 'ige_administrer' ) ) {
			echo ige_ffm_banner( 'warn', 'Les services saisissent encore eux-mêmes leurs entrées', ' Maintenant que les avances sont enregistrées ici, décochez « Les services saisissent eux-mêmes leurs approvisionnements » dans <a href="' . esc_url( ige_ffm_url( 'parametres' ) ) . '">Exercice et règles de contrôle</a>, pour que chaque entrée de caisse provienne d’une avance tracée.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi ige-accent"><div class="ige-lbl">Avances remises</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['remis'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta">Exercice <?php echo esc_html( $ex['annee'] ); ?></div></div>
			<div class="ige-card ige-kpi ige-ok"><div class="ige-lbl">Justifié</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['justifie'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo $tot['remis'] ? esc_html( round( $tot['justifie'] / $tot['remis'] * 100 ) . ' % par des dépenses validées' ) : '&nbsp;'; ?></div></div>
			<div class="ige-card ige-kpi ige-alerte"><div class="ige-lbl">Reste à justifier</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['reliquat'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta"><?php echo esc_html( count( array_filter( $syn, static fn( $l ) => $l['reliquat'] > 0 ) ) . ' service(s)' ); ?></div></div>
			<div class="ige-card ige-kpi ige-risque"><div class="ige-lbl">Depuis plus de 90 jours</div><div class="ige-val ige-num"><?php echo esc_html( ige_ffm_montant( $tot['plus90'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta">À relancer en priorité</div></div>
		</div>
		<?php
		if ( $sid && isset( $syn[ $sid ] ) ) {
			echo self::detail( $exo, $sid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<div class="ige-toolbar">
					<?php foreach ( $filtres as $k => $lbl ) : ?>
						<a class="ige-chip<?php echo $vue === $k ? ' ige-on' : ''; ?>" href="<?php echo esc_url( $k ? add_query_arg( 'vue', $k, $page ) : $page ); ?>"><?php echo esc_html( $lbl ); ?></a>
					<?php endforeach; ?>
				</div>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Service</th><th class="ige-r">Remis</th><th class="ige-r">Justifié</th><th class="ige-r">Reste</th><th style="min-width:120px">Dernière avance</th><th>Plus ancien</th><th>Nouvelle avance</th></tr></thead>
					<tbody>
					<?php foreach ( $lignes as $id => $l ) : ?>
						<tr<?php echo $sid === (int) $id ? ' class="ige-sel"' : ''; ?>>
							<td><a href="<?php echo esc_url( add_query_arg( 'service', $id, $page ) ); ?>"><b><?php echo esc_html( $l['service']['code'] ); ?></b></a></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['remis'], '' ) ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $l['justifie'], '' ) ); ?></td>
							<td class="ige-r"><b><?php echo esc_html( ige_ffm_montant( $l['reliquat'], '' ) ); ?></b></td>
							<td>
								<?php
								if ( null === $l['taux_derniere'] ) {
									echo '<span class="ige-small ige-muted">—</span>';
								} else {
									$t   = $l['taux_derniere'];
									$col = $t >= $seuil ? 'var(--ige-vert)' : ( $t >= $seuil / 2 ? 'var(--ige-orange)' : 'var(--ige-rouge)' );
									echo '<div class="ige-row" style="flex-wrap:nowrap"><div class="ige-bar" style="margin:0;flex:1;min-width:50px"><i style="width:' . esc_attr( min( 100, round( $t ) ) ) . '%;background:' . esc_attr( $col ) . '"></i></div><span>' . esc_html( floor( $t ) ) . '&nbsp;%</span></div>';
								}
								?>
							</td>
							<td><?php echo $l['plus_ancien'] ? self::age( $l['plus_ancien'] ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td>
								<?php if ( $l['eligible'] ) : ?>
									<?php if ( current_user_can( 'ige_gerer_fonds' ) ) : ?><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'service', $id, $page ) . '#ige-nouvelle-avance' ); ?>">Accorder</a><?php else : ?><span class="ige-small">Possible</span><?php endif; ?>
								<?php else : ?>
									<span class="ige-small ige-muted">Bloquée (&lt; <?php echo esc_html( $seuil ); ?> %)</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $lignes ) : ?>
						<tr><td colspan="7" class="ige-empty">Aucun service pour ce filtre.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
				<p class="ige-small ige-muted" style="margin:10px 0 0">Seuil de justification pour une nouvelle avance : <?php echo esc_html( $seuil ); ?> % de la précédente (réglable). Les dépenses comptent comme justification une fois validées par la vérification.</p>
			</section>
			<?php echo current_user_can( 'ige_gerer_fonds' ) ? self::formulaire( $exo, $sid, $syn ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Détail d'un service : sources de fonds et leur justification.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return string
	 */
	private static function detail( $exo, $sid ) {
		$j    = IGE_FFM_Avances::justification( $exo, $sid );
		$livre = add_query_arg( 'service', $sid, ige_ffm_url( 'livre-de-caisse' ) );
		ob_start();
		?>
		<section class="ige-card">
			<h2><?php echo esc_html( 'Service ' . IGE_FFM_Comptes::service_code( $sid ) ); ?> <span class="ige-sub"><a href="<?php echo esc_url( $livre ); ?>">Livre de caisse</a> · <a href="<?php echo esc_url( ige_ffm_url( 'avances' ) ); ?>">Fermer</a></span></h2>
			<div class="ige-table-wrap"><table class="ige-table ige-num">
				<thead><tr><th>Source des fonds</th><th>Date</th><th>Objet</th><th class="ige-r">Montant</th><th class="ige-r">Justifié</th><th class="ige-r">Reste</th><th>Ancienneté</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $j['sources'] as $s ) : ?>
					<tr>
						<td><b><?php echo esc_html( $s['libelle'] ); ?></b><?php echo 'entree' === $s['type'] ? ' <span class="ige-small ige-muted">(saisie par le service)</span>' : ''; ?></td>
						<td><?php echo $s['date'] ? esc_html( wp_date( 'd/m/Y', strtotime( $s['date'] ) ) ) : ''; ?></td>
						<td><?php echo esc_html( $s['avance'] ? $s['avance']['objet'] : '' ); ?></td>
						<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $s['montant'], '' ) ); ?></td>
						<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $s['justifie'], '' ) ); ?></td>
						<td class="ige-r"><b><?php echo esc_html( ige_ffm_montant( $s['reliquat'], '' ) ); ?></b></td>
						<td><?php echo $s['reliquat'] > 0 ? self::age( $s['age'] ) : ige_ffm_pill( 'Justifié', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td>
							<?php
							if ( $s['avance'] && current_user_can( 'ige_gerer_fonds' ) ) {
								echo IGE_FFM_Ecrans::form_open( 'avance_annuler', 'class="ige-row" style="gap:6px;flex-wrap:nowrap" data-ige-confirm="Annuler cette avance ? Une contrepassation retirera le montant de la caisse du service."' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								echo '<input type="hidden" name="avance" value="' . esc_attr( $s['avance']['id'] ) . '"><label class="screen-reader-text" for="ige-motif-av-' . esc_attr( $s['avance']['id'] ) . '">Motif</label><input class="ige-input" style="width:150px;padding:6px 8px" id="ige-motif-av-' . esc_attr( $s['avance']['id'] ) . '" name="motif" placeholder="Motif d’annulation" minlength="5" required><button type="submit" class="ige-act" style="background:none;border:0;cursor:pointer;color:var(--ige-rouge)">Annuler</button></form>';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $j['sources'] ) : ?>
					<tr><td colspan="8" class="ige-empty">Aucun fonds reçu sur l’exercice.</td></tr>
				<?php endif; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Dépenses validées : <?php echo esc_html( ige_ffm_montant( $j['justifie'] ) ); ?>. Elles apurent d’abord le solde initial, puis les fonds dans l’ordre où ils ont été reçus. Une avance annulée n’apparaît plus.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire de nouvelle avance.
	 *
	 * @param int   $exo Exercice.
	 * @param int   $sid Service présélectionné.
	 * @param array $syn Synthèse.
	 * @return string
	 */
	private static function formulaire( $exo, $sid, array $syn ) {
		$old   = IGE_FFM_Ecrans::old();
		$sid   = (int) ( $old['service_id'] ?? $sid );
		$seuil = (int) IGE_FFM_Reglages::get( 'seuil_justification' );
		$modes = wp_list_pluck( array_filter( IGE_FFM_Referentiels::all( 'mode_paiement' ), static fn( $m ) => (int) $m['actif'] ), 'libelle', 'id' );
		ob_start();
		?>
		<section class="ige-card" id="ige-nouvelle-avance">
			<h2>Nouvelle avance</h2>
			<?php echo IGE_FFM_Ecrans::form_open( 'avance' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-field">
					<label for="ige-av-service">Service</label>
					<select id="ige-av-service" name="service_id" required>
						<option value="">— Choisir —</option>
						<?php foreach ( $syn as $id => $l ) : ?>
							<?php if ( (int) $l['service']['actif'] ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>" data-bloque="<?php echo $l['eligible'] ? '0' : '1'; ?>"<?php selected( $sid, (int) $id ); ?>><?php echo esc_html( $l['service']['code'] . ( $l['eligible'] ? '' : ' — dernière avance justifiée à ' . floor( $l['taux_derniere'] ) . ' %' ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="ige-grid ige-cols-2" style="gap:0 12px">
					<?php echo IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', (string) ( $old['montant'] ?? '' ), array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo IGE_FFM_Ecrans::field( 'date_remise', 'Date de remise', (string) ( $old['date_remise'] ?? IGE_FFM_Contexte::aujourdhui() ), array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php echo IGE_FFM_Ecrans::field( 'mode_paiement_id', 'Mode de remise', (string) ( $old['mode_paiement_id'] ?? '' ), array( 'type' => 'select', 'options' => $modes ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo IGE_FFM_Ecrans::field( 'objet', 'Objet', (string) ( $old['objet'] ?? '' ), array( 'required' => true, 'attrs' => 'maxlength="200" placeholder="Ex. : fonctionnement novembre, mission Kwilu"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div id="ige-bloc-derogation" hidden>
					<?php echo ige_ffm_banner( 'warn', 'Règle des ' . $seuil . ' % non atteinte', ' La dernière avance de ce service n’est pas assez justifiée. Vous pouvez accorder l’avance par dérogation : le motif est conservé dans le journal d’audit.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo IGE_FFM_Ecrans::field( 'derogation', 'Motif de la dérogation', (string) ( $old['derogation'] ?? '' ), array( 'attrs' => 'maxlength="200"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<p class="ige-small ige-muted">L’avance crée automatiquement l’entrée dans la caisse du service, déjà validée, avec le numéro AV-<?php echo esc_html( IGE_FFM_Contexte::exercice()['annee'] ); ?>-….</p>
				<button type="submit" class="ige-btn ige-btn-jaune ige-btn-block">Enregistrer l’avance</button>
			</form>
		</section>
		<script>
		(function(){var s=document.getElementById('ige-av-service'),d=document.getElementById('ige-bloc-derogation');if(!s||!d){return;}
		function m(){var o=s.options[s.selectedIndex];var b=o&&o.getAttribute('data-bloque')==='1';d.hidden=!b;var i=document.getElementById('ige-derogation');if(i){i.required=b;i.minLength=b?10:0;}}
		s.addEventListener('change',m);m();}());
		</script>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enregistrement d'une avance.
	 */
	public static function handle_creer() {
		ige_ffm_check_form( 'avance' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$data = array();
		foreach ( array( 'service_id', 'montant', 'date_remise', 'mode_paiement_id', 'objet', 'derogation' ) as $k ) {
			$data[ $k ] = ige_ffm_post( $k );
		}
		$res = IGE_FFM_Avances::creer( $data );
		if ( is_wp_error( $res ) ) {
			IGE_FFM_Ecrans::remember( $data );
			ige_ffm_flash( 'ko', 'Avance non enregistrée', $res->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'avances', array( 'service' => (int) $data['service_id'] ) ) . '#ige-nouvelle-avance' );
			exit;
		}
		$a = IGE_FFM_Avances::get( $res );
		ige_ffm_flash( 'ok', 'Avance ' . $a['numero'] . ' enregistrée', sprintf( '%s remis à %s : l’entrée figure déjà dans sa caisse.', ige_ffm_montant( (int) $a['montant'] ), IGE_FFM_Comptes::service_code( (int) $a['service_id'] ) ) );
		wp_safe_redirect( ige_ffm_url( 'avances', array( 'service' => (int) $a['service_id'] ) ) );
		exit;
	}

	/**
	 * Annulation d'une avance.
	 */
	public static function handle_annuler() {
		ige_ffm_check_form( 'avance_annuler' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$id  = (int) ige_ffm_post( 'avance' );
		$a   = IGE_FFM_Avances::get( $id );
		$res = IGE_FFM_Avances::annuler( $id, ige_ffm_post( 'motif' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Annulation refusée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Avance ' . $a['numero'] . ' annulée', 'Une contrepassation a retiré le montant de la caisse du service.' );
		}
		wp_safe_redirect( ige_ffm_url( 'avances', array( 'service' => $a ? (int) $a['service_id'] : 0 ) ) );
		exit;
	}
}
