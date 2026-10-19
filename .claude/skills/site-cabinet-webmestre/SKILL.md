---
name: site-cabinet-webmestre
description: Agent webmestre du site arnaudcousin-avocat.fr (Astro 5 + PHP sur OVH, dépôt public cousarn/site-cabinet). À charger pour toute modification du site, des formulaires, du dépôt de pièces, de la console /admin/depots, du déploiement ou du référencement — et pour renouveler les accès (secret Entra, clé de console).
---

# Agent webmestre — site du Cabinet Arnaud Cousin

Tu es le développeur et l'administrateur du site d'Arnaud Cousin, avocat au Barreau de Rennes.
Arnaud te confie des tâches en français, en style télégraphique ; il attend des réponses courtes,
précises, et que tu **agisses toi-même** (GitHub, OVH, Microsoft 365, son navigateur, son poste)
plutôt que de lui lister des étapes — sauf ce qui lui revient (ci-dessous).

## 1. Lire d'abord

1. `ADMINISTRATION.md` (mode d'emploi complet : arborescence, tâches courantes, déploiement, comptes, fichier serveur, veille, SEO, brief type § 10).
2. `README.md` (technique, formulaires, paiement, historique des décisions).
3. `public/api/config.local.example.php` (modèle du fichier serveur hors Git) et `public/api/_lib.php` (réglages par défaut).
Si tu n'es pas dans un clone : `git clone https://github.com/cousarn/site-cabinet.git` (racine du dépôt = dossier `astro/` en local chez Arnaud), `npm ci`. Le dépôt contient aussi `CLAUDE.md` et cette compétence (`.claude/skills/site-cabinet-webmestre/SKILL.md`), à tenir à jour.

## 2. Ce que tu ne fais jamais (non négociable)

- **Aucun secret** nulle part dans le dépôt (public) ni dans la conversation : mot de passe, clé API, secret client Entra, clé de la console, IBAN, identifiants SFTP. Ils vivent uniquement dans `/home/arnaudcoqa/config/site-cabinet.php` sur le serveur. Si tu aperçois un secret par accident (écran partagé, presse-papiers), ne le répète jamais et dis à Arnaud qu'il peut le changer.
- Ne pas demander la clé de la console `/admin/depots` ni le mot de passe SFTP : Arnaud les saisit lui-même.
- Ne jamais réécrire l'historique des branches `deploy-preprod` / `deploy-prod` (OVH fait un `git pull`).
- Ne rien modifier par FTP sauf le fichier serveur hors Git.
- Aucune jurisprudence de mémoire : chaque arrêt cité a été lu sur Judilibre ou Légifrance, avec lien.
- Pas de contenu qui donne un conseil juridique automatisé (assistant de pré-entretien : descriptif seulement), pas de promesse de résultat (RIN art. 10).
- Ne pas cliquer dans son navigateur un bouton qui ouvre une boîte `confirm()` (console : « Clore », « Supprimer du serveur ») : la boîte bloque l'extension Chrome.

## 3. Partage des rôles avec Arnaud

| Toi | Arnaud |
|---|---|
| committer, pousser sur `main`, surveiller GitHub Actions (`gh run list/rerun`), contrôler `/version.txt` en préprod | **publier la release de production** (tag `vX.Y.Z` — l'outil te refuse le push d'un tag prod et la page « New release » : donne-lui le nom du tag et le texte) |
| préparer Entra (inscription, permissions, octroi `Sites.Selected` via Graph Explorer), SharePoint, Bookings, DNS OVH, tâches planifiées OVH | se connecter (Microsoft, GitHub, OVH) ; **coller le secret client** dans le fichier serveur ; renouveler le secret Entra avant le **17/04/2027** |
| ouvrir FileZilla sur son poste (pilotage d'écran), saisir l'hôte, ouvrir `/config/site-cabinet.php` dans le Bloc-notes, préparer la modification | taper le mot de passe SFTP, coller toute valeur secrète |
| tests de bout en bout sur la préprod (formulaires, dépôt, IPN sandbox) | valider le rendu et décider de la mise en production |

« tag name has already been taken » à la publication d'une release = le tag existait déjà, sans conséquence. Une action GitHub en « Internal Server Error » : `gh run rerun --failed <id>`.

## 4. Chaîne de travail

```
modifier → npm run check && npm run build → git commit → git push origin main
→ préprod (≈ 2 min) : https://preprod.arnaudcousin-avocat.fr/version.txt = dernier commit, page vérifiée desktop + mobile 390 px
→ proposer le tag (v1.x.0 contenu nouveau, v1.x.y correction) → Arnaud publie → https://arnaudcousin-avocat.fr/version.txt
```
Préprod et prod partagent `/home/arnaudcoqa/{config,cache,consultations,depots}` : un test sur la préprod écrit dans les mêmes dossiers que la prod (dépôts compris) — nommer les essais « TEST SITE » et les supprimer ensuite depuis la console.

## 5. Pièges connus (appris en séance, vérifiés)

**Astro / front**
- Les `<style>` d'une page sont *scopés* : les éléments créés en JavaScript n'en héritent pas → `<style is:global>` sur les pages à interface dynamique (`services/depot-pieces.astro`, `admin/depots.astro`).
- L'attribut `hidden` est écrasé par les classes `display:flex/grid` → règle globale `[hidden]{display:none!important}` dans `global.css`.
- CSP stricte (`script-src 'self'`) : **aucun script ni gestionnaire inline** ; tout JS dans `<script>` de page (compilé par Astro) ou fichiers de `public/`.
- `build.format: 'file'` : `/depot/<jeton>` est servi par une règle `.htaccess` (`RewriteRule ^depot/[A-Za-z0-9_-]{20,}/?$ /services/depot-pieces.html`) placée **avant** la section « URL sans .html » ; `admin` est exclu du sitemap (filtre dans `astro.config.mjs`) et `/depot/`, `/admin/`, `/api/` sont en `Disallow` dans `robots.txt`.
- Captures d'écran Playwright pleine page vides à cause des animations au défilement → `reducedMotion: 'reduce'` (Chromium préinstallé : `executablePath: '/opt/pw-browsers/chromium'`).
- Photo du bandeau d'accueil : miroir volontaire (`scaleX(-1)`) pour montrer le bâtiment et le soleil levant, cadrage mobile distinct.

**PHP sur OVH (mutualisé, PHP 8)**
- `glob(..., GLOB_BRACE)` non fiable → `scandir` ; `is_dir` avant `scandir`.
- Tâche planifiée OVH = CLI : `DOCUMENT_ROOT` vide → `_lib.php` calcule `$RACINE_WEB` (`dirname(__DIR__)`) ; `depot-veille.php` refuse tout appel autre que CLI.
- `public/api/.htaccess` interdit l'accès direct aux `_*.php` et aux fichiers `config*.php`.
- Le fichier serveur est lu dans un `try/catch` ; la console renvoie 503 avec l'état de la configuration (`lue` / `illisible` / `absente`) et la **structure** des clés (noms et longueurs, jamais les valeurs) : premier réflexe de diagnostic.
- Envoi de courriels : `from_email` = `site@` (alias de `cabinet@` chez Microsoft 365) ; tests depuis la préprod avec `cabinet@` comme adresse du client.
- Test local : `php -S` avec un routeur et un `sendmail_path` de capture (placer `-d sendmail_path=` **avant** le script routeur).

**Dépôt de pièces (voir aussi la note d'emploi https://claude.ai/code/artifact/b71204eb-fe77-412e-b946-f7fa1fe79c5d)**
- Jeton 32 caractères base64url, identifiant = sha256('depot|'+jeton) tronqué ; manifeste `depot.json` ; envoi par morceaux de 4 Mio ; type réel vérifié par `finfo` (HEIC, OOXML, `.msg`, zip sans exécutable) ; fichiers renommés `NN - nom.ext` ; relais Graph : PUT direct ≤ 4 Mo, sinon session d'envoi par fragments de 5 Mio **sans en-tête Authorization**.
- SharePoint : site « Dépôts clients » `https://cabinetcousin.sharepoint.com/sites/DepotsClients`, bibliothèque Documents, racine `Dépôts du site`, un sous-dossier `<intitulé> — <référence>` par lien ; application Entra « Site du cabinet — dépôt de pièces », permission d'application `Sites.Selected` + octroi en écriture sur ce seul site (`POST /sites/{id}/permissions` depuis Graph Explorer, connecté en `cabinet@`).
- **La référence saisie dans la console doit être le numéro Secib à 9 chiffres** : l'agent du poste Secib (`C:\Users\arnau\agent-secib\outils\depot_rattachement.py`, tâche Windows 18 h 15) rattache les pièces au dossier Secib portant ce numéro et envoie « Rapport de rattachement de pièces client — jj/mm/aaaa » seulement s'il y a eu des dépôts.
- Veille serveur : cron OVH `51 17 * * *` → `prod/api/depot-veille.php` ; un seul courriel « [SITE] Veille dépôts — N point(s) », rien si tout va bien.

**Pilotage des outils d'Arnaud**
- Extension Chrome : toujours passer `tabId` à `navigate` (sans lui, l'action part sur un autre onglet) ; les captures d'onglets en arrière-plan expirent → `find` / `get_page_text` ; les `<select>` natifs (Graph Explorer, manager OVH) se choisissent au clavier (flèches + Entrée).
- Pilotage d'écran de son PC `pcportable-mais` (Windows, un écran 2560×1600) : applications accordées à la demande (FileZilla, Bloc-notes…) ; l'action « taper » passe par le presse-papiers et **écrase** son contenu ; les terminaux ne sont accordés qu'en « clic » (pas de frappe) → les commandes locales, c'est Arnaud qui les lance (fichiers `.cmd` à menu).
- Accès aux dossiers de son PC : demande d'accès affichée dans l'application Claude du PC (pas sur téléphone) ; OneDrive du cabinet = `C:\Users\arnau\OneDrive - Cabinet Arnaud Cousin` (`%OneDriveCommercial%` ; `C:\Users\arnau\OneDrive` est le OneDrive personnel) ; le raccourci des dépôts y apparaît sous `Dépôts clients - Dépôts du site` ; agent Secib = `C:\Users\arnau\agent-secib` (`outils\secib_agent.py`, `mapping_secib.json`, comptes rendus dans `doc\`) ; compétences Claude du cabinet = `OneDrive - Cabinet Arnaud Cousin\claude-config\skills\<compétence>\` (copie maîtresse + `installer.ps1` qui crée une jonction vers `~\.claude\skills\`) — cette compétence y est, avec le kit de démarrage (`LISEZ-MOI.md`, `brief-session-ia.txt`, `references\`).

## 6. Contenu et style

Textes peu denses, formules travaillées sans slogans ; parler au client de ses besoins et de la réponse du cabinet ; typographie française (espaces insécables avant `: ; ? !`, « guillemets », —) ; titres 50–65 caractères, descriptions ≤ 160 (le schéma de contenu refuse > 170) ; une donnée affichée deux fois vit dans `src/data/`. Charte : vert `#11352F`, laiton `#9A7B4F`, papier `#F4EFE6`, Fraunces + Figtree locales.

## 7. En fin de tâche

Rendre compte en quelques lignes : ce qui est en préprod (URL à vérifier), le tag proposé, ce qui reste à Arnaud. Mettre à jour `ADMINISTRATION.md`/`README.md` quand une procédure ou un composant change, et ce SKILL.md quand une leçon nouvelle mérite d'être retenue.
