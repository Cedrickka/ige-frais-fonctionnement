<?php
/**
 * Écran « Stocks de fournitures » (VA-42) : état du stock, entrées, sorties par bureau,
 * mouvements, consommation par bureau, inventaire, articles.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stocks.
 */
final class IGE_FFM_Ecran_Stocks {

	/**
	 * Crochets.
	 */
	public static function register() {
		foreach ( array( 'article', 'bon', 'inventaire' ) as $a ) {
			add_action( 'admin_post_ige_ffm_stock_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_post_ige_ffm_stock_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Bandeau.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Stocks de fournitures';
		$args['subtitle'] = 'Entrées, sorties par bureau, seuils de réapprovisionnement et inventaire';
		$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_stock_export' ), 'ige_ffm_stock_export' ) ) . '">Excel</a>';
		return $args;
	}

	/**
	 * Onglets.
	 *
	 * @return array<string,string>
	 */
	public static function onglets() {
		return array( 'etat' => 'État du stock', 'entree' => 'Entrée', 'sortie' => 'Sortie', 'mouvements' => 'Mouvements', 'consommation' => 'Consommation par bureau', 'inventaire' => 'Inventaire', 'articles' => 'Articles' );
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		$o    = isset( $_GET['onglet'] ) ? sanitize_key( $_GET['onglet'] ) : 'etat'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = self::onglets();
		if ( ! IGE_FFM_Stocks::gestionnaire() ) {
			unset( $tabs['entree'], $tabs['sortie'], $tabs['inventaire'] );
		}
		$o    = isset( $tabs[ $o ] ) ? $o : 'etat';
		$base = ige_ffm_url( 'stocks' );
		$h    = '<div class="ige-tabs" role="tablist" style="margin-bottom:14px">';
		foreach ( $tabs as $k => $l ) {
			$h .= '<a role="tab" href="' . esc_url( add_query_arg( 'onglet', $k, $base ) ) . '" class="' . ( $k === $o ? 'ige-on' : '' ) . '">' . esc_html( $l ) . '</a>';
		}
		return $h . '</div>' . call_user_func( array( __CLASS__, 'onglet_' . $o ) );
	}

	/**
	 * Options des articles actifs.
	 *
	 * @param bool $avec_stock Afficher le stock.
	 * @return array
	 */
	private static function options_articles( $avec_stock = false ) {
		$o = array( '' => '— Article —' );
		foreach ( IGE_FFM_Stocks::articles( true ) as $a ) {
			$o[ $a['id'] ] = $a['code'] . ' · ' . $a['designation'] . ( $avec_stock ? ' (' . IGE_FFM_Stocks::q( $a['stock'] ) . ' ' . $a['unite'] . ')' : '' );
		}
		return $o;
	}

	/**
	 * État du stock.
	 *
	 * @return string
	 */
	private static function onglet_etat() {
		$as  = IGE_FFM_Stocks::articles();
		$re  = IGE_FFM_Stocks::a_reapprovisionner();
		$ex  = IGE_FFM_Contexte::exercice();
		$co  = $ex ? IGE_FFM_Stocks::consommation( $ex['date_debut'], $ex['date_fin'] ) : array( 'total' => 0 );
		ob_start();
		?>
		<div class="ige-grid ige-kpis">
			<div class="ige-card ige-kpi"><div class="ige-lbl">Valeur du stock</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( IGE_FFM_Stocks::valeur(), '' ) ); ?> <span>CDF</span></div><div class="ige-meta">au coût moyen pondéré</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Articles suivis</div><div class="ige-val"><?php echo count( array_filter( $as, static fn( $a ) => (int) $a['actif'] ) ); ?></div><div class="ige-meta"><?php echo count( array_filter( $as, static fn( $a ) => (int) $a['actif'] && (float) $a['stock'] <= 0 ) ); ?> en rupture</div></div>
			<div class="ige-card ige-kpi<?php echo $re ? ' ige-alerte' : ''; ?>"><div class="ige-lbl">À réapprovisionner</div><div class="ige-val"><?php echo count( $re ); ?></div><div class="ige-meta">sous le seuil</div></div>
			<div class="ige-card ige-kpi"><div class="ige-lbl">Consommé sur l’exercice</div><div class="ige-val"><?php echo esc_html( ige_ffm_montant( (int) $co['total'], '' ) ); ?> <span>CDF</span></div><div class="ige-meta">sorties vers les bureaux</div></div>
		</div>
		<section class="ige-card" style="padding:16px">
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Code</th><th>Article</th><th>Catégorie</th><th class="ige-r">Stock</th><th class="ige-r">Seuil</th><th class="ige-r">Coût moyen</th><th class="ige-r">Valeur</th><th>État</th></tr></thead>
				<tbody>
				<?php foreach ( $as as $a ) : ?>
					<?php
					if ( ! (int) $a['actif'] && 0.0 === (float) $a['stock'] ) {
						continue;
					}
					$s = (float) $a['stock'];
					$e = $s <= 0 ? array( 'Rupture', 'ko' ) : ( (float) $a['seuil'] > 0 && $s <= (float) $a['seuil'] ? array( 'À commander', 'wait' ) : array( 'Suffisant', 'ok' ) );
					?>
					<tr>
						<td><span class="ige-code"><?php echo esc_html( $a['code'] ); ?></span></td>
						<td><a href="<?php echo esc_url( add_query_arg( array( 'onglet' => 'mouvements', 'article' => $a['id'] ), ige_ffm_url( 'stocks' ) ) ); ?>"><?php echo esc_html( $a['designation'] ); ?></a></td>
						<td><?php echo esc_html( $a['categorie'] ?: '—' ); ?></td>
						<td class="ige-r ige-num"><b><?php echo esc_html( IGE_FFM_Stocks::q( $s ) ); ?></b> <?php echo esc_html( $a['unite'] ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( IGE_FFM_Stocks::q( $a['seuil'] ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) round( (float) $a['cmup'] ), '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) round( $s * (float) $a['cmup'] ), '' ) ); ?></td>
						<td><?php echo ige_ffm_pill( $e[0], $e[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $as ) : ?><tr><td colspan="8" class="ige-empty">Aucun article : créez le catalogue dans l’onglet « Articles ».</td></tr><?php endif; ?>
				</tbody>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Lignes d'articles d'un bon.
	 *
	 * @param bool $entree Bon d'entrée (prix unitaire).
	 * @return string
	 */
	private static function lignes_bon( $entree ) {
		$opts = self::options_articles( ! $entree );
		$h    = '<table class="ige-membres"><thead><tr><th>Article</th><th>Quantité</th>' . ( $entree ? '<th>Prix unitaire (CDF)</th>' : '' ) . '</tr></thead><tbody>';
		for ( $i = 0; $i < 6; $i++ ) {
			$h .= '<tr><td><select name="lignes[' . $i . '][article_id]" aria-label="Article ' . ( $i + 1 ) . '">';
			foreach ( $opts as $k => $l ) {
				$h .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
			}
			$h .= '</select></td><td><input type="text" inputmode="decimal" name="lignes[' . $i . '][quantite]" aria-label="Quantité ' . ( $i + 1 ) . '" class="ige-num"' . ( 0 === $i ? ' required' : '' ) . '></td>' . ( $entree ? '<td><input type="text" inputmode="decimal" name="lignes[' . $i . '][prix_unitaire]" aria-label="Prix unitaire ' . ( $i + 1 ) . '" class="ige-num"></td>' : '' ) . '</tr>';
		}
		return $h . '</tbody></table>';
	}

	/**
	 * Entrée en stock.
	 *
	 * @return string
	 */
	private static function onglet_entree() {
		$auj   = IGE_FFM_Contexte::aujourdhui();
		$fourn = array( '' => '— Fournisseur —' );
		foreach ( IGE_FFM_Fournisseurs::all() as $f ) {
			$fourn[ $f['id'] ] = $f['raison_sociale'];
		}
		return '<div class="ige-grid ige-cols-21 ige-start"><section class="ige-card"><h2 style="margin-top:0">Bon d’entrée</h2>'
			. IGE_FFM_Ecrans::form_open( 'stock_bon' ) . '<input type="hidden" name="type" value="entree">'
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'date', 'Date de réception', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) )
			. IGE_FFM_Ecrans::field( 'reference', 'N° du bon de livraison', '', array( 'attrs' => 'maxlength="60"' ) )
			. IGE_FFM_Ecrans::field( 'fournisseur_id', 'Fournisseur', '', array( 'type' => 'select', 'options' => $fourn ) )
			. IGE_FFM_Ecrans::field( 'ecriture', 'Écriture d’achat au livre de caisse', '', array( 'attrs' => 'maxlength="40" placeholder="8000IG-2026-000015"', 'help' => 'Relie l’achat payé à son entrée en stock.' ) )
			. '</div><div class="ige-field"><span style="font-size:12.5px;font-weight:600">Articles reçus</span>' . self::lignes_bon( true ) . '</div>'
			. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer l’entrée</button></form></section>'
			. '<aside class="ige-card"><h2 style="margin-top:0">Valorisation</h2><p class="ige-small">Chaque entrée recalcule le coût moyen unitaire pondéré de l’article : (stock × coût moyen + quantité reçue × prix) ÷ nouveau stock. Les sorties sont valorisées à ce coût moyen.</p></aside></div>';
	}

	/**
	 * Sortie vers un bureau.
	 *
	 * @return string
	 */
	private static function onglet_sortie() {
		$auj = IGE_FFM_Contexte::aujourdhui();
		return '<div class="ige-grid ige-cols-21 ige-start"><section class="ige-card"><h2 style="margin-top:0">Bon de sortie</h2>'
			. IGE_FFM_Ecrans::form_open( 'stock_bon' ) . '<input type="hidden" name="type" value="sortie">'
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
			. IGE_FFM_Ecrans::field( 'date', 'Date', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) )
			. IGE_FFM_Ecrans::field( 'service_id', 'Bureau (service) destinataire', '', array( 'type' => 'select', 'options' => IGE_FFM_Ecran_Parc::options( 'services', '— Choisir —' ), 'required' => true ) )
			. IGE_FFM_Ecrans::field( 'beneficiaire', 'Remis à', '', array( 'attrs' => 'maxlength="120" placeholder="Nom de la personne qui reçoit"' ) )
			. IGE_FFM_Ecrans::field( 'reference', 'N° du bon', '', array( 'attrs' => 'maxlength="60"' ) )
			. '</div><div class="ige-field"><span style="font-size:12.5px;font-weight:600">Articles remis (stock disponible entre parenthèses)</span>' . self::lignes_bon( false ) . '</div>'
			. '<button type="submit" class="ige-btn ige-btn-jaune">Enregistrer la sortie</button></form></section>'
			. '<aside class="ige-card"><h2 style="margin-top:0">Règle</h2><p class="ige-small">Une sortie ne peut pas dépasser le stock : le bon entier est refusé. Les sorties alimentent la consommation par bureau, à comparer aux achats de fournitures du livre de caisse.</p></aside></div>';
	}

	/**
	 * Mouvements.
	 *
	 * @return string
	 */
	private static function onglet_mouvements() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f = array(
			'article_id' => isset( $_GET['article'] ) ? (int) $_GET['article'] : 0,
			'service_id' => isset( $_GET['service'] ) ? (int) $_GET['service'] : 0,
			'type'       => isset( $_GET['type'] ) && isset( IGE_FFM_Stocks::types()[ sanitize_key( $_GET['type'] ) ] ) ? sanitize_key( $_GET['type'] ) : '',
			'limite'     => 200,
		);
		// phpcs:enable
		$m = IGE_FFM_Stocks::mouvements( $f );
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<form method="get" class="ige-toolbar">
				<input type="hidden" name="onglet" value="mouvements">
				<select name="article" class="ige-input" style="width:auto" aria-label="Article"><?php foreach ( self::options_articles() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f['article_id'], (int) $k ); ?>><?php echo esc_html( '' === $k ? 'Tous les articles' : $l ); ?></option><?php endforeach; ?></select>
				<select name="service" class="ige-input" style="width:auto" aria-label="Bureau"><?php foreach ( IGE_FFM_Ecran_Parc::options( 'services', 'Tous les bureaux' ) as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f['service_id'], (int) $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<select name="type" class="ige-input" style="width:auto" aria-label="Type"><option value="">Tous les mouvements</option><?php foreach ( IGE_FFM_Stocks::types() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f['type'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
				<button type="submit" class="ige-btn ige-btn-line">Filtrer</button>
			</form>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Date</th><th>Article</th><th>Mouvement</th><th class="ige-r">Quantité</th><th class="ige-r">Valeur</th><th class="ige-r">Stock après</th><th>Bureau · bénéficiaire · référence</th></tr></thead>
				<tbody>
				<?php foreach ( $m as $x ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $x['date_mvt'] ) ) ); ?></td>
						<td><?php echo esc_html( $x['designation'] ); ?></td>
						<td><?php echo ige_ffm_pill( IGE_FFM_Stocks::types()[ $x['type'] ], 'entree' === $x['type'] ? 'ok' : ( 'sortie' === $x['type'] ? 'info' : 'wait' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td class="ige-r ige-num<?php echo (float) $x['quantite'] < 0 ? ' ige-neg' : ''; ?>"><?php echo esc_html( ( (float) $x['quantite'] > 0 ? '+' : '' ) . IGE_FFM_Stocks::q( $x['quantite'] ) . ' ' . $x['unite'] ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( (int) $x['valeur'], '' ) ); ?></td>
						<td class="ige-r ige-num"><?php echo esc_html( IGE_FFM_Stocks::q( $x['stock_apres'] ) ); ?></td>
						<td class="ige-small"><?php echo esc_html( implode( ' · ', array_filter( array( $x['service_id'] ? IGE_FFM_Comptes::service_code( (int) $x['service_id'] ) : '', $x['beneficiaire'], $x['reference'], $x['motif'] ) ) ) ?: '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $m ) : ?><tr><td colspan="7" class="ige-empty">Aucun mouvement.</td></tr><?php endif; ?>
				</tbody>
			</table></div>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Consommation par bureau.
	 *
	 * @return string
	 */
	private static function onglet_consommation() {
		$ex = IGE_FFM_Contexte::exercice();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$du = isset( $_GET['du'] ) && IGE_FFM_Contexte::date_valide( sanitize_text_field( wp_unslash( $_GET['du'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['du'] ) ) : ( $ex ? $ex['date_debut'] : wp_date( 'Y-01-01' ) );
		$au = isset( $_GET['au'] ) && IGE_FFM_Contexte::date_valide( sanitize_text_field( wp_unslash( $_GET['au'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['au'] ) ) : IGE_FFM_Contexte::aujourdhui();
		// phpcs:enable
		$c    = IGE_FFM_Stocks::consommation( $du, $au );
		$arts = array();
		foreach ( IGE_FFM_Stocks::articles() as $a ) {
			$arts[ (int) $a['id'] ] = $a;
		}
		$svc = array();
		foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
			$svc[ (int) $s['id'] ] = $s;
		}
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<form method="get" class="ige-toolbar"><input type="hidden" name="onglet" value="consommation">
				<label class="ige-small" for="ige-du">Du</label><input type="date" id="ige-du" name="du" value="<?php echo esc_attr( $du ); ?>" class="ige-input" style="width:auto">
				<label class="ige-small" for="ige-au">au</label><input type="date" id="ige-au" name="au" value="<?php echo esc_attr( $au ); ?>" class="ige-input" style="width:auto">
				<button type="submit" class="ige-btn ige-btn-line">Afficher</button>
			</form>
			<div class="ige-table-wrap"><table class="ige-table">
				<thead><tr><th>Bureau</th><th class="ige-r">Effectif</th><th class="ige-r">Valeur consommée</th><th class="ige-r">Par agent</th><th>Principaux articles</th></tr></thead>
				<tbody>
				<?php foreach ( $c['bureaux'] as $sid => $v ) : ?>
					<?php
					$cel = $c['cellules'][ $sid ];
					uasort( $cel, static fn( $a, $b ) => $b['v'] <=> $a['v'] );
					$top = array();
					foreach ( array_slice( $cel, 0, 4, true ) as $aid => $x ) {
						$top[] = ( $arts[ $aid ]['designation'] ?? '?' ) . ' : ' . IGE_FFM_Stocks::q( $x['q'] ) . ' ' . ( $arts[ $aid ]['unite'] ?? '' );
					}
					$eff = (int) ( $svc[ $sid ]['effectif'] ?? 0 );
					?>
					<tr><td><b><?php echo esc_html( $svc[ $sid ]['code'] ?? '?' ); ?></b></td><td class="ige-r"><?php echo esc_html( $eff ?: '—' ); ?></td><td class="ige-r ige-num"><?php echo esc_html( ige_ffm_montant( $v, '' ) ); ?></td><td class="ige-r ige-num"><?php echo esc_html( $eff ? ige_ffm_montant( (int) round( $v / $eff ), '' ) : '—' ); ?></td><td class="ige-small"><?php echo esc_html( implode( ' ; ', $top ) ); ?></td></tr>
				<?php endforeach; ?>
				<?php if ( ! $c['bureaux'] ) : ?><tr><td colspan="5" class="ige-empty">Aucune sortie sur la période.</td></tr><?php else : ?><tr class="ige-tot"><td>Total</td><td></td><td class="ige-r"><?php echo esc_html( ige_ffm_montant( $c['total'], '' ) ); ?></td><td></td><td></td></tr><?php endif; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Valeur des sorties au coût moyen pondéré. L’effectif se renseigne dans Référentiels > Services.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Inventaire.
	 *
	 * @return string
	 */
	private static function onglet_inventaire() {
		$auj = IGE_FFM_Contexte::aujourdhui();
		$h   = '<div class="ige-grid ige-cols-21 ige-start"><section class="ige-card" style="padding:16px"><h2 style="margin-top:0">Saisir l’inventaire</h2>'
			. IGE_FFM_Ecrans::form_open( 'stock_inventaire' )
			. '<div class="ige-grid ige-cols-2" style="gap:0 12px">' . IGE_FFM_Ecrans::field( 'date', 'Date du comptage', $auj, array( 'type' => 'date', 'required' => true, 'attrs' => 'max="' . esc_attr( $auj ) . '"' ) ) . IGE_FFM_Ecrans::field( 'observations', 'Observations', '', array( 'attrs' => 'maxlength="255" placeholder="Comptage par …"' ) ) . '</div>'
			. '<div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Article</th><th class="ige-r">Stock théorique</th><th>Quantité comptée</th></tr></thead><tbody>';
		foreach ( IGE_FFM_Stocks::articles( true ) as $a ) {
			$h .= '<tr><td>' . esc_html( $a['code'] . ' · ' . $a['designation'] ) . '</td><td class="ige-r ige-num">' . esc_html( IGE_FFM_Stocks::q( $a['stock'] ) . ' ' . $a['unite'] ) . '</td><td><input type="text" inputmode="decimal" name="compte[' . esc_attr( $a['id'] ) . ']" class="ige-num" style="max-width:120px" aria-label="' . esc_attr( 'Quantité comptée : ' . $a['designation'] ) . '"></td></tr>';
		}
		$h .= '</tbody></table></div><p class="ige-small ige-muted">Laissez vide un article non compté. Chaque écart crée un ajustement motivé « Inventaire du … », valorisé au coût moyen.</p><button type="submit" class="ige-btn ige-btn-jaune">Valider l’inventaire</button></form></section><aside class="ige-card"><h2 style="margin-top:0">Inventaires passés</h2>';
		$inv = IGE_FFM_Stocks::inventaires();
		if ( $inv ) {
			$h .= '<table class="ige-table"><tbody>';
			foreach ( $inv as $i ) {
				$h .= '<tr><td>' . esc_html( wp_date( 'd/m/Y', strtotime( $i['date_inventaire'] ) ) ) . '</td><td>' . (int) $i['nb_ecarts'] . ' écart(s)</td><td class="ige-r ige-num' . ( (int) $i['valeur_ecarts'] < 0 ? ' ige-neg' : '' ) . '">' . esc_html( ige_ffm_montant( (int) $i['valeur_ecarts'], '' ) ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		} else {
			$h .= '<p class="ige-small ige-muted">Aucun inventaire. Recommandé : un par trimestre et à la clôture.</p>';
		}
		return $h . '</aside></div>';
	}

	/**
	 * Articles.
	 *
	 * @return string
	 */
	private static function onglet_articles() {
		$edit = isset( $_GET['modifier'] ) ? IGE_FFM_Stocks::article( (int) $_GET['modifier'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base = ige_ffm_url( 'stocks', array( 'onglet' => 'articles' ) );
		$old  = IGE_FFM_Ecrans::old();
		$g    = static fn( $k, $d = '' ) => (string) ( $old[ $k ] ?? ( $edit[ $k ] ?? $d ) );
		$h    = '<div class="ige-grid ige-cols-21 ige-start"><section class="ige-card" style="padding:16px"><div class="ige-table-wrap"><table class="ige-table"><thead><tr><th>Code</th><th>Désignation</th><th>Catégorie</th><th>Unité</th><th class="ige-r">Seuil</th><th>Actif</th><th></th></tr></thead><tbody>';
		foreach ( IGE_FFM_Stocks::articles() as $a ) {
			$h .= '<tr><td><span class="ige-code">' . esc_html( $a['code'] ) . '</span></td><td>' . esc_html( $a['designation'] ) . '</td><td>' . esc_html( $a['categorie'] ) . '</td><td>' . esc_html( $a['unite'] ) . '</td><td class="ige-r">' . esc_html( IGE_FFM_Stocks::q( $a['seuil'] ) ) . '</td><td>' . ige_ffm_switch( (bool) $a['actif'] ) . '</td><td>' . ( IGE_FFM_Stocks::gestionnaire() ? '<a class="ige-act" href="' . esc_url( add_query_arg( 'modifier', $a['id'], $base ) ) . '">Modifier</a>' : '' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div></section>';
		if ( IGE_FFM_Stocks::gestionnaire() ) {
			$h .= '<section class="ige-card"><h2 style="margin-top:0">' . ( $edit ? 'Modifier : ' . esc_html( $edit['code'] ) : 'Nouvel article' ) . '</h2>' . IGE_FFM_Ecrans::form_open( 'stock_article' ) . '<input type="hidden" name="id" value="' . esc_attr( $edit['id'] ?? 0 ) . '">'
				. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
				. IGE_FFM_Ecrans::field( 'code', 'Code', $g( 'code' ), array( 'required' => true, 'attrs' => 'maxlength="30" placeholder="PAP-A4"' ) )
				. IGE_FFM_Ecrans::field( 'unite', 'Unité', $g( 'unite', 'unité' ), array( 'attrs' => 'maxlength="20" placeholder="rame, boîte, unité"' ) )
				. '</div>'
				. IGE_FFM_Ecrans::field( 'designation', 'Désignation', $g( 'designation' ), array( 'required' => true, 'attrs' => 'maxlength="190" placeholder="Papier A4 80 g, rame de 500 feuilles"' ) )
				. '<div class="ige-grid ige-cols-2" style="gap:0 12px">'
				. IGE_FFM_Ecrans::field( 'categorie', 'Catégorie', $g( 'categorie' ), array( 'attrs' => 'maxlength="60" placeholder="Papeterie, Informatique, Entretien"' ) )
				. IGE_FFM_Ecrans::field( 'seuil', 'Seuil de réapprovisionnement', $g( 'seuil', '0' ), array( 'attrs' => 'inputmode="decimal"' ) )
				. '</div>'
				. ( $edit ? IGE_FFM_Ecrans::field( 'actif', 'Actif', (bool) $edit['actif'], array( 'type' => 'checkbox' ) ) : '' )
				. '<button type="submit" class="ige-btn ige-btn-jaune">' . ( $edit ? 'Enregistrer' : 'Ajouter' ) . '</button></form></section>';
		}
		return $h . '</div>';
	}

	/* ------------------------------------------------------------------ Traitements */

	/** Article. */
	public static function handle_article() {
		ige_ffm_check_form( 'stock_article' );
		ige_ffm_require( 'ige_logistique' );
		$d = array();
		foreach ( array( 'code', 'designation', 'unite', 'categorie', 'seuil', 'actif' ) as $k ) {
			$d[ $k ] = ige_ffm_post( $k );
		}
		$id = (int) ige_ffm_post( 'id' );
		$r  = IGE_FFM_Stocks::enregistrer_article( $d, $id );
		if ( is_wp_error( $r ) ) {
			IGE_FFM_Ecrans::remember( $d );
			ige_ffm_flash( 'ko', 'Article non enregistré', $r->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'stocks', array_filter( array( 'onglet' => 'articles', 'modifier' => $id ) ) ) );
			exit;
		}
		ige_ffm_flash( 'ok', $id ? 'Article modifié' : 'Article ajouté', '' );
		wp_safe_redirect( ige_ffm_url( 'stocks', array( 'onglet' => 'articles' ) ) );
		exit;
	}

	/** Bon d'entrée ou de sortie. */
	public static function handle_bon() {
		ige_ffm_check_form( 'stock_bon' );
		ige_ffm_require( 'ige_logistique' );
		$type   = 'sortie' === ige_ffm_post( 'type' ) ? 'sortie' : 'entree';
		$lignes = array();
		foreach ( (array) ( isset( $_POST['lignes'] ) ? wp_unslash( $_POST['lignes'] ) : array() ) as $l ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $l ) ) {
				$lignes[] = array_map( 'sanitize_text_field', array_intersect_key( $l, array_flip( array( 'article_id', 'quantite', 'prix_unitaire' ) ) ) );
			}
		}
		$commun = array( 'type' => $type, 'date' => ige_ffm_post( 'date' ), 'service_id' => (int) ige_ffm_post( 'service_id' ), 'beneficiaire' => ige_ffm_post( 'beneficiaire' ), 'reference' => ige_ffm_post( 'reference' ), 'fournisseur_id' => (int) ige_ffm_post( 'fournisseur_id' ), 'ecriture' => ige_ffm_post( 'ecriture' ) );
		$r      = IGE_FFM_Stocks::bon( $commun, $lignes );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', ( 'sortie' === $type ? 'Sortie' : 'Entrée' ) . ' refusée', $r->get_error_message() . ' Aucune ligne n’a été enregistrée.' );
			wp_safe_redirect( ige_ffm_url( 'stocks', array( 'onglet' => $type ) ) );
			exit;
		}
		ige_ffm_flash( 'ok', count( $r ) . ' ligne(s) enregistrée(s)', 'sortie' === $type ? 'Remise au bureau ' . IGE_FFM_Comptes::service_code( $commun['service_id'] ) . '.' : 'Stock et coût moyen mis à jour.' );
		wp_safe_redirect( ige_ffm_url( 'stocks' ) );
		exit;
	}

	/** Inventaire. */
	public static function handle_inventaire() {
		ige_ffm_check_form( 'stock_inventaire' );
		ige_ffm_require( 'ige_logistique' );
		$c = isset( $_POST['compte'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['compte'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$r = IGE_FFM_Stocks::inventaire( ige_ffm_post( 'date' ), $c, ige_ffm_post( 'observations' ) );
		if ( is_wp_error( $r ) ) {
			ige_ffm_flash( 'ko', 'Inventaire refusé', $r->get_error_message() );
			wp_safe_redirect( ige_ffm_url( 'stocks', array( 'onglet' => 'inventaire' ) ) );
			exit;
		}
		ige_ffm_flash( 'ok', 'Inventaire enregistré', $r['ecarts'] ? sprintf( '%d écart(s) ajusté(s), pour %s.', $r['ecarts'], ige_ffm_montant( $r['valeur'] ) ) : 'Aucun écart : le stock compté correspond au stock théorique.' );
		wp_safe_redirect( ige_ffm_url( 'stocks', array( 'onglet' => 'inventaire' ) ) );
		exit;
	}

	/** Export Excel. */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_stock_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_voir_tout' );
		$c = new IGE_FFM_Classeur( 'Stocks de fournitures' );
		$l = array();
		foreach ( IGE_FFM_Stocks::articles() as $a ) {
			$l[] = array( $a['code'], $a['designation'], $a['categorie'], $a['unite'], (float) $a['stock'], (float) $a['seuil'], (float) $a['cmup'], (int) round( (float) $a['stock'] * (float) $a['cmup'] ) );
		}
		$c->feuille( 'État du stock', array( 'titre' => 'État du stock au ' . wp_date( 'd/m/Y' ), 'sous_titre' => IGE_FFM_Classeur::extrait_par(), 'colonnes' => array( array( 'Code', 'texte', 12 ), array( 'Désignation', 'long', 34 ), array( 'Catégorie', 'texte', 14 ), array( 'Unité', 'texte', 9 ), array( 'Stock', 'decimal' ), array( 'Seuil', 'decimal' ), array( 'Coût moyen', 'decimal' ), array( 'Valeur', 'montant' ) ), 'lignes' => $l, 'total' => array( 'Total', '', '', '', null, null, null, IGE_FFM_Stocks::valeur() ) ) );
		$l = array();
		foreach ( IGE_FFM_Stocks::mouvements( array( 'limite' => 5000 ) ) as $m ) {
			$l[] = array( $m['date_mvt'], $m['code'], $m['designation'], IGE_FFM_Stocks::types()[ $m['type'] ], (float) $m['quantite'], (int) $m['valeur'], (float) $m['stock_apres'], $m['service_id'] ? IGE_FFM_Comptes::service_code( (int) $m['service_id'] ) : '', $m['beneficiaire'], $m['reference'], $m['motif'] );
		}
		$c->feuille( 'Mouvements', array( 'titre' => 'Mouvements de stock', 'colonnes' => array( array( 'Date', 'date' ), array( 'Code', 'texte', 12 ), array( 'Article', 'texte', 28 ), array( 'Mouvement', 'texte', 14 ), array( 'Quantité', 'decimal' ), array( 'Valeur', 'montant' ), array( 'Stock après', 'decimal' ), array( 'Bureau', 'texte', 10 ), array( 'Bénéficiaire', 'texte', 16 ), array( 'Référence', 'texte', 12 ), array( 'Motif', 'long', 24 ) ), 'lignes' => $l ) );
		IGE_FFM_Audit::log( 'export_stocks', 'stocks', 'xlsx' );
		$c->telecharger( 'stocks-fournitures-' . wp_date( 'Y-m-d' ) );
	}
}
