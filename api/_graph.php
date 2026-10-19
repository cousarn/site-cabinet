<?php
/**
 * Cabinet Arnaud Cousin — relais Microsoft Graph vers SharePoint.
 *
 * Une application Entra (inscription d'application) avec la seule permission d'application `Sites.Selected`,
 * accordée en écriture sur le seul site SharePoint « Dépôts clients » : le serveur OVH ne peut rien lire ni
 * écrire d'autre dans le Microsoft 365 du cabinet. Identifiants dans /config/site-cabinet.php (clé `graph`),
 * jamais dans le dépôt Git.
 */
declare(strict_types=1);

/** Le relais est-il configuré ? (sinon les pièces restent sur le serveur, récupérables depuis la console) */
function graph_configure(): bool {
    global $CONFIG;
    $g = $CONFIG['graph'] ?? [];
    return !empty($g['tenant']) && !empty($g['client_id']) && !empty($g['client_secret']) && (!empty($g['drive_id']) || !empty($g['site']));
}

/**
 * Identifiant de la bibliothèque cible : `drive_id` s'il est donné, sinon résolu depuis `site`
 * (ex. `cabinetcousin.sharepoint.com:/sites/DepotsClients` → bibliothèque de documents par défaut) et gardé en cache.
 */
function graph_drive(): string {
    global $CONFIG;
    $g = $CONFIG['graph'];
    if (!empty($g['drive_id'])) { return (string)$g['drive_id']; }
    $cache = DOSSIER_CACHE . '/graph-drive.json';
    if (is_file($cache)) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j) && ($j['site'] ?? '') === $g['site'] && !empty($j['drive'])) { return (string)$j['drive']; }
    }
    [$code, $s] = graph_appel('GET', '/sites/' . str_replace(' ', '%20', (string)$g['site']) . '?$select=id,webUrl');
    if ($code !== 200 || empty($s['id'])) { throw new RuntimeException('Site SharePoint introuvable ' . graph_message($s, $code)); }
    [$code, $d] = graph_appel('GET', '/sites/' . rawurlencode((string)$s['id']) . '/drive?$select=id,name,webUrl');
    if ($code !== 200 || empty($d['id'])) { throw new RuntimeException('Bibliothèque du site introuvable ' . graph_message($d, $code)); }
    if (!is_dir(DOSSIER_CACHE)) { @mkdir(DOSSIER_CACHE, 0700, true); }
    @file_put_contents($cache, json_encode(['site' => $g['site'], 'site_id' => $s['id'], 'drive' => $d['id'], 'nom' => $d['name'] ?? '', 'url' => $d['webUrl'] ?? '']), LOCK_EX);
    return (string)$d['id'];
}

/** Jeton applicatif (client credentials), gardé en cache hors racine web jusqu'à cinq minutes avant expiration. */
function graph_jeton(): string {
    global $CONFIG;
    $g = $CONFIG['graph'];
    $cache = DOSSIER_CACHE . '/graph-jeton.json';
    if (is_file($cache)) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j) && ($j['expire'] ?? 0) > time() + 300 && ($j['client'] ?? '') === $g['client_id'] && !empty($j['jeton'])) {
            return (string)$j['jeton'];
        }
    }
    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode((string)$g['tenant']) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $g['client_id'], 'client_secret' => $g['client_secret'],
            'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
        ]),
    ]);
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $j = is_string($rep) ? json_decode($rep, true) : null;
    if ($code !== 200 || empty($j['access_token'])) {
        throw new RuntimeException('Jeton Microsoft refusé (' . $code . ') : ' . (is_array($j) ? ($j['error_description'] ?? $j['error'] ?? '') : $err));
    }
    if (!is_dir(DOSSIER_CACHE)) { @mkdir(DOSSIER_CACHE, 0700, true); }
    @file_put_contents($cache, json_encode(['jeton' => $j['access_token'], 'expire' => time() + (int)($j['expires_in'] ?? 3600), 'client' => $g['client_id']]), LOCK_EX);
    @chmod($cache, 0600);
    return (string)$j['access_token'];
}

/** Appel Graph. $corps : tableau (envoyé en JSON) ou chaîne brute. Renvoie [code HTTP, réponse décodée ou null]. */
function graph_appel(string $methode, string $url, $corps = null, array $entetes = [], bool $auth = true): array {
    $ch = curl_init(str_starts_with($url, 'https://') ? $url : 'https://graph.microsoft.com/v1.0' . $url);
    $h = $entetes;
    if ($auth) { $h[] = 'Authorization: Bearer ' . graph_jeton(); }
    if (is_array($corps)) { $corps = json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $h[] = 'Content-Type: application/json'; }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => $h]);
    if ($corps !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $corps); }
    $rep = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($rep === false) { throw new RuntimeException('Microsoft Graph injoignable : ' . $err); }
    return [$code, json_decode((string)$rep, true)];
}

/** Message d'erreur Graph lisible. */
function graph_message(?array $j, int $code): string {
    return '(' . $code . ') ' . (string)($j['error']['message'] ?? $j['error_description'] ?? '');
}

/** Chemin encodé pour une adresse `root:/…` (chaque segment encodé séparément). */
function graph_chemin(string $chemin): string {
    return implode('/', array_map('rawurlencode', array_values(array_filter(explode('/', $chemin), fn($s) => $s !== ''))));
}

/** Crée au besoin le dossier $chemin (relatif à la racine de la bibliothèque) et renvoie [id, webUrl]. */
function graph_dossier(string $chemin): array {
    global $CONFIG;
    $drive = graph_drive();
    [$code, $j] = graph_appel('GET', "/drives/$drive/root:/" . graph_chemin($chemin) . '?$select=id,webUrl');
    if ($code === 200 && !empty($j['id'])) { return [(string)$j['id'], (string)($j['webUrl'] ?? '')]; }
    $parent = ''; $url = ''; $courant = '';
    foreach (array_filter(explode('/', $chemin), fn($s) => $s !== '') as $segment) {
        $courant = $courant === '' ? $segment : $courant . '/' . $segment;
        [$c, $r] = graph_appel('GET', "/drives/$drive/root:/" . graph_chemin($courant) . '?$select=id,webUrl');
        if ($c !== 200 || empty($r['id'])) {
            $cible = $parent === '' ? "/drives/$drive/root/children" : "/drives/$drive/items/$parent/children";
            [$c, $r] = graph_appel('POST', $cible, ['name' => $segment, 'folder' => new stdClass(), '@microsoft.graph.conflictBehavior' => 'fail']);
            if ($c === 409) { [$c, $r] = graph_appel('GET', "/drives/$drive/root:/" . graph_chemin($courant) . '?$select=id,webUrl'); }
            if (!in_array($c, [200, 201], true) || empty($r['id'])) {
                throw new RuntimeException("Dossier SharePoint « $segment » : création refusée " . graph_message($r, $c));
            }
        }
        $parent = (string)$r['id']; $url = (string)($r['webUrl'] ?? '');
    }
    return [$parent, $url];
}

/**
 * Envoie un fichier local sous le nom $nom dans le dossier $chemin de la bibliothèque.
 * ≤ 4 Mo : envoi direct ; au-delà : session d'envoi par fragments de 5 Mio (multiple de 320 Kio). Renvoie [id, webUrl].
 */
function graph_envoyer(string $local, string $chemin, string $nom): array {
    global $CONFIG;
    $drive = graph_drive();
    $taille = (int)filesize($local);
    $item = "/drives/$drive/root:/" . graph_chemin($chemin) . '/' . rawurlencode($nom);
    if ($taille <= 4 * 1024 * 1024) {
        [$code, $j] = graph_appel('PUT', $item . ':/content?@microsoft.graph.conflictBehavior=rename', (string)file_get_contents($local), ['Content-Type: application/octet-stream']);
        if (!in_array($code, [200, 201], true) || empty($j['id'])) { throw new RuntimeException('Envoi SharePoint refusé ' . graph_message($j, $code)); }
        return [(string)$j['id'], (string)($j['webUrl'] ?? '')];
    }
    [$code, $j] = graph_appel('POST', $item . ':/createUploadSession', ['item' => ['@microsoft.graph.conflictBehavior' => 'rename', 'name' => $nom]]);
    if ($code !== 200 || empty($j['uploadUrl'])) { throw new RuntimeException('Session d’envoi SharePoint refusée ' . graph_message($j, $code)); }
    $session = (string)$j['uploadUrl'];
    $fragment = 5 * 1024 * 1024;
    $fh = fopen($local, 'rb');
    $debut = 0; $reponse = null;
    while ($debut < $taille) {
        $bloc = (string)fread($fh, $fragment);
        $fin = $debut + strlen($bloc) - 1;
        $essai = 0; $ok = false;
        do {
            // L'adresse de session est pré-authentifiée : pas d'en-tête Authorization (sinon 401).
            [$code, $reponse] = graph_appel('PUT', $session, $bloc, ["Content-Range: bytes $debut-$fin/$taille", 'Content-Type: application/octet-stream'], false);
            $ok = in_array($code, [200, 201, 202], true);
            if (!$ok) { $essai++; usleep(400000 * $essai); }
        } while (!$ok && $essai < 3);
        if (!$ok) {
            fclose($fh);
            try { graph_appel('DELETE', $session, null, [], false); } catch (\Throwable $e) { /* sans importance */ }
            throw new RuntimeException('Fragment SharePoint refusé ' . graph_message($reponse, $code));
        }
        $debut = $fin + 1;
    }
    fclose($fh);
    return [(string)($reponse['id'] ?? ''), (string)($reponse['webUrl'] ?? '')];
}

/** Vérifie l'accès à la bibliothèque : renvoie ['ok' => bool, 'nom' => …, 'url' => …] ou ['ok' => false, 'erreur' => …]. */
function graph_tester(): array {
    global $CONFIG;
    if (!graph_configure()) { return ['ok' => false, 'erreur' => 'Relais non configuré (clé graph de la configuration serveur).']; }
    try {
        [$code, $j] = graph_appel('GET', '/drives/' . graph_drive() . '?$select=id,name,webUrl');
        if ($code !== 200) { return ['ok' => false, 'erreur' => 'Bibliothèque inaccessible ' . graph_message($j, $code)]; }
        [, $url] = graph_dossier((string)$CONFIG['graph']['racine']);
        return ['ok' => true, 'nom' => (string)($j['name'] ?? ''), 'url' => $url ?: (string)($j['webUrl'] ?? '')];
    } catch (\Throwable $e) {
        return ['ok' => false, 'erreur' => $e->getMessage()];
    }
}
