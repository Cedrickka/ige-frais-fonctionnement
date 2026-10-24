<?php
/**
 * Fonctions du site : Administration et Finance active ou désactive les modules optionnels.
 *
 * Un module désactivé :
 * - disparaît des menus (pages retirées, rubrique de menu vide masquée) ;
 * - affiche, si l'on ouvre directement sa page, « Fonction désactivée » ;
 * - refuse ses formulaires (admin-post) et ses points d'entrée ;
 * - ne produit plus d'alertes ni de bandeaux.
 * Les données déjà enregistrées sont conservées et réapparaissent à la réactivation.
 * Le socle (saisie, livre de caisse, transmission, vérification, avances, arrêtés, rapports)
 * ne se désactive pas.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonctions activables.
 */
final class IGE_FFM_Fonctions {

	const OPTION = 'ige_ffm_fonctions';

	/**
	 * Modules : clé => libellé, description, groupe, pages, actions (admin-post sans le préfixe
	 * « ige_ffm_ » ; une entrée finissant par « * » couvre un préfixe).
	 *
	 * @return array<string,array>
	 */
	public static function modules() {
		return array(
			'demandes_fonds' => array(
				'libelle' => 'Demandes de fonds',
				'desc'    => 'Les services expriment leurs besoins ; visas, accord, décaissement en avance (VA-03).',
				'groupe'  => 'Fonds',
				'pages'   => array( 'mes-demandes-de-fonds', 'demandes-de-fonds' ),
				'actions' => array( 'demande', 'demande_annuler', 'accorder', 'decaisser' ),
			),
			'chaine'         => array(
				'libelle' => 'Chaîne complète de la dépense',
				'desc'    => 'Liquidation puis ordonnancement visé avant paiement au-delà du seuil (VA-10). Désactivée : engagement puis paiement direct.',
				'groupe'  => 'Fonds',
				'pages'   => array( 'dossier-de-depense' ),
				'actions' => array( 'liquider' ),
			),
			'tresorerie'     => array(
				'libelle' => 'Plan de trésorerie',
				'desc'    => 'Disponible, fonds attendus, besoins des prochains mois et alerte de tension (VA-07).',
				'groupe'  => 'Fonds',
				'pages'   => array( 'tresorerie' ),
				'actions' => array( 'prevision', 'prevision_suppr' ),
			),
			'rapprochement'  => array(
				'libelle' => 'Rapprochement bancaire',
				'desc'    => 'Comptes, import des relevés, rapprochement, suspens et état en PDF (VA-06).',
				'groupe'  => 'Fonds',
				'pages'   => array( 'rapprochement-bancaire' ),
				'actions' => array( 'banque_*' ),
			),
			'devise_usd'     => array(
				'libelle' => 'Devise USD (dollar)',
				'desc'    => 'Saisie en dollars convertie au taux du jour, taux du jour, dollars dans l’arrêté de caisse (VA-51). Désactivée : tout se fait en francs congolais ; les écritures déjà saisies en dollars gardent leur mention.',
				'groupe'  => 'Fonds',
				'pages'   => array(),
				'actions' => array( 'taux' ),
			),
			'missions'       => array(
				'libelle' => 'Missions d’inspection',
				'desc'    => 'Ordres de mission, barème, visas, paiement, rapport et liquidation (VA-40).',
				'groupe'  => 'Activités',
				'pages'   => array( 'missions' ),
				'actions' => array( 'mission', 'mission_*', 'bareme' ),
			),
			'parc'           => array(
				'libelle' => 'Parc automobile',
				'desc'    => 'Véhicules, chauffeurs, documents de bord, demandes de véhicule, ordres de route, carburant, entretien, sinistres et coûts (VA-41).',
				'groupe'  => 'Logistique',
				'pages'   => array( 'parc-automobile', 'vehicules' ),
				'actions' => array( 'parc_*' ),
			),
			'stocks'         => array(
				'libelle' => 'Stocks de fournitures',
				'desc'    => 'Articles, entrées, sorties par bureau, seuil de réapprovisionnement, inventaire (VA-42).',
				'groupe'  => 'Logistique',
				'pages'   => array( 'stocks' ),
				'actions' => array( 'stock_*' ),
			),
			'risques'        => array(
				'libelle' => 'Risques et comparaison des services',
				'desc'    => 'Score de risque par service et comparaison des dépenses rapportées au budget et aux effectifs (VA-21, VA-23).',
				'groupe'  => 'Analyses',
				'pages'   => array( 'risques' ),
				'actions' => array(),
			),
			'nomenclature'   => array(
				'libelle' => 'Nomenclature budgétaire de l’État',
				'desc'    => 'Lignes de la nomenclature, rattachement des rubriques et consolidation par ligne (VA-33).',
				'groupe'  => 'Analyses',
				'pages'   => array(),
				'actions' => array(),
			),
			'rapport_hebdo'  => array(
				'libelle' => 'Rapport hebdomadaire par e-mail',
				'desc'    => 'Chaque semaine, Administration et Finance reçoit ses priorités : écritures à vérifier, retards, alertes (VA-34).',
				'groupe'  => 'Analyses',
				'pages'   => array(),
				'actions' => array( 'hebdo_*' ),
			),
			'hors_connexion' => array(
				'libelle' => 'Saisie hors connexion',
				'desc'    => 'Application installable sur téléphone, saisie sans réseau envoyée au retour de la connexion (VA-50).',
				'groupe'  => 'Services',
				'pages'   => array( 'hors-connexion' ),
				'actions' => array(),
			),
		);
	}

	/**
	 * État de tous les modules.
	 *
	 * @return array<string,bool>
	 */
	public static function etats() {
		$o   = get_option( self::OPTION, array() );
		$o   = is_array( $o ) ? $o : array();
		$out = array();
		foreach ( array_keys( self::modules() ) as $k ) {
			$out[ $k ] = array_key_exists( $k, $o ) ? (bool) $o[ $k ] : true;
		}
		return $out;
	}

	/**
	 * Le module est-il actif ?
	 *
	 * @param string $module Module.
	 * @return bool
	 */
	public static function active( $module ) {
		$e = self::etats();
		return $e[ $module ] ?? true;
	}

	/**
	 * Module d'une page (null si elle n'appartient à aucun module).
	 *
	 * @param string $slug Page.
	 * @return string|null
	 */
	public static function module_de_page( $slug ) {
		foreach ( self::modules() as $k => $m ) {
			if ( in_array( $slug, $m['pages'], true ) ) {
				return $k;
			}
		}
		if ( 'visas' === $slug && ! self::active( 'demandes_fonds' ) && ! self::active( 'chaine' ) && ! self::active( 'missions' ) ) {
			return 'visas';
		}
		return null;
	}

	/**
	 * La page est-elle désactivée ?
	 *
	 * @param string $slug Page.
	 * @return bool
	 */
	public static function page_inactive( $slug ) {
		$m = self::module_de_page( $slug );
		return 'visas' === $m || ( $m && ! self::active( $m ) );
	}

	/**
	 * Module d'une action admin-post (sans préfixe).
	 *
	 * @param string $action Action.
	 * @return string|null
	 */
	public static function module_de_action( $action ) {
		foreach ( self::modules() as $k => $m ) {
			foreach ( $m['actions'] as $a ) {
				if ( $a === $action || ( '*' === substr( $a, -1 ) && 0 === strpos( $action, substr( $a, 0, -1 ) ) ) ) {
					return $k;
				}
			}
		}
		return null;
	}

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'wp_get_nav_menu_items', array( __CLASS__, 'filtrer_menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'bloquer_action' ), 0 );
		add_action( 'admin_post_ige_ffm_fonctions', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Retire des menus les pages des modules désactivés et les rubriques devenues vides.
	 *
	 * @param WP_Post[] $items Éléments.
	 * @return WP_Post[]
	 */
	public static function filtrer_menu( $items ) {
		if ( ! is_array( $items ) || is_admin() ) {
			return $items;
		}
		$retires = array();
		foreach ( $items as $i => $it ) {
			if ( 'post_type' === $it->type && 'page' === $it->object ) {
				$p = get_post( (int) $it->object_id );
				if ( $p && self::page_inactive( $p->post_name ) ) {
					$retires[] = (int) $it->ID;
					unset( $items[ $i ] );
				}
			}
		}
		if ( ! $retires ) {
			return $items;
		}
		// Rubriques sans lien (« # ») qui n'ont plus d'enfant.
		$parents = array();
		foreach ( $items as $it ) {
			$parents[ (int) $it->menu_item_parent ] = true;
		}
		foreach ( $items as $i => $it ) {
			$vide = 'custom' === $it->type && in_array( $it->url, array( '#', '' ), true ) && empty( $parents[ (int) $it->ID ] );
			if ( $vide ) {
				unset( $items[ $i ] );
			}
		}
		return array_values( $items );
	}

	/**
	 * Refuse les formulaires d'un module désactivé.
	 */
	public static function bloquer_action() {
		if ( ! wp_doing_ajax() && false === strpos( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ), 'admin-post.php' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $action, 'ige_ffm_' ) ) {
			return;
		}
		$m = self::module_de_action( substr( $action, 8 ) );
		if ( $m && ! self::active( $m ) ) {
			wp_die( esc_html( 'Cette fonction (« ' . self::modules()[ $m ]['libelle'] . ' ») est désactivée par Administration et Finance.' ), 'Fonction désactivée', array( 'response' => 403, 'back_link' => true ) );
		}
	}

	/**
	 * Message affiché à la place d'un écran désactivé.
	 *
	 * @param string $slug Page.
	 * @return string
	 */
	public static function message( $slug ) {
		$m   = self::module_de_page( $slug );
		$lib = isset( self::modules()[ $m ] ) ? self::modules()[ $m ]['libelle'] : 'Visas';
		$txt = ' La fonction « ' . esc_html( $lib ) . ' » n’est pas utilisée pour le moment. Les données déjà enregistrées sont conservées.';
		if ( current_user_can( 'ige_administrer' ) ) {
			$txt .= ' <a href="' . esc_url( ige_ffm_url( 'fonctions-du-site' ) ) . '">Fonctions du site</a>.';
		}
		return ige_ffm_banner( 'info', 'Fonction désactivée', $txt );
	}

	/**
	 * Enregistre les états.
	 *
	 * @param array    $in      clé => bool.
	 * @param int|null $user_id Auteur.
	 * @return array Modules changés.
	 */
	public static function enregistrer( array $in, $user_id = null ) {
		$avant = self::etats();
		$apres = array();
		foreach ( array_keys( self::modules() ) as $k ) {
			$apres[ $k ] = ! empty( $in[ $k ] );
		}
		update_option( self::OPTION, $apres, true );
		$chg = array();
		foreach ( $apres as $k => $v ) {
			if ( $avant[ $k ] !== $v ) {
				$chg[ $k ] = $v;
			}
		}
		if ( $chg ) {
			$lib = array();
			foreach ( $chg as $k => $v ) {
				$lib[ self::modules()[ $k ]['libelle'] ] = $v ? 'activée' : 'désactivée';
			}
			IGE_FFM_Audit::log( 'fonctions_du_site', 'reglages', 'fonctions', array_map( static fn( $v ) => $v ? 'activée' : 'désactivée', array_intersect_key( $avant, $chg ) ), $lib, $user_id );
		}
		return $chg;
	}

	/**
	 * Traitement du formulaire.
	 */
	public static function handle() {
		ige_ffm_check_form( 'fonctions' );
		ige_ffm_require( 'ige_administrer' );
		$in  = isset( $_POST['fonction'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fonction'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$chg = self::enregistrer( array_fill_keys( $in, true ) );
		ige_ffm_flash( 'ok', $chg ? count( $chg ) . ' fonction(s) modifiée(s)' : 'Aucun changement', $chg ? 'Les menus sont mis à jour pour tous les utilisateurs.' : '' );
		wp_safe_redirect( ige_ffm_url( 'fonctions-du-site' ) );
		exit;
	}
}
