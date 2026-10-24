<?php
/**
 * Tests automatiques de la version 1.3 : jours fériés de la RDC, dossier complet par nature de
 * dépense (VA-11), échanges sur une écriture (VA-15), contrôle d'intégrité, contrôles de sécurité
 * des points d'entrée.
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-priorite1.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-priorite1.php\n" );
}
if ( ! defined( 'IGE_FFM_TESTS' ) ) {
	define( 'IGE_FFM_TESTS', true );
}

$GLOBALS['ige_t']     = array( 'ok' => 0, 'ko' => 0 );
$GLOBALS['ige_mails'] = array();

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

/**
 * Pièce PDF temporaire.
 *
 * @return array
 */
function ige_pdf() {
	$tmp = wp_tempnam( 'p.pdf' );
	file_put_contents( $tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" . wp_generate_password( 12, false ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return array( 'name' => 'piece.pdf', 'type' => '', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $tmp ) );
}

add_filter(
	'pre_wp_mail',
	static function ( $r, $atts ) {
		$GLOBALS['ige_mails'][] = $atts;
		return true;
	},
	10,
	2
);

global $wpdb;
$sfx      = substr( (string) time(), -5 );
$reglages = get_option( 'ige_ffm_reglages' );
$jf_avant = get_option( IGE_FFM_Jours_Feries::OPTION, null );
wp_set_current_user( 1 );
$ex  = IGE_FFM_Contexte::exercice();
$exo = (int) $ex['id'];
$jour = strtotime( '-1 day' );
while ( ! IGE_FFM_Jours_Feries::ouvre( gmdate( 'Y-m-d', $jour ) ) ) {
	$jour = strtotime( '-1 day', $jour );
}
$hier = gmdate( 'Y-m-d', $jour );

echo "1. Jours fériés de la RDC\n";
delete_option( IGE_FFM_Jours_Feries::OPTION );
$a = IGE_FFM_Jours_Feries::annee( 2026 );
ige_t( 10 === count( $a ), '10 jours fériés légaux par défaut' );
ige_t( 'Fête de l’Indépendance' === IGE_FFM_Jours_Feries::libelle( '2026-06-30' ) && 'Journée du héros national Patrice Émery Lumumba' === IGE_FFM_Jours_Feries::libelle( '2027-01-17' ), 'Libellés (30 juin, 17 janvier), chaque année' );
ige_t( ! IGE_FFM_Jours_Feries::ouvre( '2026-06-30' ) && IGE_FFM_Jours_Feries::ouvre( '2026-06-29' ) && ! IGE_FFM_Jours_Feries::ouvre( '2026-06-27' ), 'Jour ouvré : férié non, lundi oui, samedi non' );
ige_t( in_array( '04-06', IGE_FFM_Anomalies::jours_feries( 2026 ), true ) && in_array( '08-01', IGE_FFM_Anomalies::jours_feries( 2026 ), true ), 'Contrôle « jour non ouvré » alimenté (6 avril, 1er août)' );
ige_t( is_wp_error( IGE_FFM_Jours_Feries::lire_texte( "31/02 Jour impossible", 'md' ) ) && is_wp_error( IGE_FFM_Jours_Feries::lire_texte( "demain Fête", 'md' ) ), 'Saisie refusée : date impossible ou illisible' );
$txt = IGE_FFM_Jours_Feries::en_texte( IGE_FFM_Jours_Feries::defauts(), 'md' );
ige_t( true === IGE_FFM_Jours_Feries::enregistrer( $txt, "15/11/2026 Deuil national\n20/12/2026 Élections" ), 'Jours exceptionnels enregistrés (deuil national, élections)' );
ige_t( 'Deuil national' === IGE_FFM_Jours_Feries::libelle( '2026-11-15' ) && '' === IGE_FFM_Jours_Feries::libelle( '2027-11-15' ), 'Un jour exceptionnel ne vaut que pour son année' );
ige_t( 10 === count( IGE_FFM_Jours_Feries::reglage()['fixes'] ) && 12 === count( IGE_FFM_Jours_Feries::annee( 2026 ) ), 'Année 2026 : 12 jours fériés' );

echo "\n2. Préparation\n";
$sid  = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'P' . $sfx, 'libelle' => 'Priorité 1 ' . $sfx, 'actif' => 1 ) );
$sid2 = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'Q' . $sfx, 'libelle' => 'Autre ' . $sfx, 'actif' => 1 ) );
$agent  = IGE_FFM_Comptes::create( array( 'user_login' => 'pagent' . $sfx, 'display_name' => 'Agent P1', 'user_email' => 'pagent' . $sfx . '@exemple.cd', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$agent2 = IGE_FFM_Comptes::create( array( 'user_login' => 'qagent' . $sfx, 'display_name' => 'Agent Q', 'profil' => 'ige_agent', 'service_id' => $sid2 ) )['user_id'];
$compta = IGE_FFM_Comptes::create( array( 'user_login' => 'pcompta' . $sfx, 'display_name' => 'Comptable P1', 'user_email' => 'pcompta' . $sfx . '@exemple.cd', 'profil' => 'ige_admin' ) )['user_id'];
$rubs   = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes  = wp_list_pluck( IGE_FFM_Referentiels::all( 'mode_paiement' ), 'id', 'libelle' );
wp_set_current_user( $agent );
IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $hier, 'montant' => '5000000', 'description' => 'Approvisionnement', 'beneficiaire' => 'IGE', 'num_piece' => 'AV-1', 'mode_paiement_id' => $modes['Virement'] ) );
$base = array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'rubrique_id' => (int) $rubs['R01'], 'beneficiaire' => 'Papeterie P1', 'mode_paiement_id' => $modes['Chèque'] );
$s1 = IGE_FFM_Ecritures::saisir( $base + array( 'description' => 'Ramettes', 'num_piece' => 'F-1', 'montant' => '200000', 'piece_nature' => 'Facture' ), ige_pdf() );
$s2 = IGE_FFM_Ecritures::saisir( $base + array( 'description' => 'Classeurs', 'num_piece' => 'F-2', 'montant' => '100000', 'piece_nature' => 'Facture' ), ige_pdf() );
$s3 = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'description' => 'Commande toner', 'num_piece' => 'BC-3', 'montant' => '150000' ) + $base, ige_pdf() );
ige_t( is_int( $s1 ) && is_int( $s2 ) && is_int( $s3 ), 'Écritures saisies (2 sorties payées, 1 engagement)' );

echo "\n3. Dossier complet par nature de dépense (VA-11)\n";
$e1 = IGE_FFM_Ecritures::get( $s1 );
$d1 = IGE_FFM_Pieces::etat_dossier( $e1 );
ige_t( array( 'Facture', 'Bon de réception' ) === $d1['exigees'], 'Pièces exigées pour R01 : facture, bon de réception' );
ige_t( ! $d1['complet'] && array( 'Bon de réception' ) === $d1['manquantes'], 'Facture seule : dossier incomplet, manque le bon de réception' );
ige_t( 'Facture' === IGE_FFM_Pieces::liste( 'ecriture', $s1 )[0]['nature'], 'Nature de la pièce enregistrée à la saisie' );
$lot = IGE_FFM_Transmission::transmettre( $sid );
ige_t( is_int( $lot ), 'Lot transmis' );
$e1 = IGE_FFM_Ecritures::get( $s1 );
ige_t( isset( IGE_FFM_Anomalies::lire( $e1 )['dossier'] ) && false !== strpos( IGE_FFM_Anomalies::lire( $e1 )['dossier'], 'Bon de réception' ), 'Anomalie « Dossier incomplet » à la réception' );
ige_t( ! isset( IGE_FFM_Anomalies::lire( IGE_FFM_Ecritures::get( $s3 ) )['dossier'] ), 'Un engagement non payé n’est pas encore exigé complet' );
IGE_FFM_Pieces::enregistrer( ige_pdf(), 'ecriture', $s2, $agent, 'Bon de réception' );
$e2 = IGE_FFM_Ecritures::get( $s2 );
ige_t( IGE_FFM_Pieces::etat_dossier( $e2 )['complet'] && ! isset( IGE_FFM_Anomalies::lire( $e2 )['dossier'] ), 'Bon de réception ajouté après la transmission : dossier complet, anomalie levée' );
$fake = array( 'id' => 0, 'type' => 'sortie', 'statut' => 'payee', 'rubrique_id' => (int) $rubs['R01'], 'ecriture_origine_id' => null );
ige_t( ! IGE_FFM_Pieces::etat_dossier( $fake )['complet'], 'Aucune pièce : dossier incomplet' );

echo "\n4. Échanges sur une écriture (VA-15)\n";
ige_t( is_wp_error( IGE_FFM_Echanges::poser( $s1, 'Joignez le bon de réception.', $agent ) ), 'Un agent ne peut pas poser de question' );
wp_set_current_user( $compta );
ige_t( is_wp_error( IGE_FFM_Echanges::poser( $s1, 'Ok', $compta ) ), 'Question trop courte refusée' );
$GLOBALS['ige_mails'] = array();
$q = IGE_FFM_Echanges::poser( $s1, 'Merci de joindre le bon de réception signé.', $compta );
ige_t( is_int( $q ) && 'question' === IGE_FFM_Ecritures::get( $s1 )['echange'], 'Question posée : l’écriture attend la réponse du service' );
ige_t( 'a_verifier' === IGE_FFM_Ecritures::get( $s1 )['etat_verification'], 'L’écriture n’est pas rejetée' );
$m = end( $GLOBALS['ige_mails'] );
ige_t( $m && in_array( 'pagent' . $sfx . '@exemple.cd', (array) $m['to'], true ) && false !== strpos( $m['subject'], 'Question' ), 'Le service est prévenu par e-mail' );
ige_t( 1 === count( IGE_FFM_Echanges::en_attente( $exo, $sid ) ), 'Question visible côté service (à traiter)' );
$v = IGE_FFM_Verification::valider_sans_anomalie( $exo, array( 'service_id' => $sid ), $compta );
ige_t( 'question' === IGE_FFM_Ecritures::get( $s1 )['echange'] && 'a_verifier' === IGE_FFM_Ecritures::get( $s1 )['etat_verification'], 'La validation en masse ignore une écriture en échange' );
ige_t( 1 === IGE_FFM_Verification::file( $exo, array( 'vue' => 'echanges', 'service_id' => $sid ) )['total'], 'Filtre « Échanges en cours »' );
wp_set_current_user( $agent2 );
ige_t( is_wp_error( IGE_FFM_Echanges::repondre( $s1, 'Je réponds à la place.', null, '', $agent2 ) ), 'Un agent d’un autre service ne peut pas répondre' );
wp_set_current_user( $agent );
$GLOBALS['ige_mails'] = array();
$rep = IGE_FFM_Echanges::repondre( $s1, 'Voici le bon de réception signé par le magasinier.', ige_pdf(), 'Bon de réception', $agent );
$e1  = IGE_FFM_Ecritures::get( $s1 );
ige_t( is_int( $rep ) && 'reponse' === $e1['echange'], 'Réponse du service enregistrée' );
ige_t( 2 === count( IGE_FFM_Pieces::liste( 'ecriture', $s1 ) ) && IGE_FFM_Pieces::etat_dossier( $e1 )['complet'] && ! isset( IGE_FFM_Anomalies::lire( $e1 )['dossier'] ), 'Pièce jointe à la réponse : dossier complet, anomalie levée' );
$m = end( $GLOBALS['ige_mails'] );
ige_t( $m && 'pcompta' . $sfx . '@exemple.cd' === $m['to'] && false !== strpos( $m['subject'], 'Réponse' ), 'L’auteur de la question est prévenu' );
ige_t( is_wp_error( IGE_FFM_Echanges::repondre( $s1, 'Une seconde réponse.', null, '', $agent ) ), 'Pas de seconde réponse sans nouvelle question' );
$fil = IGE_FFM_Echanges::fil( $s1 );
ige_t( 2 === count( $fil ) && 'question' === $fil[0]['sens'] && 'reponse' === $fil[1]['sens'] && $fil[1]['piece_id'], 'Fil conservé : question, réponse avec pièce' );
wp_set_current_user( $compta );
IGE_FFM_Verification::valider( array( $s1 ), $compta );
$e1 = IGE_FFM_Ecritures::get( $s1 );
ige_t( 'validee' === $e1['etat_verification'] && '' === $e1['echange'], 'Validation : l’échange est clos' );
ige_t( is_wp_error( IGE_FFM_Echanges::poser( $s1, 'Encore une question ?', $compta ) ), 'Plus de question sur une écriture validée' );
ige_t( 2 === count( IGE_FFM_Echanges::fil( $s1 ) ), 'L’historique reste consultable après validation' );

echo "\n5. Contrôle d’intégrité (après restauration)\n";
$r = IGE_FFM_Sante::controler();
ige_t( $r['ok'], 'Contrôle conforme' . ( $r['ok'] ? '' : ' : ' . implode( ' | ', array_map( static fn( $x ) => $x[0] . ' ' . $x[2], array_filter( $r['controles'], static fn( $x ) => ! $x[1] ) ) ) ) );
ige_t( 5 === count( $r['controles'] ) && $r['chiffres']['Écritures'] > 0, '5 contrôles et chiffres clés' );
$p    = IGE_FFM_Pieces::liste( 'ecriture', $s2 )[0];
$f    = IGE_FFM_Pieces::dossier() . $p['fichier'];
$orig = file_get_contents( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
file_put_contents( $f, $orig . 'X' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$r2 = IGE_FFM_Sante::controler();
ige_t( ! $r2['ok'] && ! $r2['controles'][2][1] && false !== strpos( $r2['controles'][2][2], '1 modifiée' ), 'Pièce modifiée sur le disque : détectée' );
file_put_contents( $f, $orig ); // phpcs:ignore WordPress.WP.AlternativeFunctions
ige_t( IGE_FFM_Sante::controler()['ok'], 'Fichier rétabli : contrôle conforme' );

echo "\n6. Sécurité des points d’entrée\n";
global $wp_filter;
$hooks    = array_filter( array_keys( $wp_filter ), static fn( $k ) => 0 === strpos( $k, 'admin_post_' ) && false !== strpos( $k, 'ige_ffm' ) );
$nopriv   = array_filter( $hooks, static fn( $k ) => 0 === strpos( $k, 'admin_post_nopriv_' ) );
$sans_jet = array();
$sans_cap = array();
foreach ( $hooks as $h ) {
	foreach ( $wp_filter[ $h ]->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function'];
			$rf = is_array( $fn ) ? new ReflectionMethod( $fn[0], $fn[1] ) : new ReflectionFunction( $fn );
			$lines = array_slice( file( $rf->getFileName() ), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 );
			$src   = implode( '', $lines );
			if ( false === strpos( $src, 'ige_ffm_check_form' ) && false === strpos( $src, 'wp_verify_nonce' ) && false === strpos( $src, 'export_donnees' ) ) {
				$sans_jet[] = $h;
			}
			if ( ! preg_match( '/ige_ffm_require|current_user_can|user_can|peut_voir|peut_saisir|export_donnees/', $src ) ) {
				$sans_cap[] = $h;
			}
		}
	}
}
ige_t( count( $hooks ) >= 40, count( $hooks ) . ' traitements de formulaires inventoriés' );
ige_t( ! $nopriv, 'Aucun traitement accessible sans connexion' );
ige_t( ! $sans_jet, 'Chaque traitement vérifie un jeton anti-falsification' . ( $sans_jet ? ' — manque : ' . implode( ', ', $sans_jet ) : '' ) );
ige_t( ! $sans_cap, 'Chaque traitement vérifie les droits' . ( $sans_cap ? ' — manque : ' . implode( ', ', $sans_cap ) : '' ) );
$sql_brut = array();
foreach ( glob( IGE_FFM_DIR . 'includes/{,ecrans/}*.php', GLOB_BRACE ) as $fichier ) {
	foreach ( file( $fichier ) as $n => $l ) {
		if ( preg_match( '/(SELECT|UPDATE|DELETE|INSERT)[^;]*\$_(GET|POST|REQUEST|COOKIE)/i', $l ) ) {
			$sql_brut[] = basename( $fichier ) . ':' . ( $n + 1 );
		}
	}
}
ige_t( ! $sql_brut, 'Aucune donnée de formulaire insérée directement dans une requête SQL' . ( $sql_brut ? ' — ' . implode( ', ', $sql_brut ) : '' ) );
$sans_garde = array();
foreach ( glob( IGE_FFM_DIR . 'includes/{,ecrans/}*.php', GLOB_BRACE ) as $fichier ) {
	if ( false === strpos( (string) file_get_contents( $fichier ), "defined( 'ABSPATH' ) || exit;" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
		$sans_garde[] = basename( $fichier );
	}
}
ige_t( ! $sans_garde, 'Aucun fichier PHP exécutable directement' . ( $sans_garde ? ' — ' . implode( ', ', $sans_garde ) : '' ) );
ige_t( file_exists( IGE_FFM_DIR . 'vendor/.htaccess' ) && file_exists( IGE_FFM_DIR . 'tests/.htaccess' ) && file_exists( IGE_FFM_Pieces::dossier() . '.htaccess' ), 'Accès direct interdit : bibliothèques, tests, pièces' );

echo "\n7. Journal d'audit\n";
$acts = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 80 FROM ' . IGE_FFM_DB::t( 'audit' ) . ')' );
foreach ( array( 'reglage_jours_feries', 'question_ecriture', 'reponse_ecriture', 'controle_integrite' ) as $ac ) {
	ige_t( in_array( $ac, $acts, true ), "Action « $ac » journalisée" );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n8. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $sid, $sid2 ) as $s ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $s ) ) as $id ) {
		foreach ( IGE_FFM_Pieces::liste( 'ecriture', $id ) as $pc ) {
			wp_delete_file( IGE_FFM_Pieces::dossier() . $pc['fichier'] );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $id ) );
	}
	foreach ( array( 'ecriture', 'lot', 'echange', 'budget', 'solde_initial' ) as $tb ) {
		$wpdb->delete( IGE_FFM_DB::t( $tb ), array( 'service_id' => $s ) );
	}
	$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $s ) );
}
foreach ( array( $agent, $agent2, $compta ) as $u ) {
	wp_delete_user( $u );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $u ) );
}
null === $jf_avant ? delete_option( IGE_FFM_Jours_Feries::OPTION ) : update_option( IGE_FFM_Jours_Feries::OPTION, $jf_avant, false );
update_option( 'ige_ffm_reglages', $reglages, false );
ige_t( true, 'Données de test supprimées, réglages rétablis' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
