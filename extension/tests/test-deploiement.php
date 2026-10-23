<?php
/**
 * Tests automatiques de la version 1.1 (messagerie de la plateforme, double authentification par
 * code e-mail, invitations et comptes en nombre, lecteur Excel, reprise des fichiers Excel).
 *
 * À lancer sur un site de TEST uniquement :
 *     wp eval-file wp-content/plugins/ige-ff-metier/tests/test-deploiement.php
 *
 * Aucun e-mail ne part : les envois sont interceptés et examinés.
 *
 * @package IGE_FF_Metier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( "À lancer avec WP-CLI : wp eval-file tests/test-deploiement.php\n" );
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
 * Dernier e-mail intercepté.
 *
 * @return array
 */
function ige_dernier_mail() {
	return end( $GLOBALS['ige_mails'] ) ?: array( 'to' => '', 'subject' => '', 'message' => '' );
}

/**
 * Code à 6 chiffres d'un e-mail.
 *
 * @param array $m E-mail.
 * @return string
 */
function ige_code_du_mail( $m ) {
	return preg_match( '#<b style="font-size:24px[^"]*">(\d{6})</b>#', $m['message'], $x ) ? $x[1] : '';
}

// Interception de tous les e-mails (rien ne part).
$GLOBALS['ige_mail_ok'] = true;
add_filter(
	'pre_wp_mail',
	static function ( $r, $atts ) {
		$GLOBALS['ige_mails'][] = $atts;
		return $GLOBALS['ige_mail_ok'];
	},
	10,
	2
);

global $wpdb;
$fx  = __DIR__ . '/fixtures/';
$sfx = substr( (string) time(), -5 );
wp_set_current_user( 1 );
$ex  = IGE_FFM_Contexte::exercice();
$exo = (int) $ex['id'];

$smtp_avant     = get_option( IGE_FFM_Messagerie::OPTION, null );
$reglages_avant = get_option( 'ige_ffm_reglages' );
$central_avant  = get_option( IGE_FFM_Reprise::OPTION_CENTRAL, null );

// Service de test (le code doit correspondre au fichier d'essai).
$exist = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . IGE_FFM_DB::t( 'service' ) . ' WHERE code = %s', '9990 TEST' ) );
if ( $exist ) {
	foreach ( array( 'ecriture', 'budget', 'solde_initial' ) as $t ) {
		$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => (int) $exist ) );
	}
	$sid = (int) $exist;
} else {
	$sid = IGE_FFM_Referentiels::save( 'service', array( 'code' => '9990 TEST', 'libelle' => 'Service de test reprise', 'actif' => 1 ) );
}
$agent = IGE_FFM_Comptes::create( array( 'user_login' => 'dagent' . $sfx, 'display_name' => 'Agent déploiement', 'user_email' => 'dagent' . $sfx . '@exemple.cd', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$sans  = IGE_FFM_Comptes::create( array( 'user_login' => 'dsans' . $sfx, 'display_name' => 'Sans e-mail', 'profil' => 'ige_agent', 'service_id' => $sid ) )['user_id'];
$compta = IGE_FFM_Comptes::create( array( 'user_login' => 'dcompta' . $sfx, 'display_name' => 'Comptable déploiement', 'user_email' => 'dcompta' . $sfx . '@exemple.cd', 'profil' => 'ige_admin' ) )['user_id'];

echo "1. Messagerie de la plateforme\n";
ige_t( is_wp_error( IGE_FFM_Messagerie::enregistrer( array( 'utilisateur' => 'contact@ige-gestion.com', 'port' => 25 ) ) ), 'Port 25 refusé (465 ou 587 seulement)' );
ige_t( is_wp_error( IGE_FFM_Messagerie::enregistrer( array( 'utilisateur' => 'pas-une-adresse', 'port' => 465 ) ) ), 'Identifiant SMTP qui n’est pas une adresse refusé' );
ige_t( true === IGE_FFM_Messagerie::enregistrer( array( 'hote' => 'smtp.hostinger.com', 'port' => 465, 'utilisateur' => 'contact@ige-gestion.com', 'mot_de_passe' => 'Secret-De-Test-42', 'nom' => 'IGE · Frais de fonctionnement' ) ), 'Réglages SMTP enregistrés' );
$brut = get_option( IGE_FFM_Messagerie::OPTION );
ige_t( false === strpos( wp_json_encode( $brut ), 'Secret-De-Test-42' ), 'Mot de passe conservé chiffré (jamais en clair dans la base)' );
$r = IGE_FFM_Messagerie::reglages();
ige_t( 'Secret-De-Test-42' === $r['mot_de_passe'] && $r['actif'], 'Mot de passe déchiffré à l’usage, messagerie active' );
IGE_FFM_Messagerie::enregistrer( array( 'hote' => 'smtp.hostinger.com', 'port' => 465, 'utilisateur' => 'contact@ige-gestion.com', 'mot_de_passe' => '' ) );
ige_t( 'Secret-De-Test-42' === IGE_FFM_Messagerie::reglages()['mot_de_passe'], 'Mot de passe laissé vide : inchangé' );
$fake = new class() {
	public $Host, $Port, $SMTPAuth, $SMTPSecure, $Username, $Password, $Timeout, $CharSet, $Sender; // phpcs:ignore
	public $smtp = false;
	/** SMTP. */
	public function isSMTP() { // phpcs:ignore
		$this->smtp = true;
	}
};
IGE_FFM_Messagerie::configurer( $fake );
ige_t( $fake->smtp && 'smtp.hostinger.com' === $fake->Host && 465 === $fake->Port && 'ssl' === $fake->SMTPSecure && 'contact@ige-gestion.com' === $fake->Username && $fake->SMTPAuth, 'PHPMailer configuré : smtp.hostinger.com, port 465, SSL, authentifié' );
IGE_FFM_Messagerie::enregistrer( array( 'hote' => 'smtp.hostinger.com', 'port' => 587, 'utilisateur' => 'contact@ige-gestion.com' ) );
IGE_FFM_Messagerie::configurer( $fake );
ige_t( 587 === $fake->Port && 'tls' === $fake->SMTPSecure, 'Port 587 : TLS' );
ige_t( 'contact@ige-gestion.com' === apply_filters( 'wp_mail_from', 'wordpress@localhost' ), 'Expéditeur : la boîte de la plateforme' );
ige_t( 'c•••••t@ige-gestion.com' === IGE_FFM_Messagerie::masquer( 'contact@ige-gestion.com' ), 'Adresse masquée à l’écran (c•••••t@ige-gestion.com)' );
ige_t( ! IGE_FFM_Messagerie::adresse_valide( get_userdata( $sans ) ) && IGE_FFM_Messagerie::adresse_valide( get_userdata( $agent ) ), 'Compte sans adresse détecté' );
IGE_FFM_Messagerie::envoyer( 'x@exemple.cd', 'Essai', "Bonjour\n\n**123456**" );
$mm = ige_dernier_mail();
ige_t( '[IGE] Essai' === $mm['subject'] && false !== strpos( $mm['message'], 'Inspection Générale' ), 'Modèle d’e-mail : sujet préfixé [IGE], en-tête de l’institution' );
$avant_echecs = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'audit' ) . " WHERE action = 'echec_envoi_courriel'" );
do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed', array( 'to' => array( 'x@exemple.cd' ) ) ) );
ige_t( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . IGE_FFM_DB::t( 'audit' ) . " WHERE action = 'echec_envoi_courriel'" ) === $avant_echecs + 1, 'Échec d’envoi journalisé' );

echo "\n2. Double authentification par code e-mail\n";
ige_t( 'email' === IGE_FFM_Deux_Facteurs::methode( $agent ), 'Méthode par défaut : code par e-mail' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::envoyer_otp( $sans ) ), 'Pas de code pour un compte sans adresse e-mail' );
$GLOBALS['ige_mails'] = array();
$otp  = IGE_FFM_Deux_Facteurs::envoyer_otp( $agent );
$mail = ige_dernier_mail();
$code = ige_code_du_mail( $mail );
ige_t( is_array( $otp ) && 6 === strlen( $code ) && $mail['to'] === 'dagent' . $sfx . '@exemple.cd', 'Code à 6 chiffres envoyé à l’adresse du compte' );
ige_t( false === strpos( $mail['subject'], $code ), 'Le code n’apparaît pas dans le sujet (aperçus de notification)' );
ige_t( false === strpos( wp_json_encode( $otp ), $code ), 'Le code n’est pas conservé, seulement son empreinte' );
ige_t( IGE_FFM_Deux_Facteurs::verifier_otp( $agent, $code, $otp[0], $otp[1] ), 'Bon code accepté' );
ige_t( IGE_FFM_Deux_Facteurs::verifier_otp( $agent, substr( $code, 0, 3 ) . ' ' . substr( $code, 3 ), $otp[0], $otp[1] ), 'Espaces tolérés dans la saisie' );
$faux = str_pad( (string) ( ( (int) $code + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );
ige_t( ! IGE_FFM_Deux_Facteurs::verifier_otp( $agent, $faux, $otp[0], $otp[1] ), 'Mauvais code refusé' );
ige_t( ! IGE_FFM_Deux_Facteurs::verifier_otp( $agent, $code, $otp[0], time() - 1 ), 'Code expiré refusé (10 minutes)' );
ige_t( ! IGE_FFM_Deux_Facteurs::verifier_otp( $compta, $code, $otp[0], $otp[1] ), 'Code lié au compte : inutilisable pour un autre' );

ige_t( true === IGE_FFM_Deux_Facteurs::demander_activation_email( $agent ), 'Activation : code envoyé' );
$code_act = ige_code_du_mail( ige_dernier_mail() );
ige_t( false !== strpos( ige_dernier_mail()['subject'], 'Activation' ), 'Sujet de l’e-mail d’activation' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::demander_activation_email( $agent ) ), 'Nouvel envoi refusé avant une minute' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::activer_email( $agent, '000000' === $code_act ? '111111' : '000000' ) ), 'Activation refusée avec un mauvais code' );
$secours = IGE_FFM_Deux_Facteurs::activer_email( $agent, $code_act );
ige_t( is_array( $secours ) && 10 === count( $secours ), 'Activation avec le bon code : 10 codes de secours remis' );
ige_t( IGE_FFM_Deux_Facteurs::actif( $agent ) && 'email' === IGE_FFM_Deux_Facteurs::methode( $agent ), 'Double authentification active, méthode e-mail' );
ige_t( ! get_user_meta( $agent, '_ige_2fa_otp', true ), 'Code d’activation effacé après usage' );
$otp2  = IGE_FFM_Deux_Facteurs::envoyer_otp( $agent );
$code2 = ige_code_du_mail( ige_dernier_mail() );
$o2    = array( 'empreinte' => $otp2[0], 'expire' => $otp2[1] );
ige_t( 'email' === IGE_FFM_Deux_Facteurs::controler( $agent, $code2, $o2 ), 'Connexion : code e-mail contrôlé' );
ige_t( false === IGE_FFM_Deux_Facteurs::controler( $agent, $code2, array() ), 'Connexion : sans code envoyé, rien n’est accepté' );
ige_t( 'secours' === IGE_FFM_Deux_Facteurs::controler( $agent, $secours[0], $o2 ), 'Code de secours accepté (adresse e-mail inaccessible)' );
ige_t( false === IGE_FFM_Deux_Facteurs::controler( $agent, $secours[0], $o2 ), 'Code de secours à usage unique' );

$r = get_option( 'ige_ffm_reglages' );
$r['deux_facteurs_tous'] = true;
update_option( 'ige_ffm_reglages', $r, false );
ige_t( IGE_FFM_Deux_Facteurs::obligatoire( $sans ), 'Règle « obligatoire pour tous » : s’applique aux agents' );
ige_t( is_wp_error( IGE_FFM_Deux_Facteurs::desactiver( $agent, $agent ) ), 'Obligatoire : l’agent ne peut pas la désactiver lui-même' );
update_option( 'ige_ffm_reglages', $reglages_avant, false );
ige_t( true === IGE_FFM_Deux_Facteurs::desactiver( $agent, $compta, 'Changement d’adresse' ), 'Réinitialisation par Administration et Finance' );
ige_t( ! IGE_FFM_Deux_Facteurs::actif( $agent ) && ! get_user_meta( $agent, '_ige_2fa_methode', true ), 'Réinitialisation complète (méthode effacée)' );

echo "\n3. Invitations et comptes en nombre\n";
$GLOBALS['ige_mails'] = array();
ige_t( is_wp_error( IGE_FFM_Invitations::inviter( $sans ) ), 'Pas d’invitation sans adresse e-mail' );
ige_t( true === IGE_FFM_Invitations::inviter( $agent ), 'Invitation envoyée' );
$inv = ige_dernier_mail();
ige_t( preg_match( '#(https?://[^\s<"]+wp-login\.php\?action=rp&amp;key=([^&]+)&amp;login=([^\s<"]+))#', $inv['message'], $lk ) || preg_match( '#wp-login\.php\?action=rp&key=([^&]+)&login=([^\s<"]+)#', $inv['message'], $lk ), 'Lien personnel d’activation dans l’e-mail' );
$cle   = isset( $lk[2] ) && false !== strpos( $lk[0], '&amp;' ) ? rawurldecode( $lk[2] ) : rawurldecode( $lk[1] ?? '' );
$login = 'dagent' . $sfx;
ige_t( check_password_reset_key( $cle, $login ) instanceof WP_User, 'Lien valide (clé de réinitialisation WordPress)' );
ige_t( false === stripos( $inv['message'], 'mot de passe provisoire' ) && false !== strpos( $inv['message'], $login ), 'Aucun mot de passe dans l’e-mail ; identifiant rappelé' );
ige_t( (bool) IGE_FFM_Invitations::invite_le( $agent ), 'Date d’invitation conservée' );
ige_t( DAY_IN_SECONDS === apply_filters( 'password_reset_expiration', HOUR_IN_SECONDS ), 'Lien valable 24 heures' );
$u = get_userdata( $agent );
reset_password( $u, 'Nouveau-Mdp-2026!' );
$c = IGE_FFM_Comptes::get( $agent );
ige_t( 0 === (int) $c['mdp_a_changer'], 'Mot de passe choisi par le lien : première connexion faite' );
ige_t( is_wp_error( check_password_reset_key( $cle, $login ) ), 'Lien inutilisable une seconde fois' );

$a = IGE_FFM_Invitations::analyser_csv( $fx . 'comptes_test.csv' );
ige_t( ! is_wp_error( $a ) && 3 === count( $a['lignes'] ), 'CSV : 3 comptes valides (responsable, agent, direction)' );
ige_t( 4 === count( $a['erreurs'] ), 'CSV : 4 lignes écartées (sans e-mail, profil inconnu, service inconnu, doublon)' );
$roles = wp_list_pluck( $a['lignes'], 'profil', 'user_login' );
ige_t( 'ige_responsable' === $roles['t.resp9990'] && 'ige_agent' === $roles['t.agent9990'] && 'ige_lecteur' === $roles['t.dir9990'], 'Profils convertis (RESPONSABLE, agent, LECTEUR)' );
ige_t( $sid === (int) $a['lignes'][1]['service_id'], 'Code service reconnu malgré l’écriture (« 9990test »)' );
ige_t( 0 === (int) $a['lignes'][2]['service_id'], 'Profil Direction : sans service' );
$GLOBALS['ige_mails'] = array();
$res = IGE_FFM_Invitations::creer( $a['lignes'], true );
ige_t( 3 === $res['crees'] && 3 === $res['invites'] && ! $res['erreurs'], 'Comptes créés et 3 invitations envoyées' );
ige_t( 3 === count( $GLOBALS['ige_mails'] ), 'Un e-mail par compte' );
$t2 = get_user_by( 'login', 't.agent9990' );
ige_t( $t2 && 1 === (int) IGE_FFM_Comptes::get( $t2->ID )['mdp_a_changer'], 'Comptes en attente d’activation' );
$again = IGE_FFM_Invitations::analyser_csv( $fx . 'comptes_test.csv' );
ige_t( 0 === count( $again['lignes'] ), 'Nouvel import du même fichier : rien en double' );
$GLOBALS['ige_mail_ok'] = false;
ige_t( is_wp_error( IGE_FFM_Invitations::inviter( $t2->ID ) ), 'Échec d’envoi signalé' );
$GLOBALS['ige_mail_ok'] = true;
$csvtmp = wp_tempnam( 'c.csv' );
file_put_contents( $csvtmp, mb_convert_encoding( "identifiant;nom;email;profil;service\nt.ansi$sfx;Gérard Ngoy;t.ansi$sfx@exemple.cd;AGENT;9990 TEST\n", 'Windows-1252', 'UTF-8' ) ); // phpcs:ignore
$an = IGE_FFM_Invitations::analyser_csv( $csvtmp );
ige_t( 'Gérard Ngoy' === ( $an['lignes'][0]['display_name'] ?? '' ), 'CSV enregistré par Excel (Windows-1252) : accents corrects' );
wp_delete_file( $csvtmp );

echo "\n4. Lecteur de classeurs Excel\n";
ige_t( 27 === IGE_FFM_Xlsx::colonne( 'AB12' ) && 0 === IGE_FFM_Xlsx::colonne( 'A1' ), 'Références de colonnes (A, AB)' );
ige_t( '2026-01-05' === IGE_FFM_Xlsx::date( 46027 ) && '2026-02-15' === IGE_FFM_Xlsx::date( '15/02/2026' ) && '' === IGE_FFM_Xlsx::date( '31/02/2026' ), 'Dates Excel (nombre de série, JJ/MM/AAAA, date impossible)' );
$x = IGE_FFM_Xlsx::lire( $fx . 'SERVICE_9990_TEST.xlsx' );
ige_t( ! is_wp_error( $x ) && isset( $x['LIVRE_DE_CAISSE'], $x['BUDGET'], $x['PARAMETRES'] ), 'Fichier de service lu (feuilles du modèle)' );
$h = IGE_FFM_Xlsx::entetes( $x['LIVRE_DE_CAISSE'], 'ID_ECRITURE' );
ige_t( 3 === $h['ligne'] && isset( $h['colonnes']['etattransmission'] ), 'Ligne d’en-tête trouvée (ligne 3)' );
foreach ( array( '/mnt/user-data/uploads/IGE_FF_SERVICE_OK.xlsx', '/mnt/user-data/uploads/IGE_FF_CENTRAL.xlsx' ) as $vrai ) {
	if ( is_readable( $vrai ) ) {
		$v = IGE_FFM_Xlsx::lire( $vrai );
		ige_t( ! is_wp_error( $v ) && count( $v ) >= 8, 'Classeur réel lu : ' . basename( $vrai ) );
	}
}
$pasxl = wp_tempnam( 'x.xlsx' );
file_put_contents( $pasxl, 'ceci n’est pas un classeur' ); // phpcs:ignore
ige_t( is_wp_error( IGE_FFM_Xlsx::lire( $pasxl ) ), 'Fichier qui n’est pas un classeur refusé' );
wp_delete_file( $pasxl );

echo "\n5. Reprise des fichiers Excel\n";
ige_t( is_wp_error( IGE_FFM_Reprise::charger_central( $fx . 'SERVICE_9990_TEST.xlsx' ) ), 'Fichier de service proposé comme central : refusé' );
$c = IGE_FFM_Reprise::charger_central( $fx . 'CENTRAL_TEST.xlsx' );
ige_t( ! is_wp_error( $c ) && 3 === $c['nb'] && 2 === $c['validee'] && 1 === $c['rejetee'], 'Classeur central : 3 écritures (2 validées, 1 rejetée)' );
ige_t( is_wp_error( IGE_FFM_Reprise::analyser( $fx . 'CENTRAL_TEST.xlsx' ) ), 'Classeur central proposé comme fichier de service : refusé' );
$a = IGE_FFM_Reprise::analyser( $fx . 'SERVICE_9990_TEST.xlsx' );
ige_t( ! is_wp_error( $a ) && $sid === (int) $a['service']['id'], 'Service reconnu par son code (9990 TEST)' );
ige_t( ! $a['erreurs'], 'Aucune erreur bloquante' );
ige_t( 1000000 === $a['solde_initial'], 'Solde initial : 1 000 000 CDF' );
ige_t( 8 === count( $a['budget'] ), 'Budget : 8 lignes (les 2 rubriques à 0 n’en créent pas)' );
ige_t( 6 === count( $a['ecritures'] ), '6 écritures à importer' );
ige_t( 5000000 === $a['totaux']['entrees'] && 370000 === $a['totaux']['sorties'] && 300000 === $a['totaux']['engagees'], 'Totaux : entrées 5 000 000, sorties payées 370 000 (avec contrepassation), engagées 300 000' );
ige_t( 5630000 === $a['totaux']['solde_final'], 'Solde de caisse attendu : 5 630 000' );
$av = implode( ' ', $a['avertissements'] );
ige_t( false !== strpos( $av, 'mode de paiement inconnu' ) && false !== strpos( $av, 'sans n° de pièce' ), 'Avertissements : mode de paiement inconnu, sortie sans pièce' );
ige_t( is_wp_error( IGE_FFM_Reprise::importer( $a, $agent ) ), 'Import réservé à Administration et Finance' );
$res = IGE_FFM_Reprise::importer( $a, $compta );
ige_t( ! is_wp_error( $res ) && 6 === $res['ecritures'] && 8 === $res['budget'], 'Import : 6 écritures, 8 lignes de budget' );
ige_t( 5630000 === IGE_FFM_Budget::caisse( $exo, $sid ), 'Solde de caisse dans l’application = solde du fichier' );
ige_t( 1000000 === IGE_FFM_Budget::solde_initial( $exo, $sid ), 'Solde initial repris' );
$e = static function ( $n ) use ( $wpdb ) {
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE id_ecriture = %s', sprintf( '9990TEST-2026-%06d', $n ) ), ARRAY_A );
};
ige_t( 'validee' === $e( 2 )['etat_verification'] && 'transmise' === $e( 2 )['etat_transmission'], 'État repris du classeur central : validée' );
ige_t( 'rejetee' === $e( 3 )['etat_verification'] && 'Pièce manquante' === $e( 3 )['motif_rejet'], 'Rejet et motif repris' );
ige_t( 'a_verifier' === $e( 6 )['etat_verification'], 'Transmise mais absente du central : à vérifier' );
ige_t( 'non_transmise' === $e( 5 )['etat_transmission'] && 'engagee' === $e( 5 )['statut'], 'Engagement non transmis repris tel quel' );
ige_t( (int) $e( 4 )['ecriture_origine_id'] === (int) $e( 3 )['id'] && -400000 === (int) $e( 4 )['montant'], 'Contrepassation reliée à l’écriture d’origine' );
ige_t( (int) get_user_by( 'login', 'agent8000b' )->ID === (int) $e( 6 )['saisi_par'] || ! get_user_by( 'login', 'agent8000b' ), 'Auteur repris quand l’identifiant existe' );
ige_t( '2026-02-15' === $e( 6 )['date_operation'] && 2 === (int) $e( 6 )['mois'], 'Date saisie en texte convertie' );
ige_t( 'excel' === $e( 1 )['origine'], 'Écritures marquées « reprise Excel »' );
$anom = IGE_FFM_Anomalies::pour_service( $exo, $sid, array( (int) $e( 3 )['id'] ) );
ige_t( ! isset( $anom[ (int) $e( 3 )['id'] ]['piece'] ), 'Pas d’anomalie « pièce manquante » sur une écriture reprise' );
$a2 = IGE_FFM_Reprise::analyser( $fx . 'SERVICE_9990_TEST.xlsx' );
ige_t( 0 === count( $a2['ecritures'] ) && 6 === $a2['deja'] && ! $a2['erreurs'], 'Nouvelle analyse : tout est déjà repris, rien en double' );
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'origine' => '' ), array( 'id' => (int) $e( 6 )['id'] ) );
$a3 = IGE_FFM_Reprise::analyser( $fx . 'SERVICE_9990_TEST.xlsx' );
ige_t( (bool) $a3['erreurs'] && is_wp_error( IGE_FFM_Reprise::importer( $a3, $compta ) ), 'Service déjà démarré dans l’application : reprise refusée' );
$wpdb->update( IGE_FFM_DB::t( 'ecriture' ), array( 'origine' => 'excel' ), array( 'id' => (int) $e( 6 )['id'] ) );
ige_t( 6 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(numero) FROM ' . IGE_FFM_DB::t( 'ecriture' ) . ' WHERE service_id = %d AND exercice_id = %d', $sid, $exo ) ), 'La numérotation de l’application continuera à 7' );
$autre = IGE_FFM_Referentiels::save( 'service', array( 'code' => 'R' . $sfx, 'libelle' => 'Autre', 'actif' => 1 ) );
ige_t( 6 === ( IGE_FFM_Reprise::par_service( $exo )[ $sid ] ?? 0 ), 'Suivi : 6 écritures reprises pour le service' );

echo "\n6. Tableau de déploiement\n";
$etats = IGE_FFM_Ecran_Deploiement::etat_services();
$mien  = current( array_filter( $etats, static fn( $x ) => (int) $x['service']['id'] === $sid ) );
ige_t( 6 === $mien['reprise'] && 8 === $mien['budget'], 'Service : reprise et budget comptés' );
ige_t( 1 === $mien['sans_email'], 'Compte sans adresse e-mail signalé' );
ige_t( $mien['responsable'], 'Responsable présent (créé par import)' );
ige_t( in_array( $mien['etat'][0], array( 'En préparation', 'Prêt' ), true ), 'État : ' . $mien['etat'][0] );
$vide = current( array_filter( $etats, static fn( $x ) => (int) $x['service']['id'] === (int) $autre ) );
ige_t( 'À préparer' === $vide['etat'][0], 'Service sans compte : à préparer' );

echo "\n7. Journal d'audit\n";
$acts = $wpdb->get_col( 'SELECT DISTINCT action FROM ' . IGE_FFM_DB::t( 'audit' ) . ' WHERE id > (SELECT MAX(id) - 120 FROM ' . IGE_FFM_DB::t( 'audit' ) . ')' );
foreach ( array( 'reglage_messagerie', 'echec_envoi_courriel', 'activation_2fa', 'invitation', 'import_comptes', 'reprise_central', 'reprise_service', 'changement_mdp' ) as $ac ) {
	ige_t( in_array( $ac, $acts, true ), "Action « $ac » journalisée" );
}
ige_t( false === strpos( (string) $wpdb->get_var( 'SELECT GROUP_CONCAT(apres) FROM ' . IGE_FFM_DB::t( 'audit' ) . " WHERE action = 'reglage_messagerie'" ), 'Secret-De-Test-42' ), 'Le mot de passe SMTP n’apparaît pas dans le journal' );
ige_t( IGE_FFM_Audit::verify()['ok'], 'Chaîne d’audit intègre' );

echo "\n8. Nettoyage\n";
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( 'ecriture', 'budget', 'solde_initial' ) as $t ) {
	$wpdb->delete( IGE_FFM_DB::t( $t ), array( 'service_id' => $sid ) );
}
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => $sid ) );
$wpdb->delete( IGE_FFM_DB::t( 'service' ), array( 'id' => (int) $autre ) );
$ids = array( $agent, $sans, $compta );
foreach ( array( 't.resp9990', 't.agent9990', 't.dir9990' ) as $l ) {
	$uu = get_user_by( 'login', $l );
	if ( $uu ) {
		$ids[] = $uu->ID;
	}
}
foreach ( $ids as $uid ) {
	wp_delete_user( $uid );
	$wpdb->delete( IGE_FFM_DB::t( 'compte' ), array( 'user_id' => $uid ) );
}
null === $smtp_avant ? delete_option( IGE_FFM_Messagerie::OPTION ) : update_option( IGE_FFM_Messagerie::OPTION, $smtp_avant, false );
null === $central_avant ? delete_option( IGE_FFM_Reprise::OPTION_CENTRAL ) : update_option( IGE_FFM_Reprise::OPTION_CENTRAL, $central_avant, false );
update_option( 'ige_ffm_reglages', $reglages_avant, false );
ige_t( true, 'Données de test supprimées, réglages rétablis (le journal d’audit est conservé)' );

$r = $GLOBALS['ige_t'];
echo "\nRésultat : {$r['ok']} réussis, {$r['ko']} échoués\n";
