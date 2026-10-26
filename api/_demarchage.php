<?php
declare(strict_types=1);
/* ——— Démarchage commercial présumé (oct. 2026) ———
 * Une personne qui remplit le formulaire à la main passe les protections anti-robots (pot de miel, jeton, débit).
 * Les messages qui ressemblent à de la prospection — agences web, référencement, « audit » du site, logiciels —
 * arrivent au cabinet préfixés « [DÉMARCHAGE ?] », sans accusé de réception à l'expéditeur.
 * Rien n'est supprimé ni refusé : un vrai client mal classé reste lisible dans la boîte du cabinet.
 * Seuil volontairement prudent : « votre site » ou « je me permets » seuls ne suffisent pas (un client les écrit aussi). */

const SEUIL_DEMARCHAGE = 3;

/** Motif => points. Les motifs à 2 points relèvent du vocabulaire de la prospection ; à 1 point, de tournures fréquentes. */
const MOTIFS_DEMARCHAGE = [
    '~référencement|\bseo\b|netlinking|backlinks?|e-réputation|google ads|community manager|rédaction web|refonte (de |du )?(votre )?site~iu' => 2,
    '~\bagence\s+(web|digitale|de communication|marketing|dédiée|spécialisée)~iu' => 2,
    '~pagespeed|test mobile de google|core web vitals|temps de chargement|met \d+([,.]\d+)? ?s(econdes)? à s.afficher~iu' => 2,
    '~visibilité (en ligne|sur google|sur internet)|premi(è|e)re page (de |sur )?google|positionnement (sur|dans) google~iu' => 2,
    '~(génération|apport) de (leads|prospects|clients)|nouveaux clients (chaque|par) (mois|semaine)|fichier de prospects~iu' => 2,
    '~\bdémo\b|essai gratuit|proposition commerciale|offre de lancement~iu' => 2,
    '~(votre|ton) site( internet| web)?\b~iu' => 1,
    '~je me permets|(15|quinze|10|dix|20|vingt) minutes|échange rapide|état des lieux|\baudit\b|sans engagement|tarif préférentiel|partenariat~iu' => 1,
    '~(dédiée?|spécialisée?) (aux|pour les) (avocats|cabinets)|accompagn\w* (les )?(avocats|cabinets)~iu' => 1,
];

/** Score de prospection d'un ensemble de textes (nom, courriel, message). */
function score_demarchage(string ...$textes): int {
    $t = implode("\n", $textes);
    $s = 0;
    foreach (MOTIFS_DEMARCHAGE as $motif => $points) {
        if (preg_match($motif, $t)) { $s += $points; }
    }
    return $s;
}

/** Vrai si les textes atteignent le seuil (aucun objet « démarchage » n'est proposé dans le formulaire : ce serait l'inviter). */
function demarchage_presume(string ...$textes): bool {
    return score_demarchage(...$textes) >= SEUIL_DEMARCHAGE;
}

/** Bandeau placé en tête du courriel au cabinet. */
const AVERTISSEMENT_DEMARCHAGE = "⚠ Démarchage commercial présumé (vocabulaire de prospection) : aucun accusé de réception n'a été adressé. S'il s'agit d'un vrai client, répondez-lui directement.\n\n";
