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
      filter: (page) => !page.includes('/mentions-legales') && !page.includes('/depot/'),
      changefreq: 'monthly',
      lastmod: new Date(),
    }),
  ],
  image: { domains: [] },
  compressHTML: true,
});
