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
| tag `v1.0.0` (seul moyen : l'environnement GitHub `production` n'accepte que les tags `v*`) | `deploy-prod` | `arnaudcousin-avocat.fr` et `www.` → dossier `./prod` |

GitHub Actions construit le site et pousse `dist/` sur la branche de déploiement ; OVH (fonction *Associer Git* du multisite) est prévenu par webhook et tire la branche. Aucun identifiant FTP n'est stocké nulle part.

**Ne jamais réécrire l'historique des branches `deploy-*`** (pas de `force_orphan`, pas de `push --force`) : OVH fait un `git pull` dans son clone ; si la branche ne peut plus avancer en fast-forward, la tâche OVH reste « En cours » et tous les webhooks suivants sont refusés (400). Si cela arrive : OVH › Multisite › ⋯ › *Supprimer Git* (cocher « vider le répertoire »), puis *Associer Git* à nouveau — le clone initial prend quelques secondes et l'URL de webhook ne change pas.

### Mise en place (une fois par environnement)

1. OVH › Hébergement › Multisite › ligne du domaine › ⋯ › **Associer Git** : dépôt `https://github.com/cousarn/site-cabinet.git` (HTTPS : le dépôt est public, aucune clé à échanger), branche `deploy-preprod` (préprod) ou `deploy-prod` (prod). OVH affiche une **URL de webhook**.
2. GitHub › Settings › **Webhooks** › Add : coller l'URL OVH, content type `application/json`, événement *push*. (Un webhook par environnement.)
3. Premier `git push` : la préprod se construit et se publie. Vérifier, puis `git tag v1.0.0 && git push --tags` pour la production (après avoir associé Git au domaine principal de la même façon).

**Production, fait le 18/10/2026 (v1.0.0)** : OVH exige un dossier vide pour associer Git, et `www` contenait l'ancien site TOWeb. Les deux entrées multisite (`arnaudcousin-avocat.fr`, `www.`) ont donc été basculées vers un nouveau dossier **`./prod`** (Multisite › Modifier le domaine), Git associé dessus (branche `deploy-prod`), second webhook GitHub. L'ancien site reste intact dans `./www` : retour arrière = rebasculer le dossier racine sur `www`. Mises en production suivantes : `git tag vX.Y.Z && git push --tags` (le tag doit pointer sur un commit déjà validé en préprod).

Pourquoi HTTPS et non SSH : la clé SSH générée par OVH est RSA 2048 bits et GitHub refuse les clés RSA de moins de 3072 bits depuis le 14 octobre 2026. Fait le 18/10/2026 : préprod et prod associées, deux webhooks posés.

Fichiers propres au serveur, hors racine web, jamais écrasés, communs à la préprod et à la prod : `/config/site-cabinet.php` (IBAN, adresse d'expédition — modèle dans `public/api/config.local.example.php`), `/cache/site-cabinet/` (compteurs anti-abus), `/consultations/` (pièces des consultations en ligne).

## Formulaires (PHP sur OVH — `public/api/`)

| Point d'entrée | Rôle | Courriels |
|---|---|---|
| `contact.php` | formulaire de contact | cabinet (Reply-To client) + accusé de réception |
| `saisine.php` | saisine notaires & confrères | cabinet + accusé « sous 24 h ouvrées » |
| `devis.php` | estimation divorce par consentement mutuel (calcul serveur, grille dans `_lib.php` → `devis`) | client (détail ligne par ligne) + cabinet |
| `consultation.php` | consultation écrite : formule, question, pièces (stockées hors racine web dans `../consultations/<réf>/`), instructions de règlement | client + cabinet |
| `pre-entretien.php` | assistant de pré-entretien (JSON) : synthèse `pre-entretien-<réf>/synthese.{txt,json}`, analyse IA facultative pour l'avocat | cabinet + récapitulatif client |
| `depot.php` | dépôt de pièces par lien personnel (JSON) : état du lien, envoi par morceaux de 4 Mo, « terminer » | cabinet + accusé client |
| `depot-admin.php` | console `/admin/depots` (session par clé serveur) : créer/envoyer un lien, suivi, zip, relais SharePoint | lien au client |
| `paypal-ipn.php` | notification PayPal vérifiée : marque la consultation payée | cabinet + client |

Protections : POST seul, pot de miel (`site`), limite de 6 envois/heure/IP, validation des courriels, types MIME des pièces vérifiés, 20 Mo max, `_lib.php` et `.cache/` inaccessibles en HTTP.
Chaque envoi redirige vers `/merci?objet=…&ref=…` (référence du type `261018-6018`).

**Sur le serveur, une fois** : déposer `/config/site-cabinet.php` (modèle : `public/api/config.local.example.php`) avec l'IBAN, l'adresse d'expédition (`site@arnaudcousin-avocat.fr`, à créer dans OVH › Emails) et, si besoin, la grille du devis. Hors racine web, il n'est jamais touché par les déploiements.

## Paiement en ligne (PayPal)

La consultation écrite se règle sur la page de paiement hébergée par PayPal (compte PayPal **ou carte bancaire sans compte**). Aucun script tiers, aucun secret : `lien_paypal()` dans `_lib.php` construit une URL `cgi-bin/webscr?cmd=_xclick` avec l'identifiant marchand public (`paypal_id`), le montant, l'intitulé et la référence (`invoice` + `custom`), les URL de retour (`/merci?objet=paiement`) et d'annulation, et l'URL de notification (`notify_url`, ci-dessous). Le lien figure dans l'accusé de réception et sur la page *Merci* ; le RIB reste proposé en secours. Activation : `'paiement' => 'paypal'` dans `/config/site-cabinet.php` (fait le 18/10/2026).

### Notification de paiement instantané (IPN) — en place depuis le 19/10/2026

`public/api/paypal-ipn.php` — URL de production `https://arnaudcousin-avocat.fr/api/paypal-ipn.php` — reçoit les notifications de PayPal et marque la consultation « payée » sans intervention :

1. PayPal envoie (POST) le détail de la transaction ; le script **renvoie le message tel quel à PayPal** (`ipnpb.paypal.com`, `cmd=_notify-validate`) et n'en exploite rien tant que PayPal n'a pas répondu `VERIFIED`. Aucun secret ni clé API : l'authenticité repose sur ce renvoi. PayPal injoignable → réponse 503, PayPal réessaie (plusieurs tentatives sur quelques jours) ; `INVALID` → ignoré, journalisé, aucun courriel.
2. Contrôles : destinataire = `paypal_id` (ou `paypal_email` si cette clé est définie dans la config serveur), devise EUR, référence `invoice` (à défaut `custom`) au format `AAMMJJ-XXXX` correspondant à un dossier `consultations/consultation-<réf>/`, transaction (`txn_id`) non déjà traitée, montant ≥ prix de la demande. Le prix et le client sont lus dans `demande.json` (écrit par `consultation.php` depuis le 19/10/2026) ; pour les demandes antérieures, dans la ligne `Formule : … (120 €)` de `demande.txt`.
3. Selon `payment_status` :
   - `Completed` : écrit `paiement-<txn>.json` (trace complète, sert d'idempotence) et `paye.json` (payé le, montant, transaction) dans le dossier de la consultation ; courriel au cabinet « [SITE] Paiement reçu — formule — nom (réf) » (montant, commission PayPal, transaction, échéance des 5 jours ouvrés, dossier serveur) et au client « Votre règlement est bien reçu (réf) » (consultation lancée, réponse sous 5 jours ouvrés).
   - `Pending` : rien n'est marqué, courriel d'information au cabinet avec le motif (`pending_reason`) ; PayPal notifiera le `Completed` ensuite.
   - `Refunded`, `Reversed`, `Canceled_Reversal` : trace + courriel au cabinet ; `paye.json` n'est pas modifié.
   - Toute incohérence (destinataire, devise, référence inconnue, montant insuffisant, second règlement sur une référence déjà payée, statut inattendu) : courriel « [SITE] Paiement PayPal à vérifier » avec le détail, réponse 200 (PayPal n'a pas à réessayer).
4. Journal : `cache/site-cabinet/journal.log`, lignes `paypal-ipn <état> <réf>`, sans donnée personnelle.

Clés facultatives de la config serveur (voir `config.local.example.php`) : `paypal_sandbox` (vérification auprès de `ipnpb.sandbox.paypal.com` ; par défaut **vrai sur `preprod.*`, faux ailleurs**) et `paypal_email`.

**Côté compte PayPal (Arnaud)** — facultatif mais recommandé : le `notify_url` envoyé avec chaque paiement suffit, mais fixer l'URL dans le compte garantit la notification même pour un paiement reçu sans ce paramètre et ouvre l'historique. Paramètres du compte › Notifications › *Notifications de paiement instantané* (« Préférences du compte » dans l'ancienne interface) › Activer, URL `https://arnaudcousin-avocat.fr/api/paypal-ipn.php`. L'*Historique IPN* (même rubrique) liste chaque envoi avec le code HTTP renvoyé par le site et permet de **renvoyer** une notification, par exemple après une panne ou pour une référence corrigée.

**Tester** : PayPal Developer Dashboard › Testing Tools › *IPN Simulator* : URL `https://preprod.arnaudcousin-avocat.fr/api/paypal-ipn.php`, type « Web Accept », puis `receiver_id` = identifiant marchand, `mc_currency` = EUR, `invoice` = référence d'une demande créée depuis la préprod, `mc_gross` ≥ son prix, `payment_status` = Completed. La préprod vérifie automatiquement auprès du bac à sable (`paypal_sandbox` vrai par défaut) ; les courriels partent réellement (adresse client `cabinet@` comme pour les autres tests, mention « Mode test »). En local : `php -S 127.0.0.1:8081 -t public` puis un `curl --data-urlencode …` sur `/api/paypal-ipn.php` — sous le seul serveur intégré, et seulement depuis `127.0.0.1`, la vérification PayPal est court-circuitée (commentaire dans le script). Limite : un paiement **réel** passé depuis le formulaire de la préprod est notifié à la préprod, qui l'interroge auprès du bac à sable et le juge `INVALID` ; pour un test réel de bout en bout, poser temporairement `'paypal_sandbox' => false` dans la config serveur (sans effet sur la prod, déjà en mode réel).

### Stripe (option ultérieure)

Non implémenté ; `paiement => 'stripe'` dans la config n'affiche pour l'instant qu'un message d'attente. Ce qu'il faudrait : un compte Stripe du cabinet (vérification d'identité, IBAN de versement) ; les clés (`sk_live_…` et le secret de signature du webhook `whsec_…`) dans `/config/site-cabinet.php` **uniquement**, jamais dans le dépôt ; côté PHP, `consultation.php` crée une *Checkout Session* (API Stripe en cURL : montant, libellé, référence dans `client_reference_id`/`metadata`, URLs de retour `/merci`) et redirige le client vers l'URL retournée ; un point d'entrée `stripe-webhook.php` reçoit `checkout.session.completed`, **vérifie la signature** `Stripe-Signature` (HMAC avec `whsec_…`, tolérance de 5 min), puis applique la même logique que l'IPN (trace, `paye.json`, courriels) ; la CSP reste inchangée (redirection, aucun script tiers). À comparer avec PayPal : frais par transaction, délai de versement, carte sans compte déjà couverte par PayPal.

## Phase 2 (même dépôt)

- Paiement par carte : Stripe Checkout (`paiement => 'stripe'` dans la config) — le site ne voit jamais la carte.

## Dépôt de pièces (lien personnel → serveur → SharePoint)

Le cabinet crée un lien par dossier depuis `/admin/depots` (clé `depot.cle_admin` du fichier serveur ; session PHP, cookie strict, en-tête `X-Requested-With` exigé). Le client ouvre `/depot/<jeton>` (le `.htaccess` sert `services/depot-pieces.html`, le script lit le jeton dans l'URL) : glisser-déposer, envoi **par morceaux de 4 Mo** (`depot.php?action=morceau`, indépendant de `upload_max_filesize`), contrôle du type réel sur le fichier complet (finfo + rattrapages HEIC/Office/`.msg`, zip sans exécutable), classement `NN - nom.ext` dans `/home/arnaudcoqa/depots/<id>/` (id dérivé du jeton par SHA-256), relais immédiat vers SharePoint (`_graph.php` : jeton applicatif en cache, création du dossier `Dépôts du site/<dossier — réf>/`, envoi direct ≤ 4 Mo ou session d'envoi par fragments de 5 Mio). « Terminer » prévient le cabinet et accuse réception au client. Console : détail, zip de secours, reprise du relais, prolongation, clôture, suppression ; purge automatique des dépôts échus dont toutes les pièces sont dans SharePoint (7 jours) ou au plus tard après `conservation_jours`.

Côté Microsoft 365 (une fois) : site SharePoint « Dépôts clients », inscription d'application Entra avec la permission d'application `Sites.Selected` + consentement administrateur, octroi `write` de l'application sur ce seul site (`POST /sites/{id}/permissions`), secret client copié par l'avocat dans `/config/site-cabinet.php` (`graph`). Tant que `graph` est vide, les pièces restent sur le serveur (zip).

## Assistant de pré-entretien

`/services/pre-entretien` : questionnaire guidé (arbre dans `src/data/pre-entretien.ts` — domaine, questions propres, récit, personnes, chronologie, documents datés avec rappel « signalez la date », démarches, objectif, urgence, pièces à réunir, format de rendez-vous, coordonnées, consentement). Aucun conseil juridique. Envoi JSON à `pre-entretien.php` → synthèse au cabinet, récapitulatif + pièces + lien Bookings au client, redirection `/merci?objet=pre-entretien`. Analyse préparatoire IA (API Anthropic, pour l'avocat seul, uniquement les réponses sans coordonnées) activée par `assistant.cle_api` dans le fichier serveur ; consigne dans `public/api/_assistant-consigne.php`.

## Rendez-vous en ligne (Microsoft Bookings)

Page de réservation **personnelle** du compte `cabinet@` (« Bookings with me » — la création d'une page partagée échoue sur ce tenant, à réessayer plus tard). Trois types de réunion publics : cabinet, visioconférence Teams, téléphone — 1 h, 132 € TTC (110 € HT). Disponibilités personnalisées lun–ven 10 h–11 h et 14 h 30–19 h, pas de 30 min, délai minimal 1 jour, maximal 90 jours, rappel courriel la veille. Les rendez-vous et audiences déjà dans le calendrier Outlook (Secib, secrétariat) masquent les créneaux. Liens publics dans `src/data/cabinet.ts` (`bookings`), utilisés par `/services/rendez-vous` (boutons, nouvel onglet : la page personnelle ne s'intègre pas en iframe, la CSP reste donc `frame-src 'self'`). Les deux types par défaut en anglais (« 15/30 minutes meeting ») sont passés en privé.

## Points en attente

- Page Bookings partagée (logo, couleurs du cabinet, formulaire personnalisé) si Microsoft corrige l'erreur de création.

Réglé le 19/10/2026 : dépôt de pièces en service avec relais SharePoint (site « Dépôts clients », application Entra `Sites.Selected`, secret et clé de console dans le fichier serveur), assistant de pré-entretien, IPN PayPal.

Réglé le 18/10/2026 : Bookings (page personnelle, 3 formats à 132 € TTC), question simple écrite à 80 € TTC, SIRET 429 772 940 00038 et TVA FR70 429 772 940 (avis SIRENE / factures), assureur RCP (notice 2026 du Barreau : MMA IARD apériteur + AXA France IARD, monde entier hors établissement permanent USA/Canada), médiateur CNB, horaires 9h–12h / 14h–19h, accès PMR par ascenseur, bus C5/C6/9/57, parking Colombier (1 150 places), crédits photos © Cabinet Arnaud Cousin, barème 2026 (consultation 132 € TTC), grille devis DCM en HT dans `_lib.php`, alias `site@` (Microsoft 365), fichier serveur `/config/site-cabinet.php` déposé (RIB).
