/**
 * Données de référence du cabinet — une seule source de vérité.
 * Mentions légales vérifiées le 18/10/2026 (avis SIRENE, factures du cabinet, notice RCP 2026 du Barreau).
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
  horaires: 'Lundi–vendredi 9h–12h · 14h–19h',
  horairesSchema: ['Mo-Fr 09:00-12:00', 'Mo-Fr 14:00-19:00'],
  siteUrl: 'https://arnaudcousin-avocat.fr',
  confreres: ['I. Bagot', 'A.-C. Simon', 'S. Piperaud', 'C. Dolivet', 'C. Levêque'], // cabinet groupé (mise à jour 18/10/2026 : F. Barbier et S. Pellen partis, C. Levêque arrivé)
  // Mentions légales
  siret: '429 772 940 00038',
  tva: 'FR70 429 772 940',
  assureur: 'contrat groupe n° 114247874 souscrit par le Barreau de Rennes auprès de MMA IARD Assurances Mutuelles et MMA IARD SA (apériteur), 160 rue Henri Champion, 72030 Le Mans Cedex 9, en coassurance avec AXA France IARD SA',
  assureurCouverture: 'monde entier, à l’exception des activités exercées au sein d’un établissement permanent situé aux États-Unis d’Amérique ou au Canada',
  mediateur: 'Médiateur de la consommation de la profession d’avocat, 180 boulevard Haussmann, 75008 Paris — mediateur@mediateur-consommation-avocat.fr',
} as const;

/**
 * Prise de rendez-vous en ligne : page de réservation personnelle Microsoft Bookings (« Bookings with me »)
 * du compte cabinet@. Les créneaux (lun–ven 10 h, puis l’après-midi à partir de 14 h 30, pas de 30 min) sont
 * masqués dès qu’un rendez-vous ou une audience figure dans le calendrier Outlook. Liens publics, sans secret.
 * Les trois liens « type » ouvrent directement le format choisi ; `page` les présente tous.
 */
const bookingsBase = 'https://bookings.cloud.microsoft/bookwithme/user/1b837552a8724b3389750385d7d2b910@arnaudcousin-avocat.fr';
export const bookings = {
  page: `${bookingsBase}?anonymous&ep=plink`,
  cabinet: `${bookingsBase}/meetingtype/WVhQj8HnQkWwka4vXXl_ZA2?anonymous&ep=mLinkFromTile`,
  visio: `${bookingsBase}/meetingtype/7r6ub-rr_kOYFlJqr2YP2Q2?anonymous&ep=mLinkFromTile`,
  telephone: `${bookingsBase}/meetingtype/asah2_KYrEan3uNgg1MXLw2?anonymous&ep=mLinkFromTile`,
  /** Prix affiché pour les trois formats (110 € HT). */
  prix: '132 € TTC · 1 h au plus',
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
    { href: '/domaines/successions', titre: 'Successions, partages & indivision', accroche: 'Sortie contentieuse d’indivision, conflits d’associés de SCI familiale : en sortir sans y laisser votre part.' },
    { href: '/domaines/droit-du-travail', titre: 'Droit du travail', accroche: 'Licenciement, rupture : accepter, contester ou négocier ?' },
    { href: '/domaines/procedure-appel', titre: 'Procédure civile, appel & postulation', accroche: 'Un jugement à contester, un délai qui court.' },
    { href: '/reglement-amiable', titre: 'Règlement amiable & médiation', accroche: 'Médiation, conciliation, négociation : y aller accompagné, en sortir avec un bon accord.' },
  ],
  services: [
    { href: '/services/rendez-vous', titre: 'Prendre rendez-vous', accroche: 'Cabinet, visio ou téléphone, créneau choisi en ligne.' },
    { href: '/services/consultation-ecrite', titre: 'Consultation écrite en ligne', accroche: 'Une réponse d’avocat par écrit, sans vous déplacer. Dès 80 €.' },
    { href: '/services/devis-divorce', titre: 'Devis divorce amiable', accroche: 'Votre estimation en sept questions, confirmée par l’avocat.' },
    { href: '/services/depot-pieces', titre: 'Déposer des pièces', accroche: 'Vos documents, en sécurité, directement dans votre dossier.' },
    { href: '/services/pre-entretien', titre: 'Assistant de pré-entretien', accroche: 'Une dizaine de minutes pour préparer votre rendez-vous.' },
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
  bus: { arrets: ['Cité Judiciaire', 'Colombier'], lignes: ['C5', 'C6', '9', '57'] },
  train: 'Gare de Rennes — 10 min à pied, ou métro Gares → Colombier (1 station)',
  parkings: [
    { nom: 'Colombier', operateur: 'C-Park / Citédia', detail: 'sous la dalle du Colombier — 100 m · 1 150 places sur deux niveaux', note: 'entrée sud rue du Capitaine Maignan' },
    { nom: 'Halles centrales', operateur: 'Citédia', detail: '330 m · 40 places', note: '' },
    { nom: 'Charles-de-Gaulle', operateur: 'Citédia', detail: 'esplanade — 450 m · 800 places', note: '' },
    { nom: 'Gare Sud', operateur: 'Citédia', detail: '750 m · 1 000 places', note: '' },
  ],
  voirie: 'Voirie payante rue du Puits Mauger et rues voisines · dépose-minute devant l’immeuble · bornes de recharge Rennes Métropole, esplanade Charles-de-Gaulle (400 m).',
  velo: { stations: ['Colombier', 'Cité Judiciaire', 'La Rotonde', 'Charles-de-Gaulle'], note: 'arceaux devant le centre Colombia' },
  accessibilite: 'Cabinet accessible aux personnes à mobilité réduite : accès par ascenseur. Signalez tout besoin particulier lors de la prise de rendez-vous.',
} as const;
