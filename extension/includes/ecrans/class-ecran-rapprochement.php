<?php
/**
 * Écran « Rapprochement bancaire » (VA-06) : comptes, import du relevé (CSV ou Excel),
 * rapprochement automatique puis manuel, mouvements bancaires, explication des suspens,
 * état de rapprochement en PDF. Réservé à Administration et Finance.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rapprochement bancaire.
 */
final class IGE_FFM_Ecran_Rapprochement {

	/**
	 * Crochets.
	 */
	public static function register() {
		foreach ( array( 'compte', 'releve', 'rapprocher', 'defaire', 'mouvement', 'mouvement_ligne', 'expliquer', 'rappro_auto' ) as $a ) {
			add_action( 'admin_post_ige_ffm_banque_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Rapprochement bancaire';
		$args['subtitle'] = 'Relevé de la banque face aux opérations enregistrées';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * URL de l'écran pour un compte.
	 *
	 * @param int    $cid  Compte.
	 * @param string $ancre Ancre.
	 * @return string
	 */
	private static function url( $cid, $ancre = '' ) {
		return add_query_arg( 'compte', (int) $cid, ige_ffm_url( 'rapprochement-bancaire' ) ) . ( $ancre ? '#' . $ancre : '' );
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$comptes = IGE_FFM_Banque::comptes();
		$cid     = isset( $_GET['compte'] ) ? (int) $_GET['compte'] : ( $comptes ? (int) $comptes[0]['id'] : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$e       = $cid ? IGE_FFM_Banque::etat( $cid ) : null;
		ob_start();
		if ( ! $comptes ) {
			echo ige_ffm_banner( 'info', 'Déclarez d’abord le compte bancaire', ' Indiquez le solde du dernier relevé déjà rapproché : le rapprochement part de cette date.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="ige-grid ige-cols-2 ige-start">' . self::form_compte() . '<section class="ige-card"><h2 style="margin-top:0">Ce que l’application rapproche</h2><p class="ige-small">Sur le compte principal : les paiements des services faits par chèque ou virement, les avances de fonds remises par chèque ou virement, et les mouvements que vous saisissez (dotations reçues, frais bancaires…). Les opérations en espèces restent dans les caisses des services.</p></section></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return ob_get_clean();
		}
		if ( ! $e ) {
			return ige_ffm_banner( 'ko', 'Compte introuvable', '' );
		}
		$c   = $e['compte'];
		$ops = array_values( array_filter( IGE_FFM_Banque::operations( $c ), static fn( $o ) => ! $o['rapproche'] ) );
		?>
		<div class="ige-toolbar">
			<?php foreach ( $comptes as $x ) : ?>
				<a class="ige-chip<?php echo (int) $x['id'] === $cid ? ' ige-on' : ''; ?>" href="<?php echo esc_url( self::url( (int) $x['id'] ) ); ?>"><?php echo esc_html( $x['banque'] . ' ' . IGE_FFM_Banque::masquer( $x['numero'] ) . ( (int) $x['principal'] ? ' · principal' : '' ) ); ?></a>
			<?php endforeach; ?>
			<a class="ige-btn ige-btn-line" style="margin-left:auto" href="<?php echo esc_url( IGE_FFM_Pdf::url( array( 'quoi' => 'rapprochement', 'id' => $cid ) ) ); ?>">État de rapprochement (PDF)</a>
		</div>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Solde du relevé</div><div class="ige-val"><?php echo esc_html( null === $e['solde_releve'] ? '—' : ige_ffm_montant( $e['solde_releve'], '' ) ); ?></div><div class="ige-meta"><?php echo esc_html( $e['releve'] ? 'au ' . wp_date( 'd/m/Y', strtotime( $e['date'] ) ) : 'aucun relevé importé' ); ?></div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Solde de la comptabilité</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $e['solde_compta'], '' ) ); ?></div><div class="ige-meta">à la même date</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Lignes rapprochées</div><div class="ige-val"><?php echo (int) $e['nb_rapprochees']; ?> / <?php echo (int) $e['nb_lignes']; ?></div><div class="ige-meta">du relevé</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Écart inexpliqué</div><div class="ige-val<?php echo $e['residu'] ? ' ige-neg' : ''; ?>"><?php echo esc_html( null === $e['residu'] ? '—' : ige_ffm_montant( $e['residu'], '' ) ); ?></div><div class="ige-meta"><?php echo null === $e['residu'] ? 'indiquez le solde final du relevé' : ( $e['residu'] ? 'à rechercher' : 'rapprochement équilibré' ); ?></div></div>
		</div>
		<div class="ige-grid ige-cols-21 ige-start">
			<div>
				<section class="ige-card" style="padding:16px" id="banque">
					<div class="ige-toolbar"><h2 style="margin:0">Opérations de la banque absentes de la comptabilité (<?php echo count( $e['banque_libres'] ); ?>)</h2>
						<?php echo IGE_FFM_Ecrans::action_button( 'banque_rappro_auto', array( 'compte' => $cid ), 'Relancer le rapprochement automatique', 'ige-btn ige-btn-line' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</div>
					<div class="ige-table-wrap"><table class="ige-table">
						<thead><tr><th>Date</th><th>Libellé de la banque</th><th class="ige-r">Montant</th><th>Rapprocher avec</th><th>Ou</th></tr></thead>
						<tbody>
						<?php foreach ( $e['banque_libres'] as $l ) : ?>
							<?php $cands = array_filter( $ops, static fn( $o ) => $o['montant'] === (int) $l['montant'] ); ?>
							<tr>
								<td style="white-space:nowrap"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $l['date_operation'] ) ) ); ?></td>
								<td><?php echo esc_html( $l['libelle'] ); ?><?php echo $l['reference'] ? '<br><span class="ige-small ige-muted">' . esc_html( $l['reference'] ) . '</span>' : ''; ?><?php echo $l['explication'] ? '<br><span class="ige-small"><i>' . esc_html( $l['explication'] ) . '</i></span>' : ''; ?></td>
								<td class="ige-r ige-num<?php echo (int) $l['montant'] < 0 ? ' ige-neg' : ''; ?>"><?php echo esc_html( ige_ffm_montant( (int) $l['montant'], '' ) ); ?></td>
								<td>
									<?php if ( $cands ) : ?>
										<?php echo IGE_FFM_Ecrans::form_open( 'banque_rapprocher', 'class="ige-row" style="gap:6px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<input type="hidden" name="ligne" value="<?php echo esc_attr( $l['id'] ); ?>">
											<select name="op" aria-label="Opération de la comptabilité" style="max-width:240px"><?php foreach ( $cands as $o ) : ?><option value="<?php echo esc_attr( $o['type'] . ':' . $o['id'] ); ?>"><?php echo esc_html( wp_date( 'd/m', strtotime( $o['date'] ) ) . ' · ' . $o['reference'] . ' · ' . mb_substr( $o['libelle'], 0, 40 ) ); ?></option><?php endforeach; ?></select>
											<button type="submit" class="ige-act" style="background:none;border:0;padding:0;cursor:pointer;font:inherit">Rapprocher</button>
										</form>
									<?php else : ?>
										<span class="ige-small ige-muted">Aucune opération du même montant</span>
									<?php endif; ?>
								</td>
								<td>
									<?php echo IGE_FFM_Ecrans::form_open( 'banque_mouvement_ligne', 'class="ige-row" style="gap:6px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<input type="hidden" name="ligne" value="<?php echo esc_attr( $l['id'] ); ?>">
										<select name="nature" aria-label="Nature"><option value="<?php echo (int) $l['montant'] > 0 ? 'dotation' : 'frais'; ?>"><?php echo (int) $l['montant'] > 0 ? 'Dotation reçue' : 'Frais bancaires'; ?></option><option value="autre">Autre</option></select>
										<button type="submit" class="ige-act" style="background:none;border:0;padding:0;cursor:pointer;font:inherit">Enregistrer</button>
									</form>
									<?php echo self::form_expliquer( 'ligne', (int) $l['id'], $l['explication'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $e['banque_libres'] ) : ?><tr><td colspan="5" class="ige-empty"><?php echo $e['releve'] ? 'Toutes les lignes du relevé sont rapprochées.' : 'Importez un relevé pour commencer.'; ?></td></tr><?php endif; ?>
						</tbody>
					</table></div>
				</section>
				<section class="ige-card" style="padding:16px" id="compta">
					<h2 style="margin-top:0">Opérations de la comptabilité non encore passées en banque (<?php echo count( $e['compta_libres'] ); ?>)</h2>
					<div class="ige-table-wrap"><table class="ige-table">
						<thead><tr><th>Date</th><th>Opération</th><th>Référence</th><th class="ige-r">Montant</th><th>Explication</th></tr></thead>
						<tbody>
						<?php foreach ( $e['compta_libres'] as $o ) : ?>
							<tr>
								<td style="white-space:nowrap"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $o['date'] ) ) ); ?></td>
								<td><?php echo esc_html( $o['libelle'] ); ?></td>
								<td><?php echo esc_html( $o['reference'] ); ?></td>
								<td class="ige-r ige-num<?php echo $o['montant'] < 0 ? ' ige-neg' : ''; ?>"><?php echo esc_html( ige_ffm_montant( $o['montant'], '' ) ); ?></td>
								<td><?php echo $o['explication'] ? '<i class="ige-small">' . esc_html( $o['explication'] ) . '</i>' : ''; ?><?php echo self::form_expliquer( $o['type'], $o['id'], $o['explication'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $e['compta_libres'] ) : ?><tr><td colspan="5" class="ige-empty">Aucune opération en suspens.</td></tr><?php endif; ?>
						</tbody>
					</table></div>
					<p class="ige-small ige-muted" style="margin:10px 0 0">Un chèque émis mais pas encore encaissé reste ici jusqu’au relevé suivant ; au-delà de <?php echo (int) IGE_FFM_Reglages::get( 'suspens_alerte_jours' ); ?> jours, une alerte apparaît sur le tableau de bord.</p>
				</section>
				<?php $faites = array_filter( IGE_FFM_Banque::lignes( $cid ), static fn( $l ) => '' !== $l['rapproche_type'] ); ?>
				<?php if ( $faites ) : ?>
					<section class="ige-card" style="padding:16px">
						<details><summary style="cursor:pointer;font-weight:600">Lignes rapprochées (<?php echo count( $faites ); ?>)</summary>
							<div class="ige-table-wrap"><table class="ige-table">
								<thead><tr><th>Date</th><th>Libellé de la banque</th><th class="ige-r">Montant</th><th>Avec</th><th></th></tr></thead>
								<tbody>
								<?php foreach ( array_reverse( $faites ) as $l ) : ?>
									<tr><td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $l['date_operation'] ) ) ); ?></td><td><?php echo esc_html( $l['libelle'] ); ?></td><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $l['montant'], '' ) ); ?></td><td class="ige-small"><?php echo esc_html( array( 'ecriture' => 'Écriture', 'avance' => 'Avance', 'mouvement' => 'Mouvement' )[ $l['rapproche_type'] ] ?? $l['rapproche_type'] ); ?><?php echo $l['rapproche_par'] ? '' : ' (auto)'; ?></td><td><?php echo IGE_FFM_Ecrans::action_button( 'banque_defaire', array( 'ligne' => $l['id'] ), 'Défaire', 'ige-act', 'Défaire ce rapprochement ?' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table></div>
						</details>
					</section>
				<?php endif; ?>
			</div>
			<aside>
				<section class="ige-card">
					<h2 style="margin-top:0">Importer un relevé</h2>
					<p class="ige-small">Fichier CSV ou Excel exporté de la banque : colonnes date, libellé, référence, et débit/crédit ou montant. Une ligne déjà importée n’est jamais ajoutée deux fois.</p>
					<?php echo IGE_FFM_Ecrans::form_open( 'banque_releve', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="compte" value="<?php echo esc_attr( $cid ); ?>">
						<div class="ige-field"><label for="ige-releve">Relevé</label><input type="file" id="ige-releve" name="releve" required accept=".csv,.xlsx,text/csv"></div>
						<?php echo IGE_FFM_Ecrans::field( 'solde_final', 'Solde final du relevé (CDF)', '', array( 'attrs' => 'inputmode="numeric" class="ige-num"', 'help' => 'Permet de calculer l’écart inexpliqué.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<button type="submit" class="ige-btn ige-btn-jaune">Importer et rapprocher</button>
					</form>
					<?php if ( $e['releve'] ) : ?>
						<p class="ige-small ige-muted" style="margin:8px 0 0">Dernier relevé : <?php echo esc_html( $e['releve']['fichier'] . ', du ' . wp_date( 'd/m/Y', strtotime( $e['releve']['date_debut'] ) ) . ' au ' . wp_date( 'd/m/Y', strtotime( $e['releve']['date_fin'] ) ) ); ?>.</p>
					<?php endif; ?>
				</section>
				<section class="ige-card">
					<h2 style="margin-top:0">Mouvement bancaire</h2>
					<p class="ige-small">Dotation reçue, frais ou agios, virement interne : opérations qui ne passent pas par une caisse de service.</p>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'banque_mouvement' ) . '<input type="hidden" name="compte" value="' . esc_attr( $cid ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'nature', 'Nature', 'dotation', array( 'type' => 'select', 'options' => array( 'dotation' => 'Dotation reçue (entrée)', 'frais' => 'Frais bancaires (sortie)', 'autre' => 'Autre' ) ) )
						. IGE_FFM_Ecrans::field( 'sens', 'Sens', 'entree', array( 'type' => 'select', 'options' => array( 'entree' => 'Entrée (crédit)', 'sortie' => 'Sortie (débit)' ) ) )
						. IGE_FFM_Ecrans::field( 'date', 'Date', IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date', 'required' => true ) )
						. IGE_FFM_Ecrans::field( 'libelle', 'Libellé', '', array( 'required' => true, 'attrs' => 'maxlength="255"' ) )
						. IGE_FFM_Ecrans::field( 'reference', 'Référence', '', array( 'attrs' => 'maxlength="80"' ) )
						. IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
						. '<button type="submit" class="ige-btn ige-btn-line">Enregistrer</button></form>';
					?>
				</section>
				<section class="ige-card">
					<dl class="ige-kv">
						<dt>Banque</dt><dd><?php echo esc_html( $c['banque'] ); ?></dd>
						<dt>Intitulé</dt><dd><?php echo esc_html( $c['intitule'] ); ?></dd>
						<dt>Numéro</dt><dd><?php echo esc_html( IGE_FFM_Banque::masquer( $c['numero'] ) ); ?></dd>
						<dt>Solde de départ</dt><dd><?php echo esc_html( ige_ffm_montant( (int) $c['solde_initial'] ) . ' au ' . wp_date( 'd/m/Y', strtotime( $c['date_solde'] ) ) ); ?></dd>
					</dl>
					<details style="margin-top:8px"><summary style="cursor:pointer;font-weight:600">Ajouter un compte</summary><?php echo self::form_compte( false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></details>
				</section>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire de création de compte.
	 *
	 * @param bool $carte Dans une carte.
	 * @return string
	 */
	private static function form_compte( $carte = true ) {
		$h = IGE_FFM_Ecrans::form_open( 'banque_compte' )
			. IGE_FFM_Ecrans::field( 'banque', 'Banque', '', array( 'required' => true, 'attrs' => 'maxlength="120"' ) )
			. IGE_FFM_Ecrans::field( 'intitule', 'Intitulé du compte', '', array( 'required' => true, 'attrs' => 'maxlength="190" placeholder="IGE · Frais de fonctionnement"' ) )
			. IGE_FFM_Ecrans::field( 'numero', 'Numéro de compte', '', array( 'attrs' => 'maxlength="60"', 'help' => 'Affiché masqué (4 derniers chiffres).' ) )
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'solde_initial', 'Solde de départ (CDF)', '0', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'date_solde', 'À la date du', '', array( 'type' => 'date', 'required' => true ) )
			. '</div>'
			. IGE_FFM_Ecrans::field( 'principal', 'Compte principal (paiements par chèque ou virement des services)', ! IGE_FFM_Banque::comptes(), array( 'type' => 'checkbox' ) )
			. '<button type="submit" class="ige-btn ige-btn-jaune">Déclarer le compte</button></form>';
		return $carte ? '<section class="ige-card"><h2 style="margin-top:0">Compte bancaire</h2>' . $h . '</section>' : $h;
	}

	/**
	 * Petit formulaire d'explication d'un suspens.
	 *
	 * @param string $objet Objet.
	 * @param int    $id    Identifiant.
	 * @param string $texte Texte actuel.
	 * @return string
	 */
	private static function form_expliquer( $objet, $id, $texte ) {
		return '<details class="ige-small"><summary style="cursor:pointer">' . ( $texte ? 'Modifier l’explication' : 'Expliquer' ) . '</summary>'
			. IGE_FFM_Ecrans::form_open( 'banque_expliquer', 'class="ige-row" style="gap:6px;margin-top:4px"' )
			. '<input type="hidden" name="objet" value="' . esc_attr( $objet ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="compte" value="' . esc_attr( isset( $_GET['compte'] ) ? (int) $_GET['compte'] : 0 ) . '">' // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			. '<input type="text" name="texte" value="' . esc_attr( $texte ) . '" maxlength="255" required aria-label="Explication" placeholder="Ex. : chèque non encore encaissé">'
			. '<button type="submit" class="ige-act" style="background:none;border:0;padding:0;cursor:pointer;font:inherit">OK</button></form></details>';
	}

	/**
	 * Compte de la ligne postée (pour le retour).
	 *
	 * @return int
	 */
	private static function compte_ligne() {
		$l = IGE_FFM_Banque::ligne( (int) ige_ffm_post( 'ligne' ) );
		return $l ? (int) $l['compte_id'] : 0;
	}

	/**
	 * Retour.
	 *
	 * @param int    $cid   Compte.
	 * @param string $ancre Ancre.
	 */
	private static function vers( $cid, $ancre = '' ) {
		wp_safe_redirect( $cid ? self::url( $cid, $ancre ) : ige_ffm_url( 'rapprochement-bancaire' ) );
		exit;
	}

	/**
	 * Résultat standard.
	 *
	 * @param mixed  $r   Résultat.
	 * @param string $ok  Titre de réussite.
	 * @param string $ko  Titre d'échec.
	 */
	private static function flash( $r, $ok, $ko ) {
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', $ko, $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', $ok, '' );
		}
	}

	/** Création de compte. */
	public static function handle_compte() {
		ige_ffm_check_form( 'banque_compte' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$r = IGE_FFM_Banque::creer_compte(
			array(
				'banque'        => ige_ffm_post( 'banque' ),
				'intitule'      => ige_ffm_post( 'intitule' ),
				'numero'        => ige_ffm_post( 'numero' ),
				'solde_initial' => ige_ffm_post( 'solde_initial' ),
				'date_solde'    => ige_ffm_post( 'date_solde' ),
				'principal'     => (bool) ige_ffm_post( 'principal' ),
			)
		);
		self::flash( $r, 'Compte bancaire déclaré', 'Compte non enregistré' );
		self::vers( is_wp_error( $r ) ? 0 : $r );
	}

	/** Import d'un relevé. */
	public static function handle_releve() {
		ige_ffm_check_form( 'banque_releve' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid  = (int) ige_ffm_post( 'compte' );
		$file = isset( $_FILES['releve'] ) ? $_FILES['releve'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			ige_ffm_flash( 'ko', 'Aucun relevé reçu', 'Choisissez le fichier exporté de la banque (CSV ou Excel, 5 Mo au plus).' );
			self::vers( $cid );
		}
		if ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
			ige_ffm_flash( 'ko', 'Relevé trop volumineux', '5 Mo au plus.' );
			self::vers( $cid );
		}
		$r = IGE_FFM_Banque::importer( $cid, $file['tmp_name'], sanitize_file_name( $file['name'] ), ige_ffm_post( 'solde_final' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Relevé non importé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', $r['nouvelles'] . ' ligne(s) importée(s), ' . $r['rapprochees'] . ' rapprochée(s) automatiquement', $r['doublons'] ? $r['doublons'] . ' ligne(s) déjà connue(s) ignorée(s).' : '' );
		}
		self::vers( $cid );
	}

	/** Rapprochement manuel. */
	public static function handle_rapprocher() {
		ige_ffm_check_form( 'banque_rapprocher' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid = self::compte_ligne();
		self::flash( IGE_FFM_Banque::rapprocher( (int) ige_ffm_post( 'ligne' ), ige_ffm_post( 'op' ) ), 'Ligne rapprochée', 'Rapprochement refusé' );
		self::vers( $cid, 'banque' );
	}

	/** Annulation d'un rapprochement. */
	public static function handle_defaire() {
		ige_ffm_check_form( 'banque_defaire' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid = self::compte_ligne();
		self::flash( IGE_FFM_Banque::defaire( (int) ige_ffm_post( 'ligne' ) ), 'Rapprochement défait', 'Action impossible' );
		self::vers( $cid, 'banque' );
	}

	/** Relance du rapprochement automatique. */
	public static function handle_rappro_auto() {
		ige_ffm_check_form( 'banque_rappro_auto' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid = (int) ige_ffm_post( 'compte' );
		$n   = IGE_FFM_Banque::rapprocher_auto( $cid, get_current_user_id() );
		ige_ffm_flash( $n ? 'ok' : 'info', $n . ' ligne(s) rapprochée(s) automatiquement', $n ? '' : 'Les lignes restantes demandent un choix manuel (montants différents ou plusieurs candidats).' );
		self::vers( $cid, 'banque' );
	}

	/** Mouvement saisi. */
	public static function handle_mouvement() {
		ige_ffm_check_form( 'banque_mouvement' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid = (int) ige_ffm_post( 'compte' );
		$m   = ige_ffm_parse_montant( ige_ffm_post( 'montant' ) );
		$r   = null === $m || $m <= 0 ? new WP_Error( 'montant', 'Montant positif en CDF ; le sens indique entrée ou sortie.' ) : IGE_FFM_Banque::mouvement(
			array(
				'compte_id' => $cid,
				'date'      => ige_ffm_post( 'date' ),
				'libelle'   => ige_ffm_post( 'libelle' ),
				'reference' => ige_ffm_post( 'reference' ),
				'montant'   => 'sortie' === ige_ffm_post( 'sens' ) ? -$m : $m,
				'nature'    => ige_ffm_post( 'nature' ),
			)
		);
		if ( ! is_wp_error( $r ) ) {
			IGE_FFM_Banque::rapprocher_auto( $cid, get_current_user_id() );
		}
		self::flash( $r, 'Mouvement enregistré', 'Mouvement non enregistré' );
		self::vers( $cid );
	}

	/** Mouvement créé depuis une ligne du relevé. */
	public static function handle_mouvement_ligne() {
		ige_ffm_check_form( 'banque_mouvement_ligne' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$cid = self::compte_ligne();
		self::flash( IGE_FFM_Banque::mouvement_depuis_ligne( (int) ige_ffm_post( 'ligne' ), ige_ffm_post( 'nature' ) ), 'Mouvement enregistré et rapproché', 'Action impossible' );
		self::vers( $cid, 'banque' );
	}

	/** Explication d'un suspens. */
	public static function handle_expliquer() {
		ige_ffm_check_form( 'banque_expliquer' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$objet = sanitize_key( ige_ffm_post( 'objet' ) );
		self::flash( IGE_FFM_Banque::expliquer( $objet, (int) ige_ffm_post( 'id' ), ige_ffm_post( 'texte' ) ), 'Explication enregistrée', 'Explication refusée' );
		self::vers( (int) ige_ffm_post( 'compte' ), 'ligne' === $objet ? 'banque' : 'compta' );
	}
}
