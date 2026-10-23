<?php
/**
 * Accueil = page de connexion (les utilisateurs connectés sont redirigés vers
 * la page d'accueil de leur profil, voir inc/navigation.php).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

if ( is_user_logged_in() ) {
	// Aucune page d'accueil de profil n'existe encore : afficher la page standard.
	get_header();
	ige_ff_title_band( array( 'title' => ige_ff_texte( 'ige_application' ), 'subtitle' => '', 'actions' => '' ) );
	echo '<div class="ige-wrap">';
	echo ige_ff_banner( 'info', __( 'Aucune page d’accueil pour votre profil', 'ige-ff' ), ' ' . __( 'Un administrateur doit créer les pages de l’application (Apparence > Structure IGE).', 'ige-ff' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';
	get_footer();
	return;
}

$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$failed   = isset( $_GET['login'] ) && 'failed' === $_GET['login']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'ige-body-login' ); ?>>
<?php wp_body_open(); ?>
<main id="ige-main" class="ige-login">
	<div class="ige-login__left">
		<?php echo ige_ff_logo( 'couleur', false, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<h1><?php echo esc_html( ige_ff_texte( 'ige_application' ) ); ?></h1>
		<p class="ige-login__lead"><?php echo esc_html( ige_ff_texte( 'ige_accroche' ) ); ?></p>

		<?php if ( $failed ) : ?>
			<div style="max-width:380px;margin-bottom:16px"><?php echo ige_ff_banner( 'ko', __( 'Identifiant ou mot de passe incorrect.', 'ige-ff' ), ' ' . __( 'Après 5 essais, le compte est verrouillé 15 minutes.', 'ige-ff' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
		<?php endif; ?>

		<div class="ige-login__form">
			<?php
			wp_login_form(
				array(
					'redirect'       => $redirect,
					'label_username' => __( 'Identifiant', 'ige-ff' ),
					'label_password' => __( 'Mot de passe', 'ige-ff' ),
					'label_remember' => __( 'Rester connecté sur cet ordinateur', 'ige-ff' ),
					'label_log_in'   => __( 'Se connecter', 'ige-ff' ),
					'remember'       => false,
				)
			);
			?>
			<p class="ige-small ige-muted"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Mot de passe oublié ?', 'ige-ff' ); ?></a> <?php esc_html_e( 'Ou contactez l’administrateur de l’application.', 'ige-ff' ); ?></p>
		</div>
		<div class="ige-login__foot"><?php echo esc_html( ige_ff_texte( 'ige_adresse' ) ); ?></div>
	</div>
	<div class="ige-login__right">
		<?php
		/** Permet à l'extension métier de remplacer le panneau de présentation. */
		if ( has_action( 'ige_ff_login_panel' ) ) {
			do_action( 'ige_ff_login_panel' );
		} else {
			?>
			<h2><?php esc_html_e( 'Une seule base pour tous les services de l’Inspection', 'ige-ff' ); ?></h2>
			<ul>
				<li><b>1</b><?php esc_html_e( 'Chaque service saisit sa caisse et joint ses pièces, même depuis un téléphone.', 'ige-ff' ); ?></li>
				<li><b>2</b><?php esc_html_e( 'Administration et Finance vérifie, valide ou rejette en temps réel.', 'ige-ff' ); ?></li>
				<li><b>3</b><?php esc_html_e( 'Le budget, la caisse et les avances sont suivis sans échange de fichiers.', 'ige-ff' ); ?></li>
				<li><b>4</b><?php esc_html_e( 'Les rapports officiels portent un code QR d’authenticité.', 'ige-ff' ); ?></li>
			</ul>
			<?php
		}
		?>
	</div>
	<?php echo ige_ff_tri(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
