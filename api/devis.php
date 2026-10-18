<?php
/** Estimation d'honoraires — divorce par consentement mutuel. Grille HT du cabinet dans $CONFIG['devis'], TVA dans $CONFIG['tva']. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
if (!honeypot_ok()) { merci('devis'); }
limiter_debit('devis');

$mariage       = champ('mariage', 7);
$regime        = champ('regime', 60);
$enfants       = champ('enfants', 40);
$compensatoire = champ('compensatoire', 40);
$partage       = champ('partage', 80);
$accord        = champ('accord', 80);
$lieu          = champ('lieu', 60);
$email         = champ('email', 200);
if (!email_valide($email)) { erreur('Un courriel valide est nécessaire pour recevoir l’estimation.'); }

$g = $CONFIG['devis']; $tva = (float)$CONFIG['tva'];
$lignes = [['Forfait de base — divorce par consentement mutuel', (int)$g['base']]];
$notes  = [];
if (str_starts_with($enfants, 'Oui'))        { $lignes[] = ['Convention parentale (enfants mineurs ou à charge)', (int)$g['parentale']]; }
if (str_starts_with($compensatoire, 'Oui'))  { $lignes[] = ['Prestation compensatoire', (int)$g['compensatoire']]; }
elseif (str_starts_with($compensatoire, 'Je ne sais'))  { $notes[] = 'Si une prestation compensatoire est finalement prévue : + ' . $g['compensatoire'] . ' € HT.'; }
if (str_starts_with($partage, 'Oui'))        { $lignes[] = ['Partage du régime matrimonial ou convention d’indivision', (int)$g['partage']]; }
$dcmIncertain = false;
if (str_starts_with($accord, 'Il reste'))    { $lignes[] = ['Négociation — accord non encore obtenu à l’ouverture du dossier', (int)$g['negociation']]; }
elseif (str_starts_with($accord, 'Nous sommes loin')) { $lignes[] = ['Négociation — accord non encore obtenu à l’ouverture du dossier', (int)$g['negociation']]; $dcmIncertain = true; }
if (str_starts_with($lieu, 'Hors'))          { $notes[] = 'Signature hors Rennes : frais de déplacement en sus, au réel, précisés dans la convention d’honoraires.'; }

$ht  = array_sum(array_map(fn($l) => $l[1], $lignes));
$ttc = (int)round($ht * (1 + $tva));
$ref = reference();
$eur = fn(int $n) => number_format($n, 0, ',', ' ') . ' €';

$detail = implode("\n", array_map(fn($l) => sprintf('  %-66s %9s HT', $l[0], $eur($l[1])), $lignes));
$situation = "Mariage : $mariage · Régime : $regime · Enfants : $enfants · Prestation compensatoire : $compensatoire · Partage : $partage · Accord : $accord · Signature : $lieu";

$corpsClient = "Bonjour,\n\nVoici l'estimation demandée sur le site du cabinet (référence $ref).\n\n$detail\n\n"
    . "  Total HT : " . $eur($ht) . "   TVA 20 % : " . $eur($ttc - $ht) . "   Total TTC : " . $eur($ttc) . "\n\n"
    . ($notes ? implode("\n", array_map(fn($n) => "• $n", $notes)) . "\n\n" : '')
    . ($dcmIncertain ? "Vous indiquez être loin d'un accord. Le divorce par consentement mutuel suppose un accord complet sur tout — enfants, pension, prestation compensatoire, partage. Le cabinet peut conduire cette négociation (ligne ci-dessus) ; si elle n'aboutit pas, un divorce judiciaire reste possible (dès 1 800 € TTC pour une situation simple).\n\n" : '')
    . "Ce montant est un ordre de grandeur, ni une promesse ni un plafond. Il ne comprend pas les honoraires de l'avocat de votre conjoint (un avocat par époux est obligatoire), les émoluments du notaire (dépôt de la convention ; acte de partage si immobilier) ni les éventuels actes d'huissier.\n\n"
    . "Maître Cousin confirme ou ajuste cette estimation par écrit sous 48 heures ouvrées, puis vous propose un rendez-vous de cadrage (cabinet, visio ou téléphone).\n\n"
    . "Votre situation telle que déclarée : $situation\n\nCabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "/services/devis-divorce\n";
envoyer_mail($email, "Votre estimation de divorce par consentement mutuel ($ref)", $corpsClient);

$corpsCabinet = "Demande d'estimation DCM — réf. $ref\n\nCourriel : $email\n$situation\n\n$detail\n\n  Total HT : " . $eur($ht) . " · TTC : " . $eur($ttc) . ($dcmIncertain ? "\n  ⚠ Loin d'un accord : DCM incertain." : '') . "\n" . ($notes ? "\n" . implode("\n", $notes) . "\n" : '') . "\nÀ confirmer ou ajuster sous 48 h ouvrées.\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Devis DCM — " . $eur($ttc) . " TTC ($ref)", $corpsCabinet, $email);

journal('devis', $envoye ? 'ok' : 'echec-mail');
merci('devis', ['ref' => $ref, 'estimation' => $ttc, 'ht' => $ht] + ($dcmIncertain ? ['dcm' => 'incertain'] : []));
