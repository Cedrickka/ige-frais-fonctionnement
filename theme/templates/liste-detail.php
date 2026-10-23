<?php
/**
 * Template Name: Liste avec panneau de détail
 * Template Post Type: page
 *
 * Liste à gauche (contenu de la page), panneau de détail à droite.
 * Le panneau est rempli par l'extension métier (action « ige_ff_detail_panel »)
 * ou, à défaut, par le champ de page « _ige_panneau ».
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_title_band();
$panneau   = (string) get_post_meta( get_queried_object_id(), '_ige_panneau', true );
$has_panel = has_action( 'ige_ff_detail_panel' ) || '' !== $panneau;
?>
<div class="ige-wrap">
	<div class="ige-grid<?php echo $has_panel ? ' ige-verif' : ''; ?>">
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
		<?php if ( $has_panel ) : ?>
			<aside class="ige-card ige-detail" aria-label="<?php esc_attr_e( 'Détail', 'ige-ff' ); ?>">
				<?php
				if ( has_action( 'ige_ff_detail_panel' ) ) {
					/** Panneau de détail de l'élément sélectionné. */
					do_action( 'ige_ff_detail_panel', get_the_ID() );
				} else {
					echo ige_ff_kses_ui( $panneau ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</aside>
		<?php endif; ?>
	</div>
</div>
<?php
get_footer();
