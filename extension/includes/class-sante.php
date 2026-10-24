<?php
/**
 * Contrôle d'intégrité de l'application, à lancer après une restauration de sauvegarde
 * (ou à tout moment) : chaîne du journal d'audit, empreintes des documents officiels,
 * présence et empreinte des pièces justificatives, cohérence des écritures.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contrôle d'intégrité.
 */
final class IGE_FFM_Sante {

	const OPTION = 'ige_ffm_dernier_controle';

	/**
	 * Lance tous les contrôles.
	 *
	 * @return array{ok:bool, controles:array, chiffres:array, le:string}
	 */
	public static function controler() {
		global $wpdb;
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$c = array();

		// 1. Journal d'audit.
		$v   = IGE_FFM_Audit::verify();
		$c[] = array( 'Journal d’audit chaîné', $v['ok'], $v['ok'] ? sprintf( '%d lignes, chaîne intacte.', $v['lignes'] ) : sprintf( 'Rupture à la ligne %d : une ligne a été modifiée ou supprimée.', $v['rupture'] ) );

		// 2. Documents officiels.
		$docs = $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'document' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$alt  = array();
		foreach ( $docs as $d ) {
			if ( ! hash_equals( (string) $d['empreinte'], IGE_FFM_Documents::empreinte( $d ) ) ) {
				$alt[] = $d['reference'];
			}
		}
		$c[] = array( 'Documents officiels (rapports, PV)', ! $alt, $alt ? 'Altérés : ' . implode( ', ', array_slice( $alt, 0, 20 ) ) . '.' : sprintf( '%d document(s), tous authentiques.', count( $docs ) ) );

		// 3. Pièces justificatives.
		$dir     = IGE_FFM_Pieces::dossier();
		$pieces  = $wpdb->get_results( 'SELECT id, fichier, empreinte FROM ' . IGE_FFM_DB::t( 'piece' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$absents = array();
		$modif   = array();
		foreach ( $pieces as $p ) {
			$f = $dir . $p['fichier'];
			if ( ! is_readable( $f ) ) {
				$absents[] = (int) $p['id'];
			} elseif ( ! hash_equals( (string) $p['empreinte'], (string) hash_file( 'sha256', $f ) ) ) {
				$modif[] = (int) $p['id'];
			}
		}
		$c[] = array( 'Pièces justificatives (fichiers)', ! $absents && ! $modif, $absents || $modif ? sprintf( '%d absente(s), %d modifiée(s) sur %d. Restaurez aussi le dossier wp-content/uploads/ige-pieces.', count( $absents ), count( $modif ), count( $pieces ) ) : sprintf( '%d fichier(s) présents et intacts.', count( $pieces ) ) );

		// 4. Écritures.
		$t    = IGE_FFM_DB::t( 'ecriture' );
		$dbl  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT id_ecriture FROM $t GROUP BY id_ecriture HAVING COUNT(*) > 1) x" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orph = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t e LEFT JOIN " . IGE_FFM_DB::t( 'service' ) . ' s ON s.id = e.service_id WHERE s.id IS NULL' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$c[]  = array( 'Écritures', ! $dbl && ! $orph, $dbl || $orph ? sprintf( '%d identifiant(s) en double, %d écriture(s) sans service.', $dbl, $orph ) : 'Identifiants uniques, toutes rattachées à un service.' );

		// 5. Version de la base.
		$ok  = get_option( 'ige_ffm_db_version' ) === IGE_FFM_DB_VERSION;
		$c[] = array( 'Version de la base', $ok, $ok ? 'Schéma ' . IGE_FFM_DB_VERSION . ', à jour.' : 'Schéma ' . get_option( 'ige_ffm_db_version' ) . ' au lieu de ' . IGE_FFM_DB_VERSION . ' : rechargez une page pour lancer la mise à jour.' );

		$chiffres = array(
			'Écritures'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'Pièces'         => count( $pieces ),
			'Documents'      => count( $docs ),
			'Comptes actifs' => count( IGE_FFM_Comptes::search( array( 'actif' => 1 ) ) ),
			'Lignes d’audit' => $v['lignes'],
		);
		$res = array(
			'ok'        => ! in_array( false, array_column( $c, 1 ), true ),
			'controles' => $c,
			'chiffres'  => $chiffres,
			'le'        => ige_ffm_now(),
		);
		update_option( self::OPTION, $res, false );
		IGE_FFM_Audit::log( 'controle_integrite', 'application', '', null, array( 'resultat' => $res['ok'] ? 'conforme' : 'anomalies' ) + $chiffres );
		return $res;
	}

	/**
	 * Dernier contrôle.
	 *
	 * @return array|null
	 */
	public static function dernier() {
		$r = get_option( self::OPTION, null );
		return is_array( $r ) ? $r : null;
	}
}
