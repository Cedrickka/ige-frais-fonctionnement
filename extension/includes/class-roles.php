<?php
/**
 * Rôles et capacités.
 *
 * Le principe : chaque action de l'application est protégée par une capacité « ige_* »,
 * vérifiée côté serveur avant toute lecture ou écriture. Les rôles ne sont que des
 * regroupements de capacités. Un compte de service ne voit que les données de son service.
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Définition et installation des rôles.
 */
final class IGE_FFM_Roles {

	/**
	 * Capacités et leur signification.
	 *
	 * @return array<string,string>
	 */
	public static function capacites() {
		return array(
			'ige_acceder'           => "Accéder à l'application",
			'ige_saisir'            => 'Saisir les écritures de son service',
			'ige_voir_service'      => 'Consulter les données de son service',
			'ige_gerer_budget'      => 'Saisir le budget et le solde initial de son service',
			'ige_signer_arrete'     => "Signer l'arrêté de caisse en tant que responsable",
			'ige_verifier'          => 'Vérifier, valider et rejeter les écritures transmises',
			'ige_gerer_fonds'       => 'Enregistrer les avances de fonds aux services',
			'ige_voir_tout'         => 'Consulter les données de tous les services',
			'ige_rapports'          => 'Produire les rapports officiels',
			'ige_administrer'       => 'Gérer référentiels, comptes, exercices et règles',
			'ige_voir_audit'        => "Consulter le journal d'audit",
			'ige_viser_direction'   => 'Viser les demandes, ordonnancements et missions au-delà du seuil (Inspecteur Général)',
			'ige_logistique'        => 'Gérer les stocks de fournitures et l’inventaire des services',
			'ige_gerer_parc'        => 'Gérer le parc automobile : véhicules, chauffeurs, ordres de route, carburant, entretien',
		);
	}

	/**
	 * Rôles de l'application : clé => [ libellé, profil d'interface, capacités ].
	 *
	 * @return array<string,array>
	 */
	public static function roles() {
		$agent = array( 'ige_acceder', 'ige_saisir', 'ige_voir_service' );
		return array(
			'ige_agent'       => array( 'Agent (service)', 'service', $agent ),
			'ige_responsable' => array( 'Responsable (service)', 'service', array_merge( $agent, array( 'ige_gerer_budget', 'ige_signer_arrete' ) ) ),
			'ige_admin'       => array( 'Administration et Finance', 'central', array( 'ige_acceder', 'ige_verifier', 'ige_gerer_fonds', 'ige_voir_tout', 'ige_rapports', 'ige_administrer', 'ige_voir_audit', 'ige_logistique', 'ige_gerer_parc' ) ),
			'ige_parc'        => array( 'Gestionnaire du parc automobile', 'parc', array( 'ige_acceder', 'ige_gerer_parc' ) ),
			'ige_lecteur'     => array( 'Lecteur (Direction)', 'direction', array( 'ige_acceder', 'ige_voir_tout', 'ige_rapports', 'ige_viser_direction' ) ),
		);
	}

	/**
	 * Crée ou met à jour les rôles. Les administrateurs du site reçoivent toutes les capacités.
	 */
	public static function install() {
		foreach ( self::roles() as $key => $def ) {
			remove_role( $key );
			$caps = array( 'read' => true );
			foreach ( $def[2] as $cap ) {
				$caps[ $cap ] = true;
			}
			add_role( $key, $def[0], $caps );
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::capacites() ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Retire les capacités ajoutées aux administrateurs (les rôles ige_* restent,
	 * pour ne pas priver de rôle les comptes existants).
	 */
	public static function uninstall() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array_keys( self::capacites() ) as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
		foreach ( array_keys( self::roles() ) as $key ) {
			remove_role( $key );
		}
	}

	/**
	 * Libellé d'un rôle de l'application.
	 *
	 * @param string $role Clé.
	 * @return string
	 */
	public static function libelle( $role ) {
		$roles = self::roles();
		return $roles[ $role ][0] ?? $role;
	}

	/**
	 * Profil d'interface (central, service, direction) d'un rôle.
	 *
	 * @param string $role Clé.
	 * @return string
	 */
	public static function profil( $role ) {
		$roles = self::roles();
		return $roles[ $role ][1] ?? '';
	}
}
