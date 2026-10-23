<?php
/**
 * Plugin Name:       IGE Frais de fonctionnement (métier)
 * Plugin URI:        https://ige-gestion.com/
 * Description:       Gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement : référentiels, comptes et profils, budgets, écritures de caisse, transmission, vérification, consolidation. Étape 1 : socle (base de données, rôles, référentiels, comptes, règles, journal d'audit, sécurité des connexions).
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Inspection Générale de l'Enseignement
 * License:           GPL-2.0-or-later
 * Text Domain:       ige-ff-metier
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

define( 'IGE_FFM_VERSION', '0.1.0' );
define( 'IGE_FFM_DB_VERSION', '1' );
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
require_once IGE_FFM_DIR . 'includes/class-ecrans.php';

register_activation_hook( __FILE__, array( 'IGE_FFM_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'IGE_FFM_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'IGE_FFM_Installer', 'maybe_upgrade' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Securite', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Ecrans', 'init' ) );
add_action( 'plugins_loaded', array( 'IGE_FFM_Comptes', 'init' ) );

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'ige-ffm', IGE_FFM_URL . 'assets/ige-ffm.css', array(), (string) filemtime( IGE_FFM_DIR . 'assets/ige-ffm.css' ) );
	},
	20
);
