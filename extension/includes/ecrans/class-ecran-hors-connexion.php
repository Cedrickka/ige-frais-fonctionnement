<?php
/**
 * Écran « Saisie hors connexion » (VA-50) : état du réseau, écritures en attente sur le
 * téléphone, envoi, installation de l'application. Le contenu dynamique est rempli par
 * assets/ige-ffm-hc.js (la file d'attente n'existe que dans le navigateur).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hors connexion.
 */
final class IGE_FFM_Ecran_Hors_Connexion {

	/**
	 * Crochets.
	 */
	public static function register() {}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Saisie hors connexion';
		$args['subtitle'] = 'Saisir sans réseau, envoyer au retour de la connexion';
		$args['actions']  = '<a class="ige-btn ige-btn-jaune" href="' . esc_url( ige_ffm_url( 'nouvelle-ecriture' ) ) . '">Nouvelle écriture</a>';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! IGE_FFM_Contexte::service_utilisateur() ) {
			return ige_ffm_banner( 'info', 'Réservé aux comptes de service', ' La saisie hors connexion concerne le livre de caisse d’un service.' );
		}
		ob_start();
		?>
		<div class="ige-grid ige-cols-21 ige-start" id="ige-hc">
			<section class="ige-card" style="padding:16px">
				<div class="ige-toolbar">
					<h2 style="margin:0">Écritures en attente sur ce téléphone</h2>
					<button type="button" class="ige-btn ige-btn-line" id="ige-hc-envoyer" style="margin-left:auto">Envoyer maintenant</button>
				</div>
				<p class="ige-small" id="ige-hc-etat" role="status" aria-live="polite">Vérification…</p>
				<div id="ige-hc-liste"><noscript><p class="ige-empty">JavaScript est nécessaire pour la saisie hors connexion.</p></noscript></div>
				<p class="ige-small ige-muted" style="margin:10px 0 0">Les écritures envoyées passent par les mêmes contrôles que la saisie en ligne (budget, caisse, pièce justificative, période ouverte). Une écriture refusée reste ici avec le motif : corrigez-la en la ressaisissant, puis supprimez-la de la liste. Un même envoi répété ne crée jamais de doublon.</p>
			</section>
			<aside class="ige-card">
				<h2 style="margin-top:0">Comment ça marche</h2>
				<ol class="ige-small" style="padding-left:18px;margin:0 0 10px">
					<li>Ouvrez une fois « Nouvelle écriture » avec du réseau : la page est gardée sur le téléphone.</li>
					<li>Sans réseau, saisissez normalement et photographiez la pièce : l’écriture est rangée sur le téléphone.</li>
					<li>Au retour du réseau, l’envoi est automatique ; un bandeau indique les écritures en attente.</li>
				</ol>
				<h3 style="font-size:14px;margin:12px 0 6px">Installer l’application</h3>
				<p class="ige-small">Android (Chrome) : menu ⋮ puis « Ajouter à l’écran d’accueil ». iPhone (Safari) : bouton Partager puis « Sur l’écran d’accueil ».</p>
				<h3 style="font-size:14px;margin:12px 0 6px">Précautions</h3>
				<ul class="ige-small" style="padding-left:18px;margin:0">
					<li>Les écritures en attente restent sur ce téléphone jusqu’à l’envoi : ne videz pas les données du navigateur avant.</li>
					<li>N’utilisez pas la navigation privée.</li>
					<li>Si la session a expiré, reconnectez-vous : l’envoi reprend ensuite.</li>
					<li>Les saisies en dollars utilisent le taux du jour de la date de l’opération, vérifié à l’envoi.</li>
				</ul>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}
}
