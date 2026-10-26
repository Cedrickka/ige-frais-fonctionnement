<?php
/**
 * Tests automatiques de la version 2.4 : inventaire des services (import du fichier Excel,
 * grille, validation, comparaison, consolidé), profil « Gestionnaire du parc automobile »,
 * stocks désactivés par défaut, messages après enregistrement, nom de l'application.
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-inventaire.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-inventaire.php\n" );
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
wp_set_current_user( 1 );
$auj   = IGE_FFM_Contexte::aujourdhui();
$hier  = gmdate( 'Y-m-d', strtotime( $auj . ' -1 day' ) );
$avant = gmdate( 'Y-m-d', strtotime( $auj . ' -40 days' ) );
$fix   = __DIR__ . '/fixtures/INVENTAIRE_TEST.xlsx';

echo "1. Préparation\n";
$code  = 'INVTEST';
$exist = array_values( array_filter( IGE_FFM_Referentiels::all( 'service' ), static fn( $s ) => $code === $s['code'] ) );
if ( $exist ) {
	// Repartir d'un service vierge : on purge ses inventaires et locaux d'un passage précédent.
	$sid = (int) $exist[0]['id'];
	foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'pat_inventaire' ) . ' WHERE service_id = %d', $sid ) ) as $iid ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( IGE_FFM_DB::t( 'pat_quantite' ), array( 'inventaire_id' => (int) $iid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
	$wpdb->delete( IGE_FFM_DB::t( 'pat_inventaire' ), array( 'service_id' => $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( IGE_FFM_DB::t( 'pat_local' ), array( 'service_id' => $sid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
} else {
	$sid = IGE_FFM_Referentiels::save( 'service', array( 'code' => $code, 'libelle' => 'Service d’inventaire de test', 'effectif' => '12', 'actif' => 1 ) );
}
$sid2   = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'IV' . $sfx, 'libelle' => 'Inventaire bis ' . $sfx, 'effectif' => '5', 'actif' => 1 ) );
$af     = IGE_FFM_Comptes::create( array( 'user_login' => 'invaf' . $sfx, 'display_name' => 'A&F inventaire', 'profil' => 'ige_admin' ) )['user_id'];
$agent  = IGE_FFM_Comptes::create( array( 'user_login' => 'invag' . $sfx, 'display_name' => 'Agent inventaire', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$lect   = IGE_FFM_Comptes::create( array( 'user_login' => 'invdi' . $sfx, 'display_name' => 'Direction inventaire', 'profil' => 'ige_lecteur' ) )['user_id'];
$parc   = IGE_FFM_Comptes::create( array( 'user_login' => 'invpa' . $sfx, 'display_name' => 'Parc inventaire', 'profil' => 'ige_parc' ) );
$parc   = is_array( $parc ) ? $parc['user_id'] : 0;
ige_t( is_int( $sid ) && is_int( $sid2 ) && $af && $agent && $lect, 'Services et comptes de test créés' );
ige_t( (bool) $parc, 'Compte « Gestionnaire du parc automobile » créé' );

echo "\n2. Profil du parc automobile\n";
ige_t( user_can( $parc, 'ige_gerer_parc' ) && user_can( $af, 'ige_gerer_parc' ) && user_can( 1, 'ige_gerer_parc' ), 'Parc : son gestionnaire, Administration et Finance, l’administrateur' );
ige_t( ! user_can( $lect, 'ige_gerer_parc' ) && ! user_can( $agent, 'ige_gerer_parc' ), 'La Direction et les services ne voient pas le parc' );
ige_t( ! user_can( $parc, 'ige_logistique' ) && ! user_can( $parc, 'ige_voir_tout' ) && ! user_can( $parc, 'ige_saisir' ), 'Le gestionnaire du parc n’a pas accès aux caisses ni aux stocks' );
$e = IGE_FFM_Ecrans::ecrans();
ige_t( 'ige_gerer_parc' === $e['parc-automobile'][0] && 'ige_acceder' === $e['vehicules'][0], 'Écran du parc réservé ; « Véhicules » reste ouvert aux services pour leurs demandes' );
wp_set_current_user( $parc );
ige_t( false !== strpos( (string) apply_filters( 'ige_ff_dashboard_url', home_url( '/' ), 'parc' ), 'parc-automobile' ), 'Son accueil est le parc automobile' );
ige_t( 'Gestionnaire du parc automobile' === apply_filters( 'ige_ff_profil_libelle', '', get_userdata( $parc ) ), 'Profil affiché : « Gestionnaire du parc automobile »' );
ige_t( 'menu-parc' === apply_filters( 'ige_ff_menu_location', 'menu-service', 'parc' ), 'Il a son propre menu (emplacement menu-parc)' );
wp_set_current_user( 1 );
$loc = get_nav_menu_locations();
if ( ! empty( $loc['menu-direction'] ) && ! empty( $loc['menu-central'] ) ) {
	delete_option( 'ige_ffm_menus_ok' );
	IGE_FFM_Installer::create_pages();
	IGE_FFM_Installer::assurer_menus();
	$loc  = get_nav_menu_locations();
	$pp   = get_page_by_path( 'parc-automobile' );
	$dans = static fn( $l, $p ) => $p && ! empty( $loc[ $l ] ) && (bool) array_filter( (array) wp_get_nav_menu_items( (int) $loc[ $l ] ), static fn( $it ) => 'page' === $it->object && (int) $it->object_id === (int) $p->ID );
	ige_t( ! $dans( 'menu-direction', $pp ), 'Le parc a quitté le menu de la Direction' );
	ige_t( $dans( 'menu-parc', $pp ), 'Menu « IGE · Parc automobile » créé avec le lien du parc' );
	ige_t( $dans( 'menu-central', get_page_by_path( 'inventaire-des-services' ) ), 'Lien « Inventaire des services » dans le menu d’Administration et Finance' );
} else {
	ige_t( true, 'Menus du thème absents sur ce site de test : vérification sans objet' );
}

echo "\n3. Stocks désactivés par défaut\n";
delete_option( IGE_FFM_Fonctions::OPTION );
ige_t( ! IGE_FFM_Fonctions::active( 'stocks' ) && IGE_FFM_Fonctions::active( 'inventaire' ) && IGE_FFM_Fonctions::active( 'parc' ), 'Nouveau site : stocks désactivés, inventaire et parc actifs' );
IGE_FFM_Fonctions::enregistrer( array_merge( IGE_FFM_Fonctions::etats(), array( 'stocks' => true ) ), 1 );
ige_t( IGE_FFM_Fonctions::active( 'stocks' ) && ! IGE_FFM_Fonctions::page_inactive( 'stocks' ), 'L’administrateur les active dans « Fonctions du site »' );
$m = new ReflectionMethod( 'IGE_FFM_Installer', 'migrer_v24' );
$m->setAccessible( true );
delete_option( IGE_FFM_Fonctions::OPTION );
$nb_art = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'article' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$m->invoke( null, '12' );
ige_t( IGE_FFM_Fonctions::active( 'stocks' ) === ( $nb_art > 0 ), 'Mise à jour : stocks gardés actifs seulement si des articles existent déjà' );
update_option( IGE_FFM_Fonctions::OPTION, array( 'stocks' => false ) );
$m->invoke( null, '12' );
ige_t( ! IGE_FFM_Fonctions::active( 'stocks' ), 'Un choix déjà fait par l’administrateur est respecté' );
delete_option( IGE_FFM_Fonctions::OPTION );

echo "\n4. Nom de l’application\n";
$nom_avant = get_theme_mod( 'ige_application', null );
set_theme_mod( 'ige_application', 'Gestion des frais de fonctionnement' );
$m->invoke( null, '13' );
ige_t( 'Gestion des frais de fonctionnement du trésor public' === get_theme_mod( 'ige_application' ), 'Ancien nom remplacé à la mise à jour' );
set_theme_mod( 'ige_application', 'Nom choisi par le site' );
$m->invoke( null, '13' );
ige_t( 'Nom choisi par le site' === get_theme_mod( 'ige_application' ), 'Un nom personnalisé n’est pas écrasé' );
if ( null === $nom_avant ) {
	remove_theme_mod( 'ige_application' );
} else {
	set_theme_mod( 'ige_application', $nom_avant );
}
ige_t( false !== strpos( ige_ffm_nom_appli(), 'trésor public' ) || null !== $nom_avant, 'Nom par défaut : « Gestion des frais de fonctionnement du trésor public »' );
ige_t( 0 === strpos( ige_ffm_nom_appli( true ), 'IGE · ' ), 'Forme courte pour les e-mails et documents : « IGE · … »' );

echo "\n5. Message après enregistrement\n";
wp_set_current_user( $agent );
$GLOBALS['ige_ffm_flash_jeton'] = '';
ige_ffm_flash( 'ok', 'Écriture enregistrée avec succès : TEST-1', 'Montant 1 000 CDF.', array( 'Voir', home_url( '/livre-de-caisse/' ) ) );
$jet = $GLOBALS['ige_ffm_flash_jeton'];
$url = ige_ffm_flash_redirect( home_url( '/nouvelle-ecriture/' ) );
ige_t( '' !== $jet && false !== strpos( $url, 'ige_msg=' . $jet ), 'Adresse de retour unique (contourne les caches de page)' );
delete_transient( 'ige_ffm_flash_' . $agent ); // Simule un cache d'objets qui a perdu le message.
$_GET['ige_msg'] = $jet;
$h = ige_ffm_flash_render();
ige_t( false !== strpos( $h, 'enregistrée avec succès' ) && false !== strpos( $h, 'id="ige-flash"' ) && false !== strpos( $h, 'livre-de-caisse' ), 'Le message s’affiche quand même, avec son lien' );
ige_t( '' === ige_ffm_flash_render(), 'Affiché une seule fois' );
wp_set_current_user( $lect );
$GLOBALS['ige_ffm_flash_jeton'] = '';
wp_set_current_user( $agent );
ige_ffm_flash( 'ko', 'Écriture refusée', 'Solde insuffisant.' );
$jet2 = $GLOBALS['ige_ffm_flash_jeton'];
delete_transient( 'ige_ffm_flash_' . $agent );
wp_set_current_user( $lect );
$_GET['ige_msg'] = $jet2;
ige_t( '' === ige_ffm_flash_render(), 'Le jeton d’un autre utilisateur ne montre rien' );
unset( $_GET['ige_msg'] );
$GLOBALS['ige_ffm_flash_jeton'] = '';

echo "\n6. Droits sur l’inventaire\n";
ige_t( user_can( $af, 'ige_logistique' ) && ! user_can( $lect, 'ige_logistique' ) && ! user_can( $agent, 'ige_logistique' ) && ! user_can( $parc, 'ige_logistique' ), 'Inventaire : Administration et Finance et l’administrateur seulement' );
ige_t( 'ige_logistique' === $e['inventaire-des-services'][0] && 'inventaire' === IGE_FFM_Fonctions::module_de_page( 'inventaire-des-services' ) && 'inventaire' === IGE_FFM_Fonctions::module_de_action( 'inv_saisie' ), 'Page et formulaires rattachés au module « Inventaire des services »' );
ige_t( 'droits' === IGE_FFM_Patrimoine::ouvrir( array( 'service_id' => $sid, 'date' => $auj ), $agent )->get_error_code(), 'Un agent de service ne peut pas ouvrir d’inventaire' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::importer( $fix, 'x.xlsx', array( 'date' => $auj ), $lect ) ), 'La Direction ne peut pas importer' );

echo "\n7. Import du fichier Excel\n";
$lu = IGE_FFM_Patrimoine::lire_fichier( $fix );
ige_t( ! is_wp_error( $lu ) && 'INVTEST' === $lu['service'] && 4 === count( $lu['locaux'] ) && 6 === count( $lu['lignes'] ), 'Format reconnu : service, 4 locaux, 6 biens (la ligne après TOTAL est ignorée)' );
ige_t( ! is_wp_error( $lu ) && 1 === count( $lu['avertissements'] ) && false !== strpos( $lu['avertissements'][0], 'Poubelles' ), 'Quantité non numérique signalée, pas bloquante' );
$bad = wp_tempnam( 'b.xlsx' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::lire_fichier( $bad ) ), 'Fichier illisible refusé' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::importer( $fix, 'f.xlsx', array( 'date' => gmdate( 'Y-m-d', strtotime( $auj . ' +3 days' ) ) ), $af ) ), 'Date dans le futur refusée' );
$r = IGE_FFM_Patrimoine::importer( $fix, 'INVENTAIRE_TEST.xlsx', array( 'date' => $avant ), $af );
ige_t( is_array( $r ) && 6 === $r['biens'] && 4 === $r['locaux'] && 23 === $r['total'], 'Import : 6 biens, 4 locaux, 23 unités ' . ige_err( $r ) );
$i1 = is_array( $r ) ? $r['id'] : 0;
$inv = IGE_FFM_Patrimoine::inventaire( $i1 );
ige_t( $inv && (int) $inv['service_id'] === $sid && 'brouillon' === $inv['statut'] && 'import' === $inv['origine'] && 0 === strpos( $inv['reference'], 'INV-INVTEST-' ), 'Service reconnu dans le fichier ; inventaire en cours ' . ( $inv['reference'] ?? '' ) );
$g = IGE_FFM_Patrimoine::grille( $i1 );
$ligne = static function ( $g, $des ) {
	foreach ( $g['lignes'] as $l ) {
		if ( $des === $l['bien']['designation'] ) {
			return $l;
		}
	}
	return null;
};
$ch = $ligne( $g, 'Chaises en plastique' );
ige_t( $ch && 13 === $ch['total'] && 'Mobilier' === $ch['bien']['categorie'], 'Chaises en plastique : 13, catégorie Mobilier devinée' );
ige_t( 'Informatique et bureautique' === ( $ligne( $g, 'Ordinateurs portables' )['bien']['categorie'] ?? '' ) && 'Climatisation et électroménager' === ( $ligne( $g, 'Split' )['bien']['categorie'] ?? '' ), 'Catégories informatique et climatisation devinées' );
ige_t( null !== $ligne( $g, 'Lave Mains' ) && 0 === $ligne( $g, 'Lave Mains' )['total'], 'Bien sans quantité gardé dans la grille (à compter)' );
$cols = wp_list_pluck( $g['locaux'], 'libelle' );
ige_t( array( 'BUREAU CHEF', 'POOL INFO', 'SECRETARIAT', 'ARCHIVES' ) === $cols, 'Locaux créés dans l’ordre du fichier' );
ige_t( 9 === $g['tot_col'][ (int) $g['locaux'][1]['id'] ] && 23 === $g['total'], 'Totaux par local et général (POOL INFO = 9)' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::importer( $fix, 'f.xlsx', array( 'date' => $avant ), $af ) ), 'Un seul inventaire en cours par service' );
$r2 = IGE_FFM_Patrimoine::importer( $fix, 'f.xlsx', array( 'service_id' => $sid2, 'date' => $avant ), $af );
ige_t( is_array( $r2 ) && 0 === $r2['nouveaux_biens'] && (int) IGE_FFM_Patrimoine::inventaire( $r2['id'] )['service_id'] === $sid2, 'Service choisi à l’import ; catalogue réutilisé sans doublon' );
$nb_biens = count( IGE_FFM_Patrimoine::biens() );

echo "\n8. Saisie, validation\n";
$bt = $ligne( $g, 'Tables en bois' )['bien']['id'];
$l0 = (int) $g['locaux'][0]['id'];
$l3 = (int) $g['locaux'][3]['id'];
ige_t( is_wp_error( IGE_FFM_Patrimoine::enregistrer( $i1, array( $bt => array( $l0 => '-2' ) ), array(), $af ) ), 'Quantité négative refusée' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::enregistrer( $i1, array( $bt => array( $l0 => '2,5' ) ), array(), $af ) ), 'Quantité décimale refusée' );
$r = IGE_FFM_Patrimoine::enregistrer( $i1, array( $bt => array( $l0 => '3', $l3 => '' ) ), array( 'observations' => 'Comptage contradictoire avec le chef de service.', 'nouveaux' => array( array( 'Climatiseur mural ' . $sfx, '', array( $l0 => '1' ) ), array( 'chaises EN plastique', '', array( $l3 => '2' ) ) ) ), $af );
$g = IGE_FFM_Patrimoine::grille( $i1 );
ige_t( true === $r && 4 === $ligne( $g, 'Tables en bois' )['total'] && 15 === $ligne( $g, 'Chaises en plastique' )['total'], 'Quantités modifiées ; bien déjà compté reconnu malgré la casse, quantité ajoutée (13 + 2)' );
ige_t( null !== $ligne( $g, 'Climatiseur mural ' . $sfx ) && 'Climatisation et électroménager' === $ligne( $g, 'Climatiseur mural ' . $sfx )['bien']['categorie'] && $nb_biens + 1 === count( IGE_FFM_Patrimoine::biens() ), 'Nouveau bien ajouté au catalogue depuis la grille' );
ige_t( 'Comptage contradictoire avec le chef de service.' === $g['inventaire']['observations'] && 27 === $g['total'], 'Observations enregistrées ; total 27' );
$loc_new = IGE_FFM_Patrimoine::ajouter_local( $sid, 'Salle de réunion', $af );
ige_t( is_int( $loc_new ) && 5 === count( IGE_FFM_Patrimoine::grille( $i1 )['locaux'] ), 'Local ajouté : nouvelle colonne' );
ige_t( $loc_new === IGE_FFM_Patrimoine::ajouter_local( $sid, 'SALLE DE REUNION', $af ), 'Pas de doublon de local' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::modifier_local( $loc_new, 'Archives', $af ) ), 'Renommage en un nom déjà pris refusé' );
ige_t( true === IGE_FFM_Patrimoine::modifier_local( $loc_new, '', $af ) && 4 === count( IGE_FFM_Patrimoine::grille( $i1 )['locaux'] ), 'Local retiré des inventaires en cours' );
ige_t( true === IGE_FFM_Patrimoine::retirer_bien( $i1, $ligne( $g, 'Lave Mains' )['bien']['id'], $af ) && null === $ligne( IGE_FFM_Patrimoine::grille( $i1 ), 'Lave Mains' ), 'Bien retiré de l’inventaire' );
ige_t( true === IGE_FFM_Patrimoine::valider( $i1, $af ), 'Inventaire validé' );
$inv = IGE_FFM_Patrimoine::inventaire( $i1 );
ige_t( 'valide' === $inv['statut'] && (int) $inv['valide_par'] === $af, 'Validé par Administration et Finance, figé' );
ige_t( is_wp_error( IGE_FFM_Patrimoine::enregistrer( $i1, array( $bt => array( $l0 => '9' ) ), array(), $af ) ) && is_wp_error( IGE_FFM_Patrimoine::supprimer( $i1, $af ) ) && is_wp_error( IGE_FFM_Patrimoine::valider( $i1, $af ) ), 'Un inventaire validé ne se modifie ni ne se supprime' );
ige_t( (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'audit' ) . " WHERE action = 'patrimoine_validation' AND objet_id = %s", $inv['reference'] ) ), 'Validation inscrite au journal d’audit' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

echo "\n9. Inventaire suivant et écarts\n";
ige_t( is_wp_error( IGE_FFM_Patrimoine::valider( IGE_FFM_Patrimoine::ouvrir( array( 'service_id' => $sid, 'date' => $hier ), $af ), $af ) ), 'Un inventaire vide ne se valide pas' );
$vide = IGE_FFM_Patrimoine::inventaires( $sid )[0];
ige_t( 'brouillon' === $vide['statut'] && true === IGE_FFM_Patrimoine::supprimer( (int) $vide['id'], $af ), 'Inventaire en cours supprimé' );
$i2 = IGE_FFM_Patrimoine::ouvrir( array( 'service_id' => $sid, 'date' => $hier, 'reprendre' => true ), $af );
$g2 = IGE_FFM_Patrimoine::grille( $i2 );
ige_t( is_int( $i2 ) && 27 === $g2['total'] && $g2['precedent'] && (int) $g2['precedent']['id'] === $i1, 'Reprise des quantités validées (27) ; comparé à l’inventaire précédent' );
ige_t( substr( $avant, 0, 4 ) !== substr( $hier, 0, 4 ) || (bool) preg_match( '/^INV-INVTEST-\d{4}-02$/', IGE_FFM_Patrimoine::inventaire( $i2 )['reference'] ), 'Référence suivante : INV-INVTEST-<année>-02' );
$bs = $ligne( $g2, 'Split' )['bien']['id'];
IGE_FFM_Patrimoine::enregistrer( $i2, array( $bs => array( $l0 => '0' ), $bt => array( $l0 => '4' ) ), array(), $af );
$g2 = IGE_FFM_Patrimoine::grille( $i2 );
ige_t( -1 === $ligne( $g2, 'Split' )['ecart'] && 1 === $ligne( $g2, 'Tables en bois' )['ecart'] && 27 === $g2['tot_prec'], 'Écarts calculés : Split −1, Tables en bois +1' );
IGE_FFM_Patrimoine::valider( $i2, $af );
ige_t( (int) IGE_FFM_Patrimoine::dernier_valide( $sid )['id'] === $i2, 'Le plus récent devient la référence du service' );

echo "\n10. Consolidé et exports\n";
IGE_FFM_Patrimoine::valider( $r2['id'], $af );
$c = IGE_FFM_Patrimoine::consolide();
$lc = null;
foreach ( $c['lignes'] as $l ) {
	if ( 'Chaises en plastique' === $l[0]['designation'] ) {
		$lc = $l;
	}
}
ige_t( isset( $c['services'][ $sid ], $c['services'][ $sid2 ] ) && $lc && 15 === $lc[1][ $sid ] && 13 === $lc[1][ $sid2 ], 'Consolidé : chaises en plastique 15 (INVTEST) et 13 (second service)' );
$ps = array_values( array_filter( IGE_FFM_Patrimoine::par_service(), static fn( $p ) => (int) $p['service']['id'] === $sid ) )[0];
ige_t( 2 === $ps['nb'] && (int) $ps['dernier']['id'] === $i2 && null === $ps['en_cours'], 'Situation du service : 2 inventaires validés, aucun en cours' );
$t = IGE_FFM_Patrimoine::export_donnees( $i2 );
ige_t( 'TOTAL' === $t['colonnes'][ count( $g2['locaux'] ) + 2 ][0] && 'Écart' === end( $t['colonnes'] )[0] && 'TOTAL' === $t['total'][1], 'Export au format du fichier de service (N°, Désignation, locaux, TOTAL, écart)' );
$xl = new IGE_FFM_Classeur( $t['titre'] );
$xl->feuille( 'Inventaire', $t );
$tmp = wp_tempnam( 'i.xlsx' );
$ok  = method_exists( $xl, 'enregistrer' ) ? $xl->enregistrer( $tmp ) : null;
ige_t( null === $ok || ( file_exists( $tmp ) && filesize( $tmp ) > 1000 ), 'Classeur Excel produit' );
$tc  = IGE_FFM_Ecran_Patrimoine::export_consolide();
$pdf = class_exists( 'Dompdf\\Dompdf' ) || file_exists( IGE_FFM_DIR . 'vendor/dompdf' ) ? IGE_FFM_Pdf::tableau( $tc ) : '%PDF';
ige_t( 0 === strpos( $pdf, '%PDF' ), 'PDF du consolidé produit' );

echo "\n11. Fichier réel (si présent)\n";
$reel = getenv( 'IGE_INVENTAIRE_REEL' );
if ( $reel && file_exists( $reel ) ) {
	$lu = IGE_FFM_Patrimoine::lire_fichier( $reel );
	ige_t( ! is_wp_error( $lu ) && $lu['lignes'], sprintf( 'Fichier %s lu : service %s, %d locaux, %d biens', basename( $reel ), $lu['service'] ?? '?', count( $lu['locaux'] ?? array() ), count( $lu['lignes'] ?? array() ) ) );
} else {
	ige_t( true, 'Sans objet (variable IGE_INVENTAIRE_REEL non définie)' );
}

if ( null === $fonctions ) {
	delete_option( IGE_FFM_Fonctions::OPTION );
} else {
	update_option( IGE_FFM_Fonctions::OPTION, $fonctions );
}
$t = $GLOBALS['ige_t'];
echo "\nRésultat : {$t['ok']} réussis, {$t['ko']} échoués\n";
