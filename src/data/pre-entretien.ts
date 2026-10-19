/**
 * Assistant de pré-entretien — l'arbre des questions.
 *
 * Ce fichier se modifie sans toucher au code de la page (`src/pages/services/pre-entretien.astro`) :
 * ajouter une option, reformuler une question, compléter la liste des pièces d'un domaine se fait ici.
 *
 * Parcours : question du domaine → questions propres au domaine (`domaines[].questions`) → questions communes
 * (`questionsCommunes`, qui puisent leurs listes dans le domaine choisi : événements datés, démarches) →
 * pièces à réunir (`piecesCommunes` + `domaines[].pieces`) → format de rendez-vous → coordonnées → consentement →
 * récapitulatif. Identifiants : lettres minuscules, chiffres, tirets ; ils servent de clés dans la synthèse envoyée au cabinet.
 *
 * Règle intangible : l'assistant ne donne aucun conseil juridique. Pas de qualification, pas de pronostic, pas de
 * délai chiffré, pas de « vous avez droit à ». Il recueille, structure, signale les dates et liste les pièces.
 */

export type Option = { id: string; label: string; aide?: string };

/** Où la réponse est rangée dans la synthèse envoyée au cabinet (défaut : « situation »). */
export type Synthese = 'situation' | 'recit' | 'parties' | 'chrono' | 'demarches' | 'objectif' | 'urgence';

/** Condition d'affichage : la question `question` a reçu l'une des `valeurs` (identifiants d'options). */
export type Condition = { question: string; valeurs: string[] };

type QuestionBase = { id: string; texte: string; aide?: string; si?: Condition; synthese?: Synthese };
/** Une seule réponse parmi des boutons. */
export type QuestionChoix = QuestionBase & { type: 'choix'; options: Option[] };
/** Plusieurs réponses possibles ; au moins une sauf `facultatif`. */
export type QuestionMulti = QuestionBase & { type: 'multi'; options: Option[]; facultatif?: boolean };
/** Texte libre ; `court` = une ligne. */
export type QuestionTexte = QuestionBase & { type: 'texte'; court?: boolean; placeholder?: string; facultatif?: boolean; max?: number };
/** Documents reçus avec leur date (liste `evenements` du domaine) — déclencheurs de délai. */
export type QuestionDates = QuestionBase & { type: 'dates' };
/** Ce qui a déjà été fait (liste `demarches` du domaine). */
export type QuestionDemarches = QuestionBase & { type: 'demarches' };
export type Question = QuestionChoix | QuestionMulti | QuestionTexte | QuestionDates | QuestionDemarches;

/** Événement daté. `dateLabel` : intitulé du champ date (défaut « Date de réception »). */
export type Evenement = { id: string; label: string; dateLabel?: string };
export type Piece = { id: string; label: string };

export type Domaine = {
  id: string;
  label: string;
  /** Première bulle de l'assistant après le choix du domaine. */
  accueil: string;
  questions: Question[];
  evenements: Evenement[];
  demarches: Option[];
  pieces: Piece[];
};

/* ───────────────────────── Listes partagées ───────────────────────── */

/** Démarches déjà faites — formulations reprises par plusieurs domaines. */
const dAvocat: Option = { id: 'avocat', label: 'J’ai déjà consulté un avocat', aide: 'Précédent ou toujours en charge' };
const dCourrier: Option = { id: 'courrier', label: 'Un courrier a été adressé à l’autre partie' };
const dMediation: Option = { id: 'mediation', label: 'Une médiation ou une conciliation a été tentée ou proposée' };
const dProcedure: Option = { id: 'procedure', label: 'Une procédure est en cours' };
const dRien: Option = { id: 'rien', label: 'Rien pour l’instant' };

/** Pièces utiles dans tous les cas (fiche « Les pièces à réunir avant un premier rendez-vous »). */
export const piecesCommunes: Piece[] = [
  { id: 'identite', label: 'Pièce d’identité ; livret de famille ou convention de pacs si vous êtes marié(e) ou pacsé(e)' },
  { id: 'courriers', label: 'Courriers, courriels et SMS importants déjà échangés avec l’autre partie, son avocat, le notaire ou l’employeur' },
  { id: 'decision', label: 'Toute décision de justice déjà rendue, avec l’acte de signification s’il y en a eu un' },
  { id: 'assurances', label: 'Contrats d’assurance (habitation, automobile, carte bancaire) : une protection juridique s’y trouve parfois' },
  { id: 'impots', label: 'Dernier avis d’imposition' },
];

/* ───────────────────────── Les domaines ───────────────────────── */

export const domaines: Domaine[] = [
  {
    id: 'famille',
    label: 'Famille et séparation',
    accueil: 'Famille et séparation. Quelques questions fermées d’abord, puis votre récit en quelques phrases.',
    questions: [
      { id: 'lien', type: 'choix', texte: 'Quel est votre lien avec l’autre personne concernée ?', options: [
        { id: 'maries', label: 'Nous sommes mariés' },
        { id: 'pacses', label: 'Nous sommes pacsés' },
        { id: 'concubins', label: 'Nous vivons ou avons vécu en union libre' },
        { id: 'separes', label: 'Nous sommes déjà divorcés ou séparés', aide: 'Pension, résidence des enfants, partage à revoir' },
        { id: 'autre', label: 'Autre lien', aide: 'Grands-parents, beau-parent, tiers…' },
      ] },
      { id: 'objet', type: 'multi', texte: 'Qu’est-ce qui est en jeu ?', aide: 'Plusieurs réponses possibles.', options: [
        { id: 'separation', label: 'La séparation ou le divorce lui-même' },
        { id: 'enfants', label: 'La résidence des enfants, le droit de visite et d’hébergement' },
        { id: 'pension', label: 'Une pension alimentaire ou une contribution à l’entretien des enfants' },
        { id: 'compensatoire', label: 'Une prestation compensatoire' },
        { id: 'patrimoine', label: 'Le logement, les biens, les dettes' },
        { id: 'protection', label: 'Des violences ou une mesure de protection' },
        { id: 'autre', label: 'Autre chose' },
      ] },
      { id: 'dialogue', type: 'choix', texte: 'Où en êtes-vous avec l’autre partie ?', options: [
        { id: 'accord', label: 'Nous sommes d’accord sur l’essentiel' },
        { id: 'partiel', label: 'Nous sommes d’accord sur certains points seulement' },
        { id: 'desaccord', label: 'Nous sommes en désaccord' },
        { id: 'rupture', label: 'Il n’y a plus de dialogue' },
      ] },
    ],
    evenements: [
      { id: 'convocation', label: 'Une convocation à une audience', dateLabel: 'Date de l’audience' },
      { id: 'jugement', label: 'Un jugement ou une ordonnance' },
      { id: 'assignation', label: 'Une assignation, une requête ou un acte remis par un commissaire de justice (huissier)' },
      { id: 'recommande', label: 'Une lettre recommandée' },
      { id: 'avocat', label: 'Un courrier de l’avocat de l’autre partie' },
      { id: 'autre', label: 'Un autre document officiel' },
    ],
    demarches: [
      dAvocat,
      dCourrier,
      { id: 'mediation', label: 'Une médiation familiale a été tentée ou proposée' },
      { id: 'procedure', label: 'Une procédure est en cours devant le juge aux affaires familiales' },
      dRien,
    ],
    pieces: [
      { id: 'contrat-mariage', label: 'Contrat de mariage ou donation entre époux, s’il en existe' },
      { id: 'revenus', label: 'Trois derniers bulletins de salaire de chacun, dernier avis d’imposition commun' },
      { id: 'patrimoine', label: 'Titres de propriété, tableaux d’amortissement des prêts, relevés des comptes et placements' },
      { id: 'enfants', label: 'Pour les enfants : certificats de scolarité, justificatifs des frais, calendrier de résidence actuel' },
    ],
  },

  {
    id: 'succession',
    label: 'Succession et indivision',
    accueil: 'Succession, partage, indivision ou société civile familiale. Situons d’abord votre place et le stade du règlement.',
    questions: [
      { id: 'qualite', type: 'choix', texte: 'Quelle est votre place dans cette affaire ?', options: [
        { id: 'heritier', label: 'Héritier (enfant, conjoint, frère ou sœur…)' },
        { id: 'legataire', label: 'Légataire désigné par un testament' },
        { id: 'indivisaire', label: 'Indivisaire hors succession', aide: 'Achat en commun, ex-concubin, ex-époux' },
        { id: 'sci', label: 'Associé ou gérant d’une SCI familiale' },
        { id: 'autre', label: 'Autre', aide: 'Créancier, conjoint d’un héritier, exécuteur testamentaire…' },
      ] },
      { id: 'sci-role', type: 'choix', texte: 'Dans cette SCI, vous êtes :', si: { question: 'qualite', valeurs: ['sci'] }, options: [
        { id: 'gerant', label: 'Gérant' },
        { id: 'majoritaire', label: 'Associé majoritaire' },
        { id: 'minoritaire', label: 'Associé minoritaire' },
        { id: 'indivis', label: 'Titulaire de parts en indivision' },
      ] },
      { id: 'stade', type: 'choix', texte: 'Où en est le règlement ?', options: [
        { id: 'recent', label: 'Décès récent, rien n’est encore engagé' },
        { id: 'notaire', label: 'Un notaire est saisi, le règlement avance' },
        { id: 'bloque', label: 'Le règlement est bloqué depuis des mois ou des années' },
        { id: 'justice', label: 'Un partage ou une vente est déjà demandé en justice' },
        { id: 'sans-deces', label: 'Il n’y a pas de décès : indivision ou SCI' },
      ] },
      { id: 'blocage', type: 'multi', texte: 'Qu’est-ce qui pose problème ?', aide: 'Plusieurs réponses possibles.', options: [
        { id: 'valeur', label: 'Un désaccord sur le partage ou la valeur des biens' },
        { id: 'vente', label: 'Un bien à vendre ou à conserver, sans accord' },
        { id: 'occupation', label: 'Un bien occupé par l’un des indivisaires' },
        { id: 'detournement', label: 'Des mouvements de fonds, dons ou retraits inexpliqués' },
        { id: 'testament', label: 'Un testament, une donation ou une assurance-vie discutés' },
        { id: 'gestion', label: 'Les comptes, la gestion ou le gérant de la SCI' },
        { id: 'autre', label: 'Autre chose' },
      ] },
    ],
    evenements: [
      { id: 'notaire', label: 'Un courrier du notaire fixant une date ou un délai de réponse', dateLabel: 'Date indiquée par le notaire' },
      { id: 'assignation', label: 'Une assignation ou un acte remis par un commissaire de justice (huissier)' },
      { id: 'jugement', label: 'Un jugement' },
      { id: 'convocation', label: 'Une convocation à une audience ou à une réunion', dateLabel: 'Date de l’audience ou de la réunion' },
      { id: 'sommation', label: 'Une sommation ou une mise en demeure' },
      { id: 'recommande', label: 'Une lettre recommandée' },
      { id: 'autre', label: 'Un autre document officiel' },
    ],
    demarches: [
      { id: 'notaire', label: 'Un notaire est saisi' },
      dAvocat,
      { id: 'courrier', label: 'Un courrier a été adressé aux cohéritiers ou aux autres indivisaires' },
      dMediation,
      dProcedure,
      dRien,
    ],
    pieces: [
      { id: 'deces', label: 'Acte de décès ; testament, donations et contrats d’assurance-vie connus' },
      { id: 'notaire', label: 'Attestation notariée, projet de déclaration de succession ou de partage si le notaire en a dressé' },
      { id: 'titres', label: 'Titres de propriété des biens indivis' },
      { id: 'releves', label: 'Relevés des comptes du défunt sur les cinq dernières années, si des mouvements vous paraissent anormaux' },
      { id: 'ecrits', label: 'Tout écrit du notaire ou des cohéritiers sur le blocage' },
      { id: 'sci', label: 'Pour une SCI : statuts, derniers procès-verbaux d’assemblée, comptes' },
    ],
  },

  {
    id: 'travail',
    label: 'Droit du travail',
    accueil: 'Droit du travail. Votre situation dans l’entreprise d’abord, puis les faits.',
    questions: [
      { id: 'cote', type: 'choix', texte: 'Vous êtes :', options: [
        { id: 'salarie', label: 'Salarié(e)' },
        { id: 'employeur', label: 'Employeur' },
        { id: 'autre', label: 'Autre', aide: 'Agent public, indépendant, stagiaire…' },
      ] },
      { id: 'situation', type: 'choix', texte: 'Où en est la relation de travail ?', options: [
        { id: 'poste', label: 'Toujours en poste, sans procédure engagée' },
        { id: 'procedure', label: 'Une procédure est engagée', aide: 'Convocation à un entretien préalable, sanction annoncée' },
        { id: 'licencie', label: 'Le contrat est rompu : licenciement' },
        { id: 'conventionnelle', label: 'Une rupture conventionnelle est proposée ou signée' },
        { id: 'depart', label: 'Démission, prise d’acte ou départ envisagé' },
        { id: 'arret', label: 'Arrêt de travail, inaptitude' },
        { id: 'autre', label: 'Autre situation' },
      ] },
      { id: 'motifs', type: 'multi', texte: 'Qu’est-ce qui est en cause ?', aide: 'Plusieurs réponses possibles.', options: [
        { id: 'motif', label: 'Le motif ou la procédure de la rupture' },
        { id: 'salaires', label: 'Des salaires, heures supplémentaires ou primes impayés' },
        { id: 'harcelement', label: 'Un harcèlement ou une discrimination' },
        { id: 'conditions', label: 'Les conditions de travail, la santé au travail' },
        { id: 'clause', label: 'Une clause du contrat', aide: 'Non-concurrence, mobilité, forfait…' },
        { id: 'negociation', label: 'La négociation d’un départ' },
        { id: 'autre', label: 'Autre chose' },
      ] },
    ],
    evenements: [
      { id: 'entretien', label: 'Une convocation à un entretien préalable', dateLabel: 'Date de l’entretien' },
      { id: 'licenciement', label: 'Une lettre de licenciement' },
      { id: 'sanction', label: 'La notification d’une sanction' },
      { id: 'conventionnelle', label: 'Une proposition ou une convention de rupture conventionnelle', dateLabel: 'Date de signature ou de réception' },
      { id: 'solde', label: 'Un solde de tout compte' },
      { id: 'prudhommes', label: 'Une convocation devant le conseil de prud’hommes', dateLabel: 'Date de l’audience' },
      { id: 'jugement', label: 'Un jugement' },
      { id: 'recommande', label: 'Une lettre recommandée de l’employeur ou du salarié' },
      { id: 'autre', label: 'Un autre document officiel' },
    ],
    demarches: [
      dAvocat,
      { id: 'contestation', label: 'Une contestation écrite a été adressée à l’employeur (ou au salarié)' },
      { id: 'representants', label: 'Les représentants du personnel ou un syndicat ont été sollicités' },
      { id: 'inspection', label: 'L’inspection du travail ou le médecin du travail ont été saisis' },
      { id: 'procedure', label: 'Le conseil de prud’hommes est saisi' },
      dRien,
    ],
    pieces: [
      { id: 'contrat', label: 'Contrat de travail et avenants ; nom de la convention collective applicable' },
      { id: 'rupture', label: 'Lettre de licenciement, convocation à l’entretien préalable, proposition de rupture conventionnelle — avec leur date de réception' },
      { id: 'bulletins', label: 'Trois derniers bulletins de salaire, solde de tout compte, attestation France Travail' },
      { id: 'sante', label: 'Arrêts de travail, avis du médecin du travail' },
      { id: 'echanges', label: 'Échanges écrits avec l’employeur (courriels, entretiens d’évaluation, avertissements)' },
    ],
  },

  {
    id: 'procedure',
    label: 'Procédure et appel',
    accueil: 'Procédure civile, appel, postulation. Situons la décision ou l’acte en cause.',
    questions: [
      { id: 'etape', type: 'choix', texte: 'Quelle est votre situation ?', options: [
        { id: 'jugement', label: 'Un jugement vient d’être rendu' },
        { id: 'acte', label: 'J’ai reçu un acte', aide: 'Assignation, convocation, commandement' },
        { id: 'appel', label: 'Un appel est déjà formé, par moi ou par l’autre partie' },
        { id: 'postulation', label: 'Je cherche un avocat postulant à Rennes', aide: 'Confrère d’un autre barreau' },
        { id: 'execution', label: 'Une décision est en cours d’exécution', aide: 'Saisie, commissaire de justice' },
        { id: 'autre', label: 'Autre situation' },
      ] },
      { id: 'juridiction', type: 'choix', texte: 'Quelle juridiction est concernée ?', options: [
        { id: 'tj', label: 'Tribunal judiciaire' },
        { id: 'jaf', label: 'Juge aux affaires familiales' },
        { id: 'cph', label: 'Conseil de prud’hommes' },
        { id: 'commerce', label: 'Tribunal de commerce' },
        { id: 'ca', label: 'Cour d’appel' },
        { id: 'inconnue', label: 'Autre ou je ne sais pas' },
      ] },
      { id: 'avocat', type: 'choix', texte: 'Aviez-vous un avocat jusqu’ici ?', options: [
        { id: 'oui-charge', label: 'Oui, toujours en charge du dossier' },
        { id: 'oui-fini', label: 'Oui, mais sa mission est terminée' },
        { id: 'non', label: 'Non' },
      ] },
    ],
    evenements: [
      { id: 'signification', label: 'La signification du jugement par un commissaire de justice (huissier)' },
      { id: 'notification', label: 'Le jugement reçu par courrier du greffe ou par courrier simple' },
      { id: 'assignation', label: 'Une assignation' },
      { id: 'convocation', label: 'Une convocation à une audience', dateLabel: 'Date de l’audience' },
      { id: 'appel-adverse', label: 'La déclaration d’appel de l’autre partie ou ses conclusions' },
      { id: 'commandement', label: 'Un commandement de payer ou un acte de saisie' },
      { id: 'recommande', label: 'Une lettre recommandée' },
      { id: 'autre', label: 'Un autre acte' },
    ],
    demarches: [
      dAvocat,
      { id: 'appel', label: 'Un appel a déjà été formé' },
      dCourrier,
      { id: 'aj', label: 'Une demande d’aide juridictionnelle est déposée' },
      dRien,
    ],
    pieces: [
      { id: 'jugement', label: 'Le jugement complet et l’acte de signification' },
      { id: 'conclusions', label: 'Les conclusions et pièces échangées en première instance (votre précédent avocat les remet sur demande)' },
      { id: 'adverse', label: 'Les coordonnées de l’avocat de l’autre partie' },
    ],
  },

  {
    id: 'amiable',
    label: 'Règlement amiable',
    accueil: 'Médiation, conciliation, négociation. Voyons la nature du différend et le stade où il en est.',
    questions: [
      { id: 'nature', type: 'choix', texte: 'De quelle nature est le différend ?', options: [
        { id: 'familial', label: 'Familial', aide: 'Séparation, enfants, pension' },
        { id: 'successoral', label: 'Successoral ou patrimonial', aide: 'Succession, indivision, SCI' },
        { id: 'travail', label: 'Professionnel', aide: 'Employeur, salarié' },
        { id: 'autre', label: 'Autre', aide: 'Voisinage, contrat, associés…' },
      ] },
      { id: 'stade', type: 'choix', texte: 'Où en est la démarche amiable ?', options: [
        { id: 'idee', label: 'J’y pense, rien n’est engagé' },
        { id: 'proposee', label: 'L’autre partie propose une médiation ou une conciliation' },
        { id: 'ordonnee', label: 'Un juge a ordonné ou proposé une médiation' },
        { id: 'en-cours', label: 'La médiation est en cours' },
        { id: 'accord', label: 'Un accord existe, à mettre en forme ou à faire exécuter' },
      ] },
      { id: 'autre-partie', type: 'choix', texte: 'L’autre partie est-elle disposée à discuter ?', options: [
        { id: 'oui', label: 'Oui' },
        { id: 'pas-sollicitee', label: 'Elle n’a pas encore été sollicitée' },
        { id: 'refus', label: 'Elle refuse' },
        { id: 'inconnu', label: 'Je ne sais pas' },
      ] },
    ],
    evenements: [
      { id: 'reunion', label: 'Une convocation à une réunion de médiation ou d’information', dateLabel: 'Date de la réunion' },
      { id: 'ordonnance', label: 'Une décision du juge désignant un médiateur ou un conciliateur' },
      { id: 'mediateur', label: 'Un courrier d’un médiateur ou d’un conciliateur' },
      { id: 'convocation', label: 'Une convocation à une audience', dateLabel: 'Date de l’audience' },
      { id: 'recommande', label: 'Une lettre recommandée' },
      { id: 'autre', label: 'Un autre document officiel' },
    ],
    demarches: [
      dAvocat,
      { id: 'mediateur', label: 'Un médiateur ou un conciliateur a été contacté' },
      dCourrier,
      dProcedure,
      dRien,
    ],
    pieces: [
      { id: 'protocole', label: 'Projet d’accord, protocole ou proposition déjà échangés' },
      { id: 'decision', label: 'La décision ordonnant ou proposant la médiation, s’il en existe' },
      { id: 'fond', label: 'Les documents du différend lui-même (contrat, actes, décomptes)' },
    ],
  },

  {
    id: 'autre',
    label: 'Autre situation',
    accueil: 'Votre situation n’entre pas dans les cases précédentes : décrivez-la, le cabinet vous dira s’il peut vous aider ou vous orientera.',
    questions: [
      { id: 'nature', type: 'texte', court: true, texte: 'En quelques mots, de quoi s’agit-il ?', placeholder: 'Un bail, un voisinage, un contrat, une dette…', max: 200 },
      { id: 'adversaire', type: 'choix', texte: 'Avec qui le différend existe-t-il ?', options: [
        { id: 'particulier', label: 'Un particulier' },
        { id: 'professionnel', label: 'Une entreprise ou un professionnel' },
        { id: 'administration', label: 'Une administration ou un organisme public' },
        { id: 'aucun', label: 'Il n’y a pas d’autre partie' },
      ] },
    ],
    evenements: [
      { id: 'convocation', label: 'Une convocation', dateLabel: 'Date indiquée sur la convocation' },
      { id: 'jugement', label: 'Un jugement ou une ordonnance' },
      { id: 'assignation', label: 'Une assignation ou un acte remis par un commissaire de justice (huissier)' },
      { id: 'recommande', label: 'Une lettre recommandée' },
      { id: 'demeure', label: 'Une mise en demeure' },
      { id: 'autre', label: 'Un autre document officiel' },
    ],
    demarches: [dAvocat, dCourrier, dMediation, dProcedure, dRien],
    pieces: [
      { id: 'contrat', label: 'Le contrat, l’acte ou le document à l’origine du différend' },
    ],
  },
];

/* ───────────────────────── Questions communes à tous les domaines ───────────────────────── */

export const questionsCommunes: Question[] = [
  { id: 'recit', type: 'texte', synthese: 'recit', texte: 'Décrivez la situation en deux ou trois phrases.', aide: 'Les faits, dans l’ordre, sans détails superflus : vous développerez au rendez-vous.', placeholder: 'Ce qui s’est passé, depuis quand, où en êtes-vous aujourd’hui.', max: 2000 },
  { id: 'parties', type: 'texte', court: true, synthese: 'parties', texte: 'Qui d’autre est concerné ? Indiquez le rôle de chacun, pas nécessairement les noms.', placeholder: 'Mon ex-conjoint, nos deux enfants (8 et 12 ans), le notaire…', facultatif: true, max: 400 },
  { id: 'chrono', type: 'texte', synthese: 'chrono', texte: 'Quelles sont les dates qui comptent ?', aide: 'Décès, séparation, licenciement, courrier, audience… dans l’ordre, même approximatives.', placeholder: 'Mars 2024 : séparation. Juin 2026 : courrier de son avocat. …', facultatif: true, max: 1500 },
  { id: 'dates', type: 'dates', texte: 'Avez-vous reçu l’un de ces documents ? Cochez et indiquez la date.', aide: 'La date de remise ou de réception, pas celle qui figure en haut du courrier. Certains documents font courir des délais : le cabinet vérifiera.' },
  { id: 'demarches', type: 'demarches', synthese: 'demarches', texte: 'Qu’est-ce qui a déjà été fait ?', aide: 'Plusieurs réponses possibles.' },
  { id: 'objectif', type: 'texte', synthese: 'objectif', texte: 'Qu’attendez-vous du rendez-vous ?', aide: 'Comprendre votre situation, connaître les options et leur coût, être accompagné(e) dans une négociation, confier un dossier…', placeholder: 'Ce que vous voulez obtenir, ce que vous voulez éviter.', max: 1000 },
  { id: 'urgence', type: 'choix', synthese: 'urgence', texte: 'Y a-t-il une urgence ?', options: [
    { id: 'date', label: 'Oui : une date ou une audience approche' },
    { id: 'degradation', label: 'Oui : la situation se dégrade', aide: 'Logement, enfants, salaire, santé' },
    { id: 'mois', label: 'Il faudrait agir dans le mois' },
    { id: 'non', label: 'Pas d’urgence particulière' },
  ] },
];

/* ───────────────────────── Fin du parcours ───────────────────────── */

/** Formats de rendez-vous ; `bookings` renvoie à la clé correspondante de `cabinet.ts › bookings` (sauf « aucun »). */
export const formats: { id: 'cabinet' | 'visio' | 'telephone' | 'aucun'; label: string; aide: string }[] = [
  { id: 'cabinet', label: 'Au cabinet', aide: '15 rue du Puits Mauger, Rennes — métro Colombier' },
  { id: 'visio', label: 'En visioconférence', aide: 'Lien Teams envoyé avec la confirmation' },
  { id: 'telephone', label: 'Par téléphone', aide: 'Le cabinet vous appelle à l’heure convenue' },
  { id: 'aucun', label: 'Je ne sais pas encore', aide: 'Vous prendrez rendez-vous plus tard' },
];

/** Textes de l'assistant hors questions. */
export const textes = {
  intro: 'Bonjour. Ce questionnaire prépare votre premier rendez-vous : une question à la fois, une dizaine de minutes. Il ne donne aucun conseil juridique — c’est l’avocat qui vous répond, au rendez-vous. Il recueille votre situation, signale les dates à mentionner et vous indique les pièces à réunir. Vos réponses sont couvertes par le secret professionnel.',
  domaine: 'De quoi s’agit-il ?',
  pieces: 'Voici les pièces utiles dans votre situation. Cochez celles que vous avez déjà ; les autres figureront dans votre récapitulatif, à réunir pour le rendez-vous. Pas tout, mais daté : mieux vaut cinq pièces classées que cinquante en vrac.',
  format: 'Sous quelle forme souhaitez-vous le rendez-vous ?',
  coordonnees: 'Vos coordonnées, pour vous adresser le récapitulatif et préparer le rendez-vous.',
  consentement: 'Dernière étape avant le récapitulatif.',
  consentementCase: 'J’accepte que ces réponses soient transmises au cabinet pour préparer le rendez-vous. Elles sont couvertes par le secret professionnel et ne servent à rien d’autre.',
  recap: 'Voici votre récapitulatif. Vérifiez-le, puis envoyez-le au cabinet : vous en recevrez une copie par courriel, avec la liste des pièces à réunir.',
  /** Message affiché sous une date ; {n} est remplacé par le nombre de jours. Jamais de délai chiffré. */
  datePassee: 'Date reçue il y a {n} jours : signalez-la dès la prise de rendez-vous, certains délais sont courts.',
  dateHier: 'Date reçue hier : signalez-la dès la prise de rendez-vous, certains délais sont courts.',
  dateJour: 'Date d’aujourd’hui : signalez-la dès la prise de rendez-vous, certains délais sont courts.',
  dateFuture: 'Dans {n} jours : signalez cette date dès la prise de rendez-vous.',
  dateDemain: 'Demain : signalez cette date dès la prise de rendez-vous.',
  dateAncienne: 'Date reçue il y a plus de {n} mois : mentionnez-la tout de même, elle peut compter.',
} as const;
