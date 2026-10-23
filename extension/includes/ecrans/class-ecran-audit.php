<?php
/**
 * Écran Administration > Journal d'audit.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran du journal d'audit.
 */
final class IGE_FFM_Ecran_Audit {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_audit_verifier', array( __CLASS__, 'handle_verifier' ) );
		add_action( 'admin_post_ige_ffm_audit_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Journal d’audit';
		$args['subtitle'] = 'Toutes les actions sensibles · non modifiable, y compris par l’Admin · chaînage d’empreintes';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Filtres lus dans l'adresse.
	 *
	 * @param array $src Source (GET ou POST).
	 * @return array
	 */
	private static function filtres( array $src ) {
		$date = static fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $v ) ? (string) $v : '';
		return array(
			'du'      => $date( $src['du'] ?? '' ),
			'au'      => $date( $src['au'] ?? '' ),
			'user_id' => (int) ( $src['user_id'] ?? 0 ),
			'action'  => isset( IGE_FFM_Audit::actions()[ $src['action_audit'] ?? '' ] ) ? $src['action_audit'] : '',
			'page'    => max( 1, (int) ( $src['pg'] ?? 1 ) ),
		);
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$f       = self::filtres( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per     = 50;
		$res     = IGE_FFM_Audit::search( $f + array( 'par_page' => $per ) );
		$pages   = max( 1, (int) ceil( $res['total'] / $per ) );
		$actions = IGE_FFM_Audit::actions();
		$verif   = get_option( 'ige_ffm_audit_verification' );
		$base    = get_permalink();

		$users = array( 0 => 'Tous' );
		foreach ( get_users( array( 'fields' => array( 'ID', 'user_login' ), 'orderby' => 'login' ) ) as $u ) {
			$users[ $u->ID ] = $u->user_login;
		}

		ob_start();
		if ( $verif ) {
			echo $verif['ok'] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				? ige_ffm_banner( 'ok', 'Journal intègre', ' ' . esc_html( sprintf( '%d lignes vérifiées le %s : aucune modification ni suppression détectée.', $verif['lignes'], wp_date( 'd/m/Y à H:i', $verif['le'] ) ) ) )
				: ige_ffm_banner( 'ko', 'Rupture de la chaîne détectée', ' ' . esc_html( sprintf( 'La ligne n° %d a été modifiée ou une ligne précédente supprimée directement dans la base (vérification du %s). Prévenez l’auditeur interne.', $verif['rupture'], wp_date( 'd/m/Y à H:i', $verif['le'] ) ) ) );
		}
		?>
		<section class="ige-card" style="padding:16px">
			<form method="get" action="<?php echo esc_url( $base ); ?>" class="ige-toolbar" style="align-items:flex-end">
				<div class="ige-field" style="margin:0"><label for="ige-du">Du</label><input type="date" id="ige-du" name="du" value="<?php echo esc_attr( $f['du'] ); ?>"></div>
				<div class="ige-field" style="margin:0"><label for="ige-au">Au</label><input type="date" id="ige-au" name="au" value="<?php echo esc_attr( $f['au'] ); ?>"></div>
				<div class="ige-field" style="margin:0"><label for="ige-user">Utilisateur</label><select id="ige-user" name="user_id">
					<?php foreach ( $users as $id => $l ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $f['user_id'], $id ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></div>
				<div class="ige-field" style="margin:0"><label for="ige-action">Action</label><select id="ige-action" name="action_audit">
					<option value="">Toutes</option>
					<?php foreach ( $actions as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f['action'], $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></div>
				<button type="submit" class="ige-btn ige-btn-bleu">Filtrer</button>
				<span class="ige-spacer"></span>
			</form>
			<div class="ige-row" style="margin-bottom:12px">
				<span class="ige-small ige-muted"><?php echo esc_html( sprintf( '%d ligne(s)', $res['total'] ) ); ?></span>
				<span class="ige-spacer"></span>
				<?php
				// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
				echo IGE_FFM_Ecrans::action_button( 'audit_verifier', array(), 'Vérifier l’intégrité', 'ige-btn ige-btn-line' );
				echo IGE_FFM_Ecrans::action_button( 'audit_export', array( 'du' => $f['du'], 'au' => $f['au'], 'user_id' => $f['user_id'], 'action_audit' => $f['action'] ), 'Exporter (Excel)', 'ige-btn ige-btn-line' );
				// phpcs:enable
				?>
			</div>
			<div class="ige-table-wrap">
				<table class="ige-table ige-num">
					<thead><tr><th>N°</th><th>Date et heure</th><th>Utilisateur</th><th>Action</th><th>Objet</th><th>Changement</th><th>Adresse IP</th></tr></thead>
					<tbody>
					<?php foreach ( $res['rows'] as $r ) : ?>
						<?php $sensible = in_array( $r['action'], array( 'echec_connexion', 'verrouillage', 'desactivation_compte', 'modification_reglages' ), true ); ?>
						<tr<?php echo $sensible ? ' class="ige-rejet"' : ''; ?>>
							<td><?php echo esc_html( $r['id'] ); ?></td>
							<td style="white-space:nowrap"><?php echo esc_html( wp_date( 'd/m/Y H:i:s', strtotime( $r['created_at'] ) ) ); ?></td>
							<td><?php echo esc_html( $r['user_login'] ?: 'inconnu' ); ?></td>
							<td><?php echo esc_html( $actions[ $r['action'] ] ?? $r['action'] ); ?></td>
							<td><?php echo esc_html( trim( $r['objet'] . ' ' . $r['objet_id'] ) ); ?></td>
							<td class="ige-small"><?php echo esc_html( IGE_FFM_Audit::resume( $r ) ); ?></td>
							<td class="ige-small ige-muted"><?php echo esc_html( $r['ip'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $res['rows'] ) : ?>
						<tr><td colspan="7" class="ige-empty">Aucune action pour ces critères.</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
			<?php if ( $pages > 1 ) : ?>
				<div class="ige-row" style="margin-top:12px">
					<?php
					$q = array_filter( array( 'du' => $f['du'], 'au' => $f['au'], 'user_id' => $f['user_id'], 'action_audit' => $f['action'] ) );
					if ( $f['page'] > 1 ) {
						echo '<a class="ige-btn ige-btn-line" href="' . esc_url( add_query_arg( $q + array( 'pg' => $f['page'] - 1 ), $base ) ) . '">← Plus récentes</a>';
					}
					echo '<span class="ige-small ige-muted">' . esc_html( sprintf( 'Page %d sur %d', $f['page'], $pages ) ) . '</span>';
					if ( $f['page'] < $pages ) {
						echo '<a class="ige-btn ige-btn-line" href="' . esc_url( add_query_arg( $q + array( 'pg' => $f['page'] + 1 ), $base ) ) . '">Plus anciennes →</a>';
					}
					?>
				</div>
			<?php endif; ?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Vérification d'intégrité à la demande.
	 */
	public static function handle_verifier() {
		ige_ffm_check_form( 'audit_verifier' );
		ige_ffm_require( 'ige_voir_audit' );
		$r       = IGE_FFM_Audit::verify();
		$r['le'] = time();
		update_option( 'ige_ffm_audit_verification', $r, false );
		wp_safe_redirect( ige_ffm_url( 'journal-audit' ) );
		exit;
	}

	/**
	 * Export Excel (.xlsx) du journal filtré.
	 */
	public static function handle_export() {
		ige_ffm_check_form( 'audit_export' );
		ige_ffm_require( 'ige_voir_audit' );
		$f       = self::filtres( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$actions = IGE_FFM_Audit::actions();
		IGE_FFM_Audit::log( 'export_audit', 'audit', '', null, array_filter( $f ) );
		$lignes = array();
		$page   = 1;
		do {
			$res = IGE_FFM_Audit::search( array_merge( $f, array( 'page' => $page, 'par_page' => 1000 ) ) );
			foreach ( $res['rows'] as $r ) {
				$lignes[] = array( (int) $r['id'], $r['created_at'], $r['user_login'], $actions[ $r['action'] ] ?? $r['action'], $r['objet'], $r['objet_id'], $r['avant'], $r['apres'], $r['ip'], $r['empreinte'] );
			}
			++$page;
		} while ( count( $res['rows'] ) === 1000 && $page <= 100 );
		$per = array_filter( array( $f['du'] ? 'du ' . wp_date( 'd/m/Y', strtotime( $f['du'] ) ) : '', $f['au'] ? 'au ' . wp_date( 'd/m/Y', strtotime( $f['au'] ) ) : '' ) );
		$t   = array(
			'titre'      => 'Journal d’audit',
			'sous_titre' => ( $per ? 'Période ' . implode( ' ', $per ) . ' · ' : '' ) . count( $lignes ) . ' ligne(s) · ' . IGE_FFM_Classeur::extrait_par(),
			'colonnes'   => array(
				array( 'N°', 'nombre', 8 ),
				array( 'Date et heure', 'dateheure' ),
				array( 'Utilisateur', 'texte', 14 ),
				array( 'Action', 'texte', 28 ),
				array( 'Objet', 'texte', 12 ),
				array( 'Identifiant', 'texte', 18 ),
				array( 'Avant', 'long', 36 ),
				array( 'Après', 'long', 36 ),
				array( 'Adresse IP', 'texte', 14 ),
				array( 'Empreinte', 'texte', 20 ),
			),
			'lignes'     => $lignes,
			'notes'      => array( 'Chaque ligne est chaînée à la précédente par son empreinte SHA-256 : utilisez « Vérifier l’intégrité » dans l’application pour contrôler la chaîne.' ),
		);
		( new IGE_FFM_Classeur( 'Journal d’audit' ) )->feuille( 'Journal d’audit', $t )->telecharger( 'journal-audit-' . wp_date( 'Y-m-d' ) . '.xlsx' );
	}
}
