/**
 * Données de référence du cabinet — une seule source de vérité.
 * Tout ce qui est entre crochets […] reste à confirmer par Me Cousin.
 */
export const cabinet = {
  nom: 'Cabinet Arnaud Cousin',
  avocat: 'Maître Arnaud Cousin',
  avocatCourt: 'Me Arnaud Cousin',
  barreau: 'Barreau de Rennes',
  toque: '128',
  sermentAnnee: 2000,
  sermentMois: 'janvier',
  adresse: {
    rue: '15 rue du Puits Mauger',
    cp: '35000',
    ville: 'Rennes',
    pays: 'FR',
  },
  geo: { lat: 48.1056144, lon: -1.6823528 },
  telephone: '02 30 96 33 35',
  telephoneE164: '+33230963335',
  email: 'cabinet@arnaudcousin-avocat.fr',
  horaires: 'Lundi–vendredi 9h–12h · 14h–18h',
  horairesSchema: ['Mo-Fr 09:00-12:00', 'Mo-Fr 14:00-18:00'],
  siteUrl: 'https://arnaudcousin-avocat.fr',
  confreres: ['Bagot', 'Simon', 'Piperaud', 'Dolivet', 'Lévêque'],
  // Mentions légales — à compléter
  siret: '[SIRET]',
  tva: '[n° TVA intracommunautaire]',
  assureur: '[assureur RC professionnelle — nom, adresse]',
  mediateur: '[médiateur de la consommation de la profession d’avocat — coordonnées]',
} as const;

/** Nombre d'années d'exercice, calculé automatiquement (serment janvier 2000). */
export function anneesExercice(date = new Date()): number {
  return date.getFullYear() - cabinet.sermentAnnee;
}

/** « vingt-six » pour les textes en lettres (jusqu'à 39). */
export function anneesEnLettres(n = anneesExercice()): string {
  const unites = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf'];
  const dizaines: Record<number, string> = { 2: 'vingt', 3: 'trente' };
  if (n < 20 || n >= 40) return String(n);
  const d = Math.floor(n / 10), u = n % 10;
  if (u === 0) return dizaines[d];
  if (u === 1) return `${dizaines[d]} et un`;
  return `${dizaines[d]}-${unites[u]}`;
}

export const navigation = {
  principale: [
    { href: '/cabinet', label: 'Le cabinet' },
    { href: '/domaines', label: 'Domaines', menu: 'domaines' },
    { href: '/honoraires', label: 'Honoraires' },
    { href: '/services', label: 'Services en ligne', menu: 'services' },
    { href: '/fiches', label: 'Fiches & actualités' },
    { href: '/contact', label: 'Contact' },
  ],
  domaines: [
    { href: '/domaines/separation-divorce', titre: 'Séparation & divorce', accroche: 'Enfants, pension, patrimoine : préserver l’essentiel, vite.' },
    { href: '/domaines/successions', titre: 'Successions, partages & indivision', accroche: 'Un héritier bloque, le notaire attend : en sortir sans y laisser votre part.' },
    { href: '/domaines/droit-du-travail', titre: 'Droit du travail', accroche: 'Licenciement, rupture : accepter, contester ou négocier ?' },
    { href: '/domaines/procedure-appel', titre: 'Procédure civile, appel & postulation', accroche: 'Un jugement à contester, un délai qui court.' },
  ],
  services: [
    { href: '/services/rendez-vous', titre: 'Prendre rendez-vous', accroche: 'Cabinet, visio ou téléphone, créneau choisi en ligne.' },
    { href: '/services/consultation-ecrite', titre: 'Consultation écrite en ligne', accroche: 'Une réponse d’avocat par écrit, sans vous déplacer. Dès 100 €.' },
    { href: '/services/devis-divorce', titre: 'Devis divorce amiable', accroche: 'Votre estimation en sept questions, confirmée par l’avocat.' },
    { href: '/services/depot-pieces', titre: 'Déposer des pièces', accroche: 'Vos documents, en sécurité, directement dans votre dossier.' },
    { href: '/services#assistant', titre: 'Assistant de pré-entretien', accroche: 'Préparer votre rendez-vous, repérer un délai urgent.' },
    { href: '/notaires-confreres', titre: 'Notaires & confrères', accroche: 'Postulation, partage judiciaire : saisir le cabinet.' },
  ],
  pied: [
    { href: '/mentions-legales', label: 'Mentions légales' },
    { href: '/mentions-legales#rgpd', label: 'Confidentialité' },
    { href: '/mentions-legales#cookies', label: 'Cookies' },
    { href: '/mentions-legales#mediation', label: 'Médiateur de la consommation' },
    { href: '/mentions-legales#cgv', label: 'CGV consultation en ligne' },
  ],
} as const;

/** Accès — vérifié sur OpenStreetMap et fiches opérateurs (oct. 2026). */
export const acces = {
  metro: [
    { ligne: 'b', station: 'Colombier', distance: '50 m', temps: '1 min' },
    { ligne: 'a', station: 'Charles-de-Gaulle', distance: '450 m', temps: '6 min' },
  ],
  bus: { arrets: ['Cité Judiciaire', 'Colombier'], lignes: ['C5', 'C6'], aConfirmer: '[+ 9, 57 à confirmer]' },
  train: 'Gare de Rennes — 10 min à pied, ou métro Gares → Colombier (1 station)',
  parkings: [
    { nom: 'Colombier', operateur: 'Citédia', detail: '4 rue de Plélo — 100 m', note: '[places, 24h/24 à confirmer]' },
    { nom: 'Halles centrales', operateur: 'Citédia', detail: '330 m · 40 places', note: '' },
    { nom: 'Charles-de-Gaulle', operateur: 'Citédia', detail: 'esplanade — 450 m · 800 places', note: '' },
    { nom: 'Gare Sud', operateur: 'Citédia', detail: '750 m · 1 000 places', note: '' },
  ],
  voirie: 'Voirie payante rue du Puits Mauger et rues voisines · dépose-minute devant l’immeuble · bornes de recharge Rennes Métropole, esplanade Charles-de-Gaulle (400 m).',
  velo: { stations: ['Colombier', 'Cité Judiciaire', 'La Rotonde', 'Charles-de-Gaulle'], note: 'arceaux devant le centre Colombia' },
  accessibilite: '[à préciser : étage, ascenseur, accès PMR]',
} as const;
