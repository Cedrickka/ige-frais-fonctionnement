<?php
/**
 * Plugin Name:       IGE Frais de fonctionnement (métier)
 * Plugin URI:        https://ige-gestion.com/
 * Description:       Gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement : référentiels, comptes et profils, budgets, écritures de caisse, transmission, vérification, consolidation. Étapes 1 et 2 : socle (base, rôles, référentiels, comptes, règles, audit, sécurité) et saisie (budget, écritures, contrôles bloquants, livre de caisse, contrepassation, pièces, fournisseurs).
 * Version:           1.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Inspection Générale de l'Enseignement
 * License:           GPL-2.0-or-later
 * Text Domain:       ige-ff-metier
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

define( 'IGE_FFM_VERSION', '1.3.0' );
define( 'IGE_FFM_DB_VERSION', '8' );
define( 'IGE_FFM_FILE', __FILE__ );
define( 'IGE_FFM_DIR', plugin_dir_path( __FILE__ ) );
define( 'IGE_FFM_URL', plugin_dir_url( __FILE__ ) );

require_once IGE_FFM_DIR . 'includes/helpers.php';
require_once IGE_FFM_DIR . 'includes/class-db.php';
require_once IGE_FFM_DIR . 'includes/class-roles.php';
require_once IGE_FFM_DIR . 'includes/class-installer.php';
require_once IGE_FFM_DIR . 'includes/class-reglages.php';
require_once IGE_FFM_DIR . 'includes/class-audit.php';
require_once IGE_FFM_DIR . 'includes/class-referentiels.php';
require_once IGE_FFM_DIR . 'includes/class-comptes.php';
require_once IGE_FFM_DIR . 'includes/class-securite.php';
require_once IGE_FFM_DIR . 'includes/class-contexte.php';
require_once IGE_FFM_DIR . 'includes/class-budget.php';
require_once IGE_FFM_DIR . 'includes/class-pieces.php';
require_once IGE_FFM_DIR . 'includes/class-fournisseurs.php';
require_once IGE_FFM_DIR . 'includes/class-ecritures.php';
require_once IGE_FFM_DIR . 'includes/class-anomalies.php';
require_once IGE_FFM_DIR . 'includes/class-transmission.php';
require_once IGE_FFM_DIR . 'includes/class-verification.php';
require_once IGE_FFM_DIR . 'includes/class-avances.php';
require_once IGE_FFM_DIR . 'includes/class-arretes.php';
require_once IGE_FFM_DIR . 'includes/class-cloture.php';
require_once IGE_FFM_DIR . 'includes/class-documents.php';
require_once IGE_FFM_DIR . 'includes/class-consolidation.php';
require_once IGE_FFM_DIR . 'includes/class-rapports.php';
require_once IGE_FFM_DIR . 'includes/class-deux-facteurs.php';
require_once IGE_FFM_DIR . 'includes/class-messagerie.php';
require_once IGE_FFM_DIR . 'includes/class-xlsx.php';
require_once IGE_FFM_DIR . 'includes/class-classeur.php';
require_once IGE_FFM_DIR . 'includes/class-pdf.php';
require_once IGE_FFM_DIR . 'includes/class-reprise.php';
require_once IGE_FFM_DIR . 'includes/class-invitations.php';
require_once IGE_FFM_DIR . 'includes/class-echanges.php';
require_once IGE_FFM_DIR . 'includes/class-jours-feries.php';
require_once IGE_FFM_DIR . 'includes/class-sante.php';
require_once IGE_FFM_DIR . 'includes/class-ecrans.php';

register_activation_hook( __FILE__, array( 'IGE_FFM_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IGE_FFM_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'IGE_FFM_Installer', 'maybe_upgrade' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Securite', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Ecrans', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Comptes', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Pieces', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Transmission', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Deux_Facteurs', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Messagerie', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Invitations', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Pdf', 'init' ) );

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'ige-ffm', IGE_FFM_URL . 'assets/ige-ffm.css', array(), (string) filemtime( IGE_FFM_DIR . 'assets/ige-ffm.css' ) );
		if ( is_user_logged_in() && is_page( array( 'rapports', 'arrete-de-caisse', 'double-authentification' ) ) ) {
			wp_enqueue_script( 'ige-ffm-qrcode', IGE_FFM_URL . 'assets/vendor/qrcode.js', array(), '1.4.4', true );
		}
		if ( is_user_logged_in() && is_page( array( 'nouvelle-ecriture', 'livre-de-caisse', 'verification', 'transmission', 'arrete-de-caisse', 'caisses', 'avances', 'rapports', 'double-authentification' ) ) ) {
			wp_enqueue_script( 'ige-ffm-saisie', IGE_FFM_URL . 'assets/ige-ffm.js', wp_script_is( 'ige-ffm-qrcode', 'enqueued' ) ? array( 'ige-ffm-qrcode' ) : array(), (string) filemtime( IGE_FFM_DIR . 'assets/ige-ffm.js' ), true );
		}
	},
	20
);
