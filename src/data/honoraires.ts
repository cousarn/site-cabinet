/** Grille indicative — barème 2023 du cabinet, montants TTC. [Mise à jour 2026 à valider] */
export const bareme = {
  millesime: 2023,
  tauxHoraire: 192,
  lignes: [
    { famille: 'Consultations', items: [
      { label: 'Consultation d’orientation — cabinet, visioconférence ou téléphone (1 h au plus)', prix: '132 €' },
      { label: 'Consultation au cabinet avec avis écrit', prix: '350 €' },
      { label: 'Consultation écrite par internet', prix: 'dès 80 €' },
    ]},
    { famille: 'Famille', items: [
      { label: 'Divorce par consentement mutuel', prix: 'dès 960 €' },
      { label: 'Autres divorces', prix: 'dès 1 800 €' },
      { label: 'Juge aux affaires familiales — pension alimentaire', prix: '750 €' },
      { label: 'Autres procédures devant le juge aux affaires familiales', prix: 'dès 960 €' },
      { label: 'Adoption simple', prix: '900 €' },
      { label: 'Changement de régime matrimonial', prix: '1 200 €' },
      { label: 'Tutelle, curatelle', prix: '750 € / dès 1 200 €' },
    ]},
    { famille: 'Successions', items: [
      { label: 'Envoi en possession', prix: '480 €' },
      { label: 'Sortie contentieuse d’indivision (partage judiciaire, licitation)', prix: 'au temps passé' },
      { label: 'Conflit d’associés de SCI familiale', prix: 'au temps passé' },
    ]},
    { famille: 'Travail', items: [
      { label: 'Conseil de prud’hommes', prix: '1 800 – 3 000 € + 10 % HT du résultat' },
      { label: 'Négociation d’une rupture', prix: '750 € + honoraire de résultat' },
    ]},
    { famille: 'Procédure & postulation', items: [
      { label: 'Postulation devant le tribunal judiciaire', prix: '540 €' },
      { label: 'Postulation devant la cour d’appel', prix: '700 €' },
      { label: 'Vacation de substitution', prix: '192 €' },
    ]},
  ],
  resultat: '5 % HT des sommes obtenues ou économisées, sauf convention contraire',
} as const;

/**
 * Aide juridictionnelle — plafonds de ressources (personne seule), revalorisés chaque janvier.
 * Source : circulaire du ministère de la Justice NOR JUST2601659C du 16 janvier 2026 (justice.gouv.fr).
 * À mettre à jour chaque année en janvier.
 */
export const aj = {
  annee: 2026,
  tranches: [
    ['Jusqu’à 12 957 €', 'Aide totale (100 %)'],
    ['De 12 958 € à 15 316 €', 'Aide partielle : 55 %'],
    ['De 15 317 € à 19 433 €', 'Aide partielle : 25 %'],
    ['Au-delà de 19 433 €', 'Pas d’aide juridictionnelle'],
  ],
  majoration1: '2 332 €',
  majoration2: '1 473 €',
  mobilier: '12 957 €',
  immobilier: '38 866 €',
  source: 'circulaire du ministère de la Justice du 16 janvier 2026 (NOR JUST2601659C)',
} as const;
