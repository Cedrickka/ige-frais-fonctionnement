# Extension « IGE Frais de fonctionnement (métier) »

Logique métier de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement. Elle s'utilise avec le thème **IGE Frais de fonctionnement** (version 1.0.1 ou plus), qui fournit l'interface.

Version **1.2.0** (lot 1 complet + déploiement + exports Excel et PDF au format de la charte). Thème requis : IGE Frais de fonctionnement **1.0.2**. WordPress 6.5 ou plus (testé sur 7.1.3), PHP 8.0 ou plus.

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

### Étape 5 : pilotage (version 1.0.0)

| Écran | Contenu |
| --- | --- |
| Tableau de bord (central) | Budget consommé, caisse consolidée, avances à justifier, écritures à vérifier ; exécution par service avec le repère du temps écoulé ; alertes prioritaires (rubrique à 80 % ou dépassée, avance de plus de 90 jours, écritures non transmises depuis N jours, anomalies, arrêtés manquants) ; avances par ancienneté ; consommation par catégorie ; dépenses par mois. |
| Consolidation | Par service, catégorie, rubrique et mois, sur les seules écritures validées (RG-14) ; export Excel (CSV) de chaque vue. |
| Rapports officiels | Situation mensuelle d'exécution, état consolidé, état des avances non justifiées, rapport annuel ; registre non modifiable ; mise en page de la charte, signataire, code QR ; impression ou enregistrement en PDF depuis le navigateur. |
| Synthèse (Direction) | Vue d'une page pour l'Inspecteur Général : taux de consommation, caisse, avances, points d'attention, derniers rapports. |
| Vérifier un document (public) | Saisie ou lecture du code QR : document authentique, altéré ou inconnu, sans aucune donnée financière ; 30 vérifications par minute et par adresse au plus. |
| Sécurité du compte | Double authentification : activation par code QR, codes de secours, nouveaux codes, désactivation (si facultative). |

**Documents officiels** : chaque rapport émis reçoit une référence (`IGE-AF-2026-0001`), son contenu est figé et protégé par une empreinte SHA-256 ; toute modification directe dans la base est signalée « altéré » à la vérification. Les procès-verbaux d'arrêté (à la signature du Responsable) et de comptage inopiné (à la contresignature) sont inscrits automatiquement au registre (`IGE-PV-2026-0001`) et portent un code QR ; si les données d'un arrêté changent après coup, le procès-verbal l'affiche.

**Double authentification** : codes à 6 chiffres (norme TOTP, RFC 6238) lus dans Google Authenticator, Microsoft Authenticator ou FreeOTP ; secret chiffré dans la base ; 10 codes de secours à usage unique conservés sous forme d'empreintes ; un code ne peut pas resservir ; 5 essais et 5 minutes au plus. Obligatoire pour Administration et Finance et les administrateurs du site (réglable), facultative pour les autres. Un administrateur peut la réinitialiser pour une autre personne (téléphone perdu) depuis Comptes utilisateurs. **Urgence** : si le dernier administrateur perd téléphone et codes de secours, ajouter `define( 'IGE_FFM_DESACTIVER_2FA', true );` dans `wp-config.php`, se connecter, réinitialiser, puis retirer la ligne.

**Après la mise à jour** : les comptes Administration et Finance et l'administrateur du site seront invités à activer la double authentification à leur prochaine connexion. Prévoir un téléphone avec l'application installée, et imprimer les codes de secours. L'extension « Two Factor » installée provisoirement peut alors être désactivée.

Le PDF est produit par le navigateur (« Imprimer » puis « Enregistrer au format PDF ») : la mise en page A4 de la charte est prévue pour l'impression ; aucune bibliothèque PDF n'est installée sur le serveur.

Les pages du lot 2 affichent encore le contenu de démonstration, avec une bannière « Écran en préparation » qui indique l'étape où elles deviendront fonctionnelles.

### Version 1.1.0 : déploiement

| Élément | Contenu |
| --- | --- |
| Messagerie de la plateforme | Tous les e-mails partent de `contact@ige-gestion.com` en SMTP authentifié (smtp.hostinger.com, port 465 SSL ou 587 TLS). Réglage dans **Exercice et règles de contrôle > Messagerie** (mot de passe conservé chiffré, jamais réaffiché) ou par constantes dans `wp-config.php` : `IGE_FFM_SMTP_HOTE`, `IGE_FFM_SMTP_PORT`, `IGE_FFM_SMTP_UTILISATEUR`, `IGE_FFM_SMTP_MOT_DE_PASSE`, `IGE_FFM_SMTP_EXPEDITEUR`. Bouton « Envoyer un e-mail de test ». Échecs d'envoi journalisés. |
| Double authentification par e-mail | Méthode par défaut : à chaque connexion, un code à 6 chiffres (valable 10 minutes, usage unique, conservé sous forme d'empreinte) est envoyé à l'adresse du compte. Renvoi possible après une minute (3 au plus) ; le code précédent est alors annulé. Le code n'est jamais dans le sujet du message. L'application d'authentification reste possible au choix. Codes de secours dans les deux cas. Nouvelle règle « obligatoire pour tous les comptes ». |
| Invitations | « Inviter par e-mail » (Comptes utilisateurs) : lien personnel valable 24 h pour choisir son mot de passe ; aucun mot de passe ne circule. |
| Déploiement (Administration) | Tableau de préparation des services (comptes, Responsable, adresses manquantes, comptes activés, écritures reprises, budget, état : à préparer, en préparation, prêt, en service) ; création des comptes en nombre depuis un fichier CSV avec envoi des invitations ; reprise des fichiers Excel. |
| Reprise Excel | Classeur central (états validée, rejetée, à vérifier et motifs) puis un fichier par service : solde initial, budget, livre de caisse. Identifiants et numéros conservés, contrepassations reliées, dates converties, aperçu avec erreurs et avertissements avant import, rien en double, refus si le service a déjà commencé à saisir dans l'application. Macros jamais exécutées. |

Fichier CSV des comptes (séparateur `;`, enregistré depuis Excel « CSV UTF-8 » ou « CSV séparateur point-virgule ») :

```
identifiant;nom;email;profil;service
j.mbuyi;Jean Mbuyi;j.mbuyi@exemple.cd;RESPONSABLE;8000 IG
```

### Version 1.2.0 : exports Excel et PDF

| Export | Excel (.xlsx) | PDF |
| --- | --- | --- |
| Livre de caisse (filtres appliqués) | Oui | Oui (A4 paysage) |
| Consolidation | Oui, un onglet par vue (services, catégories, rubriques, mois) | Oui, la vue affichée |
| Journal d'audit | Oui | — |
| Rapports officiels (situation mensuelle, état consolidé, avances, rapport annuel) | — | Oui, avec code QR d'authenticité sur chaque page |
| Procès-verbaux d'arrêté et de comptage inopiné | — | Oui, avec code QR |

Les classeurs reprennent la charte : armoiries, intitulé du ministère, bande tricolore, titre, en-têtes bleu institutionnel, lignes alternées, montants au format `1 250 000` (négatifs en rouge), vraies dates, ligne de total, volets figés, filtres, impression A4 ajustée à la largeur avec en-têtes répétés et pagination. Les PDF sont produits par le serveur (aucune boîte d'impression du navigateur), en Cooper Hewitt et EB Garamond, avec pagination « Page n / N ». Les anciens exports CSV sont remplacés.

Bibliothèques incluses (dossier `vendor/`) : Dompdf 3.1.0 (LGPL 2.1), php-font-lib et php-svg-lib (LGPL), Masterminds HTML5 (MIT), PHP-CSS-Parser (MIT), qrcode-generator de Kazuhiko Arase (MIT, classes préfixées `IGE_QR`). Polices de la charte converties en TTF dans `assets/fonts/` (SIL Open Font License). Extensions PHP requises : gd, mbstring, dom, zip (présentes chez Hostinger). Le cache des polices est écrit dans `wp-content/uploads/ige-ffm-pdf/` (accès direct interdit).

## 2. Installation sur ige-gestion.com

**Mise à jour vers la 1.1.0** : après le téléversement, ouvrir **Exercice et règles de contrôle > Messagerie**, saisir `contact@ige-gestion.com` et le mot de passe de cette boîte (hPanel > E-mails), enregistrer, puis « Envoyer un e-mail de test ». Vérifier ensuite que **chaque compte a une adresse e-mail** : sans adresse, pas de code de connexion. Les comptes qui utilisent déjà une application d'authentification la gardent.

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

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-pilotage.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-deploiement.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-exports.php
```

Résultats attendus : `63`, `76`, `57`, `74`, `57`, `109` puis `33 réussis`, sans échec (469 tests). Le test du déploiement n'envoie aucun e-mail (ils sont interceptés) et utilise les fichiers d'essai de `tests/fixtures/`. Le test des fonds suppose qu'on soit au moins en mai de l'exercice ; il clôture un exercice fictif (2030) puis le supprime, sans toucher l'exercice réel. Les tests couvrent le schéma, les données initiales, les droits de chaque rôle, la validation des référentiels, la création et la modification des comptes, la robustesse des mots de passe, le verrouillage, la désactivation, les bornes des règles et la détection d'une falsification du journal.

Les tests de saisie couvrent les droits (lecteur, administrateur non rattaché, agent sur le budget), le blocage de la caisse négative et du dépassement du disponible, les validations (date future ou hors exercice, montant, rubrique, pièce, faux PDF, fichier PHP, plafond des espèces), le budget facultatif, les révisions motivées, le verrouillage du solde initial, le paiement d'un engagement, la contrepassation (et ses refus), le solde progressif du livre, la continuité de la numérotation, le mois clôturé, les doublons et la fusion des fournisseurs, le journal d'audit.

Les tests du circuit couvrent les droits de transmission, chaque anomalie, la numérotation et le récapitulatif des lots, la synthèse, les filtres, la validation en masse, le rejet motivé, la séparation des fonctions, la contrepassation d'un rejet, le paiement après validation (mise à jour sans doublon), le blocage du paiement d'un engagement rejeté, le renvoi sans contrepassation, la transmission automatique.

Les tests des fonds couvrent les droits, la création d'avance et son entrée de caisse, la justification premier entré premier sorti, la règle des 80 % et la dérogation, l'annulation, l'arrêté (billetage, dollars, autres disponibilités, écart à expliquer, double signature par deux personnes, verrouillage des mois, livre modifié après comptage), le comptage inopiné et sa contresignature, la préparation et la clôture d'exercice avec report des soldes.

Les tests du pilotage couvrent la consolidation (écritures validées seulement), le temps écoulé, les alertes, l'émission des quatre rapports, les droits, la vérification publique (authentique, inconnu, altéré), l'inscription des procès-verbaux, et la double authentification (vecteurs officiels de la RFC 6238, tolérance d'horloge, anti-rejeu, codes de secours, chiffrement du secret, obligation, réinitialisation).

Des tests d'interface ont aussi été passés pour l'étape 5 : activation de la double authentification (refus d'un mauvais code, codes de secours), connexion en deux étapes (code erroné, bon code, code de secours), tableau de bord, consolidation et export, émission de rapports avec code QR, vérification publique sur téléphone, synthèse de la Direction, aucun débordement sur téléphone ; pour l'étape 4 : avance, avance par dérogation, arrêté avec écart en direct, signatures de l'agent et du Responsable, procès-verbal, comptage inopiné et contresignature ; et pour l'étape 3 : transmission d'un lot de 10 écritures, file de vérification, rejet motivé depuis le panneau (refusé sans motif), validation en masse puis d'une sélection, rejet visible à l'accueil du service, contrepassation depuis « Rejets à corriger », accès refusé à un agent ; puis, pour l'étape 2 : saisie complète par un agent avec photo réduite de 1 Mo à 143 Ko, création d'un fournisseur pendant la saisie, refus d'un dépassement avec conservation des valeurs saisies, paiement d'un engagement, contrepassation, téléchargement contrôlé d'une pièce, export, consultation en lecture seule par le central, budget saisi par le Responsable, et pour l'étape 1 : création d'un service et d'un compte, refus d'un doublon, première connexion avec changement de mot de passe, refus d'accès d'un agent aux écrans d'administration et à l'administration WordPress, refus d'une requête forgée (code 403), message de compte verrouillé.

## 4. Points de vigilance

- **Désinstallation** : supprimer l'extension ne supprime **jamais** les données comptables. Pour tout effacer sur un site de test, ajouter `define( 'IGE_FFM_SUPPRIMER_DONNEES', true );` dans `wp-config.php` avant de supprimer l'extension.
- **Mot de passe provisoire** : il transite quelques minutes dans la base (message affiché une fois) avant d'être effacé ; il est inutilisable après la première connexion.
- **Journal d'audit** : l'application ne permet ni de modifier ni de supprimer une ligne. Un accès direct à la base (phpMyAdmin) le permettrait, mais la vérification d'intégrité le signalerait.
- **Double authentification par e-mail** : sa sûreté dépend de celle des boîtes des agents. Recommander des adresses professionnelles et un mot de passe de messagerie différent de celui de l'application. Si la messagerie tombe en panne, les codes de secours permettent de se connecter ; en dernier recours, `define( 'IGE_FFM_DESACTIVER_2FA', true );` dans `wp-config.php` suspend la double authentification.
- **Délivrabilité** : dans hPanel > Domaines > DNS, vérifier que les enregistrements SPF, DKIM et DMARC de ige-gestion.com sont actifs (Hostinger les propose) pour que les codes n'arrivent pas en indésirables.
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
5. ~~Pilotage~~ : livré en 1.0.0. **Le lot 1 est complet.**
6. Déploiement (1.1.0) : recette, reprise Excel, pilote sur 2 ou 3 services, puis généralisation aux 26 services (voir le plan de déploiement).
