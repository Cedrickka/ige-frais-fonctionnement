<?php
/**
 * Écran « Dossier de dépense » (VA-10) : engagements suivis de l'engagement au paiement,
 * pièces par étape, visas d'ordonnancement. Un service voit ses dossiers ; le central voit tout.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dossiers de dépense.
 */
final class IGE_FFM_Ecran_Dossier {

	/**
	 * Crochets.
	 */
	public static function register() {}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Dossiers de dépense';
		$args['subtitle'] = 'Engagement, liquidation, ordonnancement, paiement' . ( (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) ? ' · chaîne complète à partir de ' . ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) ) : '' );
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
		$own  = IGE_FFM_Contexte::service_utilisateur();
		$tout = current_user_can( 'ige_voir_tout' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id  = isset( $_GET['ecriture'] ) ? (int) $_GET['ecriture'] : 0;
		$vue = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : 'a_viser';
		$sid = $own ? $own : ( isset( $_GET['service'] ) ? (int) $_GET['service'] : 0 );
		// phpcs:enable
		if ( ! $own && ! $tout ) {
			return ige_ffm_banner( 'warn', 'Compte non rattaché à un service', '' );
		}
		if ( $id ) {
			return self::detail( $id );
		}
		$base  = ige_ffm_url( 'dossier-de-depense' );
		$uid   = get_current_user_id();
		$avis  = array_filter( IGE_FFM_Visas::a_viser( $uid ), static fn( $x ) => 'ordonnancement' === $x['objet'] && ( ! $sid || $x['service_id'] === $sid ) );
		$vues  = array( 'a_viser' => 'À mon visa (' . count( $avis ) . ')', 'en_cours' => 'Engagements en cours', 'payees' => 'Payées après ordonnancement' );
		$vue   = isset( $vues[ $vue ] ) ? $vue : 'a_viser';
		$rows  = 'a_viser' === $vue ? array_map( static fn( $x ) => IGE_FFM_Ecritures::get( $x['id'] ), $avis ) : IGE_FFM_Chaine::dossiers( $exo, $sid, $vue );
		$svc   = wp_list_pluck( IGE_FFM_Referentiels::all( 'service' ), 'code', 'id' );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-toolbar">
				<?php foreach ( $vues as $k => $l ) : ?>
					<a class="ige-chip<?php echo $vue === $k ? ' ige-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array_filter( array( 'vue' => $k, 'service' => $own ? 0 : $sid ) ), $base ) ); ?>"><?php echo esc_html( $l ); ?></a>
				<?php endforeach; ?>
				<?php if ( ! $own ) : ?>
					<form method="get" style="margin-left:auto"><input type="hidden" name="vue" value="<?php echo esc_attr( $vue ); ?>"><select name="service" class="ige-input" style="width:auto" onchange="this.form.submit()"><option value="">Tous les services</option><?php foreach ( IGE_FFM_Contexte::services_visibles() as $s ) : ?><option value="<?php echo esc_attr( $s['id'] ); ?>"<?php selected( $sid, (int) $s['id'] ); ?>><?php echo esc_html( $s['code'] ); ?></option><?php endforeach; ?></select></form>
				<?php endif; ?>
			</div>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Écriture</th><th>Service</th><th>Objet</th><th class="ige-r">Montant</th><th>Étape</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( array_filter( $rows ) as $e ) : ?>
					<?php
					$et = IGE_FFM_Chaine::etapes( $e );
					$en = 'Engagée';
					foreach ( $et as $x ) {
						if ( $x[1] ) {
							$en = $x[0];
						}
					}
					$req = IGE_FFM_Chaine::requise( $e );
					?>
					<tr>
						<td><b><?php echo esc_html( $e['id_ecriture'] ); ?></b><br><span class="ige-small ige-muted"><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $e['date_operation'] ) ) ); ?></span></td>
						<td><?php echo esc_html( $svc[ $e['service_id'] ] ?? '' ); ?></td>
						<td><?php echo esc_html( $e['description'] ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $e['montant'] ) ); ?></td>
						<td><?php echo ige_ffm_pill( 'refusee' === $e['etape'] ? 'Ordonnancement refusé' : ( 'liquidee' === $e['etape'] ? 'Ordonnancement en cours' : ( 'payee' === $e['statut'] ? 'Payée' : ( 'ordonnancee' === $e['etape'] ? 'Ordonnancée, à payer' : ( $req ? 'Liquidation à faire' : 'Engagée (chaîne simplifiée)' ) ) ) ), 'refusee' === $e['etape'] ? 'ko' : ( 'payee' === $e['statut'] ? 'ok' : 'wait' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'ecriture', $e['id'], $base ) ); ?>">Ouvrir</a></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! array_filter( $rows ) ) : ?>
					<tr><td colspan="6" class="ige-empty"><?php echo 'a_viser' === $vue ? 'Aucun ordonnancement n’attend votre visa.' : 'Aucun dossier dans cette liste.'; ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">À partir de <?php echo esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'chaine_seuil' ) ) ); ?>, un engagement passe par la liquidation (service fait, facture reçue) et l’ordonnancement (visa du chef de service puis d’Administration et Finance, et de l’Inspecteur Général au-delà de <?php echo esc_html( ige_ffm_montant( (int) IGE_FFM_Reglages::get( 'visa_direction_seuil' ) ) ); ?>) avant d’être payé. En dessous, la chaîne est simplifiée : engagement puis paiement.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Détail d'un dossier.
	 *
	 * @param int $id Écriture.
	 * @return string
	 */
	private static function detail( $id ) {
		$e = IGE_FFM_Ecritures::get( $id );
		if ( ! $e || 'sortie' !== $e['type'] || ! IGE_FFM_Contexte::peut_voir( (int) $e['service_id'] ) ) {
			return ige_ffm_banner( 'ko', 'Dossier introuvable', '' );
		}
		$retour = add_query_arg( 'ecriture', $id, ige_ffm_url( 'dossier-de-depense' ) );
		$r      = $e['rubrique_id'] ? IGE_FFM_Referentiels::get( 'rubrique', (int) $e['rubrique_id'] ) : null;
		$f      = $e['fournisseur_id'] ? IGE_FFM_Fournisseurs::get( (int) $e['fournisseur_id'] ) : null;
		$dispo  = IGE_FFM_Budget::disponible( (int) $e['exercice_id'], (int) $e['service_id'], (int) $e['rubrique_id'] );
		$pieces = IGE_FFM_Pieces::liste( 'ecriture', $id );
		$dos    = IGE_FFM_Pieces::etat_dossier( $e );
		ob_start();
		?>
		<p><a href="<?php echo esc_url( ige_ffm_url( 'dossier-de-depense' ) ); ?>">← Tous les dossiers</a></p>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card">
				<h2 style="margin-top:0"><?php echo esc_html( $e['id_ecriture'] . ' · ' . $e['description'] ); ?></h2>
				<?php echo IGE_FFM_Chaine::bloc( $e, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<h3 style="font-size:14px;margin:14px 0 6px">Pièces du dossier <?php echo $dos['exigees'] ? ( $dos['complet'] ? ige_ffm_pill( 'Dossier complet', 'ok' ) : ige_ffm_pill( 'Dossier incomplet', 'ko' ) ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
				<div class="ige-table-wrap"><table class="ige-table">
					<thead><tr><th>Pièce</th><th>Nature</th><th>Déposée par</th><th>Date</th></tr></thead>
					<tbody>
					<?php foreach ( $pieces as $p ) : ?>
						<?php $u = get_userdata( (int) $p['televerse_par'] ); ?>
						<tr><td><a href="<?php echo esc_url( IGE_FFM_Pieces::url( $p ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $p['nom_original'] ); ?></a></td><td><?php echo esc_html( $p['nature'] ?: '—' ); ?></td><td><?php echo esc_html( $u ? $u->user_login : '' ); ?></td><td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $p['televerse_le'] ) ) ); ?></td></tr>
					<?php endforeach; ?>
					<?php if ( ! $pieces ) : ?><tr><td colspan="4" class="ige-empty">Aucune pièce.</td></tr><?php endif; ?>
					</tbody>
				</table></div>
				<p class="ige-small"><a href="<?php echo esc_url( IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'livre-de-caisse', array( 'ecriture' => $id ) ), (int) $e['service_id'] ) . '#ige-detail' ); ?>">Ouvrir dans le livre de caisse</a> (ajout de pièces, paiement, contrepassation).</p>
			</section>
			<aside class="ige-card">
				<dl class="ige-kv">
					<dt>Service</dt><dd><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $e['service_id'] ) ); ?></dd>
					<dt>Montant engagé</dt><dd><b><?php echo esc_html( ige_ffm_montant( (int) $e['montant'] ) ); ?></b></dd>
					<dt>Rubrique</dt><dd><?php echo esc_html( $r ? $r['code'] . ' ' . $r['libelle'] : '—' ); ?></dd>
					<dt>Disponible</dt><dd><?php echo esc_html( null === $dispo ? 'sans budget' : ige_ffm_montant( $dispo ) ); ?></dd>
					<dt>Fournisseur</dt><dd><?php echo esc_html( $f ? $f['raison_sociale'] : ( $e['beneficiaire'] ?: '—' ) ); ?></dd>
					<dt>Bon de commande</dt><dd><?php echo esc_html( $e['num_piece'] ?: '—' ); ?></dd>
					<dt>Facture</dt><dd><?php echo esc_html( $e['facture_ref'] ?: '—' ); ?></dd>
				</dl>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}
}
