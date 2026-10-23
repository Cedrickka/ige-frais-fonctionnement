<?php
/**
 * Template Name: Page publique
 * Template Post Type: page
 *
 * Page accessible sans connexion (ex. vérification d'un document par code QR).
 * Fond blanc, armoiries en couleur, pas de menu.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header( 'public' );
?>
<div class="ige-verify ige-content">
	<?php
	while ( have_posts() ) {
		the_post();
		if ( ! has_action( 'ige_ff_public_content' ) ) {
			echo '<h1>' . esc_html( get_the_title() ) . '</h1>';
			$sub = (string) get_post_meta( get_the_ID(), '_ige_sous_titre', true );
			if ( $sub ) {
				echo '<p class="ige-muted" style="margin:0">' . esc_html( $sub ) . '</p>';
			}
			the_content();
		}
	}
	/** Contenu fourni par l'extension métier (résultat de la vérification). */
	do_action( 'ige_ff_public_content', get_the_ID() );
	?>
</div>
<?php
get_footer( 'public' );
