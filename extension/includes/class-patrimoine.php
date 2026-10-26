<?php
/**
 * Inventaire des services (patrimoine) : biens mobiliers et équipements comptés par local
 * (bureau, pool, secrétariat…) dans chaque service, sur le modèle du fichier
 * « INVENTAIRE DE SERVICE » d'Administration et Finance.
 *
 * - Catalogue commun des biens (désignation, catégorie), sans doublon de désignation.
 * - Locaux propres à chaque service (colonnes de l'inventaire).
 * - Inventaire = un comptage daté d'un service : brouillon modifiable, puis validé (figé).
 * - Import d'un classeur Excel au format du fichier existant, export Excel et PDF.
 *
 * Réservé à la capacité ige_logistique (Administration et Finance).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inventaire des services.
 */
final class IGE_FFM_Patrimoine {

	/**
	 * Catégories proposées pour les biens.
	 *
	 * @return string[]
	 */
	public static function categories() {
		return array( 'Mobilier', 'Informatique et bureautique', 'Climatisation et électroménager', 'Réseau et énergie', 'Entretien et hygiène', 'Autres' );
	}

	/**
	 * L'utilisateur gère-t-il l'inventaire ?
	 *
	 * @param int|null $user_id Utilisateur.
	 * @return bool
	 */
	public static function gestionnaire( $user_id = null ) {
		return user_can( $user_id ?? get_current_user_id(), 'ige_logistique' );
	}

	/**
	 * Clé de comparaison d'un libellé (minuscules, sans accents, espaces réduits).
	 *
	 * @param string $s Libellé.
	 * @return string
	 */
	public static function cle( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		return mb_substr( trim( preg_replace( '/\s+/', ' ', $s ) ), 0, 190 );
	}

	/**
	 * Devine la catégorie d'un bien d'après sa désignation.
	 *
	 * @param string $d Désignation.
	 * @return string
	 */
	public static function categorie_probable( $d ) {
		$c = self::cle( $d );
		$regles = array(
			'Informatique et bureautique'     => '/ordinateur|imprimante|moniteur|ecran|unite|clavier|souris|scanner|photocop|laptop|portable/',
			'Réseau et énergie'               => '/onduleur|stabilisat|routeur|internet|cable|rj45|allonge|rallonge|multiprise|groupe electro|batterie/',
			'Climatisation et électroménager' => '/split|climatis|refroidiss|chauffe|ventilat|frigo|refrigerat|congelat|fontaine/',
			'Entretien et hygiène'            => '/poubelle|sceau|seau|touque|lave|balai|serpill/',
			'Mobilier'                        => '/table|chaise|fauteuil|bureau|armoire|tiroir|etagere|banc|classeur|placard|canape/',
		);
		foreach ( $regles as $cat => $re ) {
			if ( preg_match( $re, $c ) ) {
				return $cat;
			}
		}
		return 'Autres';
	}

	/* ------------------------------------------------------------------ Catalogue */

	/**
	 * Biens du catalogue.
	 *
	 * @param bool $actifs Seulement les actifs.
	 * @return array[]
	 */
	public static function biens( $actifs = false ) {
		global $wpdb;
		$w = $actifs ? ' WHERE actif = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'pat_bien' ) . $w . ' ORDER BY categorie, designation', ARRAY_A );
	}

	/**
	 * Un bien.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function bien( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'pat_bien' ) . ' WHERE id = %d', $id ), ARRAY_A ) ?: null;
	}

	/**
	 * Crée ou modifie un bien.
	 *
	 * @param array    $d       designation, categorie, actif.
	 * @param int      $id      Bien à modifier (0 = nouveau).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function enregistrer_bien( array $d, $id = 0, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$des = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $d['designation'] ?? '' ) ) ) ), 0, 190 );
		$cle = self::cle( $des );
		if ( '' === $cle ) {
			return new WP_Error( 'designation', 'Indiquez la désignation du bien.' );
		}
		$t     = IGE_FFM_DB::t( 'pat_bien' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$autre = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE cle = %s AND id <> %d", $cle, (int) $id ) );
		if ( $autre ) {
			return new WP_Error( 'designation', 'Ce bien existe déjà dans le catalogue.' );
		}
		$cat = trim( sanitize_text_field( (string) ( $d['categorie'] ?? '' ) ) );
		$row = array( 'designation' => $des, 'cle' => $cle, 'categorie' => mb_substr( $cat ?: self::categorie_probable( $des ), 0, 60 ) );
		if ( $id ) {
			$avant = self::bien( (int) $id );
			if ( ! $avant ) {
				return new WP_Error( 'bien', 'Bien introuvable.' );
			}
			$row['actif'] = ! empty( $d['actif'] ) ? 1 : 0;
			$wpdb->update( $t, $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'patrimoine_bien', 'pat_bien', $des, array_intersect_key( $avant, $row ), $row, $user_id );
			return (int) $id;
		}
		$row['actif']      = 1;
		$row['created_at'] = ige_ffm_now();
		$wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$nid = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'patrimoine_bien', 'pat_bien', $des, null, array( 'categorie' => $row['categorie'] ), $user_id );
		return $nid;
	}

	/**
	 * Bien existant de même désignation, ou nouveau bien.
	 *
	 * @param string $designation Désignation.
	 * @param int    $user_id     Auteur.
	 * @return int|WP_Error
	 */
	private static function bien_par_designation( $designation, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'pat_bien' ) . ' WHERE cle = %s', self::cle( $designation ) ) );
		return $id ?: self::enregistrer_bien( array( 'designation' => $designation ), 0, $user_id );
	}

	/* ------------------------------------------------------------------ Locaux */

	/**
	 * Locaux d'un service.
	 *
	 * @param int  $sid    Service.
	 * @param bool $actifs Seulement les actifs.
	 * @return array[]
	 */
	public static function locaux( $sid, $actifs = true ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'pat_local' ) . ' WHERE service_id = %d' . ( $actifs ? ' AND actif = 1' : '' ) . ' ORDER BY ordre, id', $sid ), ARRAY_A );
	}

	/**
	 * Ajoute un local à un service (ou réactive celui de même nom).
	 *
	 * @param int      $sid     Service.
	 * @param string   $libelle Libellé (IGA, POOL INFO, SECRÉTARIAT…).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function ajouter_local( $sid, $libelle, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		if ( ! IGE_FFM_Referentiels::get( 'service', (int) $sid ) ) {
			return new WP_Error( 'service', 'Service introuvable.' );
		}
		$lib = mb_substr( trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $libelle ) ) ), 0, 90 );
		$cle = mb_substr( self::cle( $lib ), 0, 90 );
		if ( '' === $cle ) {
			return new WP_Error( 'libelle', 'Indiquez le nom du local (bureau, pool, secrétariat…).' );
		}
		$t = IGE_FFM_DB::t( 'pat_local' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ex = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE service_id = %d AND cle = %s", $sid, $cle ), ARRAY_A );
		if ( $ex ) {
			if ( ! (int) $ex['actif'] ) {
				$wpdb->update( $t, array( 'actif' => 1 ), array( 'id' => (int) $ex['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return (int) $ex['id'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ordre = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ordre),0) FROM $t WHERE service_id = %d", $sid ) ) + 1;
		$wpdb->insert( $t, array( 'service_id' => (int) $sid, 'libelle' => $lib, 'cle' => $cle, 'ordre' => $ordre, 'actif' => 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'patrimoine_local', 'pat_local', IGE_FFM_Comptes::service_code( $sid ) . ' · ' . $lib, null, null, $user_id );
		return $id;
	}

	/**
	 * Renomme ou retire un local (retiré : il n'apparaît plus dans les nouveaux inventaires).
	 *
	 * @param int      $id      Local.
	 * @param string   $libelle Nouveau libellé (vide pour retirer).
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function modifier_local( $id, $libelle, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$t = IGE_FFM_DB::t( 'pat_local' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$l = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		if ( ! $l ) {
			return new WP_Error( 'local', 'Local introuvable.' );
		}
		$lib = mb_substr( trim( sanitize_text_field( (string) $libelle ) ), 0, 90 );
		if ( '' === $lib ) {
			$wpdb->update( $t, array( 'actif' => 0 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'patrimoine_local', 'pat_local', $l['libelle'], array( 'actif' => 1 ), array( 'actif' => 0 ), $user_id );
			return true;
		}
		$cle = mb_substr( self::cle( $lib ), 0, 90 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE service_id = %d AND cle = %s AND id <> %d", $l['service_id'], $cle, $id ) ) ) {
			return new WP_Error( 'libelle', 'Ce service a déjà un local de ce nom.' );
		}
		$wpdb->update( $t, array( 'libelle' => $lib, 'cle' => $cle ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'patrimoine_local', 'pat_local', $lib, array( 'libelle' => $l['libelle'] ), array( 'libelle' => $lib ), $user_id );
		return true;
	}

	/* ------------------------------------------------------------------ Inventaires */

	/**
	 * Un inventaire.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function inventaire( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'pat_inventaire' ) . ' WHERE id = %d', $id ), ARRAY_A ) ?: null;
	}

	/**
	 * Inventaires (les plus récents d'abord).
	 *
	 * @param int $sid Service (0 = tous).
	 * @return array[] avec nb_biens et total.
	 */
	public static function inventaires( $sid = 0 ) {
		global $wpdb;
		$i = IGE_FFM_DB::t( 'pat_inventaire' );
		$q = IGE_FFM_DB::t( 'pat_quantite' );
		$w = $sid ? $wpdb->prepare( ' WHERE i.service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT i.*, (SELECT COUNT(DISTINCT q.bien_id) FROM $q q WHERE q.inventaire_id = i.id AND q.quantite > 0) AS nb_biens, (SELECT COALESCE(SUM(q.quantite),0) FROM $q q WHERE q.inventaire_id = i.id) AS total FROM $i i$w ORDER BY i.date_inventaire DESC, i.id DESC", ARRAY_A );
	}

	/**
	 * Dernier inventaire validé d'un service (avant une date ou un inventaire donné).
	 *
	 * @param int $sid    Service.
	 * @param int $avant  Exclure cet inventaire et ne garder que les antérieurs (0 = aucun filtre).
	 * @return array|null
	 */
	public static function dernier_valide( $sid, $avant = 0 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'pat_inventaire' );
		if ( $avant ) {
			$ref = self::inventaire( (int) $avant );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE service_id = %d AND statut = 'valide' AND id <> %d AND (date_inventaire < %s OR (date_inventaire = %s AND id < %d)) ORDER BY date_inventaire DESC, id DESC LIMIT 1", $sid, $avant, $ref['date_inventaire'], $ref['date_inventaire'], $avant ), ARRAY_A ) ?: null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE service_id = %d AND statut = 'valide' ORDER BY date_inventaire DESC, id DESC LIMIT 1", $sid ), ARRAY_A ) ?: null;
	}

	/**
	 * Quantités d'un inventaire : [ bien_id => [ local_id => quantité ] ].
	 *
	 * @param int $id Inventaire.
	 * @return array
	 */
	public static function quantites( $id ) {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT bien_id, local_id, quantite FROM ' . IGE_FFM_DB::t( 'pat_quantite' ) . ' WHERE inventaire_id = %d', $id ), ARRAY_A ) as $r ) {
			$out[ (int) $r['bien_id'] ][ (int) $r['local_id'] ] = (int) $r['quantite'];
		}
		return $out;
	}

	/**
	 * Grille complète d'un inventaire : locaux (colonnes), biens (lignes), totaux, écarts
	 * avec l'inventaire validé précédent du même service.
	 *
	 * @param int $id Inventaire.
	 * @return array|null
	 */
	public static function grille( $id ) {
		$inv = self::inventaire( (int) $id );
		if ( ! $inv ) {
			return null;
		}
		$q      = self::quantites( (int) $id );
		$locaux = self::locaux( (int) $inv['service_id'] );
		$ids_l  = array_map( 'intval', wp_list_pluck( $locaux, 'id' ) );
		// Un inventaire validé garde les colonnes qu'il utilise, même retirées depuis.
		foreach ( $q as $par_local ) {
			foreach ( array_keys( $par_local ) as $lid ) {
				if ( ! in_array( (int) $lid, $ids_l, true ) ) {
					$ids_l[] = (int) $lid;
				}
			}
		}
		$tous = array();
		foreach ( self::locaux( (int) $inv['service_id'], false ) as $l ) {
			$tous[ (int) $l['id'] ] = $l;
		}
		$locaux = array_values( array_filter( array_map( static fn( $lid ) => $tous[ $lid ] ?? null, $ids_l ) ) );
		usort( $locaux, static fn( $a, $b ) => ( (int) $a['ordre'] <=> (int) $b['ordre'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
		$prec = self::dernier_valide( (int) $inv['service_id'], (int) $id );
		$qp   = $prec ? self::quantites( (int) $prec['id'] ) : array();
		$biens = array();
		foreach ( self::biens() as $b ) {
			$bid = (int) $b['id'];
			if ( isset( $q[ $bid ] ) || isset( $qp[ $bid ] ) ) {
				$biens[ $bid ] = $b;
			}
		}
		$lignes   = array();
		$tot_col  = array_fill_keys( array_map( static fn( $l ) => (int) $l['id'], $locaux ), 0 );
		$tot      = 0;
		$tot_prec = 0;
		foreach ( $biens as $bid => $b ) {
			$cells = array();
			foreach ( $locaux as $l ) {
				$v                              = $q[ $bid ][ (int) $l['id'] ] ?? 0;
				$cells[ (int) $l['id'] ]        = $v;
				$tot_col[ (int) $l['id'] ]     += $v;
			}
			$total     = array_sum( $cells );
			$p         = $prec ? array_sum( $qp[ $bid ] ?? array() ) : null;
			$tot      += $total;
			$tot_prec += (int) $p;
			$lignes[]  = array( 'bien' => $b, 'cells' => $cells, 'total' => $total, 'precedent' => $p, 'ecart' => null === $p ? null : $total - $p );
		}
		usort( $lignes, static fn( $a, $b ) => strcmp( $a['bien']['categorie'], $b['bien']['categorie'] ) ?: strcasecmp( $a['bien']['designation'], $b['bien']['designation'] ) );
		return array(
			'inventaire' => $inv,
			'service'    => IGE_FFM_Referentiels::get( 'service', (int) $inv['service_id'] ),
			'locaux'     => $locaux,
			'lignes'     => $lignes,
			'tot_col'    => $tot_col,
			'total'      => $tot,
			'precedent'  => $prec,
			'tot_prec'   => $prec ? $tot_prec : null,
		);
	}

	/**
	 * Référence d'un nouvel inventaire : INV-<service>-<année>-NN.
	 *
	 * @param int    $sid  Service.
	 * @param string $date Date.
	 * @return string
	 */
	private static function reference( $sid, $date ) {
		global $wpdb;
		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( $sid ) ) ?: 'S' . $sid;
		$pref = 'INV-' . strtoupper( $code ) . '-' . substr( $date, 0, 4 ) . '-';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'pat_inventaire' ) . ' WHERE reference LIKE %s', $wpdb->esc_like( $pref ) . '%' ) );
		return $pref . str_pad( (string) ( $n + 1 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Ouvre un inventaire (brouillon). Il reprend, si demandé, les quantités du dernier
	 * inventaire validé du service, à vérifier sur place.
	 *
	 * @param array    $d       service_id, date, observations, reprendre (bool).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function ouvrir( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$sid  = (int) ( $d['service_id'] ?? 0 );
		$date = (string) ( $d['date'] ?? '' );
		if ( ! IGE_FFM_Referentiels::get( 'service', $sid ) ) {
			return new WP_Error( 'service', 'Choisissez le service inventorié.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'Date de l’inventaire invalide (pas dans le futur).' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$encours = $wpdb->get_var( $wpdb->prepare( 'SELECT reference FROM ' . IGE_FFM_DB::t( 'pat_inventaire' ) . " WHERE service_id = %d AND statut = 'brouillon'", $sid ) );
		if ( $encours ) {
			return new WP_Error( 'brouillon', sprintf( 'L’inventaire %s de ce service est encore en cours : terminez-le ou supprimez-le d’abord.', $encours ) );
		}
		$row = array(
			'service_id'      => $sid,
			'reference'       => self::reference( $sid, $date ),
			'date_inventaire' => $date,
			'statut'          => 'brouillon',
			'origine'         => mb_substr( (string) ( $d['origine'] ?? 'saisie' ), 0, 12 ),
			'fichier'         => mb_substr( sanitize_file_name( (string) ( $d['fichier'] ?? '' ) ), 0, 190 ),
			'observations'    => trim( sanitize_textarea_field( (string) ( $d['observations'] ?? '' ) ) ),
			'created_by'      => (int) $user_id,
			'created_at'      => ige_ffm_now(),
		);
		$wpdb->insert( IGE_FFM_DB::t( 'pat_inventaire' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return new WP_Error( 'enregistrement', 'Enregistrement impossible pour le moment. Réessayez.' );
		}
		if ( ! empty( $d['reprendre'] ) ) {
			$prec = self::dernier_valide( $sid );
			if ( $prec ) {
				foreach ( self::quantites( (int) $prec['id'] ) as $bid => $par_local ) {
					foreach ( $par_local as $lid => $v ) {
						$wpdb->insert( IGE_FFM_DB::t( 'pat_quantite' ), array( 'inventaire_id' => $id, 'bien_id' => $bid, 'local_id' => $lid, 'quantite' => $v ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
				}
			}
		}
		IGE_FFM_Audit::log( 'patrimoine_inventaire', 'pat_inventaire', $row['reference'], null, array( 'service' => IGE_FFM_Comptes::service_code( $sid ), 'date' => $date, 'origine' => $row['origine'] ), $user_id );
		return $id;
	}

	/**
	 * Enregistre les quantités d'un inventaire en cours.
	 *
	 * @param int      $id      Inventaire.
	 * @param array    $q       [ bien_id => [ local_id => quantité ] ] (cellules vides = 0).
	 * @param array    $extra   observations, date, nouveaux biens [ [designation, categorie, [ local_id => q ]] ].
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function enregistrer( $id, array $q, array $extra = array(), $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$inv = self::inventaire( (int) $id );
		if ( ! $inv ) {
			return new WP_Error( 'inventaire', 'Inventaire introuvable.' );
		}
		if ( 'brouillon' !== $inv['statut'] ) {
			return new WP_Error( 'statut', 'Cet inventaire est validé : il ne peut plus être modifié. Ouvrez un nouvel inventaire.' );
		}
		$locaux = array_map( 'intval', wp_list_pluck( self::locaux( (int) $inv['service_id'], false ), 'id' ) );
		$propre = array();
		foreach ( $q as $bid => $par_local ) {
			if ( ! self::bien( (int) $bid ) ) {
				continue;
			}
			foreach ( (array) $par_local as $lid => $v ) {
				if ( ! in_array( (int) $lid, $locaux, true ) ) {
					continue;
				}
				$v = trim( (string) $v );
				if ( '' !== $v && ! preg_match( '/^\d{1,7}$/', $v ) ) {
					return new WP_Error( 'quantite', 'Les quantités sont des nombres entiers positifs.' );
				}
				$propre[ (int) $bid ][ (int) $lid ] = (int) $v;
			}
		}
		$actuel = self::quantites( (int) $id );
		foreach ( (array) ( $extra['nouveaux'] ?? array() ) as $n ) {
			$des = trim( (string) ( $n[0] ?? '' ) );
			if ( '' === $des ) {
				continue;
			}
			$bid = self::bien_par_designation( $des, $user_id );
			if ( is_wp_error( $bid ) ) {
				return $bid;
			}
			if ( ! empty( $n[1] ) ) {
				$b = self::bien( $bid );
				if ( $b && ( '' === $b['categorie'] || 'Autres' === $b['categorie'] ) ) {
					$wpdb->update( IGE_FFM_DB::t( 'pat_bien' ), array( 'categorie' => mb_substr( sanitize_text_field( $n[1] ), 0, 60 ) ), array( 'id' => $bid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			foreach ( (array) ( $n[2] ?? array() ) as $lid => $v ) {
				$v = trim( (string) $v );
				if ( '' !== $v && ! preg_match( '/^\d{1,7}$/', $v ) ) {
					return new WP_Error( 'quantite', 'Les quantités sont des nombres entiers positifs.' );
				}
				if ( in_array( (int) $lid, $locaux, true ) ) {
					// Bien déjà dans la grille : la quantité s'ajoute à celle déjà comptée.
					$propre[ $bid ][ (int) $lid ] = ( $propre[ $bid ][ (int) $lid ] ?? $actuel[ $bid ][ (int) $lid ] ?? 0 ) + (int) $v;
				}
			}
			if ( ! isset( $propre[ $bid ] ) ) {
				$propre[ $bid ] = array();
			}
		}
		$t = IGE_FFM_DB::t( 'pat_quantite' );
		foreach ( $propre as $bid => $par_local ) {
			foreach ( $par_local as $lid => $v ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE inventaire_id = %d AND bien_id = %d AND local_id = %d", $id, $bid, $lid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $v > 0 ) {
					$wpdb->insert( $t, array( 'inventaire_id' => (int) $id, 'bien_id' => $bid, 'local_id' => $lid, 'quantite' => $v ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
			if ( ! $par_local ) {
				// Bien ajouté sans quantité : une cellule à zéro le garde dans la grille.
				$premier = $locaux[0] ?? 0;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $premier && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE inventaire_id = %d AND bien_id = %d", $id, $bid ) ) ) {
					$wpdb->insert( $t, array( 'inventaire_id' => (int) $id, 'bien_id' => $bid, 'local_id' => $premier, 'quantite' => 0 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
			}
		}
		$maj = array();
		if ( array_key_exists( 'observations', $extra ) ) {
			$maj['observations'] = trim( sanitize_textarea_field( (string) $extra['observations'] ) );
		}
		if ( ! empty( $extra['date'] ) && IGE_FFM_Contexte::date_valide( $extra['date'] ) && $extra['date'] <= IGE_FFM_Contexte::aujourdhui() ) {
			$maj['date_inventaire'] = $extra['date'];
		}
		if ( $maj ) {
			$wpdb->update( IGE_FFM_DB::t( 'pat_inventaire' ), $maj, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return true;
	}

	/**
	 * Retire un bien d'un inventaire en cours.
	 *
	 * @param int      $id      Inventaire.
	 * @param int      $bid     Bien.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function retirer_bien( $id, $bid, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$inv     = self::inventaire( (int) $id );
		if ( ! self::gestionnaire( $user_id ) || ! $inv ) {
			return new WP_Error( 'droits', 'Inventaire introuvable.' );
		}
		if ( 'brouillon' !== $inv['statut'] ) {
			return new WP_Error( 'statut', 'Cet inventaire est validé : il ne peut plus être modifié.' );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'pat_quantite' ), array( 'inventaire_id' => (int) $id, 'bien_id' => (int) $bid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}

	/**
	 * Valide un inventaire : il est figé et devient la référence du service.
	 *
	 * @param int      $id      Inventaire.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function valider( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$g = self::grille( (int) $id );
		if ( ! $g ) {
			return new WP_Error( 'inventaire', 'Inventaire introuvable.' );
		}
		if ( 'brouillon' !== $g['inventaire']['statut'] ) {
			return new WP_Error( 'statut', 'Cet inventaire est déjà validé.' );
		}
		if ( ! $g['total'] ) {
			return new WP_Error( 'vide', 'Inventaire vide : saisissez au moins une quantité avant de valider.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'pat_inventaire' ), array( 'statut' => 'valide', 'valide_par' => (int) $user_id, 'valide_le' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'patrimoine_validation', 'pat_inventaire', $g['inventaire']['reference'], null, array( 'biens' => count( array_filter( $g['lignes'], static fn( $l ) => $l['total'] > 0 ) ), 'total' => $g['total'] ), $user_id );
		return true;
	}

	/**
	 * Supprime un inventaire en cours (jamais un inventaire validé).
	 *
	 * @param int      $id      Inventaire.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function supprimer( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$inv     = self::inventaire( (int) $id );
		if ( ! self::gestionnaire( $user_id ) || ! $inv ) {
			return new WP_Error( 'droits', 'Inventaire introuvable.' );
		}
		if ( 'brouillon' !== $inv['statut'] ) {
			return new WP_Error( 'statut', 'Un inventaire validé ne se supprime pas : il reste dans l’historique du service.' );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'pat_quantite' ), array( 'inventaire_id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( IGE_FFM_DB::t( 'pat_inventaire' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'patrimoine_suppression', 'pat_inventaire', $inv['reference'], array( 'statut' => 'brouillon' ), null, $user_id );
		return true;
	}

	/* ------------------------------------------------------------------ Import Excel */

	/**
	 * Lit un classeur d'inventaire au format « INVENTAIRE DE SERVICE » :
	 * une ligne d'en-tête « N° | Désignation | local… | TOTAL », une cellule « SERVICE: 8001 ».
	 *
	 * @param string $chemin Fichier.
	 * @return array|WP_Error [ service (code lu), locaux [col => libellé], lignes [ [désignation, [col => q]] ], avertissements ]
	 */
	public static function lire_fichier( $chemin ) {
		$x = IGE_FFM_Xlsx::lire( $chemin );
		if ( is_wp_error( $x ) ) {
			return $x;
		}
		foreach ( $x as $lignes ) {
			$entete = null;
			$code   = '';
			foreach ( $lignes as $n => $cols ) {
				foreach ( $cols as $v ) {
					if ( is_string( $v ) && preg_match( '/^\s*SERVICE\s*:\s*(.+?)\s*$/iu', $v, $m ) ) {
						$code = trim( $m[1] );
					}
				}
				if ( null === $entete ) {
					foreach ( $cols as $c => $v ) {
						if ( is_string( $v ) && 'designation' === IGE_FFM_Xlsx::norm( $v ) ) {
							$entete = array( 'ligne' => $n, 'des' => $c );
						}
					}
				}
			}
			if ( ! $entete ) {
				continue;
			}
			$h      = $lignes[ $entete['ligne'] ];
			$locaux = array();
			$fin    = null;
			foreach ( $h as $c => $v ) {
				if ( $c <= $entete['des'] || ! is_string( $v ) || '' === trim( $v ) ) {
					continue;
				}
				if ( 'total' === IGE_FFM_Xlsx::norm( $v ) ) {
					$fin = $c;
					continue;
				}
				if ( null === $fin ) {
					$locaux[ $c ] = trim( preg_replace( '/\s+/u', ' ', $v ) );
				}
			}
			if ( ! $locaux ) {
				return new WP_Error( 'format', 'Aucune colonne de local trouvée entre « Désignation » et « TOTAL ».' );
			}
			$out   = array();
			$avert = array();
			foreach ( $lignes as $n => $cols ) {
				if ( $n <= $entete['ligne'] ) {
					continue;
				}
				$des = trim( (string) ( $cols[ $entete['des'] ] ?? '' ) );
				if ( '' === $des ) {
					continue;
				}
				if ( 'total' === IGE_FFM_Xlsx::norm( $des ) ) {
					break;
				}
				$q = array();
				foreach ( array_keys( $locaux ) as $c ) {
					$v = $cols[ $c ] ?? '';
					if ( '' === $v || null === $v ) {
						continue;
					}
					if ( ! is_numeric( $v ) || (float) $v < 0 || floor( (float) $v ) !== (float) $v ) {
						$avert[] = sprintf( 'Ligne %d (%s) : quantité « %s » ignorée.', $n, $des, $v );
						continue;
					}
					$q[ $c ] = (int) $v;
				}
				$out[] = array( $des, $q );
			}
			return array( 'service' => $code, 'locaux' => $locaux, 'lignes' => $out, 'avertissements' => $avert );
		}
		return new WP_Error( 'format', 'Format non reconnu : il faut une ligne d’en-tête avec « Désignation », les locaux puis « TOTAL ».' );
	}

	/**
	 * Importe un classeur : crée les biens et les locaux manquants, puis un inventaire en cours
	 * (à contrôler et valider).
	 *
	 * @param string   $chemin  Fichier.
	 * @param string   $nom     Nom du fichier.
	 * @param array    $d       service_id (0 = celui du fichier), date.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error [ id, biens, locaux, total, avertissements ]
	 */
	public static function importer( $chemin, $nom, array $d, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à Administration et Finance.' );
		}
		$lu = self::lire_fichier( $chemin );
		if ( is_wp_error( $lu ) ) {
			return $lu;
		}
		$sid = (int) ( $d['service_id'] ?? 0 );
		if ( ! $sid && '' !== $lu['service'] ) {
			foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
				if ( IGE_FFM_Xlsx::norm( $s['code'] ) === IGE_FFM_Xlsx::norm( $lu['service'] ) ) {
					$sid = (int) $s['id'];
				}
			}
			if ( ! $sid ) {
				return new WP_Error( 'service', sprintf( 'Le service « %s » du fichier n’existe pas dans l’application : choisissez le service dans la liste.', $lu['service'] ) );
			}
		}
		if ( ! $sid ) {
			return new WP_Error( 'service', 'Le fichier n’indique pas le service (« SERVICE: 8001 ») : choisissez-le dans la liste.' );
		}
		$id = self::ouvrir( array( 'service_id' => $sid, 'date' => $d['date'] ?? '', 'origine' => 'import', 'fichier' => $nom, 'observations' => 'Importé du fichier ' . sanitize_file_name( $nom ) . '.' ), $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$col_local = array();
		foreach ( $lu['locaux'] as $c => $lib ) {
			$lid = self::ajouter_local( $sid, $lib, $user_id );
			if ( is_wp_error( $lid ) ) {
				self::supprimer( $id, $user_id );
				return $lid;
			}
			$col_local[ $c ] = $lid;
		}
		$nouveaux = array();
		foreach ( $lu['lignes'] as $l ) {
			$q = array();
			foreach ( $l[1] as $c => $v ) {
				$q[ $col_local[ $c ] ] = $v;
			}
			$nouveaux[] = array( $l[0], '', $q );
		}
		$avant = count( self::biens() );
		$r     = self::enregistrer( $id, array(), array( 'nouveaux' => $nouveaux ), $user_id );
		if ( is_wp_error( $r ) ) {
			self::supprimer( $id, $user_id );
			return $r;
		}
		$g = self::grille( $id );
		return array( 'id' => $id, 'biens' => count( $g['lignes'] ), 'nouveaux_biens' => count( self::biens() ) - $avant, 'locaux' => count( $col_local ), 'total' => $g['total'], 'avertissements' => $lu['avertissements'] );
	}

	/* ------------------------------------------------------------------ Synthèses */

	/**
	 * Situation de chaque service : dernier inventaire validé, en cours.
	 *
	 * @return array[]
	 */
	public static function par_service() {
		$out = array();
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			if ( ! (int) $s['actif'] ) {
				continue;
			}
			$sid  = (int) $s['id'];
			$all  = self::inventaires( $sid );
			$val  = array_values( array_filter( $all, static fn( $i ) => 'valide' === $i['statut'] ) );
			$enc  = array_values( array_filter( $all, static fn( $i ) => 'brouillon' === $i['statut'] ) );
			$out[] = array( 'service' => $s, 'dernier' => $val[0] ?? null, 'en_cours' => $enc[0] ?? null, 'nb' => count( $val ), 'locaux' => count( self::locaux( $sid ) ) );
		}
		return $out;
	}

	/**
	 * Tableau consolidé : biens × services, d'après le dernier inventaire validé de chaque service.
	 *
	 * @return array [ services [sid => code], lignes [ [bien, [sid => q], total] ], totaux [sid => q], total ]
	 */
	public static function consolide() {
		$svc = array();
		$q   = array();
		foreach ( self::par_service() as $p ) {
			if ( ! $p['dernier'] ) {
				continue;
			}
			$sid         = (int) $p['service']['id'];
			$svc[ $sid ] = $p['service']['code'];
			foreach ( self::quantites( (int) $p['dernier']['id'] ) as $bid => $par_local ) {
				$q[ $bid ][ $sid ] = ( $q[ $bid ][ $sid ] ?? 0 ) + array_sum( $par_local );
			}
		}
		$lignes = array();
		$tot    = array_fill_keys( array_keys( $svc ), 0 );
		foreach ( self::biens() as $b ) {
			$bid = (int) $b['id'];
			if ( empty( $q[ $bid ] ) || ! array_sum( $q[ $bid ] ) ) {
				continue;
			}
			foreach ( $q[ $bid ] as $sid => $v ) {
				$tot[ $sid ] += $v;
			}
			$lignes[] = array( $b, $q[ $bid ], array_sum( $q[ $bid ] ) );
		}
		return array( 'services' => $svc, 'lignes' => $lignes, 'totaux' => $tot, 'total' => array_sum( $tot ) );
	}

	/**
	 * Données d'export (Excel, PDF) d'un inventaire, au format du fichier de service.
	 *
	 * @param int $id Inventaire.
	 * @return array|null
	 */
	public static function export_donnees( $id ) {
		$g = self::grille( (int) $id );
		if ( ! $g ) {
			return null;
		}
		$inv  = $g['inventaire'];
		$cols = array( array( 'N°', 'nombre', 6 ), array( 'Désignation', 'texte', 34 ) );
		foreach ( $g['locaux'] as $l ) {
			$cols[] = array( $l['libelle'], 'nombre', 12 );
		}
		$cols[] = array( 'TOTAL', 'nombre', 10 );
		$avec_prec = (bool) $g['precedent'];
		if ( $avec_prec ) {
			$cols[] = array( 'Précédent', 'nombre', 11 );
			$cols[] = array( 'Écart', 'nombre', 9 );
		}
		$lignes = array();
		$n      = 0;
		foreach ( $g['lignes'] as $l ) {
			$r = array( ++$n, $l['bien']['designation'] );
			foreach ( $g['locaux'] as $loc ) {
				$v   = $l['cells'][ (int) $loc['id'] ] ?? 0;
				$r[] = $v ?: null;
			}
			$r[] = $l['total'];
			if ( $avec_prec ) {
				$r[] = $l['precedent'];
				$r[] = $l['ecart'];
			}
			$lignes[] = $r;
		}
		$total = array( null, 'TOTAL' );
		foreach ( $g['locaux'] as $loc ) {
			$total[] = $g['tot_col'][ (int) $loc['id'] ];
		}
		$total[] = $g['total'];
		if ( $avec_prec ) {
			$total[] = $g['tot_prec'];
			$total[] = $g['total'] - (int) $g['tot_prec'];
		}
		$code = $g['service']['code'] ?? '';
		return array(
			'titre'      => 'Inventaire de service — ' . $code,
			'sous_titre' => sprintf( 'Service : %s%s · inventaire %s du %s · %s', $code, ! empty( $g['service']['libelle'] ) && $g['service']['libelle'] !== $code ? ' (' . $g['service']['libelle'] . ')' : '', $inv['reference'], wp_date( 'd/m/Y', strtotime( $inv['date_inventaire'] ) ), 'valide' === $inv['statut'] ? 'validé' : 'en cours (non validé)' ),
			'colonnes'   => $cols,
			'lignes'     => $lignes,
			'total'      => $total,
			'notes'      => array_values( array_filter( array( 'Inspection Générale Adjointe chargée de l’Administration et Finances.', $avec_prec ? sprintf( 'Écart : différence avec l’inventaire validé précédent (%s du %s).', $g['precedent']['reference'], wp_date( 'd/m/Y', strtotime( $g['precedent']['date_inventaire'] ) ) ) : '', $inv['observations'] ? 'Observations : ' . $inv['observations'] : '' ) ) ),
			'fichier'    => 'inventaire-' . sanitize_title( $code ) . '-' . $inv['date_inventaire'],
			'paysage'    => true,
		);
	}
}
