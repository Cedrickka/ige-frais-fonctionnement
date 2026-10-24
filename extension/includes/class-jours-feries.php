<?php
/**
 * Jours fériés de la République Démocratique du Congo : liste légale (fixe chaque année) et jours
 * exceptionnels décrétés (deuil national, jour d'élection…), modifiables par Administration et Finance
 * dans « Exercice et règles de contrôle ». Sert au contrôle « dépense datée un jour non ouvré ».
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Jours fériés.
 */
final class IGE_FFM_Jours_Feries {

	const OPTION = 'ige_ffm_jours_feries';

	/**
	 * Jours fériés légaux (mois-jour => libellé).
	 *
	 * @return array<string,string>
	 */
	public static function defauts() {
		return array(
			'01-01' => 'Nouvel An',
			'01-04' => 'Journée des martyrs de l’indépendance',
			'01-16' => 'Journée du héros national Laurent-Désiré Kabila',
			'01-17' => 'Journée du héros national Patrice Émery Lumumba',
			'04-06' => 'Journée du combat de Simon Kimbangu et de la conscience africaine',
			'05-01' => 'Fête du Travail',
			'05-17' => 'Journée de la Révolution et des Forces armées (FARDC)',
			'06-30' => 'Fête de l’Indépendance',
			'08-01' => 'Fête des Parents',
			'12-25' => 'Fête de Noël',
		);
	}

	/**
	 * Réglage enregistré.
	 *
	 * @return array{fixes:array<string,string>, exceptionnels:array<string,string>}
	 */
	public static function reglage() {
		$o = get_option( self::OPTION, null );
		if ( ! is_array( $o ) ) {
			return array( 'fixes' => self::defauts(), 'exceptionnels' => array() );
		}
		return array(
			'fixes'         => (array) ( $o['fixes'] ?? self::defauts() ),
			'exceptionnels' => (array) ( $o['exceptionnels'] ?? array() ),
		);
	}

	/**
	 * Jours fériés d'une année (AAAA-MM-JJ => libellé).
	 *
	 * @param int $annee Année.
	 * @return array<string,string>
	 */
	public static function annee( $annee ) {
		$r   = self::reglage();
		$out = array();
		foreach ( $r['fixes'] as $md => $l ) {
			$out[ sprintf( '%04d-%s', $annee, $md ) ] = $l;
		}
		foreach ( $r['exceptionnels'] as $d => $l ) {
			if ( 0 === strpos( $d, (string) $annee ) ) {
				$out[ $d ] = $l;
			}
		}
		ksort( $out );
		return (array) apply_filters( 'ige_ffm_jours_feries_annee', $out, (int) $annee );
	}

	/**
	 * Libellé du jour férié d'une date, ou chaîne vide.
	 *
	 * @param string $date AAAA-MM-JJ.
	 * @return string
	 */
	public static function libelle( $date ) {
		$date = substr( (string) $date, 0, 10 );
		return self::annee( (int) substr( $date, 0, 4 ) )[ $date ] ?? '';
	}

	/**
	 * Jour ouvré (lundi à vendredi, hors jours fériés) ?
	 *
	 * @param string $date AAAA-MM-JJ.
	 * @return bool
	 */
	public static function ouvre( $date ) {
		$ts = strtotime( substr( (string) $date, 0, 10 ) . ' 12:00:00 UTC' );
		return (int) gmdate( 'N', $ts ) < 6 && '' === self::libelle( $date );
	}

	/**
	 * Texte modifiable (une ligne par jour).
	 *
	 * @param array  $liste  Liste.
	 * @param string $format « md » (JJ/MM) ou « ymd » (JJ/MM/AAAA).
	 * @return string
	 */
	public static function en_texte( array $liste, $format ) {
		$l = array();
		foreach ( $liste as $k => $v ) {
			$p   = explode( '-', $k );
			$l[] = ( 'md' === $format ? $p[1] . '/' . $p[0] : $p[2] . '/' . $p[1] . '/' . $p[0] ) . ' ' . $v;
		}
		return implode( "\n", $l );
	}

	/**
	 * Lit une liste saisie (« 30/06 Fête de l’Indépendance », « 20/12/2026 Élections »).
	 *
	 * @param string $txt    Texte.
	 * @param string $format md | ymd.
	 * @return array|WP_Error
	 */
	public static function lire_texte( $txt, $format ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $txt ) as $n => $ligne ) {
			$ligne = trim( $ligne );
			if ( '' === $ligne ) {
				continue;
			}
			$re = 'md' === $format ? '#^(\d{1,2})[/.-](\d{1,2})\s+(.+)$#u' : '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})\s+(.+)$#u';
			if ( ! preg_match( $re, $ligne, $m ) ) {
				return new WP_Error( 'format', sprintf( 'Ligne %d illisible : « %s ». Format attendu : %s suivi du nom du jour.', $n + 1, $ligne, 'md' === $format ? 'JJ/MM' : 'JJ/MM/AAAA' ) );
			}
			$annee = 'md' === $format ? 2024 : (int) $m[3];
			if ( ! checkdate( (int) $m[2], (int) $m[1], $annee ) ) {
				return new WP_Error( 'date', sprintf( 'Ligne %d : date impossible (%s).', $n + 1, $ligne ) );
			}
			$lib = mb_substr( sanitize_text_field( 'md' === $format ? $m[3] : $m[4] ), 0, 120 );
			$cle = 'md' === $format ? sprintf( '%02d-%02d', $m[2], $m[1] ) : sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
			$out[ $cle ] = $lib;
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Enregistre les deux listes.
	 *
	 * @param string $fixes         Texte des jours légaux.
	 * @param string $exceptionnels Texte des jours exceptionnels.
	 * @return true|WP_Error
	 */
	public static function enregistrer( $fixes, $exceptionnels ) {
		$f = self::lire_texte( $fixes, 'md' );
		if ( is_wp_error( $f ) ) {
			return $f;
		}
		$e = self::lire_texte( $exceptionnels, 'ymd' );
		if ( is_wp_error( $e ) ) {
			return $e;
		}
		$avant = self::reglage();
		update_option( self::OPTION, array( 'fixes' => $f, 'exceptionnels' => $e ), false );
		IGE_FFM_Audit::log( 'reglage_jours_feries', 'reglages', 'jours_feries', array( 'fixes' => count( $avant['fixes'] ), 'exceptionnels' => count( $avant['exceptionnels'] ) ), array( 'fixes' => implode( ', ', array_keys( $f ) ), 'exceptionnels' => implode( ', ', array_keys( $e ) ) ) );
		return true;
	}
}
