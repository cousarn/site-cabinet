# Site du Cabinet Arnaud Cousin — avocat à Rennes

Site statique construit avec [Astro](https://astro.build), hébergé chez OVH (mutualisé), déployé par GitHub Actions.
Dépôt **public** (il ne contient que ce que tout visiteur du site voit ; IBAN, adresses d'expédition et compteurs vivent hors dépôt, sur le serveur). Le contenu éditorial est en Markdown ; les données du cabinet dans `src/data/`.

## Démarrer en local

```bash
npm install
npm run dev        # http://localhost:4321
npm run build      # génère dist/
npm run preview    # sert dist/ en local
npm run check      # vérification TypeScript / Astro
```

## Arborescence

| Dossier | Contenu |
|---|---|
| `src/pages/` | une page = une URL (`cabinet.astro` → `/cabinet`) |
| `src/content/domaines/*.md` | les 4 pages domaines : frontmatter structuré (situations, méthode, FAQ) |
| `src/content/fiches/*.md` | fiches pratiques, simulateurs, checklists, billets |
| `src/data/cabinet.ts` | adresse, téléphone, horaires, confrères, accès, navigation, **année du serment** (le « 26 ans » se calcule tout seul) |
| `src/data/honoraires.ts` | grille indicative |
| `src/layouts/Base.astro` | `<head>`, SEO, JSON-LD LegalService, header, footer |
| `src/components/` | Header (méga-menus, burger), Footer, Hero, Faq, Cta, Icone |
| `src/styles/global.css` | charte : vert cabinet `#11352F`, laiton `#9A7B4F`, papier `#F4EFE6`, Fraunces + Figtree (polices hébergées sur le site, pas Google Fonts) |
| `src/assets/photos/` | photos optimisées à la construction (`astro:assets` → WebP/AVIF responsive) |
| `public/.htaccess` | HTTPS, domaine sans `www`, URLs sans `.html`, redirections 301 de l'ancien site TOWeb, en-têtes de sécurité, cache |

## Ajouter une fiche

Créer `src/content/fiches/mon-sujet.md` :

```md
---
title: "Titre de la fiche"
description: "Une phrase de 160 caractères au plus, qui donne envie et contient le mot-clé."
date: 2026-11-15
domaine: successions        # successions | separation-divorce | droit-du-travail | procedure-appel | cabinet
type: fiche                 # fiche | simulateur | checklist | billet
lecture: "5 min"
---
## Premier intertitre
Texte…
```

Pousser sur `main` : la préprod se met à jour en deux minutes. La fiche apparaît sur `/fiches`, sur l'accueil (trois dernières) et dans le sitemap.

## Déploiement (Git, sans mot de passe)

| Déclencheur | Branche publiée | Cible OVH |
|---|---|---|
| push sur `main` | `deploy-preprod` | `preprod.arnaudcousin-avocat.fr` → dossier `./preprod` (noindex) |
| tag `v1.0.0` (seul moyen : l'environnement GitHub `production` n'accepte que les tags `v*`) | `deploy-prod` | `arnaudcousin-avocat.fr` → dossier `./www` |

GitHub Actions construit le site et pousse `dist/` sur la branche de déploiement ; OVH (fonction *Associer Git* du multisite) est prévenu par webhook et tire la branche. Aucun identifiant FTP n'est stocké nulle part.

**Ne jamais réécrire l'historique des branches `deploy-*`** (pas de `force_orphan`, pas de `push --force`) : OVH fait un `git pull` dans son clone ; si la branche ne peut plus avancer en fast-forward, la tâche OVH reste « En cours » et tous les webhooks suivants sont refusés (400). Si cela arrive : OVH › Multisite › ⋯ › *Supprimer Git* (cocher « vider le répertoire »), puis *Associer Git* à nouveau — le clone initial prend quelques secondes et l'URL de webhook ne change pas.

### Mise en place (une fois par environnement)

1. OVH › Hébergement › Multisite › ligne du domaine › ⋯ › **Associer Git** : dépôt `https://github.com/cousarn/site-cabinet.git` (HTTPS : le dépôt est public, aucune clé à échanger), branche `deploy-preprod` (préprod) ou `deploy-prod` (prod). OVH affiche une **URL de webhook**.
2. GitHub › Settings › **Webhooks** › Add : coller l'URL OVH, content type `application/json`, événement *push*. (Un webhook par environnement.)
3. Premier `git push` : la préprod se construit et se publie. Vérifier, puis `git tag v1.0.0 && git push --tags` pour la production (après avoir associé Git au domaine principal de la même façon).

Pourquoi HTTPS et non SSH : la clé SSH générée par OVH est RSA 2048 bits et GitHub refuse les clés RSA de moins de 3072 bits depuis le 14 octobre 2026. Fait le 18/10/2026 : préprod associée, webhook posé.

Fichiers propres au serveur, hors racine web, jamais écrasés : `/config/site-cabinet.php` (IBAN, adresse d'expédition — modèle dans `public/api/config.local.example.php`), `/cache/site-cabinet/` (compteurs anti-abus), `/consultations/` (pièces des consultations en ligne).

## Formulaires (PHP sur OVH — `public/api/`)

| Point d'entrée | Rôle | Courriels |
|---|---|---|
| `contact.php` | formulaire de contact | cabinet (Reply-To client) + accusé de réception |
| `saisine.php` | saisine notaires & confrères | cabinet + accusé « sous 24 h ouvrées » |
| `devis.php` | estimation divorce par consentement mutuel (calcul serveur, grille dans `_lib.php` → `devis`) | client (détail ligne par ligne) + cabinet |
| `consultation.php` | consultation écrite : formule, question, pièces (stockées hors racine web dans `../consultations/<réf>/`), instructions de règlement | client + cabinet |

Protections : POST seul, pot de miel (`site`), limite de 6 envois/heure/IP, validation des courriels, types MIME des pièces vérifiés, 20 Mo max, `_lib.php` et `.cache/` inaccessibles en HTTP.
Chaque envoi redirige vers `/merci?objet=…&ref=…` (référence du type `261018-6018`).

**Sur le serveur, une fois** : déposer `/config/site-cabinet.php` (modèle : `public/api/config.local.example.php`) avec l'IBAN, l'adresse d'expédition (`site@arnaudcousin-avocat.fr`, à créer dans OVH › Emails) et, si besoin, la grille du devis. Hors racine web, il n'est jamais touché par les déploiements.

## Phase 2 (même dépôt)

- Paiement par carte : Stripe Checkout (`paiement => 'stripe'` dans la config) — le site ne voit jamais la carte.
- Rendez-vous : iframe Microsoft Bookings dans `/services/rendez-vous`.
- Dépôt de pièces : `depot/<jeton>` → relais PHP → SharePoint → `agent-secib`.
- Assistant de pré-entretien : serveur du cabinet, modèle sous contrôle de l'avocat, journalisation minimale.

## Points en attente (`[entre crochets]` dans le site)

SIRET, TVA, assureur RC, médiateur, prix visio/téléphone, URL Bookings, places et horaires du parking Colombier, lignes de bus 9/57, accessibilité PMR, crédits photos (et mention IA le cas échéant), mise à jour du barème, grille du devis divorce validée le 18/10/2026 (`_lib.php` → `devis`, en HT : base 800, convention parentale 400, prestation compensatoire 300, partage 700, négociation 500 ; déplacement hors Rennes en sus), IBAN dans `config.local.php`.

