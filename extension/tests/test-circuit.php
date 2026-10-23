<?php
/**
 * Tests automatiques de l'étape 3 (transmission, anomalies, vérification, rejet, contrepassation).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-circuit.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-circuit.php\n" );
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
 * Message d'erreur ou « ok ».
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
$sfx      = substr( (string) time(), -5 );
$reglages = get_option( 'ige_ffm_reglages' );
add_filter( 'ige_ffm_notifier_par_courriel', '__return_false' );
wp_set_current_user( 1 );
$ex   = IGE_FFM_Contexte::exercice();
$exo  = (int) $ex['id'];
$auj  = IGE_FFM_Contexte::aujourdhui();
// Un jour ouvré récent (pour ne pas déclencher l'anomalie « jour non ouvré » par hasard).
$jour = strtotime( '-1 day' );
while ( (int) gmdate( 'N', $jour ) >= 6 ) {
	$jour = strtotime( '-1 day', $jour );
}
$hier = gmdate( 'Y-m-d', $jour );
$dim  = gmdate( 'Y-m-d', strtotime( 'last sunday', strtotime( $auj ) ) );

echo "\n1. Préparation\n";
$sid  = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'C' . $sfx, 'libelle' => 'Circuit ' . $sfx, 'actif' => 1 ) );
$sid2 = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'D' . $sfx, 'libelle' => 'Autre ' . $sfx, 'actif' => 1 ) );
$ag   = IGE_FFM_Comptes::create( array( 'user_login' => 'cagent' . $sfx, 'display_name' => 'Kabeya Mutombo', 'profil' => 'ige_agent', 'service_id' => $sid ) );
$ag2  = IGE_FFM_Comptes::create( array( 'user_login' => 'dagent' . $sfx, 'display_name' => 'Agent autre', 'profil' => 'ige_agent', 'service_id' => $sid2 ) );
$cpt  = IGE_FFM_Comptes::create( array( 'user_login' => 'ccompta' . $sfx, 'display_name' => 'Comptable central', 'profil' => 'ige_admin' ) );
$lec  = IGE_FFM_Comptes::create( array( 'user_login' => 'clect' . $sfx, 'display_name' => 'Direction', 'profil' => 'ige_lecteur' ) );
ige_t( is_int( $sid ) && is_int( $sid2 ) && ! is_wp_error( $ag ) && ! is_wp_error( $cpt ), 'Services, agents, comptable et lecteur de test créés' );
$agent  = $ag['user_id'];
$agent2 = $ag2['user_id'];
$compta = $cpt['user_id'];
$lect   = $lec['user_id'];
$rubs   = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes  = array();
foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
	$modes[ sanitize_title( remove_accents( $m['libelle'] ) ) ] = (int) $m['id'];
}
$base = array(
	'type'             => 'sortie',
	'statut'           => 'payee',
	'date_operation'   => $hier,
	'rubrique_id'      => (int) $rubs['R01'],
	'description'      => 'Fournitures',
	'beneficiaire'     => 'Papeterie Circuit',
	'num_piece'        => 'F-100',
	'mode_paiement_id' => $modes['cheque'],
);

wp_set_current_user( $agent );
$e0 = IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $hier, 'montant' => '5000000', 'description' => 'Approvisionnement', 'beneficiaire' => 'Inspection Générale', 'num_piece' => 'AV-1', 'mode_paiement_id' => $modes['virement'] ) );
$s1 = IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '300000' ), ige_pdf() );
$s2 = IGE_FFM_Ecritures::saisir( array( 'num_piece' => 'F-100', 'description' => 'Fournitures (deuxième fois)', 'montant' => '300000' ) + $base, ige_pdf() );
$s3 = IGE_FFM_Ecritures::saisir( array( 'date_operation' => $dim, 'num_piece' => 'F-101', 'beneficiaire' => 'Kabeya Mutombo', 'description' => 'Remboursement transport', 'rubrique_id' => (int) $rubs['R07'], 'montant' => '50000' ) + $base, ige_pdf() );
$s4 = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'num_piece' => 'BC-7', 'beneficiaire' => 'Garage Circuit', 'description' => 'Révision véhicule', 'rubrique_id' => (int) $rubs['R04'], 'montant' => '800000' ) + $base, ige_pdf() );
$f1 = IGE_FFM_Ecritures::saisir( array( 'num_piece' => 'T-1', 'beneficiaire' => 'Station Circuit', 'description' => 'Carburant 1', 'rubrique_id' => (int) $rubs['R03'], 'montant' => '1500000', 'mode_paiement_id' => $modes['especes'] ) + $base, ige_pdf() );
$f2 = IGE_FFM_Ecritures::saisir( array( 'num_piece' => 'T-2', 'beneficiaire' => 'Station Circuit', 'description' => 'Carburant 2', 'rubrique_id' => (int) $rubs['R03'], 'montant' => '1500000', 'mode_paiement_id' => $modes['especes'] ) + $base, ige_pdf() );
ige_t( is_int( $e0 ) && is_int( $s1 ) && is_int( $s2 ) && is_int( $s3 ) && is_int( $s4 ) && is_int( $f1 ) && is_int( $f2 ), '7 écritures saisies (entrée, sorties, engagement) : ' . ige_m( $s3 ) );
// Saisie tardive : on vieillit la date d'opération d'une écriture existante.
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'saisi_le' => gmdate( 'Y-m-d H:i:s', strtotime( $hier . ' +20 days' ) ) ), array( 'id' => $s1 ) );

echo "\n2. Anomalies à la réception\n";
$an = IGE_FFM_Anomalies::pour_service( $exo, $sid );
ige_t( isset( $an[ $s2 ]['doublon'] ) && isset( $an[ $s1 ]['doublon'] ), 'Doublon : même pièce F-100 et même bénéficiaire' );
ige_t( isset( $an[ $s3 ]['jour_non_ouvre'] ), 'Jour non ouvré : dépense datée d’un dimanche' );
ige_t( isset( $an[ $s3 ]['beneficiaire'] ), 'Bénéficiaire = agent qui saisit' );
ige_t( isset( $an[ $f1 ]['fractionnement'] ) && isset( $an[ $f2 ]['fractionnement'] ), 'Fractionnement : 2 × 1 500 000 en espèces au même bénéficiaire (plafond 2 000 000)' );
ige_t( isset( $an[ $s1 ]['saisie_tardive'] ), 'Saisie tardive (20 jours après l’opération)' );
ige_t( empty( $an[ $s4 ] ) && empty( $an[ $e0 ] ), 'Engagement et entrée sans anomalie' );
$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $s4 ) );
$an = IGE_FFM_Anomalies::pour_service( $exo, $sid, array( $s4 ) );
ige_t( isset( $an[ $s4 ]['piece'] ), 'Pièce manquante détectée (fichier retiré)' );
$opts                            = $reglages;
$opts['anomalie_jour_non_ouvre'] = false;
update_option( 'ige_ffm_reglages', $opts );
$an = IGE_FFM_Anomalies::pour_service( $exo, $sid, array( $s3 ) );
ige_t( ! isset( $an[ $s3 ]['jour_non_ouvre'] ), 'Contrôle désactivable dans les règles' );
update_option( 'ige_ffm_reglages', $reglages );
IGE_FFM_Pieces::enregistrer( ige_pdf(), 'ecriture', $s4, $agent );

echo "\n3. Transmission\n";
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Transmission::transmettre( $sid ) ), 'Lecteur : transmission refusée' );
wp_set_current_user( $agent2 );
ige_t( is_wp_error( IGE_FFM_Transmission::transmettre( $sid ) ), 'Agent d’un autre service : transmission refusée' );
wp_set_current_user( $agent );
ige_t( 7 === count( IGE_FFM_Transmission::a_transmettre( $exo, $sid ) ), '7 écritures prêtes à transmettre' );
$lot = IGE_FFM_Transmission::transmettre( $sid );
ige_t( is_int( $lot ), 'Lot transmis : ' . ige_m( $lot ) );
$l = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE id = %d', $lot ), ARRAY_A );
ige_t( preg_match( '/^TR-C' . $sfx . '-' . $ex['annee'] . '-001$/', $l['id_transmission'] ) === 1, 'Numéro de lot ' . $l['id_transmission'] );
ige_t( 7 === (int) $l['nb_lignes'] && 5000000 === (int) $l['total_entrees'] && 4450000 === (int) $l['total_sorties'], 'Récapitulatif : 7 lignes, entrées 5 000 000, sorties 4 450 000' );
$e = IGE_FFM_Ecritures::get( $s2 );
ige_t( 'transmise' === $e['etat_transmission'] && 'a_verifier' === $e['etat_verification'] && (int) $e['lot_id'] === $lot, 'Écriture transmise, À vérifier, rattachée au lot' );
ige_t( isset( IGE_FFM_Anomalies::lire( $e )['doublon'] ), 'Anomalies enregistrées sur l’écriture' );
ige_t( array( 'À vérifier', 'wait' ) === IGE_FFM_Ecritures::etat( $e ), 'État affiché au service : À vérifier' );
ige_t( is_wp_error( IGE_FFM_Transmission::transmettre( $sid ) ), 'Second envoi sans nouvelle écriture refusé (rien à transmettre)' );

echo "\n4. Vérification\n";
$syn = IGE_FFM_Verification::synthese( $exo, $sid );
ige_t( 7 === $syn['a_verifier']['n'] && 4450000 === $syn['a_verifier']['montant'], 'Synthèse : 7 à vérifier, 4 450 000 de sorties' );
ige_t( $syn['anomalies']['n'] >= 5, 'Synthèse : ' . $syn['anomalies']['n'] . ' avec anomalie' );
$fa = IGE_FFM_Verification::file( $exo, array( 'vue' => 'anomalies', 'service_id' => $sid ) );
ige_t( $fa['total'] === $syn['anomalies']['n'], 'File filtrée « Avec anomalie »' );
ige_t( 7 === IGE_FFM_Verification::file( $exo, array( 'vue' => 'a_verifier', 'lot_id' => $lot ) )['total'], 'File filtrée par lot' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Verification::valider( array( $s4 ) ) ), 'Agent : validation refusée' );
wp_set_current_user( $compta );
$r = IGE_FFM_Verification::valider_sans_anomalie( $exo, array( 'service_id' => $sid ) );
ige_t( ! is_wp_error( $r ) && 2 === $r['n'], 'Validation en masse des écritures sans anomalie : ' . ( is_wp_error( $r ) ? ige_m( $r ) : $r['n'] ) );
ige_t( 'validee' === IGE_FFM_Ecritures::get( $s4 )['etat_verification'] && 'validee' === IGE_FFM_Ecritures::get( $e0 )['etat_verification'], 'Entrée et engagement validés' );
ige_t( 'a_verifier' === IGE_FFM_Ecritures::get( $s2 )['etat_verification'], 'Écriture avec anomalie laissée à examiner' );
$r = IGE_FFM_Verification::rejeter( array( $s2 ), 'non' );
ige_t( is_wp_error( $r ) && 'motif' === $r->get_error_code(), 'Rejet sans motif refusé' );
$r = IGE_FFM_Verification::rejeter( array( $s2, $s4 ), 'Facture F-100 déjà payée (doublon)' );
ige_t( 1 === $r['n'] && 1 === count( $r['refus'] ), 'Rejet : une écriture rejetée, la déjà validée refusée' );
$e = IGE_FFM_Ecritures::get( $s2 );
ige_t( 'rejetee' === $e['etat_verification'] && 'Facture F-100 déjà payée (doublon)' === $e['motif_rejet'] && (int) $e['valide_par'] === $compta, 'Motif et vérificateur enregistrés' );
$r = IGE_FFM_Verification::valider( array( $s1, $s3, $f1, $f2 ) );
ige_t( 4 === $r['n'], 'Validation d’une sélection après examen' );
// Séparation des fonctions : un vérificateur ne valide pas ce qu'il a saisi lui-même.
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etat_verification' => 'a_verifier', 'saisi_par' => $compta ), array( 'id' => $f2 ) );
$r = IGE_FFM_Verification::valider( array( $f2 ) );
ige_t( 0 === $r['n'] && 1 === count( $r['refus'] ), 'Un vérificateur ne peut pas valider sa propre saisie' );
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'etat_verification' => 'validee', 'saisi_par' => $agent ), array( 'id' => $f2 ) );

echo "\n5. Rejet : retour au service et contrepassation\n";
wp_set_current_user( $agent );
$rej = IGE_FFM_Transmission::rejets_a_traiter( $exo, $sid );
ige_t( 1 === count( $rej ) && (int) $rej[0]['id'] === $s2, 'Le rejet apparaît dans « Rejets à corriger »' );
ige_t( 'regle' === IGE_FFM_Transmission::remettre( $s2 )->get_error_code(), 'Renvoi direct refusé : contrepassation obligatoire' );
$c2 = IGE_FFM_Ecritures::contrepasser( $s2, 'Doublon de la facture F-100' );
ige_t( is_int( $c2 ), 'Contrepassation de l’écriture rejetée : ' . ige_m( $c2 ) );
ige_t( ! IGE_FFM_Transmission::rejets_a_traiter( $exo, $sid ), 'Le rejet disparaît de la liste une fois contrepassé' );
ige_t( 1 === count( IGE_FFM_Transmission::a_transmettre( $exo, $sid ) ), 'La contrepassation attend la prochaine transmission' );

echo "\n6. Engagement payé après validation (RG-13)\n";
$r = IGE_FFM_Ecritures::payer( $s4, $auj, $modes['cheque'] );
ige_t( true === $r, 'Paiement de l’engagement validé : ' . ige_m( $r ) );
$e = IGE_FFM_Ecritures::get( $s4 );
ige_t( 'a_retransmettre' === $e['etat_transmission'] && 'À retransmettre' === IGE_FFM_Ecritures::etat( $e )[0], 'L’écriture passe « À retransmettre »' );
$nb_avant = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $sid ) );
$lot2     = IGE_FFM_Transmission::transmettre( $sid );
$l2       = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'lot' ) . ' WHERE id = %d', $lot2 ), ARRAY_A );
ige_t( 2 === (int) $l2['nb_lignes'] && 1 === (int) $l2['nb_mises_a_jour'] && str_ends_with( $l2['id_transmission'], '-002' ), 'Lot 002 : 2 lignes dont 1 mise à jour' );
$e = IGE_FFM_Ecritures::get( $s4 );
ige_t( 'a_verifier' === $e['etat_verification'] && 2 === (int) $e['nb_transmissions'] && null === $e['valide_par'], 'Au central, la même ligne repasse « À vérifier » (2e transmission)' );
ige_t( $nb_avant === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $sid ) ), 'Aucun doublon créé' );
$lots = IGE_FFM_Transmission::lots( $exo, $sid );
ige_t( 2 === count( $lots ) && 'En vérification' === IGE_FFM_Transmission::etat_lot( $lots[0] )[0], 'Journal des lots : 2 lots, le dernier en vérification' );

echo "\n7. Engagement rejeté : paiement bloqué\n";
$s5 = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'num_piece' => 'BC-9', 'beneficiaire' => 'Garage Circuit', 'description' => 'Pneus', 'rubrique_id' => (int) $rubs['R04'], 'montant' => '200000' ) + $base, ige_pdf() );
IGE_FFM_Transmission::transmettre( $sid );
wp_set_current_user( $compta );
IGE_FFM_Verification::rejeter( array( $s5 ), 'Devis non signé par le Responsable' );
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::payer( $s5, $auj, $modes['cheque'] );
ige_t( is_wp_error( $r ) && 'rejet' === $r->get_error_code(), 'Paiement d’un engagement rejeté refusé' );

echo "\n8. Renvoi sans contrepassation (règle désactivée)\n";
$opts                          = $reglages;
$opts['contrepassation_rejet'] = false;
update_option( 'ige_ffm_reglages', $opts );
ige_t( true === IGE_FFM_Transmission::remettre( $s5 ), 'Rejet renvoyé après correction' );
ige_t( 'a_retransmettre' === IGE_FFM_Ecritures::get( $s5 )['etat_transmission'], 'Il repart dans le prochain lot' );
update_option( 'ige_ffm_reglages', $reglages );

echo "\n9. Transmission automatique\n";
$opts                      = $reglages;
$opts['transmission_auto'] = true;
update_option( 'ige_ffm_reglages', $opts );
$s6 = IGE_FFM_Ecritures::saisir( array( 'num_piece' => 'F-200', 'description' => 'Classeurs', 'montant' => '40000' ) + $base, ige_pdf() );
$e  = IGE_FFM_Ecritures::get( $s6 );
ige_t( 'transmise' === $e['etat_transmission'] && 'a_verifier' === $e['etat_verification'], 'Écriture transmise dès son enregistrement' );
ige_t( 'a_retransmettre' === IGE_FFM_Ecritures::get( $s5 )['etat_transmission'], 'Les autres écritures en attente ne partent pas avec elle' );
update_option( 'ige_ffm_reglages', $reglages );

echo "\n10. Livre de caisse : filtre par état\n";
$v = IGE_FFM_Ecritures::livre( $exo, $sid, array( 'etat' => 'validee' ) );
ige_t( count( $v['rows'] ) >= 4 && ! array_filter( $v['rows'], static fn( $x ) => 'validee' !== IGE_FFM_Ecritures::etat_code( $x ) ), 'Filtre « Validées »' );
$v = IGE_FFM_Ecritures::livre( $exo, $sid, array( 'etat' => 'rejetee' ) );
ige_t( 1 === count( $v['rows'] ) && (int) $v['rows'][0]['id'] === $s2, 'Filtre « Rejetées »' );

echo "\n11. Journal d'audit\n";
$acts = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 80 FROM ' . IGE_FFM_DB::t( 'audit' ) . ')' );
foreach ( array( 'transmission', 'validation', 'rejet', 'remise_transmission' ) as $a ) {
	ige_t( in_array( $a, $acts, true ), "Action « $a » journalisée" );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n12. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $sid, $sid2 ) as $s ) {
	$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $s ) );
	foreach ( $ids as $id ) {
		foreach ( IGE_FFM_Pieces::liste( 'ecriture', $id ) as $p ) {
			wp_delete_file( IGE_FFM_Pieces::dossier() . $p['fichier'] );
		}
		$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $id ) );
	}
	foreach ( array( 'ecriture', 'lot', 'budget', 'revision_budget', 'solde_initial' ) as $t ) {
		$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => $s ) );
	}
	$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $s ) );
}
foreach ( array( $agent, $agent2, $compta, $lect ) as $u ) {
	wp_delete_user( $u );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $u ) );
}
ige_t( true, 'Données de test supprimées (le journal d’audit est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
