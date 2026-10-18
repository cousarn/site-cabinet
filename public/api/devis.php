<?php
/** Estimation d'honoraires — divorce par consentement mutuel. Calcul côté serveur, grille dans $CONFIG['devis']. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
if (!honeypot_ok()) { merci('devis'); }
limiter_debit('devis');

$mariage    = champ('mariage', 7);
$regime     = champ('regime', 60);
$enfants    = champ('enfants', 30);
$immobilier = champ('immobilier', 60);
$entreprise = champ('entreprise', 40);
$accord     = champ('accord', 60);
$email      = champ('email', 200);
if (!email_valide($email)) { erreur('Un courriel valide est nécessaire pour recevoir l’estimation.'); }

$g = $CONFIG['devis'];
$lignes = [['Forfait de base — divorce par consentement mutuel, situation simple', $g['base']]];
if ($enfants === 'Un') { $lignes[] = ['Un enfant mineur : convention parentale', $g['enfant']]; }
elseif (str_starts_with($enfants, 'Deux')) { $lignes[] = ['Plusieurs enfants : convention parentale', (int)round($g['enfant'] * 1.5)]; }
if (str_starts_with($immobilier, 'Un bien')) { $lignes[] = ['Un bien immobilier : coordination avec le notaire liquidateur', $g['immobilier_un']]; }
elseif (str_starts_with($immobilier, 'Plusieurs')) { $lignes[] = ['Plusieurs biens immobiliers : liquidation complexe', $g['immobilier_plus']]; }
if (str_starts_with($entreprise, 'Oui, l')) { $lignes[] = ['Entreprise ou parts sociales d’un époux : évaluation et récompenses', $g['entreprise_un']]; }
elseif (str_starts_with($entreprise, 'Oui, les')) { $lignes[] = ['Entreprise ou parts sociales des deux époux', $g['entreprise_deux']]; }
$dcmPossible = true;
if (str_starts_with($accord, 'Il reste')) { $lignes[] = ['Un ou deux points à négocier avec l’avocat de votre conjoint', $g['desaccord_partiel']]; }
elseif (str_starts_with($accord, 'Nous sommes loin')) { $dcmPossible = false; }

$total = array_sum(array_map(fn($l) => $l[1], $lignes));
$ref = reference();
$fmt = fn(int $n) => number_format($n, 0, ',', ' ') . ' € TTC';

$detail = implode("\n", array_map(fn($l) => sprintf('  %-70s %10s', $l[0], $fmt($l[1])), $lignes));
$situation = "Mariage : $mariage · Régime : $regime · Enfants : $enfants · Immobilier : $immobilier · Entreprise : $entreprise · Accord : $accord";

if ($dcmPossible) {
    $corpsClient = "Bonjour,\n\nVoici l'estimation demandée sur le site du cabinet (référence $ref).\n\n$detail\n\n  Estimation des honoraires du cabinet : " . $fmt($total) . "\n\n"
        . "Ce montant est un ordre de grandeur, ni une promesse ni un plafond : les honoraires réels peuvent être inférieurs (dénouement rapide) ou supérieurs (conflit imprévu). "
        . "Il ne comprend pas les honoraires de l'avocat de votre conjoint (obligatoire), les frais du notaire (dépôt de la convention ≈ 50 € HT ; liquidation si immobilier) ni les éventuels actes d'huissier.\n\n"
        . "Maître Cousin confirme ou ajuste cette estimation par écrit sous 48 heures ouvrées, puis vous propose un rendez-vous de cadrage (cabinet, visio ou téléphone).\n\n";
} else {
    $corpsClient = "Bonjour,\n\nMerci pour vos réponses (référence $ref). Vous indiquez être loin d'un accord : le divorce par consentement mutuel suppose un accord complet sur tout — enfants, pension, prestation compensatoire, partage des biens. "
        . "Il est donc trop tôt pour un forfait amiable. Deux pistes : une négociation préalable conduite par le cabinet (honoraire de transaction si elle aboutit), ou un divorce judiciaire (dès 1 800 € TTC pour une situation simple).\n\n"
        . "Maître Cousin vous écrit sous 48 heures ouvrées pour vous proposer la démarche la plus adaptée, après un premier rendez-vous.\n\n";
}
$corpsClient .= "Votre situation telle que déclarée : $situation\n\nCabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "/services/devis-divorce\n";
envoyer_mail($email, "Votre estimation de divorce par consentement mutuel ($ref)", $corpsClient);

$corpsCabinet = "Demande d'estimation DCM — réf. $ref\n\nCourriel : $email\n$situation\n\n" . ($dcmPossible ? "$detail\n\n  Total estimé : " . $fmt($total) : "DCM non envisageable (pas d'accord) — proposer négociation ou divorce judiciaire.") . "\n\nÀ confirmer ou ajuster sous 48 h ouvrées.\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Devis DCM — " . ($dcmPossible ? $fmt($total) : 'pas d’accord') . " ($ref)", $corpsCabinet, $email);

journal('devis', $envoye ? 'ok' : 'echec-mail');
merci('devis', ['ref' => $ref] + ($dcmPossible ? ['estimation' => $total] : ['dcm' => 'non']));
