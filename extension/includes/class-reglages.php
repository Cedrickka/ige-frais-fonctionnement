<?php
/**
 * Règles de gestion paramétrables (Administration > Exercice et règles de contrôle).
 *
 * @package IGE_FF_Metier
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lecture et écriture des réglages.
 */
final class IGE_FFM_Reglages {

	const OPTION = 'ige_ffm_reglages';

	/**
	 * Définition des réglages : clé => [ type, défaut, libellé, groupe ].
	 *
	 * @return array<string,array>
	 */
	public static function definitions() {
		return array(
			'bloquer_caisse_negative'    => array( 'bool', true, 'Refuser une sortie qui rend la caisse négative', 'controles' ),
			'bloquer_depassement'        => array( 'bool', true, 'Refuser un dépassement du disponible (si un budget est défini)', 'controles' ),
			'piece_obligatoire'          => array( 'bool', true, 'N° de pièce et photo obligatoires pour une sortie', 'controles' ),
			'contrepassation_rejet'      => array( 'bool', true, 'Contrepassation obligatoire après un rejet', 'controles' ),
			'entrees_manuelles'          => array( 'bool', true, 'Les services saisissent eux-mêmes leurs approvisionnements (en attendant les avances de fonds, étape 4)', 'controles' ),
			'transmission_auto'          => array( 'bool', false, 'Transmettre automatiquement chaque écriture au central dès son enregistrement', 'controles' ),
			'seuil_justification'        => array( 'int', 80, 'Justification exigée avant une nouvelle avance (%)', 'seuils' ),
			'plafond_especes'            => array( 'int', 2000000, "Plafond d'un paiement en espèces (CDF)", 'seuils' ),
			'alerte_rubrique'            => array( 'int', 80, "Alerte de consommation d'une rubrique (%)", 'seuils' ),
			'delai_relance'              => array( 'int', 15, 'Délai sans transmission avant relance (jours)', 'seuils' ),
			'delai_saisie_tardive'       => array( 'int', 10, 'Saisie tardive au-delà de (jours)', 'seuils' ),
			'anomalie_doublon'           => array( 'bool', true, 'Même facture saisie deux fois', 'anomalies' ),
			'anomalie_fractionnement'    => array( 'bool', true, 'Achats fractionnés sous le plafond (7 jours)', 'anomalies' ),
			'anomalie_jour_non_ouvre'    => array( 'bool', true, 'Dépense datée un jour non ouvré', 'anomalies' ),
			'anomalie_beneficiaire'      => array( 'bool', true, "Bénéficiaire identique à l'agent qui saisit", 'anomalies' ),
			'anomalie_saisie_tardive'    => array( 'bool', true, "Saisie tardive après l'opération", 'anomalies' ),
			'verrouillage_essais'        => array( 'int', 5, 'Essais de connexion avant verrouillage', 'securite' ),
			'verrouillage_minutes'       => array( 'int', 15, 'Durée du verrouillage (minutes)', 'securite' ),
			'inactivite_minutes'         => array( 'int', 30, "Déconnexion après inactivité (minutes)", 'securite' ),
			'mdp_longueur'               => array( 'int', 10, 'Longueur minimale du mot de passe', 'securite' ),
			'deux_facteurs_admin'        => array( 'bool', true, 'Double authentification obligatoire pour Administration et Finance et les administrateurs du site', 'securite' ),
			'deux_facteurs_tous'         => array( 'bool', false, 'Double authentification obligatoire pour tous les comptes (code par e-mail ; chaque compte doit avoir une adresse)', 'securite' ),
			'revue_comptes_le'           => array( 'date', '', 'Dernière revue des comptes', 'interne' ),
		);
	}

	/**
	 * Enregistre les valeurs par défaut manquantes.
	 */
	public static function install_defaults() {
		$current = get_option( self::OPTION, array() );
		foreach ( self::definitions() as $key => $def ) {
			if ( ! array_key_exists( $key, (array) $current ) ) {
				$current[ $key ] = $def[1];
			}
		}
		update_option( self::OPTION, $current, false );
	}

	/**
	 * Valeur d'un réglage.
	 *
	 * @param string $key Clé.
	 * @return mixed
	 */
	public static function get( $key ) {
		$defs   = self::definitions();
		$values = get_option( self::OPTION, array() );
		return $values[ $key ] ?? ( $defs[ $key ][1] ?? null );
	}

	/**
	 * Enregistre des réglages (valeurs déjà reçues du formulaire), avec journal d'audit.
	 *
	 * @param array $input Valeurs brutes.
	 * @return array Réglages enregistrés.
	 */
	public static function save( array $input ) {
		$before = get_option( self::OPTION, array() );
		$after  = $before;
		foreach ( self::definitions() as $key => $def ) {
			if ( 'interne' === $def[3] ) {
				continue;
			}
			if ( 'bool' === $def[0] ) {
				$after[ $key ] = ! empty( $input[ $key ] );
			} elseif ( 'int' === $def[0] && isset( $input[ $key ] ) ) {
				$val           = ige_ffm_parse_montant( $input[ $key ] );
				$after[ $key ] = null === $val ? $before[ $key ] ?? $def[1] : $val;
			}
		}
		// Bornes de sécurité.
		$after['seuil_justification'] = min( 100, max( 0, (int) $after['seuil_justification'] ) );
		$after['alerte_rubrique']     = min( 100, max( 1, (int) $after['alerte_rubrique'] ) );
		$after['verrouillage_essais'] = max( 3, (int) $after['verrouillage_essais'] );
		$after['inactivite_minutes']  = min( 240, max( 5, (int) $after['inactivite_minutes'] ) );
		$after['mdp_longueur']        = max( 10, (int) $after['mdp_longueur'] );

		update_option( self::OPTION, $after, false );
		$changed = array_keys( array_diff_assoc( array_map( 'strval', $after ), array_map( 'strval', $before ) ) );
		if ( $changed ) {
			$flip = array_flip( $changed );
			IGE_FFM_Audit::log( 'modification_reglages', 'reglages', '', array_intersect_key( $before, $flip ), array_intersect_key( $after, $flip ) );
		}
		return $after;
	}

	/**
	 * Marque une valeur interne (ex. date de revue des comptes).
	 *
	 * @param string $key   Clé.
	 * @param mixed  $value Valeur.
	 */
	public static function set_internal( $key, $value ) {
		$values         = get_option( self::OPTION, array() );
		$values[ $key ] = $value;
		update_option( self::OPTION, $values, false );
	}
}
