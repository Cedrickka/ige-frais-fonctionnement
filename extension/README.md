# Extension « IGE Frais de fonctionnement (métier) »

Logique métier de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement. Elle s'utilise avec le thème **IGE Frais de fonctionnement** (version 1.0.1 ou plus), qui fournit l'interface.

Version **0.4.0 — étapes 1 à 4 du lot 1** (socle, saisie, circuit avec le central, fonds et caisse). WordPress 6.5 ou plus (testé sur 7.1.3), PHP 8.0 ou plus.

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

### Étape 3 : circuit avec le central (version 0.3.0)

| Écran | Contenu |
| --- | --- |
| Transmission (service) | Écritures prêtes à partir (nouvelles ou mises à jour), contrôles signalés, récapitulatif du lot (lignes, entrées, sorties, pièces jointes, anomalies), bouton « Transmettre le lot », lots précédents et leur état, rejets à corriger avec leur motif. |
| Vérification (Administration et Finance) | Synthèse (à vérifier, avec anomalie, validées, rejetées : nombre et montant des sorties), file filtrable par état, service et lot, validation en masse des écritures sans anomalie, validation ou rejet d'une sélection, panneau de détail (pièces affichées, pièces exigées par la rubrique, anomalies expliquées, disponible du service), journal des lots reçus. |

Lots numérotés par service et par exercice (`TR-8000IG-2026-001`). Une écriture transmise passe « À vérifier » ; l'engagement payé après envoi repasse « À retransmettre » et remplace la même ligne au central, sans doublon (RG-13).

Anomalies détectées à la réception (signalées, non bloquantes) : date hors exercice, rubrique inconnue, montant nul ou négatif, pièce manquante, caisse négative, dépassement du budget, et, selon les règles : doublon de facture (même n° de pièce et même bénéficiaire, tous services confondus), fractionnement sous le plafond des espèces sur 7 jours, jour non ouvré (samedi, dimanche, jours fériés), bénéficiaire identique à l'agent qui saisit, saisie tardive. **Les jours fériés retenus sont à vérifier par l'IGE** (1er et 4 janvier, 16 et 17 janvier, 6 avril, 1er et 17 mai, 30 juin, 1er août, 25 décembre) ; ils se complètent par le filtre `ige_ffm_jours_feries`.

Rejet : motif obligatoire ; le service le voit à son accueil et sur l'écran Transmission, et reçoit un courriel si les comptes ont une adresse. Avec la règle « Contrepassation obligatoire après un rejet » (par défaut), il contrepasse puis saisit l'écriture juste ; un engagement rejeté ne peut plus être payé. Sans cette règle, il peut renvoyer l'écriture après correction (par exemple une pièce ajoutée). Un vérificateur ne peut pas valider une écriture qu'il a lui-même saisie.

Nouvelle règle : « Transmettre automatiquement chaque écriture au central dès son enregistrement » (désactivée par défaut).

### Étape 4 : fonds et caisse (version 0.4.0)

| Écran | Contenu |
| --- | --- |
| Avances (Administration et Finance) | Fonds remis, justifiés, reste à justifier, ancienneté des reliquats (moins de 30, 60, 90 jours, plus de 90), filtres, détail par service (sources de fonds et leur apurement), nouvelle avance, annulation motivée. |
| Arrêté de caisse (service) | Mois par mois : billetage CDF, dollars au taux du jour, autres disponibilités (banque, mobile money), écart en direct avec le solde du livre à la fin du mois, explication obligatoire d'un écart, signature de l'agent puis du Responsable (deux personnes différentes), clôture du mois, procès-verbal imprimable, comptages inopinés à contresigner. |
| Caisses (Administration et Finance) | Pour chaque service : solde du livre, état de l'arrêté du mois précédent, écart, dernier comptage inopiné, mois clôturés ; comptage inopiné sur place en présence de l'agent. |
| Exercice et règles de contrôle | Préparation de l'exercice suivant (budgets saisissables à l'avance), clôture de l'exercice avec contrôles préalables. |

**Avances** : l'avance crée automatiquement l'entrée dans la caisse du service, déjà validée (numéro `AV-2026-001`). La justification se calcule « premier entré, premier sorti » : les sorties payées **et validées** par le central apurent d'abord le solde initial, puis les fonds reçus dans l'ordre. Une nouvelle avance n'est accordée que si la précédente est justifiée au seuil réglé (80 % par défaut) ; sinon, une dérogation motivée est possible et conservée. Le service ne peut pas annuler une avance ; le central l'annule par contrepassation si la caisse le permet.

**Arrêté mensuel** : la signature du Responsable clôture le mois et tous les précédents ; plus aucune écriture, avance ou contrepassation n'y est possible. La signature est refusée si le livre a changé depuis le comptage.

**Clôture d'exercice** : refusée tant qu'un service a des écritures non transmises, à vérifier ou des engagements non payés ; avertissement pour les services sans arrêté de décembre ; confirmation à taper (« CLÔTURER 2026 »). Le solde de caisse de chaque service devient son solde initial de l'exercice suivant, qui s'ouvre avec budget obligatoire.

**Un seul exercice ouvert à la fois** : « Préparer l'exercice suivant » le crée désormais à l'état « préparé » (budgets seulement) ; il s'ouvre à la clôture. Si un exercice suivant avait déjà été créé ouvert, la mise à jour le repasse en « préparé ».

**À faire après installation** : décocher « Les services saisissent eux-mêmes leurs approvisionnements » dans les règles, pour que toute entrée de caisse vienne d'une avance tracée (l'écran Avances le rappelle tant que c'est coché).

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

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-circuit.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-fonds.php
```

Résultats attendus : `62`, `76`, `57` puis `74 réussis`, sans échec. Le test des fonds suppose qu'on soit au moins en mai de l'exercice ; il clôture un exercice fictif (2030) puis le supprime, sans toucher l'exercice réel. Les tests couvrent le schéma, les données initiales, les droits de chaque rôle, la validation des référentiels, la création et la modification des comptes, la robustesse des mots de passe, le verrouillage, la désactivation, les bornes des règles et la détection d'une falsification du journal.

Les tests de saisie couvrent les droits (lecteur, administrateur non rattaché, agent sur le budget), le blocage de la caisse négative et du dépassement du disponible, les validations (date future ou hors exercice, montant, rubrique, pièce, faux PDF, fichier PHP, plafond des espèces), le budget facultatif, les révisions motivées, le verrouillage du solde initial, le paiement d'un engagement, la contrepassation (et ses refus), le solde progressif du livre, la continuité de la numérotation, le mois clôturé, les doublons et la fusion des fournisseurs, le journal d'audit.

Les tests du circuit couvrent les droits de transmission, chaque anomalie, la numérotation et le récapitulatif des lots, la synthèse, les filtres, la validation en masse, le rejet motivé, la séparation des fonctions, la contrepassation d'un rejet, le paiement après validation (mise à jour sans doublon), le blocage du paiement d'un engagement rejeté, le renvoi sans contrepassation, la transmission automatique.

Les tests des fonds couvrent les droits, la création d'avance et son entrée de caisse, la justification premier entré premier sorti, la règle des 80 % et la dérogation, l'annulation, l'arrêté (billetage, dollars, autres disponibilités, écart à expliquer, double signature par deux personnes, verrouillage des mois, livre modifié après comptage), le comptage inopiné et sa contresignature, la préparation et la clôture d'exercice avec report des soldes.

Des tests d'interface ont aussi été passés (ordinateur et téléphone) pour l'étape 4 : avance, avance par dérogation, arrêté avec écart en direct, signatures de l'agent et du Responsable, procès-verbal, comptage inopiné et contresignature ; et pour l'étape 3 : transmission d'un lot de 10 écritures, file de vérification, rejet motivé depuis le panneau (refusé sans motif), validation en masse puis d'une sélection, rejet visible à l'accueil du service, contrepassation depuis « Rejets à corriger », accès refusé à un agent ; puis, pour l'étape 2 : saisie complète par un agent avec photo réduite de 1 Mo à 143 Ko, création d'un fournisseur pendant la saisie, refus d'un dépassement avec conservation des valeurs saisies, paiement d'un engagement, contrepassation, téléchargement contrôlé d'une pièce, export, consultation en lecture seule par le central, budget saisi par le Responsable, et pour l'étape 1 : création d'un service et d'un compte, refus d'un doublon, première connexion avec changement de mot de passe, refus d'accès d'un agent aux écrans d'administration et à l'administration WordPress, refus d'une requête forgée (code 403), message de compte verrouillé.

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
3. ~~Circuit avec le central~~ : livré en 0.3.0.
4. ~~Fonds et caisse~~ : livré en 0.4.0.
5. Pilotage : tableaux de bord, consolidation, rapports PDF avec code QR, vérification publique, exports Excel, double authentification.
