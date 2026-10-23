# Journal des versions

## 0.2.0 — 23 octobre 2026 · Étape 2 : saisie

- Écrans Accueil du service, Nouvelle écriture, Livre de caisse, Budget, Fournisseurs (remplacent les maquettes).
- Écritures : sorties payées ou engagées, entrées (approvisionnements, réglage « entrées manuelles »), numérotation continue par service, contrôles bloquants côté serveur (caisse, disponible, plafond des espèces, date, mois clôturé, pièce).
- Paiement d'un engagement ; contrepassation motivée (écriture inverse liée) ; aucune modification ni suppression.
- Pièces jointes protégées : vérification du type réel, 5 Mo, nom aléatoire, empreinte SHA-256, téléchargement après contrôle des droits ; réduction des photos dans le navigateur.
- Budget par rubrique saisi par le Responsable, facultatif en 2026 ; révisions motivées et historisées dès la première écriture ; solde initial verrouillé après la première écriture.
- Fournisseurs : création pendant la saisie avec réutilisation d'une fiche identique, détection des doublons, fusion, statistiques.
- Lecture seule des services pour les profils centraux (sélecteur de service).
- Export du livre de caisse (CSV pour Excel, cellules protégées contre les formules).
- Schéma version 3 (colonnes exercice, service et rubrique sur les révisions budgétaires).
- 76 tests automatiques supplémentaires (138 au total).

## 0.1.0 — 23 octobre 2026 · Étape 1 : socle

- Schéma complet du lot 1 (16 tables) et mise à jour automatique du schéma.
- Données initiales : exercice 2026 (budget facultatif), 26 services, 8 catégories, rubriques R01 à R10, 4 modes de paiement.
- Rôles Agent, Responsable, Administration et Finance, Lecteur ; 11 capacités.
- Écrans : référentiels, comptes utilisateurs (création, modification, désactivation, réinitialisation, revue trimestrielle), exercice et règles de contrôle, journal d'audit (filtres, export CSV, vérification d'intégrité), première connexion.
- Sécurité : verrouillage après échecs, comptes désactivés, mot de passe provisoire obligatoire à changer, politique de mot de passe, déconnexion après inactivité, session limitée à 12 h, administration WordPress réservée aux administrateurs.
- Journal d'audit chaîné par empreintes SHA-256.
- 62 tests automatiques.

Thème associé : IGE Frais de fonctionnement 1.0.1 (l'élément de menu « Administration » reste actif sur toutes les pages d'administration).
