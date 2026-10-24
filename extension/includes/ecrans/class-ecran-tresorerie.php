<?php
/**
 * Écran « Trésorerie » (VA-07, VA-51) : plan de trésorerie des prochains mois, fonds attendus,
 * taux du jour CDF/USD. Réservé à Administration et Finance.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trésorerie.
 */
final class IGE_FFM_Ecran_Tresorerie {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_prevision', array( __CLASS__, 'handle_prevision' ) );
		add_action( 'admin_post_ige_ffm_prevision_suppr', array( __CLASS__, 'handle_suppr' ) );
		add_action( 'admin_post_ige_ffm_taux', array( __CLASS__, 'handle_taux' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Trésorerie';
		$args['subtitle'] = 'Plan des prochains mois, fonds attendus et taux du jour';
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
		$exo   = (int) $ex['id'];
		$p     = IGE_FFM_Tresorerie::plan( $exo, 4 );
		$noms  = IGE_FFM_Ecran_Livre::mois_noms();
		$auj   = IGE_FFM_Contexte::aujourdhui();
		$taux  = IGE_FFM_Devises::derniers( 10 );
		$t0    = IGE_FFM_Devises::taux( $auj );
		$max   = 1;
		foreach ( $p['mois'] as $x ) {
			$max = max( $max, $x['besoins'], $x['fonds'] + max( 0, $x['debut'] ) );
		}
		ob_start();
		if ( $p['tension'] ) {
			echo ige_ffm_banner( 'ko', 'Tension prévue en ' . $noms[ $p['tension']['mois'] ], ' Il manquerait ' . esc_html( ige_ffm_montant( $p['tension']['manque'] ) ) . ' : décalez des dépenses non urgentes, réduisez des demandes de fonds ou obtenez une dotation plus tôt.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $t0 || strtotime( $auj ) - strtotime( $t0['date_effet'] ) > 7 * DAY_IN_SECONDS ) {
			echo ige_ffm_banner( 'warn', 'Taux du jour à mettre à jour', ' ' . ( $t0 ? 'Dernier taux : ' . esc_html( wp_date( 'd/m/Y', strtotime( $t0['date_effet'] ) ) ) . '. ' : '' ) . 'Les services ne peuvent saisir en dollars qu’avec un taux de moins de 31 jours.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Disponible aujourd’hui</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $p['disponible']['total'], '' ) ); ?></div><div class="ige-meta">CDF</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Caisses des services</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $p['disponible']['caisses'], '' ) ); ?></div><div class="ige-meta">soldes de caisse</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Banque (compte principal)</div><div class="ige-val"><?php echo esc_html( null === $p['disponible']['banque'] ? '—' : ige_ffm_montant( $p['disponible']['banque'], '' ) ); ?></div><div class="ige-meta"><?php echo null === $p['disponible']['banque'] ? 'aucun compte déclaré' : 'solde comptable'; ?></div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Dépenses courantes</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $p['recurrent'], '' ) ); ?></div><div class="ige-meta">moyenne mensuelle, 3 derniers mois</div></div>
		</div>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<h2 style="margin-top:0">Plan de trésorerie</h2>
				<?php if ( ! $p['mois'] ) : ?>
					<p class="ige-empty">Le plan couvre les mois restants de l’exercice en cours.</p>
				<?php else : ?>
					<div class="ige-plan" role="img" aria-label="Ressources et besoins par mois">
						<?php foreach ( $p['mois'] as $k => $x ) : ?>
							<?php $res = $x['fonds'] + max( 0, $x['debut'] ); ?>
							<div class="ige-plan-l">
								<b><?php echo esc_html( $noms[ $k ] ); ?></b>
								<div class="ige-plan-b">
									<i class="f" style="width:<?php echo esc_attr( max( 0.5, round( 100 * $res / $max, 1 ) ) ); ?>%" title="Ressources : <?php echo esc_attr( ige_ffm_montant( $res ) ); ?>"></i>
									<i class="b" style="width:<?php echo esc_attr( max( 0.5, round( 100 * $x['besoins'] / $max, 1 ) ) ); ?>%;<?php echo $x['fin'] < 0 ? 'background:var(--ige-rouge,#c8102e)' : ''; ?>" title="Besoins : <?php echo esc_attr( ige_ffm_montant( $x['besoins'] ) ); ?>"></i>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<p class="ige-legende"><span><i style="background:var(--ige-bleu,#0095c9)"></i>Ressources (solde de début et fonds attendus)</span><span><i style="background:var(--ige-orange,#ed7016)"></i>Besoins</span></p>
					<div class="ige-table-wrap"><table class="ige-table">
						<thead><tr><th></th><?php foreach ( $p['mois'] as $k => $x ) : ?><th class="ige-r"><?php echo esc_html( $noms[ $k ] ); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
							<tr><td>Solde de début</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['debut'], '' ) ); ?></td><?php endforeach; ?></tr>
							<tr><td>+ Fonds attendus</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['fonds'], '' ) ); ?></td><?php endforeach; ?></tr>
							<tr><td>− Demandes de fonds en cours</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['demandes'], '' ) ); ?><?php echo $x['nb_demandes'] ? '<br><span class="ige-small ige-muted">' . (int) $x['nb_demandes'] . ' demande(s)</span>' : ''; ?></td><?php endforeach; ?></tr>
							<tr><td>− Missions à payer</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['missions'], '' ) ); ?><?php echo $x['nb_missions'] ? '<br><span class="ige-small ige-muted">' . (int) $x['nb_missions'] . ' mission(s)</span>' : ''; ?></td><?php endforeach; ?></tr>
							<tr><td>− Engagements non payés</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['engagements'], '' ) ); ?></td><?php endforeach; ?></tr>
							<tr><td>− Dépenses courantes</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['recurrent'], '' ) ); ?></td><?php endforeach; ?></tr>
							<tr class="ige-tot"><td>Solde de fin</td><?php foreach ( $p['mois'] as $x ) : ?><td class="ige-r ige-num<?php echo $x['fin'] < 0 ? ' ige-neg' : ''; ?>"><?php echo esc_html( ige_ffm_montant( $x['fin'], '' ) ); ?></td><?php endforeach; ?></tr>
						</tbody>
					</table></div>
					<p class="ige-small ige-muted" style="margin:10px 0 0">Estimation prudente : les demandes de fonds non encore décaissées et les missions approuvées non payées sont comptées au mois de besoin (ou de départ) ; les engagements non payés au mois en cours ; les dépenses courantes selon la moyenne des trois derniers mois, hors missions. Une demande qui finance une mission peut être comptée deux fois.</p>
				<?php endif; ?>
			</section>
			<aside>
				<section class="ige-card">
					<h2 style="margin-top:0">Fonds attendus</h2>
					<?php $prev = array_filter( IGE_FFM_Tresorerie::previsions( $exo ), static fn( $x ) => (int) $x['mois'] >= (int) substr( $auj, 5, 2 ) ); ?>
					<?php if ( $prev ) : ?>
						<table class="ige-table"><tbody>
							<?php foreach ( $prev as $x ) : ?>
								<tr><td><?php echo esc_html( $noms[ (int) $x['mois'] ] ); ?></td><td><?php echo esc_html( $x['libelle'] ); ?></td><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $x['montant'], '' ) ); ?></td><td><?php echo IGE_FFM_Ecrans::action_button( 'prevision_suppr', array( 'prevision' => $x['id'] ), 'Retirer', 'ige-act', 'Retirer ces fonds attendus du plan ?' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
							<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
					<?php
					$opts = array();
					for ( $m = (int) substr( $auj, 5, 2 ); $m <= 12; $m++ ) {
						$opts[ $m ] = $noms[ $m ];
					}
					echo IGE_FFM_Ecrans::form_open( 'prevision' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'mois', 'Mois', '', array( 'type' => 'select', 'options' => $opts ) )
						. IGE_FFM_Ecrans::field( 'libelle', 'Origine', '', array( 'required' => true, 'attrs' => 'maxlength="190" placeholder="Ex. : dotation du 4e trimestre"' ) )
						. IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
						. '<button type="submit" class="ige-btn ige-btn-line">Ajouter au plan</button></form>';
					?>
				</section>
				<section class="ige-card" id="taux">
					<h2 style="margin-top:0">Taux du jour (USD)</h2>
					<p class="ige-small">Francs congolais pour 1 dollar. Sert à convertir les dépenses saisies en dollars ; le montant d’origine et le taux restent visibles sur l’écriture.</p>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'taux' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
						. IGE_FFM_Ecrans::field( 'date', 'Date', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) )
						. IGE_FFM_Ecrans::field( 'taux', 'Taux', $t0 ? (string) (float) $t0['taux'] : '', array( 'required' => true, 'attrs' => 'inputmode="decimal" class="ige-num" placeholder="2 850"' ) )
						. '</div><button type="submit" class="ige-btn ige-btn-jaune">Fixer le taux</button></form>';
					?>
					<?php if ( $taux ) : ?>
						<table class="ige-table" style="margin-top:10px"><thead><tr><th>Date</th><th class="ige-r">CDF pour 1 USD</th></tr></thead><tbody>
							<?php foreach ( $taux as $x ) : ?>
								<tr><td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $x['date_effet'] ) ) ); ?></td><td class="ige-r ige-num"><?php echo esc_html( number_format( (float) $x['taux'], fmod( (float) $x['taux'], 1 ) ? 2 : 0, ',', "\u{202F}" ) ); ?></td></tr>
							<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</section>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Ajout de fonds attendus.
	 */
	public static function handle_prevision() {
		ige_ffm_check_form( 'prevision' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$r = IGE_FFM_Tresorerie::ajouter( array( 'mois' => ige_ffm_post( 'mois' ), 'libelle' => ige_ffm_post( 'libelle' ), 'montant' => ige_ffm_post( 'montant' ) ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Fonds attendus non enregistrés', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Fonds attendus ajoutés au plan', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'tresorerie' ) );
		exit;
	}

	/**
	 * Suppression de fonds attendus.
	 */
	public static function handle_suppr() {
		ige_ffm_check_form( 'prevision_suppr' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$r = IGE_FFM_Tresorerie::supprimer( (int) ige_ffm_post( 'prevision' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Suppression impossible', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Fonds attendus retirés du plan', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'tresorerie' ) );
		exit;
	}

	/**
	 * Taux du jour.
	 */
	public static function handle_taux() {
		ige_ffm_check_form( 'taux' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$r = IGE_FFM_Devises::fixer( ige_ffm_post( 'date' ), ige_ffm_post( 'taux' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Taux non enregistré', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Taux du jour enregistré', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'tresorerie' ) . '#taux' );
		exit;
	}
}
