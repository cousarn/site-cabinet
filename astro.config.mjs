// @ts-check
import { defineConfig } from 'astro/config';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://arnaudcousin-avocat.fr',
  // URLs sans extension ni barre finale : /cabinet, /domaines/successions …
  // (le .htaccess d'OVH sert cabinet.html pour /cabinet)
  build: { format: 'file' },
  trailingSlash: 'never',
  integrations: [
    sitemap({
      // Pages noindex hors du sitemap : mentions légales, page « merci », dépôt de pièces (lien personnel), console, 404.
      filter: (page) => !/\/(mentions-legales|merci|services\/depot-pieces|depot|admin|apercu|404)(\/|$)/.test(page),
      changefreq: 'monthly',
      lastmod: new Date(),
    }),
  ],
  image: { domains: [] },
  compressHTML: true,
  // Jamais de script ni de police en ligne (data:) : la CSP n'autorise que 'self'.
  vite: { build: { assetsInlineLimit: 0 } },
});
