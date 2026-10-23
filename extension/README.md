# Extension « IGE Frais de fonctionnement (métier) »

Logique métier de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement. Elle s'utilise avec le thème **IGE Frais de fonctionnement** (version 1.0.1 ou plus), qui fournit l'interface.

Version **0.2.0 — étapes 1 (socle) et 2 (saisie) du lot 1**. WordPress 6.5 ou plus (testé sur 7.1.3), PHP 8.0 ou plus.

## 1. Ce que contient l'étape 1

| Élément | Contenu |
| --- | --- |
| Base de données | 16 tables `{prefix}ige_*` pour tout le lot 1 : exercices, services, catégories, rubriques, modes de paiement, budgets, soldes initiaux, révisions budgétaires, comptes, fournisseurs, écritures, lots de transmission, pièces jointes, avances, arrêtés de caisse, journal d'audit. Montants en francs congolais stockés en entiers. |
| Données initiales | Exercice 2026 (budget facultatif), 26 services, 8 catégories (liste du fichier service), rubriques R01 à R10 (R02 reclassée en « Consommables informatiques »), 4 modes de paiement. |
| Rôles | Agent, Responsable, Administration et Finance (`ige_admin`), Lecteur (Direction), avec 11 capacités `ige_*` vérifiées côté serveur. |
| Écrans | Référentiels, Comptes utilisateurs, Exercice et règles de contrôle, Journal d'audit, Première connexion. |
| Sécurité | Verrouillage après 5 échecs (15 min), comptes désactivables, mot de passe provisoire à changer à la première connexion (10 caractères, majuscule, minuscule, chiffre), déconnexion après 30 min d'inactivité, session limitée à 12 h, administration WordPress fermée aux utilisateurs de l'application. Tous ces seuils sont réglables. |
| Journal d'audit | Chaque action sensible est enregistrée (qui, quoi, quand, avant et après, adresse IP). Les lignes sont chaînées par empreintes SHA-256 : une modification directe dans la base est détectée par « Vérifier l'intégrité ». Export CSV pour Excel. |

### Étape 2 : saisie (version 0.2.0)

| Écran | Contenu |
| --- | --- |
| Accueil du service | Solde de caisse, budget consommé (ou « sans budget » en 2026), engagements en cours, écritures à transmettre, rubriques sous tension, dernières écritures. |
| Nouvelle écriture | Sortie ou entrée (approvisionnement), rubrique, montant, description, fournisseur (liste, nouveau ou autre bénéficiaire), n° et fichier de la pièce (photo réduite automatiquement sur le téléphone), mode, statut payé ou engagé. Contrôles en direct : caisse après l'opération, disponible de la rubrique. |
| Livre de caisse | Écritures du service avec solde progressif, filtres (mois, rubrique, statut, état), détail, pièces jointes, paiement d'un engagement, contrepassation motivée, ajout d'une pièce, export Excel (CSV), impression. |
| Budget | Saisie par le Responsable du service (facultative en 2026), solde initial, exécution par rubrique, révisions motivées dès la première écriture, sorties par mois. |
| Fournisseurs | Répertoire commun (Administration et Finance) : montants payés, nombre de services, détection des doublons (nom normalisé, NIF, téléphone) et fusion. |

Contrôles appliqués côté serveur à chaque écriture : droit de saisie limité à son propre service, date dans l'exercice et non future, mois non clôturé, montant entier positif, rubrique active, n° et fichier de pièce (réglable), plafond des espèces, **caisse jamais négative**, **disponible jamais dépassé** quand la rubrique a un budget. Une écriture enregistrée ne se modifie ni ne se supprime : elle se corrige par **contrepassation** (écriture inverse, liée à l'originale). La numérotation par service et par exercice est continue (`8000IG-2026-000001`).

Pièces jointes : JPG, PNG, WEBP ou PDF de 5 Mo au plus, type vérifié sur le contenu, stockées hors de portée du public (`uploads/ige-pieces`, accès direct interdit), renommées au hasard, avec empreinte SHA-256 ; elles ne se téléchargent qu'après contrôle des droits.

En attendant le module des avances (étape 4), les services saisissent eux-mêmes leurs approvisionnements (« Entrée »), avec la provenance des fonds. Ce choix se désactive dans **Exercice et règles de contrôle** (« Les services saisissent eux-mêmes leurs approvisionnements »).

Les autres pages de l'application affichent encore le contenu de démonstration, avec une bannière « Écran en préparation » qui indique l'étape où elles deviendront fonctionnelles.

## 2. Installation sur ige-gestion.com

Mise à jour depuis la 0.1.0 : Extensions > Ajouter > Téléverser `ige-ff-metier.zip` > « Remplacer la version installée ». Le schéma est mis à jour automatiquement, sans perte de données. Vider ensuite le cache LiteSpeed.

Première installation :

1. Le thème **IGE Frais de fonctionnement 1.0.1** doit être actif. Si la version 1.0.0 est installée : Apparence > Thèmes > Ajouter > Téléverser `ige-ff.zip` > « Remplacer la version installée ».
2. Extensions > Ajouter > Téléverser une extension > `ige-ff-metier.zip` > Installer > **Activer**. Les tables, les rôles et les données initiales sont créés à l'activation.
3. Vider le cache LiteSpeed dans hPanel.
4. Ouvrir **Administration > Comptes utilisateurs** et créer les comptes. Chaque mot de passe provisoire s'affiche **une seule fois** : le noter et le remettre en main propre à la personne.
5. Ouvrir **Administration > Exercice et règles de contrôle** et vérifier les seuils.
6. Chaque Responsable de service ouvre **Budget** pour saisir son solde initial de caisse (avant la première écriture) et, s'il le souhaite, ses prévisions par rubrique.

Hostinger : la limite d'envoi de fichiers par défaut (`upload_max_filesize`) suffit pour les pièces de 5 Mo ; si un envoi échoue, la relever à 8 Mo dans hPanel > Avancé > Configuration PHP.

Les administrateurs techniques du site (rôle WordPress « Administrateur ») ont accès à tout. Pour le travail quotidien du comptable, créer un compte au profil **Administration et Finance** : il gère l'application mais ne peut pas toucher à WordPress lui-même.

## 3. Tests

Sur le site de test uniquement (le script crée puis supprime des données) :

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-socle.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-saisie.php
```

Résultats attendus : `62 réussis, 0 échoués` puis `76 réussis, 0 échoués`. Les tests couvrent le schéma, les données initiales, les droits de chaque rôle, la validation des référentiels, la création et la modification des comptes, la robustesse des mots de passe, le verrouillage, la désactivation, les bornes des règles et la détection d'une falsification du journal.

Les tests de saisie couvrent les droits (lecteur, administrateur non rattaché, agent sur le budget), le blocage de la caisse négative et du dépassement du disponible, les validations (date future ou hors exercice, montant, rubrique, pièce, faux PDF, fichier PHP, plafond des espèces), le budget facultatif, les révisions motivées, le verrouillage du solde initial, le paiement d'un engagement, la contrepassation (et ses refus), le solde progressif du livre, la continuité de la numérotation, le mois clôturé, les doublons et la fusion des fournisseurs, le journal d'audit.

Des tests d'interface ont aussi été passés (ordinateur et téléphone) : saisie complète par un agent avec photo réduite de 1 Mo à 143 Ko, création d'un fournisseur pendant la saisie, refus d'un dépassement avec conservation des valeurs saisies, paiement d'un engagement, contrepassation, téléchargement contrôlé d'une pièce, export, consultation en lecture seule par le central, budget saisi par le Responsable, et pour l'étape 1 : création d'un service et d'un compte, refus d'un doublon, première connexion avec changement de mot de passe, refus d'accès d'un agent aux écrans d'administration et à l'administration WordPress, refus d'une requête forgée (code 403), message de compte verrouillé.

## 4. Points de vigilance

- **Désinstallation** : supprimer l'extension ne supprime **jamais** les données comptables. Pour tout effacer sur un site de test, ajouter `define( 'IGE_FFM_SUPPRIMER_DONNEES', true );` dans `wp-config.php` avant de supprimer l'extension.
- **Mot de passe provisoire** : il transite quelques minutes dans la base (message affiché une fois) avant d'être effacé ; il est inutilisable après la première connexion.
- **Journal d'audit** : l'application ne permet ni de modifier ni de supprimer une ligne. Un accès direct à la base (phpMyAdmin) le permettrait, mais la vérification d'intégrité le signalerait.
- **Double authentification** : pour les comptes Administration et Finance, installer en complément l'extension officielle « Two Factor » de WordPress.org et l'activer pour ces comptes, en attendant l'intégration native prévue à l'étape 5.
- **Revue du code** : avant la mise en production réelle, faire relire le code par un développeur ou un auditeur indépendant, en particulier les contrôles d'accès.

## 5. Pour les développeurs

- Point d'entrée : `ige-ff-metier.php`. Classes dans `includes/`, écrans dans `includes/ecrans/`.
- Toute écriture passe par une classe métier (`IGE_FFM_Referentiels`, `IGE_FFM_Comptes`, `IGE_FFM_Reglages`, `IGE_FFM_Ecritures`, `IGE_FFM_Budget`, `IGE_FFM_Fournisseurs`, `IGE_FFM_Pieces`) qui valide, enregistre et inscrit au journal (`IGE_FFM_Audit::log()`).
- Chaque formulaire : jeton (`ige_ffm_check_form`), capacité (`ige_ffm_require`), requêtes préparées, puis redirection (motif POST/redirection/GET).
- Les écrans remplacent le contenu des pages créées par le thème (filtre `the_content`, page identifiée par son slug) et alimentent le bandeau de titre (`ige_ff_title_band`).
- Fonctions utiles pour les étapes suivantes : `IGE_FFM_Contexte` (exercice, service de l'utilisateur, droits de voir, saisir, budgéter), `IGE_FFM_Budget::caisse()`, `::suivi()`, `IGE_FFM_Ecritures::livre()`, `::etat()`, `IGE_FFM_Reglages::get()`.
- Le script `assets/ige-ffm.js` (pages Nouvelle écriture et Livre de caisse) ne fait que de l'affichage et de la réduction des photos : tous les contrôles sont refaits côté serveur.

## 6. Étapes suivantes du lot 1

2. ~~Saisie~~ : livrée en 0.2.0.
3. Circuit avec le central : transmission, file de vérification, détection des anomalies, validation et rejet.
4. Fonds et caisse : avances, règle des 80 %, arrêté et clôture de caisse, comptage inopiné, clôture d'exercice.
5. Pilotage : tableaux de bord, consolidation, rapports PDF avec code QR, vérification publique, exports Excel, double authentification.
