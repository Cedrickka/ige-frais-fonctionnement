<?php
/**
 * Template Name: Formulaire
 * Template Post Type: page
 *
 * Bandeau de titre puis contenu dans une colonne étroite (saisie, téléphone).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
ige_ff_part( 'template-parts/page/content', array( 'wrap_class' => 'ige-narrow' ) );
get_footer();
