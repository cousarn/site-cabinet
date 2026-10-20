<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
controle_anti_robot('contact'); // pot de miel + jeton signé (voir _lib.php)
limiter_debit('contact');

$nom   = champ('nom', 120);
$tel   = champ('telephone', 40);
$email = champ('email', 200);
$objet = champ('objet', 60);
$msg   = texte('message');
$ok    = isset($_POST['consentement']);

if ($nom === '' || $msg === '' || !email_valide($email)) { erreur('Nom, courriel valide et message sont nécessaires pour vous répondre.'); }
if (!$ok) { erreur('Merci de cocher la case de consentement : sans elle, le cabinet ne peut pas traiter votre message.'); }
if (mb_strlen($msg) < 20) { erreur('Quelques lignes de plus nous aideront à vous répondre utilement.'); }

$suspect = contenu_suspect($nom, $msg);
$ref = reference();
$corps = ($suspect ? AVERTISSEMENT_SUSPECT : '') . "Nouveau message depuis le site — réf. $ref\n\n"
       . "Objet : $objet\nNom : $nom\nCourriel : $email\nTéléphone : " . ($tel ?: '—') . "\n\n"
       . "Message :\n$msg\n\n— Envoyé le " . date('d/m/Y à H:i') . " · IP " . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], ($suspect ? '[SPAM ?] ' : '') . "[SITE] Contact — $objet — $nom ($ref)", $corps, $email);

$accuse = "Bonjour,\n\nVotre message est bien arrivé au cabinet (référence $ref). Vous serez rappelé(e) sous 48 heures ouvrées, du lundi au vendredi.\n\n"
        . "En cas d'urgence — délai qui expire, audience imminente — appelez le 02 30 96 33 35 : un message est pris 24h/24.\n\n"
        . "Rappel : ne transmettez pas de pièces par courriel ; si le cabinet prend votre dossier, un lien de dépôt sécurisé vous sera adressé.\n\n"
        . "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · $CONFIG[cabinet_email]\n" . SITE_URL . "\n";
if (!$suspect) { envoyer_mail($email, "Votre message au cabinet Arnaud Cousin ($ref)", $accuse); }

journal('contact', ($envoye ? 'ok' : 'echec-mail') . ($suspect ? '-suspect' : ''));
if (!$envoye) { erreur('Le courriel n’a pas pu partir. Appelez le cabinet au 02 30 96 33 35 ou écrivez à ' . $CONFIG['cabinet_email'] . '.', 500); }
merci('contact', ['ref' => $ref]);
