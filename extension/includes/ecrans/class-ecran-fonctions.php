<?php
/**
 * Écran Administration > Fonctions du site : activer ou désactiver les modules et leurs menus.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions du site.
 */
final class IGE_FFM_Ecran_Fonctions {

	/**
	 * Crochets (le traitement est dans IGE_FFM_Fonctions).
	 */
	public static function register() {}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Fonctions du site';
		$args['subtitle'] = 'Activer ou désactiver les modules et leurs menus · aucune donnée n’est supprimée';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$etats  = IGE_FFM_Fonctions::etats();
		$groupes = array();
		foreach ( IGE_FFM_Fonctions::modules() as $k => $m ) {
			$groupes[ $m['groupe'] ][ $k ] = $m;
		}
		$h = IGE_FFM_Ecrans::form_open( 'fonctions', 'class="ige-grid" style="gap:16px"' );
		$h .= '<div class="ige-grid ige-cols-2 ige-start">';
		foreach ( $groupes as $g => $mods ) {
			$h .= '<section class="ige-card"><h2 style="margin-top:0">' . esc_html( $g ) . '</h2><div class="ige-fonctions">';
			foreach ( $mods as $k => $m ) {
				$h .= '<label class="ige-fonction"><input type="checkbox" name="fonction[]" value="' . esc_attr( $k ) . '"' . checked( $etats[ $k ], true, false ) . ' style="width:auto"> <span><b>' . esc_html( $m['libelle'] ) . '</b> ' . ( $etats[ $k ] ? ige_ffm_pill( 'Active', 'ok' ) : ige_ffm_pill( 'Désactivée', 'neutre' ) ) . '<br><span class="ige-small ige-muted">' . esc_html( $m['desc'] ) . '</span></span></label>';
			}
			$h .= '</div></section>';
		}
		$h .= '<section class="ige-card"><h2 style="margin-top:0">Toujours actives</h2><p class="ige-small">Saisie et livre de caisse, budget, transmission et vérification, échanges, avances de fonds, arrêtés de caisse, caisses, consolidation, rapports, journal d’audit, comptes et sécurité. Ce socle ne se désactive pas.</p><p class="ige-small ige-muted">Désactiver une fonction retire ses menus pour tous les profils, bloque ses formulaires et coupe ses alertes et ses e-mails. Les données déjà saisies restent intactes et réapparaissent à la réactivation. Chaque changement est inscrit au journal d’audit.</p></section>';
		$h .= '</div><div><button type="submit" class="ige-btn ige-btn-jaune">Enregistrer</button></div></form>';
		return $h;
	}
}
