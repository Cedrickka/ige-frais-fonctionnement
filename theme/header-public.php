<?php
/**
 * En-tête des pages publiques (vérification d'un document) : fond blanc, logo en couleur,
 * sans menu. Règle de la charte : sur fond blanc, les armoiries sont en couleur.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'ige-body-public' ); ?>>
<?php wp_body_open(); ?>
<a class="ige-skip" href="#ige-main"><?php esc_html_e( 'Aller au contenu', 'ige-ff' ); ?></a>
<div class="ige-public">
	<header class="ige-public-head">
		<?php echo ige_ff_logo( 'couleur' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</header>
	<main id="ige-main" class="ige-public-main" tabindex="-1">
