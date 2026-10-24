<?php
/**
 * Registre des documents officiels (rapports, procès-verbaux) : référence unique, contenu figé,
 * empreinte SHA-256, vérification publique par code QR (VA-31).
 *
 * Le contenu d'un document est enregistré au moment de son émission et ne change plus :
 * un rapport émis reflète les chiffres du jour où il a été produit. La page publique de
 * vérification confirme l'authenticité (référence connue, empreinte intacte) sans afficher
 * de données financières.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Documents officiels.
 */
final class IGE_FFM_Documents {

	/**
	 * Types de documents.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'situation_mensuelle' => 'Situation mensuelle d’exécution',
			'etat_consolide'      => 'État consolidé de l’exercice',
			'avances'             => 'État des avances non justifiées',
			'rapport_annuel'      => 'Rapport annuel',
			'pv_arrete'           => 'Procès-verbal d’arrêté de caisse',
			'pv_inopine'          => 'Procès-verbal de comptage inopiné',
			'ordre_mission'       => 'Ordre de mission',
			'etat_rapprochement'  => 'État de rapprochement bancaire',
		);
	}

	/**
	 * Empreinte d'un document.
	 *
	 * @param array $d Document (reference, type, titre, periode, emis_le, contenu).
	 * @return string
	 */
	public static function empreinte( array $d ) {
		return hash( 'sha256', implode( '|', array( $d['reference'], $d['type'], $d['titre'], $d['periode'], $d['emis_le'], $d['contenu'] ) ) );
	}

	/**
	 * Émet un document.
	 *
	 * @param array    $d       type, titre, periode, contenu (HTML), signataire, exercice_id, service_id, objet_id.
	 * @param int|null $user_id Émetteur.
	 * @return int|WP_Error
	 */
	public static function emettre( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$t       = IGE_FFM_DB::t( 'document' );
		$annee   = (int) wp_date( 'Y' );
		$prefixe = in_array( $d['type'], array( 'pv_arrete', 'pv_inopine' ), true ) ? 'IGE-PV' : ( 'ordre_mission' === $d['type'] ? 'IGE-OM' : 'IGE-AF' );
		$now     = ige_ffm_now();
		for ( $essai = 0; $essai < 5; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n   = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE reference LIKE %s", $wpdb->esc_like( $prefixe . '-' . $annee . '-' ) . '%' ) ) + $essai;
			$row = array(
				'reference'   => sprintf( '%s-%d-%04d', $prefixe, $annee, $n ),
				'type'        => $d['type'],
				'titre'       => mb_substr( (string) $d['titre'], 0, 255 ),
				'periode'     => mb_substr( (string) ( $d['periode'] ?? '' ), 0, 60 ),
				'exercice_id' => isset( $d['exercice_id'] ) ? (int) $d['exercice_id'] : null,
				'service_id'  => ! empty( $d['service_id'] ) ? (int) $d['service_id'] : null,
				'objet_id'    => ! empty( $d['objet_id'] ) ? (int) $d['objet_id'] : null,
				'signataire'  => mb_substr( (string) ( $d['signataire'] ?? '' ), 0, 120 ),
				'contenu'     => (string) $d['contenu'],
				'emis_par'    => (int) $user_id,
				'emis_le'     => $now,
			);
			$row['empreinte'] = self::empreinte( $row );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$id = (int) $wpdb->insert_id;
				IGE_FFM_Audit::log( 'emission_document', 'document', $row['reference'], null, array( 'type' => $row['type'], 'titre' => $row['titre'], 'empreinte' => $row['empreinte'] ), $user_id );
				return $id;
			}
		}
		return new WP_Error( 'document', 'Émission impossible pour le moment. Réessayez.' );
	}

	/**
	 * Un document par identifiant.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'document' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Document lié à un objet (procès-verbal d'un arrêté).
	 *
	 * @param string $type     Type.
	 * @param int    $objet_id Objet.
	 * @return array|null
	 */
	public static function pour_objet( $type, $objet_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'document' ) . ' WHERE type = %s AND objet_id = %d ORDER BY id DESC LIMIT 1', $type, $objet_id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Registre.
	 *
	 * @param int $limit Nombre.
	 * @return array[]
	 */
	public static function liste( $limit = 100 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, reference, type, titre, periode, service_id, signataire, emis_par, emis_le, nb_verifications FROM ' . IGE_FFM_DB::t( 'document' ) . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
	}

	/**
	 * Vérification publique d'une référence.
	 *
	 * @param string $reference Référence saisie ou lue dans le code QR.
	 * @param bool   $compter   Incrémenter le compteur de vérifications.
	 * @return array{statut:string, document:?array}
	 *         statut : authentique | altere | inconnu.
	 */
	public static function verifier( $reference, $compter = true ) {
		global $wpdb;
		$ref = strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $reference ) );
		if ( '' === $ref ) {
			return array( 'statut' => 'inconnu', 'document' => null );
		}
		$t = IGE_FFM_DB::t( 'document' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$d = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE reference = %s", $ref ), ARRAY_A );
		if ( ! $d ) {
			return array( 'statut' => 'inconnu', 'document' => null );
		}
		if ( $compter ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET nb_verifications = nb_verifications + 1 WHERE id = %d", $d['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array(
			'statut'   => hash_equals( $d['empreinte'], self::empreinte( $d ) ) ? 'authentique' : 'altere',
			'document' => $d,
		);
	}

	/**
	 * Adresse publique de vérification d'un document (contenu du code QR).
	 *
	 * @param string $reference Référence.
	 * @return string
	 */
	public static function url_verification( $reference ) {
		return ige_ffm_url( 'verifier-un-document', array( 'ref' => $reference ) );
	}

	/**
	 * Mise en page officielle d'un document (en-tête, titre, contenu, signatures, pied avec code QR).
	 *
	 * @param array $d Document.
	 * @return string
	 */
	public static function rendu( array $d ) {
		$emetteur = get_userdata( (int) $d['emis_par'] );
		ob_start();
		?>
		<div class="ige-doc-wrap">
			<article class="ige-pdf">
				<div class="ige-hd">
					<?php echo function_exists( 'ige_ff_logo' ) ? ige_ff_logo( 'couleur', true, false ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-ref"><?php echo esc_html( $d['reference'] ); ?><br>Kinshasa, le <?php echo esc_html( ige_ff_date_fr_safe_ts( strtotime( $d['emis_le'] ) ) ); ?></div>
				</div>
				<h1 class="ige-doc-title"><?php echo esc_html( $d['titre'] ); ?></h1>
				<?php echo wp_kses_post( $d['contenu'] ); ?>
				<?php if ( $d['signataire'] ) : ?>
					<div class="ige-sign"><div><?php echo esc_html( $d['signataire'] ); ?><br><br><br>Signature et sceau</div><div>Établi par <?php echo esc_html( $emetteur ? $emetteur->display_name : '' ); ?><br>le <?php echo esc_html( wp_date( 'd/m/Y à H:i', strtotime( $d['emis_le'] ) ) ); ?></div></div>
				<?php endif; ?>
				<div class="ige-ft">
					<?php echo function_exists( 'ige_ff_tri' ) ? ige_ff_tri() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-qr" data-ige-qr="<?php echo esc_attr( self::url_verification( $d['reference'] ) ); ?>" aria-label="Code QR de vérification"></div>
					<div>Document authentifiable : scannez le code ou saisissez la référence <b><?php echo esc_html( $d['reference'] ); ?></b> sur la page « Vérifier un document » de l’application.<br>Empreinte : <?php echo esc_html( substr( $d['empreinte'], 0, 4 ) . '…' . substr( $d['empreinte'], -4 ) ); ?></div>
				</div>
			</article>
		</div>
		<?php
		return ob_get_clean();
	}
}
