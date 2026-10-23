<?php
/**
 * Écran Administration > Services, catégories, rubriques, modes de paiement.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran des référentiels.
 */
final class IGE_FFM_Ecran_Referentiels {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_referentiel', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Référentiels';
		$args['subtitle'] = 'Services, catégories, rubriques et modes de paiement · aucun élément n’est supprimé, il est désactivé';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Onglet courant.
	 *
	 * @return string
	 */
	private static function onglet() {
		$o = isset( $_GET['onglet'] ) ? sanitize_key( $_GET['onglet'] ) : 'service'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( IGE_FFM_Referentiels::types()[ $o ] ) ? $o : 'service';
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$type   = self::onglet();
		$types  = IGE_FFM_Referentiels::types();
		$rows   = IGE_FFM_Referentiels::all( $type );
		$edit   = isset( $_GET['modifier'] ) ? IGE_FFM_Referentiels::get( $type, (int) $_GET['modifier'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base   = get_permalink();

		$tabs = '<div class="ige-tabs" role="tablist">';
		foreach ( $types as $k => $t ) {
			$count = count( IGE_FFM_Referentiels::all( $k ) );
			$tabs .= '<a role="tab" href="' . esc_url( add_query_arg( 'onglet', $k, $base ) ) . '" class="' . ( $k === $type ? 'ige-on' : '' ) . '"' . ( $k === $type ? ' aria-selected="true"' : '' ) . '>' . esc_html( $t['label'] . ' (' . $count . ')' ) . '</a>';
		}
		$tabs .= '</div>';

		ob_start();
		?>
		<div class="ige-grid ige-adm-split">
			<section class="ige-card" style="padding:16px">
				<?php echo $tabs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-table-wrap">
					<table class="ige-table">
						<?php echo self::table( $type, $rows, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</table>
				</div>
				<p class="ige-small ige-muted" style="margin:12px 0 0">
					<?php
					echo esc_html(
						'rubrique' === $type
							? 'Les rubriques sont communes à tous les services. La nomenclature relie chaque rubrique à sa ligne budgétaire officielle. Un élément utilisé par des écritures ne peut qu’être désactivé.'
							: 'Un élément désactivé n’est plus proposé à la saisie mais reste visible dans l’historique. Chaque modification est inscrite au journal d’audit.'
					);
					?>
				</p>
			</section>
			<section class="ige-card" id="ige-formulaire">
				<?php echo self::form( $type, $edit, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Tableau d'un référentiel.
	 *
	 * @param string $type Type.
	 * @param array  $rows Lignes.
	 * @param string $base Adresse de la page.
	 * @return string
	 */
	private static function table( $type, array $rows, $base ) {
		$link = static fn( $r ) => '<a class="ige-act" href="' . esc_url( add_query_arg( array( 'onglet' => $type, 'modifier' => $r['id'] ), $base ) . '#ige-formulaire' ) . '">Modifier</a>';
		$h    = '';
		switch ( $type ) {
			case 'service':
				$comptes = array();
				foreach ( IGE_FFM_Comptes::search() as $c ) {
					if ( $c['service_id'] ) {
						$comptes[ $c['service_id'] ] = ( $comptes[ $c['service_id'] ] ?? 0 ) + 1;
					}
				}
				$h .= '<thead><tr><th>Code</th><th>Libellé</th><th class="ige-r">Comptes</th><th>Actif</th><th></th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$h .= '<tr><td><b>' . esc_html( $r['code'] ) . '</b></td><td>' . esc_html( $r['libelle'] ) . '</td><td class="ige-r">' . (int) ( $comptes[ $r['id'] ] ?? 0 ) . '</td><td>' . ige_ffm_switch( (bool) $r['actif'] ) . '</td><td>' . $link( $r ) . '</td></tr>';
				}
				break;
			case 'categorie':
				$par_cat = array();
				foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $rub ) {
					$par_cat[ $rub['categorie_id'] ] = ( $par_cat[ $rub['categorie_id'] ] ?? 0 ) + 1;
				}
				$h .= '<thead><tr><th>Libellé</th><th class="ige-r">Rubriques</th><th>Active</th><th></th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$h .= '<tr><td>' . esc_html( $r['libelle'] ) . '</td><td class="ige-r">' . (int) ( $par_cat[ $r['id'] ] ?? 0 ) . '</td><td>' . ige_ffm_switch( (bool) $r['actif'] ) . '</td><td>' . $link( $r ) . '</td></tr>';
				}
				break;
			case 'rubrique':
				$h .= '<thead><tr><th>Code</th><th>Libellé</th><th>Catégorie</th><th>Pièces exigées</th><th>Nomenclature de l’État</th><th>Active</th><th></th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$nom = $r['nomenclature'] ? '<span class="ige-code">' . esc_html( $r['nomenclature'] ) . '</span>' : ige_ffm_pill( 'À relier', 'neutre' );
					$h  .= '<tr><td><span class="ige-code">' . esc_html( $r['code'] ) . '</span></td><td>' . esc_html( $r['libelle'] ) . '</td><td>' . esc_html( IGE_FFM_Referentiels::categorie_libelle( (int) $r['categorie_id'] ) ) . '</td><td>' . esc_html( $r['pieces_exigees'] ) . '</td><td>' . $nom . '</td><td>' . ige_ffm_switch( (bool) $r['actif'] ) . '</td><td>' . $link( $r ) . '</td></tr>';
				}
				break;
			default:
				$h .= '<thead><tr><th>Libellé</th><th>Actif</th><th></th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					$h .= '<tr><td>' . esc_html( $r['libelle'] ) . '</td><td>' . ige_ffm_switch( (bool) $r['actif'] ) . '</td><td>' . $link( $r ) . '</td></tr>';
				}
		}
		if ( ! $rows ) {
			$h .= '<tr><td colspan="7" class="ige-empty">Aucun élément.</td></tr>';
		}
		return $h . '</tbody>';
	}

	/**
	 * Formulaire d'ajout ou de modification.
	 *
	 * @param string     $type Type.
	 * @param array|null $edit Élément modifié.
	 * @param string     $base Adresse de la page.
	 * @return string
	 */
	private static function form( $type, $edit, $base ) {
		$t   = IGE_FFM_Referentiels::types()[ $type ];
		$old = IGE_FFM_Ecrans::old();
		$v   = static fn( $k, $d = '' ) => $old[ $k ] ?? ( $edit[ $k ] ?? $d );

		$art = in_array( $t['single'], array( 'catégorie', 'rubrique' ), true ) ? 'une ' : 'un ';
		$h   = '<h2>' . esc_html( $edit ? 'Modifier : ' . ( $edit['code'] ?? $edit['libelle'] ) : 'Ajouter ' . $art . $t['single'] ) . '</h2>';
		$h .= IGE_FFM_Ecrans::form_open( 'referentiel' );
		$h .= '<input type="hidden" name="type" value="' . esc_attr( $type ) . '"><input type="hidden" name="id" value="' . esc_attr( $edit['id'] ?? 0 ) . '">';
		if ( in_array( 'code', $t['fields'], true ) ) {
			$h .= IGE_FFM_Ecrans::field( 'code', 'Code', $v( 'code' ), array( 'required' => true, 'help' => 'rubrique' === $type ? 'Exemple : R11' : 'Exemple : 8019' ) );
		}
		$h .= IGE_FFM_Ecrans::field( 'libelle', 'Libellé', $v( 'libelle' ), array( 'required' => true ) );
		if ( 'rubrique' === $type ) {
			$cats = array( '' => '— Choisir —' );
			foreach ( IGE_FFM_Referentiels::all( 'categorie' ) as $c ) {
				$cats[ $c['id'] ] = $c['libelle'] . ( $c['actif'] ? '' : ' (inactive)' );
			}
			$h .= IGE_FFM_Ecrans::field( 'categorie_id', 'Catégorie', $v( 'categorie_id' ), array( 'type' => 'select', 'options' => $cats, 'required' => true ) );
			$h .= IGE_FFM_Ecrans::field( 'pieces_exigees', 'Pièces exigées', $v( 'pieces_exigees' ), array( 'help' => 'Affichées à l’agent lors de la saisie. Exemple : Facture, bon de réception' ) );
			$h .= IGE_FFM_Ecrans::field( 'nomenclature', 'Ligne de la nomenclature de l’État', $v( 'nomenclature' ), array( 'help' => 'À renseigner avec le service du Budget.' ) );
		}
		$h .= IGE_FFM_Ecrans::field( 'ordre', 'Ordre d’affichage', $v( 'ordre', '' ), array( 'type' => 'number', 'help' => 'Laisser vide pour placer en fin de liste.' ) );
		$h .= IGE_FFM_Ecrans::field( 'actif', 'Actif (proposé à la saisie)', $edit ? (bool) $v( 'actif' ) : true, array( 'type' => 'checkbox' ) );
		if ( $edit ) {
			$usage = IGE_FFM_Referentiels::usage( $type, (int) $edit['id'] );
			if ( $usage ) {
				$h .= '<p class="ige-small ige-muted">' . esc_html( sprintf( 'Utilisé par %d écriture(s) : il ne peut pas être supprimé, seulement désactivé.', $usage ) ) . '</p>';
			}
		}
		$h .= '<div class="ige-row"><button type="submit" class="ige-btn ige-btn-jaune">' . esc_html( $edit ? 'Enregistrer' : 'Ajouter' ) . '</button>';
		if ( $edit ) {
			$h .= '<a class="ige-btn ige-btn-line" href="' . esc_url( add_query_arg( 'onglet', $type, $base ) ) . '">Annuler</a>';
		}
		return $h . '</div></form>';
	}

	/**
	 * Traitement du formulaire.
	 */
	public static function handle() {
		ige_ffm_check_form( 'referentiel' );
		ige_ffm_require( 'ige_administrer' );
		$type = sanitize_key( ige_ffm_post( 'type' ) );
		$id   = (int) ige_ffm_post( 'id' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data   = wp_unslash( $_POST );
		$result = IGE_FFM_Referentiels::save( $type, $data, $id );
		$back   = ige_ffm_url( 'referentiels', array( 'onglet' => $type ) );
		if ( is_wp_error( $result ) ) {
			IGE_FFM_Ecrans::remember( array_map( 'sanitize_text_field', array_intersect_key( $data, array_flip( array( 'code', 'libelle', 'categorie_id', 'pieces_exigees', 'nomenclature', 'ordre', 'actif' ) ) ) ) );
			ige_ffm_flash( 'ko', 'Enregistrement refusé', $result->get_error_message() );
			wp_safe_redirect( $id ? add_query_arg( 'modifier', $id, $back ) . '#ige-formulaire' : $back . '#ige-formulaire' );
			exit;
		}
		ige_ffm_flash( 'ok', $id ? 'Modification enregistrée' : 'Élément ajouté', '' );
		wp_safe_redirect( $back );
		exit;
	}
}
