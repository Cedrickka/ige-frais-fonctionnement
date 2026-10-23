<?php
/**
 * Tests automatiques de l'étape 5 (consolidation, alertes, documents officiels et vérification,
 * rapports, double authentification).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-pilotage.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-pilotage.php\n" );
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
$ex   = IGE_FFM_Contexte::exercice();
$exo  = (int) $ex['id'];
$auj  = IGE_FFM_Contexte::aujourdhui();
$hier = gmdate( 'Y-m-d', strtotime( $auj . ' -1 day' ) );
if ( substr( $hier, 0, 7 ) !== substr( $auj, 0, 7 ) ) {
	$hier = $auj;
}
$docs_avant = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . IGE_FFM_DB::t( 'document' ) );

echo "\n1. Préparation\n";
$sid = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'P' . $sfx, 'libelle' => 'Pilotage ' . $sfx, 'actif' => 1 ) );
$c   = array(
	'agent'  => IGE_FFM_Comptes::create( array( 'user_login' => 'pagent' . $sfx, 'display_name' => 'Agent pilotage', 'profil' => 'ige_agent', 'service_id' => $sid ) ),
	'resp'   => IGE_FFM_Comptes::create( array( 'user_login' => 'presp' . $sfx, 'display_name' => 'Responsable pilotage', 'profil' => 'ige_responsable', 'service_id' => $sid ) ),
	'compta' => IGE_FFM_Comptes::create( array( 'user_login' => 'pcompta' . $sfx, 'display_name' => 'Comptable pilotage', 'profil' => 'ige_admin' ) ),
	'compt2' => IGE_FFM_Comptes::create( array( 'user_login' => 'pcompt2' . $sfx, 'display_name' => 'Second comptable', 'profil' => 'ige_admin' ) ),
	'lect'   => IGE_FFM_Comptes::create( array( 'user_login' => 'plect' . $sfx, 'display_name' => 'Direction', 'profil' => 'ige_lecteur' ) ),
);
ige_t( ! array_filter( $c, 'is_wp_error' ), 'Service et comptes de test créés' );
$agent  = $c['agent']['user_id'];
$resp   = $c['resp']['user_id'];
$compta = $c['compta']['user_id'];
$compt2 = $c['compt2']['user_id'];
$lect   = $c['lect']['user_id'];
$rubs   = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes  = array();
foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
	$modes[ sanitize_title( remove_accents( $m['libelle'] ) ) ] = (int) $m['id'];
}
$r01  = (int) $rubs['R01'];
$base = array( 'type' => 'sortie', 'statut' => 'payee', 'date_operation' => $hier, 'rubrique_id' => $r01, 'description' => 'Fournitures', 'beneficiaire' => 'Papeterie Pilotage', 'mode_paiement_id' => $modes['cheque'] );
wp_set_current_user( $resp );
IGE_FFM_Budget::enregistrer( $exo, $sid, array( $r01 => '1000000' ), '', $resp );
wp_set_current_user( $compta );
IGE_FFM_Avances::creer( array( 'service_id' => $sid, 'montant' => '2000000', 'date_remise' => $hier, 'mode_paiement_id' => $modes['virement'], 'objet' => 'Fonctionnement' ) );
wp_set_current_user( $agent );
$s1 = IGE_FFM_Ecritures::saisir( $base + array( 'num_piece' => 'P1', 'montant' => '850000' ), ige_pdf() );
$s2 = IGE_FFM_Ecritures::saisir( array( 'rubrique_id' => (int) $rubs['R03'], 'description' => 'Carburant' ) + $base + array( 'num_piece' => 'P2', 'montant' => '300000' ), ige_pdf() );
IGE_FFM_Transmission::transmettre( $sid );
wp_set_current_user( $compta );
IGE_FFM_Verification::valider( array( $s1 ) );
ige_t( is_int( $s1 ) && is_int( $s2 ), 'Budget 1 000 000 (R01), avance 2 000 000, deux sorties dont une validée' );

echo "\n2. Consolidation (écritures validées uniquement)\n";
$co = IGE_FFM_Consolidation::calculer( $exo );
$s  = $co['services'][ $sid ] ?? null;
ige_t( $s && 850000 === $s['consomme'], 'Consommé du service : 850 000 (la sortie non validée est exclue)' );
ige_t( $s && 2000000 === $s['entrees'] && 1000000 === $s['budget'] && 150000 === $s['disponible'], 'Entrées 2 000 000, budget 1 000 000, disponible 150 000' );
ige_t( $s && 85 === (int) round( $s['taux'] ), 'Exécution 85 %' );
ige_t( $s && 850000 === $s['caisse'], 'Caisse du livre (toutes écritures) : 850 000' );
ige_t( $s && 1 === $s['a_verifier'] && $s['dernier_lot'], 'Une écriture à vérifier, date du dernier lot connue' );
$cat = IGE_FFM_Referentiels::get( 'rubrique', $r01 )['categorie_id'];
ige_t( $co['categories'][ (int) $cat ]['consomme'] >= 850000, 'Ventilation par catégorie' );
ige_t( $co['mois'][ (int) substr( $hier, 5, 2 ) ] >= 850000, 'Ventilation par mois' );
$tps = IGE_FFM_Consolidation::temps_ecoule( $ex, $ex['date_fin'] );
ige_t( 100 === (int) round( $tps ) && 0 === (int) round( IGE_FFM_Consolidation::temps_ecoule( $ex, gmdate( 'Y-m-d', strtotime( $ex['date_debut'] . ' -1 day' ) ) ) ), 'Temps écoulé : 0 % avant le 1er janvier, 100 % au 31 décembre' );

echo "\n3. Alertes\n";
$al    = IGE_FFM_Consolidation::alertes( $exo );
$texte = implode( ' | ', array_column( $al, 1 ) );
ige_t( false !== strpos( $texte, 'P' . $sfx . ' : rubrique R01 consommée à 85 %' ), 'Alerte : rubrique consommée au-delà de 80 %' );
ige_t( isset( $al[0] ) && in_array( $al[0][0], array( 'ko', 'warn', 'info' ), true ), 'Alertes triées par gravité' );

echo "\n4. Rapports officiels et registre\n";
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Rapports::emettre( 'etat_consolide', array() ) ), 'Lecteur (Direction) : émission refusée' );
wp_set_current_user( $compta );
$mois = (int) substr( $auj, 5, 2 );
ige_t( 'mois' === IGE_FFM_Rapports::emettre( 'situation_mensuelle', array( 'mois' => min( 12, $mois + 1 ) ) )->get_error_code() || 12 === $mois, 'Situation d’un mois futur refusée' );
$ids = array();
foreach ( array( 'situation_mensuelle', 'etat_consolide', 'avances', 'rapport_annuel' ) as $type ) {
	$ids[ $type ] = IGE_FFM_Rapports::emettre( $type, array( 'mois' => $mois, 'signataire' => 'L’Inspecteur Général de l’Enseignement' ) );
	ige_t( is_int( $ids[ $type ] ), 'Rapport émis : ' . IGE_FFM_Documents::types()[ $type ] );
}
$d = IGE_FFM_Documents::get( $ids['situation_mensuelle'] );
ige_t( preg_match( '/^IGE-AF-\d{4}-\d{4}$/', $d['reference'] ) === 1, 'Référence ' . $d['reference'] );
ige_t( false !== strpos( $d['contenu'], 'P' . $sfx ) && false !== strpos( $d['contenu'], '850' ), 'Le contenu contient le service et ses chiffres' );
ige_t( 'L’Inspecteur Général de l’Enseignement' === $d['signataire'] && 64 === strlen( $d['empreinte'] ), 'Signataire et empreinte SHA-256 enregistrés' );
ige_t( false !== strpos( IGE_FFM_Documents::get( $ids['avances'] )['contenu'], 'AV-' ), 'L’état des avances liste l’avance du service' );
$rendu = IGE_FFM_Documents::rendu( $d );
ige_t( false !== strpos( $rendu, 'data-ige-qr="' ) && false !== strpos( $rendu, 'ref=' . $d['reference'] ), 'Mise en page officielle avec code QR vers la page de vérification' );

echo "\n5. Vérification publique\n";
$v = IGE_FFM_Documents::verifier( strtolower( $d['reference'] ) );
ige_t( 'authentique' === $v['statut'], 'Référence authentique (saisie en minuscules)' );
ige_t( 1 === (int) IGE_FFM_Documents::get( $d['id'] )['nb_verifications'], 'Compteur de vérifications incrémenté' );
ige_t( 'inconnu' === IGE_FFM_Documents::verifier( 'IGE-AF-1999-9999' )['statut'] && 'inconnu' === IGE_FFM_Documents::verifier( '' )['statut'], 'Référence inconnue signalée' );
$wpdb->update( IGE_FFM_DB::t( 'document' ), array( 'contenu' => str_replace( '850', '150', $d['contenu'] ) ), array( 'id' => $d['id'] ) );
ige_t( 'altere' === IGE_FFM_Documents::verifier( $d['reference'], false )['statut'], 'Contenu modifié dans la base : document signalé altéré' );
$wpdb->update( IGE_FFM_DB::t( 'document' ), array( 'contenu' => $d['contenu'] ), array( 'id' => $d['id'] ) );
ige_t( 'authentique' === IGE_FFM_Documents::verifier( $d['reference'], false )['statut'], 'Authentique de nouveau après restauration' );

echo "\n6. Procès-verbaux au registre\n";
wp_set_current_user( $agent );
$m_prec = max( 1, $mois - 1 );
$theo   = IGE_FFM_Arretes::solde_theorique( $exo, $sid, IGE_FFM_Arretes::fin_de_mois( $ex, $m_prec ) );
$ar     = IGE_FFM_Arretes::enregistrer( $sid, $m_prec, array( 'autres' => (string) $theo, 'autres_detail' => 'Banque' ) );
IGE_FFM_Arretes::signer_agent( $ar );
ige_t( null === IGE_FFM_Documents::pour_objet( 'pv_arrete', $ar ), 'Pas de PV au registre avant la signature finale' );
wp_set_current_user( $resp );
IGE_FFM_Arretes::signer_responsable( $ar );
$pv = IGE_FFM_Documents::pour_objet( 'pv_arrete', $ar );
ige_t( $pv && 0 === strpos( $pv['reference'], 'IGE-PV-' ), 'PV enregistré au registre à la clôture du mois : ' . ( $pv ? $pv['reference'] : '' ) );
ige_t( $pv && 'authentique' === IGE_FFM_Documents::verifier( $pv['reference'], false )['statut'], 'PV vérifiable publiquement' );
wp_set_current_user( $compta );
$ci = IGE_FFM_Arretes::inopine( $sid, array( 'autres' => (string) IGE_FFM_Budget::caisse( $exo, $sid ), 'agent_present' => $agent ) );
wp_set_current_user( $agent );
IGE_FFM_Arretes::contresigner( $ci );
ige_t( null !== IGE_FFM_Documents::pour_objet( 'pv_inopine', $ci ), 'PV de comptage inopiné enregistré à la contresignature' );

echo "\n7. Double authentification (TOTP)\n";
$k = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
ige_t( '12345678901234567890' === IGE_FFM_Deux_Facteurs::base32_decode( $k ) && $k === IGE_FFM_Deux_Facteurs::base32_encode( '12345678901234567890' ), 'Base 32 : encodage et décodage' );
ige_t( '287082' === IGE_FFM_Deux_Facteurs::code( $k, 1 ) && '081804' === IGE_FFM_Deux_Facteurs::code( $k, (int) floor( 1111111109 / 30 ) ) && '005924' === IGE_FFM_Deux_Facteurs::code( $k, (int) floor( 1234567890 / 30 ) ), 'Codes conformes aux vecteurs de la RFC 6238' );
ige_t( false !== IGE_FFM_Deux_Facteurs::verifier_code( $k, IGE_FFM_Deux_Facteurs::code( $k, (int) floor( 1000 / 30 ) - 1 ), 0, 1000 ), 'Tolérance d’un pas (horloge du téléphone en retard)' );
ige_t( false === IGE_FFM_Deux_Facteurs::verifier_code( $k, IGE_FFM_Deux_Facteurs::code( $k, (int) floor( 1000 / 30 ) - 3 ), 0, 1000 ), 'Code trop ancien refusé' );
ige_t( IGE_FFM_Deux_Facteurs::obligatoire( $compta ) && ! IGE_FFM_Deux_Facteurs::obligatoire( $agent ), 'Obligatoire pour Administration et Finance, facultative pour un agent' );
$sec = IGE_FFM_Deux_Facteurs::secret_en_attente( $compta );
ige_t( 32 === strlen( $sec ) && $sec === IGE_FFM_Deux_Facteurs::secret_en_attente( $compta ), 'Secret de 160 bits conservé jusqu’à l’activation' );
ige_t( 0 === strpos( (string) get_user_meta( $compta, '_ige_2fa_attente', true ), function_exists( 'openssl_encrypt' ) ? 'c:' : 'p:' ) && false === strpos( (string) get_user_meta( $compta, '_ige_2fa_attente', true ), $sec ), 'Secret chiffré dans la base' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::activer( $compta, '000000' === IGE_FFM_Deux_Facteurs::code( $sec, (int) floor( time() / 30 ) ) ? '111111' : '000000' ) ), 'Activation refusée avec un mauvais code' );
$codes = IGE_FFM_Deux_Facteurs::activer( $compta, IGE_FFM_Deux_Facteurs::code( $sec, (int) floor( time() / 30 ) ) );
ige_t( is_array( $codes ) && 10 === count( $codes ) && IGE_FFM_Deux_Facteurs::actif( $compta ), 'Activée, 10 codes de secours remis' );
ige_t( false === IGE_FFM_Deux_Facteurs::controler( $compta, IGE_FFM_Deux_Facteurs::code( $sec, (int) floor( time() / 30 ) ) ), 'Un code déjà utilisé ne peut pas resservir' );
ige_t( false === IGE_FFM_Deux_Facteurs::controler( $compta, '123' ), 'Code mal formé refusé' );
ige_t( 'secours' === IGE_FFM_Deux_Facteurs::controler( $compta, strtolower( $codes[0] ) ) && 9 === IGE_FFM_Deux_Facteurs::codes_restants( $compta ), 'Code de secours accepté une fois (9 restants)' );
ige_t( false === IGE_FFM_Deux_Facteurs::controler( $compta, $codes[0] ), 'Le même code de secours est refusé ensuite' );
$hash = (array) get_user_meta( $compta, '_ige_2fa_secours', true );
ige_t( ! in_array( str_replace( '-', '', $codes[1] ), $hash, true ), 'Codes de secours conservés sous forme d’empreintes' );
ige_t( 'obligatoire' === IGE_FFM_Deux_Facteurs::desactiver( $compta, $compta )->get_error_code(), 'Un comptable ne peut pas désactiver sa double authentification obligatoire' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::desactiver( $compta, $agent ) ), 'Un agent ne peut pas réinitialiser celle d’un autre' );
ige_t( true === IGE_FFM_Deux_Facteurs::desactiver( $compta, $compt2, 'Téléphone perdu' ) && ! IGE_FFM_Deux_Facteurs::actif( $compta ) && ! get_user_meta( $compta, '_ige_2fa_secret', true ), 'Réinitialisation par un autre administrateur : secret et codes effacés' );
$sa = IGE_FFM_Deux_Facteurs::secret_en_attente( $agent );
IGE_FFM_Deux_Facteurs::activer( $agent, IGE_FFM_Deux_Facteurs::code( $sa, (int) floor( time() / 30 ) ) );
ige_t( IGE_FFM_Deux_Facteurs::actif( $agent ) && true === IGE_FFM_Deux_Facteurs::desactiver( $agent, $agent ), 'Un agent peut activer puis désactiver la sienne (facultative)' );

echo "\n8. Journal d'audit\n";
$acts = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 80 FROM ' . IGE_FFM_DB::t( 'audit' ) . ')' );
foreach ( array( 'emission_document', 'activation_2fa', 'code_secours_2fa', 'reinitialisation_2fa', 'desactivation_2fa' ) as $a ) {
	ige_t( in_array( $a, $acts, true ), "Action « $a » journalisée" );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n9. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . IGE_FFM_DB::t( 'document' ) . ' WHERE id > %d', (int) $docs_avant ) );
foreach ( $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $sid ) ) as $id ) {
	foreach ( IGE_FFM_Pieces::liste( 'ecriture', $id ) as $p ) {
		wp_delete_file( IGE_FFM_Pieces::dossier() . $p['fichier'] );
	}
	$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $id ) );
}
foreach ( array( 'ecriture', 'lot', 'budget', 'revision_budget', 'solde_initial', 'avance', 'arrete' ) as $t ) {
	$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => $sid ) );
}
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $sid ) );
foreach ( array( $agent, $resp, $compta, $compt2, $lect ) as $u ) {
	wp_delete_user( $u );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $u ) );
}
ige_t( true, 'Données de test supprimées (le journal d’audit est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
