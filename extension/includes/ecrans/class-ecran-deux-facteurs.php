<?php
/**
 * Écran « Sécurité du compte » : double authentification par code e-mail (par défaut) ou par
 * application, codes de secours, changement de méthode ; réinitialisation par l'administration
 * (depuis l'écran Comptes).
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
		add_action( 'admin_post_ige_ffm_2fa_envoyer', array( __CLASS__, 'handle_envoyer' ) );
		add_action( 'admin_post_ige_ffm_2fa_activer_email', array( __CLASS__, 'handle_activer_email' ) );
		add_action( 'admin_post_ige_ffm_2fa_activer', array( __CLASS__, 'handle_activer' ) );
		add_action( 'admin_post_ige_ffm_2fa_gerer', array( __CLASS__, 'handle_gerer' ) );
		add_action( 'admin_post_ige_ffm_2fa_reinitialiser', array( __CLASS__, 'handle_reinitialiser' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Sécurité du compte';
		$args['subtitle'] = 'Double authentification : un code à usage unique en plus du mot de passe, à chaque connexion';
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
		$adr   = IGE_FFM_Messagerie::adresse_valide( $u );
		$app   = isset( $_GET['methode'] ) && 'application' === sanitize_key( $_GET['methode'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page  = ige_ffm_url( 'double-authentification' );
		ob_start();
		if ( IGE_FFM_Deux_Facteurs::desactive() ) {
			echo ige_ffm_banner( 'ko', 'Double authentification suspendue sur ce site', ' La constante IGE_FFM_DESACTIVER_2FA est active dans wp-config.php : retirez-la dès que l’accès est rétabli.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $codes ) :
			?>
			<section class="ige-card">
				<h2>Vos codes de secours</h2>
				<?php echo ige_ffm_banner( 'warn', 'Notez-les maintenant', ' Ils ne seront plus jamais affichés. Chaque code sert une seule fois, si vous ne recevez pas l’e-mail ou n’avez pas votre téléphone. Gardez-les sur papier, dans un endroit sûr.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div class="ige-codes ige-num"><?php foreach ( $codes as $c ) : ?><span class="ige-code"><?php echo esc_html( $c ); ?></span><?php endforeach; ?></div>
				<button type="button" class="ige-btn ige-btn-line ige-no-print" style="margin-top:12px" onclick="window.print()">Imprimer les codes</button>
			</section>
			<?php
		endif;

		if ( IGE_FFM_Deux_Facteurs::actif( $uid ) ) :
			$m = IGE_FFM_Deux_Facteurs::methode( $uid );
			?>
			<section class="ige-card">
				<h2>Double authentification <span class="ige-sub"><?php echo ige_ffm_pill( 'Activée', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></h2>
				<p style="margin-top:0">
					<?php if ( 'email' === $m ) : ?>
						À chaque connexion, après votre mot de passe, un code est envoyé à <b><?php echo esc_html( IGE_FFM_Messagerie::masquer( $u->user_email ) ); ?></b> par la messagerie de la plateforme.
					<?php else : ?>
						À chaque connexion, après votre mot de passe, saisissez le code affiché par votre application d’authentification.
					<?php endif; ?>
					Codes de secours restants : <b><?php echo esc_html( IGE_FFM_Deux_Facteurs::codes_restants( $uid ) ); ?></b>.
				</p>
				<?php echo IGE_FFM_Ecrans::form_open( '2fa_gerer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo IGE_FFM_Ecrans::field( 'mdp', 'Votre mot de passe (pour confirmer)', '', array( 'type' => 'password', 'required' => true, 'attrs' => 'autocomplete="current-password"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-row">
						<button type="submit" name="operation" value="codes" class="ige-btn ige-btn-line">Nouveaux codes de secours</button>
						<button type="submit" name="operation" value="changer" class="ige-btn ige-btn-line">Changer de méthode</button>
						<?php if ( ! $oblig ) : ?>
							<button type="submit" name="operation" value="desactiver" class="ige-btn ige-btn-rouge">Désactiver</button>
						<?php endif; ?>
					</div>
				</form>
				<p class="ige-small ige-muted" style="margin:12px 0 0">Adresse e-mail changée ou téléphone perdu : connectez-vous avec un code de secours, puis demandez à l’administration de réinitialiser votre double authentification.</p>
			</section>
			<?php
		elseif ( ! $app ) :
			$o = (array) get_user_meta( $uid, '_ige_2fa_otp', true );
			if ( $oblig ) {
				echo ige_ffm_banner( 'warn', 'Activation obligatoire', ' Votre profil l’exige : activez la double authentification pour continuer.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<section class="ige-card">
				<h2>Activer la double authentification par e-mail</h2>
				<?php if ( ! $adr ) : ?>
					<?php echo ige_ffm_banner( 'ko', 'Aucune adresse e-mail sur votre compte', ' Demandez à Administration et Finance d’ajouter votre adresse professionnelle, ou utilisez une application d’authentification.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<p style="margin-top:0">À chaque connexion, un code à 6 chiffres vous sera envoyé à <b><?php echo esc_html( $u->user_email ); ?></b> depuis l’adresse de la plateforme. Pour vérifier que vous recevez bien ces messages, nous vous envoyons d’abord un code d’activation.</p>
					<?php if ( empty( $o['empreinte'] ) ) : ?>
						<?php echo IGE_FFM_Ecrans::form_open( '2fa_envoyer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<button type="submit" class="ige-btn ige-btn-jaune">Recevoir le code d’activation</button>
						</form>
					<?php else : ?>
						<?php echo IGE_FFM_Ecrans::form_open( '2fa_activer_email', 'class="ige-row" style="align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<div style="flex:1;min-width:200px"><?php echo IGE_FFM_Ecrans::field( 'code', 'Code reçu par e-mail', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="one-time-code" maxlength="7"', 'help' => 'Valable 10 minutes. Regardez aussi dans les courriers indésirables.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
							<button type="submit" class="ige-btn ige-btn-jaune" style="margin-bottom:16px">Activer</button>
						</form>
						<?php echo IGE_FFM_Ecrans::form_open( '2fa_envoyer' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<button type="submit" class="ige-act" style="background:none;border:0;padding:0;cursor:pointer">Je n’ai rien reçu : renvoyer un code</button>
						</form>
					<?php endif; ?>
				<?php endif; ?>
				<p class="ige-small ige-muted" style="margin:16px 0 0">Pas d’accès fiable à votre messagerie ? <a href="<?php echo esc_url( add_query_arg( 'methode', 'application', $page ) ); ?>">Utiliser plutôt une application d’authentification sur téléphone</a>.</p>
			</section>
			<?php
		else :
			$secret = IGE_FFM_Deux_Facteurs::secret_en_attente( $uid );
			?>
			<section class="ige-card">
				<h2>Activer avec une application d’authentification</h2>
				<ol class="ige-etapes">
					<li>Installez sur votre téléphone Google Authenticator, Microsoft Authenticator ou FreeOTP (gratuites).</li>
					<li>Dans l’application, ajoutez un compte en scannant ce code :
						<div class="ige-qr ige-qr-grand" data-ige-qr="<?php echo esc_attr( IGE_FFM_Deux_Facteurs::uri( $u, $secret ) ); ?>" aria-label="Code QR d’activation"></div>
						<span class="ige-small">Sans appareil photo, saisissez la clé : <span class="ige-code"><?php echo esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ); ?></span></span>
					</li>
					<li>Saisissez le code à 6 chiffres affiché par l’application :</li>
				</ol>
				<?php echo IGE_FFM_Ecrans::form_open( '2fa_activer', 'class="ige-row" style="align-items:flex-end"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div style="flex:1;min-width:200px"><?php echo IGE_FFM_Ecrans::field( 'code', 'Code de vérification', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="one-time-code" maxlength="7"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<button type="submit" class="ige-btn ige-btn-jaune" style="margin-bottom:16px">Activer</button>
				</form>
				<p class="ige-small ige-muted" style="margin:0"><a href="<?php echo esc_url( $page ); ?>">Revenir au code par e-mail</a></p>
			</section>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Retour à l'écran.
	 *
	 * @param array $q Paramètres.
	 */
	private static function retour( array $q = array() ) {
		wp_safe_redirect( ige_ffm_url( 'double-authentification', $q ) );
		exit;
	}

	/**
	 * Envoi du code d'activation par e-mail.
	 */
	public static function handle_envoyer() {
		ige_ffm_check_form( '2fa_envoyer' );
		$res = IGE_FFM_Deux_Facteurs::demander_activation_email( get_current_user_id() );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Code non envoyé', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Code envoyé', 'Consultez votre messagerie et saisissez le code ci-dessous.' );
		}
		self::retour();
	}

	/**
	 * Activation par e-mail.
	 */
	public static function handle_activer_email() {
		ige_ffm_check_form( '2fa_activer_email' );
		$uid = get_current_user_id();
		$res = IGE_FFM_Deux_Facteurs::activer_email( $uid, ige_ffm_post( 'code' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Activation refusée', $res->get_error_message() );
		} else {
			set_transient( 'ige_ffm_2fa_codes_' . $uid, $res, 10 * MINUTE_IN_SECONDS );
			ige_ffm_flash( 'ok', 'Double authentification activée', 'Notez vos codes de secours ci-dessous.' );
		}
		self::retour();
	}

	/**
	 * Activation par application.
	 */
	public static function handle_activer() {
		ige_ffm_check_form( '2fa_activer' );
		$uid = get_current_user_id();
		$res = IGE_FFM_Deux_Facteurs::activer( $uid, ige_ffm_post( 'code' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Activation refusée', $res->get_error_message() );
			self::retour( array( 'methode' => 'application' ) );
		}
		set_transient( 'ige_ffm_2fa_codes_' . $uid, $res, 10 * MINUTE_IN_SECONDS );
		ige_ffm_flash( 'ok', 'Double authentification activée', 'Notez vos codes de secours ci-dessous.' );
		self::retour();
	}

	/**
	 * Codes de secours, changement de méthode, désactivation (mot de passe exigé).
	 */
	public static function handle_gerer() {
		ige_ffm_check_form( '2fa_gerer' );
		$u  = wp_get_current_user();
		$op = ige_ffm_post( 'operation' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$mdp = isset( $_POST['mdp'] ) ? (string) wp_unslash( $_POST['mdp'] ) : '';
		if ( ! $u->exists() || ! wp_check_password( $mdp, $u->user_pass, $u->ID ) ) {
			ige_ffm_flash( 'ko', 'Mot de passe incorrect', '' );
			self::retour();
		}
		if ( 'codes' === $op ) {
			set_transient( 'ige_ffm_2fa_codes_' . $u->ID, IGE_FFM_Deux_Facteurs::nouveaux_codes_secours( $u->ID ), 10 * MINUTE_IN_SECONDS );
			IGE_FFM_Audit::log( 'codes_secours_2fa', 'compte', $u->ID, null, null, $u->ID );
			ige_ffm_flash( 'ok', 'Nouveaux codes de secours', 'Les anciens codes ne fonctionnent plus.' );
		} elseif ( 'changer' === $op ) {
			// Réinitialisation par l'intéressé, puis nouvelle activation (obligatoire ou non).
			foreach ( array( '_ige_2fa_actif', '_ige_2fa_secret', '_ige_2fa_secours', '_ige_2fa_dernier', '_ige_2fa_attente', '_ige_2fa_methode', '_ige_2fa_otp' ) as $k ) {
				delete_user_meta( $u->ID, $k );
			}
			IGE_FFM_Audit::log( 'changement_methode_2fa', 'compte', $u->ID, null, null, $u->ID );
			ige_ffm_flash( 'info', 'Choisissez votre nouvelle méthode', 'La double authentification sera de nouveau active dès la confirmation.' );
		} elseif ( 'desactiver' === $op ) {
			$res = IGE_FFM_Deux_Facteurs::desactiver( $u->ID );
			if ( is_wp_error( $res ) ) {
				ige_ffm_flash( 'ko', 'Désactivation refusée', $res->get_error_message() );
			} else {
				ige_ffm_flash( 'ok', 'Double authentification désactivée', '' );
			}
		}
		self::retour();
	}

	/**
	 * Réinitialisation par l'administration.
	 */
	public static function handle_reinitialiser() {
		ige_ffm_check_form( '2fa_reinitialiser' );
		ige_ffm_require( 'ige_administrer' );
		$uid = (int) ige_ffm_post( 'user_id' );
		if ( $uid === get_current_user_id() ) {
			ige_ffm_flash( 'ko', 'Action refusée', 'Un autre administrateur doit réinitialiser votre propre double authentification.' );
		} else {
			$res = IGE_FFM_Deux_Facteurs::desactiver( $uid, get_current_user_id(), 'Réinitialisation demandée (adresse ou téléphone changé)' );
			if ( is_wp_error( $res ) ) {
				ige_ffm_flash( 'ko', 'Action refusée', $res->get_error_message() );
			} else {
				$u = get_userdata( $uid );
				ige_ffm_flash( 'ok', 'Double authentification réinitialisée pour ' . ( $u ? $u->user_login : $uid ), 'À sa prochaine connexion, la personne l’activera de nouveau.' );
			}
		}
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}
}
