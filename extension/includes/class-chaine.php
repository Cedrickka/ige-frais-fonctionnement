<?php
/**
 * Chaîne de la dépense (VA-10) : engagement, liquidation (service fait, facture reçue),
 * ordonnancement (visas du circuit), paiement.
 *
 * S'applique aux engagements dont le montant atteint le seuil « chaine_seuil » ; en dessous,
 * l'engagement se paie directement (chaîne simplifiée à deux étapes). La liquidation reste
 * possible pour tout engagement.
 *
 * Colonne « etape » de l'écriture : '' (engagée) → 'liquidee' (ordonnancement en cours)
 * → 'ordonnancee' (payable) ; 'refusee' si l'ordonnancement est refusé.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Chaîne de la dépense.
 */
final class IGE_FFM_Chaine {

	/**
	 * La chaîne complète s'applique-t-elle à cette écriture ?
	 *
	 * @param array $e Écriture.
	 * @return bool
	 */
	public static function requise( array $e ) {
		$seuil = IGE_FFM_Fonctions::active( 'chaine' ) ? (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) : 0;
		return $seuil > 0 && 'sortie' === ( $e['type'] ?? '' ) && empty( $e['ecriture_origine_id'] ) && empty( $e['mission_id'] ) && (int) ( $e['montant'] ?? 0 ) >= $seuil;
	}

	/**
	 * L'engagement peut-il être payé au regard de la chaîne ?
	 *
	 * @param array $e Écriture.
	 * @return true|WP_Error
	 */
	public static function payable( array $e ) {
		if ( ! self::requise( $e ) || 'ordonnancee' === $e['etape'] ) {
			return true;
		}
		if ( 'refusee' === $e['etape'] ) {
			return new WP_Error( 'chaine', 'L’ordonnancement de cette dépense a été refusé : elle ne peut pas être payée. Contrepassez l’engagement.' );
		}
		return new WP_Error( 'chaine', sprintf( 'Dépense de %s ou plus : constatez le service fait (liquidation) puis obtenez l’ordonnancement avant de payer.', ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) ) ) );
	}

	/**
	 * Liquidation : service fait constaté, facture reçue.
	 *
	 * @param int      $id      Écriture engagée.
	 * @param string   $date    Date du service fait.
	 * @param string   $facture Référence de la facture.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function liquider( $id, $date, $facture, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = IGE_FFM_Ecritures::get( (int) $id );
		if ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Écriture introuvable dans votre service.' );
		}
		if ( 'sortie' !== $e['type'] || 'engagee' !== $e['statut'] || $e['ecriture_origine_id'] || IGE_FFM_Ecritures::contrepassation_de( (int) $id ) ) {
			return new WP_Error( 'etat', 'Seul un engagement en cours peut être liquidé.' );
		}
		if ( '' !== $e['etape'] ) {
			return new WP_Error( 'etat', 'Cet engagement est déjà liquidé.' );
		}
		if ( ! IGE_FFM_Contexte::date_valide( $date ) || $date < $e['date_operation'] || $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'La date du service fait doit être comprise entre l’engagement et aujourd’hui.' );
		}
		$facture = mb_substr( trim( sanitize_text_field( (string) $facture ) ), 0, 60 );
		if ( '' === $facture ) {
			return new WP_Error( 'facture', 'Indiquez la référence de la facture ou du document de service fait.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etape' => 'liquidee', 'liquidee_le' => $date, 'liquidee_par' => (int) $user_id, 'facture_ref' => $facture ), array( 'id' => (int) $id, 'etape' => '' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'liquidation', 'ecriture', $e['id_ecriture'], null, array( 'service_fait' => $date, 'facture' => $facture ), $user_id );
		$etat = IGE_FFM_Visas::etat( 'ordonnancement', (int) $id, (int) $e['montant'] );
		if ( $etat['suivante'] ) {
			IGE_FFM_Visas::notifier( 'ordonnancement', $e['id_ecriture'], $etat['suivante'], (int) $e['service_id'], (int) $e['montant'] );
		}
		return true;
	}

	/**
	 * Visa d'ordonnancement.
	 *
	 * @param int      $id          Écriture liquidée.
	 * @param string   $decision    vise | refuse.
	 * @param string   $commentaire Commentaire.
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error
	 */
	public static function viser( $id, $decision, $commentaire, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = IGE_FFM_Ecritures::get( (int) $id );
		if ( ! $e || 'liquidee' !== $e['etape'] || 'engagee' !== $e['statut'] ) {
			return new WP_Error( 'etat', 'Cette dépense n’attend pas d’ordonnancement.' );
		}
		$r = IGE_FFM_Visas::viser( 'ordonnancement', (int) $id, (int) $e['service_id'], (int) $e['montant'], $decision, $commentaire, $e['id_ecriture'], $user_id );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['refuse'] ) {
			$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etape' => 'refusee' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( $r['complet'] ) {
			$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etape' => 'ordonnancee', 'ordonnancee_le' => ige_ffm_now() ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			IGE_FFM_Audit::log( 'ordonnancement', 'ecriture', $e['id_ecriture'], null, array( 'montant' => (int) $e['montant'] ), $user_id );
		}
		return $r;
	}

	/**
	 * Dépenses liquidées en attente d'ordonnancement.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = tous).
	 * @return array[]
	 */
	public static function en_ordonnancement( $exo, $sid = 0 ) {
		global $wpdb;
		$w = $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE exercice_id = %d AND type = 'sortie' AND statut = 'engagee' AND etape = 'liquidee' $w ORDER BY liquidee_le, id", $exo ), ARRAY_A );
	}

	/**
	 * Dossiers de dépense : engagements en cours et dépenses récentes passées par la chaîne.
	 *
	 * @param int    $exo Exercice.
	 * @param int    $sid Service (0 = tous).
	 * @param string $vue en_cours | payees.
	 * @return array[]
	 */
	public static function dossiers( $exo, $sid = 0, $vue = 'en_cours' ) {
		global $wpdb;
		$t     = IGE_FFM_DB::t( 'ecriture' );
		$w     = $sid ? $wpdb->prepare( ' AND e.service_id = %d', $sid ) : '';
		$extra = 'payees' === $vue ? " AND e.statut = 'payee' AND e.etape = 'ordonnancee'" : " AND e.statut = 'engagee'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT e.* FROM $t e WHERE e.exercice_id = %d AND e.type = 'sortie' AND e.ecriture_origine_id IS NULL AND NOT EXISTS (SELECT 1 FROM $t c WHERE c.ecriture_origine_id = e.id) $extra $w ORDER BY e.date_operation DESC, e.id DESC LIMIT 300", $exo ), ARRAY_A );
		return $rows;
	}

	/**
	 * Étapes d'un dossier (pour la frise).
	 *
	 * @param array $e Écriture.
	 * @return array[] [ libellé, fait, détail ].
	 */
	public static function etapes( array $e ) {
		$req   = self::requise( $e );
		$visas = IGE_FFM_Visas::etat( 'ordonnancement', (int) $e['id'], (int) $e['montant'] );
		$nv    = count( array_filter( $visas['visas'], static fn( $v ) => 'vise' === $v['decision'] ) );
		$out   = array(
			array( 'Engagement', true, wp_date( 'd/m/Y', strtotime( $e['date_operation'] ) ) . ( $e['num_piece'] ? ' · ' . $e['num_piece'] : '' ) ),
			array( 'Liquidation', '' !== $e['etape'], $e['liquidee_le'] ? wp_date( 'd/m/Y', strtotime( $e['liquidee_le'] ) ) . ' · service fait, facture ' . $e['facture_ref'] : ( $req ? 'à faire' : 'facultative' ) ),
			array( 'Ordonnancement', 'ordonnancee' === $e['etape'], 'refusee' === $e['etape'] ? 'refusé' : ( 'ordonnancee' === $e['etape'] ? 'ordonnancé le ' . wp_date( 'd/m/Y', strtotime( $e['ordonnancee_le'] ) ) : ( $req ? $nv . ' visa(s) sur ' . count( $visas['circuit'] ) : 'non requis' ) ) ),
			array( 'Paiement', 'payee' === $e['statut'], 'payee' === $e['statut'] ? wp_date( 'd/m/Y', strtotime( $e['date_paiement'] ) ) : ( true === self::payable( $e ) ? 'possible' : 'après ordonnancement' ) ),
		);
		return $out;
	}

	/**
	 * Frise des étapes (HTML).
	 *
	 * @param array $e Écriture.
	 * @return string
	 */
	public static function frise( array $e ) {
		$h = '<ol class="ige-chaine">';
		foreach ( self::etapes( $e ) as $i => $x ) {
			$h .= '<li class="' . ( $x[1] ? 'ige-fait' : '' ) . '"><span class="ige-n">' . ( $i + 1 ) . '</span><b>' . esc_html( $x[0] ) . '</b><span>' . esc_html( $x[2] ) . '</span></li>';
		}
		return $h . '</ol>';
	}

	/**
	 * Bloc « chaîne de la dépense » pour le détail d'une écriture (livre, dossier).
	 *
	 * @param array  $e      Écriture.
	 * @param string $retour URL de retour.
	 * @return string
	 */
	public static function bloc( array $e, $retour ) {
		if ( 'sortie' !== $e['type'] || $e['ecriture_origine_id'] || ( ! self::requise( $e ) && '' === $e['etape'] && 'engagee' !== $e['statut'] ) ) {
			return '';
		}
		$h    = '<div class="ige-chaine-bloc"><h3 style="margin:0 0 8px;font-size:14px">Chaîne de la dépense' . ( self::requise( $e ) ? '' : ' <span class="ige-small ige-muted">(simplifiée : sous le seuil de ' . esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) ) ) . ')</span>' ) . '</h3>' . self::frise( $e );
		$peut = IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'] );
		if ( 'engagee' === $e['statut'] && '' === $e['etape'] && $peut && ! IGE_FFM_Ecritures::contrepassation_de( (int) $e['id'] ) ) {
			$h .= IGE_FFM_Ecrans::form_open( 'liquider', 'class="ige-row ige-no-print" style="flex-wrap:wrap;align-items:flex-end;margin-top:8px"' )
				. '<input type="hidden" name="ecriture" value="' . esc_attr( $e['id'] ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
				. '<div style="min-width:150px">' . IGE_FFM_Ecrans::field( 'date_service_fait', 'Service fait le', IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( $e['date_operation'] ) . '" max="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '"' ) ) . '</div>'
				. '<div style="flex:1;min-width:160px">' . IGE_FFM_Ecrans::field( 'facture_ref', 'Facture reçue n°', '', array( 'required' => true, 'attrs' => 'maxlength="60"' ) ) . '</div>'
				. '<button type="submit" class="ige-btn ige-btn-line" style="margin-bottom:16px">Constater le service fait</button></form>'
				. '<p class="ige-small ige-muted" style="margin:0">Joignez la facture et le bon ou procès-verbal de réception avec « Ajouter la pièce ».</p>';
		}
		if ( 'liquidee' === $e['etape'] || 'refusee' === $e['etape'] || 'ordonnancee' === $e['etape'] ) {
			$etat = IGE_FFM_Visas::etat( 'ordonnancement', (int) $e['id'], (int) $e['montant'] );
			$h   .= '<p class="ige-small" style="font-weight:600;margin:10px 0 4px">Visas d’ordonnancement</p>' . IGE_FFM_Visas::frise( $etat );
			if ( 'liquidee' === $e['etape'] ) {
				$h .= IGE_FFM_Visas::formulaire( 'ordonnancement', (int) $e['id'], $etat, (int) $e['service_id'], $retour );
			}
		}
		return $h . '</div>';
	}

	/**
	 * Formulaire de liquidation (admin-post).
	 */
	public static function handle_liquider() {
		ige_ffm_check_form( 'liquider' );
		ige_ffm_require( 'ige_saisir' );
		$id  = (int) ige_ffm_post( 'ecriture' );
		$res = self::liquider( $id, ige_ffm_post( 'date_service_fait' ), ige_ffm_post( 'facture_ref' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Liquidation refusée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Service fait constaté', 'L’ordonnancement est demandé ; les personnes qui doivent viser sont prévenues.' );
		}
		$retour = ige_ffm_post( 'retour' );
		wp_safe_redirect( $retour && 0 === strpos( $retour, home_url() ) ? $retour : ige_ffm_url( 'livre-de-caisse', array( 'ecriture' => $id ) ) );
		exit;
	}
}
