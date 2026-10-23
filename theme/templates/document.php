<?php
/**
 * Template Name: Document officiel
 * Template Post Type: page
 *
 * Document A4 au format de la charte : en-tête officiel (armoiries, Ligne d'État, intitulé),
 * référence et date, contenu, signatures, pied de page avec code QR d'authenticité.
 * Imprimable tel quel (Ctrl+P) ; la même structure sert de gabarit aux PDF de l'extension.
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

get_header();
$post_id = get_queried_object_id();
/** Référence affichée en haut à droite (par défaut : le sous-titre de la page). */
$reference = (string) apply_filters( 'ige_ff_document_reference', get_post_meta( $post_id, '_ige_sous_titre', true ), $post_id );
?>
<div class="ige-row ige-no-print ige-wrap" style="padding-bottom:0">
	<button type="button" class="ige-btn ige-btn-line" data-ige-print><?php esc_html_e( 'Imprimer ou enregistrer en PDF', 'ige-ff' ); ?></button>
</div>
<div class="ige-doc-wrap">
	<article class="ige-pdf">
		<div class="ige-hd">
			<?php echo ige_ff_logo( 'couleur', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="ige-ref">
				<?php echo esc_html( $reference ); ?><br>
				<?php
				/* translators: %s: date du jour. */
				printf( esc_html__( 'Kinshasa, le %s', 'ige-ff' ), esc_html( ige_ff_date_fr() ) );
				?>
			</div>
		</div>
		<h1 class="ige-doc-title"><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>
		<div class="ige-content">
			<?php
			while ( have_posts() ) {
				the_post();
				the_content();
			}
			?>
		</div>
		<div class="ige-ft">
			<?php echo ige_ff_tri(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="ige-qr">
				<?php
				if ( has_action( 'ige_ff_document_qr' ) ) {
					/** Code QR d'authenticité (SVG) fourni par l'extension métier. */
					do_action( 'ige_ff_document_qr', $post_id );
				} else {
					esc_html_e( 'Code QR', 'ige-ff' );
				}
				?>
			</div>
			<div>
				<?php esc_html_e( 'Document authentifiable : scannez le code ou saisissez sa référence sur la page de vérification de l’application.', 'ige-ff' ); ?><br>
				<?php echo esc_html( ige_ff_texte( 'ige_entite' ) . ' · ' . ige_ff_texte( 'ige_adresse' ) ); ?>
			</div>
		</div>
	</article>
</div>
<?php
get_footer();
