<?php
/**
 * Carburant (complément du parc, VA-41) :
 * - dotations mensuelles par véhicule et par service, en litres ou en francs congolais ;
 *   suivi dotation / consommé / reste, alerte à 80 %, plein au-delà de la dotation refusé sauf
 *   dérogation motivée accordée par la gestion du parc ;
 * - bons carburant : carnets numérotés reçus des stations, remise des bons à un véhicule ou un
 *   chauffeur, contrôle à l'utilisation (bon inconnu, déjà utilisé, remis à un autre véhicule,
 *   annulé), bons remis et non utilisés, valeur des bons comparée au montant du plein.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dotations et bons carburant.
 */
final class IGE_FFM_Carburant {

	const SEUIL_ALERTE = 80;

	/**
	 * Statuts des bons : code => [ libellé, ton ].
	 *
	 * @return array<string,array>
	 */
	public static function statuts_bon() {
		return array(
			'disponible' => array( 'En réserve', 'neutre' ),
			'remis'      => array( 'Remis', 'info' ),
			'utilise'    => array( 'Utilisé', 'ok' ),
			'annule'     => array( 'Annulé', 'ko' ),
		);
	}

	/**
	 * Unités de dotation.
	 *
	 * @return array<string,string>
	 */
	public static function unites() {
		return array( 'litres' => 'litres', 'cdf' => 'CDF' );
	}

	/* ------------------------------------------------------------------ Dotations */

	/**
	 * Dotations de l'exercice.
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function dotations( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'dotation' ) . ' WHERE exercice_id = %d AND actif = 1 ORDER BY cible, cible_id', $exo ), ARRAY_A );
	}

	/**
	 * Dotation d'une cible.
	 *
	 * @param int    $exo   Exercice.
	 * @param string $cible vehicule | service.
	 * @param int    $id    Identifiant.
	 * @return array|null
	 */
	public static function dotation( $exo, $cible, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'dotation' ) . ' WHERE exercice_id = %d AND cible = %s AND cible_id = %d AND actif = 1', $exo, $cible, $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Fixe (ou supprime avec une quantité vide) la dotation mensuelle d'un véhicule ou d'un service.
	 *
	 * @param array    $d       cible, cible_id, unite, quantite.
	 * @param int|null $user_id Auteur.
	 * @return int|true|WP_Error
	 */
	public static function fixer_dotation( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! IGE_FFM_Parc::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Les dotations sont fixées par la gestion du parc.' );
		}
		$ex    = IGE_FFM_Contexte::exercice();
		$cible = 'service' === ( $d['cible'] ?? '' ) ? 'service' : 'vehicule';
		$cid   = (int) ( $d['cible_id'] ?? 0 );
		$obj   = 'service' === $cible ? IGE_FFM_Referentiels::get( 'service', $cid ) : IGE_FFM_Parc::vehicule( $cid );
		if ( ! $ex || ! $obj ) {
			return new WP_Error( 'cible', 'Choisissez le véhicule ou le service.' );
		}
		$nom   = 'service' === $cible ? $obj['code'] : $obj['immatriculation'];
		$avant = self::dotation( (int) $ex['id'], $cible, $cid );
		if ( '' === trim( (string) ( $d['quantite'] ?? '' ) ) ) {
			if ( ! $avant ) {
				return new WP_Error( 'quantite', 'Indiquez la quantité mensuelle.' );
			}
			$wpdb->update( IGE_FFM_DB::t( 'dotation' ), array( 'actif' => 0, 'updated_at' => ige_ffm_now() ), array( 'id' => (int) $avant['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'dotation_carburant', $cible, $nom, array( 'mensuel' => $avant['quantite'] . ' ' . self::unites()[ $avant['unite'] ] ), array( 'mensuel' => 'supprimée' ), $user_id );
			return true;
		}
		$unite = isset( self::unites()[ $d['unite'] ?? '' ] ) ? $d['unite'] : 'litres';
		$q     = IGE_FFM_Devises::nombre( $d['quantite'] );
		if ( null === $q || $q <= 0 || ( 'litres' === $unite && $q > 100000 ) ) {
			return new WP_Error( 'quantite', 'Quantité mensuelle invalide.' );
		}
		$q   = 'cdf' === $unite ? (float) round( $q ) : round( $q, 2 );
		$row = array( 'unite' => $unite, 'quantite' => $q, 'updated_at' => ige_ffm_now() );
		if ( $avant ) {
			$wpdb->update( IGE_FFM_DB::t( 'dotation' ), $row, array( 'id' => (int) $avant['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $avant['id'];
		} else {
			$wpdb->insert( IGE_FFM_DB::t( 'dotation' ), $row + array( 'exercice_id' => (int) $ex['id'], 'cible' => $cible, 'cible_id' => $cid, 'actif' => 1, 'created_by' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
		}
		IGE_FFM_Audit::log( 'dotation_carburant', $cible, $nom, $avant ? array( 'mensuel' => $avant['quantite'] . ' ' . self::unites()[ $avant['unite'] ] ) : null, array( 'mensuel' => $q . ' ' . self::unites()[ $unite ] ), $user_id );
		return $id;
	}

	/**
	 * Consommation d'une cible sur un mois.
	 *
	 * @param string $cible vehicule | service.
	 * @param int    $id    Identifiant.
	 * @param string $mois  AAAA-MM.
	 * @param string $unite litres | cdf.
	 * @return float
	 */
	public static function consomme( $cible, $id, $mois, $unite ) {
		global $wpdb;
		$col = 'cdf' === $unite ? 'montant' : 'litres';
		$w   = 'service' === $cible ? 'service_id' : 'vehicule_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM($col),0) FROM " . IGE_FFM_DB::t( 'carburant' ) . " WHERE $w = %d AND date_plein BETWEEN %s AND %s", $id, $mois . '-01', gmdate( 'Y-m-t', strtotime( $mois . '-01' ) ) ) );
	}

	/**
	 * Suivi d'une dotation pour un mois.
	 *
	 * @param array  $dot  Dotation.
	 * @param string $mois AAAA-MM (mois en cours par défaut).
	 * @return array{dotation:float, consomme:float, reste:float, taux:float, libelle:string}
	 */
	public static function suivi( array $dot, $mois = '' ) {
		$mois = $mois ?: substr( IGE_FFM_Contexte::aujourdhui(), 0, 7 );
		$c    = self::consomme( $dot['cible'], (int) $dot['cible_id'], $mois, $dot['unite'] );
		$q    = (float) $dot['quantite'];
		if ( 'service' === $dot['cible'] ) {
			$s   = IGE_FFM_Referentiels::get( 'service', (int) $dot['cible_id'] );
			$lib = $s ? 'Service ' . $s['code'] : '?';
		} else {
			$v   = IGE_FFM_Parc::vehicule( (int) $dot['cible_id'] );
			$lib = $v ? $v['immatriculation'] : '?';
		}
		return array( 'dotation' => $q, 'consomme' => $c, 'reste' => $q - $c, 'taux' => $q > 0 ? round( $c / $q * 100, 1 ) : 0, 'libelle' => $lib );
	}

	/**
	 * Affichage d'une quantité de dotation.
	 *
	 * @param float  $q     Quantité.
	 * @param string $unite Unité.
	 * @return string
	 */
	public static function fmt( $q, $unite ) {
		return 'cdf' === $unite ? ige_ffm_montant( (int) round( $q ) ) : number_format( (float) $q, fmod( (float) $q, 1 ) ? 1 : 0, ',', "\u{202F}" ) . "\u{00A0}L";
	}

	/* ------------------------------------------------------------------ Bons */

	/**
	 * Numéros saisis (« 1201, 1202 » ou « B-1201 ») → liste normalisée.
	 *
	 * @param string $s Saisie.
	 * @return string[]
	 */
	public static function numeros( $s ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', strtoupper( trim( (string) $s ) ) ) as $n ) {
			$n = preg_replace( '/[^A-Z0-9\-\/]/', '', $n );
			if ( '' !== $n ) {
				$out[] = $n;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Numéro d'un bon (préfixe + numéro).
	 *
	 * @param string $prefixe Préfixe.
	 * @param int    $n       Numéro.
	 * @return string
	 */
	public static function numero( $prefixe, $n ) {
		return strtoupper( $prefixe ) . $n;
	}

	/**
	 * Carnets.
	 *
	 * @return array[]
	 */
	public static function carnets() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT c.*, (SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'bon' ) . " b WHERE b.carnet_id = c.id AND b.statut = 'disponible') AS dispo, (SELECT COUNT(*) FROM " . IGE_FFM_DB::t( 'bon' ) . " b WHERE b.carnet_id = c.id AND b.statut = 'remis') AS remis, (SELECT COUNT(*) FROM " . IGE_FFM_DB::t( 'bon' ) . " b WHERE b.carnet_id = c.id AND b.statut = 'utilise') AS utilises FROM " . IGE_FFM_DB::t( 'carnet' ) . ' c ORDER BY c.recu_le DESC, c.id DESC', ARRAY_A );
	}

	/**
	 * Un bon par son numéro.
	 *
	 * @param string $numero Numéro.
	 * @return array|null
	 */
	public static function bon( $numero ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT b.*, c.valeur_unitaire, c.litres_unitaire, c.station FROM ' . IGE_FFM_DB::t( 'bon' ) . ' b JOIN ' . IGE_FFM_DB::t( 'carnet' ) . ' c ON c.id = b.carnet_id WHERE b.numero = %s', strtoupper( $numero ) ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Le suivi des bons est-il en place (au moins un carnet) ?
	 *
	 * @return bool
	 */
	public static function suivi_bons() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT id FROM ' . IGE_FFM_DB::t( 'carnet' ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Enregistre un carnet de bons et crée ses bons numérotés.
	 *
	 * @param array    $d       station, prefixe, debut, fin, valeur_unitaire, litres_unitaire, recu_le, reference, ecriture.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function ajouter_carnet( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! IGE_FFM_Parc::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion du parc.' );
		}
		$pre = mb_substr( strtoupper( preg_replace( '/[^A-Za-z0-9\-\/]/', '', (string) ( $d['prefixe'] ?? '' ) ) ), 0, 10 );
		$deb = (int) preg_replace( '/\D/', '', (string) ( $d['debut'] ?? '' ) );
		$fin = (int) preg_replace( '/\D/', '', (string) ( $d['fin'] ?? '' ) );
		if ( $deb <= 0 || $fin < $deb ) {
			return new WP_Error( 'numeros', 'Numéros de début et de fin invalides.' );
		}
		if ( $fin - $deb >= 500 ) {
			return new WP_Error( 'numeros', '500 bons au plus par carnet.' );
		}
		$val = '' === trim( (string) ( $d['valeur_unitaire'] ?? '' ) ) ? null : ige_ffm_parse_montant( $d['valeur_unitaire'] );
		$lit = '' === trim( (string) ( $d['litres_unitaire'] ?? '' ) ) ? null : IGE_FFM_Devises::nombre( $d['litres_unitaire'] );
		if ( ( null === $val && null === $lit ) || ( null !== $val && $val <= 0 ) || ( null !== $lit && $lit <= 0 ) ) {
			return new WP_Error( 'valeur', 'Indiquez la valeur d’un bon, en francs congolais ou en litres.' );
		}
		$recu = (string) ( $d['recu_le'] ?? '' );
		if ( ! IGE_FFM_Contexte::date_valide( $recu ) || $recu > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'recu_le', 'Date de réception invalide.' );
		}
		$eid = IGE_FFM_Parc::ecriture_ref( $d['ecriture'] ?? '' );
		if ( is_wp_error( $eid ) ) {
			return $eid;
		}
		$tb = IGE_FFM_DB::t( 'bon' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deja = $wpdb->get_var( $wpdb->prepare( "SELECT numero FROM $tb WHERE numero IN (%s, %s) LIMIT 1", self::numero( $pre, $deb ), self::numero( $pre, $fin ) ) );
		if ( $deja ) {
			return new WP_Error( 'numeros', 'Le bon ' . $deja . ' existe déjà : ce carnet chevauche un carnet enregistré.' );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'carnet' ),
			array(
				'station'         => mb_substr( trim( sanitize_text_field( (string) ( $d['station'] ?? '' ) ) ), 0, 120 ),
				'prefixe'         => $pre,
				'debut'           => $deb,
				'fin'             => $fin,
				'valeur_unitaire' => $val,
				'litres_unitaire' => $lit,
				'recu_le'         => $recu,
				'reference'       => mb_substr( trim( sanitize_text_field( (string) ( $d['reference'] ?? '' ) ) ), 0, 60 ),
				'ecriture_id'     => $eid,
				'created_by'      => (int) $user_id,
				'created_at'      => ige_ffm_now(),
			)
		);
		$cid = (int) $wpdb->insert_id;
		$wpdb->suppress_errors( true );
		$n = 0;
		for ( $i = $deb; $i <= $fin; $i++ ) {
			$n += (int) $wpdb->insert( $tb, array( 'carnet_id' => $cid, 'numero' => self::numero( $pre, $i ), 'statut' => 'disponible' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->suppress_errors( false );
		IGE_FFM_Audit::log( 'carnet_bons', 'carnet', self::numero( $pre, $deb ) . ' → ' . self::numero( $pre, $fin ), null, array_filter( array( 'bons' => $n, 'station' => $d['station'] ?? '', 'valeur' => $val, 'litres' => $lit ) ), $user_id );
		return $cid;
	}

	/**
	 * Remet des bons à un véhicule (et à son chauffeur).
	 *
	 * @param string   $numeros Numéros (liste, ou « 1201-1210 » pour une plage).
	 * @param int      $vid     Véhicule.
	 * @param int      $cid     Chauffeur (facultatif).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Nombre de bons remis.
	 */
	public static function remettre( $numeros, $vid, $cid = 0, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! IGE_FFM_Parc::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion du parc.' );
		}
		$v = IGE_FFM_Parc::vehicule( (int) $vid );
		if ( ! $v || 'reforme' === $v['statut'] ) {
			return new WP_Error( 'vehicule', 'Choisissez un véhicule en service.' );
		}
		$liste = self::plage( $numeros );
		if ( is_wp_error( $liste ) ) {
			return $liste;
		}
		$bons = array();
		foreach ( $liste as $n ) {
			$b = self::bon( $n );
			if ( ! $b ) {
				return new WP_Error( 'bon', 'Bon ' . $n . ' inconnu : enregistrez d’abord le carnet.' );
			}
			if ( 'disponible' !== $b['statut'] ) {
				return new WP_Error( 'bon', 'Bon ' . $n . ' déjà ' . strtolower( self::statuts_bon()[ $b['statut'] ][0] ) . '.' );
			}
			$bons[] = $b;
		}
		foreach ( $bons as $b ) {
			$wpdb->update( IGE_FFM_DB::t( 'bon' ), array( 'statut' => 'remis', 'vehicule_id' => (int) $vid, 'chauffeur_id' => $cid ? (int) $cid : null, 'remis_le' => ige_ffm_now(), 'remis_par' => (int) $user_id ), array( 'id' => (int) $b['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$ch = $cid ? IGE_FFM_Parc::chauffeur( (int) $cid ) : null;
		IGE_FFM_Audit::log( 'remise_bons', 'vehicule', $v['immatriculation'], null, array_filter( array( 'bons' => implode( ', ', array_column( $bons, 'numero' ) ), 'chauffeur' => $ch ? $ch['nom'] : '' ) ), $user_id );
		return count( $bons );
	}

	/**
	 * Saisie « 1201-1210 » ou liste → numéros.
	 *
	 * @param string $s Saisie.
	 * @return string[]|WP_Error
	 */
	public static function plage( $s ) {
		$s = mb_strtoupper( trim( (string) $s ) );
		if ( '' !== $s && self::bon( $s ) ) {
			return array( $s ); // Un numéro existant n'est jamais lu comme une plage.
		}
		if ( ! preg_match( '/[,;]/', $s ) ) {
			// « à » (ou « a ») entouré d'espaces sépare les bornes ; une lettre A d'un préfixe n'est pas touchée.
			$s2 = preg_replace( '/\s+(?:À|A)\s+/u', '~', preg_replace( '/\s+/u', ' ', $s ) );
			$s2 = str_replace( ' ', '', $s2 );
			$pos = array();
			for ( $i = 0, $n = strlen( $s2 ); $i < $n; $i++ ) {
				if ( '-' === $s2[ $i ] || '~' === $s2[ $i ] ) {
					$pos[] = $i;
				}
			}
			foreach ( $pos as $i ) {
				$g = substr( $s2, 0, $i );
				$d = substr( $s2, $i + 1 );
				if ( preg_match( '/^(.*\D)?(\d+)$/', $g, $m1 ) && preg_match( '/^(.*\D)?(\d+)$/', $d, $m2 ) ) {
					$p1 = $m1[1] ?? '';
					$p2 = $m2[1] ?? '';
					$a  = (int) $m1[2];
					$b  = (int) $m2[2];
					if ( ( '' === $p2 || $p2 === $p1 ) && $b >= $a ) {
						if ( $b - $a >= 200 ) {
							return new WP_Error( 'numeros', 'Plage de numéros trop longue (200 bons au plus).' );
						}
						$out = array();
						for ( $k = $a; $k <= $b; $k++ ) {
							$out[] = $p1 . $k;
						}
						return $out;
					}
				}
			}
		}
		$l = self::numeros( $s );
		return $l ? $l : new WP_Error( 'numeros', 'Indiquez les numéros des bons.' );
	}

	/**
	 * Annule un bon (perdu, volé, détérioré).
	 *
	 * @param string   $numero  Numéro.
	 * @param string   $motif   Motif.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function annuler_bon( $numero, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$b       = self::bon( $numero );
		if ( ! $b || ! IGE_FFM_Parc::gestionnaire( $user_id ) ) {
			return new WP_Error( 'bon', 'Bon introuvable.' );
		}
		if ( in_array( $b['statut'], array( 'utilise', 'annule' ), true ) ) {
			return new WP_Error( 'bon', 'Ce bon est déjà ' . strtolower( self::statuts_bon()[ $b['statut'] ][0] ) . '.' );
		}
		$motif = mb_substr( trim( sanitize_text_field( (string) $motif ) ), 0, 190 );
		if ( mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif (perdu, volé, détérioré…).' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'bon' ), array( 'statut' => 'annule', 'motif' => $motif ), array( 'id' => (int) $b['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'annulation_bon', 'bon', $b['numero'], array( 'statut' => self::statuts_bon()[ $b['statut'] ][0] ), array( 'motif' => $motif ), $user_id );
		return true;
	}

	/**
	 * Bons.
	 *
	 * @param array $f statut, vehicule_id, carnet_id, limite.
	 * @return array[]
	 */
	public static function bons( array $f = array() ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( ! empty( $f['statut'] ) ) {
			$w[] = $wpdb->prepare( 'b.statut = %s', $f['statut'] );
		}
		if ( ! empty( $f['sauf_reserve'] ) ) {
			$w[] = "b.statut <> 'disponible'";
		}
		foreach ( array( 'vehicule_id', 'carnet_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = $wpdb->prepare( "b.$k = %d", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		$lim = ' LIMIT ' . ( ! empty( $f['limite'] ) ? (int) $f['limite'] : 300 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT b.*, c.station, c.valeur_unitaire, c.litres_unitaire, v.immatriculation FROM ' . IGE_FFM_DB::t( 'bon' ) . ' b JOIN ' . IGE_FFM_DB::t( 'carnet' ) . ' c ON c.id = b.carnet_id LEFT JOIN ' . IGE_FFM_DB::t( 'vehicule' ) . ' v ON v.id = b.vehicule_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY b.id DESC' . $lim, ARRAY_A );
	}

	/* ------------------------------------------------------------------ Contrôle d'un plein */

	/**
	 * Contrôles avant d'enregistrer un plein : dotations et bons.
	 *
	 * @param array  $v       Véhicule.
	 * @param string $date    Date.
	 * @param float  $litres  Litres.
	 * @param int    $montant Montant.
	 * @param string $mode    bon | carte | especes.
	 * @param string $bons    Numéros de bons saisis.
	 * @param string $derog   Motif de dérogation (gestion du parc seulement).
	 * @param int    $user_id Utilisateur.
	 * @return array|WP_Error [ bons => lignes, anomalies => string[], derogation => string, alertes => string[] ].
	 */
	public static function controler( array $v, $date, $litres, $montant, $mode, $bons, $derog, $user_id ) {
		$ex    = IGE_FFM_Contexte::exercice();
		$mois  = substr( $date, 0, 7 );
		$gest  = IGE_FFM_Parc::gestionnaire( $user_id );
		$derog = mb_substr( trim( sanitize_text_field( (string) $derog ) ), 0, 255 );
		$out   = array( 'bons' => array(), 'anomalies' => array(), 'derogation' => '', 'alertes' => array() );
		// Dotations du véhicule et de son service.
		$cibles = array( array( 'vehicule', (int) $v['id'] ) );
		if ( $v['service_id'] ) {
			$cibles[] = array( 'service', (int) $v['service_id'] );
		}
		foreach ( $cibles as $c ) {
			$dot = $ex ? self::dotation( (int) $ex['id'], $c[0], $c[1] ) : null;
			if ( ! $dot ) {
				continue;
			}
			$s     = self::suivi( $dot, $mois );
			$ajout = 'cdf' === $dot['unite'] ? (float) $montant : (float) $litres;
			$apres = $s['consomme'] + $ajout;
			if ( $apres > $s['dotation'] + 0.001 ) {
				$txt = sprintf( 'Dotation de %s dépassée (%s consommés sur %s ce mois)', $s['libelle'], self::fmt( $apres, $dot['unite'] ), self::fmt( $s['dotation'], $dot['unite'] ) );
				if ( ! $gest ) {
					return new WP_Error( 'dotation', $txt . ' : demandez une dérogation à la gestion du parc.' );
				}
				if ( mb_strlen( $derog ) < 10 ) {
					return new WP_Error( 'dotation', $txt . ' : indiquez le motif de la dérogation pour enregistrer ce plein.' );
				}
				$out['derogation']  = $derog;
				$out['anomalies'][] = 'Hors dotation, dérogation : ' . $derog;
			} elseif ( $s['dotation'] > 0 && $apres / $s['dotation'] * 100 >= self::SEUIL_ALERTE ) {
				$out['alertes'][] = sprintf( 'Dotation de %s utilisée à %d %% ce mois', $s['libelle'], floor( $apres / $s['dotation'] * 100 ) );
			}
		}
		// Bons.
		$nums = self::numeros( $bons );
		if ( 'bon' === $mode && self::suivi_bons() ) {
			if ( ! $nums ) {
				return new WP_Error( 'bon_numero', 'Indiquez le numéro du ou des bons utilisés.' );
			}
			$valeur = 0;
			$lt     = 0.0;
			foreach ( $nums as $n ) {
				$b = self::bon( $n );
				if ( ! $b ) {
					if ( ! $gest ) {
						return new WP_Error( 'bon_numero', 'Bon ' . $n . ' inconnu : il n’appartient à aucun carnet enregistré.' );
					}
					$out['anomalies'][] = 'Bon ' . $n . ' hors carnet';
					continue;
				}
				if ( 'utilise' === $b['statut'] ) {
					return new WP_Error( 'bon_numero', 'Bon ' . $n . ' déjà utilisé le ' . wp_date( 'd/m/Y', strtotime( $b['utilise_le'] ) ) . ' : un bon ne sert qu’une fois.' );
				}
				if ( 'annule' === $b['statut'] ) {
					return new WP_Error( 'bon_numero', 'Bon ' . $n . ' annulé (' . $b['motif'] . ').' );
				}
				if ( 'disponible' === $b['statut'] ) {
					if ( ! $gest ) {
						return new WP_Error( 'bon_numero', 'Bon ' . $n . ' jamais remis à un véhicule.' );
					}
					$out['anomalies'][] = 'Bon ' . $n . ' utilisé sans avoir été remis';
				} elseif ( (int) $b['vehicule_id'] !== (int) $v['id'] ) {
					$autre              = IGE_FFM_Parc::vehicule( (int) $b['vehicule_id'] );
					$out['anomalies'][] = 'Bon ' . $n . ' remis au véhicule ' . ( $autre ? $autre['immatriculation'] : '?' );
				}
				$valeur        += (int) $b['valeur_unitaire'];
				$lt            += (float) $b['litres_unitaire'];
				$out['bons'][] = $b;
			}
			if ( $valeur > 0 && $montant > $valeur ) {
				$out['anomalies'][] = sprintf( 'Montant (%s) supérieur à la valeur des bons (%s)', ige_ffm_montant( (int) $montant ), ige_ffm_montant( $valeur ) );
			}
			if ( $lt > 0 && $litres > $lt + 0.5 ) {
				$out['anomalies'][] = sprintf( '%s L servis pour des bons de %s L', number_format( $litres, 1, ',', ' ' ), number_format( $lt, 1, ',', ' ' ) );
			}
		}
		return $out;
	}

	/**
	 * Après l'enregistrement d'un plein : bons utilisés.
	 *
	 * @param int   $plein Plein.
	 * @param array $ctrl  Résultat de controler().
	 */
	public static function apres( $plein, array $ctrl ) {
		global $wpdb;
		foreach ( $ctrl['bons'] as $b ) {
			$wpdb->update( IGE_FFM_DB::t( 'bon' ), array( 'statut' => 'utilise', 'carburant_id' => (int) $plein, 'utilise_le' => ige_ffm_now() ), array( 'id' => (int) $b['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/* ------------------------------------------------------------------ Alertes */

	/**
	 * Alertes : dotations proches ou dépassées ce mois, bons remis et inutilisés depuis 30 jours.
	 *
	 * @return array[] [ niveau, texte, lien ].
	 */
	public static function alertes() {
		$out  = array();
		$ex   = IGE_FFM_Contexte::exercice();
		$base = ige_ffm_url( 'parc-automobile', array( 'onglet' => 'dotations' ) );
		if ( $ex ) {
			foreach ( self::dotations( (int) $ex['id'] ) as $d ) {
				$s = self::suivi( $d );
				if ( $s['consomme'] > $s['dotation'] ) {
					$out[] = array( 'ko', sprintf( 'Dotation carburant de %s dépassée ce mois : %s sur %s.', $s['libelle'], self::fmt( $s['consomme'], $d['unite'] ), self::fmt( $s['dotation'], $d['unite'] ) ), $base );
				} elseif ( $s['taux'] >= self::SEUIL_ALERTE ) {
					$out[] = array( 'warn', sprintf( 'Dotation carburant de %s utilisée à %d %% ce mois.', $s['libelle'], floor( $s['taux'] ) ), $base );
				}
			}
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'bon' ) . " WHERE statut = 'remis' AND remis_le < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		if ( $n ) {
			$out[] = array( 'warn', sprintf( '%d bon(s) carburant remis depuis plus de 30 jours et non utilisés.', $n ), add_query_arg( 'bons', 'remis', $base ) );
		}
		return $out;
	}
}
