<?php
/**
 * Demande de rendez-vous : le client résume son affaire, puis choisit son créneau (Microsoft Bookings) et règle la consultation.
 * Bookings (page personnelle) n'encaisse rien et ne pose pas de question obligatoire : ce formulaire passe avant lui.
 * L'avocat reçoit le résumé avant le rendez-vous (compétence, conflit d'intérêts, recherches à prévoir).
 * Écrit hors racine web dans <stockage>/rendez-vous-<réf>/ ; paypal-ipn.php y marque le règlement (paye.json).
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
controle_anti_robot('rendez-vous');
limiter_debit('rendez-vous');

/* Liens publics Bookings : les mêmes que `bookings` dans src/data/cabinet.ts (à modifier aux deux endroits). */
$base    = 'https://bookings.cloud.microsoft/bookwithme/user/1b837552a8724b3389750385d7d2b910@arnaudcousin-avocat.fr/meetingtype/';
$formats = [ // libellé, lien de réservation, règlement exigé avant le rendez-vous
    'cabinet'   => ['Rendez-vous au cabinet', $base . 'WVhQj8HnQkWwka4vXXl_ZA2?anonymous&ep=mLinkFromTile', false],
    'visio'     => ['Rendez-vous en visioconférence', $base . '7r6ub-rr_kOYFlJqr2YP2Q2?anonymous&ep=mLinkFromTile', true],
    'telephone' => ['Rendez-vous par téléphone', $base . 'asah2_KYrEan3uNgg1MXLw2?anonymous&ep=mLinkFromTile', true],
];
$prix = 132; // TTC, une heure au plus (110 € HT) : première consultation de prise de contact, une seule fois par affaire
$taux = 192; // taux horaire TTC du cabinet (bareme.tauxHoraire, src/data/honoraires.ts) : le suivi se facture au temps passé

$format    = champ('format', 20);
$domaine   = champ('domaine', 60);
$deja      = champ('deja', 5);
$resume    = texte('resume', 4000);
$personnes = champ('personnes', 400);
$echeance  = champ('echeance', 200);
$nom       = champ('nom', 120);
$email     = champ('email', 200);
$telephone = champ('telephone', 40);

if (!isset($formats[$format])) { erreur('Choisissez le format du rendez-vous : au cabinet, en visioconférence ou par téléphone.'); }
[$libelle, $lien, $avance] = $formats[$format];
if (!in_array($deja, ['non', 'oui'], true)) { erreur('Indiquez si vous avez déjà consulté le cabinet pour cette affaire : le tarif en dépend.'); }
$suivi = $deja === 'oui'; // déjà consulté pour cette affaire : ni forfait, ni créneau en ligne, le cabinet recontacte
if ($nom === '' || !email_valide($email)) { erreur('Nom et courriel valide sont nécessaires.'); }
if (strlen((string)preg_replace('/\D/', '', $telephone)) < 9) { erreur('Un numéro de téléphone est nécessaire pour vous joindre.'); }
if (mb_strlen($resume) < 80) { erreur('Résumez votre affaire en quelques lignes au moins : l’avocat lit ce résumé avant le rendez-vous.'); }
if (!isset($_POST['cgv'])) { erreur('Merci d’accepter les conditions de la consultation.'); }

$suspect = contenu_suspect($nom, $resume, $personnes, $echeance);
$ref     = reference();
$dossier = rtrim($CONFIG['stockage'], '/') . '/rendez-vous-' . $ref;
@mkdir($dossier, 0700, true);
@file_put_contents($dossier . '/demande.txt', "Réf. $ref — " . date('c') . "\nFormule : $libelle (" . ($suivi ? 'suivi, au temps passé' : "$prix €") . ")\nDomaine : $domaine\nNom : $nom\nCourriel : $email\nTéléphone : $telephone\n"
    . 'Autres personnes : ' . ($personnes ?: 'non précisé') . "\nDate ou délai : " . ($echeance ?: 'non précisé') . "\n\nRésumé :\n$resume\n", LOCK_EX);
// Version structurée, lue par paypal-ipn.php (prix attendu, nom et courriel du client) ; le résumé reste dans demande.txt.
@file_put_contents($dossier . '/demande.json', json_encode(
    ['ref' => $ref, 'date' => date('c'), 'type' => 'rendez-vous', 'suivi' => $suivi, 'formule' => $format, 'libelle' => $libelle, 'prix' => $suivi ? 0 : $prix, 'domaine' => $domaine, 'nom' => $nom, 'email' => $email, 'lien' => $lien],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

$payer = (!$suivi && $CONFIG['paiement'] === 'paypal' && !empty($CONFIG['paypal_id'])) ? lien_paypal($libelle, $prix, $ref, $format, 'rendez-vous') : null;
$reglement = $avance ? "2. RÉGLEZ LA CONSULTATION ($prix € TTC) AVANT LE RENDEZ-VOUS\n\n" : "2. RÈGLEMENT ($prix € TTC)\n\nAu cabinet, le jour du rendez-vous, ou dès maintenant si vous le préférez :\n\n";
if ($payer) { $reglement .= "En ligne, par PayPal ou par carte bancaire (sans compte PayPal) :\n$payer\n\nOu par"; } else { $reglement .= 'Par'; }
$reglement .= " virement, référence « $ref » :\n$CONFIG[rib]\n" . ($avance ? "\nLe rendez-vous est définitivement retenu à réception du règlement.\n" : '');

$corpsClient = "Bonjour,\n\nVotre demande de rendez-vous est bien enregistrée (référence $ref). L'avocat lit votre résumé avant le rendez-vous.\n\n"
    . "Format : $libelle" . ($suivi ? '' : ', une heure au plus') . "\nDomaine : $domaine\n\n"
    . ($suivi
        ? "Vous avez indiqué avoir déjà consulté le cabinet pour cette affaire. Le tarif de $prix € vaut une seule fois par affaire, pour une première consultation de prise de contact : le suivi est facturé au temps passé, au taux horaire du cabinet ($taux € TTC).\n\n"
          . "Le cabinet vous recontacte sous 48 heures ouvrées pour convenir du rendez-vous et de son coût. Vous n'avez rien à régler maintenant.\n\n"
        : "1. CHOISISSEZ VOTRE CRÉNEAU, si ce n'est déjà fait\n\n$lien\n\n$reglement\n"
          . "La consultation est une consultation d'orientation, sans recherche longue. Si votre affaire ne relève pas des compétences du cabinet ou présente un conflit d'intérêts, "
          . "vous en êtes informé(e) avant le rendez-vous et intégralement remboursé(e). Si elle appelle des recherches importantes, l'avocat vous le dit et vous propose un devis.\n\n"
          . "Ce tarif vaut une seule fois par affaire, pour une première consultation de prise de contact. Les demandes ultérieures sur le même sujet sont facturées au temps passé, au taux horaire du cabinet ($taux € TTC).\n\n")
    . "Urgence (délai qui expire, audience imminente) : 02 30 96 33 35, prise de message 24h/24.\n\n"
    . "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";
if (!$suspect) { envoyer_mail($email, "Votre demande de rendez-vous ($ref)", $corpsClient); } // suspect : créneau et règlement restent affichés sur la page de remerciement

$corpsCabinet = ($suspect ? AVERTISSEMENT_SUSPECT : '') . "Demande de rendez-vous — réf. $ref\n\n" . ($suivi ? "SUIVI : le client dit avoir déjà consulté le cabinet pour cette affaire. Ni forfait de $prix €, ni lien de réservation : le recontacter, facturation au temps passé.\n\n" : '')
    . "Format : $libelle" . ($suivi ? '' : " ($prix €, " . ($avance ? 'à régler avant le rendez-vous' : 'réglé au cabinet ou en ligne') . ')') . "\nDomaine : $domaine\n"
    . "Nom : $nom\nCourriel : $email\nTéléphone : $telephone\nAutres personnes concernées : " . ($personnes ?: 'non précisé') . "\nDate ou délai signalé : " . ($echeance ?: 'non précisé') . "\n\n"
    . "Résumé de l'affaire :\n$resume\n\n"
    . "À apprécier avant le rendez-vous : compétence du cabinet, conflit d'intérêts, recherches à prévoir.\n"
    . ($suivi ? ''
        : "La suite : le client choisit son créneau (message « Nouvelle réservation » de Bookings) et règle (message « [SITE] Paiement reçu »). Sans ce second message, rien n'est réglé en ligne.\n"
          . "Le client déclare une première consultation pour cette affaire : vérifier qu'il n'a pas déjà été reçu.\n"
          . "Pour refuser : annuler la réservation dans Outlook et répondre à ce message ; rembourser le règlement éventuel.\n")
    . "Dossier serveur : $dossier\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], ($suspect ? '[SPAM ?] ' : '') . "[SITE] Demande de rendez-vous — " . ($suivi ? 'SUIVI — ' : '') . "$libelle — $nom ($ref)", $corpsCabinet, $email);

journal('rendez-vous', ($envoye ? 'ok' : 'echec-mail') . ($suspect ? '-suspect' : ''));
merci('rendez-vous', $suivi ? ['ref' => $ref, 'suivi' => 1] : ['ref' => $ref, 'prix' => $prix, 'format' => $format] + ($payer ? ['payer' => $payer] : []));
