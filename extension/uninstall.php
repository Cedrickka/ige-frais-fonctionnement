<?php
/**
 * Désinstallation.
 *
 * Par sécurité, les données comptables ne sont JAMAIS supprimées automatiquement.
 * Pour tout effacer (site de test uniquement), ajouter dans wp-config.php :
 *     define( 'IGE_FFM_SUPPRIMER_DONNEES', true );
 * puis supprimer l'extension.
 *
 * @package IGE_FF_Metier
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-db.php';
require_once __DIR__ . '/includes/class-roles.php';

IGE_FFM_Roles::uninstall();
wp_clear_scheduled_hook( 'ige_ffm_horaire' );

if ( defined( 'IGE_FFM_SUPPRIMER_DONNEES' ) && IGE_FFM_SUPPRIMER_DONNEES ) {
	global $wpdb;
	foreach ( IGE_FFM_DB::tables() as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS ' . IGE_FFM_DB::t( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}
	delete_option( 'ige_ffm_reglages' );
	delete_option( 'ige_ffm_db_version' );
	delete_option( 'ige_ffm_audit_verification' );
	delete_option( 'ige_ffm_smtp' );
	delete_option( 'ige_ffm_reprise_central' );
	delete_option( 'ige_ffm_bareme' );
	delete_option( 'ige_ffm_fonctions' );
	delete_option( 'ige_ffm_rapport_hebdo' );
	delete_option( 'ige_ffm_menus_v21' );
}
