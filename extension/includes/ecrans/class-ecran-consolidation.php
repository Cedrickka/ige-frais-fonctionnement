<?php
/**
 * Écran Consolidation (EF-71) : par service, catégorie, rubrique et mois ; export Excel (CSV).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consolidation.
 */
final class IGE_FFM_Ecran_Consolidation {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_conso_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Onglets.
	 *
	 * @return array<string,string>
	 */
	private static function vues() {
		return array( 'services' => 'Par service', 'categories' => 'Par catégorie', 'rubriques' => 'Par rubrique', 'mois' => 'Par mois' );
	}

	/**
	 * Onglet courant.
	 *
	 * @return string
	 */
	private static function vue() {
		$v = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : 'services'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::vues()[ $v ] ) ? $v : 'services';
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Consolidation';
		$args['subtitle'] = 'Écritures validées uniquement · au ' . ige_ff_date_fr_safe();
		$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_conso_export&vue=' . self::vue() ), 'ige_ffm_conso_export' ) ) . '">Exporter (Excel)</a>';
		if ( current_user_can( 'ige_administrer' ) ) {
			$args['actions'] .= '<a class="ige-btn ige-btn-jaune" href="' . esc_url( ige_ffm_url( 'rapports', array( 'type' => 'etat_consolide' ) ) ) . '">État consolidé (PDF)</a>';
		}
		return $args;
	}

	/**
	 * Tableau d'une vue : en-têtes et lignes (texte brut, montants entiers).
	 *
	 * @param string $vue Vue.
	 * @return array{entetes:array, lignes:array, total:?array, num:array}
	 */
	public static function tableau( $vue ) {
		$ex = IGE_FFM_Contexte::exercice();
		$c  = IGE_FFM_Consolidation::calculer( (int) $ex['id'] );
		$t  = $c['total'];
		switch ( $vue ) {
			case 'categories':
				$l = array();
				foreach ( $c['categories'] as $k ) {
					$l[] = array( $k['libelle'], $k['budget'], $k['consomme'], $k['budget'] ? round( $k['consomme'] / $k['budget'] * 100, 1 ) : null, $t['consomme'] ? round( $k['consomme'] / $t['consomme'] * 100, 1 ) : 0 );
				}
				return array( 'entetes' => array( 'Catégorie', 'Budget', 'Consommé', 'Exécution (%)', 'Part (%)' ), 'lignes' => $l, 'total' => array( 'Total', $t['budget'], $t['consomme'], null === $t['taux'] ? null : round( $t['taux'], 1 ), 100 ), 'num' => array( 1, 2 ) );
			case 'rubriques':
				$l = array();
				foreach ( $c['rubriques'] as $r ) {
					$l[] = array( $r['rubrique']['code'] . ' ' . $r['rubrique']['libelle'], IGE_FFM_Referentiels::categorie_libelle( (int) ( $r['rubrique']['categorie_id'] ?? 0 ) ), $r['consomme'], $t['consomme'] ? round( $r['consomme'] / $t['consomme'] * 100, 1 ) : 0 );
				}
				return array( 'entetes' => array( 'Rubrique', 'Catégorie', 'Consommé', 'Part (%)' ), 'lignes' => $l, 'total' => array( 'Total', '', $t['consomme'], 100 ), 'num' => array( 2 ) );
			case 'mois':
				$noms = IGE_FFM_Ecran_Livre::mois_noms();
				$l    = array();
				$cum  = 0;
				foreach ( $c['mois'] as $m => $v ) {
					$cum += $v;
					$l[]  = array( $noms[ $m ], $v, $cum, $t['budget'] ? round( $cum / $t['budget'] * 100, 1 ) : null );
				}
				return array( 'entetes' => array( 'Mois', 'Dépenses validées', 'Cumul', 'Cumul / budget (%)' ), 'lignes' => $l, 'total' => null, 'num' => array( 1, 2 ) );
			default:
				$l = array();
				foreach ( $c['services'] as $s ) {
					$l[] = array( $s['service']['code'], $s['avec_budget'] ? $s['budget'] : null, $s['entrees'], $s['paye'], $s['engage'], $s['disponible'], null === $s['taux'] ? null : round( $s['taux'], 1 ), $s['caisse'], $s['a_verifier'], $s['dernier_lot'] ? wp_date( 'd/m/Y', strtotime( $s['dernier_lot'] ) ) : '' );
				}
				return array( 'entetes' => array( 'Service', 'Budget', 'Entrées', 'Payé', 'Engagé', 'Disponible', 'Exécution (%)', 'Caisse', 'À vérifier', 'Dernier lot' ), 'lignes' => $l, 'total' => array( 'Total (' . $t['nb_services'] . ' services)', $t['budget'], $t['entrees'], $t['paye'], $t['engage'], $t['disponible'], null === $t['taux'] ? null : round( $t['taux'], 1 ), $t['caisse'], $t['a_verifier'], '' ), 'num' => array( 1, 2, 3, 4, 5, 7, 8 ) );
		}
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! IGE_FFM_Contexte::exercice() ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$vue = self::vue();
		$tab = self::tableau( $vue );
		$fmt = static function ( $v, $i, $num ) {
			if ( null === $v ) {
				return '<span class="ige-muted">—</span>';
			}
			if ( in_array( $i, $num, true ) ) {
				return '<span' . ( $v < 0 ? ' style="color:var(--ige-rouge)"' : '' ) . '>' . esc_html( ige_ffm_montant( (int) $v, '' ) ) . '</span>';
			}
			if ( is_float( $v ) || ( is_numeric( $v ) && ! is_string( $v ) ) ) {
				return '<span' . ( $v > 100 ? ' style="color:var(--ige-rouge);font-weight:700"' : '' ) . '>' . esc_html( number_format( (float) $v, 1, ',', '' ) ) . '</span>';
			}
			return esc_html( (string) $v );
		};
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-tabs">
				<?php foreach ( self::vues() as $k => $lbl ) : ?>
					<a class="<?php echo $vue === $k ? 'ige-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'vue', $k, ige_ffm_url( 'consolidation' ) ) ); ?>"><?php echo esc_html( $lbl ); ?></a>
				<?php endforeach; ?>
			</div>
			<div class="ige-table-wrap"><table class="ige-table ige-num">
				<thead><tr>
					<?php foreach ( $tab['entetes'] as $i => $h ) : ?>
						<th<?php echo $i ? ' class="ige-r"' : ''; ?>><?php echo esc_html( $h ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php if ( $tab['total'] ) : ?>
					<tr class="ige-total"><?php foreach ( $tab['total'] as $i => $v ) : ?><td<?php echo $i ? ' class="ige-r"' : ''; ?>><?php echo $fmt( $v, $i, $tab['num'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?></tr>
				<?php endif; ?>
				<?php foreach ( $tab['lignes'] as $l ) : ?>
					<tr><?php foreach ( $l as $i => $v ) : ?><td<?php echo $i ? ' class="ige-r"' : ''; ?>><?php echo $fmt( $v, $i, $tab['num'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Seules les écritures validées par Administration et Finance sont consolidées (règle RG-14). La caisse est celle du livre de chaque service. Les montants sont en francs congolais.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Export CSV (Excel).
	 */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_conso_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_voir_tout' );
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			wp_die( 'Aucun exercice ouvert.' );
		}
		$vue = self::vue();
		$tab = self::tableau( $vue );
		IGE_FFM_Audit::log( 'export_consolidation', 'exercice', $ex['annee'], null, array( 'vue' => $vue ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="consolidation-' . $vue . '-' . $ex['annee'] . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, $tab['entetes'], ';' );
		$ligne = static fn( $l ) => array_map( static fn( $v ) => is_float( $v ) ? str_replace( '.', ',', (string) $v ) : ( is_int( $v ) ? (string) $v : IGE_FFM_Ecran_Livre::csv_texte( (string) $v ) ), $l );
		foreach ( $tab['lignes'] as $l ) {
			fputcsv( $out, $ligne( $l ), ';' );
		}
		if ( $tab['total'] ) {
			fputcsv( $out, $ligne( $tab['total'] ), ';' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
