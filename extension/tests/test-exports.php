<?php
/**
 * Tests automatiques de la version 1.2 : classeurs Excel mis en forme et PDF produits par le serveur.
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-exports.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-exports.php\n" );
}

$GLOBALS['ige_t'] = array( 'ok' => 0, 'ko' => 0 );

/**
 * Vérifie une condition.
 *
 * @param bool   $cond  Condition.
 * @param string $label Libellé.
 */
function ige_t( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['ige_t']['ok'];
		echo "  ✓ $label\n";
	} else {
		++$GLOBALS['ige_t']['ko'];
		echo "  ✗ $label\n";
	}
}

global $wpdb;
$sfx = substr( (string) time(), -5 );
wp_set_current_user( 1 );
$ex  = IGE_FFM_Contexte::exercice();
$exo = (int) $ex['id'];

echo "1. Classeur Excel (.xlsx)\n";
ige_t( 46027.0 === IGE_FFM_Classeur::serie( '2026-01-05' ) && 46027.5 === IGE_FFM_Classeur::serie( '2026-01-05 12:00:00' ) && null === IGE_FFM_Classeur::serie( 'hier' ), 'Dates converties en dates Excel (jour et heure)' );
ige_t( 'A' === IGE_FFM_Classeur::col( 0 ) && 'Z' === IGE_FFM_Classeur::col( 25 ) && 'AA' === IGE_FFM_Classeur::col( 26 ) && 'AN' === IGE_FFM_Classeur::col( 39 ), 'Lettres de colonnes (A, Z, AA, AN)' );
$c = new IGE_FFM_Classeur( 'Essai' );
$c->feuille(
	'Livre: essai/1?',
	array(
		'titre'      => 'Livre de caisse — TEST',
		'sous_titre' => 'Exercice 2026',
		'colonnes'   => array( array( 'Identifiant', 'texte' ), array( 'Date', 'date' ), array( 'Description', 'long' ), array( 'Montant', 'montant' ), array( 'Taux (%)', 'decimal' ) ),
		'lignes'     => array(
			array( 'X-1', '2026-01-05', '=HYPERLINK("http://exemple")', 1250000, 12.5 ),
			array( 'X-2', '2026-02-15', "Texte\x07 avec contrôle & <balise>", -400000, null ),
		),
		'total'      => array( 'Total', '', '', 850000, null ),
		'notes'      => array( 'Note de bas de tableau.' ),
	)
);
$c->feuille( 'Deuxième', array( 'titre' => 'Autre', 'colonnes' => array( array( 'A', 'texte' ), array( 'B', 'montant' ) ), 'lignes' => array( array( 'a', 1 ) ) ) );
$f = wp_tempnam( 't.xlsx' );
ige_t( true === $c->enregistrer( $f ), 'Classeur écrit' );
$z = new ZipArchive();
$z->open( $f );
$parts = array();
for ( $i = 0; $i < $z->numFiles; $i++ ) {
	$parts[] = $z->getNameIndex( $i );
}
ige_t( ! array_diff( array( '[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/media/armoirie.png', 'xl/drawings/drawing1.xml' ), $parts ), 'Parties du classeur présentes (2 feuilles, styles, armoiries)' );
$wb = $z->getFromName( 'xl/workbook.xml' );
ige_t( false !== strpos( $wb, 'name="Livre  essai 1"' ) && false !== strpos( $wb, '_xlnm.Print_Titles' ) && false !== strpos( $wb, '_xlnm._FilterDatabase' ), 'Nom d’onglet nettoyé, en-têtes répétés à l’impression, filtre' );
$s1 = $z->getFromName( 'xl/worksheets/sheet1.xml' );
ige_t( false !== strpos( $s1, 'state="frozen"' ) && false !== strpos( $s1, '<autoFilter ref="A8:E10"/>' ) && false !== strpos( $s1, 'fitToWidth="1"' ), 'Volets figés, filtre sur les données, impression ajustée à la largeur' );
ige_t( false === strpos( $s1, '<f>' ) && false !== strpos( $s1, '=HYPERLINK(&quot;http://exemple&quot;)' ), 'Un texte commençant par « = » reste du texte (aucune formule)' );
ige_t( false === strpos( $s1, "\x07" ) && false !== strpos( $s1, '&amp; &lt;balise&gt;' ), 'Caractères de contrôle retirés, XML échappé' );
$st = $z->getFromName( 'xl/styles.xml' );
ige_t( false !== strpos( $st, 'FF17418A' ) && false !== strpos( $st, 'FFFFF24B' ) && false !== strpos( $st, 'FFDB3832' ) && false !== strpos( $st, 'FF0095C9' ), 'Couleurs de la charte (bleu institutionnel, bande tricolore)' );
ige_t( false !== strpos( $st, '#,##0;[Red]\-#,##0' ) && false !== strpos( $st, 'dd/mm/yyyy' ), 'Formats : montants (négatifs en rouge), dates JJ/MM/AAAA' );
$z->close();
$x = IGE_FFM_Xlsx::lire( $f );
ige_t( ! is_wp_error( $x ) && 2 === count( $x ), 'Relu par le lecteur Excel de l’application' );
$l = array_values( $x )[0];
ige_t( 'Livre de caisse — TEST' === $l[5][0] && 'Identifiant' === $l[8][0] && 'Montant' === $l[8][3], 'Titre ligne 5, en-têtes ligne 8' );
ige_t( 1250000 === $l[9][3] && 46027 === $l[9][1] && -400000 === $l[10][3] && 850000 === $l[11][3], 'Montants et dates enregistrés comme nombres ; total' );
wp_delete_file( $f );

echo "\n2. Exports de l’application\n";
$sid   = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'X' . $sfx, 'libelle' => 'Exports ' . $sfx, 'actif' => 1 ) );
$agent = IGE_FFM_Comptes::create( array( 'user_login' => 'xagent' . $sfx, 'display_name' => 'Agent exports', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$resp  = IGE_FFM_Comptes::create( array( 'user_login' => 'xresp' . $sfx, 'display_name' => 'Responsable exports', 'profil' => 'ige_responsable', 'service_id' => $sid ) )['user_id'];
IGE_FFM_Budget::enregistrer_solde_initial( $exo, $sid, '1 000 000', $resp );
wp_set_current_user( $agent );
$_GET = array();
$t = IGE_FFM_Ecran_Livre::export_donnees();
ige_t( 14 === count( $t['colonnes'] ) && 1 === count( $t['lignes'] ) && 1000000 === $t['total'][11], 'Livre : 14 colonnes, solde de début, total' );
ige_t( false !== strpos( $t['titre'], 'X' . $sfx ) && 0 === stripos( $t['fichier'], 'livre-caisse-x' . $sfx ), 'Livre : titre et nom de fichier du service' );
wp_set_current_user( 1 );
$cv = IGE_FFM_Ecran_Consolidation::export_donnees( 'services' );
ige_t( 'montant' === $cv['colonnes'][1][1] && 'decimal' === $cv['colonnes'][6][1] && 'nombre' === $cv['colonnes'][8][1], 'Consolidation : types de colonnes (montants, pourcentages, nombres)' );
ige_t( ( IGE_FFM_Fonctions::active( 'nomenclature' ) ? 5 : 4 ) === count( IGE_FFM_Ecran_Consolidation::vues() ), 'Consolidation : une vue par onglet (nomenclature comprise si active)' );

echo "\n3. PDF\n";
$pdf = IGE_FFM_Pdf::tableau( $t );
ige_t( 0 === strpos( $pdf, '%PDF-' ) && strlen( $pdf ) > 5000, 'Tableau en PDF' );
ige_t( (bool) preg_match( '#/FontFile2#', $pdf ) && false === strpos( $pdf, '/BaseFont /Helvetica' ), 'Polices de la charte incorporées (aucune police de substitution)' );
ige_t( (bool) preg_match( '#/Subtype\s*/Image#', $pdf ), 'Armoiries incorporées' );
$long = $t;
for ( $i = 0; $i < 120; $i++ ) {
	$long['lignes'][] = array( 'X-' . $i, '2026-03-01', 'Sortie', 'Payée', 'R01', 'Ligne ' . $i, 'Fournisseur', 'F-' . $i, 'Espèces', null, 1000, 999000 - $i * 1000, 'Validée', '' );
}
$pdf2 = IGE_FFM_Pdf::tableau( $long );
ige_t( preg_match_all( '#/Type\s*/Page[^s]#', $pdf2 ) >= 3, 'Long tableau sur plusieurs pages (' . preg_match_all( '#/Type\s*/Page[^s]#', $pdf2 ) . ' pages)' );
ige_t( false !== strpos( IGE_FFM_Pdf::qr( 'https://ige-gestion.com/verifier-un-document/?ref=IGE-AF-2026-0001' ), 'data:image/png;base64,' ), 'Code QR produit par le serveur' );

// Rapport officiel.
$r = IGE_FFM_Rapports::emettre( 'avances', array(), 1 );
ige_t( ! is_wp_error( $r ), 'Rapport émis' );
$d    = IGE_FFM_Documents::get( (int) $r );
$pdfd = $d ? IGE_FFM_Pdf::document( $d ) : '';
ige_t( 0 === strpos( $pdfd, '%PDF-' ) && preg_match_all( '#/Subtype\s*/Image#', $pdfd ) >= 2, 'Rapport officiel en PDF, avec armoiries et code QR' );
ige_t( false !== strpos( $pdfd, 'EBGaramond' ), 'Texte en EB Garamond (charte)' );

// Procès-verbal.
wp_set_current_user( $agent );
$mois = max( 1, (int) wp_date( 'n', strtotime( IGE_FFM_Contexte::aujourdhui() ) ) - 1 );
$ar   = IGE_FFM_Arretes::enregistrer( $sid, $mois, array( 'cdf' => array( 20000 => 50 ) ), $agent );
ige_t( ! is_wp_error( $ar ), 'Arrêté de caisse enregistré' );
IGE_FFM_Arretes::signer_agent( $ar, $agent );
wp_set_current_user( $resp );
$sr = IGE_FFM_Arretes::signer_responsable( $ar, $resp );
ige_t( true === $sr, 'Arrêté signé : procès-verbal inscrit au registre' );
$pv = IGE_FFM_Pdf::proces_verbal( IGE_FFM_Arretes::get( $ar ) );
ige_t( 0 === strpos( $pv, '%PDF-' ) && preg_match_all( '#/Subtype\s*/Image#', $pv ) >= 2, 'Procès-verbal en PDF, avec code QR' );
$txt = IGE_FFM_Ecran_Arrete::pv( IGE_FFM_Arretes::get( $ar ) );
ige_t( false !== strpos( $txt, 'ige-sign' ) && false !== strpos( $txt, 'ige-doc-title' ), 'Le PDF reprend le procès-verbal de l’écran' );
wp_set_current_user( 1 );

echo "\n4. Liens et droits\n";
$u = IGE_FFM_Pdf::url( array( 'quoi' => 'document', 'id' => 5 ) );
ige_t( false !== strpos( $u, 'action=ige_ffm_pdf' ) && false !== strpos( $u, '_wpnonce=' ), 'Liens de téléchargement protégés par un jeton' );
ige_t( ! user_can( $agent, 'ige_rapports' ) && IGE_FFM_Contexte::peut_voir( $sid, $agent ) && ! IGE_FFM_Contexte::peut_voir( 1, $agent ), 'Agent : pas de rapports ; PV de son seul service' );
ige_t( 'Téléchargement d’un PDF' === ( IGE_FFM_Audit::actions()['telechargement_pdf'] ?? '' ), 'Téléchargements journalisés' );

echo "\n5. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( $d ) {
	$wpdb->delete( IGE_FFM_DB::t( 'document' ), array( 'id' => (int) $d['id'] ) );
}
$wpdb->delete( IGE_FFM_DB::t( 'document' ), array( 'service_id' => $sid ) );
foreach ( array( 'ecriture', 'solde_initial', 'arrete', 'budget' ) as $tb ) {
	$wpdb->delete( IGE_FFM_DB::t( $tb ), array( 'service_id' => $sid ) );
}
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $sid ) );
foreach ( array( $agent, $resp ) as $uid ) {
	wp_delete_user( $uid );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $uid ) );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
