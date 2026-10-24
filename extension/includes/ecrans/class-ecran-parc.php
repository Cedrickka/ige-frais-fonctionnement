<?php
/**
 * Écrans du parc automobile (VA-41) :
 * - « Parc automobile » (gestion, Administration et Finance ; consultation par la Direction) :
 *   tableau de bord, véhicules et fiches, demandes et ordres de route, carburant, entretiens et
 *   sinistres, chauffeurs, coûts ;
 * - « Véhicules » (services) : demandes de véhicule, départ et retour, pleins des véhicules affectés.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parc automobile : écran de gestion.
 */
final class IGE_FFM_Ecran_Parc {

	/**
	 * Crochets.
	 */
	public static function register() {
		foreach ( array( 'vehicule', 'statut', 'chauffeur', 'document', 'demande', 'attribuer', 'clore', 'depart', 'retour', 'plein', 'intervention', 'realiser' ) as $a ) {
			add_action( 'admin_post_ige_ffm_parc_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_post_ige_ffm_parc_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Parc automobile';
		$args['subtitle'] = 'Véhicules, chauffeurs, ordres de route, carburant, entretien et coûts';
		$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_parc_export' ), 'ige_ffm_parc_export' ) ) . '">Excel</a>';
		return $args;
	}

	/**
	 * Onglets.
	 *
	 * @return array<string,string>
	 */
	public static function onglets() {
		return array( 'vehicules' => 'Véhicules', 'deplacements' => 'Demandes et ordres de route', 'carburant' => 'Carburant', 'entretiens' => 'Entretien et sinistres', 'chauffeurs' => 'Chauffeurs', 'couts' => 'Coûts' );
	}

	/**
	 * Options de sélection.
	 *
	 * @param string $quoi services | vehicules | chauffeurs.
	 * @param string $vide Libellé de l'option vide.
	 * @return array
	 */
	public static function options( $quoi, $vide = '' ) {
		$o = '' === $vide ? array() : array( '' => $vide );
		if ( 'services' === $quoi ) {
			foreach ( IGE_FFM_Referentiels::all( 'service', true ) as $s ) {
				$o[ $s['id'] ] = $s['code'];
			}
		} elseif ( 'vehicules' === $quoi ) {
			foreach ( IGE_FFM_Parc::vehicules( array( 'sans_reformes' => true ) ) as $v ) {
				$o[ $v['id'] ] = IGE_FFM_Parc::nom( $v ) . ( 'disponible' !== $v['statut'] ? ' (' . strtolower( IGE_FFM_Parc::statuts()[ $v['statut'] ][0] ) . ')' : '' );
			}
		} else {
			foreach ( IGE_FFM_Parc::chauffeurs( true ) as $c ) {
				$o[ $c['id'] ] = $c['nom'];
			}
		}
		return $o;
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['vehicule'] ) ) {
			return self::fiche( (int) $_GET['vehicule'] );
		}
		if ( ! empty( $_GET['deplacement'] ) ) {
			return self::ordre( (int) $_GET['deplacement'] );
		}
		$o = isset( $_GET['onglet'] ) ? sanitize_key( $_GET['onglet'] ) : 'vehicules';
		// phpcs:enable
		$o    = isset( self::onglets()[ $o ] ) ? $o : 'vehicules';
		$base = ige_ffm_url( 'parc-automobile' );
		$h    = '<div class="ige-tabs" role="tablist" style="margin-bottom:14px">';
		foreach ( self::onglets() as $k => $l ) {
			$n = '';
			if ( 'deplacements' === $k ) {
				$d = count( IGE_FFM_Parc::deplacements( array( 'statut' => 'demande' ) ) );
				$n = $d ? ' (' . $d . ')' : '';
			}
			$h .= '<a role="tab" href="' . esc_url( add_query_arg( 'onglet', $k, $base ) ) . '" class="' . ( $k === $o ? 'ige-on' : '' ) . '">' . esc_html( $l . $n ) . '</a>';
		}
		$h .= '</div>';
		return $h . call_user_func( array( __CLASS__, 'onglet_' . $o ) );
	}

	/**
	 * Pastille d'un document.
	 *
	 * @param array $e [ libellé, état, fin ].
	 * @return string
	 */
	private static function pastille_doc( array $e ) {
		$m = array( 'ok' => array( 'ok', 'à jour' ), 'bientot' => array( 'wait', 'à renouveler' ), 'expire' => array( 'ko', 'expiré' ), 'manquant' => array( 'neutre', 'absent' ) );
		$t = $m[ $e[1] ];
		return ige_ffm_pill( $e[0] . ' : ' . $t[1] . ( $e[2] && 'manquant' !== $e[1] ? ' (' . wp_date( 'd/m/Y', strtotime( $e[2] ) ) . ')' : '' ), $t[0] );
	}

	/**
	 * Onglet Véhicules : chiffres, alertes, liste, ajout.
	 *
	 * @return string
	 */
	private static function onglet_vehicules() {
		$vs   = IGE_FFM_Parc::vehicules();
		$ex   = IGE_FFM_Contexte::exercice();
		$co   = $ex ? IGE_FFM_Parc::couts( (int) $ex['id'] ) : array();
		$al   = IGE_FFM_Parc::alertes();
		$base = ige_ffm_url( 'parc-automobile' );
		$act  = array_filter( $vs, static fn( $v ) => 'reforme' !== $v['statut'] );
		$route = count( array_filter( $act, static fn( $v ) => IGE_FFM_Parc::en_route( (int) $v['id'] ) ) );
		$immo = count( array_filter( $act, static fn( $v ) => 'immobilise' === $v['statut'] ) );
		$tot  = array_sum( array_column( $co, 'total' ) );
		$km   = array_sum( array_column( $co, 'km' ) );
		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Véhicules en service</div><div class="ige-val"><?php echo (int) ( count( $act ) - $immo ); ?> <span>/ <?php echo count( $act ); ?></span></div><div class="ige-meta"><?php echo (int) $route; ?> en route · <?php echo (int) $immo; ?> immobilisé(s)</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Coût du parc (exercice)</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $tot, '' ) ); ?> <span>CDF</span></div><div class="ige-meta">carburant, entretien, sinistres, documents</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Kilomètres parcourus</div><div class="ige-val"><?php echo esc_html( number_format( $km, 0, ',', "\u{202F}" ) ); ?></div><div class="ige-meta"><?php echo $km ? esc_html( ige_ffm_montant( (int) round( $tot / $km ) ) . ' par km' ) : 'relevés à compléter'; ?></div></div>
			<div class="ige-card ige-kpi<?php echo array_filter( $al, static fn( $a ) => 'ko' === $a[0] ) ? ' ige-alerte' : ''; ?>"><div class="ige-lbl">Points à suivre</div><div class="ige-val"><?php echo count( $al ); ?></div><div class="ige-meta"><?php echo count( array_filter( $al, static fn( $a ) => 'ko' === $a[0] ) ); ?> urgent(s)</div></div>
		</div>
		<?php if ( $al ) : ?>
			<section class="ige-card" style="padding:14px 16px"><h2 style="margin-top:0">À suivre</h2><ul class="ige-alertes" style="margin:0;padding-left:18px">
				<?php foreach ( array_slice( $al, 0, 15 ) as $a ) : ?>
					<li class="ige-small" style="margin:3px 0"><?php echo ige_ffm_pill( 'ko' === $a[0] ? 'Urgent' : ( 'warn' === $a[0] ? 'À faire' : 'Info' ), 'ko' === $a[0] ? 'ko' : ( 'warn' === $a[0] ? 'wait' : 'info' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="<?php echo esc_url( $a[2] ); ?>"><?php echo esc_html( $a[1] ); ?></a></li>
				<?php endforeach; ?>
			</ul></section>
		<?php endif; ?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<h2 style="margin-top:0">Véhicules</h2>
				<div class="ige-table-wrap"><table class="ige-table">
					<thead><tr><th>Véhicule</th><th>Affectation</th><th>Chauffeur</th><th class="ige-r">Compteur</th><th>Documents</th><th>Situation</th></tr></thead>
					<tbody>
					<?php foreach ( $vs as $v ) : ?>
						<?php
						$docs = IGE_FFM_Parc::etat_documents( (int) $v['id'] );
						$etats = array_column( $docs, 1 );
						$pire  = in_array( 'expire', $etats, true ) ? 'expire' : ( in_array( 'manquant', $etats, true ) ? 'manquant' : ( in_array( 'bientot', $etats, true ) ? 'bientot' : 'ok' ) );
						$si = IGE_FFM_Parc::situation( $v );
						$ch = $v['chauffeur_id'] ? IGE_FFM_Parc::chauffeur( (int) $v['chauffeur_id'] ) : null;
						?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'vehicule', $v['id'], $base ) ); ?>"><b><?php echo esc_html( $v['immatriculation'] ); ?></b></a><br><span class="ige-small ige-muted"><?php echo esc_html( trim( $v['marque'] . ' ' . $v['modele'] ) . ' · ' . ( IGE_FFM_Parc::categories()[ $v['categorie'] ] ?? '' ) ); ?></span></td>
							<td><?php echo esc_html( $v['service_id'] ? IGE_FFM_Comptes::service_code( (int) $v['service_id'] ) : 'Pool central' ); ?></td>
							<td><?php echo esc_html( $ch ? $ch['nom'] : '—' ); ?></td>
							<td class="ige-r ige-num"><?php echo esc_html( number_format( (int) $v['km_actuel'], 0, ',', "\u{202F}" ) ); ?> km</td>
							<td><?php echo 'reforme' === $v['statut'] ? '—' : ige_ffm_pill( array( 'ok' => 'À jour', 'expire' => 'Expiré', 'manquant' => 'À compléter', 'bientot' => 'À renouveler' )[ $pire ], 'ok' === $pire ? 'ok' : ( 'expire' === $pire ? 'ko' : 'wait' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo ige_ffm_pill( $si[0], $si[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $vs ) : ?><tr><td colspan="6" class="ige-empty">Aucun véhicule enregistré.</td></tr><?php endif; ?>
					</tbody>
				</table></div>
			</section>
			<?php if ( IGE_FFM_Parc::gestionnaire() ) : ?>
				<section class="ige-card"><h2 style="margin-top:0">Ajouter un véhicule</h2><?php echo self::form_vehicule( null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire véhicule.
	 *
	 * @param array|null $v Véhicule modifié.
	 * @return string
	 */
	private static function form_vehicule( $v ) {
		$old = IGE_FFM_Ecrans::old();
		$g   = static fn( $k, $d = '' ) => (string) ( $old[ $k ] ?? ( $v[ $k ] ?? $d ) );
		$h   = IGE_FFM_Ecrans::form_open( 'parc_vehicule' ) . '<input type="hidden" name="id" value="' . esc_attr( $v['id'] ?? 0 ) . '">'
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'immatriculation', 'Immatriculation', $g( 'immatriculation' ), array( 'required' => true, 'attrs' => 'maxlength="20" placeholder="1234AB01" style="text-transform:uppercase"' ) )
			. IGE_FFM_Ecrans::field( 'categorie', 'Catégorie', $g( 'categorie', '4x4' ), array( 'type' => 'select', 'options' => IGE_FFM_Parc::categories() ) )
			. IGE_FFM_Ecrans::field( 'marque', 'Marque', $g( 'marque' ), array( 'required' => true, 'attrs' => 'maxlength="60" placeholder="Toyota"' ) )
			. IGE_FFM_Ecrans::field( 'modele', 'Modèle', $g( 'modele' ), array( 'attrs' => 'maxlength="60" placeholder="Land Cruiser"' ) )
			. IGE_FFM_Ecrans::field( 'annee', 'Année', $g( 'annee' ), array( 'type' => 'number', 'attrs' => 'min="1970" max="' . ( (int) wp_date( 'Y' ) + 1 ) . '"' ) )
			. IGE_FFM_Ecrans::field( 'energie', 'Énergie', $g( 'energie', 'diesel' ), array( 'type' => 'select', 'options' => IGE_FFM_Parc::energies() ) )
			. IGE_FFM_Ecrans::field( 'places', 'Places', $g( 'places' ), array( 'type' => 'number', 'attrs' => 'min="1" max="80"' ) )
			. IGE_FFM_Ecrans::field( 'reservoir', 'Réservoir (litres)', $g( 'reservoir' ), array( 'type' => 'number', 'attrs' => 'min="1" max="1000"' ) )
			. IGE_FFM_Ecrans::field( 'conso_ref', 'Consommation de référence (L/100 km)', $g( 'conso_ref' ), array( 'attrs' => 'inputmode="decimal" placeholder="12"', 'help' => 'Au-delà (+ tolérance), le plein est signalé.' ) )
			. ( $v ? '' : IGE_FFM_Ecrans::field( 'km_initial', 'Compteur à l’entrée dans le parc (km)', $g( 'km_initial', '0' ), array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) ) )
			. IGE_FFM_Ecrans::field( 'service_id', 'Affectation', $g( 'service_id' ), array( 'type' => 'select', 'options' => self::options( 'services', 'Pool central (Administration et Finance)' ) ) )
			. IGE_FFM_Ecrans::field( 'chauffeur_id', 'Chauffeur attitré', $g( 'chauffeur_id' ), array( 'type' => 'select', 'options' => self::options( 'chauffeurs', '— Aucun —' ) ) )
			. IGE_FFM_Ecrans::field( 'chassis', 'N° de châssis', $g( 'chassis' ), array( 'attrs' => 'maxlength="40"' ) )
			. IGE_FFM_Ecrans::field( 'date_acquisition', 'Date d’acquisition', $g( 'date_acquisition' ), array( 'type' => 'date' ) )
			. IGE_FFM_Ecrans::field( 'valeur', 'Valeur d’acquisition (CDF)', '' === $g( 'valeur' ) ? '' : ige_ffm_montant( (int) $g( 'valeur' ), '' ), array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. '</div><button type="submit" class="ige-btn ige-btn-jaune">' . ( $v ? 'Enregistrer' : 'Ajouter le véhicule' ) . '</button></form>';
		return $h;
	}

	/**
	 * Fiche d'un véhicule.
	 *
	 * @param int $id Véhicule.
	 * @return string
	 */
	private static function fiche( $id ) {
		$v = IGE_FFM_Parc::vehicule( $id );
		if ( ! $v ) {
			return ige_ffm_banner( 'ko', 'Véhicule introuvable', '' );
		}
		$base   = ige_ffm_url( 'parc-automobile' );
		$retour = add_query_arg( 'vehicule', $id, $base );
		$gest   = IGE_FFM_Parc::gestionnaire();
		$ex     = IGE_FFM_Contexte::exercice();
		$c      = $ex ? ( IGE_FFM_Parc::couts( (int) $ex['id'] )[ $id ] ?? null ) : null;
		$si     = IGE_FFM_Parc::situation( $v );
		$ch     = $v['chauffeur_id'] ? IGE_FFM_Parc::chauffeur( (int) $v['chauffeur_id'] ) : null;
		ob_start();
		?>
		<p><a href="<?php echo esc_url( $base ); ?>">← Tous les véhicules</a></p>
		<div class="ige-grid ige-cols-21 ige-start">
			<div>
				<section class="ige-card">
					<h2 style="margin-top:0"><?php echo esc_html( IGE_FFM_Parc::nom( $v ) ); ?> <?php echo ige_ffm_pill( $si[0], $si[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
					<?php if ( 'disponible' !== $v['statut'] && $v['motif_statut'] ) : ?><p class="ige-small"><b>Motif :</b> <?php echo esc_html( $v['motif_statut'] ); ?></p><?php endif; ?>
					<p><?php foreach ( IGE_FFM_Parc::etat_documents( $id ) as $e ) { echo self::pastille_doc( $e ) . ' '; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
					<?php if ( $c ) : ?>
						<div class="ige-grid ige-kpis" style="margin:10px 0">
							<div class="ige-card ige-kpi"><div class="ige-lbl">Coût de l’exercice</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( $c['total'], '' ) ); ?></div><div class="ige-meta">carburant <?php echo esc_html( ige_ffm_montant( $c['carburant'], '' ) ); ?> · entretien <?php echo esc_html( ige_ffm_montant( $c['entretien'] + $c['sinistres'], '' ) ); ?></div></div>
							<div class="ige-card ige-kpi"><div class="ige-lbl">Kilomètres</div><div class="ige-val"><?php echo esc_html( number_format( $c['km'], 0, ',', "\u{202F}" ) ); ?></div><div class="ige-meta"><?php echo null === $c['cout_km'] ? '—' : esc_html( ige_ffm_montant( $c['cout_km'] ) . ' par km' ); ?></div></div>
							<div class="ige-card ige-kpi"><div class="ige-lbl">Consommation</div><div class="ige-val"><?php echo null === $c['conso'] ? '—' : esc_html( number_format( $c['conso'], 1, ',', ' ' ) ); ?> <span>L/100</span></div><div class="ige-meta">référence <?php echo $v['conso_ref'] ? esc_html( number_format( (float) $v['conso_ref'], 1, ',', ' ' ) ) : '—'; ?></div></div>
						</div>
					<?php endif; ?>
					<h3 style="font-size:14px;margin:14px 0 6px">Échéances d’entretien</h3>
					<?php $ech = IGE_FFM_Parc::echeances( $v ); ?>
					<?php if ( $ech ) : ?>
						<ul class="ige-small" style="margin:0;padding-left:18px">
						<?php foreach ( $ech as $e ) : ?>
							<li><?php echo $e['du'] ? ige_ffm_pill( 'À faire', 'wait' ) . ' ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $e['objet'] . ( $e['date'] ? ' · le ' . wp_date( 'd/m/Y', strtotime( $e['date'] ) ) : '' ) . ( $e['km'] ? ' · à ' . number_format( $e['km'], 0, ',', "\u{202F}" ) . ' km' : '' ) . ( $e['planifie'] ? ' (planifié)' : '' ) ); ?></li>
						<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="ige-small ige-muted">Aucune échéance : planifiez le prochain entretien (vidange, révision) à l’enregistrement de chaque intervention.</p>
					<?php endif; ?>
				</section>
				<section class="ige-card" style="padding:16px">
					<h2 style="margin-top:0">Carnet de bord</h2>
					<?php echo self::table_deplacements( IGE_FFM_Parc::deplacements( array( 'vehicule_id' => $id, 'limite' => 15 ) ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section class="ige-card" style="padding:16px">
					<h2 style="margin-top:0">Carburant</h2>
					<?php echo self::table_pleins( IGE_FFM_Parc::pleins( array( 'vehicule_id' => $id, 'limite' => 15 ) ), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section class="ige-card" style="padding:16px">
					<h2 style="margin-top:0">Entretien, réparations et sinistres</h2>
					<?php echo self::table_interventions( IGE_FFM_Parc::interventions( array( 'vehicule_id' => $id ) ), false, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</section>
				<section class="ige-card" style="padding:16px">
					<h2 style="margin-top:0">Documents de bord</h2>
					<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Document</th><th>Référence</th><th>Validité</th><th class="ige-r">Montant</th><th>Scan</th></tr></thead><tbody>
					<?php foreach ( IGE_FFM_Parc::documents( $id ) as $d ) : ?>
						<?php $p = $d['piece_id'] ? self::piece( (int) $d['piece_id'] ) : null; ?>
						<tr><td><?php echo esc_html( IGE_FFM_Parc::types_documents()[ $d['type'] ][0] ?? $d['type'] ); ?></td><td><?php echo esc_html( trim( $d['reference'] . ' ' . $d['organisme'] ) ); ?></td><td><?php echo esc_html( ( $d['date_debut'] ? wp_date( 'd/m/Y', strtotime( $d['date_debut'] ) ) : '…' ) . ' → ' . ( $d['date_fin'] ? wp_date( 'd/m/Y', strtotime( $d['date_fin'] ) ) : '…' ) ); ?></td><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $d['montant'], '' ) ); ?></td><td><?php echo $p ? '<a href="' . esc_url( IGE_FFM_Pieces::url( $p ) ) . '" target="_blank" rel="noopener">Voir</a>' : '—'; ?></td></tr>
					<?php endforeach; ?>
					</tbody></table></div>
				</section>
			</div>
			<aside>
				<section class="ige-card">
					<dl class="ige-kv">
						<dt>Catégorie</dt><dd><?php echo esc_html( IGE_FFM_Parc::categories()[ $v['categorie'] ] ?? '' ); ?></dd>
						<dt>Énergie</dt><dd><?php echo esc_html( IGE_FFM_Parc::energies()[ $v['energie'] ] ?? '' ); ?></dd>
						<dt>Année</dt><dd><?php echo esc_html( $v['annee'] ?: '—' ); ?></dd>
						<dt>Places</dt><dd><?php echo esc_html( $v['places'] ?: '—' ); ?></dd>
						<dt>Compteur</dt><dd><b><?php echo esc_html( number_format( (int) $v['km_actuel'], 0, ',', "\u{202F}" ) ); ?> km</b></dd>
						<dt>Affectation</dt><dd><?php echo esc_html( $v['service_id'] ? IGE_FFM_Comptes::service_code( (int) $v['service_id'] ) : 'Pool central' ); ?></dd>
						<dt>Chauffeur</dt><dd><?php echo esc_html( $ch ? $ch['nom'] . ( $ch['telephone'] ? ' · ' . $ch['telephone'] : '' ) : '—' ); ?></dd>
						<dt>Châssis</dt><dd><?php echo esc_html( $v['chassis'] ?: '—' ); ?></dd>
						<dt>Acquisition</dt><dd><?php echo esc_html( ( $v['date_acquisition'] ? wp_date( 'd/m/Y', strtotime( $v['date_acquisition'] ) ) : '—' ) . ( $v['valeur'] ? ' · ' . ige_ffm_montant( (int) $v['valeur'] ) : '' ) ); ?></dd>
					</dl>
				</section>
				<?php if ( $gest ) : ?>
					<section class="ige-card"><h2 style="margin-top:0">Enregistrer un plein</h2><?php echo self::form_plein( $v, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
					<section class="ige-card"><h2 style="margin-top:0">Entretien, réparation, sinistre</h2><?php echo self::form_intervention( $v, $retour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
					<section class="ige-card"><h2 style="margin-top:0">Document de bord</h2>
						<?php
						$types = array();
						foreach ( IGE_FFM_Parc::types_documents() as $k => $t ) {
							$types[ $k ] = $t[0];
						}
						echo IGE_FFM_Ecrans::form_open( 'parc_document', 'enctype="multipart/form-data"' ) . '<input type="hidden" name="vehicule_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							. IGE_FFM_Ecrans::field( 'type', 'Document', '', array( 'type' => 'select', 'options' => $types ) )
							. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
							. IGE_FFM_Ecrans::field( 'reference', 'N° (police, PV…)', '', array( 'attrs' => 'maxlength="60"' ) )
							. IGE_FFM_Ecrans::field( 'organisme', 'Assureur / organisme', '', array( 'attrs' => 'maxlength="120" placeholder="SONAS…"' ) )
							. IGE_FFM_Ecrans::field( 'date_debut', 'Du', '', array( 'type' => 'date' ) )
							. IGE_FFM_Ecrans::field( 'date_fin', 'Au', '', array( 'type' => 'date' ) )
							. '</div>'
							. IGE_FFM_Ecrans::field( 'montant', 'Montant payé (CDF)', '', array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
							. '<div class="ige-field"><label for="ige-doc-scan">Scan (facultatif)</label><input type="file" id="ige-doc-scan" name="scan" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>'
							. '<button type="submit" class="ige-btn ige-btn-line">Enregistrer</button></form>';
						?>
					</section>
					<section class="ige-card"><h2 style="margin-top:0">Statut</h2>
						<?php
						echo IGE_FFM_Ecrans::form_open( 'parc_statut' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							. IGE_FFM_Ecrans::field( 'statut', 'Nouveau statut', $v['statut'], array( 'type' => 'select', 'options' => array_map( static fn( $s ) => $s[0], IGE_FFM_Parc::statuts() ) ) )
							. IGE_FFM_Ecrans::field( 'motif', 'Motif', '', array( 'attrs' => 'maxlength="190" placeholder="Panne moteur, accident, réforme…"' ) )
							. '<button type="submit" class="ige-btn ige-btn-line">Changer le statut</button></form>';
						?>
					</section>
					<section class="ige-card"><details><summary style="cursor:pointer;font-weight:600">Modifier la fiche</summary><?php echo self::form_vehicule( $v ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></details></section>
				<?php endif; ?>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Une pièce.
	 *
	 * @param int $id Pièce.
	 * @return array|null
	 */
	private static function piece( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$p = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'piece' ) . ' WHERE id = %d', $id ), ARRAY_A );
		return $p ?: null;
	}

	/**
	 * Formulaire de plein.
	 *
	 * @param array|null $v      Véhicule (null : liste à choisir).
	 * @param string     $retour Retour.
	 * @param array      $liste  Véhicules proposés (si $v est null).
	 * @return string
	 */
	public static function form_plein( $v, $retour, array $liste = array() ) {
		$auj = IGE_FFM_Contexte::aujourdhui();
		$h   = IGE_FFM_Ecrans::form_open( 'parc_plein' ) . '<input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">';
		if ( $v ) {
			$h .= '<input type="hidden" name="vehicule_id" value="' . esc_attr( $v['id'] ) . '">';
		} else {
			$o = array();
			foreach ( $liste as $x ) {
				$o[ $x['id'] ] = IGE_FFM_Parc::nom( $x );
			}
			$h .= IGE_FFM_Ecrans::field( 'vehicule_id', 'Véhicule', '', array( 'type' => 'select', 'options' => $o ) );
		}
		return $h . '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'date', 'Date', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) )
			. IGE_FFM_Ecrans::field( 'km', 'Compteur (km)', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' . ( $v ? ' placeholder="' . esc_attr( $v['km_actuel'] ) . '"' : '' ) ) )
			. IGE_FFM_Ecrans::field( 'litres', 'Litres', '', array( 'required' => true, 'attrs' => 'inputmode="decimal" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'mode', 'Paiement', 'bon', array( 'type' => 'select', 'options' => array( 'bon' => 'Bon carburant', 'carte' => 'Carte carburant', 'especes' => 'Espèces' ) ) )
			. IGE_FFM_Ecrans::field( 'bon_numero', 'N° du bon', '', array( 'attrs' => 'maxlength="40"' ) )
			. '</div>'
			. IGE_FFM_Ecrans::field( 'station', 'Station', '', array( 'attrs' => 'maxlength="120"' ) )
			. IGE_FFM_Ecrans::field( 'plein', 'Plein complet (sert au calcul de la consommation)', true, array( 'type' => 'checkbox' ) )
			. IGE_FFM_Ecrans::field( 'ecriture', 'Écriture du livre de caisse (facultatif)', '', array( 'attrs' => 'maxlength="40" placeholder="8000IG-2026-000015"', 'help' => 'Relie le plein à la dépense payée.' ) )
			. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer le plein</button></form>';
	}

	/**
	 * Formulaire d'intervention.
	 *
	 * @param array  $v      Véhicule.
	 * @param string $retour Retour.
	 * @return string
	 */
	private static function form_intervention( array $v, $retour ) {
		return IGE_FFM_Ecrans::form_open( 'parc_intervention', 'enctype="multipart/form-data"' ) . '<input type="hidden" name="vehicule_id" value="' . esc_attr( $v['id'] ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
			. IGE_FFM_Ecrans::field( 'nature', 'Nature', 'entretien', array( 'type' => 'select', 'options' => IGE_FFM_Parc::natures() ) )
			. IGE_FFM_Ecrans::field( 'objet', 'Objet', '', array( 'required' => true, 'attrs' => 'maxlength="190" placeholder="Vidange et filtres, révision 10 000 km…"' ) )
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'date_prevue', 'Prévue le (planification)', '', array( 'type' => 'date' ) )
			. IGE_FFM_Ecrans::field( 'date_realisee', 'Réalisée le', '', array( 'type' => 'date', 'attrs' => 'max="' . esc_attr( IGE_FFM_Contexte::aujourdhui() ) . '"' ) )
			. IGE_FFM_Ecrans::field( 'km', 'Compteur (km)', '', array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', '', array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'prochaine_km', 'Prochaine échéance (km)', '', array( 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
			. IGE_FFM_Ecrans::field( 'prochaine_date', 'Prochaine échéance (date)', '', array( 'type' => 'date' ) )
			. '</div>'
			. IGE_FFM_Ecrans::field( 'garage', 'Garage', '', array( 'attrs' => 'maxlength="120"' ) )
			. '<div class="ige-field"><label for="ige-int-desc">Description (obligatoire pour un sinistre)</label><textarea id="ige-int-desc" name="description" rows="3" maxlength="4000" placeholder="Circonstances, lieu, tiers, constat, déclaration à l’assurance…"></textarea></div>'
			. IGE_FFM_Ecrans::field( 'immobilise', 'Véhicule immobilisé jusqu’à la réalisation', false, array( 'type' => 'checkbox' ) )
			. IGE_FFM_Ecrans::field( 'ecriture', 'Écriture du livre de caisse (facultatif)', '', array( 'attrs' => 'maxlength="40"' ) )
			. '<div class="ige-field"><label for="ige-int-piece">Facture ou constat (facultatif)</label><input type="file" id="ige-int-piece" name="piece" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>'
			. '<button type="submit" class="ige-btn ige-btn-line">Enregistrer</button></form>';
	}

	/**
	 * Tableau des déplacements.
	 *
	 * @param array[] $rows     Déplacements.
	 * @param bool    $vehicule Afficher la colonne véhicule.
	 * @param string  $page     Page des liens (parc-automobile | vehicules).
	 * @return string
	 */
	public static function table_deplacements( array $rows, $vehicule = true, $page = 'parc-automobile' ) {
		$base = ige_ffm_url( $page );
		$h    = '<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>N°</th><th>Service</th><th>Objet · destination</th><th>Période</th>' . ( $vehicule ? '<th>Véhicule</th>' : '' ) . '<th class="ige-r">Distance</th><th>État</th></tr></thead><tbody>';
		foreach ( $rows as $x ) {
			$st = IGE_FFM_Parc::statuts_deplacement()[ $x['statut'] ];
			$v  = $x['vehicule_id'] ? IGE_FFM_Parc::vehicule( (int) $x['vehicule_id'] ) : null;
			$h .= '<tr><td><a href="' . esc_url( add_query_arg( 'deplacement', $x['id'], $base ) ) . '"><b>' . esc_html( $x['numero'] ) . '</b></a></td><td>' . esc_html( IGE_FFM_Comptes::service_code( (int) $x['service_id'] ) ) . '</td><td>' . esc_html( $x['objet'] ) . '<br><span class="ige-small ige-muted">' . esc_html( $x['destination'] ) . '</span></td><td style="white-space:nowrap" class="ige-small">' . esc_html( wp_date( 'd/m H:i', strtotime( $x['depart_prevu'] ) ) ) . '<br>' . esc_html( wp_date( 'd/m H:i', strtotime( $x['retour_prevu'] ) ) ) . '</td>' . ( $vehicule ? '<td>' . esc_html( $v ? $v['immatriculation'] : '—' ) . '</td>' : '' ) . '<td class="ige-r ige-num">' . esc_html( null !== $x['km_retour'] ? number_format( (int) $x['km_retour'] - (int) $x['km_depart'], 0, ',', "\u{202F}" ) . ' km' : '—' ) . '</td><td>' . ige_ffm_pill( $st[0], $st[1] ) . '</td></tr>';
		}
		if ( ! $rows ) {
			$h .= '<tr><td colspan="7" class="ige-empty">Aucun déplacement.</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/**
	 * Tableau des pleins.
	 *
	 * @param array[] $rows     Pleins.
	 * @param bool    $vehicule Colonne véhicule.
	 * @return string
	 */
	public static function table_pleins( array $rows, $vehicule = true ) {
		$h = '<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Date</th>' . ( $vehicule ? '<th>Véhicule</th>' : '' ) . '<th class="ige-r">Compteur</th><th class="ige-r">Litres</th><th class="ige-r">Montant</th><th class="ige-r">L/100 km</th><th>Bon · station</th><th>Contrôle</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			$h .= '<tr><td>' . esc_html( wp_date( 'd/m/Y', strtotime( $p['date_plein'] ) ) ) . '</td>' . ( $vehicule ? '<td>' . esc_html( $p['immatriculation'] ) . '</td>' : '' ) . '<td class="ige-r ige-num">' . esc_html( number_format( (int) $p['km'], 0, ',', "\u{202F}" ) ) . '</td><td class="ige-r ige-num">' . esc_html( number_format( (float) $p['litres'], 2, ',', ' ' ) . ( (int) $p['plein'] ? '' : ' (partiel)' ) ) . '</td><td class="ige-r ige-num">' . esc_html( ige_ffm_montant( (int) $p['montant'], '' ) ) . '</td><td class="ige-r ige-num">' . esc_html( null === $p['conso'] ? '—' : number_format( (float) $p['conso'], 1, ',', ' ' ) ) . '</td><td class="ige-small">' . esc_html( trim( $p['bon_numero'] . ' ' . $p['station'] ) ?: '—' ) . '</td><td>' . ( $p['anomalie'] ? ige_ffm_pill( 'Anomalie', 'ko' ) . '<div class="ige-small" style="color:var(--ige-ko-txt)">' . esc_html( $p['anomalie'] ) . '</div>' : ige_ffm_pill( 'Normal', 'ok' ) ) . '</td></tr>';
		}
		if ( ! $rows ) {
			$h .= '<tr><td colspan="8" class="ige-empty">Aucun plein enregistré.</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/**
	 * Tableau des interventions.
	 *
	 * @param array[] $rows     Interventions.
	 * @param bool    $vehicule Colonne véhicule.
	 * @param string  $retour   Retour (formulaire de réalisation).
	 * @return string
	 */
	private static function table_interventions( array $rows, $vehicule, $retour ) {
		$gest = IGE_FFM_Parc::gestionnaire();
		$h    = '<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Date</th>' . ( $vehicule ? '<th>Véhicule</th>' : '' ) . '<th>Nature · objet</th><th>Garage</th><th class="ige-r">Montant</th><th>État</th></tr></thead><tbody>';
		foreach ( $rows as $e ) {
			$etat = 'realise' === $e['statut'] ? ige_ffm_pill( 'Réalisé', 'ok' ) : ige_ffm_pill( 'Planifié', $e['date_prevue'] && $e['date_prevue'] <= IGE_FFM_Contexte::aujourdhui() ? 'ko' : 'wait' );
			if ( 'planifie' === $e['statut'] && $gest ) {
				$etat .= '<details class="ige-small"><summary style="cursor:pointer">Clore</summary>' . IGE_FFM_Ecrans::form_open( 'parc_realiser' ) . '<input type="hidden" name="id" value="' . esc_attr( $e['id'] ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">'
					. IGE_FFM_Ecrans::field( 'date_realisee', 'Réalisée le', IGE_FFM_Contexte::aujourdhui(), array( 'type' => 'date' ) )
					. IGE_FFM_Ecrans::field( 'km', 'Compteur', '', array( 'attrs' => 'inputmode="numeric"' ) )
					. IGE_FFM_Ecrans::field( 'montant', 'Montant (CDF)', '', array( 'attrs' => 'inputmode="numeric"' ) )
					. IGE_FFM_Ecrans::field( 'prochaine_km', 'Prochaine échéance (km)', '', array( 'attrs' => 'inputmode="numeric"' ) )
					. IGE_FFM_Ecrans::field( 'prochaine_date', 'Prochaine échéance (date)', '', array( 'type' => 'date' ) )
					. '<button type="submit" class="ige-btn ige-btn-line">Marquer réalisé</button></form></details>';
			}
			$h .= '<tr><td class="ige-small">' . esc_html( wp_date( 'd/m/Y', strtotime( $e['date_realisee'] ?: $e['date_prevue'] ) ) ) . '</td>' . ( $vehicule ? '<td>' . esc_html( $e['immatriculation'] ) . '</td>' : '' ) . '<td><b>' . esc_html( IGE_FFM_Parc::natures()[ $e['nature'] ] ?? '' ) . '</b> · ' . esc_html( $e['objet'] ) . ( $e['description'] ? '<div class="ige-small ige-muted">' . esc_html( wp_trim_words( $e['description'], 25 ) ) . '</div>' : '' ) . '</td><td>' . esc_html( $e['garage'] ?: '—' ) . '</td><td class="ige-r ige-num">' . esc_html( ige_ffm_montant( (int) $e['montant'], '' ) ) . '</td><td>' . $etat . '</td></tr>';
		}
		if ( ! $rows ) {
			$h .= '<tr><td colspan="6" class="ige-empty">Aucune intervention.</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/**
	 * Onglet Demandes et ordres de route.
	 *
	 * @return string
	 */
	private static function onglet_deplacements() {
		$dem  = IGE_FFM_Parc::deplacements( array( 'statut' => 'demande' ) );
		$cour = IGE_FFM_Parc::deplacements( array( 'statut' => array( 'attribue', 'en_cours' ) ) );
		$fin  = IGE_FFM_Parc::deplacements( array( 'statut' => array( 'termine', 'refuse', 'annule' ), 'limite' => 30 ) );
		ob_start();
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<div>
				<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Demandes à traiter (<?php echo count( $dem ); ?>)</h2><?php echo self::table_deplacements( $dem ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
				<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Attribués et en route</h2><?php echo self::table_deplacements( $cour ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
				<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Derniers déplacements clos</h2><?php echo self::table_deplacements( $fin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
			</div>
			<?php if ( IGE_FFM_Parc::gestionnaire() ) : ?>
				<section class="ige-card"><h2 style="margin-top:0">Nouvel ordre de route</h2><p class="ige-small">Pour un déplacement décidé par Administration et Finance ; les services font leur demande depuis leur page « Véhicules ».</p><?php echo self::form_demande( true, ige_ffm_url( 'parc-automobile', array( 'onglet' => 'deplacements' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Formulaire de demande de véhicule.
	 *
	 * @param bool   $central Saisie par la gestion du parc (choix du service).
	 * @param string $retour  Retour.
	 * @param int    $sid     Service (missions proposées).
	 * @return string
	 */
	public static function form_demande( $central, $retour, $sid = 0 ) {
		$old = IGE_FFM_Ecrans::old();
		$auj = IGE_FFM_Contexte::aujourdhui();
		$h   = IGE_FFM_Ecrans::form_open( 'parc_demande' ) . '<input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">';
		if ( $central ) {
			$h .= IGE_FFM_Ecrans::field( 'service_id', 'Service demandeur', $old['service_id'] ?? '', array( 'type' => 'select', 'options' => self::options( 'services', '— Choisir —' ), 'required' => true ) );
		}
		$h .= IGE_FFM_Ecrans::field( 'objet', 'Objet', $old['objet'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="255" placeholder="Inspection, dépôt de courrier, réunion au Ministère…"' ) )
			. IGE_FFM_Ecrans::field( 'destination', 'Destination', $old['destination'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="190"' ) )
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'depart', 'Départ', $old['depart'] ?? $auj . 'T08:00', array( 'type' => 'datetime-local', 'required' => true ) )
			. IGE_FFM_Ecrans::field( 'retour_prevu', 'Retour', $old['retour_prevu'] ?? $auj . 'T16:00', array( 'type' => 'datetime-local', 'required' => true ) )
			. IGE_FFM_Ecrans::field( 'passagers', 'Passagers', $old['passagers'] ?? '1', array( 'type' => 'number', 'attrs' => 'min="1" max="60"' ) )
			. '</div>';
		if ( $sid && IGE_FFM_Fonctions::active( 'missions' ) ) {
			$ex = IGE_FFM_Contexte::exercice();
			$o  = array( '' => '— Aucune —' );
			foreach ( $ex ? IGE_FFM_Missions::liste( (int) $ex['id'], $sid ) : array() as $m ) {
				if ( in_array( $m['statut'], array( 'soumise', 'approuvee', 'payee' ), true ) ) {
					$o[ $m['id'] ] = $m['numero'] . ' · ' . $m['destination'];
				}
			}
			if ( count( $o ) > 1 ) {
				$h .= IGE_FFM_Ecrans::field( 'mission_id', 'Mission d’inspection liée', '', array( 'type' => 'select', 'options' => $o ) );
			}
		}
		return $h . '<button type="submit" class="ige-btn ige-btn-jaune">' . ( $central ? 'Créer l’ordre de route' : 'Demander un véhicule' ) . '</button></form>';
	}

	/**
	 * Détail d'un déplacement (ordre de route) : attribution, départ, retour.
	 *
	 * @param int    $id   Déplacement.
	 * @param string $page Page (parc-automobile | vehicules).
	 * @return string
	 */
	public static function ordre( $id, $page = 'parc-automobile' ) {
		$x = IGE_FFM_Parc::deplacement( $id );
		if ( ! $x || ( 'vehicules' === $page && ! IGE_FFM_Contexte::peut_voir( (int) $x['service_id'] ) ) ) {
			return ige_ffm_banner( 'ko', 'Déplacement introuvable', '' );
		}
		$uid    = get_current_user_id();
		$gest   = IGE_FFM_Parc::gestionnaire( $uid );
		$base   = ige_ffm_url( $page );
		$retour = add_query_arg( 'deplacement', $id, $base );
		$st     = IGE_FFM_Parc::statuts_deplacement()[ $x['statut'] ];
		$v      = $x['vehicule_id'] ? IGE_FFM_Parc::vehicule( (int) $x['vehicule_id'] ) : null;
		$ch     = $x['chauffeur_id'] ? IGE_FFM_Parc::chauffeur( (int) $x['chauffeur_id'] ) : null;
		$dem    = get_userdata( (int) $x['demandeur_id'] );
		$m      = $x['mission_id'] ? IGE_FFM_Missions::get( (int) $x['mission_id'] ) : null;
		ob_start();
		?>
		<p><a href="<?php echo esc_url( 'vehicules' === $page ? $base : add_query_arg( 'onglet', 'deplacements', $base ) ); ?>">← Retour à la liste</a></p>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card">
				<h2 style="margin-top:0"><?php echo esc_html( $x['numero'] . ' · ' . $x['objet'] ); ?> <?php echo ige_ffm_pill( $st[0], $st[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
				<dl class="ige-kv">
					<dt>Service</dt><dd><?php echo esc_html( IGE_FFM_Comptes::service_code( (int) $x['service_id'] ) . ( $dem ? ' · demandé par ' . $dem->display_name : '' ) ); ?></dd>
					<dt>Destination</dt><dd><?php echo esc_html( $x['destination'] ); ?></dd>
					<dt>Période prévue</dt><dd><?php echo esc_html( wp_date( 'd/m/Y H:i', strtotime( $x['depart_prevu'] ) ) . ' → ' . wp_date( 'd/m/Y H:i', strtotime( $x['retour_prevu'] ) ) ); ?></dd>
					<dt>Passagers</dt><dd><?php echo (int) $x['passagers']; ?></dd>
					<?php if ( $m ) : ?><dt>Mission</dt><dd><?php echo esc_html( $m['numero'] . ' · ' . $m['objet'] ); ?></dd><?php endif; ?>
					<dt>Véhicule</dt><dd><?php echo esc_html( $v ? IGE_FFM_Parc::nom( $v ) : '—' ); ?></dd>
					<dt>Chauffeur</dt><dd><?php echo esc_html( $ch ? $ch['nom'] . ( $ch['telephone'] ? ' · ' . $ch['telephone'] : '' ) : '—' ); ?></dd>
					<?php if ( null !== $x['km_depart'] ) : ?><dt>Départ</dt><dd><?php echo esc_html( number_format( (int) $x['km_depart'], 0, ',', "\u{202F}" ) . ' km · ' . wp_date( 'd/m/Y H:i', strtotime( $x['sortie_le'] ) ) ); ?></dd><?php endif; ?>
					<?php if ( null !== $x['km_retour'] ) : ?><dt>Retour</dt><dd><?php echo esc_html( number_format( (int) $x['km_retour'], 0, ',', "\u{202F}" ) . ' km · ' . wp_date( 'd/m/Y H:i', strtotime( $x['retour_le'] ) ) . ' · ' . number_format( (int) $x['km_retour'] - (int) $x['km_depart'], 0, ',', "\u{202F}" ) . ' km parcourus' ); ?></dd><?php endif; ?>
					<?php if ( $x['observations'] ) : ?><dt>Observations</dt><dd><?php echo nl2br( esc_html( $x['observations'] ) ); ?></dd><?php endif; ?>
					<?php if ( $x['motif'] ) : ?><dt>Motif</dt><dd><?php echo esc_html( $x['motif'] ); ?></dd><?php endif; ?>
				</dl>
			</section>
			<aside class="ige-card">
				<?php if ( $gest && in_array( $x['statut'], array( 'demande', 'attribue' ), true ) ) : ?>
					<h2 style="margin-top:0"><?php echo 'demande' === $x['statut'] ? 'Attribuer' : 'Changer l’attribution'; ?></h2>
					<p class="ige-small">Contrôlés à l’enregistrement : véhicule en service et libre sur la période, assurance et contrôle technique valides, places, permis et disponibilité du chauffeur.</p>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'parc_attribuer' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'vehicule_id', 'Véhicule', (string) $x['vehicule_id'], array( 'type' => 'select', 'options' => self::options( 'vehicules', '— Choisir —' ), 'required' => true ) )
						. IGE_FFM_Ecrans::field( 'chauffeur_id', 'Chauffeur', (string) $x['chauffeur_id'], array( 'type' => 'select', 'options' => self::options( 'chauffeurs', '— Sans chauffeur —' ) ) )
						. '<button type="submit" class="ige-btn ige-btn-jaune">Attribuer</button></form>';
					?>
				<?php endif; ?>
				<?php if ( 'attribue' === $x['statut'] && IGE_FFM_Parc::peut_conduire( $x, $uid ) ) : ?>
					<h2>Départ</h2>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'parc_depart' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'km', 'Compteur au départ (km)', $v ? (string) $v['km_actuel'] : '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num"' ) )
						. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer le départ</button></form>';
					?>
				<?php endif; ?>
				<?php if ( 'en_cours' === $x['statut'] && IGE_FFM_Parc::peut_conduire( $x, $uid ) ) : ?>
					<h2 style="margin-top:0">Retour</h2>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'parc_retour' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'km', 'Compteur au retour (km)', '', array( 'required' => true, 'attrs' => 'inputmode="numeric" class="ige-num" min="' . esc_attr( $x['km_depart'] ) . '"' ) )
						. '<div class="ige-field"><label for="ige-obs">Observations (incident, panne, accrochage)</label><textarea id="ige-obs" name="observations" rows="3" maxlength="2000"></textarea></div>'
						. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer le retour</button></form>';
					?>
				<?php endif; ?>
				<?php if ( $gest && 'demande' === $x['statut'] ) : ?>
					<h2>Refuser</h2>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'parc_clore' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="statut" value="refuse"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'motif', 'Motif', '', array( 'required' => true, 'attrs' => 'minlength="5" maxlength="255" placeholder="Aucun véhicule disponible, déplacement non prioritaire…"' ) )
						. '<button type="submit" class="ige-btn ige-btn-rouge">Refuser la demande</button></form>';
					?>
				<?php endif; ?>
				<?php if ( in_array( $x['statut'], array( 'demande', 'attribue' ), true ) && ( $gest || IGE_FFM_Parc::peut_conduire( $x, $uid ) ) ) : ?>
					<details style="margin-top:12px"><summary class="ige-small" style="cursor:pointer;font-weight:600">Annuler le déplacement</summary>
					<?php
					echo IGE_FFM_Ecrans::form_open( 'parc_clore' ) . '<input type="hidden" name="id" value="' . esc_attr( $id ) . '"><input type="hidden" name="statut" value="annule"><input type="hidden" name="retour" value="' . esc_attr( $retour ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						. IGE_FFM_Ecrans::field( 'motif', 'Motif', '', array( 'required' => true, 'attrs' => 'minlength="5" maxlength="255"' ) )
						. '<button type="submit" class="ige-btn ige-btn-line">Annuler</button></form>';
					?>
					</details>
				<?php endif; ?>
				<?php if ( in_array( $x['statut'], array( 'termine', 'refuse', 'annule' ), true ) ) : ?>
					<p class="ige-small ige-muted">Déplacement clos.</p>
				<?php endif; ?>
			</aside>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Onglet Carburant.
	 *
	 * @return string
	 */
	private static function onglet_carburant() {
		$ex = IGE_FFM_Contexte::exercice();
		$an = isset( $_GET['anomalies'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$p  = IGE_FFM_Parc::pleins( array( 'exercice_id' => $ex ? (int) $ex['id'] : 0, 'anomalies' => $an, 'limite' => 100 ) );
		$base = ige_ffm_url( 'parc-automobile', array( 'onglet' => 'carburant' ) );
		ob_start();
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<div class="ige-toolbar"><h2 style="margin:0">Pleins de l’exercice</h2>
					<a class="ige-chip<?php echo $an ? '' : ' ige-on'; ?>" href="<?php echo esc_url( $base ); ?>">Tous</a>
					<a class="ige-chip<?php echo $an ? ' ige-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'anomalies', 1, $base ) ); ?>">Anomalies</a>
				</div>
				<?php echo self::table_pleins( $p ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="ige-small ige-muted" style="margin:10px 0 0">La consommation se calcule entre deux pleins complets : litres mis depuis le plein précédent, divisés par les kilomètres parcourus. Elle est signalée au-delà de la référence du véhicule de plus de <?php echo (int) IGE_FFM_Reglages::get( 'parc_tolerance_conso' ); ?> % (réglable).</p>
			</section>
			<?php if ( IGE_FFM_Parc::gestionnaire() ) : ?>
				<section class="ige-card"><h2 style="margin-top:0">Enregistrer un plein</h2><?php echo self::form_plein( null, $base, IGE_FFM_Parc::vehicules( array( 'sans_reformes' => true ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Onglet Entretien et sinistres.
	 *
	 * @return string
	 */
	private static function onglet_entretiens() {
		$ex   = IGE_FFM_Contexte::exercice();
		$plan = IGE_FFM_Parc::interventions( array( 'statut' => 'planifie' ) );
		$fait = IGE_FFM_Parc::interventions( array( 'statut' => 'realise', 'exercice_id' => $ex ? (int) $ex['id'] : 0, 'limite' => 60 ) );
		$base = ige_ffm_url( 'parc-automobile', array( 'onglet' => 'entretiens' ) );
		$dues = array();
		foreach ( IGE_FFM_Parc::vehicules( array( 'sans_reformes' => true ) ) as $v ) {
			foreach ( IGE_FFM_Parc::echeances( $v ) as $e ) {
				if ( $e['du'] && ! $e['planifie'] ) {
					$dues[] = array( $v, $e );
				}
			}
		}
		ob_start();
		?>
		<?php if ( $dues ) : ?>
			<section class="ige-card" style="padding:14px 16px"><h2 style="margin-top:0">Entretiens arrivés à échéance</h2><ul class="ige-small" style="margin:0;padding-left:18px">
			<?php foreach ( $dues as $d ) : ?>
				<li><a href="<?php echo esc_url( add_query_arg( 'vehicule', $d[0]['id'], ige_ffm_url( 'parc-automobile' ) ) ); ?>"><?php echo esc_html( $d[0]['immatriculation'] ); ?></a> : <?php echo esc_html( $d[1]['objet'] . ' (' . $d[1]['raison'] . ')' ); ?></li>
			<?php endforeach; ?>
			</ul></section>
		<?php endif; ?>
		<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Planifié</h2><?php echo self::table_interventions( $plan, true, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
		<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Réalisé sur l’exercice</h2><?php echo self::table_interventions( $fait, true, $base ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ige-small ige-muted" style="margin:10px 0 0">Les interventions s’enregistrent depuis la fiche de chaque véhicule.</p></section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Onglet Chauffeurs.
	 *
	 * @return string
	 */
	private static function onglet_chauffeurs() {
		$cs   = IGE_FFM_Parc::chauffeurs();
		$edit = isset( $_GET['modifier'] ) ? IGE_FFM_Parc::chauffeur( (int) $_GET['modifier'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$auj  = IGE_FFM_Contexte::aujourdhui();
		$base = ige_ffm_url( 'parc-automobile', array( 'onglet' => 'chauffeurs' ) );
		ob_start();
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<section class="ige-card" style="padding:16px">
				<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Nom</th><th>Téléphone</th><th>Service</th><th>Permis</th><th>Validité</th><th>Actif</th><th></th></tr></thead><tbody>
				<?php foreach ( $cs as $c ) : ?>
					<tr>
						<td><b><?php echo esc_html( $c['nom'] ); ?></b></td>
						<td><?php echo esc_html( $c['telephone'] ?: '—' ); ?></td>
						<td><?php echo esc_html( $c['service_id'] ? IGE_FFM_Comptes::service_code( (int) $c['service_id'] ) : 'Pool' ); ?></td>
						<td class="ige-small"><?php echo esc_html( trim( $c['permis_numero'] . ' ' . $c['permis_categories'] ) ); ?></td>
						<td><?php echo $c['permis_expire'] ? ige_ffm_pill( wp_date( 'd/m/Y', strtotime( $c['permis_expire'] ) ), $c['permis_expire'] < $auj ? 'ko' : ( $c['permis_expire'] <= gmdate( 'Y-m-d', strtotime( $auj . ' +30 days' ) ) ? 'wait' : 'ok' ) ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo ige_ffm_switch( (bool) $c['actif'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php if ( IGE_FFM_Parc::gestionnaire() ) : ?><a class="ige-act" href="<?php echo esc_url( add_query_arg( 'modifier', $c['id'], $base ) ); ?>">Modifier</a><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $cs ) : ?><tr><td colspan="7" class="ige-empty">Aucun chauffeur enregistré.</td></tr><?php endif; ?>
				</tbody></table></div>
			</section>
			<?php if ( IGE_FFM_Parc::gestionnaire() ) : ?>
				<section class="ige-card"><h2 style="margin-top:0"><?php echo $edit ? 'Modifier : ' . esc_html( $edit['nom'] ) : 'Ajouter un chauffeur'; ?></h2>
				<?php
				echo IGE_FFM_Ecrans::form_open( 'parc_chauffeur' ) . '<input type="hidden" name="id" value="' . esc_attr( $edit['id'] ?? 0 ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					. IGE_FFM_Ecrans::field( 'nom', 'Nom complet', $edit['nom'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="120"' ) )
					. IGE_FFM_Ecrans::field( 'telephone', 'Téléphone', $edit['telephone'] ?? '', array( 'type' => 'tel', 'attrs' => 'maxlength="30"' ) )
					. IGE_FFM_Ecrans::field( 'service_id', 'Service', (string) ( $edit['service_id'] ?? '' ), array( 'type' => 'select', 'options' => self::options( 'services', 'Pool central' ) ) )
					. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
					. IGE_FFM_Ecrans::field( 'permis_numero', 'N° du permis', $edit['permis_numero'] ?? '', array( 'required' => true, 'attrs' => 'maxlength="40"' ) )
					. IGE_FFM_Ecrans::field( 'permis_categories', 'Catégories', $edit['permis_categories'] ?? '', array( 'attrs' => 'maxlength="20" placeholder="B, C, D"' ) )
					. '</div>'
					. IGE_FFM_Ecrans::field( 'permis_expire', 'Permis valable jusqu’au', $edit['permis_expire'] ?? '', array( 'type' => 'date', 'required' => true ) )
					. ( $edit ? IGE_FFM_Ecrans::field( 'actif', 'Actif', (bool) $edit['actif'], array( 'type' => 'checkbox' ) ) : '' )
					. '<button type="submit" class="ige-btn ige-btn-jaune">' . ( $edit ? 'Enregistrer' : 'Ajouter' ) . '</button></form>';
				?>
				</section>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Onglet Coûts.
	 *
	 * @return string
	 */
	private static function onglet_couts() {
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$co  = IGE_FFM_Parc::couts( (int) $ex['id'] );
		uasort( $co, static fn( $a, $b ) => $b['total'] <=> $a['total'] );
		$med = IGE_FFM_Analyses::mediane( array_filter( array_column( $co, 'cout_km' ) ) );
		$t   = array( 'carburant' => 0, 'entretien' => 0, 'sinistres' => 0, 'documents' => 0, 'total' => 0, 'km' => 0 );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<h2 style="margin-top:0">Coût par véhicule · exercice <?php echo esc_html( $ex['annee'] ); ?></h2>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Véhicule</th><th class="ige-r">Carburant</th><th class="ige-r">Litres</th><th class="ige-r">Entretien</th><th class="ige-r">Sinistres</th><th class="ige-r">Documents</th><th class="ige-r">Total</th><th class="ige-r">Km</th><th class="ige-r">Coût/km</th><th class="ige-r">L/100</th></tr></thead>
				<tbody>
				<?php foreach ( $co as $vid => $c ) : ?>
					<?php
					foreach ( $t as $k => $x ) {
						$t[ $k ] += $c[ $k ];
					}
					$haut = $med && $c['cout_km'] && $c['cout_km'] > 1.5 * $med;
					?>
					<tr>
						<td><a href="<?php echo esc_url( add_query_arg( 'vehicule', $vid, ige_ffm_url( 'parc-automobile' ) ) ); ?>"><?php echo esc_html( $c['vehicule']['immatriculation'] ); ?></a></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $c['carburant'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( number_format( $c['litres'], 0, ',', "\u{202F}" ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $c['entretien'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $c['sinistres'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $c['documents'], '' ) ); ?></td>
						<td class="ige-r ige-num"><b><?php echo esc_html( ige_ffm_montant( $c['total'], '' ) ); ?></b></td>
						<td class="ige-r ige-num"><?php echo esc_html( number_format( $c['km'], 0, ',', "\u{202F}" ) ); ?></td>
						<td class="ige-r ige-num<?php echo $haut ? ' ige-neg' : ''; ?>"><?php echo esc_html( null === $c['cout_km'] ? '—' : ige_ffm_montant( $c['cout_km'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( null === $c['conso'] ? '—' : number_format( $c['conso'], 1, ',', ' ' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="ige-tot"><td>Total</td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $t['carburant'], '' ) ); ?></td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $t['entretien'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $t['sinistres'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $t['documents'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $t['total'], '' ) ); ?></td><td class="ige-r"><?php echo esc_html( number_format( $t['km'], 0, ',', "\u{202F}" ) ); ?></td><td class="ige-r"><?php echo $t['km'] ? esc_html( ige_ffm_montant( (int) round( $t['total'] / $t['km'] ), '' ) ) : '—'; ?></td><td></td></tr>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Kilomètres de l’exercice d’après les relevés (pleins et carnets de bord). En rouge : coût au kilomètre supérieur de moitié à la médiane du parc. Les montants viennent des saisies du parc ; reliez-les aux écritures du livre de caisse pour les rapprocher des dépenses.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ Traitements */

	/**
	 * Résultat standard et retour.
	 *
	 * @param mixed  $r      Résultat.
	 * @param string $ok     Titre en cas de succès.
	 * @param string $ko     Titre en cas d'échec.
	 * @param string $defaut Page par défaut.
	 * @param string $texte  Texte en cas de succès.
	 */
	private static function fin( $r, $ok, $ko, $defaut = 'parc-automobile', $texte = '' ) {
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', $ko, $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', $ok, $texte );
		}
		IGE_FFM_Ecran_Demandes::retour( $defaut );
	}

	/**
	 * Champs postés (texte).
	 *
	 * @param string[] $k Clés.
	 * @return array
	 */
	private static function post( array $k ) {
		$o = array();
		foreach ( $k as $x ) {
			$o[ $x ] = ige_ffm_post( $x );
		}
		return $o;
	}

	/** Véhicule. */
	public static function handle_vehicule() {
		ige_ffm_check_form( 'parc_vehicule' );
		ige_ffm_require( 'ige_logistique' );
		$id = (int) ige_ffm_post( 'id' );
		$d  = self::post( array( 'immatriculation', 'marque', 'modele', 'annee', 'categorie', 'energie', 'places', 'chassis', 'service_id', 'chauffeur_id', 'conso_ref', 'reservoir', 'date_acquisition', 'valeur', 'km_initial' ) );
		$r  = IGE_FFM_Parc::enregistrer_vehicule( $d, $id );
		if ( is_wp_error( $r ) ) {
			IGE_FFM_Ecrans::remember( $d );
			ige_ffm_flash( 'ko', 'Véhicule non enregistré', $r->get_error_message() );
			wp_safe_redirect( $id ? ige_ffm_url( 'parc-automobile', array( 'vehicule' => $id ) ) : ige_ffm_url( 'parc-automobile' ) );
			exit;
		}
		ige_ffm_flash( 'ok', $id ? 'Fiche mise à jour' : 'Véhicule ajouté', $id ? '' : 'Enregistrez maintenant ses documents de bord (assurance, contrôle technique, vignette).' );
		wp_safe_redirect( ige_ffm_url( 'parc-automobile', array( 'vehicule' => $r ) ) );
		exit;
	}

	/** Statut. */
	public static function handle_statut() {
		ige_ffm_check_form( 'parc_statut' );
		ige_ffm_require( 'ige_logistique' );
		$id = (int) ige_ffm_post( 'id' );
		$r  = IGE_FFM_Parc::changer_statut( $id, ige_ffm_post( 'statut' ), ige_ffm_post( 'motif' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Statut inchangé', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Statut du véhicule mis à jour', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'parc-automobile', array( 'vehicule' => $id ) ) );
		exit;
	}

	/** Chauffeur. */
	public static function handle_chauffeur() {
		ige_ffm_check_form( 'parc_chauffeur' );
		ige_ffm_require( 'ige_logistique' );
		$r = IGE_FFM_Parc::enregistrer_chauffeur( self::post( array( 'nom', 'telephone', 'service_id', 'permis_numero', 'permis_categories', 'permis_expire', 'actif' ) ), (int) ige_ffm_post( 'id' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Chauffeur non enregistré', $r->get_error_message() );
		} else {
			ige_ffm_flash( 'ok', 'Chauffeur enregistré', '' );
		}
		wp_safe_redirect( ige_ffm_url( 'parc-automobile', array( 'onglet' => 'chauffeurs' ) ) );
		exit;
	}

	/** Document de bord. */
	public static function handle_document() {
		ige_ffm_check_form( 'parc_document' );
		ige_ffm_require( 'ige_logistique' );
		$file = isset( $_FILES['scan'] ) ? $_FILES['scan'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		self::fin( IGE_FFM_Parc::ajouter_document( self::post( array( 'vehicule_id', 'type', 'reference', 'organisme', 'date_debut', 'date_fin', 'montant' ) ), $file ), 'Document enregistré', 'Document refusé' );
	}

	/** Demande de véhicule (service ou gestion). */
	public static function handle_demande() {
		ige_ffm_check_form( 'parc_demande' );
		ige_ffm_require( 'ige_acceder' );
		$d           = self::post( array( 'service_id', 'objet', 'destination', 'depart', 'passagers', 'mission_id' ) );
		$d['retour'] = ige_ffm_post( 'retour_prevu' );
		$r           = IGE_FFM_Parc::demander( $d );
		if ( is_wp_error( $r ) ) {
			IGE_FFM_Ecrans::remember( $d + array( 'retour_prevu' => $d['retour'] ) );
			self::fin( $r, '', 'Demande non enregistrée', 'vehicules' );
		}
		$x = IGE_FFM_Parc::deplacement( $r );
		ige_ffm_flash( 'ok', 'Demande ' . $x['numero'] . ' enregistrée', IGE_FFM_Parc::gestionnaire() ? 'Attribuez maintenant le véhicule et le chauffeur.' : 'La gestion du parc est prévenue ; vous recevrez l’attribution par e-mail.' );
		wp_safe_redirect( add_query_arg( 'deplacement', $r, ige_ffm_url( IGE_FFM_Parc::gestionnaire() && ! IGE_FFM_Contexte::service_utilisateur() ? 'parc-automobile' : 'vehicules' ) ) );
		exit;
	}

	/** Attribution. */
	public static function handle_attribuer() {
		ige_ffm_check_form( 'parc_attribuer' );
		ige_ffm_require( 'ige_logistique' );
		self::fin( IGE_FFM_Parc::attribuer( (int) ige_ffm_post( 'id' ), (int) ige_ffm_post( 'vehicule_id' ), (int) ige_ffm_post( 'chauffeur_id' ) ), 'Véhicule attribué', 'Attribution refusée', 'parc-automobile', 'Le service est prévenu par e-mail.' );
	}

	/** Refus ou annulation. */
	public static function handle_clore() {
		ige_ffm_check_form( 'parc_clore' );
		ige_ffm_require( 'ige_acceder' );
		$st = 'refuse' === ige_ffm_post( 'statut' ) ? 'refuse' : 'annule';
		self::fin( IGE_FFM_Parc::clore( (int) ige_ffm_post( 'id' ), $st, ige_ffm_post( 'motif' ) ), 'refuse' === $st ? 'Demande refusée' : 'Déplacement annulé', 'Action refusée', 'vehicules' );
	}

	/** Départ. */
	public static function handle_depart() {
		ige_ffm_check_form( 'parc_depart' );
		ige_ffm_require( 'ige_acceder' );
		$r = IGE_FFM_Parc::depart( (int) ige_ffm_post( 'id' ), ige_ffm_post( 'km' ) );
		self::fin( $r, 'Départ enregistré', 'Départ refusé', 'vehicules', is_array( $r ) && $r['ecart'] > 0 ? sprintf( '%s km parcourus depuis le dernier relevé ne figurent dans aucun carnet de bord.', number_format( $r['ecart'], 0, ',', ' ' ) ) : 'Bonne route.' );
	}

	/** Retour. */
	public static function handle_retour() {
		ige_ffm_check_form( 'parc_retour' );
		ige_ffm_require( 'ige_acceder' );
		$obs = isset( $_POST['observations'] ) ? sanitize_textarea_field( wp_unslash( $_POST['observations'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$r   = IGE_FFM_Parc::retour( (int) ige_ffm_post( 'id' ), ige_ffm_post( 'km' ), $obs );
		self::fin( $r, 'Retour enregistré', 'Retour refusé', 'vehicules', is_array( $r ) ? number_format( $r['distance'], 0, ',', ' ' ) . ' km parcourus.' : '' );
	}

	/** Plein. */
	public static function handle_plein() {
		ige_ffm_check_form( 'parc_plein' );
		ige_ffm_require( 'ige_acceder' );
		$r = IGE_FFM_Parc::ajouter_plein( self::post( array( 'vehicule_id', 'date', 'litres', 'montant', 'km', 'plein', 'station', 'mode', 'bon_numero', 'ecriture' ) ) );
		if ( is_array( $r ) && $r['anomalie'] ) {
			ige_ffm_flash( 'warn', 'Plein enregistré, mais signalé', $r['anomalie'] . '.' );
			IGE_FFM_Ecran_Demandes::retour( 'parc-automobile' );
		}
		self::fin( $r, 'Plein enregistré', 'Plein refusé', 'parc-automobile', is_array( $r ) && null !== $r['conso'] ? 'Consommation depuis le plein précédent : ' . number_format( $r['conso'], 1, ',', ' ' ) . ' L/100 km.' : '' );
	}

	/** Intervention. */
	public static function handle_intervention() {
		ige_ffm_check_form( 'parc_intervention' );
		ige_ffm_require( 'ige_logistique' );
		$d                = self::post( array( 'vehicule_id', 'nature', 'objet', 'date_prevue', 'date_realisee', 'km', 'garage', 'montant', 'prochaine_km', 'prochaine_date', 'immobilise', 'ecriture' ) );
		$d['description'] = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$file             = isset( $_FILES['piece'] ) ? $_FILES['piece'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		self::fin( IGE_FFM_Parc::intervention( $d, $file ), 'Intervention enregistrée', 'Intervention refusée' );
	}

	/** Réalisation d'une intervention planifiée. */
	public static function handle_realiser() {
		ige_ffm_check_form( 'parc_realiser' );
		ige_ffm_require( 'ige_logistique' );
		self::fin( IGE_FFM_Parc::realiser( (int) ige_ffm_post( 'id' ), self::post( array( 'date_realisee', 'km', 'montant', 'garage', 'prochaine_km', 'prochaine_date', 'ecriture' ) ) ), 'Intervention réalisée', 'Action refusée' );
	}

	/** Export Excel du parc. */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_parc_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_voir_tout' );
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			wp_die( 'Aucun exercice ouvert.' );
		}
		$c = new IGE_FFM_Classeur( 'Parc automobile ' . $ex['annee'] );
		$l = array();
		foreach ( IGE_FFM_Parc::couts( (int) $ex['id'] ) as $x ) {
			$v   = $x['vehicule'];
			$l[] = array( $v['immatriculation'], trim( $v['marque'] . ' ' . $v['modele'] ), $v['service_id'] ? IGE_FFM_Comptes::service_code( (int) $v['service_id'] ) : 'Pool', IGE_FFM_Parc::situation( $v )[0], (int) $v['km_actuel'], $x['carburant'], $x['entretien'], $x['sinistres'], $x['documents'], $x['total'], $x['km'], $x['cout_km'], $x['conso'] );
		}
		$c->feuille( 'Véhicules et coûts', array( 'titre' => 'Parc automobile ' . $ex['annee'] . ' — coûts par véhicule', 'sous_titre' => IGE_FFM_Classeur::extrait_par(), 'colonnes' => array( array( 'Immatriculation', 'texte', 14 ), array( 'Véhicule', 'texte', 22 ), array( 'Affectation', 'texte', 12 ), array( 'Situation', 'texte', 12 ), array( 'Compteur', 'nombre' ), array( 'Carburant', 'montant' ), array( 'Entretien', 'montant' ), array( 'Sinistres', 'montant' ), array( 'Documents', 'montant' ), array( 'Total', 'montant' ), array( 'Km', 'nombre' ), array( 'Coût/km', 'montant' ), array( 'L/100 km', 'decimal' ) ), 'lignes' => $l ) );
		$l = array();
		foreach ( IGE_FFM_Parc::pleins( array( 'exercice_id' => (int) $ex['id'] ) ) as $p ) {
			$l[] = array( $p['date_plein'], $p['immatriculation'], (int) $p['km'], (float) $p['litres'], (int) $p['montant'], null === $p['conso'] ? null : (float) $p['conso'], $p['bon_numero'], $p['station'], $p['anomalie'] );
		}
		$c->feuille( 'Carburant', array( 'titre' => 'Pleins de carburant ' . $ex['annee'], 'colonnes' => array( array( 'Date', 'date' ), array( 'Véhicule', 'texte', 14 ), array( 'Compteur', 'nombre' ), array( 'Litres', 'decimal' ), array( 'Montant', 'montant' ), array( 'L/100 km', 'decimal' ), array( 'Bon', 'texte', 12 ), array( 'Station', 'texte', 18 ), array( 'Anomalie', 'long', 40 ) ), 'lignes' => $l ) );
		$l = array();
		foreach ( IGE_FFM_Parc::deplacements( array( 'exercice_id' => (int) $ex['id'] ) ) as $x ) {
			$v   = $x['vehicule_id'] ? IGE_FFM_Parc::vehicule( (int) $x['vehicule_id'] ) : null;
			$ch  = $x['chauffeur_id'] ? IGE_FFM_Parc::chauffeur( (int) $x['chauffeur_id'] ) : null;
			$l[] = array( $x['numero'], IGE_FFM_Comptes::service_code( (int) $x['service_id'] ), $x['objet'], $x['destination'], $x['depart_prevu'], $x['retour_prevu'], $v ? $v['immatriculation'] : '', $ch ? $ch['nom'] : '', null === $x['km_depart'] ? null : (int) $x['km_depart'], null === $x['km_retour'] ? null : (int) $x['km_retour'], null === $x['km_retour'] ? null : (int) $x['km_retour'] - (int) $x['km_depart'], IGE_FFM_Parc::statuts_deplacement()[ $x['statut'] ][0] );
		}
		$c->feuille( 'Carnet de bord', array( 'titre' => 'Ordres de route ' . $ex['annee'], 'colonnes' => array( array( 'N°', 'texte', 13 ), array( 'Service', 'texte', 10 ), array( 'Objet', 'long', 30 ), array( 'Destination', 'texte', 18 ), array( 'Départ prévu', 'dateheure' ), array( 'Retour prévu', 'dateheure' ), array( 'Véhicule', 'texte', 12 ), array( 'Chauffeur', 'texte', 16 ), array( 'Km départ', 'nombre' ), array( 'Km retour', 'nombre' ), array( 'Distance', 'nombre' ), array( 'État', 'texte', 14 ) ), 'lignes' => $l ) );
		$l = array();
		foreach ( IGE_FFM_Parc::interventions( array( 'exercice_id' => (int) $ex['id'] ) ) as $e ) {
			$l[] = array( $e['date_realisee'] ?: $e['date_prevue'], $e['immatriculation'], IGE_FFM_Parc::natures()[ $e['nature'] ] ?? '', $e['objet'], $e['garage'], (int) $e['montant'], 'realise' === $e['statut'] ? 'Réalisé' : 'Planifié' );
		}
		$c->feuille( 'Entretien et sinistres', array( 'titre' => 'Entretien, réparations et sinistres ' . $ex['annee'], 'colonnes' => array( array( 'Date', 'date' ), array( 'Véhicule', 'texte', 12 ), array( 'Nature', 'texte', 18 ), array( 'Objet', 'long', 34 ), array( 'Garage', 'texte', 18 ), array( 'Montant', 'montant' ), array( 'État', 'texte', 10 ) ), 'lignes' => $l ) );
		IGE_FFM_Audit::log( 'export_parc', 'exercice', $ex['annee'], null, array( 'format' => 'xlsx' ) );
		$c->telecharger( 'parc-automobile-' . $ex['annee'] );
	}
}

/**
 * Véhicules : écran des services.
 */
final class IGE_FFM_Ecran_Vehicules {

	/**
	 * Crochets (traitements dans IGE_FFM_Ecran_Parc).
	 */
	public static function register() {}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Véhicules';
		$args['subtitle'] = 'Demandes de véhicule, départs et retours, pleins des véhicules du service';
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
		if ( ! $sid ) {
			return $ctx['html'];
		}
		if ( ! empty( $_GET['deplacement'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return IGE_FFM_Ecran_Parc::ordre( (int) $_GET['deplacement'], 'vehicules' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$base = IGE_FFM_Ecran_Service::lien( ige_ffm_url( 'vehicules' ), $sid );
		$peut = IGE_FFM_Contexte::peut_saisir( $sid );
		$vs   = IGE_FFM_Parc::vehicules( array( 'service_id' => $sid, 'sans_reformes' => true ) );
		$enc  = IGE_FFM_Parc::deplacements( array( 'service_id' => $sid, 'statut' => array( 'demande', 'attribue', 'en_cours' ) ) );
		$fin  = IGE_FFM_Parc::deplacements( array( 'service_id' => $sid, 'statut' => array( 'termine', 'refuse', 'annule' ), 'limite' => 15 ) );
		ob_start();
		echo $ctx['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="ige-grid ige-cols-21 ige-start">
			<div>
				<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Demandes et déplacements en cours</h2><?php echo IGE_FFM_Ecran_Parc::table_deplacements( $enc, true, 'vehicules' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><p class="ige-small ige-muted" style="margin:10px 0 0">Ouvrez un déplacement attribué pour enregistrer le kilométrage au départ, puis au retour.</p></section>
				<?php if ( $vs ) : ?>
					<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Véhicules affectés au service</h2>
						<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Véhicule</th><th class="ige-r">Compteur</th><th>Documents</th><th>Situation</th></tr></thead><tbody>
						<?php foreach ( $vs as $v ) : ?>
							<?php $si = IGE_FFM_Parc::situation( $v ); ?>
							<tr><td><b><?php echo esc_html( $v['immatriculation'] ); ?></b> · <?php echo esc_html( trim( $v['marque'] . ' ' . $v['modele'] ) ); ?></td><td class="ige-r ige-num"><?php echo esc_html( number_format( (int) $v['km_actuel'], 0, ',', "\u{202F}" ) ); ?> km</td><td class="ige-small"><?php foreach ( IGE_FFM_Parc::etat_documents( (int) $v['id'] ) as $e ) { echo esc_html( $e[0] . ( 'ok' === $e[1] ? ' ✓' : ( 'expire' === $e[1] ? ' : expiré' : ( 'manquant' === $e[1] ? ' : absent' : ' : à renouveler' ) ) ) ) . '<br>'; } ?></td><td><?php echo ige_ffm_pill( $si[0], $si[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
						<?php endforeach; ?>
						</tbody></table></div>
						<h3 style="font-size:14px;margin:14px 0 6px">Derniers pleins</h3>
						<?php echo IGE_FFM_Ecran_Parc::table_pleins( IGE_FFM_Parc::pleins( array( 'service_id' => $sid, 'limite' => 10 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</section>
				<?php endif; ?>
				<section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Historique</h2><?php echo IGE_FFM_Ecran_Parc::table_deplacements( $fin, true, 'vehicules' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
			</div>
			<?php if ( $peut ) : ?>
				<aside>
					<section class="ige-card"><h2 style="margin-top:0">Demander un véhicule</h2><?php echo IGE_FFM_Ecran_Parc::form_demande( false, $base, $sid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
					<?php if ( $vs ) : ?>
						<section class="ige-card"><h2 style="margin-top:0">Enregistrer un plein</h2><?php echo IGE_FFM_Ecran_Parc::form_plein( 1 === count( $vs ) ? $vs[0] : null, $base, $vs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section>
					<?php endif; ?>
				</aside>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
