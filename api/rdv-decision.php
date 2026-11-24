<?php
/**
 * Rendez-vous pris par un client ayant un dossier en cours : validation ou annulation par le cabinet, a posteriori.
 * Les deux boutons du message « [SITE] Dossier en cours » mènent ici (lien signé). Un GET n'affiche qu'une page de
 * confirmation : les antivirus de messagerie ouvrent les liens des courriels, seul le bouton de la page (POST) agit.
 * Écrit decision.json dans <stockage>/rendez-vous-<réf>/. L'agenda Outlook n'est pas modifié : le site n'y a pas accès.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

/** Page de réponse minimale. $corps : HTML déjà échappé. */
function page_decision(string $titre, string $corps, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    $t = htmlspecialchars($titre, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"fr\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>$t</title>"
        . "<body style=\"font-family:system-ui;max-width:40em;margin:3em auto;padding:0 1em;line-height:1.5\"><h1 style=\"font-weight:500\">$t</h1>$corps</body></html>";
    exit;
}

$poste  = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$source = $poste ? $_POST : $_GET;
$v      = fn(string $k): string => is_string($source[$k] ?? null) ? trim($source[$k]) : '';
$e      = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
limiter_debit('rdv-decision', 60);

$ref    = $v('ref');
$action = $v('action');
if (!preg_match('/^\d{6}-[A-Z0-9]{4}$/', $ref) || !in_array($action, ['valider', 'annuler'], true) || !hash_equals(signature_rdv($ref, $action), $v('sig'))) {
    page_decision('Lien non valable', '<p>Ce lien est incomplet ou n’a pas été émis par le site. Ouvrez-le depuis le message « [SITE] Dossier en cours ».</p>', 403);
}
$dossier = rtrim($CONFIG['stockage'], '/') . '/rendez-vous-' . $ref;
$d = is_file($dossier . '/demande.json') ? json_decode((string)file_get_contents($dossier . '/demande.json'), true) : null;
if (!is_array($d) || ($d['mode'] ?? '') !== 'dossier') { page_decision('Demande introuvable', '<p>Cette demande n’est plus sur le serveur.</p>', 404); }

$etat = is_file($dossier . '/decision.json') ? json_decode((string)file_get_contents($dossier . '/decision.json'), true) : null;
$etat = is_array($etat) ? $etat : ['action' => '', 'date' => '', 'historique' => []];
$libelles = ['valider' => 'validé', 'annuler' => 'annulé'];
$fiche = '<p><strong>' . $e($d['nom'] ?? '') . '</strong> · ' . $e($d['email'] ?? '') . ' · ' . $e($d['telephone'] ?? '') . '<br>Dossier déclaré : ' . $e(($d['numero'] ?? '') ?: 'non indiqué')
    . ' · partie adverse déclarée : ' . $e(($d['adverse'] ?? '') ?: 'non indiquée') . '<br>' . $e($d['libelle'] ?? '') . ' · référence ' . $e($ref) . '</p>';

if (!$poste) {
    $deja = $etat['action'] !== '' ? '<p>Ce rendez-vous est déjà <strong>' . $libelles[$etat['action']] . '</strong> (' . $e($etat['date']) . ').</p>' : '';
    if ($etat['action'] === $action) { page_decision('Rendez-vous déjà ' . $libelles[$action], $fiche . $deja); }
    $bouton = $action === 'valider' ? 'Valider le rendez-vous' : 'Annuler le rendez-vous';
    $couleur = $action === 'valider' ? '#11352F' : '#8C2F2F';
    $choix = $action === 'annuler'
        ? '<p><label><input type="checkbox" name="prevenir" value="1" checked> Prévenir cette personne par courriel que le rendez-vous n’est pas validé</label></p>'
        : '<p>Un accusé de réception de la validation part aussitôt à ' . $e($d['email'] ?? '') . '.</p>';
    page_decision($bouton . ' ?', $fiche . $deja . '<form method="post" action="/api/rdv-decision.php">'
        . '<input type="hidden" name="ref" value="' . $e($ref) . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="sig" value="' . $e($v('sig')) . '">'
        . $choix . '<p><button type="submit" style="font:inherit;padding:12px 22px;border:0;border-radius:6px;color:#fff;background:' . $couleur . '">' . $bouton . '</button></p></form>');
}

if ($etat['action'] === $action) { page_decision('Rendez-vous déjà ' . $libelles[$action], $fiche . '<p>Rien de nouveau n’a été envoyé.</p>'); }
$etat['historique'][] = ['action' => $action, 'date' => date('c')];
$etat['action'] = $action;
$etat['date']   = date('d/m/Y à H:i');
@file_put_contents($dossier . '/decision.json', json_encode($etat, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

$email = (string)($d['email'] ?? '');
$signature = "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";
$envoye = false;
if ($action === 'valider') {
    if (email_valide($email)) {
        $envoye = envoyer_mail($email, "Votre rendez-vous est validé ($ref)", "Bonjour,\n\nLe cabinet a validé votre rendez-vous (référence $ref), à la date et à l'heure de la confirmation que vous avez reçue.\n\n"
            . (($d['lien'] ?? '') !== '' ? "Si vous n'avez pas encore choisi votre créneau :\n$d[lien]\n\n" : '') . $signature);
    }
    journal('rdv-decision', 'valide');
    page_decision('Rendez-vous validé', $fiche . '<p>' . ($envoye ? 'L’accusé de réception est parti à ' . $e($email) . '.' : 'L’accusé de réception n’a pas pu partir : prévenez le client directement.') . '</p>');
}

if ($v('prevenir') === '1' && email_valide($email)) {
    $envoye = envoyer_mail($email, "Votre demande de rendez-vous n'est pas validée ($ref)", "Bonjour,\n\nLe cabinet n'a pas validé votre demande de rendez-vous (référence $ref). Le rendez-vous n'aura pas lieu.\n\n"
        . "Si vous avez un dossier en cours au cabinet, appelez le 02 30 96 33 35.\n\n" . $signature);
}
envoyer_mail($CONFIG['cabinet_email'], '[SITE] Rendez-vous annulé, créneau à supprimer — ' . ($d['nom'] ?? '') . " ($ref)",
    "Le rendez-vous de " . ($d['nom'] ?? '') . " <$email> (référence $ref) est annulé par le cabinet.\n\n"
    . "Reste à faire : supprimer le créneau dans l'agenda Outlook (la suppression envoie l'annulation Bookings). Le site n'a pas accès à l'agenda.\n"
    . 'Personne prévenue par le site : ' . ($envoye ? 'oui' : 'non') . ".\n");
journal('rdv-decision', 'annule');
page_decision('Rendez-vous annulé', $fiche . '<p>' . ($envoye ? 'La personne est prévenue par courriel.' : 'La personne n’a pas été prévenue par le site.')
    . '</p><p><strong>Reste à faire :</strong> supprimer le créneau dans votre agenda Outlook. Un rappel vous est envoyé.</p>');
