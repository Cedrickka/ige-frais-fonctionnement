<?php
/**
 * Modèle de secours (liste d'articles, archives, recherche).
 * L'application n'utilise pas d'articles ; ce modèle reste sobre et conforme à la charte.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_title_band(
	array(
		'title'    => is_search() ? __( 'Recherche', 'ige-ff' ) : wp_strip_all_tags( get_the_archive_title() ?: get_bloginfo( 'name' ) ),
		'subtitle' => '',
		'actions'  => '',
	)
);
?>
<div class="ige-wrap ige-content">
	<?php if ( have_posts() ) : ?>
		<div class="ige-card">
			<ul class="ige-alert-list">
				<?php
				while ( have_posts() ) :
					the_post();
					?>
					<li><span class="ige-dot" style="background:var(--ige-bleu)"></span><div><a href="<?php the_permalink(); ?>"><b><?php the_title(); ?></b></a><br><span class="ige-small ige-muted"><?php echo esc_html( get_the_date() ); ?></span></div></li>
				<?php endwhile; ?>
			</ul>
		</div>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<div class="ige-card ige-empty"><?php esc_html_e( 'Aucun contenu à afficher.', 'ige-ff' ); ?></div>
	<?php endif; ?>
</div>
<?php
get_footer();
