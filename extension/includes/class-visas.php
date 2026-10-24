<?php
/**
 * Circuit de visas (VA-14), commun aux demandes de fonds, aux ordonnancements de dépense et aux
 * ordres de mission : visa du Responsable du service, puis d'Administration et Finance (comptable),
 * puis de l'Inspecteur Général au-delà du seuil réglé (« visa_direction_seuil »).
 *
 * Une même personne ne donne qu'un visa par dossier. Un refus arrête le circuit.
 * Les visas sont conservés et journalisés ; ils ne se modifient ni ne s'effacent.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Visas.
 */
final class IGE_FFM_Visas {

	/**
	 * Libellés des étapes.
	 *
	 * @return array<string,string>
	 */
	public static function etapes() {
		return array(
			'responsable' => 'Chef de service',
			'comptable'   => 'Administration et Finance',
			'direction'   => 'Inspecteur Général',
		);
	}

	/**
	 * Libellés des objets visés.
	 *
	 * @return array<string,string>
	 */
	public static function objets() {
		return array(
			'demande'         => 'Demande de fonds',
			'ordonnancement'  => 'Ordonnancement',
			'mission'         => 'Ordre de mission',
		);
	}

	/**
	 * Étapes du circuit pour un montant.
	 *
	 * @param int $montant Montant (CDF).
	 * @return string[]
	 */
	public static function circuit( $montant ) {
		$c     = array( 'responsable', 'comptable' );
		$seuil = (int) IGE_FFM_Reglages::get( 'visa_direction_seuil' );
		if ( $seuil > 0 && (int) $montant > $seuil ) {
			$c[] = 'direction';
		}
		return $c;
	}

	/**
	 * Visas donnés sur un objet.
	 *
	 * @param string $objet Objet.
	 * @param int    $id    Identifiant.
	 * @return array[]
	 */
	public static function liste( $objet, $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'visa' ) . ' WHERE objet = %s AND objet_id = %d ORDER BY id', $objet, $id ), ARRAY_A );
	}

	/**
	 * État du circuit.
	 *
	 * @param string $objet   Objet.
	 * @param int    $id      Identifiant.
	 * @param int    $montant Montant.
	 * @return array{circuit:string[], visas:array, suivante:?string, refuse:bool, complet:bool}
	 */
	public static function etat( $objet, $id, $montant ) {
		$circuit = self::circuit( $montant );
		$visas   = array();
		$refuse  = false;
		foreach ( self::liste( $objet, $id ) as $v ) {
			$visas[ $v['etape'] ] = $v;
			if ( 'refuse' === $v['decision'] ) {
				$refuse = true;
			}
		}
		$suivante = null;
		if ( ! $refuse ) {
			foreach ( $circuit as $e ) {
				if ( ! isset( $visas[ $e ] ) ) {
					$suivante = $e;
					break;
				}
			}
		}
		return array(
			'circuit'  => $circuit,
			'visas'    => $visas,
			'suivante' => $suivante,
			'refuse'   => $refuse,
			'complet'  => ! $refuse && null === $suivante,
		);
	}

	/**
	 * L'utilisateur peut-il donner ce visa ?
	 *
	 * @param int    $user_id Utilisateur.
	 * @param string $etape   Étape.
	 * @param int    $sid     Service du dossier.
	 * @return bool
	 */
	public static function peut( $user_id, $etape, $sid ) {
		switch ( $etape ) {
			case 'responsable':
				return user_can( $user_id, 'ige_signer_arrete' ) && (int) $sid === IGE_FFM_Contexte::service_utilisateur( $user_id );
			case 'comptable':
				return user_can( $user_id, 'ige_verifier' );
			case 'direction':
				return user_can( $user_id, 'ige_viser_direction' );
		}
		return false;
	}

	/**
	 * Donne un visa (ou un refus) à l'étape attendue.
	 *
	 * @param string   $objet       Objet.
	 * @param int      $id          Identifiant.
	 * @param int      $sid         Service du dossier.
	 * @param int      $montant     Montant.
	 * @param string   $decision    vise | refuse.
	 * @param string   $commentaire Commentaire (obligatoire pour un refus).
	 * @param string   $ref         Référence lisible (journal).
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error État après le visa.
	 */
	public static function viser( $objet, $id, $sid, $montant, $decision, $commentaire, $ref, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$etat    = self::etat( $objet, $id, $montant );
		$etape   = $etat['suivante'];
		if ( ! $etape ) {
			return new WP_Error( 'etat', $etat['refuse'] ? 'Ce dossier a été refusé.' : 'Tous les visas ont déjà été donnés.' );
		}
		if ( ! self::peut( $user_id, $etape, $sid ) ) {
			return new WP_Error( 'droits', 'Le visa attendu est celui de : ' . self::etapes()[ $etape ] . '.' );
		}
		foreach ( $etat['visas'] as $v ) {
			if ( (int) $v['user_id'] === (int) $user_id ) {
				return new WP_Error( 'separation', 'Vous avez déjà visé ce dossier : le visa suivant doit venir d’une autre personne.' );
			}
		}
		$decision    = 'refuse' === $decision ? 'refuse' : 'vise';
		$commentaire = mb_substr( trim( sanitize_textarea_field( (string) $commentaire ) ), 0, 500 );
		if ( 'refuse' === $decision && mb_strlen( $commentaire ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif du refus.' );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_DB::t( 'visa' ),
			array(
				'objet'       => $objet,
				'objet_id'    => (int) $id,
				'etape'       => $etape,
				'decision'    => $decision,
				'commentaire' => $commentaire,
				'user_id'     => (int) $user_id,
				'created_at'  => ige_ffm_now(),
			)
		);
		IGE_FFM_Audit::log( 'refuse' === $decision ? 'refus_visa' : 'visa', $objet, $ref, null, array_filter( array( 'etape' => self::etapes()[ $etape ], 'commentaire' => $commentaire ) ), $user_id );
		$apres = self::etat( $objet, $id, $montant );
		if ( $apres['suivante'] ) {
			self::notifier( $objet, $ref, $apres['suivante'], (int) $sid, $montant );
		}
		return $apres;
	}

	/**
	 * Prévient les personnes qui doivent donner le visa suivant.
	 *
	 * @param string $objet   Objet.
	 * @param string $ref     Référence.
	 * @param string $etape   Étape attendue.
	 * @param int    $sid     Service.
	 * @param int    $montant Montant.
	 */
	public static function notifier( $objet, $ref, $etape, $sid, $montant ) {
		if ( ! apply_filters( 'ige_ffm_notifier_par_courriel', true, 'visa' ) ) {
			return;
		}
		$mails = array();
		foreach ( IGE_FFM_Comptes::search( array( 'actif' => 1 ) ) as $c ) {
			if ( self::peut( (int) $c['user_id'], $etape, $sid ) ) {
				$u = get_userdata( (int) $c['user_id'] );
				if ( IGE_FFM_Messagerie::adresse_valide( $u ) ) {
					$mails[] = $u->user_email;
				}
			}
		}
		if ( ! $mails ) {
			return;
		}
		$pages = array( 'demande' => 'direction' === $etape ? 'visas' : ( 'responsable' === $etape ? 'mes-demandes-de-fonds' : 'demandes-de-fonds' ), 'ordonnancement' => 'direction' === $etape ? 'visas' : 'dossier-de-depense', 'mission' => 'direction' === $etape ? 'visas' : 'missions' );
		IGE_FFM_Messagerie::envoyer(
			array_unique( $mails ),
			'Visa attendu : ' . self::objets()[ $objet ] . ' ' . $ref,
			"Bonjour,\n\n" . self::objets()[ $objet ] . ' ' . $ref . ' (' . IGE_FFM_Comptes::service_code( $sid ) . ', ' . ige_ffm_montant( (int) $montant ) . ') attend votre visa (' . self::etapes()[ $etape ] . ").\n\n" . ige_ffm_url( $pages[ $objet ] )
		);
	}

	/**
	 * Frise des visas (HTML).
	 *
	 * @param array $etat État.
	 * @return string
	 */
	public static function frise( array $etat ) {
		$h = '<ol class="ige-visas">';
		foreach ( $etat['circuit'] as $e ) {
			$v = $etat['visas'][ $e ] ?? null;
			$u = $v ? get_userdata( (int) $v['user_id'] ) : null;
			if ( $v ) {
				$cls = 'refuse' === $v['decision'] ? 'ko' : 'ok';
				$txt = ( 'refuse' === $v['decision'] ? 'Refusé' : 'Visé' ) . ' par ' . ( $u ? $u->display_name : '?' ) . ' le ' . wp_date( 'd/m/Y à H:i', strtotime( $v['created_at'] ) ) . ( $v['commentaire'] ? ' — « ' . $v['commentaire'] . ' »' : '' );
			} elseif ( $etat['refuse'] ) {
				$cls = 'neutre';
				$txt = 'Sans objet (dossier refusé)';
			} else {
				$cls = $etat['suivante'] === $e ? 'wait' : 'neutre';
				$txt = $etat['suivante'] === $e ? 'Visa attendu' : 'À venir';
			}
			$h .= '<li class="ige-visa-' . $cls . '"><b>' . esc_html( self::etapes()[ $e ] ) . '</b><span>' . esc_html( $txt ) . '</span></li>';
		}
		return $h . '</ol>';
	}

	/**
	 * Formulaire de visa (si l'utilisateur peut viser).
	 *
	 * @param string $objet  Objet.
	 * @param int    $id     Identifiant.
	 * @param array  $etat   État.
	 * @param int    $sid    Service.
	 * @param string $retour URL de retour.
	 * @return string
	 */
	public static function formulaire( $objet, $id, array $etat, $sid, $retour ) {
		$uid = get_current_user_id();
		if ( ! $etat['suivante'] || ! self::peut( $uid, $etat['suivante'], $sid ) ) {
			return '';
		}
		foreach ( $etat['visas'] as $v ) {
			if ( (int) $v['user_id'] === $uid ) {
				return '<p class="ige-small ige-muted">Vous avez déjà visé ce dossier ; le visa suivant revient à une autre personne.</p>';
			}
		}
		return IGE_FFM_Ecrans::form_open( 'viser', 'class="ige-visa-form"' )
			. '<input type="hidden" name="objet" value="' . esc_attr( $objet ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
			. '<label for="ige-visa-com-' . esc_attr( $objet . $id ) . '" class="ige-small" style="font-weight:600">Votre visa (' . esc_html( self::etapes()[ $etat['suivante'] ] ) . ')</label>'
			. '<textarea id="ige-visa-com-' . esc_attr( $objet . $id ) . '" name="commentaire" class="ige-input" rows="2" maxlength="500" placeholder="Avis ou observations (obligatoire pour un refus)"></textarea>'
			. '<div class="ige-row" style="margin-top:6px"><button type="submit" name="decision" value="vise" class="ige-btn ige-btn-jaune">Viser</button><button type="submit" name="decision" value="refuse" class="ige-btn ige-btn-rouge" data-ige-confirm-btn="Refuser ce dossier ?">Refuser</button></div></form>';
	}

	/**
	 * Traitement du formulaire de visa (admin-post).
	 */
	public static function handle() {
		ige_ffm_check_form( 'viser' );
		ige_ffm_require( 'ige_acceder' );
		$objet = sanitize_key( ige_ffm_post( 'objet' ) );
		$id    = (int) ige_ffm_post( 'id' );
		$res   = new WP_Error( 'objet', 'Dossier inconnu.' );
		if ( 'demande' === $objet ) {
			$res = IGE_FFM_Demandes::viser( $id, ige_ffm_post( 'decision' ), ige_ffm_post( 'commentaire' ) );
		} elseif ( 'ordonnancement' === $objet ) {
			$res = IGE_FFM_Chaine::viser( $id, ige_ffm_post( 'decision' ), ige_ffm_post( 'commentaire' ) );
		} elseif ( 'mission' === $objet ) {
			$res = IGE_FFM_Missions::viser( $id, ige_ffm_post( 'decision' ), ige_ffm_post( 'commentaire' ) );
		}
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Visa non enregistré', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'refuse' === ige_ffm_post( 'decision' ) ? 'Refus enregistré' : 'Visa enregistré', $res['complet'] ? 'Tous les visas sont réunis.' : ( $res['suivante'] ? 'Visa suivant : ' . self::etapes()[ $res['suivante'] ] . ' (prévenu par e-mail).' : '' ) );
		}
		$retour = ige_ffm_post( 'retour' );
		wp_safe_redirect( $retour && 0 === strpos( $retour, home_url() ) ? $retour : ige_ffm_url( 'accueil-service' ) );
		exit;
	}

	/**
	 * Dossiers qui attendent le visa de l'utilisateur.
	 *
	 * @param int $user_id Utilisateur.
	 * @return array[] Lignes : objet, id, ref, service_id, montant, libelle, url.
	 */
	public static function a_viser( $user_id ) {
		$out = array();
		$ex  = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return $out;
		}
		foreach ( IGE_FFM_Fonctions::active( 'demandes_fonds' ) ? IGE_FFM_Demandes::en_circuit( (int) $ex['id'] ) : array() as $d ) {
			$e = self::etat( 'demande', (int) $d['id'], (int) $d['montant'] );
			if ( $e['suivante'] && self::peut( $user_id, $e['suivante'], (int) $d['service_id'] ) ) {
				$out[] = array( 'objet' => 'demande', 'id' => (int) $d['id'], 'ref' => $d['reference'], 'service_id' => (int) $d['service_id'], 'montant' => (int) $d['montant'], 'libelle' => $d['objet'], 'etape' => $e['suivante'] );
			}
		}
		foreach ( IGE_FFM_Fonctions::active( 'chaine' ) ? IGE_FFM_Chaine::en_ordonnancement( (int) $ex['id'] ) : array() as $x ) {
			$e = self::etat( 'ordonnancement', (int) $x['id'], (int) $x['montant'] );
			if ( $e['suivante'] && self::peut( $user_id, $e['suivante'], (int) $x['service_id'] ) ) {
				$out[] = array( 'objet' => 'ordonnancement', 'id' => (int) $x['id'], 'ref' => $x['id_ecriture'], 'service_id' => (int) $x['service_id'], 'montant' => (int) $x['montant'], 'libelle' => $x['description'], 'etape' => $e['suivante'] );
			}
		}
		foreach ( IGE_FFM_Fonctions::active( 'missions' ) ? IGE_FFM_Missions::en_circuit( (int) $ex['id'] ) : array() as $m ) {
			$e = self::etat( 'mission', (int) $m['id'], (int) $m['montant'] );
			if ( $e['suivante'] && self::peut( $user_id, $e['suivante'], (int) $m['service_id'] ) ) {
				$out[] = array( 'objet' => 'mission', 'id' => (int) $m['id'], 'ref' => $m['numero'], 'service_id' => (int) $m['service_id'], 'montant' => (int) $m['montant'], 'libelle' => $m['objet'] . ' · ' . $m['destination'], 'etape' => $e['suivante'] );
			}
		}
		return $out;
	}
}
