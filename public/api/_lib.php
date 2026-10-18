<?php
/**
 * Cabinet Arnaud Cousin — bibliothèque commune des points d'entrée PHP (OVH mutualisé, PHP ≥ 8.1).
 * Aucun secret ici : les réglages sensibles vont dans api/config.local.php (non versionné, créé sur le serveur).
 */
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');

const SITE_URL = 'https://arnaudcousin-avocat.fr';

/** Réglages par défaut — surchargés par config.local.php si présent. */
$CONFIG = [
    'cabinet_email'   => 'cabinet@arnaudcousin-avocat.fr', // destinataire des formulaires
    'from_email'      => 'site@arnaudcousin-avocat.fr',    // expéditeur technique (doit exister chez OVH pour passer les filtres)
    'from_name'       => 'Site du cabinet Arnaud Cousin',
    'stockage'        => dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/..') . '/consultations', // hors racine web
    'rate_par_heure'  => 6,     // envois max par IP et par formulaire
    'taille_max_mo'   => 20,    // pièces jointes cumulées
    'paiement'        => 'virement', // 'virement' | 'stripe' (phase 2)
    'rib'             => '[RIB du cabinet — IBAN à insérer dans config.local.php]',
    'devis'           => [ // forfaits divorce par consentement mutuel, TTC — [grille à valider par Me Cousin]
        'base'            => 960,
        'enfant'          => 240,   // par situation « un » ; « deux ou plus » = ×1.5
        'immobilier_un'   => 360,
        'immobilier_plus' => 720,
        'entreprise_un'   => 600,
        'entreprise_deux' => 900,
        'desaccord_partiel' => 240,
    ],
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) { $CONFIG = array_replace_recursive($CONFIG, $local); }
}

/** Méthode POST obligatoire. */
function exiger_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST'); http_response_code(405); exit('Méthode non autorisée.');
    }
}

/** Nettoie une chaîne de formulaire (une ligne). */
function champ(string $nom, int $max = 300): string {
    $v = $_POST[$nom] ?? '';
    if (!is_string($v)) return '';
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);
    return mb_substr(trim($v), 0, $max);
}

/** Nettoie un texte multi-lignes. */
function texte(string $nom, int $max = 8000): string {
    $v = $_POST[$nom] ?? '';
    if (!is_string($v)) return '';
    $v = str_replace("\0", '', $v);
    return mb_substr(trim($v), 0, $max);
}

function email_valide(string $e): bool {
    return $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e);
}

/** Pot de miel : le champ « site » doit rester vide (rempli par les robots). */
function honeypot_ok(): bool {
    return trim((string)($_POST['site'] ?? '')) === '';
}

/** Limite de débit par IP et par formulaire, fichiers dans api/.cache (interdit en HTTP par .htaccess). */
function limiter_debit(string $formulaire): void {
    global $CONFIG;
    $dir = __DIR__ . '/.cache';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $f   = $dir . '/' . $formulaire . '-' . hash('sha256', $ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
    if (count($hits) >= (int)$CONFIG['rate_par_heure']) {
        http_response_code(429);
        exit('Trop de demandes depuis votre connexion. Réessayez dans une heure ou appelez le cabinet.');
    }
    $hits[] = $now;
    @file_put_contents($f, json_encode($hits), LOCK_EX);
    // ménage occasionnel
    if (random_int(1, 50) === 1) {
        foreach (glob($dir . '/*.json') ?: [] as $g) { if (filemtime($g) < $now - 7200) @unlink($g); }
    }
}

/** Envoi d'un courriel texte UTF-8 (mail() d'OVH). */
function envoyer_mail(string $a, string $sujet, string $corps, ?string $repondreA = null): bool {
    global $CONFIG;
    $entetes = [
        'From: ' . mb_encode_mimeheader($CONFIG['from_name'], 'UTF-8') . ' <' . $CONFIG['from_email'] . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: site-cabinet',
    ];
    if ($repondreA && email_valide($repondreA)) { $entetes[] = 'Reply-To: ' . $repondreA; }
    $sujetEnc = mb_encode_mimeheader($sujet, 'UTF-8');
    return mail($a, $sujetEnc, wordwrap($corps, 900, "\n", true), implode("\r\n", $entetes), '-f' . $CONFIG['from_email']);
}

/** Journal minimal (sans contenu personnel) pour le suivi. */
function journal(string $formulaire, string $etat): void {
    $dir = __DIR__ . '/.cache';
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    @file_put_contents($dir . '/journal.log', date('c') . " $formulaire $etat\n", FILE_APPEND | LOCK_EX);
}

/** Redirection vers la page de remerciement. */
function merci(string $objet, array $params = []): never {
    $q = http_build_query(['objet' => $objet] + $params);
    header('Location: /merci?' . $q, true, 303);
    exit;
}

/** Erreur lisible, sans fuite technique. */
function erreur(string $message, int $code = 400): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang=\"fr\"><meta charset=\"utf-8\"><title>Formulaire</title><body style=\"font-family:system-ui;max-width:40em;margin:4em auto;line-height:1.5\"><h1 style=\"font-weight:500\">Le formulaire n'a pas pu être envoyé</h1><p>$m</p><p><a href=\"javascript:history.back()\">Revenir au formulaire</a> · <a href=\"/contact\">Contact</a></p></body></html>";
    exit;
}

/** Identifiant court lisible pour les accusés de réception (AAMMJJ-XXXX). */
function reference(): string {
    return date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
}

/** Enregistre des pièces jointes (champ pieces[]) dans un dossier hors racine web ; renvoie la liste des fichiers acceptés. */
function enregistrer_pieces(string $champ, string $sousDossier): array {
    global $CONFIG;
    if (empty($_FILES[$champ]) || !is_array($_FILES[$champ]['name'])) return [];
    $autorises = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/heic' => 'heic', 'image/webp' => 'webp',
                  'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/msword' => 'doc'];
    $dossier = rtrim($CONFIG['stockage'], '/') . '/' . $sousDossier;
    if (!is_dir($dossier) && !@mkdir($dossier, 0700, true)) return [];
    $total = 0; $ok = [];
    $fi = new finfo(FILEINFO_MIME_TYPE);
    foreach ($_FILES[$champ]['name'] as $i => $nom) {
        if (($_FILES[$champ]['error'][$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $tmp = $_FILES[$champ]['tmp_name'][$i];
        $taille = (int)$_FILES[$champ]['size'][$i];
        $total += $taille;
        if ($total > (int)$CONFIG['taille_max_mo'] * 1024 * 1024) break;
        $mime = $fi->file($tmp) ?: '';
        if (!isset($autorises[$mime])) continue;
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo((string)$nom, PATHINFO_FILENAME)) ?: 'piece';
        $cible = $dossier . '/' . sprintf('%02d', $i + 1) . '_' . mb_substr($base, 0, 60) . '.' . $autorises[$mime];
        if (move_uploaded_file($tmp, $cible)) { $ok[] = basename($cible) . ' (' . round($taille / 1024) . ' Ko)'; }
    }
    return $ok;
}
