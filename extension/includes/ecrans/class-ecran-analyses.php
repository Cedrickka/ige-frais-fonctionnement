<?php
/**
 * Écrans d'analyse :
 * - « Risques » (VA-21, VA-23) : score de risque par service et comparaison des services ;
 * - « Notifications » (VA-34) : rapport hebdomadaire par e-mail ;
 * - « Fonctions du site » est dans class-ecran-fonctions.php.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Risques et comparaison.
 */
final class IGE_FFM_Ecran_Risques {

	/**
	 * Crochets.
	 */
	public static function register() {}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Risques et comparaison des services';
		$args['subtitle'] = 'Où porter les contrôles sur place, et quels écarts de dépense examiner';
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
		$o    = isset( $_GET['onglet'] ) && 'comparaison' === $_GET['onglet'] ? 'comparaison' : 'risques'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = ige_ffm_url( 'risques' );
		$h    = '<div class="ige-tabs" role="tablist" style="margin-bottom:14px"><a role="tab" class="' . ( 'risques' === $o ? 'ige-on' : '' ) . '" href="' . esc_url( $base ) . '">Score de risque</a><a role="tab" class="' . ( 'comparaison' === $o ? 'ige-on' : '' ) . '" href="' . esc_url( add_query_arg( 'onglet', 'comparaison', $base ) ) . '">Comparaison des services</a></div>';
		return $h . ( 'risques' === $o ? self::risques( (int) $ex['id'] ) : self::comparaison( (int) $ex['id'] ) );
	}

	/**
	 * Scores.
	 *
	 * @param int $exo Exercice.
	 * @return string
	 */
	private static function risques( $exo ) {
		$r   = IGE_FFM_Analyses::risques( $exo );
		$fac = IGE_FFM_Analyses::facteurs();
		$el  = count( array_filter( $r, static fn( $x ) => 'ko' === $x['niveau'][1] ) );
		$mo  = count( array_filter( $r, static fn( $x ) => 'wait' === $x['niveau'][1] ) );
		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi<?php echo $el ? ' ige-alerte' : ''; ?>"><div class="ige-lbl">Risque élevé</div><div class="ige-val"><?php echo (int) $el; ?></div><div class="ige-meta">score de 60 ou plus</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Risque moyen</div><div class="ige-val"><?php echo (int) $mo; ?></div><div class="ige-meta">de 35 à 60</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Services suivis</div><div class="ige-val"><?php echo count( $r ); ?></div><div class="ige-meta"><?php echo count( array_filter( $r, static fn( $x ) => ! $x['activite'] ) ); ?> sans écriture</div></div>
		</div>
		<section class="ige-card" style="padding:16px">
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Service</th><th>Score</th><?php foreach ( $fac as $f ) : ?><th class="ige-r" title="<?php echo esc_attr( $f[2] ); ?>"><?php echo esc_html( $f[0] ); ?></th><?php endforeach; ?></tr></thead>
				<tbody>
				<?php $tous = isset( $_GET['tous'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php foreach ( $r as $x ) : ?>
					<?php
					if ( ! $x['activite'] && ! $tous ) {
						continue;
					}
					?>
					<tr>
						<td><b><?php echo esc_html( $x['service']['code'] ); ?></b><?php echo $x['activite'] ? '' : '<br><span class="ige-small ige-muted">sans écriture</span>'; ?></td>
						<td style="white-space:nowrap"><div class="ige-bar" style="width:90px;display:inline-block;vertical-align:middle"><i style="width:<?php echo esc_attr( min( 100, $x['score'] ) ); ?>%"></i></div> <b><?php echo esc_html( number_format( $x['score'], 0 ) ); ?></b> <?php echo ige_ffm_pill( $x['niveau'][0], $x['niveau'][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<?php foreach ( array_keys( $fac ) as $k ) : ?>
							<?php $f = $x['facteurs'][ $k ]; ?>
							<td class="ige-r ige-small<?php echo $f[0] >= 60 ? ' ige-neg' : ''; ?>" title="<?php echo esc_attr( $f[1] ); ?>"><b><?php echo esc_html( number_format( $f[0], 0 ) ); ?></b><br><span class="ige-muted"><?php echo esc_html( $f[1] ); ?></span></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php $sans = count( array_filter( $r, static fn( $x ) => ! $x['activite'] ) ); ?>
			<?php if ( $sans ) : ?>
				<p class="ige-small ige-muted" style="margin:10px 0 0"><?php echo esc_html( $sans . ' service(s) sans écriture sur l’exercice ' . ( $tous ? 'affichés.' : 'masqués.' ) ); ?> <a href="<?php echo esc_url( $tous ? ige_ffm_url( 'risques' ) : ige_ffm_url( 'risques', array( 'tous' => 1 ) ) ); ?>"><?php echo $tous ? 'Les masquer' : 'Les afficher'; ?></a></p>
			<?php endif; ?>
		</section>
		<section class="ige-card">
			<h2 style="margin-top:0">Comment le score est calculé</h2>
			<p class="ige-small">Chaque facteur donne un risque de 0 à 100 ; le score est leur moyenne pondérée. Il oriente les contrôles sur place et les comptages inopinés ; il ne constitue pas un jugement sur le service.</p>
			<ul class="ige-small" style="margin:0;padding-left:18px">
				<?php foreach ( $fac as $f ) : ?><li><b><?php echo esc_html( $f[0] ); ?></b> (poids <?php echo (int) $f[1]; ?>) : <?php echo esc_html( $f[2] ); ?></li><?php endforeach; ?>
			</ul>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Comparaison.
	 *
	 * @param int $exo Exercice.
	 * @return string
	 */
	private static function comparaison( $exo ) {
		$rid  = isset( $_GET['rubrique'] ) ? (int) $_GET['rubrique'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$c    = IGE_FFM_Analyses::comparaison( $exo, $rid );
		$max  = max( 1, max( array_map( static fn( $x ) => (int) $x['par_agent'], $c['lignes'] ) ?: array( 1 ) ) );
		ob_start();
		?>
		<?php if ( $c['sans_effectif'] ) : ?>
			<?php echo ige_ffm_banner( 'info', $c['sans_effectif'] . ' service(s) sans effectif', ' Renseignez le nombre d’agents dans <a href="' . esc_url( ige_ffm_url( 'referentiels' ) ) . '">Référentiels > Services</a> pour les comparer.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php endif; ?>
		<section class="ige-card" style="padding:16px">
			<form method="get" class="ige-toolbar"><input type="hidden" name="onglet" value="comparaison">
				<label class="ige-small" for="ige-rub" style="font-weight:600">Rubrique</label>
				<select id="ige-rub" name="rubrique" class="ige-input" style="width:auto" onchange="this.form.submit()"><option value="0">Toutes les rubriques</option><?php foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) : ?><option value="<?php echo esc_attr( $r['id'] ); ?>"<?php selected( $rid, (int) $r['id'] ); ?>><?php echo esc_html( $r['code'] . ' · ' . $r['libelle'] ); ?></option><?php endforeach; ?></select>
				<noscript><button class="ige-btn ige-btn-line" type="submit">Afficher</button></noscript>
				<span class="ige-small ige-muted" style="margin-left:auto">Médiane par agent : <b><?php echo esc_html( null === $c['mediane'] ? '—' : ige_ffm_montant( (int) round( $c['mediane'] ) ) ); ?></b></span>
			</form>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Service</th><th class="ige-r">Effectif</th><th class="ige-r">Budget</th><th class="ige-r">Dépenses validées</th><th class="ige-r">Exécution</th><th class="ige-r">Budget par agent</th><th>Dépense par agent</th><th class="ige-r">Indice</th></tr></thead>
				<tbody>
				<?php foreach ( $c['lignes'] as $x ) : ?>
					<tr>
						<td><b><?php echo esc_html( $x['service']['code'] ); ?></b></td>
						<td class="ige-r"><?php echo esc_html( $x['effectif'] ?: '—' ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( null === $x['budget'] ? '—' : ige_ffm_montant( $x['budget'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['depense'], '' ) ); ?></td>
						<td class="ige-r"><?php echo esc_html( null === $x['taux'] ? '—' : number_format( $x['taux'], 0 ) . ' %' ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( null === $x['budget_agent'] ? '—' : ige_ffm_montant( $x['budget_agent'], '' ) ); ?></td>
						<td style="min-width:180px"><?php if ( null !== $x['par_agent'] ) : ?><div class="ige-bar" style="display:inline-block;width:110px;vertical-align:middle"><i style="width:<?php echo esc_attr( round( $x['par_agent'] / $max * 100 ) ); ?>%"></i></div> <span class="ige-num"><?php echo esc_html( ige_ffm_montant( $x['par_agent'], '' ) ); ?></span><?php else : ?>—<?php endif; ?></td>
						<td class="ige-r"><?php echo null === $x['indice'] ? '—' : ( 'haut' === $x['signal'] ? ige_ffm_pill( $x['indice'] . ' : à examiner', 'ko' ) : ( 'bas' === $x['signal'] ? ige_ffm_pill( $x['indice'] . ' : faible', 'info' ) : esc_html( (string) $x['indice'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Dépenses validées par Administration et Finance. Indice = dépense par agent du service ÷ médiane des services × 100 ; au-delà de 150, l’écart est signalé, sous 50 il peut traduire une sous-exécution ou des saisies en retard. Comparez des services de missions comparables.</p>
		</section>
		<?php
		return ob_get_clean();
	}
}

/**
 * Notifications : rapport hebdomadaire.
 */
final class IGE_FFM_Ecran_Notifications {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_hebdo_reglages', array( __CLASS__, 'handle_reglages' ) );
		add_action( 'admin_post_ige_ffm_hebdo_envoyer', array( __CLASS__, 'handle_envoyer' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Notifications';
		$args['subtitle'] = 'Rapport hebdomadaire et e-mails envoyés par la plateforme';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$r     = IGE_FFM_Analyses::reglages_hebdo();
		$jours = array( 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche' );
		$heures = array();
		for ( $i = 5; $i <= 20; $i++ ) {
			$heures[ $i ] = sprintf( '%02d h', $i );
		}
		$actif = IGE_FFM_Fonctions::active( 'rapport_hebdo' );
		$c     = IGE_FFM_Analyses::contenu_hebdo();
		$dest  = IGE_FFM_Analyses::destinataires();
		ob_start();
		if ( ! $actif ) {
			echo ige_ffm_banner( 'info', 'Rapport hebdomadaire désactivé', ' Il se réactive dans <a href="' . esc_url( ige_ffm_url( 'fonctions-du-site' ) ) . '">Fonctions du site</a>.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-cols-2 ige-start">
			<section class="ige-card">
				<h2 style="margin-top:0">Rapport hebdomadaire (VA-34)</h2>
				<p class="ige-small">Chaque semaine, les comptes Administration et Finance reçoivent leurs priorités : écritures à vérifier, alertes (budget, retards, avances, arrêtés, parc, stocks), fonds à décaisser, services à risque.</p>
				<?php
				echo ! $actif ? '' : IGE_FFM_Ecrans::form_open( 'hebdo_reglages' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. IGE_FFM_Ecrans::field( 'actif', 'Envoyer le rapport chaque semaine', $r['actif'], array( 'type' => 'checkbox' ) )
					. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
					. IGE_FFM_Ecrans::field( 'jour', 'Jour', (string) $r['jour'], array( 'type' => 'select', 'options' => $jours ) )
					. IGE_FFM_Ecrans::field( 'heure', 'À partir de', (string) $r['heure'], array( 'type' => 'select', 'options' => $heures ) )
					. '</div>'
					. IGE_FFM_Ecrans::field( 'copie', 'Copie à (facultatif)', $r['copie'], array( 'attrs' => 'maxlength="500" placeholder="adresse1@…, adresse2@…"', 'help' => 'Par exemple le secrétariat de l’Inspecteur Général.' ) )
					. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer</button></form>';
				?>
				<p class="ige-small ige-muted" style="margin-top:12px">Destinataires (<?php echo count( $dest ); ?>) : <?php echo esc_html( implode( ', ', array_map( array( 'IGE_FFM_Messagerie', 'masquer' ), $dest ) ) ?: 'aucun compte Administration et Finance avec une adresse e-mail' ); ?>.<?php echo $r['dernier'] ? ' Dernier envoi automatique : semaine ' . esc_html( $r['dernier'] ) . '.' : ''; ?></p>
				<?php if ( $actif ) : ?>
					<?php echo IGE_FFM_Ecrans::action_button( 'hebdo_envoyer', array(), 'M’envoyer le rapport maintenant', 'ige-btn ige-btn-line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php endif; ?>
			</section>
			<section class="ige-card">
				<h2 style="margin-top:0">Aperçu</h2>
				<p class="ige-small"><b>Objet :</b> [IGE] <?php echo esc_html( $c['sujet'] ); ?></p>
				<div class="ige-small" style="border:1px solid var(--ige-ligne,#e3e6ea);border-radius:8px;padding:12px 14px;background:var(--ige-fond,#f7f8fa)"><?php echo wp_kses_post( wpautop( make_clickable( esc_html( $c['texte'] ) ) ) ); ?></div>
			</section>
		</div>
		<section class="ige-card">
			<h2 style="margin-top:0">Autres e-mails de la plateforme</h2>
			<p class="ige-small">Envoyés au fil de l’eau, sans réglage : code de connexion, invitation, rejet et question sur une écriture, réponse du service, visas attendus (demandes de fonds, ordonnancements, missions), décaissement, attribution ou refus d’un véhicule, retour d’un véhicule avec observations. La messagerie se règle dans <a href="<?php echo esc_url( ige_ffm_url( 'parametres' ) ); ?>">Exercice et règles de contrôle</a>.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Réglages.
	 */
	public static function handle_reglages() {
		ige_ffm_check_form( 'hebdo_reglages' );
		ige_ffm_require( 'ige_administrer' );
		$r = IGE_FFM_Analyses::enregistrer_hebdo( array( 'actif' => ige_ffm_post( 'actif' ), 'jour' => ige_ffm_post( 'jour' ), 'heure' => ige_ffm_post( 'heure' ), 'copie' => ige_ffm_post( 'copie' ) ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Réglage refusé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Rapport hebdomadaire réglé', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'notifications' ) );
		exit;
	}

	/**
	 * Envoi immédiat à soi-même.
	 */
	public static function handle_envoyer() {
		ige_ffm_check_form( 'hebdo_envoyer' );
		ige_ffm_require( 'ige_administrer' );
		$u = wp_get_current_user();
		if ( ! is_email( $u->user_email ) ) {
			ige_ffm_flash( 'ko', 'Envoi impossible', 'Votre compte n’a pas d’adresse e-mail.' );
		} else {
			IGE_FFM_Analyses::envoyer_hebdo( array( $u->user_email ) );
			ige_ffm_flash( 'ok', 'Rapport envoyé', 'Vérifiez votre boîte ' . IGE_FFM_Messagerie::masquer( $u->user_email ) . '.' );
		}
		wp_safe_redirect( ige_ffm_url( 'notifications' ) );
		exit;
	}
}
