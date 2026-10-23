<?php
/**
 * Écran Administration > Exercice et règles de contrôle.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran des paramètres.
 */
final class IGE_FFM_Ecran_Parametres {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_reglages', array( __CLASS__, 'handle_reglages' ) );
		add_action( 'admin_post_ige_ffm_exercice_suivant', array( __CLASS__, 'handle_exercice_suivant' ) );
		add_action( 'admin_post_ige_ffm_exercice_budget', array( __CLASS__, 'handle_exercice_budget' ) );
		add_action( 'admin_post_ige_ffm_cloture_exercice', array( __CLASS__, 'handle_cloture' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Exercice et règles de contrôle';
		$args['subtitle'] = 'Les règles de gestion sont des paramètres, pas du code';
		$args['actions']  = '<button type="submit" form="ige-form-reglages" class="ige-btn ige-btn-jaune">Enregistrer les règles</button>';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$defs      = IGE_FFM_Reglages::definitions();
		$exercices = IGE_FFM_Referentiels::exercices();
		$next      = $exercices ? (int) $exercices[0]['annee'] + 1 : (int) wp_date( 'Y' );

		$groupes = array(
			'controles' => 'Contrôles bloquants',
			'seuils'    => 'Seuils',
			'anomalies' => 'Détection d’anomalies à la vérification',
			'securite'  => 'Sécurité des comptes',
		);
		$unites  = array(
			'seuil_justification'  => '%',
			'plafond_especes'      => 'CDF',
			'alerte_rubrique'      => '%',
			'delai_relance'        => 'jours',
			'delai_saisie_tardive' => 'jours',
			'verrouillage_essais'  => 'essais',
			'verrouillage_minutes' => 'minutes',
			'inactivite_minutes'   => 'minutes',
			'mdp_longueur'         => 'caractères',
		);

		ob_start();
		?>
		<div class="ige-grid ige-cols-2 ige-start">
			<section class="ige-card">
				<h2>Exercices</h2>
				<div class="ige-table-wrap">
					<table class="ige-table">
						<thead><tr><th>Année</th><th>Période</th><th>État</th><th>Budget des services</th><th></th></tr></thead>
						<tbody>
						<?php foreach ( $exercices as $e ) : ?>
							<tr>
								<td><b><?php echo esc_html( $e['annee'] ); ?></b></td>
								<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $e['date_debut'] ) ) . ' – ' . wp_date( 'd/m/Y', strtotime( $e['date_fin'] ) ) ); ?></td>
								<td><?php echo 'ouvert' === $e['statut'] ? ige_ffm_pill( 'Ouvert', 'ok' ) : ( 'prepare' === $e['statut'] ? ige_ffm_pill( 'Préparé', 'info' ) : ige_ffm_pill( 'Clos', 'neutre' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( (int) $e['budget_obligatoire'] ? 'Obligatoire' : 'Facultatif' ); ?></td>
								<td>
									<?php
									if ( in_array( $e['statut'], array( 'ouvert', 'prepare' ), true ) ) {
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										echo IGE_FFM_Ecrans::action_button( 'exercice_budget', array( 'id' => $e['id'], 'obligatoire' => (int) $e['budget_obligatoire'] ? 0 : 1 ), (int) $e['budget_obligatoire'] ? 'Rendre facultatif' : 'Rendre obligatoire', 'ige-act' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="ige-small ige-muted">Budget facultatif : un service sans budget n’a pas de contrôle de disponible ; seul le blocage de caisse négative s’applique, et sa consommation sert de base au budget suivant.</p>
				<?php if ( ! IGE_FFM_Cloture::exercice_prepare() ) : ?>
				<div class="ige-row" style="margin-top:12px">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					echo IGE_FFM_Ecrans::action_button( 'exercice_suivant', array(), 'Préparer l’exercice ' . $next, 'ige-btn ige-btn-line', 'Préparer l’exercice ' . $next . ' ? Les Responsables pourront y saisir leur budget ; il s’ouvrira à la clôture de l’exercice en cours.' );
					?>
				</div>
				<?php endif; ?>
				<p class="ige-small ige-muted">Un exercice « préparé » reçoit les budgets de l’année suivante mais aucune écriture ; il s’ouvre automatiquement à la clôture de l’exercice en cours.</p>
				<?php echo self::cloture(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>

			<?php
			echo IGE_FFM_Ecrans::form_open( 'reglages', 'id="ige-form-reglages" class="ige-grid" style="gap:16px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			foreach ( $groupes as $g => $titre ) :
				?>
				<section class="ige-card">
					<h2><?php echo esc_html( $titre ); ?></h2>
					<?php
					foreach ( $defs as $key => $def ) {
						if ( $def[3] !== $g ) {
							continue;
						}
						$val = IGE_FFM_Reglages::get( $key );
						if ( 'bool' === $def[0] ) {
							echo IGE_FFM_Ecrans::field( $key, $def[2], (bool) $val, array( 'type' => 'checkbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							$shown = 'plafond_especes' === $key ? ige_ffm_montant( (int) $val, '' ) : (string) $val;
							echo IGE_FFM_Ecrans::field( $key, $def[2], $shown, array( 'attrs' => 'inputmode="numeric"', 'help' => isset( $unites[ $key ] ) ? 'En ' . $unites[ $key ] . '.' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
					}
					?>
				</section>
			<?php endforeach; ?>
				<div><button type="submit" class="ige-btn ige-btn-jaune">Enregistrer les règles</button></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enregistrement des règles.
	 */
	public static function handle_reglages() {
		ige_ffm_check_form( 'reglages' );
		ige_ffm_require( 'ige_administrer' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		IGE_FFM_Reglages::save( wp_unslash( $_POST ) );
		ige_ffm_flash( 'ok', 'Règles enregistrées', 'Les modifications sont inscrites au journal d’audit.' );
		wp_safe_redirect( ige_ffm_url( 'parametres' ) );
		exit;
	}

	/**
	 * Bloc de clôture de l'exercice ouvert.
	 *
	 * @return string
	 */
	private static function cloture() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return '';
		}
		$b    = IGE_FFM_Cloture::bilan( (int) $ex['id'] );
		$pb   = array_filter( $b['services'], static fn( $x ) => $x['bloquant'] || $x['avertissement'] );
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		ob_start();
		?>
		<h3 style="margin:22px 0 8px;font-size:15px">Clôture de l’exercice <?php echo esc_html( $ex['annee'] ); ?></h3>
		<p class="ige-small" style="margin-top:0">À la clôture, plus aucune écriture ne peut être ajoutée à <?php echo esc_html( $ex['annee'] ); ?> ; le solde de caisse de chaque service devient son solde initial de <?php echo esc_html( (int) $ex['annee'] + 1 ); ?>, et l’exercice suivant s’ouvre.</p>
		<?php if ( $b['bloquants'] ) : ?>
			<?php echo ige_ffm_banner( 'ko', $b['bloquants'] . ' service(s) empêchent la clôture', ' Toutes les écritures doivent être transmises et vérifiées, et les engagements payés ou contrepassés.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php elseif ( $b['avertissements'] ) : ?>
			<?php echo ige_ffm_banner( 'warn', $b['avertissements'] . ' service(s) sans arrêté de décembre', ' La clôture reste possible, mais leur caisse n’a pas été comptée en fin d’année.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php else : ?>
			<?php echo ige_ffm_banner( 'ok', 'Tout est en ordre pour la clôture', '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
		<?php if ( $pb ) : ?>
			<div class="ige-table-wrap" style="margin-top:10px"><table class="ige-table ige-num">
				<thead><tr><th>Service</th><th class="ige-r">Non transmises</th><th class="ige-r">À vérifier</th><th class="ige-r">Engagements</th><th>Dernier mois clôturé</th></tr></thead>
				<tbody>
				<?php foreach ( $pb as $x ) : ?>
					<tr>
						<td><?php echo esc_html( $x['service']['code'] ); ?></td>
						<td class="ige-r"><?php echo esc_html( $x['non_transmises'] ?: '—' ); ?></td>
						<td class="ige-r"><?php echo esc_html( $x['a_verifier'] ?: '—' ); ?></td>
						<td class="ige-r"><?php echo esc_html( $x['engagements'] ?: '—' ); ?></td>
						<td><?php echo $x['mois_clos'] ? esc_html( $noms[ $x['mois_clos'] ] ) : '—'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		<?php endif; ?>
		<?php if ( ! $b['bloquants'] ) : ?>
			<?php echo IGE_FFM_Ecrans::form_open( 'cloture_exercice', 'style="margin-top:12px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="exercice" value="<?php echo esc_attr( $ex['id'] ); ?>">
				<?php echo IGE_FFM_Ecrans::field( 'confirmation', 'Pour confirmer, tapez « CLÔTURER ' . $ex['annee'] . ' »', '', array( 'required' => true, 'attrs' => 'autocomplete="off"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<button type="submit" class="ige-btn ige-btn-rouge">Clôturer l’exercice <?php echo esc_html( $ex['annee'] ); ?></button>
			</form>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Clôture de l'exercice.
	 */
	public static function handle_cloture() {
		ige_ffm_check_form( 'cloture_exercice' );
		ige_ffm_require( 'ige_administrer' );
		$res = IGE_FFM_Cloture::cloturer( (int) ige_ffm_post( 'exercice' ), ige_ffm_post( 'confirmation' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Clôture refusée', $res->get_error_message() );
		} else {
			$n = IGE_FFM_Cloture::exercice( $res );
			ige_ffm_flash( 'ok', 'Exercice clôturé', 'Les soldes de caisse sont reportés ; l’exercice ' . $n['annee'] . ' est ouvert (budget obligatoire).' );
		}
		wp_safe_redirect( ige_ffm_url( 'parametres' ) );
		exit;
	}

	/**
	 * Création de l'exercice suivant.
	 */
	public static function handle_exercice_suivant() {
		ige_ffm_check_form( 'exercice_suivant' );
		ige_ffm_require( 'ige_administrer' );
		$r = IGE_FFM_Referentiels::creer_exercice_suivant();
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Création refusée', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Exercice préparé', 'Les Responsables peuvent y saisir leur budget (onglet de l’exercice sur l’écran Budget). Budget obligatoire par défaut ; modifiable ci-dessous.' );
		}
		wp_safe_redirect( ige_ffm_url( 'parametres' ) );
		exit;
	}

	/**
	 * Obligation de budget.
	 */
	public static function handle_exercice_budget() {
		ige_ffm_check_form( 'exercice_budget' );
		ige_ffm_require( 'ige_administrer' );
		IGE_FFM_Referentiels::set_budget_obligatoire( (int) ige_ffm_post( 'id' ), '1' === ige_ffm_post( 'obligatoire' ) );
		ige_ffm_flash( 'ok', 'Exercice mis à jour', '' );
		wp_safe_redirect( ige_ffm_url( 'parametres' ) );
		exit;
	}
}
