<?php
/**
 * Arrêtés de caisse (VA-04, VA-05) et comptages inopinés (VA-04 bis).
 *
 * Arrêté mensuel : l'agent compte la caisse (billetage CDF et USD, autres disponibilités),
 * l'application compare au solde théorique du livre à la fin du mois ; un écart doit être
 * expliqué. L'agent signe, puis le Responsable (une autre personne) signe : le mois est clôturé
 * et plus aucune écriture ne peut y être ajoutée.
 *
 * Comptage inopiné : Administration et Finance compte la caisse sans prévenir, en présence de
 * l'agent, qui contresigne ensuite le procès-verbal depuis son compte. Il ne clôture rien.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Arrêtés et comptages.
 */
final class IGE_FFM_Arretes {

	/**
	 * Coupures en circulation.
	 *
	 * @return array{CDF:int[], USD:int[]}
	 */
	public static function coupures() {
		return (array) apply_filters(
			'ige_ffm_coupures',
			array(
				'CDF' => array( 20000, 10000, 5000, 1000, 500, 200, 100, 50 ),
				'USD' => array( 100, 50, 20, 10, 5, 2, 1 ),
			)
		);
	}

	/**
	 * Un arrêté.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'arrete' ) . ' WHERE id = %d', $id ), ARRAY_A );
		if ( $r ) {
			$r['detail'] = json_decode( (string) $r['billetage'], true ) ?: array();
		}
		return $r ?: null;
	}

	/**
	 * Arrêtés d'un service (ou de tous).
	 *
	 * @param int    $exo  Exercice.
	 * @param int    $sid  Service (0 = tous).
	 * @param string $type mensuel | inopine | '' (tous).
	 * @return array[]
	 */
	public static function liste( $exo, $sid = 0, $type = '' ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'arrete' );
		$w = ( $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '' ) . ( $type ? $wpdb->prepare( ' AND type = %s', $type ) : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d $w ORDER BY created_at DESC, id DESC", $exo ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['detail'] = json_decode( (string) $r['billetage'], true ) ?: array();
		}
		return $rows;
	}

	/**
	 * Arrêté mensuel d'un mois (le plus récent).
	 *
	 * @param int $exo  Exercice.
	 * @param int $sid  Service.
	 * @param int $mois Mois.
	 * @return array|null
	 */
	public static function mensuel( $exo, $sid, $mois ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'arrete' ) . " WHERE exercice_id = %d AND service_id = %d AND mois = %d AND type = 'mensuel' ORDER BY id DESC LIMIT 1", $exo, $sid, $mois ) );
		return $id ? self::get( $id ) : null;
	}

	/**
	 * Dernier mois clôturé (0 si aucun).
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return int
	 */
	public static function dernier_mois_clos( $exo, $sid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(mois) FROM ' . IGE_FFM_DB::t( 'arrete' ) . " WHERE exercice_id = %d AND service_id = %d AND type = 'mensuel' AND statut = 'cloture'", $exo, $sid ) );
	}

	/**
	 * Dernier jour d'un mois de l'exercice.
	 *
	 * @param array $ex   Exercice.
	 * @param int   $mois Mois.
	 * @return string
	 */
	public static function fin_de_mois( array $ex, $mois ) {
		return gmdate( 'Y-m-t', strtotime( sprintf( '%04d-%02d-01', (int) $ex['annee'], (int) $mois ) ) );
	}

	/**
	 * Solde théorique de la caisse à une date (fin de journée).
	 *
	 * @param int    $exo  Exercice.
	 * @param int    $sid  Service.
	 * @param string $date Date (AAAA-MM-JJ).
	 * @return int
	 */
	public static function solde_theorique( $exo, $sid, $date ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mvt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CASE WHEN type = 'entree' AND date_operation <= %s THEN montant WHEN type = 'sortie' AND statut = 'payee' AND COALESCE(date_paiement, date_operation) <= %s THEN -montant ELSE 0 END),0) FROM $t WHERE exercice_id = %d AND service_id = %d", $date, $date, $exo, $sid ) );
		return IGE_FFM_Budget::solde_initial( $exo, $sid ) + $mvt;
	}

	/**
	 * Écritures du mois encore non transmises (avertissement avant clôture).
	 *
	 * @param int $exo  Exercice.
	 * @param int $sid  Service.
	 * @param int $mois Mois.
	 * @return int
	 */
	public static function non_transmises_du_mois( $exo, $sid, $mois ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND service_id = %d AND mois <= %d AND etat_transmission IN ('non_transmise','a_retransmettre')", $exo, $sid, $mois ) );
	}

	/**
	 * Calcule le comptage à partir des saisies.
	 *
	 * @param array $in cdf[coupure] => nombre, usd[coupure] => nombre, taux, autres.
	 * @return array|WP_Error Détail normalisé avec total_cdf, total_usd, contre_valeur_usd, autres, total.
	 */
	public static function compter( array $in ) {
		$c      = self::coupures();
		$detail = array( 'cdf' => array(), 'usd' => array() );
		$tc     = 0;
		$tu     = 0;
		foreach ( array( 'cdf' => 'CDF', 'usd' => 'USD' ) as $k => $dev ) {
			foreach ( $c[ $dev ] as $coupure ) {
				$raw = trim( (string) ( $in[ $k ][ $coupure ] ?? '' ) );
				$n   = '' === $raw ? 0 : ige_ffm_parse_montant( $raw );
				if ( null === $n ) {
					return new WP_Error( 'billetage', sprintf( 'Nombre de billets invalide pour %s %s.', ige_ffm_montant( $coupure, '' ), $dev ) );
				}
				if ( $n ) {
					$detail[ $k ][ $coupure ] = $n;
				}
				if ( 'cdf' === $k ) {
					$tc += $n * $coupure;
				} else {
					$tu += $n * $coupure;
				}
			}
		}
		$taux   = ige_ffm_parse_montant( $in['taux'] ?? '' );
		$autres = ige_ffm_parse_montant( '' === trim( (string) ( $in['autres'] ?? '' ) ) ? '0' : $in['autres'] );
		if ( $tu > 0 && ( null === $taux || $taux <= 0 ) ) {
			return new WP_Error( 'taux', 'Indiquez le taux du jour (CDF pour 1 USD) pour convertir les dollars comptés.' );
		}
		if ( null === $autres ) {
			return new WP_Error( 'autres', 'Montant des autres disponibilités invalide.' );
		}
		$detail['taux']              = $tu > 0 ? $taux : 0;
		$detail['total_cdf']         = $tc;
		$detail['total_usd']         = $tu;
		$detail['contre_valeur_usd'] = $tu * ( $tu > 0 ? $taux : 0 );
		$detail['autres']            = $autres;
		$detail['autres_detail']     = mb_substr( trim( sanitize_text_field( (string) ( $in['autres_detail'] ?? '' ) ) ), 0, 190 );
		$detail['total']             = $tc + $detail['contre_valeur_usd'] + $autres;
		return $detail;
	}

	/**
	 * Enregistre (ou met à jour) le comptage d'un arrêté mensuel, sans le signer.
	 *
	 * @param int      $sid     Service.
	 * @param int      $mois    Mois.
	 * @param array    $in      Saisies du comptage (voir compter()) et « explication ».
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Identifiant de l'arrêté.
	 */
	public static function enregistrer( $sid, $mois, array $in, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = (int) $sid;
		$mois    = (int) $mois;
		if ( ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'L’arrêté de caisse est établi par le service.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$exo     = (int) $ex['id'];
		$mois_ac = (int) substr( min( IGE_FFM_Contexte::aujourdhui(), $ex['date_fin'] ), 5, 2 );
		if ( $mois < 1 || $mois > 12 || ( substr( IGE_FFM_Contexte::aujourdhui(), 0, 4 ) === (string) $ex['annee'] && $mois > $mois_ac ) ) {
			return new WP_Error( 'mois', 'Un arrêté ne peut porter que sur un mois commencé.' );
		}
		if ( self::dernier_mois_clos( $exo, $sid ) >= $mois ) {
			return new WP_Error( 'mois', 'Ce mois est déjà clôturé.' );
		}
		$d = self::compter( $in );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$theo = self::solde_theorique( $exo, $sid, self::fin_de_mois( $ex, $mois ) );
		$row  = array(
			'solde_theorique' => $theo,
			'total_compte'    => $d['total'],
			'ecart'           => $d['total'] - $theo,
			'explication'     => trim( sanitize_textarea_field( (string) ( $in['explication'] ?? '' ) ) ),
			'billetage'       => wp_json_encode( $d ),
			'signe_agent_par' => null,
			'signe_agent_le'  => null,
			'signe_resp_par'  => null,
			'signe_resp_le'   => null,
			'statut'          => 'brouillon',
		);
		$t  = IGE_FFM_DB::t( 'arrete' );
		$ar = self::mensuel( $exo, $sid, $mois );
		if ( $ar && 'cloture' !== $ar['statut'] ) {
			$wpdb->update( $t, $row, array( 'id' => (int) $ar['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $ar['id'];
		} else {
			$wpdb->insert( $t, $row + array( 'exercice_id' => $exo, 'service_id' => $sid, 'mois' => $mois, 'type' => 'mensuel', 'devise' => 'CDF', 'created_by' => (int) $user_id, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$id = (int) $wpdb->insert_id;
		}
		IGE_FFM_Audit::log( 'comptage_caisse', 'arrete', self::reference( self::get( $id ) ), null, array( 'solde_theorique' => $theo, 'compte' => $d['total'], 'ecart' => $d['total'] - $theo ), $user_id );
		return $id;
	}

	/**
	 * Contrôles communs avant une signature : solde théorique inchangé, écart expliqué.
	 *
	 * @param array $a Arrêté.
	 * @return true|WP_Error
	 */
	private static function controler( array $a ) {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex || (int) $ex['id'] !== (int) $a['exercice_id'] ) {
			return new WP_Error( 'exercice', 'L’exercice de cet arrêté est clos.' );
		}
		$date = 'mensuel' === $a['type'] ? self::fin_de_mois( $ex, (int) $a['mois'] ) : '9999-12-31';
		if ( 'mensuel' === $a['type'] && self::solde_theorique( (int) $a['exercice_id'], (int) $a['service_id'], $date ) !== (int) $a['solde_theorique'] ) {
			return new WP_Error( 'solde', 'Le solde du livre a changé depuis le comptage (nouvelle écriture ou paiement). Vérifiez la caisse et enregistrez de nouveau le comptage.' );
		}
		if ( 0 !== (int) $a['ecart'] && mb_strlen( trim( (string) $a['explication'] ) ) < 10 ) {
			return new WP_Error( 'explication', sprintf( 'Écart de %s : une explication est obligatoire avant de signer.', ige_ffm_montant( (int) $a['ecart'] ) ) );
		}
		return true;
	}

	/**
	 * Signature de l'agent (arrêté mensuel).
	 *
	 * @param int      $id      Arrêté.
	 * @param int|null $user_id Agent.
	 * @return true|WP_Error
	 */
	public static function signer_agent( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$a       = self::get( $id );
		if ( ! $a || 'mensuel' !== $a['type'] || ! IGE_FFM_Contexte::peut_saisir( (int) $a['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Arrêté introuvable dans votre service.' );
		}
		if ( 'brouillon' !== $a['statut'] ) {
			return new WP_Error( 'etat', 'Cet arrêté est déjà signé.' );
		}
		$ok = self::controler( $a );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$wpdb->update( IGE_FFM_DB::t( 'arrete' ), array( 'statut' => 'signe_agent', 'signe_agent_par' => $user_id, 'signe_agent_le' => ige_ffm_now() ), array( 'id' => $id, 'statut' => 'brouillon' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'signature_arrete', 'arrete', self::reference( $a ), null, array( 'signataire' => 'agent', 'ecart' => (int) $a['ecart'] ), $user_id );
		return true;
	}

	/**
	 * Signature du Responsable : le mois est clôturé.
	 *
	 * @param int      $id      Arrêté.
	 * @param int|null $user_id Responsable.
	 * @return true|WP_Error
	 */
	public static function signer_responsable( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$a       = self::get( $id );
		if ( ! $a || 'mensuel' !== $a['type'] || ! user_can( $user_id, 'ige_signer_arrete' ) || (int) $a['service_id'] !== IGE_FFM_Contexte::service_utilisateur( $user_id ) ) {
			return new WP_Error( 'droits', 'Seul le Responsable du service signe l’arrêté.' );
		}
		if ( 'signe_agent' !== $a['statut'] ) {
			return new WP_Error( 'etat', 'L’agent qui a compté la caisse doit signer d’abord.' );
		}
		if ( (int) $a['signe_agent_par'] === (int) $user_id ) {
			return new WP_Error( 'separation', 'Le Responsable qui contresigne doit être une autre personne que l’agent qui a compté.' );
		}
		$ok = self::controler( $a );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$wpdb->update( IGE_FFM_DB::t( 'arrete' ), array( 'statut' => 'cloture', 'signe_resp_par' => $user_id, 'signe_resp_le' => ige_ffm_now() ), array( 'id' => $id, 'statut' => 'signe_agent' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'cloture_mois', 'arrete', self::reference( $a ), null, array( 'mois' => (int) $a['mois'], 'solde' => (int) $a['solde_theorique'], 'ecart' => (int) $a['ecart'] ), $user_id );
		IGE_FFM_Rapports::enregistrer_pv( (int) $id, $user_id );
		return true;
	}

	/**
	 * Comptage inopiné par le central.
	 *
	 * @param int      $sid     Service.
	 * @param array    $in      Comptage, « explication », « agent_present » (identifiant du compte).
	 * @param int|null $user_id Contrôleur.
	 * @return int|WP_Error
	 */
	public static function inopine( $sid, array $in, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = (int) $sid;
		if ( ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
			return new WP_Error( 'droits', 'Le comptage inopiné est fait par Administration et Finance.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		$s  = IGE_FFM_Referentiels::get( 'service', $sid );
		if ( ! $ex || ! $s ) {
			return new WP_Error( 'service', 'Choisissez un service.' );
		}
		$agent = (int) ( $in['agent_present'] ?? 0 );
		if ( ! $agent || IGE_FFM_Contexte::service_utilisateur( $agent ) !== $sid ) {
			return new WP_Error( 'agent', 'Indiquez l’agent du service présent pendant le comptage.' );
		}
		$d = self::compter( $in );
		if ( is_wp_error( $d ) ) {
			return $d;
		}
		$d['agent_present'] = $agent;
		$d['heure']         = wp_date( 'H:i' );
		$theo               = self::solde_theorique( (int) $ex['id'], $sid, '9999-12-31' );
		$expl               = trim( sanitize_textarea_field( (string) ( $in['explication'] ?? '' ) ) );
		if ( $d['total'] !== $theo && mb_strlen( $expl ) < 10 ) {
			return new WP_Error( 'explication', sprintf( 'Écart de %s : consignez les explications de l’agent.', ige_ffm_montant( $d['total'] - $theo ) ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'arrete' ),
			array(
				'exercice_id'     => (int) $ex['id'],
				'service_id'      => $sid,
				'mois'            => (int) wp_date( 'n' ),
				'type'            => 'inopine',
				'devise'          => 'CDF',
				'solde_theorique' => $theo,
				'total_compte'    => $d['total'],
				'ecart'           => $d['total'] - $theo,
				'explication'     => $expl,
				'billetage'       => wp_json_encode( $d ),
				'signe_resp_par'  => $user_id,
				'signe_resp_le'   => ige_ffm_now(),
				'statut'          => 'constate',
				'created_by'      => $user_id,
				'created_at'      => ige_ffm_now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'comptage_inopine', 'arrete', self::reference( self::get( $id ) ), null, array( 'service' => $s['code'], 'solde_theorique' => $theo, 'compte' => $d['total'], 'ecart' => $d['total'] - $theo ), $user_id );
		return $id;
	}

	/**
	 * Contresignature d'un comptage inopiné par un agent du service.
	 *
	 * @param int      $id      Arrêté.
	 * @param int|null $user_id Agent.
	 * @return true|WP_Error
	 */
	public static function contresigner( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$a       = self::get( $id );
		if ( ! $a || 'inopine' !== $a['type'] || ! IGE_FFM_Contexte::peut_saisir( (int) $a['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Procès-verbal introuvable dans votre service.' );
		}
		if ( 'constate' !== $a['statut'] ) {
			return new WP_Error( 'etat', 'Ce procès-verbal est déjà contresigné.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'arrete' ), array( 'statut' => 'contresigne', 'signe_agent_par' => $user_id, 'signe_agent_le' => ige_ffm_now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'signature_arrete', 'arrete', self::reference( $a ), null, array( 'signataire' => 'agent (comptage inopiné)' ), $user_id );
		IGE_FFM_Rapports::enregistrer_pv( (int) $id, $user_id );
		return true;
	}

	/**
	 * Référence d'un procès-verbal.
	 *
	 * @param array|null $a Arrêté.
	 * @return string
	 */
	public static function reference( $a ) {
		if ( ! $a ) {
			return '';
		}
		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( (int) $a['service_id'] ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$annee = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT annee FROM ' . IGE_FFM_DB::t( 'exercice' ) . ' WHERE id = %d', $a['exercice_id'] ) );
		return 'mensuel' === $a['type'] ? sprintf( 'PV-%s-%d-%02d', $code, $annee, $a['mois'] ) : sprintf( 'CI-%s-%d-%04d', $code, $annee, $a['id'] );
	}

	/**
	 * Libellé et couleur de l'état.
	 *
	 * @param array $a Arrêté.
	 * @return array{0:string,1:string}
	 */
	public static function etat( array $a ) {
		$l = array(
			'brouillon'   => array( 'Compté, à signer', 'neutre' ),
			'signe_agent' => array( 'Signé par l’agent', 'wait' ),
			'cloture'     => array( 'Mois clôturé', 'ok' ),
			'constate'    => array( 'À contresigner', 'wait' ),
			'contresigne' => array( 'Contresigné', 'ok' ),
		);
		return $l[ $a['statut'] ] ?? array( $a['statut'], 'neutre' );
	}
}
