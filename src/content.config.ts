import { defineCollection, z } from 'astro:content';
import { glob } from 'astro/loaders';

/** Fiches pratiques, simulateurs, billets — un fichier Markdown par fiche. */
const fiches = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/fiches' }),
  schema: z.object({
    title: z.string(),
    description: z.string().max(170),
    date: z.coerce.date(),
    updated: z.coerce.date().optional(),
    domaine: z.enum(['successions', 'separation-divorce', 'droit-du-travail', 'procedure-appel', 'cabinet']),
    type: z.enum(['fiche', 'simulateur', 'checklist', 'billet']).default('fiche'),
    lecture: z.string().optional(), // « 6 min »
    draft: z.boolean().default(false),
  }),
});

/** Pages domaines — contenu éditorial en Markdown, structure dans le frontmatter. */
const domaines = defineCollection({
  loader: glob({ pattern: '**/*.md', base: './src/content/domaines' }),
  schema: z.object({
    title: z.string(),          // <title> / H1 SEO
    h1: z.string(),
    description: z.string().max(170),
    accroche: z.string(),       // sous le H1 : le besoin du client
    image: z.enum(['parlement', 'couloir', 'cite', 'themis']).default('parlement'),
    ordre: z.number(),
    situations: z.array(z.object({ titre: z.string(), texte: z.string() })),
    methode: z.array(z.object({ titre: z.string(), texte: z.string() })).optional(),
    encadre: z.object({ titre: z.string(), texte: z.string(), lien: z.string().optional(), libelle: z.string().optional() }).optional(),
    faq: z.array(z.object({ q: z.string(), r: z.string() })).default([]),
    fiches: z.array(z.string()).default([]), // slugs de fiches liées
  }),
});

export const collections = { fiches, domaines };
