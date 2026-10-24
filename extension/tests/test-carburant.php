<?php
/**
 * Tests automatiques de la version 2.2 : dotations de carburant (par véhicule et par service) et
 * bons carburant (carnets, remise, contrôle à l'utilisation).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-carburant.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-carburant.php\n" );
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
 * Message d'erreur.
 *
 * @param mixed $r Résultat.
 * @return string
 */
function ige_err( $r ) {
	return is_wp_error( $r ) ? $r->get_error_message() : '';
}

add_filter( 'pre_wp_mail', '__return_true' );

global $wpdb;
$sfx = substr( (string) time(), -5 );
wp_set_current_user( 1 );
$ex  = IGE_FFM_Contexte::exercice();
$exo = (int) $ex['id'];
$auj = IGE_FFM_Contexte::aujourdhui();
$fonctions = get_option( IGE_FFM_Fonctions::OPTION, null );
delete_option( IGE_FFM_Fonctions::OPTION );

echo "1. Préparation\n";
$sid    = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'C' . $sfx, 'libelle' => 'Carburant ' . $sfx, 'actif' => 1 ) );
$agent  = IGE_FFM_Comptes::create( array( 'user_login' => 'cagent' . $sfx, 'display_name' => 'Agent C', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$gest   = IGE_FFM_Comptes::create( array( 'user_login' => 'cgest' . $sfx, 'display_name' => 'Parc C', 'profil' => 'ige_admin' ) )['user_id'];
wp_set_current_user( $gest );
$v1 = IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => $sfx . 'CA01', 'marque' => 'Toyota', 'service_id' => $sid, 'km_initial' => '10000', 'reservoir' => '80' ) );
$v2 = IGE_FFM_Parc::enregistrer_vehicule( array( 'immatriculation' => $sfx . 'CB01', 'marque' => 'Toyota', 'service_id' => $sid, 'km_initial' => '20000', 'reservoir' => '80' ) );
$c1 = IGE_FFM_Parc::enregistrer_chauffeur( array( 'nom' => 'Chauffeur C ' . $sfx, 'permis_numero' => 'PC', 'permis_expire' => gmdate( 'Y-m-d', strtotime( '+1 year' ) ) ) );
ige_t( is_int( $v1 ) && is_int( $v2 ) && is_int( $c1 ), 'Deux véhicules du service, un chauffeur' );

echo "\n2. Dotations\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'vehicule', 'cible_id' => $v1, 'quantite' => '100' ) ) ), 'Un agent ne fixe pas de dotation' );
wp_set_current_user( $gest );
ige_t( is_wp_error( IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'vehicule', 'cible_id' => $v1, 'quantite' => '-5' ) ) ), 'Quantité négative refusée' );
$d1 = IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'vehicule', 'cible_id' => $v1, 'quantite' => '100', 'unite' => 'litres' ) );
$d2 = IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'service', 'cible_id' => $sid, 'quantite' => '500 000', 'unite' => 'cdf' ) );
ige_t( is_int( $d1 ) && is_int( $d2 ), 'Dotations : 100 L par mois pour le véhicule 1, 500 000 CDF pour le service' );
ige_t( $d1 === IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'vehicule', 'cible_id' => $v1, 'quantite' => '120', 'unite' => 'litres' ) ) && 120.0 === (float) IGE_FFM_Carburant::dotation( $exo, 'vehicule', $v1 )['quantite'], 'Modification : la dotation passe à 120 L (même ligne)' );
wp_set_current_user( $agent );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '50', 'montant' => '150000', 'km' => '10200', 'plein' => 1, 'mode' => 'carte' ) );
ige_t( is_array( $p ) && ! $p['alertes'], '50 L (150 000) : dans les deux dotations' );
$s = IGE_FFM_Carburant::suivi( IGE_FFM_Carburant::dotation( $exo, 'vehicule', $v1 ) );
ige_t( 50.0 === $s['consomme'] && 70.0 === $s['reste'], 'Suivi du véhicule : 50 L consommés, 70 L restants' );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '50', 'montant' => '150000', 'km' => '10600', 'plein' => 1, 'mode' => 'carte' ) );
ige_t( is_array( $p ) && $p['alertes'] && false !== strpos( implode( ' ', $p['alertes'] ), '83 %' ), 'À 100 L sur 120 : alerte à 83 %' );
$r = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '30', 'montant' => '90000', 'km' => '10900', 'plein' => 1, 'mode' => 'carte' ) );
ige_t( false !== strpos( ige_err( $r ), 'dérogation' ) && false !== strpos( ige_err( $r ), '130' ), 'Dépassement (130 L sur 120) : refusé pour un service, dérogation à demander' );
$r = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v2, 'date' => $auj, 'litres' => '70', 'montant' => '210000', 'km' => '20300', 'plein' => 1, 'mode' => 'carte' ) );
ige_t( false !== strpos( ige_err( $r ), 'Service C' . $sfx ), 'Véhicule 2 sans dotation propre : bloqué par la dotation du service (510 000 > 500 000)' );
wp_set_current_user( $gest );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '30', 'montant' => '90000', 'km' => '10900', 'plein' => 1, 'mode' => 'carte' ) ) ), 'motif' ), 'Gestion du parc : dérogation exigée avec motif' );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '30', 'montant' => '90000', 'km' => '10900', 'plein' => 1, 'mode' => 'carte', 'derogation' => 'Mission urgente à Kikwit' ) );
ige_t( is_array( $p ) && false !== strpos( $p['anomalie'], 'Hors dotation' ), 'Dérogation motivée : plein enregistré et signalé' );
$row = $wpdb->get_row( $wpdb->prepare( 'SELECT derogation, service_id FROM ' . IGE_FFM_DB::t( 'carburant' ) . ' WHERE id = %d', $p['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
ige_t( 'Mission urgente à Kikwit' === $row['derogation'] && (int) $row['service_id'] === $sid, 'Dérogation et service conservés sur le plein' );
$al = wp_list_pluck( IGE_FFM_Carburant::alertes(), 1 );
ige_t( (bool) array_filter( $al, static fn( $a ) => false !== strpos( $a, $sfx . 'CA01 dépassée' ) ) && ! array_filter( $al, static fn( $a ) => false !== strpos( $a, 'Service C' . $sfx ) ), 'Alertes : dotation du véhicule dépassée ; celle du service (78 %) non signalée' );
ige_t( (bool) array_filter( IGE_FFM_Consolidation::alertes( $exo ), static fn( $a ) => false !== strpos( $a[1], 'CA01 dépassée' ) ), 'Dépassement remonté au tableau de bord' );
ige_t( true === IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'service', 'cible_id' => $sid, 'quantite' => '' ) ) && null === IGE_FFM_Carburant::dotation( $exo, 'service', $sid ), 'Dotation du service supprimée' );
IGE_FFM_Carburant::fixer_dotation( array( 'cible' => 'vehicule', 'cible_id' => $v1, 'quantite' => '' ) );

echo "\n3. Carnets de bons\n";
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Carburant::ajouter_carnet( array( 'prefixe' => 'X', 'debut' => '1', 'fin' => '2', 'valeur_unitaire' => '1000', 'recu_le' => $auj ) ) ), 'Un agent n’enregistre pas de carnet' );
wp_set_current_user( $gest );
$pre = 'T' . $sfx . '-';
ige_t( is_wp_error( IGE_FFM_Carburant::ajouter_carnet( array( 'prefixe' => $pre, 'debut' => '10', 'fin' => '5', 'valeur_unitaire' => '30000', 'recu_le' => $auj ) ) ), 'Numéros inversés refusés' );
ige_t( is_wp_error( IGE_FFM_Carburant::ajouter_carnet( array( 'prefixe' => $pre, 'debut' => '1', 'fin' => '10', 'recu_le' => $auj ) ) ), 'Valeur du bon obligatoire' );
$cn = IGE_FFM_Carburant::ajouter_carnet( array( 'station' => 'Engen Gombe', 'prefixe' => $pre, 'debut' => '101', 'fin' => '110', 'valeur_unitaire' => '30 000', 'recu_le' => $auj, 'reference' => 'FAC-9' ) );
ige_t( is_int( $cn ) && 10 === count( IGE_FFM_Carburant::bons( array( 'carnet_id' => $cn ) ) ), 'Carnet de 10 bons de 30 000 enregistré' );
ige_t( false !== strpos( ige_err( IGE_FFM_Carburant::ajouter_carnet( array( 'prefixe' => $pre, 'debut' => '105', 'fin' => '120', 'valeur_unitaire' => '30000', 'recu_le' => $auj ) ) ), 'chevauche' ), 'Carnet qui chevauche un carnet existant refusé' );
ige_t( 10 === count( IGE_FFM_Carburant::plage( $pre . '101-' . $pre . '110' ) ) && array( $pre . '101', $pre . '103' ) === IGE_FFM_Carburant::plage( $pre . '101, ' . $pre . '103' ), 'Saisie d’une plage ou d’une liste de numéros' );
ige_t( 10 === count( IGE_FFM_Carburant::plage( strtolower( $pre ) . '101 à ' . $pre . '110' ) ) && 10 === count( IGE_FFM_Carburant::plage( $pre . '101 A ' . $pre . '110' ) ), 'Plage « 101 à 110 » (accent, minuscules) comprise' );
ige_t( array( 'CA-7', 'CA-8', 'CA-9' ) === IGE_FFM_Carburant::plage( 'CA-7-9' ), 'Une lettre A dans le préfixe n’est pas lue comme « à »' );

echo "\n4. Remise et utilisation\n";
ige_t( 3 === IGE_FFM_Carburant::remettre( $pre . '101-' . $pre . '103', $v1, $c1 ), 'Trois bons remis au véhicule 1 et à son chauffeur' );
ige_t( false !== strpos( ige_err( IGE_FFM_Carburant::remettre( $pre . '102', $v2 ) ), 'déjà remis' ), 'Un bon déjà remis ne se remet pas une seconde fois' );
ige_t( 1 === IGE_FFM_Carburant::remettre( $pre . '104', $v2 ), 'Un bon remis au véhicule 2' );
wp_set_current_user( $agent );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v2, 'date' => $auj, 'litres' => '20', 'montant' => '60000', 'km' => '20300', 'plein' => 1, 'mode' => 'bon' ) ) ), 'numéro' ), 'Paiement par bon : numéro exigé (suivi des bons actif)' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v2, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '20300', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => 'INCONNU99' ) ) ), 'inconnu' ), 'Bon hors carnet refusé pour un service' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v2, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '20300', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '109' ) ) ), 'jamais remis' ), 'Bon resté en réserve refusé pour un service' );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v2, 'date' => $auj, 'litres' => '20', 'montant' => '60000', 'km' => '20300', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '104, ' . $pre . '101' ) );
ige_t( is_array( $p ) && 'utilise' === IGE_FFM_Carburant::bon( $pre . '104' )['statut'], 'Deux bons utilisés pour un plein de 60 000' );
ige_t( false !== strpos( $p['anomalie'], $pre . '101 remis au véhicule ' . $sfx . 'CA01' ), 'Bon remis à un autre véhicule : signalé' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '11000', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '104' ) ) ), 'déjà utilisé' ), 'Bon déjà utilisé : refusé' );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '15', 'montant' => '45000', 'km' => '11000', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '102' ) );
ige_t( is_array( $p ) && false !== strpos( $p['anomalie'], 'supérieur à la valeur des bons' ), 'Montant (45 000) supérieur à la valeur du bon (30 000) : signalé' );
ige_t( is_wp_error( IGE_FFM_Carburant::annuler_bon( $pre . '103', 'x', $gest ) ), 'Annulation sans motif refusée' );
ige_t( true === IGE_FFM_Carburant::annuler_bon( $pre . '103', 'Carnet mouillé, bon illisible', $gest ), 'Bon perdu annulé' );
ige_t( false !== strpos( ige_err( IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '11100', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '103' ) ) ), 'annulé' ), 'Bon annulé : refusé' );
wp_set_current_user( $gest );
$p = IGE_FFM_Parc::ajouter_plein( array( 'vehicule_id' => $v1, 'date' => $auj, 'litres' => '10', 'montant' => '30000', 'km' => '11100', 'plein' => 0, 'mode' => 'bon', 'bon_numero' => $pre . '110' ) );
ige_t( is_array( $p ) && false !== strpos( $p['anomalie'], 'sans avoir été remis' ), 'Gestion du parc : bon non remis accepté mais signalé' );
$wpdb->update( IGE_FFM_DB::t( 'bon' ), array( 'statut' => 'remis', 'vehicule_id' => $v1, 'remis_le' => gmdate( 'Y-m-d H:i:s', strtotime( '-40 days' ) ) ), array( 'numero' => $pre . '108' ) ); // phpcs:ignore WordPress.DB
ige_t( (bool) array_filter( wp_list_pluck( IGE_FFM_Carburant::alertes(), 1 ), static fn( $a ) => false !== strpos( $a, 'non utilisés' ) ), 'Bon remis depuis plus de 30 jours et non utilisé : alerte' );
$c = array_values( array_filter( IGE_FFM_Carburant::carnets(), static fn( $x ) => (int) $x['id'] === $cn ) )[0];
ige_t( 4 === (int) $c['utilises'] && 1 === (int) $c['remis'], 'Carnet : 4 bons utilisés, 1 en main' );

echo "\n5. Journal et désactivation\n";
$acts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ); // phpcs:ignore WordPress.DB
$att  = array( 'dotation_carburant', 'carnet_bons', 'remise_bons', 'annulation_bon' );
ige_t( ! array_diff( $att, $acts ) && ! array_diff( $att, array_keys( IGE_FFM_Audit::actions() ) ), 'Dotations et bons tracés au journal, avec libellés' );
ige_t( 'parc' === IGE_FFM_Fonctions::module_de_action( 'parc_dotation' ) && 'parc' === IGE_FFM_Fonctions::module_de_action( 'parc_carnet' ), 'Désactiver le parc désactive aussi dotations et bons' );

if ( null === $fonctions ) {
	delete_option( IGE_FFM_Fonctions::OPTION );
} else {
	update_option( IGE_FFM_Fonctions::OPTION, $fonctions );
}
$t = $GLOBALS['ige_t'];
echo "\nRésultat : {$t['ok']} réussis, {$t['ko']} échoués\n";
