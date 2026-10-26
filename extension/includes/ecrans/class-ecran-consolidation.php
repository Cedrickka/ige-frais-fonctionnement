<?php
/**
 * Écran Consolidation (EF-71) : par service, catégorie, rubrique et mois ; export Excel (CSV).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consolidation.
 */
final class IGE_FFM_Ecran_Consolidation {

	/**
	 * Traitements.
	 */
	public static function register() {
		add_action( 'admin_post_ige_ffm_conso_export', array( __CLASS__, 'handle_export' ) );
	}

	/**
	 * Onglets.
	 *
	 * @return array<string,string>
	 */
	public static function vues() {
		$v = array( 'services' => 'Par service', 'services_categories' => 'Services et catégories', 'services_rubriques' => 'Services et rubriques', 'categories' => 'Par catégorie', 'rubriques' => 'Par rubrique', 'mois' => 'Par mois' );
		if ( IGE_FFM_Fonctions::active( 'nomenclature' ) ) {
			$v['nomenclature'] = 'Par ligne de la nomenclature';
		}
		return $v;
	}

	/**
	 * Onglet courant.
	 *
	 * @return string
	 */
	public static function vue() {
		$v = isset( $_GET['vue'] ) ? sanitize_key( $_GET['vue'] ) : 'services'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::vues()[ $v ] ) ? $v : 'services';
	}

	/**
	 * Bandeau de titre.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	public static function title_band( $args ) {
		$args['title']    = 'Consolidation';
		$args['subtitle'] = 'Écritures validées uniquement · au ' . ige_ff_date_fr_safe();
		$args['actions']  = '<a class="ige-btn ige-btn-ghost" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ige_ffm_conso_export&vue=' . self::vue() ), 'ige_ffm_conso_export' ) ) . '">Excel</a>';
		$args['actions'] .= '<a class="ige-btn ige-btn-ghost" href="' . esc_url( IGE_FFM_Pdf::url( array( 'quoi' => 'consolidation', 'vue' => self::vue() ) ) ) . '">PDF</a>';
		if ( current_user_can( 'ige_administrer' ) ) {
			$args['actions'] .= '<a class="ige-btn ige-btn-jaune" href="' . esc_url( ige_ffm_url( 'rapports', array( 'type' => 'etat_consolide' ) ) ) . '">Émettre l’état consolidé officiel</a>';
		}
		return $args;
	}

	/**
	 * Tableau d'une vue : en-têtes et lignes (texte brut, montants entiers).
	 *
	 * @param string $vue Vue.
	 * @return array{entetes:array, lignes:array, total:?array, num:array}
	 */
	public static function tableau( $vue ) {
		$ex = IGE_FFM_Contexte::exercice();
		$c  = IGE_FFM_Consolidation::calculer( (int) $ex['id'] );
		$t  = $c['total'];
		switch ( $vue ) {
			case 'services_categories':
			case 'services_rubriques':
				return self::croisement( $c, 'services_rubriques' === $vue );
			case 'categories':
				$l = array();
				foreach ( $c['categories'] as $k ) {
					$l[] = array( $k['libelle'], $k['budget'], $k['consomme'], $k['budget'] ? round( $k['consomme'] / $k['budget'] * 100, 1 ) : null, $t['consomme'] ? round( $k['consomme'] / $t['consomme'] * 100, 1 ) : 0 );
				}
				return array( 'entetes' => array( 'Catégorie', 'Budget', 'Consommé', 'Exécution (%)', 'Part (%)' ), 'lignes' => $l, 'total' => array( 'Total', $t['budget'], $t['consomme'], null === $t['taux'] ? null : round( $t['taux'], 1 ), 100 ), 'num' => array( 1, 2 ) );
			case 'rubriques':
				$l = array();
				foreach ( $c['rubriques'] as $r ) {
					$l[] = array( $r['rubrique']['code'] . ' ' . $r['rubrique']['libelle'], IGE_FFM_Referentiels::categorie_libelle( (int) ( $r['rubrique']['categorie_id'] ?? 0 ) ), $r['consomme'], $t['consomme'] ? round( $r['consomme'] / $t['consomme'] * 100, 1 ) : 0 );
				}
				return array( 'entetes' => array( 'Rubrique', 'Catégorie', 'Consommé', 'Part (%)' ), 'lignes' => $l, 'total' => array( 'Total', '', $t['consomme'], 100 ), 'num' => array( 2 ) );
			case 'nomenclature':
				$l = array();
				foreach ( IGE_FFM_Analyses::par_nomenclature( (int) $ex['id'] ) as $n ) {
					$l[] = array( '' === $n['code'] ? '—' : $n['code'], $n['libelle'], implode( ', ', $n['rubriques'] ), $n['budget'], $n['consomme'], $n['taux'] );
				}
				return array( 'entetes' => array( 'Ligne', 'Libellé officiel', 'Rubriques', 'Budget', 'Consommé', 'Exécution (%)' ), 'lignes' => $l, 'total' => array( 'Total', '', '', $t['budget'], $t['consomme'], null === $t['taux'] ? null : round( $t['taux'], 1 ) ), 'num' => array( 3, 4 ) );
			case 'mois':
				$noms = IGE_FFM_Ecran_Livre::mois_noms();
				$l    = array();
				$cum  = 0;
				foreach ( $c['mois'] as $m => $v ) {
					$cum += $v;
					$l[]  = array( $noms[ $m ], $v, $cum, $t['budget'] ? round( $cum / $t['budget'] * 100, 1 ) : null );
				}
				return array( 'entetes' => array( 'Mois', 'Dépenses validées', 'Cumul', 'Cumul / budget (%)' ), 'lignes' => $l, 'total' => null, 'num' => array( 1, 2 ) );
			default:
				$l = array();
				foreach ( $c['services'] as $s ) {
					$l[] = array( $s['service']['code'], $s['avec_budget'] ? $s['budget'] : null, $s['entrees'], $s['paye'], $s['engage'], $s['disponible'], null === $s['taux'] ? null : round( $s['taux'], 1 ), $s['caisse'], $s['a_verifier'], $s['dernier_lot'] ? wp_date( 'd/m/Y', strtotime( $s['dernier_lot'] ) ) : '' );
				}
				return array( 'entetes' => array( 'Service', 'Budget', 'Entrées', 'Payé', 'Engagé', 'Disponible', 'Exécution (%)', 'Caisse', 'À vérifier', 'Dernier lot' ), 'lignes' => $l, 'total' => array( 'Total (' . $t['nb_services'] . ' services)', $t['budget'], $t['entrees'], $t['paye'], $t['engage'], $t['disponible'], null === $t['taux'] ? null : round( $t['taux'], 1 ), $t['caisse'], $t['a_verifier'], '' ), 'num' => array( 1, 2, 3, 4, 5, 7, 8 ) );
		}
	}

	/**
	 * Tableau croisé : une ligne par service, une colonne par catégorie (ou par rubrique).
	 * Montants consommés (payé + engagé) des écritures validées.
	 *
	 * @param array $c        Consolidation calculée.
	 * @param bool  $rubrique Colonnes par rubrique plutôt que par catégorie.
	 * @return array
	 */
	public static function croisement( array $c, $rubrique = false ) {
		$cols = array();
		$rubs = IGE_FFM_Referentiels::all( 'rubrique' );
		if ( $rubrique ) {
			foreach ( $rubs as $r ) {
				$cols[ (int) $r['id'] ] = array( $r['code'] . ' · ' . $r['libelle'], (int) $r['actif'] );
			}
		} else {
			$codes = array();
			foreach ( $rubs as $r ) {
				$codes[ (int) $r['categorie_id'] ][] = $r['code'];
			}
			foreach ( IGE_FFM_Referentiels::all( 'categorie' ) as $k ) {
				if ( empty( $codes[ (int) $k['id'] ] ) ) {
					$cols[ (int) $k['id'] ] = array( $k['libelle'], 0 ); // Sans rubrique : affichée seulement si elle porte des dépenses.
					continue;
				}
				$cols[ (int) $k['id'] ] = array( $k['libelle'] . ( empty( $codes[ (int) $k['id'] ] ) ? '' : ' (' . implode( ', ', $codes[ (int) $k['id'] ] ) . ')' ), (int) $k['actif'] );
			}
			$cols[0] = array( 'Sans catégorie', 0 );
		}
		$cle = $rubrique ? 'par_rub' : 'par_cat';
		$tot = array_fill_keys( array_keys( $cols ), 0 );
		foreach ( $c['services'] as $s ) {
			foreach ( $s[ $cle ] as $id => $m ) {
				if ( isset( $tot[ $id ] ) ) {
					$tot[ $id ] += $m;
				}
			}
		}
		// Colonnes affichées : éléments actifs, et inactifs seulement s'ils ont des dépenses.
		$ids = array_values( array_filter( array_keys( $cols ), static fn( $id ) => $cols[ $id ][1] || $tot[ $id ] ) );
		$l   = array();
		foreach ( $c['services'] as $s ) {
			$ligne = array( $s['service']['code'] );
			foreach ( $ids as $id ) {
				$ligne[] = $s[ $cle ][ $id ] ?? 0;
			}
			$ligne[] = array_sum( array_map( static fn( $id ) => $s[ $cle ][ $id ] ?? 0, $ids ) );
			$l[]     = $ligne;
		}
		$total = array( 'Total (' . count( $c['services'] ) . ' services)' );
		foreach ( $ids as $id ) {
			$total[] = $tot[ $id ];
		}
		$total[] = array_sum( array_map( static fn( $id ) => $tot[ $id ], $ids ) );
		$entetes = array_merge( array( 'Service' ), array_map( static fn( $id ) => $cols[ $id ][0], $ids ), array( 'Total' ) );
		return array( 'entetes' => $entetes, 'lignes' => $l, 'total' => $total, 'num' => range( 1, count( $ids ) + 1 ), 'croise' => true );
	}

	/**
	 * Affichage.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! IGE_FFM_Contexte::exercice() ) {
			return ige_ffm_banner( 'warn', 'Aucun exercice ouvert', '' );
		}
		$vue = self::vue();
		$tab = self::tableau( $vue );
		$fmt = static function ( $v, $i, $num ) {
			if ( null === $v ) {
				return '<span class="ige-muted">—</span>';
			}
			if ( in_array( $i, $num, true ) ) {
				return '<span' . ( $v < 0 ? ' style="color:var(--ige-rouge)"' : '' ) . '>' . esc_html( ige_ffm_montant( (int) $v, '' ) ) . '</span>';
			}
			if ( is_float( $v ) || ( is_numeric( $v ) && ! is_string( $v ) ) ) {
				return '<span' . ( $v > 100 ? ' style="color:var(--ige-rouge);font-weight:700"' : '' ) . '>' . esc_html( number_format( (float) $v, 1, ',', '' ) ) . '</span>';
			}
			return esc_html( (string) $v );
		};
		ob_start();
		?>
		<section class="ige-card" style="padding:16px">
			<div class="ige-tabs">
				<?php foreach ( self::vues() as $k => $lbl ) : ?>
					<?php
					if ( 'services_rubriques' === $k ) {
						continue;
					}
					$on = $vue === $k || ( 'services_categories' === $k && 'services_rubriques' === $vue );
					?>
					<a class="<?php echo $on ? 'ige-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'vue', $k, ige_ffm_url( 'consolidation' ) ) ); ?>"><?php echo esc_html( $lbl ); ?></a>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $tab['croise'] ) ) : ?>
				<p class="ige-small" style="margin:0 0 10px">Colonnes :
					<?php if ( 'services_categories' === $vue ) : ?><strong>par catégorie</strong> · <a href="<?php echo esc_url( add_query_arg( 'vue', 'services_rubriques', ige_ffm_url( 'consolidation' ) ) ); ?>">par rubrique</a>
					<?php else : ?><a href="<?php echo esc_url( add_query_arg( 'vue', 'services_categories', ige_ffm_url( 'consolidation' ) ) ); ?>">par catégorie</a> · <strong>par rubrique</strong><?php endif; ?>
					<span class="ige-muted"> — montants consommés (payé + engagé) des écritures validées.</span>
				</p>
			<?php endif; ?>
			<div class="ige-table-wrap"><table class="ige-table ige-num">
				<thead><tr>
					<?php foreach ( $tab['entetes'] as $i => $h ) : ?>
						<th<?php echo $i ? ' class="ige-r"' : ''; ?><?php echo ! empty( $tab['croise'] ) && $i ? ' style="white-space:normal;min-width:96px;max-width:150px;vertical-align:bottom"' : ''; ?>><?php echo esc_html( $h ); ?></th>
					<?php endforeach; ?>
				</tr></thead>
				<tbody>
				<?php if ( $tab['total'] ) : ?>
					<tr class="ige-total"><?php foreach ( $tab['total'] as $i => $v ) : ?><td<?php echo $i ? ' class="ige-r"' : ''; ?>><?php echo $fmt( $v, $i, $tab['num'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?></tr>
				<?php endif; ?>
				<?php foreach ( $tab['lignes'] as $l ) : ?>
					<tr><?php foreach ( $l as $i => $v ) : ?><td<?php echo $i ? ' class="ige-r"' : ''; ?>><?php echo $fmt( $v, $i, $tab['num'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="ige-small ige-muted" style="margin:10px 0 0">Seules les écritures validées par Administration et Finance sont consolidées (règle RG-14). La caisse est celle du livre de chaque service. Les montants sont en francs congolais.</p>
		</section>
		<?php
		return ob_get_clean();
	}

	/**
	 * Données d'export d'une vue (Excel et PDF).
	 *
	 * @param string $vue Vue.
	 * @return array
	 */
	public static function export_donnees( $vue ) {
		$ex    = IGE_FFM_Contexte::exercice();
		$tab   = self::tableau( $vue );
		$vues  = self::vues();
		$types = array();
		foreach ( $tab['entetes'] as $i => $h ) {
			if ( 'À vérifier' === $h ) {
				$types[] = array( $h, 'nombre', 10 );
			} elseif ( in_array( $i, $tab['num'], true ) ) {
				$types[] = array( $h, 'montant' );
			} elseif ( false !== strpos( $h, '%' ) ) {
				$types[] = array( $h, 'decimal' );
			} elseif ( 'Dernier lot' === $h ) {
				$types[] = array( $h, 'texte', 11 );
			} else {
				$types[] = array( $h, 'texte', 0 === $i ? 26 : 14 );
			}
		}
		return array(
			'titre'      => 'Consolidation ' . $ex['annee'] . ' — par ' . strtolower( preg_replace( '/^Par /i', '', $vues[ $vue ] ?? $vue ) ),
			'sous_titre' => 'Écritures validées par Administration et Finance · ' . IGE_FFM_Classeur::extrait_par(),
			'colonnes'   => $types,
			'lignes'     => $tab['lignes'],
			'total'      => $tab['total'],
			'notes'      => array( 'Seules les écritures validées par Administration et Finance sont consolidées (règle RG-14). La caisse est celle du livre de chaque service.' ),
			'fichier'    => 'consolidation-' . $vue . '-' . $ex['annee'],
			'annee'      => $ex['annee'],
			'vue'        => $vue,
			'paysage'    => count( $types ) > 6,
		);
	}

	/**
	 * Export Excel (.xlsx) : un onglet par vue, la vue affichée en premier.
	 */
	public static function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_conso_export' ) ) {
			wp_die( 'Lien expiré.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_voir_tout' );
		$ex = IGE_FFM_Contexte::exercice();
		if ( ! $ex ) {
			wp_die( 'Aucun exercice ouvert.' );
		}
		$vue = self::vue();
		IGE_FFM_Audit::log( 'export_consolidation', 'exercice', $ex['annee'], null, array( 'vue' => $vue, 'format' => 'xlsx' ) );
		$c    = new IGE_FFM_Classeur( 'Consolidation ' . $ex['annee'] );
		$vues = array_keys( self::vues() );
		usort( $vues, static fn( $a, $b ) => ( $b === $vue ) <=> ( $a === $vue ) );
		foreach ( $vues as $v ) {
			$t = self::export_donnees( $v );
			$c->feuille( self::vues()[ $v ], $t );
		}
		$c->telecharger( 'consolidation-' . $ex['annee'] . '.xlsx' );
	}
}
