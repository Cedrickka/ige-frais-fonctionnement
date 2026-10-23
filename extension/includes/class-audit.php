<?php
/**
 * Journal d'audit infalsifiable.
 *
 * Chaque ligne contient l'empreinte SHA-256 de son contenu et de l'empreinte de la ligne
 * précédente (chaînage). Modifier ou supprimer une ligne directement dans la base casse la
 * chaîne : la vérification d'intégrité le signale. L'application ne fournit aucune fonction
 * de modification ni de suppression du journal, y compris pour l'Admin.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Journal d'audit.
 */
final class IGE_FFM_Audit {

	/**
	 * Libellés des actions.
	 *
	 * @return array<string,string>
	 */
	public static function actions() {
		return array(
			'installation'            => 'Installation',
			'connexion'               => 'Connexion',
			'echec_connexion'         => 'Échec de connexion',
			'verrouillage'            => 'Verrouillage du compte',
			'deconnexion_inactivite'  => 'Déconnexion pour inactivité',
			'changement_mdp'          => 'Changement de mot de passe',
			'creation_compte'         => 'Création de compte',
			'modification_compte'     => 'Modification de compte',
			'desactivation_compte'    => 'Désactivation de compte',
			'reactivation_compte'     => 'Réactivation de compte',
			'reinitialisation_mdp'    => 'Réinitialisation du mot de passe',
			'revue_comptes'           => 'Revue des comptes',
			'creation_referentiel'    => 'Création (référentiel)',
			'modification_referentiel' => 'Modification (référentiel)',
			'modification_reglages'   => 'Modification des règles',
			'creation_exercice'       => "Création d'exercice",
			'export_audit'            => "Export du journal d'audit",
			'saisie_ecriture'         => 'Saisie d’une écriture',
			'paiement_engagement'     => 'Paiement d’un engagement',
			'contrepassation'         => 'Contrepassation',
			'ajout_piece'             => 'Ajout d’une pièce justificative',
			'modification_budget'     => 'Saisie du budget',
			'revision_budget'         => 'Révision budgétaire',
			'solde_initial'           => 'Solde initial de caisse',
			'creation_fournisseur'    => 'Création de fournisseur',
			'modification_fournisseur' => 'Modification de fournisseur',
			'fusion_fournisseurs'     => 'Fusion de fournisseurs',
			'export_livre'            => 'Export du livre de caisse',
		);
	}

	/**
	 * Écrit une ligne dans le journal.
	 *
	 * @param string            $action   Action (voir actions()).
	 * @param string            $objet    Type d'objet (compte, service, rubrique…).
	 * @param string|int        $objet_id Identifiant.
	 * @param array|string|null $avant    Valeurs avant.
	 * @param array|string|null $apres    Valeurs après.
	 * @param int|null          $user_id  Auteur (utilisateur courant par défaut).
	 * @return int Identifiant de la ligne.
	 */
	public static function log( $action, $objet = '', $objet_id = '', $avant = null, $apres = null, $user_id = null ) {
		global $wpdb;
		$table   = IGE_FFM_DB::t( 'audit' );
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$user    = $user_id ? get_userdata( $user_id ) : null;

		$row = array(
			'user_id'    => $user_id,
			'user_login' => $user ? $user->user_login : '',
			'action'     => substr( (string) $action, 0, 60 ),
			'objet'      => substr( (string) $objet, 0, 40 ),
			'objet_id'   => substr( (string) $objet_id, 0, 60 ),
			'avant'      => null === $avant ? null : wp_json_encode( $avant, JSON_UNESCAPED_UNICODE ),
			'apres'      => null === $apres ? null : wp_json_encode( $apres, JSON_UNESCAPED_UNICODE ),
			'ip'         => ige_ffm_ip(),
			'created_at' => ige_ffm_now(),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$prev              = (string) $wpdb->get_var( "SELECT empreinte FROM $table ORDER BY id DESC LIMIT 1" );
		$row['empreinte'] = self::hash( $prev, $row );
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * Empreinte d'une ligne.
	 *
	 * @param string $prev Empreinte précédente.
	 * @param array  $row  Ligne.
	 * @return string
	 */
	private static function hash( $prev, array $row ) {
		$data = implode(
			'|',
			array(
				$prev,
				(string) $row['user_id'],
				(string) $row['user_login'],
				(string) $row['action'],
				(string) $row['objet'],
				(string) $row['objet_id'],
				(string) $row['avant'],
				(string) $row['apres'],
				(string) $row['ip'],
				(string) $row['created_at'],
			)
		);
		return hash( 'sha256', $data );
	}

	/**
	 * Vérifie l'intégrité de toute la chaîne.
	 *
	 * @return array{ok:bool, lignes:int, rupture:int|null}
	 */
	public static function verify() {
		global $wpdb;
		$table  = IGE_FFM_DB::t( 'audit' );
		$prev   = '';
		$count  = 0;
		$offset = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id ASC LIMIT %d OFFSET %d", 500, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				++$count;
				if ( ! hash_equals( self::hash( $prev, $row ), (string) $row['empreinte'] ) ) {
					return array( 'ok' => false, 'lignes' => $count, 'rupture' => (int) $row['id'] );
				}
				$prev = $row['empreinte'];
			}
			$offset += 500;
		} while ( count( $rows ) === 500 );
		return array( 'ok' => true, 'lignes' => $count, 'rupture' => null );
	}

	/**
	 * Recherche dans le journal.
	 *
	 * @param array $f Filtres : user_id, action, du, au, page, par_page.
	 * @return array{rows:array, total:int}
	 */
	public static function search( array $f ) {
		global $wpdb;
		$table = IGE_FFM_DB::t( 'audit' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['user_id'] ) ) {
			$where[] = 'user_id = %d';
			$args[]  = (int) $f['user_id'];
		}
		if ( ! empty( $f['action'] ) ) {
			$where[] = 'action = %s';
			$args[]  = $f['action'];
		}
		if ( ! empty( $f['du'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $f['du'] . ' 00:00:00';
		}
		if ( ! empty( $f['au'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $f['au'] . ' 23:59:59';
		}
		$per    = max( 1, (int) ( $f['par_page'] ?? 50 ) );
		$page   = max( 1, (int) ( $f['page'] ?? 1 ) );
		$sql_w  = implode( ' AND ', $where );
		$q_args = array_merge( $args, array( $per, ( $page - 1 ) * $per ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $sql_w ORDER BY id DESC LIMIT %d OFFSET %d", $q_args ), ARRAY_A );
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sql_w", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
		// phpcs:enable
		return array( 'rows' => $rows, 'total' => $total );
	}

	/**
	 * Résumé lisible du changement « avant → après ».
	 *
	 * @param array $row Ligne du journal.
	 * @return string
	 */
	public static function resume( array $row ) {
		$avant = $row['avant'] ? json_decode( $row['avant'], true ) : null;
		$apres = $row['apres'] ? json_decode( $row['apres'], true ) : null;
		if ( is_array( $avant ) && is_array( $apres ) ) {
			$parts = array();
			foreach ( $apres as $k => $v ) {
				if ( array_key_exists( $k, $avant ) && self::val( $avant[ $k ] ) !== self::val( $v ) ) {
					$parts[] = $k . ' : ' . self::val( $avant[ $k ] ) . ' → ' . self::val( $v );
				}
			}
			return implode( ' ; ', $parts );
		}
		if ( is_array( $apres ) ) {
			$parts = array();
			foreach ( array_slice( $apres, 0, 4, true ) as $k => $v ) {
				$parts[] = $k . ' : ' . self::val( $v );
			}
			return implode( ' ; ', $parts );
		}
		return '';
	}

	/**
	 * Valeur affichable.
	 *
	 * @param mixed $v Valeur.
	 * @return string
	 */
	private static function val( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'oui' : 'non';
		}
		if ( is_array( $v ) ) {
			return wp_json_encode( $v, JSON_UNESCAPED_UNICODE );
		}
		return (string) $v;
	}
}
