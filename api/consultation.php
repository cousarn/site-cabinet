<?php
/** Consultation écrite en ligne — enregistrement de la demande et des pièces, puis instructions de règlement. */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
if (!honeypot_ok()) { merci('consultation'); }
limiter_debit('consultation');

$formules = ['simple' => ['Question simple', 80], 'consultation' => ['Consultation', 120], 'approfondie' => ['Consultation approfondie', 349]];
$formule  = champ('formule', 20);
if (!isset($formules[$formule])) { $formule = 'consultation'; }
[$libelle, $prix] = $formules[$formule];

$domaine  = champ('domaine', 60);
$question = texte('question', 12000);
$nom      = champ('nom', 120);
$email    = champ('email', 200);
$cgv      = isset($_POST['cgv']);

if ($nom === '' || !email_valide($email)) { erreur('Nom et courriel valide sont nécessaires.'); }
if (mb_strlen($question) < 40) { erreur('Exposez votre situation et votre question en quelques lignes au moins : l’avocat ne peut pas répondre à une question trop courte.'); }
if (!$cgv) { erreur('Merci d’accepter les conditions de la consultation en ligne.'); }

$ref = reference();
$pieces = enregistrer_pieces('pieces', 'consultation-' . $ref);
$fichier = rtrim($CONFIG['stockage'], '/') . '/consultation-' . $ref . '/demande.txt';
@mkdir(dirname($fichier), 0700, true);
@file_put_contents($fichier, "Réf. $ref — " . date('c') . "\nFormule : $libelle ($prix €)\nDomaine : $domaine\nNom : $nom\nCourriel : $email\n\nQuestion :\n$question\n\nPièces : " . (implode(', ', $pieces) ?: 'aucune') . "\n");

$virement = "Par virement de $prix € TTC, référence « $ref » :\n$CONFIG[rib]\nou par chèque à l'ordre de Maître Arnaud Cousin.";
$payer = null;
if ($CONFIG['paiement'] === 'paypal' && !empty($CONFIG['paypal_id'])) {
    $payer = lien_paypal($libelle, $prix, $ref, $formule);
    $instructions = "RÈGLEMENT DE $prix € TTC\n\n1. En ligne, par PayPal ou par carte bancaire (sans compte PayPal), en quelques secondes :\n$payer\n\n2. $virement\n\nLa consultation est lancée dès réception du règlement (délai de 5 jours ouvrés à compter de celle-ci).";
} elseif ($CONFIG['paiement'] === 'stripe') {
    $instructions = "Le lien de règlement par carte vous est adressé dans un courriel séparé."; // phase 2 : Stripe Checkout
} else {
    $instructions = "Règlement de $prix € TTC. $virement\nLa consultation est lancée dès réception du règlement (délai de 5 jours ouvrés à compter de celle-ci).";
}

$corpsClient = "Bonjour,\n\nVotre demande de consultation écrite est bien enregistrée (référence $ref).\n\nFormule : $libelle — $prix € TTC\nDomaine : $domaine\nPièces reçues : " . (implode(', ', $pieces) ?: 'aucune') . "\n\n"
    . "$instructions\n\n"
    . "L'avocat demeure libre de refuser la consultation (conflit d'intérêts, matière hors compétence) : vous en seriez informé(e) sous 48 heures et intégralement remboursé(e). "
    . "En demandant l'exécution immédiate, vous avez renoncé à votre droit de rétractation une fois la consultation délivrée.\n\n"
    . "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";
envoyer_mail($email, "Votre demande de consultation écrite ($ref)", $corpsClient);

$corpsCabinet = "Consultation écrite — réf. $ref\n\nFormule : $libelle ($prix €)\nDomaine : $domaine\nNom : $nom\nCourriel : $email\nPièces : " . (implode(', ', $pieces) ?: 'aucune') . "\nDossier serveur : " . dirname($fichier) . "\n\nQuestion :\n$question\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Consultation écrite — $libelle — $nom ($ref)", $corpsCabinet, $email);

journal('consultation', $envoye ? 'ok' : 'echec-mail');
merci('consultation', ['ref' => $ref, 'prix' => $prix] + ($payer ? ['payer' => $payer] : []));
