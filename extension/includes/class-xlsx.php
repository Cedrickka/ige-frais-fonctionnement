<?php
/**
 * Lecteur minimal de classeurs Excel (.xlsx et .xlsm), sans bibliothèque externe :
 * valeurs des cellules (textes partagés, textes en ligne, nombres, booléens, résultats de formules).
 * Les macros ne sont jamais exécutées ni lues.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lecteur XLSX.
 */
final class IGE_FFM_Xlsx {

	const TAILLE_MAX = 20971520; // 20 Mo.

	/**
	 * Lit un classeur.
	 *
	 * @param string $chemin Fichier.
	 * @return array<string,array<int,array<int,mixed>>>|WP_Error Feuilles : nom => lignes (n° de ligne => colonnes indexées à partir de 0).
	 */
	public static function lire( $chemin ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', 'Le serveur ne sait pas ouvrir les fichiers Excel (extension PHP « zip » absente).' );
		}
		if ( ! is_readable( $chemin ) || filesize( $chemin ) > self::TAILLE_MAX ) {
			return new WP_Error( 'fichier', 'Fichier illisible ou trop volumineux (20 Mo au plus).' );
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $chemin ) ) {
			return new WP_Error( 'fichier', 'Ce fichier n’est pas un classeur Excel (.xlsx ou .xlsm).' );
		}
		$wb   = self::xml( $z, 'xl/workbook.xml' );
		$rels = self::xml( $z, 'xl/_rels/workbook.xml.rels' );
		if ( ! $wb || ! $rels ) {
			$z->close();
			return new WP_Error( 'fichier', 'Classeur Excel endommagé ou d’un format non pris en charge.' );
		}
		$cibles = array();
		foreach ( $rels->Relationship as $r ) {
			$cibles[ (string) $r['Id'] ] = (string) $r['Target'];
		}
		$partages = array();
		$ss       = self::xml( $z, 'xl/sharedStrings.xml' );
		if ( $ss ) {
			foreach ( $ss->si as $si ) {
				$partages[] = self::texte( $si );
			}
		}
		$out = array();
		$wb->registerXPathNamespace( 'm', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );
		foreach ( $wb->sheets->sheet as $sh ) {
			$rid    = (string) $sh->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			$cible  = $cibles[ $rid ] ?? '';
			$cible  = 0 === strpos( $cible, '/' ) ? ltrim( $cible, '/' ) : 'xl/' . $cible;
			$feuille = self::xml( $z, $cible );
			$lignes  = array();
			if ( $feuille && isset( $feuille->sheetData ) ) {
				foreach ( $feuille->sheetData->row as $row ) {
					$n = (int) $row['r'];
					foreach ( $row->c as $c ) {
						$col = self::colonne( (string) $c['r'] );
						$t   = (string) $c['t'];
						$v   = isset( $c->v ) ? (string) $c->v : null;
						if ( 's' === $t ) {
							$val = $partages[ (int) $v ] ?? '';
						} elseif ( 'inlineStr' === $t ) {
							$val = self::texte( $c->is );
						} elseif ( 'b' === $t ) {
							$val = '1' === $v;
						} elseif ( 'str' === $t || 'e' === $t ) {
							$val = $v;
						} else {
							$val = null === $v ? null : ( is_numeric( $v ) ? $v + 0 : $v );
						}
						if ( null !== $val && '' !== $val ) {
							$lignes[ $n ][ $col ] = $val;
						}
					}
				}
			}
			$out[ (string) $sh['name'] ] = $lignes;
		}
		$z->close();
		return $out;
	}

	/**
	 * Partie XML d'une archive.
	 *
	 * @param ZipArchive $z    Archive.
	 * @param string     $nom  Chemin.
	 * @return SimpleXMLElement|null
	 */
	private static function xml( ZipArchive $z, $nom ) {
		$s = $z->getFromName( $nom );
		if ( false === $s || strlen( $s ) > 60 * 1048576 ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$x    = simplexml_load_string( $s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT );
		libxml_use_internal_errors( $prev );
		return $x ?: null;
	}

	/**
	 * Texte d'un élément (avec ou sans mise en forme riche).
	 *
	 * @param SimpleXMLElement|null $e Élément.
	 * @return string
	 */
	private static function texte( $e ) {
		if ( ! $e ) {
			return '';
		}
		if ( isset( $e->t ) ) {
			return (string) $e->t;
		}
		$s = '';
		foreach ( $e->r as $r ) {
			$s .= (string) $r->t;
		}
		return $s;
	}

	/**
	 * Index (base 0) de la colonne d'une référence « AB12 ».
	 *
	 * @param string $ref Référence.
	 * @return int
	 */
	public static function colonne( $ref ) {
		$l = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$n = 0;
		foreach ( str_split( $l ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}

	/**
	 * Date Excel (nombre de série ou texte JJ/MM/AAAA) en AAAA-MM-JJ.
	 *
	 * @param mixed $v Valeur.
	 * @return string Vide si invalide.
	 */
	public static function date( $v ) {
		if ( is_int( $v ) || is_float( $v ) ) {
			if ( $v < 1 || $v > 2958465 ) {
				return '';
			}
			return gmdate( 'Y-m-d', (int) round( ( $v - 25569 ) * 86400 ) );
		}
		$v = trim( (string) $v );
		if ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $v, $m ) && checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ) {
			return sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] );
		}
		if ( preg_match( '#^(\d{4})-(\d{2})-(\d{2})#', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $m[1] . '-' . $m[2] . '-' . $m[3];
		}
		return '';
	}

	/**
	 * Cherche la ligne d'en-tête contenant un libellé, et renvoie la correspondance libellé normalisé => colonne.
	 *
	 * @param array  $lignes Lignes.
	 * @param string $repere Libellé repère (ex. « ID_ECRITURE »).
	 * @return array{ligne:int, colonnes:array<string,int>}|null
	 */
	public static function entetes( array $lignes, $repere ) {
		$cle = self::norm( $repere );
		foreach ( $lignes as $n => $cols ) {
			foreach ( $cols as $c => $v ) {
				if ( is_string( $v ) && self::norm( $v ) === $cle ) {
					$map = array();
					foreach ( $cols as $c2 => $v2 ) {
						if ( is_string( $v2 ) ) {
							$map[ self::norm( $v2 ) ] = $c2;
						}
					}
					return array( 'ligne' => $n, 'colonnes' => $map );
				}
			}
		}
		return null;
	}

	/**
	 * Libellé normalisé (minuscules, sans accents ni ponctuation).
	 *
	 * @param string $s Texte.
	 * @return string
	 */
	public static function norm( $s ) {
		return preg_replace( '/[^a-z0-9]/', '', strtolower( remove_accents( (string) $s ) ) );
	}
}
