<?php
/**
 * Échanges sur une écriture (VA-15) : Administration et Finance pose une question sur une écriture
 * transmise sans la rejeter ; le service répond et peut joindre une pièce. Tout est conservé et tracé.
 *
 * État de l'écriture (colonne « echange ») : « question » = réponse du service attendue ;
 * « reponse » = réponse reçue, à examiner par le central ; vide = pas d'échange en cours.
 * La validation ou le rejet de l'écriture clôt l'échange.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Échanges.
 */
final class IGE_FFM_Echanges {

	/**
	 * Messages d'une écriture, du plus ancien au plus récent.
	 *
	 * @param int $eid Écriture.
	 * @return array[]
	 */
	public static function fil( $eid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'echange' ) . ' WHERE ecriture_id = %d ORDER BY id', $eid ), ARRAY_A );
	}

	/**
	 * Écritures en attente d'une réponse du service.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array[]
	 */
	public static function en_attente( $exo, $sid ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND service_id = %d AND echange = 'question' ORDER BY id", $exo, $sid ), ARRAY_A );
	}

	/**
	 * Nombre d'échanges en cours par état, pour le central.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = tous).
	 * @return array{question:int, reponse:int}
	 */
	public static function compter( $exo, $sid = 0 ) {
		global $wpdb;
		$w = $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$r = $wpdb->get_results( $wpdb->prepare( 'SELECT echange, COUNT(*) AS n FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND echange <> '' $w GROUP BY echange", $exo ), ARRAY_A );
		$o = array( 'question' => 0, 'reponse' => 0 );
		foreach ( $r as $x ) {
			$o[ $x['echange'] ] = (int) $x['n'];
		}
		return $o;
	}

	/**
	 * Texte d'un message.
	 *
	 * @param string $m Message.
	 * @return string|WP_Error
	 */
	private static function texte( $m ) {
		$m = trim( sanitize_textarea_field( (string) $m ) );
		if ( mb_strlen( $m ) < 5 ) {
			return new WP_Error( 'message', 'Écrivez votre message (5 caractères au moins).' );
		}
		return mb_substr( $m, 0, 2000 );
	}

	/**
	 * Question du central sur une écriture transmise, à vérifier.
	 *
	 * @param int      $eid     Écriture.
	 * @param string   $message Question.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Identifiant du message.
	 */
	public static function poser( $eid, $message, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_verifier' ) ) {
			return new WP_Error( 'droits', 'Seule Administration et Finance pose des questions sur les écritures.' );
		}
		$e = IGE_FFM_Ecritures::get( (int) $eid );
		if ( ! $e || 'transmise' !== $e['etat_transmission'] || 'a_verifier' !== $e['etat_verification'] ) {
			return new WP_Error( 'etat', 'On ne peut questionner qu’une écriture transmise et pas encore traitée.' );
		}
		$m = self::texte( $message );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		$wpdb->insert( IGE_FFM_DB::t( 'echange' ), array( 'ecriture_id' => (int) $eid, 'service_id' => (int) $e['service_id'], 'user_id' => (int) $user_id, 'sens' => 'question', 'message' => $m, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'echange' => 'question' ), array( 'id' => (int) $eid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'question_ecriture', 'ecriture', $e['id_ecriture'], null, array( 'question' => mb_substr( $m, 0, 200 ) ), $user_id );
		self::notifier_service( $e, $m, $user_id );
		return $id;
	}

	/**
	 * Réponse du service, avec une pièce éventuelle.
	 *
	 * @param int        $eid     Écriture.
	 * @param string     $message Réponse.
	 * @param array|null $file    Pièce ($_FILES).
	 * @param string     $nature  Nature de la pièce.
	 * @param int|null   $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function repondre( $eid, $message, $file = null, $nature = '', $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = IGE_FFM_Ecritures::get( (int) $eid );
		if ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Seul le service de l’écriture peut répondre.' );
		}
		if ( 'question' !== $e['echange'] ) {
			return new WP_Error( 'etat', 'Aucune question en attente sur cette écriture.' );
		}
		$m = self::texte( $message );
		if ( is_wp_error( $m ) ) {
			return $m;
		}
		$pid = null;
		if ( $file && ! empty( $file['name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? 0 ) ) {
			$pid = IGE_FFM_Pieces::enregistrer( $file, 'ecriture', (int) $eid, $user_id, $nature );
			if ( is_wp_error( $pid ) ) {
				return $pid;
			}
		}
		$wpdb->insert( IGE_FFM_DB::t( 'echange' ), array( 'ecriture_id' => (int) $eid, 'service_id' => (int) $e['service_id'], 'user_id' => (int) $user_id, 'sens' => 'reponse', 'message' => $m, 'piece_id' => $pid, 'created_at' => ige_ffm_now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = (int) $wpdb->insert_id;
		$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'echange' => 'reponse' ), array( 'id' => (int) $eid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Anomalies::recalculer( (int) $eid );
		IGE_FFM_Audit::log( 'reponse_ecriture', 'ecriture', $e['id_ecriture'], null, array( 'reponse' => mb_substr( $m, 0, 200 ) ) + ( $pid ? array( 'piece' => $pid ) : array() ), $user_id );
		self::notifier_central( $e, $m, $user_id );
		return $id;
	}

	/**
	 * Clôt l'échange (validation ou rejet de l'écriture).
	 *
	 * @param int[] $ids Écritures.
	 */
	public static function clore( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'UPDATE ' . IGE_FFM_DB::t( 'ecriture' ) . " SET echange = '' WHERE id IN (" . implode( ',', $ids ) . ')' );
		}
	}

	/**
	 * Prévient le service (agents et Responsable actifs) d'une question.
	 *
	 * @param array  $e       Écriture.
	 * @param string $m       Question.
	 * @param int    $user_id Auteur.
	 */
	private static function notifier_service( array $e, $m, $user_id ) {
		if ( ! apply_filters( 'ige_ffm_notifier_par_courriel', true, 'question' ) ) {
			return;
		}
		$mails = array();
		foreach ( IGE_FFM_Comptes::search( array( 'service_id' => (int) $e['service_id'], 'actif' => 1 ) ) as $c ) {
			$u = get_userdata( (int) $c['user_id'] );
			if ( IGE_FFM_Messagerie::adresse_valide( $u ) ) {
				$mails[] = $u->user_email;
			}
		}
		if ( ! $mails ) {
			return;
		}
		$a = get_userdata( (int) $user_id );
		IGE_FFM_Messagerie::envoyer(
			$mails,
			'Question sur l’écriture ' . $e['id_ecriture'],
			'Bonjour,' . "\n\n" . ( $a ? $a->display_name : 'Administration et Finance' ) . ' (Administration et Finance) pose une question sur l’écriture ' . $e['id_ecriture'] . ' (' . $e['description'] . ', ' . ige_ffm_montant( (int) $e['montant'] ) . ') :' . "\n\n« " . $m . " »\n\n"
			. 'L’écriture n’est pas rejetée. Répondez dans l’application, avec une pièce si nécessaire :' . "\n\n" . ige_ffm_url( 'transmission' ) . '#ige-questions'
		);
	}

	/**
	 * Prévient l'auteur de la dernière question d'une réponse.
	 *
	 * @param array  $e       Écriture.
	 * @param string $m       Réponse.
	 * @param int    $user_id Auteur de la réponse.
	 */
	private static function notifier_central( array $e, $m, $user_id ) {
		if ( ! apply_filters( 'ige_ffm_notifier_par_courriel', true, 'reponse' ) ) {
			return;
		}
		$q = null;
		foreach ( array_reverse( self::fil( (int) $e['id'] ) ) as $x ) {
			if ( 'question' === $x['sens'] ) {
				$q = $x;
				break;
			}
		}
		$dest = $q ? get_userdata( (int) $q['user_id'] ) : null;
		if ( ! IGE_FFM_Messagerie::adresse_valide( $dest ) ) {
			return;
		}
		$a = get_userdata( (int) $user_id );
		IGE_FFM_Messagerie::envoyer(
			$dest->user_email,
			'Réponse sur l’écriture ' . $e['id_ecriture'],
			'Bonjour,' . "\n\n" . ( $a ? $a->display_name : 'Le service' ) . ' (' . IGE_FFM_Comptes::service_code( (int) $e['service_id'] ) . ') a répondu à votre question sur l’écriture ' . $e['id_ecriture'] . ' :' . "\n\n« " . $m . " »\n\n"
			. ige_ffm_url( 'verification', array( 'ecriture' => (int) $e['id'] ) )
		);
	}
}
