<?php
/**
 * Tests automatiques de l'étape 1 (socle).
 *
 * À lancer sur un site de TEST uniquement (crée des comptes et des éléments de référentiel) :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-socle.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-socle.php\n" );
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

echo "\n1. Schéma et données initiales\n";
foreach ( IGE_FFM_DB::tables() as $t ) {
	$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( $t ) . ' WHERE 1=%d', 1 ) ) || 0 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( $t ) );
	ige_t( $exists && '' === $wpdb->last_error, "table $t" );
}
$services = IGE_FFM_Referentiels::all( 'service' );
ige_t( count( $services ) >= 26, '26 services initiaux' );
ige_t( ! in_array( 'Pécule de transport', wp_list_pluck( $services, 'code' ), true ), "« Pécule de transport » n'est pas un service" );
ige_t( count( IGE_FFM_Referentiels::all( 'categorie' ) ) >= 8, '8 catégories (liste du fichier service)' );
$r02 = $wpdb->get_row( 'SELECT * FROM ' . IGE_FFM_DB::t( 'rubrique' ) . " WHERE code = 'R02'", ARRAY_A );
ige_t( 'Consommables informatiques' === IGE_FFM_Referentiels::categorie_libelle( (int) $r02['categorie_id'] ), 'R02 reclassée en « Consommables informatiques »' );
$ex = IGE_FFM_Referentiels::exercice_courant();
ige_t( $ex && 2026 === (int) $ex['annee'] && 0 === (int) $ex['budget_obligatoire'], 'Exercice 2026 ouvert, budget facultatif' );

echo "\n2. Rôles et capacités\n";
ige_t( ! get_role( 'ige_agent' )->has_cap( 'ige_administrer' ), "Agent : pas d'administration" );
ige_t( ! get_role( 'ige_agent' )->has_cap( 'ige_gerer_budget' ), "Agent : pas de saisie du budget" );
ige_t( get_role( 'ige_responsable' )->has_cap( 'ige_gerer_budget' ), 'Responsable : saisie du budget' );
ige_t( ! get_role( 'ige_lecteur' )->has_cap( 'ige_saisir' ), 'Lecteur : aucune saisie' );
ige_t( ! get_role( 'ige_admin' )->has_cap( 'ige_saisir' ), 'Admin : ne saisit pas les écritures des services (séparation des fonctions)' );
ige_t( ! get_role( 'ige_admin' )->has_cap( 'manage_options' ), "Admin de l'application : pas administrateur du site" );
ige_t( get_role( 'administrator' )->has_cap( 'ige_voir_audit' ), "Administrateur du site : accès au journal d'audit" );

echo "\n3. Référentiels\n";
$dup = IGE_FFM_Referentiels::save( 'service', array( 'code' => '8000 IG', 'libelle' => 'Doublon', 'actif' => 1 ) );
ige_t( is_wp_error( $dup ), 'Code de service en double refusé' );
$bad = IGE_FFM_Referentiels::save( 'rubrique', array( 'code' => 'RX' . $sfx, 'libelle' => 'Test', 'categorie_id' => 999999, 'actif' => 1 ) );
ige_t( is_wp_error( $bad ), 'Rubrique sans catégorie valide refusée' );
$sid = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'T' . $sfx, 'libelle' => 'Service de test', 'actif' => 1 ) );
ige_t( is_int( $sid ) && $sid > 0, 'Création d’un service' );
IGE_FFM_Referentiels::save( 'service', array( 'code' => 'T' . $sfx, 'libelle' => 'Service de test modifié', 'actif' => 1 ), $sid );
$last = $wpdb->get_row( 'SELECT * FROM ' . IGE_FFM_DB::t( 'audit' ) . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
ige_t( 'modification_referentiel' === $last['action'] && (string) $sid === $last['objet_id'], 'Modification inscrite au journal (avant → après)' );
ige_t( false !== strpos( IGE_FFM_Audit::resume( $last ), 'Service de test modifié' ), 'Résumé lisible du changement' );

echo "\n4. Comptes\n";
$no_service = IGE_FFM_Comptes::create( array( 'user_login' => 'agent' . $sfx, 'display_name' => 'Agent test', 'profil' => 'ige_agent' ) );
ige_t( is_wp_error( $no_service ), 'Agent sans service refusé' );
$created = IGE_FFM_Comptes::create( array( 'user_login' => 'agent' . $sfx, 'display_name' => 'Agent test', 'profil' => 'ige_agent', 'service_id' => $sid ) );
ige_t( is_array( $created ), 'Création d’un compte Agent' );
$uid  = $created['user_id'];
$user = get_userdata( $uid );
ige_t( in_array( 'ige_agent', $user->roles, true ), 'Rôle WordPress ige_agent attribué' );
ige_t( 1 === (int) IGE_FFM_Comptes::get( $uid )['mdp_a_changer'], 'Mot de passe à changer à la première connexion' );
ige_t( 'T' . $sfx === get_user_meta( $uid, 'ige_service', true ), 'Code du service transmis au thème' );
ige_t( (bool) preg_match( '/[A-Z]/', $created['password'] ) && (bool) preg_match( '/[a-z]/', $created['password'] ) && (bool) preg_match( '/\d/', $created['password'] ) && strlen( $created['password'] ) >= 12, 'Mot de passe provisoire robuste (12 caractères, majuscule, minuscule, chiffre)' );
ige_t( is_wp_error( IGE_FFM_Comptes::create( array( 'user_login' => 'agent' . $sfx, 'display_name' => 'X', 'profil' => 'ige_lecteur' ) ) ), 'Identifiant en double refusé' );
ige_t( is_wp_error( IGE_FFM_Comptes::create( array( 'user_login' => 'x' . $sfx, 'display_name' => 'X', 'profil' => 'administrator' ) ) ), 'Impossible de créer un administrateur du site' );
ige_t( ! IGE_FFM_Comptes::modifiable( 1 ), "Un administrateur du site n'est pas modifiable depuis l'application" );
$upd = IGE_FFM_Comptes::update( $uid, array( 'display_name' => 'Agent test', 'profil' => 'ige_responsable', 'service_id' => $sid ) );
ige_t( true === $upd && in_array( 'ige_responsable', get_userdata( $uid )->roles, true ), 'Changement de profil vers Responsable' );

echo "\n5. Connexion et sécurité\n";
$auth = wp_authenticate( 'agent' . $sfx, $created['password'] );
ige_t( $auth instanceof WP_User, 'Connexion avec le mot de passe provisoire' );
ige_t( is_wp_error( IGE_FFM_Comptes::check_strength( 'court', $user ) ), 'Mot de passe trop court refusé' );
ige_t( is_wp_error( IGE_FFM_Comptes::check_strength( 'sansmajuscule123', $user ) ), 'Mot de passe sans majuscule refusé' );
ige_t( is_wp_error( IGE_FFM_Comptes::check_strength( 'Agent' . $sfx . 'Xyz9', get_userdata( $uid ) ) ), "Mot de passe contenant l'identifiant refusé" );
ige_t( is_wp_error( IGE_FFM_Comptes::check_strength( $created['password'], get_userdata( $uid ) ) ), 'Réutiliser le mot de passe provisoire refusé' );
ige_t( true === IGE_FFM_Comptes::check_strength( 'Kinshasa2026Ok', get_userdata( $uid ) ), 'Mot de passe conforme accepté' );

for ( $i = 0; $i < 5; $i++ ) {
	wp_authenticate( 'agent' . $sfx, 'mauvais-mot-de-passe' );
}
$locked = wp_authenticate( 'agent' . $sfx, $created['password'] );
ige_t( is_wp_error( $locked ) && 'ige_verrouille' === $locked->get_error_code(), 'Compte verrouillé après 5 échecs, même avec le bon mot de passe' );
$new_pwd = IGE_FFM_Comptes::reset_password( $uid );
ige_t( wp_authenticate( 'agent' . $sfx, $new_pwd ) instanceof WP_User, 'Réinitialisation : déverrouille et donne un nouveau mot de passe provisoire' );

IGE_FFM_Comptes::set_actif( $uid, false );
$inactive = wp_authenticate( 'agent' . $sfx, $new_pwd );
ige_t( is_wp_error( $inactive ) && 'ige_inactif' === $inactive->get_error_code(), 'Compte désactivé : connexion refusée' );
IGE_FFM_Comptes::set_actif( $uid, true );
ige_t( wp_authenticate( 'agent' . $sfx, $new_pwd ) instanceof WP_User, 'Compte réactivé : connexion possible' );

echo "\n6. Règles de gestion\n";
$reglages_avant = get_option( 'ige_ffm_reglages' );
$saved = IGE_FFM_Reglages::save( array( 'seuil_justification' => '150', 'mdp_longueur' => '4', 'inactivite_minutes' => '1', 'plafond_especes' => '3 000 000', 'bloquer_caisse_negative' => '1', 'bloquer_depassement' => '1', 'piece_obligatoire' => '1', 'contrepassation_rejet' => '1' ) );
ige_t( 100 === $saved['seuil_justification'], 'Seuil de justification borné à 100 %' );
ige_t( 10 === $saved['mdp_longueur'], 'Longueur de mot de passe jamais inférieure à 10' );
ige_t( 5 === $saved['inactivite_minutes'], 'Inactivité jamais inférieure à 5 minutes' );
ige_t( 3000000 === $saved['plafond_especes'], 'Montant saisi avec espaces compris (3 000 000)' );
update_option( 'ige_ffm_reglages', $reglages_avant, false );
ige_t( 80 === (int) IGE_FFM_Reglages::get( 'seuil_justification' ), 'Réglages rétablis' );

echo "\n7. Journal d'audit infalsifiable\n";
$v = IGE_FFM_Audit::verify();
ige_t( $v['ok'] && $v['lignes'] > 10, sprintf( 'Chaîne intègre (%d lignes)', $v['lignes'] ) );
$row = $wpdb->get_row( 'SELECT id, apres FROM ' . IGE_FFM_DB::t( 'audit' ) . ' ORDER BY id ASC LIMIT 1 OFFSET 2', ARRAY_A );
$wpdb->update( IGE_FFM_DB::t( 'audit' ), array( 'apres' => '{"falsifie":true}' ), array( 'id' => $row['id'] ) );
$v2 = IGE_FFM_Audit::verify();
ige_t( ! $v2['ok'] && (int) $row['id'] === $v2['rupture'], 'Modification directe dans la base détectée (ligne ' . $row['id'] . ')' );
$wpdb->update( IGE_FFM_DB::t( 'audit' ), array( 'apres' => $row['apres'] ), array( 'id' => $row['id'] ) );
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne de nouveau intègre après restauration' );

echo "\n8. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );
$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $uid ) );
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $sid ) );
ige_t( true, 'Données de test supprimées (le journal d’audit, lui, est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
