<?php
/**
 * Double devise (VA-51) : une dépense payée en dollars est saisie dans sa devise réelle et
 * convertie en francs congolais au taux du jour fixé par Administration et Finance.
 * Le livre, les budgets et les états restent en CDF ; le montant et le taux d'origine sont conservés.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devises et taux.
 */
final class IGE_FFM_Devises {

	/**
	 * Taux applicable à une date : le dernier fixé à cette date ou avant.
	 *
	 * @param string $date   AAAA-MM-JJ.
	 * @param string $devise Devise.
	 * @return array|null date_effet, taux.
	 */
	public static function taux( $date, $devise = 'USD' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'taux' ) . ' WHERE devise = %s AND date_effet <= %s ORDER BY date_effet DESC LIMIT 1', $devise, $date ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Derniers taux (pour l'écran et la conversion dans le navigateur).
	 *
	 * @param int $n Nombre.
	 * @return array[]
	 */
	public static function derniers( $n = 30 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'taux' ) . " WHERE devise = 'USD' ORDER BY date_effet DESC LIMIT %d", $n ), ARRAY_A );
	}

	/**
	 * Fixe le taux d'une date.
	 *
	 * @param string   $date    Date.
	 * @param string   $taux    Taux saisi (« 2 850 » ou « 2850,50 »).
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function fixer( $date, $taux, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Le taux du jour est fixé par Administration et Finance.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'Date invalide ou future.' );
		}
		$t = self::nombre( $taux );
		if ( null === $t || $t < 100 || $t > 100000 ) {
			return new WP_Error( 'taux', 'Taux invalide : nombre de francs congolais pour 1 dollar (par exemple 2 850).' );
		}
		$tb    = IGE_FFM_DB::t( 'taux' );
		$avant = self::taux( $date );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $tb WHERE date_effet = %s AND devise = 'USD'", $date ) );
		$wpdb->insert( $tb, array( 'date_effet' => $date, 'devise' => 'USD', 'taux' => $t, 'saisi_par' => (int) $user_id, 'saisi_le' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'taux_du_jour', 'taux', $date, $avant && $avant['date_effet'] === $date ? array( 'taux' => (float) $avant['taux'] ) : null, array( 'taux' => $t ), $user_id );
		return true;
	}

	/**
	 * Nombre décimal saisi à la française.
	 *
	 * @param mixed $v Valeur.
	 * @return float|null
	 */
	public static function nombre( $v ) {
		$s = str_replace( array( ' ', "\u{00A0}", "\u{202F}" ), '', trim( (string) $v ) );
		$s = str_replace( ',', '.', $s );
		return '' !== $s && is_numeric( $s ) ? round( (float) $s, 4 ) : null;
	}

	/**
	 * Convertit un montant en dollars en francs congolais au taux de la date.
	 *
	 * @param mixed  $usd  Montant en USD (« 120 », « 120,50 »).
	 * @param string $date Date.
	 * @return array{cdf:int, usd:float, taux:float, date_taux:string}|WP_Error
	 */
	public static function convertir( $usd, $date ) {
		$u = self::nombre( $usd );
		if ( null === $u || $u <= 0 ) {
			return new WP_Error( 'montant', 'Montant en dollars invalide.' );
		}
		$t = self::taux( $date );
		if ( ! $t ) {
			return new WP_Error( 'taux', 'Aucun taux du jour n’est fixé à cette date : Administration et Finance doit le saisir (Trésorerie).' );
		}
		if ( strtotime( $date ) - strtotime( $t['date_effet'] ) > 31 * DAY_IN_SECONDS ) {
			return new WP_Error( 'taux', 'Le dernier taux connu date du ' . wp_date( 'd/m/Y', strtotime( $t['date_effet'] ) ) . ' : Administration et Finance doit fixer un taux plus récent.' );
		}
		return array( 'cdf' => (int) round( $u * (float) $t['taux'] ), 'usd' => round( $u, 2 ), 'taux' => (float) $t['taux'], 'date_taux' => $t['date_effet'] );
	}

	/**
	 * Mention d'origine d'une écriture en devise (« 120,00 USD × 2 850 »).
	 *
	 * @param array $e Écriture.
	 * @return string
	 */
	public static function mention( array $e ) {
		if ( 'USD' !== ( $e['devise_origine'] ?? 'CDF' ) || null === $e['montant_origine'] ) {
			return '';
		}
		return number_format( (float) $e['montant_origine'], 2, ',', "\u{202F}" ) . "\u{00A0}USD × " . number_format( (float) $e['taux'], ( fmod( (float) $e['taux'], 1 ) ? 2 : 0 ), ',', "\u{202F}" );
	}
}
