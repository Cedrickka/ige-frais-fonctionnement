<?php
/**
 * Petits stocks de fournitures (VA-42) : articles, entrées (liées aux achats du livre de caisse),
 * sorties par bureau, valorisation au coût moyen unitaire pondéré (CMUP), seuil de
 * réapprovisionnement, inventaire avec ajustement des écarts, consommation par bureau.
 *
 * Réservé à la capacité ige_logistique ; consultation pour ige_voir_tout.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stocks.
 */
final class IGE_FFM_Stocks {

	/**
	 * Types de mouvements : code => libellé.
	 *
	 * @return array<string,string>
	 */
	public static function types() {
		return array( 'entree' => 'Entrée', 'sortie' => 'Sortie', 'ajustement' => 'Ajustement d’inventaire' );
	}

	/**
	 * Droits de gestion.
	 *
	 * @param int|null $user_id Utilisateur.
	 * @return bool
	 */
	public static function gestionnaire( $user_id = null ) {
		return user_can( $user_id ?? get_current_user_id(), 'ige_logistique' );
	}

	/**
	 * Quantité saisie (deux décimales au plus, virgule acceptée).
	 *
	 * @param mixed $v Valeur.
	 * @return float|null
	 */
	public static function quantite( $v ) {
		$q = IGE_FFM_Devises::nombre( $v );
		return null === $q ? null : round( $q, 2 );
	}

	/**
	 * Affichage d'une quantité.
	 *
	 * @param float $q Quantité.
	 * @return string
	 */
	public static function q( $q ) {
		$q = (float) $q;
		return number_format( $q, fmod( $q, 1 ) ? 2 : 0, ',', "\u{202F}" );
	}

	/**
	 * Articles.
	 *
	 * @param bool $actifs Actifs seulement.
	 * @return array[]
	 */
	public static function articles( $actifs = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'article' ) . ( $actifs ? ' WHERE actif = 1' : '' ) . ' ORDER BY categorie, designation', ARRAY_A );
	}

	/**
	 * Un article.
	 *
	 * @param int $id Article.
	 * @return array|null
	 */
	public static function article( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'article' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Crée ou modifie un article.
	 *
	 * @param array    $d       code, designation, unite, categorie, seuil, actif.
	 * @param int      $id      Article.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function enregistrer_article( array $d, $id = 0, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion des stocks.' );
		}
		$row = array(
			'code'        => mb_substr( strtoupper( preg_replace( '/[^A-Za-z0-9\-_.]/', '', (string) ( $d['code'] ?? '' ) ) ), 0, 30 ),
			'designation' => mb_substr( trim( sanitize_text_field( (string) ( $d['designation'] ?? '' ) ) ), 0, 190 ),
			'unite'       => mb_substr( trim( sanitize_text_field( (string) ( $d['unite'] ?? '' ) ) ), 0, 20 ) ?: 'unité',
			'categorie'   => mb_substr( trim( sanitize_text_field( (string) ( $d['categorie'] ?? '' ) ) ), 0, 60 ),
			'seuil'       => self::quantite( '' === trim( (string) ( $d['seuil'] ?? '' ) ) ? '0' : $d['seuil'] ),
			'actif'       => $id ? ( empty( $d['actif'] ) ? 0 : 1 ) : 1,
		);
		if ( '' === $row['code'] || mb_strlen( $row['designation'] ) < 2 ) {
			return new WP_Error( 'code', 'Le code et la désignation sont obligatoires.' );
		}
		if ( null === $row['seuil'] || $row['seuil'] < 0 ) {
			return new WP_Error( 'seuil', 'Seuil de réapprovisionnement invalide.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'article' ) . ' WHERE code = %s AND id <> %d', $row['code'], (int) $id ) ) ) {
			return new WP_Error( 'code', 'Ce code d’article existe déjà.' );
		}
		if ( $id ) {
			$avant = self::article( $id );
			if ( ! $avant ) {
				return new WP_Error( 'article', 'Article introuvable.' );
			}
			$wpdb->update( IGE_FFM_DB::t( 'article' ), $row + array( 'updated_at' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'article_stock', 'article', $row['code'], array_intersect_key( $avant, $row ), $row, $user_id );
			return (int) $id;
		}
		$wpdb->insert( IGE_FFM_DB::t( 'article' ), $row + array( 'stock' => 0, 'cmup' => 0, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$nid = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'article_stock', 'article', $row['code'], null, $row, $user_id );
		return $nid;
	}

	/**
	 * Enregistre un mouvement (entrée, sortie, ajustement) et met à jour le stock et le CMUP.
	 *
	 * @param array    $d       article_id, type, date, quantite, prix_unitaire (entrée), service_id (sortie),
	 *                          beneficiaire, fournisseur_id, ecriture, reference, motif, inventaire_id.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function mouvement( array $d, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion des stocks.' );
		}
		$a  = self::article( (int) ( $d['article_id'] ?? 0 ) );
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $a || ! $ex ) {
			return new WP_Error( 'article', 'Article introuvable.' );
		}
		$type = isset( self::types()[ $d['type'] ?? '' ] ) ? $d['type'] : '';
		$date = (string) ( $d['date'] ?? '' );
		if ( ! $type ) {
			return new WP_Error( 'type', 'Type de mouvement inconnu.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date > IGE_FFM_Contexte::aujourdhui() || $date < $ex['date_debut'] ) {
			return new WP_Error( 'date', 'Date invalide (dans l’exercice, pas dans le futur).' );
		}
		$q = self::quantite( $d['quantite'] ?? '' );
		if ( null === $q || 0.0 === $q || ( 'ajustement' !== $type && $q < 0 ) ) {
			return new WP_Error( 'quantite', 'Quantité invalide pour ' . $a['designation'] . '.' );
		}
		$stock = (float) $a['stock'];
		$cmup  = (float) $a['cmup'];
		$pu    = null;
		$sid   = null;
		$motif = mb_substr( trim( sanitize_text_field( (string) ( $d['motif'] ?? '' ) ) ), 0, 255 );
		$eid   = null;
		if ( 'entree' === $type ) {
			$pu = IGE_FFM_Devises::nombre( $d['prix_unitaire'] ?? '' );
			if ( null === $pu || $pu < 0 ) {
				return new WP_Error( 'prix_unitaire', 'Prix unitaire en CDF invalide pour ' . $a['designation'] . '.' );
			}
			$eid = IGE_FFM_Parc::ecriture_ref( $d['ecriture'] ?? '' );
			if ( is_wp_error( $eid ) ) {
				return $eid;
			}
			$cmup   = $stock + $q > 0 ? round( ( $stock * $cmup + $q * $pu ) / ( $stock + $q ), 2 ) : $pu;
			$delta  = $q;
			$valeur = (int) round( $q * $pu );
		} elseif ( 'sortie' === $type ) {
			$sid = (int) ( $d['service_id'] ?? 0 );
			if ( ! IGE_FFM_Referentiels::get( 'service', $sid ) ) {
				return new WP_Error( 'service_id', 'Choisissez le bureau (service) destinataire.' );
			}
			if ( $q > $stock ) {
				return new WP_Error( 'quantite', sprintf( 'Stock insuffisant de %s : %s %s disponibles.', $a['designation'], self::q( $stock ), $a['unite'] ) );
			}
			$delta  = -$q;
			$valeur = -(int) round( $q * $cmup );
		} else {
			if ( mb_strlen( $motif ) < 5 ) {
				return new WP_Error( 'motif', 'Un ajustement exige un motif (inventaire, casse, péremption…).' );
			}
			if ( $stock + $q < 0 ) {
				return new WP_Error( 'quantite', 'L’ajustement rendrait le stock négatif.' );
			}
			$delta  = $q;
			$valeur = (int) round( $q * $cmup );
		}
		$apres = round( $stock + $delta, 2 );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'stock_mvt' ),
			array(
				'article_id'     => (int) $a['id'],
				'exercice_id'    => (int) $ex['id'],
				'date_mvt'       => $date,
				'type'           => $type,
				'quantite'       => $delta,
				'prix_unitaire'  => $pu,
				'valeur'         => $valeur,
				'stock_apres'    => $apres,
				'cmup_apres'     => $cmup,
				'service_id'     => $sid,
				'beneficiaire'   => mb_substr( trim( sanitize_text_field( (string) ( $d['beneficiaire'] ?? '' ) ) ), 0, 120 ),
				'fournisseur_id' => (int) ( $d['fournisseur_id'] ?? 0 ) ?: null,
				'ecriture_id'    => $eid,
				'reference'      => mb_substr( trim( sanitize_text_field( (string) ( $d['reference'] ?? '' ) ) ), 0, 60 ),
				'motif'          => $motif,
				'inventaire_id'  => (int) ( $d['inventaire_id'] ?? 0 ) ?: null,
				'created_by'     => (int) $user_id,
				'created_at'     => ige_ffm_now(),
			)
		);
		$mid = (int) $wpdb->insert_id;
		$wpdb->update( IGE_FFM_DB::t( 'article' ), array( 'stock' => $apres, 'cmup' => $cmup, 'updated_at' => ige_ffm_now() ), array( 'id' => (int) $a['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( empty( $d['inventaire_id'] ) ) {
			IGE_FFM_Audit::log( 'mouvement_stock', 'article', $a['code'], array( 'stock' => (float) $a['stock'] ), array_filter( array( 'type' => self::types()[ $type ], 'quantite' => $delta, 'stock' => $apres, 'bureau' => $sid ? IGE_FFM_Comptes::service_code( $sid ) : '', 'motif' => $motif ) ), $user_id );
		}
		return $mid;
	}

	/**
	 * Plusieurs lignes en une fois (bon d'entrée ou de sortie) : tout ou rien.
	 *
	 * @param array    $commun  Champs communs (type, date, service_id, beneficiaire, reference, fournisseur_id, ecriture).
	 * @param array    $lignes  [ article_id, quantite, prix_unitaire ].
	 * @param int|null $user_id Auteur.
	 * @return int[]|WP_Error
	 */
	public static function bon( array $commun, array $lignes, $user_id = null ) {
		$lignes = array_values( array_filter( $lignes, static fn( $l ) => ! empty( $l['article_id'] ) && '' !== trim( (string) ( $l['quantite'] ?? '' ) ) ) );
		if ( ! $lignes ) {
			return new WP_Error( 'lignes', 'Ajoutez au moins un article et sa quantité.' );
		}
		// Contrôle préalable des quantités cumulées en sortie.
		if ( 'sortie' === ( $commun['type'] ?? '' ) ) {
			$cumul = array();
			foreach ( $lignes as $l ) {
				$cumul[ (int) $l['article_id'] ] = ( $cumul[ (int) $l['article_id'] ] ?? 0 ) + (float) self::quantite( $l['quantite'] );
			}
			foreach ( $cumul as $aid => $q ) {
				$a = self::article( $aid );
				if ( $a && $q > (float) $a['stock'] ) {
					return new WP_Error( 'quantite', sprintf( 'Stock insuffisant de %s : %s %s disponibles.', $a['designation'], self::q( $a['stock'] ), $a['unite'] ) );
				}
			}
		}
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = array();
		foreach ( $lignes as $l ) {
			$r = self::mouvement( $commun + $l, $user_id );
			if ( is_wp_error( $r ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::recalculer( array_map( 'intval', array_column( $lignes, 'article_id' ) ) );
				return $r;
			}
			$ids[] = $r;
		}
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $ids;
	}

	/**
	 * Recalcule stock et CMUP d'articles à partir de leurs mouvements (après une annulation).
	 *
	 * @param int[] $ids Articles.
	 */
	public static function recalculer( array $ids ) {
		global $wpdb;
		foreach ( array_unique( $ids ) as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$last = $wpdb->get_row( $wpdb->prepare( 'SELECT stock_apres, cmup_apres FROM ' . IGE_FFM_DB::t( 'stock_mvt' ) . ' WHERE article_id = %d ORDER BY id DESC LIMIT 1', $id ), ARRAY_A );
			$wpdb->update( IGE_FFM_DB::t( 'article' ), array( 'stock' => $last ? $last['stock_apres'] : 0, 'cmup' => $last ? $last['cmup_apres'] : 0 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Mouvements.
	 *
	 * @param array $f article_id, service_id, type, du, au, limite.
	 * @return array[]
	 */
	public static function mouvements( array $f = array() ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'stock_mvt' );
		$w = array( '1=1' );
		foreach ( array( 'article_id', 'service_id' ) as $k ) {
			if ( ! empty( $f[ $k ] ) ) {
				$w[] = $wpdb->prepare( "m.$k = %d", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( ! empty( $f['type'] ) ) {
			$w[] = $wpdb->prepare( 'm.type = %s', $f['type'] );
		}
		if ( ! empty( $f['du'] ) ) {
			$w[] = $wpdb->prepare( 'm.date_mvt >= %s', $f['du'] );
		}
		if ( ! empty( $f['au'] ) ) {
			$w[] = $wpdb->prepare( 'm.date_mvt <= %s', $f['au'] );
		}
		$lim = ! empty( $f['limite'] ) ? ' LIMIT ' . (int) $f['limite'] : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( "SELECT m.*, a.code, a.designation, a.unite FROM $t m JOIN " . IGE_FFM_DB::t( 'article' ) . ' a ON a.id = m.article_id WHERE ' . implode( ' AND ', $w ) . ' ORDER BY m.date_mvt DESC, m.id DESC' . $lim, ARRAY_A );
	}

	/**
	 * Articles sous le seuil de réapprovisionnement.
	 *
	 * @return array[]
	 */
	public static function a_reapprovisionner() {
		return array_values( array_filter( self::articles( true ), static fn( $a ) => (float) $a['seuil'] > 0 && (float) $a['stock'] <= (float) $a['seuil'] ) );
	}

	/**
	 * Valeur totale du stock.
	 *
	 * @return int
	 */
	public static function valeur() {
		$v = 0.0;
		foreach ( self::articles() as $a ) {
			$v += (float) $a['stock'] * (float) $a['cmup'];
		}
		return (int) round( $v );
	}

	/**
	 * Consommation par bureau et par article sur une période (sorties).
	 *
	 * @param string $du Du.
	 * @param string $au Au.
	 * @return array{bureaux:array, articles:array, cellules:array, total:int}
	 */
	public static function consommation( $du, $au ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT service_id, article_id, -SUM(quantite) q, -SUM(valeur) v FROM ' . IGE_FFM_DB::t( 'stock_mvt' ) . " WHERE type = 'sortie' AND date_mvt BETWEEN %s AND %s GROUP BY service_id, article_id", $du, $au ), ARRAY_A );
		$out = array( 'bureaux' => array(), 'articles' => array(), 'cellules' => array(), 'total' => 0 );
		foreach ( $rows as $r ) {
			$s = (int) $r['service_id'];
			$a = (int) $r['article_id'];
			$out['cellules'][ $s ][ $a ] = array( 'q' => (float) $r['q'], 'v' => (int) $r['v'] );
			$out['bureaux'][ $s ]        = ( $out['bureaux'][ $s ] ?? 0 ) + (int) $r['v'];
			$out['articles'][ $a ]       = ( $out['articles'][ $a ] ?? 0 ) + (float) $r['q'];
			$out['total']               += (int) $r['v'];
		}
		arsort( $out['bureaux'] );
		return $out;
	}

	/**
	 * Inventaire : enregistre les quantités comptées et ajuste les écarts.
	 *
	 * @param string   $date    Date.
	 * @param array    $comptes article_id => quantité comptée (les articles non saisis sont ignorés).
	 * @param string   $obs     Observations.
	 * @param int|null $user_id Auteur.
	 * @return array|WP_Error [ 'id', 'ecarts', 'valeur' ].
	 */
	public static function inventaire( $date, array $comptes, $obs = '', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! self::gestionnaire( $user_id ) ) {
			return new WP_Error( 'droits', 'Réservé à la gestion des stocks.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'Date d’inventaire invalide.' );
		}
		$lignes = array();
		foreach ( $comptes as $aid => $v ) {
			if ( '' === trim( (string) $v ) ) {
				continue;
			}
			$a = self::article( (int) $aid );
			$q = self::quantite( $v );
			if ( ! $a || null === $q || $q < 0 ) {
				return new WP_Error( 'quantite', 'Quantité comptée invalide' . ( $a ? ' pour ' . $a['designation'] : '' ) . '.' );
			}
			$lignes[] = array( 'article_id' => (int) $a['id'], 'code' => $a['code'], 'designation' => $a['designation'], 'theorique' => (float) $a['stock'], 'compte' => $q, 'ecart' => round( $q - (float) $a['stock'], 2 ), 'cmup' => (float) $a['cmup'] );
		}
		if ( ! $lignes ) {
			return new WP_Error( 'lignes', 'Saisissez au moins une quantité comptée.' );
		}
		$ecarts = array_filter( $lignes, static fn( $l ) => 0.0 !== $l['ecart'] );
		$valeur = (int) round( array_sum( array_map( static fn( $l ) => $l['ecart'] * $l['cmup'], $ecarts ) ) );
		$wpdb->insert( IGE_FFM_DB::t( 'inventaire' ), array( 'date_inventaire' => $date, 'lignes' => wp_json_encode( $lignes ), 'nb_ecarts' => count( $ecarts ), 'valeur_ecarts' => $valeur, 'observations' => mb_substr( sanitize_text_field( (string) $obs ), 0, 255 ), 'created_by' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$iid = (int) $wpdb->insert_id;
		foreach ( $ecarts as $l ) {
			self::mouvement( array( 'article_id' => $l['article_id'], 'type' => 'ajustement', 'date' => $date, 'quantite' => (string) $l['ecart'], 'motif' => 'Inventaire du ' . wp_date( 'd/m/Y', strtotime( $date ) ), 'inventaire_id' => $iid ), $user_id );
		}
		IGE_FFM_Audit::log( 'inventaire_stock', 'inventaire', wp_date( 'd/m/Y', strtotime( $date ) ), null, array( 'articles' => count( $lignes ), 'ecarts' => count( $ecarts ), 'valeur' => $valeur ), $user_id );
		return array( 'id' => $iid, 'ecarts' => count( $ecarts ), 'valeur' => $valeur );
	}

	/**
	 * Inventaires passés.
	 *
	 * @return array[]
	 */
	public static function inventaires() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'inventaire' ) . ' ORDER BY date_inventaire DESC, id DESC LIMIT 20', ARRAY_A );
	}

	/**
	 * Alertes du stock pour le tableau de bord.
	 *
	 * @param array $out Alertes.
	 * @return array
	 */
	public static function filtre_alertes( $out ) {
		if ( ! IGE_FFM_Fonctions::active( 'stocks' ) ) {
			return $out;
		}
		$r = self::a_reapprovisionner();
		if ( $r ) {
			$out[] = array( 'warn', sprintf( '%d article(s) de fournitures sous le seuil de réapprovisionnement : %s.', count( $r ), implode( ', ', array_slice( array_column( $r, 'designation' ), 0, 4 ) ) . ( count( $r ) > 4 ? '…' : '' ) ), ige_ffm_url( 'stocks' ) );
		}
		return $out;
	}

	/**
	 * Crochets.
	 */
	public static function init() {
		add_filter( 'ige_ffm_alertes', array( __CLASS__, 'filtre_alertes' ), 21 );
	}
}
