<?php
/**
 * IGE Frais de fonctionnement — fonctions du thème.
 *
 * Le thème fournit l'interface (charte graphique, en-tête, menus, modèles de pages,
 * composants). La logique comptable (écritures, budgets, contrôles, droits) relève
 * de l'extension métier « IGE Frais de fonctionnement », qui s'appuie sur les
 * fonctions et filtres documentés ici et dans README.md.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

define( 'IGE_FF_VERSION', '1.0.1' );
define( 'IGE_FF_DIR', get_template_directory() );
define( 'IGE_FF_URI', get_template_directory_uri() );

require IGE_FF_DIR . '/inc/setup.php';
require IGE_FF_DIR . '/inc/customizer.php';
require IGE_FF_DIR . '/inc/profils.php';
require IGE_FF_DIR . '/inc/navigation.php';
require IGE_FF_DIR . '/inc/template-tags.php';
require IGE_FF_DIR . '/inc/page-meta.php';
require IGE_FF_DIR . '/inc/login.php';
require IGE_FF_DIR . '/inc/structure.php';
