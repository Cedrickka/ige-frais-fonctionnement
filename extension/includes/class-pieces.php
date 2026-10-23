<?php
/**
 * Pièces justificatives (photos, PDF).
 *
 * Stockage dans wp-content/uploads/ige-pieces/, dossier interdit d'accès direct (.htaccess,
 * index.php) ; noms de fichiers aléatoires ; téléchargement uniquement par l'application,
 * après contrôle des droits sur le service concerné. Les photos trop grandes sont réduites
 * (2 000 px) pour économiser l'espace et la bande passante.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pièces jointes.
 */
final class IGE_FFM_Pieces {

	const TAILLE_MAX = 5242880; // 5 Mo.

	/**
	 * Types acceptés : extension => type MIME.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		);
	}

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'admin_post_ige_ffm_piece', array( __CLASS__, 'telecharger' ) );
	}

	/**
	 * Dossier de stockage protégé (créé si besoin).
	 *
	 * @return string Chemin absolu, avec / final.
	 */
	public static function dossier() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'ige-pieces/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '.htaccess', "# Accès direct interdit : les pièces sont servies par l'application.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php // Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Vérifie un fichier envoyé (structure $_FILES).
	 *
	 * @param array|null $file Fichier.
	 * @return true|WP_Error
	 */
	public static function valider( $file ) {
		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_NO_FILE === (int) $file['error'] ) {
			return new WP_Error( 'piece', 'Joignez la pièce justificative (photo ou PDF).' );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'piece', in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ? 'La pièce dépasse la taille autorisée (5 Mo).' : "L'envoi de la pièce a échoué. Réessayez." );
		}
		if ( (int) $file['size'] > self::TAILLE_MAX ) {
			return new WP_Error( 'piece', 'La pièce dépasse 5 Mo. Reprenez la photo en qualité normale.' );
		}
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::types() );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::types(), true ) ) {
			return new WP_Error( 'piece', 'Format refusé : envoyez une photo (JPG, PNG, WEBP) ou un PDF.' );
		}
		return true;
	}

	/**
	 * Enregistre une pièce validée et la rattache à un objet (écriture, avance…).
	 *
	 * @param array  $file     Fichier ($_FILES).
	 * @param string $objet    Type d'objet.
	 * @param int    $objet_id Identifiant.
	 * @param int    $user_id  Auteur.
	 * @return int|WP_Error Identifiant de la pièce.
	 */
	public static function enregistrer( array $file, $objet, $objet_id, $user_id ) {
		global $wpdb;
		$ok = self::valider( $file );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$check  = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::types() );
		$ext    = 'image/jpeg' === $check['type'] ? 'jpg' : ( $check['ext'] ?: 'bin' );
		$sous   = wp_date( 'Y' ) . '/';
		$dir    = self::dossier() . $sous;
		wp_mkdir_p( $dir );
		$nom    = wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest   = $dir . $nom;
		$moved  = is_uploaded_file( $file['tmp_name'] ) ? move_uploaded_file( $file['tmp_name'], $dest ) : ( defined( 'IGE_FFM_TESTS' ) && IGE_FFM_TESTS && copy( $file['tmp_name'], $dest ) );
		if ( ! $moved ) {
			return new WP_Error( 'piece', "Impossible d'enregistrer la pièce sur le serveur." );
		}
		if ( 0 === strpos( $check['type'], 'image/' ) ) {
			$editor = wp_get_image_editor( $dest );
			if ( ! is_wp_error( $editor ) ) {
				$size = $editor->get_size();
				if ( $size['width'] > 2000 || $size['height'] > 2000 ) {
					$editor->resize( 2000, 2000, false );
					$editor->set_quality( 80 );
					$editor->save( $dest );
				}
			}
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'piece' ),
			array(
				'objet'         => $objet,
				'objet_id'      => (int) $objet_id,
				'fichier'       => $sous . $nom,
				'nom_original'  => substr( sanitize_file_name( $file['name'] ), 0, 190 ),
				'type_mime'     => $check['type'],
				'taille'        => (int) filesize( $dest ),
				'empreinte'     => hash_file( 'sha256', $dest ),
				'televerse_par' => (int) $user_id,
				'televerse_le'  => ige_ffm_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'ajout_piece', $objet, $objet_id, null, array( 'piece' => $id, 'fichier' => sanitize_file_name( $file['name'] ) ), $user_id );
		return $id;
	}

	/**
	 * Pièces d'un objet.
	 *
	 * @param string $objet    Type.
	 * @param int    $objet_id Identifiant.
	 * @return array[]
	 */
	public static function liste( $objet, $objet_id ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'piece' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE objet = %s AND objet_id = %d ORDER BY id", $objet, $objet_id ), ARRAY_A );
	}

	/**
	 * Nombre de pièces par écriture, pour une liste d'écritures.
	 *
	 * @param int[] $ids Écritures.
	 * @return array<int,int>
	 */
	public static function compter_ecritures( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$t   = IGE_FFM_DB::t( 'piece' );
		$in  = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT objet_id, COUNT(*) AS n FROM $t WHERE objet = 'ecriture' AND objet_id IN ($in) GROUP BY objet_id", ARRAY_A );
		return wp_list_pluck( $rows, 'n', 'objet_id' );
	}

	/**
	 * Lien de téléchargement protégé.
	 *
	 * @param array $p Pièce.
	 * @return string
	 */
	public static function url( array $p ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_piece&id=' . (int) $p['id'] ), 'ige_ffm_piece_' . (int) $p['id'] );
	}

	/**
	 * Sert une pièce après contrôle des droits.
	 */
	public static function telecharger() {
		global $wpdb;
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		if ( ! $id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_piece_' . $id ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		$t = IGE_FFM_DB::t( 'piece' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		if ( ! $p ) {
			wp_die( 'Pièce introuvable.', '', array( 'response' => 404 ) );
		}
		$service = 0;
		if ( 'ecriture' === $p['objet'] ) {
			$e       = IGE_FFM_Ecritures::get( (int) $p['objet_id'] );
			$service = $e ? (int) $e['service_id'] : 0;
		}
		if ( ! $service || ! IGE_FFM_Contexte::peut_voir( $service ) ) {
			wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
		}
		$path = self::dossier() . $p['fichier'];
		if ( ! is_readable( $path ) ) {
			wp_die( 'Fichier manquant sur le serveur.', '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: ' . $p['type_mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $p['nom_original'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
