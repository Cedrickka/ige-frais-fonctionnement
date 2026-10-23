<?php
/**
 * Template Name: Tableau de bord
 * Template Post Type: page
 *
 * Bandeau de titre puis contenu sur toute la largeur de l'application.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_part( 'template-parts/page/content', array( 'wrap_class' => '' ) );
get_footer();
