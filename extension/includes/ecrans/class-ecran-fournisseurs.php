<?php
/**
 * Écran Fournisseurs (Administration et Finance) : répertoire, statistiques, doublons, fusion.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fournisseurs.
 */
final class IGE_FFM_Ecran_Fournisseurs {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_fournisseur', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ige_ffm_fournisseur_fusion', array( __CLASS__, 'handle_fusion' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Fournisseurs';
		$args['subtitle'] = 'Répertoire commun à tous les services · repérage des doublons';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex    = IGE_FFM_Contexte::exercice();
		$stats = $ex ? IGE_FFM_Fournisseurs::statistiques( (int) $ex['id'] ) : array();
		$all   = IGE_FFM_Fournisseurs::all( false );
		$dbl   = IGE_FFM_Fournisseurs::doublons();
		$mod   = isset( $_GET['modifier'] ) ? IGE_FFM_Fournisseurs::get( (int) $_GET['modifier'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$old   = IGE_FFM_Ecrans::old();
		$val   = static fn( $k ) => isset( $old[ $k ] ) ? (string) $old[ $k ] : ( $mod ? (string) $mod[ $k ] : '' );
		$q     = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $q ) {
			$nq  = IGE_FFM_Fournisseurs::normaliser( $q );
			$all = array_filter( $all, static fn( $f ) => false !== strpos( IGE_FFM_Fournisseurs::normaliser( $f['raison_sociale'] ), $nq ) || false !== stripos( $f['nif'] . ' ' . $f['telephone'], $q ) );
		}
		usort( $all, static fn( $a, $b ) => ( $stats[ $b['id'] ]['paye'] ?? 0 ) <=> ( $stats[ $a['id'] ]['paye'] ?? 0 ) ?: strcmp( $a['raison_sociale'], $b['raison_sociale'] ) );
		$page = ige_ffm_url( 'fournisseurs' );

		ob_start();
		if ( $dbl ) {
			echo ige_ffm_banner( 'warn', count( $dbl ) . ' groupe(s) de doublons probables', ' Même nom, même NIF ou même téléphone. Fusionnez-les pour que les statistiques et les contrôles soient justes.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-adm-split">
			<section class="ige-card">
				<h2>Répertoire <span class="ige-sub"><?php echo esc_html( count( $all ) . ' fournisseur(s)' . ( $ex ? ' · montants payés ' . $ex['annee'] : '' ) ); ?></span></h2>
				<form method="get" class="ige-toolbar" action="<?php echo esc_url( $page ); ?>">
					<label class="screen-reader-text" for="ige-q">Rechercher</label>
					<input type="search" id="ige-q" name="q" class="ige-input" style="max-width:280px" value="<?php echo esc_attr( $q ); ?>" placeholder="Nom, NIF ou téléphone">
					<button class="ige-btn ige-btn-line" type="submit">Rechercher</button>
				</form>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Fournisseur</th><th>NIF / RCCM</th><th>Téléphone</th><th class="ige-r">Payé</th><th class="ige-r">Services</th><th>État</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $all as $f ) : ?>
						<?php $s = $stats[ $f['id'] ] ?? array( 'paye' => 0, 'services' => 0 ); ?>
						<tr>
							<td><b><?php echo esc_html( $f['raison_sociale'] ); ?></b></td>
							<td><?php echo esc_html( trim( $f['nif'] . ( $f['rccm'] ? ' · ' . $f['rccm'] : '' ) ) ?: '—' ); ?></td>
							<td><?php echo esc_html( $f['telephone'] ?: '—' ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( $s['paye'], '' ) ); ?></td>
							<td class="ige-r"><?php echo esc_html( $s['services'] ); ?></td>
							<td>
								<?php
								if ( $f['fusionne_dans'] ) {
									echo ige_ffm_pill( 'Fusionné', 'neutre' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								} else {
									echo (int) $f['actif'] ? ige_ffm_pill( 'Actif', 'ok' ) : ige_ffm_pill( 'Inactif', 'neutre' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</td>
							<td><?php if ( ! $f['fusionne_dans'] ) : ?><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'modifier', $f['id'], $page ) ); ?>">Modifier</a><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $all ) : ?>
						<tr><td colspan="7" class="ige-empty">Aucun fournisseur. Les services en créent en saisissant leurs dépenses.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
			</section>

			<div class="ige-grid">
				<section class="ige-card">
					<h2><?php echo $mod ? 'Modifier le fournisseur' : 'Ajouter un fournisseur'; ?></h2>
					<?php echo IGE_FFM_Ecrans::form_open( 'fournisseur' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<input type="hidden" name="id" value="<?php echo esc_attr( $mod ? $mod['id'] : 0 ); ?>">
						<?php echo IGE_FFM_Ecrans::field( 'raison_sociale', 'Raison sociale', $val( 'raison_sociale' ), array( 'required' => true, 'attrs' => 'maxlength="190"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo IGE_FFM_Ecrans::field( 'nif', 'NIF', $val( 'nif' ), array( 'attrs' => 'maxlength="40"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo IGE_FFM_Ecrans::field( 'rccm', 'RCCM', $val( 'rccm' ), array( 'attrs' => 'maxlength="60"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo IGE_FFM_Ecrans::field( 'telephone', 'Téléphone', $val( 'telephone' ), array( 'type' => 'tel' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php if ( $mod ) : ?>
							<?php echo IGE_FFM_Ecrans::field( 'actif', 'Actif (proposé à la saisie)', isset( $old['actif'] ) ? $old['actif'] : $mod['actif'], array( 'type' => 'checkbox' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endif; ?>
						<div class="ige-row">
							<button type="submit" class="ige-btn ige-btn-bleu"><?php echo $mod ? 'Enregistrer' : 'Ajouter'; ?></button>
							<?php if ( $mod ) : ?><a class="ige-btn ige-btn-line" href="<?php echo esc_url( $page ); ?>">Annuler</a><?php endif; ?>
						</div>
					</form>
				</section>

				<?php foreach ( $dbl as $groupe ) : ?>
					<section class="ige-card">
						<h2>Doublon probable</h2>
						<?php echo IGE_FFM_Ecrans::form_open( 'fournisseur_fusion', 'data-ige-confirm="Fusionner ? Les écritures du fournisseur absorbé passeront au fournisseur conservé. Opération définitive."' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<p class="ige-small ige-muted" style="margin-top:0">Choisissez la fiche à conserver :</p>
							<?php foreach ( $groupe as $i => $f ) : ?>
								<label class="ige-row" style="font-weight:500;margin-bottom:6px"><input type="radio" name="garde" value="<?php echo esc_attr( $f['id'] ); ?>" style="width:auto"<?php checked( 0, $i ); ?>> <?php echo esc_html( $f['raison_sociale'] . ( $f['telephone'] ? ' · ' . $f['telephone'] : '' ) ); ?></label>
							<?php endforeach; ?>
							<input type="hidden" name="groupe" value="<?php echo esc_attr( implode( ',', wp_list_pluck( $groupe, 'id' ) ) ); ?>">
							<button type="submit" class="ige-btn ige-btn-line" style="margin-top:6px">Fusionner</button>
						</form>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Création ou modification.
	 */
	public static function handle_save() {
		ige_ffm_check_form( 'fournisseur' );
		ige_ffm_require( 'ige_administrer' );
		$id   = (int) ige_ffm_post( 'id' );
		$data = array(
			'raison_sociale' => ige_ffm_post( 'raison_sociale' ),
			'nif'            => ige_ffm_post( 'nif' ),
			'rccm'           => ige_ffm_post( 'rccm' ),
			'telephone'      => ige_ffm_post( 'telephone' ),
			'actif'          => ige_ffm_post( 'actif' ) ? 1 : 0,
		);
		$res = IGE_FFM_Fournisseurs::save( $data, $id, get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			IGE_FFM_Ecrans::remember( $data );
			ige_ffm_flash( 'ko', 'Fournisseur non enregistré', $res->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'fournisseurs', $id ? array( 'modifier' => $id ) : array() ) );
			exit;
		}
		ige_ffm_flash( 'ok', $id ? 'Fournisseur modifié' : 'Fournisseur ajouté', '' );
		wp_safe_redirect( ige_ffm_url( 'fournisseurs' ) );
		exit;
	}

	/**
	 * Fusion d'un groupe de doublons dans la fiche choisie.
	 */
	public static function handle_fusion() {
		ige_ffm_check_form( 'fournisseur_fusion' );
		ige_ffm_require( 'ige_administrer' );
		$garde  = (int) ige_ffm_post( 'garde' );
		$groupe = array_filter( array_map( 'intval', explode( ',', ige_ffm_post( 'groupe' ) ) ) );
		if ( ! in_array( $garde, $groupe, true ) ) {
			ige_ffm_flash( 'ko', 'Fusion refusée', 'Choisissez la fiche à conserver.' );
			wp_safe_redirect( ige_ffm_url( 'fournisseurs' ) );
			exit;
		}
		$n = 0;
		foreach ( $groupe as $absorbe ) {
			if ( $absorbe !== $garde && ! is_wp_error( IGE_FFM_Fournisseurs::fusionner( $garde, $absorbe, get_current_user_id() ) ) ) {
				++$n;
			}
		}
		$g = IGE_FFM_Fournisseurs::get( $garde );
		ige_ffm_flash( 'ok', 'Fusion effectuée', sprintf( '%d fiche(s) rattachée(s) à « %s ».', $n, $g ? $g['raison_sociale'] : '' ) );
		wp_safe_redirect( ige_ffm_url( 'fournisseurs' ) );
		exit;
	}
}
