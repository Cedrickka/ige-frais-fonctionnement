<?php
/**
 * Répertoire des fournisseurs (VA-13) : identification, historique, doublons, fusion.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fournisseurs.
 */
final class IGE_FFM_Fournisseurs {

	/**
	 * Forme normalisée d'une raison sociale pour repérer les doublons
	 * (« Papeterie du Centre SARL » = « PAPETERIE CENTRE »).
	 *
	 * @param string $nom Raison sociale.
	 * @return string
	 */
	public static function normaliser( $nom ) {
		$n = strtolower( remove_accents( (string) $nom ) );
		$n = preg_replace( '/\b(sarl|sprl|sa|sas|suarl|ets|etablissements?|societe|ste|the|du|de|des|la|le|les|et)\b/', ' ', $n );
		return preg_replace( '/[^a-z0-9]/', '', $n );
	}

	/**
	 * Téléphone normalisé (chiffres seuls, sans indicatif 243).
	 *
	 * @param string $tel Téléphone.
	 * @return string
	 */
	private static function tel( $tel ) {
		$d = preg_replace( '/\D/', '', (string) $tel );
		return preg_replace( '/^(00)?243/', '0', $d );
	}

	/**
	 * Liste.
	 *
	 * @param bool $actifs_seul Seulement les actifs.
	 * @return array[]
	 */
	public static function all( $actifs_seul = true ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'fournisseur' );
		$w = $actifs_seul ? 'WHERE actif = 1' : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $t $w ORDER BY raison_sociale", ARRAY_A );
	}

	/**
	 * Un fournisseur.
	 *
	 * @param int $id Identifiant.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'fournisseur' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ), ARRAY_A );
		return $r ?: null;
	}

	/**
	 * Cherche un fournisseur actif identique (même NIF, même nom normalisé ou même téléphone).
	 *
	 * @param array $d        Données.
	 * @param int   $sauf_id  Fournisseur à ignorer.
	 * @return array|null
	 */
	public static function trouver_identique( array $d, $sauf_id = 0 ) {
		$nom = self::normaliser( $d['raison_sociale'] ?? '' );
		$nif = strtoupper( trim( (string) ( $d['nif'] ?? '' ) ) );
		$tel = self::tel( $d['telephone'] ?? '' );
		foreach ( self::all() as $f ) {
			if ( (int) $f['id'] === (int) $sauf_id ) {
				continue;
			}
			if ( ( $nif && strtoupper( $f['nif'] ) === $nif ) || ( $nom && self::normaliser( $f['raison_sociale'] ) === $nom ) || ( strlen( $tel ) >= 9 && self::tel( $f['telephone'] ) === $tel ) ) {
				return $f;
			}
		}
		return null;
	}

	/**
	 * Crée ou modifie un fournisseur.
	 *
	 * @param array $data    Données.
	 * @param int   $id      Identifiant (0 = création).
	 * @param int   $user_id Auteur.
	 * @return int|WP_Error
	 */
	public static function save( array $data, $id, $user_id ) {
		global $wpdb;
		$row = array(
			'raison_sociale' => trim( sanitize_text_field( (string) ( $data['raison_sociale'] ?? '' ) ) ),
			'nif'            => strtoupper( trim( sanitize_text_field( (string) ( $data['nif'] ?? '' ) ) ) ),
			'rccm'           => trim( sanitize_text_field( (string) ( $data['rccm'] ?? '' ) ) ),
			'telephone'      => trim( sanitize_text_field( (string) ( $data['telephone'] ?? '' ) ) ),
		);
		if ( mb_strlen( $row['raison_sociale'] ) < 2 ) {
			return new WP_Error( 'raison_sociale', 'La raison sociale est obligatoire.' );
		}
		$same = self::trouver_identique( $row, $id );
		if ( $same ) {
			return new WP_Error( 'doublon', sprintf( 'Ce fournisseur existe déjà : « %s ». Utilisez-le plutôt que d’en créer un second.', $same['raison_sociale'] ), array( 'id' => (int) $same['id'] ) );
		}
		$t = IGE_FFM_DB::t( 'fournisseur' );
		if ( $id ) {
			$before = self::get( $id );
			if ( ! $before ) {
				return new WP_Error( 'id', 'Fournisseur introuvable.' );
			}
			$row['actif']      = empty( $data['actif'] ) ? 0 : 1;
			$row['updated_at'] = ige_ffm_now();
			$wpdb->update( $t, $row, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$keys = array( 'raison_sociale', 'nif', 'rccm', 'telephone', 'actif' );
			$av   = array();
			$ap   = array();
			foreach ( $keys as $k ) {
				if ( (string) $before[ $k ] !== (string) $row[ $k ] ) {
					$av[ $k ] = $before[ $k ];
					$ap[ $k ] = $row[ $k ];
				}
			}
			if ( $ap ) {
				IGE_FFM_Audit::log( 'modification_fournisseur', 'fournisseur', $id, $av, $ap, $user_id );
			}
			return (int) $id;
		}
		$row['actif']      = 1;
		$row['created_by'] = (int) $user_id;
		$row['created_at'] = ige_ffm_now();
		$wpdb->insert( $t, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new = (int) $wpdb->insert_id;
		IGE_FFM_Audit::log( 'creation_fournisseur', 'fournisseur', $new, null, $row, $user_id );
		return $new;
	}

	/**
	 * Groupes de doublons probables parmi les fournisseurs actifs.
	 *
	 * @return array[] Liste de groupes (chaque groupe : liste de fournisseurs).
	 */
	public static function doublons() {
		$groupes = array();
		foreach ( self::all() as $f ) {
			$cles = array( 'n:' . self::normaliser( $f['raison_sociale'] ) );
			if ( $f['nif'] ) {
				$cles[] = 'f:' . strtoupper( $f['nif'] );
			}
			$tel = self::tel( $f['telephone'] );
			if ( strlen( $tel ) >= 9 ) {
				$cles[] = 't:' . $tel;
			}
			foreach ( $cles as $k ) {
				$groupes[ $k ][ $f['id'] ] = $f;
			}
		}
		$out  = array();
		$seen = array();
		foreach ( $groupes as $g ) {
			if ( count( $g ) < 2 ) {
				continue;
			}
			$ids = array_keys( $g );
			sort( $ids );
			$key = implode( ',', $ids );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$out[]        = array_values( $g );
			}
		}
		return $out;
	}

	/**
	 * Fusionne deux fournisseurs : les écritures du second passent au premier, le second est désactivé.
	 *
	 * @param int $garde   Fournisseur conservé.
	 * @param int $absorbe Fournisseur absorbé.
	 * @param int $user_id Auteur.
	 * @return true|WP_Error
	 */
	public static function fusionner( $garde, $absorbe, $user_id ) {
		global $wpdb;
		$a = self::get( $garde );
		$b = self::get( $absorbe );
		if ( ! $a || ! $b || $garde === $absorbe ) {
			return new WP_Error( 'fusion', 'Choisissez deux fournisseurs différents.' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$n = $wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'fournisseur_id' => $garde ), array( 'fournisseur_id' => $absorbe ) );
		$wpdb->update( IGE_FFM_DB::t( 'fournisseur' ), array( 'actif' => 0, 'fusionne_dans' => $garde, 'updated_at' => ige_ffm_now() ), array( 'id' => $absorbe ) );
		// phpcs:enable
		IGE_FFM_Audit::log( 'fusion_fournisseurs', 'fournisseur', $garde, array( 'absorbe' => $b['raison_sociale'] ), array( 'conserve' => $a['raison_sociale'], 'ecritures_rattachees' => (int) $n ), $user_id );
		return true;
	}

	/**
	 * Total payé et nombre de services par fournisseur sur un exercice.
	 *
	 * @param int $exo Exercice.
	 * @return array<int,array{paye:int,services:int}>
	 */
	public static function statistiques( $exo ) {
		global $wpdb;
		$t = IGE_FFM_DB::t( 'ecriture' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT fournisseur_id, SUM(CASE WHEN statut = 'payee' THEN montant ELSE 0 END) AS paye, COUNT(DISTINCT service_id) AS services FROM $t WHERE exercice_id = %d AND type = 'sortie' AND fournisseur_id IS NOT NULL GROUP BY fournisseur_id", $exo ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['fournisseur_id'] ] = array( 'paye' => (int) $r['paye'], 'services' => (int) $r['services'] );
		}
		return $out;
	}
}
