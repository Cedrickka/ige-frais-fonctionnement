# Journal des versions

## 2.4.0 — 26 octobre 2026 · Inventaire des services, profil du parc, nom de l'application

- Nom de l'application : « Gestion des frais de fonctionnement du trésor public » sur toutes les pages, e-mails, PDF, classeurs et dans l'onglet du navigateur (thème 1.0.3 recommandé ; l'extension met aussi le nom à jour).
- Inventaire des services (nouveau module, Logistique) : catalogue des biens, locaux par service, inventaires datés en cours puis validés, reprise et écarts avec l'inventaire précédent, import du fichier Excel « INVENTAIRE DE SERVICE », tableau consolidé biens × services, exports Excel et PDF. Réservé à Administration et Finance et à l'administrateur.
- Profil « Gestionnaire du parc automobile » : menu propre, accueil sur le parc. Le parc n'est plus visible que par ce profil, Administration et Finance et l'administrateur ; retiré du menu de la Direction. La page « Véhicules » des services reste disponible.
- Stocks de fournitures désactivés par défaut (activables dans Fonctions du site) ; conservés sur un site mis à jour qui a déjà des articles.
- Saisie d'une écriture : message de succès ou de refus avec le motif, toujours affiché en haut de la page, même derrière le cache de l'hébergeur.
- Rappel : la règle « Photo ou PDF de la pièce obligatoire » (distincte du n° de pièce) et l'onglet « Services et catégories » ajouté à côté de « Par service » sont arrivés en 2.3.0.
- Schéma version 13. 67 tests automatiques supplémentaires, 873 au total.

## 2.3.0 — 26 octobre 2026 · Consolidation croisée et pièce facultative

- Consolidation : nouvel onglet « Services et catégories », tableau croisé avec une ligne par service et une colonne par catégorie (rubriques rattachées rappelées dans l'en-tête), total par service et total général ; bascule « par rubrique » (R01 à R10). Montants consommés (payé + engagé) des écritures validées. Exports Excel et PDF (paysage) inclus.
- Pièce justificative : la photo ou le PDF devient facultatif. Deux règles distinctes dans Exercice et règles de contrôle : « N° de la pièce justificative obligatoire » (reprend l'ancienne valeur) et « Photo ou PDF obligatoire » (désactivée à la mise à jour). Un dossier sans pièce reste signalé « incomplet » au vérificateur ; la pièce peut être ajoutée plus tard depuis le livre de caisse.
- Schéma version 12 (migration des règles, tracée au journal d'audit). 7 tests automatiques supplémentaires.

## 2.2.1 — 24 octobre 2026 · Correctifs bons carburant et menus

- Saisie d'une plage de bons « BC-1001 à BC-1060 » : le « à » accentué en minuscules est désormais compris (seuls les deux numéros extrêmes étaient retenus).
- Une lettre A dans le préfixe d'un carnet (ex. « CA-7-9 ») n'est plus confondue avec « à ».
- Liens de menu des pages récentes rétablis automatiquement s'ils manquent (« Véhicules » pour les services ; « Visas », « Risques », « Parc automobile » pour la Direction ; « Fonctions du site » et « Déploiement » dans l'administration), par exemple quand l'extension a été activée avant le thème. Aucun doublon.
- 4 tests automatiques supplémentaires (793 au total).

## 2.2.0 — 24 octobre 2026 · Dotations et bons carburant

- Dotations mensuelles de carburant par véhicule et par service (litres ou CDF), suivi du mois, alerte à 80 %, refus au-delà sauf dérogation motivée de la gestion du parc.
- Bons carburant : carnets numérotés, remise aux véhicules et chauffeurs, contrôle à l'utilisation (inconnu, en réserve, déjà utilisé, annulé, autre véhicule, valeur dépassée), alerte sur les bons non utilisés, annulation motivée.
- Nouvel onglet « Dotations et bons », dotations visibles par les services, export Excel complété.
- Schéma version 11. 40 tests automatiques supplémentaires (789 au total).

## 2.1.0 — 24 octobre 2026 · Priorité 3, stocks et fonctions du site

- Fonctions du site : 12 modules activables par Administration et Finance ; menus, pages, formulaires, alertes et e-mails suivent ; données conservées.
- Devise USD désactivable (saisie, taux du jour, billetage de l'arrêté).
- Parc automobile et gestion du charroi (VA-41) : véhicules, chauffeurs, documents de bord et échéances, demandes de véhicule, attribution contrôlée, ordres de route et carnet de bord, carburant et consommation, entretiens planifiés, réparations et immobilisation, sinistres, coûts par véhicule et par km, alertes, export Excel ; écran « Véhicules » pour les services.
- Stocks de fournitures (VA-42) : articles, bons d'entrée et de sortie par bureau, coût moyen pondéré, consommation par bureau, inventaire, seuils, export Excel.
- Score de risque par service (VA-21) et comparaison des services avec effectifs (VA-23).
- Nomenclature budgétaire de l'État (VA-33) : référentiel, rattachement contrôlé, consolidation par ligne.
- Rapport hebdomadaire par e-mail (VA-34) et écran Notifications.
- Schéma version 10. 112 tests automatiques supplémentaires (746 au total).

## 2.0.0 — 24 octobre 2026 · Lot 2

- Missions d'inspection (VA-40) : ordre de mission, barème paramétrable (fictif tant que le barème officiel n'est pas saisi), visas, ordre de mission officiel en PDF avec code QR, paiement par la caisse du service avec l'ordre de mission en pièce, rapport de retour, liquidation (complément ou reversement).
- Demandes de fonds (VA-03) avec visas, accord (réduction motivée), refus, décaissement en avance de fonds.
- Circuit de visas (VA-14) et chaîne de la dépense (VA-10) : liquidation et ordonnancement au-delà d'un seuil réglable, visa de l'Inspecteur Général au-delà de 5 000 000 CDF ; écrans « Dossiers de dépense » et « Visas ».
- Rapprochement bancaire (VA-06) : comptes, import CSV/Excel sans doublon, rapprochement automatique et manuel, mouvements, suspens expliqués, état en PDF, alerte.
- Plan de trésorerie (VA-07) et taux du jour ; saisie en dollars convertie en francs (VA-51).
- Saisie hors connexion (VA-50) : application installable, file d'attente sur le téléphone, envoi automatique par l'API sans doublon.
- Correctif : adresse des pages enfants (ex. « Saisie hors connexion »).
- Schéma version 9 (11 nouvelles tables). 102 tests automatiques supplémentaires (634 au total).

## 1.3.0 — 24 octobre 2026 · Fin du lot 1

- Échanges sur une écriture (VA-15) : question du central sans rejet, réponse du service avec pièce, notifications par e-mail, filtre « Échanges en cours », clôture à la validation ou au rejet.
- Dossier complet (VA-11) : nature de chaque pièce, indicateur et liste cochée des pièces exigées par la rubrique, anomalie « Dossier incomplet ».
- Jours fériés de la RDC (10 jours légaux) modifiables, jours exceptionnels, nom du jour férié dans l'anomalie.
- Contrôle d'intégrité de l'application (après restauration, mensuel) et procédure de sauvegarde et de restauration.
- Revue de sécurité des points d'entrée ; accès direct interdit à `vendor/` et `tests/`.
- Schéma version 8 (table des échanges, nature des pièces).
- 52 tests automatiques supplémentaires (521 au total).

## 1.2.0 — 24 octobre 2026 · Exports Excel et PDF

- Exports Excel au format .xlsx mis en forme selon la charte (armoiries, bande tricolore, en-têtes bleu institutionnel, montants et dates réels, total, filtres, volets figés, mise en page d'impression) : livre de caisse, consolidation (4 onglets), journal d'audit. Les exports CSV sont retirés.
- PDF produits par le serveur (Dompdf) : rapports officiels et procès-verbaux avec code QR sur chaque page, livre de caisse et consolidation. Boutons « Télécharger le PDF » à la place de « Imprimer ou enregistrer en PDF » ; colonne PDF dans le registre des rapports.
- Téléchargements de PDF journalisés.
- 33 tests automatiques supplémentaires (469 au total).

## 1.1.0 — 23 octobre 2026 · Déploiement

- Messagerie de la plateforme : envoi par la boîte Hostinger `contact@ige-gestion.com` en SMTP authentifié, réglage avec mot de passe chiffré ou constantes `wp-config.php`, e-mail de test, modèle aux couleurs de l'institution, échecs journalisés.
- Double authentification par code envoyé par e-mail (méthode par défaut) : 6 chiffres, 10 minutes, usage unique, renvoi limité ; activation après réception d'un premier code ; application d'authentification toujours possible ; règle « obligatoire pour tous ».
- Invitations par e-mail (lien personnel de 24 h pour choisir son mot de passe).
- Nouvel écran Administration > Déploiement : préparation des services, création des comptes en nombre (CSV), reprise des fichiers Excel (classeur central et fichiers de service) avec aperçu puis import.
- Notification des rejets envoyée par la messagerie de la plateforme.
- Schéma version 7 (origine des écritures : saisie ou reprise Excel ; pas d'anomalie « pièce manquante » sur les écritures reprises).
- 109 tests automatiques supplémentaires (436 au total).

## 1.0.0 — 23 octobre 2026 · Étape 5 : pilotage — lot 1 complet

- Écrans Tableau de bord, Consolidation, Rapports, Synthèse (Direction), Vérifier un document (public), Double authentification.
- Consolidation sur les écritures validées ; alertes prioritaires ; exports Excel (CSV).
- Registre des documents officiels : référence unique, contenu figé, empreinte SHA-256, code QR, vérification publique ; procès-verbaux d'arrêté et de comptage inopiné inscrits automatiquement.
- Rapports : situation mensuelle, état consolidé, avances non justifiées, rapport annuel.
- Double authentification native (TOTP), obligatoire pour Administration et Finance et les administrateurs, codes de secours, réinitialisation par un administrateur, constante d'urgence.
- Schéma version 6 (table des documents).
- 57 tests automatiques supplémentaires (327 au total).

Thème associé : 1.0.2 (lien « Sécurité » vers la double authentification dans l'en-tête).

## 0.4.0 — 23 octobre 2026 · Étape 4 : fonds et caisse

- Écrans Avances, Arrêté de caisse et Caisses (remplacent les maquettes) ; clôture d'exercice dans Exercice et règles de contrôle.
- Avances de fonds avec entrée automatique dans la caisse du service, justification premier entré premier sorti par les dépenses validées, ancienneté des reliquats, règle des 80 % avec dérogation motivée, annulation par le central.
- Arrêté mensuel : billetage CDF et USD, autres disponibilités, écart expliqué, double signature (agent puis Responsable, deux personnes), clôture du mois, procès-verbal imprimable au format de la charte.
- Comptage inopiné par Administration et Finance, contresigné par l'agent présent.
- Exercices : statut « préparé », un seul exercice ouvert, clôture avec contrôles et report des soldes.
- Accueil du service : rappel de l'arrêté du mois précédent, carte des avances reçues.
- Schéma version 5.
- 74 tests automatiques supplémentaires (269 au total).

## 0.3.0 — 23 octobre 2026 · Étape 3 : circuit avec le central

- Écrans Transmission (service) et Vérification (Administration et Finance), qui remplacent les maquettes.
- Lots numérotés, récapitulatif, journal des lots reçus ; mise à jour sans doublon d'une écriture retransmise (engagement payé après envoi).
- Détection des anomalies à la réception (11 contrôles, dont doublon de facture entre services, fractionnement, jour non ouvré, bénéficiaire = agent, saisie tardive).
- Validation en masse des écritures sans anomalie, validation ou rejet d'une sélection, rejet motivé avec courriel au service, séparation des fonctions.
- Rejets à corriger à l'accueil du service ; contrepassation obligatoire (réglable) ; paiement d'un engagement rejeté bloqué.
- Règle « transmission automatique » ; filtre du livre de caisse par état (à vérifier, validée, rejetée).
- Mise en page : plus aucun débordement horizontal sur téléphone.
- Schéma version 4 (nombre de transmissions par écriture).
- 57 tests automatiques supplémentaires (195 au total).

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
