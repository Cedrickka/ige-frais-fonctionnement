<?php
/**
 * En-tête de l'application : logo de la charte, outils utilisateur, menu du profil,
 * Ligne d'État horizontale, barre secondaire de la rubrique courante.
 *
 * Les modèles « Page publique » et l'accueil (connexion) utilisent leur propre en-tête :
 * get_header( 'public' ).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;
$user = wp_get_current_user();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="ige-skip" href="#ige-main"><?php esc_html_e( 'Aller au contenu', 'ige-ff' ); ?></a>

<header class="ige-head">
	<div class="ige-head__top">
		<?php echo ige_ff_logo( 'blanc', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<div class="ige-head__tools">
			<?php if ( $user->exists() ) : ?>
				<?php $count = ige_ff_notifications_count(); ?>
				<a class="ige-bell" href="<?php echo esc_url( ige_ff_notifications_url() ); ?>">
					<?php esc_html_e( 'Notifications', 'ige-ff' ); ?>
					<?php if ( $count > 0 ) : ?>
						<b><?php echo esc_html( $count ); ?></b><span class="screen-reader-text"><?php esc_html_e( 'non lues', 'ige-ff' ); ?></span>
					<?php endif; ?>
				</a>
				<div class="ige-user">
					<span class="ige-avatar" aria-hidden="true"><?php echo esc_html( ige_ff_initiales( $user ) ); ?></span>
					<span class="ige-user__txt">
						<?php echo esc_html( $user->user_login ); ?><br>
						<span class="ige-role"><?php echo esc_html( ige_ff_profil_libelle( $user ) ); ?></span>
						· <a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Se déconnecter', 'ige-ff' ); ?></a>
					</span>
				</div>
			<?php endif; ?>
			<button type="button" class="ige-menu-toggle" aria-expanded="false" aria-controls="ige-nav"><?php esc_html_e( 'Menu', 'ige-ff' ); ?></button>
		</div>
	</div>

	<nav id="ige-nav" class="ige-nav" aria-label="<?php esc_attr_e( 'Navigation principale', 'ige-ff' ); ?>">
		<?php ige_ff_primary_menu(); ?>
		<?php if ( $user->exists() ) : ?>
			<div class="ige-nav__user">
				<span><?php echo esc_html( $user->user_login . ' · ' . ige_ff_profil_libelle( $user ) ); ?></span>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Se déconnecter', 'ige-ff' ); ?></a>
			</div>
		<?php endif; ?>
	</nav>
</header>
<?php
echo ige_ff_tri(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
ige_ff_sub_bar();
?>
<main id="ige-main" class="ige-main" tabindex="-1">
