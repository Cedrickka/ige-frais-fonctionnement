<?php
/**
 * Écran « Inventaire des services » : situation par service, fiche d'inventaire
 * (biens × locaux), import du fichier Excel, tableau consolidé, catalogue des biens.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des services.
 */
final class IGE_FFM_Ecran_Patrimoine {

	const PAGE = 'inventaire-des-services';

	/**
	 * Crochets.
	 */
	public static function register() {
		foreach ( array( 'ouvrir', 'import', 'saisie', 'supprimer', 'local', 'bien', 'retirer', 'export' ) as $a ) {
			add_action( 'admin_post_ige_ffm_inv_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Inventaire des services';
		$args['subtitle'] = 'Biens et équipements comptés local par local dans chaque service';
		return $args;
	}

	/**
	 * Adresse de l'écran.
	 *
	 * @param array $q Paramètres.
	 * @return string
	 */
	private static function url( array $q = array() ) {
		return ige_ffm_url( self::PAGE, $q );
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id = isset( $_GET['inventaire'] ) ? (int) $_GET['inventaire'] : 0;
		$o  = isset( $_GET['onglet'] ) ? sanitize_key( $_GET['onglet'] ) : 'services';
		// phpcs:enable
		$tabs = array( 'services' => 'Inventaires par service', 'consolide' => 'Tableau consolidé', 'biens' => 'Catalogue des biens' );
		$o    = $id ? 'fiche' : ( isset( $tabs[ $o ] ) ? $o : 'services' );
		$h    = '<div class="ige-tabs" role="tablist" style="margin-bottom:14px">';
		foreach ( $tabs as $k => $l ) {
			$h .= '<a role="tab" href="' . esc_url( self::url( array( 'onglet' => $k ) ) ) . '" class="' . ( $k === $o || ( 'fiche' === $o && 'services' === $k ) ? 'ige-on' : '' ) . '">' . esc_html( $l ) . '</a>';
		}
		$h .= '</div>';
		return $h . ( 'fiche' === $o ? self::fiche( $id ) : call_user_func( array( __CLASS__, 'onglet_' . $o ) ) );
	}

	/**
	 * Options des services actifs.
	 *
	 * @param string $vide Libellé de l'option vide.
	 * @return array
	 */
	private static function options_services( $vide ) {
		$o = array( '' => $vide );
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			if ( (int) $s['actif'] ) {
				$o[ $s['id'] ] = $s['code'] . ( $s['libelle'] && $s['libelle'] !== $s['code'] ? ' · ' . $s['libelle'] : '' );
			}
		}
		return $o;
	}

	/**
	 * Situation par service, ouverture et import.
	 *
	 * @return string
	 */
	private static function onglet_services() {
		$ps   = IGE_FFM_Patrimoine::par_service();
		$fait = count( array_filter( $ps, static fn( $p ) => $p['dernier'] ) );
		$enc  = count( array_filter( $ps, static fn( $p ) => $p['en_cours'] ) );
		$c    = IGE_FFM_Patrimoine::consolide();
		$auj  = IGE_FFM_Contexte::aujourdhui();
		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Services inventoriés</div><div class="ige-val"><?php echo (int) $fait; ?> <span>/ <?php echo count( $ps ); ?></span></div><div class="ige-meta">au moins un inventaire validé</div></div>
			<div class="ige-card ige-kpi<?php echo $enc ? ' ige-alerte' : ''; ?>"><div class="ige-lbl">Inventaires en cours</div><div class="ige-val"><?php echo (int) $enc; ?></div><div class="ige-meta">à terminer et valider</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Biens recensés</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $c['total'], '' ) ); ?></div><div class="ige-meta"><?php echo count( $c['lignes'] ); ?> désignations, derniers inventaires validés</div></div>
		</div>
		<section class="ige-card" style="padding:16px;margin-bottom:16px">
			<h3 style="margin-top:0">Situation par service</h3>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Service</th><th class="ige-r">Locaux</th><th>Dernier inventaire validé</th><th class="ige-r">Biens</th><th>En cours</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $ps as $p ) : ?>
					<tr>
						<td><span class="ige-code"><?php echo esc_html( $p['service']['code'] ); ?></span> <?php echo esc_html( $p['service']['libelle'] !== $p['service']['code'] ? $p['service']['libelle'] : '' ); ?></td>
						<td class="ige-r ige-num"><?php echo (int) $p['locaux']; ?></td>
						<td><?php echo $p['dernier'] ? '<a href="' . esc_url( self::url( array( 'inventaire' => $p['dernier']['id'] ) ) ) . '">' . esc_html( $p['dernier']['reference'] ) . '</a> · ' . esc_html( wp_date( 'd/m/Y', strtotime( $p['dernier']['date_inventaire'] ) ) ) : ige_ffm_pill( 'Jamais inventorié', 'wait' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="ige-r ige-num"><?php echo $p['dernier'] ? esc_html( ige_ffm_montant( (int) $p['dernier']['total'], '' ) ) : '—'; ?></td>
						<td><?php echo $p['en_cours'] ? '<a href="' . esc_url( self::url( array( 'inventaire' => $p['en_cours']['id'] ) ) ) . '">' . esc_html( $p['en_cours']['reference'] ) . '</a>' : '—'; ?></td>
						<td class="ige-r"><?php echo $p['nb'] > 1 ? esc_html( $p['nb'] . ' inventaires' ) : ''; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<div class="ige-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
			<section class="ige-card" style="padding:16px">
				<h3 style="margin-top:0">Ouvrir un inventaire</h3>
				<p class="ige-help">Une grille vide, ou les quantités du dernier inventaire validé, à vérifier local par local.</p>
				<?php echo IGE_FFM_Ecrans::form_open( 'inv_ouvrir' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo IGE_FFM_Ecrans::field( 'service_id', 'Service', '', array( 'type' => 'select', 'options' => self::options_services( '— Service —' ), 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo IGE_FFM_Ecrans::field( 'date', 'Date du comptage', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo IGE_FFM_Ecrans::field( 'reprendre', 'Reprendre les quantités du dernier inventaire validé', '1', array( 'type' => 'checkbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<button type="submit" class="ige-btn ige-btn-jaune">Ouvrir l’inventaire</button>
				</form>
			</section>
			<section class="ige-card" style="padding:16px">
				<h3 style="margin-top:0">Importer un fichier Excel</h3>
				<p class="ige-help">Le modèle « INVENTAIRE DE SERVICE » : une ligne « N° · Désignation · locaux… · TOTAL » et la mention « SERVICE: 8001 ». Les biens et les locaux manquants sont créés ; l’inventaire reste à contrôler puis valider.</p>
				<?php echo IGE_FFM_Ecrans::form_open( 'inv_import', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-field"><label for="ige-fichier-inv">Fichier (.xlsx, 5 Mo au plus)</label><input type="file" id="ige-fichier-inv" name="fichier" accept=".xlsx" required></div>
				<?php echo IGE_FFM_Ecrans::field( 'service_id', 'Service', '', array( 'type' => 'select', 'options' => self::options_services( 'Celui indiqué dans le fichier' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo IGE_FFM_Ecrans::field( 'date', 'Date du comptage', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<button type="submit" class="ige-btn ige-btn-jaune">Importer</button>
				</form>
			</section>
		</div>
		<?php
		$all = IGE_FFM_Patrimoine::inventaires();
		if ( $all ) :
			?>
			<section class="ige-card" style="padding:16px;margin-top:16px">
				<h3 style="margin-top:0">Historique des inventaires</h3>
				<div class="ige-table-wrap"><table class="ige-table">
					<thead><tr><th>Référence</th><th>Service</th><th>Date</th><th>Origine</th><th class="ige-r">Désignations</th><th class="ige-r">Biens</th><th>État</th></tr></thead>
					<tbody>
					<?php foreach ( $all as $i ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( self::url( array( 'inventaire' => $i['id'] ) ) ); ?>"><?php echo esc_html( $i['reference'] ); ?></a></td>
							<td><span class="ige-code"><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $i['service_id'] ) ); ?></span></td>
							<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $i['date_inventaire'] ) ) ); ?></td>
							<td><?php echo esc_html( 'import' === $i['origine'] ? 'Fichier Excel' : 'Saisie' ); ?></td>
							<td class="ige-r ige-num"><?php echo (int) $i['nb_biens']; ?></td>
							<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $i['total'], '' ) ); ?></td>
							<td><?php echo 'valide' === $i['statut'] ? ige_ffm_pill( 'Validé', 'ok' ) : ige_ffm_pill( 'En cours', 'wait' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</section>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Fiche d'un inventaire : grille biens × locaux.
	 *
	 * @param int $id Inventaire.
	 * @return string
	 */
	private static function fiche( $id ) {
		$g = IGE_FFM_Patrimoine::grille( $id );
		if ( ! $g ) {
			return '<div class="ige-card ige-empty" style="padding:20px">Inventaire introuvable.</div>';
		}
		$inv    = $g['inventaire'];
		$ouvert = 'brouillon' === $inv['statut'];
		$sid    = (int) $inv['service_id'];
		$nl     = count( $g['locaux'] );
		$prec   = $g['precedent'];
		$cats   = array_combine( IGE_FFM_Patrimoine::categories(), IGE_FFM_Patrimoine::categories() );
		$xl     = wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_inv_export&id=' . (int) $id ), 'ige_ffm_inv_export' );
		$pdf    = IGE_FFM_Pdf::url( array( 'quoi' => 'inventaire', 'id' => (int) $id ) );
		ob_start();
		?>
		<p><a href="<?php echo esc_url( self::url() ); ?>">← Tous les inventaires</a></p>
		<section class="ige-card" style="padding:16px;margin-bottom:16px">
			<div class="ige-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between">
				<div>
					<h3 style="margin:0">Inventaire <?php echo esc_html( $inv['reference'] ); ?> · service <?php echo esc_html( $g['service']['code'] ?? '' ); ?></h3>
					<div class="ige-help">
						<?php echo esc_html( wp_date( 'd/m/Y', strtotime( $inv['date_inventaire'] ) ) ); ?> ·
						<?php echo esc_html( 'import' === $inv['origine'] ? 'importé du fichier ' . $inv['fichier'] : 'saisi dans l’application' ); ?> ·
						<?php echo $ouvert ? ige_ffm_pill( 'En cours', 'wait' ) : ige_ffm_pill( 'Validé le ' . wp_date( 'd/m/Y', strtotime( $inv['valide_le'] ) ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo $prec ? ' · comparé à ' . esc_html( $prec['reference'] ) : ''; ?>
					</div>
				</div>
				<div class="ige-row" style="gap:8px">
					<a class="ige-btn ige-btn-line" href="<?php echo esc_url( $xl ); ?>">Excel</a>
					<a class="ige-btn ige-btn-line" href="<?php echo esc_url( $pdf ); ?>">PDF</a>
					<?php if ( $ouvert ) : ?>
						<?php echo IGE_FFM_Ecrans::action_button( 'inv_supprimer', array( 'id' => $id ), 'Supprimer', 'ige-btn ige-btn-rouge', 'Supprimer cet inventaire en cours ? Les quantités saisies seront perdues.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
				</div>
			</div>
		</section>
		<?php if ( $ouvert && ! $nl ) : ?>
			<div class="ige-card" style="padding:16px;margin-bottom:16px"><b>Ajoutez d’abord les locaux du service</b> (bureau de l’IGA, pool informatique, secrétariat, archives…) : ce sont les colonnes de l’inventaire.</div>
		<?php endif; ?>
		<?php if ( $ouvert ) : ?>
			<?php echo IGE_FFM_Ecrans::form_open( 'inv_saisie', 'id="ige-inv-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<?php endif; ?>
		<section class="ige-card" style="padding:16px;margin-bottom:16px">
			<div class="ige-table-wrap"><table class="ige-table ige-inv">
				<thead><tr><th>N°</th><th style="min-width:200px">Désignation</th>
				<?php foreach ( $g['locaux'] as $l ) : ?>
					<th class="ige-r" style="min-width:74px"><?php echo esc_html( $l['libelle'] ); ?></th>
				<?php endforeach; ?>
				<th class="ige-r">TOTAL</th>
				<?php if ( $prec ) : ?><th class="ige-r">Précédent</th><th class="ige-r">Écart</th><?php endif; ?>
				<?php if ( $ouvert ) : ?><th></th><?php endif; ?>
				</tr></thead>
				<tbody>
				<?php
				$n   = 0;
				$cat = null;
				foreach ( $g['lignes'] as $ln ) :
					$b = $ln['bien'];
					if ( $cat !== $b['categorie'] ) :
						$cat = $b['categorie'];
						?>
						<tr class="ige-sous"><td colspan="<?php echo (int) ( 3 + $nl + ( $prec ? 2 : 0 ) + ( $ouvert ? 1 : 0 ) ); ?>" style="font-weight:600;background:var(--ige-fond-2,#f4f5f7)"><?php echo esc_html( $cat ?: 'Autres' ); ?></td></tr>
					<?php endif; ?>
					<tr>
						<td class="ige-num"><?php echo (int) ++$n; ?></td>
						<td><?php echo esc_html( $b['designation'] ); ?></td>
						<?php foreach ( $g['locaux'] as $l ) : ?>
							<?php $v = $ln['cells'][ (int) $l['id'] ] ?? 0; ?>
							<td class="ige-r ige-num">
							<?php if ( $ouvert ) : ?>
								<input type="number" min="0" step="1" inputmode="numeric" name="q[<?php echo (int) $b['id']; ?>][<?php echo (int) $l['id']; ?>]" value="<?php echo $v ? (int) $v : ''; ?>" aria-label="<?php echo esc_attr( $b['designation'] . ' · ' . $l['libelle'] ); ?>" class="ige-inv-q">
							<?php else : ?>
								<?php echo $v ? (int) $v : '<span class="ige-help">·</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php endif; ?>
							</td>
						<?php endforeach; ?>
						<td class="ige-r ige-num"><b><?php echo (int) $ln['total']; ?></b></td>
						<?php if ( $prec ) : ?>
							<td class="ige-r ige-num"><?php echo (int) $ln['precedent']; ?></td>
							<td class="ige-r ige-num"><?php echo $ln['ecart'] ? ige_ffm_pill( ( $ln['ecart'] > 0 ? '+' : '' ) . $ln['ecart'], $ln['ecart'] < 0 ? 'ko' : 'neutre' ) : '0'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<?php endif; ?>
						<?php if ( $ouvert ) : ?>
							<td><button type="submit" form="ige-retirer-<?php echo (int) $b['id']; ?>" class="ige-act" style="background:none;border:0;padding:0;cursor:pointer;font:inherit" title="Retirer ce bien de l’inventaire">Retirer</button></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $g['lignes'] ) : ?>
					<tr><td colspan="<?php echo (int) ( 3 + $nl ); ?>" class="ige-empty">Aucun bien : ajoutez les biens comptés ci-dessous.</td></tr>
				<?php endif; ?>
				<?php if ( $ouvert && $nl ) : ?>
					<?php for ( $i = 0; $i < 3; $i++ ) : ?>
						<tr>
							<td class="ige-help">+</td>
							<td>
								<input type="text" name="nouveau[<?php echo (int) $i; ?>][des]" list="ige-biens" placeholder="Nouveau bien (ex. Chaises plastiques)" aria-label="Désignation du nouveau bien" style="width:100%;min-width:180px">
							</td>
							<?php foreach ( $g['locaux'] as $l ) : ?>
								<td class="ige-r"><input type="number" min="0" step="1" inputmode="numeric" name="nouveau[<?php echo (int) $i; ?>][q][<?php echo (int) $l['id']; ?>]" aria-label="<?php echo esc_attr( 'Nouveau bien · ' . $l['libelle'] ); ?>" class="ige-inv-q"></td>
							<?php endforeach; ?>
							<td colspan="<?php echo (int) ( 2 + ( $prec ? 2 : 0 ) ); ?>">
								<select name="nouveau[<?php echo (int) $i; ?>][cat]" aria-label="Catégorie du nouveau bien"><option value="">Catégorie (auto)</option>
								<?php foreach ( $cats as $c ) : ?><option><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endfor; ?>
				<?php endif; ?>
				</tbody>
				<tfoot><tr><th></th><th>TOTAL</th>
				<?php foreach ( $g['locaux'] as $l ) : ?>
					<th class="ige-r ige-num"><?php echo (int) $g['tot_col'][ (int) $l['id'] ]; ?></th>
				<?php endforeach; ?>
				<th class="ige-r ige-num"><?php echo (int) $g['total']; ?></th>
				<?php if ( $prec ) : ?><th class="ige-r ige-num"><?php echo (int) $g['tot_prec']; ?></th><th class="ige-r ige-num"><?php echo esc_html( ( $g['total'] - $g['tot_prec'] > 0 ? '+' : '' ) . ( $g['total'] - $g['tot_prec'] ) ); ?></th><?php endif; ?>
				<?php if ( $ouvert ) : ?><th></th><?php endif; ?>
				</tr></tfoot>
			</table></div>
			<?php if ( $ouvert ) : ?>
				<datalist id="ige-biens">
				<?php foreach ( IGE_FFM_Patrimoine::biens( true ) as $b ) : ?><option value="<?php echo esc_attr( $b['designation'] ); ?>"><?php endforeach; ?>
				</datalist>
				<div class="ige-field" style="margin-top:14px"><label for="ige-obs-inv">Observations</label><textarea id="ige-obs-inv" name="observations" rows="2"><?php echo esc_textarea( $inv['observations'] ); ?></textarea></div>
				<div class="ige-row" style="gap:10px;flex-wrap:wrap">
					<button type="submit" class="ige-btn ige-btn-jaune" name="suite" value="enregistrer">Enregistrer</button>
					<button type="submit" class="ige-btn ige-btn-bleu" name="suite" value="valider" data-ige-valider="1">Enregistrer et valider</button>
					<span class="ige-help">Une fois validé, l’inventaire est figé et devient la référence du service.</span>
				</div>
			<?php elseif ( $inv['observations'] ) : ?>
				<p class="ige-help" style="margin-top:12px">Observations : <?php echo esc_html( $inv['observations'] ); ?></p>
			<?php endif; ?>
		</section>
		<?php if ( $ouvert ) : ?>
			</form>
			<?php foreach ( $g['lignes'] as $ln ) : ?>
				<form id="ige-retirer-<?php echo (int) $ln['bien']['id']; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-ige-confirm="<?php echo esc_attr( 'Retirer « ' . $ln['bien']['designation'] . ' » de cet inventaire ?' ); ?>" hidden>
					<?php echo ige_ffm_form_fields( 'inv_retirer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>"><input type="hidden" name="bien" value="<?php echo (int) $ln['bien']['id']; ?>">
				</form>
			<?php endforeach; ?>
			<section class="ige-card" style="padding:16px">
				<h3 style="margin-top:0">Locaux du service <?php echo esc_html( $g['service']['code'] ?? '' ); ?></h3>
				<p class="ige-help">Les colonnes de l’inventaire. Un local retiré n’apparaît plus dans les nouveaux inventaires ; les inventaires validés le conservent.</p>
				<?php if ( $g['locaux'] ) : ?>
					<div class="ige-table-wrap"><table class="ige-table" style="max-width:640px"><tbody>
					<?php foreach ( $g['locaux'] as $l ) : ?>
						<tr>
							<td>
								<?php echo IGE_FFM_Ecrans::form_open( 'inv_local', 'class="ige-row" style="gap:8px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<input type="hidden" name="id" value="<?php echo (int) $id; ?>"><input type="hidden" name="local" value="<?php echo (int) $l['id']; ?>">
								<input type="text" name="libelle" value="<?php echo esc_attr( $l['libelle'] ); ?>" aria-label="Nom du local" style="max-width:260px">
								<button type="submit" class="ige-btn ige-btn-line">Renommer</button>
								</form>
							</td>
							<td class="ige-r"><?php echo IGE_FFM_Ecrans::action_button( 'inv_local', array( 'id' => $id, 'local' => $l['id'], 'libelle' => '' ), 'Retirer', 'ige-act', 'Retirer ce local des inventaires du service ? Les quantités saisies dans cette colonne pour l’inventaire en cours seront ignorées.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table></div>
				<?php endif; ?>
				<?php echo IGE_FFM_Ecrans::form_open( 'inv_local', 'class="ige-row" style="gap:8px;margin-top:10px;flex-wrap:wrap;align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php echo IGE_FFM_Ecrans::field( 'libelle', 'Nouveau local', '', array( 'required' => true, 'attrs' => 'placeholder="ex. SECRÉTARIAT"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<button type="submit" class="ige-btn ige-btn-line">Ajouter le local</button>
				<span class="ige-help">Enregistrez d’abord la grille : l’ajout d’un local recharge la page.</span>
				</form>
			</section>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	/**
	 * Tableau consolidé biens × services.
	 *
	 * @return string
	 */
	private static function onglet_consolide() {
		$c = IGE_FFM_Patrimoine::consolide();
		$xl = wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_inv_export&id=0' ), 'ige_ffm_inv_export' );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-row" style="justify-content:space-between;flex-wrap:wrap;gap:8px">
				<p class="ige-help" style="margin:0">Quantités du dernier inventaire validé de chaque service.</p>
				<span><a class="ige-btn ige-btn-line" href="<?php echo esc_url( $xl ); ?>">Excel</a> <a class="ige-btn ige-btn-line" href="<?php echo esc_url( IGE_FFM_Pdf::url( array( 'quoi' => 'inventaire', 'id' => 0 ) ) ); ?>">PDF</a></span>
			</div>
			<div class="ige-table-wrap" style="margin-top:10px"><table class="ige-table">
				<thead><tr><th>Désignation</th><th>Catégorie</th>
				<?php foreach ( $c['services'] as $code ) : ?><th class="ige-r"><?php echo esc_html( $code ); ?></th><?php endforeach; ?>
				<th class="ige-r">TOTAL</th></tr></thead>
				<tbody>
				<?php foreach ( $c['lignes'] as $l ) : ?>
					<tr><td><?php echo esc_html( $l[0]['designation'] ); ?></td><td><?php echo esc_html( $l[0]['categorie'] ); ?></td>
					<?php foreach ( array_keys( $c['services'] ) as $sid ) : ?><td class="ige-r ige-num"><?php echo empty( $l[1][ $sid ] ) ? '<span class="ige-help">·</span>' : (int) $l[1][ $sid ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?>
					<td class="ige-r ige-num"><b><?php echo (int) $l[2]; ?></b></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $c['lignes'] ) : ?><tr><td colspan="3" class="ige-empty">Aucun inventaire validé pour le moment.</td></tr><?php endif; ?>
				</tbody>
				<?php if ( $c['lignes'] ) : ?>
					<tfoot><tr><th>TOTAL</th><th></th><?php foreach ( $c['totaux'] as $v ) : ?><th class="ige-r ige-num"><?php echo (int) $v; ?></th><?php endforeach; ?><th class="ige-r ige-num"><?php echo (int) $c['total']; ?></th></tr></tfoot>
				<?php endif; ?>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Catalogue des biens.
	 *
	 * @return string
	 */
	private static function onglet_biens() {
		$cats = array_combine( IGE_FFM_Patrimoine::categories(), IGE_FFM_Patrimoine::categories() );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px;margin-bottom:16px">
			<h3 style="margin-top:0">Ajouter un bien</h3>
			<?php echo IGE_FFM_Ecrans::form_open( 'inv_bien', 'class="ige-row" style="gap:10px;flex-wrap:wrap;align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo IGE_FFM_Ecrans::field( 'designation', 'Désignation', '', array( 'required' => true ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo IGE_FFM_Ecrans::field( 'categorie', 'Catégorie', '', array( 'type' => 'select', 'options' => array( '' => 'Automatique' ) + $cats ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<button type="submit" class="ige-btn ige-btn-jaune">Ajouter</button>
			</form>
		</section>
		<section class="ige-card" style="padding:16px">
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Désignation</th><th>Catégorie</th><th>Actif</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( IGE_FFM_Patrimoine::biens() as $b ) : ?>
					<tr>
						<td colspan="4">
							<?php echo IGE_FFM_Ecrans::form_open( 'inv_bien', 'class="ige-row" style="gap:10px;flex-wrap:wrap;align-items:center"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<input type="hidden" name="bien" value="<?php echo (int) $b['id']; ?>">
							<input type="text" name="designation" value="<?php echo esc_attr( $b['designation'] ); ?>" aria-label="Désignation" style="flex:1;min-width:200px">
							<select name="categorie" aria-label="Catégorie">
							<?php foreach ( $cats + array( $b['categorie'] => $b['categorie'] ) as $c ) : ?><option<?php selected( $b['categorie'], $c ); ?>><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
							</select>
							<label class="ige-row" style="gap:4px"><input type="checkbox" name="actif" value="1"<?php checked( (int) $b['actif'], 1 ); ?> style="width:auto"> Actif</label>
							<button type="submit" class="ige-btn ige-btn-line">Enregistrer</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ Traitements */

	/**
	 * Fin d'un traitement : message et redirection.
	 *
	 * @param WP_Error|mixed $r     Résultat.
	 * @param string         $titre Titre en cas de refus.
	 * @param string         $ok    Titre en cas de succès.
	 * @param string         $texte Texte en cas de succès.
	 * @param array          $vers  Paramètres de la page de retour.
	 */
	private static function fin( $r, $titre, $ok, $texte, array $vers ) {
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', $titre, $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', $ok, $texte );
		}
		wp_safe_redirect( self::url( $vers ) );
		exit;
	}

	/** Ouvrir un inventaire. */
	public static function handle_ouvrir() {
		ige_ffm_check_form( 'inv_ouvrir' );
		ige_ffm_require( 'ige_logistique' );
		$r = IGE_FFM_Patrimoine::ouvrir( array( 'service_id' => (int) ige_ffm_post( 'service_id' ), 'date' => ige_ffm_post( 'date' ), 'reprendre' => '1' === ige_ffm_post( 'reprendre' ) ) );
		self::fin( $r, 'Inventaire non ouvert', 'Inventaire ouvert', 'Saisissez les quantités local par local, puis validez.', is_wp_error( $r ) ? array() : array( 'inventaire' => $r ) );
	}

	/** Import d'un fichier Excel. */
	public static function handle_import() {
		ige_ffm_check_form( 'inv_import' );
		ige_ffm_require( 'ige_logistique' );
		$f = isset( $_FILES['fichier'] ) ? $_FILES['fichier'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::fin( new WP_Error( 'fichier', 'Choisissez le fichier Excel (.xlsx) de l’inventaire.' ), 'Aucun fichier reçu', '', '', array() );
		}
		if ( (int) $f['size'] > 5 * MB_IN_BYTES || ! preg_match( '/\.xlsx$/i', (string) $f['name'] ) ) {
			self::fin( new WP_Error( 'fichier', 'Un fichier Excel .xlsx de 5 Mo au plus.' ), 'Fichier refusé', '', '', array() );
		}
		$r = IGE_FFM_Patrimoine::importer( $f['tmp_name'], sanitize_file_name( $f['name'] ), array( 'service_id' => (int) ige_ffm_post( 'service_id' ), 'date' => ige_ffm_post( 'date' ) ) );
		if ( is_wp_error( $r ) ) {
			self::fin( $r, 'Fichier non importé', '', '', array() );
		}
		$t = sprintf( '%d biens (%d nouveaux au catalogue), %d locaux, %s unités. Contrôlez la grille puis validez.', $r['biens'], $r['nouveaux_biens'], $r['locaux'], ige_ffm_montant( $r['total'], '' ) );
		if ( $r['avertissements'] ) {
			$t .= ' Attention : ' . implode( ' ', array_slice( $r['avertissements'], 0, 5 ) );
		}
		self::fin( $r, '', 'Inventaire importé', $t, array( 'inventaire' => $r['id'] ) );
	}

	/** Enregistrer (et valider) la grille. */
	public static function handle_saisie() {
		ige_ffm_check_form( 'inv_saisie' );
		ige_ffm_require( 'ige_logistique' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$id = (int) ige_ffm_post( 'id' );
		$q  = array();
		foreach ( (array) ( $_POST['q'] ?? array() ) as $bid => $par_local ) {
			foreach ( (array) $par_local as $lid => $v ) {
				$q[ (int) $bid ][ (int) $lid ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
		}
		$nouveaux = array();
		foreach ( (array) ( $_POST['nouveau'] ?? array() ) as $n ) {
			$des = sanitize_text_field( wp_unslash( (string) ( $n['des'] ?? '' ) ) );
			if ( '' === trim( $des ) ) {
				continue;
			}
			$qq = array();
			foreach ( (array) ( $n['q'] ?? array() ) as $lid => $v ) {
				$qq[ (int) $lid ] = sanitize_text_field( wp_unslash( (string) $v ) );
			}
			$nouveaux[] = array( $des, sanitize_text_field( wp_unslash( (string) ( $n['cat'] ?? '' ) ) ), $qq );
		}
		$obs = isset( $_POST['observations'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observations'] ) ) : '';
		// phpcs:enable
		$r = IGE_FFM_Patrimoine::enregistrer( $id, $q, array( 'nouveaux' => $nouveaux, 'observations' => $obs ) );
		if ( is_wp_error( $r ) ) {
			self::fin( $r, 'Inventaire non enregistré', '', '', array( 'inventaire' => $id ) );
		}
		if ( 'valider' === ige_ffm_post( 'suite' ) ) {
			$v = IGE_FFM_Patrimoine::valider( $id );
			$g = IGE_FFM_Patrimoine::grille( $id );
			self::fin( $v, 'Quantités enregistrées, mais inventaire non validé', 'Inventaire validé', sprintf( '%s · %s unités. Il est désormais la référence du service.', $g['inventaire']['reference'], ige_ffm_montant( $g['total'], '' ) ), array( 'inventaire' => $id ) );
		}
		self::fin( $r, '', 'Quantités enregistrées', 'L’inventaire reste en cours : validez-le une fois le comptage terminé.', array( 'inventaire' => $id ) );
	}

	/** Supprimer un inventaire en cours. */
	public static function handle_supprimer() {
		ige_ffm_check_form( 'inv_supprimer' );
		ige_ffm_require( 'ige_logistique' );
		$id = (int) ige_ffm_post( 'id' );
		$r  = IGE_FFM_Patrimoine::supprimer( $id );
		self::fin( $r, 'Suppression refusée', 'Inventaire supprimé', '', is_wp_error( $r ) ? array( 'inventaire' => $id ) : array() );
	}

	/** Retirer un bien d'un inventaire en cours. */
	public static function handle_retirer() {
		ige_ffm_check_form( 'inv_retirer' );
		ige_ffm_require( 'ige_logistique' );
		$id = (int) ige_ffm_post( 'id' );
		$r  = IGE_FFM_Patrimoine::retirer_bien( $id, (int) ige_ffm_post( 'bien' ) );
		self::fin( $r, 'Bien non retiré', 'Bien retiré de l’inventaire', '', array( 'inventaire' => $id ) );
	}

	/** Ajouter, renommer ou retirer un local. */
	public static function handle_local() {
		ige_ffm_check_form( 'inv_local' );
		ige_ffm_require( 'ige_logistique' );
		$id  = (int) ige_ffm_post( 'id' );
		$inv = IGE_FFM_Patrimoine::inventaire( $id );
		if ( ! $inv ) {
			self::fin( new WP_Error( 'inventaire', 'Inventaire introuvable.' ), 'Local non enregistré', '', '', array() );
		}
		$lid = (int) ige_ffm_post( 'local' );
		$lib = ige_ffm_post( 'libelle' );
		if ( $lid ) {
			$r = IGE_FFM_Patrimoine::modifier_local( $lid, $lib );
			self::fin( $r, 'Local non modifié', '' === $lib ? 'Local retiré' : 'Local renommé', '', array( 'inventaire' => $id ) );
		}
		$r = IGE_FFM_Patrimoine::ajouter_local( (int) $inv['service_id'], $lib );
		self::fin( $r, 'Local non ajouté', 'Local ajouté', 'Une nouvelle colonne est disponible dans la grille.', array( 'inventaire' => $id ) );
	}

	/** Créer ou modifier un bien du catalogue. */
	public static function handle_bien() {
		ige_ffm_check_form( 'inv_bien' );
		ige_ffm_require( 'ige_logistique' );
		$bid = (int) ige_ffm_post( 'bien' );
		$r   = IGE_FFM_Patrimoine::enregistrer_bien( array( 'designation' => ige_ffm_post( 'designation' ), 'categorie' => ige_ffm_post( 'categorie' ), 'actif' => $bid ? '1' === ige_ffm_post( 'actif' ) : true ), $bid );
		self::fin( $r, 'Bien non enregistré', $bid ? 'Bien modifié' : 'Bien ajouté au catalogue', '', array( 'onglet' => 'biens' ) );
	}

	/**
	 * Données d'export du tableau consolidé.
	 *
	 * @return array
	 */
	public static function export_consolide() {
		$c    = IGE_FFM_Patrimoine::consolide();
		$cols = array( array( 'Désignation', 'texte', 32 ), array( 'Catégorie', 'texte', 18 ) );
		foreach ( $c['services'] as $code ) {
			$cols[] = array( $code, 'nombre', 9 );
		}
		$cols[] = array( 'TOTAL', 'nombre', 10 );
		$lignes = array();
		foreach ( $c['lignes'] as $l ) {
			$r = array( $l[0]['designation'], $l[0]['categorie'] );
			foreach ( array_keys( $c['services'] ) as $sid ) {
				$r[] = $l[1][ $sid ] ?? null;
			}
			$r[]      = $l[2];
			$lignes[] = $r;
		}
		return array(
			'titre'      => 'Inventaire consolidé des services',
			'sous_titre' => 'Dernier inventaire validé de chaque service · au ' . wp_date( 'd/m/Y' ),
			'colonnes'   => $cols,
			'lignes'     => $lignes,
			'total'      => array_merge( array( 'TOTAL', '' ), array_values( $c['totaux'] ), array( $c['total'] ) ),
			'notes'      => array( 'Inspection Générale Adjointe chargée de l’Administration et Finances.' ),
			'fichier'    => 'inventaire-consolide-' . wp_date( 'Y-m-d' ),
			'paysage'    => true,
		);
	}

	/** Export Excel d'un inventaire (id) ou du consolidé (id = 0). */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_inv_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_logistique' );
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		$t  = $id ? IGE_FFM_Patrimoine::export_donnees( $id ) : self::export_consolide();
		if ( ! $t ) {
			wp_die( 'Inventaire introuvable.', '', array( 'response' => 404 ) );
		}
		$c = new IGE_FFM_Classeur( $t['titre'] );
		$c->feuille( $id ? 'Inventaire' : 'Consolidé', $t );
		IGE_FFM_Audit::log( 'export_patrimoine', 'pat_inventaire', $id ? $t['fichier'] : 'consolidé', null, array( 'format' => 'xlsx' ) );
		$c->telecharger( $t['fichier'] );
	}
}
