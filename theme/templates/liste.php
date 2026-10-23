<?php
/**
 * Template Name: Liste
 * Template Post Type: page
 *
 * Bandeau de titre puis tableaux et filtres sur toute la largeur.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_part( 'template-parts/page/content', array( 'wrap_class' => '' ) );
get_footer();
