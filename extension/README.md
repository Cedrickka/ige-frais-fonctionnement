# Extension « IGE Frais de fonctionnement (métier) »

Logique métier de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement. Elle s'utilise avec le thème **IGE Frais de fonctionnement** (version 1.0.1 ou plus), qui fournit l'interface.

Version **2.2.1** (lot 1 complet ; lot 2 : missions d'inspection, demandes de fonds, circuit de visas et chaîne de la dépense, rapprochement bancaire, plan de trésorerie, saisie hors connexion, double devise CDF/USD ; priorité 3 : parc automobile et gestion du charroi, stocks de fournitures, score de risque, comparaison des services, nomenclature budgétaire, rapport hebdomadaire ; dotations et bons carburant ; fonctions du site activables). Thème requis : IGE Frais de fonctionnement **1.0.2**. WordPress 6.5 ou plus (testé sur 7.1.3), PHP 8.0 ou plus.

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

### Version 1.3.0 : fin du lot 1

| Élément | Contenu |
| --- | --- |
| Échanges sur une écriture (VA-15) | Dans le panneau de vérification, Administration et Finance pose une question au service sans rejeter l'écriture. Le service est prévenu par e-mail et sur son accueil ; il répond dans Transmission > « Questions à traiter », avec une pièce s'il le faut. Le vérificateur est prévenu de la réponse. Filtre « Échanges en cours » dans la vérification ; la validation en masse écarte les écritures dont l'échange est ouvert ; valider ou rejeter clôt l'échange. Tout est conservé et journalisé. |
| Dossier complet (VA-11) | Chaque pièce jointe porte sa nature (facture, bon de réception, reçu…) choisie parmi les pièces exigées par la rubrique. Indicateur « Dossier complet / incomplet » avec la liste cochée dans le livre de caisse, la vérification et les questions ; nouvelle anomalie « Dossier incomplet » (désactivable). |
| Jours fériés | Les 10 jours fériés légaux de la RDC sont préremplis et modifiables dans Exercice et règles de contrôle, avec les jours exceptionnels décrétés (deuil national, élection…). L'anomalie « jour non ouvré » affiche le nom du jour férié. |
| Contrôle d'intégrité | Bouton « Lancer le contrôle » (Exercice et règles de contrôle) : chaîne du journal d'audit, empreintes des documents officiels, présence et empreinte de chaque pièce, unicité des écritures, version de la base. À lancer après toute restauration et chaque mois. |
| Sécurité | Revue de tous les points d'entrée (53 formulaires et téléchargements) : jeton, droit et périmètre du service vérifiés côté serveur ; accès direct interdit aux dossiers `vendor/` et `tests/`. |

### Version 2.0.0 : lot 2

| Élément | Contenu |
| --- | --- |
| Missions d'inspection (VA-40) | Écran Missions : ordre de mission (membres, catégorie, destination, zone, dates, transport), frais calculés selon le **barème** (indemnité par jour, hébergement par nuit, transport aller-retour par membre, frais divers par mission), circuit de visas, ordre de mission officiel en PDF avec code QR (registre des documents, préfixe IGE-OM), paiement par la caisse du service (sortie R07 liée à la mission, l'ordre de mission joint en pièce), dépôt du rapport et de l'état de paiement émargé, liquidation au retour (complément ou reversement automatique), annulation avant paiement. Chiffres : coût réel, coût moyen, coût par membre, rapports manquants. **Le barème fourni est fictif** : Administration et Finance saisit le barème officiel dans Exercice et règles de contrôle > Barème des frais de mission. |
| Demandes de fonds (VA-03) | Le service exprime un besoin (objet, rubriques, montant, date de besoin, justification) ; visa du chef de service, puis Administration et Finance accorde (montant réduit possible, avec avis) ou refuse, puis visa de l'Inspecteur Général au-delà du seuil ; le décaissement crée l'avance de fonds et son entrée de caisse (règle des 80 % et dérogation inchangées). Notifications par e-mail à chaque étape. |
| Circuit de visas et chaîne de la dépense (VA-10, VA-14) | À partir d'un seuil réglable (1 000 000 CDF par défaut), un engagement suit liquidation (service fait, facture) puis ordonnancement (visas chef de service, Administration et Finance, Inspecteur Général au-delà de 5 000 000 CDF) avant de pouvoir être payé. En dessous, chaîne simplifiée. Écran « Dossiers de dépense » et écran « Visas » (tout ce qui attend le visa de la personne connectée) ; un refus exige un motif ; une personne ne vise qu'une fois un dossier. |
| Rapprochement bancaire (VA-06) | Compte(s) bancaire(s) avec solde de départ ; import du relevé CSV ou Excel (date, libellé, référence, débit/crédit ou montant ; aucun doublon au réimport) ; rapprochement automatique (même montant, dates proches, référence reconnue) puis manuel ; mouvements bancaires (dotations, frais) créés depuis le relevé ; explication des suspens ; écart inexpliqué calculé avec le solde final du relevé ; état de rapprochement en PDF ; alerte au tableau de bord au-delà de 60 jours de suspens. |
| Plan de trésorerie (VA-07) | Disponible (caisses des services et banque principale), fonds attendus saisis par mois, demandes de fonds et missions à venir, engagements non payés, dépenses courantes (moyenne des trois derniers mois) ; solde de fin par mois et alerte de tension. |
| Double devise (VA-51) | Taux du jour fixé par Administration et Finance (Trésorerie) ; saisie en dollars convertie en francs au taux de la date (refusée sans taux de moins de 31 jours) ; montant d'origine et taux conservés et affichés. |
| Saisie hors connexion (VA-50) | Application installable sur le téléphone ; la page Nouvelle écriture s'ouvre sans réseau ; l'écriture et la photo de la pièce sont rangées sur le téléphone puis envoyées automatiquement au retour du réseau, avec les mêmes contrôles qu'en ligne et sans doublon possible (clé unique par écriture). Page « Saisie hors connexion » : file d'attente, motifs de refus, envoi manuel. |

**Hypothèses à confirmer** : le compte bancaire principal porte les paiements des services par chèque ou virement, les avances remises par chèque ou virement et les mouvements saisis ; les espèces restent dans les caisses. Le plan de trésorerie peut compter deux fois une demande de fonds destinée à payer une mission. Les seuils de la chaîne et des visas se règlent dans « Circuit de la dépense, devises et banque ».

### Version 2.2.0 : dotations et bons carburant

| Élément | Contenu |
| --- | --- |
| Dotations de carburant | Parc automobile > Dotations et bons : dotation mensuelle par véhicule et/ou par service, en litres ou en francs congolais, valable chaque mois de l'exercice. Suivi du mois : dotation, consommé, reste, pourcentage. Alerte à 80 %. Un plein qui dépasse la dotation du véhicule ou celle de son service est refusé ; seule la gestion du parc peut l'enregistrer, avec une dérogation motivée conservée sur le plein et signalée. Dépassements au tableau de bord ; dotations visibles par le service sur sa page « Véhicules ». |
| Bons carburant | Carnets reçus des stations (préfixe, numéros de début et de fin, valeur en CDF ou en litres, facture, écriture d'achat) ; chaque bon est créé et suivi : en réserve, remis (à un véhicule et à son chauffeur), utilisé, annulé (motif). Dès qu'un carnet existe, un plein payé par bon exige le ou les numéros : bon inconnu, en réserve, déjà utilisé ou annulé refusé ; bon remis à un autre véhicule, montant ou litres supérieurs à la valeur des bons signalés. Alerte sur les bons remis depuis plus de 30 jours et non utilisés. Export Excel : onglets Dotations et Bons carburant. |

### Version 2.1.0 : priorité 3, stocks et fonctions du site

| Élément | Contenu |
| --- | --- |
| Fonctions du site | Administration > Fonctions du site : 12 modules activables (demandes de fonds, chaîne complète de la dépense, trésorerie, rapprochement bancaire, devise USD, missions, parc automobile, stocks, risques et comparaison, nomenclature, rapport hebdomadaire, saisie hors connexion). Une fonction désactivée disparaît des menus de tous les profils (la rubrique de menu vide aussi), sa page affiche « Fonction désactivée », ses formulaires sont refusés et ses alertes et e-mails s'arrêtent. Les données restent et reviennent à la réactivation. Le socle ne se désactive pas. Changements journalisés. |
| Devise USD | Désactivable : plus de saisie en dollars, plus de taux du jour, plus de billetage en dollars dans l'arrêté. Les écritures déjà saisies en dollars gardent leur mention. Si la trésorerie est désactivée mais le dollar actif, le taux du jour se fixe dans Exercice et règles de contrôle. |
| Parc automobile (VA-41, gestion du charroi) | Véhicules (fiche, affectation à un service ou pool central, chauffeur attitré, compteur, consommation de référence, réservoir, statut en service / immobilisé / réformé) ; chauffeurs et permis ; documents de bord avec échéances (assurance, contrôle technique, vignette obligatoires ; carte rose, autorisation) et scans ; demandes de véhicule des services ; attribution contrôlée (véhicule en service et libre, places, assurance et contrôle technique valides, permis et disponibilité du chauffeur) ; ordres de route OR-AAAA-NNN et carnet de bord (compteur au départ et au retour, kilomètres non justifiés signalés, observations transmises au gestionnaire) ; pleins (bons, cartes, consommation aux 100 km entre deux pleins complets, anomalies : surconsommation, prix au litre, compteur, réservoir) ; entretiens planifiés et réalisés avec prochaine échéance en km ou en date, réparations avec immobilisation automatique, pneumatiques, sinistres décrits ; coût par véhicule et par km ; alertes au tableau de bord ; export Excel (4 onglets). Écran des services « Véhicules » : demande, départ et retour, pleins des véhicules affectés. |
| Stocks de fournitures (VA-42) | Catalogue d'articles (unité, catégorie, seuil) ; bons d'entrée (fournisseur, bon de livraison, lien vers l'écriture d'achat) et de sortie par bureau, en plusieurs lignes, tout ou rien ; coût moyen unitaire pondéré ; journal des mouvements filtrable ; consommation par bureau et par agent ; inventaire avec ajustement motivé des écarts ; alerte de réapprovisionnement ; export Excel. |
| Risques (VA-21) | Score de 0 à 100 par service : rejets (20), retard de transmission (15), avances non justifiées après 60 jours (20), anomalies (20), arrêtés en retard (15), écarts de caisse (10) ; niveaux faible, moyen, élevé ; détail de chaque facteur. |
| Comparaison des services (VA-23) | Effectif saisi pour chaque service (Référentiels) ; dépenses validées, budget, exécution, budget et dépense par agent, indice par rapport à la médiane (signalé au-delà de 150), filtre par rubrique. |
| Nomenclature de l'État (VA-33) | Référentiel des lignes officielles ; chaque rubrique se rattache à une ligne (contrôlé) ; vue « Par ligne de la nomenclature » dans la consolidation, en Excel et en PDF. |
| Rapport hebdomadaire (VA-34) | Administration > Notifications : jour, heure, copies ; envoyé aux comptes Administration et Finance (écritures à vérifier, alertes, fonds à décaisser, services à risque), une fois par semaine ; aperçu et envoi à soi-même. |

## 2. Installation sur ige-gestion.com

**Mise à jour vers la 2.2.0** : téléverser l'extension (schéma version 11 : dotations, carnets, bons). Puis Parc automobile > Dotations et bons : fixer les dotations, enregistrer les carnets en stock et remettre les bons aux véhicules. Tant qu'aucun carnet n'est enregistré, le numéro de bon reste facultatif.

**Mise à jour vers la 2.1.0** : téléverser l'extension ; le schéma passe en version 10 (10 nouvelles tables, effectif des services), les pages « Fonctions du site » et « Véhicules » se créent et s'ajoutent aux menus, la Direction reçoit « Risques » et « Parc automobile ». Puis : (1) Administration > Fonctions du site : désactiver ce qui n'est pas encore utilisé ; (2) Référentiels > Services : saisir l'effectif de chaque service ; (3) Référentiels > Nomenclature de l'État, puis rattacher les rubriques ; (4) Parc automobile : chauffeurs, véhicules et documents de bord ; (5) Stocks : catalogue, puis un inventaire d'ouverture (bon d'entrée au prix connu) ; (6) Notifications : jour du rapport hebdomadaire. La tâche planifiée du rapport repose sur WP-Cron : sur Hostinger, ajouter si possible une tâche cron qui appelle `wp-cron.php` toutes les heures.

**Mise à jour vers la 2.0.0** : téléverser l'extension (« Remplacer la version installée ») ; le schéma passe en version 9 sans perte de données. Puis, avec un compte Administration et Finance : (1) Exercice et règles de contrôle > vérifier les seuils de « Circuit de la dépense, devises et banque » et saisir le **barème officiel** des missions ; (2) Fonds > Trésorerie > fixer le **taux du jour** ; (3) Fonds > Rapprochement bancaire > déclarer le compte avec le solde du dernier relevé déjà rapproché. Vider le cache LiteSpeed. Le profil Lecteur (Direction) reçoit le droit de viser au-delà du seuil : vérifier que seuls l'Inspecteur Général et ses adjoints ont ce profil.

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

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-priorite1.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-lot2.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-lot3.php
```

```
wp eval-file wp-content/plugins/ige-ff-metier/tests/test-carburant.php
```

Résultats attendus : `88`, `76`, `57`, `74`, `57`, `109`, `33`, `51`, `102`, `102` puis `40 réussis`, sans échec (789 tests). Le test du déploiement n'envoie aucun e-mail (ils sont interceptés) et utilise les fichiers d'essai de `tests/fixtures/`. Le test des fonds suppose qu'on soit au moins en mai de l'exercice ; il clôture un exercice fictif (2030) puis le supprime, sans toucher l'exercice réel. Les tests couvrent le schéma, les données initiales, les droits de chaque rôle, la validation des référentiels, la création et la modification des comptes, la robustesse des mots de passe, le verrouillage, la désactivation, les bornes des règles et la détection d'une falsification du journal.

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
- **Lot 2** : le barème des missions et les taux de change d'exemple sont fictifs ; rien ne doit être payé avant la saisie du barème officiel. Les écritures en attente hors connexion vivent dans le navigateur du téléphone : un téléphone perdu ou réinitialisé avant l'envoi les perd (la pièce papier fait foi).
- **Version 2.1** : la consommation de référence de chaque véhicule et le prix de référence du litre sont à saisir par Administration et Finance ; sans eux, les contrôles de surconsommation et de prix ne s'appliquent pas. Les coûts du parc et la valeur du stock viennent des saisies du parc et des stocks : relier les pleins, entretiens et entrées aux écritures du livre de caisse pour les rapprocher des dépenses.
- **Revue du code** : la revue interne de la 1.3.0, de la 2.0.0 et de la 2.1.0 a contrôlé chaque point d'entrée (jeton, droit, périmètre du service), l'échappement des affichages et les requêtes SQL. Elle ne remplace pas une relecture par un développeur ou un auditeur indépendant avant la mise en production réelle, en particulier des contrôles d'accès.

## 5. Sauvegarde et restauration

Hostinger fait une sauvegarde automatique chaque jour (hPanel > Fichiers > Sauvegardes) ; elle couvre les fichiers et la base. Avant chaque mise à jour de l'extension, chaque reprise Excel et chaque vague de déploiement, faire en plus une sauvegarde manuelle.

Restauration (à faire une fois en recette pour la chronométrer) :

1. Prévenir les services et suspendre la saisie.
2. hPanel > Sauvegardes : restaurer la base de données à la date voulue, puis les fichiers. Les pièces justificatives sont dans `wp-content/uploads/ige-pieces` : la base seule ne suffit pas.
3. Vider le cache LiteSpeed et se reconnecter.
4. Exercice et règles de contrôle > Contrôle d'intégrité > « Lancer le contrôle ». Tous les points doivent être conformes ; une pièce absente signifie que les fichiers n'ont pas été restaurés.
5. Les écritures saisies entre la sauvegarde et la panne sont perdues : demander aux services concernés de les ressaisir (leurs pièces papier font foi), puis noter l'incident dans le registre de déploiement.

## 6. Pour les développeurs

- Point d'entrée : `ige-ff-metier.php`. Classes dans `includes/`, écrans dans `includes/ecrans/`.
- Toute écriture passe par une classe métier (`IGE_FFM_Referentiels`, `IGE_FFM_Comptes`, `IGE_FFM_Reglages`, `IGE_FFM_Ecritures`, `IGE_FFM_Budget`, `IGE_FFM_Fournisseurs`, `IGE_FFM_Pieces`) qui valide, enregistre et inscrit au journal (`IGE_FFM_Audit::log()`).
- Chaque formulaire : jeton (`ige_ffm_check_form`), capacité (`ige_ffm_require`), requêtes préparées, puis redirection (motif POST/redirection/GET).
- Les écrans remplacent le contenu des pages créées par le thème (filtre `the_content`, page identifiée par son slug) et alimentent le bandeau de titre (`ige_ff_title_band`).
- Fonctions utiles pour les étapes suivantes : `IGE_FFM_Contexte` (exercice, service de l'utilisateur, droits de voir, saisir, budgéter), `IGE_FFM_Budget::caisse()`, `::suivi()`, `IGE_FFM_Ecritures::livre()`, `::etat()`, `IGE_FFM_Reglages::get()`.
- Le script `assets/ige-ffm.js` (pages Nouvelle écriture et Livre de caisse) ne fait que de l'affichage et de la réduction des photos : tous les contrôles sont refaits côté serveur.

## 7. Étapes suivantes du lot 1

2. ~~Saisie~~ : livrée en 0.2.0.
3. ~~Circuit avec le central~~ : livré en 0.3.0.
4. ~~Fonds et caisse~~ : livré en 0.4.0.
5. ~~Pilotage~~ : livré en 1.0.0. **Le lot 1 est complet.**
6. Déploiement (1.1.0) : recette, reprise Excel, pilote sur 2 ou 3 services, puis généralisation aux 26 services (voir le plan de déploiement).
