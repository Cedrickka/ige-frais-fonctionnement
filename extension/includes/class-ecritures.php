<?php
/**
 * Écritures de caisse : saisie avec contrôles bloquants, paiement d'un engagement,
 * contrepassation, consultation du livre de caisse.
 *
 * Principes :
 * - aucune écriture n'est modifiée ni supprimée ; seule exception, le paiement d'un engagement
 *   (passage du statut « Engagée » à « Payée »), tracé au journal ;
 * - une erreur se corrige par contrepassation : une écriture de même nature, en montant négatif,
 *   liée à l'écriture d'origine (les soldes de caisse et de budget sont ainsi rétablis) ;
 * - tous les contrôles sont faits ici, côté serveur, quel que soit l'écran utilisé.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écritures.
 */
final class IGE_FFM_Ecritures {

	/**
	 * Une écriture.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Écriture qui contrepasse une écriture donnée (ou null).
	 *
	 * @param int $id Écriture d'origine.
	 * @return array|null
	 */
	public static function contrepassation_de( $id ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE ecriture_origine_id = %d LIMIT 1", $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Le mois de cette date est-il clôturé (arrêté de caisse signé) pour le service ?
	 *
	 * @param int    $exo  Exercice.
	 * @param int    $sid  Service.
	 * @param string $date Date AAAA-MM-JJ.
	 * @return bool
	 */
	public static function mois_clos( $exo, $sid, $date ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'arrete' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d AND service_id = %d AND mois >= %d AND type = 'mensuel' AND statut = 'cloture'", $exo, $sid, (int) substr( $date, 5, 2 ) ) );
	}

	/**
	 * Le mode de paiement est-il « Espèces » ?
	 *
	 * @param int $mode_id Mode.
	 * @return bool
	 */
	private static function est_especes( $mode_id ) {
		$m = IGE_FFM_Referentiels::get( 'mode_paiement', $mode_id );
		return $m && 'especes' === sanitize_title( remove_accents( $m['libelle'] ) );
	}

	/**
	 * Contrôle de la date d'opération.
	 *
	 * @param array  $ex   Exercice.
	 * @param int    $sid  Service.
	 * @param string $date Date.
	 * @return true|WP_Error
	 */
	private static function controler_date( array $ex, $sid, $date ) {
		if ( ! IGE_FFM_Contexte::date_valide( $date ) ) {
			return new WP_Error( 'date', 'Date invalide.' );
		}
		if ( $date < $ex['date_debut'] || $date > $ex['date_fin'] ) {
			return new WP_Error( 'date', sprintf( 'La date doit appartenir à l’exercice %d.', $ex['annee'] ) );
		}
		if ( $date > IGE_FFM_Contexte::aujourdhui() ) {
			return new WP_Error( 'date', 'Une date future est refusée.' );
		}
		if ( self::mois_clos( (int) $ex['id'], $sid, $date ) ) {
			return new WP_Error( 'date', 'Ce mois est clôturé (arrêté de caisse signé) : aucune écriture ne peut y être ajoutée.' );
		}
		return true;
	}

	/**
	 * Contrôles de trésorerie et de budget pour une sortie.
	 *
	 * @param array $ex      Exercice.
	 * @param int   $sid     Service.
	 * @param int   $rid     Rubrique.
	 * @param int   $montant Montant.
	 * @param bool  $payee   La sortie est payée (sinon engagée).
	 * @param int   $mode_id Mode de paiement.
	 * @param bool  $deja_engagee La somme est déjà comptée dans le consommé (paiement d'un engagement).
	 * @return true|WP_Error
	 */
	private static function controler_sortie( array $ex, $sid, $rid, $montant, $payee, $mode_id, $deja_engagee = false ) {
		$exo = (int) $ex['id'];
		if ( $payee && self::est_especes( $mode_id ) ) {
			$plafond = (int) IGE_FFM_Reglages::get( 'plafond_especes' );
			if ( $plafond > 0 && $montant > $plafond ) {
				return new WP_Error( 'plafond', sprintf( 'Paiement en espèces limité à %s : utilisez un chèque ou un virement.', ige_ffm_montant( $plafond ) ) );
			}
		}
		if ( $payee && IGE_FFM_Reglages::get( 'bloquer_caisse_negative' ) ) {
			$caisse = IGE_FFM_Budget::caisse( $exo, $sid );
			if ( $caisse - $montant < 0 ) {
				return new WP_Error( 'caisse', sprintf( 'Caisse insuffisante : solde %s, sortie %s. Enregistrez d’abord l’approvisionnement.', ige_ffm_montant( $caisse ), ige_ffm_montant( $montant ) ) );
			}
		}
		if ( ! $deja_engagee ) {
			$dispo = IGE_FFM_Budget::disponible( $exo, $sid, $rid );
			if ( null === $dispo && (int) $ex['budget_obligatoire'] ) {
				return new WP_Error( 'budget', 'Aucun budget n’est défini pour cette rubrique : le Responsable doit d’abord le saisir.' );
			}
			if ( null !== $dispo && IGE_FFM_Reglages::get( 'bloquer_depassement' ) && $dispo - $montant < 0 ) {
				return new WP_Error( 'disponible', sprintf( 'Dépassement du budget : disponible %s sur cette rubrique, sortie %s. Une révision budgétaire est nécessaire.', ige_ffm_montant( max( 0, $dispo ) ), ige_ffm_montant( $montant ) ) );
			}
		}
		return true;
	}

	/**
	 * Saisit une écriture.
	 *
	 * @param array      $data    Données : type, statut, date_operation, rubrique_id, montant, description,
	 *                            fournisseur_id, beneficiaire, num_piece, mode_paiement_id, observations.
	 * @param array|null $file    Pièce justificative ($_FILES['piece']).
	 * @param int|null   $user_id Auteur (utilisateur courant par défaut).
	 * @return int|WP_Error Identifiant de l'écriture.
	 */
	public static function saisir( array $data, $file = null, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = IGE_FFM_Contexte::service_utilisateur( $user_id );
		if ( ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Votre compte ne permet pas de saisir des écritures.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}

		$type   = 'entree' === ( $data['type'] ?? '' ) ? 'entree' : 'sortie';
		$statut = 'entree' === $type ? 'payee' : ( 'engagee' === ( $data['statut'] ?? '' ) ? 'engagee' : 'payee' );
		$date   = (string) ( $data['date_operation'] ?? '' );
		$rid    = (int) ( $data['rubrique_id'] ?? 0 );
		$mode   = (int) ( $data['mode_paiement_id'] ?? 0 );
		$mnt    = ige_ffm_parse_montant( $data['montant'] ?? '' );
		$desc   = trim( sanitize_text_field( (string) ( $data['description'] ?? '' ) ) );
		$piece  = trim( sanitize_text_field( (string) ( $data['num_piece'] ?? '' ) ) );
		$fid    = (int) ( $data['fournisseur_id'] ?? 0 );
		$benef  = trim( sanitize_text_field( (string) ( $data['beneficiaire'] ?? '' ) ) );
		$obs    = trim( sanitize_textarea_field( (string) ( $data['observations'] ?? '' ) ) );

		if ( 'entree' === $type && ! IGE_FFM_Reglages::get( 'entrees_manuelles' ) ) {
			return new WP_Error( 'type', 'Les approvisionnements sont enregistrés par Administration et Finance (avances de fonds).' );
		}
		$ok = self::controler_date( $ex, $sid, $date );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( null === $mnt || $mnt <= 0 ) {
			return new WP_Error( 'montant', 'Le montant doit être un nombre entier supérieur à 0 (en CDF).' );
		}
		if ( '' === $desc ) {
			return new WP_Error( 'description', 'La description est obligatoire.' );
		}
		$m = IGE_FFM_Referentiels::get( 'mode_paiement', $mode );
		if ( ! $m || ! (int) $m['actif'] ) {
			return new WP_Error( 'mode', 'Choisissez le mode de paiement.' );
		}
		if ( 'sortie' === $type ) {
			$r = IGE_FFM_Referentiels::get( 'rubrique', $rid );
			if ( ! $r || ! (int) $r['actif'] ) {
				return new WP_Error( 'rubrique', 'Choisissez une rubrique active.' );
			}
			if ( IGE_FFM_Reglages::get( 'piece_obligatoire' ) && '' === $piece ) {
				return new WP_Error( 'num_piece', 'Le numéro de la pièce justificative est obligatoire pour une sortie.' );
			}
			if ( $fid && ! IGE_FFM_Fournisseurs::get( $fid ) ) {
				return new WP_Error( 'fournisseur', 'Fournisseur introuvable.' );
			}
			if ( ! $fid && '' === $benef ) {
				return new WP_Error( 'fournisseur', 'Indiquez le fournisseur ou le bénéficiaire.' );
			}
			$ok = self::controler_sortie( $ex, $sid, $rid, $mnt, 'payee' === $statut, $mode );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		} else {
			$rid = 0;
			$fid = 0;
		}
		$need_file = 'sortie' === $type && IGE_FFM_Reglages::get( 'piece_obligatoire' );
		$has_file  = $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];
		if ( $need_file || $has_file ) {
			$ok = IGE_FFM_Pieces::valider( $file );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( $sid ) ) ?: 'S' . $sid;
		$t    = IGE_FFM_DB::t( 'ecriture' );
		$row  = array(
			'exercice_id'      => (int) $ex['id'],
			'service_id'       => $sid,
			'date_operation'   => $date,
			'mois'             => (int) substr( $date, 5, 2 ),
			'type'             => $type,
			'statut'           => $statut,
			'rubrique_id'      => $rid ?: null,
			'description'      => mb_substr( $desc, 0, 255 ),
			'fournisseur_id'   => $fid ?: null,
			'beneficiaire'     => mb_substr( $benef, 0, 190 ),
			'num_piece'        => mb_substr( $piece, 0, 60 ),
			'mode_paiement_id' => $mode,
			'montant'          => $mnt,
			'observations'     => $obs,
			'date_paiement'    => 'payee' === $statut ? $date : null,
			'etat_transmission' => 'non_transmise',
			'saisi_par'        => (int) $user_id,
			'saisi_le'         => ige_ffm_now(),
		);
		$id = self::inserer_numerotee( $row, $code, (int) $ex['annee'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( $has_file ) {
			$pid = IGE_FFM_Pieces::enregistrer( $file, 'ecriture', $id, $user_id, (string) ( $data['piece_nature'] ?? '' ) );
			if ( is_wp_error( $pid ) ) {
				$wpdb->delete( $t, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $pid;
			}
		}
		$e = self::get( $id );
		IGE_FFM_Audit::log(
			'saisie_ecriture',
			'ecriture',
			$e['id_ecriture'],
			null,
			array(
				'type'     => $type,
				'statut'   => $statut,
				'date'     => $date,
				'rubrique' => $rid ? IGE_FFM_Referentiels::get( 'rubrique', $rid )['code'] : '',
				'montant'  => $mnt,
			),
			$user_id
		);
		do_action( 'ige_ffm_ecriture_enregistree', $id, $user_id );
		return $id;
	}

	/**
	 * Insère une écriture avec le numéro suivant du service (sans trou ; réessaie en cas de collision).
	 *
	 * @param array  $row   Ligne.
	 * @param string $code  Code du service sans espaces.
	 * @param int    $annee Année.
	 * @return int|WP_Error
	 */
	private static function inserer_numerotee( array $row, $code, $annee ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		for ( $essai = 0; $essai < 5; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$num                = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(numero) FROM $t WHERE service_id = %d AND exercice_id = %d", $row['service_id'], $row['exercice_id'] ) );
			$row['numero']      = $num;
			$row['id_ecriture'] = sprintf( '%s-%d-%06d', $code, $annee, $num );
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->suppress_errors( false );
			if ( $ok ) {
				return (int) $wpdb->insert_id;
			}
		}
		return new WP_Error( 'numero', 'Enregistrement impossible pour le moment (numérotation). Réessayez.' );
	}

	/**
	 * Paie un engagement.
	 *
	 * @param int      $id      Écriture engagée.
	 * @param string   $date    Date du paiement.
	 * @param int      $mode_id Mode de paiement.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function payer( $id, $date, $mode_id, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$e       = self::get( $id );
		if ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Écriture introuvable dans votre service.' );
		}
		if ( 'sortie' !== $e['type'] || 'engagee' !== $e['statut'] || $e['ecriture_origine_id'] || self::contrepassation_de( $id ) ) {
			return new WP_Error( 'etat', 'Seul un engagement en cours (non contrepassé) peut être payé.' );
		}
		if ( 'rejetee' === $e['etat_verification'] && 'transmise' === $e['etat_transmission'] && IGE_FFM_Reglages::get( 'contrepassation_rejet' ) ) {
			return new WP_Error( 'rejet', 'Cet engagement a été rejeté par le central : contrepassez-le puis saisissez l’engagement correct.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex || (int) $ex['id'] !== (int) $e['exercice_id'] ) {
			return new WP_Error( 'exercice', 'L’exercice de cet engagement est clos.' );
		}
		$ok = self::controler_date( $ex, (int) $e['service_id'], (string) $date );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( $date < $e['date_operation'] ) {
			return new WP_Error( 'date', 'Le paiement ne peut pas précéder l’engagement.' );
		}
		$m = IGE_FFM_Referentiels::get( 'mode_paiement', (int) $mode_id );
		if ( ! $m || ! (int) $m['actif'] ) {
			return new WP_Error( 'mode', 'Choisissez le mode de paiement.' );
		}
		$ok = self::controler_sortie( $ex, (int) $e['service_id'], (int) $e['rubrique_id'], (int) $e['montant'], true, (int) $mode_id, true );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$upd = array(
			'statut'           => 'payee',
			'date_paiement'    => $date,
			'mode_paiement_id' => (int) $mode_id,
		);
		if ( 'transmise' === $e['etat_transmission'] ) {
			$upd['etat_transmission'] = 'a_retransmettre';
		}
		$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), $upd, array( 'id' => $id, 'statut' => 'engagee' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'paiement_engagement', 'ecriture', $e['id_ecriture'], array( 'statut' => 'engagee' ), array( 'statut' => 'payee', 'date_paiement' => $date, 'mode' => $m['libelle'] ), $user_id );
		do_action( 'ige_ffm_ecriture_enregistree', (int) $id, $user_id );
		return true;
	}

	/**
	 * Contrepasse une écriture (annulation par écriture inverse, en montant négatif).
	 *
	 * @param int      $id      Écriture d'origine.
	 * @param string   $motif   Motif (obligatoire).
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Identifiant de la contrepassation.
	 */
	public static function contrepasser( $id, $motif, $user_id = null, $central = false ) {
		$user_id = $user_id ?? get_current_user_id();
		$e       = self::get( $id );
		if ( $central ) {
			// Annulation d'une avance par le central : seule l'écriture d'entrée liée à l'avance est concernée.
			if ( ! $e || ! $e['avance_id'] || ! user_can( $user_id, 'ige_gerer_fonds' ) ) {
				return new WP_Error( 'droits', 'Seule Administration et Finance peut annuler une avance.' );
			}
		} elseif ( ! $e || ! IGE_FFM_Contexte::peut_saisir( (int) $e['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Écriture introuvable dans votre service.' );
		} elseif ( $e['avance_id'] ) {
			return new WP_Error( 'avance', 'Cette entrée provient d’une avance de fonds : seule Administration et Finance peut l’annuler.' );
		}
		if ( $e['ecriture_origine_id'] ) {
			return new WP_Error( 'etat', 'Une contrepassation ne peut pas elle-même être contrepassée.' );
		}
		if ( self::contrepassation_de( $id ) ) {
			return new WP_Error( 'etat', 'Cette écriture a déjà été contrepassée.' );
		}
		$motif = trim( sanitize_text_field( (string) $motif ) );
		if ( mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif de la contrepassation.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex || (int) $ex['id'] !== (int) $e['exercice_id'] ) {
			return new WP_Error( 'exercice', 'L’exercice de cette écriture est clos.' );
		}
		$today = IGE_FFM_Contexte::aujourdhui();
		$date  = $today > $ex['date_fin'] ? $ex['date_fin'] : $today;
		if ( self::mois_clos( (int) $ex['id'], (int) $e['service_id'], $date ) ) {
			return new WP_Error( 'date', 'Le mois en cours est clôturé.' );
		}
		// Annuler une entrée retire de l'argent de la caisse : la caisse doit le permettre.
		if ( 'entree' === $e['type'] && IGE_FFM_Reglages::get( 'bloquer_caisse_negative' ) ) {
			$caisse = IGE_FFM_Budget::caisse( (int) $ex['id'], (int) $e['service_id'] );
			if ( $caisse - (int) $e['montant'] < 0 ) {
				return new WP_Error( 'caisse', sprintf( 'Contrepassation impossible : elle rendrait la caisse négative (solde %s).', ige_ffm_montant( $caisse ) ) );
			}
		}
		$row = array(
			'exercice_id'         => (int) $e['exercice_id'],
			'service_id'          => (int) $e['service_id'],
			'date_operation'      => $date,
			'mois'                => (int) substr( $date, 5, 2 ),
			'type'                => $e['type'],
			'statut'              => $e['statut'],
			'rubrique_id'         => $e['rubrique_id'],
			'description'         => mb_substr( 'Contrepassation de ' . $e['id_ecriture'] . ' : ' . $motif, 0, 255 ),
			'fournisseur_id'      => $e['fournisseur_id'],
			'beneficiaire'        => $e['beneficiaire'],
			'num_piece'           => $e['num_piece'],
			'mode_paiement_id'    => $e['mode_paiement_id'],
			'montant'             => -1 * (int) $e['montant'],
			'observations'        => $motif,
			'date_paiement'       => 'payee' === $e['statut'] ? $date : null,
			'ecriture_origine_id' => (int) $e['id'],
			'avance_id'           => $e['avance_id'] ?: null,
			'etat_transmission'   => 'non_transmise',
			'saisi_par'           => (int) $user_id,
			'saisi_le'            => ige_ffm_now(),
		);
		if ( $central ) {
			$row = array_merge( $row, self::etat_central( $user_id ) );
		}
		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( (int) $e['service_id'] ) );
		$new  = self::inserer_numerotee( $row, $code, (int) $ex['annee'] );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$c = self::get( $new );
		IGE_FFM_Audit::log( 'contrepassation', 'ecriture', $e['id_ecriture'], null, array( 'contrepassation' => $c['id_ecriture'], 'montant' => (int) $c['montant'], 'motif' => $motif ), $user_id );
		if ( ! $central ) {
			do_action( 'ige_ffm_ecriture_enregistree', $new, $user_id );
		}
		return $new;
	}

	/**
	 * Champs d'une écriture créée par le central (avance) : déjà transmise et validée.
	 *
	 * @param int $user_id Auteur.
	 * @return array
	 */
	private static function etat_central( $user_id ) {
		return array(
			'etat_transmission' => 'transmise',
			'etat_verification' => 'validee',
			'anomalies'         => '[]',
			'nb_transmissions'  => 1,
			'valide_par'        => (int) $user_id,
			'valide_le'         => ige_ffm_now(),
		);
	}

	/**
	 * Insère l'écriture d'entrée d'une avance de fonds (créée par le central, déjà validée).
	 *
	 * @param array $row     Ligne (exercice_id, service_id, date_operation, montant, description, num_piece, mode_paiement_id, avance_id).
	 * @param int   $annee   Année de l'exercice.
	 * @param int   $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function inserer_avance( array $row, $annee, $user_id ) {
		$row  = array_merge(
			array(
				'mois'          => (int) substr( $row['date_operation'], 5, 2 ),
				'type'          => 'entree',
				'statut'        => 'payee',
				'beneficiaire'  => 'Administration et Finance (avance de fonds)',
				'date_paiement' => $row['date_operation'],
				'saisi_par'     => (int) $user_id,
				'saisi_le'      => ige_ffm_now(),
			),
			$row,
			self::etat_central( $user_id )
		);
		$code = preg_replace( '/[^A-Za-z0-9]/', '', IGE_FFM_Comptes::service_code( (int) $row['service_id'] ) ) ?: 'S' . (int) $row['service_id'];
		return self::inserer_numerotee( $row, $code, (int) $annee );
	}

	/**
	 * Livre de caisse d'un service, avec solde progressif.
	 *
	 * @param int   $exo Exercice.
	 * @param int   $sid Service.
	 * @param array $f   Filtres : mois, rubrique_id, statut, etat.
	 * @return array{rows:array, solde_debut:int}
	 */
	public static function livre( $exo, $sid, array $f = array() ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d AND service_id = %d ORDER BY date_operation ASC, id ASC", $exo, $sid ), ARRAY_A );
		$solde = IGE_FFM_Budget::solde_initial( $exo, $sid );
		$contre = array();
		foreach ( $all as $r ) {
			if ( $r['ecriture_origine_id'] ) {
				$contre[ (int) $r['ecriture_origine_id'] ] = $r['id_ecriture'];
			}
		}
		$rows        = array();
		$solde_debut = null;
		foreach ( $all as $r ) {
			$mvt = 0;
			if ( 'entree' === $r['type'] ) {
				$mvt = (int) $r['montant'];
			} elseif ( 'payee' === $r['statut'] ) {
				$mvt = -1 * (int) $r['montant'];
			}
			$keep = ( empty( $f['mois'] ) || (int) $r['mois'] === (int) $f['mois'] )
				&& ( empty( $f['rubrique_id'] ) || (int) $r['rubrique_id'] === (int) $f['rubrique_id'] )
				&& ( empty( $f['statut'] ) || $r['statut'] === $f['statut'] )
				&& ( empty( $f['etat'] ) || self::etat_code( $r ) === $f['etat'] );
			if ( null === $solde_debut && ( empty( $f['mois'] ) || (int) $r['mois'] >= (int) $f['mois'] ) ) {
				$solde_debut = $solde;
			}
			$solde += $mvt;
			if ( $keep ) {
				$r['solde']           = $solde;
				$r['contrepassee_par'] = $contre[ (int) $r['id'] ] ?? '';
				$rows[]               = $r;
			}
		}
		return array(
			'rows'        => $rows,
			'solde_debut' => null === $solde_debut ? $solde : $solde_debut,
		);
	}

	/**
	 * Dernières écritures d'un service.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @param int $n   Nombre.
	 * @return array[]
	 */
	public static function dernieres( $exo, $sid, $n = 5 ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE exercice_id = %d AND service_id = %d ORDER BY id DESC LIMIT %d", $exo, $sid, $n ), ARRAY_A );
	}

	/**
	 * Comptes par état de transmission.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service.
	 * @return array<string,int>
	 */
	public static function compter_etats( $exo, $sid ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT etat_transmission, COUNT(*) AS n FROM $t WHERE exercice_id = %d AND service_id = %d GROUP BY etat_transmission", $exo, $sid ), ARRAY_A );
		return array_map( 'intval', wp_list_pluck( $rows, 'n', 'etat_transmission' ) );
	}

	/**
	 * Libellé et couleur de l'état d'une écriture (transmission puis vérification).
	 *
	 * @param array $e Écriture.
	 * @return array{0:string,1:string}
	 */
	public static function etat( array $e ) {
		$l = array(
			'non_transmise'   => array( 'Non transmise', 'neutre' ),
			'a_retransmettre' => array( 'À retransmettre', 'info' ),
			'a_verifier'      => array( 'À vérifier', 'wait' ),
			'validee'         => array( 'Validée', 'ok' ),
			'rejetee'         => array( 'Rejetée', 'ko' ),
		);
		return $l[ self::etat_code( $e ) ];
	}

	/**
	 * Code de l'état affiché : non_transmise, a_retransmettre, a_verifier, validee ou rejetee.
	 *
	 * @param array $e Écriture.
	 * @return string
	 */
	public static function etat_code( array $e ) {
		if ( 'non_transmise' === $e['etat_transmission'] || '' === (string) $e['etat_transmission'] ) {
			return 'non_transmise';
		}
		if ( 'a_retransmettre' === $e['etat_transmission'] ) {
			return 'a_retransmettre';
		}
		if ( in_array( $e['etat_verification'], array( 'validee', 'rejetee' ), true ) ) {
			return $e['etat_verification'];
		}
		return 'a_verifier';
	}
}
