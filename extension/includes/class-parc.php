<?php
/**
 * Parc automobile et gestion du charroi (VA-41) :
 * - véhicules (fiche, affectation, chauffeur attitré, statut, kilométrage) et chauffeurs (permis) ;
 * - documents de bord avec échéances (assurance, contrôle technique, vignette…) ;
 * - demandes de véhicule des services, attribution (disponibilité, documents, permis vérifiés),
 *   ordres de route et carnet de bord (kilométrage au départ et au retour) ;
 * - carburant (pleins, bons, consommation aux 100 km, consommations anormales) ;
 * - entretiens planifiés et réalisés, réparations, pneumatiques, sinistres ;
 * - coût par véhicule et par kilomètre, alertes.
 *
 * Toute action est réservée à la capacité ige_gerer_parc (Gestionnaire du parc et Administration et Finance), sauf : un service demande un véhicule,
 * enregistre le départ et le retour de ses déplacements et les pleins des véhicules qui lui sont affectés.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parc automobile.
 */
final class IGE_FFM_Parc {

	/**
	 * Catégories de véhicules.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array( 'berline' => 'Berline', '4x4' => '4x4 / tout-terrain', 'pickup' => 'Pick-up', 'minibus' => 'Minibus', 'bus' => 'Bus', 'camion' => 'Camion', 'moto' => 'Moto' );
	}

	/**
	 * Énergies.
	 *
	 * @return array<string,string>
	 */
	public static function energies() {
		return array( 'diesel' => 'Diesel (gasoil)', 'essence' => 'Essence', 'hybride' => 'Hybride', 'electrique' => 'Électrique' );
	}

	/**
	 * Statuts administratifs d'un véhicule (« en mission » se déduit des déplacements en cours).
	 *
	 * @return array<string,array>
	 */
	public static function statuts() {
		return array(
			'disponible' => array( 'En service', 'ok' ),
			'immobilise' => array( 'Immobilisé', 'ko' ),
			'reforme'    => array( 'Réformé', 'neutre' ),
		);
	}

	/**
	 * Documents de bord : type => [ libellé, obligatoire ].
	 *
	 * @return array<string,array>
	 */
	public static function types_documents() {
		return (array) apply_filters(
			'ige_ffm_parc_documents',
			array(
				'assurance'          => array( 'Assurance', true ),
				'controle_technique' => array( 'Contrôle technique', true ),
				'vignette'           => array( 'Vignette / taxe de circulation', true ),
				'carte_rose'         => array( 'Carte rose (certificat d’immatriculation)', false ),
				'autorisation'       => array( 'Autorisation de transport', false ),
				'autre'              => array( 'Autre document', false ),
			)
		);
	}

	/**
	 * Natures d'intervention.
	 *
	 * @return array<string,string>
	 */
	public static function natures() {
		return array( 'entretien' => 'Entretien périodique', 'reparation' => 'Réparation', 'pneus' => 'Pneumatiques', 'sinistre' => 'Sinistre / accident' );
	}

	/**
	 * Statuts d'un déplacement : code => [ libellé, ton ].
	 *
	 * @return array<string,array>
	 */
	public static function statuts_deplacement() {
		return array(
			'demande'  => array( 'Demande à traiter', 'wait' ),
			'attribue' => array( 'Véhicule attribué', 'info' ),
			'en_cours' => array( 'En route', 'info' ),
			'termine'  => array( 'Terminé', 'ok' ),
			'refuse'   => array( 'Refusé', 'ko' ),
			'annule'   => array( 'Annulé', 'neutre' ),
		);
	}

	/**
	 * L'utilisateur gère-t-il le parc ?
	 *
	 * @param int|null $user_id Utilisateur.
	 * @return bool
	 */
	public static function gestionnaire( $user_id = null ) {
		return user_can( $user_id ?? get_current_user_id(), 'ige_gerer_parc' );
	}

	/**
	 * Lecture générique.
	 *
	 * @param string $table Table.
	 * @param int    $id    Identifiant.
	 * @return array|null
	 */
	private static function ligne( $table, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( $table ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/* ------------------------------------------------------------------ Véhicules */

	/**
	 * Véhicules.
	 *
	 * @param array $f service_id, sans_reformes.
	 * @return array[]
	 */
	public static function vehicules( array $f = array() ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( ! empty( $f['service_id'] ) ) {
			$w[] = $wpdb->prepare( 'service_id = %d', $f['service_id'] );
		}
		if ( ! empty( $f['sans_reformes'] ) ) {
			$w[] = "statut <> 'reforme'";
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'vehicule' ) . ' WHERE ' . implode( ' AND ', $w ) . " ORDER BY statut = 'reforme', immatriculation", ARRAY_A );
	}

	/**
	 * Un véhicule.
	 *
	 * @param int $id Véhicule.
	 * @return array|null
	 */
	public static function vehicule( $id ) {
		return self::ligne( 'vehicule', (int) $id );
	}

	/**
	 * Libellé court d'un véhicule.
	 *
	 * @param array|null $v Véhicule.
	 * @return string
	 */
	public static function nom( $v ) {
		return $v ? $v['immatriculation'] . ' · ' . trim( $v['marque'] . ' ' . $v['modele'] ) : '—';
	}

	/**
	 * Crée ou modifie un véhicule.
	 *
	 * @param array    $d       Données.
	 * @param int      $id      Véhicule (0 = création).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function enregistrer_vehicule( array $d, $id = 0, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion du parc (Administration et Finance).' );
		}
		$avant = $id ? self::vehicule( $id ) : null;
		if ( $id && ! $avant ) {
			return new WP_Error( 'vehicule', 'Véhicule introuvable.' );
		}
		$immat = strtoupper( preg_replace( '/\s+/', ' ', trim( sanitize_text_field( (string) ( $d['immatriculation'] ?? '' ) ) ) ) );
		$row   = array(
			'immatriculation'  => mb_substr( $immat, 0, 20 ),
			'marque'           => mb_substr( trim( sanitize_text_field( (string) ( $d['marque'] ?? '' ) ) ), 0, 60 ),
			'modele'           => mb_substr( trim( sanitize_text_field( (string) ( $d['modele'] ?? '' ) ) ), 0, 60 ),
			'annee'            => (int) ( $d['annee'] ?? 0 ) ?: null,
			'categorie'        => isset( self::categories()[ $d['categorie'] ?? '' ] ) ? $d['categorie'] : '4x4',
			'energie'          => isset( self::energies()[ $d['energie'] ?? '' ] ) ? $d['energie'] : 'diesel',
			'places'           => (int) ( $d['places'] ?? 0 ) ?: null,
			'chassis'          => mb_substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $d['chassis'] ?? '' ) ) ), 0, 40 ),
			'service_id'       => (int) ( $d['service_id'] ?? 0 ) ?: null,
			'chauffeur_id'     => (int) ( $d['chauffeur_id'] ?? 0 ) ?: null,
			'conso_ref'        => '' === trim( (string) ( $d['conso_ref'] ?? '' ) ) ? null : IGE_FFM_Devises::nombre( $d['conso_ref'] ),
			'reservoir'        => (int) ( $d['reservoir'] ?? 0 ) ?: null,
			'date_acquisition' => IGE_FFM_Contexte::date_valide( (string) ( $d['date_acquisition'] ?? '' ) ) ? $d['date_acquisition'] : null,
			'valeur'           => '' === trim( (string) ( $d['valeur'] ?? '' ) ) ? null : ige_ffm_parse_montant( $d['valeur'] ),
		);
		if ( ! preg_match( '/^[A-Z0-9][A-Z0-9 \-\/]{2,19}$/', $row['immatriculation'] ) ) {
			return new WP_Error( 'immatriculation', 'Immatriculation invalide (lettres, chiffres, tirets ; par exemple 1234AB01).' );
		}
		if ( '' === $row['marque'] ) {
			return new WP_Error( 'marque', 'Indiquez la marque.' );
		}
		if ( $row['annee'] && ( $row['annee'] < 1970 || $row['annee'] > (int) wp_date( 'Y' ) + 1 ) ) {
			return new WP_Error( 'annee', 'Année de mise en circulation invalide.' );
		}
		if ( null !== $row['conso_ref'] && ( $row['conso_ref'] < 2 || $row['conso_ref'] > 60 ) ) {
			return new WP_Error( 'conso_ref', 'Consommation de référence : entre 2 et 60 litres aux 100 km.' );
		}
		if ( $row['service_id'] && ! IGE_FFM_Referentiels::get( 'service', $row['service_id'] ) ) {
			return new WP_Error( 'service_id', 'Service d’affectation introuvable.' );
		}
		if ( $row['chauffeur_id'] && ! self::chauffeur( $row['chauffeur_id'] ) ) {
			return new WP_Error( 'chauffeur_id', 'Chauffeur introuvable.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dupe = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'vehicule' ) . ' WHERE immatriculation = %s AND id <> %d', $row['immatriculation'], (int) $id ) );
		if ( $dupe ) {
			return new WP_Error( 'immatriculation', 'Un véhicule porte déjà cette immatriculation.' );
		}
		if ( $id ) {
			$row['updated_at'] = ige_ffm_now();
			$wpdb->update( IGE_FFM_DB::t( 'vehicule' ), $row, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$diff = array_diff_assoc( array_map( 'strval', array_intersect_key( $row, $avant ) ), array_map( 'strval', array_intersect_key( $avant, $row ) ) );
			unset( $diff['updated_at'] );
			if ( $diff ) {
				IGE_FFM_Audit::log( 'modification_vehicule', 'vehicule', $row['immatriculation'], array_intersect_key( $avant, $diff ), $diff, $user_id );
			}
			return (int) $id;
		}
		$km                       = max( 0, (int) preg_replace( '/\D/', '', (string) ( $d['km_initial'] ?? '0' ) ) );
		$row['km_initial']        = $km;
		$row['km_actuel']         = $km;
		$row['statut']            = 'disponible';
		$row['created_at']        = ige_ffm_now();
		$wpdb->insert( IGE_FFM_DB::t( 'vehicule' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$nid = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'creation_vehicule', 'vehicule', $row['immatriculation'], null, array( 'vehicule' => self::nom( $row ), 'km' => $km ), $user_id );
		return $nid;
	}

	/**
	 * Change le statut (immobilisation, remise en service, réforme).
	 *
	 * @param int      $id      Véhicule.
	 * @param string   $statut  Statut.
	 * @param string   $motif   Motif.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function changer_statut( $id, $statut, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$v       = self::vehicule( (int) $id );
		if ( ! $v || ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Véhicule introuvable.' );
		}
		if ( ! isset( self::statuts()[ $statut ] ) || $statut === $v['statut'] ) {
			return new WP_Error( 'statut', 'Statut inchangé.' );
		}
		$motif = mb_substr( trim( sanitize_text_field( (string) $motif ) ), 0, 190 );
		if ( 'disponible' !== $statut && mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif (panne, accident, réforme…).' );
		}
		if ( 'disponible' !== $statut && self::en_route( (int) $id ) ) {
			return new WP_Error( 'statut', 'Le véhicule est en route : enregistrez d’abord son retour.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'vehicule' ), array( 'statut' => $statut, 'motif_statut' => $motif, 'updated_at' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'statut_vehicule', 'vehicule', $v['immatriculation'], array( 'statut' => self::statuts()[ $v['statut'] ][0] ), array( 'statut' => self::statuts()[ $statut ][0], 'motif' => $motif ), $user_id );
		return true;
	}

	/**
	 * Déplacement en cours d'un véhicule.
	 *
	 * @param int $vid Véhicule.
	 * @return array|null
	 */
	public static function en_route( $vid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'deplacement' ) . " WHERE vehicule_id = %d AND statut = 'en_cours' LIMIT 1", $vid ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Situation affichée d'un véhicule.
	 *
	 * @param array $v Véhicule.
	 * @return array [ libellé, ton ].
	 */
	public static function situation( array $v ) {
		if ( 'disponible' === $v['statut'] && self::en_route( (int) $v['id'] ) ) {
			return array( 'En route', 'info' );
		}
		return self::statuts()[ $v['statut'] ] ?? array( $v['statut'], 'neutre' );
	}

	/**
	 * Relevé de compteur : met à jour le kilométrage actuel.
	 *
	 * @param int $vid Véhicule.
	 * @param int $km  Kilométrage.
	 */
	private static function releve( $vid, $km ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . IGE_FFM_DB::t( 'vehicule' ) . ' SET km_actuel = %d WHERE id = %d AND km_actuel < %d', $km, $vid, $km ) );
	}

	/**
	 * Kilométrage saisi (chiffres seulement).
	 *
	 * @param mixed $v Valeur.
	 * @return int|null
	 */
	private static function km( $v ) {
		$s = preg_replace( '/[\s\x{00A0}\x{202F}.]/u', '', (string) $v );
		return '' !== $s && ctype_digit( $s ) ? (int) $s : null;
	}

	/* ------------------------------------------------------------------ Chauffeurs */

	/**
	 * Chauffeurs.
	 *
	 * @param bool $actifs Actifs seulement.
	 * @return array[]
	 */
	public static function chauffeurs( $actifs = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'chauffeur' ) . ( $actifs ? ' WHERE actif = 1' : '' ) . ' ORDER BY actif DESC, nom', ARRAY_A );
	}

	/**
	 * Un chauffeur.
	 *
	 * @param int $id Chauffeur.
	 * @return array|null
	 */
	public static function chauffeur( $id ) {
		return self::ligne( 'chauffeur', (int) $id );
	}

	/**
	 * Crée ou modifie un chauffeur.
	 *
	 * @param array    $d       nom, telephone, service_id, permis_numero, permis_categories, permis_expire, actif.
	 * @param int      $id      Chauffeur.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function enregistrer_chauffeur( array $d, $id = 0, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion du parc.' );
		}
		$row = array(
			'nom'               => mb_substr( trim( sanitize_text_field( (string) ( $d['nom'] ?? '' ) ) ), 0, 120 ),
			'telephone'         => mb_substr( preg_replace( '/[^0-9+ ]/', '', (string) ( $d['telephone'] ?? '' ) ), 0, 30 ),
			'service_id'        => (int) ( $d['service_id'] ?? 0 ) ?: null,
			'permis_numero'     => mb_substr( trim( sanitize_text_field( (string) ( $d['permis_numero'] ?? '' ) ) ), 0, 40 ),
			'permis_categories' => mb_substr( strtoupper( preg_replace( '/[^A-Za-z0-9, ]/', '', (string) ( $d['permis_categories'] ?? '' ) ) ), 0, 20 ),
			'permis_expire'     => IGE_FFM_Contexte::date_valide( (string) ( $d['permis_expire'] ?? '' ) ) ? $d['permis_expire'] : null,
			'actif'             => $id ? ( empty( $d['actif'] ) ? 0 : 1 ) : 1,
		);
		if ( mb_strlen( $row['nom'] ) < 3 ) {
			return new WP_Error( 'nom', 'Indiquez le nom du chauffeur.' );
		}
		if ( '' === $row['permis_numero'] || ! $row['permis_expire'] ) {
			return new WP_Error( 'permis', 'Le numéro et la date de fin de validité du permis sont obligatoires.' );
		}
		if ( $id ) {
			$avant = self::chauffeur( $id );
			if ( ! $avant ) {
				return new WP_Error( 'chauffeur', 'Chauffeur introuvable.' );
			}
			$wpdb->update( IGE_FFM_DB::t( 'chauffeur' ), $row + array( 'updated_at' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'chauffeur', 'chauffeur', $row['nom'], array_intersect_key( $avant, $row ), $row, $user_id );
			return (int) $id;
		}
		$wpdb->insert( IGE_FFM_DB::t( 'chauffeur' ), $row + array( 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$nid = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'chauffeur', 'chauffeur', $row['nom'], null, $row, $user_id );
		return $nid;
	}

	/**
	 * Permis valide à une date ?
	 *
	 * @param array  $c    Chauffeur.
	 * @param string $date Date.
	 * @return bool
	 */
	public static function permis_valide( array $c, $date ) {
		return $c['permis_expire'] && $c['permis_expire'] >= substr( $date, 0, 10 );
	}

	/* ------------------------------------------------------------------ Documents */

	/**
	 * Documents d'un véhicule (les plus récents d'abord).
	 *
	 * @param int $vid Véhicule.
	 * @return array[]
	 */
	public static function documents( $vid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'vehicule_doc' ) . ' WHERE vehicule_id = %d ORDER BY COALESCE(date_fin, date_debut) DESC, id DESC', $vid ), ARRAY_A );
	}

	/**
	 * Enregistre un document de bord (renouvellement compris).
	 *
	 * @param array      $d       vehicule_id, type, reference, organisme, date_debut, date_fin, montant.
	 * @param array|null $file    Scan (facultatif).
	 * @param int|null   $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function ajouter_document( array $d, $file = null, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$v       = self::vehicule( (int) ( $d['vehicule_id'] ?? 0 ) );
		if ( ! $v || ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Véhicule introuvable.' );
		}
		$type = (string) ( $d['type'] ?? '' );
		if ( ! isset( self::types_documents()[ $type ] ) ) {
			return new WP_Error( 'type', 'Choisissez le type de document.' );
		}
		$deb = (string) ( $d['date_debut'] ?? '' );
		$fin = (string) ( $d['date_fin'] ?? '' );
		if ( ( '' !== $deb && ! IGE_FFM_Contexte::date_valide( $deb ) ) || ( '' !== $fin && ! IGE_FFM_Contexte::date_valide( $fin ) ) || ( $deb && $fin && $fin < $deb ) ) {
			return new WP_Error( 'dates', 'Dates de validité invalides.' );
		}
		if ( self::types_documents()[ $type ][1] && '' === $fin ) {
			return new WP_Error( 'date_fin', 'Indiquez la date de fin de validité : elle déclenche les rappels.' );
		}
		$m = '' === trim( (string) ( $d['montant'] ?? '' ) ) ? 0 : ige_ffm_parse_montant( $d['montant'] );
		if ( null === $m || $m < 0 ) {
			return new WP_Error( 'montant', 'Montant invalide.' );
		}
		$row = array(
			'vehicule_id' => (int) $v['id'],
			'type'        => $type,
			'reference'   => mb_substr( trim( sanitize_text_field( (string) ( $d['reference'] ?? '' ) ) ), 0, 60 ),
			'organisme'   => mb_substr( trim( sanitize_text_field( (string) ( $d['organisme'] ?? '' ) ) ), 0, 120 ),
			'date_debut'  => $deb ?: null,
			'date_fin'    => $fin ?: null,
			'montant'     => $m,
			'created_by'  => (int) $user_id,
			'created_at'  => ige_ffm_now(),
		);
		$wpdb->insert( IGE_FFM_DB::t( 'vehicule_doc' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( $file && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			$pid = IGE_FFM_Pieces::enregistrer( $file, 'vehicule_doc', $id, $user_id, self::types_documents()[ $type ][0] );
			if ( ! is_wp_error( $pid ) ) {
				$wpdb->update( IGE_FFM_DB::t( 'vehicule_doc' ), array( 'piece_id' => (int) $pid ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		IGE_FFM_Audit::log( 'document_vehicule', 'vehicule', $v['immatriculation'], null, array( 'document' => self::types_documents()[ $type ][0], 'fin' => $fin, 'montant' => $m ), $user_id );
		return $id;
	}

	/**
	 * État des documents obligatoires d'un véhicule à une date.
	 *
	 * @param int    $vid  Véhicule.
	 * @param string $date Date (aujourd'hui par défaut).
	 * @return array type => [ libellé, état (ok|bientot|expire|manquant), date_fin ].
	 */
	public static function etat_documents( $vid, $date = '' ) {
		$date = $date ?: IGE_FFM_Contexte::aujourdhui();
		$docs = self::documents( $vid );
		$out  = array();
		foreach ( self::types_documents() as $k => $t ) {
			if ( ! $t[1] ) {
				continue;
			}
			$fin = null;
			foreach ( $docs as $doc ) {
				if ( $doc['type'] === $k && $doc['date_fin'] && ( null === $fin || $doc['date_fin'] > $fin ) ) {
					$fin = $doc['date_fin'];
				}
			}
			if ( ! $fin ) {
				$etat = 'manquant';
			} elseif ( $fin < $date ) {
				$etat = 'expire';
			} elseif ( $fin <= gmdate( 'Y-m-d', strtotime( $date . ' +30 days' ) ) ) {
				$etat = 'bientot';
			} else {
				$etat = 'ok';
			}
			$out[ $k ] = array( $t[0], $etat, $fin );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Déplacements */

	/**
	 * Déplacements.
	 *
	 * @param array $f service_id, vehicule_id, statut (chaîne ou liste), exercice_id, limite.
	 * @return array[]
	 */
	public static function deplacements( array $f = array() ) {
		global $wpdb;
		$w = array( '1=1' );
		foreach ( array( 'service_id', 'vehicule_id', 'exercice_id', 'chauffeur_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = $wpdb->prepare( "$k = %d", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( ! empty( $f['statut'] ) ) {
			$st  = array_map( 'sanitize_key', (array) $f['statut'] );
			$w[] = "statut IN ('" . implode( "','", $st ) . "')";
		}
		$lim = ! empty( $f['limite'] ) ? ' LIMIT ' . (int) $f['limite'] : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'deplacement' ) . ' WHERE ' . implode( ' AND ', $w ) . ' ORDER BY depart_prevu DESC, id DESC' . $lim, ARRAY_A );
	}

	/**
	 * Un déplacement.
	 *
	 * @param int $id Déplacement.
	 * @return array|null
	 */
	public static function deplacement( $id ) {
		return self::ligne( 'deplacement', (int) $id );
	}

	/**
	 * Date et heure saisies (AAAA-MM-JJ et HH:MM, ou AAAA-MM-JJTHH:MM).
	 *
	 * @param string $v Valeur.
	 * @return string|null AAAA-MM-JJ HH:MM:00.
	 */
	private static function dateheure( $v ) {
		$v = str_replace( 'T', ' ', trim( (string) $v ) );
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2})( (\d{2}):(\d{2}))?$/', $v, $m ) && IGE_FFM_Contexte::date_valide( $m[1] ) ) {
			$h = isset( $m[3] ) ? (int) $m[3] : 8;
			$i = isset( $m[4] ) ? (int) $m[4] : 0;
			return $h < 24 && $i < 60 ? sprintf( '%s %02d:%02d:00', $m[1], $h, $i ) : null;
		}
		return null;
	}

	/**
	 * Demande de véhicule (un service, ou la gestion du parc pour un service).
	 *
	 * @param array    $d       service_id (gestion), objet, destination, depart, retour, passagers, mission_id.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function demander( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = IGE_FFM_Contexte::service_utilisateur( $user_id );
		if ( ! $sid && self::gestionnaire( $user_id ) ) {
			$sid = (int) ( $d['service_id'] ?? 0 );
			if ( ! IGE_FFM_Referentiels::get( 'service', $sid ) ) {
				return new WP_Error( 'service_id', 'Choisissez le service demandeur.' );
			}
		} elseif ( ! $sid || ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Votre compte ne permet pas de demander un véhicule.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$objet = mb_substr( trim( sanitize_text_field( (string) ( $d['objet'] ?? '' ) ) ), 0, 255 );
		$dest  = mb_substr( trim( sanitize_text_field( (string) ( $d['destination'] ?? '' ) ) ), 0, 190 );
		$dep   = self::dateheure( $d['depart'] ?? '' );
		$ret   = self::dateheure( $d['retour'] ?? '' );
		$pax   = max( 1, min( 60, (int) ( $d['passagers'] ?? 1 ) ) );
		if ( mb_strlen( $objet ) < 5 ) {
			return new WP_Error( 'objet', 'Indiquez l’objet du déplacement.' );
		}
		if ( mb_strlen( $dest ) < 2 ) {
			return new WP_Error( 'destination', 'Indiquez la destination.' );
		}
		if ( ! $dep || ! $ret || $ret <= $dep ) {
			return new WP_Error( 'dates', 'Dates invalides : le retour doit suivre le départ.' );
		}
		if ( substr( $dep, 0, 10 ) < gmdate( 'Y-m-d', strtotime( IGE_FFM_Contexte::aujourdhui() . ' -1 day' ) ) && ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'dates', 'Le départ ne peut pas être dans le passé.' );
		}
		$mid = (int) ( $d['mission_id'] ?? 0 );
		if ( $mid ) {
			$m = IGE_FFM_Missions::get( $mid );
			if ( ! $m || (int) $m['service_id'] !== $sid || ! in_array( $m['statut'], array( 'soumise', 'approuvee', 'payee' ), true ) ) {
				return new WP_Error( 'mission_id', 'Mission introuvable pour ce service.' );
			}
		}
		$t  = IGE_FFM_DB::t( 'deplacement' );
		$id = 0;
		for ( $essai = 0; $essai < 5 && ! $id; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d", $ex['id'] ) ) + $essai;
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'numero'       => sprintf( 'OR-%d-%03d', $ex['annee'], $n ),
					'exercice_id'  => (int) $ex['id'],
					'service_id'   => $sid,
					'demandeur_id' => (int) $user_id,
					'objet'        => $objet,
					'destination'  => $dest,
					'depart_prevu' => $dep,
					'retour_prevu' => $ret,
					'passagers'    => $pax,
					'mission_id'   => $mid ?: null,
					'statut'       => 'demande',
					'created_at'   => ige_ffm_now(),
				)
			);
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				$id = (int) $wpdb->insert_id;
			}
		}
		if ( ! $id ) {
			return new WP_Error( 'numero', 'Enregistrement impossible pour le moment. Réessayez.' );
		}
		$x = self::deplacement( $id );
		IGE_FFM_Audit::log( 'demande_vehicule', 'deplacement', $x['numero'], null, array( 'objet' => $objet, 'destination' => $dest, 'depart' => $dep, 'retour' => $ret ), $user_id );
		if ( ! self::gestionnaire( $user_id ) ) {
			self::prevenir_gestion( 'Demande de véhicule ' . $x['numero'], IGE_FFM_Comptes::service_code( $sid ) . ' demande un véhicule pour « ' . $objet . ' » (' . $dest . '), du ' . wp_date( 'd/m/Y H:i', strtotime( $dep ) ) . ' au ' . wp_date( 'd/m/Y H:i', strtotime( $ret ) ) . ".\n\nAttribuez un véhicule et un chauffeur : " . add_query_arg( 'onglet', 'deplacements', ige_ffm_url( 'parc-automobile' ) ) );
		}
		return $id;
	}

	/**
	 * Déplacements qui chevauchent une période pour un véhicule ou un chauffeur.
	 *
	 * @param string $col    vehicule_id | chauffeur_id.
	 * @param int    $val    Identifiant.
	 * @param string $debut  Début.
	 * @param string $fin    Fin.
	 * @param int    $exclure Déplacement à ignorer.
	 * @return array[]
	 */
	public static function conflits( $col, $val, $debut, $fin, $exclure = 0 ) {
		global $wpdb;
		$col = 'chauffeur_id' === $col ? 'chauffeur_id' : 'vehicule_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'deplacement' ) . " WHERE $col = %d AND id <> %d AND statut IN ('attribue','en_cours') AND depart_prevu < %s AND retour_prevu > %s", $val, $exclure, $fin, $debut ), ARRAY_A );
	}

	/**
	 * Attribue un véhicule et un chauffeur à une demande (contrôles : disponibilité, documents, permis).
	 *
	 * @param int      $id      Déplacement.
	 * @param int      $vid     Véhicule.
	 * @param int      $cid     Chauffeur (0 = conduit par le demandeur, déconseillé).
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function attribuer( $id, $vid, $cid, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$x       = self::deplacement( (int) $id );
		if ( ! $x || ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Demande introuvable.' );
		}
		if ( ! in_array( $x['statut'], array( 'demande', 'attribue' ), true ) ) {
			return new WP_Error( 'etat', 'Ce déplacement ne peut plus être attribué.' );
		}
		$v = self::vehicule( (int) $vid );
		if ( ! $v ) {
			return new WP_Error( 'vehicule', 'Choisissez un véhicule.' );
		}
		if ( 'disponible' !== $v['statut'] ) {
			return new WP_Error( 'vehicule', self::nom( $v ) . ' est ' . strtolower( self::statuts()[ $v['statut'] ][0] ) . ( $v['motif_statut'] ? ' (' . $v['motif_statut'] . ')' : '' ) . '.' );
		}
		if ( $v['places'] && (int) $x['passagers'] > (int) $v['places'] ) {
			return new WP_Error( 'places', sprintf( '%s n’a que %d places pour %d passagers.', self::nom( $v ), $v['places'], $x['passagers'] ) );
		}
		$c = self::conflits( 'vehicule_id', (int) $vid, $x['depart_prevu'], $x['retour_prevu'], (int) $id );
		if ( $c ) {
			return new WP_Error( 'conflit', self::nom( $v ) . ' est déjà retenu pour ' . $c[0]['numero'] . ' (' . wp_date( 'd/m H:i', strtotime( $c[0]['depart_prevu'] ) ) . ' – ' . wp_date( 'd/m H:i', strtotime( $c[0]['retour_prevu'] ) ) . ').' );
		}
		foreach ( self::etat_documents( (int) $vid, substr( $x['depart_prevu'], 0, 10 ) ) as $k => $e ) {
			if ( in_array( $k, array( 'assurance', 'controle_technique' ), true ) && in_array( $e[1], array( 'expire', 'manquant' ), true ) ) {
				return new WP_Error( 'documents', $e[0] . ' de ' . $v['immatriculation'] . ( 'expire' === $e[1] ? ' expiré le ' . wp_date( 'd/m/Y', strtotime( $e[2] ) ) : ' non enregistré' ) . ' : le véhicule ne peut pas circuler.' );
			}
		}
		if ( $cid ) {
			$ch = self::chauffeur( (int) $cid );
			if ( ! $ch || ! (int) $ch['actif'] ) {
				return new WP_Error( 'chauffeur', 'Chauffeur introuvable ou inactif.' );
			}
			if ( ! self::permis_valide( $ch, $x['retour_prevu'] ) ) {
				return new WP_Error( 'permis', 'Le permis de ' . $ch['nom'] . ' n’est pas valide pour toute la durée du déplacement.' );
			}
			$cc = self::conflits( 'chauffeur_id', (int) $cid, $x['depart_prevu'], $x['retour_prevu'], (int) $id );
			if ( $cc ) {
				return new WP_Error( 'conflit', $ch['nom'] . ' conduit déjà pour ' . $cc[0]['numero'] . '.' );
			}
		}
		$wpdb->update( IGE_FFM_DB::t( 'deplacement' ), array( 'vehicule_id' => (int) $vid, 'chauffeur_id' => $cid ? (int) $cid : null, 'statut' => 'attribue', 'traite_par' => (int) $user_id, 'traite_le' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'attribution_vehicule', 'deplacement', $x['numero'], null, array( 'vehicule' => self::nom( $v ), 'chauffeur' => $cid ? self::chauffeur( (int) $cid )['nom'] : '' ), $user_id );
		self::prevenir_demandeur( $x, 'Votre demande de véhicule ' . $x['numero'] . ' est accordée : ' . self::nom( $v ) . ( $cid ? ', chauffeur ' . self::chauffeur( (int) $cid )['nom'] : '' ) . '. Départ prévu le ' . wp_date( 'd/m/Y à H:i', strtotime( $x['depart_prevu'] ) ) . '.' );
		return true;
	}

	/**
	 * Refus ou annulation d'un déplacement.
	 *
	 * @param int      $id      Déplacement.
	 * @param string   $statut  refuse | annule.
	 * @param string   $motif   Motif.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function clore( $id, $statut, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$x       = self::deplacement( (int) $id );
		if ( ! $x ) {
			return new WP_Error( 'deplacement', 'Déplacement introuvable.' );
		}
		$gest = self::gestionnaire( $user_id );
		$serv = IGE_FFM_Contexte::service_utilisateur( $user_id ) === (int) $x['service_id'] && IGE_FFM_Contexte::peut_saisir( (int) $x['service_id'], $user_id );
		if ( 'refuse' === $statut ? ! $gest : ! ( $gest || $serv ) ) {
			return new WP_Error( 'droits', 'Action réservée.' );
		}
		if ( ! in_array( $x['statut'], 'refuse' === $statut ? array( 'demande' ) : array( 'demande', 'attribue' ), true ) ) {
			return new WP_Error( 'etat', 'Ce déplacement ne peut plus être ' . ( 'refuse' === $statut ? 'refusé' : 'annulé' ) . '.' );
		}
		$motif = mb_substr( trim( sanitize_text_field( (string) $motif ) ), 0, 255 );
		if ( mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'deplacement' ), array( 'statut' => 'refuse' === $statut ? 'refuse' : 'annule', 'motif' => $motif, 'traite_par' => (int) $user_id, 'traite_le' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'refuse' === $statut ? 'refus_vehicule' : 'annulation_deplacement', 'deplacement', $x['numero'], null, array( 'motif' => $motif ), $user_id );
		if ( 'refuse' === $statut ) {
			self::prevenir_demandeur( $x, 'Votre demande de véhicule ' . $x['numero'] . ' n’a pas pu être satisfaite : ' . $motif . '.' );
		}
		return true;
	}

	/**
	 * Peut-on enregistrer le départ ou le retour (gestion du parc, ou service du déplacement) ?
	 *
	 * @param array $x       Déplacement.
	 * @param int   $user_id Utilisateur.
	 * @return bool
	 */
	public static function peut_conduire( array $x, $user_id ) {
		return self::gestionnaire( $user_id ) || ( IGE_FFM_Contexte::service_utilisateur( $user_id ) === (int) $x['service_id'] && IGE_FFM_Contexte::peut_saisir( (int) $x['service_id'], $user_id ) );
	}

	/**
	 * Départ : kilométrage au compteur.
	 *
	 * @param int      $id      Déplacement attribué.
	 * @param mixed    $km      Kilométrage.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error [ 'ecart' => km non justifiés depuis le dernier relevé ].
	 */
	public static function depart( $id, $km, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$x       = self::deplacement( (int) $id );
		if ( ! $x || ! self::peut_conduire( $x, $user_id ) ) {
			return new WP_Error( 'droits', 'Déplacement introuvable.' );
		}
		if ( 'attribue' !== $x['statut'] ) {
			return new WP_Error( 'etat', 'Le départ s’enregistre sur un déplacement dont le véhicule est attribué.' );
		}
		$v = self::vehicule( (int) $x['vehicule_id'] );
		if ( self::en_route( (int) $v['id'] ) ) {
			return new WP_Error( 'etat', 'Ce véhicule n’est pas encore rentré de son déplacement précédent.' );
		}
		$k = self::km( $km );
		if ( null === $k ) {
			return new WP_Error( 'km', 'Indiquez le kilométrage au compteur.' );
		}
		if ( $k < (int) $v['km_actuel'] ) {
			return new WP_Error( 'km', sprintf( 'Le compteur ne peut pas reculer : dernier relevé %s km.', number_format( (int) $v['km_actuel'], 0, ',', "\u{202F}" ) ) );
		}
		$ecart = $k - (int) $v['km_actuel'];
		$wpdb->update( IGE_FFM_DB::t( 'deplacement' ), array( 'statut' => 'en_cours', 'km_depart' => $k, 'sortie_le' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::releve( (int) $v['id'], $k );
		IGE_FFM_Audit::log( 'depart_vehicule', 'deplacement', $x['numero'], null, array_filter( array( 'km' => $k, 'km_non_justifies' => $ecart ) ), $user_id );
		return array( 'ecart' => $ecart );
	}

	/**
	 * Retour : kilométrage, observations (incident, panne).
	 *
	 * @param int      $id      Déplacement en cours.
	 * @param mixed    $km      Kilométrage.
	 * @param string   $obs     Observations.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error [ 'distance' => km ].
	 */
	public static function retour( $id, $km, $obs = '', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$x       = self::deplacement( (int) $id );
		if ( ! $x || ! self::peut_conduire( $x, $user_id ) ) {
			return new WP_Error( 'droits', 'Déplacement introuvable.' );
		}
		if ( 'en_cours' !== $x['statut'] ) {
			return new WP_Error( 'etat', 'Ce véhicule n’est pas en route.' );
		}
		$k = self::km( $km );
		if ( null === $k || $k < (int) $x['km_depart'] ) {
			return new WP_Error( 'km', sprintf( 'Kilométrage au retour invalide : au moins %s km (départ).', number_format( (int) $x['km_depart'], 0, ',', "\u{202F}" ) ) );
		}
		if ( $k - (int) $x['km_depart'] > 5000 ) {
			return new WP_Error( 'km', 'Plus de 5 000 km pour un seul déplacement : vérifiez le compteur.' );
		}
		$obs = mb_substr( trim( sanitize_textarea_field( (string) $obs ) ), 0, 2000 );
		$wpdb->update( IGE_FFM_DB::t( 'deplacement' ), array( 'statut' => 'termine', 'km_retour' => $k, 'retour_le' => ige_ffm_now(), 'observations' => $obs ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::releve( (int) $x['vehicule_id'], $k );
		$dist = $k - (int) $x['km_depart'];
		IGE_FFM_Audit::log( 'retour_vehicule', 'deplacement', $x['numero'], null, array_filter( array( 'km' => $k, 'distance' => $dist, 'observations' => $obs ) ), $user_id );
		if ( '' !== $obs ) {
			self::prevenir_gestion( 'Retour du véhicule ' . self::vehicule( (int) $x['vehicule_id'] )['immatriculation'], 'Observations au retour de ' . $x['numero'] . ' : ' . $obs . "\n\nFiche du véhicule : " . add_query_arg( 'vehicule', (int) $x['vehicule_id'], ige_ffm_url( 'parc-automobile' ) ) );
		}
		return array( 'distance' => $dist );
	}

	/* ------------------------------------------------------------------ Carburant */

	/**
	 * Écriture du livre de caisse désignée par son numéro (facultatif).
	 *
	 * @param string $ref Numéro (ex. 8000IG-2026-000015).
	 * @return int|null|WP_Error Identifiant, null si vide.
	 */
	public static function ecriture_ref( $ref ) {
		$ref = strtoupper( trim( sanitize_text_field( (string) $ref ) ) );
		if ( '' === $ref ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE id_ecriture = %s AND type = 'sortie'", $ref ) );
		return $id ?: new WP_Error( 'ecriture', 'Écriture ' . $ref . ' introuvable dans les livres de caisse (sorties).' );
	}

	/**
	 * Peut-on saisir un plein pour ce véhicule (gestion du parc, ou service d'affectation) ?
	 *
	 * @param array $v       Véhicule.
	 * @param int   $user_id Utilisateur.
	 * @return bool
	 */
	public static function peut_plein( array $v, $user_id ) {
		return self::gestionnaire( $user_id ) || ( $v['service_id'] && IGE_FFM_Contexte::service_utilisateur( $user_id ) === (int) $v['service_id'] && IGE_FFM_Contexte::peut_saisir( (int) $v['service_id'], $user_id ) );
	}

	/**
	 * Enregistre un plein ; calcule la consommation entre deux pleins complets et signale les anomalies.
	 *
	 * @param array    $d       vehicule_id, date, litres, montant, km, plein, station, mode, bon_numero, ecriture.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error [ 'id', 'conso', 'anomalie' ].
	 */
	public static function ajouter_plein( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$v       = self::vehicule( (int) ( $d['vehicule_id'] ?? 0 ) );
		if ( ! $v || ! self::peut_plein( $v, $user_id ) ) {
			return new WP_Error( 'droits', 'Véhicule introuvable ou non affecté à votre service.' );
		}
		$ex   = IGE_FFM_Contexte::exercice();
		$date = (string) ( $d['date'] ?? '' );
		if ( ! $ex || ! IGE_FFM_Contexte::date_valide( $date ) || $date > IGE_FFM_Contexte::aujourdhui() || $date < $ex['date_debut'] ) {
			return new WP_Error( 'date', 'Date du plein invalide (dans l’exercice, pas dans le futur).' );
		}
		$l = IGE_FFM_Devises::nombre( $d['litres'] ?? '' );
		if ( null === $l || $l <= 0 || $l > 1000 ) {
			return new WP_Error( 'litres', 'Quantité en litres invalide.' );
		}
		if ( $v['reservoir'] && $l > (float) $v['reservoir'] * 1.05 ) {
			return new WP_Error( 'litres', sprintf( '%s litres dépassent la capacité du réservoir (%d L) : vérifiez le bon.', number_format( $l, 2, ',', ' ' ), $v['reservoir'] ) );
		}
		$m = ige_ffm_parse_montant( $d['montant'] ?? '' );
		if ( null === $m || $m <= 0 ) {
			return new WP_Error( 'montant', 'Montant en CDF invalide.' );
		}
		$k = self::km( $d['km'] ?? '' );
		if ( null === $k ) {
			return new WP_Error( 'km', 'Indiquez le kilométrage au compteur au moment du plein.' );
		}
		$t = IGE_FFM_DB::t( 'carburant' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avant_km = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(km) FROM $t WHERE vehicule_id = %d AND date_plein <= %s", $v['id'], $date ) );
		if ( $k < $avant_km ) {
			return new WP_Error( 'km', sprintf( 'Kilométrage inférieur au plein précédent (%s km).', number_format( $avant_km, 0, ',', "\u{202F}" ) ) );
		}
		$eid = self::ecriture_ref( $d['ecriture'] ?? '' );
		if ( is_wp_error( $eid ) ) {
			return $eid;
		}
		$mode  = in_array( $d['mode'] ?? '', array( 'bon', 'carte', 'especes' ), true ) ? $d['mode'] : 'bon';
		$plein = ! empty( $d['plein'] );
		$ctrl  = IGE_FFM_Carburant::controler( $v, $date, $l, $m, $mode, (string) ( $d['bon_numero'] ?? '' ), (string) ( $d['derogation'] ?? '' ), $user_id );
		if ( is_wp_error( $ctrl ) ) {
			return $ctrl;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$t,
			array(
				'vehicule_id' => (int) $v['id'],
				'exercice_id' => (int) $ex['id'],
				'date_plein'  => $date,
				'litres'      => $l,
				'montant'     => $m,
				'km'          => $k,
				'plein'       => $plein ? 1 : 0,
				'station'     => mb_substr( trim( sanitize_text_field( (string) ( $d['station'] ?? '' ) ) ), 0, 120 ),
				'mode'        => $mode,
				'bon_numero'  => mb_substr( implode( ', ', IGE_FFM_Carburant::numeros( (string) ( $d['bon_numero'] ?? '' ) ) ), 0, 120 ),
				'service_id'  => $v['service_id'] ? (int) $v['service_id'] : null,
				'derogation'  => $ctrl['derogation'],
				'ecriture_id' => $eid,
				'saisi_par'   => (int) $user_id,
				'created_at'  => ige_ffm_now(),
			)
		);
		$id   = (int) $wpdb->insert_id;
		IGE_FFM_Carburant::apres( $id, $ctrl );
		$anom = $ctrl['anomalies'];
		$conso = null;
		if ( $plein ) {
			// Plein complet précédent : la consommation = litres mis depuis ce plein / distance parcourue.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$prec = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE vehicule_id = %d AND plein = 1 AND id <> %d AND km < %d ORDER BY km DESC, id DESC LIMIT 1", $v['id'], $id, $k ), ARRAY_A );
			if ( $prec ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$litres = (float) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(litres) FROM $t WHERE vehicule_id = %d AND km > %d AND km <= %d", $v['id'], $prec['km'], $k ) );
				$dist   = $k - (int) $prec['km'];
				if ( $dist > 0 ) {
					$conso = round( $litres / $dist * 100, 2 );
					$tol   = max( 0, (int) IGE_FFM_Reglages::get( 'parc_tolerance_conso' ) );
					if ( $v['conso_ref'] && $conso > (float) $v['conso_ref'] * ( 1 + $tol / 100 ) ) {
						$anom[] = sprintf( 'Consommation de %s L/100 km pour une référence de %s (+%d %%)', number_format( $conso, 1, ',', ' ' ), number_format( (float) $v['conso_ref'], 1, ',', ' ' ), round( ( $conso / (float) $v['conso_ref'] - 1 ) * 100 ) );
					}
				}
			}
		}
		$prix = $m / $l;
		$ref  = (float) IGE_FFM_Reglages::get( 'parc_prix_litre' );
		if ( $ref > 0 && $prix > $ref * 1.25 ) {
			$anom[] = sprintf( 'Prix de %s CDF le litre, supérieur de plus de 25 %% au prix de référence', number_format( $prix, 0, ',', ' ' ) );
		}
		if ( $k > (int) $v['km_actuel'] + 2000 ) {
			$anom[] = sprintf( '%s km non expliqués par les déplacements depuis le dernier relevé', number_format( $k - (int) $v['km_actuel'], 0, ',', ' ' ) );
		}
		$wpdb->update( $t, array( 'conso' => $conso, 'anomalie' => mb_substr( implode( ' ; ', $anom ), 0, 255 ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::releve( (int) $v['id'], $k );
		IGE_FFM_Audit::log( 'carburant', 'vehicule', $v['immatriculation'], null, array_filter( array( 'litres' => $l, 'montant' => $m, 'km' => $k, 'conso' => $conso, 'anomalie' => implode( ' ; ', $anom ) ) ), $user_id );
		return array( 'id' => $id, 'conso' => $conso, 'anomalie' => implode( ' ; ', $anom ), 'alertes' => $ctrl['alertes'] );
	}

	/**
	 * Pleins.
	 *
	 * @param array $f vehicule_id, exercice_id, service_id (véhicules affectés), anomalies, limite.
	 * @return array[]
	 */
	public static function pleins( array $f = array() ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'carburant' );
		$w = array( '1=1' );
		if ( ! empty( $f['vehicule_id'] ) ) {
			$w[] = $wpdb->prepare( 'c.vehicule_id = %d', $f['vehicule_id'] );
		}
		if ( ! empty( $f['exercice_id'] ) ) {
			$w[] = $wpdb->prepare( 'c.exercice_id = %d', $f['exercice_id'] );
		}
		if ( ! empty( $f['service_id'] ) ) {
			$w[] = $wpdb->prepare( 'v.service_id = %d', $f['service_id'] );
		}
		if ( ! empty( $f['anomalies'] ) ) {
			$w[] = "c.anomalie <> ''";
		}
		$lim = ! empty( $f['limite'] ) ? ' LIMIT ' . (int) $f['limite'] : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT c.*, v.immatriculation FROM $t c JOIN " . IGE_FFM_DB::t( 'vehicule' ) . ' v ON v.id = c.vehicule_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY c.date_plein DESC, c.id DESC' . $lim, ARRAY_A );
	}

	/* ------------------------------------------------------------------ Entretien et sinistres */

	/**
	 * Planifie ou enregistre une intervention (entretien, réparation, pneus, sinistre).
	 *
	 * @param array      $d       vehicule_id, nature, objet, date_prevue, date_realisee, km, garage, montant, prochaine_km, prochaine_date, immobilise, description, ecriture.
	 * @param array|null $file    Facture ou constat (facultatif).
	 * @param int|null   $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function intervention( array $d, $file = null, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$v       = self::vehicule( (int) ( $d['vehicule_id'] ?? 0 ) );
		if ( ! $v || ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Véhicule introuvable.' );
		}
		$ex     = IGE_FFM_Contexte::exercice();
		$nature = isset( self::natures()[ $d['nature'] ?? '' ] ) ? $d['nature'] : '';
		$objet  = mb_substr( trim( sanitize_text_field( (string) ( $d['objet'] ?? '' ) ) ), 0, 190 );
		if ( ! $ex || ! $nature || mb_strlen( $objet ) < 3 ) {
			return new WP_Error( 'objet', 'Indiquez la nature et l’objet de l’intervention.' );
		}
		$prev = (string) ( $d['date_prevue'] ?? '' );
		$real = (string) ( $d['date_realisee'] ?? '' );
		if ( ( '' !== $prev && ! IGE_FFM_Contexte::date_valide( $prev ) ) || ( '' !== $real && ( ! IGE_FFM_Contexte::date_valide( $real ) || $real > IGE_FFM_Contexte::aujourdhui() ) ) || ( '' === $prev && '' === $real ) ) {
			return new WP_Error( 'dates', 'Indiquez la date prévue (planification) ou la date de réalisation (pas dans le futur).' );
		}
		$m = '' === trim( (string) ( $d['montant'] ?? '' ) ) ? 0 : ige_ffm_parse_montant( $d['montant'] );
		if ( null === $m || $m < 0 ) {
			return new WP_Error( 'montant', 'Montant invalide.' );
		}
		$k  = '' === trim( (string) ( $d['km'] ?? '' ) ) ? null : self::km( $d['km'] );
		$pk = '' === trim( (string) ( $d['prochaine_km'] ?? '' ) ) ? null : self::km( $d['prochaine_km'] );
		$pd = (string) ( $d['prochaine_date'] ?? '' );
		if ( ( '' !== trim( (string) ( $d['km'] ?? '' ) ) && null === $k ) || ( null !== $pk && null !== $k && $pk <= $k ) || ( '' !== $pd && ! IGE_FFM_Contexte::date_valide( $pd ) ) ) {
			return new WP_Error( 'km', 'Kilométrages ou échéance invalides (la prochaine échéance suit l’intervention).' );
		}
		$eid = self::ecriture_ref( $d['ecriture'] ?? '' );
		if ( is_wp_error( $eid ) ) {
			return $eid;
		}
		$realise = '' !== $real;
		$row     = array(
			'vehicule_id'    => (int) $v['id'],
			'exercice_id'    => (int) $ex['id'],
			'nature'         => $nature,
			'objet'          => $objet,
			'date_prevue'    => $prev ?: null,
			'date_realisee'  => $real ?: null,
			'km'             => $k,
			'garage'         => mb_substr( trim( sanitize_text_field( (string) ( $d['garage'] ?? '' ) ) ), 0, 120 ),
			'montant'        => $m,
			'statut'         => $realise ? 'realise' : 'planifie',
			'prochaine_km'   => $pk,
			'prochaine_date' => $pd ?: null,
			'immobilise'     => empty( $d['immobilise'] ) ? 0 : 1,
			'description'    => mb_substr( trim( sanitize_textarea_field( (string) ( $d['description'] ?? '' ) ) ), 0, 4000 ),
			'ecriture_id'    => $eid,
			'created_by'     => (int) $user_id,
			'created_at'     => ige_ffm_now(),
		);
		if ( 'sinistre' === $nature && mb_strlen( $row['description'] ) < 10 ) {
			return new WP_Error( 'description', 'Décrivez le sinistre : circonstances, lieu, tiers, constat, déclaration à l’assurance.' );
		}
		$wpdb->insert( IGE_FFM_DB::t( 'entretien' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		if ( $file && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			$pid = IGE_FFM_Pieces::enregistrer( $file, 'entretien', $id, $user_id, 'sinistre' === $nature ? 'Constat' : 'Facture' );
			if ( ! is_wp_error( $pid ) ) {
				$wpdb->update( IGE_FFM_DB::t( 'entretien' ), array( 'piece_id' => (int) $pid ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
		if ( $k ) {
			self::releve( (int) $v['id'], $k );
		}
		if ( $row['immobilise'] && ! $realise && 'disponible' === $v['statut'] && ! self::en_route( (int) $v['id'] ) ) {
			self::changer_statut( (int) $v['id'], 'immobilise', self::natures()[ $nature ] . ' : ' . $objet, $user_id );
		}
		IGE_FFM_Audit::log( 'sinistre' === $nature ? 'sinistre_vehicule' : 'entretien_vehicule', 'vehicule', $v['immatriculation'], null, array_filter( array( 'nature' => self::natures()[ $nature ], 'objet' => $objet, 'statut' => $realise ? 'réalisé' : 'planifié', 'montant' => $m ) ), $user_id );
		return $id;
	}

	/**
	 * Clôt une intervention planifiée (réalisée).
	 *
	 * @param int      $id      Intervention.
	 * @param array    $d       date_realisee, km, montant, garage, prochaine_km, prochaine_date, ecriture.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function realiser( $id, array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = self::ligne( 'entretien', (int) $id );
		if ( ! $e || ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Intervention introuvable.' );
		}
		if ( 'planifie' !== $e['statut'] ) {
			return new WP_Error( 'etat', 'Cette intervention est déjà close.' );
		}
		$real = (string) ( $d['date_realisee'] ?? '' );
		if ( ! IGE_FFM_Contexte::date_valide( $real ) || $real > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'Date de réalisation invalide.' );
		}
		$m  = ige_ffm_parse_montant( '' === trim( (string) ( $d['montant'] ?? '' ) ) ? '0' : $d['montant'] );
		$k  = '' === trim( (string) ( $d['km'] ?? '' ) ) ? null : self::km( $d['km'] );
		$pk = '' === trim( (string) ( $d['prochaine_km'] ?? '' ) ) ? null : self::km( $d['prochaine_km'] );
		$pd = (string) ( $d['prochaine_date'] ?? '' );
		if ( null === $m || $m < 0 || ( null !== $pk && null !== $k && $pk <= $k ) || ( '' !== $pd && ! IGE_FFM_Contexte::date_valide( $pd ) ) ) {
			return new WP_Error( 'valeurs', 'Montant, kilométrage ou prochaine échéance invalides.' );
		}
		$eid = self::ecriture_ref( $d['ecriture'] ?? '' );
		if ( is_wp_error( $eid ) ) {
			return $eid;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'entretien' ),
			array_filter(
				array(
					'statut'         => 'realise',
					'date_realisee'  => $real,
					'km'             => $k,
					'montant'        => $m,
					'garage'         => mb_substr( trim( sanitize_text_field( (string) ( $d['garage'] ?? '' ) ) ), 0, 120 ) ?: $e['garage'],
					'prochaine_km'   => $pk,
					'prochaine_date' => $pd ?: null,
					'ecriture_id'    => $eid,
				),
				static fn( $x ) => null !== $x
			),
			array( 'id' => (int) $id )
		);
		if ( $k ) {
			self::releve( (int) $e['vehicule_id'], $k );
		}
		$v = self::vehicule( (int) $e['vehicule_id'] );
		if ( (int) $e['immobilise'] && 'immobilise' === $v['statut'] ) {
			self::changer_statut( (int) $v['id'], 'disponible', '', $user_id );
		}
		IGE_FFM_Audit::log( 'entretien_vehicule', 'vehicule', $v['immatriculation'], array( 'statut' => 'planifié' ), array( 'objet' => $e['objet'], 'statut' => 'réalisé', 'montant' => $m ), $user_id );
		return true;
	}

	/**
	 * Interventions.
	 *
	 * @param array $f vehicule_id, statut, exercice_id, nature, limite.
	 * @return array[]
	 */
	public static function interventions( array $f = array() ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'entretien' );
		$w = array( '1=1' );
		foreach ( array( 'vehicule_id', 'exercice_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = $wpdb->prepare( "e.$k = %d", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		foreach ( array( 'statut', 'nature' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = $wpdb->prepare( "e.$k = %s", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$lim = ! empty( $f['limite'] ) ? ' LIMIT ' . (int) $f['limite'] : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT e.*, v.immatriculation FROM $t e JOIN " . IGE_FFM_DB::t( 'vehicule' ) . ' v ON v.id = e.vehicule_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY COALESCE(e.date_realisee, e.date_prevue) DESC, e.id DESC' . $lim, ARRAY_A );
	}

	/**
	 * Échéances d'entretien d'un véhicule : planifiés et prochaines échéances des derniers entretiens réalisés.
	 *
	 * @param array $v Véhicule.
	 * @return array[] objet, date, km, du (bool), raison.
	 */
	public static function echeances( array $v ) {
		$auj = IGE_FFM_Contexte::aujourdhui();
		$out = array();
		$vus = array();
		foreach ( self::interventions( array( 'vehicule_id' => (int) $v['id'] ) ) as $e ) {
			if ( 'planifie' === $e['statut'] ) {
				$du    = $e['date_prevue'] && $e['date_prevue'] <= $auj;
				$out[] = array( 'objet' => $e['objet'], 'date' => $e['date_prevue'], 'km' => null, 'du' => $du, 'raison' => $du ? 'date dépassée' : '', 'id' => (int) $e['id'], 'planifie' => true );
				continue;
			}
			if ( 'realise' !== $e['statut'] || ( ! $e['prochaine_km'] && ! $e['prochaine_date'] ) ) {
				continue;
			}
			$cle = mb_strtolower( $e['nature'] . '|' . $e['objet'] );
			if ( isset( $vus[ $cle ] ) ) {
				continue; // Seule la dernière intervention de même objet compte.
			}
			$vus[ $cle ] = true;
			$par_km      = $e['prochaine_km'] && (int) $v['km_actuel'] >= (int) $e['prochaine_km'] - 500;
			$par_date    = $e['prochaine_date'] && $e['prochaine_date'] <= gmdate( 'Y-m-d', strtotime( $auj . ' +15 days' ) );
			$out[]       = array( 'objet' => $e['objet'], 'date' => $e['prochaine_date'], 'km' => $e['prochaine_km'] ? (int) $e['prochaine_km'] : null, 'du' => $par_km || $par_date, 'raison' => $par_km ? 'kilométrage atteint' : ( $par_date ? 'échéance proche' : '' ), 'id' => (int) $e['id'], 'planifie' => false );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Coûts et alertes */

	/**
	 * Coûts par véhicule sur l'exercice.
	 *
	 * @param int $exo Exercice.
	 * @return array vehicule_id => carburant, litres, entretien, sinistres, documents, total, km, cout_km, conso.
	 */
	public static function couts( $exo ) {
		global $wpdb;
		$ex = null;
		foreach ( IGE_FFM_Referentiels::exercices() as $x ) {
			if ( (int) $x['id'] === (int) $exo ) {
				$ex = $x;
			}
		}
		$out = array();
		foreach ( self::vehicules() as $v ) {
			$out[ (int) $v['id'] ] = array( 'vehicule' => $v, 'carburant' => 0, 'litres' => 0.0, 'entretien' => 0, 'sinistres' => 0, 'documents' => 0, 'km' => 0, 'kms' => array() );
		}
		$tc = IGE_FFM_DB::t( 'carburant' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT vehicule_id, SUM(montant) m, SUM(litres) l, MIN(km) k1, MAX(km) k2 FROM $tc WHERE exercice_id = %d GROUP BY vehicule_id", $exo ), ARRAY_A ) as $r ) {
			if ( isset( $out[ $r['vehicule_id'] ] ) ) {
				$out[ $r['vehicule_id'] ]['carburant'] = (int) $r['m'];
				$out[ $r['vehicule_id'] ]['litres']    = (float) $r['l'];
				array_push( $out[ $r['vehicule_id'] ]['kms'], (int) $r['k1'], (int) $r['k2'] );
			}
		}
		$te = IGE_FFM_DB::t( 'entretien' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT vehicule_id, nature, SUM(montant) m FROM $te WHERE exercice_id = %d AND statut = 'realise' GROUP BY vehicule_id, nature", $exo ), ARRAY_A ) as $r ) {
			if ( isset( $out[ $r['vehicule_id'] ] ) ) {
				$out[ $r['vehicule_id'] ][ 'sinistre' === $r['nature'] ? 'sinistres' : 'entretien' ] += (int) $r['m'];
			}
		}
		if ( $ex ) {
			$td = IGE_FFM_DB::t( 'vehicule_doc' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT vehicule_id, SUM(montant) m FROM $td WHERE COALESCE(date_debut, DATE(created_at)) BETWEEN %s AND %s GROUP BY vehicule_id", $ex['date_debut'], $ex['date_fin'] ), ARRAY_A ) as $r ) {
				if ( isset( $out[ $r['vehicule_id'] ] ) ) {
					$out[ $r['vehicule_id'] ]['documents'] = (int) $r['m'];
				}
			}
			$tdp = IGE_FFM_DB::t( 'deplacement' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT vehicule_id, MIN(km_depart) k1, MAX(km_retour) k2 FROM $tdp WHERE exercice_id = %d AND statut = 'termine' GROUP BY vehicule_id", $exo ), ARRAY_A ) as $r ) {
				if ( isset( $out[ $r['vehicule_id'] ] ) ) {
					array_push( $out[ $r['vehicule_id'] ]['kms'], (int) $r['k1'], (int) $r['k2'] );
				}
			}
		}
		foreach ( $out as &$c ) {
			$c['km']      = $c['kms'] ? max( $c['kms'] ) - min( $c['kms'] ) : 0;
			$c['total']   = $c['carburant'] + $c['entretien'] + $c['sinistres'] + $c['documents'];
			$c['cout_km'] = $c['km'] > 0 ? (int) round( $c['total'] / $c['km'] ) : null;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$moy        = $wpdb->get_var( $wpdb->prepare( "SELECT AVG(conso) FROM $tc WHERE vehicule_id = %d AND exercice_id = %d AND conso IS NOT NULL", $c['vehicule']['id'], $exo ) );
			$c['conso'] = null === $moy ? null : round( (float) $moy, 1 );
			unset( $c['kms'] );
		}
		unset( $c );
		return $out;
	}

	/**
	 * Alertes du parc : [ niveau, texte, lien ].
	 *
	 * @return array[]
	 */
	public static function alertes() {
		$out  = array();
		$auj  = IGE_FFM_Contexte::aujourdhui();
		$base = ige_ffm_url( 'parc-automobile' );
		foreach ( self::vehicules( array( 'sans_reformes' => true ) ) as $v ) {
			$lien = add_query_arg( 'vehicule', $v['id'], $base );
			foreach ( self::etat_documents( (int) $v['id'] ) as $e ) {
				if ( 'expire' === $e[1] ) {
					$out[] = array( 'ko', sprintf( '%s : %s expiré(e) depuis le %s.', $v['immatriculation'], $e[0], wp_date( 'd/m/Y', strtotime( $e[2] ) ) ), $lien );
				} elseif ( 'manquant' === $e[1] ) {
					$out[] = array( 'warn', sprintf( '%s : %s non enregistré(e).', $v['immatriculation'], $e[0] ), $lien );
				} elseif ( 'bientot' === $e[1] ) {
					$out[] = array( 'warn', sprintf( '%s : %s à renouveler avant le %s.', $v['immatriculation'], $e[0], wp_date( 'd/m/Y', strtotime( $e[2] ) ) ), $lien );
				}
			}
			foreach ( self::echeances( $v ) as $e ) {
				if ( $e['du'] ) {
					$out[] = array( 'warn', sprintf( '%s : %s à faire (%s).', $v['immatriculation'], $e['objet'], $e['raison'] ), $lien );
				}
			}
			if ( 'immobilise' === $v['statut'] && $v['updated_at'] && strtotime( $v['updated_at'] ) < time() - 30 * DAY_IN_SECONDS ) {
				$out[] = array( 'warn', sprintf( '%s immobilisé depuis plus de 30 jours (%s).', $v['immatriculation'], $v['motif_statut'] ), $lien );
			}
		}
		foreach ( self::chauffeurs( true ) as $c ) {
			if ( $c['permis_expire'] && $c['permis_expire'] < $auj ) {
				$out[] = array( 'ko', sprintf( 'Permis de %s expiré depuis le %s.', $c['nom'], wp_date( 'd/m/Y', strtotime( $c['permis_expire'] ) ) ), add_query_arg( 'onglet', 'chauffeurs', $base ) );
			} elseif ( $c['permis_expire'] && $c['permis_expire'] <= gmdate( 'Y-m-d', strtotime( $auj . ' +30 days' ) ) ) {
				$out[] = array( 'warn', sprintf( 'Permis de %s à renouveler avant le %s.', $c['nom'], wp_date( 'd/m/Y', strtotime( $c['permis_expire'] ) ) ), add_query_arg( 'onglet', 'chauffeurs', $base ) );
			}
		}
		$dem = self::deplacements( array( 'statut' => 'demande' ) );
		if ( $dem ) {
			$out[] = array( 'info', sprintf( '%d demande(s) de véhicule à traiter.', count( $dem ) ), add_query_arg( 'onglet', 'deplacements', $base ) );
		}
		foreach ( self::deplacements( array( 'statut' => 'en_cours' ) ) as $x ) {
			if ( $x['retour_prevu'] < current_time( 'mysql' ) ) {
				$out[] = array( 'warn', sprintf( '%s : retour prévu le %s non enregistré.', $x['numero'], wp_date( 'd/m H:i', strtotime( $x['retour_prevu'] ) ) ), add_query_arg( 'deplacement', $x['id'], $base ) );
			}
		}
		$out = array_merge( $out, IGE_FFM_Carburant::alertes() );
		$ex  = IGE_FFM_Contexte::exercice();
		if ( $ex ) {
			$n = count( self::pleins( array( 'exercice_id' => (int) $ex['id'], 'anomalies' => true ) ) );
			if ( $n ) {
				$out[] = array( 'warn', sprintf( '%d plein(s) de carburant présentent une anomalie (consommation, prix, kilométrage).', $n ), add_query_arg( 'onglet', 'carburant', $base ) );
			}
		}
		return $out;
	}

	/**
	 * Alertes du parc ajoutées au tableau de bord.
	 *
	 * @param array $out Alertes.
	 * @return array
	 */
	public static function filtre_alertes( $out ) {
		if ( ! IGE_FFM_Fonctions::active( 'parc' ) || ( is_user_logged_in() && ! current_user_can( 'ige_gerer_parc' ) ) ) {
			return $out;
		}
		$a = self::alertes();
		$k = array_filter( $a, static fn( $x ) => 'ko' === $x[0] );
		$w = count( $a ) - count( $k );
		foreach ( $k as $x ) {
			$out[] = $x;
		}
		if ( $w ) {
			$out[] = array( 'warn', sprintf( 'Parc automobile : %d point(s) à suivre (échéances, entretiens, demandes, carburant).', $w ), ige_ffm_url( 'parc-automobile' ) );
		}
		return $out;
	}

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'ige_ffm_alertes', array( __CLASS__, 'filtre_alertes' ), 20 );
		// Profil « Gestionnaire du parc automobile » : interface du thème.
		add_action( 'after_setup_theme', static fn() => register_nav_menus( array( 'menu-parc' => 'Menu du Gestionnaire du parc automobile' ) ), 20 );
		add_filter( 'ige_ff_roles_profils', static fn( $m ) => (array) $m + array( 'ige_parc' => 'parc' ) );
		add_filter( 'ige_ff_menu_location', static fn( $loc, $profil = '' ) => 'parc' === $profil ? 'menu-parc' : $loc, 10, 2 );
		add_filter(
			'ige_ff_dashboard_url',
			static function ( $url, $profil = '' ) {
				if ( 'parc' === $profil ) {
					$p = get_page_by_path( 'parc-automobile' );
					return $p ? get_permalink( $p ) : $url;
				}
				return $url;
			},
			10,
			2
		);
		add_filter(
			'ige_ff_profil_libelle',
			static function ( $label, $user = null ) {
				return $user && in_array( 'ige_parc', (array) $user->roles, true ) ? 'Gestionnaire du parc automobile' : $label;
			},
			10,
			2
		);
	}

	/* ------------------------------------------------------------------ Notifications */

	/**
	 * Prévient la gestion du parc par e-mail.
	 *
	 * @param string $sujet Sujet.
	 * @param string $texte Texte.
	 */
	private static function prevenir_gestion( $sujet, $texte ) {
		$a = array();
		foreach ( get_users( array( 'capability' => 'ige_gerer_parc', 'fields' => array( 'user_email' ) ) ) as $u ) {
			if ( is_email( $u->user_email ) ) {
				$a[] = $u->user_email;
			}
		}
		if ( $a ) {
			IGE_FFM_Messagerie::envoyer( array_unique( $a ), $sujet, $texte );
		}
	}

	/**
	 * Prévient le demandeur d'un déplacement.
	 *
	 * @param array  $x     Déplacement.
	 * @param string $texte Texte.
	 */
	private static function prevenir_demandeur( array $x, $texte ) {
		$u = get_userdata( (int) $x['demandeur_id'] );
		if ( $u && is_email( $u->user_email ) && ! user_can( $u, 'ige_gerer_parc' ) ) {
			IGE_FFM_Messagerie::envoyer( $u->user_email, 'Véhicule : ' . $x['numero'], $texte . "\n\n" . ige_ffm_url( 'vehicules' ) );
		}
	}
}
