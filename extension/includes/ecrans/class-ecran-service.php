<?php
/**
 * Éléments communs aux écrans d'un service : choix du service (profils centraux),
 * messages quand aucun service n'est rattaché.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outils des écrans de service.
 */
final class IGE_FFM_Ecran_Service {

	/**
	 * Service affiché, ou HTML d'explication si aucun.
	 *
	 * @return array{sid:int, html:string}
	 */
	public static function contexte() {
		$sid = IGE_FFM_Contexte::service_courant();
		if ( $sid && IGE_FFM_Contexte::peut_voir( $sid ) ) {
			return array( 'sid' => $sid, 'html' => self::selecteur( $sid ) );
		}
		if ( current_user_can( 'ige_voir_tout' ) ) {
			return array( 'sid' => 0, 'html' => ige_ffm_banner( 'info', 'Choisissez un service', ' Votre profil consulte tous les services, en lecture.' ) . self::selecteur( 0 ) );
		}
		return array( 'sid' => 0, 'html' => ige_ffm_banner( 'warn', 'Compte non rattaché à un service', ' Demandez à Administration et Finance de rattacher votre compte à votre service.' ) );
	}

	/**
	 * Sélecteur de service pour les profils centraux (lecture seule).
	 *
	 * @param int $sid Service courant.
	 * @return string
	 */
	public static function selecteur( $sid ) {
		if ( IGE_FFM_Contexte::service_utilisateur() || ! current_user_can( 'ige_voir_tout' ) ) {
			return '';
		}
		$h = '<form method="get" class="ige-toolbar"><label for="ige-service-choix" class="ige-small" style="font-weight:600">Service consulté</label><select id="ige-service-choix" name="service" class="ige-input" style="width:auto" onchange="this.form.submit()"><option value="">— Choisir —</option>';
		foreach ( IGE_FFM_Contexte::services_visibles() as $s ) {
			$h .= '<option value="' . esc_attr( $s['id'] ) . '"' . selected( $sid, (int) $s['id'], false ) . '>' . esc_html( $s['code'] ) . '</option>';
		}
		return $h . '</select><noscript><button class="ige-btn ige-btn-line" type="submit">Afficher</button></noscript>' . ( $sid ? '<span class="ige-small ige-muted">Lecture seule : la saisie est réservée au service.</span>' : '' ) . '</form>';
	}

	/**
	 * Ajoute ?service= aux liens quand un profil central consulte un service.
	 *
	 * @param string $url URL.
	 * @param int    $sid Service.
	 * @return string
	 */
	public static function lien( $url, $sid ) {
		return IGE_FFM_Contexte::service_utilisateur() ? $url : add_query_arg( 'service', $sid, $url );
	}

	/**
	 * Barre d'exécution avec pourcentage.
	 *
	 * @param float|null $taux Taux (%).
	 * @return string
	 */
	public static function barre( $taux ) {
		if ( null === $taux ) {
			return '<span class="ige-small ige-muted">Sans budget</span>';
		}
		$color = $taux > 100 ? 'var(--ige-rouge)' : ( $taux >= (int) IGE_FFM_Reglages::get( 'alerte_rubrique' ) ? 'var(--ige-orange)' : 'var(--ige-bleu)' );
		return '<div class="ige-row" style="flex-wrap:nowrap"><div class="ige-bar" style="margin:0;flex:1;min-width:60px"><i style="width:' . esc_attr( min( 100, round( $taux ) ) ) . '%;background:' . $color . '"></i></div><span class="ige-num">' . esc_html( round( $taux ) ) . '&nbsp;%</span></div>';
	}
}
