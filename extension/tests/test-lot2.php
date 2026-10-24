<?php
/**
 * Tests automatiques de la version 2.0 (lot 2) : double devise CDF/USD, chaîne de la dépense et
 * visas, demandes de fonds, missions d'inspection, rapprochement bancaire, plan de trésorerie,
 * saisie hors connexion (envoi différé, sans doublon).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-lot2.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-lot2.php\n" );
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
$bareme   = get_option( IGE_FFM_Missions::OPTION_BAREME, null );
wp_set_current_user( 1 );
$ex   = IGE_FFM_Contexte::exercice();
$exo  = (int) $ex['id'];
$auj  = IGE_FFM_Contexte::aujourdhui();
$jour = strtotime( '-1 day' );
while ( ! IGE_FFM_Jours_Feries::ouvre( gmdate( 'Y-m-d', $jour ) ) ) {
	$jour = strtotime( '-1 day', $jour );
}
$hier = gmdate( 'Y-m-d', $jour );
IGE_FFM_Reglages::save( array_merge( array_map( static fn( $v ) => is_bool( $v ) ? ( $v ? '1' : '' ) : (string) $v, (array) $reglages ), array( 'chaine_seuil' => '1000000', 'visa_direction_seuil' => '5000000', 'saisie_usd' => '1', 'rappro_jours' => '10' ) ) );

echo "1. Préparation\n";
$sid    = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'L' . $sfx, 'libelle' => 'Lot 2 ' . $sfx, 'actif' => 1 ) );
$agent  = IGE_FFM_Comptes::create( array( 'user_login' => 'lagent' . $sfx, 'display_name' => 'Agent L2', 'user_email' => 'lagent' . $sfx . '@exemple.cd', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$resp   = IGE_FFM_Comptes::create( array( 'user_login' => 'lresp' . $sfx, 'display_name' => 'Chef L2', 'user_email' => 'lresp' . $sfx . '@exemple.cd', 'profil' => 'ige_responsable', 'service_id' => $sid ) )['user_id'];
$compta = IGE_FFM_Comptes::create( array( 'user_login' => 'lcompta' . $sfx, 'display_name' => 'Comptable L2', 'user_email' => 'lcompta' . $sfx . '@exemple.cd', 'profil' => 'ige_admin' ) )['user_id'];
$ig     = IGE_FFM_Comptes::create( array( 'user_login' => 'lig' . $sfx, 'display_name' => 'Inspecteur Général L2', 'user_email' => 'lig' . $sfx . '@exemple.cd', 'profil' => 'ige_lecteur' ) )['user_id'];
$rubs   = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes  = wp_list_pluck( IGE_FFM_Referentiels::all( 'mode_paiement' ), 'id', 'libelle' );
wp_set_current_user( $agent );
$appro = IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $hier, 'montant' => '60000000', 'description' => 'Approvisionnement', 'beneficiaire' => 'IGE', 'num_piece' => 'AV-L2', 'mode_paiement_id' => $modes['Virement'] ) );
ige_t( is_int( $appro ), 'Service, comptes (agent, chef, A&F, Inspecteur Général) et approvisionnement' );

echo "\n2. Double devise CDF/USD (VA-51)\n";
ige_t( is_wp_error( IGE_FFM_Devises::fixer( $hier, '2850', $agent ) ), 'Un agent ne fixe pas le taux du jour' );
ige_t( is_wp_error( IGE_FFM_Devises::fixer( $hier, '12', $compta ) ) && is_wp_error( IGE_FFM_Devises::fixer( gmdate( 'Y-m-d', strtotime( '+2 days' ) ), '2850', $compta ) ), 'Taux absurde ou date future refusés' );
ige_t( true === IGE_FFM_Devises::fixer( gmdate( 'Y-m-d', strtotime( $hier . ' -40 days' ) ), '2700', $compta ) && true === IGE_FFM_Devises::fixer( $hier, '2 850,50', $compta ), 'Taux fixés (2 700 il y a 40 jours, 2 850,50 hier)' );
$cv = IGE_FFM_Devises::convertir( '120', $hier );
ige_t( ! is_wp_error( $cv ) && 342060 === $cv['cdf'] && 2850.5 === $cv['taux'], '120 USD × 2 850,50 = 342 060 CDF (arrondi au franc)' );
ige_t( is_wp_error( IGE_FFM_Devises::convertir( '120', gmdate( 'Y-m-d', strtotime( $hier . ' -5 days' ) ) ) ), 'Taux de plus de 31 jours : conversion refusée' );
$base = array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'rubrique_id' => (int) $rubs['R01'], 'beneficiaire' => 'Fournisseur L2', 'mode_paiement_id' => $modes['Espèces'] ?? reset( $modes ), 'piece_nature' => 'Facture' );
$u1 = IGE_FFM_Ecritures::saisir( $base + array( 'devise' => 'USD', 'montant_usd' => '120', 'description' => 'Cartouches payées en dollars', 'num_piece' => 'FU-1' ), ige_pdf() );
$e  = is_int( $u1 ) ? IGE_FFM_Ecritures::get( $u1 ) : array();
ige_t( is_int( $u1 ) && 342060 === (int) $e['montant'] && 'USD' === $e['devise_origine'] && 120.0 === (float) $e['montant_origine'], 'Écriture en USD : 342 060 CDF au livre, origine 120 USD conservée' );
ige_t( false !== strpos( IGE_FFM_Devises::mention( $e ), 'USD × 2' ), 'Mention « 120,00 USD × 2 850,50 » affichable' );
$fonctions = get_option( IGE_FFM_Fonctions::OPTION, null );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'devise_usd' => false ) ), 1 );
ige_t( is_wp_error( IGE_FFM_Ecritures::saisir( $base + array( 'devise' => 'USD', 'montant_usd' => '10', 'description' => 'Interdit', 'num_piece' => 'FU-2' ), ige_pdf() ) ), 'Devise USD désactivée (Fonctions du site) : saisie en dollars refusée' );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'devise_usd' => true ) ), 1 );

echo "\n3. Clé unique : pas de doublon (VA-50)\n";
$cle = 'test-' . wp_generate_password( 10, false );
$k1  = IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '45000', 'description' => 'Taxi hors connexion', 'num_piece' => 'HC-1', 'cle_client' => $cle ), ige_pdf() );
$k2  = IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '45000', 'description' => 'Taxi hors connexion', 'num_piece' => 'HC-1', 'cle_client' => $cle ), ige_pdf() );
ige_t( is_int( $k1 ) && $k1 === $k2, 'Même clé envoyée deux fois : une seule écriture' );
ige_t( 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE cle_client = %s', $cle ) ), 'Une seule ligne en base' ); // phpcs:ignore WordPress.DB

echo "\n4. Chaîne de la dépense et visas (VA-10, VA-14)\n";
ige_t( array( 'responsable', 'comptable' ) === IGE_FFM_Visas::circuit( 2000000 ) && array( 'responsable', 'comptable', 'direction' ) === IGE_FFM_Visas::circuit( 6000000 ), 'Circuit : chef puis A&F ; Inspecteur Général au-delà de 5 000 000' );
$petit = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'description' => 'Petit achat', 'num_piece' => 'BC-P', 'montant' => '300000' ) + $base, ige_pdf() );
ige_t( true === IGE_FFM_Chaine::payable( IGE_FFM_Ecritures::get( $petit ) ), 'Sous le seuil : chaîne simplifiée, payable directement' );
$gros = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'description' => 'Réfection du bureau', 'num_piece' => 'G' . $sfx . 'BC', 'montant' => '6500000', 'mode_paiement_id' => $modes['Chèque'] ) + $base, ige_pdf() );
ige_t( is_int( $gros ) && IGE_FFM_Chaine::requise( IGE_FFM_Ecritures::get( $gros ) ), 'Engagement de 6 500 000 : chaîne complète requise' );
ige_t( is_wp_error( IGE_FFM_Ecritures::payer( $gros, $auj, $modes['Chèque'] ) ), 'Paiement refusé avant liquidation et ordonnancement' );
ige_t( is_wp_error( IGE_FFM_Chaine::liquider( $gros, $auj, '', $agent ) ), 'Liquidation sans référence de facture refusée' );
$GLOBALS['ige_mails'] = array();
ige_t( true === IGE_FFM_Chaine::liquider( $gros, $auj, 'FACT-889', $agent ), 'Service fait constaté (facture FACT-889)' );
$m = end( $GLOBALS['ige_mails'] );
ige_t( $m && in_array( 'lresp' . $sfx . '@exemple.cd', (array) $m['to'], true ), 'Le chef de service est prévenu pour son visa' );
ige_t( is_wp_error( IGE_FFM_Chaine::viser( $gros, 'vise', '', $compta ) ), 'A&F ne peut pas viser avant le chef de service' );
ige_t( is_wp_error( IGE_FFM_Chaine::viser( $gros, 'vise', '', $agent ) ), 'Un agent ne vise pas' );
$r = IGE_FFM_Chaine::viser( $gros, 'vise', 'Travaux conformes', $resp );
ige_t( ! is_wp_error( $r ) && 'comptable' === $r['suivante'], 'Visa du chef ; suivant : Administration et Finance' );
ige_t( is_wp_error( IGE_FFM_Chaine::viser( $gros, 'vise', '', $resp ) ), 'Une même personne ne vise pas deux fois' );
ige_t( 1 === count( array_filter( IGE_FFM_Visas::a_viser( $compta ), static fn( $x ) => 'ordonnancement' === $x['objet'] && $x['id'] === $gros ) ), 'Le dossier apparaît dans « Visas » d’A&F' );
$r = IGE_FFM_Chaine::viser( $gros, 'vise', '', $compta );
ige_t( ! is_wp_error( $r ) && 'direction' === $r['suivante'], 'Visa A&F ; suivant : Inspecteur Général (au-delà du seuil)' );
ige_t( is_wp_error( IGE_FFM_Ecritures::payer( $gros, $auj, $modes['Chèque'] ) ), 'Toujours pas payable sans le visa de l’Inspecteur Général' );
$r = IGE_FFM_Chaine::viser( $gros, 'vise', '', $ig );
ige_t( ! is_wp_error( $r ) && $r['complet'] && 'ordonnancee' === IGE_FFM_Ecritures::get( $gros )['etape'], 'Visa de l’Inspecteur Général : dépense ordonnancée' );
ige_t( true === IGE_FFM_Ecritures::payer( $gros, $auj, $modes['Chèque'] ) || is_int( IGE_FFM_Ecritures::get( $gros )['id'] ) && 'payee' === IGE_FFM_Ecritures::get( $gros )['statut'], 'Paiement accepté après ordonnancement' );
$ref = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'description' => 'Mobilier', 'num_piece' => 'BC-R', 'montant' => '1200000' ) + $base, ige_pdf() );
IGE_FFM_Chaine::liquider( $ref, $auj, 'FACT-1', $agent );
ige_t( is_wp_error( IGE_FFM_Chaine::viser( $ref, 'refuse', '', $resp ) ), 'Refus sans motif impossible' );
IGE_FFM_Chaine::viser( $ref, 'refuse', 'Devis non comparé', $resp );
ige_t( 'refusee' === IGE_FFM_Ecritures::get( $ref )['etape'] && is_wp_error( IGE_FFM_Ecritures::payer( $ref, $auj, $modes['Chèque'] ) ), 'Ordonnancement refusé : la dépense ne peut pas être payée' );

echo "\n5. Demandes de fonds (VA-03)\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Demandes::creer( array( 'objet' => 'Fonctionnement', 'montant' => '1000000', 'date_besoin' => $auj, 'rubriques' => array(), 'justification' => 'Achats courants du mois' ), $agent ) ), 'Demande sans rubrique refusée' );
ige_t( is_wp_error( IGE_FFM_Demandes::creer( array( 'objet' => 'Fonctionnement', 'montant' => '1000000', 'date_besoin' => $auj, 'rubriques' => array( $rubs['R01'] ), 'justification' => 'Achats courants du mois' ), $compta ) ), 'Un compte central ne demande pas de fonds' );
$GLOBALS['ige_mails'] = array();
$dm = IGE_FFM_Demandes::creer( array( 'objet' => 'Fonctionnement de fin d’année', 'montant' => '3000000', 'date_besoin' => $auj, 'rubriques' => array( $rubs['R01'], $rubs['R07'] ), 'justification' => 'Fournitures et déplacements du trimestre' ), $agent );
$d  = IGE_FFM_Demandes::get( $dm );
ige_t( is_int( $dm ) && 0 === strpos( $d['reference'], 'DF-L' . $sfx . '-' ) && 'soumise' === $d['statut'] && 'R01, R07' === $d['rubriques'], 'Demande ' . ( $d['reference'] ?? '' ) . ' soumise (rubriques R01, R07)' );
ige_t( (bool) array_filter( $GLOBALS['ige_mails'], static fn( $m ) => in_array( 'lresp' . $sfx . '@exemple.cd', (array) $m['to'], true ) ), 'Le chef de service est prévenu' );
ige_t( is_wp_error( IGE_FFM_Demandes::accorder( $dm, '3000000', '', $compta ) ), 'A&F ne peut pas accorder avant le visa du chef' );
ige_t( ! is_wp_error( IGE_FFM_Demandes::viser( $dm, 'vise', '', $resp ) ), 'Visa du chef de service' );
ige_t( is_wp_error( IGE_FFM_Demandes::viser( $dm, 'vise', '', $compta ) ), 'A&F passe par « accorder » (montant), pas par un simple visa' );
ige_t( is_wp_error( IGE_FFM_Demandes::accorder( $dm, '3500000', '', $compta ) ), 'Accord supérieur au montant demandé refusé' );
ige_t( is_wp_error( IGE_FFM_Demandes::accorder( $dm, '2500000', '', $compta ) ), 'Réduction sans avis refusée' );
$GLOBALS['ige_mails'] = array();
$r = IGE_FFM_Demandes::accorder( $dm, '2 500 000', 'Déplacements reportés en janvier', $compta );
$d = IGE_FFM_Demandes::get( $dm );
ige_t( ! is_wp_error( $r ) && $r['complet'] && 'visee' === $d['statut'] && 2500000 === (int) $d['montant_accorde'], 'Accordée à 2 500 000 (sous le seuil : pas de visa de l’Inspecteur Général)' );
ige_t( (bool) array_filter( $GLOBALS['ige_mails'], static fn( $m ) => in_array( 'lagent' . $sfx . '@exemple.cd', (array) $m['to'], true ) ), 'Le service est prévenu de l’accord' );
$caisse0 = IGE_FFM_Budget::caisse( $exo, $sid );
$av      = IGE_FFM_Demandes::decaisser( $dm, array( 'date_remise' => $auj, 'mode_paiement_id' => $modes['Chèque'] ), $compta );
$d       = IGE_FFM_Demandes::get( $dm );
ige_t( is_int( $av ) && 'decaissee' === $d['statut'] && (int) $d['avance_id'] === $av, 'Décaissement : avance de fonds créée' );
ige_t( IGE_FFM_Budget::caisse( $exo, $sid ) === $caisse0 + 2500000, 'L’avance entre dans la caisse du service (+2 500 000)' );
ige_t( is_wp_error( IGE_FFM_Demandes::decaisser( $dm, array( 'date_remise' => $auj ), $compta ) ), 'Pas de second décaissement' );
$dg = IGE_FFM_Demandes::creer( array( 'objet' => 'Équipement informatique', 'montant' => '8000000', 'date_besoin' => $auj, 'rubriques' => array( $rubs['R01'] ), 'justification' => 'Remplacement de trois ordinateurs' ), $agent );
IGE_FFM_Demandes::viser( $dg, 'vise', '', $resp );
$r = IGE_FFM_Demandes::accorder( $dg, '8000000', '', $compta );
ige_t( ! is_wp_error( $r ) && 'direction' === $r['suivante'] && 'soumise' === IGE_FFM_Demandes::get( $dg )['statut'], '8 000 000 : après A&F, visa de l’Inspecteur Général attendu' );
IGE_FFM_Demandes::viser( $dg, 'refuse', 'Attendre le budget d’investissement', $ig );
ige_t( 'refusee' === IGE_FFM_Demandes::get( $dg )['statut'], 'Refus de l’Inspecteur Général : demande refusée' );
$da = IGE_FFM_Demandes::creer( array( 'objet' => 'Carburant', 'montant' => '400000', 'date_besoin' => $auj, 'rubriques' => array( $rubs['R01'] ), 'justification' => 'Carburant du véhicule de service' ), $agent );
ige_t( true === IGE_FFM_Demandes::annuler( $da, $agent ) && 'annulee' === IGE_FFM_Demandes::get( $da )['statut'], 'Annulation par le service avant tout visa' );

echo "\n6. Missions d’inspection (VA-40)\n";
delete_option( IGE_FFM_Missions::OPTION_BAREME );
$b = IGE_FFM_Missions::bareme();
ige_t( ! $b['officiel'] && 150000 === $b['categories']['inspecteur']['indemnite'], 'Barème d’exemple tant que le barème officiel n’est pas saisi' );
$dep = gmdate( 'Y-m-d', strtotime( $hier . ' -4 days' ) );
$c   = IGE_FFM_Missions::calculer( array( array( 'nom' => 'Inspecteur A', 'categorie' => 'inspecteur' ), array( 'nom' => 'Chauffeur B', 'categorie' => 'appui' ), array( 'nom' => '' ) ), 'province', $dep, $hier );
ige_t( ! is_wp_error( $c ) && 5 === $c['jours'] && 2 === count( $c['membres'] ), 'Calcul : 5 jours, 2 membres (ligne vide ignorée)' );
ige_t( ! is_wp_error( $c ) && ( 5 * 150000 + 5 * 100000 + 2 * 350000 + 300000 ) === $c['total'], 'Total = indemnités 1 250 000 + transport 700 000 + divers 300 000 = 2 250 000' );
ige_t( is_wp_error( IGE_FFM_Missions::calculer( array( array( 'nom' => 'X', 'categorie' => 'inspecteur' ) ), 'province', $hier, $dep ) ), 'Retour avant le départ refusé' );
ige_t( is_wp_error( IGE_FFM_Missions::enregistrer_bareme( array(), $agent ) ), 'Un agent ne modifie pas le barème' );
$in = array( 'indemnite' => array( 'ig' => '220000', 'inspecteur' => '160000', 'appui' => '100000' ), 'hebergement' => array( 'ig' => '0', 'inspecteur' => '0', 'appui' => '0' ), 'transport' => array( 'kinshasa' => '50000', 'province' => '350000' ), 'frais_divers' => '300000' );
ige_t( true === IGE_FFM_Missions::enregistrer_bareme( $in, $compta ) && IGE_FFM_Missions::bareme()['officiel'], 'Barème enregistré par A&F : devient officiel' );
$mi = IGE_FFM_Missions::creer( array( 'objet' => 'Inspection des écoles conventionnées', 'destination' => 'Kikwit, Kwilu', 'zone' => 'province', 'date_depart' => $dep, 'date_retour' => $hier, 'transport' => 'Route', 'membres' => array( array( 'nom' => 'Inspecteur A', 'fonction' => 'Inspecteur principal', 'categorie' => 'inspecteur' ), array( 'nom' => 'Chauffeur B', 'categorie' => 'appui' ) ) ), $agent );
$m  = IGE_FFM_Missions::get( $mi );
ige_t( is_int( $mi ) && 0 === strpos( $m['numero'], 'OM-' . $ex['annee'] . '-' ) && 2300000 === (int) $m['montant'] && 2 === count( IGE_FFM_Missions::membres( $mi ) ), 'Ordre de mission ' . ( $m['numero'] ?? '' ) . ' : 2 300 000 au nouveau barème' );
ige_t( is_wp_error( IGE_FFM_Missions::payer( $mi, array( 'date' => $auj ), $agent ) ), 'Paiement refusé avant les visas' );
IGE_FFM_Missions::viser( $mi, 'vise', '', $resp );
IGE_FFM_Missions::viser( $mi, 'vise', '', $compta );
$m   = IGE_FFM_Missions::get( $mi );
$doc = IGE_FFM_Documents::pour_objet( 'ordre_mission', $mi );
ige_t( 'approuvee' === $m['statut'] && $doc && 0 === strpos( $doc['reference'], 'IGE-OM' ), 'Visas réunis : approuvée, ordre de mission officiel ' . ( $doc['reference'] ?? '' ) . ' émis' );
ige_t( $doc && false !== strpos( $doc['contenu'], 'Inspecteur A' ) && false !== strpos( $doc['contenu'], 'Émargement' ), 'L’ordre de mission contient les membres et l’état de paiement à émarger' );
$pdf = $doc ? IGE_FFM_Pdf::document( $doc ) : '';
ige_t( 0 === strpos( $pdf, '%PDF' ), 'Ordre de mission en PDF (avec code QR de vérification)' );
ige_t( is_wp_error( IGE_FFM_Missions::payer( $mi, array( 'date' => $auj ), $compta ) ), 'Le paiement est fait par le service, pas par le central' );
ige_t( is_wp_error( IGE_FFM_Missions::payer( $mi, array( 'date' => $auj, 'mode_paiement_id' => $modes['Espèces'] ), $agent ) ), 'Plafond des espèces appliqué au paiement d’une mission' );
$pe = IGE_FFM_Missions::payer( $mi, array( 'date' => $auj, 'mode_paiement_id' => $modes['Chèque'] ), $agent );
$e  = is_int( $pe ) ? IGE_FFM_Ecritures::get( $pe ) : array();
if ( is_wp_error( $pe ) ) {
	echo '    (' . $pe->get_error_message() . ")\n";
}
ige_t( is_int( $pe ) && 'payee' === $e['statut'] && (int) $e['mission_id'] === $mi && (int) $e['rubrique_id'] === (int) $rubs['R07'] && 2300000 === (int) $e['montant'], 'Paiement : sortie R07 de 2 300 000 liée à la mission' );
$pj = is_int( $pe ) ? IGE_FFM_Pieces::liste( 'ecriture', $pe ) : array();
ige_t( 1 === count( $pj ) && 'Ordre de mission' === $pj[0]['nature'] && '.pdf' === substr( $pj[0]['nom_original'], -4 ), 'L’ordre de mission est joint en pièce justificative' );
ige_t( false === IGE_FFM_Chaine::requise( $e ), 'Une mission visée n’a pas besoin d’un second ordonnancement' );
ige_t( 'Rapport manquant' === IGE_FFM_Missions::etape( IGE_FFM_Missions::get( $mi ) )[0], 'Mission terminée sans rapport : « Rapport manquant »' );
ige_t( is_wp_error( IGE_FFM_Missions::liquider( $mi, '2100000', 'Un jour de moins', $agent ) ), 'Liquidation refusée sans rapport' );
ige_t( is_int( IGE_FFM_Missions::deposer( $mi, ige_pdf(), 'Rapport de mission', $agent ) ), 'Rapport de mission déposé' );
ige_t( is_wp_error( IGE_FFM_Missions::liquider( $mi, '2100000', '', $agent ) ), 'Écart sans explication refusé' );
$caisse0 = IGE_FFM_Budget::caisse( $exo, $sid );
ige_t( true === IGE_FFM_Missions::liquider( $mi, '2 040 000', 'Retour anticipé d’un jour', $agent ), 'Liquidation : coût réel 2 040 000' );
$rev = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'ecriture' ) . " WHERE mission_id = %d AND type = 'entree'", $mi ), ARRAY_A ); // phpcs:ignore WordPress.DB
ige_t( $rev && 260000 === (int) $rev['montant'] && IGE_FFM_Budget::caisse( $exo, $sid ) === $caisse0 + 260000, 'Reversement de 260 000 inscrit en entrée dans la caisse' );
$k = IGE_FFM_Missions::chiffres( $exo, $sid );
ige_t( 2040000 === $k['cout'] && 1020000 === $k['par_insp'], 'Chiffres : coût réel 2 040 000, 1 020 000 par membre' );
$mx = IGE_FFM_Missions::creer( array( 'objet' => 'Mission annulée', 'destination' => 'Matadi', 'zone' => 'province', 'date_depart' => $auj, 'date_retour' => $auj, 'membres' => array( array( 'nom' => 'Inspecteur C', 'categorie' => 'inspecteur' ) ) ), $agent );
ige_t( true === IGE_FFM_Missions::annuler( $mx, 'Report de la mission', $agent ) && 'annulee' === IGE_FFM_Missions::get( $mx )['statut'], 'Annulation avant paiement' );

echo "\n7. Rapprochement bancaire (VA-06)\n";
ige_t( is_wp_error( IGE_FFM_Banque::creer_compte( array( 'banque' => 'X', 'intitule' => 'Y', 'date_solde' => $hier ), $agent ) ), 'Un agent ne déclare pas de compte' );
$date_solde = gmdate( 'Y-m-d', strtotime( $hier . ' -2 days' ) );
$cid        = IGE_FFM_Banque::creer_compte( array( 'banque' => 'Banque L2 ' . $sfx, 'intitule' => 'IGE Fonctionnement', 'numero' => '0001 2345 6789', 'solde_initial' => '100 000 000', 'date_solde' => $date_solde, 'principal' => 1 ), $compta );
ige_t( is_int( $cid ) && '•••• 6789' === IGE_FFM_Banque::masquer( IGE_FFM_Banque::compte( $cid )['numero'] ), 'Compte principal déclaré ; numéro masqué' );
$ops = IGE_FFM_Banque::operations( IGE_FFM_Banque::compte( $cid ) );
$og  = array_values( array_filter( $ops, static fn( $o ) => 'ecriture' === $o['type'] && $o['id'] === $gros ) );
$oa  = array_values( array_filter( $ops, static fn( $o ) => 'avance' === $o['type'] && $o['id'] === $av ) );
ige_t( $og && -6500000 === $og[0]['montant'] && $oa && -2500000 === $oa[0]['montant'], 'Le chèque de 6 500 000 et l’avance par chèque de 2 500 000 sont attendus en banque' );
$csv = wp_tempnam( 'releve.csv' );
file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
	$csv,
	"Date;Libellé;Référence;Débit;Crédit\n"
	. gmdate( 'd/m/Y', strtotime( $auj ) ) . ";CHQ G" . $sfx . "BC REFECTION;G" . $sfx . "BC;6 500 000,00;\n"
	. gmdate( 'd/m/Y', strtotime( $auj ) ) . ";FRAIS TENUE DE COMPTE;;12 500,00;\n"
	. gmdate( 'd/m/Y', strtotime( $auj ) ) . ";VIREMENT RECU TRESOR;VT" . $sfx . ";;9 876 543,00\n"
);
$im = IGE_FFM_Banque::importer( $cid, $csv, 'releve.csv', '', $compta );
ige_t( ! is_wp_error( $im ) && 3 === $im['nouvelles'] && $im['rapprochees'] >= 1, 'Relevé CSV importé : 3 lignes, ' . ( is_array( $im ) ? $im['rapprochees'] : 0 ) . ' rapprochée(s) automatiquement' );
$l_g = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . " WHERE compte_id = %d AND reference = %s", $cid, 'G' . $sfx . 'BC' ), ARRAY_A ); // phpcs:ignore WordPress.DB
ige_t( $l_g && 'ecriture' === $l_g['rapproche_type'] && (int) $l_g['rapproche_id'] === $gros, 'Le chèque est rapproché de sa dépense (référence reconnue)' );
$im2 = IGE_FFM_Banque::importer( $cid, $csv, 'releve.csv', '', $compta );
ige_t( ! is_wp_error( $im2 ) && 0 === $im2['nouvelles'] && 3 === $im2['doublons'], 'Réimport du même relevé : aucune ligne en double' );
$l_f = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . " WHERE compte_id = %d AND libelle LIKE %s", $cid, 'FRAIS%' ), ARRAY_A ); // phpcs:ignore WordPress.DB
ige_t( $l_f && -12500 === (int) $l_f['montant'], 'Débit lu comme montant négatif (−12 500)' );
ige_t( is_int( IGE_FFM_Banque::mouvement_depuis_ligne( (int) $l_f['id'], 'frais', $compta ) ) && 'mouvement' === IGE_FFM_Banque::ligne( (int) $l_f['id'] )['rapproche_type'], 'Frais bancaires enregistrés depuis le relevé et rapprochés' );
$l_v = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'releve_ligne' ) . " WHERE compte_id = %d AND reference = %s", $cid, 'VT' . $sfx ), ARRAY_A ); // phpcs:ignore WordPress.DB
$mv  = IGE_FFM_Banque::mouvement( array( 'compte_id' => $cid, 'date' => $auj, 'libelle' => 'Dotation du Trésor', 'reference' => 'VT-77', 'montant' => 9876000, 'nature' => 'dotation' ), $compta );
ige_t( is_wp_error( IGE_FFM_Banque::rapprocher( (int) $l_v['id'], 'mouvement:' . $mv, $compta ) ), 'Rapprochement manuel refusé si les montants diffèrent' );
$mv2 = IGE_FFM_Banque::mouvement( array( 'compte_id' => $cid, 'date' => $auj, 'libelle' => 'Dotation du Trésor (corrigée)', 'reference' => 'VT-77', 'montant' => 9876543, 'nature' => 'dotation' ), $compta );
ige_t( true === IGE_FFM_Banque::rapprocher( (int) $l_v['id'], 'mouvement:' . $mv2, $compta ), 'Rapprochement manuel de la dotation' );
ige_t( true === IGE_FFM_Banque::expliquer( 'mouvement', $mv, 'Saisie erronée, à supprimer', $compta ), 'Suspens expliqué' );
$et = IGE_FFM_Banque::etat( $cid );
ige_t( 3 === $et['nb_rapprochees'] && ! $et['banque_libres'], 'Toutes les lignes du relevé sont rapprochées' );
$libres = array_filter( $et['compta_libres'], static fn( $o ) => 'avance' === $o['type'] && $o['id'] === $av );
ige_t( (bool) $libres, 'L’avance par chèque reste en suspens (chèque non encaissé)' );
$sf = (int) $et['solde_compta'] + array_sum( array_map( static fn( $o ) => -1 * $o['montant'], $et['compta_libres'] ) );
$wpdb->update( IGE_FFM_DB::t( 'releve' ), array( 'solde_final' => $sf ), array( 'id' => (int) $et['releve']['id'] ) ); // phpcs:ignore WordPress.DB
ige_t( 0 === IGE_FFM_Banque::etat( $cid )['residu'], 'Solde du relevé expliqué par les suspens : écart inexpliqué nul' );
$wpdb->update( IGE_FFM_DB::t( 'releve' ), array( 'solde_final' => $sf + 1000 ), array( 'id' => (int) $et['releve']['id'] ) ); // phpcs:ignore WordPress.DB
ige_t( 1000 === IGE_FFM_Banque::etat( $cid )['residu'], 'Écart inexpliqué de 1 000 détecté' );
ige_t( 0 === strpos( IGE_FFM_Banque::etat_pdf( $cid ), '%PDF' ), 'État de rapprochement en PDF' );
ige_t( true === IGE_FFM_Banque::defaire( (int) $l_v['id'], $compta ) && '' === IGE_FFM_Banque::ligne( (int) $l_v['id'] )['rapproche_type'], 'Rapprochement défait' );
$xl = new IGE_FFM_Classeur();
$xl->feuille( 'Relevé', array( 'colonnes' => array( array( 'Date opération', 'date', 14 ), array( 'Libellé', 'texte', 40 ), array( 'Montant', 'montant', 14 ) ), 'lignes' => array( array( $auj, 'AGIOS', -3000 ) ) ) );
$xf = wp_tempnam( 'r.xlsx' );
$xl->enregistrer( $xf );
$lu = IGE_FFM_Banque::lire_releve( $xf, 'releve.xlsx' );
ige_t( ! is_wp_error( $lu ) && 1 === count( $lu ) && -3000 === $lu[0]['montant'] && $auj === $lu[0]['date'], 'Relevé Excel lu (colonne Montant signée)' );
ige_t( is_wp_error( IGE_FFM_Banque::lire_releve( $csv, 'releve.pdf' ) ), 'Format non pris en charge refusé' );

echo "\n8. Plan de trésorerie (VA-07)\n";
ige_t( is_wp_error( IGE_FFM_Tresorerie::ajouter( array( 'mois' => 12, 'libelle' => 'Dotation', 'montant' => '1000' ), $agent ) ), 'Un agent ne saisit pas de fonds attendus' );
$mois = (int) substr( $auj, 5, 2 );
$pv   = IGE_FFM_Tresorerie::ajouter( array( 'mois' => $mois, 'libelle' => 'Dotation L2 ' . $sfx, 'montant' => '7 000 000' ), $compta );
$p    = IGE_FFM_Tresorerie::plan( $exo );
ige_t( is_int( $pv ) && isset( $p['mois'][ $mois ] ) && $p['mois'][ $mois ]['fonds'] >= 7000000, 'Fonds attendus intégrés au mois en cours' );
ige_t( null !== $p['disponible']['banque'] && $p['disponible']['total'] === $p['disponible']['caisses'] + $p['disponible']['banque'], 'Disponible : caisses + banque principale' );
$x = $p['mois'][ $mois ];
ige_t( $x['fin'] === $x['debut'] + $x['fonds'] - $x['demandes'] - $x['missions'] - $x['engagements'] - $x['recurrent'], 'Solde de fin = début + fonds − besoins' );
$dt = IGE_FFM_Demandes::creer( array( 'objet' => 'Besoin exceptionnel', 'montant' => (string) ( $p['disponible']['total'] + $x['fonds'] + 1000000000 ), 'date_besoin' => $auj, 'rubriques' => array( $rubs['R01'] ), 'justification' => 'Test de tension de trésorerie' ), $agent );
$p2 = IGE_FFM_Tresorerie::plan( $exo );
ige_t( $p2['tension'] && $p2['tension']['mois'] === $mois, 'Demande dépassant le disponible : tension signalée ce mois-ci' );
IGE_FFM_Demandes::annuler( $dt, $agent );
$sp = IGE_FFM_Tresorerie::supprimer( $pv, $compta );
ige_t( true === $sp, 'Fonds attendus retirés' . ( is_wp_error( $sp ) ? ' — ' . $sp->get_error_message() : '' ) );

echo "\n9. Envoi différé (API de la saisie hors connexion)\n";
wp_set_current_user( $agent );
$cle = 'hc-' . wp_generate_password( 12, false );
$req = new WP_REST_Request( 'POST', '/ige-ffm/v1/ecritures' );
$req->set_body_params( array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'rubrique_id' => (string) $rubs['R01'], 'montant' => '35000', 'description' => 'Photocopies (hors connexion)', 'fournisseur_id' => 'autre', 'beneficiaire' => 'Copy-Center', 'num_piece' => 'HC-9', 'mode_paiement_id' => (string) ( $modes['Espèces'] ?? reset( $modes ) ), 'piece_nature' => 'Facture', 'cle_client' => $cle ) );
$req->set_file_params( array( 'piece' => ige_pdf() ) );
IGE_FFM_Pieces::autoriser_genere( true );
$r1 = rest_do_request( $req );
$req->set_file_params( array( 'piece' => ige_pdf() ) );
$r2 = rest_do_request( $req );
IGE_FFM_Pieces::autoriser_genere( false );
ige_t( 201 === $r1->get_status() && 201 === $r2->get_status() && $r1->get_data()['id'] === $r2->get_data()['id'], 'Écriture envoyée (201) ; renvoi identique : même écriture, pas de doublon' );
$req2 = new WP_REST_Request( 'POST', '/ige-ffm/v1/ecritures' );
$req2->set_body_params( array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'montant' => '35000', 'description' => 'Sans rubrique', 'cle_client' => 'hc-' . wp_generate_password( 12, false ) ) );
$r3 = rest_do_request( $req2 );
ige_t( 422 === $r3->get_status() && '' !== $r3->get_data()['message'], 'Écriture invalide : refusée (422) avec le motif' );
$req3 = new WP_REST_Request( 'POST', '/ige-ffm/v1/ecritures' );
$req3->set_body_params( array( 'type' => 'sortie', 'montant' => '1' ) );
ige_t( 400 === rest_do_request( $req3 )->get_status(), 'Sans clé : refusée (400)' );
wp_set_current_user( $ig );
ige_t( 403 === rest_do_request( $req )->get_status(), 'Profil sans droit de saisie : refusé (403)' );
wp_set_current_user( 0 );
ige_t( in_array( rest_do_request( $req )->get_status(), array( 401, 403 ), true ), 'Sans connexion : refusé' );
wp_set_current_user( 1 );

echo "\n10. Journal\n";
$acts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ); // phpcs:ignore WordPress.DB
$att  = array( 'taux_du_jour', 'liquidation', 'visa', 'refus_visa', 'ordonnancement', 'demande_fonds', 'decaissement_demande', 'annulation_demande', 'ordre_mission', 'paiement_mission', 'liquidation_mission', 'bareme_missions', 'compte_bancaire', 'import_releve', 'rapprochement', 'mouvement_bancaire', 'suspens_bancaire', 'prevision_tresorerie' );
$manq = array_diff( $att, $acts );
ige_t( ! $manq, 'Toutes les opérations du lot 2 sont tracées' . ( $manq ? ' — manque : ' . implode( ', ', $manq ) : '' ) );
$lib = IGE_FFM_Audit::actions();
ige_t( ! array_diff( $att, array_keys( $lib ) ), 'Libellés en français pour le journal' );

update_option( 'ige_ffm_reglages', $reglages, false );
if ( null === $bareme ) {
	delete_option( IGE_FFM_Missions::OPTION_BAREME );
} else {
	update_option( IGE_FFM_Missions::OPTION_BAREME, $bareme, false );
}
$t = $GLOBALS['ige_t'];
echo "\nRésultat : {$t['ok']} réussis, {$t['ko']} échoués\n";
