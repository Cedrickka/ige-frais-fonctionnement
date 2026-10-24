<?php
/**
 * Saisie hors connexion (VA-50) : application web installable sur le téléphone.
 *
 * - Un service worker garde en mémoire la page « Nouvelle écriture » et les fichiers de l'interface ;
 *   sans réseau, la page s'ouvre quand même.
 * - Sans réseau, l'écriture (avec la photo de la pièce) est enregistrée sur le téléphone
 *   (IndexedDB), puis envoyée automatiquement au retour du réseau.
 * - L'envoi passe par l'API REST de WordPress (jeton de session) et applique exactement les mêmes
 *   contrôles que la saisie en ligne ; une clé unique par écriture évite tout doublon si l'envoi
 *   est répété.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hors connexion.
 */
final class IGE_FFM_Hors_Connexion {

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'servir' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ), 30 );
		add_action( 'wp_head', array( __CLASS__, 'manifeste' ) );
		add_action( 'wp_footer', array( __CLASS__, 'nettoyage_deconnecte' ) );
		add_action( 'wp_ajax_ige_ffm_jeton', array( __CLASS__, 'jeton' ) );
	}

	/**
	 * Jeton REST frais pour l'envoi différé (la page gardée en mémoire peut être ancienne).
	 */
	public static function jeton() {
		if ( ! current_user_can( 'ige_saisir' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Service worker et manifeste, servis depuis la racine du site (portée « / »).
	 */
	public static function servir() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ige-ffm-sw'] ) ) {
			nocache_headers();
			header( 'Content-Type: application/javascript; charset=utf-8' );
			header( 'Service-Worker-Allowed: /' );
			header( 'X-Content-Type-Options: nosniff' );
			$pages = wp_json_encode( array( wp_parse_url( ige_ffm_url( 'nouvelle-ecriture' ), PHP_URL_PATH ), wp_parse_url( ige_ffm_url( 'hors-connexion' ), PHP_URL_PATH ) ) );
			echo 'const IGE_PAGES=' . $pages . ";const IGE_VERSION='" . esc_js( IGE_FFM_VERSION ) . "';\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			readfile( IGE_FFM_DIR . 'assets/ige-ffm-sw.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}
		if ( isset( $_GET['ige-ffm-manifest'] ) ) {
			nocache_headers();
			header( 'Content-Type: application/manifest+json; charset=utf-8' );
			echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'name'             => 'IGE · Frais de fonctionnement',
					'short_name'       => 'IGE Frais',
					'lang'             => 'fr',
					'start_url'        => wp_parse_url( ige_ffm_url( 'nouvelle-ecriture' ), PHP_URL_PATH ),
					'scope'            => '/',
					'display'          => 'standalone',
					'background_color' => '#ffffff',
					'theme_color'      => '#17418a',
					'icons'            => array(
						array( 'src' => IGE_FFM_URL . 'assets/img/armoirie-128.png', 'sizes' => '128x128', 'type' => 'image/png' ),
						array( 'src' => IGE_FFM_URL . 'assets/img/armoirie-192.png', 'sizes' => '192x192', 'type' => 'image/png' ),
						array( 'src' => IGE_FFM_URL . 'assets/img/armoirie-512.png', 'sizes' => '512x512', 'type' => 'image/png' ),
					),
				)
			);
			exit;
		}
		// phpcs:enable
	}

	/**
	 * Lien vers le manifeste (application installable) pour les comptes de service.
	 */
	public static function manifeste() {
		if ( is_user_logged_in() && current_user_can( 'ige_saisir' ) ) {
			echo '<link rel="manifest" href="' . esc_url( home_url( '/?ige-ffm-manifest=1' ) ) . '">' . "\n" . '<meta name="theme-color" content="#17418a">' . "\n";
		}
	}

	/**
	 * Scripts : enregistrement du service worker, file d'attente, envoi.
	 */
	public static function scripts() {
		if ( ! is_user_logged_in() || ! current_user_can( 'ige_saisir' ) || ! IGE_FFM_Contexte::service_utilisateur() ) {
			return;
		}
		wp_enqueue_script( 'ige-ffm-hc', IGE_FFM_URL . 'assets/ige-ffm-hc.js', array(), (string) filemtime( IGE_FFM_DIR . 'assets/ige-ffm-hc.js' ), true );
		wp_localize_script(
			'ige-ffm-hc',
			'IGE_HC',
			array(
				'sw'      => home_url( '/?ige-ffm-sw=1' ),
				'api'     => esc_url_raw( rest_url( 'ige-ffm/v1/ecritures' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'user'    => get_current_user_id(),
				'page'    => ige_ffm_url( 'hors-connexion' ),
				'saisie'  => ige_ffm_url( 'nouvelle-ecriture' ),
				'livre'   => ige_ffm_url( 'livre-de-caisse' ),
			)
		);
	}

	/**
	 * Sur la page de connexion : vide les pages gardées en mémoire (la file d'attente est conservée).
	 */
	public static function nettoyage_deconnecte() {
		if ( is_user_logged_in() ) {
			return;
		}
		echo "<script>if('caches' in window){caches.keys().then(function(k){k.forEach(function(n){if(n.indexOf('ige-ffm-pages')===0){caches.delete(n);}});});}</script>\n";
	}

	/**
	 * Route d'envoi des écritures saisies hors connexion.
	 */
	public static function routes() {
		register_rest_route(
			'ige-ffm/v1',
			'/ecritures',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn() => is_user_logged_in() && current_user_can( 'ige_saisir' ),
				'callback'            => array( __CLASS__, 'recevoir' ),
			)
		);
	}

	/**
	 * Reçoit une écriture différée.
	 *
	 * @param WP_REST_Request $req Requête.
	 * @return WP_REST_Response
	 */
	public static function recevoir( WP_REST_Request $req ) {
		$data = (array) $req->get_body_params();
		if ( '' === preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $data['cle_client'] ?? '' ) ) ) {
			return new WP_REST_Response( array( 'code' => 'cle', 'message' => 'Clé de l’écriture manquante.' ), 400 );
		}
		$files = $req->get_file_params();
		$file  = isset( $files['piece'] ) && UPLOAD_ERR_NO_FILE !== (int) $files['piece']['error'] ? $files['piece'] : null;
		$res   = IGE_FFM_Ecran_Nouvelle_Ecriture::enregistrer( $data, $file );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'code' => $res->get_error_code(), 'message' => $res->get_error_message() ), 422 );
		}
		$e = IGE_FFM_Ecritures::get( (int) $res );
		return new WP_REST_Response( array( 'id' => (int) $e['id'], 'id_ecriture' => $e['id_ecriture'], 'montant' => (int) $e['montant'] ), 201 );
	}
}
