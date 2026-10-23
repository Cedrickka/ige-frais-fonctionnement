<?php
/**
 * Boîte « Bandeau de titre » dans l'éditeur de pages : sous-titre et boutons d'action.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Déclare les champs de page (protégés par le préfixe « _ »).
 */
function ige_ff_register_meta() {
	foreach ( array( '_ige_sous_titre', '_ige_actions' ) as $key ) {
		register_post_meta(
			'page',
			$key,
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => '_ige_actions' === $key ? 'ige_ff_kses_ui' : 'sanitize_text_field',
				'auth_callback'     => static fn() => current_user_can( 'edit_pages' ),
			)
		);
	}
}
add_action( 'init', 'ige_ff_register_meta' );

/**
 * Ajoute la boîte dans l'éditeur.
 */
function ige_ff_add_meta_box() {
	add_meta_box( 'ige-ff-bandeau', __( 'Bandeau de titre', 'ige-ff' ), 'ige_ff_meta_box', 'page', 'side' );
}
add_action( 'add_meta_boxes', 'ige_ff_add_meta_box' );

/**
 * Contenu de la boîte.
 *
 * @param WP_Post $post Page.
 */
function ige_ff_meta_box( $post ) {
	wp_nonce_field( 'ige_ff_meta', 'ige_ff_meta_nonce' );
	$sub = (string) get_post_meta( $post->ID, '_ige_sous_titre', true );
	$act = (string) get_post_meta( $post->ID, '_ige_actions', true );
	?>
	<p><label for="ige_sous_titre"><strong><?php esc_html_e( 'Sous-titre', 'ige-ff' ); ?></strong></label>
	<input type="text" id="ige_sous_titre" name="ige_sous_titre" value="<?php echo esc_attr( $sub ); ?>" style="width:100%"></p>
	<p><label for="ige_actions"><strong><?php esc_html_e( 'Boutons d’action (HTML)', 'ige-ff' ); ?></strong></label>
	<textarea id="ige_actions" name="ige_actions" rows="4" style="width:100%;font-family:monospace;font-size:11px"><?php echo esc_textarea( $act ); ?></textarea>
	<span class="description"><?php esc_html_e( 'Exemple : <a class="ige-btn ige-btn-jaune" href="…">Exporter</a>. Classes : ige-btn-jaune (action principale), ige-btn-ghost (secondaire).', 'ige-ff' ); ?></span></p>
	<?php
}

/**
 * Enregistre les champs.
 *
 * @param int $post_id Page.
 */
function ige_ff_save_meta( $post_id ) {
	if ( ! isset( $_POST['ige_ff_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ige_ff_meta_nonce'] ) ), 'ige_ff_meta' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_page', $post_id ) ) {
		return;
	}
	update_post_meta( $post_id, '_ige_sous_titre', sanitize_text_field( wp_unslash( $_POST['ige_sous_titre'] ?? '' ) ) );
	update_post_meta( $post_id, '_ige_actions', ige_ff_kses_ui( wp_unslash( $_POST['ige_actions'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
}
add_action( 'save_post_page', 'ige_ff_save_meta' );
