<?php
/**
 * Écran Nouvelle écriture (saisie d'une sortie ou d'un approvisionnement, pièce jointe).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saisie d'une écriture.
 */
final class IGE_FFM_Ecran_Nouvelle_Ecriture {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_ecriture', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$sid              = IGE_FFM_Contexte::service_utilisateur();
		$args['title']    = 'Nouvelle écriture';
		$args['subtitle'] = $sid ? 'Service ' . IGE_FFM_Comptes::service_code( $sid ) . ' · le numéro est attribué à l’enregistrement' : '';
		$args['actions']  = $sid ? '<a class="ige-btn ige-btn-ghost" href="' . esc_url( ige_ffm_url( 'livre-de-caisse' ) ) . '">Livre de caisse</a>' : '';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$sid = IGE_FFM_Contexte::service_utilisateur();
		$ex  = IGE_FFM_Contexte::exercice();
		if ( ! $sid || ! IGE_FFM_Contexte::peut_saisir( $sid ) ) {
			return ige_ffm_banner( 'warn', 'Saisie réservée aux agents et responsables d’un service', ' Votre compte n’est pas rattaché à un service ou ne permet pas la saisie.' );
		}
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', ' Administration et Finance doit ouvrir l’exercice.' );
		}
		$exo    = (int) $ex['id'];
		$old    = IGE_FFM_Ecrans::old();
		$v      = static fn( $k, $d = '' ) => isset( $old[ $k ] ) ? (string) $old[ $k ] : $d;
		$caisse = IGE_FFM_Budget::caisse( $exo, $sid );
		$suivi  = IGE_FFM_Budget::suivi( $exo, $sid );
		$dispo  = array();
		foreach ( $suivi['lignes'] as $l ) {
			$dispo[ (int) $l['rubrique']['id'] ] = $l['disponible'];
		}
		$type     = 'entree' === $v( 'type' ) ? 'entree' : 'sortie';
		$entrees  = (bool) IGE_FFM_Reglages::get( 'entrees_manuelles' );
		$oblig    = (bool) IGE_FFM_Reglages::get( 'piece_obligatoire' );
		$bloque   = (bool) IGE_FFM_Reglages::get( 'bloquer_depassement' );
		$fourn    = $v( 'fournisseur_id' );
		$modes    = array_filter( IGE_FFM_Referentiels::all( 'mode_paiement' ), static fn( $m ) => (int) $m['actif'] );

		ob_start();
		?>
		<?php echo IGE_FFM_Ecrans::form_open( 'ecriture', 'enctype="multipart/form-data" class="ige-grid ige-cols-21 ige-start" id="ige-saisie" data-caisse="' . esc_attr( $caisse ) . '" data-bloque="' . ( $bloque ? '1' : '0' ) . '"' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<section class="ige-card">
				<?php if ( $entrees ) : ?>
					<div class="ige-seg ige-seg-radio" role="radiogroup" aria-label="Type d’écriture">
						<label><input type="radio" name="type" value="sortie"<?php checked( $type, 'sortie' ); ?>><span>Sortie (dépense)</span></label>
						<label><input type="radio" name="type" value="entree"<?php checked( $type, 'entree' ); ?>><span>Entrée (approvisionnement)</span></label>
					</div>
				<?php else : ?>
					<input type="hidden" name="type" value="sortie">
				<?php endif; ?>

				<div class="ige-grid ige-cols-2" style="gap:0 16px">
					<?php echo IGE_FFM_Ecrans::field( 'date_operation', 'Date de l’opération', $v( 'date_operation', IGE_FFM_Contexte::aujourdhui() ), array( 'type' => 'date', 'required' => true, 'attrs' => 'min="' . esc_attr( $ex['date_debut'] ) . '" max="' . esc_attr( min( $ex['date_fin'], IGE_FFM_Contexte::aujourdhui() ) ) . '"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', $v( 'montant' ), array( 'required' => true, 'attrs' => 'inputmode="numeric" autocomplete="off" placeholder="0" class="ige-num"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<?php if ( IGE_FFM_Reglages::get( 'saisie_usd' ) ) : ?>
					<?php $taux = array_map( static fn( $t ) => array( 'd' => $t['date_effet'], 't' => (float) $t['taux'] ), IGE_FFM_Devises::derniers( 60 ) ); ?>
					<div class="ige-grid ige-cols-2" style="gap:0 16px" id="ige-devise-bloc" data-taux="<?php echo esc_attr( wp_json_encode( $taux ) ); ?>">
						<div class="ige-field">
							<label for="ige-devise">Devise du paiement</label>
							<select id="ige-devise" name="devise">
								<option value="CDF"<?php selected( $v( 'devise', 'CDF' ), 'CDF' ); ?>>Francs congolais (CDF)</option>
								<option value="USD"<?php selected( $v( 'devise' ), 'USD' ); ?>>Dollars (USD), convertis au taux du jour</option>
							</select>
						</div>
						<div class="ige-field" id="ige-usd-bloc"<?php echo 'USD' === $v( 'devise' ) ? '' : ' hidden'; ?>>
							<label for="ige-montant_usd">Montant en dollars (USD)</label>
							<input type="text" id="ige-montant_usd" name="montant_usd" value="<?php echo esc_attr( $v( 'montant_usd' ) ); ?>" inputmode="decimal" autocomplete="off" placeholder="0,00" class="ige-num">
							<span class="ige-help" id="ige-conv" aria-live="polite"></span>
						</div>
					</div>
				<?php endif; ?>

				<div class="ige-field ige-sortie-only">
					<label for="ige-rubrique_id">Rubrique</label>
					<select id="ige-rubrique_id" name="rubrique_id">
						<option value="">— Choisir la rubrique —</option>
						<?php
						foreach ( IGE_FFM_Referentiels::all( 'rubrique' ) as $r ) :
							if ( ! (int) $r['actif'] ) {
								continue;
							}
							$d = $dispo[ (int) $r['id'] ] ?? null;
							?>
							<option value="<?php echo esc_attr( $r['id'] ); ?>" data-dispo="<?php echo esc_attr( null === $d ? '' : $d ); ?>" data-pieces="<?php echo esc_attr( $r['pieces_exigees'] ); ?>"<?php selected( $v( 'rubrique_id' ), (string) $r['id'] ); ?>><?php echo esc_html( $r['code'] . ' — ' . $r['libelle'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="ige-help" id="ige-pieces-aide"></span>
				</div>

				<?php echo IGE_FFM_Ecrans::field( 'description', 'Description', $v( 'description' ), array( 'required' => true, 'attrs' => 'maxlength="255" placeholder="Objet précis de la dépense ou de l’entrée"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="ige-field ige-sortie-only">
					<label for="ige-fournisseur_id">Fournisseur ou bénéficiaire</label>
					<select id="ige-fournisseur_id" name="fournisseur_id">
						<option value="">— Choisir —</option>
						<?php foreach ( IGE_FFM_Fournisseurs::all() as $f ) : ?>
							<option value="<?php echo esc_attr( $f['id'] ); ?>"<?php selected( $fourn, (string) $f['id'] ); ?>><?php echo esc_html( $f['raison_sociale'] ); ?></option>
						<?php endforeach; ?>
						<option value="nouveau"<?php selected( $fourn, 'nouveau' ); ?>>+ Nouveau fournisseur…</option>
						<option value="autre"<?php selected( $fourn, 'autre' ); ?>>Autre bénéficiaire (personne, agent)…</option>
					</select>
				</div>
				<div class="ige-grid ige-cols-2 ige-sortie-only" id="ige-nouveau-fournisseur" style="gap:0 16px"<?php echo 'nouveau' === $fourn ? '' : ' hidden'; ?>>
					<?php echo IGE_FFM_Ecrans::field( 'f_raison_sociale', 'Raison sociale', $v( 'f_raison_sociale' ), array( 'attrs' => 'maxlength="190"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo IGE_FFM_Ecrans::field( 'f_telephone', 'Téléphone', $v( 'f_telephone' ), array( 'type' => 'tel', 'help' => 'Sert à éviter les doublons.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="ige-sortie-only" id="ige-autre-beneficiaire"<?php echo 'autre' === $fourn ? '' : ' hidden'; ?>>
					<?php echo IGE_FFM_Ecrans::field( 'beneficiaire', 'Nom du bénéficiaire', $v( 'beneficiaire' ), array( 'attrs' => 'maxlength="190"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="ige-entree-only"<?php echo 'entree' === $type ? '' : ' hidden'; ?>>
					<?php echo IGE_FFM_Ecrans::field( 'beneficiaire_entree', 'Provenance des fonds', $v( 'beneficiaire_entree', 'Inspection Générale (Administration et Finance)' ), array( 'attrs' => 'maxlength="190"', 'help' => 'Les approvisionnements sont tracés : précisez la référence de l’avance dans le n° de pièce.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<div class="ige-grid ige-cols-2" style="gap:0 16px">
					<?php echo IGE_FFM_Ecrans::field( 'num_piece', 'N° de la pièce justificative', $v( 'num_piece' ), array( 'attrs' => 'maxlength="60" placeholder="Facture, reçu, bon…"' . ( $oblig ? ' data-oblig="1"' : '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div class="ige-field">
						<label for="ige-mode_paiement_id">Mode de paiement</label>
						<select id="ige-mode_paiement_id" name="mode_paiement_id" required>
							<?php foreach ( $modes as $m ) : ?>
								<option value="<?php echo esc_attr( $m['id'] ); ?>"<?php selected( $v( 'mode_paiement_id' ), (string) $m['id'] ); ?>><?php echo esc_html( $m['libelle'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="ige-field ige-sortie-only">
					<span style="font-size:12.5px;font-weight:600">Statut</span>
					<label class="ige-row" style="font-weight:500"><input type="radio" name="statut" value="payee" style="width:auto"<?php checked( $v( 'statut', 'payee' ), 'payee' ); ?>> Payée — l’argent est sorti de la caisse</label>
					<label class="ige-row" style="font-weight:500"><input type="radio" name="statut" value="engagee" style="width:auto"<?php checked( $v( 'statut' ), 'engagee' ); ?>> Engagée — commande passée, paiement plus tard</label>
				</div>

				<div class="ige-field">
					<label for="ige-piece">Pièce justificative (photo ou PDF)<?php echo $oblig ? ' — obligatoire pour une sortie' : ''; ?></label>
					<input type="file" id="ige-piece" name="piece" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment">
					<span class="ige-help">5 Mo au plus. Les photos sont réduites automatiquement avant l’envoi.</span>
					<span class="ige-help" id="ige-piece-info" aria-live="polite"></span>
				</div>
				<div class="ige-field ige-sortie-only" id="ige-nature-bloc" hidden>
					<label for="ige-piece_nature">Nature de cette pièce</label>
					<select id="ige-piece_nature" name="piece_nature" data-valeur="<?php echo esc_attr( $v( 'piece_nature' ) ); ?>"></select>
					<span class="ige-help">Les autres pièces exigées s’ajoutent ensuite depuis le livre de caisse ; le dossier est complet quand toutes sont jointes.</span>
				</div>

				<?php echo IGE_FFM_Ecrans::field( 'observations', 'Observations (facultatif)', $v( 'observations' ), array( 'attrs' => 'maxlength="500"' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="ige-row">
					<button type="submit" class="ige-btn ige-btn-bleu" id="ige-enregistrer">Enregistrer l’écriture</button>
					<a class="ige-btn ige-btn-line" href="<?php echo esc_url( ige_ffm_url( 'accueil-service' ) ); ?>">Annuler</a>
				</div>
			</section>

			<aside class="ige-grid">
				<section class="ige-card">
					<h2>Contrôles en direct</h2>
					<dl class="ige-kv" style="grid-template-columns:1fr auto">
						<dt>Solde de caisse</dt><dd class="ige-num ige-right"><?php echo esc_html( ige_ffm_montant( $caisse ) ); ?></dd>
						<dt>Caisse après l’opération</dt><dd class="ige-num ige-right" id="ige-caisse-apres">—</dd>
						<dt>Disponible de la rubrique</dt><dd class="ige-num ige-right" id="ige-dispo">—</dd>
					</dl>
					<div id="ige-controle" aria-live="polite" style="margin-top:12px"></div>
				</section>
				<section class="ige-card">
					<h2>Règles appliquées</h2>
					<ul class="ige-small" style="margin:0;padding-left:18px;line-height:1.6">
						<?php if ( IGE_FFM_Reglages::get( 'bloquer_caisse_negative' ) ) : ?><li>La caisse ne peut pas devenir négative.</li><?php endif; ?>
						<?php if ( $bloque ) : ?><li>Une sortie ne peut pas dépasser le disponible de sa rubrique.</li><?php endif; ?>
						<li>Paiement en espèces limité à <?php echo esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'plafond_especes' ) ) ); ?> par opération.</li>
						<li>Une écriture enregistrée ne se modifie pas : une erreur se corrige par contrepassation.</li>
						<?php if ( ! (int) $ex['budget_obligatoire'] ) : ?><li>Exercice <?php echo esc_html( $ex['annee'] ); ?> : saisie possible sans budget ; la consommation est suivie.</li><?php endif; ?>
					</ul>
				</section>
			</aside>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Champs du formulaire de saisie.
	 *
	 * @return string[]
	 */
	public static function champs() {
		return array( 'type', 'statut', 'date_operation', 'rubrique_id', 'montant', 'description', 'fournisseur_id', 'beneficiaire', 'beneficiaire_entree', 'num_piece', 'mode_paiement_id', 'observations', 'f_raison_sociale', 'f_telephone', 'piece_nature', 'devise', 'montant_usd', 'cle_client' );
	}

	/**
	 * Prépare et enregistre une saisie (formulaire en ligne ou envoi différé hors connexion).
	 *
	 * @param array      $data    Champs bruts (déjà « unslashés »).
	 * @param array|null $file    Pièce.
	 * @param int|null   $user_id Auteur.
	 * @return int|WP_Error Écriture.
	 */
	public static function enregistrer( array $data, $file = null, $user_id = null ) {
		$user_id = $user_id ?? get_current_user_id();
		$propre  = array();
		foreach ( self::champs() as $k ) {
			$propre[ $k ] = 'observations' === $k ? sanitize_textarea_field( (string) ( $data[ $k ] ?? '' ) ) : sanitize_text_field( (string) ( $data[ $k ] ?? '' ) );
		}
		$saisie = $propre;
		if ( 'entree' === $propre['type'] ) {
			$saisie['beneficiaire']   = $propre['beneficiaire_entree'];
			$saisie['fournisseur_id'] = 0;
		} elseif ( 'nouveau' === $propre['fournisseur_id'] ) {
			$fid = IGE_FFM_Fournisseurs::save( array( 'raison_sociale' => $propre['f_raison_sociale'], 'telephone' => $propre['f_telephone'] ), 0, $user_id );
			if ( is_wp_error( $fid ) && 'doublon' === $fid->get_error_code() ) {
				$fid = (int) $fid->get_error_data()['id'];
			}
			if ( is_wp_error( $fid ) ) {
				return $fid;
			}
			$saisie['fournisseur_id'] = $fid;
			$saisie['beneficiaire']   = '';
		} elseif ( 'autre' === $propre['fournisseur_id'] ) {
			$saisie['fournisseur_id'] = 0;
		} else {
			$saisie['beneficiaire'] = '';
		}
		return IGE_FFM_Ecritures::saisir( $saisie, $file, $user_id );
	}

	/**
	 * Enregistrement du formulaire.
	 */
	public static function handle() {
		ige_ffm_check_form( 'ecriture' );
		ige_ffm_require( 'ige_saisir' );
		$data   = array();
		foreach ( self::champs() as $k ) {
			$data[ $k ] = isset( $_POST[ $k ] ) ? wp_unslash( $_POST[ $k ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$retour = ige_ffm_url( 'nouvelle-ecriture' );
		$file   = isset( $_FILES['piece'] ) ? $_FILES['piece'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$result = self::enregistrer( $data, $file );
		if ( is_wp_error( $result ) ) {
			IGE_FFM_Ecrans::remember( array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $data ) );
			ige_ffm_flash( 'ko', 'Écriture non enregistrée', $result->get_error_message() );
			wp_safe_redirect( $retour );
			exit;
		}
		$e = IGE_FFM_Ecritures::get( $result );
		ige_ffm_flash(
			'ok',
			'Écriture ' . $e['id_ecriture'] . ' enregistrée',
			sprintf( '%s de %s. Vous pouvez saisir la suivante.', 'entree' === $e['type'] ? 'Entrée' : ( 'engagee' === $e['statut'] ? 'Engagement' : 'Sortie payée' ), ige_ffm_montant( (int) $e['montant'] ) )
		);
		wp_safe_redirect( $retour );
		exit;
	}
}
