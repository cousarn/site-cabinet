// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';
import { readdirSync, readFileSync } from 'node:fs';

// Date réelle de chaque fiche pour le sitemap : « updated », à défaut « date », lues dans l'en-tête du fichier.
// Google n'utilise <lastmod> que s'il est fiable ; la date de construction, identique partout, ne l'était pas.
const dossierFiches = new URL('./src/content/fiches/', import.meta.url);
const datesFiches = Object.fromEntries(
  readdirSync(dossierFiches)
    .filter((nom) => nom.endsWith('.md'))
    .map((nom) => {
      const entete = readFileSync(new URL(nom, dossierFiches), 'utf8').split(/^---\s*$/m)[1] ?? '';
      /** @param {string} cle */
      const lire = (cle) => entete.match(new RegExp(`^${cle}:\\s*["']?(\\d{4}-\\d{2}-\\d{2})`, 'm'))?.[1];
      return [nom.replace(/\.md$/, ''), lire('updated') ?? lire('date')];
    }),
);

export default defineConfig({
  site: 'https://arnaudcousin-avocat.fr',
  // URLs sans extension ni barre finale : /cabinet, /domaines/successions …
  // (le .htaccess d'OVH sert cabinet.html pour /cabinet)
  // Feuilles de style intégrées à chaque page : aucune requête CSS bloquant l'affichage (8 Ko compressés au plus)
  build: { format: 'file', inlineStylesheets: 'always' },
  trailingSlash: 'never',
  integrations: [
    sitemap({
      // Pages noindex hors du sitemap : mentions légales, page « merci », dépôt de pièces (lien personnel), console, 404.
      filter: (page) => !/\/(mentions-legales|merci|services\/depot-pieces|depot|admin|apercu|404)(\/|$)/.test(page),
      // <lastmod> seulement pour les fiches, à leur date réelle ; rien pour les autres pages.
      serialize(item) {
        const fiche = item.url.match(/\/fiches\/([^/]+)\/?$/);
        const jour = fiche && datesFiches[fiche[1]];
        if (jour) item.lastmod = new Date(`${jour}T00:00:00Z`).toISOString();
        return item;
      },
    }),
  ],
  image: { domains: [] },
  compressHTML: true,
  // Jamais de script ni de police en ligne (data:) : la CSP n'autorise que 'self'.
  vite: { build: { assetsInlineLimit: 0 } },
});
