<?php
/**
 * Écran Livre de caisse : écritures du service, solde progressif, détail, paiement des
 * engagements, contrepassation, ajout de pièces, export.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Livre de caisse.
 */
final class IGE_FFM_Ecran_Livre {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_payer', array( __CLASS__, 'handle_payer' ) );
		add_action( 'admin_post_ige_ffm_contrepasser', array( __CLASS__, 'handle_contrepasser' ) );
		add_action( 'admin_post_ige_ffm_piece_ajout', array( __CLASS__, 'handle_piece' ) );
		add_action( 'admin_post_ige_ffm_livre_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Filtres lus dans l'adresse.
	 *
	 * @return array
	 */
	private static function filtres() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array(
			'mois'        => isset( $_GET['mois'] ) ? max( 0, min( 12, (int) $_GET['mois'] ) ) : 0,
			'rubrique_id' => isset( $_GET['rubrique'] ) ? (int) $_GET['rubrique'] : 0,
			'statut'      => isset( $_GET['statut'] ) ? sanitize_key( $_GET['statut'] ) : '',
			'etat'        => isset( $_GET['etat'] ) ? sanitize_key( $_GET['etat'] ) : '',
		);
		// phpcs:enable
		if ( ! in_array( $f['statut'], array( '', 'payee', 'engagee' ), true ) ) {
			$f['statut'] = '';
		}
		if ( ! in_array( $f['etat'], array( '', 'non_transmise', 'a_retransmettre', 'a_verifier', 'validee', 'rejetee' ), true ) ) {
			$f['etat'] = '';
		}
		return $f;
	}

	/**
	 * Noms des mois.
	 *
	 * @return string[]
	 */
	public static function mois_noms() {
		return array( 1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre' );
	}

	/**
	 * Noms abrégés des mois.
	 *
	 * @return string[]
	 */
	public static function mois_courts() {
		return array( 1 => 'Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.' );
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
		$f   = self::filtres();
		$args['title']    = 'Livre de caisse';
		$args['subtitle'] = $sid && $ex ? IGE_FFM_Comptes::service_code( $sid ) . ' · exercice ' . $ex['annee'] . ( $f['mois'] ? ' · ' . strtolower( self::mois_noms()[ $f['mois'] ] ) : '' ) : '';
		$args['actions']  = '';
		if ( $sid && $ex && IGE_FFM_Contexte::peut_voir( $sid ) ) {
			$export = wp_nonce_url( add_query_arg( array_filter( array( 'action' => 'ige_ffm_livre_export', 'service' => $sid, 'mois' => $f['mois'], 'rubrique' => $f['rubrique_id'], 'statut' => $f['statut'], 'etat' => $f['etat'] ) ), admin_url( 'admin-post.php' ) ), 'ige_ffm_livre_export' );
			$pdf    = IGE_FFM_Pdf::url( array_filter( array( 'quoi' => 'livre', 'service' => $sid, 'mois' => $f['mois'], 'rubrique' => $f['rubrique_id'], 'statut' => $f['statut'], 'etat' => $f['etat'] ) ) );
			$args['actions'] .= '<a class="ige-btn ige-btn-ghost" href="' . esc_url( $export ) . '">Excel</a>';
			$args['actions'] .= '<a class="ige-btn ige-btn-ghost" href="' . esc_url( $pdf ) . '">PDF</a>';
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
			return $ctx['html'];
		}
		$exo   = (int) $ex['id'];
		$f     = self::filtres();
		$livre = IGE_FFM_Ecritures::livre( $exo, $sid, $f );
		$rows  = $livre['rows'];
		$rubs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
		$fourn = wp_list_pluck( IGE_FFM_Fournisseurs::all( false ), 'raison_sociale', 'id' );
		$npcs  = IGE_FFM_Pieces::compter_ecritures( wp_list_pluck( $rows, 'id' ) );
		$base  = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse' ), $sid );
		$sel   = isset( $_GET['ecriture'] ) ? (int) $_GET['ecriture'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tot_e = 0;
		$tot_s = 0;

		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $sel ) {
			echo self::detail( $sel, $sid, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<section class="ige-card" style="padding:16px">
			<form method="get" class="ige-toolbar ige-no-print" action="<?php echo esc_url( ige_ffm_url( 'livre-de-caisse' ) ); ?>">
				<?php if ( ! IGE_FFM_Contexte::service_utilisateur() ) : ?>
					<input type="hidden" name="service" value="<?php echo esc_attr( $sid ); ?>">
				<?php endif; ?>
				<label class="screen-reader-text" for="ige-f-mois">Mois</label>
				<select id="ige-f-mois" name="mois" class="ige-input" style="width:auto">
					<option value="">Tous les mois</option>
					<?php foreach ( self::mois_noms() as $n => $nom ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>"<?php selected( $f['mois'], $n ); ?>><?php echo esc_html( $nom ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="ige-f-rub">Rubrique</label>
				<select id="ige-f-rub" name="rubrique" class="ige-input" style="width:auto">
					<option value="">Toutes les rubriques</option>
					<?php foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) : ?>
						<option value="<?php echo esc_attr( $r['id'] ); ?>"<?php selected( $f['rubrique_id'], (int) $r['id'] ); ?>><?php echo esc_html( $r['code'] . ' ' . $r['libelle'] ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="ige-f-statut">Statut</label>
				<select id="ige-f-statut" name="statut" class="ige-input" style="width:auto">
					<option value="">Tous statuts</option>
					<option value="payee"<?php selected( $f['statut'], 'payee' ); ?>>Payées</option>
					<option value="engagee"<?php selected( $f['statut'], 'engagee' ); ?>>Engagées</option>
				</select>
				<label class="screen-reader-text" for="ige-f-etat">État</label>
				<select id="ige-f-etat" name="etat" class="ige-input" style="width:auto">
					<option value="">Tous états</option>
					<option value="non_transmise"<?php selected( $f['etat'], 'non_transmise' ); ?>>Non transmises</option>
					<option value="a_retransmettre"<?php selected( $f['etat'], 'a_retransmettre' ); ?>>À retransmettre</option>
					<option value="a_verifier"<?php selected( $f['etat'], 'a_verifier' ); ?>>À vérifier</option>
					<option value="validee"<?php selected( $f['etat'], 'validee' ); ?>>Validées</option>
					<option value="rejetee"<?php selected( $f['etat'], 'rejetee' ); ?>>Rejetées</option>
				</select>
				<button type="submit" class="ige-btn ige-btn-line">Filtrer</button>
				<?php if ( array_filter( $f ) ) : ?>
					<a class="ige-small" href="<?php echo esc_url( $base ); ?>">Effacer les filtres</a>
				<?php endif; ?>
				<span class="ige-spacer ige-small ige-muted">Aucune modification ni suppression : les erreurs se corrigent par contrepassation.</span>
			</form>
			<p class="ige-small ige-muted" style="margin:0 0 10px">Solde au début de la période : <b class="ige-num"><?php echo esc_html( ige_ffm_montant( $livre['solde_debut'] ) ); ?></b><?php echo array_filter( array( $f['rubrique_id'], $f['statut'], $f['etat'] ) ) ? ' · avec des filtres, la colonne Solde reste celle de la caisse complète.' : ''; ?></p>
			<div class="ige-table-wrap"><table class="ige-table ige-num ige-livre">
				<thead><tr><th>N°</th><th>Date</th><th>Rubrique</th><th>Description</th><th>Bénéficiaire</th><th>Pièce</th><th>Statut</th><th class="ige-r">Entrée</th><th class="ige-r">Sortie</th><th class="ige-r">Solde</th><th>État</th><th class="ige-no-print"></th></tr></thead>
				<tbody>
				<?php
				foreach ( $rows as $e ) :
					$m      = (int) $e['montant'];
					$entree = 'entree' === $e['type'] ? $m : 0;
					$sortie = 'sortie' === $e['type'] ? $m : 0;
					$tot_e += $entree;
					$tot_s += 'payee' === $e['statut'] ? $sortie : 0;
					list( $lbl, $tone ) = IGE_FFM_Ecritures::etat( $e );
					$annule = (bool) $e['contrepassee_par'];
					$benef  = $e['fournisseur_id'] ? ( $fourn[ $e['fournisseur_id'] ] ?? '' ) : $e['beneficiaire'];
					$n      = (int) ( $npcs[ $e['id'] ] ?? 0 );
					?>
					<tr<?php echo $sel === (int) $e['id'] ? ' class="ige-sel"' : ( $annule ? ' class="ige-annule"' : '' ); ?>>
						<td><a href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $base ) ); ?>" title="<?php echo esc_attr( $e['id_ecriture'] ); ?>"><?php echo esc_html( sprintf( '%06d', $e['numero'] ) ); ?></a></td>
						<td><?php echo esc_html( wp_date( 'd/m', strtotime( $e['date_operation'] ) ) ); ?></td>
						<td><?php echo esc_html( $rubs[ $e['rubrique_id'] ] ?? '—' ); ?></td>
						<td><?php echo esc_html( $e['description'] ); ?><?php echo $e['contrepassee_par'] ? '<br><span class="ige-small ige-muted">Contrepassée par ' . esc_html( $e['contrepassee_par'] ) . '</span>' : ''; ?></td>
						<td><?php echo esc_html( $benef ); ?></td>
						<td><?php echo esc_html( $e['num_piece'] ); ?><?php echo $n ? ' <span class="ige-small" title="Pièce(s) jointe(s)">📎' . ( $n > 1 ? esc_html( $n ) : '' ) . '</span>' : ''; ?></td>
						<td><?php echo 'engagee' === $e['statut'] ? ige_ffm_pill( 'Engagée', 'info' ) : 'Payée'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="ige-r"><?php echo $entree ? esc_html( ige_ffm_montant( $entree, '' ) ) : ''; ?></td>
						<td class="ige-r"><?php echo $sortie ? esc_html( ige_ffm_montant( $sortie, '' ) ) : ''; ?></td>
						<td class="ige-r"><b><?php echo esc_html( ige_ffm_montant( (int) $e['solde'], '' ) ); ?></b></td>
						<td><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="ige-no-print"><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $base ) ); ?>">Voir</a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="12" class="ige-empty">Aucune écriture pour cette sélection.</td></tr>
				<?php else : ?>
					<tr class="ige-total"><td colspan="7">Total de la sélection (sorties payées)</td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $tot_e, '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $tot_s, '' ) ); ?></td><td colspan="3"></td></tr>
				<?php endif; ?>
				</tbody>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Carte de détail d'une écriture, avec les actions possibles.
	 *
	 * @param int    $id   Écriture.
	 * @param int    $sid  Service affiché.
	 * @param string $base Adresse du livre.
	 * @return string
	 */
	private static function detail( $id, $sid, $base ) {
		$e = IGE_FFM_Ecritures::get( $id );
		if ( ! $e || (int) $e['service_id'] !== (int) $sid ) {
			return ige_ffm_banner( 'warn', 'Écriture introuvable dans ce service', '' );
		}
		$r      = $e['rubrique_id'] ? IGE_FFM_Referentiels::get( 'rubrique', (int) $e['rubrique_id'] ) : null;
		$f      = $e['fournisseur_id'] ? IGE_FFM_Fournisseurs::get( (int) $e['fournisseur_id'] ) : null;
		$mode   = IGE_FFM_Referentiels::get( 'mode_paiement', (int) $e['mode_paiement_id'] );
		$auteur = get_userdata( (int) $e['saisi_par'] );
		$cp     = IGE_FFM_Ecritures::contrepassation_de( $id );
		$orig   = $e['ecriture_origine_id'] ? IGE_FFM_Ecritures::get( (int) $e['ecriture_origine_id'] ) : null;
		$peut   = IGE_FFM_Contexte::peut_saisir( $sid );
		$pieces = IGE_FFM_Pieces::liste( 'ecriture', $id );
		list( $lbl, $tone ) = IGE_FFM_Ecritures::etat( $e );
		$modes  = array_filter( IGE_FFM_Referentiels::all( 'mode_paiement' ), static fn( $m ) => (int) $m['actif'] );

		ob_start();
		?>
		<section class="ige-card ige-detail" id="ige-detail">
			<h2><?php echo esc_html( 'Écriture ' . $e['id_ecriture'] ); ?> <?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><span class="ige-sub"><a href="<?php echo esc_url( $base ); ?>">Fermer</a></span></h2>
			<?php if ( 'rejetee' === $e['etat_verification'] && $e['motif_rejet'] ) : ?>
				<?php echo ige_ffm_banner( 'ko', 'Rejetée par le central', ' ' . esc_html( $e['motif_rejet'] ) . ( $cp ? '' : ' — corrigez par contrepassation puis saisissez l’écriture juste.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>
			<div class="ige-grid ige-cols-2 ige-start">
				<dl>
					<dt>Type</dt><dd><?php echo 'entree' === $e['type'] ? 'Entrée (approvisionnement)' : 'Sortie'; ?> · <?php echo 'engagee' === $e['statut'] ? 'engagée' : 'payée'; ?></dd>
					<dt>Date</dt><dd><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $e['date_operation'] ) ) ); ?><?php echo $e['date_paiement'] && $e['date_paiement'] !== $e['date_operation'] ? esc_html( ' · payée le ' . wp_date( 'd/m/Y', strtotime( $e['date_paiement'] ) ) ) : ''; ?></dd>
					<dt>Montant</dt><dd class="ige-num"><b><?php echo esc_html( ige_ffm_montant( (int) $e['montant'] ) ); ?></b></dd>
					<dt>Rubrique</dt><dd><?php echo esc_html( $r ? $r['code'] . ' — ' . $r['libelle'] : '—' ); ?></dd>
					<dt>Description</dt><dd><?php echo esc_html( $e['description'] ); ?></dd>
					<dt>Bénéficiaire</dt><dd><?php echo esc_html( $f ? $f['raison_sociale'] : ( $e['beneficiaire'] ?: '—' ) ); ?></dd>
					<dt>Pièce n°</dt><dd><?php echo esc_html( $e['num_piece'] ?: '—' ); ?></dd>
					<dt>Mode</dt><dd><?php echo esc_html( $mode ? $mode['libelle'] : '—' ); ?></dd>
					<?php if ( $e['observations'] ) : ?><dt>Observations</dt><dd><?php echo esc_html( $e['observations'] ); ?></dd><?php endif; ?>
					<dt>Saisie</dt><dd><?php echo esc_html( ( $auteur ? $auteur->display_name : '—' ) . ' · ' . wp_date( 'd/m/Y H:i', strtotime( $e['saisi_le'] ) ) ); ?></dd>
					<?php if ( $orig ) : ?><dt>Annule</dt><dd><a href="<?php echo esc_url( add_query_arg( 'ecriture', $orig['id'], $base ) ); ?>"><?php echo esc_html( $orig['id_ecriture'] ); ?></a></dd><?php endif; ?>
					<?php if ( $cp ) : ?><dt>Annulée par</dt><dd><a href="<?php echo esc_url( add_query_arg( 'ecriture', $cp['id'], $base ) ); ?>"><?php echo esc_html( $cp['id_ecriture'] ); ?></a></dd><?php endif; ?>
				</dl>
				<div>
					<h3 style="margin:0 0 8px;font-size:14px">Pièces jointes</h3>
					<?php if ( $pieces ) : ?>
						<ul style="margin:0 0 12px;padding-left:18px">
							<?php foreach ( $pieces as $p ) : ?>
								<li><a href="<?php echo esc_url( IGE_FFM_Pieces::url( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['nom_original'] ); ?></a> <span class="ige-small ige-muted"><?php echo esc_html( size_format( (int) $p['taille'] ) ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="ige-small ige-muted">Aucune pièce jointe.</p>
					<?php endif; ?>
					<?php if ( $peut ) : ?>
						<?php echo IGE_FFM_Ecrans::form_open( 'piece_ajout', 'enctype="multipart/form-data" class="ige-row ige-no-print"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="ecriture" value="<?php echo esc_attr( $id ); ?>">
							<label class="screen-reader-text" for="ige-piece-ajout">Ajouter une pièce</label>
							<input type="file" id="ige-piece-ajout" name="piece" class="ige-input" style="width:auto;flex:1" accept="image/jpeg,image/png,image/webp,application/pdf" required>
							<button type="submit" class="ige-btn ige-btn-line">Ajouter la pièce</button>
						</form>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $e['avance_id'] && ! $orig ) : ?>
				<p class="ige-small ige-muted ige-no-print" style="margin:16px 0 0">Entrée créée par Administration et Finance (avance de fonds) : seule Administration et Finance peut l’annuler.</p>
			<?php endif; ?>
			<?php if ( $peut && ! $cp && ! $orig && ! $e['avance_id'] ) : ?>
				<div class="ige-grid ige-cols-2 ige-start ige-no-print" style="margin-top:16px;border-top:1px solid var(--ige-ligne);padding-top:16px">
					<?php if ( 'sortie' === $e['type'] && 'engagee' === $e['statut'] && ! ( 'rejetee' === $e['etat_verification'] && IGE_FFM_Reglages::get( 'contrepassation_rejet' ) ) ) : ?>
						<?php echo IGE_FFM_Ecrans::form_open( 'payer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<h3 style="margin:0 0 8px;font-size:14px">Payer l’engagement</h3>
							<input type="hidden" name="ecriture" value="<?php echo esc_attr( $id ); ?>">
							<div class="ige-grid ige-cols-2" style="gap:0 12px">
								<?php echo IGE_FFM_Ecrans::field( 'date_paiement', 'Date du paiement', IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( $e['date_operation'] ) . '" max="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php echo IGE_FFM_Ecrans::field( 'mode_paiement_id', 'Mode', (string) $e['mode_paiement_id'], array( 'type' => 'select', 'options' => wp_list_pluck( $modes, 'libelle', 'id' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
							<button type="submit" class="ige-btn ige-btn-bleu">Enregistrer le paiement</button>
						</form>
					<?php else : ?>
						<div></div>
					<?php endif; ?>
					<?php echo IGE_FFM_Ecrans::form_open( 'contrepasser', 'data-ige-confirm="Contrepasser cette écriture ? Une écriture inverse sera créée ; l’opération est définitive."' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<h3 style="margin:0 0 8px;font-size:14px">Contrepasser (annuler)</h3>
						<input type="hidden" name="ecriture" value="<?php echo esc_attr( $id ); ?>">
						<?php echo IGE_FFM_Ecrans::field( 'motif', 'Motif', '', array( 'required' => true, 'attrs' => 'minlength="5" maxlength="200" placeholder="Ex. : montant erroné, rubrique incorrecte, rejet du central"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<button type="submit" class="ige-btn ige-btn-rouge">Contrepasser</button>
					</form>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Écriture postée, contrôlée.
	 *
	 * @return int
	 */
	private static function id_poste() {
		return isset( $_POST['ecriture'] ) ? (int) $_POST['ecriture'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Retour au détail de l'écriture.
	 *
	 * @param int $id Écriture.
	 */
	private static function retour( $id ) {
		wp_safe_redirect( ige_ffm_url( 'livre-de-caisse', array( 'ecriture' => $id ) ) . '#ige-detail' );
		exit;
	}

	/**
	 * Paiement d'un engagement.
	 */
	public static function handle_payer() {
		ige_ffm_check_form( 'payer' );
		ige_ffm_require( 'ige_saisir' );
		$id  = self::id_poste();
		$res = IGE_FFM_Ecritures::payer( $id, ige_ffm_post( 'date_paiement' ), (int) ige_ffm_post( 'mode_paiement_id' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Paiement refusé', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Engagement payé', 'La sortie est maintenant déduite de la caisse.' );
		}
		self::retour( $id );
	}

	/**
	 * Contrepassation.
	 */
	public static function handle_contrepasser() {
		ige_ffm_check_form( 'contrepasser' );
		ige_ffm_require( 'ige_saisir' );
		$id  = self::id_poste();
		$res = IGE_FFM_Ecritures::contrepasser( $id, ige_ffm_post( 'motif' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Contrepassation refusée', $res->get_error_message() );
			self::retour( $id );
		}
		$c = IGE_FFM_Ecritures::get( $res );
		ige_ffm_flash( 'ok', 'Écriture contrepassée par ' . $c['id_ecriture'], 'Saisissez maintenant l’écriture correcte si nécessaire.' );
		self::retour( $res );
	}

	/**
	 * Ajout d'une pièce à une écriture existante.
	 */
	public static function handle_piece() {
		ige_ffm_check_form( 'piece_ajout' );
		ige_ffm_require( 'ige_saisir' );
		$id = self::id_poste();
		$e  = IGE_FFM_Ecritures::get( $id );
		if ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'] ) ) {
			wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
		}
		$file = isset( $_FILES['piece'] ) ? $_FILES['piece'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$res  = $file ? IGE_FFM_Pieces::enregistrer( $file, 'ecriture', $id, get_current_user_id() ) : new WP_Error( 'piece', 'Choisissez un fichier.' );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Pièce refusée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Pièce ajoutée', '' );
		}
		self::retour( $id );
	}

	/**
	 * Données d'export du livre filtré (Excel et PDF), après contrôle des droits.
	 *
	 * @return array
	 */
	public static function export_donnees() {
		ige_ffm_require( 'ige_acceder' );
		$sid = IGE_FFM_Contexte::service_courant();
		$ex  = IGE_FFM_Contexte::exercice();
		if ( ! $sid || ! $ex || ! IGE_FFM_Contexte::peut_voir( $sid ) ) {
			wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
		}
		$f     = self::filtres();
		$livre = IGE_FFM_Ecritures::livre( (int) $ex['id'], $sid, $f );
		$rubs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
		$modes = wp_list_pluck( IGE_FFM_Referentiels::all( 'mode_paiement' ), 'libelle', 'id' );
		$fourn = wp_list_pluck( IGE_FFM_Fournisseurs::all( false ), 'raison_sociale', 'id' );
		$code  = IGE_FFM_Comptes::service_code( $sid );
		$s     = IGE_FFM_Referentiels::get( 'service', $sid );
		IGE_FFM_Audit::log( 'export_livre', 'service', $code, null, array_filter( $f ) + array( 'lignes' => count( $livre['rows'] ) ) );

		$filtres = array();
		if ( $f['mois'] ) {
			$filtres[] = strtolower( self::mois_noms()[ $f['mois'] ] );
		}
		if ( $f['rubrique_id'] ) {
			$filtres[] = 'rubrique ' . ( $rubs[ $f['rubrique_id'] ] ?? '' );
		}
		if ( $f['statut'] ) {
			$filtres[] = 'payee' === $f['statut'] ? 'payées' : 'engagées';
		}
		if ( $f['etat'] ) {
			$filtres[] = str_replace( '_', ' ', $f['etat'] );
		}
		$lignes  = array( array( '', '', '', '', '', 'Solde au début de la période', '', '', '', null, null, (int) $livre['solde_debut'], '', '', '_style' => 'italique' ) );
		$entrees = 0;
		$sorties = 0;
		$solde   = (int) $livre['solde_debut'];
		foreach ( $livre['rows'] as $e ) {
			list( $lbl ) = IGE_FFM_Ecritures::etat( $e );
			$m           = (int) $e['montant'];
			if ( 'entree' === $e['type'] ) {
				$entrees += $m;
			} else {
				$sorties += 'payee' === $e['statut'] ? $m : 0;
			}
			$solde    = (int) $e['solde'];
			$lignes[] = array(
				$e['id_ecriture'],
				$e['date_operation'],
				'entree' === $e['type'] ? 'Entrée' : 'Sortie',
				'engagee' === $e['statut'] ? 'Engagée' : 'Payée',
				$rubs[ $e['rubrique_id'] ] ?? '',
				$e['description'],
				$e['fournisseur_id'] ? ( $fourn[ $e['fournisseur_id'] ] ?? '' ) : $e['beneficiaire'],
				$e['num_piece'],
				$modes[ $e['mode_paiement_id'] ] ?? '',
				'entree' === $e['type'] ? $m : null,
				'sortie' === $e['type'] ? $m : null,
				(int) $e['solde'],
				$lbl,
				(string) $e['contrepassee_par'],
			);
		}
		return array(
			'titre'      => 'Livre de caisse — ' . $code,
			'sous_titre' => ( $s && $s['libelle'] !== $code ? $s['libelle'] . ' · ' : '' ) . 'Exercice ' . $ex['annee'] . ( $filtres ? ' · ' . implode( ', ', $filtres ) : '' ) . ' · ' . count( $livre['rows'] ) . ' écriture(s) · ' . IGE_FFM_Classeur::extrait_par(),
			'colonnes'   => array(
				array( 'Identifiant', 'texte', 19 ),
				array( 'Date', 'date' ),
				array( 'Type', 'texte', 8 ),
				array( 'Statut', 'texte', 9 ),
				array( 'Rubrique', 'texte', 9 ),
				array( 'Description', 'long', 36 ),
				array( 'Fournisseur / bénéficiaire', 'long', 24 ),
				array( 'N° pièce', 'texte', 12 ),
				array( 'Mode', 'texte', 12 ),
				array( 'Entrée', 'montant' ),
				array( 'Sortie', 'montant' ),
				array( 'Solde', 'montant' ),
				array( 'État', 'texte', 13 ),
				array( 'Contrepassée par', 'texte', 19 ),
			),
			'lignes'     => $lignes,
			'total'      => array( 'Total de la période', '', '', '', '', '', '', '', '', $entrees, $sorties, $solde, '', '' ),
			'notes'      => array( 'Total des sorties : sorties payées seulement (les engagements non payés ne sortent pas de la caisse). Solde : solde de caisse après chaque écriture.' ),
			'fichier'    => 'livre-caisse-' . sanitize_file_name( $code ) . '-' . $ex['annee'] . ( $f['mois'] ? '-' . sprintf( '%02d', $f['mois'] ) : '' ),
			'paysage'    => true,
		);
	}

	/**
	 * Export Excel (.xlsx) du livre filtré.
	 */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_livre_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		$t = self::export_donnees();
		( new IGE_FFM_Classeur( $t['titre'] ) )->feuille( 'Livre de caisse', $t )->telecharger( $t['fichier'] . '.xlsx' );
	}

	/**
	 * Neutralise les formules dans un tableur (=, +, -, @ en début de cellule).
	 *
	 * @param string $s Texte.
	 * @return string
	 */
	public static function csv_texte( $s ) {
		$s = (string) $s;
		return '' !== $s && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $s : $s;
	}
}
