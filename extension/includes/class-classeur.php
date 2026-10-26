<?php
/**
 * Classeurs Excel (.xlsx) mis en forme selon la charte : armoiries, intitulé du ministère,
 * bande tricolore, titre, en-têtes bleu institutionnel, lignes alternées, montants au format
 * « 1 250 000 » (négatifs en rouge), dates réelles, ligne de total, volets figés, filtres,
 * impression A4 paysage ajustée à la largeur avec en-têtes répétés et pied de page.
 *
 * Écrit sans bibliothèque externe (ZipArchive). Les textes sont toujours des textes : une
 * cellule commençant par « = » n'est jamais interprétée comme une formule.
 *
 * Exemple :
 *   $c = new IGE_FFM_Classeur( 'Livre de caisse 8000 IG' );
 *   $c->feuille( 'Livre', array( 'titre' => …, 'sous_titre' => …, 'colonnes' => array( array( 'Date', 'date' ), array( 'Montant', 'montant' ) ), 'lignes' => …, 'total' => … ) );
 *   $c->telecharger( 'livre.xlsx' );
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classeur Excel.
 */
final class IGE_FFM_Classeur {

	const BLEU_INST = '17418A';
	const BLEU      = '0095C9';
	const JAUNE     = 'FFF24B';
	const ROUGE     = 'DB3832';
	const ENCRE     = '323230';
	const GRIS      = '5D6670';
	const LIGNE     = 'D5DBE3';
	const ZEBRE     = 'F3F5F8';
	const TOTAL     = 'E6ECF5';

	/** Largeurs par défaut selon le type. */
	const LARGEURS = array( 'texte' => 14, 'long' => 38, 'montant' => 15, 'date' => 11.5, 'dateheure' => 16, 'nombre' => 9, 'decimal' => 11 );

	/** @var string */
	private $titre;
	/** @var array[] */
	private $feuilles = array();
	/** @var array Polices, remplissages, bordures, formats, styles. */
	private $fonts   = array();
	private $fills   = array( '<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>' );
	private $borders = array( '<border><left/><right/><top/><bottom/><diagonal/></border>' );
	private $fmts    = array();
	private $xfs     = array();
	private $index   = array();

	/**
	 * Constructeur.
	 *
	 * @param string $titre Titre du document (propriétés du fichier).
	 */
	public function __construct( $titre = '' ) {
		$this->titre = (string) $titre;
		$this->fonts[] = $this->font( array() );
		$this->xfs[]   = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
	}

	/**
	 * Ajoute une feuille.
	 *
	 * @param string $nom Nom de l'onglet (31 caractères au plus).
	 * @param array  $d   titre, sous_titre, colonnes [ [libellé, type, largeur?] ], lignes (tableaux de valeurs),
	 *                    total (ligne ou null), notes (textes sous le tableau), avant (lignes spéciales en tête : [libellé, colonne => valeur]).
	 * @return self
	 */
	public function feuille( $nom, array $d ) {
		$nom = trim( preg_replace( '#[\[\]\*\?/\\\\:]#', ' ', (string) $nom ) );
		$nom = mb_substr( '' !== $nom ? $nom : 'Feuille', 0, 31 );
		$this->feuilles[] = array( 'nom' => $nom ) + $d + array( 'titre' => '', 'sous_titre' => '', 'colonnes' => array(), 'lignes' => array(), 'total' => null, 'notes' => array() );
		return $this;
	}

	// ---------------------------------------------------------------------
	// Styles.
	// ---------------------------------------------------------------------

	/**
	 * XML d'une police.
	 *
	 * @param array $o b, i, sz, color.
	 * @return string
	 */
	private function font( array $o ) {
		return '<font>' . ( ! empty( $o['b'] ) ? '<b/>' : '' ) . ( ! empty( $o['i'] ) ? '<i/>' : '' ) . '<sz val="' . ( $o['sz'] ?? 10 ) . '"/><color rgb="FF' . ( $o['color'] ?? self::ENCRE ) . '"/><name val="Arial"/><family val="2"/></font>';
	}

	/**
	 * Index d'un élément dans une liste (ajouté s'il manque).
	 *
	 * @param array  $liste Liste.
	 * @param string $xml   Élément.
	 * @return int
	 */
	private static function idx( array &$liste, $xml ) {
		$i = array_search( $xml, $liste, true );
		if ( false === $i ) {
			$liste[] = $xml;
			$i       = count( $liste ) - 1;
		}
		return $i;
	}

	/**
	 * Style de cellule.
	 *
	 * @param array $o font (array), fill (hex), border (thin|total|none), fmt (code), h (left|center|right), wrap, v (top|center).
	 * @return int
	 */
	private function style( array $o ) {
		$cle = wp_json_encode( $o );
		if ( isset( $this->index[ $cle ] ) ) {
			return $this->index[ $cle ];
		}
		$font = self::idx( $this->fonts, $this->font( $o['font'] ?? array() ) );
		$fill = empty( $o['fill'] ) ? 0 : self::idx( $this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . $o['fill'] . '"/><bgColor indexed="64"/></patternFill></fill>' );
		$b    = $o['border'] ?? 'none';
		if ( 'thin' === $b ) {
			$s      = '<left style="thin"><color rgb="FF' . self::LIGNE . '"/></left><right style="thin"><color rgb="FF' . self::LIGNE . '"/></right><top style="thin"><color rgb="FF' . self::LIGNE . '"/></top><bottom style="thin"><color rgb="FF' . self::LIGNE . '"/></bottom>';
			$border = self::idx( $this->borders, '<border>' . $s . '<diagonal/></border>' );
		} elseif ( 'total' === $b ) {
			$border = self::idx( $this->borders, '<border><left style="thin"><color rgb="FF' . self::LIGNE . '"/></left><right style="thin"><color rgb="FF' . self::LIGNE . '"/></right><top style="medium"><color rgb="FF' . self::BLEU_INST . '"/></top><bottom style="medium"><color rgb="FF' . self::BLEU_INST . '"/></bottom><diagonal/></border>' );
		} elseif ( 'tete' === $b ) {
			$border = self::idx( $this->borders, '<border><left style="thin"><color rgb="FFFFFFFF"/></left><right style="thin"><color rgb="FFFFFFFF"/></right><top/><bottom/><diagonal/></border>' );
		} else {
			$border = 0;
		}
		$fmt = 0;
		if ( ! empty( $o['fmt'] ) ) {
			$fmt = 164 + self::idx( $this->fmts, $o['fmt'] );
		}
		$align = '';
		if ( ! empty( $o['h'] ) || ! empty( $o['wrap'] ) || ! empty( $o['v'] ) ) {
			$align = '<alignment' . ( ! empty( $o['indent'] ) ? ' horizontal="left" indent="' . (int) $o['indent'] . '"' : ( ! empty( $o['h'] ) ? ' horizontal="' . $o['h'] . '"' : '' ) ) . ' vertical="' . ( $o['v'] ?? 'top' ) . '"' . ( ! empty( $o['wrap'] ) ? ' wrapText="1"' : '' ) . '/>';
		}
		$this->xfs[]         = '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0"' . ( $fmt ? ' applyNumberFormat="1"' : '' ) . ' applyFont="1"' . ( $fill ? ' applyFill="1"' : '' ) . ( $border ? ' applyBorder="1"' : '' ) . ( $align ? ' applyAlignment="1">' . $align . '</xf>' : '/>' );
		$this->index[ $cle ] = count( $this->xfs ) - 1;
		return $this->index[ $cle ];
	}

	/**
	 * Format et alignement d'un type de colonne.
	 *
	 * @param string $type Type.
	 * @return array
	 */
	private static function format( $type ) {
		switch ( $type ) {
			case 'montant':
				return array( 'fmt' => '#,##0;[Red]\-#,##0', 'h' => 'right' );
			case 'date':
				return array( 'fmt' => 'dd/mm/yyyy', 'h' => 'center' );
			case 'dateheure':
				return array( 'fmt' => 'dd/mm/yyyy hh:mm', 'h' => 'center' );
			case 'nombre':
				return array( 'fmt' => '#,##0', 'h' => 'right' );
			case 'decimal':
				return array( 'fmt' => '0.0', 'h' => 'right' );
			case 'long':
				return array( 'wrap' => true );
			default:
				return array();
		}
	}

	// ---------------------------------------------------------------------
	// Cellules.
	// ---------------------------------------------------------------------

	/**
	 * Nom de colonne (0 → A).
	 *
	 * @param int $i Index.
	 * @return string
	 */
	public static function col( $i ) {
		$s = '';
		for ( ++$i; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	/**
	 * Texte sûr pour XML (caractères de contrôle retirés).
	 *
	 * @param string $s Texte.
	 * @return string
	 */
	private static function x( $s ) {
		$s = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $s );
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Numéro de série Excel d'une date (AAAA-MM-JJ ou AAAA-MM-JJ HH:MM:SS).
	 *
	 * @param string $d Date.
	 * @return float|null
	 */
	public static function serie( $d ) {
		$d = trim( (string) $d );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $d, $m ) ) {
			return null;
		}
		$jours = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] ) / 86400 + 25569;
		$frac  = isset( $m[4] ) ? ( (int) $m[4] * 3600 + (int) $m[5] * 60 + (int) ( $m[6] ?? 0 ) ) / 86400 : 0;
		return round( $jours + $frac, 6 );
	}

	/**
	 * Cellule.
	 *
	 * @param string $ref   Référence (B4).
	 * @param mixed  $v     Valeur.
	 * @param int    $s     Style.
	 * @param string $type  Type de la colonne.
	 * @return string
	 */
	private static function cellule( $ref, $v, $s, $type = 'texte' ) {
		if ( null === $v || '' === $v ) {
			return '<c r="' . $ref . '" s="' . $s . '"/>';
		}
		if ( in_array( $type, array( 'date', 'dateheure' ), true ) && is_string( $v ) ) {
			$n = self::serie( $v );
			if ( null !== $n ) {
				return '<c r="' . $ref . '" s="' . $s . '"><v>' . $n . '</v></c>';
			}
		}
		if ( in_array( $type, array( 'montant', 'nombre', 'decimal' ), true ) && is_numeric( $v ) ) {
			return '<c r="' . $ref . '" s="' . $s . '"><v>' . ( 0 + $v ) . '</v></c>';
		}
		return '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::x( $v ) . '</t></is></c>';
	}

	// ---------------------------------------------------------------------
	// Feuilles.
	// ---------------------------------------------------------------------

	/**
	 * XML d'une feuille.
	 *
	 * @param array $f Feuille.
	 * @param int   $n Numéro (1…).
	 * @return array{xml:string, filtre:string, titres:string}
	 */
	private function xml_feuille( array $f, $n ) {
		$cols   = array_values( $f['colonnes'] );
		$nb     = max( 2, count( $cols ) );
		$der    = self::col( $nb - 1 );
		$rows   = array();
		$merges = array();

		$s_min  = $this->style( array( 'font' => array( 'b' => true, 'sz' => 11, 'color' => self::BLEU_INST ), 'v' => 'center', 'indent' => 7 ) );
		$s_ent  = $this->style( array( 'font' => array( 'sz' => 9.5, 'color' => self::ENCRE ), 'v' => 'center', 'indent' => 7 ) );
		$s_tit  = $this->style( array( 'font' => array( 'b' => true, 'sz' => 14, 'color' => self::BLEU_INST ), 'v' => 'center' ) );
		$s_sous = $this->style( array( 'font' => array( 'i' => true, 'sz' => 9, 'color' => self::GRIS ), 'v' => 'center', 'wrap' => true ) );
		$s_tri  = array( $this->style( array( 'fill' => self::BLEU ) ), $this->style( array( 'fill' => self::JAUNE ) ), $this->style( array( 'fill' => self::ROUGE ) ) );
		$s_head = $this->style( array( 'font' => array( 'b' => true, 'sz' => 9.5, 'color' => 'FFFFFF' ), 'fill' => self::BLEU_INST, 'border' => 'tete', 'h' => 'center', 'v' => 'center', 'wrap' => true ) );
		$s_note = $this->style( array( 'font' => array( 'i' => true, 'sz' => 8.5, 'color' => self::GRIS ), 'wrap' => true ) );

		// En-tête institutionnel (les armoiries sont posées sur A1:A2, le texte est décalé).
		$rows[1] = '<row r="1" ht="20" customHeight="1">' . self::cellule( 'A1', 'MINISTÈRE DE L’ÉDUCATION NATIONALE ET NOUVELLE CITOYENNETÉ', $s_min ) . '</row>';
		$rows[2] = '<row r="2" ht="18" customHeight="1">' . self::cellule( 'A2', 'Inspection Générale de l’Enseignement · ' . ige_ffm_nom_appli(), $s_ent ) . '</row>';
		$tri     = '';
		for ( $i = 0; $i < $nb; $i++ ) {
			$tri .= '<c r="' . self::col( $i ) . '3" s="' . $s_tri[ min( 2, intdiv( $i * 3, $nb ) ) ] . '"/>';
		}
		$rows[3] = '<row r="3" ht="4.5" customHeight="1">' . $tri . '</row>';
		$rows[5] = '<row r="5" ht="24" customHeight="1">' . self::cellule( 'A5', $f['titre'], $s_tit ) . '</row>';
		$rows[6] = '<row r="6" ht="' . ( mb_strlen( (string) $f['sous_titre'] ) > 160 ? 30 : 16 ) . '" customHeight="1">' . self::cellule( 'A6', $f['sous_titre'], $s_sous ) . '</row>';
		$merges[] = 'A1:' . $der . '1';
		$merges[] = 'A2:' . $der . '2';
		$merges[] = 'A5:' . $der . '5';
		$merges[] = 'A6:' . $der . '6';

		// En-têtes de colonnes.
		$h = 8;
		$c = '';
		foreach ( $cols as $i => $col ) {
			$c .= self::cellule( self::col( $i ) . $h, $col[0], $s_head );
		}
		$rows[ $h ] = '<row r="' . $h . '" ht="30" customHeight="1">' . $c . '</row>';

		// Données.
		$r = $h;
		foreach ( array_values( $f['lignes'] ) as $k => $ligne ) {
			++$r;
			$zebre = $k % 2 ? self::ZEBRE : '';
			$c     = '';
			$sp    = is_array( $ligne ) && isset( $ligne['_style'] ) ? $ligne['_style'] : '';
			$vals  = array_values( array_diff_key( (array) $ligne, array( '_style' => 1 ) ) );
			foreach ( $cols as $i => $col ) {
				$type = $col[1] ?? 'texte';
				$o    = array( 'border' => 'thin', 'fill' => $zebre, 'font' => array( 'sz' => 9.5, 'b' => 'gras' === $sp, 'i' => 'italique' === $sp ), 'v' => 'top' ) + self::format( $type );
				$c   .= self::cellule( self::col( $i ) . $r, $vals[ $i ] ?? null, $this->style( $o ), $type );
			}
			$rows[ $r ] = '<row r="' . $r . '">' . $c . '</row>';
		}
		$fin_donnees = $r;

		// Total.
		if ( $f['total'] ) {
			++$r;
			$c    = '';
			$vals = array_values( $f['total'] );
			foreach ( $cols as $i => $col ) {
				$type = $col[1] ?? 'texte';
				$o    = array( 'border' => 'total', 'fill' => self::TOTAL, 'font' => array( 'b' => true, 'sz' => 10, 'color' => self::BLEU_INST ), 'v' => 'center' ) + self::format( $type );
				$c   .= self::cellule( self::col( $i ) . $r, $vals[ $i ] ?? null, $this->style( $o ), $type );
			}
			$rows[ $r ] = '<row r="' . $r . '" ht="20" customHeight="1">' . $c . '</row>';
		}

		// Notes.
		++$r;
		foreach ( (array) $f['notes'] as $note ) {
			++$r;
			$rows[ $r ] = '<row r="' . $r . '" ht="' . ( mb_strlen( $note ) > 140 ? 26 : 14 ) . '" customHeight="1">' . self::cellule( 'A' . $r, $note, $s_note ) . '</row>';
			$merges[]   = 'A' . $r . ':' . $der . $r;
		}

		// Largeurs.
		$xcols = '';
		foreach ( $cols as $i => $col ) {
			$w = (float) ( $col[2] ?? ( self::LARGEURS[ $col[1] ?? 'texte' ] ?? 14 ) );
			if ( 0 === $i ) {
				$w = max( $w, 9 );
			}
			$xcols .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		ksort( $rows );
		$filtre = 'A' . $h . ':' . $der . max( $h + 1, $fin_donnees );
		$xml    = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
			. '<dimension ref="A1:' . $der . max( $r, $h ) . '"/>'
			. '<sheetViews><sheetView workbookViewId="0" showGridLines="0"' . ( 1 === $n ? ' tabSelected="1"' : '' ) . '><pane ySplit="' . $h . '" topLeftCell="A' . ( $h + 1 ) . '" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A' . ( $h + 1 ) . '" sqref="A' . ( $h + 1 ) . '"/></sheetView></sheetViews>'
			. '<sheetFormatPr defaultRowHeight="15"/>'
			. '<cols>' . $xcols . '</cols>'
			. '<sheetData>' . implode( '', $rows ) . '</sheetData>'
			. ( $f['lignes'] ? '<autoFilter ref="' . $filtre . '"/>' : '' )
			. '<mergeCells count="' . count( $merges ) . '">' . implode( '', array_map( static fn( $m ) => '<mergeCell ref="' . $m . '"/>', $merges ) ) . '</mergeCells>'
			. '<printOptions horizontalCentered="1"/>'
			. '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.6" header="0.25" footer="0.3"/>'
			. '<pageSetup paperSize="9" orientation="' . ( $nb > 6 ? 'landscape' : 'portrait' ) . '" fitToWidth="1" fitToHeight="0"/>'
			. '<headerFooter><oddFooter>' . self::x( '&L&8' . ige_ffm_nom_appli( true ) . '&C&8Page &P / &N&R&8' . $f['titre'] ) . '</oddFooter></headerFooter>'
			. '<drawing r:id="rId1"/>'
			. '</worksheet>';
		return array( 'xml' => $xml, 'filtre' => $f['lignes'] ? $filtre : '', 'titres' => '$' . $h . ':$' . $h );
	}

	// ---------------------------------------------------------------------
	// Fichier.
	// ---------------------------------------------------------------------

	/**
	 * Écrit le classeur dans un fichier.
	 *
	 * @param string $chemin Fichier de destination.
	 * @return true|WP_Error
	 */
	public function enregistrer( $chemin ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip', 'Extension PHP « zip » absente du serveur.' );
		}
		if ( ! $this->feuilles ) {
			$this->feuille( 'Feuille', array() );
		}
		$z = new ZipArchive();
		if ( true !== $z->open( $chemin, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'zip', 'Écriture du fichier impossible.' );
		}
		$sheets  = '';
		$wbrels  = '';
		$types   = '';
		$names   = '';
		$logo    = IGE_FFM_DIR . 'assets/img/armoirie-128.png';
		foreach ( $this->feuilles as $i => $f ) {
			$n   = $i + 1;
			$out = $this->xml_feuille( $f, $n );
			$z->addFromString( "xl/worksheets/sheet$n.xml", $out['xml'] );
			$z->addFromString( "xl/worksheets/_rels/sheet$n.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing' . $n . '.xml"/></Relationships>' );
			$z->addFromString( "xl/drawings/drawing$n.xml", self::dessin() );
			$z->addFromString( "xl/drawings/_rels/drawing$n.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/armoirie.png"/></Relationships>' );
			$sheets .= '<sheet name="' . self::x( $f['nom'] ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$wbrels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$types  .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/drawings/drawing' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
			$q       = "'" . str_replace( "'", "''", $f['nom'] ) . "'";
			if ( $out['filtre'] ) {
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::x( $q . '!' . preg_replace( '/([A-Z]+)(\d+)/', '\$$1\$$2', $out['filtre'] ) ) . '</definedName>';
			}
			$names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::x( $q . '!' . $out['titres'] ) . '</definedName>';
		}
		$n = count( $this->feuilles );
		$z->addFile( $logo, 'xl/media/armoirie.png' );
		$z->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' . $types . '</Types>' );
		$z->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>' );
		$now = gmdate( 'Y-m-d\TH:i:s\Z' );
		$z->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x( $this->titre ) . '</dc:title><dc:creator>' . self::x( ige_ffm_nom_appli( true ) ) . '</dc:creator><cp:lastModifiedBy>' . self::x( ige_ffm_nom_appli( true ) ) . '</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>' );
		$z->addFromString( 'docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Microsoft Excel</Application><Company>Inspection Générale de l’Enseignement</Company></Properties>' );
		$z->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>' . $sheets . '</sheets><definedNames>' . $names . '</definedNames></workbook>' );
		$z->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbrels . '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$fmts = '';
		foreach ( $this->fmts as $k => $code ) {
			$fmts .= '<numFmt numFmtId="' . ( 164 + $k ) . '" formatCode="' . self::x( $code ) . '"/>';
		}
		$z->addFromString(
			'xl/styles.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. ( $fmts ? '<numFmts count="' . count( $this->fmts ) . '">' . $fmts . '</numFmts>' : '' )
			. '<fonts count="' . count( $this->fonts ) . '">' . implode( '', $this->fonts ) . '</fonts>'
			. '<fills count="' . count( $this->fills ) . '">' . implode( '', $this->fills ) . '</fills>'
			. '<borders count="' . count( $this->borders ) . '">' . implode( '', $this->borders ) . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count( $this->xfs ) . '">' . implode( '', $this->xfs ) . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
		);
		$z->close();
		return true;
	}

	/**
	 * Dessin : armoiries en haut à gauche (environ 1,2 cm).
	 *
	 * @return string
	 */
	private static function dessin() {
		$emu = 400000;
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>76200</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>19050</xdr:rowOff></xdr:from><xdr:ext cx="' . $emu . '" cy="' . $emu . '"/>'
			. '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Armoiries" descr="Armoiries de la République Démocratique du Congo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
			. '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
			. '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $emu . '" cy="' . $emu . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
	}

	/**
	 * Envoie le classeur au navigateur et termine la requête.
	 *
	 * @param string $nom Nom du fichier (.xlsx).
	 */
	public function telecharger( $nom ) {
		$tmp = wp_tempnam( 'ige.xlsx' );
		$ok  = $this->enregistrer( $tmp );
		if ( is_wp_error( $ok ) ) {
			wp_die( esc_html( $ok->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $nom ) . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * Ligne de contexte commune : « extrait le … par … ».
	 *
	 * @return string
	 */
	public static function extrait_par() {
		$u = wp_get_current_user();
		return 'Extrait le ' . wp_date( 'd/m/Y à H:i' ) . ( $u && $u->exists() ? ' par ' . $u->display_name : '' ) . ' · montants en francs congolais';
	}
}
