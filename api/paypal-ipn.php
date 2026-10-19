<?php
/**
 * Notification de paiement instantané PayPal (IPN) — marque une consultation écrite « payée ».
 * PayPal appelle cette URL (notify_url posé par lien_paypal() dans _lib.php) à chaque événement sur un paiement.
 * Rien n'est exploité avant que PayPal ait confirmé le message (réponse VERIFIED à son renvoi) ; aucun secret, aucune clé API.
 * Écrit dans le dossier de la consultation (hors racine web) : paiement-<txn>.json (trace, idempotence) et paye.json (synthèse).
 * Réponses : 200 corps vide (traité ou ignoré, PayPal n'insiste pas) ; 503 (vérification injoignable, PayPal réessaie).
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();
ignore_user_abort(true); // si PayPal coupe la connexion, le traitement va à son terme
@set_time_limit(90);

/** Termine la requête : journal (sans donnée personnelle), code HTTP, corps vide. */
function fin(int $code, string $etat): never {
    journal('paypal-ipn', $etat);
    http_response_code($code);
    exit;
}

/** Renvoie le message brut à PayPal pour vérification : 'VERIFIED', 'INVALID' ou null (transport en échec). */
function paypal_verifier(string $brut, bool $sandbox): ?string {
    /* Serveur PHP intégré (php -S, tests locaux depuis la machine elle-même) : PayPal n'étant pas joignable depuis le poste
       de développement, la notification est réputée vérifiée. Sans effet sous Apache/OVH (SAPI fpm-fcgi, cgi-fcgi…). */
    if (php_sapi_name() === 'cli-server' && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { return 'VERIFIED'; }
    $ch = curl_init('https://' . ($sandbox ? 'ipnpb.sandbox.paypal.com' : 'ipnpb.paypal.com') . '/cgi-bin/webscr');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_POSTFIELDS => 'cmd=_notify-validate&' . $brut, // le corps reçu, tel quel, précédé de la commande
        CURLOPT_HTTPHEADER => ['User-Agent: site-cabinet-IPN', 'Connection: Close'],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FORBID_REUSE => true,
    ]);
    $rep  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if (!is_string($rep) || $code !== 200) { error_log("paypal-ipn : vérification injoignable ($code) $err"); return null; }
    $rep = trim($rep);
    return in_array($rep, ['VERIFIED', 'INVALID'], true) ? $rep : null;
}

/** Lit la demande : demande.json (écrit par consultation.php) ; à défaut, analyse de demande.txt (anciennes demandes). */
function lire_demande(string $dossier): array {
    $d = ['formule' => '', 'libelle' => '', 'prix' => 0, 'nom' => '', 'email' => ''];
    if (is_file($dossier . '/demande.json')) {
        $j = json_decode((string)file_get_contents($dossier . '/demande.json'), true);
        if (is_array($j)) {
            foreach (['formule', 'libelle', 'nom', 'email'] as $k) { $d[$k] = is_scalar($j[$k] ?? null) ? (string)$j[$k] : ''; }
            $d['prix'] = is_numeric($j['prix'] ?? null) ? (float)$j['prix'] : 0;
            return $d;
        }
    }
    $txt = is_file($dossier . '/demande.txt') ? (string)file_get_contents($dossier . '/demande.txt') : '';
    if (preg_match('/^Formule : (.+?) \((\d+(?:[.,]\d+)?) €\)/mu', $txt, $m)) { $d['libelle'] = $m[1]; $d['prix'] = (float)str_replace(',', '.', $m[2]); }
    if (preg_match('/^Nom : (.*)$/mu', $txt, $m)) { $d['nom'] = trim($m[1]); }
    if (preg_match('/^Courriel : (.*)$/mu', $txt, $m)) { $d['email'] = trim($m[1]); }
    return $d;
}

/** Trace de la transaction (création exclusive : false si elle existe déjà, donc notification en double). */
function tracer_transaction(string $fichier, array $donnees): bool {
    $fh = @fopen($fichier, 'x');
    if ($fh === false) { return false; }
    fwrite($fh, json_encode($donnees, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fclose($fh);
    @chmod($fichier, 0600);
    return true;
}

/** Date à n jours ouvrés (lundi–vendredi ; les jours fériés ne sont pas décomptés). */
function echeance_ouvree(int $jours): string {
    $d = new DateTimeImmutable('today');
    while ($jours > 0) { $d = $d->modify('+1 day'); if ((int)$d->format('N') <= 5) { $jours--; } }
    return $d->format('d/m/Y');
}

/** Montant à la française. */
function euros(float $m): string { return number_format($m, 2, ',', ' '); }

/* ---- Lecture et vérification ---- */
$brut = (string)file_get_contents('php://input');
if ($brut === '') { fin(400, 'corps-vide'); }
parse_str($brut, $ipn);
$v = fn(string $k): string => is_string($ipn[$k] ?? null) ? trim($ipn[$k]) : '';

$sandbox = (bool)($CONFIG['paypal_sandbox'] ?? str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'preprod.'));
$verdict = paypal_verifier($brut, $sandbox);
if ($verdict === null) { fin(503, 'verification-injoignable'); }  // PayPal réessaiera
if ($verdict !== 'VERIFIED') { fin(200, 'invalid'); }              // message forgé ou altéré : ignoré

/* À partir d'ici, le message vient bien de PayPal. */
$statut  = $v('payment_status');
$txn     = $v('txn_id');
$ref     = $v('invoice') !== '' ? $v('invoice') : $v('custom');
$montant = $v('mc_gross');
$devise  = $v('mc_currency');
$refOk   = (bool)preg_match('/^\d{6}-[A-Z0-9]{4}$/', $ref);
$refSure = $refOk ? $ref : ($ref === '' ? '' : 'ref-invalide'); // seule une référence bien formée est reprise dans le journal et les sujets
$dossier = $refOk ? rtrim($CONFIG['stockage'], '/') . '/consultation-' . $ref : '';
$utiles  = [];
foreach (['txn_id', 'parent_txn_id', 'txn_type', 'payment_status', 'pending_reason', 'reason_code', 'payment_date', 'payment_type',
          'mc_gross', 'mc_fee', 'mc_currency', 'payer_email', 'payer_id', 'payer_status', 'first_name', 'last_name',
          'receiver_id', 'receiver_email', 'business', 'invoice', 'custom', 'item_name', 'item_number', 'test_ipn', 'ipn_track_id'] as $k) {
    if ($v($k) !== '') { $utiles[$k] = mb_substr($v($k), 0, 200); }
}
$test = ($sandbox || $v('test_ipn') === '1') ? "Mode test (bac à sable PayPal) : aucun argent réel.\n" : '';

/** Incohérence : courriel détaillé au cabinet, journal, 200 (PayPal n'a pas à réessayer). */
$aVerifier = function (string $motif, string $etat) use ($CONFIG, $refSure, $dossier, $utiles, $test): never {
    $detail = '';
    foreach ($utiles as $k => $val) { $detail .= "$k : $val\n"; }
    envoyer_mail($CONFIG['cabinet_email'], '[SITE] Paiement PayPal à vérifier' . ($refSure !== '' ? " ($refSure)" : ''),
        "Une notification PayPal authentifiée (IPN) n'a pas pu être rattachée automatiquement à une consultation.\n\n"
        . "Motif : $motif\n$test\nDonnées PayPal :\n$detail\n"
        . ($dossier !== '' && is_dir($dossier) ? "Dossier serveur : $dossier\n" : '')
        . "À contrôler dans le compte PayPal du cabinet (Activité ; Paramètres › Notifications › Historique IPN pour rejouer la notification après correction).\n");
    fin(200, $etat . ($refSure !== '' ? " $refSure" : ''));
};

$destinataireOk = $v('receiver_id') !== '' && $v('receiver_id') === (string)$CONFIG['paypal_id'];
if (!$destinataireOk && !empty($CONFIG['paypal_email'])) {
    foreach (['receiver_email', 'business'] as $k) {
        if ($v($k) !== '' && strcasecmp($v($k), (string)$CONFIG['paypal_email']) === 0) { $destinataireOk = true; break; }
    }
}
if (!$destinataireOk) { $aVerifier('destinataire inattendu : le paiement ne vise pas le compte PayPal du cabinet', 'destinataire'); }
if ($devise !== 'EUR') { $aVerifier("devise « $devise » au lieu de EUR", 'devise'); }
if (!$refOk || !is_dir($dossier)) { $aVerifier('référence de consultation inconnue' . ($ref !== '' ? ' (« ' . mb_substr($ref, 0, 40) . ' »)' : ' (champs invoice et custom vides)'), 'ref-inconnue'); }
if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $txn)) { $aVerifier('identifiant de transaction (txn_id) absent ou inattendu', 'txn-invalide'); }

$demande    = lire_demande($dossier);
$prix       = (float)$demande['prix'];
$libelle    = $demande['libelle'] !== '' ? (string)$demande['libelle'] : 'Consultation écrite';
$nom        = $demande['nom'] !== '' ? (string)$demande['nom'] : 'client non identifié';
$email      = (string)$demande['email'];
$fichierTxn = $dossier . '/paiement-' . $txn . '.json';
$trace      = ['txn_id' => $txn, 'date' => date('c'), 'montant' => $montant, 'devise' => $devise, 'payer_email' => $v('payer_email'),
               'statut' => $statut, 'ref' => $ref, 'sandbox' => $sandbox, 'paypal' => $utiles];
$payeur     = trim($v('first_name') . ' ' . $v('last_name')) . ($v('payer_email') !== '' ? ' <' . $v('payer_email') . '>' : '');
$enTete     = "Consultation : $libelle" . ($prix > 0 ? ' (' . euros($prix) . ' € TTC attendus)' : '') . "\nClient : $nom" . ($email !== '' ? " <$email>" : '') . "\n"
            . 'Montant : ' . euros((float)$montant) . ' € par PayPal' . ($v('mc_fee') !== '' ? ' (commission PayPal : ' . euros((float)$v('mc_fee')) . ' €)' : '') . "\n"
            . "Payeur PayPal : $payeur\nTransaction : $txn" . ($v('payment_date') !== '' ? ' — ' . $v('payment_date') : '') . "\n$test";
$signature  = "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";

/* ---- Traitement selon le statut ---- */
switch ($statut) {
    case 'Completed':
        if (is_file($fichierTxn)) { fin(200, "doublon $ref"); }
        if ($prix <= 0) { $aVerifier('prix attendu introuvable dans la demande (demande.json / demande.txt)', 'prix-inconnu'); }
        if ((float)$montant + 0.005 < $prix) { $aVerifier('montant insuffisant : ' . euros((float)$montant) . ' € reçus pour ' . euros($prix) . ' € attendus', 'montant'); }
        if (!tracer_transaction($fichierTxn, $trace)) { fin(200, "doublon $ref"); }
        if (is_file($dossier . '/paye.json')) { $aVerifier('un règlement était déjà enregistré pour cette référence (double paiement ?)', 'double-reglement'); }
        @file_put_contents($dossier . '/paye.json', json_encode(['ref' => $ref, 'paye_le' => date('c'), 'montant' => $montant, 'devise' => $devise,
            'txn_id' => $txn, 'moyen' => 'paypal', 'payer_email' => $v('payer_email'), 'sandbox' => $sandbox],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $aujourdhui = date('d/m/Y');
        $echeance   = echeance_ouvree(5);
        $corpsCabinet = "Paiement reçu — réf. $ref\n\n$enTete\n"
            . "Le délai de réponse de 5 jours ouvrés court à compter de ce jour ($aujourdhui), soit au plus tard le $echeance (jours fériés non décomptés).\n\n"
            . ($email !== '' ? "Le client a reçu un courriel de confirmation du règlement.\n" : "Adresse du client introuvable : aucun courriel de confirmation ne lui a été adressé.\n")
            . "Dossier serveur : $dossier\n";
        $envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Paiement reçu — $libelle — $nom ($ref)", $corpsCabinet, $email !== '' ? $email : null);

        if (email_valide($email)) {
            $corpsClient = "Bonjour,\n\nVotre règlement de " . euros((float)$montant) . " € TTC pour la consultation écrite « $libelle » (référence $ref) est bien reçu. Merci.\n\n"
                . "La consultation est lancée : la réponse écrite de l'avocat vous parviendra par courriel, à cette adresse, sous 5 jours ouvrés. "
                . "Si une précision vous était nécessaire, le cabinet vous contacterait sans attendre.\n\n"
                . "Transaction PayPal : $txn. PayPal vous adresse par ailleurs son propre reçu.\n$test\n" . $signature;
            envoyer_mail($email, "Votre règlement est bien reçu ($ref)", $corpsClient);
        }
        fin(200, ($envoye ? 'paye' : 'paye-echec-mail') . " $ref");

    case 'Pending':
        $motif = $v('pending_reason') !== '' ? $v('pending_reason') : 'non précisé';
        envoyer_mail($CONFIG['cabinet_email'], "[SITE] Paiement PayPal en attente — $libelle — $nom ($ref)",
            "Paiement en attente — réf. $ref\n\n$enTete\nMotif PayPal (pending_reason) : $motif\n\n"
            . "Rien n'est marqué : PayPal enverra une nouvelle notification quand le paiement sera terminé (ou refusé). "
            . "Un paiement « echeck » ou « multi_currency » peut demander une action dans le compte PayPal.\nDossier serveur : $dossier\n");
        fin(200, "pending $ref");

    case 'Refunded': case 'Reversed': case 'Canceled_Reversal':
        if (!tracer_transaction($fichierTxn, $trace)) { fin(200, "doublon $ref"); }
        $libStatut = ['Refunded' => 'remboursement', 'Reversed' => 'annulation (contestation)', 'Canceled_Reversal' => 'annulation levée'][$statut];
        envoyer_mail($CONFIG['cabinet_email'], "[SITE] Paiement PayPal — $libStatut — $libelle — $nom ($ref)",
            ucfirst($libStatut) . " — réf. $ref\n\n$enTete"
            . ($v('parent_txn_id') !== '' ? 'Transaction d’origine : ' . $v('parent_txn_id') . "\n" : '')
            . ($v('reason_code') !== '' ? 'Motif PayPal (reason_code) : ' . $v('reason_code') . "\n" : '')
            . "\nLe fichier paye.json du dossier n'est pas modifié : apprécier la suite à donner (consultation à suspendre, réponse à la contestation).\nDossier serveur : $dossier\n");
        fin(200, strtolower($statut) . " $ref");

    default:
        $aVerifier("statut de paiement « $statut » non traité automatiquement", 'statut');
}
