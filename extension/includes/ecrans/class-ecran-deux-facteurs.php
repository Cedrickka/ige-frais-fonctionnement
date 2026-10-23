<?php
/**
 * Écran « Double authentification » : activation (code QR), codes de secours, désactivation ;
 * réinitialisation par l'administration (depuis l'écran Comptes).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Double authentification.
 */
final class IGE_FFM_Ecran_Deux_Facteurs {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_2fa_activer', array( __CLASS__, 'handle_activer' ) );
		add_action( 'admin_post_ige_ffm_2fa_codes', array( __CLASS__, 'handle_codes' ) );
		add_action( 'admin_post_ige_ffm_2fa_desactiver', array( __CLASS__, 'handle_desactiver' ) );
		add_action( 'admin_post_ige_ffm_2fa_reinitialiser', array( __CLASS__, 'handle_reinitialiser' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Double authentification';
		$args['subtitle'] = 'Un code à usage unique, en plus du mot de passe, à chaque connexion';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Codes de secours à montrer une seule fois.
	 *
	 * @return string[]
	 */
	private static function codes_a_montrer() {
		$k = 'ige_ffm_2fa_codes_' . get_current_user_id();
		$c = get_transient( $k );
		delete_transient( $k );
		return is_array( $c ) ? $c : array();
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$u     = wp_get_current_user();
		$uid   = (int) $u->ID;
		$oblig = IGE_FFM_Deux_Facteurs::obligatoire( $uid );
		$codes = self::codes_a_montrer();
		ob_start();
		if ( IGE_FFM_Deux_Facteurs::desactive() ) {
			echo ige_ffm_banner( 'ko', 'Double authentification suspendue sur ce site', ' La constante IGE_FFM_DESACTIVER_2FA est active dans wp-config.php : retirez-la dès que l’accès est rétabli.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $codes ) :
			?>
			<section class="ige-card">
				<h2>Vos codes de secours</h2>
				<?php echo ige_ffm_banner( 'warn', 'Notez-les maintenant', ' Ils ne seront plus jamais affichés. Chaque code sert une seule fois, si vous n’avez pas votre téléphone. Gardez-les sur papier, dans un endroit sûr.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-codes ige-num"><?php foreach ( $codes as $c ) : ?><span class="ige-code"><?php echo esc_html( $c ); ?></span><?php endforeach; ?></div>
				<button type="button" class="ige-btn ige-btn-line ige-no-print" style="margin-top:12px" onclick="window.print()">Imprimer les codes</button>
			</section>
			<?php
		endif;
		if ( IGE_FFM_Deux_Facteurs::actif( $uid ) ) :
			?>
			<section class="ige-card">
				<h2>État <span class="ige-sub"><?php echo ige_ffm_pill( 'Activée', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></h2>
				<p style="margin-top:0">À chaque connexion, après votre mot de passe, l’application vous demande le code affiché par votre application d’authentification. Codes de secours restants : <b><?php echo esc_html( IGE_FFM_Deux_Facteurs::codes_restants( $uid ) ); ?></b>.</p>
				<?php echo IGE_FFM_Ecrans::form_open( '2fa_codes', 'class="ige-row" style="align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div style="flex:1;min-width:200px"><?php echo IGE_FFM_Ecrans::field( 'code', 'Code actuel de l’application', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="one-time-code" maxlength="9"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<button type="submit" class="ige-btn ige-btn-line" style="margin-bottom:16px">Générer de nouveaux codes de secours</button>
					<?php if ( ! $oblig ) : ?>
						<button type="submit" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" name="action" value="ige_ffm_2fa_desactiver" class="ige-btn ige-btn-rouge" style="margin-bottom:16px">Désactiver</button>
						<?php wp_nonce_field( 'ige_ffm_2fa_desactiver', '_ige_nonce_d' ); ?>
					<?php endif; ?>
				</form>
				<p class="ige-small ige-muted" style="margin:0">Téléphone perdu ou changé : utilisez un code de secours pour vous connecter, puis demandez à l’administration de réinitialiser votre double authentification afin de l’activer sur le nouveau téléphone.</p>
			</section>
			<?php
		else :
			$secret = IGE_FFM_Deux_Facteurs::secret_en_attente( $uid );
			if ( $oblig ) {
				echo ige_ffm_banner( 'warn', 'Activation obligatoire', ' Votre profil donne accès à l’administration et aux fonds : activez la double authentification pour continuer.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<section class="ige-card">
				<h2>Activer la double authentification</h2>
				<ol class="ige-etapes">
					<li>Installez sur votre téléphone une application d’authentification : Google Authenticator, Microsoft Authenticator ou FreeOTP (gratuites).</li>
					<li>Dans l’application, ajoutez un compte en scannant ce code :
						<div class="ige-qr ige-qr-grand" data-ige-qr="<?php echo esc_attr( IGE_FFM_Deux_Facteurs::uri( $u, $secret ) ); ?>" aria-label="Code QR d’activation"></div>
						<span class="ige-small">Sans appareil photo, saisissez la clé : <span class="ige-code"><?php echo esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ); ?></span></span>
					</li>
					<li>Saisissez le code à 6 chiffres affiché par l’application :</li>
				</ol>
				<?php echo IGE_FFM_Ecrans::form_open( '2fa_activer', 'class="ige-row" style="align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div style="flex:1;min-width:200px"><?php echo IGE_FFM_Ecrans::field( 'code', 'Code de vérification', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="one-time-code" maxlength="7" pattern="[0-9 ]{6,7}"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<button type="submit" class="ige-btn ige-btn-jaune" style="margin-bottom:16px">Activer</button>
				</form>
			</section>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Activation.
	 */
	public static function handle_activer() {
		ige_ffm_check_form( '2fa_activer' );
		if ( ! is_user_logged_in() ) {
			wp_die( 'Connexion requise.', '', array( 'response' => 403 ) );
		}
		$uid = get_current_user_id();
		$res = IGE_FFM_Deux_Facteurs::activer( $uid, ige_ffm_post( 'code' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Activation refusée', $res->get_error_message() );
		} else {
			set_transient( 'ige_ffm_2fa_codes_' . $uid, $res, 10 * MINUTE_IN_SECONDS );
			ige_ffm_flash( 'ok', 'Double authentification activée', 'Notez vos codes de secours ci-dessous.' );
		}
		wp_safe_redirect( ige_ffm_url( 'double-authentification' ) );
		exit;
	}

	/**
	 * Nouveaux codes de secours (code actuel exigé).
	 */
	public static function handle_codes() {
		ige_ffm_check_form( '2fa_codes' );
		$uid = get_current_user_id();
		if ( ! $uid || ! IGE_FFM_Deux_Facteurs::actif( $uid ) || 'totp' !== IGE_FFM_Deux_Facteurs::controler( $uid, ige_ffm_post( 'code' ) ) ) {
			ige_ffm_flash( 'ko', 'Code incorrect', 'Saisissez le code affiché maintenant par votre application.' );
		} else {
			set_transient( 'ige_ffm_2fa_codes_' . $uid, IGE_FFM_Deux_Facteurs::nouveaux_codes_secours( $uid ), 10 * MINUTE_IN_SECONDS );
			IGE_FFM_Audit::log( 'codes_secours_2fa', 'compte', $uid, null, null, $uid );
			ige_ffm_flash( 'ok', 'Nouveaux codes de secours', 'Les anciens codes ne fonctionnent plus.' );
		}
		wp_safe_redirect( ige_ffm_url( 'double-authentification' ) );
		exit;
	}

	/**
	 * Désactivation par l'intéressé (code actuel exigé, impossible si obligatoire).
	 */
	public static function handle_desactiver() {
		if ( ! isset( $_POST['_ige_nonce_d'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_ige_nonce_d'] ) ), 'ige_ffm_2fa_desactiver' ) ) {
			wp_die( 'Le formulaire a expiré.', '', array( 'response' => 403 ) );
		}
		$uid = get_current_user_id();
		if ( ! $uid || 'totp' !== IGE_FFM_Deux_Facteurs::controler( $uid, ige_ffm_post( 'code' ) ) ) {
			ige_ffm_flash( 'ko', 'Code incorrect', 'Saisissez le code affiché maintenant par votre application.' );
		} else {
			$res = IGE_FFM_Deux_Facteurs::desactiver( $uid );
			if ( is_wp_error( $res ) ) {
				ige_ffm_flash( 'ko', 'Désactivation refusée', $res->get_error_message() );
			} else {
				ige_ffm_flash( 'ok', 'Double authentification désactivée', '' );
			}
		}
		wp_safe_redirect( ige_ffm_url( 'double-authentification' ) );
		exit;
	}

	/**
	 * Réinitialisation par l'administration (téléphone perdu).
	 */
	public static function handle_reinitialiser() {
		ige_ffm_check_form( '2fa_reinitialiser' );
		ige_ffm_require( 'ige_administrer' );
		$uid = (int) ige_ffm_post( 'user_id' );
		if ( $uid === get_current_user_id() ) {
			ige_ffm_flash( 'ko', 'Action refusée', 'Un autre administrateur doit réinitialiser votre propre double authentification.' );
		} else {
			$res = IGE_FFM_Deux_Facteurs::desactiver( $uid, get_current_user_id(), 'Réinitialisation demandée (téléphone perdu ou changé)' );
			if ( is_wp_error( $res ) ) {
				ige_ffm_flash( 'ko', 'Action refusée', $res->get_error_message() );
			} else {
				$u = get_userdata( $uid );
				ige_ffm_flash( 'ok', 'Double authentification réinitialisée pour ' . ( $u ? $u->user_login : $uid ), 'À sa prochaine connexion, la personne l’activera sur son nouveau téléphone.' );
			}
		}
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}
}
