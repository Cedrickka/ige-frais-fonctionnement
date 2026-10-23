<?php
/**
 * Template Name: Guide des composants
 * Template Post Type: page
 *
 * Référence visuelle de la bibliothèque de composants (classes ige-*), pour les développeurs
 * de l'extension métier et pour la recette de la charte.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_title_band(
	array(
		'title'    => __( 'Guide des composants', 'ige-ff' ),
		'subtitle' => __( 'Bibliothèque d’interface conforme à la charte graphique du Gouvernement', 'ige-ff' ),
		'actions'  => '<button class="ige-btn ige-btn-ghost" type="button">' . esc_html__( 'Action secondaire', 'ige-ff' ) . '</button><button class="ige-btn ige-btn-jaune" type="button">' . esc_html__( 'Action principale', 'ige-ff' ) . '</button>',
	)
);
?>
<div class="ige-wrap">
	<?php get_template_part( 'template-parts/guide/components' ); ?>
</div>
<?php
get_footer();
