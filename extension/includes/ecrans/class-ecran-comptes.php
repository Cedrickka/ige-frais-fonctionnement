<?php
/**
 * Écran Administration > Comptes utilisateurs.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Écran des comptes.
 */
final class IGE_FFM_Ecran_Comptes {

	/**
	 * Crochets des formulaires.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_compte', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ige_ffm_compte_etat', array( __CLASS__, 'handle_etat' ) );
		add_action( 'admin_post_ige_ffm_compte_reset', array( __CLASS__, 'handle_reset' ) );
		add_action( 'admin_post_ige_ffm_comptes_revue', array( __CLASS__, 'handle_revue' ) );
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Administration — Comptes utilisateurs';
		$args['subtitle'] = 'Un compte par personne, rattaché à un service et à un profil';
		$args['actions']  = '<a class="ige-btn ige-btn-jaune" href="' . esc_url( remove_query_arg( 'modifier', get_permalink() ) . '#ige-formulaire' ) . '">+ Nouveau compte</a>';
		return $args;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$profil = isset( $_GET['profil'] ) ? sanitize_key( $_GET['profil'] ) : '';
		$etat   = isset( $_GET['etat'] ) ? sanitize_key( $_GET['etat'] ) : 'actifs';
		$edit   = isset( $_GET['modifier'] ) ? (int) $_GET['modifier'] : 0;
		// phpcs:enable
		$base     = get_permalink();
		$filters  = array( 'actif' => 'inactifs' === $etat ? 0 : 1 );
		if ( $profil ) {
			$filters['profil'] = $profil;
		}
		$comptes  = IGE_FFM_Comptes::search( $filters );
		$tous     = IGE_FFM_Comptes::search();
		$inactifs = IGE_FFM_Comptes::inactifs( 90 );
		$revue    = (string) IGE_FFM_Reglages::get( 'revue_comptes_le' );
		$revue_due = ! $revue || strtotime( $revue ) < strtotime( '-90 days' );

		ob_start();
		if ( $revue_due ) {
			echo '<div class="ige-banner ige-warn" role="status"><span class="ige-ic">!</span><div style="flex:1"><b>Revue trimestrielle des comptes à faire</b>Confirmez que chaque compte actif correspond à une personne en poste et au bon profil. ' . esc_html( sprintf( '%d compte(s) actif(s) sans connexion depuis plus de 90 jours.', count( $inactifs ) ) ) . ( $revue ? ' Dernière revue : ' . esc_html( wp_date( 'd/m/Y', strtotime( $revue ) ) ) . '.' : '' ) . '</div>' . IGE_FFM_Ecrans::action_button( 'comptes_revue', array(), 'Confirmer la revue', 'ige-btn ige-btn-line', 'Confirmez-vous avoir vérifié la liste des comptes actifs ?' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
		<div class="ige-grid ige-adm-split">
			<section class="ige-card" style="padding:16px">
				<div class="ige-toolbar">
					<?php
					$chip = static function ( $label, $args, $on ) use ( $base ) {
						return '<a class="ige-chip' . ( $on ? ' ige-on' : '' ) . '" href="' . esc_url( add_query_arg( $args, $base ) ) . '">' . esc_html( $label ) . '</a>';
					};
					$n_actifs = count( array_filter( $tous, static fn( $c ) => (int) $c['actif'] ) );
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
					echo $chip( 'Actifs (' . $n_actifs . ')', array( 'etat' => 'actifs' ), 'actifs' === $etat && ! $profil );
					echo $chip( 'Désactivés (' . ( count( $tous ) - $n_actifs ) . ')', array( 'etat' => 'inactifs' ), 'inactifs' === $etat );
					foreach ( IGE_FFM_Roles::roles() as $k => $r ) {
						echo $chip( $r[0], array( 'profil' => $k ), $profil === $k );
					}
					// phpcs:enable
					?>
				</div>
				<div class="ige-table-wrap">
					<table class="ige-table">
						<thead><tr><th>Compte</th><th>Profil</th><th>Service</th><th>Dernière connexion</th><th>État</th><th>Actions</th></tr></thead>
						<tbody>
						<?php foreach ( $comptes as $c ) : ?>
							<?php
							$jamais  = ! $c['derniere_connexion'];
							$ancien  = $c['derniere_connexion'] && strtotime( $c['derniere_connexion'] ) < strtotime( '-90 days' );
							$service = IGE_FFM_Comptes::service_code( (int) $c['service_id'] );
							?>
							<tr class="<?php echo $edit === (int) $c['user_id'] ? 'ige-sel' : ( $ancien ? 'ige-rejet' : '' ); ?>">
								<td><b><?php echo esc_html( $c['user_login'] ); ?></b><br><span class="ige-small ige-muted"><?php echo esc_html( $c['display_name'] ); ?></span></td>
								<td><?php echo ige_ffm_pill( IGE_FFM_Roles::libelle( $c['profil'] ), 'ige_admin' === $c['profil'] ? 'info' : 'neutre' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( $service ?: '—' ); ?></td>
								<td<?php echo $ancien ? ' class="ige-neg"' : ''; ?>><?php echo esc_html( $jamais ? 'Jamais' : wp_date( 'd/m/Y H:i', strtotime( $c['derniere_connexion'] ) ) ); ?></td>
								<td>
									<?php
									// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
									echo (int) $c['actif'] ? ige_ffm_pill( 'Actif', 'ok' ) : ige_ffm_pill( 'Désactivé', 'ko' );
									if ( (int) $c['mdp_a_changer'] && (int) $c['actif'] ) {
										echo ' ' . ige_ffm_pill( 'Mot de passe provisoire', 'wait' );
									}
									if ( IGE_FFM_Deux_Facteurs::actif( (int) $c['user_id'] ) ) {
										echo ' ' . ige_ffm_pill( 'Double authentification', 'info' );
									} elseif ( IGE_FFM_Deux_Facteurs::obligatoire( (int) $c['user_id'] ) && (int) $c['actif'] ) {
										echo ' ' . ige_ffm_pill( 'Double authentification à activer', 'wait' );
									}
									// phpcs:enable
									?>
								</td>
								<td><div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start;white-space:nowrap">
									<?php if ( IGE_FFM_Comptes::modifiable( (int) $c['user_id'] ) ) : ?>
										<a class="ige-act" href="<?php echo esc_url( add_query_arg( 'modifier', $c['user_id'], $base ) . '#ige-formulaire' ); ?>">Modifier</a>
										<?php
										// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
										if ( (int) $c['actif'] && (int) $c['mdp_a_changer'] && IGE_FFM_Messagerie::adresse_valide( get_userdata( (int) $c['user_id'] ) ) ) {
											$deja = IGE_FFM_Invitations::invite_le( (int) $c['user_id'] );
											echo IGE_FFM_Ecrans::action_button( 'inviter', array( 'user_id' => $c['user_id'] ), $deja ? 'Renvoyer l’invitation' : 'Inviter par e-mail', 'ige-act', 'Envoyer à ' . $c['user_login'] . ' un lien pour choisir son mot de passe ?' );
										}
										echo IGE_FFM_Ecrans::action_button( 'compte_reset', array( 'user_id' => $c['user_id'] ), 'Réinitialiser', 'ige-act', 'Générer un nouveau mot de passe provisoire pour ' . $c['user_login'] . ' ?' );
										if ( (int) $c['user_id'] !== get_current_user_id() && IGE_FFM_Deux_Facteurs::actif( (int) $c['user_id'] ) ) {
											echo IGE_FFM_Ecrans::action_button( '2fa_reinitialiser', array( 'user_id' => $c['user_id'] ), 'Réinit. double auth.', 'ige-act', 'Réinitialiser la double authentification de ' . $c['user_login'] . ' (téléphone perdu ou changé) ? Elle devra l’activer de nouveau.' );
										}
										if ( (int) $c['user_id'] !== get_current_user_id() ) {
											echo (int) $c['actif']
												? IGE_FFM_Ecrans::action_button( 'compte_etat', array( 'user_id' => $c['user_id'], 'actif' => 0 ), 'Désactiver', 'ige-act', 'Désactiver le compte ' . $c['user_login'] . ' ? La personne ne pourra plus se connecter.' )
												: IGE_FFM_Ecrans::action_button( 'compte_etat', array( 'user_id' => $c['user_id'], 'actif' => 1 ), 'Réactiver', 'ige-act' );
										}
										// phpcs:enable
										?>
									<?php endif; ?>
								</div></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $comptes ) : ?>
							<tr><td colspan="6" class="ige-empty">Aucun compte dans cette liste.</td></tr>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
				<p class="ige-small ige-muted" style="margin:12px 0 0">Les mots de passe ne sont jamais affichés ni conservés en clair. « Réinitialiser » génère un mot de passe provisoire, à changer à la connexion suivante. Les administrateurs techniques du site se gèrent dans WordPress.</p>
			</section>
			<section class="ige-card" id="ige-formulaire">
				<?php echo self::form( $edit ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</section>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire de création ou de modification.
	 *
	 * @param int $user_id Compte modifié (0 pour une création).
	 * @return string
	 */
	private static function form( $user_id ) {
		$c    = $user_id ? IGE_FFM_Comptes::get( $user_id ) : null;
		$u    = $c ? get_userdata( $user_id ) : null;
		$old  = IGE_FFM_Ecrans::old();
		$val  = static fn( $k, $d ) => $old[ $k ] ?? $d;
		$base = get_permalink();

		$profils = array( '' => '— Choisir —' );
		foreach ( IGE_FFM_Roles::roles() as $k => $r ) {
			$profils[ $k ] = $r[0];
		}
		$services = array( '' => '— Aucun (profil central ou Direction) —' );
		foreach ( IGE_FFM_Referentiels::all( 'service', true ) as $s ) {
			$services[ $s['id'] ] = $s['code'] . ( $s['libelle'] !== $s['code'] ? ' · ' . $s['libelle'] : '' );
		}
		$email = $u && false === strpos( $u->user_email, '@invalid.local' ) ? $u->user_email : '';

		$h  = '<h2>' . esc_html( $c ? 'Modifier le compte ' . $u->user_login : 'Nouveau compte' ) . '</h2>';
		$h .= IGE_FFM_Ecrans::form_open( 'compte' );
		$h .= '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '">';
		$h .= IGE_FFM_Ecrans::field( 'user_login', 'Identifiant', $u ? $u->user_login : $val( 'user_login', '' ), array( 'required' => ! $c, 'readonly' => (bool) $c, 'help' => $c ? 'L’identifiant ne peut pas être modifié.' : 'Exemple : agent8001 ou j.mbuyi. Lettres, chiffres, point, tiret.', 'attrs' => 'autocomplete="off"' ) );
		$h .= IGE_FFM_Ecrans::field( 'display_name', 'Nom complet', $val( 'display_name', $u ? $u->display_name : '' ), array( 'required' => true ) );
		$h .= IGE_FFM_Ecrans::field( 'user_email', 'Adresse e-mail', $val( 'user_email', $email ), array( 'type' => 'email', 'help' => 'Indispensable : elle reçoit l’invitation et les codes de connexion. Une adresse par personne.' ) );
		$h .= IGE_FFM_Ecrans::field( 'profil', 'Profil', $val( 'profil', $c['profil'] ?? '' ), array( 'type' => 'select', 'options' => $profils, 'required' => true ) );
		$h .= IGE_FFM_Ecrans::field( 'service_id', 'Service', $val( 'service_id', $c['service_id'] ?? '' ), array( 'type' => 'select', 'options' => $services, 'help' => 'Obligatoire pour les profils Agent et Responsable.' ) );
		$h .= '<div class="ige-row"><button type="submit" class="ige-btn ige-btn-jaune">' . esc_html( $c ? 'Enregistrer' : 'Créer le compte' ) . '</button>';
		if ( $c ) {
			$h .= '<a class="ige-btn ige-btn-line" href="' . esc_url( $base ) . '">Annuler</a>';
		}
		$h .= '</div>';
		if ( ! $c ) {
			$h .= '<p class="ige-small ige-muted" style="margin-top:12px">Un mot de passe provisoire est généré et affiché une seule fois. Vous pouvez aussi, au lieu de le remettre, envoyer une invitation par e-mail : la personne choisit elle-même son mot de passe par un lien personnel. Pour créer de nombreux comptes, utilisez Déploiement.</p>';
		}
		return $h . '</form>';
	}

	/**
	 * Création ou modification.
	 */
	public static function handle_save() {
		ige_ffm_check_form( 'compte' );
		ige_ffm_require( 'ige_administrer' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$data    = wp_unslash( $_POST );
		$user_id = (int) ( $data['user_id'] ?? 0 );
		$back    = ige_ffm_url( 'comptes' );

		if ( $user_id ) {
			$result = IGE_FFM_Comptes::update( $user_id, $data );
			if ( is_wp_error( $result ) ) {
				ige_ffm_flash( 'ko', 'Modification refusée', $result->get_error_message() );
				wp_safe_redirect( add_query_arg( 'modifier', $user_id, $back ) . '#ige-formulaire' );
				exit;
			}
			ige_ffm_flash( 'ok', 'Compte modifié', '' );
			wp_safe_redirect( $back );
			exit;
		}

		$result = IGE_FFM_Comptes::create( $data );
		if ( is_wp_error( $result ) ) {
			IGE_FFM_Ecrans::remember( array_map( 'sanitize_text_field', array_intersect_key( $data, array_flip( array( 'user_login', 'display_name', 'user_email', 'profil', 'service_id' ) ) ) ) );
			ige_ffm_flash( 'ko', 'Création refusée', $result->get_error_message() );
			wp_safe_redirect( $back . '#ige-formulaire' );
			exit;
		}
		$login = sanitize_user( (string) $data['user_login'], true );
		ige_ffm_flash( 'ok', 'Compte créé : ' . $login, sprintf( 'Mot de passe provisoire : %s — remettez-le à la personne ; il ne sera plus affiché. Elle choisira son propre mot de passe à la première connexion.', $result['password'] ) );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Activation ou désactivation.
	 */
	public static function handle_etat() {
		ige_ffm_check_form( 'compte_etat' );
		ige_ffm_require( 'ige_administrer' );
		$user_id = (int) ige_ffm_post( 'user_id' );
		$actif   = '1' === ige_ffm_post( 'actif' );
		$result  = IGE_FFM_Comptes::set_actif( $user_id, $actif );
		if ( is_wp_error( $result ) ) {
			ige_ffm_flash( 'ko', 'Action refusée', $result->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', $actif ? 'Compte réactivé' : 'Compte désactivé', $actif ? '' : 'Ses sessions ouvertes ont été fermées.' );
		}
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}

	/**
	 * Nouveau mot de passe provisoire.
	 */
	public static function handle_reset() {
		ige_ffm_check_form( 'compte_reset' );
		ige_ffm_require( 'ige_administrer' );
		$user_id = (int) ige_ffm_post( 'user_id' );
		$result  = IGE_FFM_Comptes::reset_password( $user_id );
		if ( is_wp_error( $result ) ) {
			ige_ffm_flash( 'ko', 'Action refusée', $result->get_error_message() );
		} else {
			$u = get_userdata( $user_id );
			ige_ffm_flash( 'ok', 'Nouveau mot de passe provisoire pour ' . $u->user_login, sprintf( '%s — remettez-le à la personne ; il ne sera plus affiché.', $result ) );
		}
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}

	/**
	 * Confirmation de la revue trimestrielle.
	 */
	public static function handle_revue() {
		ige_ffm_check_form( 'comptes_revue' );
		ige_ffm_require( 'ige_administrer' );
		IGE_FFM_Reglages::set_internal( 'revue_comptes_le', ige_ffm_now() );
		IGE_FFM_Audit::log( 'revue_comptes', 'comptes', '', null, array( 'comptes_actifs' => count( IGE_FFM_Comptes::search( array( 'actif' => 1 ) ) ) ) );
		ige_ffm_flash( 'ok', 'Revue des comptes enregistrée', 'Prochaine revue dans trois mois.' );
		wp_safe_redirect( ige_ffm_url( 'comptes' ) );
		exit;
	}
}
