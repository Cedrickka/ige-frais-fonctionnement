<?php
/**
 * Écrans de l'application, affichés dans les pages créées par le thème (Apparence > Structure IGE).
 *
 * Chaque écran est rattaché à une page par son identifiant (slug) et protégé par une capacité.
 * Le contenu de démonstration de la page est remplacé par l'écran réel.
 * Les formulaires passent par admin-post.php : vérification du jeton, de la capacité,
 * enregistrement, journal d'audit, puis redirection vers l'écran avec un message.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/ecrans/class-ecran-referentiels.php';
require_once __DIR__ . '/ecrans/class-ecran-comptes.php';
require_once __DIR__ . '/ecrans/class-ecran-parametres.php';
require_once __DIR__ . '/ecrans/class-ecran-audit.php';
require_once __DIR__ . '/ecrans/class-ecran-premiere-connexion.php';

/**
 * Routeur des écrans.
 */
final class IGE_FFM_Ecrans {

	/**
	 * Écrans disponibles : slug => [ capacité, classe ].
	 *
	 * @return array<string,array>
	 */
	public static function ecrans() {
		return array(
			'referentiels'       => array( 'ige_administrer', 'IGE_FFM_Ecran_Referentiels' ),
			'comptes'            => array( 'ige_administrer', 'IGE_FFM_Ecran_Comptes' ),
			'parametres'         => array( 'ige_administrer', 'IGE_FFM_Ecran_Parametres' ),
			'journal-audit'      => array( 'ige_voir_audit', 'IGE_FFM_Ecran_Audit' ),
			'premiere-connexion' => array( 'read', 'IGE_FFM_Ecran_Premiere_Connexion' ),
		);
	}

	/**
	 * Pages dont l'écran réel arrive dans une étape ultérieure : slug => libellé de l'étape.
	 *
	 * @return array<string,string>
	 */
	public static function a_venir() {
		$e2 = 'étape 2 du lot 1 (saisie)';
		$e3 = 'étape 3 du lot 1 (circuit avec le central)';
		$e4 = 'étape 4 du lot 1 (fonds et caisse)';
		$e5 = 'étape 5 du lot 1 (pilotage)';
		$l2 = 'lot 2';
		return array(
			'accueil-service'        => $e2,
			'nouvelle-ecriture'      => $e2,
			'livre-de-caisse'        => $e2,
			'budget'                 => $e2,
			'fournisseurs'           => $e2,
			'verification'           => $e3,
			'dossier-de-depense'     => $l2,
			'transmission'           => $e3,
			'avances'                => $e4,
			'caisses'                => $e4,
			'arrete-de-caisse'       => $e4,
			'tableau-de-bord'        => $e5,
			'consolidation'          => $e5,
			'rapports'               => $e5,
			'modele-de-rapport'      => $e5,
			'synthese'               => $e5,
			'verifier-un-document'   => $e5,
			'demandes-de-fonds'      => $l2,
			'mes-demandes-de-fonds'  => $l2,
			'tresorerie'             => $l2,
			'rapprochement-bancaire' => $l2,
			'missions'               => $l2,
			'parc-automobile'        => $l2,
			'stocks'                 => $l2,
			'risques'                => $l2,
			'hors-connexion'         => $l2,
			'notifications'          => $l2,
		);
	}

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'content' ), 1000 );
		add_filter( 'ige_ff_title_band', array( __CLASS__, 'title_band' ), 10, 2 );
		foreach ( self::ecrans() as $def ) {
			call_user_func( array( $def[1], 'register' ) );
		}
	}

	/**
	 * Identifiant de la page affichée.
	 *
	 * @return string
	 */
	private static function slug() {
		$post = get_queried_object();
		return $post instanceof WP_Post && 'page' === $post->post_type ? $post->post_name : '';
	}

	/**
	 * Remplace le contenu de la page par l'écran réel.
	 *
	 * @param string $content Contenu.
	 * @return string
	 */
	public static function content( $content ) {
		if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$slug   = self::slug();
		$ecrans = self::ecrans();
		if ( isset( $ecrans[ $slug ] ) ) {
			list( $cap, $class ) = $ecrans[ $slug ];
			if ( ! current_user_can( $cap ) ) {
				return ige_ffm_banner( 'ko', 'Accès refusé', ' Cet écran est réservé à un autre profil.' );
			}
			return ige_ffm_flash_render() . call_user_func( array( $class, 'render' ) );
		}
		$a_venir = self::a_venir();
		if ( isset( $a_venir[ $slug ] ) && is_user_logged_in() ) {
			$note = ige_ffm_banner( 'warn', 'Écran en préparation', ' ' . esc_html( sprintf( 'Il sera fonctionnel à l’%s. Les données affichées ci-dessous sont fictives.', $a_venir[ $slug ] ) ) );
			return $note . $content;
		}
		return $content;
	}

	/**
	 * Bandeau de titre des écrans réels.
	 *
	 * @param array $args    Titre, sous-titre, actions.
	 * @param int   $post_id Page.
	 * @return array
	 */
	public static function title_band( $args, $post_id ) {
		$post   = get_post( $post_id );
		$ecrans = self::ecrans();
		if ( $post && isset( $ecrans[ $post->post_name ] ) && current_user_can( $ecrans[ $post->post_name ][0] ) ) {
			$class = $ecrans[ $post->post_name ][1];
			if ( method_exists( $class, 'title_band' ) ) {
				$args = call_user_func( array( $class, 'title_band' ), $args );
			}
		}
		return $args;
	}

	/**
	 * Mémorise les valeurs saisies pour réafficher un formulaire refusé.
	 *
	 * @param array $data Valeurs.
	 */
	public static function remember( array $data ) {
		set_transient( 'ige_ffm_old_' . get_current_user_id(), $data, 120 );
	}

	/**
	 * Valeurs mémorisées (lues une seule fois).
	 *
	 * @return array
	 */
	public static function old() {
		static $old = null;
		if ( null === $old ) {
			$key = 'ige_ffm_old_' . get_current_user_id();
			$old = get_transient( $key ) ?: array();
			delete_transient( $key );
		}
		return $old;
	}

	/**
	 * Champ de formulaire standard.
	 *
	 * @param string $name  Nom.
	 * @param string $label Libellé.
	 * @param string $value Valeur.
	 * @param array  $o     Options : type, help, required, readonly, options (select), attrs.
	 * @return string
	 */
	public static function field( $name, $label, $value = '', array $o = array() ) {
		$id    = 'ige-' . sanitize_html_class( $name );
		$type  = $o['type'] ?? 'text';
		$req   = ! empty( $o['required'] ) ? ' required' : '';
		$ro    = ! empty( $o['readonly'] ) ? ' readonly' : '';
		$attrs = $o['attrs'] ?? '';
		$html  = '<div class="ige-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		if ( 'select' === $type ) {
			$html .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $req . '>';
			foreach ( $o['options'] as $k => $l ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( (string) $value, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			$html .= '</select>';
		} elseif ( 'checkbox' === $type ) {
			$html = '<div class="ige-field"><label class="ige-row" style="font-weight:500"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . ' style="width:auto"> ' . esc_html( $label ) . '</label>';
		} else {
			$html .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $req . $ro . ' ' . $attrs . '>';
		}
		if ( ! empty( $o['help'] ) ) {
			$html .= '<span class="ige-help">' . esc_html( $o['help'] ) . '</span>';
		}
		return $html . '</div>';
	}

	/**
	 * Bouton de formulaire autonome (action sur une ligne).
	 *
	 * @param string $action  Action.
	 * @param array  $hidden  Champs cachés.
	 * @param string $label   Libellé.
	 * @param string $class   Classe du bouton.
	 * @param string $confirm Message de confirmation (vide pour aucun).
	 * @return string
	 */
	public static function action_button( $action, array $hidden, $label, $class = 'ige-act', $confirm = '' ) {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( $confirm ? ' data-ige-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		$html .= ige_ffm_form_fields( $action );
		foreach ( $hidden as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$style = 'ige-act' === $class ? ' style="background:none;border:0;padding:0;cursor:pointer;font:inherit"' : '';
		return $html . '<button type="submit" class="' . esc_attr( $class ) . '"' . $style . '>' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * Ouvre un formulaire vers admin-post.php.
	 *
	 * @param string $action Action.
	 * @param string $attrs  Attributs supplémentaires.
	 * @return string
	 */
	public static function form_open( $action, $attrs = '' ) {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" ' . $attrs . '>' . ige_ffm_form_fields( $action );
	}
}

/**
 * Confirmation des actions sensibles (désactivation, réinitialisation).
 */
add_action(
	'wp_footer',
	static function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		echo "<script>document.addEventListener('submit',function(e){var f=e.target.closest('form[data-ige-confirm]');if(f&&!window.confirm(f.getAttribute('data-ige-confirm'))){e.preventDefault();}});</script>\n";
	},
	99
);
