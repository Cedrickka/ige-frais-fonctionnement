<?php
/**
 * Écran Rapports officiels (génération, registre, consultation) et page publique
 * « Vérifier un document ».
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rapports.
 */
final class IGE_FFM_Ecran_Rapports {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_rapport', array( __CLASS__, 'handle_emettre' ) );
		add_action(
			'template_redirect',
			static function () {
				if ( is_page( 'modele-de-rapport' ) && is_user_logged_in() ) {
					wp_safe_redirect( ige_ffm_url( 'rapports' ) );
					exit;
				}
			}
		);
	}

	/**
	 * Document affiché (?doc=).
	 *
	 * @return array|null
	 */
	private static function doc() {
		$id = isset( $_GET['doc'] ) ? (int) $_GET['doc'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $id ? IGE_FFM_Documents::get( $id ) : null;
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$d = self::doc();
		if ( $d ) {
			$args['title']    = $d['titre'];
			$args['subtitle'] = 'Réf. ' . $d['reference'] . ' · ' . $d['periode'];
			$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( ige_ffm_url( 'rapports' ) ) . '">Registre</a><button type="button" class="ige-btn ige-btn-jaune" onclick="window.print()">Imprimer ou enregistrer en PDF</button>';
			return $args;
		}
		$args['title']    = 'Rapports officiels';
		$args['subtitle'] = 'Produits au format de la charte, avec code QR d’authenticité';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$d = self::doc();
		if ( $d ) {
			if ( in_array( $d['type'], array( 'pv_arrete', 'pv_inopine' ), true ) ) {
				$url = add_query_arg( array( 'service' => $d['service_id'], 'pv' => $d['objet_id'] ), ige_ffm_url( 'arrete-de-caisse' ) );
				return ige_ffm_banner( 'info', 'Procès-verbal', ' <a href="' . esc_url( $url ) . '">Ouvrir le procès-verbal ' . esc_html( $d['reference'] ) . '</a>' );
			}
			$v = IGE_FFM_Documents::verifier( $d['reference'], false );
			return ( 'authentique' === $v['statut'] ? '' : ige_ffm_banner( 'ko', 'Document altéré', ' Son contenu ne correspond plus à l’empreinte enregistrée à l’émission.' ) ) . IGE_FFM_Documents::rendu( $d );
		}
		$noms  = IGE_FFM_Ecran_Livre::mois_noms();
		$ex    = IGE_FFM_Contexte::exercice();
		$types = array_slice( IGE_FFM_Documents::types(), 0, 4, true );
		$type  = isset( $_GET['type'] ) && isset( $types[ sanitize_key( $_GET['type'] ) ] ) ? sanitize_key( $_GET['type'] ) : 'situation_mensuelle'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$moism = $ex ? ( IGE_FFM_Contexte::aujourdhui() > $ex['date_fin'] ? 12 : (int) wp_date( 'n' ) ) : 12;
		$docs  = IGE_FFM_Documents::liste( 100 );
		$svcs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'service' ), 'code', 'id' );
		ob_start();
		?>
		<div class="ige-grid ige-cols-12 ige-start">
			<?php if ( current_user_can( 'ige_administrer' ) && $ex ) : ?>
				<section class="ige-card">
					<h2>Générer un rapport</h2>
					<?php echo IGE_FFM_Ecrans::form_open( 'rapport', 'data-ige-confirm="Émettre ce rapport ? Il recevra une référence définitive et ses chiffres seront figés."' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo IGE_FFM_Ecrans::field( 'type', 'Type de rapport', $type, array( 'type' => 'select', 'options' => $types, 'attrs' => 'onchange="document.getElementById(\'ige-bloc-mois\').hidden=this.value!==\'situation_mensuelle\'"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div id="ige-bloc-mois"<?php echo 'situation_mensuelle' === $type ? '' : ' hidden'; ?>>
							<?php
							$opts = array();
							for ( $m = $moism; $m >= 1; $m-- ) {
								$opts[ $m ] = $noms[ $m ] . ' ' . $ex['annee'];
							}
							echo IGE_FFM_Ecrans::field( 'mois', 'Mois', (string) max( 1, $moism - 1 ), array( 'type' => 'select', 'options' => $opts ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</div>
						<?php echo IGE_FFM_Ecrans::field( 'signataire', 'Signataire', '', array( 'type' => 'select', 'options' => array_combine( IGE_FFM_Rapports::signataires(), IGE_FFM_Rapports::signataires() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p class="ige-small ige-muted">Les chiffres sont ceux des écritures validées au moment de l’émission ; ils ne changent plus ensuite. Pour une version à jour, émettez un nouveau rapport.</p>
						<button type="submit" class="ige-btn ige-btn-jaune ige-btn-block" style="padding:11px">Émettre le rapport</button>
					</form>
				</section>
			<?php endif; ?>
			<section class="ige-card" style="padding:16px">
				<h2>Documents émis <span class="ige-sub">registre non modifiable</span></h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Référence</th><th>Document</th><th>Période</th><th>Émis le</th><th>Par</th><th class="ige-r">Vérifications QR</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $docs as $doc ) : ?>
						<?php $u = get_userdata( (int) $doc['emis_par'] ); ?>
						<tr>
							<td><span class="ige-code"><?php echo esc_html( $doc['reference'] ); ?></span></td>
							<td><?php echo esc_html( $doc['titre'] ); ?></td>
							<td><?php echo esc_html( $doc['periode'] ); ?></td>
							<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $doc['emis_le'] ) ) ); ?></td>
							<td><?php echo esc_html( $u ? $u->user_login : '' ); ?></td>
							<td class="ige-r"><?php echo esc_html( $doc['nb_verifications'] ); ?></td>
							<td><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'doc', $doc['id'], ige_ffm_url( 'rapports' ) ) ); ?>">Ouvrir</a></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $docs ) : ?>
						<tr><td colspan="7" class="ige-empty">Aucun document émis pour le moment.</td></tr>
					<?php endif; ?>
					</tbody>
				</table></div>
				<?php echo ige_ffm_banner( 'info', 'Authenticité', ' Chaque document porte une référence unique et un code QR. Toute personne qui scanne le code voit si le document est authentique et non modifié, sans accès aux données financières. Les procès-verbaux d’arrêté de caisse sont enregistrés automatiquement à leur signature finale.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php unset( $svcs ); ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Émission d'un rapport.
	 */
	public static function handle_emettre() {
		ige_ffm_check_form( 'rapport' );
		ige_ffm_require( 'ige_administrer' );
		$res = IGE_FFM_Rapports::emettre( ige_ffm_post( 'type' ), array( 'mois' => (int) ige_ffm_post( 'mois' ), 'signataire' => ige_ffm_post( 'signataire' ) ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Rapport non émis', $res->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'rapports' ) );
			exit;
		}
		$d = IGE_FFM_Documents::get( $res );
		ige_ffm_flash( 'ok', 'Rapport ' . $d['reference'] . ' émis', 'Imprimez-le ou enregistrez-le en PDF ; le code QR permet d’en vérifier l’authenticité.' );
		wp_safe_redirect( ige_ffm_url( 'rapports', array( 'doc' => $res ) ) );
		exit;
	}
}

/**
 * Page publique « Vérifier un document ».
 */
final class IGE_FFM_Ecran_Verifier {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action(
			'template_redirect',
			static function () {
				if ( is_page( 'verifier-un-document' ) ) {
					add_action( 'ige_ff_public_content', array( __CLASS__, 'render_public' ) );
				}
			}
		);
	}

	/**
	 * Limite de 30 vérifications par minute et par adresse.
	 *
	 * @return bool
	 */
	private static function limite_atteinte() {
		$k = 'ige_ffm_verif_' . md5( ige_ffm_ip() );
		$n = (int) get_transient( $k );
		set_transient( $k, $n + 1, MINUTE_IN_SECONDS );
		return $n >= 30;
	}

	/**
	 * Contenu public (remplace le contenu de démonstration de la page).
	 */
	public static function render_public() {
		$ref = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h1>Vérification d’un document officiel</h1><p class="ige-muted" style="margin:0">Page ouverte en scannant le code QR d’un rapport ou d’un procès-verbal, ou en saisissant sa référence.</p>';
		if ( '' !== $ref ) {
			if ( self::limite_atteinte() ) {
				echo ige_ffm_banner( 'warn', 'Trop de vérifications', ' Réessayez dans une minute.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				$v = IGE_FFM_Documents::verifier( $ref );
				$d = $v['document'];
				if ( 'authentique' === $v['statut'] ) {
					$types = IGE_FFM_Documents::types();
					echo '<div class="ige-seal"><div class="ige-ok">✓</div><div><b style="font-size:16px">Document authentique</b><br><span class="ige-small">Ce document a bien été émis par l’application de l’Inspection Générale de l’Enseignement et n’a pas été modifié depuis.</span></div></div>';
					echo '<dl class="ige-kv">';
					echo '<dt>Référence</dt><dd>' . esc_html( $d['reference'] ) . '</dd>';
					echo '<dt>Document</dt><dd>' . esc_html( ( $types[ $d['type'] ] ?? $d['type'] ) . ( $d['periode'] ? ', ' . $d['periode'] : '' ) ) . '</dd>';
					echo '<dt>Émis le</dt><dd>' . esc_html( wp_date( 'd/m/Y à H \h i', strtotime( $d['emis_le'] ) ) ) . '</dd>';
					echo '<dt>Émetteur</dt><dd>' . esc_html( in_array( $d['type'], array( 'pv_arrete', 'pv_inopine' ), true ) ? 'Service ' . IGE_FFM_Comptes::service_code( (int) $d['service_id'] ) : 'Administration et Finance' ) . '</dd>';
					echo '<dt>Empreinte</dt><dd><span class="ige-code">' . esc_html( substr( $d['empreinte'], 0, 4 ) . '…' . substr( $d['empreinte'], -4 ) ) . '</span></dd>';
					echo '</dl>';
				} elseif ( 'altere' === $v['statut'] ) {
					echo '<div class="ige-seal ige-ko"><div class="ige-ok">!</div><div><b style="font-size:16px">Document altéré</b><br><span class="ige-small">La référence existe, mais le contenu enregistré ne correspond plus à son empreinte. Ne vous fiez pas à ce document et prévenez l’Inspection Générale.</span></div></div>';
				} else {
					echo '<div class="ige-seal ige-ko"><div class="ige-ok">✕</div><div><b style="font-size:16px">Référence inconnue</b><br><span class="ige-small">Aucun document de l’application ne porte la référence « ' . esc_html( $ref ) . ' ». Vérifiez la saisie ; un document dont la référence est inconnue n’est pas authentique.</span></div></div>';
				}
			}
		}
		echo ige_ffm_banner( 'info', '', 'Pour un contrôle complet, comparez le document papier avec la copie officielle, accessible aux agents habilités après connexion. Aucune donnée financière n’est affichée publiquement.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<form method="get" action="' . esc_url( ige_ffm_url( 'verifier-un-document' ) ) . '" class="ige-field" style="margin-top:24px"><label for="ige-ref">' . ( $ref ? 'Vérifier une autre référence' : 'Référence du document' ) . '</label><div class="ige-row"><input id="ige-ref" name="ref" style="flex:1" value="' . esc_attr( $ref ? '' : 'IGE-' ) . '" placeholder="IGE-AF-2026-0001" autocomplete="off"><button class="ige-btn ige-btn-bleu" type="submit">Vérifier</button></div></form>';
	}
}
