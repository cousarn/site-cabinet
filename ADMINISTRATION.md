# Administrer arnaudcousin-avocat.fr

Mode d'emploi du site du Cabinet Arnaud Cousin, avocat au Barreau de Rennes. Il s'adresse à trois lecteurs : Arnaud lui-même, une session d'assistant IA (Claude ou autre) à qui il confie une tâche, et un éventuel agent local chargé de la veille. Tout ce qui suit est vérifiable dans le dépôt `cousarn/site-cabinet` (public) ; rien de secret n'y figure, et rien de secret ne doit jamais y entrer.

> **Règle d'or pour un agent** : le site est statique et déployé par Git. On ne modifie jamais rien par FTP sur le serveur, sauf le fichier de configuration hors Git décrit au § 6. Toute modification = commit sur `main` → vérification en préprod → tag `vX.Y.Z` → production.

---

## 1. Vue d'ensemble

| Élément | Valeur |
|---|---|
| Production | https://arnaudcousin-avocat.fr (et `www.`, redirigé en 301) — dossier OVH `./prod` |
| Préproduction | https://preprod.arnaudcousin-avocat.fr — dossier OVH `./preprod`, balise `noindex` |
| Ancien site TOWeb | conservé intact dans le dossier OVH `./www` (retour arrière possible : Multisite › Modifier le domaine › dossier racine `www`) |
| Dépôt | `github.com/cousarn/site-cabinet`, branche de travail `main`, dossier `astro/` |
| Générateur | Astro 5 (statique, `build.format: 'file'` → URL sans `.html`), Node 22 |
| Hébergement | OVH mutualisé, cluster 105, utilisateur `arnaudcoqa`, PHP 8 pour les formulaires |
| Déploiement | GitHub Actions construit `dist/` et le pousse sur `deploy-preprod` (push `main`) ou `deploy-prod` (tag `v*`) ; OVH tire la branche via webhook en 1 à 2 min |
| Courriel | Microsoft 365 ; `site@arnaudcousin-avocat.fr` (expéditeur des formulaires) est un alias de `cabinet@` ; MX, SPF dans la zone DNS OVH |
| Rendez-vous | Microsoft Bookings, page personnelle du compte `cabinet@` (3 types : cabinet, visio, téléphone — 1 h, 132 € TTC) |
| Paiement | PayPal « Payments Standard » (lien hébergé, identifiant marchand public dans `_lib.php`), RIB en secours ; notification IPN (`api/paypal-ipn.php`) qui marque la consultation « payée » et prévient cabinet et client |
| Dépôt de pièces | lien personnel `/depot/<jeton>` créé depuis la console `/admin/depots` ; pièces hors racine web puis relayées dans SharePoint (site « Dépôts clients », application Entra `Sites.Selected`) |
| Pré-entretien | questionnaire guidé `/services/pre-entretien` (arbre dans `src/data/pre-entretien.ts`), synthèse au cabinet ; analyse IA facultative pour l'avocat seul (clé dans le fichier serveur) |
| Référencement | Google Search Console (propriété domaine, validée par TXT DNS), Bing Webmaster (validé par CNAME DNS), sitemap `/sitemap-index.xml` |

Vérifier d'un coup d'œil quelle version est en ligne : `https://arnaudcousin-avocat.fr/version.txt` (hash du commit, date, référence), idem sur la préprod.

## 2. Où se trouve quoi

```
astro/
├── src/data/cabinet.ts        coordonnées, horaires, confrères, SIRET/TVA, assureur, médiateur, accès,
│                              navigation (menus), liens Bookings (`bookings`), année du serment
├── src/data/honoraires.ts     grille indicative (`bareme`), barème de l'aide juridictionnelle (`aj`)
├── src/content/domaines/*.md  les 5 pages « Domaines » : tout dans le frontmatter (situations, méthode, FAQ, fiches liées)
├── src/content/fiches/*.md    fiches pratiques et billets : frontmatter + Markdown
├── src/pages/*.astro          pages fixes (accueil, cabinet, honoraires, contact, mentions légales, règlement amiable…)
├── src/pages/services/        rendez-vous, consultation écrite, devis divorce, dépôt de pièces, assistant de pré-entretien
├── src/pages/admin/depots.astro  console du cabinet (noindex, hors sitemap) : liens de dépôt, suivi, zip, relais SharePoint
├── src/data/pre-entretien.ts  arbre des questions de l'assistant de pré-entretien (modifiable sans toucher à la page)
├── src/layouts/Base.astro     <head>, SEO, JSON-LD (LegalService + BreadcrumbList), header, footer
├── src/components/            Header, Footer, Hero, Faq, Cta, Icone ; HeroAccueil = bandeau de la page d’accueil (portrait © David Ferrière,
│                              4 compositions a–d, « c » en service ; la photo n’est jamais retouchée ni recouverte)
├── src/styles/global.css      charte : vert #11352F, laiton #9A7B4F, papier #F4EFE6, Fraunces + Figtree (polices locales)
├── src/assets/photos/         photos (WebP ; Astro génère les tailles responsives)
├── public/.htaccess           HTTPS, sans www, URL sans .html, 301 de l'ancien site, en-têtes de sécurité (CSP stricte)
├── public/api/*.php           formulaires : contact, saisine, devis, consultation, pre-entretien ; dépôt : depot, depot-admin ;
│                              paypal-ipn ; `_lib.php` = réglages et utilitaires, `_depot.php` + `_graph.php` = dépôt et relais SharePoint,
│                              `_assistant-consigne.php` = consigne de l'analyse IA du pré-entretien
├── public/api/config.local.example.php   modèle du fichier serveur hors Git (§ 6)
├── .github/workflows/deploy.yml          la chaîne de déploiement
├── README.md                  documentation technique (déploiement, formulaires, paiement, historique des décisions)
└── ADMINISTRATION.md          ce document
```

Règle de dépendance : une donnée affichée à plusieurs endroits vit dans `src/data/` (ex. : les horaires, le téléphone, les prix des rendez-vous). Ne jamais la dupliquer en dur dans une page.

## 3. Les tâches courantes

### 3.1 Publier une fiche pratique (actualité, note de jurisprudence)

1. Créer `src/content/fiches/<slug>.md` (slug en minuscules, sans accent, tirets). Frontmatter obligatoire :
   ```yaml
   ---
   title: "Titre de la fiche"                       # 50 à 65 caractères, mot-clé devant
   description: "Une phrase de 170 caractères au plus (le build refuse au-delà)."
   date: 2026-10-18
   domaine: successions        # successions | separation-divorce | droit-du-travail | procedure-appel | cabinet
   type: fiche                 # fiche | simulateur | checklist | billet
   lecture: "7 min"
   ---
   ```
2. Corps en Markdown : intertitres `##`, un encadré « Le réflexe » en fin de fiche (`<div class="encadre">…</div>`), un paragraphe *Sources* en italique.
3. Jurisprudence : **chaque arrêt cité doit avoir été lu** sur Judilibre (`courdecassation.fr/decision/<id>`) ou Légifrance, avec date, numéro de pourvoi et lien. Pas de référence de mémoire. Préférer les arrêts publiés au Bulletin. Les textes de loi : lien vers l'article Légifrance dans sa version en vigueur.
4. Lier la fiche à son domaine : ajouter le slug dans `fiches: [...]` du fichier `src/content/domaines/<domaine>.md` (en tête de liste si elle est récente).
5. Vérifier, pousser, contrôler en préprod, puis passer en production (§ 4).

La fiche apparaît automatiquement sur `/fiches`, sur l'accueil (trois dernières), dans le sitemap et avec un JSON-LD `Article`.

### 3.2 Modifier un prix, un horaire, une coordonnée

- Rendez-vous (cabinet, visio, téléphone) : `bookings.prix` dans `src/data/cabinet.ts` **et** le prix dans chaque type de réunion Bookings (titre et description) **et** `honoraires.ts` (ligne « Consultation d'orientation ») **et** la FAQ de `honoraires.astro`.
- Consultation écrite : `$formules` dans `public/api/consultation.php` (montants TTC facturés), `services/consultation-ecrite.astro` (affichage), `services/index.astro` et `cabinet.ts › services` (« dès 80 € »), CGV dans `mentions-legales.astro`.
- Horaires : `cabinet.horaires` et `cabinet.horairesSchema` ; puis fiche Google Business et page Bookings (heures de disponibilité).
- Grille devis divorce (HT) : valeurs par défaut dans `_lib.php` (`devis`), surchargeables dans le fichier serveur (§ 6) ; l'affichage de `services/devis-divorce.astro` doit suivre.
- Confrères du cabinet groupé, assureur RCP (notice annuelle du Barreau), médiateur : `cabinet.ts`.
- Barème AJ : `aj` dans `honoraires.ts`, à mettre à jour **chaque janvier** d'après la circulaire du ministère (voir § 7).
- Bandeau d'annonce de l'accueil (réforme, texte nouveau) : `src/data/annonces.ts` — une entrée avec étiquette, phrase, lien et fenêtre d'affichage `du`/`au` ; le bandeau disparaît de lui-même à la date `au` (au prochain déploiement). Composant `Annonce.astro`, placé sous le bandeau d'accueil dans `index.astro`.

### 3.3 Ajouter ou modifier une page

Copier la structure d'une page voisine (`reglement-amiable.astro` est un bon modèle : hero, sections, FAQ, CTA). Titre `<title>` de 50 à 65 caractères avec « | Arnaud Cousin », description de 120 à 160 caractères, un seul `h1`, fil d'Ariane `fil=[…]`. Ajouter l'entrée de menu dans `cabinet.ts › navigation` si la page doit être accessible depuis l'en-tête (le méga-menu « Domaines » et l'accueil se nourrissent de `navigation.domaines` : l'accueil attend aussi une icône, une accroche et une liste par entrée dans `index.astro`).

### 3.4 Photos

Déposer un WebP dans `src/assets/photos/` (1 536 à 2 400 px de large, qualité 80), l'importer dans la page et l'afficher avec `<Image>` d'Astro (`widths`, `sizes`, `alt` descriptif, `loading="lazy"` sauf dans le bandeau). Les images générées par IA doivent être inspectées à 100 % (mains, lunettes, verres, bijoux, texte) ; recadrer ou retoucher avant usage. Crédit : « Photos et illustrations © Cabinet Arnaud Cousin » (footer et mentions légales) — vérifier que la licence le permet pour toute image achetée.

### 3.5 Formulaires et courriels

Les formulaires (contact, saisine, devis, consultation écrite, pré-entretien) sont en PHP dans `public/api/` et envoient depuis `site@`. Pour tester sans polluer : envoyer depuis la préprod avec `cabinet@` comme adresse du client ; les deux mails arrivent dans la boîte du cabinet (référence du type `261018-747E`). Les pièces des consultations écrites et les synthèses de pré-entretien sont stockées hors racine web dans `/home/arnaudcoqa/consultations/<réf>/` ; les paiements PayPal confirmés y laissent `paye.json`.

**Protection contre les robots** (oct. 2026, après les premiers spams reçus par le formulaire de contact). Trois filtres, dans `_lib.php` :
1. *Pot de miel* : champ invisible `site` ; s'il est rempli, l'envoi est ignoré en silence.
2. *Jeton signé* (`controle_anti_robot`) : à l'ouverture de la page, le composant `src/components/Jeton.astro` demande à `/api/jeton.php` un horodatage signé, à usage unique. Le serveur refuse l'envoi si le jeton manque (robot qui poste directement, ou visiteur sans JavaScript), s'il est faux ou déjà servi, s'il a moins de 3 secondes ou plus de 7 jours. Le refus affiche une page d'erreur lisible et aucun courriel ne part. Le secret de signature est tiré au hasard dans `/home/arnaudcoqa/cache/site-cabinet/jeton.secret` (ou `jeton_secret` dans le fichier serveur) ; le supprimer invalide seulement les pages déjà ouvertes.
3. *Contenu suspect* (`contenu_suspect`) : un lien dans le texte libre (http, www, telegra.ph, t.me, bit.ly, BBCode, HTML) fait arriver le message préfixé `[SPAM ?]`, avec un bandeau d'avertissement, **sans accusé de réception** à l'adresse saisie (qui peut être celle d'un tiers).

Appliqué au contact, à la saisine, au devis, à la consultation écrite et au pré-entretien (jeton joint à sa requête JSON). Statistiques : `cache/site-cabinet/journal.log` (`robot-pot-de-miel`, `robot-jeton-absent`, `robot-jeton-trop-rapide`, `robot-jeton-rejoue`, `ok-suspect`…), sans contenu personnel. Un nouveau formulaire doit recevoir `<Jeton formulaire="…" />` à côté du pot de miel, son nom dans `FORMULAIRES_JETON` et l'appel `controle_anti_robot('…')` dans son point d'entrée.

### 3.6 Dépôt de pièces (au quotidien)

Note d'emploi complète (cabinet et secrétariat, modèle de courriel, incidents, sécurité) : https://claude.ai/code/artifact/b71204eb-fe77-412e-b946-f7fa1fe79c5d

1. Ouvrir `https://arnaudcousin-avocat.fr/admin/depots`, saisir la clé de la console (`depot.cle_admin` du fichier serveur, § 6).
2. « Nouveau lien » : civilité et nom du client, courriel, intitulé du dossier tel que dans Secib, référence, pièces attendues, validité (30 jours par défaut). Le site envoie le lien au client (ou « Copier le lien » pour le coller dans un courriel Outlook).
3. Le client dépose depuis `/depot/<jeton>` (ordinateur ou téléphone, photos comprises) et clique « Terminer » : courriel `[SITE] Pièces déposées — <dossier>` au cabinet, accusé de réception au client. Chaque pièce est relayée dès réception dans SharePoint › site « Dépôts clients » › `Dépôts du site/<dossier — référence>/` (numérotées `01 - …`). Depuis le poste du cabinet (raccourci OneDrive « Dépôts du site »), rattacher dans Secib comme d'habitude — ou laisser faire l'agent (point 7). **La référence saisie dans la console doit être le numéro Secib à 9 chiffres** : c'est elle qui termine le nom du sous-dossier (`<intitulé> — <numéro>`) et permet le rattachement automatique.
4. Console : « Détail » (pièces, états SharePoint, messages du client), « Télécharger (zip) » (secours si le relais est en panne), « Relayer vers SharePoint » (reprise), « Prolonger », « Clore » (plus d'envoi possible), « Supprimer du serveur » (les copies SharePoint restent). Purge automatique : dépôt clos ou expiré depuis 7 jours dont toutes les pièces sont dans SharePoint, ou au plus tard 90 jours après l'échéance.
5. **Veille automatique** (`api/depot-veille.php`, tâche planifiée OVH quotidienne — Hébergement › Tâches planifiées : commande `prod/api/depot-veille.php`, PHP 8, une fois par jour en fin d'après-midi) : reprend les relais SharePoint en échec, prévient le cabinet des pièces déposées sans clic « Terminer » depuis plus de 12 h, signale les liens envoyés sans dépôt (tous les 5 jours puis à 3 jours de l'échéance) et les liens qui expirent avec des pièces ; un seul courriel « [SITE] Veille dépôts — N point(s) », rien si tout va bien. À défaut de tâche planifiée, la veille se lance à l'ouverture de la console si la dernière passe date de plus de 20 h.
6. Limites par lien : 50 Mo par fichier, 500 Mo et 60 pièces au total ; types : PDF, images (HEIC compris), Office, OpenDocument, texte, courriels `.eml`/`.msg`, audio, vidéo, zip sans exécutable. Le type réel est contrôlé sur le contenu, pas sur l'extension.
7. **Rattachement Secib automatique** (poste Secib, hors du site) : `C:\Users\arnau\agent-secib\outils\depot_rattachement.py`, tâche planifiée Windows quotidienne (18 h 15, `installer_depot_rattachement.ps1`). Parcourt OneDrive › Dépôts du site, rattache chaque nouvelle pièce au dossier Secib identifié par le numéro du sous-dossier (répertoire « 8 -Documents reçus », titre = nom d'origine, date = date du dépôt), puis envoie à cabinet@ « Rapport de rattachement de pièces client — jj/mm/aaaa » **seulement s'il y a eu des dépôts** (rattachées, à vérifier, en échec, sous-dossiers sans numéro Secib). Notice : `agent-secib\doc\RATTACHEMENT-DEPOTS.md` ; lancement manuel : `rattacher_depots_maintenant.cmd`.

### 3.7 Assistant de pré-entretien

Les questions vivent dans `src/data/pre-entretien.ts` (domaines, questions à choix ou texte, événements datés, démarches, pièces par domaine). Les modifier = éditer ce fichier, construire, préprod, prod. L'assistant ne donne jamais de conseil : les textes doivent rester descriptifs. Les synthèses arrivent par courriel `[SITE] Pré-entretien — <domaine> — <nom>` ; si la clé API est renseignée (§ 6), un bloc « analyse préparatoire » pour l'avocat seul s'y ajoute ; la consigne du modèle est dans `public/api/_assistant-consigne.php`.

## 4. Vérifier, puis mettre en ligne

```bash
cd astro
npm ci                 # première fois
npm run check          # types, schémas de contenu (descriptions trop longues, enum, etc.)
npm run build          # génère dist/ ; échoue si une fiche ne respecte pas le schéma
npm run preview        # http://localhost:4321 pour regarder
git add -A && git commit -m "…" && git push origin main     # → préprod en ~2 min
```

Contrôles en préprod avant toute production :
- la page nouvelle ou modifiée s'affiche, sur ordinateur et sur mobile (390 px) ;
- `https://preprod.arnaudcousin-avocat.fr/version.txt` porte bien le dernier commit ;
- aucun lien interne cassé (un script simple : extraire les `href="/…"` de `dist/` et vérifier que le fichier existe) ;
- pas de texte « [à confirmer] », pas de prix contradictoire entre deux pages.

Production : créer un tag `vX.Y.Z` sur le commit validé — soit `git tag vX.Y.Z && git push origin vX.Y.Z`, soit GitHub › Releases › *Draft a new release* (le tag est créé à la publication). L'environnement GitHub `production` n'accepte que les tags `v*`. Deux minutes plus tard, `/version.txt` en production doit afficher le commit. Numérotation : `v1.x.0` pour du contenu nouveau, `v1.x.y` pour une correction.

**Si la prod (ou la préprod) ne bouge pas** alors que l'action GitHub est verte : regarder GitHub › Settings › Webhooks › *Recent Deliveries* (compte `cousarn`). Le 24/10/2026, le service de webhooks d'OVH a répondu `401 {"class":"Client::Unauthorized"}` à une partie des envois (même URL, succès et échecs mêlés), et le renvoi (*Redeliver*) échouait aussi. Remède immédiat, sans rien casser : manager OVH › Hébergements › `arnaudcousin-avocat.fr` › **Mes sites** › ⋮ du site (`Website-prod` = dossier `prod`) › **Déployer Git** › *Valider* **sans** cocher « forcer » ; le statut passe « En cours » puis `/version.txt` est à jour en moins d'une minute. *Informations sur le dernier déploiement* (même menu) donne le journal du `git pull`.

*Mise à jour du 25/10/2026.* Les refus d'OVH sont **intermittents** : ce jour-là, la v1.9.0 et la v1.9.1 n'ont pas été tirées (déploiement manuel), la v1.9.2 l'a été automatiquement. L'URL de webhook enregistrée dans GitHub est identique à celle qu'affiche OVH (même jeton, vérifié dans « Configurer Git ») : **ne pas** réassocier Git ni recréer le webhook, cela ne changerait rien — et le formulaire « Configurer Git » d'OVH exige un dossier vide. Depuis cette date, l'action GitHub vérifie elle-même la mise en ligne (`/version.txt` doit porter le commit dans les 4 minutes) : une **croix rouge** sur l'étape « Vérifier la mise en ligne » signifie qu'il faut lancer « Déployer Git » à la main.

**Interdit** : réécrire l'historique des branches `deploy-preprod` / `deploy-prod` (OVH fait un `git pull` ; une branche réécrite bloque tout). Si OVH reste bloqué sur « En cours » et que les webhooks répondent 400 : Multisite › ⋯ › *Supprimer Git* (cocher « vider le répertoire ») puis *Associer Git* à nouveau (même URL de webhook).

## 5. Comptes et accès (qui fait quoi)

| Service | Compte | Ce qu'un agent peut faire | Ce qui reste à Arnaud |
|---|---|---|---|
| GitHub | `cousarn` | lire, committer, pousser sur `main`, **publier la release de production** (autorisation permanente d'Arnaud du 25/10/2026 : depuis son Chrome, page « New release », sans confirmation à lui demander) | gérer les webhooks (mode sudo) ; le clic « Publish release » seulement si le garde-fou de l'outil le refuse à l'agent |
| OVH (manager) | compte client Arnaud | zone DNS (TXT/CNAME de vérification), multisite, suivi des tâches | mot de passe, SFTP, facturation, certificats |
| Serveur (SFTP) | `arnaudcoqa` | rien directement : passer par FileZilla sur le poste d'Arnaud, qui saisit lui-même le mot de passe | identifiants |
| Microsoft 365 | `cabinet@…` (admin) | Bookings (types, disponibilités), lecture du calendrier pour vérifier les créneaux | connexion, alias, licences |
| PayPal | compte du cabinet | rien (l'identifiant marchand suffit au site ; l'IPN se vérifie sans clé) | tout ; activer l'IPN dans les préférences du compte (facultatif, le lien le porte déjà) |
| Entra / SharePoint | `cabinet@…` (admin) | préparer l'inscription d'application, les permissions, le site « Dépôts clients », l'octroi `Sites.Selected` | copier la valeur du secret client dans le fichier serveur (§ 6), renouveler le secret avant expiration — **secret actuel : expire le 17/04/2027** (Entra › Inscriptions d'applications › Site du cabinet — dépôt de pièces › Certificats & secrets) |
| Google (Search Console, Business Profile) | compte Google d'Arnaud (`arnaudcousin00@gmail.com` porte la Search Console) | Search Console une fois connecté (sitemap, inspection d'URL, demandes d'indexation) | connexion, fiche Google Business |
| Bing Webmaster | connecté via Google | sitemaps, inspection | connexion |

Jamais : saisir un mot de passe, un code, une clé API, un IBAN dans une conversation ou un fichier du dépôt. Le RIB n'existe que dans le fichier serveur (§ 6).

## 6. Le fichier serveur hors Git

`/home/arnaudcoqa/config/site-cabinet.php` (modèle : `public/api/config.local.example.php`) contient : `cabinet_email`, `from_email`, `rib` (texte affiché dans les accusés de réception), `paiement` (`paypal` | `virement` | `stripe`), `paypal_id`, `depot.cle_admin` (clé de la console des dépôts), `graph` (tenant, client_id, client_secret, site, racine — relais SharePoint), optionnellement `assistant.cle_api` (analyse IA du pré-entretien), `paypal_sandbox`, `paypal_email`, `devis`, `tva`. Il est lu par la préprod **et** la prod, jamais touché par les déploiements, protégé par un `try/catch` (une erreur de syntaxe n'empêche pas le site de fonctionner, elle est journalisée). Pour le modifier : FileZilla (SFTP, hôte du cluster 105) › clic droit › *Afficher/Éditer* › enregistrer › accepter le renvoi.

## 7. Veille et actualisation — calendrier

| Quand | Quoi | Où |
|---|---|---|
| Chaque semaine | Formulaires : un envoi test depuis la préprod ; Search Console › Pages (erreurs d'indexation) ; Bing idem | § 3.5, consoles |
| Chaque semaine | Mentions « v*.txt » : prod et préprod portent le dernier commit attendu | `/version.txt` |
| Chaque mois | Lien Bookings : réserver un créneau test et l'annuler ; vérifier que les RDV Outlook bloquent bien les créneaux | Bookings |
| Chaque mois | Fiche Google Business : horaires, avis à répondre, photos | Google |
| Chaque mois | Veille jurisprudentielle pour les fiches existantes (recel, délivrance de legs, indivision, salaire différé) : Judilibre, 1re civ., arrêts publiés ; mettre à jour la fiche (`updated:` dans le frontmatter) si un arrêt change la règle | Judilibre |
| Octobre | `public/.well-known/security.txt` : repousser la date `Expires` d'un an (contact de signalement des failles) | dépôt |
| Janvier | Barème de l'aide juridictionnelle (circulaire du ministère de la Justice, NOR JUST…) → `aj` dans `honoraires.ts` | justice.gouv.fr |
| Janvier | Notice RCP du Barreau de Rennes (assureur, n° de contrat) → `cabinet.assureur` ; millésime du barème d'honoraires si révisé | courrier de l'Ordre |
| Janvier | Année du serment / ancienneté : automatique (`anneesExercice` dans `cabinet.ts`) ; vérifier l'affichage | accueil, cabinet |
| Trimestre | Dépendances : `npm outdated`, `npm audit` ; mettre à jour Astro mineur, rebuild, préprod | dépôt |
| Trimestre | Certificat SSL OVH (renouvellement automatique Let's Encrypt), expiration du domaine (déc. 2026), DMARC à envisager | OVH |
| À chaque changement de prix, d'horaire, de confrère | Mettre à jour **toutes** les occurrences listées au § 3.2 et la page Bookings | — |
| À chaque texte nouveau (loi, décret) touchant famille, successions, travail, procédure | Billet court sur `/fiches` (type `billet`), lié au domaine | § 3.1 |

Sources de veille utiles : Judilibre (recherche par chambre + « publié au bulletin »), Légifrance (versions des articles), justice.gouv.fr (politique de l'amiable, AJ), Dalloz actualité / Actu-Juridique (abonnements du cabinet), bulletin de l'Ordre.

**Veille automatique du matin** (depuis le 24/10/2026) : une tâche planifiée Claude « Veille JO et Cour de cassation » tourne du lundi au vendredi vers 7 h 30 (heure de Paris). Elle clone ce dépôt, lance `veille/veille_dila.py` (données brutes DILA : JO de la veille et du jour, nouveaux lots CASS/INCA/CONSTIT — voir `veille/README.md`), lit les textes retenus et envoie à `cabinet@` un courriel « [VEILLE] JO et Cour de cassation — jj/mm/aaaa » avec résumé et liens Légifrance ; pas de courriel si rien ne concerne le cabinet. Elle n'écrit rien dans le dépôt. Réglage de l'horaire, du destinataire ou de l'approbation : application Claude › tâches planifiées. Les décisions du jour de la Cour de cassation ne sont lues que si une clé PISTE (API Judilibre) est fournie à l'environnement en variable `JUDILIBRE_KEYID` — à créer par Arnaud sur piste.gouv.fr le cas échéant.

## 8. Améliorer le référencement — méthode

Le site est techniquement propre (URL courtes, 301 de l'ancien site, canonical, sitemap, JSON-LD `LegalService`/`Attorney`, `BreadcrumbList`, `FAQPage`, `Article`, CSP, images WebP/AVIF, polices locales). Le levier est maintenant **le contenu local et spécialisé**, puis la notoriété. Un agent peut conduire ce travail en boucle mensuelle.

### 8.1 Mesurer (chaque mois, 20 min)
- Search Console › *Performances* : requêtes et pages, filtrer les 90 derniers jours ; noter les requêtes en position 5–20 (gains rapides) et celles où le CTR est faible malgré une bonne position (titre/description à réécrire).
- Search Console › *Pages* : erreurs, pages « explorées, non indexées » ; *Améliorations* : données structurées (FAQ, fil d'Ariane) sans erreur.
- Bing Webmaster › *Search Performance* et *Site Scan* (audit technique gratuit).
- Fiche Google Business : vues, appels, clics « itinéraire » et « site web » ; nombre et note des avis.
- Tenir un tableau simple (date, requête, position, page) dans `docs/seo-suivi.md` ou un tableur du cabinet.

### 8.2 Mots-clés visés (à affiner avec les données)
Requêtes principales, toutes avec « Rennes » ou « avocat » : *avocat succession Rennes*, *avocat partage judiciaire*, *sortie d'indivision avocat*, *recel successoral avocat*, *délivrance de legs*, *avocat divorce Rennes*, *prestation compensatoire*, *avocat droit du travail Rennes*, *licenciement Rennes avocat*, *postulation Rennes*, *avocat médiation Rennes*, *conflit associés SCI familiale*, *salaire différé agricole*. Une page ou une fiche par intention de recherche ; ne pas créer deux pages concurrentes sur la même requête (cannibalisation).

### 8.3 Produire (une à deux fiches par mois)
- Sujets à forte demande et faible concurrence locale : procédure concrète + délais + coût + « quand consulter ». Modèle : les fiches *recel* et *délivrance du legs* (question du client dans le titre, jurisprudence sourcée, encadré « Le réflexe », appel à consulter).
- Idées en réserve : pension alimentaire (barème, révision), prestation compensatoire (calcul, capital/rente), résidence alternée, liquidation du régime matrimonial après divorce, indemnité d'occupation en indivision, licitation (déroulé d'une vente aux enchères), rupture conventionnelle (délais, calcul), inaptitude, barème Macron, appel civil (délais, déclaration d'appel), postulation (mode d'emploi pour confrères), médiation familiale (déroulé, coût), SCI familiale bloquée (révocation du gérant, dissolution).
- Chaque fiche : 1 200 à 2 000 mots, intertitres qui répondent à des questions, liens internes vers le domaine, le rendez-vous et une fiche voisine ; `description` qui donne envie ; date réelle ; `updated:` à chaque mise à jour substantielle (Google valorise la fraîcheur).
- Mettre à jour les fiches existantes quand la jurisprudence bouge (voir veille § 7) plutôt que d'en créer une nouvelle sur le même sujet.

### 8.4 Référencement local
- Fiche Google Business : catégorie principale « Avocat », secondaires (droit de la famille, successions, droit du travail), horaires exacts (9h–12h / 14h–19h), lien *Prendre rendez-vous* vers la page Bookings, description de 750 caractères reprenant les domaines, photos du cabinet, publication mensuelle (« Google Posts ») reprenant une fiche. Répondre à **tous** les avis, y compris négatifs, sobrement et sans rien révéler d'un dossier (secret professionnel).
- Cohérence NAP (nom, adresse, téléphone) partout : site, Google, Bing Places, annuaire de l'Ordre, avocat.fr, Pages Jaunes, Justifit/Alexia si présents. Un numéro, une graphie de l'adresse.
- **Audit NAP du 25/10/2026** (données Search Console : 613 impressions / 23 clics sur 17–23 oct., quasi exclusivement sur le nom) : *avocat.fr* (fiche 5424) — adresse et site exacts, mais téléphone, horaires, serment absents, photo de 2014, doublon de compétences → à compléter avec les identifiants CNB d'Arnaud ; *Pages Jaunes* (pro 55168592) — téléphone exact, mais ni site, ni horaires, ni description, ni photo → à revendiquer (compte Solocal) ; *neobarreau*, *keskeces*, *starofservice*, *horairesdouverture24* — fiches automatiques, non prioritaires. Lien d'avis Google : `https://g.page/r/CZi40OFONlXOEBM/review`. Anciennes URL TOWeb encore indexées redirigées en 301 (`.htaccess` § 2).
- Liens entrants de qualité : annuaire de l'Ordre (déjà), avocat.fr (via l'Ordre), fiches partenaires (notaires, CMR 35 si réciprocité), interventions/publications (Gazette du Palais, revue du Barreau, conférences), article invité sur un site de notaires ou d'experts. Pas d'achat de liens, pas d'annuaires de masse.

### 8.5 Technique (chaque trimestre)
- PageSpeed Insights sur l'accueil, une page domaine et une fiche : viser LCP < 2,5 s, CLS ≈ 0 ; surveiller le poids de la photo du bandeau et le `fetchpriority="high"` sur la première image.
- Test des résultats enrichis (search.google.com/test/rich-results) sur une page FAQ et une fiche.
- Liens cassés internes (script du § 4) et externes (les URL Judilibre/Légifrance sont stables ; revérifier une fois par an).
- Titres ≤ 65 caractères, descriptions ≤ 160 : `python3` sur `dist/` comme au § 4, après chaque série de modifications.
- Ne jamais indexer la préprod (balise `noindex` posée par `PUBLIC_ENV=preprod`) ; vérifier qu'aucune URL `preprod.` n'apparaît dans Search Console.

### 8.6 Ce qu'on ne fait pas
Pas de texte généré en masse, pas de pages « ville » dupliquées (Rennes, Saint-Malo, Saint-Brieuc… : une mention naturelle du ressort suffit), pas de mots-clés cachés, pas d'avis sollicités en échange d'un avantage, pas de promesse de résultat (déontologie : RIN art. 10). Le contenu doit rester celui d'un avocat qui explique ; c'est ce qui le fait référencer et ce qui fait venir les clients.

## 9. Ce qui n'est pas encore fait (phase 2)

- Dépôt de pièces : le relais SharePoint fonctionne dès que les clés `graph` sont renseignées (§ 6) ; d'ici là, pièces sur le serveur et zip depuis la console. Envisager plus tard une suppression des copies serveur immédiatement après relais confirmé.
- Pré-entretien : activer l'analyse IA (clé `assistant.cle_api`) si l'avocat le souhaite ; vérifier l'identifiant de modèle au moment de l'activer.
- Stripe Checkout en complément de PayPal (compte Stripe, clés dans le fichier serveur, webhook signé).
- Page Bookings partagée (logo, couleurs) si Microsoft corrige l'erreur de création sur le tenant.
- DMARC sur le domaine ; nettoyage des entrées DNS parasites `arnaudcousin-avocat.fr.arnaudcousin-avocat.fr`.
- Avis à l'Ordre : e-mail affiché dans l'annuaire (`arnaud.cousin@avocat-conseil.fr`) et langue en double.

## 10. Brief type pour une session IA

L'agent webmestre est décrit dans `.claude/skills/site-cabinet-webmestre/SKILL.md` (chargé automatiquement par Claude Code dans ce dépôt, ou enregistré comme compétence « site-cabinet-webmestre » du compte Claude) ; `CLAUDE.md` en donne le résumé. Un kit de démarrage hors Git (brief à coller, copies de ces documents, note d'emploi du dépôt, notice de l'agent SECIB) est sur le OneDrive du cabinet : `site web sauvegardes\site 2026 - kit session IA\`.

> Tu administres le site statique Astro du Cabinet Arnaud Cousin (dépôt `cousarn/site-cabinet`, dossier `astro/`). Lis `ADMINISTRATION.md` puis `README.md`. Tâche : ___. Contraintes : aucun secret dans le dépôt ni dans la conversation ; ne jamais réécrire les branches `deploy-*` ; toute jurisprudence citée doit avoir été lue sur Judilibre ou Légifrance avec son lien ; descriptions ≤ 170 caractères ; vérifier `npm run check` et `npm run build` avant de pousser ; pousser sur `main`, contrôler la préprod, puis proposer le tag de production sans le poser toi-même sauf instruction expresse. Rends compte en français, en quelques lignes, avec les URL à vérifier.

Historique des décisions et de la mise en ligne (18–19 octobre 2026) : voir `README.md`.
