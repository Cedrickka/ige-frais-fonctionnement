<?php
/**
 * Tests automatiques de l'étape 4 (avances, justification, arrêtés de caisse, comptages inopinés,
 * clôture d'exercice).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-fonds.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-fonds.php\n" );
}
if ( ! defined( 'IGE_FFM_TESTS' ) ) {
	define( 'IGE_FFM_TESTS', true );
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

/**
 * Code d'erreur ou « ok ».
 *
 * @param mixed $r Résultat.
 * @return string
 */
function ige_m( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() . ' : ' . $r->get_error_message() : 'ok';
}

/**
 * Pièce PDF temporaire.
 *
 * @return array
 */
function ige_pdf() {
	$tmp = wp_tempnam( 'p.pdf' );
	file_put_contents( $tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return array( 'name' => 'facture.pdf', 'type' => '', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $tmp ) );
}

global $wpdb;
$sfx = substr( (string) time(), -5 );
add_filter( 'ige_ffm_notifier_par_courriel', '__return_false' );
wp_set_current_user( 1 );
$ex    = IGE_FFM_Contexte::exercice();
$exo   = (int) $ex['id'];
$an    = (int) $ex['annee'];
$auj   = IGE_FFM_Contexte::aujourdhui();
$mois  = (int) substr( $auj, 5, 2 );
$hier  = gmdate( 'Y-m-d', strtotime( $auj . ' -1 day' ) );
if ( (int) substr( $hier, 5, 2 ) !== $mois ) {
	$hier = $auj;
}
$m_av1 = max( 1, $mois - 3 );
$d_av1 = sprintf( '%d-%02d-15', $an, $m_av1 );
$m_ar2 = max( $m_av1 + 1, $mois - 1 );
if ( $mois < 5 ) {
	exit( "Ce test suppose qu'on soit au moins en mai de l'exercice.\n" );
}

echo "\n1. Préparation\n";
$sid  = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'F' . $sfx, 'libelle' => 'Fonds ' . $sfx, 'actif' => 1 ) );
$sid2 = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'G' . $sfx, 'libelle' => 'Autre ' . $sfx, 'actif' => 1 ) );
$c    = array(
	'agent'  => IGE_FFM_Comptes::create( array( 'user_login' => 'fagent' . $sfx, 'display_name' => 'Agent fonds', 'profil' => 'ige_agent', 'service_id' => $sid ) ),
	'resp'   => IGE_FFM_Comptes::create( array( 'user_login' => 'fresp' . $sfx, 'display_name' => 'Responsable fonds', 'profil' => 'ige_responsable', 'service_id' => $sid ) ),
	'autre'  => IGE_FFM_Comptes::create( array( 'user_login' => 'gagent' . $sfx, 'display_name' => 'Agent autre', 'profil' => 'ige_agent', 'service_id' => $sid2 ) ),
	'compta' => IGE_FFM_Comptes::create( array( 'user_login' => 'fcompta' . $sfx, 'display_name' => 'Comptable', 'profil' => 'ige_admin' ) ),
	'lect'   => IGE_FFM_Comptes::create( array( 'user_login' => 'flect' . $sfx, 'display_name' => 'Direction', 'profil' => 'ige_lecteur' ) ),
);
ige_t( ! array_filter( $c, 'is_wp_error' ), 'Services et comptes de test créés' );
$agent  = $c['agent']['user_id'];
$resp   = $c['resp']['user_id'];
$autre  = $c['autre']['user_id'];
$compta = $c['compta']['user_id'];
$lect   = $c['lect']['user_id'];
$rubs   = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes  = array();
foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
	$modes[ sanitize_title( remove_accents( $m['libelle'] ) ) ] = (int) $m['id'];
}
$base = array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'rubrique_id' => (int) $rubs['R01'], 'description' => 'Fournitures', 'beneficiaire' => 'Papeterie Fonds', 'mode_paiement_id' => $modes['cheque'] );
wp_set_current_user( $resp );
ige_t( true === IGE_FFM_Budget::enregistrer_solde_initial( $exo, $sid, '200000', $resp ), 'Solde initial 200 000 saisi par le Responsable' );

echo "\n2. Avances de fonds\n";
$av = array( 'service_id' => $sid, 'montant' => '1000000', 'date_remise' => $d_av1, 'mode_paiement_id' => $modes['especes'], 'objet' => 'Fonctionnement du trimestre' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Avances::creer( $av ) ), 'Agent : création d’avance refusée' );
wp_set_current_user( $compta );
ige_t( 'objet' === IGE_FFM_Avances::creer( array( 'objet' => '' ) + $av )->get_error_code(), 'Objet obligatoire' );
ige_t( 'date' === IGE_FFM_Avances::creer( array( 'date_remise' => gmdate( 'Y-m-d', strtotime( '+3 days' ) ) ) + $av )->get_error_code(), 'Date future refusée' );
$a1 = IGE_FFM_Avances::creer( $av );
ige_t( is_int( $a1 ), 'Avance 1 enregistrée : ' . ige_m( $a1 ) );
$A1 = IGE_FFM_Avances::get( $a1 );
ige_t( preg_match( '/^AV-' . $an . '-\d{3}$/', $A1['numero'] ) === 1, 'Numéro ' . $A1['numero'] );
$e1 = IGE_FFM_Ecritures::get( (int) $A1['ecriture_id'] );
ige_t( $e1 && 'entree' === $e1['type'] && (int) $e1['avance_id'] === $a1 && 1000000 === (int) $e1['montant'], 'Entrée créée dans la caisse du service, liée à l’avance' );
ige_t( 'transmise' === $e1['etat_transmission'] && 'validee' === $e1['etat_verification'], 'Entrée déjà transmise et validée (créée par le central)' );
ige_t( 1200000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Caisse = 1 200 000' );
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::contrepasser( (int) $A1['ecriture_id'], 'Je veux annuler' );
ige_t( is_wp_error( $r ) && 'avance' === $r->get_error_code(), 'Le service ne peut pas contrepasser une avance' );

echo "\n3. Justification (premier entré, premier sorti)\n";
$s1 = IGE_FFM_Ecritures::saisir( $base + array( 'num_piece' => 'F1', 'montant' => '600000' ), ige_pdf() );
$s2 = IGE_FFM_Ecritures::saisir( array( 'rubrique_id' => (int) $rubs['R03'], 'description' => 'Carburant' ) + $base + array( 'num_piece' => 'F2', 'montant' => '450000' ), ige_pdf() );
IGE_FFM_Transmission::transmettre( $sid );
$j = IGE_FFM_Avances::justification( $exo, $sid );
ige_t( 0 === $j['justifie'] && 0.0 === (float) $j['taux_derniere'], 'Dépenses transmises mais pas encore validées : rien de justifié' );
wp_set_current_user( $compta );
IGE_FFM_Verification::valider( array( $s1, $s2 ) );
$j = IGE_FFM_Avances::justification( $exo, $sid );
ige_t( 1050000 === $j['justifie'], 'Après validation : 1 050 000 justifiés' );
ige_t( 'report' === $j['sources'][0]['type'] && 0 === $j['sources'][0]['reliquat'], 'Le solde initial est apuré en premier' );
ige_t( 850000 === $j['avances'][0]['justifie'] && 85 === (int) floor( $j['taux_derniere'] ), 'Avance 1 justifiée à 85 % (850 000 sur 1 000 000)' );
wp_set_current_user( $agent );
$s3 = IGE_FFM_Ecritures::saisir( $base + array( 'num_piece' => 'F3', 'montant' => '100000' ), ige_pdf() );
IGE_FFM_Transmission::transmettre( $sid );
ige_t( 85 === (int) floor( IGE_FFM_Avances::justification( $exo, $sid )['taux_derniere'] ), 'Une dépense non encore validée ne compte pas' );
$syn = IGE_FFM_Avances::synthese( $exo )[ $sid ];
ige_t( 150000 === $syn['reliquat'] && 150000 === $syn['tranches']['plus90'] + $syn['tranches']['de60a90'] + $syn['tranches']['de30a60'] && $syn['plus_ancien'] >= 60, 'Synthèse : reliquat 150 000, ancienneté ' . $syn['plus_ancien'] . ' jours' );

echo "\n4. Règle des 80 % et dérogation\n";
wp_set_current_user( $compta );
$a2 = IGE_FFM_Avances::creer( array( 'montant' => '500000', 'date_remise' => $hier, 'objet' => 'Fonctionnement du mois' ) + $av );
ige_t( is_int( $a2 ), 'Avance 2 accordée (la précédente est justifiée à 85 % ≥ 80 %)' );
$r = IGE_FFM_Avances::creer( array( 'montant' => '300000', 'date_remise' => $hier, 'objet' => 'Mission' ) + $av );
ige_t( is_wp_error( $r ) && 'justification' === $r->get_error_code(), 'Avance 3 refusée : avance 2 justifiée à 0 %' );
$r = IGE_FFM_Avances::creer( array( 'montant' => '300000', 'date_remise' => $hier, 'objet' => 'Mission', 'derogation' => 'court' ) + $av );
ige_t( is_wp_error( $r ), 'Dérogation sans motif suffisant refusée' );
$a3 = IGE_FFM_Avances::creer( array( 'montant' => '300000', 'date_remise' => $hier, 'objet' => 'Mission', 'derogation' => 'Mission d’inspection urgente décidée par l’IG' ) + $av );
ige_t( is_int( $a3 ) && false !== strpos( IGE_FFM_Avances::get( $a3 )['objet'], 'dérogation' ), 'Avance 3 accordée par dérogation motivée (motif conservé)' );
$caisse = IGE_FFM_Budget::caisse( $exo, $sid );

echo "\n5. Annulation d’une avance\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Avances::annuler( $a3, 'Erreur de service' ) ), 'Agent : annulation refusée' );
wp_set_current_user( $compta );
ige_t( true === IGE_FFM_Avances::annuler( $a3, 'Remise faite au mauvais service' ), 'Avance 3 annulée par le central' );
ige_t( $caisse - 300000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Montant retiré de la caisse (contrepassation)' );
ige_t( IGE_FFM_Avances::annulee( IGE_FFM_Avances::get( $a3 ) ) && 2 === count( IGE_FFM_Avances::justification( $exo, $sid )['avances'] ), 'L’avance annulée sort du suivi' );
$cp = IGE_FFM_Ecritures::contrepassation_de( (int) IGE_FFM_Avances::get( $a3 )['ecriture_id'] );
ige_t( 'validee' === $cp['etat_verification'], 'La contrepassation du central est validée d’office' );

echo "\n6. Arrêté de caisse mensuel\n";
$noms  = IGE_FFM_Ecran_Livre::mois_noms();
$theo1 = IGE_FFM_Arretes::solde_theorique( $exo, $sid, IGE_FFM_Arretes::fin_de_mois( $ex, $m_av1 ) );
ige_t( 1200000 === $theo1, 'Solde théorique fin ' . strtolower( $noms[ $m_av1 ] ) . ' : 1 200 000' );
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'cdf' => array( 20000 => 60 ) ) ) ), 'Lecteur : comptage refusé' );
wp_set_current_user( $agent );
ige_t( 'mois' === IGE_FFM_Arretes::enregistrer( $sid, $mois + 1, array( 'cdf' => array( 20000 => 1 ) ) )->get_error_code(), 'Mois non commencé refusé' );
ige_t( 'taux' === IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'usd' => array( 100 => 2 ) ) )->get_error_code(), 'Dollars comptés sans taux du jour refusés' );
$ar1 = IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'cdf' => array( 20000 => 59, 10000 => 1 ), 'usd' => array( 1 => 0 ) ) );
$A   = IGE_FFM_Arretes::get( $ar1 );
ige_t( 1190000 === (int) $A['total_compte'] && -10000 === (int) $A['ecart'], 'Comptage 1 190 000 : écart −10 000' );
ige_t( 'explication' === IGE_FFM_Arretes::signer_agent( $ar1 )->get_error_code(), 'Signature refusée tant que l’écart n’est pas expliqué' );
$ar1 = IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'cdf' => array( 20000 => 55, 10000 => 5 ), 'usd' => array( 100 => 5 ), 'taux' => '2 800', 'autres' => '0' ) );
$A   = IGE_FFM_Arretes::get( $ar1 );
ige_t( 1150000 + 1400000 === (int) $A['total_compte'] && 1350000 === (int) $A['ecart'], 'Dollars convertis au taux du jour (500 USD × 2 800)' );
$ar1 = IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'cdf' => array( 20000 => 59 ), 'autres' => '20000', 'autres_detail' => 'Solde M-Pesa' ) );
$A   = IGE_FFM_Arretes::get( $ar1 );
ige_t( 0 === (int) $A['ecart'] && 1 === count( IGE_FFM_Arretes::liste( $exo, $sid, 'mensuel' ) ), 'Comptage corrigé (espèces + mobile money) : caisse conforme, un seul arrêté pour le mois' );
ige_t( true === IGE_FFM_Arretes::signer_agent( $ar1 ), 'Signature de l’agent' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Arretes::signer_responsable( $ar1 ) ), 'Un agent ne peut pas signer comme Responsable' );
wp_set_current_user( $resp );
ige_t( true === IGE_FFM_Arretes::signer_responsable( $ar1 ), 'Signature du Responsable : mois clôturé' );
ige_t( IGE_FFM_Ecritures::mois_clos( $exo, $sid, $d_av1 ) && IGE_FFM_Ecritures::mois_clos( $exo, $sid, sprintf( '%d-01-10', $an ) ), 'Le mois et les précédents sont verrouillés' );
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::saisir( array( 'date_operation' => $d_av1, 'num_piece' => 'F9' ) + $base + array( 'montant' => '1000' ), ige_pdf() );
ige_t( is_wp_error( $r ) && 'date' === $r->get_error_code(), 'Saisie dans le mois clôturé refusée' );
ige_t( 'mois' === IGE_FFM_Arretes::enregistrer( $sid, $m_av1, array( 'cdf' => array( 20000 => 1 ) ) )->get_error_code(), 'Un mois clôturé ne peut pas être recompté' );
wp_set_current_user( $compta );
$r = IGE_FFM_Avances::creer( array( 'date_remise' => $d_av1, 'objet' => 'Test mois clos', 'derogation' => 'Test de date dans un mois clôturé' ) + $av );
ige_t( is_wp_error( $r ) && 'date' === $r->get_error_code(), 'Avance datée d’un mois clôturé refusée' );

echo "\n7. Séparation des signatures et livre modifié\n";
wp_set_current_user( $resp );
$ar2 = IGE_FFM_Arretes::enregistrer( $sid, $m_ar2, array( 'cdf' => array( 20000 => 60 ) ) );
$A2  = IGE_FFM_Arretes::get( $ar2 );
IGE_FFM_Arretes::enregistrer( $sid, $m_ar2, array( 'cdf' => array( 20000 => 60 ) ) + array( 'autres' => (string) ( (int) $A2['solde_theorique'] - 1200000 ) ) );
ige_t( true === IGE_FFM_Arretes::signer_agent( $ar2 ), 'Le Responsable compte et signe comme agent' );
$r = IGE_FFM_Arretes::signer_responsable( $ar2 );
ige_t( is_wp_error( $r ) && 'separation' === $r->get_error_code(), 'Il ne peut pas contresigner son propre comptage' );
wp_set_current_user( $agent );
$ar3 = IGE_FFM_Arretes::enregistrer( $sid, $mois, array( 'cdf' => array( 20000 => 1 ), 'explication' => 'Comptage partiel pour le test' ) );
IGE_FFM_Ecritures::saisir( $base + array( 'num_piece' => 'F4', 'montant' => '5000' ), ige_pdf() );
$r = IGE_FFM_Arretes::signer_agent( $ar3 );
ige_t( is_wp_error( $r ) && 'solde' === $r->get_error_code(), 'Signature refusée si le livre a changé depuis le comptage' );

echo "\n8. Comptage inopiné\n";
$cdf = array( 20000 => 10 );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Arretes::inopine( $sid, array( 'cdf' => $cdf, 'agent_present' => $agent ) ) ), 'Agent : comptage inopiné refusé' );
wp_set_current_user( $compta );
ige_t( 'agent' === IGE_FFM_Arretes::inopine( $sid, array( 'cdf' => $cdf, 'agent_present' => $autre ) )->get_error_code(), 'Agent présent d’un autre service refusé' );
ige_t( 'explication' === IGE_FFM_Arretes::inopine( $sid, array( 'cdf' => $cdf, 'agent_present' => $agent ) )->get_error_code(), 'Écart sans constatation refusé' );
$theo = IGE_FFM_Budget::caisse( $exo, $sid );
$ci   = IGE_FFM_Arretes::inopine( $sid, array( 'cdf' => array(), 'autres' => (string) $theo, 'autres_detail' => 'Relevé bancaire', 'agent_present' => $agent ) );
$CI   = IGE_FFM_Arretes::get( $ci );
ige_t( is_int( $ci ) && 0 === (int) $CI['ecart'] && 'constate' === $CI['statut'] && (int) $CI['signe_resp_par'] === $compta, 'Comptage inopiné conforme constaté par le contrôleur' );
ige_t( preg_match( '/^CI-F' . $sfx . '-' . $an . '-\d{4}$/', IGE_FFM_Arretes::reference( $CI ) ) === 1, 'Référence ' . IGE_FFM_Arretes::reference( $CI ) );
wp_set_current_user( $autre );
ige_t( is_wp_error( IGE_FFM_Arretes::contresigner( $ci ) ), 'Un agent d’un autre service ne peut pas contresigner' );
wp_set_current_user( $agent );
ige_t( true === IGE_FFM_Arretes::contresigner( $ci ) && 'contresigne' === IGE_FFM_Arretes::get( $ci )['statut'], 'L’agent présent contresigne' );
ige_t( ! IGE_FFM_Ecritures::mois_clos( $exo, $sid, $auj ), 'Le comptage inopiné ne clôture pas le mois' );

echo "\n9. Exercices : préparation et clôture\n";
wp_set_current_user( 1 );
$pid = IGE_FFM_Referentiels::creer_exercice_suivant();
$P   = IGE_FFM_Cloture::exercice( $pid );
ige_t( $P && 'prepare' === $P['statut'] && (int) $P['annee'] === $an + 1, 'Exercice ' . ( $an + 1 ) . ' préparé (pas ouvert)' );
ige_t( (int) IGE_FFM_Referentiels::exercice_courant()['id'] === $exo, 'L’exercice en cours reste ' . $an );
ige_t( is_wp_error( IGE_FFM_Referentiels::creer_exercice_suivant() ), 'Un seul exercice préparé à la fois' );
wp_set_current_user( $resp );
ige_t( 1 === IGE_FFM_Budget::enregistrer( $pid, $sid, array( (int) $rubs['R01'] => '2 000 000' ), '', $resp ), 'Le Responsable saisit son budget ' . ( $an + 1 ) . ' à l’avance' );
$wpdb->delete( IGE_FFM_DB::t( 'budget' ), array( 'exercice_id' => $pid ) );
$wpdb->delete( IGE_FFM_DB::t( 'exercice' ), array( 'id' => $pid ) );

// Clôture sur un exercice fictif (2030) pour ne pas toucher l'exercice réel du site de test.
$wpdb->insert( IGE_FFM_DB::t( 'exercice' ), array( 'annee' => 2030, 'date_debut' => '2030-01-01', 'date_fin' => '2030-12-31', 'statut' => 'ouvert', 'budget_obligatoire' => 0, 'created_at' => ige_ffm_now() ) );
$fx = (int) $wpdb->insert_id;
foreach ( IGE_FFM_Referentiels::all( 'service' ) as $s ) {
	if ( ! in_array( (int) $s['id'], array( $sid, $sid2 ), true ) ) {
		$wpdb->insert( IGE_FFM_DB::t( 'solde_initial' ), array( 'exercice_id' => $fx, 'service_id' => (int) $s['id'], 'devise' => 'CDF', 'montant' => 0 ) );
	}
}
$ins = static function ( $d ) use ( $wpdb, $fx, $sid ) {
	static $n = 0;
	++$n;
	$wpdb->insert( IGE_FFM_DB::t( 'ecriture' ), $d + array( 'id_ecriture' => 'TEST-2030-' . $n, 'numero' => $n, 'exercice_id' => $fx, 'service_id' => $sid, 'date_operation' => '2030-03-10', 'mois' => 3, 'description' => 'Test clôture', 'saisi_par' => 1, 'saisi_le' => '2030-03-10 10:00:00' ) );
	return (int) $wpdb->insert_id;
};
$x1 = $ins( array( 'type' => 'entree', 'statut' => 'payee', 'montant' => 900000, 'etat_transmission' => 'transmise', 'etat_verification' => 'validee' ) );
$x2 = $ins( array( 'type' => 'sortie', 'statut' => 'engagee', 'montant' => 100000, 'etat_transmission' => 'transmise', 'etat_verification' => 'validee' ) );
$x3 = $ins( array( 'type' => 'sortie', 'statut' => 'payee', 'montant' => 250000, 'etat_transmission' => 'non_transmise', 'etat_verification' => '' ) );
$b  = IGE_FFM_Cloture::bilan( $fx );
$bs = array_values( array_filter( $b['services'], static fn( $x ) => (int) $x['service']['id'] === $sid ) )[0];
ige_t( $bs['bloquant'] && 1 === $bs['engagements'] && 1 === $bs['non_transmises'], 'Bilan : engagement en cours et écriture non transmise détectés' );
ige_t( 'bloquants' === IGE_FFM_Cloture::cloturer( $fx, 'CLÔTURER 2030', 1 )->get_error_code(), 'Clôture refusée tant qu’il reste des blocages' );
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'statut' => 'payee', 'etat_verification' => 'validee' ), array( 'id' => $x2 ) );
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etat_transmission' => 'transmise', 'etat_verification' => 'validee' ), array( 'id' => $x3 ) );
ige_t( is_wp_error( IGE_FFM_Cloture::cloturer( $fx, 'CLOTURER 2030', 1 ) ), 'Confirmation inexacte refusée' );
ige_t( is_wp_error( IGE_FFM_Cloture::cloturer( $fx, 'CLÔTURER 2030', $resp ) ), 'Un Responsable de service ne peut pas clôturer' );
$nx = IGE_FFM_Cloture::cloturer( $fx, 'CLÔTURER 2030', 1 );
ige_t( is_int( $nx ), 'Exercice 2030 clôturé : ' . ige_m( $nx ) );
$N = is_int( $nx ) ? IGE_FFM_Cloture::exercice( $nx ) : null;
ige_t( 'clos' === IGE_FFM_Cloture::exercice( $fx )['statut'] && $N && 2031 === (int) $N['annee'] && 'ouvert' === $N['statut'], 'Exercice 2031 ouvert automatiquement' );
ige_t( $N && 550000 === IGE_FFM_Budget::solde_initial( $nx, $sid ), 'Solde final reporté en solde initial 2031 (900 000 − 100 000 − 250 000)' );
ige_t( $N && 1 === (int) $N['budget_obligatoire'], 'Budget obligatoire pour le nouvel exercice' );
ige_t( (int) IGE_FFM_Referentiels::exercice_courant()['id'] === $exo, 'L’exercice réel du site n’est pas affecté' );

echo "\n10. Journal d'audit\n";
$acts = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 80 FROM ' . IGE_FFM_DB::t( 'audit' ) . ')' );
foreach ( array( 'avance_fonds', 'annulation_avance', 'comptage_caisse', 'signature_arrete', 'cloture_mois', 'comptage_inopine', 'cloture_exercice' ) as $a ) {
	ige_t( in_array( $a, $acts, true ), "Action « $a » journalisée" );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n11. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $fx, $nx ) as $e ) {
	if ( is_int( $e ) ) {
		$wpdb->delete( IGE_FFM_DB::t( 'ecriture' ), array( 'exercice_id' => $e ) );
		$wpdb->delete( IGE_FFM_DB::t( 'solde_initial' ), array( 'exercice_id' => $e ) );
		$wpdb->delete( IGE_FFM_DB::t( 'exercice' ), array( 'id' => $e ) );
	}
}
foreach ( array( $sid, $sid2 ) as $s ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $s ) ) as $id ) {
		foreach ( IGE_FFM_Pieces::liste( 'ecriture', $id ) as $p ) {
			wp_delete_file( IGE_FFM_Pieces::dossier() . $p['fichier'] );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $id ) );
	}
	foreach ( array( 'ecriture', 'lot', 'budget', 'revision_budget', 'solde_initial', 'avance', 'arrete' ) as $t ) {
		$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => $s ) );
	}
	$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $s ) );
}
foreach ( array( $agent, $resp, $autre, $compta, $lect ) as $u ) {
	wp_delete_user( $u );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $u ) );
}
ige_t( true, 'Données de test supprimées (le journal d’audit est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
