# Thème WordPress « IGE Frais de fonctionnement »

Thème de l'application de gestion des frais de fonctionnement de l'Inspection Générale de l'Enseignement (Ministère de l'Éducation Nationale et Nouvelle Citoyenneté, RDC). Il applique la *Charte graphique du Gouvernement de la RDC* (février 2022) et reproduit les maquettes validées.

Le thème gère **l'apparence** : en-tête, menus par profil, modèles de pages, composants, documents imprimables. La **logique comptable** (écritures, budgets, contrôles bloquants, droits, PDF, codes QR) relève de l'extension métier « IGE Frais de fonctionnement », qui s'appuie sur les fonctions et crochets décrits plus bas.

Version 1.0.0 · WordPress 6.5 ou plus (testé sur 7.1.3) · PHP 8.0 ou plus.

---

## 1. Installation

1. Dans WordPress : **Apparence > Thèmes > Ajouter > Téléverser un thème**, choisir `ige-ff.zip`, puis **Activer**.
2. **Réglages > Permaliens** : choisir « Titre de la publication ».
3. **Apparence > Structure IGE** : cliquer sur **Créer les pages et les menus**. Les 32 pages sont créées avec leur modèle, et les 5 menus sont créés et assignés. Cocher « contenu de démonstration » uniquement sur le site de test (données fictives des maquettes).
4. **Apparence > Personnaliser > Identité IGE** : vérifier l'intitulé officiel, l'adresse et le texte de la page de connexion.
5. **Réglages > Général** : langue du site « Français ».

Sur Hostinger, faire d'abord ces étapes sur le site de test (staging), puis publier vers la production.

## 2. Ce que voit chaque profil

| Profil | Rôles WordPress | Menu affiché | Page d'arrivée après connexion |
| --- | --- | --- | --- |
| Administration et Finance | `administrator`, `ige_admin` | Menu Administration et Finance | `/tableau-de-bord/` |
| Service | `ige_responsable`, `ige_agent` | Menu des services | `/accueil-service/` |
| Direction | `ige_lecteur` | Menu de la Direction | `/synthese/` |
| Visiteur | (non connecté) | aucun | page de connexion |

Les rôles sont créés par l'extension métier ; le thème ne fait que lire le rôle pour adapter l'interface et **ne donne aucun droit**. Toutes les pages, sauf l'accueil et les pages au modèle « Page publique », redirigent un visiteur non connecté vers la connexion.

La barre d'administration WordPress n'apparaît qu'aux administrateurs du site.

## 3. Modèles de pages

| Modèle | Fichier | Usage |
| --- | --- | --- |
| (par défaut) | `page.php` | Bandeau de titre et contenu |
| Tableau de bord | `templates/tableau-de-bord.php` | Indicateurs et graphiques |
| Liste | `templates/liste.php` | Tableaux, filtres, états |
| Liste avec panneau de détail | `templates/liste-detail.php` | Vérification, demandes de fonds : liste à gauche, détail à droite |
| Formulaire | `templates/formulaire.php` | Saisie en colonne étroite, adaptée au téléphone |
| Administration | `templates/administration.php` | Barre latérale d'administration et contenu |
| Document officiel | `templates/document.php` | Rapport A4 avec en-tête officiel, signatures et code QR, imprimable |
| Page publique | `templates/page-publique.php` | Vérification d'un document, sans connexion |
| Guide des composants | `templates/guide-composants.php` | Référence visuelle pour les développeurs |

L'accueil (`front-page.php`) est la page de connexion.

Chaque page a une boîte **Bandeau de titre** dans l'éditeur : sous-titre et boutons d'action (HTML avec les classes `ige-btn`).

## 4. Menus

Cinq emplacements : `menu-central`, `menu-service`, `menu-direction`, `menu-admin` (barre latérale d'administration), `menu-pied`.

- Un élément de premier niveau avec des enfants s'affiche en liste déroulante. Sur la page d'un enfant, les autres enfants s'affichent dans la barre blanche sous l'en-tête (ex. Fonds > Demandes, Avances, Trésorerie, Rapprochement).
- Pour un groupe sans page propre (Fonds, Logistique, Analyses), utiliser un lien personnalisé `#`.
- Dans `menu-admin`, les éléments de premier niveau avec l'adresse `#` deviennent des titres de groupe.
- Sous 1 100 px de large, le menu passe dans un tiroir ouvert par le bouton « Menu ».

## 5. Règles de la charte appliquées

- Logotype dans l'ordre imposé : armoiries, Ligne d'État (bleu, jaune, rouge de haut en bas, parts égales), intitulé.
- Armoiries **en couleur sur fond blanc**, **en blanc sur fond bleu**. La version blanche (`assets/img/armoirie-blanc.png`) est dérivée du fichier fourni ; la remplacer par la version monochrome officielle si le service communication en fournit une, sous le même nom.
- Bleu institutionnel `#17418a` pour l'en-tête, les menus et le pied de page ; jaune `#fff24b` pour l'action principale, toujours avec un texte bleu foncé.
- Typographie Cooper Hewitt (interface) et EB Garamond (corps des documents), fichiers locaux sous licence SIL Open Font License : aucun appel à un service externe.
- Les états ne reposent jamais sur la couleur seule : chaque pastille porte un libellé.

## 6. Pour l'extension métier : fonctions et crochets

### Fonctions d'affichage (`inc/template-tags.php`)

| Fonction | Rôle |
| --- | --- |
| `ige_ff_logo( $variante, $petit, $lien )` | Bloc logotype (`couleur` ou `blanc`) |
| `ige_ff_title_band( $args )` | Bandeau de titre (title, subtitle, actions) |
| `ige_ff_kpi( $args )` | Carte d'indicateur (label, value, unit, meta, tone, percent) |
| `ige_ff_pill( $libelle, $etat )` | Pastille : `ok`, `wait`, `ko`, `info`, `neutre` |
| `ige_ff_banner( $type, $titre, $texte )` | Bannière : `ok`, `info`, `warn`, `ko` |
| `ige_ff_montant( $montant, $devise )` | Format « 1 234 567 CDF » |
| `ige_ff_date_fr( $timestamp )` | Date en toutes lettres en français |
| `ige_ff_kses_ui( $html )` | Filtre HTML qui conserve les éléments de formulaire |
| `ige_ff_profil()` | `central`, `service`, `direction` ou `visiteur` |

### Filtres

| Filtre | Usage |
| --- | --- |
| `ige_ff_roles_profils` | Correspondance rôles > profils |
| `ige_ff_profil_libelle` | Libellé sous le nom de l'utilisateur (le service vient de la méta utilisateur `ige_service`) |
| `ige_ff_menu_location` | Emplacement de menu selon le profil |
| `ige_ff_dashboard_url` | Page d'arrivée d'un profil |
| `ige_ff_title_band` | Titre, sous-titre et boutons du bandeau |
| `ige_ff_notifications_count`, `ige_ff_notifications_url` | Pastille de notifications de l'en-tête |
| `ige_ff_document_reference` | Référence affichée sur un document officiel |
| `ige_ff_require_login` | Désactiver ou ajuster la protection des pages |

### Actions

| Action | Usage |
| --- | --- |
| `ige_ff_before_content`, `ige_ff_after_content` | Insérer des écrans avant ou après le contenu d'une page |
| `ige_ff_detail_panel` | Panneau de détail du modèle « Liste avec panneau » |
| `ige_ff_document_qr` | Code QR d'authenticité (SVG) d'un document |
| `ige_ff_public_content` | Résultat de la vérification publique d'un document |
| `ige_ff_login_panel` | Remplacer le panneau bleu de la page de connexion |

## 7. Bibliothèque de composants (CSS)

Toutes les classes sont préfixées `ige-` pour ne jamais entrer en conflit avec WordPress ou une extension. La page **Guide des composants** les montre toutes. Principales familles :

- Mise en page : `ige-wrap`, `ige-grid`, `ige-cols-2`, `ige-cols-3`, `ige-cols-21`, `ige-cols-12`, `ige-kpis`, `ige-verif`, `ige-narrow`, `ige-row`.
- Cartes et indicateurs : `ige-card`, `ige-kpi` + `ige-accent | ige-ok | ige-alerte | ige-risque`, `ige-bar`.
- Boutons : `ige-btn` + `ige-btn-jaune | ige-btn-bleu | ige-btn-line | ige-btn-rouge | ige-btn-ghost`.
- États : `ige-pill` + `ige-p-ok | ige-p-wait | ige-p-ko | ige-p-info | ige-p-neutre`, `ige-anom`.
- Tableaux : `ige-table-wrap` > `table.ige-table`, cellules `ige-r` (montants), lignes `ige-sel | ige-rejet | ige-contre | ige-total`.
- Formulaires : `ige-field`, `ige-help`, `ige-has-error` + `ige-error`, `ige-toolbar`, `ige-chip`, `ige-switch`, `ige-seg`, `ige-photo`, `ige-billet`.
- Messages et suivi : `ige-banner`, `ige-warn`, `ige-check`, `ige-msg`, `ige-timeline`, `ige-chain`, `ige-visa`, `ige-steps`.
- Graphiques simples : `ige-exec`, `ige-mini-bars`, `ige-tbars`, `ige-cmp`, `ige-age`, `ige-legend`.
- Fenêtre modale : `ige-modal-backdrop` > `ige-modal`, ouverture par `data-ige-modal-open="id"`, fermeture par `data-ige-modal-close` ou Échap.

Les couleurs sont des variables CSS (`--ige-bleu-inst`, `--ige-jaune`, etc.) définies en tête de `assets/css/ige.css`.

## 8. Contenu de démonstration

Les fichiers `demo/*.json` contiennent le contenu fictif des 31 écrans maquettés. Ils ne servent qu'à la recette du thème. En production, ne pas cocher l'option de démonstration, ou vider le contenu de ces pages avant la mise en service. Le dossier `demo/` peut être supprimé du thème de production.

## 9. Structure des fichiers

```
ige-ff/
├── style.css                En-tête du thème
├── functions.php            Chargement des modules inc/
├── theme.json               Palette et polices de l'éditeur de blocs
├── header.php, footer.php   En-tête et pied de l'application
├── header-public.php, footer-public.php
├── front-page.php           Page de connexion
├── page.php, index.php, 404.php
├── templates/               Modèles de pages
├── template-parts/          Parties réutilisables (contenu, guide)
├── inc/                     Réglages, profils, menus, fonctions d'affichage, structure
├── assets/css/              ige.css (charte), login.css, editor.css
├── assets/js/ige.js         Menus, modales, impression (sans dépendance)
├── assets/fonts/            Cooper Hewitt, EB Garamond (OFL)
├── assets/img/              Armoiries couleur et blanc
└── demo/                    Contenu fictif des maquettes
```
