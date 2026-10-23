<?php
/**
 * Corps commun des pages : bandeau de titre puis contenu dans la largeur de l'application.
 *
 * Arguments : wrap_class (classes supplémentaires du conteneur).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

$wrap_class = $args['wrap_class'] ?? '';
ige_ff_title_band();
?>
<div class="ige-wrap ige-content <?php echo esc_attr( $wrap_class ); ?>">
	<?php
	/** Avant le contenu de la page (messages, filtres de l'extension métier). */
	do_action( 'ige_ff_before_content', get_the_ID() );
	while ( have_posts() ) {
		the_post();
		the_content();
	}
	/** Après le contenu de la page. */
	do_action( 'ige_ff_after_content', get_the_ID() );
	?>
</div>
