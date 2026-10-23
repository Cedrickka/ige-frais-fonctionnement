<?php
/**
 * Tests automatiques de l'étape 2 (saisie, budget, caisse, contrepassation, pièces, fournisseurs).
 *
 * À lancer sur un site de TEST uniquement (crée un service, des comptes et des écritures) :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-saisie.php
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-saisie.php\n" );
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
 * Fichier temporaire au format $_FILES.
 *
 * @param string $nom     Nom.
 * @param string $contenu Contenu.
 * @return array
 */
function ige_fichier( $nom, $contenu ) {
	$tmp = wp_tempnam( $nom );
	file_put_contents( $tmp, $contenu ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return array( 'name' => $nom, 'type' => '', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $tmp ) );
}

global $wpdb;
$sfx      = substr( (string) time(), -5 );
$reglages = get_option( 'ige_ffm_reglages' );
wp_set_current_user( 1 );
$ex  = IGE_FFM_Contexte::exercice();
$exo = (int) $ex['id'];
$auj = IGE_FFM_Contexte::aujourdhui();
$hier = wp_date( 'Y-m-d', strtotime( '-1 day' ) );

echo "\n1. Préparation\n";
$sid = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'T' . $sfx, 'libelle' => 'Service de test ' . $sfx, 'actif' => 1 ) );
ige_t( is_int( $sid ) && $sid > 0, 'Service de test créé' );
$ag = IGE_FFM_Comptes::create( array( 'user_login' => 'tagent' . $sfx, 'display_name' => 'Agent test', 'profil' => 'ige_agent', 'service_id' => $sid ) );
$rs = IGE_FFM_Comptes::create( array( 'user_login' => 'tresp' . $sfx, 'display_name' => 'Responsable test', 'profil' => 'ige_responsable', 'service_id' => $sid ) );
$lc = IGE_FFM_Comptes::create( array( 'user_login' => 'tlect' . $sfx, 'display_name' => 'Lecteur test', 'profil' => 'ige_lecteur' ) );
ige_t( ! is_wp_error( $ag ) && ! is_wp_error( $rs ) && ! is_wp_error( $lc ), 'Agent, Responsable et Lecteur créés' );
$agent = $ag['user_id'];
$resp  = $rs['user_id'];
$lect  = $lc['user_id'];
$rubs  = wp_list_pluck( IGE_FFM_Referentiels::all( 'rubrique' ), 'id', 'code' );
$modes = array();
foreach ( IGE_FFM_Referentiels::all( 'mode_paiement' ) as $m ) {
	$modes[ sanitize_title( remove_accents( $m['libelle'] ) ) ] = (int) $m['id'];
}
$cheque  = $modes['cheque'];
$especes = $modes['especes'];
$r01     = (int) $rubs['R01'];
$r03     = (int) $rubs['R03'];

$base = array(
	'type'             => 'sortie',
	'statut'           => 'payee',
	'date_operation'   => $hier,
	'rubrique_id'      => $r01,
	'description'      => 'Rames de papier',
	'beneficiaire'     => 'Papeterie test',
	'num_piece'        => 'F-001',
	'mode_paiement_id' => $cheque,
);
$pdf = static fn() => ige_fichier( 'facture.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n" );

echo "\n2. Droits de saisie\n";
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Ecritures::saisir( $base, $pdf() ) ), 'Lecteur : saisie refusée' );
wp_set_current_user( 1 );
ige_t( is_wp_error( IGE_FFM_Ecritures::saisir( $base, $pdf() ) ), 'Administrateur du site non rattaché : saisie refusée' );
wp_set_current_user( $agent );
ige_t( is_wp_error( IGE_FFM_Budget::enregistrer( $exo, $sid, array( $r01 => '1000' ), '', $agent ) ), 'Agent : budget refusé (réservé au Responsable)' );

echo "\n3. Contrôles de caisse\n";
$r = IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '50000' ), $pdf() );
ige_t( is_wp_error( $r ) && 'caisse' === $r->get_error_code(), 'Sortie payée sur caisse vide refusée (' . ige_m( $r ) . ')' );
$e1 = IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $hier, 'montant' => '1 500 000', 'description' => 'Approvisionnement octobre', 'beneficiaire' => 'Inspection Générale', 'num_piece' => 'AV-01', 'mode_paiement_id' => $especes ) );
ige_t( is_int( $e1 ), 'Entrée (approvisionnement) enregistrée : ' . ige_m( $e1 ) );
ige_t( 1500000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Caisse = 1 500 000 (montant « 1 500 000 » bien lu)' );
$en = IGE_FFM_Ecritures::get( $e1 );
ige_t( 'payee' === $en['statut'] && null === $en['rubrique_id'], 'Une entrée est toujours payée et sans rubrique' );
ige_t( preg_match( '/^T' . $sfx . '-' . $ex['annee'] . '-000001$/', $en['id_ecriture'] ) === 1, 'Numérotation ' . $en['id_ecriture'] );

echo "\n4. Validations de la saisie\n";
ige_t( 'date' === IGE_FFM_Ecritures::saisir( array( 'date_operation' => wp_date( 'Y-m-d', strtotime( '+2 days' ) ), 'montant' => '1000' ) + $base, $pdf() )->get_error_code(), 'Date future refusée' );
ige_t( 'date' === IGE_FFM_Ecritures::saisir( array( 'date_operation' => ( $ex['annee'] - 1 ) . '-12-31', 'montant' => '1000' ) + $base, $pdf() )->get_error_code(), 'Date hors exercice refusée' );
ige_t( 'montant' === IGE_FFM_Ecritures::saisir( array( 'montant' => '12,50' ) + $base, $pdf() )->get_error_code(), 'Montant décimal refusé' );
ige_t( 'montant' === IGE_FFM_Ecritures::saisir( array( 'montant' => '0' ) + $base, $pdf() )->get_error_code(), 'Montant nul refusé' );
ige_t( 'rubrique' === IGE_FFM_Ecritures::saisir( array( 'rubrique_id' => 0, 'montant' => '1000' ) + $base, $pdf() )->get_error_code(), 'Sortie sans rubrique refusée' );
ige_t( 'num_piece' === IGE_FFM_Ecritures::saisir( array( 'num_piece' => '', 'montant' => '1000' ) + $base, $pdf() )->get_error_code(), 'Sortie sans n° de pièce refusée' );
ige_t( 'piece' === IGE_FFM_Ecritures::saisir( array( 'montant' => '1000' ) + $base, null )->get_error_code(), 'Sortie sans fichier justificatif refusée' );
ige_t( 'piece' === IGE_FFM_Ecritures::saisir( array( 'montant' => '1000' ) + $base, ige_fichier( 'virus.php', '<?php echo 1;' ) )->get_error_code(), 'Fichier .php refusé' );
ige_t( 'piece' === IGE_FFM_Ecritures::saisir( array( 'montant' => '1000' ) + $base, ige_fichier( 'faux.pdf', '<?php echo 1;' ) )->get_error_code(), 'Faux PDF (contenu PHP) refusé' );
ige_t( 'fournisseur' === IGE_FFM_Ecritures::saisir( array( 'beneficiaire' => '', 'montant' => '1000' ) + $base, $pdf() )->get_error_code(), 'Sortie sans fournisseur ni bénéficiaire refusée' );
$r = IGE_FFM_Ecritures::saisir( array( 'montant' => '2500000', 'mode_paiement_id' => $especes ) + $base, $pdf() );
ige_t( is_wp_error( $r ) && 'plafond' === $r->get_error_code(), 'Espèces au-dessus du plafond refusées' );

echo "\n5. Sorties sans budget (exercice à budget facultatif)\n";
$s1 = IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '200000' ), $pdf() );
ige_t( is_int( $s1 ), 'Sortie payée sans budget acceptée : ' . ige_m( $s1 ) );
ige_t( 1300000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Caisse = 1 300 000' );
ige_t( 1 === count( IGE_FFM_Pieces::liste( 'ecriture', $s1 ) ), 'Pièce rattachée à l’écriture' );
$p    = IGE_FFM_Pieces::liste( 'ecriture', $s1 )[0];
$path = IGE_FFM_Pieces::dossier() . $p['fichier'];
ige_t( is_readable( $path ) && hash_file( 'sha256', $path ) === $p['empreinte'], 'Fichier stocké avec son empreinte SHA-256' );
ige_t( is_readable( IGE_FFM_Pieces::dossier() . '.htaccess' ), 'Dossier des pièces protégé (.htaccess)' );
ige_t( false === strpos( $p['fichier'], 'facture' ), 'Nom de fichier aléatoire sur le serveur' );
$en1 = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'rubrique_id' => $r03, 'description' => 'Bon carburant', 'montant' => '300000' ) + $base, $pdf() );
ige_t( is_int( $en1 ) && 1300000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Engagement enregistré sans toucher la caisse' );

echo "\n6. Budget (Responsable)\n";
wp_set_current_user( $resp );
$r = IGE_FFM_Budget::enregistrer( $exo, $sid, array( $r01 => '100000' ), 'test', $resp );
ige_t( is_wp_error( $r ) && 'montant' === $r->get_error_code(), 'Budget inférieur au consommé refusé' );
$r = IGE_FFM_Budget::enregistrer( $exo, $sid, array( $r01 => '1000000', $r03 => '400000' ), '', $resp );
ige_t( is_wp_error( $r ) && 'motif' === $r->get_error_code(), 'Révision sans motif refusée (le service a des écritures)' );
$r = IGE_FFM_Budget::enregistrer( $exo, $sid, array( $r01 => '1 000 000', $r03 => '400000' ), 'Budget initial tardif', $resp );
ige_t( 2 === $r, 'Budget R01 et R03 enregistré avec motif' );
ige_t( 800000 === IGE_FFM_Budget::disponible( $exo, $sid, $r01 ), 'Disponible R01 = 800 000' );
ige_t( 100000 === IGE_FFM_Budget::disponible( $exo, $sid, $r03 ), 'Disponible R03 = 100 000 (engagement compté)' );
ige_t( null === IGE_FFM_Budget::disponible( $exo, $sid, (int) $rubs['R05'] ), 'Rubrique sans ligne : disponible « sans budget »' );
ige_t( 2 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'revision_budget' ) . ' WHERE service_id = %d', $sid ) ), 'Deux lignes de révision conservées' );
$r = IGE_FFM_Budget::enregistrer_solde_initial( $exo, $sid, '50000', $resp );
ige_t( is_wp_error( $r ) && 'verrou' === $r->get_error_code(), 'Solde initial verrouillé après la première écriture' );
$s = IGE_FFM_Budget::suivi( $exo, $sid );
ige_t( 1400000 === $s['total']['budget'] && 500000 === $s['total']['engage'] + $s['total']['paye'], 'Suivi : budget 1 400 000, consommé 500 000' );

echo "\n7. Dépassement du disponible\n";
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::saisir( array( 'montant' => '900000' ) + $base, $pdf() );
ige_t( is_wp_error( $r ) && 'disponible' === $r->get_error_code(), 'Sortie au-delà du disponible refusée (' . ige_m( $r ) . ')' );
$r = IGE_FFM_Ecritures::saisir( array( 'statut' => 'engagee', 'montant' => '900000' ) + $base, $pdf() );
ige_t( is_wp_error( $r ) && 'disponible' === $r->get_error_code(), 'Engagement au-delà du disponible refusé' );

echo "\n8. Paiement d'un engagement\n";
ige_t( 'date' === IGE_FFM_Ecritures::payer( $en1, ( $ex['annee'] - 1 ) . '-12-01', $cheque )->get_error_code(), 'Paiement daté hors exercice refusé' );
wp_set_current_user( $lect );
ige_t( is_wp_error( IGE_FFM_Ecritures::payer( $en1, $auj, $cheque ) ), 'Lecteur : paiement refusé' );
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::payer( $en1, $auj, $cheque );
ige_t( true === $r, 'Engagement payé : ' . ige_m( $r ) );
ige_t( 1000000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Caisse = 1 000 000 après paiement' );
ige_t( 100000 === IGE_FFM_Budget::disponible( $exo, $sid, $r03 ), 'Disponible R03 inchangé (engagé devenu payé)' );
ige_t( is_wp_error( IGE_FFM_Ecritures::payer( $en1, $auj, $cheque ) ), 'Second paiement du même engagement refusé' );

echo "\n9. Contrepassation\n";
$r = IGE_FFM_Ecritures::contrepasser( $s1, 'ok' );
ige_t( is_wp_error( $r ) && 'motif' === $r->get_error_code(), 'Motif trop court refusé' );
$c1 = IGE_FFM_Ecritures::contrepasser( $s1, 'Montant erroné sur la facture' );
ige_t( is_int( $c1 ), 'Sortie contrepassée : ' . ige_m( $c1 ) );
$c = IGE_FFM_Ecritures::get( $c1 );
ige_t( -200000 === (int) $c['montant'] && (int) $c['ecriture_origine_id'] === $s1, 'Écriture inverse négative, liée à l’originale' );
ige_t( 1200000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Caisse rétablie : 1 200 000' );
ige_t( 1000000 === IGE_FFM_Budget::disponible( $exo, $sid, $r01 ), 'Disponible R01 rétabli : 1 000 000' );
ige_t( is_wp_error( IGE_FFM_Ecritures::contrepasser( $s1, 'Encore une fois' ) ), 'Double contrepassation refusée' );
ige_t( is_wp_error( IGE_FFM_Ecritures::contrepasser( $c1, 'Annuler l’annulation' ) ), 'Contrepassation d’une contrepassation refusée' );
ige_t( null !== IGE_FFM_Ecritures::get( $s1 ), 'L’écriture originale est conservée (aucune suppression)' );
$r = IGE_FFM_Ecritures::contrepasser( $e1, 'Approvisionnement saisi deux fois' );
ige_t( is_int( $r ) || ( is_wp_error( $r ) && 'caisse' === $r->get_error_code() ), 'Contrepassation d’une entrée soumise au contrôle de caisse' );
if ( is_int( $r ) ) {
	ige_t( false, 'La contrepassation de l’entrée aurait dû rendre la caisse négative' );
}
$livre = IGE_FFM_Ecritures::livre( $exo, $sid );
$last  = end( $livre['rows'] );
ige_t( (int) $last['solde'] === IGE_FFM_Budget::caisse( $exo, $sid ), 'Livre : solde progressif final = caisse' );
$orig = array_values( array_filter( $livre['rows'], static fn( $x ) => (int) $x['id'] === $s1 ) )[0];
ige_t( $orig['contrepassee_par'] === $c['id_ecriture'], 'Livre : l’originale indique sa contrepassation' );
$nums = array_map( 'intval', wp_list_pluck( $livre['rows'], 'numero' ) );
sort( $nums );
ige_t( range( 1, count( $nums ) ) === $nums, 'Numérotation continue, sans trou (' . count( $nums ) . ' écritures)' );

echo "\n10. Mois clôturé\n";
$wpdb->insert( IGE_FFM_DB::t( 'arrete' ), array( 'exercice_id' => $exo, 'service_id' => $sid, 'mois' => (int) wp_date( 'n' ), 'type' => 'mensuel', 'solde_theorique' => 0, 'total_compte' => 0, 'ecart' => 0, 'statut' => 'cloture', 'created_by' => 1, 'created_at' => ige_ffm_now() ) );
$aid = (int) $wpdb->insert_id;
ige_t( $aid > 0 && 'date' === IGE_FFM_Ecritures::saisir( $base + array( 'montant' => '1000' ), $pdf() )->get_error_code(), 'Saisie dans un mois clôturé refusée' );
$wpdb->delete( IGE_FFM_DB::t( 'arrete' ), array( 'id' => $aid ) );

echo "\n11. Fournisseurs\n";
wp_set_current_user( 1 );
$f1 = IGE_FFM_Fournisseurs::save( array( 'raison_sociale' => 'Papeterie du Centre ' . $sfx . ' SARL', 'telephone' => '+243 81 000 ' . $sfx ), 0, 1 );
ige_t( is_int( $f1 ), 'Fournisseur créé' );
$d = IGE_FFM_Fournisseurs::save( array( 'raison_sociale' => 'PAPETERIE CENTRE ' . $sfx ), 0, 1 );
ige_t( is_wp_error( $d ) && 'doublon' === $d->get_error_code() && $f1 === $d->get_error_data()['id'], 'Doublon de nom détecté (SARL, casse, « du »)' );
$d = IGE_FFM_Fournisseurs::save( array( 'raison_sociale' => 'Autre nom ' . $sfx, 'telephone' => '081000' . $sfx ), 0, 1 );
ige_t( is_wp_error( $d ) && 'doublon' === $d->get_error_code(), 'Doublon de téléphone détecté (+243 / 0)' );
$wpdb->insert( IGE_FFM_DB::t( 'fournisseur' ), array( 'raison_sociale' => 'Papeterie Centre ' . $sfx, 'actif' => 1, 'created_at' => ige_ffm_now() ) );
$f2 = (int) $wpdb->insert_id;
$grp = array_filter( IGE_FFM_Fournisseurs::doublons(), static fn( $g ) => in_array( $f2, array_map( 'intval', wp_list_pluck( $g, 'id' ) ), true ) );
ige_t( 1 === count( $grp ), 'Doublon existant listé pour fusion' );
wp_set_current_user( $agent );
$sf = IGE_FFM_Ecritures::saisir( array( 'fournisseur_id' => $f2, 'beneficiaire' => '', 'montant' => '10000' ) + $base, $pdf() );
ige_t( is_int( $sf ), 'Sortie sur le fournisseur en double' );
wp_set_current_user( 1 );
ige_t( true === IGE_FFM_Fournisseurs::fusionner( $f1, $f2, 1 ), 'Fusion effectuée' );
ige_t( $f1 === (int) IGE_FFM_Ecritures::get( $sf )['fournisseur_id'], 'Écriture rattachée au fournisseur conservé' );
ige_t( 0 === (int) IGE_FFM_Fournisseurs::get( $f2 )['actif'] && $f1 === (int) IGE_FFM_Fournisseurs::get( $f2 )['fusionne_dans'], 'Fiche absorbée désactivée et liée' );
$st = IGE_FFM_Fournisseurs::statistiques( $exo );
ige_t( isset( $st[ $f1 ] ) && $st[ $f1 ]['paye'] >= 10000, 'Statistiques du fournisseur' );

echo "\n12. Entrées manuelles désactivées\n";
$in                      = $reglages;
$in['entrees_manuelles'] = '';
IGE_FFM_Reglages::save( $in );
wp_set_current_user( $agent );
$r = IGE_FFM_Ecritures::saisir( array( 'type' => 'entree', 'date_operation' => $hier, 'montant' => '1000', 'description' => 'x', 'mode_paiement_id' => $especes ) );
ige_t( is_wp_error( $r ) && 'type' === $r->get_error_code(), 'Entrée refusée quand le réglage est désactivé' );
update_option( 'ige_ffm_reglages', $reglages );

echo "\n13. Journal d'audit\n";
$acts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 60 FROM ' . IGE_FFM_DB::t( 'audit' ) . ') AND 1 = %d', 1 ) );
foreach ( array( 'saisie_ecriture', 'paiement_engagement', 'contrepassation', 'ajout_piece', 'revision_budget', 'fusion_fournisseurs' ) as $a ) {
	ige_t( in_array( $a, $acts, true ), "Action « $a » journalisée" );
}
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n14. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d', $sid ) );
foreach ( $ids as $id ) {
	foreach ( IGE_FFM_Pieces::liste( 'ecriture', $id ) as $p ) {
		wp_delete_file( IGE_FFM_Pieces::dossier() . $p['fichier'] );
	}
	$wpdb->delete( IGE_FFM_DB::t( 'piece' ), array( 'objet' => 'ecriture', 'objet_id' => $id ) );
}
foreach ( array( 'ecriture', 'budget', 'revision_budget', 'solde_initial' ) as $t ) {
	$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => $sid ) );
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . IGE_FFM_DB::t( 'fournisseur' ) . ' WHERE id IN (%d, %d)', $f1, $f2 ) );
foreach ( array( $agent, $resp, $lect ) as $u ) {
	wp_delete_user( $u );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $u ) );
}
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $sid ) );
ige_t( true, 'Données de test supprimées (le journal d’audit est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
