<?php
/**
 * Textes institutionnels modifiables dans Apparence > Personnaliser > Identité IGE.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valeurs par défaut des textes institutionnels.
 *
 * @return array<string,string>
 */
function ige_ff_defaults() {
	return array(
		'ige_ministere'   => "Ministère de l'Éducation Nationale\net Nouvelle Citoyenneté",
		'ige_entite'      => "Inspection Générale de l'Enseignement",
		'ige_application' => 'Gestion des frais de fonctionnement',
		'ige_adresse'     => 'Kinshasa · République Démocratique du Congo',
		'ige_accroche'    => "Espace réservé aux agents, responsables de service et au service Administration et Finance.",
	);
}

/**
 * Lit un texte institutionnel.
 *
 * @param string $key Clé (ige_ministere, ige_entite, ige_application, ige_adresse, ige_accroche).
 * @return string
 */
function ige_ff_texte( $key ) {
	$defaults = ige_ff_defaults();
	return (string) get_theme_mod( $key, $defaults[ $key ] ?? '' );
}

/**
 * Déclare la section de l'outil de personnalisation.
 *
 * @param WP_Customize_Manager $wp_customize Gestionnaire.
 */
function ige_ff_customize_register( $wp_customize ) {
	$wp_customize->add_section(
		'ige_identite',
		array(
			'title'       => __( 'Identité IGE', 'ige-ff' ),
			'description' => __( "Intitulé officiel affiché à droite de la Ligne d'État. La charte impose une taille homogène : ne pas ajouter de mise en forme.", 'ige-ff' ),
			'priority'    => 20,
		)
	);

	$labels = array(
		'ige_ministere'   => __( 'Intitulé du ministère (deux lignes possibles)', 'ige-ff' ),
		'ige_entite'      => __( "Nom de l'entité", 'ige-ff' ),
		'ige_application' => __( "Nom de l'application", 'ige-ff' ),
		'ige_adresse'     => __( 'Adresse (pied de page)', 'ige-ff' ),
		'ige_accroche'    => __( 'Texte de la page de connexion', 'ige-ff' ),
	);

	foreach ( ige_ff_defaults() as $key => $default ) {
		$wp_customize->add_setting(
			$key,
			array(
				'default'           => $default,
				'sanitize_callback' => 'sanitize_textarea_field',
			)
		);
		$wp_customize->add_control(
			$key,
			array(
				'label'   => $labels[ $key ],
				'section' => 'ige_identite',
				'type'    => 'ige_ministere' === $key || 'ige_accroche' === $key ? 'textarea' : 'text',
			)
		);
	}
}
add_action( 'customize_register', 'ige_ff_customize_register' );
