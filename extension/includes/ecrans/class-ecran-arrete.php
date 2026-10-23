<?php
/**
 * Écran Arrêté de caisse (service) : comptage mensuel, rapprochement avec le livre, signatures,
 * clôture du mois, procès-verbaux imprimables, comptages inopinés à contresigner.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Arrêté de caisse.
 */
final class IGE_FFM_Ecran_Arrete {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_arrete', array( __CLASS__, 'handle_enregistrer' ) );
		add_action( 'admin_post_ige_ffm_arrete_signer', array( __CLASS__, 'handle_signer' ) );
		add_action( 'admin_post_ige_ffm_inopine_contresigner', array( __CLASS__, 'handle_contresigner' ) );
	}

	/**
	 * Mois choisi (?mois=, sinon le mois précédent s'il n'est pas clôturé).
	 *
	 * @param array $ex  Exercice.
	 * @param int   $sid Service.
	 * @return int
	 */
	private static function mois_choisi( array $ex, $sid ) {
		$max = self::mois_max( $ex );
		$m   = isset( $_GET['mois'] ) ? (int) $_GET['mois'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $m >= 1 && $m <= $max ) {
			return $m;
		}
		// Par défaut : le mois précédent (ou le mois en cours en janvier), sauf s'il est déjà clôturé.
		$clos = IGE_FFM_Arretes::dernier_mois_clos( (int) $ex['id'], $sid );
		return min( $max, max( $clos + 1, $max - 1, 1 ) );
	}

	/**
	 * Dernier mois commencé de l'exercice.
	 *
	 * @param array $ex Exercice.
	 * @return int
	 */
	private static function mois_max( array $ex ) {
		$auj = IGE_FFM_Contexte::aujourdhui();
		if ( $auj > $ex['date_fin'] ) {
			return 12;
		}
		return $auj < $ex['date_debut'] ? 1 : (int) substr( $auj, 5, 2 );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$sid = IGE_FFM_Contexte::service_courant();
		$ex  = IGE_FFM_Contexte::exercice();
		$pv  = isset( $_GET['pv'] ) ? (int) $_GET['pv'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args['actions'] = '';
		if ( $pv ) {
			$args['title']    = 'Procès-verbal de caisse';
			$args['subtitle'] = $sid ? IGE_FFM_Comptes::service_code( $sid ) : '';
			$args['actions']  = '<button type="button" class="ige-btn ige-btn-jaune" onclick="window.print()">Imprimer ou enregistrer en PDF</button>';
			return $args;
		}
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		$m    = $sid && $ex ? self::mois_choisi( $ex, $sid ) : 0;
		$args['title']    = 'Arrêté de caisse' . ( $m ? ' — ' . strtolower( $noms[ $m ] ) . ' ' . $ex['annee'] : '' );
		$args['subtitle'] = ( $sid ? IGE_FFM_Comptes::service_code( $sid ) . ' · ' : '' ) . 'comptage physique comparé au solde du livre de caisse';
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
		$pv = isset( $_GET['pv'] ) ? (int) $_GET['pv'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $pv ) {
			$a = IGE_FFM_Arretes::get( $pv );
			if ( ! $a || (int) $a['service_id'] !== $sid ) {
				return ige_ffm_banner( 'ko', 'Procès-verbal introuvable', '' );
			}
			return '<p class="ige-no-print"><a href="' . esc_url( IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'arrete-de-caisse', array( 'mois' => 'mensuel' === $a['type'] ? $a['mois'] : '' ) ), $sid ) ) . '">← Retour à l’arrêté de caisse</a></p>' . self::pv( $a );
		}

		$exo   = (int) $ex['id'];
		$mois  = self::mois_choisi( $ex, $sid );
		$noms  = IGE_FFM_Ecran_Livre::mois_noms();
		$clos  = IGE_FFM_Arretes::dernier_mois_clos( $exo, $sid );
		$ar    = IGE_FFM_Arretes::mensuel( $exo, $sid, $mois );
		$base  = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'arrete-de-caisse' ), $sid );
		$peut  = IGE_FFM_Contexte::peut_saisir( $sid );
		$theo  = IGE_FFM_Arretes::solde_theorique( $exo, $sid, IGE_FFM_Arretes::fin_de_mois( $ex, $mois ) );
		$fin   = wp_date( 'd/m', strtotime( IGE_FFM_Arretes::fin_de_mois( $ex, $mois ) ) );
		$old   = IGE_FFM_Ecrans::old();
		$det   = $old ? array( 'cdf' => $old['cdf'] ?? array(), 'usd' => $old['usd'] ?? array(), 'taux' => $old['taux'] ?? '', 'autres' => $old['autres'] ?? '', 'autres_detail' => $old['autres_detail'] ?? '' ) : ( $ar ? $ar['detail'] : array() );
		$expl  = $old['explication'] ?? ( $ar ? (string) $ar['explication'] : '' );
		$edit  = $peut && $mois > $clos && ( ! $ar || 'brouillon' === $ar['statut'] );
		$inop  = array_filter( IGE_FFM_Arretes::liste( $exo, $sid, 'inopine' ), static fn( $x ) => 'constate' === $x['statut'] );

		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $inop as $x ) {
			$ctl = get_userdata( (int) $x['signe_resp_par'] );
			echo '<div class="ige-banner ige-warn"><span class="ige-ic">!</span><div><b>Comptage inopiné du ' . esc_html( wp_date( 'd/m/Y', strtotime( $x['created_at'] ) ) ) . ' à contresigner</b> Effectué par ' . esc_html( $ctl ? $ctl->display_name : 'Administration et Finance' ) . ' · écart ' . esc_html( ige_ffm_montant( (int) $x['ecart'] ) ) . '. <a href="' . esc_url( add_query_arg( 'pv', $x['id'], $base ) ) . '">Lire le procès-verbal</a>';
			if ( $peut ) {
				echo ' ' . IGE_FFM_Ecrans::action_button( 'inopine_contresigner', array( 'arrete' => $x['id'] ), 'Contresigner', 'ige-btn ige-btn-line', 'Contresigner ce procès-verbal ? Vous confirmez avoir assisté au comptage.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</div></div>';
		}
		?>
		<section class="ige-card" style="padding:14px 16px">
			<div class="ige-toolbar" style="margin:0">
				<?php for ( $m = 1; $m <= self::mois_max( $ex ); $m++ ) : ?>
					<?php
					$a   = $m <= $clos ? null : IGE_FFM_Arretes::mensuel( $exo, $sid, $m );
					$cls = $m === $mois ? ' ige-on' : '';
					$ico = $m <= $clos ? '✓ ' : ( $a ? '… ' : '' );
					?>
					<a class="ige-chip<?php echo esc_attr( $cls ); ?>" href="<?php echo esc_url( add_query_arg( 'mois', $m, $base ) ); ?>" title="<?php echo esc_attr( $m <= $clos ? 'Clôturé' : ( $a ? IGE_FFM_Arretes::etat( $a )[0] : 'Non arrêté' ) ); ?>"><?php echo esc_html( $ico . IGE_FFM_Ecran_Livre::mois_courts()[ $m ] ); ?></a>
				<?php endfor; ?>
				<span class="ige-spacer ige-small ige-muted"><?php echo $clos ? esc_html( 'Mois clôturés : janvier → ' . strtolower( $noms[ $clos ] ) ) : 'Aucun mois clôturé'; ?></span>
			</div>
		</section>
		<?php
		if ( $mois <= $clos ) {
			$cl = IGE_FFM_Arretes::mensuel( $exo, $sid, $mois );
			if ( $cl && 'cloture' === $cl['statut'] ) {
				echo ige_ffm_banner( 'ok', 'Mois clôturé', ' ' . esc_html( $noms[ $mois ] ) . ' est arrêté et signé : plus aucune écriture ne peut y être ajoutée. <a href="' . esc_url( add_query_arg( 'pv', $cl['id'], $base ) ) . '">Procès-verbal</a>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo ige_ffm_banner( 'ok', 'Mois clôturé', ' ' . esc_html( $noms[ $mois ] ) . ' est couvert par l’arrêté d’un mois suivant.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return ob_get_clean();
		}
		$nt = IGE_FFM_Arretes::non_transmises_du_mois( $exo, $sid, $mois );
		if ( $nt ) {
			echo ige_ffm_banner( 'warn', $nt . ' écriture(s) de la période pas encore transmise(s)', ' Transmettez-les au central avant de clôturer : après la clôture, seule une contrepassation dans un mois ouvert permettra une correction.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $edit ) {
			echo IGE_FFM_Ecrans::form_open( 'arrete', 'id="ige-arrete-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<input type="hidden" name="mois" value="' . esc_attr( $mois ) . '">';
		}
		?>
		<div class="ige-grid ige-cols-2 ige-start" data-ige-billetage data-theorique="<?php echo esc_attr( $theo ); ?>">
			<?php echo self::billetage( $det, ! $edit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div class="ige-grid">
				<section class="ige-card">
					<h2>Rapprochement</h2>
					<dl class="ige-kv ige-num" style="grid-template-columns:1fr auto">
						<dt>Solde du livre de caisse au <?php echo esc_html( $fin ); ?></dt><dd class="ige-right"><?php echo esc_html( ige_ffm_montant( $theo ) ); ?></dd>
						<dt>Total compté</dt><dd class="ige-right" data-ige-total><?php echo esc_html( ige_ffm_montant( (int) ( $det['total'] ?? 0 ) ) ); ?></dd>
						<dt><b style="color:var(--ige-ink)">Écart</b></dt><dd class="ige-right" data-ige-ecart style="font-weight:700"><?php echo $ar ? esc_html( ige_ffm_montant( (int) $ar['ecart'] ) ) : '—'; ?></dd>
					</dl>
					<?php if ( $ar && (int) $ar['solde_theorique'] !== $theo ) : ?>
						<?php echo ige_ffm_banner( 'warn', 'Le livre a changé depuis le comptage', ' Solde au moment du comptage : ' . esc_html( ige_ffm_montant( (int) $ar['solde_theorique'] ) ) . '. Enregistrez de nouveau le comptage.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endif; ?>
					<div class="ige-field" style="margin:14px 0 0">
						<label for="ige-explication">Explication de l’écart</label>
						<textarea id="ige-explication" name="explication" rows="3" maxlength="1000"<?php echo $edit ? '' : ' readonly'; ?> placeholder="Obligatoire s’il y a un écart : cause, pièces, mesures prises"><?php echo esc_textarea( $expl ); ?></textarea>
					</div>
					<?php if ( $edit ) : ?>
						<button type="submit" class="ige-btn ige-btn-bleu" style="margin-top:12px">Enregistrer le comptage</button>
					<?php endif; ?>
				</section>
				<?php echo self::signatures( $ar, $sid, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
		if ( $edit ) {
			echo '</form>';
		}
		$hist = IGE_FFM_Arretes::liste( $exo, $sid );
		if ( $hist ) :
			?>
			<section class="ige-card">
				<h2>Procès-verbaux de l’exercice</h2>
				<div class="ige-table-wrap"><table class="ige-table ige-num">
					<thead><tr><th>Référence</th><th>Type</th><th>Période</th><th class="ige-r">Solde du livre</th><th class="ige-r">Compté</th><th class="ige-r">Écart</th><th>État</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $hist as $h ) : ?>
						<?php list( $lbl, $tone ) = IGE_FFM_Arretes::etat( $h ); ?>
						<tr>
							<td><?php echo esc_html( IGE_FFM_Arretes::reference( $h ) ); ?></td>
							<td><?php echo 'mensuel' === $h['type'] ? 'Arrêté mensuel' : 'Comptage inopiné'; ?></td>
							<td><?php echo esc_html( 'mensuel' === $h['type'] ? $noms[ (int) $h['mois'] ] : wp_date( 'd/m/Y', strtotime( $h['created_at'] ) ) ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $h['solde_theorique'], '' ) ); ?></td>
							<td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $h['total_compte'], '' ) ); ?></td>
							<td class="ige-r"<?php echo (int) $h['ecart'] ? ' style="color:var(--ige-rouge);font-weight:700"' : ''; ?>><?php echo esc_html( ige_ffm_montant( (int) $h['ecart'], '' ) ); ?></td>
							<td><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'pv', $h['id'], $base ) ); ?>">PV</a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
			</section>
			<?php
		endif;
		return ob_get_clean();
	}

	/**
	 * Carte des signatures d'un arrêté mensuel.
	 *
	 * @param array|null $ar   Arrêté.
	 * @param int        $sid  Service.
	 * @param string     $base Adresse de l'écran.
	 * @return string
	 */
	private static function signatures( $ar, $sid, $base ) {
		$uid = get_current_user_id();
		$ag  = $ar && $ar['signe_agent_par'] ? get_userdata( (int) $ar['signe_agent_par'] ) : null;
		$rs  = $ar && $ar['signe_resp_par'] ? get_userdata( (int) $ar['signe_resp_par'] ) : null;
		$h   = '<section class="ige-card"><h2>Signatures du procès-verbal</h2>';
		$h  .= '<div class="ige-check">' . ( $ag ? '<span class="ige-ok">✓</span>Agent : ' . esc_html( $ag->display_name . ', ' . wp_date( 'd/m à H:i', strtotime( $ar['signe_agent_le'] ) ) ) : '<span class="ige-muted">☐</span>Agent qui a compté la caisse' ) . '</div>';
		$h  .= '<div class="ige-check">' . ( $rs ? '<span class="ige-ok">✓</span>Responsable : ' . esc_html( $rs->display_name . ', ' . wp_date( 'd/m à H:i', strtotime( $ar['signe_resp_le'] ) ) ) : '<span class="ige-muted">☐</span>Responsable du service (une autre personne)' ) . '</div>';
		$h  .= '<p class="ige-small ige-muted">Après la signature du Responsable, le mois est clôturé : plus aucune écriture ne peut y être ajoutée.</p>';
		if ( ! $ar ) {
			$h .= '<p class="ige-small ige-muted" style="margin:0">Comptez la caisse et enregistrez le comptage pour pouvoir signer.</p>';
		} elseif ( 'brouillon' === $ar['statut'] && IGE_FFM_Contexte::peut_saisir( $sid ) ) {
			$h .= IGE_FFM_Ecrans::action_button( 'arrete_signer', array( 'arrete' => $ar['id'], 'qui' => 'agent' ), 'Signer en tant qu’agent', 'ige-btn ige-btn-jaune', 'Signer l’arrêté ? Vous attestez avoir compté la caisse.' );
		} elseif ( 'signe_agent' === $ar['statut'] && current_user_can( 'ige_signer_arrete' ) && IGE_FFM_Contexte::service_utilisateur() === (int) $sid ) {
			$h .= (int) $ar['signe_agent_par'] === $uid
				? '<p class="ige-small" style="margin:0">Un autre membre du service ayant le profil Responsable doit contresigner.</p>'
				: IGE_FFM_Ecrans::action_button( 'arrete_signer', array( 'arrete' => $ar['id'], 'qui' => 'responsable' ), 'Signer et clôturer le mois', 'ige-btn ige-btn-jaune', 'Signer et clôturer le mois ? Plus aucune écriture ne pourra y être ajoutée.' );
		} elseif ( 'signe_agent' === $ar['statut'] ) {
			$h .= '<p class="ige-small" style="margin:0">En attente de la signature du Responsable.</p>';
		}
		if ( $ar ) {
			$h .= '<p style="margin:12px 0 0"><a class="ige-act" href="' . esc_url( add_query_arg( 'pv', $ar['id'], $base ) ) . '">Voir le procès-verbal</a></p>';
		}
		return $h . '</section>';
	}

	/**
	 * Carte de billetage (saisie ou lecture), réutilisée par l'écran Caisses.
	 *
	 * @param array $d         Détail (cdf, usd, taux, autres, autres_detail).
	 * @param bool  $lecture   Lecture seule.
	 * @return string
	 */
	public static function billetage( array $d, $lecture = false ) {
		$c  = IGE_FFM_Arretes::coupures();
		$ro = $lecture ? ' readonly' : '';
		$h  = '<section class="ige-card"><h2>Billetage <span class="ige-sub">francs congolais</span></h2><div class="ige-billet ige-num">';
		$h .= '<span class="ige-h">Coupure</span><span class="ige-h ige-right">Nombre</span><span class="ige-h ige-right">Montant</span>';
		foreach ( $c['CDF'] as $v ) {
			$n  = (int) ( $d['cdf'][ $v ] ?? 0 );
			$h .= '<label for="ige-cdf-' . $v . '">' . esc_html( ige_ffm_montant( $v, '' ) ) . ' FC</label><input id="ige-cdf-' . $v . '" name="cdf[' . $v . ']" inputmode="numeric" value="' . esc_attr( $n ?: '' ) . '" placeholder="0" data-coupure="' . $v . '" data-devise="cdf"' . $ro . '><span class="ige-right" data-ligne>' . esc_html( ige_ffm_montant( $n * $v, '' ) ) . '</span>';
		}
		$h .= '<span style="font-weight:700;border-top:1px solid var(--ige-ligne);padding-top:8px">Total CDF</span><span style="border-top:1px solid var(--ige-ligne)"></span><span class="ige-right" data-total-cdf style="font-weight:700;border-top:1px solid var(--ige-ligne);padding-top:8px">' . esc_html( ige_ffm_montant( (int) ( $d['total_cdf'] ?? 0 ), '' ) ) . '</span>';
		$h .= '</div>';
		$h .= '<details' . ( ! empty( $d['usd'] ) || ! empty( $d['autres'] ) ? ' open' : '' ) . ' style="margin-top:16px"><summary class="ige-small" style="font-weight:600;cursor:pointer">Dollars et autres disponibilités</summary><div class="ige-billet ige-num" style="margin-top:10px">';
		foreach ( $c['USD'] as $v ) {
			$n  = (int) ( $d['usd'][ $v ] ?? 0 );
			$h .= '<label for="ige-usd-' . $v . '">' . esc_html( $v ) . ' USD</label><input id="ige-usd-' . $v . '" name="usd[' . $v . ']" inputmode="numeric" value="' . esc_attr( $n ?: '' ) . '" placeholder="0" data-coupure="' . $v . '" data-devise="usd"' . $ro . '><span class="ige-right" data-ligne>' . esc_html( $n * $v ) . ' USD</span>';
		}
		$h .= '</div>';
		$h .= '<div class="ige-grid ige-cols-2" style="gap:0 12px;margin-top:12px">';
		$h .= IGE_FFM_Ecrans::field( 'taux', 'Taux du jour (CDF pour 1 USD)', ! empty( $d['taux'] ) ? (string) $d['taux'] : '', array( 'attrs' => 'inputmode="numeric" data-taux' . $ro ) );
		$h .= IGE_FFM_Ecrans::field( 'autres', 'Autres disponibilités (CDF)', ! empty( $d['autres'] ) ? (string) $d['autres'] : '', array( 'attrs' => 'inputmode="numeric" data-autres' . $ro, 'help' => 'Compte bancaire, mobile money…' ) );
		$h .= '</div>';
		$h .= IGE_FFM_Ecrans::field( 'autres_detail', 'Détail des autres disponibilités', (string) ( $d['autres_detail'] ?? '' ), array( 'attrs' => 'maxlength="190" placeholder="Ex. : relevé Rawbank du 30/09, solde M-Pesa"' . $ro ) );
		$h .= '</details></section>';
		return $h;
	}

	/**
	 * Procès-verbal imprimable (format de la charte).
	 *
	 * @param array $a Arrêté.
	 * @return string
	 */
	public static function pv( array $a ) {
		$noms = IGE_FFM_Ecran_Livre::mois_noms();
		$d    = $a['detail'];
		$c    = IGE_FFM_Arretes::coupures();
		$ag   = $a['signe_agent_par'] ? get_userdata( (int) $a['signe_agent_par'] ) : null;
		$rs   = $a['signe_resp_par'] ? get_userdata( (int) $a['signe_resp_par'] ) : null;
		$pr   = ! empty( $d['agent_present'] ) ? get_userdata( (int) $d['agent_present'] ) : null;
		$mens = 'mensuel' === $a['type'];
		$code = IGE_FFM_Comptes::service_code( (int) $a['service_id'] );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$annee = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT annee FROM ' . IGE_FFM_DB::t( 'exercice' ) . ' WHERE id = %d', $a['exercice_id'] ) );
		$date  = $mens ? IGE_FFM_Arretes::fin_de_mois( array( 'annee' => $annee ), (int) $a['mois'] ) : substr( $a['created_at'], 0, 10 );
		list( $lbl ) = IGE_FFM_Arretes::etat( $a );
		$doc         = IGE_FFM_Documents::pour_objet( $mens ? 'pv_arrete' : 'pv_inopine', (int) $a['id'] );
		$modifie     = false;
		if ( $doc ) {
			$snap = json_decode( $doc['contenu'], true ) ?: array();
			foreach ( $snap as $k => $v ) {
				if ( isset( $a[ $k ] ) && (string) $a[ $k ] !== (string) $v ) {
					$modifie = true;
				}
			}
		}

		ob_start();
		if ( $modifie ) {
			echo ige_ffm_banner( 'ko', 'Données modifiées après la signature', ' Le procès-verbal ne correspond plus à la version enregistrée au registre (' . esc_html( $doc['reference'] ) . '). Signalez-le à l’administrateur.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-doc-wrap">
			<article class="ige-pdf">
				<div class="ige-hd">
					<?php echo function_exists( 'ige_ff_logo' ) ? ige_ff_logo( 'couleur', true, false ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-ref"><?php echo esc_html( IGE_FFM_Arretes::reference( $a ) ); ?><br>Kinshasa, le <?php echo esc_html( ige_ff_date_fr_safe_ts( strtotime( $a['created_at'] ) ) ); ?></div>
				</div>
				<h1 class="ige-doc-title"><?php echo $mens ? 'Procès-verbal d’arrêté de caisse' : 'Procès-verbal de comptage inopiné'; ?></h1>
				<div class="ige-subt"><?php echo esc_html( 'Service ' . $code . ' · ' . ( $mens ? strtolower( $noms[ (int) $a['mois'] ] ) . ' ' . $annee : 'comptage du ' . wp_date( 'd/m/Y', strtotime( $a['created_at'] ) ) . ( ! empty( $d['heure'] ) ? ' à ' . $d['heure'] : '' ) ) . ' · montants en francs congolais · état : ' . $lbl ); ?></div>
				<table class="ige-table ige-num">
					<thead><tr><th>Coupure</th><th class="ige-r">Nombre</th><th class="ige-r">Montant</th></tr></thead>
					<tbody>
					<?php foreach ( $c['CDF'] as $v ) : ?>
						<?php $n = (int) ( $d['cdf'][ $v ] ?? 0 ); ?>
						<?php if ( $n ) : ?><tr><td><?php echo esc_html( ige_ffm_montant( $v, '' ) ); ?> FC</td><td class="ige-r"><?php echo esc_html( $n ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $n * $v, '' ) ); ?></td></tr><?php endif; ?>
					<?php endforeach; ?>
					<tr class="ige-tot"><td>Total en francs congolais</td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) ( $d['total_cdf'] ?? 0 ), '' ) ); ?></td></tr>
					<?php if ( ! empty( $d['total_usd'] ) ) : ?>
						<tr><td>Dollars : <?php echo esc_html( implode( ', ', array_map( static fn( $v, $n ) => $n . ' × ' . $v . ' USD', array_keys( $d['usd'] ), $d['usd'] ) ) ); ?></td><td class="ige-r"><?php echo esc_html( $d['total_usd'] . ' USD × ' . ige_ffm_montant( (int) $d['taux'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $d['contre_valeur_usd'], '' ) ); ?></td></tr>
					<?php endif; ?>
					<?php if ( ! empty( $d['autres'] ) ) : ?>
						<tr><td>Autres disponibilités<?php echo ! empty( $d['autres_detail'] ) ? ' : ' . esc_html( $d['autres_detail'] ) : ''; ?></td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $d['autres'], '' ) ); ?></td></tr>
					<?php endif; ?>
					<tr class="ige-tot"><td>Total compté</td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $a['total_compte'], '' ) ); ?></td></tr>
					<tr><td>Solde du livre de caisse <?php echo $mens ? 'au ' . esc_html( wp_date( 'd/m/Y', strtotime( $date ) ) ) : 'au moment du comptage'; ?></td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $a['solde_theorique'], '' ) ); ?></td></tr>
					<tr class="ige-tot"><td>Écart (compté − livre)</td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $a['ecart'], '' ) ); ?></td></tr>
					</tbody>
				</table>
				<p style="margin-top:16px"><?php echo 0 === (int) $a['ecart'] ? 'La caisse est conforme au livre.' : '<b>Explication de l’écart :</b> ' . esc_html( (string) $a['explication'] ); ?></p>
				<?php if ( $mens ) : ?>
					<p><?php echo 'cloture' === $a['statut'] ? esc_html( 'Le mois de ' . strtolower( $noms[ (int) $a['mois'] ] ) . ' ' . $annee . ' est clôturé : aucune écriture ne peut plus y être ajoutée.' ) : 'Procès-verbal en attente de signature.'; ?></p>
				<?php endif; ?>
				<div class="ige-sign">
					<?php if ( $mens ) : ?>
						<div>L’agent qui a compté la caisse<br><b><?php echo $ag ? esc_html( $ag->display_name ) : '—'; ?></b><br><?php echo $ag ? esc_html( 'Signé dans l’application le ' . wp_date( 'd/m/Y à H:i', strtotime( $a['signe_agent_le'] ) ) ) : 'Non signé'; ?></div>
						<div>Le Responsable du service<br><b><?php echo $rs ? esc_html( $rs->display_name ) : '—'; ?></b><br><?php echo $rs ? esc_html( 'Signé dans l’application le ' . wp_date( 'd/m/Y à H:i', strtotime( $a['signe_resp_le'] ) ) ) : 'Non signé'; ?></div>
					<?php else : ?>
						<div>Le contrôleur (Administration et Finance)<br><b><?php echo $rs ? esc_html( $rs->display_name ) : '—'; ?></b><br><?php echo $rs ? esc_html( 'Constaté le ' . wp_date( 'd/m/Y à H:i', strtotime( $a['signe_resp_le'] ) ) ) : ''; ?></div>
						<div>L’agent présent<br><b><?php echo $pr ? esc_html( $pr->display_name ) : '—'; ?></b><br><?php echo $ag ? esc_html( 'Contresigné dans l’application le ' . wp_date( 'd/m/Y à H:i', strtotime( $a['signe_agent_le'] ) ) ) : 'Contresignature attendue'; ?></div>
					<?php endif; ?>
				</div>
				<div class="ige-ft">
					<?php echo function_exists( 'ige_ff_tri' ) ? ige_ff_tri() : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php if ( $doc ) : ?>
						<div class="ige-qr" data-ige-qr="<?php echo esc_attr( IGE_FFM_Documents::url_verification( $doc['reference'] ) ); ?>" aria-label="Code QR de vérification"></div>
						<div>Document authentifiable : scannez le code ou saisissez la référence <b><?php echo esc_html( $doc['reference'] ); ?></b> sur la page « Vérifier un document ».<br><?php echo esc_html( IGE_FFM_Arretes::reference( $a ) ); ?></div>
					<?php else : ?>
						<div>Procès-verbal établi dans l’application de gestion des frais de fonctionnement · <?php echo esc_html( IGE_FFM_Arretes::reference( $a ) ); ?> · le code QR d’authenticité est attribué à la signature finale.</div>
					<?php endif; ?>
				</div>
			</article>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Saisies du comptage postées.
	 *
	 * @return array
	 */
	public static function saisies() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$num = static function ( $k ) {
			$out = array();
			if ( isset( $_POST[ $k ] ) && is_array( $_POST[ $k ] ) ) {
				foreach ( wp_unslash( $_POST[ $k ] ) as $c => $v ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$out[ (int) $c ] = sanitize_text_field( (string) $v );
				}
			}
			return $out;
		};
		$in = array(
			'cdf'           => $num( 'cdf' ),
			'usd'           => $num( 'usd' ),
			'taux'          => ige_ffm_post( 'taux' ),
			'autres'        => ige_ffm_post( 'autres' ),
			'autres_detail' => ige_ffm_post( 'autres_detail' ),
			'explication'   => isset( $_POST['explication'] ) ? sanitize_textarea_field( wp_unslash( $_POST['explication'] ) ) : '',
			'agent_present' => (int) ige_ffm_post( 'agent_present' ),
		);
		// phpcs:enable
		return $in;
	}

	/**
	 * Enregistrement du comptage.
	 */
	public static function handle_enregistrer() {
		ige_ffm_check_form( 'arrete' );
		ige_ffm_require( 'ige_saisir' );
		$mois = (int) ige_ffm_post( 'mois' );
		$in   = self::saisies();
		$res  = IGE_FFM_Arretes::enregistrer( IGE_FFM_Contexte::service_utilisateur(), $mois, $in );
		if ( is_wp_error( $res ) ) {
			IGE_FFM_Ecrans::remember( $in );
			ige_ffm_flash( 'ko', 'Comptage non enregistré', $res->get_error_message() );
		} else {
			$a = IGE_FFM_Arretes::get( $res );
			ige_ffm_flash( 0 === (int) $a['ecart'] ? 'ok' : 'warn', 0 === (int) $a['ecart'] ? 'Comptage enregistré : caisse conforme' : 'Comptage enregistré : écart de ' . ige_ffm_montant( (int) $a['ecart'] ), 'Signez maintenant l’arrêté.' . ( (int) $a['ecart'] && mb_strlen( (string) $a['explication'] ) < 10 ? ' L’écart doit être expliqué avant la signature.' : '' ) );
		}
		wp_safe_redirect( ige_ffm_url( 'arrete-de-caisse', array( 'mois' => $mois ) ) );
		exit;
	}

	/**
	 * Signature (agent ou responsable).
	 */
	public static function handle_signer() {
		ige_ffm_check_form( 'arrete_signer' );
		ige_ffm_require( 'ige_saisir' );
		$id  = (int) ige_ffm_post( 'arrete' );
		$res = 'responsable' === ige_ffm_post( 'qui' ) ? IGE_FFM_Arretes::signer_responsable( $id ) : IGE_FFM_Arretes::signer_agent( $id );
		$a   = IGE_FFM_Arretes::get( $id );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Signature refusée', $res->get_error_message() );
		} elseif ( 'cloture' === $a['statut'] ) {
			ige_ffm_flash( 'ok', 'Arrêté signé : ' . strtolower( IGE_FFM_Ecran_Livre::mois_noms()[ (int) $a['mois'] ] ) . ' est clôturé', 'Le procès-verbal est disponible ci-dessous.' );
		} else {
			ige_ffm_flash( 'ok', 'Arrêté signé par l’agent', 'Le Responsable du service doit maintenant le contresigner pour clôturer le mois.' );
		}
		wp_safe_redirect( ige_ffm_url( 'arrete-de-caisse', array( 'mois' => $a ? (int) $a['mois'] : '' ) ) );
		exit;
	}

	/**
	 * Contresignature d'un comptage inopiné.
	 */
	public static function handle_contresigner() {
		ige_ffm_check_form( 'inopine_contresigner' );
		ige_ffm_require( 'ige_saisir' );
		$res = IGE_FFM_Arretes::contresigner( (int) ige_ffm_post( 'arrete' ) );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Contresignature refusée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Procès-verbal contresigné', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'arrete-de-caisse' ) );
		exit;
	}
}
