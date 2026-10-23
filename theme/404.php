<?php
/**
 * Page introuvable.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_title_band(
	array(
		'title'    => __( 'Page introuvable', 'ige-ff' ),
		'subtitle' => __( 'Erreur 404', 'ige-ff' ),
		'actions'  => '',
	)
);
?>
<div class="ige-wrap">
	<div class="ige-card ige-empty">
		<p><?php esc_html_e( 'Cette page n’existe pas ou a été déplacée.', 'ige-ff' ); ?></p>
		<p><a class="ige-btn ige-btn-bleu" href="<?php echo esc_url( ige_ff_dashboard_url() ?: home_url( '/' ) ); ?>"><?php esc_html_e( 'Revenir à l’accueil', 'ige-ff' ); ?></a></p>
	</div>
</div>
<?php
get_footer();
