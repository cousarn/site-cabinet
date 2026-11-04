<?php
/**
 * Cabinet Arnaud Cousin — mesure d'audience interne, sans cookie et sans tiers.
 *
 * Principe : chaque page vue envoie un signal à api/mesure.php, qui écrit une ligne
 * dans un journal quotidien hors racine web. Aucun cookie, aucune adresse IP conservée :
 * la visite est identifiée par une empreinte sha256(sel du jour + IP + navigateur),
 * tronquée à 8 caractères. Le sel est tiré au hasard chaque jour et le précédent est
 * effacé : deux jours ne peuvent pas être recoupés, même par l'éditeur.
 *
 * Cette conception suit les critères de la CNIL pour une mesure d'audience exemptée
 * de consentement (finalité limitée au suivi du site pour le seul éditeur, pas de
 * recoupement, pas de transmission à un tiers, conservation limitée) : aucun bandeau
 * n'est donc nécessaire. Voir ADMINISTRATION.md § 8.7.
 */
declare(strict_types=1);

/** Conservation des journaux quotidiens (la CNIL recommande 13 mois au plus). */
const MESURE_CONSERVATION_JOURS = 400;

/** Dossier des journaux, hors racine web. La préproduction écrit à part pour ne pas polluer les chiffres. */
function mesure_dossier(bool $preprod = false): string
{
    $base = DOSSIER_CACHE . '/mesure';
    return $preprod ? $base . '/preprod' : $base;
}

/** Vrai si la requête vient de la préproduction. */
function mesure_est_preprod(): bool
{
    return str_starts_with((string)($_SERVER['HTTP_HOST'] ?? ''), 'preprod.');
}

/**
 * Sel du jour, tiré au hasard et renouvelé à chaque date.
 * Le fichier ne contient que le sel courant : celui de la veille est écrasé.
 */
function mesure_sel(string $dossier, string $jour): string
{
    $f = $dossier . '/sel.json';
    if (is_file($f)) {
        $v = json_decode((string)@file_get_contents($f), true);
        if (is_array($v) && ($v['jour'] ?? '') === $jour && is_string($v['sel'] ?? null) && $v['sel'] !== '') {
            return $v['sel'];
        }
    }
    $sel = bin2hex(random_bytes(16));
    @file_put_contents($f, json_encode(['jour' => $jour, 'sel' => $sel]), LOCK_EX);
    @chmod($f, 0600);
    return $sel;
}

/** Robots connus et navigateurs sans tête : rien n'est enregistré. */
function mesure_est_robot(string $ua): bool
{
    if ($ua === '') { return true; }
    $motifs = ['bot', 'crawl', 'spider', 'slurp', 'curl', 'wget', 'python-requests', 'headless',
               'lighthouse', 'pagespeed', 'preview', 'monitor', 'uptime', 'facebookexternalhit',
               'embedly', 'quora link', 'whatsapp', 'telegram', 'semrush', 'ahrefs', 'mj12', 'dotbot'];
    $u = strtolower($ua);
    foreach ($motifs as $m) { if (str_contains($u, $m)) { return true; } }
    return false;
}

/** Famille d'appareil déduite du navigateur : m (mobile), t (tablette), o (ordinateur). */
function mesure_appareil(string $ua): string
{
    $u = strtolower($ua);
    if (str_contains($u, 'ipad') || (str_contains($u, 'android') && !str_contains($u, 'mobile')) || str_contains($u, 'tablet')) { return 't'; }
    if (str_contains($u, 'mobi') || str_contains($u, 'iphone') || str_contains($u, 'android')) { return 'm'; }
    return 'o';
}

/**
 * Source de la visite : un nom court, jamais l'URL complète du référent.
 * utm_source prime ; sinon le domaine du référent ; « direct » si rien.
 */
function mesure_source(string $referent, string $utm): string
{
    $utm = preg_replace('/[^a-z0-9._-]/', '', strtolower($utm)) ?? '';
    if ($utm !== '') { return substr($utm, 0, 40); }
    if ($referent === '') { return 'direct'; }
    $hote = strtolower((string)parse_url($referent, PHP_URL_HOST));
    if ($hote === '') { return 'direct'; }
    if (str_starts_with($hote, 'www.')) { $hote = substr($hote, 4); }
    $propre = (string)parse_url(SITE_URL, PHP_URL_HOST);
    if ($hote === $propre || $hote === 'www.' . $propre || str_ends_with($hote, '.' . $propre)) { return 'interne'; }
    // Regroupement des grands moteurs et réseaux, dont les sous-domaines sont innombrables.
    foreach (['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
              'ecosia' => 'ecosia', 'qwant' => 'qwant', 'linkedin' => 'linkedin', 'facebook' => 'facebook',
              'instagram' => 'instagram', 'avocat.fr' => 'avocat.fr', 'pagesjaunes' => 'pagesjaunes',
              'justifit' => 'justifit', 'alexia' => 'alexia', 'doctrine' => 'doctrine',
              'legifrance' => 'legifrance', 'chatgpt' => 'chatgpt', 'perplexity' => 'perplexity',
              'claude' => 'claude', 'openai' => 'chatgpt'] as $aiguille => $nom) {
        if (str_contains($hote, $aiguille)) { return $nom; }
    }
    return substr($hote, 0, 60);
}

/** Chemin propre : sans paramètres, sans ancre, limité à 120 caractères. */
function mesure_chemin(string $p): string
{
    $p = (string)parse_url($p, PHP_URL_PATH);
    if ($p === '' || $p[0] !== '/') { return ''; }
    $p = preg_replace('#/{2,}#', '/', $p) ?? $p;
    // Les liens de dépôt sont personnels : on ne garde que la forme générique.
    if (preg_match('#^/depot/#', $p)) { $p = '/depot/'; }
    if (strlen($p) > 120) { $p = substr($p, 0, 120); }
    return $p;
}

/** Écrit une page vue dans le journal du jour. */
function mesure_enregistrer(string $chemin, string $referent, string $utm): void
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (mesure_est_robot($ua)) { return; }
    $chemin = mesure_chemin($chemin);
    if ($chemin === '') { return; }

    $dossier = mesure_dossier(mesure_est_preprod());
    if (!is_dir($dossier) && !@mkdir($dossier, 0700, true) && !is_dir($dossier)) { return; }

    $jour = date('Y-m-d');
    $sel  = mesure_sel($dossier, $jour);
    $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $visite = substr(hash('sha256', $sel . '|' . $ip . '|' . $ua), 0, 8);

    $ligne = json_encode([
        'h' => date('H:i'),
        'p' => $chemin,
        'r' => mesure_source($referent, $utm),
        'a' => mesure_appareil($ua),
        'v' => $visite,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $journal = $dossier . '/' . $jour . '.log';
    $neuf = !is_file($journal);
    @file_put_contents($journal, $ligne . "\n", FILE_APPEND | LOCK_EX);
    if ($neuf) { @chmod($journal, 0600); }

    if (random_int(1, 200) === 1) { mesure_purger($dossier); }
}

/** Efface les journaux au-delà de la durée de conservation. */
function mesure_purger(string $dossier): void
{
    if (!is_dir($dossier)) { return; }
    $limite = date('Y-m-d', time() - MESURE_CONSERVATION_JOURS * 86400);
    foreach (scandir($dossier) ?: [] as $f) {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\.log$/', $f, $m) && $m[1] < $limite) { @unlink($dossier . '/' . $f); }
    }
}

/**
 * Agrégation sur les $jours derniers jours (aujourd'hui inclus).
 * Renvoie les séries quotidiennes et les classements, sans jamais exposer une ligne brute.
 */
function mesure_rapport(int $jours = 30, bool $preprod = false): array
{
    $dossier = mesure_dossier($preprod);
    $serie = $pages = $sources = [];
    $appareils = ['o' => 0, 'm' => 0, 't' => 0];
    $heures = array_fill(0, 24, 0);
    $totalPages = 0;
    $visitesTotales = [];

    for ($i = $jours - 1; $i >= 0; $i--) {
        $jour = date('Y-m-d', time() - $i * 86400);
        $f = $dossier . '/' . $jour . '.log';
        $vues = 0; $visites = [];
        if (is_file($f) && ($fh = @fopen($f, 'r')) !== false) {
            while (($l = fgets($fh)) !== false) {
                $d = json_decode(trim($l), true);
                if (!is_array($d) || !isset($d['p'])) { continue; }
                $vues++;
                $totalPages++;
                $pages[$d['p']] = ($pages[$d['p']] ?? 0) + 1;
                $v = (string)($d['v'] ?? '');
                if ($v !== '') {
                    $nouvelle = !isset($visites[$v]);
                    $visites[$v] = true;
                    $visitesTotales[$jour . $v] = true;
                    if ($nouvelle) {
                        $r = (string)($d['r'] ?? 'direct');
                        $sources[$r] = ($sources[$r] ?? 0) + 1;
                        $a = (string)($d['a'] ?? 'o');
                        if (isset($appareils[$a])) { $appareils[$a]++; }
                    }
                }
                $h = (int)substr((string)($d['h'] ?? '00:00'), 0, 2);
                if ($h >= 0 && $h < 24) { $heures[$h]++; }
            }
            fclose($fh);
        }
        $serie[] = ['jour' => $jour, 'visites' => count($visites), 'pages' => $vues];
    }

    arsort($pages); arsort($sources);
    $classement = static function (array $t, string $cle, int $n): array {
        $r = [];
        foreach (array_slice($t, 0, $n, true) as $k => $v) { $r[] = [$cle => $k, 'n' => $v]; }
        return $r;
    };

    $nbVisites = count($visitesTotales);
    return [
        'jours'     => $jours,
        'serie'     => $serie,
        'visites'   => $nbVisites,
        'pages'     => $totalPages,
        'parVisite' => $nbVisites > 0 ? round($totalPages / $nbVisites, 1) : 0.0,
        'top_pages'   => $classement($pages, 'p', 20),
        'top_sources' => $classement($sources, 'r', 12),
        'appareils'   => $appareils,
        'heures'      => $heures,
    ];
}
