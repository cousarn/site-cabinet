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

/** Créneau réservé aux dossiers en cours : lien privé Bookings, lu dans SharePoint (jamais dans ce code, public) et gardé en cache ; '' s'il est illisible. */
function creneau_dossier(string $format): string {
    global $CONFIG;
    $cache = DOSSIER_CACHE . '/rdv-dossier.json';
    $liens = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
    if (!is_array($liens) || (int)@filemtime($cache) < time() - 3600) {
        try {
            require_once __DIR__ . '/_graph.php';
            if (graph_configure()) {
                $chemin = trim((string)($CONFIG['graph']['racine'] ?? 'Dépôts du site'), '/') . '/reglages-rendez-vous.json';
                [$code, $item] = graph_appel('GET', '/drives/' . graph_drive() . '/root:/' . graph_chemin($chemin));
                $url = (string)($item['@microsoft.graph.downloadUrl'] ?? '');
                if ($code === 200 && $url !== '') {
                    [$code, $lus] = graph_appel('GET', $url, null, [], false);
                    if ($code === 200 && is_array($lus)) {
                        $liens = $lus;
                        @file_put_contents($cache, json_encode($lus, JSON_UNESCAPED_SLASHES), LOCK_EX);
                        @chmod($cache, 0600);
                    }
                }
            }
        } catch (\Throwable $x) { journal('rendez-vous', 'creneaux-illisibles'); }
    }
    $l = is_array($liens) && is_string($liens[$format] ?? null) ? $liens[$format] : '';
    return preg_match('~^https://bookings\.cloud\.microsoft/bookwithme/user/[A-Za-z0-9@._-]+/meetingtype/[A-Za-z0-9_-]+\?[A-Za-z0-9=&_-]+$~', $l) === 1 ? $l : '';
}

$format    = champ('format', 20);
$domaine   = champ('domaine', 60);
$deja      = champ('deja', 10);
$numero    = (string)preg_replace('/\D/', '', champ('numero', 30)); // dossier en cours : numéro SECIB
$adverse   = champ('adverse', 120);
$resume    = texte('resume', 4000);
$personnes = champ('personnes', 400);
$echeance  = champ('echeance', 200);
$nom       = champ('nom', 120);
$email     = champ('email', 200);
$telephone = champ('telephone', 40);

if (!isset($formats[$format])) { erreur('Choisissez le format du rendez-vous : au cabinet, en visioconférence ou par téléphone.'); }
[$libelle, $lien, $avance] = $formats[$format];
if (!in_array($deja, ['non', 'oui', 'dossier'], true)) { erreur('Indiquez si vous avez déjà consulté le cabinet pour cette affaire : le tarif en dépend.'); }
$suivi   = $deja === 'oui';     // déjà consulté pour cette affaire : ni forfait, ni créneau en ligne, le cabinet recontacte
$enCours = $deja === 'dossier'; // dossier en cours : créneau sans règlement, contrôle a posteriori par le cabinet (rdv-decision.php)
if ($enCours && $numero !== '' && !preg_match('/^\d{8,9}$/', $numero)) { erreur('Le numéro de dossier comporte neuf chiffres. Laissez ce champ vide si vous ne l’avez pas.'); }
if ($enCours && $numero === '' && mb_strlen($adverse) < 2) { erreur('Indiquez votre numéro de dossier ou le nom de la partie adverse.'); }
if ($nom === '' || !email_valide($email)) { erreur('Nom et courriel valide sont nécessaires.'); }
if (strlen((string)preg_replace('/\D/', '', $telephone)) < 9) { erreur('Un numéro de téléphone est nécessaire pour vous joindre.'); }
if ($enCours && mb_strlen($resume) < 20) { erreur('Indiquez en une phrase l’objet du rendez-vous.'); }
if (!$enCours && mb_strlen($resume) < 80) { erreur('Résumez votre affaire en quelques lignes au moins : l’avocat lit ce résumé avant le rendez-vous.'); }
if ($deja === 'non' && !isset($_POST['cgv'])) { erreur('Merci d’accepter les conditions de la consultation.'); }

$suspect = contenu_suspect($nom, $resume, $personnes, $echeance, $adverse);
$ref     = reference();
$dossier = rtrim($CONFIG['stockage'], '/') . '/rendez-vous-' . $ref;
@mkdir($dossier, 0700, true);

if ($enCours) {
    $lien = creneau_dossier($format);
    $libelle = ['cabinet' => 'Dossier en cours, au cabinet', 'visio' => 'Dossier en cours, en visioconférence', 'telephone' => 'Dossier en cours, par téléphone'][$format];
    @file_put_contents($dossier . '/demande.txt', "Réf. $ref — " . date('c') . "\n$libelle\nNom : $nom\nCourriel : $email\nTéléphone : $telephone\nNuméro de dossier déclaré : " . ($numero ?: 'non indiqué') . "\nPartie adverse déclarée : " . ($adverse ?: 'non indiquée') . "\nDate ou délai : " . ($echeance ?: 'non précisé') . "\n\nObjet :\n$resume\n", LOCK_EX);
    @file_put_contents($dossier . '/demande.json', json_encode(
        ['ref' => $ref, 'date' => date('c'), 'type' => 'rendez-vous', 'mode' => 'dossier', 'formule' => $format, 'libelle' => $libelle, 'prix' => 0, 'nom' => $nom, 'email' => $email, 'telephone' => $telephone, 'numero' => $numero, 'adverse' => $adverse, 'lien' => $lien],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    if (!$suspect) {
        envoyer_mail($email, "Votre demande de rendez-vous ($ref)", "Bonjour,\n\nVotre demande de rendez-vous est bien enregistrée (référence $ref).\n\nFormat : $libelle\n\n"
            . ($lien !== '' ? "CHOISISSEZ VOTRE CRÉNEAU, si ce n'est déjà fait\n\n$lien\n\n" : "Le cabinet vous recontacte pour convenir du créneau.\n\n")
            . "Le cabinet vérifie votre dossier et vous confirme ensuite le rendez-vous par courriel. Vous n'avez rien à régler maintenant : le rendez-vous relève des conditions convenues pour votre dossier.\n\n"
            . "Urgence (délai qui expire, audience imminente) : 02 30 96 33 35, prise de message 24h/24.\n\n"
            . "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n");
    }

    // Message du cabinet : identité déclarée, deux boutons (liens signés vers rdv-decision.php, qui demande confirmation).
    $hote = 'https://' . ($_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST));
    $url  = fn(string $action): string => "$hote/api/rdv-decision.php?ref=$ref&action=$action&sig=" . signature_rdv($ref, $action);
    $lignes = ['Nom' => $nom, 'Courriel' => $email, 'Téléphone' => $telephone, 'Numéro de dossier déclaré' => $numero ?: 'non indiqué', 'Partie adverse déclarée' => $adverse ?: 'non indiquée',
        'Format' => $libelle, 'Date ou délai signalé' => $echeance ?: 'non précisé', 'Objet du rendez-vous' => $resume];
    $intro  = "Un client qui déclare un dossier en cours a pris rendez-vous. Son identité n'est pas vérifiée : comparez avec le dossier avant de valider.";
    $suite  = $lien !== '' ? "La date choisie arrive par le message « Nouvelle réservation » de Bookings, dont l'objet commence par son nom et « Dossier en cours »." : "ATTENTION : le lien de réservation n'a pas pu être lu, le client n'a pas choisi de créneau. Le recontacter.";
    $texte  = ($suspect ? AVERTISSEMENT_SUSPECT : '') . "$intro\n\n";
    $tableau = '';
    foreach ($lignes as $k => $val) {
        $texte .= "$k : $val\n";
        $tableau .= '<tr><td style="padding:4px 14px 4px 0;vertical-align:top;color:#555">' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '</td><td style="padding:4px 0">' . nl2br(htmlspecialchars($val, ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    }
    $texte .= "\n$suite\n\nVALIDER le rendez-vous (accusé de réception au client) :\n" . $url('valider') . "\n\nANNULER le rendez-vous (imposteur, erreur) :\n" . $url('annuler') . "\n\n"
        . "CONTRÔLE DE L'AGENT : comparer nom, courriel, téléphone et partie adverse avec le dossier SECIB ; signaler toute anomalie ; ne jamais ouvrir ces deux liens.\nDossier serveur : $dossier\n";
    $bouton = fn(string $action, string $t, string $c): string => '<a href="' . htmlspecialchars($url($action), ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;margin:0 12px 12px 0;padding:12px 22px;border-radius:6px;color:#ffffff;background:' . $c . ';text-decoration:none;font-weight:600">' . $t . '</a>';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:15px;line-height:1.5;color:#222">' . ($suspect ? '<p>' . nl2br(htmlspecialchars(trim(AVERTISSEMENT_SUSPECT), ENT_QUOTES, 'UTF-8')) . '</p>' : '')
        . '<p>' . htmlspecialchars($intro, ENT_QUOTES, 'UTF-8') . '</p><table style="border-collapse:collapse;font-size:15px">' . $tableau . '</table><p>' . htmlspecialchars($suite, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p>' . $bouton('valider', 'Valider le rendez-vous', '#11352F') . $bouton('annuler', 'Annuler le rendez-vous', '#8C2F2F') . '</p>'
        . '<p style="font-size:13px;color:#555">Chaque bouton ouvre une page de confirmation. Valider envoie un accusé de réception au client. Référence ' . $ref . '.<br>Contrôle de l’agent : comparer nom, courriel, téléphone et partie adverse avec le dossier SECIB ; signaler toute anomalie ; ne jamais ouvrir ces deux liens.</p></div>';
    $envoye = envoyer_mail_html($CONFIG['cabinet_email'], ($suspect ? '[SPAM ?] ' : '') . "[SITE] Dossier en cours — rendez-vous de $nom — " . ($numero ?: "c/ $adverse") . " ($ref)", $texte, $html, $email);
    journal('rendez-vous', ($envoye ? 'dossier' : 'dossier-echec-mail') . ($suspect ? '-suspect' : '') . ($lien === '' ? '-sans-creneau' : ''));
    merci('rendez-vous', ['ref' => $ref, 'dossier' => 1] + ($lien !== '' ? ['creneau' => $lien] : []));
}

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
