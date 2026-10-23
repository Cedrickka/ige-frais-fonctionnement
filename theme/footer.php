<?php
/**
 * Pied de page de l'application.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;
?>
</main>
<footer class="ige-foot">
	<div class="ige-foot__in">
		<?php echo ige_ff_logo( 'blanc', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span><?php echo esc_html( ige_ff_texte( 'ige_adresse' ) ); ?></span>
		<?php ige_ff_footer_menu(); ?>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
