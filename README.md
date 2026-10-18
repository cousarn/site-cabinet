# Site du Cabinet Arnaud Cousin — avocat à Rennes

Site statique construit avec [Astro](https://astro.build), hébergé chez OVH (mutualisé), déployé par GitHub Actions.
Dépôt privé. Le contenu éditorial est en Markdown ; les données du cabinet dans `src/data/`.

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

## Déploiement

| Déclencheur | Cible | Dossier OVH |
|---|---|---|
| push sur `main` | préproduction `preprod.arnaudcousin-avocat.fr` (noindex) | `www/preprod/` |
| tag `v1.0.0`, ou lancement manuel « prod » | production `arnaudcousin-avocat.fr` | `www/` |

### Mise en place (une fois, par Me Cousin)

1. **OVH — utilisateur FTP dédié** : Espace client › Hébergements › FTP-SSH › *Créer un utilisateur*, répertoire racine `www`. Noter hôte, identifiant, mot de passe.
2. **OVH — sous-domaine de préprod** : Hébergements › Multisite › *Ajouter un domaine* `preprod.arnaudcousin-avocat.fr`, dossier racine `www/preprod`, SSL activé ; puis Noms de domaine › Zone DNS : enregistrement `preprod` CNAME vers le domaine (OVH le propose automatiquement).
3. **GitHub — secrets** : Settings › Secrets and variables › Actions : `OVH_FTP_HOST`, `OVH_FTP_USER`, `OVH_FTP_PASSWORD`, `OVH_DIR_PREPROD` (`./www/preprod/`), `OVH_DIR_PROD` (`./www/`).
4. **GitHub — environnements** : Settings › Environments › `production` : cocher *Required reviewers* (vous) pour qu'aucune mise en production ne parte sans votre clic.
5. Premier `git push` : la préprod se construit. Vérifier, puis `git tag v1.0.0 && git push --tags` pour la production.

Les identifiants OVH ne figurent nulle part dans le code et ne sont jamais communiqués à des tiers.

## Formulaires (PHP sur OVH — `public/api/`)

| Point d'entrée | Rôle | Courriels |
|---|---|---|
| `contact.php` | formulaire de contact | cabinet (Reply-To client) + accusé de réception |
| `saisine.php` | saisine notaires & confrères | cabinet + accusé « sous 24 h ouvrées » |
| `devis.php` | estimation divorce par consentement mutuel (calcul serveur, grille dans `_lib.php` → `devis`) | client (détail ligne par ligne) + cabinet |
| `consultation.php` | consultation écrite : formule, question, pièces (stockées hors racine web dans `../consultations/<réf>/`), instructions de règlement | client + cabinet |

Protections : POST seul, pot de miel (`site`), limite de 6 envois/heure/IP, validation des courriels, types MIME des pièces vérifiés, 20 Mo max, `_lib.php` et `.cache/` inaccessibles en HTTP.
Chaque envoi redirige vers `/merci?objet=…&ref=…` (référence du type `261018-6018`).

**Sur le serveur, une fois** : copier `api/config.local.example.php` en `api/config.local.php` et renseigner l'IBAN, l'adresse d'expédition (`site@arnaudcousin-avocat.fr`, à créer dans OVH › Emails) et, si besoin, la grille du devis. Ce fichier n'est jamais écrasé par les déploiements.

## Phase 2 (même dépôt)

- Paiement par carte : Stripe Checkout (`paiement => 'stripe'` dans la config) — le site ne voit jamais la carte.
- Rendez-vous : iframe Microsoft Bookings dans `/services/rendez-vous`.
- Dépôt de pièces : `depot/<jeton>` → relais PHP → SharePoint → `agent-secib`.
- Assistant de pré-entretien : serveur du cabinet, modèle sous contrôle de l'avocat, journalisation minimale.

## Points en attente (`[entre crochets]` dans le site)

SIRET, TVA, assureur RC, médiateur, prix visio/téléphone, URL Bookings, places et horaires du parking Colombier, lignes de bus 9/57, accessibilité PMR, crédits photos (et mention IA le cas échéant), mise à jour du barème, **grille du devis divorce** (`_lib.php` → `devis` : base 960 €, enfants +240/+360, immobilier +360/+720, entreprise +600/+900, désaccord partiel +240 — proposition à valider), IBAN dans `config.local.php`.
