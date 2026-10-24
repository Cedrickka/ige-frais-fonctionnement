<?php
/**
 * Écran Vérification (Administration et Finance) : file des écritures reçues, anomalies,
 * validation en masse ou à l'unité, rejet motivé, panneau de détail, journal des lots reçus.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Vérification.
 */
final class IGE_FFM_Ecran_Verification {

	/**
	 * Traitements et panneau de détail.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_verifier', array( __CLASS__, 'handle_verifier' ) );
		add_action( 'admin_post_ige_ffm_valider_tout', array( __CLASS__, 'handle_valider_tout' ) );
		add_action( 'admin_post_ige_ffm_question', array( __CLASS__, 'handle_question' ) );
		add_action(
			'template_redirect',
			static function () {
				if ( is_page( 'verification' ) && current_user_can( 'ige_verifier' ) ) {
					add_action( 'ige_ff_detail_panel', array( __CLASS__, 'panneau' ) );
				}
			}
		);
	}

	/**
	 * Filtres lus dans l'adresse.
	 *
	 * @return array
	 */
	private static function filtres() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$vue = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : 'a_verifier';
		$f   = array(
			'vue'        => in_array( $vue, array( 'a_verifier', 'anomalies', 'echanges', 'validee', 'rejetee', 'lots' ), true ) ? $vue : 'a_verifier',
			'service_id' => isset( $_GET['service'] ) ? (int) $_GET['service'] : 0,
			'lot_id'     => isset( $_GET['lot'] ) ? (int) $_GET['lot'] : 0,
		);
		// phpcs:enable
		return $f;
	}

	/**
	 * Adresse de l'écran avec des filtres.
	 *
	 * @param array $f Filtres.
	 * @return string
	 */
	private static function url( array $f ) {
		$q = array_filter(
			array(
				'vue'      => 'a_verifier' === ( $f['vue'] ?? '' ) ? '' : ( $f['vue'] ?? '' ),
				'service'  => $f['service_id'] ?? 0,
				'lot'      => $f['lot_id'] ?? 0,
				'ecriture' => $f['ecriture'] ?? 0,
			)
		);
		return ige_ffm_url( 'verification', $q );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$ex = IGE_FFM_Contexte::exercice();
		$args['title']   = 'File de vérification';
		$args['actions'] = '';
		if ( ! $ex ) {
			return $args;
		}
		$f    = self::filtres();
		$syn  = IGE_FFM_Verification::synthese( (int) $ex['id'], $f['service_id'] );
		$lots = self::lots_du_jour( (int) $ex['id'] );
		$args['subtitle'] = sprintf( '%d écriture(s) à vérifier · %d avec anomalie · %d lot(s) reçu(s) aujourd’hui', $syn['a_verifier']['n'], $syn['anomalies']['n'], $lots );
		$sans             = $syn['a_verifier']['n'] - $syn['anomalies']['n'];
		if ( $sans > 0 && ( $f['service_id'] || $f['lot_id'] ) ) {
			$sans = self::compter_sans_anomalie( (int) $ex['id'], $f );
		}
		if ( $sans > 0 && 'lots' !== $f['vue'] ) {
			$args['actions'] = IGE_FFM_Ecrans::action_button(
				'valider_tout',
				array( 'service' => $f['service_id'], 'lot' => $f['lot_id'] ),
				'Valider les ' . $sans . ' sans anomalie',
				'ige-btn ige-btn-ghost',
				'Valider les ' . $sans . ' écriture(s) sans anomalie' . ( $f['service_id'] || $f['lot_id'] ? ' de la sélection' : '' ) . ' ?'
			);
		}
		return $args;
	}

	/**
	 * Lots reçus aujourd'hui.
	 *
	 * @param int $exo Exercice.
	 * @return int
	 */
	private static function lots_du_jour( $exo ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE exercice_id = %d AND envoye_le >= %s', $exo, wp_date( 'Y-m-d' ) . ' 00:00:00' ) );
	}

	/**
	 * Nombre d'écritures à vérifier sans anomalie pour des filtres.
	 *
	 * @param int   $exo Exercice.
	 * @param array $f   Filtres.
	 * @return int
	 */
	private static function compter_sans_anomalie( $exo, array $f ) {
		$file = IGE_FFM_Verification::file( $exo, array( 'vue' => 'a_verifier' ) + $f, 5000 );
		return count( array_filter( $file['rows'], static fn( $e ) => ! IGE_FFM_Anomalies::lire( $e ) && '' === (string) $e['echange'] ) );
	}

	/**
	 * Affichage de la liste.
	 *
	 * @return string
	 */
	public static function render() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$exo  = (int) $ex['id'];
		$f    = self::filtres();
		$syn  = IGE_FFM_Verification::synthese( $exo, $f['service_id'] );
		$svcs = wp_list_pluck( IGE_FFM_Referentiels::all( 'service' ), 'code', 'id' );
		$sel  = isset( $_GET['ecriture'] ) ? (int) $_GET['ecriture'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ech  = IGE_FFM_Echanges::compter( $exo, $f['service_id'] );

		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi ige-alerte"><div class="ige-lbl">À vérifier</div><div class="ige-val"><?php echo esc_html( $syn['a_verifier']['n'] ); ?> <span>écriture(s)</span></div><div class="ige-meta"><?php echo esc_html( 'Sorties : ' . ige_ffm_montant( $syn['a_verifier']['montant'] ) ); ?></div></div>
			<div class="ige-card ige-kpi ige-risque"><div class="ige-lbl">Avec anomalie</div><div class="ige-val"><?php echo esc_html( $syn['anomalies']['n'] ); ?> <span>écriture(s)</span></div><div class="ige-meta">À examiner avant de valider</div></div>
			<div class="ige-card ige-kpi ige-ok"><div class="ige-lbl">Validées</div><div class="ige-val"><?php echo esc_html( $syn['validee']['n'] ); ?> <span>écriture(s)</span></div><div class="ige-meta"><?php echo esc_html( 'Sorties : ' . ige_ffm_montant( $syn['validee']['montant'] ) ); ?></div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Rejetées</div><div class="ige-val"><?php echo esc_html( $syn['rejetee']['n'] ); ?> <span>écriture(s)</span></div><div class="ige-meta"><?php echo esc_html( 'Sorties : ' . ige_ffm_montant( $syn['rejetee']['montant'] ) ); ?></div></div>
		</div>
		<section class="ige-card" style="padding:16px">
			<div class="ige-toolbar">
				<?php
				$chips = array(
					'a_verifier' => 'À vérifier (' . $syn['a_verifier']['n'] . ')',
					'anomalies'  => 'Avec anomalie (' . $syn['anomalies']['n'] . ')',
					'echanges'   => 'Échanges en cours (' . ( $ech['question'] + $ech['reponse'] ) . ( $ech['reponse'] ? ' · ' . $ech['reponse'] . ' réponse(s)' : '' ) . ')',
					'validee'    => 'Validées',
					'rejetee'    => 'Rejetées',
					'lots'       => 'Lots reçus',
				);
				foreach ( $chips as $k => $lbl ) {
					echo '<a class="ige-chip' . ( $f['vue'] === $k ? ' ige-on' : '' ) . '" href="' . esc_url( self::url( array( 'vue' => $k, 'service_id' => $f['service_id'] ) ) ) . '">' . esc_html( $lbl ) . '</a>';
				}
				?>
				<form method="get" action="<?php echo esc_url( ige_ffm_url( 'verification' ) ); ?>" class="ige-row" style="gap:8px;margin-left:auto">
					<?php if ( 'a_verifier' !== $f['vue'] ) : ?><input type="hidden" name="vue" value="<?php echo esc_attr( $f['vue'] ); ?>"><?php endif; ?>
					<label class="screen-reader-text" for="ige-v-service">Service</label>
					<select id="ige-v-service" name="service" class="ige-input" style="width:auto" onchange="this.form.submit()">
						<option value="">Tous les services</option>
						<?php foreach ( $svcs as $id => $code ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"<?php selected( $f['service_id'], (int) $id ); ?>><?php echo esc_html( $code ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $f['lot_id'] ) : ?>
						<a class="ige-chip ige-on" href="<?php echo esc_url( self::url( array( 'vue' => $f['vue'], 'service_id' => $f['service_id'] ) ) ); ?>" title="Retirer le filtre">Lot <?php echo esc_html( self::lot_numero( $f['lot_id'] ) ); ?> ✕</a>
					<?php endif; ?>
					<noscript><button class="ige-btn ige-btn-line" type="submit">Filtrer</button></noscript>
				</form>
			</div>
			<?php
			if ( 'lots' === $f['vue'] ) {
				echo self::journal_lots( $exo, $f, $svcs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			} else {
				echo self::tableau( $exo, $f, $svcs, $sel ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Numéro d'un lot.
	 *
	 * @param int $id Lot.
	 * @return string
	 */
	private static function lot_numero( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT id_transmission FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Tableau des écritures, avec sélection et actions groupées.
	 *
	 * @param int   $exo  Exercice.
	 * @param array $f    Filtres.
	 * @param array $svcs Services.
	 * @param int   $sel  Écriture sélectionnée.
	 * @return string
	 */
	private static function tableau( $exo, array $f, array $svcs, $sel ) {
		$file  = IGE_FFM_Verification::file( $exo, $f );
		$rows  = $file['rows'];
		$rubs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'code', 'id' );
		$fourn = wp_list_pluck( IGE_FFM_Fournisseurs::all( false ), 'raison_sociale', 'id' );
		$actif = in_array( $f['vue'], array( 'a_verifier', 'anomalies', 'echanges' ), true );

		ob_start();
		if ( $actif ) {
			echo IGE_FFM_Ecrans::form_open( 'verifier', 'id="ige-verif-form"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<?php if ( $actif && $rows ) : ?>
			<div class="ige-toolbar ige-verif-actions">
				<label class="screen-reader-text" for="ige-motif-lot">Motif du rejet</label>
				<input type="text" id="ige-motif-lot" name="motif" class="ige-input" style="flex:1;min-width:220px" maxlength="200" placeholder="Motif du rejet (obligatoire pour rejeter)">
				<button type="submit" name="decision" value="rejeter" class="ige-btn ige-btn-rouge" data-ige-sel>Rejeter</button>
				<button type="submit" name="decision" value="valider" class="ige-btn ige-btn-jaune" data-ige-sel>Valider la sélection</button>
			</div>
		<?php endif; ?>
		<div class="ige-table-wrap"><table class="ige-table ige-num">
			<thead><tr>
				<?php if ( $actif ) : ?><th><input type="checkbox" id="ige-tout" aria-label="Tout sélectionner"></th><?php endif; ?>
				<th>ID écriture</th><th>Date</th><th>Service</th><th>Rubrique</th><th>Bénéficiaire</th><th class="ige-r">Montant</th><th><?php echo $actif ? 'Contrôles' : ( 'rejetee' === $f['vue'] ? 'Motif' : 'Validée le' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $e ) : ?>
				<?php
				$a    = IGE_FFM_Anomalies::lire( $e );
				$lien = self::url( $f + array( 'ecriture' => (int) $e['id'] ) );
				$maj  = 1 < self::versions( $e );
				?>
				<tr<?php echo $sel === (int) $e['id'] ? ' class="ige-sel"' : ''; ?>>
					<?php if ( $actif ) : ?><td><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $e['id'] ); ?>" aria-label="<?php echo esc_attr( 'Sélectionner ' . $e['id_ecriture'] ); ?>"></td><?php endif; ?>
					<td><a href="<?php echo esc_url( $lien ); ?>"><b><?php echo esc_html( $e['id_ecriture'] ); ?></b></a></td>
					<td><?php echo esc_html( wp_date( 'd/m', strtotime( $e['date_operation'] ) ) ); ?></td>
					<td><?php echo esc_html( $svcs[ $e['service_id'] ] ?? '' ); ?></td>
					<td><?php echo esc_html( 'entree' === $e['type'] ? 'Entrée' : ( $rubs[ $e['rubrique_id'] ] ?? '—' ) ); ?></td>
					<td><?php echo esc_html( $e['fournisseur_id'] ? ( $fourn[ $e['fournisseur_id'] ] ?? '' ) : $e['beneficiaire'] ); ?></td>
					<td class="ige-r"<?php echo 'entree' === $e['type'] ? ' style="color:var(--ige-ok-txt)"' : ''; ?>><?php echo esc_html( ( 'entree' === $e['type'] ? '+ ' : '' ) . ige_ffm_montant( (int) $e['montant'], '' ) ); ?></td>
					<td>
						<?php
						if ( $actif ) {
							if ( 'question' === $e['echange'] ) {
								echo ige_ffm_pill( 'Question posée', 'wait' ) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( 'reponse' === $e['echange'] ) {
								echo ige_ffm_pill( 'Réponse reçue', 'info' ) . '<br>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							if ( $e['ecriture_origine_id'] ) {
								echo ige_ffm_pill( 'Contrepassation', 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} elseif ( $maj && ! $a ) {
								echo ige_ffm_pill( 'payee' === $e['statut'] && $e['date_paiement'] && $e['date_paiement'] !== $e['date_operation'] ? 'Engagement payé (mise à jour)' : 'Mise à jour', 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								echo $a ? IGE_FFM_Anomalies::pastilles( $a ) : ige_ffm_pill( 'Dossier complet', 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
						} elseif ( 'rejetee' === $f['vue'] ) {
							echo '<span class="ige-anom">' . esc_html( $e['motif_rejet'] ) . '</span>';
						} else {
							echo esc_html( $e['valide_le'] ? wp_date( 'd/m/Y', strtotime( $e['valide_le'] ) ) : '' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="8" class="ige-empty"><?php echo $actif ? 'Rien à vérifier pour cette sélection.' : 'Aucune écriture.'; ?></td></tr>
			<?php endif; ?>
			</tbody>
		</table></div>
		<?php if ( $actif ) : ?>
			</form>
		<?php endif; ?>
		<p class="ige-small ige-muted" style="margin:12px 0 0"><?php echo esc_html( count( $rows ) . ' sur ' . $file['total'] . ' affichée(s)' ); ?></p>
		<?php
		return ob_get_clean();
	}

	/**
	 * Nombre de transmissions d'une écriture (1 = nouvelle, plus = mise à jour).
	 *
	 * @param array $e Écriture.
	 * @return int
	 */
	private static function versions( array $e ) {
		return max( 1, (int) ( $e['nb_transmissions'] ?? 1 ) );
	}

	/**
	 * Journal des lots reçus (EF-64).
	 *
	 * @param int   $exo  Exercice.
	 * @param array $f    Filtres.
	 * @param array $svcs Services.
	 * @return string
	 */
	private static function journal_lots( $exo, array $f, array $svcs ) {
		$lots = IGE_FFM_Transmission::lots( $exo, $f['service_id'] ?: null, 200 );
		ob_start();
		?>
		<div class="ige-table-wrap"><table class="ige-table ige-num">
			<thead><tr><th>Lot</th><th>Service</th><th>Reçu le</th><th>Envoyé par</th><th class="ige-r">Lignes</th><th class="ige-r">Entrées</th><th class="ige-r">Sorties</th><th>État</th></tr></thead>
			<tbody>
			<?php foreach ( $lots as $l ) : ?>
				<?php
				list( $lbl, $tone ) = IGE_FFM_Transmission::etat_lot( $l );
				$u                  = get_userdata( (int) $l['envoye_par'] );
				?>
				<tr>
					<td><a href="<?php echo esc_url( self::url( array( 'vue' => 'a_verifier', 'lot_id' => (int) $l['id'] ) ) ); ?>"><b><?php echo esc_html( $l['id_transmission'] ); ?></b></a></td>
					<td><?php echo esc_html( $svcs[ $l['service_id'] ] ?? '' ); ?></td>
					<td><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $l['envoye_le'] ) ) ); ?></td>
					<td><?php echo esc_html( $u ? $u->display_name : '—' ); ?></td>
					<td class="ige-r"><?php echo esc_html( $l['nb_lignes'] . ( (int) $l['nb_mises_a_jour'] ? ' (' . $l['nb_mises_a_jour'] . ' m. à j.)' : '' ) ); ?></td>
					<td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $l['total_entrees'], '' ) ); ?></td>
					<td class="ige-r"><?php echo esc_html( ige_ffm_montant( (int) $l['total_sorties'], '' ) ); ?></td>
					<td><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( ! $lots ) : ?>
				<tr><td colspan="8" class="ige-empty">Aucun lot reçu sur l’exercice.</td></tr>
			<?php endif; ?>
			</tbody>
		</table></div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Panneau de détail de l'écriture sélectionnée.
	 */
	public static function panneau() {
		$id = isset( $_GET['ecriture'] ) ? (int) $_GET['ecriture'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$e  = $id ? IGE_FFM_Ecritures::get( $id ) : null;
		if ( ! $e || 'non_transmise' === $e['etat_transmission'] ) {
			echo '<h2>Détail</h2><p class="ige-small ige-muted" style="margin:0">Cliquez sur l’identifiant d’une écriture pour voir son dossier : pièces, contrôles, disponible du service, puis la valider ou la rejeter.</p>';
			return;
		}
		list( $lbl, $tone ) = IGE_FFM_Ecritures::etat( $e );
		$r      = $e['rubrique_id'] ? IGE_FFM_Referentiels::get( 'rubrique', (int) $e['rubrique_id'] ) : null;
		$f      = $e['fournisseur_id'] ? IGE_FFM_Fournisseurs::get( (int) $e['fournisseur_id'] ) : null;
		$mode   = IGE_FFM_Referentiels::get( 'mode_paiement', (int) $e['mode_paiement_id'] );
		$auteur = get_userdata( (int) $e['saisi_par'] );
		$valid  = $e['valide_par'] ? get_userdata( (int) $e['valide_par'] ) : null;
		$a      = IGE_FFM_Anomalies::lire( $e );
		$lib    = IGE_FFM_Anomalies::libelles();
		$pieces = IGE_FFM_Pieces::liste( 'ecriture', $id );
		$orig   = $e['ecriture_origine_id'] ? IGE_FFM_Ecritures::get( (int) $e['ecriture_origine_id'] ) : null;
		$cp     = IGE_FFM_Ecritures::contrepassation_de( $id );
		$dispo  = $r ? IGE_FFM_Budget::disponible( (int) $e['exercice_id'], (int) $e['service_id'], (int) $r['id'] ) : null;
		$retour = self::url( self::filtres() );
		?>
		<h2><?php echo esc_html( $e['id_ecriture'] ); ?> <span style="margin-left:auto"><?php echo ige_ffm_pill( $lbl, $tone ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></h2>
		<dl class="ige-num">
			<dt>Service</dt><dd><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $e['service_id'] ) ); ?></dd>
			<dt>Date</dt><dd><?php echo esc_html( ige_ff_date_fr_safe_ts( strtotime( $e['date_operation'] ) ) ); ?></dd>
			<dt>Type</dt><dd><?php echo esc_html( ( 'entree' === $e['type'] ? 'Entrée' : 'Sortie' ) . ' · ' . ( 'engagee' === $e['statut'] ? 'engagée' : 'payée' ) . ( $mode ? ' · ' . $mode['libelle'] : '' ) ); ?></dd>
			<dt>Rubrique</dt><dd><?php echo esc_html( $r ? $r['code'] . ' ' . $r['libelle'] : '—' ); ?></dd>
			<dt>Description</dt><dd><?php echo esc_html( $e['description'] ); ?></dd>
			<dt>Bénéficiaire</dt><dd><?php echo esc_html( $f ? $f['raison_sociale'] : ( $e['beneficiaire'] ?: '—' ) ); ?></dd>
			<dt>Pièce n°</dt><dd><?php echo esc_html( $e['num_piece'] ?: '—' ); ?></dd>
			<dt>Montant</dt><dd><b><?php echo esc_html( ige_ffm_montant( (int) $e['montant'] ) ); ?></b><?php echo IGE_FFM_Devises::mention( $e ) ? ' <span class="ige-small ige-muted">(' . esc_html( IGE_FFM_Devises::mention( $e ) ) . ')</span>' : ''; ?></dd>
			<?php if ( null !== $dispo ) : ?><dt>Disponible</dt><dd<?php echo $dispo < 0 ? ' style="color:var(--ige-rouge)"' : ''; ?>><?php echo esc_html( ige_ffm_montant( $dispo ) . ' sur la rubrique' ); ?></dd><?php endif; ?>
			<dt>Saisi par</dt><dd><?php echo esc_html( ( $auteur ? $auteur->display_name : '—' ) . ' · ' . wp_date( 'd/m H:i', strtotime( $e['saisi_le'] ) ) ); ?></dd>
			<?php if ( $orig ) : ?><dt>Annule</dt><dd><a href="<?php echo esc_url( self::url( array( 'vue' => 'validee', 'ecriture' => (int) $orig['id'] ) ) ); ?>"><?php echo esc_html( $orig['id_ecriture'] ); ?></a></dd><?php endif; ?>
			<?php if ( $cp ) : ?><dt>Contrepassée par</dt><dd><?php echo esc_html( $cp['id_ecriture'] ); ?></dd><?php endif; ?>
			<?php if ( $valid ) : ?><dt><?php echo 'rejetee' === $e['etat_verification'] ? 'Rejetée par' : 'Validée par'; ?></dt><dd><?php echo esc_html( $valid->display_name . ' · ' . wp_date( 'd/m H:i', strtotime( $e['valide_le'] ) ) ); ?></dd><?php endif; ?>
			<?php if ( $e['motif_rejet'] ) : ?><dt>Motif</dt><dd style="color:var(--ige-ko-txt)"><?php echo esc_html( $e['motif_rejet'] ); ?></dd><?php endif; ?>
		</dl>

		<?php if ( $pieces ) : ?>
			<div class="ige-pieces">
			<?php foreach ( $pieces as $p ) : ?>
				<a href="<?php echo esc_url( IGE_FFM_Pieces::url( $p ) ); ?>" target="_blank" rel="noopener" class="ige-piece-vue">
					<?php if ( 0 === strpos( $p['type_mime'], 'image/' ) ) : ?>
						<img src="<?php echo esc_url( IGE_FFM_Pieces::url( $p ) ); ?>" alt="<?php echo esc_attr( 'Pièce ' . $p['nom_original'] ); ?>" loading="lazy">
					<?php else : ?>
						<span>PDF · <?php echo esc_html( $p['nom_original'] ); ?></span>
					<?php endif; ?>
				</a>
			<?php endforeach; ?>
			</div>
		<?php elseif ( 'sortie' === $e['type'] && ! $orig ) : ?>
			<div class="ige-piece">Aucune pièce jointe</div>
		<?php endif; ?>

		<?php $dos = IGE_FFM_Pieces::etat_dossier( $e ); ?>
		<?php if ( $dos['exigees'] ) : ?>
			<p class="ige-small" style="margin:12px 0 4px;font-weight:600">Pièces exigées pour <?php echo esc_html( $r['code'] ); ?> <?php echo $dos['complet'] ? ige_ffm_pill( 'Dossier complet', 'ok' ) : ige_ffm_pill( 'Dossier incomplet', 'ko' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php foreach ( $dos['presentes'] as $pe => $ok ) : ?>
				<div class="ige-check"><?php echo $ok ? '<span class="ige-ok">✓</span>' : '<span class="ige-ko">✕</span>'; ?><?php echo esc_html( $pe ); ?></div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $a ) : ?>
			<p class="ige-small" style="margin:12px 0 4px;font-weight:600">Anomalies détectées à la réception</p>
			<?php foreach ( $a as $code => $detail ) : ?>
				<div class="ige-check" style="align-items:flex-start"><span class="ige-ko">✕</span><span><b><?php echo esc_html( $lib[ $code ] ?? $code ); ?></b> — <?php echo esc_html( $detail ); ?></span></div>
			<?php endforeach; ?>
		<?php elseif ( 'a_verifier' === $e['etat_verification'] ) : ?>
			<div class="ige-check"><span class="ige-ok">✓</span>Aucune anomalie détectée à la réception</div>
		<?php endif; ?>

		<?php echo self::echanges( $e, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( 'transmise' === $e['etat_transmission'] && 'a_verifier' === $e['etat_verification'] ) : ?>
			<?php if ( (int) $e['saisi_par'] === get_current_user_id() ) : ?>
				<?php echo ige_ffm_banner( 'warn', 'Vous avez saisi cette écriture', ' Un autre vérificateur doit la traiter.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<?php echo IGE_FFM_Ecrans::form_open( 'verifier', 'style="margin-top:14px"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<input type="hidden" name="ids[]" value="<?php echo esc_attr( $id ); ?>">
					<input type="hidden" name="retour" value="<?php echo esc_attr( $retour ); ?>">
					<label class="screen-reader-text" for="ige-motif-un">Motif du rejet</label>
					<input type="text" id="ige-motif-un" name="motif" class="ige-input" maxlength="200" placeholder="Motif (obligatoire pour rejeter)">
					<div style="display:flex;gap:8px;margin-top:10px">
						<button type="submit" name="decision" value="rejeter" class="ige-btn ige-btn-rouge" style="flex:1">Rejeter</button>
						<button type="submit" name="decision" value="valider" class="ige-btn ige-btn-jaune" style="flex:1">Valider</button>
					</div>
				</form>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Fil des échanges d'une écriture et formulaire de question.
	 *
	 * @param array  $e      Écriture.
	 * @param string $retour Adresse de retour.
	 * @return string
	 */
	private static function echanges( array $e, $retour ) {
		$fil = IGE_FFM_Echanges::fil( (int) $e['id'] );
		$h   = '';
		if ( $fil ) {
			$h .= '<p class="ige-small" style="margin:14px 0 6px;font-weight:600">Échanges avec le service</p>' . self::fil_html( $fil );
		}
		if ( 'transmise' === $e['etat_transmission'] && 'a_verifier' === $e['etat_verification'] && current_user_can( 'ige_verifier' ) ) {
			$h .= IGE_FFM_Ecrans::form_open( 'question', 'style="margin-top:12px"' )
				. '<input type="hidden" name="ecriture" value="' . esc_attr( $e['id'] ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
				. '<label for="ige-question" class="ige-small" style="font-weight:600;display:block;margin-bottom:4px">' . ( 'question' === $e['echange'] ? 'Compléter la question' : 'Poser une question au service (sans rejeter)' ) . '</label>'
				. '<textarea id="ige-question" name="message" class="ige-input" rows="2" maxlength="2000" required minlength="5" placeholder="Ex. : joignez le bon de réception signé ; précisez le nombre de ramettes."></textarea>'
				. '<button type="submit" class="ige-btn ige-btn-line" style="margin-top:6px">Envoyer la question</button></form>';
		}
		return $h;
	}

	/**
	 * Messages d'un échange.
	 *
	 * @param array[] $fil Messages.
	 * @return string
	 */
	public static function fil_html( array $fil ) {
		$h = '<div class="ige-fil">';
		foreach ( $fil as $m ) {
			$u     = get_userdata( (int) $m['user_id'] );
			$q     = 'question' === $m['sens'];
			$piece = $m['piece_id'] ? IGE_FFM_Pieces::get( (int) $m['piece_id'] ) : null;
			$h    .= '<div class="ige-msg' . ( $q ? ' ige-msg-q' : ' ige-msg-r' ) . '"><div class="ige-small ige-muted">' . esc_html( ( $q ? 'Administration et Finance · ' : 'Service · ' ) . ( $u ? $u->display_name : '' ) . ' · ' . wp_date( 'd/m/Y H:i', strtotime( $m['created_at'] ) ) ) . '</div>'
				. '<div>' . nl2br( esc_html( $m['message'] ) ) . '</div>'
				. ( $piece ? '<div class="ige-small">Pièce jointe : <a href="' . esc_url( IGE_FFM_Pieces::url( $piece ) ) . '" target="_blank" rel="noopener">' . esc_html( ( $piece['nature'] ? $piece['nature'] . ' · ' : '' ) . $piece['nom_original'] ) . '</a></div>' : '' )
				. '</div>';
		}
		return $h . '</div>';
	}

	/**
	 * Question au service.
	 */
	public static function handle_question() {
		ige_ffm_check_form( 'question' );
		ige_ffm_require( 'ige_verifier' );
		$id  = (int) ige_ffm_post( 'ecriture' );
		$msg = isset( $_POST['message'] ) ? wp_unslash( $_POST['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$res = IGE_FFM_Echanges::poser( $id, $msg );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Question non envoyée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Question envoyée au service', 'L’écriture reste à vérifier ; vous serez prévenu de la réponse.' );
		}
		$retour = ige_ffm_post( 'retour' );
		$url    = $retour && 0 === strpos( $retour, home_url() ) ? $retour : ige_ffm_url( 'verification' );
		wp_safe_redirect( add_query_arg( 'ecriture', $id, $url ) );
		exit;
	}

	/**
	 * Validation ou rejet (sélection ou écriture seule).
	 */
	public static function handle_verifier() {
		ige_ffm_check_form( 'verifier' );
		ige_ffm_require( 'ige_verifier' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids      = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : array();
		$decision = ige_ffm_post( 'decision' );
		$retour   = ige_ffm_post( 'retour' );
		if ( ! $ids ) {
			ige_ffm_flash( 'warn', 'Aucune écriture sélectionnée', 'Cochez les écritures à traiter.' );
		} else {
			$res = 'rejeter' === $decision ? IGE_FFM_Verification::rejeter( $ids, ige_ffm_post( 'motif' ) ) : IGE_FFM_Verification::valider( $ids );
			if ( is_wp_error( $res ) ) {
				ige_ffm_flash( 'ko', 'rejeter' === $decision ? 'Rejet refusé' : 'Validation refusée', $res->get_error_message() );
			} else {
				$txt = $res['refus'] ? 'Non traitées : ' . implode( ', ', $res['refus'] ) . '.' : '';
				ige_ffm_flash( $res['n'] ? 'ok' : 'warn', sprintf( 'rejeter' === $decision ? '%d écriture(s) rejetée(s)' : '%d écriture(s) validée(s)', $res['n'] ), $txt . ( 'rejeter' === $decision && $res['n'] ? ' Le service est prévenu.' : '' ) );
			}
		}
		if ( $retour && 0 === strpos( $retour, home_url() ) ) {
			wp_safe_redirect( remove_query_arg( 'ecriture', $retour ) );
			exit;
		}
		ige_ffm_back( ige_ffm_url( 'verification' ) );
	}

	/**
	 * Validation de toutes les écritures sans anomalie.
	 */
	public static function handle_valider_tout() {
		ige_ffm_check_form( 'valider_tout' );
		ige_ffm_require( 'ige_verifier' );
		$ex  = IGE_FFM_Contexte::exercice();
		$res = $ex ? IGE_FFM_Verification::valider_sans_anomalie( (int) $ex['id'], array( 'service_id' => (int) ige_ffm_post( 'service' ), 'lot_id' => (int) ige_ffm_post( 'lot' ) ) ) : new WP_Error( 'exercice', 'Aucun exercice ouvert.' );
		if ( is_wp_error( $res ) ) {
			ige_ffm_flash( 'ko', 'Validation refusée', $res->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', sprintf( '%d écriture(s) sans anomalie validée(s)', $res['n'] ), $res['refus'] ? 'Non traitées : ' . implode( ', ', $res['refus'] ) . '.' : 'Les écritures signalées restent à examiner.' );
		}
		ige_ffm_back( ige_ffm_url( 'verification' ) );
	}
}

/**
 * Date en toutes lettres pour un horodatage (fonction du thème si présente).
 *
 * @param int $ts Horodatage.
 * @return string
 */
function ige_ff_date_fr_safe_ts( $ts ) {
	return function_exists( 'ige_ff_date_fr' ) ? ige_ff_date_fr( $ts ) : wp_date( 'd/m/Y', $ts );
}
