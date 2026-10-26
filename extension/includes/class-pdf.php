<?php
/**
 * Fichiers PDF produits par le serveur (Dompdf, LGPL) : documents officiels (rapports,
 * procès-verbaux) avec code QR d'authenticité sur chaque page, et tableaux (livre de caisse,
 * consolidation) au format de la charte : armoiries, intitulé du ministère, bande tricolore,
 * polices de la charte (Cooper Hewitt pour les titres et tableaux, EB Garamond pour le texte),
 * pagination « Page n / N ».
 *
 * Aucune ressource distante n'est chargée ; le PHP intégré aux documents est désactivé.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Production des PDF.
 */
final class IGE_FFM_Pdf {

	/**
	 * Crochets.
	 */
	public static function init() {
		add_action( 'admin_post_ige_ffm_pdf', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Chargement des bibliothèques (à la demande).
	 */
	public static function charger() {
		static $fait = false;
		if ( $fait ) {
			return;
		}
		$fait = true;
		$v    = IGE_FFM_DIR . 'vendor/';
		$map  = array(
			'Dompdf\\'         => $v . 'dompdf/src/',
			'FontLib\\'        => $v . 'php-font-lib/src/FontLib/',
			'Svg\\'            => $v . 'php-svg-lib/src/Svg/',
			'Masterminds\\'    => $v . 'html5/src/',
			'Sabberworm\\CSS\\' => $v . 'php-css-parser/src/',
		);
		spl_autoload_register(
			static function ( $classe ) use ( $map, $v ) {
				if ( 'Dompdf\\Cpdf' === $classe ) {
					require_once $v . 'dompdf/lib/Cpdf.php';
					return;
				}
				foreach ( $map as $prefixe => $dir ) {
					if ( 0 === strpos( $classe, $prefixe ) ) {
						$f = $dir . str_replace( '\\', '/', substr( $classe, strlen( $prefixe ) ) ) . '.php';
						if ( is_readable( $f ) ) {
							require_once $f;
						}
						return;
					}
				}
			}
		);
		if ( ! class_exists( 'IGE_QRCode', false ) ) {
			require_once $v . 'qrcode/qrcode.php';
		}
	}

	/**
	 * Dossier de cache des polices (hors de portée du public).
	 *
	 * @return string
	 */
	private static function cache() {
		$u   = wp_upload_dir( null, false );
		$dir = trailingslashit( $u['basedir'] ) . 'ige-ffm-pdf/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . 'index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * Code QR en image PNG (data URI).
	 *
	 * @param string $texte Contenu.
	 * @return string
	 */
	public static function qr( $texte ) {
		self::charger();
		$q   = IGE_QRCode::getMinimumQRCode( (string) $texte, IGE_QR_ERROR_CORRECT_LEVEL_M );
		$img = $q->createImage( 6, 2 );
		ob_start();
		imagepng( $img );
		$png = ob_get_clean();
		imagedestroy( $img );
		return 'data:image/png;base64,' . base64_encode( $png ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Armoiries (data URI).
	 *
	 * @return string
	 */
	private static function armoiries() {
		return 'data:image/png;base64,' . base64_encode( (string) file_get_contents( IGE_FFM_DIR . 'assets/img/armoirie-128.png' ) ); // phpcs:ignore
	}

	/**
	 * En-tête institutionnel.
	 *
	 * @param string $droite Texte à droite (référence, date).
	 * @return string
	 */
	public static function entete( $droite = '' ) {
		return '<table class="hd"><tr><td class="arm"><img src="' . self::armoiries() . '" alt=""></td><td class="etat"><span class="b"></span><span class="j"></span><span class="r"></span></td>'
			. '<td class="int"><b>MINISTÈRE DE L’ÉDUCATION NATIONALE<br>ET NOUVELLE CITOYENNETÉ</b><br><span>Inspection Générale de l’Enseignement</span></td>'
			. '<td class="ref">' . $droite . '</td></tr></table>';
	}

	/**
	 * Bande tricolore.
	 *
	 * @param string $classe Classe.
	 * @return string
	 */
	private static function tri( $classe = 'tri' ) {
		return '<table class="' . $classe . '"><tr><td class="b"></td><td class="j"></td><td class="r"></td></tr></table>';
	}

	/**
	 * Feuille de style des PDF.
	 *
	 * @param bool $paysage Paysage.
	 * @return string
	 */
	private static function css( $paysage ) {
		$bleu = '#17418a';
		return '@page{size:A4 ' . ( $paysage ? 'landscape' : 'portrait' ) . ';margin:14mm 14mm 30mm 14mm}'
			. 'body{font-family:"EB Garamond",serif;font-size:11pt;color:#222;line-height:1.35}'
			. 'table{border-collapse:collapse}'
			. '.hd{width:100%;font-family:"Cooper Hewitt",sans-serif}'
			. '.hd td{vertical-align:middle;padding:0}'
			. '.hd .arm{width:16mm}.hd .arm img{width:14mm;height:14mm}'
			. '.hd .etat{width:4mm}.hd .etat span{display:block;width:1.2mm;height:4.6mm}'
			. '.b{background:#0095c9}.j{background:#fff24b}.r{background:#db3832}'
			. '.hd .int{font-size:8.5pt;color:#222;padding-left:2mm}.hd .int b{font-size:8.5pt;letter-spacing:.2pt}.hd .int span{font-size:8pt;color:#444}'
			. '.hd .ref{text-align:right;font-size:8.5pt;color:#555}'
			. '.tri{width:100%;height:1.2mm;margin:3mm 0 0}.tri td{height:1.2mm;padding:0}'
			. 'h1,.ige-doc-title{font-family:"Cooper Hewitt",sans-serif;font-size:14pt;color:' . $bleu . ';text-transform:uppercase;margin:9mm 0 1mm;font-weight:bold}'
			. 'h2,h3{font-family:"Cooper Hewitt",sans-serif;color:' . $bleu . ';font-size:11pt;margin:6mm 0 2mm}'
			. '.ige-subt,.sous{font-size:9.5pt;color:#555;margin-bottom:5mm;font-family:"Cooper Hewitt",sans-serif}'
			. 'p{margin:0 0 3mm}'
			. '.ige-table,.tab{width:100%;font-family:"Cooper Hewitt",sans-serif;font-size:8.5pt;margin:2mm 0 4mm}.tab{font-size:7.6pt}'
			. '.ige-table th,.tab th{background:' . $bleu . ';color:#fff;font-size:7.8pt;text-align:left;padding:2mm 1.8mm;font-weight:bold;border-left:.3pt solid #fff}'
			. '.ige-table td,.tab td{padding:1.2mm 1.5mm;border-bottom:.4pt solid #d5dbe3;vertical-align:top}'
			. '.tab tr.z td{background:#f3f5f8}'
			. '.ige-r,.n{text-align:right;white-space:nowrap}.nw{white-space:nowrap}'
			. '.ige-table th.ige-r,.tab th.n{text-align:right}'
			. '.ige-tot td,.tab tr.tot td{font-weight:bold;background:#e6ecf5;color:' . $bleu . ';border-top:1pt solid ' . $bleu . ';border-bottom:1pt solid ' . $bleu . '}'
			. '.neg{color:#b4241f}'
			. '.ige-sign{width:100%;margin-top:9mm;font-size:10pt;page-break-inside:avoid}'
			. '.ige-sign td{width:50%;vertical-align:top;border-top:.6pt solid #999;padding:2mm 4mm 0 0;height:24mm}'
			. '.ige-sign td+td{padding-left:6mm}'
			. '.pied{position:fixed;left:0;right:0;bottom:-24mm;height:22mm;font-family:"Cooper Hewitt",sans-serif;font-size:7.5pt;color:#555}'
			. '.pied .tri{margin:0 0 2mm}'
			. '.pied table.l{width:100%}.pied table.l td{vertical-align:top;padding:0}'
			. '.pied .qr{width:19mm}.pied .qr img{width:17mm;height:17mm}'
			. '.note{font-size:8pt;color:#666;font-family:"Cooper Hewitt",sans-serif}'
			. '.ige-muted{color:#666}.ige-small{font-size:9pt}'
			. 'b,strong{font-weight:bold}';
	}

	/**
	 * Produit un PDF à partir d'un corps HTML.
	 *
	 * @param string $corps   HTML (sans en-tête de page ni pied).
	 * @param array  $o       titre, entete_droite, pied (HTML), qr (texte du code QR), paysage, auteur.
	 * @return string PDF.
	 */
	public static function generer( $corps, array $o = array() ) {
		self::charger();
		$o     += array( 'titre' => 'Document', 'entete_droite' => '', 'pied' => '', 'qr' => '', 'paysage' => false );
		$cache  = self::cache();
		$pied   = '<div class="pied">' . self::tri() . '<table class="l"><tr>'
			. ( $o['qr'] ? '<td class="qr"><img src="' . self::qr( $o['qr'] ) . '" alt=""></td>' : '' )
			. '<td>' . $o['pied'] . '</td></tr></table></div>';
		$html   = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>' . esc_html( $o['titre'] ) . '</title><style>' . self::css( $o['paysage'] ) . '</style></head><body>'
			. $pied . self::entete( $o['entete_droite'] ) . self::tri() . $corps . '</body></html>';
		// Espaces fines insécables des montants : remplacées par des insécables ordinaires (présentes dans toutes les polices).
		$html = str_replace( array( "\u{202F}", '&#8239;' ), "\u{00A0}", $html );

		$opt = new \Dompdf\Options();
		$opt->setIsRemoteEnabled( false );
		$opt->setIsPhpEnabled( false );
		$opt->setIsJavascriptEnabled( false );
		$opt->setFontDir( $cache );
		$opt->setFontCache( $cache );
		$opt->setTempDir( get_temp_dir() );
		$opt->setChroot( array( IGE_FFM_DIR, $cache ) );
		$opt->setDefaultFont( 'Cooper Hewitt' );
		$opt->setDefaultPaperSize( 'a4' );
		$pdf = new \Dompdf\Dompdf( $opt );
		$fm  = $pdf->getFontMetrics();
		// Les polices de la charte n'ont pas d'italique : le romain sert aussi pour l'italique.
		foreach ( array(
			array( 'Cooper Hewitt', 'normal', 'normal', 'CooperHewitt-Regular' ),
			array( 'Cooper Hewitt', 'bold', 'normal', 'CooperHewitt-Bold' ),
			array( 'Cooper Hewitt', 'normal', 'italic', 'CooperHewitt-Regular' ),
			array( 'Cooper Hewitt', 'bold', 'italic', 'CooperHewitt-Bold' ),
			array( 'EB Garamond', 'normal', 'normal', 'EBGaramond-Regular' ),
			array( 'EB Garamond', 'bold', 'normal', 'EBGaramond-SemiBold' ),
			array( 'EB Garamond', 'normal', 'italic', 'EBGaramond-Regular' ),
			array( 'EB Garamond', 'bold', 'italic', 'EBGaramond-SemiBold' ),
		) as $f ) {
			$fams = $fm->getFontFamilies();
			$var  = 'normal' === $f[2] ? $f[1] : ( 'bold' === $f[1] ? 'bold_italic' : 'italic' );
			if ( empty( $fams[ strtolower( $f[0] ) ][ $var ] ) || ! file_exists( $fams[ strtolower( $f[0] ) ][ $var ] . '.ufm' ) ) {
				$fm->registerFont( array( 'family' => $f[0], 'weight' => $f[1], 'style' => $f[2] ), IGE_FFM_DIR . 'assets/fonts/' . $f[3] . '.ttf' );
			}
		}
		$pdf->setPaper( 'A4', $o['paysage'] ? 'landscape' : 'portrait' );
		$pdf->loadHtml( $html, 'UTF-8' );
		$pdf->addInfo( 'Title', $o['titre'] );
		$pdf->addInfo( 'Author', 'Inspection Générale de l’Enseignement' );
		$pdf->addInfo( 'Creator', ige_ffm_nom_appli( true ) );
		$pdf->render();
		$c    = $pdf->getCanvas();
		$font = $fm->getFont( 'Cooper Hewitt', 'normal' );
		$c->page_text( $c->get_width() - 110, $c->get_height() - 30, 'Page {PAGE_NUM} / {PAGE_COUNT}', $font, 7.5, array( 0.33, 0.33, 0.33 ) );
		return (string) $pdf->output();
	}

	/**
	 * PDF d'un document officiel du registre (rapport).
	 *
	 * @param array $d Document.
	 * @return string
	 */
	public static function document( array $d ) {
		$emetteur = get_userdata( (int) $d['emis_par'] );
		$corps    = '<h1>' . esc_html( $d['titre'] ) . '</h1>' . self::adapter( wp_kses_post( $d['contenu'] ) );
		if ( $d['signataire'] ) {
			$corps .= '<table class="ige-sign"><tr><td>' . esc_html( $d['signataire'] ) . '<br><br><br>Signature et sceau</td><td>Établi par ' . esc_html( $emetteur ? $emetteur->display_name : '' ) . '<br>le ' . esc_html( wp_date( 'd/m/Y à H:i', strtotime( $d['emis_le'] ) ) ) . '</td></tr></table>';
		}
		return self::generer(
			$corps,
			array(
				'titre'         => $d['titre'] . ' — ' . $d['reference'],
				'entete_droite' => '<b>' . esc_html( $d['reference'] ) . '</b><br>Kinshasa, le ' . esc_html( self::date_fr( $d['emis_le'] ) ),
				'qr'            => IGE_FFM_Documents::url_verification( $d['reference'] ),
				'pied'          => 'Document authentifiable : scannez le code ou saisissez la référence <b>' . esc_html( $d['reference'] ) . '</b> sur la page « Vérifier un document » de ' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '.<br>Empreinte SHA-256 : ' . esc_html( substr( $d['empreinte'], 0, 16 ) . '…' . substr( $d['empreinte'], -8 ) ),
			)
		);
	}

	/**
	 * PDF d'un procès-verbal d'arrêté ou de comptage : le HTML de l'écran est repris tel quel.
	 *
	 * @param array $a Arrêté.
	 * @return string
	 */
	public static function proces_verbal( array $a ) {
		$html = IGE_FFM_Ecran_Arrete::pv( $a );
		// Corps de l'article : du titre jusqu'au pied.
		$corps = preg_match( '#(<h1 class="ige-doc-title">.*?)<div class="ige-ft">#s', $html, $m ) ? $m[1] : '';
		$doc   = IGE_FFM_Documents::pour_objet( 'mensuel' === $a['type'] ? 'pv_arrete' : 'pv_inopine', (int) $a['id'] );
		$ref   = IGE_FFM_Arretes::reference( $a );
		return self::generer(
			self::adapter( $corps ),
			array(
				'titre'         => ( 'mensuel' === $a['type'] ? 'Procès-verbal d’arrêté de caisse' : 'Procès-verbal de comptage inopiné' ) . ' — ' . $ref,
				'entete_droite' => '<b>' . esc_html( $doc ? $doc['reference'] : $ref ) . '</b><br>Kinshasa, le ' . esc_html( self::date_fr( $a['created_at'] ) ),
				'qr'            => $doc ? IGE_FFM_Documents::url_verification( $doc['reference'] ) : '',
				'pied'          => $doc
					? 'Document authentifiable : scannez le code ou saisissez la référence <b>' . esc_html( $doc['reference'] ) . '</b> sur la page « Vérifier un document ».<br>' . esc_html( $ref )
					: 'Procès-verbal établi dans l’application « ' . esc_html( ige_ffm_nom_appli() ) . ' » · ' . esc_html( $ref ) . ' · le code QR d’authenticité est attribué à la signature finale.',
			)
		);
	}

	/**
	 * PDF d'un tableau (livre de caisse, consolidation).
	 *
	 * @param array $t titre, sous_titre, colonnes [ [libellé, type] ], lignes, total, notes, paysage.
	 * @return string
	 */
	public static function tableau( array $t ) {
		$t   += array( 'sous_titre' => '', 'total' => null, 'notes' => array(), 'paysage' => true );
		$num  = static fn( $type ) => in_array( $type, array( 'montant', 'nombre', 'decimal' ), true );
		$val  = static function ( $v, $type ) {
			if ( null === $v || '' === $v ) {
				return '';
			}
			if ( 'montant' === $type || 'nombre' === $type ) {
				return '<span' . ( $v < 0 ? ' class="neg"' : '' ) . '>' . esc_html( ige_ffm_montant( (int) $v, '' ) ) . '</span>';
			}
			if ( 'decimal' === $type ) {
				return esc_html( number_format( (float) $v, 1, ',', '' ) );
			}
			if ( 'date' === $type && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $v ) ) {
				return esc_html( wp_date( 'd/m/Y', strtotime( $v ) ) );
			}
			if ( 'dateheure' === $type && preg_match( '/^\d{4}-\d{2}-\d{2}/', (string) $v ) ) {
				return esc_html( wp_date( 'd/m/Y H:i', strtotime( $v ) ) );
			}
			return esc_html( (string) $v );
		};
		$larg = array_map( static fn( $c ) => (float) ( $c[2] ?? ( IGE_FFM_Classeur::LARGEURS[ $c[1] ?? 'texte' ] ?? 14 ) ), $t['colonnes'] );
		$tot  = array_sum( $larg ) ?: 1;
		$cls  = static fn( $c ) => $num( $c[1] ?? '' ) ? 'n' : ( 'long' === ( $c[1] ?? '' ) ? '' : 'nw' );
		$h    = '<h1>' . esc_html( $t['titre'] ) . '</h1><div class="sous">' . esc_html( $t['sous_titre'] ) . '</div><table class="tab"><thead><tr>';
		foreach ( $t['colonnes'] as $i => $c ) {
			$h .= '<th class="' . $cls( $c ) . '" style="width:' . round( $larg[ $i ] / $tot * 100, 1 ) . '%">' . esc_html( $c[0] ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( array_values( $t['lignes'] ) as $k => $l ) {
			$sp   = is_array( $l ) && isset( $l['_style'] ) ? $l['_style'] : '';
			$vals = array_values( array_diff_key( (array) $l, array( '_style' => 1 ) ) );
			$h   .= '<tr class="' . ( $k % 2 ? 'z' : '' ) . '"' . ( 'gras' === $sp || 'italique' === $sp ? ' style="font-' . ( 'gras' === $sp ? 'weight:bold' : 'style:italic' ) . '"' : '' ) . '>';
			foreach ( $t['colonnes'] as $i => $c ) {
				$h .= '<td class="' . $cls( $c ) . '">' . $val( $vals[ $i ] ?? null, $c[1] ?? 'texte' ) . '</td>';
			}
			$h .= '</tr>';
		}
		if ( $t['total'] ) {
			$vals = array_values( $t['total'] );
			$h   .= '<tr class="tot">';
			foreach ( $t['colonnes'] as $i => $c ) {
				$h .= '<td class="' . $cls( $c ) . '">' . $val( $vals[ $i ] ?? null, $c[1] ?? 'texte' ) . '</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</tbody></table>';
		foreach ( (array) $t['notes'] as $n ) {
			$h .= '<p class="note">' . esc_html( $n ) . '</p>';
		}
		return self::generer(
			$h,
			array(
				'titre'         => $t['titre'],
				'paysage'       => $t['paysage'],
				'entete_droite' => 'Kinshasa, le ' . esc_html( self::date_fr( current_time( 'mysql' ) ) ),
				'pied'          => esc_html( IGE_FFM_Classeur::extrait_par() ) . '<br>Document de travail, sans valeur de pièce officielle : les rapports officiels portent une référence et un code QR.',
			)
		);
	}

	/**
	 * Adapte le HTML des écrans à Dompdf (grilles et bandes tricolores en tableaux).
	 *
	 * @param string $h HTML.
	 * @return string
	 */
	private static function adapter( $h ) {
		$h = str_replace( '<div class="ige-tri" aria-hidden="true"></div>', self::tri(), $h );
		// Signatures : <div class="ige-sign"><div>A</div><div>B</div></div> → tableau.
		$h = preg_replace_callback(
			'#<div class="ige-sign">\s*(.*?)\s*</div>\s*$#s',
			static function ( $m ) {
				preg_match_all( '#<div>(.*?)</div>#s', $m[1], $cells );
				return '<table class="ige-sign"><tr><td>' . implode( '</td><td>', $cells[1] ) . '</td></tr></table>';
			},
			trim( $h )
		);
		return $h;
	}

	/**
	 * Date en toutes lettres (« 24 octobre 2026 »).
	 *
	 * @param string $d Date.
	 * @return string
	 */
	public static function date_fr( $d ) {
		$mois = array( '', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
		$t    = strtotime( (string) $d );
		return (int) wp_date( 'j', $t ) . ( 1 === (int) wp_date( 'j', $t ) ? 'er' : '' ) . ' ' . $mois[ (int) wp_date( 'n', $t ) ] . ' ' . wp_date( 'Y', $t );
	}

	/**
	 * Lien de téléchargement.
	 *
	 * @param array $q Paramètres (quoi, id…).
	 * @return string
	 */
	public static function url( array $q ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'ige_ffm_pdf' ) + $q, admin_url( 'admin-post.php' ) ), 'ige_ffm_pdf' );
	}

	/**
	 * Envoie un PDF au navigateur.
	 *
	 * @param string $pdf Contenu.
	 * @param string $nom Nom du fichier.
	 */
	public static function envoyer( $pdf, $nom ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $nom ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Téléchargement (admin-post.php?action=ige_ffm_pdf&quoi=…).
	 */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'ige_ffm_pdf' ) ) {
			wp_die( 'Lien expiré : rechargez la page.', '', array( 'response' => 403 ) );
		}
		ige_ffm_require( 'ige_acceder' );
		$quoi = isset( $_GET['quoi'] ) ? sanitize_key( $_GET['quoi'] ) : '';
		$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
		// phpcs:enable
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		switch ( $quoi ) {
			case 'document':
				$d = IGE_FFM_Documents::get( $id );
				if ( ! $d || ! current_user_can( 'ige_rapports' ) || in_array( $d['type'], array( 'pv_arrete', 'pv_inopine' ), true ) ) {
					wp_die( 'Document introuvable.', '', array( 'response' => 404 ) );
				}
				IGE_FFM_Audit::log( 'telechargement_pdf', 'document', $d['reference'] );
				self::envoyer( self::document( $d ), $d['reference'] . '.pdf' );
				break;
			case 'om':
				$m = IGE_FFM_Missions::get( $id );
				$d = $m ? IGE_FFM_Documents::pour_objet( 'ordre_mission', (int) $m['id'] ) : null;
				if ( ! $d || ! IGE_FFM_Contexte::peut_voir( (int) $m['service_id'] ) ) {
					wp_die( 'Ordre de mission introuvable.', '', array( 'response' => 404 ) );
				}
				IGE_FFM_Audit::log( 'telechargement_pdf', 'mission', $m['numero'] );
				self::envoyer( self::document( $d ), $m['numero'] . '.pdf' );
				break;
			case 'rapprochement':
				if ( ! current_user_can( 'ige_gerer_fonds' ) ) {
					wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
				}
				self::envoyer( IGE_FFM_Banque::etat_pdf( $id ), 'etat-rapprochement-' . wp_date( 'Y-m-d' ) . '.pdf' );
				break;
			case 'pv':
				$a = IGE_FFM_Arretes::get( $id );
				if ( ! $a || ! IGE_FFM_Contexte::peut_voir( (int) $a['service_id'] ) ) {
					wp_die( 'Procès-verbal introuvable.', '', array( 'response' => 404 ) );
				}
				IGE_FFM_Audit::log( 'telechargement_pdf', 'arrete', IGE_FFM_Arretes::reference( $a ) );
				self::envoyer( self::proces_verbal( $a ), IGE_FFM_Arretes::reference( $a ) . '.pdf' );
				break;
			case 'livre':
				$t = IGE_FFM_Ecran_Livre::export_donnees();
				self::envoyer( self::tableau( $t ), $t['fichier'] . '.pdf' );
				break;
			case 'consolidation':
				if ( ! current_user_can( 'ige_voir_tout' ) ) {
					wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
				}
				$t = IGE_FFM_Ecran_Consolidation::export_donnees( IGE_FFM_Ecran_Consolidation::vue() );
				IGE_FFM_Audit::log( 'export_consolidation', 'exercice', $t['annee'], null, array( 'vue' => $t['vue'], 'format' => 'pdf' ) );
				self::envoyer( self::tableau( $t ), $t['fichier'] . '.pdf' );
				break;
			case 'inventaire':
				if ( ! current_user_can( 'ige_logistique' ) ) {
					wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
				}
				$t = $id ? IGE_FFM_Patrimoine::export_donnees( $id ) : IGE_FFM_Ecran_Patrimoine::export_consolide();
				if ( ! $t ) {
					wp_die( 'Inventaire introuvable.', '', array( 'response' => 404 ) );
				}
				IGE_FFM_Audit::log( 'export_patrimoine', 'pat_inventaire', $t['fichier'], null, array( 'format' => 'pdf' ) );
				self::envoyer( self::tableau( $t ), $t['fichier'] . '.pdf' );
				break;
		}
		wp_die( 'Demande inconnue.', '', array( 'response' => 400 ) );
	}
}
