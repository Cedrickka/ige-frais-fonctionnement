# Extension « IGE Frais de fonctionnement (métier) »

Logique métier de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement. Elle s'utilise avec le thème **IGE Frais de fonctionnement** (version 1.0.1 ou plus), qui fournit l'interface.

Version **0.1.0 — étape 1 du lot 1 : socle**. WordPress 6.5 ou plus (testé sur 7.1.3), PHP 8.0 ou plus.

## 1. Ce que contient l'étape 1

| Élément | Contenu |
| --- | --- |
| Base de données | 16 tables `{prefix}ige_*` pour tout le lot 1 : exercices, services, catégories, rubriques, modes de paiement, budgets, soldes initiaux, révisions budgétaires, comptes, fournisseurs, écritures, lots de transmission, pièces jointes, avances, arrêtés de caisse, journal d'audit. Montants en francs congolais stockés en entiers. |
| Données initiales | Exercice 2026 (budget facultatif), 26 services, 8 catégories (liste du fichier service), rubriques R01 à R10 (R02 reclassée en « Consommables informatiques »), 4 modes de paiement. |
| Rôles | Agent, Responsable, Administration et Finance (`ige_admin`), Lecteur (Direction), avec 11 capacités `ige_*` vérifiées côté serveur. |
| Écrans | Référentiels, Comptes utilisateurs, Exercice et règles de contrôle, Journal d'audit, Première connexion. |
| Sécurité | Verrouillage après 5 échecs (15 min), comptes désactivables, mot de passe provisoire à changer à la première connexion (10 caractères, majuscule, minuscule, chiffre), déconnexion après 30 min d'inactivité, session limitée à 12 h, administration WordPress fermée aux utilisateurs de l'application. Tous ces seuils sont réglables. |
| Journal d'audit | Chaque action sensible est enregistrée (qui, quoi, quand, avant et après, adresse IP). Les lignes sont chaînées par empreintes SHA-256 : une modification directe dans la base est détectée par « Vérifier l'intégrité ». Export CSV pour Excel. |

Les autres pages de l'application affichent encore le contenu de démonstration, avec une bannière « Écran en préparation » qui indique l'étape où elles deviendront fonctionnelles.

## 2. Installation sur ige-gestion.com

1. Le thème **IGE Frais de fonctionnement 1.0.1** doit être actif. Si la version 1.0.0 est installée : Apparence > Thèmes > Ajouter > Téléverser `ige-ff.zip` > « Remplacer la version installée ».
2. Extensions > Ajouter > Téléverser une extension > `ige-ff-metier.zip` > Installer > **Activer**. Les tables, les rôles et les données initiales sont créés à l'activation.
3. Vider le cache LiteSpeed dans hPanel.
4. Ouvrir **Administration > Comptes utilisateurs** et créer les comptes. Chaque mot de passe provisoire s'affiche **une seule fois** : le noter et le remettre en main propre à la personne.
5. Ouvrir **Administration > Exercice et règles de contrôle** et vérifier les seuils.

Les administrateurs techniques du site (rôle WordPress « Administrateur ») ont accès à tout. Pour le travail quotidien du comptable, créer un compte au profil **Administration et Finance** : il gère l'application mais ne peut pas toucher à WordPress lui-même.

## 3. Tests

Sur le site de test uniquement (le script crée puis supprime des données) :

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-socle.php
```

Résultat attendu : `62 réussis, 0 échoués`. Les tests couvrent le schéma, les données initiales, les droits de chaque rôle, la validation des référentiels, la création et la modification des comptes, la robustesse des mots de passe, le verrouillage, la désactivation, les bornes des règles et la détection d'une falsification du journal.

Des tests d'interface ont aussi été passés : création d'un service et d'un compte, refus d'un doublon, première connexion avec changement de mot de passe, refus d'accès d'un agent aux écrans d'administration et à l'administration WordPress, refus d'une requête forgée (code 403), message de compte verrouillé.

## 4. Points de vigilance

- **Désinstallation** : supprimer l'extension ne supprime **jamais** les données comptables. Pour tout effacer sur un site de test, ajouter `define( 'IGE_FFM_SUPPRIMER_DONNEES', true );` dans `wp-config.php` avant de supprimer l'extension.
- **Mot de passe provisoire** : il transite quelques minutes dans la base (message affiché une fois) avant d'être effacé ; il est inutilisable après la première connexion.
- **Journal d'audit** : l'application ne permet ni de modifier ni de supprimer une ligne. Un accès direct à la base (phpMyAdmin) le permettrait, mais la vérification d'intégrité le signalerait.
- **Double authentification** : pour les comptes Administration et Finance, installer en complément l'extension officielle « Two Factor » de WordPress.org et l'activer pour ces comptes, en attendant l'intégration native prévue à l'étape 5.
- **Revue du code** : avant la mise en production réelle, faire relire le code par un développeur ou un auditeur indépendant, en particulier les contrôles d'accès.

## 5. Pour les développeurs

- Point d'entrée : `ige-ff-metier.php`. Classes dans `includes/`, écrans dans `includes/ecrans/`.
- Toute écriture passe par une classe métier (`IGE_FFM_Referentiels`, `IGE_FFM_Comptes`, `IGE_FFM_Reglages`) qui valide, enregistre et inscrit au journal (`IGE_FFM_Audit::log()`).
- Chaque formulaire : jeton (`ige_ffm_check_form`), capacité (`ige_ffm_require`), requêtes préparées, puis redirection (motif POST/redirection/GET).
- Les écrans remplacent le contenu des pages créées par le thème (filtre `the_content`, page identifiée par son slug) et alimentent le bandeau de titre (`ige_ff_title_band`).
- Fonctions utiles pour les étapes suivantes : `IGE_FFM_Comptes::service_id()`, `IGE_FFM_Referentiels::exercice_courant()`, `IGE_FFM_Reglages::get()`.

## 6. Étapes suivantes du lot 1

2. Saisie : budget du service, écritures, contrôles bloquants, livre de caisse, paiement d'un engagement, contrepassation, pièces jointes, fournisseurs.
3. Circuit avec le central : transmission, file de vérification, détection des anomalies, validation et rejet.
4. Fonds et caisse : avances, règle des 80 %, arrêté et clôture de caisse, comptage inopiné, clôture d'exercice.
5. Pilotage : tableaux de bord, consolidation, rapports PDF avec code QR, vérification publique, exports Excel, double authentification.
