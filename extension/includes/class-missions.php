<?php
/**
 * Missions d'inspection (VA-40) : ordre de mission (inspecteurs, destination, dates), calcul des
 * frais selon un barème paramétrable, circuit de visas, ordre de mission officiel avec code QR,
 * paiement par la caisse du service (sortie liée à la mission, l'ordre de mission en pièce jointe),
 * rapport de mission au retour, liquidation (complément ou reversement).
 *
 * Statuts : soumise (visas) → approuvee → payee → liquidee ; refusee ; annulee.
 * Les étapes affichées « En mission » et « Rapport attendu » se déduisent des dates.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Missions.
 */
final class IGE_FFM_Missions {

	const OPTION_BAREME = 'ige_ffm_bareme';

	/**
	 * Barème par défaut (fictif, à remplacer par le barème officiel).
	 *
	 * @return array
	 */
	public static function bareme_defaut() {
		return array(
			'categories'   => array(
				'ig'         => array( 'libelle' => 'Inspecteur Général / Inspecteur Général adjoint', 'indemnite' => 200000, 'hebergement' => 0 ),
				'inspecteur' => array( 'libelle' => 'Inspecteur', 'indemnite' => 150000, 'hebergement' => 0 ),
				'appui'      => array( 'libelle' => 'Agent d’appui, chauffeur', 'indemnite' => 100000, 'hebergement' => 0 ),
			),
			'transport'    => array( 'kinshasa' => 50000, 'province' => 350000 ),
			'frais_divers' => 300000,
			'officiel'     => false,
		);
	}

	/**
	 * Barème en vigueur.
	 *
	 * @return array
	 */
	public static function bareme() {
		$b = get_option( self::OPTION_BAREME, null );
		return is_array( $b ) ? $b + self::bareme_defaut() : self::bareme_defaut();
	}

	/**
	 * Enregistre le barème (Administration et Finance).
	 *
	 * @param array    $in      Saisies : indemnite[cat], hebergement[cat], transport[zone], frais_divers.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function enregistrer_bareme( array $in, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		if ( ! user_can( $user_id, 'ige_administrer' ) ) {
			return new WP_Error( 'droits', 'Barème réservé à Administration et Finance.' );
		}
		$avant = self::bareme();
		$b     = $avant;
		foreach ( array_keys( $b['categories'] ) as $c ) {
			foreach ( array( 'indemnite', 'hebergement' ) as $k ) {
				$v = ige_ffm_parse_montant( $in[ $k ][ $c ] ?? '' );
				if ( null === $v || $v < 0 ) {
					return new WP_Error( $k, 'Montants du barème : nombres entiers positifs en CDF.' );
				}
				$b['categories'][ $c ][ $k ] = $v;
			}
		}
		foreach ( array( 'kinshasa', 'province' ) as $z ) {
			$v = ige_ffm_parse_montant( $in['transport'][ $z ] ?? '' );
			if ( null === $v || $v < 0 ) {
				return new WP_Error( 'transport', 'Transport : nombres entiers positifs en CDF.' );
			}
			$b['transport'][ $z ] = $v;
		}
		$v = ige_ffm_parse_montant( $in['frais_divers'] ?? '' );
		if ( null === $v || $v < 0 ) {
			return new WP_Error( 'frais_divers', 'Frais divers : nombre entier positif en CDF.' );
		}
		$b['frais_divers'] = $v;
		$b['officiel']     = true;
		update_option( self::OPTION_BAREME, $b, false );
		IGE_FFM_Audit::log( 'bareme_missions', 'reglages', 'bareme', $avant, $b, $user_id );
		return true;
	}

	/**
	 * Statuts : code => [ libellé, ton ].
	 *
	 * @return array<string,array>
	 */
	public static function statuts() {
		return array(
			'soumise'   => array( 'Visas en cours', 'wait' ),
			'approuvee' => array( 'Approuvée, à payer', 'info' ),
			'payee'     => array( 'Payée', 'info' ),
			'liquidee'  => array( 'Liquidée', 'ok' ),
			'refusee'   => array( 'Refusée', 'ko' ),
			'annulee'   => array( 'Annulée', 'neutre' ),
		);
	}

	/**
	 * Étape affichée (tient compte des dates pour une mission payée).
	 *
	 * @param array $m Mission.
	 * @return array [ libellé, ton ].
	 */
	public static function etape( array $m ) {
		$auj = IGE_FFM_Contexte::aujourdhui();
		if ( 'payee' === $m['statut'] ) {
			if ( $auj < $m['date_depart'] ) {
				return array( 'Payée, départ le ' . wp_date( 'd/m', strtotime( $m['date_depart'] ) ), 'info' );
			}
			if ( $auj <= $m['date_retour'] ) {
				return array( 'En mission', 'info' );
			}
			return $m['rapport_piece_id'] ? array( 'Rapport reçu, à liquider', 'wait' ) : array( 'Rapport manquant', 'ko' );
		}
		return self::statuts()[ $m['statut'] ] ?? array( $m['statut'], 'neutre' );
	}

	/**
	 * Une mission.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mission' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Membres d'une mission.
	 *
	 * @param int $id Mission.
	 * @return array[]
	 */
	public static function membres( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mission_membre' ) . ' WHERE mission_id = %d ORDER BY id', $id ), ARRAY_A );
	}

	/**
	 * Liste des missions.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = toutes).
	 * @return array[]
	 */
	public static function liste( $exo, $sid = 0 ) {
		global $wpdb;
		$w = $sid ? $wpdb->prepare( ' AND service_id = %d', $sid ) : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mission' ) . " WHERE exercice_id = %d $w ORDER BY date_depart DESC, id DESC", $exo ), ARRAY_A );
	}

	/**
	 * Missions dont le circuit de visas est en cours.
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function en_circuit( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mission' ) . " WHERE exercice_id = %d AND statut = 'soumise' ORDER BY id", $exo ), ARRAY_A );
	}

	/**
	 * Calcule les frais d'une mission.
	 *
	 * @param array  $membres Membres : nom, fonction, categorie.
	 * @param string $zone    kinshasa | province.
	 * @param string $depart  Date de départ.
	 * @param string $retour  Date de retour.
	 * @return array{jours:int, nuitees:int, membres:array, transport:int, indemnites:int, hebergement:int, divers:int, total:int}|WP_Error
	 */
	public static function calculer( array $membres, $zone, $depart, $retour ) {
		$b = self::bareme();
		if ( ! IGE_FFM_Contexte::date_valide( $depart ) || ! IGE_FFM_Contexte::date_valide( $retour ) || $retour < $depart ) {
			return new WP_Error( 'dates', 'Dates de mission invalides (le retour ne peut précéder le départ).' );
		}
		$zone  = 'kinshasa' === $zone ? 'kinshasa' : 'province';
		$jours = (int) round( ( strtotime( $retour ) - strtotime( $depart ) ) / DAY_IN_SECONDS ) + 1;
		if ( $jours > 60 ) {
			return new WP_Error( 'dates', 'Une mission ne peut pas dépasser 60 jours.' );
		}
		$nuit = max( 0, $jours - 1 );
		$out  = array();
		$tot  = array( 'indemnites' => 0, 'hebergement' => 0, 'transport' => 0 );
		foreach ( $membres as $x ) {
			$nom = trim( sanitize_text_field( (string) ( $x['nom'] ?? '' ) ) );
			if ( '' === $nom ) {
				continue;
			}
			$cat = isset( $b['categories'][ $x['categorie'] ?? '' ] ) ? $x['categorie'] : '';
			if ( ! $cat ) {
				return new WP_Error( 'categorie', 'Choisissez la catégorie de ' . $nom . ' dans le barème.' );
			}
			$l     = array(
				'nom'         => mb_substr( $nom, 0, 120 ),
				'fonction'    => mb_substr( trim( sanitize_text_field( (string) ( $x['fonction'] ?? '' ) ) ), 0, 120 ),
				'categorie'   => $cat,
				'jours'       => $jours,
				'nuitees'     => $nuit,
				'indemnites'  => $jours * (int) $b['categories'][ $cat ]['indemnite'],
				'hebergement' => $nuit * (int) $b['categories'][ $cat ]['hebergement'],
				'transport'   => (int) $b['transport'][ $zone ],
			);
			$l['total'] = $l['indemnites'] + $l['hebergement'] + $l['transport'];
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += $l[ $k ];
			}
			$out[] = $l;
		}
		if ( ! $out ) {
			return new WP_Error( 'membres', 'Indiquez au moins un membre de la mission.' );
		}
		if ( count( $out ) > 12 ) {
			return new WP_Error( 'membres', 'Douze membres au plus par ordre de mission.' );
		}
		$divers = (int) $b['frais_divers'];
		return $tot + array(
			'jours'   => $jours,
			'nuitees' => $nuit,
			'membres' => $out,
			'divers'  => $divers,
			'total'   => array_sum( $tot ) + $divers,
		);
	}

	/**
	 * Crée un ordre de mission (service, ou Administration et Finance pour un service).
	 *
	 * @param array    $data    service_id (central), objet, destination, zone, date_depart, date_retour, transport, membres[].
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function creer( array $data, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$sid     = IGE_FFM_Contexte::service_utilisateur( $user_id );
		if ( ! $sid && user_can( $user_id, 'ige_gerer_fonds' ) ) {
			$sid = (int) ( $data['service_id'] ?? 0 );
			$s   = IGE_FFM_Referentiels::get( 'service', $sid );
			if ( ! $s || ! (int) $s['actif'] ) {
				return new WP_Error( 'service', 'Choisissez le service qui organise et paie la mission.' );
			}
		} elseif ( ! $sid || ! IGE_FFM_Contexte::peut_saisir( $sid, $user_id ) ) {
			return new WP_Error( 'droits', 'Votre compte ne permet pas de créer un ordre de mission.' );
		}
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		}
		$objet = trim( sanitize_text_field( (string) ( $data['objet'] ?? '' ) ) );
		$dest  = trim( sanitize_text_field( (string) ( $data['destination'] ?? '' ) ) );
		$dep   = (string) ( $data['date_depart'] ?? '' );
		$ret   = (string) ( $data['date_retour'] ?? '' );
		if ( mb_strlen( $objet ) < 5 ) {
			return new WP_Error( 'objet', 'Indiquez l’objet de la mission.' );
		}
		if ( mb_strlen( $dest ) < 2 ) {
			return new WP_Error( 'destination', 'Indiquez la destination.' );
		}
		if ( $dep < $ex['date_debut'] || $ret > $ex['date_fin'] ) {
			return new WP_Error( 'dates', sprintf( 'La mission doit se dérouler pendant l’exercice %d.', $ex['annee'] ) );
		}
		$c = self::calculer( (array) ( $data['membres'] ?? array() ), (string) ( $data['zone'] ?? 'province' ), $dep, $ret );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$t  = IGE_FFM_DB::t( 'mission' );
		$id = 0;
		for ( $essai = 0; $essai < 5 && ! $id; $essai++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE exercice_id = %d", $ex['id'] ) ) + $essai;
			$wpdb->suppress_errors( true );
			$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$t,
				array(
					'numero'       => sprintf( 'OM-%d-%03d', $ex['annee'], $n ),
					'exercice_id'  => (int) $ex['id'],
					'service_id'   => $sid,
					'objet'        => mb_substr( $objet, 0, 255 ),
					'destination'  => mb_substr( $dest, 0, 190 ),
					'zone'         => 'kinshasa' === ( $data['zone'] ?? '' ) ? 'kinshasa' : 'province',
					'date_depart'  => $dep,
					'date_retour'  => $ret,
					'transport'    => mb_substr( trim( sanitize_text_field( (string) ( $data['transport'] ?? '' ) ) ), 0, 60 ),
					'frais_divers' => $c['divers'],
					'montant'      => $c['total'],
					'statut'       => 'soumise',
					'created_by'   => (int) $user_id,
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
		foreach ( $c['membres'] as $mb ) {
			$wpdb->insert( IGE_FFM_DB::t( 'mission_membre' ), $mb + array( 'mission_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$m = self::get( $id );
		IGE_FFM_Audit::log( 'ordre_mission', 'mission', $m['numero'], null, array( 'objet' => $objet, 'destination' => $dest, 'dates' => $dep . ' → ' . $ret, 'membres' => count( $c['membres'] ), 'montant' => $c['total'] ), $user_id );
		$etat = IGE_FFM_Visas::etat( 'mission', $id, $c['total'] );
		IGE_FFM_Visas::notifier( 'mission', $m['numero'], $etat['suivante'], $sid, $c['total'] );
		return $id;
	}

	/**
	 * Visa d'un ordre de mission ; à l'approbation, l'ordre de mission officiel est émis (référence, code QR).
	 *
	 * @param int      $id          Mission.
	 * @param string   $decision    vise | refuse.
	 * @param string   $commentaire Commentaire.
	 * @param int|null $user_id     Auteur.
	 * @return array|WP_Error
	 */
	public static function viser( $id, $decision, $commentaire, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$m       = self::get( (int) $id );
		if ( ! $m || 'soumise' !== $m['statut'] ) {
			return new WP_Error( 'etat', 'Cet ordre de mission n’attend pas de visa.' );
		}
		$r = IGE_FFM_Visas::viser( 'mission', (int) $id, (int) $m['service_id'], (int) $m['montant'], $decision, $commentaire, $m['numero'], $user_id );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		if ( $r['refuse'] ) {
			$v = end( $r['visas'] );
			$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'statut' => 'refusee', 'motif' => $v ? $v['commentaire'] : '' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		} elseif ( $r['complet'] ) {
			$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'statut' => 'approuvee' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::emettre_om( self::get( (int) $id ), $user_id );
		}
		return $r;
	}

	/**
	 * Contenu de l'ordre de mission et de l'état de paiement (HTML figé).
	 *
	 * @param array $m Mission.
	 * @return string
	 */
	public static function contenu_om( array $m ) {
		$b    = self::bareme();
		$mbs  = self::membres( (int) $m['id'] );
		$s    = IGE_FFM_Referentiels::get( 'service', (int) $m['service_id'] );
		$visa = IGE_FFM_Visas::etat( 'mission', (int) $m['id'], (int) $m['montant'] );
		$h    = '<p><b>Service :</b> ' . esc_html( $s ? $s['code'] . ( $s['libelle'] !== $s['code'] ? ' · ' . $s['libelle'] : '' ) : '' ) . '<br>'
			. '<b>Objet :</b> ' . esc_html( $m['objet'] ) . '<br>'
			. '<b>Destination :</b> ' . esc_html( $m['destination'] ) . ( 'kinshasa' === $m['zone'] ? ' (Kinshasa)' : ' (province)' ) . '<br>'
			. '<b>Dates :</b> du ' . esc_html( wp_date( 'd/m/Y', strtotime( $m['date_depart'] ) ) ) . ' au ' . esc_html( wp_date( 'd/m/Y', strtotime( $m['date_retour'] ) ) ) . ( $m['transport'] ? ' · <b>transport :</b> ' . esc_html( $m['transport'] ) : '' ) . '</p>';
		$h   .= '<h2>Membres de la mission et état de paiement</h2><table class="ige-table ige-num"><thead><tr><th>Nom</th><th>Fonction</th><th class="ige-r">Jours</th><th class="ige-r">Indemnités</th><th class="ige-r">Hébergement</th><th class="ige-r">Transport</th><th class="ige-r">Total</th><th>Émargement</th></tr></thead><tbody>';
		foreach ( $mbs as $x ) {
			$h .= '<tr><td>' . esc_html( $x['nom'] ) . '</td><td>' . esc_html( $x['fonction'] ?: ( $b['categories'][ $x['categorie'] ]['libelle'] ?? '' ) ) . '</td><td class="ige-r">' . (int) $x['jours'] . '</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $x['indemnites'], '' ) ) . '</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $x['hebergement'], '' ) ) . '</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $x['transport'], '' ) ) . '</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $x['total'], '' ) ) . '</td><td></td></tr>';
		}
		$h .= '<tr><td colspan="6">Frais divers forfaitaires de la mission</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $m['frais_divers'], '' ) ) . '</td><td></td></tr>';
		$h .= '<tr class="ige-tot"><td colspan="6">Total à payer (francs congolais)</td><td class="ige-r">' . esc_html( ige_ffm_montant( (int) $m['montant'], '' ) ) . '</td><td></td></tr></tbody></table>';
		$v  = array();
		foreach ( $visa['visas'] as $x ) {
			$u   = get_userdata( (int) $x['user_id'] );
			$v[] = IGE_FFM_Visas::etapes()[ $x['etape'] ] . ' : ' . ( $u ? $u->display_name : '' ) . ', le ' . wp_date( 'd/m/Y', strtotime( $x['created_at'] ) );
		}
		if ( $v ) {
			$h .= '<p><b>Visas :</b> ' . esc_html( implode( ' ; ', $v ) ) . '.</p>';
		}
		$h .= '<p>Calcul selon le barème des frais de mission' . ( $b['officiel'] ? '' : ' (barème d’exemple)' ) . '. Le rapport de mission est à remettre au retour ; il conditionne la liquidation. Les membres émargent l’état de paiement à la remise des fonds.</p>';
		return $h;
	}

	/**
	 * Émet l'ordre de mission officiel (registre des documents).
	 *
	 * @param array $m       Mission.
	 * @param int   $user_id Auteur.
	 * @return int|WP_Error Document.
	 */
	private static function emettre_om( array $m, $user_id ) {
		return IGE_FFM_Documents::emettre(
			array(
				'type'        => 'ordre_mission',
				'titre'       => 'Ordre de mission ' . $m['numero'],
				'periode'     => wp_date( 'd/m/Y', strtotime( $m['date_depart'] ) ) . ' – ' . wp_date( 'd/m/Y', strtotime( $m['date_retour'] ) ),
				'contenu'     => self::contenu_om( $m ),
				'signataire'  => 'L’Inspecteur Général de l’Enseignement',
				'exercice_id' => (int) $m['exercice_id'],
				'service_id'  => (int) $m['service_id'],
				'objet_id'    => (int) $m['id'],
			),
			$user_id
		);
	}

	/**
	 * Paiement des frais par la caisse du service : sortie liée à la mission, l'ordre de mission en pièce.
	 *
	 * @param int      $id      Mission approuvée.
	 * @param array    $data    date, mode_paiement_id.
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Écriture.
	 */
	public static function payer( $id, array $data, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$m       = self::get( (int) $id );
		if ( ! $m || ! IGE_FFM_Contexte::peut_saisir( (int) $m['service_id'], $user_id ) || IGE_FFM_Contexte::service_utilisateur( $user_id ) !== (int) $m['service_id'] ) {
			return new WP_Error( 'droits', 'Le paiement est enregistré par le service qui organise la mission.' );
		}
		if ( 'approuvee' !== $m['statut'] ) {
			return new WP_Error( 'etat', 'Seul un ordre de mission approuvé peut être payé.' );
		}
		$rub = self::rubrique();
		if ( ! $rub ) {
			return new WP_Error( 'rubrique', 'Aucune rubrique « Missions » (R07) active : Administration et Finance doit la créer.' );
		}
		$doc = IGE_FFM_Documents::pour_objet( 'ordre_mission', (int) $id );
		$pdf = $doc ? IGE_FFM_Pdf::document( $doc ) : '';
		$tmp = wp_tempnam( 'om.pdf' );
		file_put_contents( $tmp, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$file = array( 'name' => $m['numero'] . '.pdf', 'type' => 'application/pdf', 'tmp_name' => $tmp, 'error' => $pdf ? UPLOAD_ERR_OK : UPLOAD_ERR_NO_FILE, 'size' => strlen( $pdf ) );
		IGE_FFM_Pieces::autoriser_genere( true );
		$eid = IGE_FFM_Ecritures::saisir(
			array(
				'type'             => 'sortie',
				'statut'           => 'payee',
				'date_operation'   => (string) ( $data['date'] ?? IGE_FFM_Contexte::aujourdhui() ),
				'rubrique_id'      => (int) $rub['id'],
				'montant'          => (string) $m['montant'],
				'description'      => mb_substr( 'Frais de mission ' . $m['numero'] . ' : ' . $m['objet'] . ' (' . $m['destination'] . ')', 0, 255 ),
				'beneficiaire'     => 'Membres de la mission ' . $m['numero'],
				'num_piece'        => $m['numero'],
				'mode_paiement_id' => (int) ( $data['mode_paiement_id'] ?? 0 ),
				'piece_nature'     => 'Ordre de mission',
				'_extra'           => array( 'mission_id' => (int) $id ),
			),
			$pdf ? $file : null,
			$user_id
		);
		IGE_FFM_Pieces::autoriser_genere( false );
		wp_delete_file( $tmp );
		if ( is_wp_error( $eid ) ) {
			return $eid;
		}
		$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'statut' => 'payee', 'ecriture_id' => (int) $eid ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'paiement_mission', 'mission', $m['numero'], null, array( 'ecriture' => IGE_FFM_Ecritures::get( (int) $eid )['id_ecriture'], 'montant' => (int) $m['montant'] ), $user_id );
		return (int) $eid;
	}

	/**
	 * Rubrique des missions (R07 ou, à défaut, une rubrique dont le libellé contient « mission »).
	 *
	 * @return array|null
	 */
	public static function rubrique() {
		$code = (string) apply_filters( 'ige_ffm_rubrique_missions', 'R07' );
		$alt  = null;
		foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) {
			if ( ! (int) $r['actif'] ) {
				continue;
			}
			if ( strtoupper( $r['code'] ) === $code ) {
				return $r;
			}
			if ( ! $alt && false !== stripos( remove_accents( $r['libelle'] ), 'mission' ) ) {
				$alt = $r;
			}
		}
		return $alt;
	}

	/**
	 * Dépôt du rapport de mission (et, en option, de l'état de paiement émargé).
	 *
	 * @param int      $id      Mission.
	 * @param array    $file    Fichier.
	 * @param string   $nature  « Rapport de mission » ou « État de paiement ».
	 * @param int|null $user_id Auteur.
	 * @return int|WP_Error Pièce.
	 */
	public static function deposer( $id, array $file, $nature, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$m       = self::get( (int) $id );
		if ( ! $m || ! IGE_FFM_Contexte::peut_saisir( (int) $m['service_id'], $user_id ) ) {
			return new WP_Error( 'droits', 'Mission introuvable dans votre service.' );
		}
		if ( ! in_array( $m['statut'], array( 'payee', 'liquidee' ), true ) ) {
			return new WP_Error( 'etat', 'Les pièces de retour se déposent après le paiement de la mission.' );
		}
		$nature = 'État de paiement' === $nature ? 'État de paiement' : 'Rapport de mission';
		if ( 'État de paiement' === $nature && $m['ecriture_id'] ) {
			return IGE_FFM_Pieces::enregistrer( $file, 'ecriture', (int) $m['ecriture_id'], $user_id, 'État de paiement' );
		}
		$pid = IGE_FFM_Pieces::enregistrer( $file, 'mission', (int) $id, $user_id, $nature );
		if ( ! is_wp_error( $pid ) ) {
			$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'rapport_piece_id' => (int) $pid ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return $pid;
	}

	/**
	 * Liquidation au retour : frais réels ; un écart donne un complément (sortie) ou un reversement (entrée).
	 *
	 * @param int      $id      Mission payée, rapport déposé.
	 * @param string   $reel    Coût réel.
	 * @param string   $motif   Explication d'un écart.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function liquider( $id, $reel, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$m       = self::get( (int) $id );
		if ( ! $m || ! IGE_FFM_Contexte::peut_saisir( (int) $m['service_id'], $user_id ) || IGE_FFM_Contexte::service_utilisateur( $user_id ) !== (int) $m['service_id'] ) {
			return new WP_Error( 'droits', 'La liquidation est faite par le service qui a payé la mission.' );
		}
		if ( 'payee' !== $m['statut'] ) {
			return new WP_Error( 'etat', 'Seule une mission payée peut être liquidée.' );
		}
		if ( ! $m['rapport_piece_id'] ) {
			return new WP_Error( 'rapport', 'Déposez d’abord le rapport de mission : il conditionne la liquidation.' );
		}
		if ( IGE_FFM_Contexte::aujourdhui() < $m['date_retour'] ) {
			return new WP_Error( 'dates', 'La mission n’est pas terminée.' );
		}
		$r = ige_ffm_parse_montant( $reel );
		if ( null === $r || $r < 0 ) {
			return new WP_Error( 'montant', 'Coût réel : nombre entier en CDF.' );
		}
		$ecart = $r - (int) $m['montant'];
		$motif = trim( sanitize_text_field( (string) $motif ) );
		if ( 0 !== $ecart && mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Expliquez l’écart entre les frais payés et les frais réels (jours en moins, transport…).' );
		}
		$ex   = IGE_FFM_Contexte::exercice();
		$rub  = self::rubrique();
		$date = IGE_FFM_Contexte::aujourdhui();
		if ( 0 !== $ecart ) {
			if ( $ecart > 0 && IGE_FFM_Reglages::get( 'bloquer_caisse_negative' ) && IGE_FFM_Budget::caisse( (int) $ex['id'], (int) $m['service_id'] ) < $ecart ) {
				return new WP_Error( 'caisse', 'Caisse insuffisante pour payer le complément de ' . ige_ffm_montant( $ecart ) . '.' );
			}
			$e = IGE_FFM_Ecritures::get( (int) $m['ecriture_id'] );
			$x = IGE_FFM_Ecritures::inserer_systeme(
				array(
					'exercice_id'      => (int) $ex['id'],
					'service_id'       => (int) $m['service_id'],
					'date_operation'   => $date,
					'type'             => $ecart > 0 ? 'sortie' : 'entree',
					'rubrique_id'      => $ecart > 0 && $rub ? (int) $rub['id'] : null,
					'description'      => mb_substr( ( $ecart > 0 ? 'Complément' : 'Reversement' ) . ' de frais de mission ' . $m['numero'] . ' : ' . $motif, 0, 255 ),
					'beneficiaire'     => $ecart > 0 ? 'Membres de la mission ' . $m['numero'] : 'Reversement des membres de la mission ' . $m['numero'],
					'num_piece'        => $m['numero'],
					'mode_paiement_id' => $e ? (int) $e['mode_paiement_id'] : null,
					'montant'          => abs( $ecart ),
					'mission_id'       => (int) $id,
				),
				(int) $ex['annee'],
				$user_id
			);
			if ( is_wp_error( $x ) ) {
				return $x;
			}
		}
		$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'statut' => 'liquidee', 'cout_reel' => $r, 'liquidee_le' => ige_ffm_now(), 'motif' => $motif ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'liquidation_mission', 'mission', $m['numero'], array( 'paye' => (int) $m['montant'] ), array( 'reel' => $r, 'ecart' => $ecart, 'motif' => $motif ), $user_id );
		return true;
	}

	/**
	 * Annulation (avant paiement) par le service.
	 *
	 * @param int      $id      Mission.
	 * @param string   $motif   Motif.
	 * @param int|null $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function annuler( $id, $motif, $user_id = null ) {
		global $wpdb;
		$user_id = $user_id ?? get_current_user_id();
		$m       = self::get( (int) $id );
		if ( ! $m || ( ! IGE_FFM_Contexte::peut_saisir( (int) $m['service_id'], $user_id ) && ! user_can( $user_id, 'ige_gerer_fonds' ) ) ) {
			return new WP_Error( 'droits', 'Mission introuvable.' );
		}
		if ( ! in_array( $m['statut'], array( 'soumise', 'approuvee' ), true ) ) {
			return new WP_Error( 'etat', 'Une mission payée ne s’annule plus : liquidez-la (reversement).' );
		}
		$motif = trim( sanitize_text_field( (string) $motif ) );
		if ( mb_strlen( $motif ) < 5 ) {
			return new WP_Error( 'motif', 'Indiquez le motif de l’annulation.' );
		}
		$wpdb->update( IGE_FFM_DB::t( 'mission' ), array( 'statut' => 'annulee', 'motif' => $motif ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		IGE_FFM_Audit::log( 'annulation_mission', 'mission', $m['numero'], null, array( 'motif' => $motif ), $user_id );
		return true;
	}

	/**
	 * Chiffres clés.
	 *
	 * @param int $exo Exercice.
	 * @param int $sid Service (0 = tous).
	 * @return array
	 */
	public static function chiffres( $exo, $sid = 0 ) {
		$l   = self::liste( $exo, $sid );
		$aut = array_filter( $l, static fn( $m ) => in_array( $m['statut'], array( 'payee', 'liquidee' ), true ) );
		$cout = 0;
		$insp = 0;
		foreach ( $aut as $m ) {
			$cout += null === $m['cout_reel'] ? (int) $m['montant'] : (int) $m['cout_reel'];
			$insp += count( self::membres( (int) $m['id'] ) );
		}
		$encours  = count( array_filter( $l, static fn( $m ) => 'En mission' === self::etape( $m )[0] ) );
		$manquant = count( array_filter( $l, static fn( $m ) => 'Rapport manquant' === self::etape( $m )[0] ) );
		return array(
			'nb'        => count( array_filter( $l, static fn( $m ) => ! in_array( $m['statut'], array( 'refusee', 'annulee' ), true ) ) ),
			'en_cours'  => $encours,
			'cout'      => $cout,
			'moyen'     => $aut ? (int) round( $cout / count( $aut ) ) : 0,
			'par_insp'  => $insp ? (int) round( $cout / $insp ) : 0,
			'manquants' => $manquant,
		);
	}

	/**
	 * Missions approuvées non encore payées (trésorerie).
	 *
	 * @param int $exo Exercice.
	 * @return array[]
	 */
	public static function a_payer( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'mission' ) . " WHERE exercice_id = %d AND statut IN ('soumise','approuvee') ORDER BY date_depart", $exo ), ARRAY_A );
	}
}
