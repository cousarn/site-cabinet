/**
 * Bandeau d’annonce de la page d’accueil (réforme, texte nouveau, information importante).
 * Une seule annonce est affichée : la première dont la fenêtre `du`/`au` contient la date du build.
 * Pour retirer le bandeau : vider le tableau ou passer la date `au`. Le site est régénéré à chaque déploiement.
 */
export interface Annonce {
  /** Étiquette courte, en capitales (ex. « Réforme »). */
  etiquette: string;
  /** Phrase principale, en gras. */
  titre: string;
  /** Précision en une ligne. */
  texte: string;
  /** Page cible, relative au site. */
  lien: string;
  /** Libellé du lien. */
  libelle: string;
  /** Affichage du (incluse) … au (incluse), format AAAA-MM-JJ. */
  du: string;
  au: string;
}

export const annonces: Annonce[] = [
  {
    etiquette: 'Réforme',
    titre: 'Partage judiciaire : la procédure change au 1er janvier 2027.',
    texte: 'Avocat obligatoire à toutes les étapes, y compris devant le notaire ; délais réduits pour agir — quatre mois pour les contestations, deux mois pour les dires, quinze jours pour l’appel.',
    lien: '/fiches/reforme-partage-judiciaire-2027',
    libelle: 'En savoir plus',
    du: '2026-10-24',
    au: '2027-06-30',
  },
];

/** L’annonce à afficher aujourd’hui, ou `undefined`. */
export function annonceDuJour(aujourdhui = new Date()): Annonce | undefined {
  const j = aujourdhui.toISOString().slice(0, 10);
  return annonces.find((a) => a.du <= j && j <= a.au);
}
