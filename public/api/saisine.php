<?php
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
controle_anti_robot('saisine');
limiter_debit('saisine');

$qualite   = champ('qualite', 60);
$structure = champ('structure', 160);
$nom       = champ('nom', 120);
$email     = champ('email', 200);
$objet     = champ('objet', 80);
$msg       = texte('message');

if ($nom === '' || $structure === '' || $msg === '' || !email_valide($email)) { erreur('Nom, structure, courriel professionnel et objet de la saisine sont nécessaires.'); }

$suspect = contenu_suspect($structure, $nom, $msg);
$ref = reference();
$corps = ($suspect ? AVERTISSEMENT_SUSPECT : '') . "Saisine professionnelle depuis le site — réf. $ref\n\n"
       . "Qualité : $qualite\nStructure : $structure\nNom : $nom\nCourriel : $email\nObjet : $objet\n\n"
       . "Détail (juridiction, audience, délai, parties) :\n$msg\n\n— Envoyé le " . date('d/m/Y à H:i') . "\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], ($suspect ? '[SPAM ?] ' : '') . "[SITE] Saisine — $objet — $structure ($ref)", $corps, $email);

$accuse = "Cher(e) Confrère, Maître, Madame, Monsieur,\n\n"
        . "Votre saisine (référence $ref — $objet) est bien reçue. Le cabinet vous confirme sa disponibilité et le forfait applicable sous 24 heures ouvrées, avant toute diligence, et vous adresse un lien de dépôt sécurisé pour les pièces.\n\n"
        . "Pour une audience ou un délai imminent, appelez directement le 02 30 96 33 35.\n\n"
        . "Bien confraternellement,\n\nArnaud Cousin — Avocat au Barreau de Rennes, Toque 128\n15 rue du Puits Mauger, 35000 Rennes · " . SITE_URL . "\n";
if (!$suspect) { envoyer_mail($email, "Accusé de réception de votre saisine ($ref)", $accuse); }

journal('saisine', ($envoye ? 'ok' : 'echec-mail') . ($suspect ? '-suspect' : ''));
if (!$envoye) { erreur('Le courriel n’a pas pu partir. Appelez le cabinet au 02 30 96 33 35.', 500); }
merci('saisine', ['ref' => $ref]);
