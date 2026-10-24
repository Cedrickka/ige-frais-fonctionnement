<?php
/**
 * Demandes de fonds en ligne (VA-03) : le service exprime un besoin, le chef de service vise,
 * Administration et Finance accorde (ou réduit), l'Inspecteur Général vise au-delà du seuil,
 * puis Administration et Finance décaisse : le décaissement crée l'avance de fonds (AV-…)
 * et son entrée dans la caisse du service.
 *
 * Statuts : soumise (circuit en cours) → visee (accordée, à décaisser) → decaissee ;
 * refusee ; annulee (par le service, avant tout visa).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Demandes de fonds.
 */
final class IGE_FFM_Demandes {

	/**
	 * Libellés des statuts : code => [ libellé, ton ].
	 *
	 * @return array<string,array>
	 */
	public static function statuts() {
		return array(
			'soumise'   => array( 'Visas en cours', 'wait' ),
			'visee'     => array( 'Accordée, à décaisser', 'info' ),
			'decaissee' => array( 'Décaissée', 'ok' ),
			'refusee'   => array( 'Refusée', 'ko' ),
			'annulee'   => array( 'Annulée', 'neutre' ),
		);
	}

	/**
	 * Une demande.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'demande' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Montant qui détermine le circuit (accordé s'il est connu).
	 *
	 * @param array $d Demande.
	 * @return int
	 */
	public static function montant_circuit( array $d ) {
		return null === $d['montant_accorde'] ? (int) $d['montant'] : (int) $d['montant_accorde'];
	}

	/**
	 * Liste des demandes.
	 *
	 * @param int    $exo    Exercice.
	 * @param int    $sid    Service (0 = tous).
	 * @param string $statut Statut (vide = tous).
	 * @return array[]
	 */
	public static function liste( $exo, $sid = 0, $statut = '' ) {
		global $wpdb;
		$w = array( $wpdb->prepare( 'exercice_id = %d', $exo ) );
		if ( $sid ) {
			$w[] = $wpdb->prepare( 'service_id = %d', $sid );
		}
		if ( $statut ) {
			$w[] = $wpdb->prepare( 'statut = %s', $statut );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . IGE_FFM_DB::t( 'demande' ) . ' WHERE ' . implode( ' AND ', $w ) . ' ORDER BY id DESC', ARRAY_A );
	}

	/**
	 * Demandes dont le circuit de visas est en cours.
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function en_circuit( $exo ) {
		return self::liste( $exo, 0, 'soumise' );
	}

	/**
	 * Crée une demande (service).
	 *
	 * @param array    $data    objet, rubriques (ids), montant, date_besoin, justification.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function creer( array $data, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = IGE_FFM_Contexte::service_utilisateur( $user_id );
		if ( ! $sid || ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Seul un compte de service peut demander des fonds.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$objet = trim( sanitize_text_field( (string) ( $data['objet'] ?? '' ) ) );
		$mnt   = ige_ffm_parse_montant( $data['montant'] ?? '' );
		$date  = (string) ( $data['date_besoin'] ?? '' );
		$just  = trim( sanitize_textarea_field( (string) ( $data['justification'] ?? '' ) ) );
		$rubs  = array();
		foreach ( (array) ( $data['rubriques'] ?? array() ) as $rid ) {
			$r = IGE_FFM_Referentiels::get( 'rubrique', (int) $rid );
			if ( $r && (int) $r['actif'] ) {
				$rubs[] = $r['code'];
			}
		}
		if ( mb_strlen( $objet ) < 3 ) {
			return new WP_Error( 'objet', 'Indiquez l’objet de la demande (période, mission, achat).' );
		}
		if ( null === $mnt || $mnt <= 0 ) {
			return new WP_Error( 'montant', 'Le montant doit être un nombre entier supérieur à 0 (en CDF).' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date < IGE_FFM_Contexte::aujourdhui() || $date > $ex['date_fin'] ) {
			return new WP_Error( 'date_besoin', sprintf( 'La date de besoin doit être comprise entre aujourd’hui et la fin de l’exercice %d.', $ex['annee'] ) );
		}
		if ( ! $rubs ) {
			return new WP_Error( 'rubriques', 'Cochez au moins une rubrique concernée.' );
		}
		if ( mb_strlen( $just ) < 10 ) {
			return new WP_Error( 'justification', 'Justifiez le besoin en quelques mots.' );
		}
		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( $sid ) ) ?: 'S' . $sid;
		$t    = IGE_FFM_DB::t( 'demande' );
		$id   = 0;
		for ( $essai = 0; $essai < 5 && ! $id; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d AND service_id = %d", $ex['id'], $sid ) ) + $essai;
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'reference'     => sprintf( 'DF-%s-%03d', $code, $n ),
					'exercice_id'   => (int) $ex['id'],
					'service_id'    => $sid,
					'objet'         => mb_substr( $objet, 0, 255 ),
					'rubriques'     => implode( ', ', $rubs ),
					'montant'       => $mnt,
					'date_besoin'   => $date,
					'justification' => mb_substr( $just, 0, 2000 ),
					'statut'        => 'soumise',
					'created_by'    => (int) $user_id,
					'created_at'    => ige_ffm_now(),
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
		$d = self::get( $id );
		IGE_FFM_Audit::log( 'demande_fonds', 'demande', $d['reference'], null, array( 'objet' => $objet, 'montant' => $mnt, 'besoin' => $date, 'rubriques' => $d['rubriques'] ), $user_id );
		IGE_FFM_Visas::notifier( 'demande', $d['reference'], 'responsable', $sid, $mnt );
		return $id;
	}

	/**
	 * Visa du chef de service ou de l'Inspecteur Général (le visa d'Administration et Finance passe par accorder()).
	 *
	 * @param int      $id          Demande.
	 * @param string   $decision    vise | refuse.
	 * @param string   $commentaire Commentaire.
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error
	 */
	public static function viser( $id, $decision, $commentaire, $user_id = null ) {
		$d = self::get( (int) $id );
		if ( ! $d || 'soumise' !== $d['statut'] ) {
			return new WP_Error( 'etat', 'Cette demande n’attend pas de visa.' );
		}
		$etat = IGE_FFM_Visas::etat( 'demande', (int) $id, self::montant_circuit( $d ) );
		if ( 'comptable' === $etat['suivante'] && 'refuse' !== $decision ) {
			return new WP_Error( 'etape', 'Le visa d’Administration et Finance fixe le montant accordé : utilisez « Accorder ».' );
		}
		return self::apres_visa( $d, IGE_FFM_Visas::viser( 'demande', (int) $id, (int) $d['service_id'], self::montant_circuit( $d ), $decision, $commentaire, $d['reference'], $user_id ), $user_id );
	}

	/**
	 * Visa d'Administration et Finance : montant accordé (égal ou réduit).
	 *
	 * @param int      $id          Demande.
	 * @param string   $montant     Montant accordé.
	 * @param string   $commentaire Commentaire (obligatoire si réduit).
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error
	 */
	public static function accorder( $id, $montant, $commentaire, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$d       = self::get( (int) $id );
		if ( ! $d || 'soumise' !== $d['statut'] ) {
			return new WP_Error( 'etat', 'Cette demande n’attend pas de visa.' );
		}
		$etat = IGE_FFM_Visas::etat( 'demande', (int) $id, (int) $d['montant'] );
		if ( 'comptable' !== $etat['suivante'] ) {
			return new WP_Error( 'etape', 'Le visa d’Administration et Finance n’est pas encore attendu.' );
		}
		$m = ige_ffm_parse_montant( $montant );
		if ( null === $m || $m <= 0 || $m > (int) $d['montant'] ) {
			return new WP_Error( 'montant', 'Le montant accordé doit être positif et au plus égal au montant demandé.' );
		}
		if ( $m < (int) $d['montant'] && mb_strlen( trim( (string) $commentaire ) ) < 5 ) {
			return new WP_Error( 'motif', 'Expliquez la réduction du montant.' );
		}
		if ( ! IGE_FFM_Visas::peut( $user_id, 'comptable', (int) $d['service_id'] ) ) {
			return new WP_Error( 'droits', 'Visa réservé à Administration et Finance.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'montant_accorde' => $m ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$com = ( $m < (int) $d['montant'] ? 'Accordé ' . ige_ffm_montant( $m ) . ' sur ' . ige_ffm_montant( (int) $d['montant'] ) . '. ' : '' ) . trim( (string) $commentaire );
		$r   = IGE_FFM_Visas::viser( 'demande', (int) $id, (int) $d['service_id'], $m, 'vise', $com, $d['reference'], $user_id );
		if ( is_wp_error( $r ) ) {
			$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'montant_accorde' => $d['montant_accorde'] ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $r;
		}
		return self::apres_visa( self::get( (int) $id ), $r, $user_id );
	}

	/**
	 * Met à jour le statut après un visa.
	 *
	 * @param array          $d       Demande.
	 * @param array|WP_Error $r       Résultat du visa.
	 * @param int|null       $user_id Auteur.
	 * @return array|WP_Error
	 */
	private static function apres_visa( array $d, $r, $user_id ) {
		global $wpdb;
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['refuse'] ) {
			$v = end( $r['visas'] );
			$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'statut' => 'refusee', 'motif' => $v ? $v['commentaire'] : '' ), array( 'id' => (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::notifier_service( $d, 'Votre demande ' . $d['reference'] . ' a été refusée' . ( $v ? ' (' . IGE_FFM_Visas::etapes()[ $v['etape'] ] . ') : « ' . $v['commentaire'] . ' »' : '' ) . '.' );
		} elseif ( $r['complet'] ) {
			if ( null === $d['montant_accorde'] ) {
				$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'montant_accorde' => (int) $d['montant'] ), array( 'id' => (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'statut' => 'visee' ), array( 'id' => (int) $d['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$d = self::get( (int) $d['id'] );
			self::notifier_service( $d, 'Votre demande ' . $d['reference'] . ' est accordée pour ' . ige_ffm_montant( (int) $d['montant_accorde'] ) . ' : Administration et Finance va procéder au décaissement.' );
		}
		return $r;
	}

	/**
	 * Décaissement : crée l'avance de fonds correspondante.
	 *
	 * @param int      $id         Demande accordée.
	 * @param array    $data       date_remise, mode_paiement_id, derogation.
	 * @param int|null $user_id    Auteur.
	 * @return int|WP_Error Avance.
	 */
	public static function decaisser( $id, array $data, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$d       = self::get( (int) $id );
		if ( ! $d || 'visee' !== $d['statut'] ) {
			return new WP_Error( 'etat', 'Seule une demande accordée (tous visas réunis) peut être décaissée.' );
		}
		$av = IGE_FFM_Avances::creer(
			array(
				'service_id'       => (int) $d['service_id'],
				'montant'          => (string) $d['montant_accorde'],
				'date_remise'      => $data['date_remise'] ?? IGE_FFM_Contexte::aujourdhui(),
				'mode_paiement_id' => (int) ( $data['mode_paiement_id'] ?? 0 ),
				'objet'            => mb_substr( $d['reference'] . ' : ' . $d['objet'], 0, 200 ),
				'derogation'       => $data['derogation'] ?? '',
			),
			$user_id
		);
		if ( is_wp_error( $av ) ) {
			return $av;
		}
		$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'statut' => 'decaissee', 'avance_id' => (int) $av ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$a = IGE_FFM_Avances::get( (int) $av );
		IGE_FFM_Audit::log( 'decaissement_demande', 'demande', $d['reference'], null, array( 'avance' => $a['numero'], 'montant' => (int) $a['montant'] ), $user_id );
		self::notifier_service( $d, 'Les fonds de votre demande ' . $d['reference'] . ' sont décaissés : avance ' . $a['numero'] . ' de ' . ige_ffm_montant( (int) $a['montant'] ) . ', déjà inscrite dans votre caisse.' );
		return (int) $av;
	}

	/**
	 * Annulation par le service, avant tout visa.
	 *
	 * @param int      $id      Demande.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function annuler( $id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$d       = self::get( (int) $id );
		if ( ! $d || ! IGE_FFM_Contexte::peut_saisir( (int) $d['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Demande introuvable dans votre service.' );
		}
		if ( 'soumise' !== $d['statut'] || IGE_FFM_Visas::liste( 'demande', (int) $id ) ) {
			return new WP_Error( 'etat', 'Une demande déjà visée ne peut plus être annulée.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'demande' ), array( 'statut' => 'annulee' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'annulation_demande', 'demande', $d['reference'], null, null, $user_id );
		return true;
	}

	/**
	 * Prévient les comptes du service.
	 *
	 * @param array  $d     Demande.
	 * @param string $texte Message.
	 */
	private static function notifier_service( array $d, $texte ) {
		if ( ! apply_filters( 'ige_ffm_notifier_par_courriel', true, 'demande' ) ) {
			return;
		}
		$mails = array();
		foreach ( IGE_FFM_Comptes::search( array( 'service_id' => (int) $d['service_id'], 'actif' => 1 ) ) as $c ) {
			$u = get_userdata( (int) $c['user_id'] );
			if ( IGE_FFM_Messagerie::adresse_valide( $u ) ) {
				$mails[] = $u->user_email;
			}
		}
		if ( $mails ) {
			IGE_FFM_Messagerie::envoyer( $mails, 'Demande de fonds ' . $d['reference'], "Bonjour,\n\n" . $texte . "\n\n" . ige_ffm_url( 'mes-demandes-de-fonds' ) );
		}
	}

	/**
	 * Besoins en attente (pour la trésorerie) : demandes en circuit ou accordées non décaissées.
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function besoins( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'demande' ) . " WHERE exercice_id = %d AND statut IN ('soumise','visee') ORDER BY date_besoin", $exo ), ARRAY_A );
	}
}
