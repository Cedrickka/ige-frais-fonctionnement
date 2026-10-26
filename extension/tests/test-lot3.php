<?php
/**
 * Tests automatiques de la version 2.1 : fonctions du site (modules activables, menus, devise USD),
 * parc automobile et gestion du charroi, stocks de fournitures, score de risque, comparaison des
 * services, nomenclature budgétaire, rapport hebdomadaire.
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-lot3.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-lot3.php\n" );
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

/**
 * Message d'une erreur (ou vide).
 *
 * @param mixed $r Résultat.
 * @return string
 */
function ige_err( $r ) {
	return is_wp_error( $r ) ? $r->get_error_message() : '';
}

global $wpdb;
$sfx       = substr( (string) time(), -5 );
$fonctions = get_option( IGE_FFM_Fonctions::OPTION, null );
$reglages  = get_option( 'ige_ffm_reglages' );
$hebdo     = get_option( IGE_FFM_Analyses::OPTION_HEBDO, null );
wp_set_current_user( 1 );
$ex   = IGE_FFM_Contexte::exercice();
$exo  = (int) $ex['id'];
$auj  = IGE_FFM_Contexte::aujourdhui();
$hier = gmdate( 'Y-m-d', strtotime( $auj . ' -1 day' ) );
$demain = gmdate( 'Y-m-d', strtotime( $auj . ' +1 day' ) );
delete_option( IGE_FFM_Fonctions::OPTION );

echo "1. Préparation\n";
$sid    = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'V' . $sfx, 'libelle' => 'Lot 3 ' . $sfx, 'effectif' => '10', 'actif' => 1 ) );
$sid2   = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'W' . $sfx, 'libelle' => 'Lot 3 bis ' . $sfx, 'effectif' => '40', 'actif' => 1 ) );
$agent  = IGE_FFM_Comptes::create( array( 'user_login' => 'vagent' . $sfx, 'display_name' => 'Agent V', 'user_email' => 'vagent' . $sfx . '@exemple.cd', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$agent2 = IGE_FFM_Comptes::create( array( 'user_login' => 'wagent' . $sfx, 'display_name' => 'Agent W', 'profil' => 'ige_agent', 'service_id' => $sid2 ) )['user_id'];
$compta = IGE_FFM_Comptes::create( array( 'user_login' => 'vcompta' . $sfx, 'display_name' => 'Logistique V', 'user_email' => 'vcompta' . $sfx . '@exemple.cd', 'profil' => 'ige_admin' ) )['user_id'];
$lect   = IGE_FFM_Comptes::create( array( 'user_login' => 'vlect' . $sfx, 'display_name' => 'Direction V', 'profil' => 'ige_lecteur' ) )['user_id'];
ige_t( is_int( $sid ) && 10 === (int) IGE_FFM_Referentiels::get( 'service', $sid )['effectif'], 'Service avec effectif (10 agents)' );
ige_t( is_wp_error( IGE_FFM_Referentiels::save( 'service', array( 'code' => 'X' . $sfx, 'libelle' => 'Trop', 'effectif' => '99999', 'actif' => 1 ) ) ), 'Effectif absurde refusé' );
ige_t( user_can( $compta, 'ige_logistique' ) && ! user_can( $agent, 'ige_logistique' ) && ! user_can( $lect, 'ige_logistique' ), 'Droit « logistique » : Administration et Finance seulement' );

echo "\n2. Fonctions du site\n";
$e0 = IGE_FFM_Fonctions::etats();
ige_t( 13 === count( IGE_FFM_Fonctions::modules() ) && false === $e0['stocks'] && 1 === count( array_filter( $e0, static fn( $v ) => ! $v ) ), '13 fonctions activables : toutes actives par défaut sauf les stocks de fournitures' );
ige_t( IGE_FFM_Fonctions::page_inactive( 'stocks' ) && ! IGE_FFM_Fonctions::page_inactive( 'inventaire-des-services' ), 'Stocks désactivés par défaut, inventaire des services actif' );
update_option( IGE_FFM_Fonctions::OPTION, array( 'stocks' => true ) );
ige_t( IGE_FFM_Fonctions::active( 'stocks' ), 'L’administrateur peut activer les stocks' );
ige_t( 'parc' === IGE_FFM_Fonctions::module_de_page( 'vehicules' ) && 'rapprochement' === IGE_FFM_Fonctions::module_de_action( 'banque_releve' ) && 'missions' === IGE_FFM_Fonctions::module_de_action( 'mission_payer' ) && null === IGE_FFM_Fonctions::module_de_action( 'ecriture' ), 'Pages et formulaires rattachés à leur fonction ; le socle n’en dépend pas' );
$chg = IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'parc' => false, 'devise_usd' => false, 'chaine' => false ) ), $compta );
ige_t( 3 === count( $chg ) && ! IGE_FFM_Fonctions::active( 'parc' ) && IGE_FFM_Fonctions::page_inactive( 'parc-automobile' ) && ! IGE_FFM_Fonctions::page_inactive( 'livre-de-caisse' ), 'Parc, dollar et chaîne complète désactivés' );
$audit = $wpdb->get_var( $wpdb->prepare( 'SELECT apres FROM ' . IGE_FFM_DB::t( 'audit' ) . " WHERE action = 'fonctions_du_site' AND user_id = %d ORDER BY id DESC LIMIT 1", $compta ) ); // phpcs:ignore WordPress.DB
ige_t( $audit && false !== strpos( $audit, 'Parc automobile' ), 'Changement inscrit au journal' );
$menu = wp_insert_term( 'Menu test ' . $sfx, 'nav_menu' );
$mid  = (int) $menu['term_id'];
$grp  = wp_update_nav_menu_item( $mid, 0, array( 'menu-item-title' => 'Logistique', 'menu-item-url' => '#', 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $mid, 0, array( 'menu-item-title' => 'Parc', 'menu-item-object' => 'page', 'menu-item-object-id' => get_page_by_path( 'parc-automobile' )->ID, 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $grp, 'menu-item-status' => 'publish' ) );
wp_update_nav_menu_item( $mid, 0, array( 'menu-item-title' => 'Livre', 'menu-item-object' => 'page', 'menu-item-object-id' => get_page_by_path( 'livre-de-caisse' )->ID, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
$titres = wp_list_pluck( wp_get_nav_menu_items( $mid ), 'title' );
ige_t( array( 'Livre' ) === array_values( $titres ), 'Menu : la page Parc et la rubrique « Logistique » devenue vide disparaissent' );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'parc' => true ) ), $compta );
ige_t( 3 === count( wp_get_nav_menu_items( $mid ) ), 'Réactivée : le menu revient' );
wp_delete_nav_menu( $mid );
wp_set_current_user( $agent );
ige_t( ! IGE_FFM_Devises::active() && is_wp_error( IGE_FFM_Ecritures::saisir( array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $auj, 'rubrique_id' => 1, 'devise' => 'USD', 'montant_usd' => '10', 'description' => 'x', 'num_piece' => 'x' ) ) ), 'Dollar désactivé : saisie en USD refusée' );
$html = IGE_FFM_Ecran_Arrete::billetage( array() );
ige_t( false === strpos( $html, 'name="usd[' ) && false !== strpos( $html, 'Autres disponibilités' ), 'Dollar désactivé : plus de billetage en dollars dans l’arrêté' );
$fake = array( 'type' => 'sortie', 'montant' => 9000000, 'ecriture_origine_id' => null, 'mission_id' => null, 'etape' => '' );
ige_t( false === IGE_FFM_Chaine::requise( $fake ) && true === IGE_FFM_Chaine::payable( $fake ), 'Chaîne complète désactivée : engagement payable directement' );
IGE_FFM_Fonctions::enregistrer( array_fill_keys( array_keys( IGE_FFM_Fonctions::modules() ), true ), 1 );
ige_t( IGE_FFM_Devises::active() && true === IGE_FFM_Chaine::requise( $fake ), 'Tout réactivé : dollar et chaîne de nouveau en vigueur' );

echo "\n3. Parc : véhicules, chauffeurs, documents\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => 'AB' . $sfx, 'marque' => 'Toyota' ) ) ), 'Un agent ne crée pas de véhicule' );
wp_set_current_user( $compta );
ige_t( is_wp_error( IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => '#', 'marque' => 'Toyota' ) ) ), 'Immatriculation invalide refusée' );
$v1 = IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => $sfx . 'AA01', 'marque' => 'Toyota', 'modele' => 'Land Cruiser', 'annee' => '2019', 'categorie' => '4x4', 'places' => '7', 'reservoir' => '90', 'conso_ref' => '12', 'km_initial' => '50 000', 'service_id' => $sid ) );
$v2 = IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => $sfx . 'BB01', 'marque' => 'Nissan', 'modele' => 'Hardbody', 'categorie' => 'pickup', 'places' => '2', 'conso_ref' => '10', 'km_initial' => '120000' ) );
ige_t( is_int( $v1 ) && 50000 === (int) IGE_FFM_Parc::vehicule( $v1 )['km_actuel'], 'Véhicule ajouté, compteur à 50 000 km' );
ige_t( is_wp_error( IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => strtolower( $sfx . 'aa01' ), 'marque' => 'Autre' ) ) ), 'Immatriculation en double refusée (majuscules normalisées)' );
ige_t( is_wp_error( IGE_FFM_Parc::enregistrer_chauffeur( array( 'nom' => 'Sans permis' ) ) ), 'Chauffeur sans permis refusé' );
$c1 = IGE_FFM_Parc::enregistrer_chauffeur( array( 'nom' => 'Mbala ' . $sfx, 'telephone' => '+243 81 000 0000', 'permis_numero' => 'P-1', 'permis_categories' => 'B,C', 'permis_expire' => gmdate( 'Y-m-d', strtotime( '+2 years' ) ) ) );
$c2 = IGE_FFM_Parc::enregistrer_chauffeur( array( 'nom' => 'Expiré ' . $sfx, 'permis_numero' => 'P-2', 'permis_expire' => $hier ) );
ige_t( is_int( $c1 ) && is_int( $c2 ), 'Chauffeurs enregistrés' );
$etat = IGE_FFM_Parc::etat_documents( $v1 );
ige_t( 'manquant' === $etat['assurance'][1] && 3 === count( $etat ), 'Trois documents obligatoires, absents au départ' );
ige_t( is_wp_error( IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v1, 'type' => 'assurance', 'date_debut' => $auj ) ) ), 'Assurance sans date de fin refusée' );
$d1 = IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v1, 'type' => 'assurance', 'reference' => 'POL-1', 'organisme' => 'SONAS', 'date_debut' => $ex['date_debut'], 'date_fin' => gmdate( 'Y-m-d', strtotime( $auj . ' +10 days' ) ), 'montant' => '1 200 000' ), ige_pdf() );
IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v1, 'type' => 'controle_technique', 'date_fin' => gmdate( 'Y-m-d', strtotime( '+6 months' ) ) ) );
IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v1, 'type' => 'vignette', 'date_fin' => gmdate( 'Y-m-d', strtotime( '+6 months' ) ), 'montant' => '150000' ) );
$etat = IGE_FFM_Parc::etat_documents( $v1 );
ige_t( is_int( $d1 ) && 'bientot' === $etat['assurance'][1] && 'ok' === $etat['controle_technique'][1], 'Assurance à renouveler (échéance dans 10 jours), contrôle technique à jour' );
ige_t( (bool) IGE_FFM_Parc::documents( $v1 )[0]['piece_id'] || (bool) array_filter( IGE_FFM_Parc::documents( $v1 ), static fn( $d ) => $d['piece_id'] ), 'Scan de la police joint' );
IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v2, 'type' => 'assurance', 'date_fin' => $hier ) );
IGE_FFM_Parc::ajouter_document( array( 'vehicule_id' => $v2, 'type' => 'controle_technique', 'date_fin' => gmdate( 'Y-m-d', strtotime( '+6 months' ) ) ) );
ige_t( 'expire' === IGE_FFM_Parc::etat_documents( $v2 )['assurance'][1], 'Assurance du second véhicule expirée' );

echo "\n4. Demandes de véhicule et ordres de route\n";
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Parc::demander( array( 'objet' => 'Réunion', 'destination' => 'Gombe', 'depart' => $demain . 'T08:00', 'retour' => $demain . 'T12:00' ) ) ), 'Le profil Direction ne demande pas de véhicule' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Parc::demander( array( 'objet' => 'Réunion au Ministère', 'destination' => 'Gombe', 'depart' => $demain . 'T12:00', 'retour' => $demain . 'T08:00' ) ) ), 'Retour avant le départ refusé' );
$GLOBALS['ige_mails'] = array();
$dp = IGE_FFM_Parc::demander( array( 'objet' => 'Inspection des écoles de Kinshasa-Est', 'destination' => 'Masina, Kimbanseke', 'depart' => $demain . 'T08:00', 'retour' => $demain . 'T17:00', 'passagers' => '4' ) );
$x  = IGE_FFM_Parc::deplacement( $dp );
ige_t( is_int( $dp ) && 0 === strpos( $x['numero'], 'OR-' . $ex['annee'] . '-' ) && 'demande' === $x['statut'] && (int) $x['service_id'] === $sid, 'Demande ' . ( $x['numero'] ?? '' ) . ' enregistrée par le service' );
ige_t( (bool) array_filter( $GLOBALS['ige_mails'], static fn( $m ) => in_array( 'vcompta' . $sfx . '@exemple.cd', (array) $m['to'], true ) ), 'La gestion du parc est prévenue par e-mail' );
ige_t( is_wp_error( IGE_FFM_Parc::attribuer( $dp, $v1, $c1, $agent ) ), 'Un agent n’attribue pas de véhicule' );
wp_set_current_user( $compta );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::attribuer( $dp, $v2, $c1 ) ), 'places' ) || false !== strpos( ige_err( IGE_FFM_Parc::attribuer( $dp, $v2, $c1 ) ), 'Assurance' ), 'Pick-up 2 places refusé pour 4 passagers' );
$x2 = IGE_FFM_Parc::demander( array( 'service_id' => $sid2, 'objet' => 'Livraison de plis', 'destination' => 'Lingwala', 'depart' => $demain . 'T09:00', 'retour' => $demain . 'T10:00', 'passagers' => '1' ) );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::attribuer( $x2, $v2, $c1 ) ), 'Assurance' ), 'Assurance expirée : le véhicule ne peut pas circuler' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::attribuer( $dp, $v1, $c2 ) ), 'permis' ), 'Permis expiré : chauffeur refusé' );
$GLOBALS['ige_mails'] = array();
ige_t( true === IGE_FFM_Parc::attribuer( $dp, $v1, $c1 ) && 'attribue' === IGE_FFM_Parc::deplacement( $dp )['statut'], 'Véhicule et chauffeur attribués' );
ige_t( (bool) array_filter( $GLOBALS['ige_mails'], static fn( $m ) => in_array( 'vagent' . $sfx . '@exemple.cd', (array) $m['to'], true ) ), 'Le demandeur est prévenu' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::attribuer( $x2, $v1, 0 ) ), 'déjà retenu' ), 'Même véhicule sur une période qui chevauche : refusé' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::clore( $x2, 'refuse', '' ) ), 'motif' ) && true === IGE_FFM_Parc::clore( $x2, 'refuse', 'Aucun véhicule libre ce jour-là' ), 'Refus motivé' );
wp_set_current_user( $agent2 );
ige_t( is_wp_error( IGE_FFM_Parc::depart( $dp, '50100', $agent2 ) ), 'Un autre service ne déclare pas le départ' );
wp_set_current_user( $agent );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::depart( $dp, '49000' ) ), 'reculer' ), 'Compteur inférieur au dernier relevé refusé' );
$r = IGE_FFM_Parc::depart( $dp, '50 120' );
ige_t( is_array( $r ) && 120 === $r['ecart'] && 'en_cours' === IGE_FFM_Parc::deplacement( $dp )['statut'], 'Départ : 120 km non justifiés depuis le dernier relevé signalés' );
ige_t( 'En route' === IGE_FFM_Parc::situation( IGE_FFM_Parc::vehicule( $v1 ) )[0], 'Le véhicule apparaît « en route »' );
ige_t( is_wp_error( IGE_FFM_Parc::retour( $dp, '50 100' ) ) && is_wp_error( IGE_FFM_Parc::retour( $dp, '60 200' ) ), 'Retour : compteur inférieur au départ ou plus de 5 000 km refusés' );
$GLOBALS['ige_mails'] = array();
$r = IGE_FFM_Parc::retour( $dp, '50 265', 'Rétroviseur gauche cassé' );
ige_t( is_array( $r ) && 145 === $r['distance'] && 50265 === (int) IGE_FFM_Parc::vehicule( $v1 )['km_actuel'], 'Retour : 145 km parcourus, compteur mis à jour' );
ige_t( (bool) array_filter( $GLOBALS['ige_mails'], static fn( $m ) => false !== strpos( $m['message'], 'Rétroviseur' ) ), 'Observation au retour transmise à la gestion du parc' );

echo "\n5. Carburant et consommation\n";
wp_set_current_user( $agent2 );
ige_t( is_wp_error( IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '40', 'montant' => '120000', 'km' => '50300', 'plein' => 1 ) ) ), 'Un service ne saisit pas de plein pour un véhicule d’un autre service' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '120', 'montant' => '360000', 'km' => '50300', 'plein' => 1 ) ) ), 'Plus de litres que le réservoir (90 L) refusé' );
$p1 = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '60', 'montant' => '180 000', 'km' => '50 300', 'plein' => 1, 'mode' => 'carte', 'bon_numero' => '' ) );
ige_t( is_array( $p1 ) && null === $p1['conso'] && '' === $p1['anomalie'], 'Premier plein complet : pas encore de consommation' );
IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '20', 'montant' => '60000', 'km' => '50 450', 'plein' => 0 ) );
$p3 = IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '40', 'montant' => '120000', 'km' => '50 800', 'plein' => 1 ) );
ige_t( is_array( $p3 ) && 12.0 === (float) $p3['conso'] && '' === $p3['anomalie'], '60 L sur 500 km = 12 L/100 km, conforme à la référence' );
$p4 = IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '40', 'montant' => '120000', 'km' => '51 000', 'plein' => 1 ) );
ige_t( is_array( $p4 ) && 20.0 === (float) $p4['conso'] && false !== strpos( $p4['anomalie'], 'Consommation' ), '40 L sur 200 km = 20 L/100 km : consommation anormale signalée' );
ige_t( is_wp_error( IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '50 900', 'plein' => 0 ) ) ), 'Compteur inférieur au plein précédent refusé' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'mode' => 'carte', 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '51 100', 'ecriture' => 'INCONNU-1' ) ) ), 'introuvable' ), 'Lien vers une écriture inexistante refusé' );

echo "\n6. Entretien, réparations, sinistres\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Parc::intervention( array( 'vehicule_id' => $v1, 'nature' => 'entretien', 'objet' => 'Vidange', 'date_realisee' => $auj ) ) ), 'Un agent n’enregistre pas d’intervention' );
wp_set_current_user( $compta );
$i1 = IGE_FFM_Parc::intervention( array( 'vehicule_id' => $v1, 'nature' => 'entretien', 'objet' => 'Vidange et filtres', 'date_realisee' => $auj, 'km' => '51 000', 'montant' => '250 000', 'garage' => 'Garage Central', 'prochaine_km' => '55 000' ), ige_pdf() );
ige_t( is_int( $i1 ), 'Vidange réalisée, prochaine à 55 000 km' );
ige_t( ! array_filter( IGE_FFM_Parc::echeances( IGE_FFM_Parc::vehicule( $v1 ) ), static fn( $e ) => $e['du'] ), 'Pas encore à échéance (51 000 km)' );
$wpdb->update( IGE_FFM_DB::t( 'vehicule' ), array( 'km_actuel' => 54700 ), array( 'id' => $v1 ) ); // phpcs:ignore WordPress.DB
ige_t( (bool) array_filter( IGE_FFM_Parc::echeances( IGE_FFM_Parc::vehicule( $v1 ) ), static fn( $e ) => $e['du'] && 'kilométrage atteint' === $e['raison'] ), 'À 54 700 km : vidange signalée à faire' );
ige_t( is_wp_error( IGE_FFM_Parc::intervention( array( 'vehicule_id' => $v2, 'nature' => 'sinistre', 'objet' => 'Accrochage', 'date_realisee' => $auj, 'description' => 'court' ) ) ), 'Sinistre sans description détaillée refusé' );
$i2 = IGE_FFM_Parc::intervention( array( 'vehicule_id' => $v2, 'nature' => 'reparation', 'objet' => 'Boîte de vitesses', 'date_prevue' => $demain, 'immobilise' => 1, 'garage' => 'Garage Kintambo' ) );
ige_t( is_int( $i2 ) && 'immobilise' === IGE_FFM_Parc::vehicule( $v2 )['statut'], 'Réparation planifiée avec immobilisation : véhicule immobilisé' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::attribuer( IGE_FFM_Parc::demander( array( 'service_id' => $sid2, 'objet' => 'Course urgente', 'destination' => 'Limete', 'depart' => $demain . 'T13:00', 'retour' => $demain . 'T14:00' ) ), $v2, 0 ) ), 'mmobilisé' ), 'Véhicule immobilisé : attribution refusée' );
ige_t( true === IGE_FFM_Parc::realiser( $i2, array( 'date_realisee' => $auj, 'montant' => '900 000', 'km' => '120 010' ) ) && 'disponible' === IGE_FFM_Parc::vehicule( $v2 )['statut'], 'Réparation réalisée : véhicule remis en service' );
$i3 = IGE_FFM_Parc::intervention( array( 'vehicule_id' => $v2, 'nature' => 'sinistre', 'objet' => 'Accrochage rond-point Victoire', 'date_realisee' => $auj, 'montant' => '300000', 'description' => 'Accrochage avec un taxi, constat amiable signé, déclaration SONAS le jour même.' ) );
ige_t( is_int( $i3 ), 'Sinistre enregistré avec circonstances' );

echo "\n7. Coûts et alertes du parc\n";
$co = IGE_FFM_Parc::couts( $exo );
ige_t( 480000 === $co[ $v1 ]['carburant'] && 250000 === $co[ $v1 ]['entretien'] && 1350000 === $co[ $v1 ]['documents'] && 2080000 === $co[ $v1 ]['total'], 'Coût du véhicule 1 : carburant 480 000 + entretien 250 000 + documents 1 350 000' );
ige_t( 900000 === $co[ $v2 ]['entretien'] && 300000 === $co[ $v2 ]['sinistres'], 'Véhicule 2 : réparation et sinistre séparés' );
ige_t( $co[ $v1 ]['km'] >= 880 && null !== $co[ $v1 ]['cout_km'] && 16.0 === (float) $co[ $v1 ]['conso'], 'Kilomètres de l’exercice, coût au km et consommation moyenne (16 L/100)' );
$al = wp_list_pluck( IGE_FFM_Parc::alertes(), 1 );
$cherche = static fn( $t ) => (bool) array_filter( $al, static fn( $a ) => false !== strpos( $a, $t ) );
ige_t( $cherche( $sfx . 'BB01 : Assurance expiré' ) && $cherche( $sfx . 'AA01 : Assurance à renouveler' ) && $cherche( 'Permis de Expiré ' . $sfx ), 'Alertes : assurance expirée, à renouveler, permis expiré' );
ige_t( $cherche( 'Vidange et filtres à faire' ) && $cherche( 'anomalie' ), 'Alertes : entretien dû, plein anormal' );
$tb = IGE_FFM_Consolidation::alertes( $exo );
ige_t( (bool) array_filter( $tb, static fn( $a ) => false !== strpos( $a[1], 'Assurance expiré' ) ), 'Les urgences du parc remontent au tableau de bord' );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'parc' => false ) ), 1 );
ige_t( ! array_filter( IGE_FFM_Consolidation::alertes( $exo ), static fn( $a ) => false !== strpos( $a[1], 'Assurance' ) ), 'Parc désactivé : plus d’alerte au tableau de bord' );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'parc' => true ) ), 1 );

echo "\n8. Stocks de fournitures\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Stocks::enregistrer_article( array( 'code' => 'X', 'designation' => 'X' ) ) ), 'Un agent ne gère pas le stock' );
wp_set_current_user( $compta );
$a1 = IGE_FFM_Stocks::enregistrer_article( array( 'code' => 'PAP' . $sfx, 'designation' => 'Papier A4, rame', 'unite' => 'rame', 'categorie' => 'Papeterie', 'seuil' => '20' ) );
$a2 = IGE_FFM_Stocks::enregistrer_article( array( 'code' => 'TON' . $sfx, 'designation' => 'Toner HP 85A', 'unite' => 'unité', 'categorie' => 'Informatique', 'seuil' => '2' ) );
ige_t( is_int( $a1 ) && is_int( $a2 ) && is_wp_error( IGE_FFM_Stocks::enregistrer_article( array( 'code' => 'pap' . $sfx, 'designation' => 'Doublon' ) ) ), 'Articles créés ; code en double refusé' );
$b = IGE_FFM_Stocks::bon( array( 'type' => 'entree', 'date' => $auj, 'reference' => 'BL-1' ), array( array( 'article_id' => $a1, 'quantite' => '50', 'prix_unitaire' => '10 000' ), array( 'article_id' => $a2, 'quantite' => '5', 'prix_unitaire' => '95000' ) ) );
ige_t( is_array( $b ) && 2 === count( $b ) && 50.0 === (float) IGE_FFM_Stocks::article( $a1 )['stock'] && 10000.0 === (float) IGE_FFM_Stocks::article( $a1 )['cmup'], 'Bon d’entrée : 50 rames à 10 000, 5 toners' );
IGE_FFM_Stocks::bon( array( 'type' => 'entree', 'date' => $auj ), array( array( 'article_id' => $a1, 'quantite' => '50', 'prix_unitaire' => '12000' ) ) );
ige_t( 11000.0 === (float) IGE_FFM_Stocks::article( $a1 )['cmup'] && 100.0 === (float) IGE_FFM_Stocks::article( $a1 )['stock'], 'Coût moyen pondéré : (50 × 10 000 + 50 × 12 000) ÷ 100 = 11 000' );
$r = IGE_FFM_Stocks::bon( array( 'type' => 'sortie', 'date' => $auj, 'service_id' => $sid ), array( array( 'article_id' => $a1, 'quantite' => '30' ), array( 'article_id' => $a2, 'quantite' => '9' ) ) );
ige_t( is_wp_error( $r ) && 100.0 === (float) IGE_FFM_Stocks::article( $a1 )['stock'], 'Sortie au-delà du stock (9 toners pour 5) : bon entier refusé, rien n’est sorti' );
ige_t( is_wp_error( IGE_FFM_Stocks::bon( array( 'type' => 'sortie', 'date' => $auj ), array( array( 'article_id' => $a1, 'quantite' => '1' ) ) ) ), 'Sortie sans bureau destinataire refusée' );
$r = IGE_FFM_Stocks::bon( array( 'type' => 'sortie', 'date' => $auj, 'service_id' => $sid, 'beneficiaire' => 'Secrétariat' ), array( array( 'article_id' => $a1, 'quantite' => '30' ), array( 'article_id' => $a2, 'quantite' => '4' ) ) );
IGE_FFM_Stocks::bon( array( 'type' => 'sortie', 'date' => $auj, 'service_id' => $sid2 ), array( array( 'article_id' => $a1, 'quantite' => '60' ) ) );
ige_t( is_array( $r ) && 10.0 === (float) IGE_FFM_Stocks::article( $a1 )['stock'] && 1.0 === (float) IGE_FFM_Stocks::article( $a2 )['stock'], 'Sorties vers deux bureaux : stock restant 10 rames, 1 toner' );
$re = array_column( IGE_FFM_Stocks::a_reapprovisionner(), 'id' );
ige_t( in_array( (string) $a1, array_map( 'strval', $re ), true ) && in_array( (string) $a2, array_map( 'strval', $re ), true ), 'Les deux articles passent sous le seuil de réapprovisionnement' );
ige_t( (bool) array_filter( IGE_FFM_Consolidation::alertes( $exo ), static fn( $a ) => false !== strpos( $a[1], 'sous le seuil' ) ), 'Alerte de réapprovisionnement au tableau de bord' );
$c = IGE_FFM_Stocks::consommation( $ex['date_debut'], $auj );
ige_t( 30 * 11000 + 4 * 95000 === $c['bureaux'][ $sid ] && 60 * 11000 === $c['bureaux'][ $sid2 ], 'Consommation par bureau valorisée au coût moyen' );
$inv = IGE_FFM_Stocks::inventaire( $auj, array( $a1 => '8', $a2 => '1' ), 'Comptage trimestriel' );
ige_t( is_array( $inv ) && 1 === $inv['ecarts'] && -22000 === $inv['valeur'] && 8.0 === (float) IGE_FFM_Stocks::article( $a1 )['stock'], 'Inventaire : 2 rames manquantes ajustées (−22 000)' );
ige_t( 'ajustement' === IGE_FFM_Stocks::mouvements( array( 'article_id' => $a1, 'limite' => 1 ) )[0]['type'], 'L’écart devient un mouvement « ajustement » motivé' );
ige_t( (int) round( 8 * 11000 + 1 * 95000 ) <= IGE_FFM_Stocks::valeur(), 'Valeur du stock calculée' );

echo "\n9. Score de risque et comparaison des services\n";
wp_set_current_user( $agent );
$modes = wp_list_pluck( IGE_FFM_Referentiels::all( 'mode_paiement' ), 'id', 'libelle' );
$rubs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $auj, 'montant' => '5000000', 'description' => 'Approvisionnement', 'beneficiaire' => 'IGE', 'num_piece' => 'AV', 'mode_paiement_id' => $modes['Virement'] ) );
$ids = array();
for ( $i = 1; $i <= 4; $i++ ) {
	$ids[] = IGE_FFM_Ecritures::saisir( array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $auj, 'rubrique_id' => (int) $rubs['R01'], 'montant' => (string) ( 100000 * $i ), 'description' => 'Fournitures ' . $i, 'beneficiaire' => 'Papeterie', 'num_piece' => 'F' . $i, 'mode_paiement_id' => $modes['Espèces'], 'piece_nature' => 'Facture' ), ige_pdf() );
}
IGE_FFM_Transmission::transmettre( $sid, $agent );
wp_set_current_user( $compta );
IGE_FFM_Verification::rejeter( array( $ids[0] ), 'Facture illisible', $compta );
IGE_FFM_Verification::rejeter( array( $ids[1] ), 'Facture au nom d’un autre service', $compta );
IGE_FFM_Verification::valider( array( $ids[2], $ids[3] ), $compta );
$rk = array_values( array_filter( IGE_FFM_Analyses::risques( $exo ), static fn( $x ) => (int) $x['service']['id'] === $sid ) )[0];
ige_t( 100 === (int) $rk['facteurs']['rejets'][0] && false !== strpos( $rk['facteurs']['rejets'][1], '2 sur 4' ), 'Rejets : 2 sur 4 → risque maximal sur ce facteur' );
ige_t( $rk['score'] >= 20 && count( $rk['facteurs'] ) === count( IGE_FFM_Analyses::facteurs() ), 'Score pondéré calculé (' . $rk['score'] . '/100)' );
ige_t( 'Élevé' === IGE_FFM_Analyses::niveau( 70 )[0] && 'Moyen' === IGE_FFM_Analyses::niveau( 40 )[0] && 'Faible' === IGE_FFM_Analyses::niveau( 10 )[0], 'Niveaux : faible, moyen, élevé' );
$cp = IGE_FFM_Analyses::comparaison( $exo );
ige_t( 700000 === $cp['lignes'][ $sid ]['depense'] && 70000 === $cp['lignes'][ $sid ]['par_agent'], 'Comparaison : 700 000 validés pour 10 agents = 70 000 par agent' );
ige_t( null !== $cp['mediane'] && null !== $cp['lignes'][ $sid ]['indice'], 'Indice par rapport à la médiane des services' );
ige_t( 3.0 === IGE_FFM_Analyses::mediane( array( 5, 1, 3 ) ) && 2.5 === IGE_FFM_Analyses::mediane( array( 1, 2, 3, 4 ) ), 'Médiane correcte (nombre pair et impair)' );
$cr = IGE_FFM_Analyses::comparaison( $exo, (int) $rubs['R02'] );
ige_t( 0 === $cr['lignes'][ $sid ]['depense'], 'Comparaison limitée à une rubrique' );

echo "\n10. Nomenclature budgétaire de l’État\n";
wp_set_current_user( 1 );
$n1 = IGE_FFM_Referentiels::save( 'nomenclature', array( 'code' => '6.2.' . $sfx, 'libelle' => 'Fournitures et petits matériels de bureau', 'actif' => 1 ) );
ige_t( is_int( $n1 ), 'Ligne de nomenclature créée' );
$r01 = IGE_FFM_Referentiels::get( 'rubrique', (int) $rubs['R01'] );
$avant_nom = $r01['nomenclature'];
ige_t( is_wp_error( IGE_FFM_Referentiels::save( 'rubrique', array_merge( $r01, array( 'nomenclature' => 'INEXISTANTE' ) ), (int) $r01['id'] ) ), 'Rattachement à une ligne inexistante refusé' );
ige_t( (int) $r01['id'] === IGE_FFM_Referentiels::save( 'rubrique', array_merge( $r01, array( 'nomenclature' => '6.2.' . $sfx ) ), (int) $r01['id'] ) && 1 === IGE_FFM_Referentiels::usage( 'nomenclature', $n1 ), 'Rubrique R01 rattachée à la ligne' );
$pn = array_values( array_filter( IGE_FFM_Analyses::par_nomenclature( $exo ), static fn( $l ) => '6.2.' . $sfx === $l['code'] ) );
ige_t( $pn && in_array( 'R01', $pn[0]['rubriques'], true ) && $pn[0]['consomme'] >= 700000, 'Consolidation par ligne de nomenclature (dépenses validées)' );
ige_t( isset( IGE_FFM_Ecran_Consolidation::vues()['nomenclature'] ) && 'Ligne' === IGE_FFM_Ecran_Consolidation::tableau( 'nomenclature' )['entetes'][0], 'Vue « Par ligne de la nomenclature » dans la consolidation et l’export' );
IGE_FFM_Referentiels::save( 'rubrique', array_merge( $r01, array( 'nomenclature' => $avant_nom && in_array( $avant_nom, array_column( IGE_FFM_Referentiels::all( 'nomenclature' ), 'code' ), true ) ? $avant_nom : '6.2.' . $sfx ) ), (int) $r01['id'] );

echo "\n11. Rapport hebdomadaire\n";
ige_t( is_wp_error( IGE_FFM_Analyses::enregistrer_hebdo( array( 'actif' => 1, 'copie' => 'pas-une-adresse' ), 1 ) ), 'Adresse en copie invalide refusée' );
ige_t( true === IGE_FFM_Analyses::enregistrer_hebdo( array( 'actif' => 1, 'jour' => (int) wp_date( 'N' ), 'heure' => 5, 'copie' => 'secretariat' . $sfx . '@exemple.cd' ), 1 ), 'Réglage : chaque semaine, aujourd’hui, à partir de 5 h' );
$dest = IGE_FFM_Analyses::destinataires();
ige_t( in_array( 'vcompta' . $sfx . '@exemple.cd', $dest, true ) && in_array( 'secretariat' . $sfx . '@exemple.cd', $dest, true ) && ! in_array( 'vagent' . $sfx . '@exemple.cd', $dest, true ), 'Destinataires : Administration et Finance et la copie, pas les agents' );
$c = IGE_FFM_Analyses::contenu_hebdo();
ige_t( false !== strpos( $c['texte'], 'À VÉRIFIER' ) && false !== strpos( $c['texte'], 'ALERTES' ) && false !== strpos( $c['sujet'], 'à vérifier' ), 'Contenu : écritures à vérifier, alertes' );
$GLOBALS['ige_mails'] = array();
$o = get_option( IGE_FFM_Analyses::OPTION_HEBDO );
$o['dernier'] = '';
update_option( IGE_FFM_Analyses::OPTION_HEBDO, $o );
IGE_FFM_Analyses::quotidien();
$n1m = count( $GLOBALS['ige_mails'] );
IGE_FFM_Analyses::quotidien();
ige_t( 1 === $n1m && 1 === count( $GLOBALS['ige_mails'] ), 'Envoi au créneau prévu, une seule fois par semaine' );
ige_t( wp_date( 'o-W' ) === IGE_FFM_Analyses::reglages_hebdo()['dernier'], 'Semaine d’envoi mémorisée' );
$GLOBALS['ige_mails'] = array();
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'rapport_hebdo' => false ) ), 1 );
$o['dernier'] = '';
update_option( IGE_FFM_Analyses::OPTION_HEBDO, $o );
IGE_FFM_Analyses::quotidien();
ige_t( 0 === count( $GLOBALS['ige_mails'] ), 'Fonction désactivée : aucun envoi' );
ige_t( (bool) wp_next_scheduled( 'ige_ffm_horaire' ), 'Tâche planifiée installée (toutes les heures)' );

echo "\n12. Journal et sécurité\n";
$acts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ); // phpcs:ignore WordPress.DB
$att  = array( 'fonctions_du_site', 'creation_vehicule', 'chauffeur', 'document_vehicule', 'demande_vehicule', 'attribution_vehicule', 'refus_vehicule', 'depart_vehicule', 'retour_vehicule', 'carburant', 'entretien_vehicule', 'sinistre_vehicule', 'statut_vehicule', 'article_stock', 'mouvement_stock', 'inventaire_stock', 'reglage_rapport_hebdo', 'rapport_hebdo' );
$manq = array_diff( $att, $acts );
ige_t( ! $manq, 'Toutes les opérations sont tracées' . ( $manq ? ' — manque : ' . implode( ', ', $manq ) : '' ) );
ige_t( ! array_diff( $att, array_keys( IGE_FFM_Audit::actions() ) ), 'Libellés en français pour le journal' . ( array_diff( $att, array_keys( IGE_FFM_Audit::actions() ) ) ? ' — manque : ' . implode( ', ', array_diff( $att, array_keys( IGE_FFM_Audit::actions() ) ) ) : '' ) );
global $wp_filter;
$sans = array();
foreach ( array_keys( $wp_filter ) as $h ) {
	if ( preg_match( '/^admin_post_ige_ffm_(parc|stock|hebdo|fonctions)/', $h ) ) {
		foreach ( $wp_filter[ $h ]->callbacks as $cbs ) {
			foreach ( $cbs as $cb ) {
				$rf  = new ReflectionMethod( $cb['function'][0], $cb['function'][1] );
				$src = implode( '', array_slice( file( $rf->getFileName() ), $rf->getStartLine() - 1, $rf->getEndLine() - $rf->getStartLine() + 1 ) );
				if ( ( false === strpos( $src, 'ige_ffm_check_form' ) && false === strpos( $src, 'wp_verify_nonce' ) ) || ! preg_match( '/ige_ffm_require/', $src ) ) {
					$sans[] = $h;
				}
			}
		}
	}
}
ige_t( ! $sans, 'Formulaires du lot : jeton et droit vérifiés' . ( $sans ? ' — ' . implode( ', ', $sans ) : '' ) );

if ( null === $fonctions ) {
	delete_option( IGE_FFM_Fonctions::OPTION );
} else {
	update_option( IGE_FFM_Fonctions::OPTION, $fonctions );
}
update_option( 'ige_ffm_reglages', $reglages, false );
if ( null === $hebdo ) {
	delete_option( IGE_FFM_Analyses::OPTION_HEBDO );
} else {
	update_option( IGE_FFM_Analyses::OPTION_HEBDO, $hebdo, false );
}
echo "\n13. Liens de menu des nouvelles pages\n";
$loc = get_nav_menu_locations();
$pv  = get_page_by_path( 'vehicules' );
if ( ! empty( $loc['menu-service'] ) && $pv ) {
	$liens = static function () use ( $loc, $pv ) {
		return array_values( array_filter( (array) wp_get_nav_menu_items( (int) $loc['menu-service'] ), static fn( $it ) => 'page' === $it->object && (int) $it->object_id === (int) $pv->ID ) );
	};
	foreach ( $liens() as $it ) {
		wp_delete_post( $it->ID, true );
	}
	delete_option( 'ige_ffm_menus_ok' );
	IGE_FFM_Installer::assurer_menus();
	ige_t( 1 === count( $liens() ), 'Lien « Véhicules » manquant recréé dans le menu des services' );
	delete_option( 'ige_ffm_menus_ok' );
	IGE_FFM_Installer::assurer_menus();
	ige_t( 1 === count( $liens() ) && IGE_FFM_VERSION === get_option( 'ige_ffm_menus_ok' ), 'Aucun doublon si la vérification repasse' );
} else {
	ige_t( true, 'Menus du thème absents sur ce site de test : vérification sans objet' );
}
$t = $GLOBALS['ige_t'];
echo "\nRésultat : {$t['ok']} réussis, {$t['ko']} échoués\n";
