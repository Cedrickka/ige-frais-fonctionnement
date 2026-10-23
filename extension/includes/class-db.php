<?php
/**
 * Noms des tables et schéma de la base de données.
 *
 * Toutes les tables sont préfixées « {prefix}ige_ ». Les montants en francs congolais
 * sont des entiers (BIGINT) : aucune erreur d'arrondi possible. Les montants d'origine
 * en devise étrangère (lot 2) sont en DECIMAL(15,2).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accès au schéma.
 */
final class IGE_FFM_DB {

	/**
	 * Nom complet d'une table.
	 *
	 * @param string $name Nom court (ex. « service »).
	 * @return string
	 */
	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'ige_' . $name;
	}

	/**
	 * Instructions CREATE TABLE, au format attendu par dbDelta().
	 *
	 * @return string[]
	 */
	public static function schema() {
		global $wpdb;
		$c = $wpdb->get_charset_collate();
		$t = array( __CLASS__, 't' );

		return array(
			'CREATE TABLE ' . $t( 'exercice' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  annee smallint(4) unsigned NOT NULL,
  date_debut date NOT NULL,
  date_fin date NOT NULL,
  statut varchar(10) NOT NULL DEFAULT 'ouvert',
  budget_obligatoire tinyint(1) NOT NULL DEFAULT 1,
  cloture_le datetime NULL,
  cloture_par bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY annee (annee)
) $c;",
			'CREATE TABLE ' . $t( 'service' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(60) NOT NULL,
  libelle varchar(190) NOT NULL,
  actif tinyint(1) NOT NULL DEFAULT 1,
  ordre int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code)
) $c;",
			'CREATE TABLE ' . $t( 'categorie' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  libelle varchar(190) NOT NULL,
  actif tinyint(1) NOT NULL DEFAULT 1,
  ordre int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY libelle (libelle)
) $c;",
			'CREATE TABLE ' . $t( 'rubrique' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(20) NOT NULL,
  libelle varchar(190) NOT NULL,
  categorie_id bigint(20) unsigned NOT NULL,
  pieces_exigees varchar(255) NOT NULL DEFAULT '',
  nomenclature varchar(60) NOT NULL DEFAULT '',
  actif tinyint(1) NOT NULL DEFAULT 1,
  ordre int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY categorie_id (categorie_id)
) $c;",
			'CREATE TABLE ' . $t( 'mode_paiement' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  libelle varchar(60) NOT NULL,
  actif tinyint(1) NOT NULL DEFAULT 1,
  ordre int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY libelle (libelle)
) $c;",
			'CREATE TABLE ' . $t( 'budget' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exercice_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  rubrique_id bigint(20) unsigned NOT NULL,
  montant bigint(20) NOT NULL DEFAULT 0,
  observations varchar(255) NOT NULL DEFAULT '',
  updated_by bigint(20) unsigned NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY budget_ligne (exercice_id,service_id,rubrique_id)
) $c;",
			'CREATE TABLE ' . $t( 'solde_initial' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exercice_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  devise varchar(3) NOT NULL DEFAULT 'CDF',
  montant bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY solde_ligne (exercice_id,service_id,devise)
) $c;",
			'CREATE TABLE ' . $t( 'revision_budget' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  budget_id bigint(20) unsigned NOT NULL,
  exercice_id bigint(20) unsigned NOT NULL DEFAULT 0,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rubrique_id bigint(20) unsigned NOT NULL DEFAULT 0,
  ancien_montant bigint(20) NOT NULL,
  nouveau_montant bigint(20) NOT NULL,
  motif varchar(255) NOT NULL,
  statut varchar(12) NOT NULL DEFAULT 'appliquee',
  demande_par bigint(20) unsigned NOT NULL,
  decide_par bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  decided_at datetime NULL,
  PRIMARY KEY  (id),
  KEY budget_id (budget_id),
  KEY exercice_service (exercice_id,service_id)
) $c;",
			'CREATE TABLE ' . $t( 'compte' ) . " (
  user_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NULL,
  profil varchar(20) NOT NULL,
  actif tinyint(1) NOT NULL DEFAULT 1,
  mdp_a_changer tinyint(1) NOT NULL DEFAULT 1,
  derniere_connexion datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (user_id),
  KEY service_id (service_id)
) $c;",
			'CREATE TABLE ' . $t( 'fournisseur' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  raison_sociale varchar(190) NOT NULL,
  nif varchar(40) NOT NULL DEFAULT '',
  rccm varchar(60) NOT NULL DEFAULT '',
  telephone varchar(40) NOT NULL DEFAULT '',
  actif tinyint(1) NOT NULL DEFAULT 1,
  fusionne_dans bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NULL,
  PRIMARY KEY  (id),
  KEY nif (nif)
) $c;",
			'CREATE TABLE ' . $t( 'ecriture' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  id_ecriture varchar(40) NOT NULL,
  numero int(10) unsigned NOT NULL,
  exercice_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  date_operation date NOT NULL,
  mois tinyint(2) unsigned NOT NULL,
  type varchar(6) NOT NULL,
  statut varchar(8) NOT NULL,
  rubrique_id bigint(20) unsigned NULL,
  description varchar(255) NOT NULL,
  fournisseur_id bigint(20) unsigned NULL,
  beneficiaire varchar(190) NOT NULL DEFAULT '',
  num_piece varchar(60) NOT NULL DEFAULT '',
  mode_paiement_id bigint(20) unsigned NULL,
  montant bigint(20) NOT NULL,
  devise_origine varchar(3) NOT NULL DEFAULT 'CDF',
  montant_origine decimal(15,2) NULL,
  taux decimal(12,4) NULL,
  observations text NULL,
  date_paiement date NULL,
  ecriture_origine_id bigint(20) unsigned NULL,
  avance_id bigint(20) unsigned NULL,
  etat_transmission varchar(16) NOT NULL DEFAULT 'non_transmise',
  etat_verification varchar(12) NOT NULL DEFAULT '',
  anomalies text NULL,
  motif_rejet varchar(255) NOT NULL DEFAULT '',
  lot_id bigint(20) unsigned NULL,
  nb_transmissions smallint(5) unsigned NOT NULL DEFAULT 0,
  saisi_par bigint(20) unsigned NOT NULL,
  saisi_le datetime NOT NULL,
  valide_par bigint(20) unsigned NULL,
  valide_le datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY id_ecriture (id_ecriture),
  KEY service_exercice (service_id,exercice_id),
  KEY rubrique_id (rubrique_id),
  KEY etat_verification (etat_verification),
  KEY lot_id (lot_id)
) $c;",
			'CREATE TABLE ' . $t( 'lot' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  id_transmission varchar(40) NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  exercice_id bigint(20) unsigned NOT NULL,
  nb_lignes int(10) unsigned NOT NULL DEFAULT 0,
  nb_mises_a_jour int(10) unsigned NOT NULL DEFAULT 0,
  total_entrees bigint(20) NOT NULL DEFAULT 0,
  total_sorties bigint(20) NOT NULL DEFAULT 0,
  envoye_par bigint(20) unsigned NOT NULL,
  envoye_le datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY id_transmission (id_transmission)
) $c;",
			'CREATE TABLE ' . $t( 'piece' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  objet varchar(20) NOT NULL,
  objet_id bigint(20) unsigned NOT NULL,
  fichier varchar(255) NOT NULL,
  nom_original varchar(190) NOT NULL,
  type_mime varchar(60) NOT NULL,
  taille int(10) unsigned NOT NULL,
  empreinte char(64) NOT NULL,
  televerse_par bigint(20) unsigned NOT NULL,
  televerse_le datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY objet (objet,objet_id)
) $c;",
			'CREATE TABLE ' . $t( 'avance' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  numero varchar(30) NOT NULL,
  exercice_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  montant bigint(20) NOT NULL,
  date_remise date NOT NULL,
  mode_paiement_id bigint(20) unsigned NULL,
  objet varchar(255) NOT NULL DEFAULT '',
  ecriture_id bigint(20) unsigned NULL,
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY numero (numero),
  KEY service_exercice (service_id,exercice_id)
) $c;",
			'CREATE TABLE ' . $t( 'arrete' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  exercice_id bigint(20) unsigned NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  mois tinyint(2) unsigned NOT NULL,
  type varchar(10) NOT NULL DEFAULT 'mensuel',
  devise varchar(3) NOT NULL DEFAULT 'CDF',
  solde_theorique bigint(20) NOT NULL,
  total_compte bigint(20) NOT NULL,
  ecart bigint(20) NOT NULL,
  explication text NULL,
  billetage text NULL,
  signe_agent_par bigint(20) unsigned NULL,
  signe_agent_le datetime NULL,
  signe_resp_par bigint(20) unsigned NULL,
  signe_resp_le datetime NULL,
  statut varchar(12) NOT NULL DEFAULT 'brouillon',
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY service_mois (service_id,exercice_id,mois)
) $c;",
			'CREATE TABLE ' . $t( 'audit' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  action varchar(60) NOT NULL,
  objet varchar(40) NOT NULL DEFAULT '',
  objet_id varchar(60) NOT NULL DEFAULT '',
  avant longtext NULL,
  apres longtext NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  empreinte char(64) NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_id (user_id),
  KEY action (action)
) $c;",
		);
	}

	/**
	 * Liste des tables (noms courts).
	 *
	 * @return string[]
	 */
	public static function tables() {
		return array( 'exercice', 'service', 'categorie', 'rubrique', 'mode_paiement', 'budget', 'solde_initial', 'revision_budget', 'compte', 'fournisseur', 'ecriture', 'lot', 'piece', 'avance', 'arrete', 'audit' );
	}
}
