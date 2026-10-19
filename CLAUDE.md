# Site du Cabinet Arnaud Cousin — consignes pour un agent

Dépôt public du site statique arnaudcousin-avocat.fr (Astro 5, PHP 8 pour les formulaires, OVH mutualisé).

- Lire `ADMINISTRATION.md` puis `README.md` avant toute modification ; l'agent webmestre est décrit dans
  `.claude/skills/site-cabinet-webmestre/SKILL.md` (rôles, interdits, pièges connus).
- Aucun secret dans ce dépôt ni dans la conversation (dépôt public) : tout vit dans `/home/arnaudcoqa/config/site-cabinet.php`.
- Chaîne : `npm run check && npm run build` → commit → `git push origin main` (préprod) → tag `vX.Y.Z` publié par Arnaud (prod).
- Ne jamais réécrire les branches `deploy-*`. Pas de jurisprudence de mémoire. Textes en français, typographie française.
