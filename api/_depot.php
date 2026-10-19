<?php
/**
 * Cabinet Arnaud Cousin — dépôt de pièces par lien personnel : fonctions communes à l'API client (depot.php)
 * et à la console du cabinet (depot-admin.php).
 *
 * Un dépôt = un dossier hors racine web `depots/<id>/` contenant `depot.json` (manifeste), les pièces reçues
 * (`01 - nom.pdf`, `02 - …`) et `.part/` (morceaux en cours). L'identifiant est dérivé du jeton par hachage ;
 * le jeton lui-même (32 caractères aléatoires) ne circule que dans le lien remis au client.
 * Chaque pièce complète est relayée vers SharePoint (_graph.php) ; à défaut de relais, elle reste sur le serveur,
 * récupérable depuis la console (zip).
 */
declare(strict_types=1);
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_graph.php';

/** Types acceptés : type MIME détecté sur le fichier complet → extension normalisée. */
const DEPOT_TYPES = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/heic' => 'heic', 'image/heif' => 'heic', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/tiff' => 'tif', 'image/bmp' => 'bmp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.oasis.opendocument.text' => 'odt', 'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
    'text/plain' => 'txt', 'text/rtf' => 'rtf', 'application/rtf' => 'rtf', 'text/csv' => 'csv',
    'message/rfc822' => 'eml', 'application/vnd.ms-outlook' => 'msg',
    'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/ogg' => 'ogg', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/3gpp' => '3gp',
    'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/3gpp' => '3gp', 'video/x-msvideo' => 'avi',
    'application/zip' => 'zip',
];
/** Extensions annoncées acceptables (contrôle rapide dès le premier morceau ; le type réel est vérifié sur le fichier complet). */
const DEPOT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'gif', 'tif', 'tiff', 'bmp', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'odt', 'ods', 'txt', 'rtf', 'csv', 'eml', 'msg', 'mp3', 'm4a', 'ogg', 'wav', 'aac', 'amr', '3gp', 'mp4', 'mov', 'avi', 'zip'];
/** Contenu refusé dans une archive zip. */
const DEPOT_ZIP_INTERDITS = ['exe', 'bat', 'cmd', 'com', 'scr', 'pif', 'msi', 'dll', 'js', 'jse', 'vbs', 'vbe', 'wsf', 'ps1', 'psm1', 'jar', 'lnk', 'hta', 'reg', 'iso', 'img', 'vhd', 'app', 'sh'];

const DEPOT_MORCEAU = 4 * 1024 * 1024; // taille des morceaux envoyés par la page (indépendante de upload_max_filesize)

function depot_reglage(string $cle) { global $CONFIG; return $CONFIG['depot'][$cle] ?? null; }

function depot_racine(): string {
    $d = rtrim((string)depot_reglage('dossier'), '/');
    if (!is_dir($d)) { @mkdir($d, 0700, true); }
    return $d;
}
function depot_chemin(string $id): string { return depot_racine() . '/' . $id; }

/** Jeton : 24 octets aléatoires en base64url = 32 caractères [A-Za-z0-9_-]. */
function depot_jeton_nouveau(): string { return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '='); }
function depot_jeton_format_ok(string $j): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{32}$/', $j); }
function depot_id(string $jeton): string { return substr(hash('sha256', 'depot|' . $jeton), 0, 24); }
/** Lien personnel du client. Sur la préproduction, le lien reste sur la préproduction (essais) ; partout ailleurs, le domaine officiel. */
function depot_lien(string $jeton): string {
    $hote = (string)($_SERVER['HTTP_HOST'] ?? '');
    $base = (str_starts_with($hote, 'preprod.') && preg_match('/^[a-z0-9.-]+$/', $hote)) ? 'https://' . $hote : SITE_URL;
    return $base . '/depot/' . $jeton;
}

function depot_lire(string $id): ?array {
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) { return null; }
    $f = depot_chemin($id) . '/depot.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function depot_ecrire(array $d): void {
    $dossier = depot_chemin($d['id']);
    if (!is_dir($dossier)) { @mkdir($dossier, 0700, true); }
    file_put_contents($dossier . '/depot.json', json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}
/** Lecture-modification-écriture du manifeste sous verrou ; $fn(array &$d) peut renvoyer une valeur. */
function depot_modifier(string $id, callable $fn) {
    $dossier = depot_chemin($id);
    if (!is_dir($dossier)) { throw new RuntimeException('Dépôt introuvable.'); }
    $verrou = fopen($dossier . '/.verrou', 'c');
    flock($verrou, LOCK_EX);
    try {
        $d = depot_lire($id);
        if (!$d) { throw new RuntimeException('Dépôt introuvable.'); }
        $r = $fn($d);
        depot_ecrire($d);
        return $r;
    } finally {
        flock($verrou, LOCK_UN);
        fclose($verrou);
    }
}
/** Dépôt désigné par un jeton (lien client), ou null. */
function depot_par_jeton(string $jeton): ?array {
    if (!depot_jeton_format_ok($jeton)) { return null; }
    $d = depot_lire(depot_id($jeton));
    if (!$d || !hash_equals((string)($d['jeton'] ?? ''), $jeton)) { return null; }
    return $d;
}
/** 'ouvert' | 'expire' | 'clos' */
function depot_statut(array $d): string {
    if (!empty($d['clos'])) { return 'clos'; }
    return strtotime($d['expire'] . ' 23:59:59') < time() ? 'expire' : 'ouvert';
}
/** Tous les dépôts (console), du plus récent au plus ancien. */
function depot_tous(): array {
    $liste = [];
    foreach (glob(depot_racine() . '/*/depot.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) { $liste[] = $d; }
    }
    usort($liste, fn($a, $b) => strcmp($b['cree'], $a['cree']));
    return $liste;
}

/** Nom sûr pour le disque et SharePoint : accents conservés, caractères interdits retirés, longueur bornée. */
function depot_nom_sur(string $nom, int $max = 80): string {
    $nom = preg_replace('/[\x00-\x1F"*:<>?\/\\\\|#%~{}]+/u', ' ', $nom) ?? '';
    $nom = preg_replace('/\s+/u', ' ', $nom) ?? '';
    $nom = trim($nom, " .");
    $nom = str_replace('_vti_', 'vti', $nom);
    if ($nom === '') { $nom = 'piece'; }
    return rtrim(mb_substr($nom, 0, $max), ' .');
}
function depot_taille_lisible(int $octets): string {
    if ($octets >= 1048576) { return number_format($octets / 1048576, 1, ',', ' ') . ' Mo'; }
    return max(1, (int)round($octets / 1024)) . ' Ko';
}
function depot_date_fr(string $iso): string {
    $t = strtotime($iso);
    return $t ? date('d/m/Y', $t) : $iso;
}
function depot_total(array $d): int { return array_sum(array_map(fn($f) => (int)$f['taille'], $d['fichiers'] ?? [])); }

/** Chemin SharePoint du dépôt (relatif à la racine de la bibliothèque). */
function depot_chemin_sharepoint(array $d): string {
    global $CONFIG;
    return trim((string)$CONFIG['graph']['racine'], '/') . '/' . $d['sharepoint_dossier'];
}

/**
 * Type réel d'un fichier complet → extension normalisée, ou null s'il est refusé.
 * Le type MIME est lu sur le contenu (finfo) ; quelques cas où la bibliothèque magic est muette sont rattrapés
 * (HEIC, documents Office ou OpenDocument vus comme de simples zip, .msg vu comme conteneur OLE).
 */
function depot_type(string $chemin, string $nomOriginal): ?string {
    $extOrig = strtolower(pathinfo($nomOriginal, PATHINFO_EXTENSION));
    if (!in_array($extOrig, DEPOT_EXTENSIONS, true)) { return null; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($chemin) ?: '';
    $tete = (string)file_get_contents($chemin, false, null, 0, 16);
    if (substr($tete, 4, 4) === 'ftyp' && in_array(substr($tete, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1', 'heim', 'heis'], true)) { return 'heic'; }
    if ($mime === 'application/zip' || $mime === 'application/octet-stream' || $mime === 'application/x-zip') {
        if (in_array($extOrig, ['docx', 'xlsx', 'pptx', 'odt', 'ods'], true) && depot_zip_contient($chemin, in_array($extOrig, ['odt', 'ods'], true) ? 'mimetype' : '[Content_Types].xml')) { return $extOrig; }
    }
    if (in_array($mime, ['application/x-ole-storage', 'application/CDFV2', 'application/CDFV2-unknown', 'application/vnd.ms-office'], true)) {
        if (in_array($extOrig, ['msg', 'doc', 'xls', 'ppt'], true)) { return $extOrig; }
    }
    if ($mime === 'application/zip' && $extOrig === 'zip') { return depot_zip_sain($chemin) ? 'zip' : null; }
    if ($mime === 'video/mp4' && in_array($extOrig, ['m4a'], true)) { return 'm4a'; }
    if (isset(DEPOT_TYPES[$mime])) {
        $ext = DEPOT_TYPES[$mime];
        // conserver une extension d'origine équivalente (jpeg, tiff, heif)
        $equiv = ['jpg' => ['jpeg'], 'tif' => ['tiff'], 'heic' => ['heif'], '3gp' => ['3gp']];
        return in_array($extOrig, $equiv[$ext] ?? [], true) ? $extOrig : $ext;
    }
    return null;
}
function depot_zip_contient(string $chemin, string $entree): bool {
    if (!class_exists('ZipArchive')) { return false; }
    $z = new ZipArchive();
    if ($z->open($chemin) !== true) { return false; }
    $ok = $z->locateName($entree) !== false;
    $z->close();
    return $ok;
}
/** Une archive zip est acceptée si elle ne contient aucun exécutable ni script. */
function depot_zip_sain(string $chemin): bool {
    if (!class_exists('ZipArchive')) { return false; }
    $z = new ZipArchive();
    if ($z->open($chemin) !== true) { return false; }
    $sain = true;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = (string)$z->getNameIndex($i);
        $e = strtolower(pathinfo($n, PATHINFO_EXTENSION));
        if (in_array($e, DEPOT_ZIP_INTERDITS, true)) { $sain = false; break; }
    }
    $z->close();
    return $sain;
}

/** Relaie vers SharePoint la pièce $nom du dépôt $id ; met à jour son état dans le manifeste. Renvoie 'ok' | 'erreur' | 'attente'. */
function depot_relayer(string $id, string $nom): string {
    $d = depot_lire($id);
    if (!$d) { return 'erreur'; }
    if (!graph_configure()) { return 'attente'; }
    $local = depot_chemin($id) . '/' . $nom;
    if (!is_file($local)) { return 'erreur'; }
    try {
        [, $urlDossier] = graph_dossier(depot_chemin_sharepoint($d));
        [$itemId, $url] = graph_envoyer($local, depot_chemin_sharepoint($d), $nom);
        depot_modifier($id, function (array &$d) use ($nom, $itemId, $url, $urlDossier) {
            foreach ($d['fichiers'] as &$f) {
                if ($f['nom'] === $nom) { $f['sharepoint'] = ['etat' => 'ok', 'id' => $itemId, 'url' => $url, 'date' => date('c')]; }
            }
            unset($f);
            if ($urlDossier !== '') { $d['sharepoint_url'] = $urlDossier; }
        });
        journal('depot', 'relais-ok');
        return 'ok';
    } catch (\Throwable $e) {
        $message = mb_substr($e->getMessage(), 0, 300);
        depot_modifier($id, function (array &$d) use ($nom, $message) {
            foreach ($d['fichiers'] as &$f) {
                if ($f['nom'] === $nom) {
                    $f['sharepoint'] = ['etat' => 'erreur', 'erreur' => $message, 'date' => date('c'), 'essais' => (int)($f['sharepoint']['essais'] ?? 0) + 1];
                }
            }
            unset($f);
        });
        error_log('site-cabinet dépôt ' . $id . ' : relais SharePoint en échec — ' . $message);
        journal('depot', 'relais-echec');
        return 'erreur';
    }
}

/** Supprime les morceaux abandonnés depuis plus de 24 h. */
function depot_nettoyer_morceaux(string $id): void {
    $part = depot_chemin($id) . '/.part';
    if (!is_dir($part)) { return; }
    foreach (glob($part . '/*') ?: [] as $f) {
        if (filemtime($f) < time() - 86400) { @unlink($f); }
    }
}

/** Supprime un dépôt du serveur (pièces, manifeste, dossier). */
function depot_supprimer(string $id): void {
    $dossier = depot_chemin($id);
    if (!preg_match('/^[a-f0-9]{24}$/', $id) || !is_dir($dossier)) { return; }
    if (is_dir($dossier . '/.part')) {
        foreach (array_diff(scandir($dossier . '/.part') ?: [], ['.', '..']) as $f) { @unlink($dossier . '/.part/' . $f); }
        @rmdir($dossier . '/.part');
    }
    foreach (array_diff(scandir($dossier) ?: [], ['.', '..']) as $f) { if (is_file($dossier . '/' . $f)) { @unlink($dossier . '/' . $f); } }
    @rmdir($dossier);
}

/** Signature du cabinet pour les courriels. */
function depot_signature(): string {
    return "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";
}

/** Courriel au client : son lien personnel. */
function depot_envoyer_lien(array $d): bool {
    $email = (string)($d['client']['email'] ?? '');
    if (!email_valide($email)) { return false; }
    $consigne = trim((string)($d['consigne'] ?? ''));
    $corps = "Bonjour,\n\n"
        . "Pour transmettre vos pièces au cabinet dans le dossier « {$d['dossier']} », utilisez votre lien personnel :\n\n"
        . depot_lien($d['jeton']) . "\n\n"
        . "Il est valable jusqu'au " . depot_date_fr($d['expire']) . ". Depuis un ordinateur ou un téléphone : ouvrez le lien, glissez vos fichiers ou prenez-les en photo, puis cliquez sur « Terminer » pour prévenir le cabinet. Vous pouvez y revenir autant de fois que nécessaire.\n\n"
        . ($consigne !== '' ? "Pièces attendues :\n$consigne\n\n" : '')
        . "Formats acceptés : PDF, photos, documents Word ou Excel, courriels, enregistrements audio ou vidéo — 50 Mo par fichier. Les pièces sont chiffrées en transit et classées directement dans votre dossier au cabinet.\n\n"
        . "Ce lien vous est personnel : ne le transmettez pas. Si vous ne l'avez pas demandé, ignorez ce message.\n\n"
        . depot_signature();
    global $CONFIG;
    return envoyer_mail($email, "Votre lien pour déposer vos pièces — {$d['dossier']}", $corps, $CONFIG['cabinet_email']);
}

/* ───────── Veille quotidienne ─────────
 * Lancée par la tâche planifiée OVH (`php prod/api/depot-veille.php`) et, à défaut, depuis la console si la dernière
 * passe date de plus de 20 h. Elle ne crée rien : elle signale au cabinet ce qui demande attention, reprend les relais
 * SharePoint en échec, prévient des pièces déposées que le client n'a pas « terminées », et fait le ménage. */

function depot_veille_fichier(): string { return DOSSIER_CACHE . '/depot-veille.json'; }
function depot_veille_derniere(): int {
    $f = depot_veille_fichier();
    $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    return (int)($j['derniere'] ?? 0);
}

/** Exécute la veille ; renvoie ['points' => [...lignes], 'mail' => bool envoyé]. */
function depot_veille(bool $envoyerMail = true): array {
    global $CONFIG;
    $points = []; $maintenant = time();
    $delaiSansTerminer = 12 * 3600;   // pièces déposées mais non signalées depuis plus de 12 h
    $relanceApresJours = 5;           // lien envoyé sans aucune pièce depuis 5 jours
    foreach (depot_tous() as $d) {
        $id = $d['id']; $statut = depot_statut($d); $etiquette = $d['dossier'] . ' — ' . $d['client']['nom'];
        depot_nettoyer_morceaux($id);
        // 1. Relais SharePoint à reprendre
        $aRelayer = array_filter($d['fichiers'], fn($f) => ($f['sharepoint']['etat'] ?? '') !== 'ok');
        if ($aRelayer && graph_configure()) {
            $ok = 0; $ko = 0;
            foreach ($aRelayer as $f) { depot_relayer($id, $f['nom']) === 'ok' ? $ok++ : $ko++; }
            $points[] = "RELAIS SharePoint — $etiquette : " . count($aRelayer) . ' pièce(s) reprises, ' . ($ko ? "$ko toujours en échec (voir la console, télécharger le zip)" : 'toutes classées');
            $d = depot_lire($id) ?? $d;
        } elseif ($aRelayer && !graph_configure()) {
            $points[] = "RELAIS non configuré — $etiquette : " . count($aRelayer) . ' pièce(s) sur le serveur seulement';
        }
        // 2. Pièces déposées mais non « terminées » par le client : on prévient le cabinet à sa place
        $dernier = $d['depots'] ? end($d['depots'])['date'] : '';
        $nonSignalees = array_values(array_filter($d['fichiers'], fn($f) => $f['date'] > $dernier && strtotime($f['date']) < $maintenant - $delaiSansTerminer));
        if ($nonSignalees) {
            $liste = implode("\n", array_map(fn($f) => '    - ' . $f['original'] . ' (' . depot_taille_lisible((int)$f['taille']) . ', ' . depot_date_fr($f['date']) . ')', $nonSignalees));
            $points[] = "PIÈCES NON SIGNALÉES — $etiquette : " . count($nonSignalees) . " pièce(s) déposée(s) sans clic « Terminer » :\n$liste\n    " . (!empty($d['sharepoint_url']) ? 'SharePoint : ' . $d['sharepoint_url'] : 'Console : ' . SITE_URL . '/admin/depots');
            depot_modifier($id, function (array &$x) use ($nonSignalees) {
                $x['depots'][] = ['date' => date('c'), 'message' => '(signalées au cabinet par la veille automatique)', 'fichiers' => array_map(fn($f) => $f['nom'], $nonSignalees), 'veille' => true];
            });
        }
        // 3. Lien envoyé, aucune pièce, depuis plusieurs jours
        if ($statut === 'ouvert' && $d['fichiers'] === []) {
            $depuis = strtotime($d['lien_envoye'] ?? $d['cree']);
            $age = (int)floor(($maintenant - $depuis) / 86400);
            $resteJours = (int)ceil((strtotime($d['expire'] . ' 23:59:59') - $maintenant) / 86400);
            if ($age >= $relanceApresJours && ($age % $relanceApresJours === 0 || $resteJours <= 3)) {
                $points[] = "SANS DÉPÔT — $etiquette : lien " . ($d['lien_envoye'] ? 'envoyé' : 'créé') . " il y a $age jours, aucune pièce ; expire dans $resteJours jour(s)" . ($d['client']['email'] ? " — relance possible depuis la console (« Renvoyer le lien »)" : '');
            }
        }
        // 4. Lien qui expire bientôt avec des pièces reçues mais toujours ouvert
        if ($statut === 'ouvert' && $d['fichiers'] !== []) {
            $resteJours = (int)ceil((strtotime($d['expire'] . ' 23:59:59') - $maintenant) / 86400);
            if ($resteJours === 3) { $points[] = "ÉCHÉANCE — $etiquette : le lien expire dans 3 jours (" . count($d['fichiers']) . ' pièce(s) reçues) — prolonger ou clore'; }
        }
    }
    $envoye = false;
    if ($points && $envoyerMail) {
        $corps = "Veille quotidienne du dépôt de pièces — " . date('d/m/Y H:i') . "\n\n" . implode("\n\n", array_map(fn($p) => '• ' . $p, $points))
            . "\n\nConsole : " . SITE_URL . "/admin/depots\n";
        $envoye = envoyer_mail($CONFIG['cabinet_email'], '[SITE] Veille dépôts — ' . count($points) . ' point(s)', $corps);
    }
    if (!is_dir(DOSSIER_CACHE)) { @mkdir(DOSSIER_CACHE, 0700, true); }
    @file_put_contents(depot_veille_fichier(), json_encode(['derniere' => $maintenant, 'points' => count($points), 'mail' => $envoye]), LOCK_EX);
    journal('depot-veille', count($points) . ($envoye ? '-mail' : ''));
    return ['points' => $points, 'mail' => $envoye];
}
