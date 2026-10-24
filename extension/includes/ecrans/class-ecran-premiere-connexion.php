<?php
/**
 * Écran Première connexion : choix du mot de passe personnel.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran de première connexion.
 */
final class IGE_FFM_Ecran_Premiere_Connexion {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_premier_mdp', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Choisissez votre mot de passe';
		$args['subtitle'] = 'Première connexion : le mot de passe provisoire n’est plus valable après cette étape';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$user = wp_get_current_user();
		$min  = (int) IGE_FFM_Reglages::get( 'mdp_longueur' );
		ob_start();
		?>
		<section class="ige-card">
			<p style="margin-top:0">Compte <b><?php echo esc_html( $user->user_login ); ?></b>. Choisissez un mot de passe que vous seul connaissez.</p>
			<?php echo IGE_FFM_Ecrans::form_open( 'premier_mdp', 'autocomplete="off"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-field"><label for="ige-mdp1">Nouveau mot de passe</label><input type="password" id="ige-mdp1" name="mdp1" required minlength="<?php echo esc_attr( $min ); ?>" autocomplete="new-password"></div>
				<div class="ige-field"><label for="ige-mdp2">Confirmer le mot de passe</label><input type="password" id="ige-mdp2" name="mdp2" required minlength="<?php echo esc_attr( $min ); ?>" autocomplete="new-password"></div>
				<div class="ige-rule"><span class="ige-ok">•</span><?php echo esc_html( sprintf( 'Au moins %d caractères', $min ) ); ?></div>
				<div class="ige-rule"><span class="ige-ok">•</span>Une majuscule, une minuscule et un chiffre</div>
				<div class="ige-rule"><span class="ige-ok">•</span>Différent de l’identifiant et du mot de passe provisoire</div>
				<button type="submit" class="ige-btn ige-btn-bleu ige-btn-block" style="margin-top:16px">Enregistrer et continuer</button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Enregistrement du nouveau mot de passe.
	 */
	public static function handle() {
		ige_ffm_check_form( 'premier_mdp' );
		ige_ffm_require( 'read' ); // Son propre compte seulement.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$user = wp_get_current_user();
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$p1 = isset( $_POST['mdp1'] ) ? (string) wp_unslash( $_POST['mdp1'] ) : '';
		$p2 = isset( $_POST['mdp2'] ) ? (string) wp_unslash( $_POST['mdp2'] ) : '';
		// phpcs:enable
		$back = ige_ffm_url( 'premiere-connexion' );
		if ( $p1 !== $p2 ) {
			ige_ffm_flash( 'ko', 'Les deux mots de passe sont différents.', '' );
			wp_safe_redirect( $back );
			exit;
		}
		$check = IGE_FFM_Comptes::check_strength( $p1, $user );
		if ( is_wp_error( $check ) ) {
			ige_ffm_flash( 'ko', 'Mot de passe refusé', $check->get_error_message() );
			wp_safe_redirect( $back );
			exit;
		}
		wp_set_password( $p1, $user->ID );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		update_user_meta( $user->ID, '_ige_activite', time() );
		IGE_FFM_Comptes::mot_de_passe_change( $user->ID );
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
}
