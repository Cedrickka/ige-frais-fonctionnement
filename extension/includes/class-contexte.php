<?php
/**
 * Contexte de travail : exercice courant, service de l'utilisateur, droits sur un service.
 *
 * Règle d'isolement : un compte de service ne lit et n'écrit que les données de son service.
 * Seuls les profils qui ont « ige_voir_tout » (Administration et Finance, Direction) peuvent
 * consulter un autre service, en lecture.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contexte.
 */
final class IGE_FFM_Contexte {

	/**
	 * Exercice ouvert courant.
	 *
	 * @return array|null
	 */
	public static function exercice() {
		static $ex = false;
		if ( false === $ex ) {
			$ex = IGE_FFM_Referentiels::exercice_courant();
		}
		return $ex;
	}

	/**
	 * Service rattaché au compte de l'utilisateur (0 si aucun ou compte inactif).
	 *
	 * @param int|null $user_id Utilisateur.
	 * @return int
	 */
	public static function service_utilisateur( $user_id = null ) {
		$c = IGE_FFM_Comptes::get( $user_id ?? get_current_user_id() );
		return $c && (int) $c['actif'] ? (int) $c['service_id'] : 0;
	}

	/**
	 * Service affiché : celui de l'utilisateur, ou celui choisi (?service=) par un profil central.
	 *
	 * @return int
	 */
	public static function service_courant() {
		$own = self::service_utilisateur();
		if ( $own ) {
			return $own;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$asked = isset( $_GET['service'] ) ? (int) $_GET['service'] : 0;
		return $asked && current_user_can( 'ige_voir_tout' ) ? $asked : 0;
	}

	/**
	 * L'utilisateur peut-il consulter ce service ?
	 *
	 * @param int      $service_id Service.
	 * @param int|null $user_id    Utilisateur.
	 * @return bool
	 */
	public static function peut_voir( $service_id, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( user_can( $user_id, 'ige_voir_tout' ) ) {
			return true;
		}
		return user_can( $user_id, 'ige_voir_service' ) && (int) $service_id === self::service_utilisateur( $user_id ) && $service_id > 0;
	}

	/**
	 * L'utilisateur peut-il saisir pour ce service ? (uniquement son propre service)
	 *
	 * @param int      $service_id Service.
	 * @param int|null $user_id    Utilisateur.
	 * @return bool
	 */
	public static function peut_saisir( $service_id, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		return user_can( $user_id, 'ige_saisir' ) && $service_id > 0 && (int) $service_id === self::service_utilisateur( $user_id );
	}

	/**
	 * L'utilisateur peut-il modifier le budget de ce service ?
	 *
	 * @param int      $service_id Service.
	 * @param int|null $user_id    Utilisateur.
	 * @return bool
	 */
	public static function peut_budgeter( $service_id, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		return user_can( $user_id, 'ige_gerer_budget' ) && $service_id > 0 && (int) $service_id === self::service_utilisateur( $user_id );
	}

	/**
	 * Liste des services consultables (pour le sélecteur des profils centraux).
	 *
	 * @return array[]
	 */
	public static function services_visibles() {
		if ( current_user_can( 'ige_voir_tout' ) ) {
			return IGE_FFM_Referentiels::all( 'service', true );
		}
		$sid = self::service_utilisateur();
		$s   = $sid ? IGE_FFM_Referentiels::get( 'service', $sid ) : null;
		return $s ? array( $s ) : array();
	}

	/**
	 * Date valide (AAAA-MM-JJ) ?
	 *
	 * @param string $d Date.
	 * @return bool
	 */
	public static function date_valide( $d ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Date du jour (fuseau du site).
	 *
	 * @return string
	 */
	public static function aujourdhui() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Noms des mois.
	 *
	 * @return string[]
	 */
	public static function mois() {
		return array( 1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre' );
	}
}
