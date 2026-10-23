# Journal des versions

## 0.1.0 — 23 octobre 2026 · Étape 1 : socle

- Schéma complet du lot 1 (16 tables) et mise à jour automatique du schéma.
- Données initiales : exercice 2026 (budget facultatif), 26 services, 8 catégories, rubriques R01 à R10, 4 modes de paiement.
- Rôles Agent, Responsable, Administration et Finance, Lecteur ; 11 capacités.
- Écrans : référentiels, comptes utilisateurs (création, modification, désactivation, réinitialisation, revue trimestrielle), exercice et règles de contrôle, journal d'audit (filtres, export CSV, vérification d'intégrité), première connexion.
- Sécurité : verrouillage après échecs, comptes désactivés, mot de passe provisoire obligatoire à changer, politique de mot de passe, déconnexion après inactivité, session limitée à 12 h, administration WordPress réservée aux administrateurs.
- Journal d'audit chaîné par empreintes SHA-256.
- 62 tests automatiques.

Thème associé : IGE Frais de fonctionnement 1.0.1 (l'élément de menu « Administration » reste actif sur toutes les pages d'administration).
