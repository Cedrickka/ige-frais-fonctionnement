<?php
/**
 * Écrans des demandes de fonds (VA-03) et des visas (VA-14) :
 * - « Demandes de fonds » côté service (mes-demandes-de-fonds) : nouvelle demande, suivi, visa du chef de service ;
 * - « Demandes de fonds » côté Administration et Finance (demandes-de-fonds) : accord, réduction, refus, décaissement ;
 * - « Visas » (visas) : tout ce qui attend le visa de la personne connectée, dont l'Inspecteur Général.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Outils communs.
 */
final class IGE_FFM_Ecran_Demandes {

	/**
	 * Résumé d'une demande (HTML).
	 *
	 * @param array $d Demande.
	 * @return string
	 */
	public static function resume( array $d ) {
		$u  = get_userdata( (int) $d['created_by'] );
		$st = IGE_FFM_Demandes::statuts()[ $d['statut'] ];
		$j  = IGE_FFM_Avances::justification( (int) $d['exercice_id'], (int) $d['service_id'] );
		$h  = '<h2 style="margin-top:0">' . esc_html( $d['reference'] . ' · ' . $d['objet'] ) . ' ' . ige_ffm_pill( $st[0], $st[1] ) . '</h2>'
			. '<dl class="ige-kv">'
			. '<dt>Service</dt><dd>' . esc_html( IGE_FFM_Comptes::service_code( (int) $d['service_id'] ) ) . '</dd>'
			. '<dt>Montant demandé</dt><dd><b>' . esc_html( ige_ffm_montant( (int) $d['montant'] ) ) . '</b></dd>'
			. ( null !== $d['montant_accorde'] ? '<dt>Montant accordé</dt><dd><b>' . esc_html( ige_ffm_montant( (int) $d['montant_accorde'] ) ) . '</b></dd>' : '' )
			. '<dt>Rubriques</dt><dd>' . esc_html( $d['rubriques'] ) . '</dd>'
			. '<dt>Date de besoin</dt><dd>' . esc_html( wp_date( 'd/m/Y', strtotime( $d['date_besoin'] ) ) ) . '</dd>'
			. '<dt>Justification</dt><dd>' . nl2br( esc_html( (string) $d['justification'] ) ) . '</dd>'
			. '<dt>Demandée par</dt><dd>' . esc_html( ( $u ? $u->display_name : '' ) . ', le ' . wp_date( 'd/m/Y', strtotime( $d['created_at'] ) ) ) . '</dd>'
			. '<dt>Avance précédente</dt><dd>' . esc_html( null === $j['taux_derniere'] ? 'aucune' : $j['derniere']['libelle'] . ' justifiée à ' . floor( $j['taux_derniere'] ) . ' % (seuil ' . (int) IGE_FFM_Reglages::get( 'seuil_justification' ) . ' %)' ) . '</dd>'
			. ( $d['avance_id'] ? '<dt>Décaissement</dt><dd>' . esc_html( IGE_FFM_Avances::get( (int) $d['avance_id'] )['numero'] ?? '' ) . '</dd>' : '' )
			. ( 'refusee' === $d['statut'] && $d['motif'] ? '<dt>Motif du refus</dt><dd>' . esc_html( $d['motif'] ) . '</dd>' : '' )
			. '</dl>';
		return $h;
	}

	/**
	 * Visas en abrégé pour un tableau (✓ visé, ✕ refusé, … attendu).
	 *
	 * @param array $etat État.
	 * @return string
	 */
	public static function visas_courts( array $etat ) {
		$out = array();
		foreach ( $etat['circuit'] as $e ) {
			$v     = $etat['visas'][ $e ] ?? null;
			$lib   = array( 'responsable' => 'Chef', 'comptable' => 'A&F', 'direction' => 'IG' )[ $e ];
			$out[] = '<span title="' . esc_attr( IGE_FFM_Visas::etapes()[ $e ] ) . '" class="' . ( $v ? ( 'vise' === $v['decision'] ? 'ige-ok' : 'ige-ko' ) : 'ige-muted' ) . '">' . ( $v ? ( 'vise' === $v['decision'] ? '✓' : '✕' ) : ( $etat['suivante'] === $e ? '…' : '·' ) ) . '&nbsp;' . esc_html( $lib ) . '</span>';
		}
		return '<span class="ige-small" style="white-space:nowrap">' . implode( ' ', $out ) . '</span>';
	}

	/**
	 * Retour après un formulaire.
	 *
	 * @param string $defaut Page par défaut.
	 */
	public static function retour( $defaut ) {
		$r = ige_ffm_post( 'retour' );
		wp_safe_redirect( $r && 0 === strpos( $r, home_url() ) ? $r : ige_ffm_url( $defaut ) );
		exit;
	}
}

/**
 * Demandes de fonds : écran du service.
 */
final class IGE_FFM_Ecran_Demandes_Service {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_demande', array( __CLASS__, 'handle_creer' ) );
		add_action( 'admin_post_ige_ffm_demande_annuler', array( __CLASS__, 'handle_annuler' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Demandes de fonds';
		$args['subtitle'] = 'Exprimer un besoin, suivre les visas et le décaissement';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ctx = IGE_FFM_Ecran_Service::contexte();
		$sid = $ctx['sid'];
		$ex  = IGE_FFM_Contexte::exercice();
		if ( ! $sid || ! $ex ) {
			return $ctx['html'];
		}
		$base = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'mes-demandes-de-fonds' ), $sid );
		$id   = isset( $_GET['demande'] ) ? (int) $_GET['demande'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $id ) {
			$d = IGE_FFM_Demandes::get( $id );
			if ( ! $d || (int) $d['service_id'] !== $sid ) {
				return ige_ffm_banner( 'ko', 'Demande introuvable', '' );
			}
			$etat   = IGE_FFM_Visas::etat( 'demande', $id, IGE_FFM_Demandes::montant_circuit( $d ) );
			$retour = add_query_arg( 'demande', $id, $base );
			$h      = '<p><a href="' . esc_url( $base ) . '">← Mes demandes</a></p><section class="ige-card">' . IGE_FFM_Ecran_Demandes::resume( $d ) . '<h3 style="font-size:14px;margin:14px 0 6px">Visas</h3>' . IGE_FFM_Visas::frise( $etat );
			if ( 'soumise' === $d['statut'] && 'responsable' === $etat['suivante'] ) {
				$h .= IGE_FFM_Visas::formulaire( 'demande', $id, $etat, $sid, $retour );
			}
			if ( 'soumise' === $d['statut'] && ! $etat['visas'] && IGE_FFM_Contexte::peut_saisir( $sid ) ) {
				$h .= IGE_FFM_Ecrans::action_button( 'demande_annuler', array( 'demande' => $id, 'retour' => $base ), 'Annuler la demande', 'ige-btn ige-btn-line', 'Annuler cette demande ?' );
			}
			return $h . '</section>';
		}
		$liste = IGE_FFM_Demandes::liste( (int) $ex['id'], $sid );
		$j     = IGE_FFM_Avances::justification( (int) $ex['id'], $sid );
		$seuil = (int) IGE_FFM_Reglages::get( 'seuil_justification' );
		$peut  = IGE_FFM_Contexte::peut_saisir( $sid );
		$old   = IGE_FFM_Ecrans::old();
		$a_vis = array_filter( IGE_FFM_Visas::a_viser( get_current_user_id() ), static fn( $x ) => 'demande' === $x['objet'] && $x['service_id'] === $sid );
		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $a_vis ) {
			echo ige_ffm_banner( 'warn', count( $a_vis ) . ' demande(s) attendent votre visa', ' Ouvrez-les dans la liste ci-dessous.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<h2 style="margin-top:0">Mes demandes · exercice <?php echo esc_html( $ex['annee'] ); ?></h2>
				<div class="ige-table-wrap"><table class="ige-table">
					<thead><tr><th>Réf.</th><th>Objet</th><th class="ige-r">Demandé</th><th class="ige-r">Accordé</th><th>Visas</th><th>Statut</th></tr></thead>
					<tbody>
					<?php foreach ( $liste as $d ) : ?>
						<?php $st = IGE_FFM_Demandes::statuts()[ $d['statut'] ]; ?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'demande', $d['id'], $base ) ); ?>"><b><?php echo esc_html( $d['reference'] ); ?></b></a></td>
							<td><?php echo esc_html( $d['objet'] ); ?></td>
							<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $d['montant'], '' ) ); ?></td>
							<td class="ige-r ige-num"><?php echo esc_html( null === $d['montant_accorde'] ? '—' : ige_ffm_montant( (int) $d['montant_accorde'], '' ) ); ?></td>
							<td><?php echo IGE_FFM_Ecran_Demandes::visas_courts( IGE_FFM_Visas::etat( 'demande', (int) $d['id'], IGE_FFM_Demandes::montant_circuit( $d ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo ige_ffm_pill( $st[0] . ( $d['avance_id'] ? ' (' . ( IGE_FFM_Avances::get( (int) $d['avance_id'] )['numero'] ?? '' ) . ')' : '' ), $st[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $liste ) : ?><tr><td colspan="6" class="ige-empty">Aucune demande pour cet exercice.</td></tr><?php endif; ?>
					</tbody>
				</table></div>
				<p class="ige-small ige-muted" style="margin:10px 0 0">Ordre des visas : chef de service, Administration et Finance (qui peut réduire le montant), puis l’Inspecteur Général au-delà de <?php echo esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'visa_direction_seuil' ) ) ); ?>. Le décaissement crée l’avance de fonds dans votre caisse.</p>
			</section>
			<?php if ( $peut ) : ?>
				<section class="ige-card">
					<h2 style="margin-top:0">Nouvelle demande</h2>
					<?php if ( null !== $j['taux_derniere'] && $j['taux_derniere'] < $seuil ) : ?>
						<?php echo ige_ffm_banner( 'warn', 'Avance en cours justifiée à ' . floor( $j['taux_derniere'] ) . ' %', ' Le seuil est de ' . $seuil . ' %. La demande sera transmise, mais le décaissement attendra la justification (ou une dérogation motivée).' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<?php echo IGE_FFM_Ecrans::form_open( 'demande' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo IGE_FFM_Ecrans::field( 'objet', 'Objet', $old['objet'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="255" placeholder="Ex. : fonctionnement de novembre, mission Kwilu"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><span style="font-size:12.5px;font-weight:600">Rubriques concernées</span>
							<div style="display:flex;flex-wrap:wrap;gap:4px 14px">
							<?php foreach ( IGE_FFM_Referentiels::all( 'rubrique', true ) as $r ) : ?>
								<label class="ige-small" style="font-weight:500"><input type="checkbox" name="rubriques[]" value="<?php echo esc_attr( $r['id'] ); ?>" style="width:auto"<?php checked( in_array( (string) $r['id'], (array) ( $old['rubriques'] ?? array() ), true ) ); ?>> <?php echo esc_html( $r['code'] ); ?></label>
							<?php endforeach; ?>
							</div>
						</div>
						<div class="ige-grid ige-cols-2" style="gap:0 12px">
							<?php echo IGE_FFM_Ecrans::field( 'montant', 'Montant demandé (CDF)', $old['montant'] ?? '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo IGE_FFM_Ecrans::field( 'date_besoin', 'Date de besoin', $old['date_besoin'] ?? IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '" max="' . esc_attr( $ex['date_fin'] ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</div>
						<div class="ige-field"><label for="ige-justification">Justification</label><textarea id="ige-justification" name="justification" rows="4" required minlength="10" placeholder="Activités prévues, dépenses attendues, échéances."><?php echo esc_textarea( $old['justification'] ?? '' ); ?></textarea></div>
						<button type="submit" class="ige-btn ige-btn-jaune">Soumettre au chef de service</button>
					</form>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Création.
	 */
	public static function handle_creer() {
		ige_ffm_check_form( 'demande' );
		ige_ffm_require( 'ige_saisir' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$data = array(
			'objet'         => ige_ffm_post( 'objet' ),
			'montant'       => ige_ffm_post( 'montant' ),
			'date_besoin'   => ige_ffm_post( 'date_besoin' ),
			'justification' => isset( $_POST['justification'] ) ? sanitize_textarea_field( wp_unslash( $_POST['justification'] ) ) : '',
			'rubriques'     => isset( $_POST['rubriques'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['rubriques'] ) ) : array(),
		);
		// phpcs:enable
		$r = IGE_FFM_Demandes::creer( $data );
		if ( is_wp_error( $r ) ) {
			IGE_FFM_Ecrans::remember( array_merge( $data, array( 'rubriques' => array_map( 'strval', $data['rubriques'] ) ) ) );
			ige_ffm_flash( 'ko', 'Demande non enregistrée', $r->get_error_message() );
		} else {
			$d = IGE_FFM_Demandes::get( $r );
			ige_ffm_flash( 'ok', 'Demande ' . $d['reference'] . ' soumise', 'Le chef de service est prévenu pour son visa.' );
		}
		wp_safe_redirect( ige_ffm_url( 'mes-demandes-de-fonds' ) );
		exit;
	}

	/**
	 * Annulation.
	 */
	public static function handle_annuler() {
		ige_ffm_check_form( 'demande_annuler' );
		ige_ffm_require( 'ige_saisir' );
		$r = IGE_FFM_Demandes::annuler( (int) ige_ffm_post( 'demande' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Annulation refusée', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Demande annulée', '' );
		}
		IGE_FFM_Ecran_Demandes::retour( 'mes-demandes-de-fonds' );
	}
}

/**
 * Demandes de fonds : écran d'Administration et Finance.
 */
final class IGE_FFM_Ecran_Demandes_Central {

	/**
	 * Crochets.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_accorder', array( __CLASS__, 'handle_accorder' ) );
		add_action( 'admin_post_ige_ffm_decaisser', array( __CLASS__, 'handle_decaisser' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Demandes de fonds';
		$args['subtitle'] = 'Accord, réduction ou refus, puis décaissement en avance de fonds';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$exo  = (int) $ex['id'];
		$base = ige_ffm_url( 'demandes-de-fonds' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id  = isset( $_GET['demande'] ) ? (int) $_GET['demande'] : 0;
		$vue = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : 'mon_visa';
		// phpcs:enable
		if ( $id ) {
			return self::detail( $id, $base );
		}
		$toutes = IGE_FFM_Demandes::liste( $exo );
		$groupes = array( 'mon_visa' => array(), 'direction' => array(), 'chef' => array(), 'a_decaisser' => array(), 'decaissee' => array(), 'refusee' => array() );
		foreach ( $toutes as $d ) {
			if ( 'soumise' === $d['statut'] ) {
				$e = IGE_FFM_Visas::etat( 'demande', (int) $d['id'], IGE_FFM_Demandes::montant_circuit( $d ) );
				$groupes[ 'comptable' === $e['suivante'] ? 'mon_visa' : ( 'direction' === $e['suivante'] ? 'direction' : 'chef' ) ][] = $d;
			} elseif ( 'visee' === $d['statut'] ) {
				$groupes['a_decaisser'][] = $d;
			} elseif ( isset( $groupes[ $d['statut'] ] ) ) {
				$groupes[ $d['statut'] ][] = $d;
			}
		}
		$libs = array( 'mon_visa' => 'À mon visa', 'direction' => 'Chez l’Inspecteur Général', 'chef' => 'Chez le chef de service', 'a_decaisser' => 'À décaisser', 'decaissee' => 'Décaissées', 'refusee' => 'Refusées' );
		$vue  = isset( $libs[ $vue ] ) ? $vue : 'mon_visa';
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-toolbar">
				<?php foreach ( $libs as $k => $l ) : ?>
					<a class="ige-chip<?php echo $vue === $k ? ' ige-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'vue', $k, $base ) ); ?>"><?php echo esc_html( $l . ' (' . count( $groupes[ $k ] ) . ')' ); ?></a>
				<?php endforeach; ?>
			</div>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Réf.</th><th>Service</th><th>Objet</th><th>Besoin</th><th class="ige-r">Demandé</th><th class="ige-r">Accordé</th><th>Avance précédente</th><th>Visas</th></tr></thead>
				<tbody>
				<?php foreach ( $groupes[ $vue ] as $d ) : ?>
					<?php $j = IGE_FFM_Avances::justification( $exo, (int) $d['service_id'] ); ?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'demande', $d['id'], $base ) ); ?>"><b><?php echo esc_html( $d['reference'] ); ?></b></a></td>
						<td><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $d['service_id'] ) ); ?></td>
						<td><?php echo esc_html( $d['objet'] ); ?></td>
						<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $d['date_besoin'] ) ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $d['montant'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( null === $d['montant_accorde'] ? '—' : ige_ffm_montant( (int) $d['montant_accorde'], '' ) ); ?></td>
						<td<?php echo null !== $j['taux_derniere'] && $j['taux_derniere'] < (int) IGE_FFM_Reglages::get( 'seuil_justification' ) ? ' class="ige-neg"' : ''; ?>><?php echo esc_html( null === $j['taux_derniere'] ? '—' : floor( $j['taux_derniere'] ) . ' % justifiée' ); ?></td>
						<td><?php echo IGE_FFM_Ecran_Demandes::visas_courts( IGE_FFM_Visas::etat( 'demande', (int) $d['id'], IGE_FFM_Demandes::montant_circuit( $d ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $groupes[ $vue ] ) : ?><tr><td colspan="8" class="ige-empty">Aucune demande dans cette liste.</td></tr><?php endif; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Seuils de visa (réglables) : jusqu’à <?php echo esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'visa_direction_seuil' ) ) ); ?>, chef de service puis Administration et Finance ; au-delà, visa de l’Inspecteur Général en plus. Un décaissement alors que l’avance précédente est justifiée à moins de <?php echo (int) IGE_FFM_Reglages::get( 'seuil_justification' ); ?> % exige une dérogation motivée.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Détail : visa d'accord, décaissement.
	 *
	 * @param int    $id   Demande.
	 * @param string $base Liste.
	 * @return string
	 */
	private static function detail( $id, $base ) {
		$d = IGE_FFM_Demandes::get( $id );
		if ( ! $d ) {
			return ige_ffm_banner( 'ko', 'Demande introuvable', '' );
		}
		$etat   = IGE_FFM_Visas::etat( 'demande', $id, IGE_FFM_Demandes::montant_circuit( $d ) );
		$retour = add_query_arg( 'demande', $id, $base );
		$uid    = get_current_user_id();
		$h      = '<p><a href="' . esc_url( $base ) . '">← Toutes les demandes</a></p><div class="ige-grid ige-cols-21 ige-start"><section class="ige-card">' . IGE_FFM_Ecran_Demandes::resume( $d ) . '<h3 style="font-size:14px;margin:14px 0 6px">Visas</h3>' . IGE_FFM_Visas::frise( $etat ) . '</section><aside class="ige-card">';
		if ( 'soumise' === $d['statut'] && 'comptable' === $etat['suivante'] && IGE_FFM_Visas::peut( $uid, 'comptable', (int) $d['service_id'] ) ) {
			$h .= '<h2 style="margin-top:0">Visa d’Administration et Finance</h2>'
				. IGE_FFM_Ecrans::form_open( 'accorder' )
				. '<input type="hidden" name="demande" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
				. IGE_FFM_Ecrans::field( 'montant_accorde', 'Montant accordé (CDF)', ige_ffm_montant( (int) $d['montant'], '' ), array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"', 'help' => 'Égal ou inférieur au montant demandé.' ) )
				. '<div class="ige-field"><label for="ige-com-acc">Avis</label><textarea id="ige-com-acc" name="commentaire" rows="2" maxlength="500" placeholder="Obligatoire si vous réduisez le montant"></textarea></div>'
				. '<button type="submit" class="ige-btn ige-btn-jaune">Accorder</button></form>'
				. IGE_FFM_Ecrans::form_open( 'viser', 'style="margin-top:12px"' )
				. '<input type="hidden" name="objet" value="demande"><input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '"><input type="hidden" name="decision" value="refuse">'
				. '<div class="ige-field"><label for="ige-com-ref">Motif de refus</label><textarea id="ige-com-ref" name="commentaire" rows="2" maxlength="500" required minlength="5"></textarea></div>'
				. '<button type="submit" class="ige-btn ige-btn-rouge">Refuser la demande</button></form>';
		} elseif ( 'soumise' === $d['statut'] ) {
			$h .= '<p class="ige-small">' . esc_html( $etat['suivante'] ? 'Visa attendu : ' . IGE_FFM_Visas::etapes()[ $etat['suivante'] ] . '.' : '' ) . '</p>' . IGE_FFM_Visas::formulaire( 'demande', $id, $etat, (int) $d['service_id'], $retour );
		} elseif ( 'visee' === $d['statut'] && current_user_can( 'ige_gerer_fonds' ) ) {
			$ok    = IGE_FFM_Avances::eligible( (int) $d['exercice_id'], (int) $d['service_id'] );
			$modes = wp_list_pluck( array_filter( IGE_FFM_Referentiels::all( 'mode_paiement' ), static fn( $m ) => (int) $m['actif'] ), 'libelle', 'id' );
			$h    .= '<h2 style="margin-top:0">Décaissement</h2><p class="ige-small">Crée l’avance de fonds de ' . esc_html( ige_ffm_montant( (int) $d['montant_accorde'] ) ) . ' et son entrée dans la caisse du service.</p>'
				. ( is_wp_error( $ok ) ? ige_ffm_banner( 'warn', 'Dérogation nécessaire', ' ' . esc_html( $ok->get_error_message() ) ) : '' )
				. IGE_FFM_Ecrans::form_open( 'decaisser' )
				. '<input type="hidden" name="demande" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
				. IGE_FFM_Ecrans::field( 'date_remise', 'Date de remise', IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '"' ) )
				. IGE_FFM_Ecrans::field( 'mode_paiement_id', 'Mode de remise', '', array( 'type' => 'select', 'options' => $modes ) )
				. ( is_wp_error( $ok ) ? IGE_FFM_Ecrans::field( 'derogation', 'Motif de la dérogation', '', array( 'required' => true, 'attrs' => 'minlength="10" maxlength="200"' ) ) : '' )
				. '<button type="submit" class="ige-btn ige-btn-jaune">Décaisser</button></form>';
		} else {
			$st = IGE_FFM_Demandes::statuts()[ $d['statut'] ];
			$h .= '<p>' . ige_ffm_pill( $st[0], $st[1] ) . '</p>';
		}
		return $h . '</aside></div>';
	}

	/**
	 * Accord.
	 */
	public static function handle_accorder() {
		ige_ffm_check_form( 'accorder' );
		ige_ffm_require( 'ige_verifier' );
		$r = IGE_FFM_Demandes::accorder( (int) ige_ffm_post( 'demande' ), ige_ffm_post( 'montant_accorde' ), isset( $_POST['commentaire'] ) ? sanitize_textarea_field( wp_unslash( $_POST['commentaire'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Visa non enregistré', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Demande accordée', $r['complet'] ? 'Tous les visas sont réunis : vous pouvez décaisser.' : 'Visa suivant : ' . IGE_FFM_Visas::etapes()[ $r['suivante'] ] . ' (prévenu par e-mail).' );
		}
		IGE_FFM_Ecran_Demandes::retour( 'demandes-de-fonds' );
	}

	/**
	 * Décaissement.
	 */
	public static function handle_decaisser() {
		ige_ffm_check_form( 'decaisser' );
		ige_ffm_require( 'ige_gerer_fonds' );
		$r = IGE_FFM_Demandes::decaisser( (int) ige_ffm_post( 'demande' ), array( 'date_remise' => ige_ffm_post( 'date_remise' ), 'mode_paiement_id' => (int) ige_ffm_post( 'mode_paiement_id' ), 'derogation' => ige_ffm_post( 'derogation' ) ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Décaissement refusé', $r->get_error_message() );
		} else {
			$a = IGE_FFM_Avances::get( $r );
			ige_ffm_flash( 'ok', 'Fonds décaissés : avance ' . $a['numero'], 'L’entrée est inscrite dans la caisse du service, qui est prévenu.' );
		}
		IGE_FFM_Ecran_Demandes::retour( 'demandes-de-fonds' );
	}
}

/**
 * Visas : tout ce qui attend le visa de la personne connectée.
 */
final class IGE_FFM_Ecran_Visas {

	/**
	 * Crochets.
	 */
	public static function register() {}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Visas';
		$args['subtitle'] = 'Demandes de fonds, ordonnancements et ordres de mission qui attendent votre visa';
		$args['actions']  = '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$uid  = get_current_user_id();
		$base = ige_ffm_url( 'visas' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$objet = isset( $_GET['objet'] ) ? sanitize_key( $_GET['objet'] ) : '';
		$id    = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		// phpcs:enable
		if ( $objet && $id ) {
			return self::detail( $objet, $id, $base );
		}
		$liste = IGE_FFM_Visas::a_viser( $uid );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Dossier</th><th>Réf.</th><th>Service</th><th>Objet</th><th class="ige-r">Montant</th><th>Votre visa</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $liste as $x ) : ?>
					<tr>
						<td><?php echo esc_html( IGE_FFM_Visas::objets()[ $x['objet'] ] ); ?></td>
						<td><b><?php echo esc_html( $x['ref'] ); ?></b></td>
						<td><?php echo esc_html( IGE_FFM_Comptes::service_code( $x['service_id'] ) ); ?></td>
						<td><?php echo esc_html( $x['libelle'] ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $x['montant'] ) ); ?></td>
						<td><?php echo esc_html( IGE_FFM_Visas::etapes()[ $x['etape'] ] ); ?></td>
						<td><a class="ige-btn ige-btn-line" href="<?php echo esc_url( add_query_arg( array( 'objet' => $x['objet'], 'id' => $x['id'] ), $base ) ); ?>">Examiner</a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $liste ) : ?><tr><td colspan="7" class="ige-empty">Rien n’attend votre visa.</td></tr><?php endif; ?>
				</tbody>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Examen d'un dossier.
	 *
	 * @param string $objet Objet.
	 * @param int    $id    Identifiant.
	 * @param string $base  Liste.
	 * @return string
	 */
	private static function detail( $objet, $id, $base ) {
		$retour = add_query_arg( array( 'objet' => $objet, 'id' => $id ), $base );
		$h      = '<p><a href="' . esc_url( $base ) . '">← Tous les visas</a></p><section class="ige-card">';
		if ( 'demande' === $objet ) {
			$d = IGE_FFM_Demandes::get( $id );
			if ( ! $d || ! IGE_FFM_Contexte::peut_voir( (int) $d['service_id'] ) ) {
				return ige_ffm_banner( 'ko', 'Dossier introuvable', '' );
			}
			$etat = IGE_FFM_Visas::etat( 'demande', $id, IGE_FFM_Demandes::montant_circuit( $d ) );
			$h   .= IGE_FFM_Ecran_Demandes::resume( $d );
			$sid  = (int) $d['service_id'];
			if ( 'comptable' === $etat['suivante'] ) {
				$h .= IGE_FFM_Visas::frise( $etat ) . '<p><a class="ige-btn ige-btn-jaune" href="' . esc_url( add_query_arg( 'demande', $id, ige_ffm_url( 'demandes-de-fonds' ) ) ) . '">Accorder ou refuser</a></p></section>';
				return $h;
			}
		} elseif ( 'ordonnancement' === $objet ) {
			$e = IGE_FFM_Ecritures::get( $id );
			if ( ! $e || ! IGE_FFM_Contexte::peut_voir( (int) $e['service_id'] ) ) {
				return ige_ffm_banner( 'ko', 'Dossier introuvable', '' );
			}
			$h  .= '<h2 style="margin-top:0">Ordonnancement ' . esc_html( $e['id_ecriture'] ) . '</h2><dl class="ige-kv"><dt>Service</dt><dd>' . esc_html( IGE_FFM_Comptes::service_code( (int) $e['service_id'] ) ) . '</dd><dt>Objet</dt><dd>' . esc_html( $e['description'] ) . '</dd><dt>Montant</dt><dd><b>' . esc_html( ige_ffm_montant( (int) $e['montant'] ) ) . '</b></dd><dt>Service fait</dt><dd>' . esc_html( $e['liquidee_le'] ? wp_date( 'd/m/Y', strtotime( $e['liquidee_le'] ) ) . ' · facture ' . $e['facture_ref'] : '—' ) . '</dd></dl>'
				. '<p><a href="' . esc_url( add_query_arg( 'ecriture', $id, ige_ffm_url( 'dossier-de-depense' ) ) ) . '">Voir le dossier et ses pièces</a></p>';
			$etat = IGE_FFM_Visas::etat( 'ordonnancement', $id, (int) $e['montant'] );
			$sid  = (int) $e['service_id'];
		} elseif ( 'mission' === $objet ) {
			$m = IGE_FFM_Missions::get( $id );
			if ( ! $m || ! IGE_FFM_Contexte::peut_voir( (int) $m['service_id'] ) ) {
				return ige_ffm_banner( 'ko', 'Dossier introuvable', '' );
			}
			$h   .= '<h2 style="margin-top:0">Ordre de mission ' . esc_html( $m['numero'] ) . '</h2>' . IGE_FFM_Missions::contenu_om( $m ) . '<p><a href="' . esc_url( add_query_arg( 'mission', $id, ige_ffm_url( 'missions' ) ) ) . '">Ouvrir la mission</a></p>';
			$etat = IGE_FFM_Visas::etat( 'mission', $id, (int) $m['montant'] );
			$sid  = (int) $m['service_id'];
		} else {
			return ige_ffm_banner( 'ko', 'Dossier introuvable', '' );
		}
		$h .= '<h3 style="font-size:14px;margin:14px 0 6px">Visas</h3>' . IGE_FFM_Visas::frise( $etat ) . IGE_FFM_Visas::formulaire( $objet, $id, $etat, $sid, $retour );
		return $h . '</section>';
	}
}
