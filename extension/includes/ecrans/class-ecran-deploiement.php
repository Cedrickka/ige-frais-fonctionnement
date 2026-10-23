<?php
/**
 * Écran Administration > Déploiement : préparation de chaque service (comptes, invitations,
 * reprise des fichiers Excel, budget) et suivi de la mise en service.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran du déploiement.
 */
final class IGE_FFM_Ecran_Deploiement {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		foreach ( array( 'csv_analyser', 'csv_creer', 'reprise_central', 'reprise_analyser', 'reprise_importer', 'inviter', 'inviter_service', 'deploiement_annuler' ) as $a ) {
			add_action( 'admin_post_ige_ffm_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Déploiement';
		$args['subtitle'] = 'Comptes, invitations et reprise des fichiers Excel, service par service';
		return $args;
	}

	/**
	 * Clé de l'analyse en attente.
	 *
	 * @param string $quoi csv|reprise.
	 * @return string
	 */
	private static function cle( $quoi ) {
		return 'ige_ffm_dep_' . $quoi . '_' . get_current_user_id();
	}

	/**
	 * Fichier téléversé (chemin temporaire) après contrôle de l'extension.
	 *
	 * @param string[] $ext Extensions admises.
	 * @return string|WP_Error
	 */
	private static function fichier( array $ext ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$f = $_FILES['fichier'] ?? null;
		if ( ! $f || UPLOAD_ERR_OK !== (int) $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return new WP_Error( 'fichier', 'Choisissez un fichier.' );
		}
		$e = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
		// phpcs:enable
		if ( ! in_array( $e, $ext, true ) ) {
			return new WP_Error( 'fichier', 'Format attendu : ' . implode( ', ', array_map( static fn( $x ) => '.' . $x, $ext ) ) . '.' );
		}
		return $f['tmp_name'];
	}

	/**
	 * Préparation des services.
	 *
	 * @return array[]
	 */
	public static function etat_services() {
		$ex    = IGE_FFM_Contexte::exercice();
		$exo   = $ex ? (int) $ex['id'] : 0;
		$rep   = $exo ? IGE_FFM_Reprise::par_service( $exo ) : array();
		$out   = array();
		$comptes = IGE_FFM_Comptes::search( array( 'actif' => 1 ) );
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$natives = $exo ? wp_list_pluck( $wpdb->get_results( $wpdb->prepare( "SELECT service_id, COUNT(*) AS n FROM $t WHERE exercice_id = %d AND origine <> 'excel' GROUP BY service_id", $exo ), ARRAY_A ), 'n', 'service_id' ) : array();
		foreach ( IGE_FFM_Referentiels::all( 'service', true ) as $s ) {
			$sid = (int) $s['id'];
			$cs  = array_filter( $comptes, static fn( $c ) => (int) $c['service_id'] === $sid );
			$r   = array(
				'service'      => $s,
				'comptes'      => count( $cs ),
				'responsable'  => (bool) array_filter( $cs, static fn( $c ) => 'ige_responsable' === $c['profil'] ),
				'sans_email'   => count( array_filter( $cs, static fn( $c ) => ! IGE_FFM_Messagerie::adresse_valide( get_userdata( (int) $c['user_id'] ) ) ) ),
				'a_inviter'    => count( array_filter( $cs, static fn( $c ) => (int) $c['mdp_a_changer'] && ! IGE_FFM_Invitations::invite_le( (int) $c['user_id'] ) && IGE_FFM_Messagerie::adresse_valide( get_userdata( (int) $c['user_id'] ) ) ) ),
				'actives'      => count( array_filter( $cs, static fn( $c ) => ! (int) $c['mdp_a_changer'] ) ),
				'reprise'      => (int) ( $rep[ $sid ] ?? 0 ),
				'budget'       => $exo ? count( array_filter( IGE_FFM_Budget::lignes( $exo, $sid ) ) ) : 0,
				'ecritures'    => (int) ( $natives[ $sid ] ?? 0 ),
			);
			if ( $r['ecritures'] ) {
				$r['etat'] = array( 'En service', 'ok' );
			} elseif ( $r['responsable'] && $r['actives'] && ! $r['sans_email'] && $r['budget'] ) {
				$r['etat'] = array( 'Prêt', 'info' );
			} elseif ( $r['comptes'] ) {
				$r['etat'] = array( 'En préparation', 'wait' );
			} else {
				$r['etat'] = array( 'À préparer', 'neutre' );
			}
			$out[] = $r;
		}
		return $out;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$ex      = IGE_FFM_Contexte::exercice();
		$etats   = self::etat_services();
		$smtp    = IGE_FFM_Messagerie::reglages();
		$csv     = get_transient( self::cle( 'csv' ) );
		$rep     = get_transient( self::cle( 'reprise' ) );
		$central = IGE_FFM_Reprise::central();
		$compte  = array_count_values( array_map( static fn( $e ) => $e['etat'][0], $etats ) );

		ob_start();
		if ( ! $smtp['actif'] ) {
			echo ige_ffm_banner( 'warn', 'Messagerie non configurée', ' Les invitations et les codes de connexion par e-mail ne peuvent pas partir. Renseignez la boîte de la plateforme dans <a href="' . esc_url( ige_ffm_url( 'parametres' ) . '#ige-messagerie' ) . '">Paramètres › Messagerie</a>.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-kpis" style="margin-bottom:16px">
			<?php foreach ( array( 'En service' => 'ige-ok', 'Prêt' => 'ige-accent', 'En préparation' => '', 'À préparer' => '' ) as $l => $c ) : ?>
				<div class="ige-card ige-kpi <?php echo esc_attr( $c ); ?>"><div class="ige-lbl"><?php echo esc_html( $l ); ?></div><div class="ige-val ige-num"><?php echo (int) ( $compte[ $l ] ?? 0 ); ?></div><div class="ige-meta">service(s) sur <?php echo count( $etats ); ?></div></div>
			<?php endforeach; ?>
		</div>

		<section class="ige-card" style="padding:16px;margin-bottom:16px">
			<h2>Préparation des services</h2>
			<div class="ige-table-wrap">
				<table class="ige-table">
					<thead><tr><th>Service</th><th class="ige-num">Comptes</th><th>Responsable</th><th class="ige-num">Sans e-mail</th><th class="ige-num">Activés</th><th class="ige-num">Écritures reprises</th><th class="ige-num">Lignes de budget</th><th class="ige-num">Écritures saisies</th><th>État</th><th>Action</th></tr></thead>
					<tbody>
					<?php foreach ( $etats as $e ) : ?>
						<tr>
							<td><b><?php echo esc_html( $e['service']['code'] ); ?></b><?php echo $e['service']['libelle'] !== $e['service']['code'] ? '<br><span class="ige-small ige-muted">' . esc_html( $e['service']['libelle'] ) . '</span>' : ''; ?></td>
							<td class="ige-num"><?php echo (int) $e['comptes']; ?></td>
							<td><?php echo $e['responsable'] ? '✓' : '<span class="ige-neg">Aucun</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td class="ige-num<?php echo $e['sans_email'] ? ' ige-neg' : ''; ?>"><?php echo (int) $e['sans_email']; ?></td>
							<td class="ige-num"><?php echo (int) $e['actives'] . ' / ' . (int) $e['comptes']; ?></td>
							<td class="ige-num"><?php echo (int) $e['reprise']; ?></td>
							<td class="ige-num"><?php echo (int) $e['budget']; ?></td>
							<td class="ige-num"><?php echo (int) $e['ecritures']; ?></td>
							<td><?php echo ige_ffm_pill( $e['etat'][0], $e['etat'][1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo $e['a_inviter'] ? IGE_FFM_Ecrans::action_button( 'inviter_service', array( 'service_id' => $e['service']['id'] ), 'Inviter (' . $e['a_inviter'] . ')', 'ige-act', 'Envoyer l’invitation aux ' . $e['a_inviter'] . ' compte(s) du service ' . $e['service']['code'] . ' pas encore invités ?' ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="ige-small ige-muted" style="margin:12px 0 0">« Prêt » : un Responsable, au moins un compte activé, toutes les adresses e-mail renseignées et un budget saisi. « En service » : le service a saisi au moins une écriture dans l’application. « Inviter » envoie le lien d’activation aux comptes qui n’ont jamais été invités ; pour renvoyer une invitation, passez par Comptes utilisateurs.</p>
		</section>

		<div class="ige-grid ige-adm-split">
			<section class="ige-card">
				<h2>1. Créer les comptes en nombre</h2>
				<?php if ( $csv ) : ?>
					<?php echo self::apercu_csv( $csv ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<p class="ige-small">Fichier CSV (séparateur point-virgule), une ligne par personne, avec en-tête :</p>
					<pre class="ige-small" style="background:var(--ige-fond,#f4f6fa);padding:8px;border-radius:6px;overflow:auto">identifiant;nom;email;profil;service
j.mbuyi;Jean Mbuyi;j.mbuyi@exemple.cd;RESPONSABLE;8000 IG
agent8000b;Marie Kabila;m.kabila@exemple.cd;AGENT;8000 IG</pre>
					<p class="ige-small ige-muted">Profils : AGENT, RESPONSABLE, ADMIN (Administration et Finance), LECTEUR (Direction). Le service est son code. L’adresse e-mail est obligatoire : elle reçoit l’invitation puis les codes de connexion.</p>
					<?php echo IGE_FFM_Ecrans::form_open( 'csv_analyser', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><label for="ige-csv">Fichier des comptes (.csv)</label><input type="file" id="ige-csv" name="fichier" accept=".csv,text/csv" required></div>
						<button type="submit" class="ige-btn ige-btn-jaune">Analyser le fichier</button>
					</form>
				<?php endif; ?>
			</section>

			<section class="ige-card">
				<h2>2. Reprendre les fichiers Excel</h2>
				<?php if ( $rep ) : ?>
					<?php echo self::apercu_reprise( $rep ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<p class="ige-small"><b>a. Classeur central (facultatif, une fois).</b> Il donne l’état de vérification (validée, rejetée, à vérifier) des écritures déjà transmises.
					<?php if ( $central ) : ?>
						<br><?php echo ige_ffm_pill( 'Chargé le ' . wp_date( 'd/m/Y H:i', strtotime( $central['charge_le'] ) ), 'ok' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html( sprintf( '%d écritures : %d validées, %d rejetées, %d à vérifier.', $central['resume']['nb'], $central['resume']['validee'], $central['resume']['rejetee'], $central['resume']['a_verifier'] ) ); ?>
					<?php endif; ?>
					</p>
					<?php echo IGE_FFM_Ecrans::form_open( 'reprise_central', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><label for="ige-central">IGE_FF_CENTRAL (.xlsx ou .xlsm)</label><input type="file" id="ige-central" name="fichier" accept=".xlsx,.xlsm" required></div>
						<button type="submit" class="ige-btn ige-btn-line"><?php echo $central ? 'Recharger le classeur central' : 'Charger le classeur central'; ?></button>
					</form>
					<hr style="margin:16px 0;border:0;border-top:1px solid var(--ige-bord,#e5e7eb)">
					<p class="ige-small"><b>b. Fichier du service.</b> Solde initial, budget et livre de caisse de l’exercice <?php echo esc_html( $ex ? $ex['annee'] : '' ); ?>. À faire avant que le service commence à saisir dans l’application.</p>
					<?php echo IGE_FFM_Ecrans::form_open( 'reprise_analyser', 'enctype="multipart/form-data"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div class="ige-field"><label for="ige-service-x">SERVICE_….xlsm</label><input type="file" id="ige-service-x" name="fichier" accept=".xlsx,.xlsm" required></div>
						<button type="submit" class="ige-btn ige-btn-jaune">Analyser le fichier</button>
					</form>
					<p class="ige-small ige-muted" style="margin-top:12px">Les macros des fichiers ne sont jamais exécutées. Rien n’est enregistré avant votre confirmation. Une écriture déjà reprise n’est jamais importée deux fois.</p>
				<?php endif; ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Aperçu d'un import de comptes.
	 *
	 * @param array $a Analyse.
	 * @return string
	 */
	private static function apercu_csv( array $a ) {
		$h = '<p>' . esc_html( sprintf( '%d compte(s) prêt(s) à créer, %d ligne(s) écartée(s).', count( $a['lignes'] ), count( $a['erreurs'] ) ) ) . '</p>';
		if ( $a['lignes'] ) {
			$h .= '<div class="ige-table-wrap" style="max-height:320px;overflow:auto"><table class="ige-table"><thead><tr><th>Identifiant</th><th>Nom</th><th>E-mail</th><th>Profil</th><th>Service</th></tr></thead><tbody>';
			foreach ( $a['lignes'] as $l ) {
				$h .= '<tr><td>' . esc_html( $l['user_login'] ) . '</td><td>' . esc_html( $l['display_name'] ) . '</td><td>' . esc_html( $l['user_email'] ) . '</td><td>' . esc_html( IGE_FFM_Roles::libelle( $l['profil'] ) ) . '</td><td>' . esc_html( IGE_FFM_Comptes::service_code( $l['service_id'] ) ?: '—' ) . '</td></tr>';
			}
			$h .= '</tbody></table></div>';
		}
		$h .= self::liste( $a['erreurs'], 'Lignes écartées', 'ko' );
		$h .= '<div class="ige-row" style="margin-top:12px">';
		if ( $a['lignes'] ) {
			$h .= IGE_FFM_Ecrans::form_open( 'csv_creer', 'style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"' );
			$h .= '<label class="ige-small" style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="inviter" value="1" checked style="width:auto"> Envoyer l’invitation par e-mail</label>';
			$h .= '<button type="submit" class="ige-btn ige-btn-jaune">' . esc_html( sprintf( 'Créer les %d compte(s)', count( $a['lignes'] ) ) ) . '</button></form>';
		}
		$h .= IGE_FFM_Ecrans::action_button( 'deploiement_annuler', array( 'quoi' => 'csv' ), 'Annuler', 'ige-btn ige-btn-line' );
		return $h . '</div>';
	}

	/**
	 * Aperçu d'une reprise.
	 *
	 * @param array $a Analyse.
	 * @return string
	 */
	private static function apercu_reprise( array $a ) {
		$t  = $a['totaux'];
		$h  = '<p><b>' . esc_html( $a['service']['code'] ) . '</b> — ' . esc_html( sprintf( '%d écriture(s) dans le fichier, %d à importer, %d déjà présente(s).', $a['nb_fichier'], count( $a['ecritures'] ), $a['deja'] ) ) . '</p>';
		$h .= '<table class="ige-table"><tbody>';
		$h .= '<tr><td>Solde initial (report)</td><td class="ige-num">' . esc_html( ige_ffm_montant( $a['solde_initial'] ) ) . '</td></tr>';
		$h .= '<tr><td>Entrées</td><td class="ige-num">' . esc_html( ige_ffm_montant( $t['entrees'] ) ) . '</td></tr>';
		$h .= '<tr><td>Sorties payées</td><td class="ige-num">' . esc_html( ige_ffm_montant( $t['sorties'] ) ) . '</td></tr>';
		$h .= '<tr><td>Dépenses engagées non payées</td><td class="ige-num">' . esc_html( ige_ffm_montant( $t['engagees'] ) ) . '</td></tr>';
		$h .= '<tr><td><b>Solde de caisse après reprise</b></td><td class="ige-num"><b>' . esc_html( ige_ffm_montant( $t['solde_final'] ) ) . '</b></td></tr>';
		$h .= '<tr><td>Lignes de budget</td><td class="ige-num">' . count( $a['budget'] ) . ' · ' . esc_html( ige_ffm_montant( array_sum( $a['budget'] ) ) ) . '</td></tr>';
		$lib = array( 'non_transmise' => 'Non transmises', 'transmise' => 'Transmises', 'a_retransmettre' => 'À retransmettre', 'a_verifier' => 'À vérifier', 'validee' => 'Validées', 'rejetee' => 'Rejetées' );
		$et  = array();
		foreach ( $a['etats'] as $k => $n ) {
			$et[] = ( $lib[ $k ] ?? $k ) . ' : ' . $n;
		}
		$h .= '<tr><td>États' . ( $a['central'] ? '' : ' <span class="ige-small ige-muted">(classeur central non chargé)</span>' ) . '</td><td class="ige-num">' . esc_html( implode( ' · ', $et ) ) . '</td></tr>';
		$h .= '</tbody></table>';
		$h .= self::liste( $a['erreurs'], 'Erreurs à corriger dans le fichier', 'ko' );
		$h .= self::liste( $a['avertissements'], 'Avertissements', 'warn' );
		$h .= '<div class="ige-row" style="margin-top:12px">';
		if ( ! $a['erreurs'] ) {
			$h .= IGE_FFM_Ecrans::action_button( 'reprise_importer', array(), 'Importer dans l’application', 'ige-btn ige-btn-jaune', 'Importer le solde initial, le budget et ' . count( $a['ecritures'] ) . ' écriture(s) pour ' . $a['service']['code'] . ' ?' );
		}
		$h .= IGE_FFM_Ecrans::action_button( 'deploiement_annuler', array( 'quoi' => 'reprise' ), 'Annuler', 'ige-btn ige-btn-line' );
		return $h . '</div>';
	}

	/**
	 * Liste de messages repliable.
	 *
	 * @param string[] $l     Messages.
	 * @param string   $titre Titre.
	 * @param string   $type  ko|warn.
	 * @return string
	 */
	private static function liste( array $l, $titre, $type ) {
		if ( ! $l ) {
			return '';
		}
		$h = '<details class="ige-banner ige-' . esc_attr( $type ) . '" style="display:block;margin-top:12px"' . ( 'ko' === $type ? ' open' : '' ) . '><summary><b>' . esc_html( $titre . ' (' . count( $l ) . ')' ) . '</b></summary><ul class="ige-small" style="margin:8px 0 0;padding-left:18px">';
		foreach ( array_slice( $l, 0, 200 ) as $m ) {
			$h .= '<li>' . esc_html( $m ) . '</li>';
		}
		if ( count( $l ) > 200 ) {
			$h .= '<li>…</li>';
		}
		return $h . '</ul></details>';
	}

	/**
	 * Retour à l'écran.
	 *
	 * @param string $ancre Ancre.
	 */
	private static function retour( $ancre = '' ) {
		wp_safe_redirect( ige_ffm_url( 'deploiement' ) . $ancre );
		exit;
	}

	/**
	 * Analyse du CSV des comptes.
	 */
	public static function handle_csv_analyser() {
		ige_ffm_check_form( 'csv_analyser' );
		ige_ffm_require( 'ige_administrer' );
		$f = self::fichier( array( 'csv', 'txt' ) );
		$a = is_wp_error( $f ) ? $f : IGE_FFM_Invitations::analyser_csv( $f );
		if ( is_wp_error( $a ) ) {
			ige_ffm_flash( 'ko', 'Fichier refusé', $a->get_error_message() );
		} else {
			set_transient( self::cle( 'csv' ), $a, HOUR_IN_SECONDS );
		}
		self::retour();
	}

	/**
	 * Création des comptes analysés.
	 */
	public static function handle_csv_creer() {
		ige_ffm_check_form( 'csv_creer' );
		ige_ffm_require( 'ige_administrer' );
		$a = get_transient( self::cle( 'csv' ) );
		delete_transient( self::cle( 'csv' ) );
		if ( ! $a ) {
			ige_ffm_flash( 'ko', 'Analyse expirée', 'Analysez de nouveau le fichier.' );
			self::retour();
		}
		$r = IGE_FFM_Invitations::creer( $a['lignes'], '1' === ige_ffm_post( 'inviter' ) );
		ige_ffm_flash( $r['erreurs'] ? 'warn' : 'ok', sprintf( '%d compte(s) créé(s), %d invitation(s) envoyée(s)', $r['crees'], $r['invites'] ), implode( ' · ', array_slice( $r['erreurs'], 0, 10 ) ) );
		self::retour();
	}

	/**
	 * Chargement du classeur central.
	 */
	public static function handle_reprise_central() {
		ige_ffm_check_form( 'reprise_central' );
		ige_ffm_require( 'ige_administrer' );
		$f = self::fichier( array( 'xlsx', 'xlsm' ) );
		$r = is_wp_error( $f ) ? $f : IGE_FFM_Reprise::charger_central( $f );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Fichier refusé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Classeur central chargé', sprintf( '%d écritures : %d validées, %d rejetées, %d à vérifier. Analysez maintenant les fichiers des services.', $r['nb'], $r['validee'], $r['rejetee'], $r['a_verifier'] ) );
		}
		self::retour();
	}

	/**
	 * Analyse d'un fichier de service.
	 */
	public static function handle_reprise_analyser() {
		ige_ffm_check_form( 'reprise_analyser' );
		ige_ffm_require( 'ige_administrer' );
		$f = self::fichier( array( 'xlsx', 'xlsm' ) );
		$a = is_wp_error( $f ) ? $f : IGE_FFM_Reprise::analyser( $f );
		if ( is_wp_error( $a ) ) {
			ige_ffm_flash( 'ko', 'Fichier refusé', $a->get_error_message() );
		} else {
			set_transient( self::cle( 'reprise' ), $a, HOUR_IN_SECONDS );
		}
		self::retour();
	}

	/**
	 * Import de la reprise analysée.
	 */
	public static function handle_reprise_importer() {
		ige_ffm_check_form( 'reprise_importer' );
		ige_ffm_require( 'ige_administrer' );
		$a = get_transient( self::cle( 'reprise' ) );
		delete_transient( self::cle( 'reprise' ) );
		if ( ! $a ) {
			ige_ffm_flash( 'ko', 'Analyse expirée', 'Analysez de nouveau le fichier.' );
			self::retour();
		}
		$r = IGE_FFM_Reprise::importer( $a );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Import refusé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Reprise de ' . $r['service'] . ' terminée', sprintf( '%d écriture(s), %d ligne(s) de budget. Solde de caisse : %s. Faites-le vérifier par le Responsable du service.', $r['ecritures'], $r['budget'], ige_ffm_montant( $r['caisse'] ) ) );
		}
		self::retour();
	}

	/**
	 * Invitations d'un service.
	 */
	public static function handle_inviter_service() {
		ige_ffm_check_form( 'inviter_service' );
		ige_ffm_require( 'ige_administrer' );
		$sid = (int) ige_ffm_post( 'service_id' );
		$ok  = 0;
		$err = array();
		foreach ( IGE_FFM_Comptes::search( array( 'service_id' => $sid, 'actif' => 1 ) ) as $c ) {
			if ( ! (int) $c['mdp_a_changer'] || IGE_FFM_Invitations::invite_le( (int) $c['user_id'] ) ) {
				continue;
			}
			$r = IGE_FFM_Invitations::inviter( (int) $c['user_id'] );
			if ( is_wp_error( $r ) ) {
				$err[] = $c['user_login'] . ' : ' . $r->get_error_message();
			} else {
				++$ok;
			}
		}
		ige_ffm_flash( $err ? 'warn' : 'ok', sprintf( '%d invitation(s) envoyée(s)', $ok ), implode( ' · ', $err ) );
		self::retour();
	}

	/**
	 * Invitation (ou nouvel envoi) d'un compte, depuis Comptes utilisateurs.
	 */
	public static function handle_inviter() {
		ige_ffm_check_form( 'inviter' );
		ige_ffm_require( 'ige_administrer' );
		$uid = (int) ige_ffm_post( 'user_id' );
		$r   = IGE_FFM_Invitations::inviter( $uid );
		$u   = get_userdata( $uid );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Invitation non envoyée', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Invitation envoyée à ' . $u->user_login, 'Le lien d’activation est valable 24 heures.' );
		}
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}

	/**
	 * Abandon d'une analyse.
	 */
	public static function handle_deploiement_annuler() {
		ige_ffm_check_form( 'deploiement_annuler' );
		ige_ffm_require( 'ige_administrer' );
		delete_transient( self::cle( 'csv' === ige_ffm_post( 'quoi' ) ? 'csv' : 'reprise' ) );
		self::retour();
	}
}
