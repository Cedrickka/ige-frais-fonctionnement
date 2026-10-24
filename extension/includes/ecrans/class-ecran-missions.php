<?php
/**
 * Écran « Missions d'inspection » (VA-40) : ordres de mission, calcul selon le barème, visas,
 * ordre de mission officiel (PDF avec code QR), paiement, rapport de retour, liquidation.
 * Le barème se règle dans Paramètres (Administration et Finance).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Missions.
 */
final class IGE_FFM_Ecran_Missions {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_mission', array( __CLASS__, 'handle_creer' ) );
		add_action( 'admin_post_ige_ffm_mission_payer', array( __CLASS__, 'handle_payer' ) );
		add_action( 'admin_post_ige_ffm_mission_deposer', array( __CLASS__, 'handle_deposer' ) );
		add_action( 'admin_post_ige_ffm_mission_liquider', array( __CLASS__, 'handle_liquider' ) );
		add_action( 'admin_post_ige_ffm_mission_annuler', array( __CLASS__, 'handle_annuler' ) );
		add_action( 'admin_post_ige_ffm_bareme', array( __CLASS__, 'handle_bareme' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Missions d’inspection';
		$args['subtitle'] = 'Ordre de mission, frais selon le barème, visas, paiement, rapport et liquidation';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Service consulté (0 = tous, pour le central).
	 *
	 * @return int|null Null si aucun accès.
	 */
	private static function sid() {
		$own = IGE_FFM_Contexte::service_utilisateur();
		if ( $own ) {
			return $own;
		}
		if ( ! current_user_can( 'ige_voir_tout' ) ) {
			return null;
		}
		return isset( $_GET['service'] ) ? (int) $_GET['service'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex  = IGE_FFM_Contexte::exercice();
		$sid = self::sid();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		if ( null === $sid ) {
			return ige_ffm_banner( 'warn', 'Compte non rattaché à un service', ' Demandez à Administration et Finance de rattacher votre compte à votre service.' );
		}
		$id = isset( $_GET['mission'] ) ? (int) $_GET['mission'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			return self::detail( $id );
		}
		$exo   = (int) $ex['id'];
		$own   = IGE_FFM_Contexte::service_utilisateur();
		$base  = ige_ffm_url( 'missions' );
		$k     = IGE_FFM_Missions::chiffres( $exo, $sid );
		$liste = IGE_FFM_Missions::liste( $exo, $sid );
		$b     = IGE_FFM_Missions::bareme();
		$peut  = $own ? IGE_FFM_Contexte::peut_saisir( $own ) : current_user_can( 'ige_gerer_fonds' );
		$old   = IGE_FFM_Ecrans::old();
		ob_start();
		if ( ! $b['officiel'] ) {
			echo ige_ffm_banner( 'warn', 'Barème d’exemple', ' Les montants du barème sont fictifs : Administration et Finance doit saisir le barème officiel dans Paramètres avant les premières missions réelles.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Missions de l’exercice</div><div class="ige-val"><?php echo (int) $k['nb']; ?></div><div class="ige-meta"><?php echo (int) $k['en_cours']; ?> en cours sur le terrain</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Coût des missions payées</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $k['cout'], '' ) ); ?></div><div class="ige-meta">CDF, réel après liquidation</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Coût moyen</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $k['moyen'], '' ) ); ?></div><div class="ige-meta"><?php echo esc_html( ige_ffm_montant( $k['par_insp'] ) ); ?> par membre</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Rapports manquants</div><div class="ige-val<?php echo $k['manquants'] ? ' ige-neg' : ''; ?>"><?php echo (int) $k['manquants']; ?></div><div class="ige-meta">missions terminées sans rapport</div></div>
		</div>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<div class="ige-toolbar">
					<h2 style="margin:0">Ordres de mission · <?php echo esc_html( $ex['annee'] ); ?></h2>
					<?php if ( ! $own ) : ?>
						<form method="get" style="margin-left:auto"><select name="service" class="ige-input" style="width:auto" onchange="this.form.submit()"><option value="">Tous les services</option><?php foreach ( IGE_FFM_Contexte::services_visibles() as $s ) : ?><option value="<?php echo esc_attr( $s['id'] ); ?>"<?php selected( $sid, (int) $s['id'] ); ?>><?php echo esc_html( $s['code'] ); ?></option><?php endforeach; ?></select></form>
					<?php endif; ?>
				</div>
				<div class="ige-table-wrap"><table class="ige-table">
					<thead><tr><th>N°</th><?php echo $own ? '' : '<th>Service</th>'; ?><th>Objet · destination</th><th>Dates</th><th class="ige-r">Montant</th><th>Étape</th></tr></thead>
					<tbody>
					<?php foreach ( $liste as $m ) : ?>
						<?php $et = IGE_FFM_Missions::etape( $m ); ?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'mission', $m['id'], $base ) ); ?>"><b><?php echo esc_html( $m['numero'] ); ?></b></a></td>
							<?php if ( ! $own ) : ?><td><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $m['service_id'] ) ); ?></td><?php endif; ?>
							<td><?php echo esc_html( $m['objet'] ); ?><br><span class="ige-small ige-muted"><?php echo esc_html( $m['destination'] ); ?></span></td>
							<td style="white-space:nowrap"><?php echo esc_html( wp_date( 'd/m', strtotime( $m['date_depart'] ) ) . ' → ' . wp_date( 'd/m/Y', strtotime( $m['date_retour'] ) ) ); ?></td>
							<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( null === $m['cout_reel'] ? (int) $m['montant'] : (int) $m['cout_reel'], '' ) ); ?></td>
							<td><?php echo ige_ffm_pill( $et[0], $et[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $liste ) : ?><tr><td colspan="6" class="ige-empty">Aucune mission pour cet exercice.</td></tr><?php endif; ?>
					</tbody>
				</table></div>
			</section>
			<?php if ( $peut ) : ?>
				<section class="ige-card">
					<h2 style="margin-top:0">Nouvel ordre de mission</h2>
					<?php echo IGE_FFM_Ecrans::form_open( 'mission', 'id="ige-mission-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( ! $own ) : ?>
							<?php
							$opts = array( '' => '— Service qui organise et paie —' );
							foreach ( IGE_FFM_Contexte::services_visibles() as $s ) {
								$opts[ $s['id'] ] = $s['code'];
							}
							echo IGE_FFM_Ecrans::field( 'service_id', 'Service', $old['service_id'] ?? '', array( 'type' => 'select', 'options' => $opts, 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						<?php endif; ?>
						<?php echo IGE_FFM_Ecrans::field( 'objet', 'Objet de la mission', $old['objet'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="255" placeholder="Ex. : inspection des écoles conventionnées"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-grid ige-cols-2" style="gap:0 12px">
							<?php echo IGE_FFM_Ecrans::field( 'destination', 'Destination', $old['destination'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="190" placeholder="Ville, province"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo IGE_FFM_Ecrans::field( 'zone', 'Zone (barème)', $old['zone'] ?? 'province', array( 'type' => 'select', 'options' => array( 'province' => 'Province', 'kinshasa' => 'Kinshasa' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo IGE_FFM_Ecrans::field( 'date_depart', 'Départ', $old['date_depart'] ?? '', array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( $ex['date_debut'] ) . '" max="' . esc_attr( $ex['date_fin'] ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo IGE_FFM_Ecrans::field( 'date_retour', 'Retour', $old['date_retour'] ?? '', array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( $ex['date_debut'] ) . '" max="' . esc_attr( $ex['date_fin'] ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<?php echo IGE_FFM_Ecrans::field( 'transport', 'Moyen de transport', $old['transport'] ?? '', array( 'attrs' => 'maxlength="60" placeholder="Route, avion, bateau…"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><span style="font-size:12.5px;font-weight:600">Membres de la mission</span>
							<table class="ige-membres">
								<thead><tr><th>Nom</th><th>Fonction</th><th>Catégorie</th></tr></thead>
								<tbody>
								<?php for ( $i = 0; $i < 6; $i++ ) : ?>
									<?php $mo = $old['membres'][ $i ] ?? array(); ?>
									<tr>
										<td><input type="text" name="membres[<?php echo (int) $i; ?>][nom]" value="<?php echo esc_attr( $mo['nom'] ?? '' ); ?>" maxlength="120" aria-label="Nom du membre <?php echo (int) $i + 1; ?>"<?php echo 0 === $i ? ' required' : ''; ?>></td>
										<td><input type="text" name="membres[<?php echo (int) $i; ?>][fonction]" value="<?php echo esc_attr( $mo['fonction'] ?? '' ); ?>" maxlength="120" aria-label="Fonction du membre <?php echo (int) $i + 1; ?>"></td>
										<td><select name="membres[<?php echo (int) $i; ?>][categorie]" aria-label="Catégorie du membre <?php echo (int) $i + 1; ?>"><?php foreach ( $b['categories'] as $c => $x ) : ?><option value="<?php echo esc_attr( $c ); ?>"<?php selected( $mo['categorie'] ?? 'inspecteur', $c ); ?>><?php echo esc_html( $x['libelle'] ); ?></option><?php endforeach; ?></select></td>
									</tr>
								<?php endfor; ?>
								</tbody>
							</table>
							<span class="ige-help">Lignes vides ignorées. Les frais sont calculés à l’enregistrement selon le barème (indemnités par jour, hébergement par nuit, transport aller-retour, frais divers par mission).</span>
						</div>
						<button type="submit" class="ige-btn ige-btn-jaune">Soumettre aux visas</button>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Détail d'une mission.
	 *
	 * @param int $id Mission.
	 * @return string
	 */
	private static function detail( $id ) {
		$m = IGE_FFM_Missions::get( $id );
		if ( ! $m || ! IGE_FFM_Contexte::peut_voir( (int) $m['service_id'] ) ) {
			return ige_ffm_banner( 'ko', 'Mission introuvable', '' );
		}
		$sid    = (int) $m['service_id'];
		$own    = IGE_FFM_Contexte::service_utilisateur() === $sid && IGE_FFM_Contexte::peut_saisir( $sid );
		$retour = add_query_arg( 'mission', $id, ige_ffm_url( 'missions' ) );
		$etat   = IGE_FFM_Visas::etat( 'mission', $id, (int) $m['montant'] );
		$et     = IGE_FFM_Missions::etape( $m );
		$doc    = IGE_FFM_Documents::pour_objet( 'ordre_mission', $id );
		$pieces = IGE_FFM_Pieces::liste( 'mission', $id );
		$e      = $m['ecriture_id'] ? IGE_FFM_Ecritures::get( (int) $m['ecriture_id'] ) : null;
		$auj    = IGE_FFM_Contexte::aujourdhui();
		ob_start();
		?>
		<p><a href="<?php echo esc_url( ige_ffm_url( 'missions' ) ); ?>">← Toutes les missions</a></p>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card">
				<h2 style="margin-top:0"><?php echo esc_html( $m['numero'] . ' · ' . $m['objet'] ); ?> <?php echo ige_ffm_pill( $et[0], $et[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
				<div class="ige-om ige-table-wrap" style="overflow-x:auto"><?php echo wp_kses_post( IGE_FFM_Missions::contenu_om( $m ) ); ?></div>
				<h3 style="font-size:14px;margin:14px 0 6px">Visas</h3>
				<?php echo IGE_FFM_Visas::frise( $etat ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php
				if ( 'soumise' === $m['statut'] ) {
					echo IGE_FFM_Visas::formulaire( 'mission', $id, $etat, $sid, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
				<?php if ( 'refusee' === $m['statut'] || 'annulee' === $m['statut'] ) : ?>
					<p class="ige-small"><b>Motif :</b> <?php echo esc_html( $m['motif'] ); ?></p>
				<?php endif; ?>
				<h3 style="font-size:14px;margin:14px 0 6px">Pièces de la mission</h3>
				<ul class="ige-small" style="margin:0;padding-left:18px">
					<?php if ( $doc ) : ?><li><a href="<?php echo esc_url( IGE_FFM_Pdf::url( array( 'quoi' => 'om', 'id' => $id ) ) ); ?>">Ordre de mission officiel <?php echo esc_html( $doc['reference'] ); ?> (PDF, code QR)</a></li><?php endif; ?>
					<?php if ( $e ) : ?><li>Paiement : <a href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], ige_ffm_url( 'dossier-de-depense' ) ) ); ?>"><?php echo esc_html( $e['id_ecriture'] ); ?></a> du <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $e['date_operation'] ) ) ); ?></li><?php endif; ?>
					<?php foreach ( $pieces as $p ) : ?>
						<li><a href="<?php echo esc_url( IGE_FFM_Pieces::url( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( ( $p['nature'] ?: 'Pièce' ) . ' · ' . $p['nom_original'] ); ?></a></li>
					<?php endforeach; ?>
					<?php if ( ! $doc && ! $e && ! $pieces ) : ?><li class="ige-muted">L’ordre de mission officiel est émis après le dernier visa.</li><?php endif; ?>
				</ul>
				<?php if ( 'liquidee' === $m['statut'] ) : ?>
					<p class="ige-small"><b>Liquidation :</b> coût réel <?php echo esc_html( ige_ffm_montant( (int) $m['cout_reel'] ) ); ?><?php echo (int) $m['cout_reel'] !== (int) $m['montant'] ? esc_html( ' (écart ' . ige_ffm_montant( (int) $m['cout_reel'] - (int) $m['montant'] ) . ' : ' . $m['motif'] . ')' ) : ''; ?>.</p>
				<?php endif; ?>
			</section>
			<aside class="ige-card">
				<?php if ( $own && 'approuvee' === $m['statut'] ) : ?>
					<h2 style="margin-top:0">Payer les frais</h2>
					<p class="ige-small">Une sortie de <?php echo esc_html( ige_ffm_montant( (int) $m['montant'] ) ); ?> est inscrite dans votre livre de caisse (rubrique Missions), l’ordre de mission en pièce jointe. Les membres émargent l’état de paiement.</p>
					<?php
					$modes = wp_list_pluck( array_filter( IGE_FFM_Referentiels::all( 'mode_paiement' ), static fn( $x ) => (int) $x['actif'] ), 'libelle', 'id' );
					echo IGE_FFM_Ecrans::form_open( 'mission_payer' ) . '<input type="hidden" name="mission" value="' . esc_attr( $id ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'date', 'Date du paiement', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'mode_paiement_id', 'Mode de paiement', '', array( 'type' => 'select', 'options' => $modes ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer le paiement</button></form>';
					?>
				<?php endif; ?>
				<?php if ( $own && in_array( $m['statut'], array( 'payee', 'liquidee' ), true ) ) : ?>
					<h2 style="margin-top:0">Pièces de retour</h2>
					<?php echo IGE_FFM_Ecrans::form_open( 'mission_deposer', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="mission" value="<?php echo esc_attr( $id ); ?>">
						<?php echo IGE_FFM_Ecrans::field( 'nature', 'Nature', '', array( 'type' => 'select', 'options' => array( 'Rapport de mission' => 'Rapport de mission', 'État de paiement' => 'État de paiement émargé' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><label for="ige-fichier-mission">Fichier (PDF, photo)</label><input type="file" id="ige-fichier-mission" name="fichier" required accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"></div>
						<button type="submit" class="ige-btn ige-btn-line">Déposer</button>
					</form>
				<?php endif; ?>
				<?php if ( $own && 'payee' === $m['statut'] ) : ?>
					<h2>Liquider</h2>
					<?php if ( $auj < $m['date_retour'] ) : ?>
						<p class="ige-small ige-muted">Après le retour, le <?php echo esc_html( wp_date( 'd/m/Y', strtotime( $m['date_retour'] ) ) ); ?>.</p>
					<?php elseif ( ! $m['rapport_piece_id'] ) : ?>
						<p class="ige-small ige-muted">Déposez d’abord le rapport de mission.</p>
					<?php else : ?>
						<?php echo IGE_FFM_Ecrans::form_open( 'mission_liquider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="mission" value="<?php echo esc_attr( $id ); ?>">
							<?php echo IGE_FFM_Ecrans::field( 'reel', 'Frais réels (CDF)', ige_ffm_montant( (int) $m['montant'], '' ), array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"', 'help' => 'Un écart crée un complément (sortie) ou un reversement (entrée) dans la caisse.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo IGE_FFM_Ecrans::field( 'motif', 'Explication de l’écart', '', array( 'attrs' => 'maxlength="200"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<button type="submit" class="ige-btn ige-btn-jaune">Liquider la mission</button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( in_array( $m['statut'], array( 'soumise', 'approuvee' ), true ) && ( $own || current_user_can( 'ige_gerer_fonds' ) ) ) : ?>
					<h2>Annuler</h2>
					<?php echo IGE_FFM_Ecrans::form_open( 'mission_annuler', 'data-ige-confirm="Annuler cet ordre de mission ?"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="mission" value="<?php echo esc_attr( $id ); ?>">
						<?php echo IGE_FFM_Ecrans::field( 'motif', 'Motif', '', array( 'required' => true, 'attrs' => 'minlength="5" maxlength="200"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<button type="submit" class="ige-btn ige-btn-line">Annuler la mission</button>
					</form>
				<?php endif; ?>
				<?php if ( in_array( $m['statut'], array( 'refusee', 'annulee', 'liquidee' ), true ) || ( ! $own && ! in_array( $m['statut'], array( 'soumise', 'approuvee' ), true ) ) ) : ?>
					<dl class="ige-kv">
						<dt>Service</dt><dd><?php echo esc_html( IGE_FFM_Comptes::service_code( $sid ) ); ?></dd>
						<dt>Montant</dt><dd><?php echo esc_html( ige_ffm_montant( (int) $m['montant'] ) ); ?></dd>
						<dt>Étape</dt><dd><?php echo esc_html( $et[0] ); ?></dd>
					</dl>
				<?php endif; ?>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Redirection vers la mission.
	 *
	 * @param int $id Mission.
	 */
	private static function vers( $id ) {
		wp_safe_redirect( $id ? add_query_arg( 'mission', (int) $id, ige_ffm_url( 'missions' ) ) : ige_ffm_url( 'missions' ) );
		exit;
	}

	/**
	 * Création.
	 */
	public static function handle_creer() {
		ige_ffm_check_form( 'mission' );
		ige_ffm_require( 'ige_acceder' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$membres = array();
		foreach ( (array) ( isset( $_POST['membres'] ) ? wp_unslash( $_POST['membres'] ) : array() ) as $x ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $x ) ) {
				$membres[] = array(
					'nom'       => sanitize_text_field( (string) ( $x['nom'] ?? '' ) ),
					'fonction'  => sanitize_text_field( (string) ( $x['fonction'] ?? '' ) ),
					'categorie' => sanitize_key( (string) ( $x['categorie'] ?? '' ) ),
				);
			}
		}
		// phpcs:enable
		$data = array(
			'service_id'  => (int) ige_ffm_post( 'service_id' ),
			'objet'       => ige_ffm_post( 'objet' ),
			'destination' => ige_ffm_post( 'destination' ),
			'zone'        => ige_ffm_post( 'zone' ),
			'date_depart' => ige_ffm_post( 'date_depart' ),
			'date_retour' => ige_ffm_post( 'date_retour' ),
			'transport'   => ige_ffm_post( 'transport' ),
			'membres'     => array_slice( $membres, 0, 12 ),
		);
		$r    = IGE_FFM_Missions::creer( $data );
		if ( is_wp_error( $r ) ) {
			IGE_FFM_Ecrans::remember( $data );
			ige_ffm_flash( 'ko', 'Ordre de mission non enregistré', $r->get_error_message() );
			self::vers( 0 );
		}
		$m = IGE_FFM_Missions::get( $r );
		ige_ffm_flash( 'ok', 'Ordre de mission ' . $m['numero'] . ' soumis', 'Frais calculés : ' . ige_ffm_montant( (int) $m['montant'] ) . '. Le premier visa est demandé par e-mail.' );
		self::vers( $r );
	}

	/**
	 * Paiement.
	 */
	public static function handle_payer() {
		ige_ffm_check_form( 'mission_payer' );
		ige_ffm_require( 'ige_saisir' );
		$id = (int) ige_ffm_post( 'mission' );
		$r  = IGE_FFM_Missions::payer( $id, array( 'date' => ige_ffm_post( 'date' ), 'mode_paiement_id' => (int) ige_ffm_post( 'mode_paiement_id' ) ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Paiement non enregistré', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Paiement enregistré : ' . IGE_FFM_Ecritures::get( $r )['id_ecriture'], 'L’ordre de mission est joint à l’écriture.' );
		}
		self::vers( $id );
	}

	/**
	 * Dépôt d'une pièce de retour.
	 */
	public static function handle_deposer() {
		ige_ffm_check_form( 'mission_deposer' );
		ige_ffm_require( 'ige_saisir' );
		$id   = (int) ige_ffm_post( 'mission' );
		$file = isset( $_FILES['fichier'] ) ? $_FILES['fichier'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			ige_ffm_flash( 'ko', 'Aucun fichier', 'Choisissez le fichier à déposer.' );
			self::vers( $id );
		}
		$r = IGE_FFM_Missions::deposer( $id, $file, ige_ffm_post( 'nature' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Dépôt refusé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Pièce déposée', '' );
		}
		self::vers( $id );
	}

	/**
	 * Liquidation.
	 */
	public static function handle_liquider() {
		ige_ffm_check_form( 'mission_liquider' );
		ige_ffm_require( 'ige_saisir' );
		$id = (int) ige_ffm_post( 'mission' );
		$r  = IGE_FFM_Missions::liquider( $id, ige_ffm_post( 'reel' ), ige_ffm_post( 'motif' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Liquidation refusée', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Mission liquidée', '' );
		}
		self::vers( $id );
	}

	/**
	 * Annulation.
	 */
	public static function handle_annuler() {
		ige_ffm_check_form( 'mission_annuler' );
		ige_ffm_require( 'ige_acceder' );
		$id = (int) ige_ffm_post( 'mission' );
		$r  = IGE_FFM_Missions::annuler( $id, ige_ffm_post( 'motif' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Annulation refusée', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Ordre de mission annulé', '' );
		}
		self::vers( $id );
	}

	/**
	 * Formulaire du barème (affiché dans Paramètres).
	 *
	 * @return string
	 */
	public static function formulaire_bareme() {
		$b = IGE_FFM_Missions::bareme();
		$h = '<section class="ige-card" id="bareme-missions"><h2 style="margin-top:0">Barème des frais de mission</h2>'
			. ( $b['officiel'] ? '' : ige_ffm_banner( 'warn', 'Barème d’exemple', ' Ces montants sont fictifs. Saisissez le barème officiel puis enregistrez.' ) )
			. IGE_FFM_Ecrans::form_open( 'bareme' )
			. '<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Catégorie</th><th class="ige-r">Indemnité par jour</th><th class="ige-r">Hébergement par nuit</th></tr></thead><tbody>';
		foreach ( $b['categories'] as $c => $x ) {
			$h .= '<tr><td>' . esc_html( $x['libelle'] ) . '</td>'
				. '<td><input type="text" inputmode="numeric" class="ige-num" name="indemnite[' . esc_attr( $c ) . ']" value="' . esc_attr( ige_ffm_montant( (int) $x['indemnite'], '' ) ) . '" aria-label="Indemnité ' . esc_attr( $x['libelle'] ) . '"></td>'
				. '<td><input type="text" inputmode="numeric" class="ige-num" name="hebergement[' . esc_attr( $c ) . ']" value="' . esc_attr( ige_ffm_montant( (int) $x['hebergement'], '' ) ) . '" aria-label="Hébergement ' . esc_attr( $x['libelle'] ) . '"></td></tr>';
		}
		$h .= '</tbody></table></div><div class="ige-grid ige-cols-2" style="gap:0 12px;margin-top:10px">'
			. IGE_FFM_Ecrans::field( 'transport[kinshasa]', 'Transport aller-retour, Kinshasa (par membre)', ige_ffm_montant( (int) $b['transport']['kinshasa'], '' ), array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'transport[province]', 'Transport aller-retour, province (par membre)', ige_ffm_montant( (int) $b['transport']['province'], '' ), array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'frais_divers', 'Frais divers forfaitaires (par mission)', ige_ffm_montant( (int) $b['frais_divers'], '' ), array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. '</div><p class="ige-small ige-muted">Montants en francs congolais. Le nouveau barème s’applique aux ordres de mission créés ensuite ; les ordres déjà émis gardent leur calcul.</p>'
			. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer le barème officiel</button></form></section>';
		return $h;
	}

	/**
	 * Enregistrement du barème.
	 */
	public static function handle_bareme() {
		ige_ffm_check_form( 'bareme' );
		ige_ffm_require( 'ige_administrer' );
		$in = array();
		foreach ( array( 'indemnite', 'hebergement', 'transport' ) as $k ) {
			$in[ $k ] = isset( $_POST[ $k ] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST[ $k ] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$in['frais_divers'] = ige_ffm_post( 'frais_divers' );
		$r                  = IGE_FFM_Missions::enregistrer_bareme( $in );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Barème non enregistré', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Barème des missions enregistré', 'Il s’applique aux prochains ordres de mission.' );
		}
		wp_safe_redirect( ige_ffm_url( 'parametres' ) . '#bareme-missions' );
		exit;
	}
}
