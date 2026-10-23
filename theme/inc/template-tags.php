<?php
/**
 * Fonctions d'affichage réutilisables par les modèles du thème et par l'extension métier.
 * Toutes renvoient du HTML conforme à la charte (classes ige-*).
 *
 * @package IGE_FF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bloc logotype de la charte : bloc-armoirie, Ligne d'État, intitulé officiel.
 *
 * @param string $variante « couleur » (fond blanc) ou « blanc » (fond bleu, règle de la charte).
 * @param bool   $petit    Taille réduite pour l'en-tête (environ 50 px).
 * @param bool   $lien     Envelopper dans un lien vers l'accueil.
 * @return string
 */
function ige_ff_logo( $variante = 'couleur', $petit = false, $lien = true ) {
	$classes = array( 'ige-logo' );
	if ( $petit ) {
		$classes[] = 'ige-sm';
	}
	if ( 'blanc' === $variante ) {
		$classes[] = 'ige-blanc';
	}
	$ministere = nl2br( esc_html( ige_ff_texte( 'ige_ministere' ) ) );
	$entite    = esc_html( ige_ff_texte( 'ige_entite' ) );
	$inner     = '<span class="ige-armoirie" role="img" aria-label="' . esc_attr__( 'Armoiries de la République Démocratique du Congo', 'ige-ff' ) . '"></span>'
		. '<span class="ige-ligne-etat" aria-hidden="true"></span>'
		. '<span class="ige-intitule">' . $ministere . '<small>' . $entite . '</small></span>';
	$tag     = $lien ? 'a' : 'div';
	$href    = $lien ? ' href="' . esc_url( home_url( '/' ) ) . '"' : '';
	return '<' . $tag . ' class="' . esc_attr( implode( ' ', $classes ) ) . '"' . $href . '>' . $inner . '</' . $tag . '>';
}

/**
 * Bande tricolore (Ligne d'État horizontale).
 *
 * @return string
 */
function ige_ff_tri() {
	return '<div class="ige-tri" aria-hidden="true"></div>';
}

/**
 * Bandeau de titre de page : titre, sous-titre, boutons d'action.
 *
 * Le sous-titre et les boutons viennent des champs de la page (boîte « Bandeau de titre »)
 * ou du filtre « ige_ff_title_band » utilisé par l'extension métier.
 *
 * @param array $args { title, subtitle, actions (HTML) }.
 */
function ige_ff_title_band( $args = array() ) {
	$post_id  = get_queried_object_id();
	$defaults = array(
		'title'    => $post_id ? get_the_title( $post_id ) : wp_get_document_title(),
		'subtitle' => $post_id ? (string) get_post_meta( $post_id, '_ige_sous_titre', true ) : '',
		'actions'  => $post_id ? (string) get_post_meta( $post_id, '_ige_actions', true ) : '',
	);
	/** Filtre le contenu du bandeau de titre. */
	$args = apply_filters( 'ige_ff_title_band', wp_parse_args( $args, $defaults ), $post_id );
	?>
	<div class="ige-title-band">
		<div class="ige-title-band__in">
			<div>
				<h1><?php echo esc_html( $args['title'] ); ?></h1>
				<?php if ( $args['subtitle'] ) : ?>
					<p><?php echo esc_html( $args['subtitle'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( $args['actions'] ) : ?>
				<div class="ige-title-band__actions"><?php echo ige_ff_kses_ui( $args['actions'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

/**
 * Pastille d'état avec libellé (la couleur seule ne porte jamais l'information).
 *
 * @param string $libelle Texte.
 * @param string $etat    ok | wait | ko | info | neutre.
 * @return string
 */
function ige_ff_pill( $libelle, $etat = 'neutre' ) {
	$etat = in_array( $etat, array( 'ok', 'wait', 'ko', 'info', 'neutre' ), true ) ? $etat : 'neutre';
	return '<span class="ige-pill ige-p-' . esc_attr( $etat ) . '">' . esc_html( $libelle ) . '</span>';
}

/**
 * Bannière de message.
 *
 * @param string $type  ok | info | warn | ko.
 * @param string $titre Titre en gras.
 * @param string $texte Texte (HTML simple autorisé).
 * @return string
 */
function ige_ff_banner( $type, $titre, $texte = '' ) {
	$icons = array(
		'ok'   => '✓',
		'info' => 'i',
		'warn' => '!',
		'ko'   => '!',
	);
	$type = isset( $icons[ $type ] ) ? $type : 'info';
	$role = 'ko' === $type ? 'alert' : 'status';
	return '<div class="ige-banner ige-' . esc_attr( $type ) . '" role="' . $role . '"><span class="ige-ic" aria-hidden="true">' . $icons[ $type ] . '</span><div><b>' . esc_html( $titre ) . '</b>' . wp_kses_post( $texte ) . '</div></div>';
}

/**
 * Carte d'indicateur.
 *
 * @param array $args { label, value, unit, meta, tone (accent|ok|alerte|risque), percent }.
 * @return string
 */
function ige_ff_kpi( $args ) {
	$a    = wp_parse_args(
		$args,
		array(
			'label'   => '',
			'value'   => '',
			'unit'    => '',
			'meta'    => '',
			'tone'    => 'accent',
			'percent' => null,
		)
	);
	$html = '<div class="ige-card ige-kpi ige-' . esc_attr( $a['tone'] ) . '">';
	$html .= '<div class="ige-lbl">' . esc_html( $a['label'] ) . '</div>';
	$html .= '<div class="ige-val">' . esc_html( $a['value'] ) . ( $a['unit'] ? ' <span>' . esc_html( $a['unit'] ) . '</span>' : '' ) . '</div>';
	if ( null !== $a['percent'] ) {
		$p     = max( 0, min( 100, (float) $a['percent'] ) );
		$html .= '<div class="ige-bar" role="img" aria-label="' . esc_attr( round( $p ) . ' %' ) . '"><i style="width:' . esc_attr( $p ) . '%"></i></div>';
	}
	if ( $a['meta'] ) {
		$html .= '<div class="ige-meta">' . esc_html( $a['meta'] ) . '</div>';
	}
	return $html . '</div>';
}

/**
 * Montant au format de l'application : 1 234 567 CDF (espace fine insécable).
 *
 * @param float  $montant Montant.
 * @param string $devise  Devise.
 * @param int    $dec     Décimales.
 * @return string
 */
function ige_ff_montant( $montant, $devise = 'CDF', $dec = 0 ) {
	$txt = number_format( (float) $montant, $dec, ',', "\u{202F}" );
	return $devise ? $txt . "\u{00A0}" . $devise : $txt;
}

/**
 * Nombre de notifications non lues (fourni par l'extension métier).
 *
 * @return int
 */
function ige_ff_notifications_count() {
	return (int) apply_filters( 'ige_ff_notifications_count', 0, get_current_user_id() );
}

/**
 * URL de la page des notifications (fournie par l'extension métier).
 *
 * @return string
 */
function ige_ff_notifications_url() {
	return (string) apply_filters( 'ige_ff_notifications_url', '#' );
}

/**
 * Rend un modèle partiel du thème en lui passant des variables.
 *
 * @param string $slug Chemin relatif (ex. « template-parts/page/kpis »).
 * @param array  $args Variables.
 */
function ige_ff_part( $slug, $args = array() ) {
	get_template_part( $slug, null, $args );
}

/**
 * Filtre HTML des composants d'interface : balises de contenu autorisées par WordPress,
 * plus les éléments de formulaire et les attributs d'accessibilité.
 *
 * @param string $html HTML.
 * @return string
 */
function ige_ff_kses_ui( $html ) {
	static $allowed = null;
	if ( null === $allowed ) {
		$common  = array(
			'class'            => true,
			'id'               => true,
			'style'            => true,
			'role'             => true,
			'title'            => true,
			'aria-label'       => true,
			'aria-hidden'      => true,
			'aria-current'     => true,
			'aria-expanded'    => true,
			'aria-describedby' => true,
			'data-ige'         => true,
		);
		$allowed = wp_kses_allowed_html( 'post' );
		foreach ( array( 'div', 'span', 'section', 'aside', 'nav', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'b', 'i', 'em', 'small', 'p', 'h2', 'h3', 'a', 'label' ) as $tag ) {
			$allowed[ $tag ] = array_merge( $allowed[ $tag ] ?? array(), $common );
		}
		$allowed['a']        = array_merge( $allowed['a'], array( 'href' => true, 'target' => true, 'rel' => true ) );
		$allowed['label']    = array_merge( $allowed['label'], array( 'for' => true ) );
		$allowed['button']   = array_merge( $common, array( 'type' => true, 'name' => true, 'value' => true, 'disabled' => true, 'form' => true ) );
		$allowed['input']    = array_merge( $common, array( 'type' => true, 'name' => true, 'value' => true, 'checked' => true, 'disabled' => true, 'placeholder' => true, 'readonly' => true, 'required' => true, 'min' => true, 'max' => true, 'step' => true, 'inputmode' => true, 'autocomplete' => true ) );
		$allowed['select']   = array_merge( $common, array( 'name' => true, 'disabled' => true, 'required' => true ) );
		$allowed['option']   = array( 'value' => true, 'selected' => true );
		$allowed['textarea'] = array_merge( $common, array( 'name' => true, 'rows' => true, 'disabled' => true, 'placeholder' => true, 'required' => true ) );
		$allowed['form']     = array_merge( $common, array( 'action' => true, 'method' => true, 'enctype' => true ) );
	}
	return wp_kses( (string) $html, $allowed );
}

/**
 * Date en toutes lettres, en français, quelle que soit la langue du site.
 *
 * @param int|null $timestamp Horodatage (maintenant par défaut).
 * @return string Exemple : « 23 octobre 2026 ».
 */
function ige_ff_date_fr( $timestamp = null ) {
	$mois = array( 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
	$ts   = $timestamp ?? time();
	$jour = (int) wp_date( 'j', $ts );
	return ( 1 === $jour ? '1er' : $jour ) . ' ' . $mois[ (int) wp_date( 'n', $ts ) - 1 ] . ' ' . wp_date( 'Y', $ts );
}
