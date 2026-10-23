<?php
/**
 * Template Name: Administration
 * Template Post Type: page
 *
 * Barre latérale d'administration (emplacement « Barre latérale Administration »)
 * et contenu de la page à droite.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_title_band();
?>
<div class="ige-wrap">
	<div class="ige-adm">
		<?php ige_ff_admin_sidebar(); ?>
		<div class="ige-content">
			<?php
			do_action( 'ige_ff_before_content', get_the_ID() );
			while ( have_posts() ) {
				the_post();
				the_content();
			}
			do_action( 'ige_ff_after_content', get_the_ID() );
			?>
		</div>
	</div>
</div>
<?php
get_footer();
