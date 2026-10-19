<?php
/**
 * Console du cabinet pour le dépôt de pièces — API JSON de la page /admin/depots.
 * Accès par la clé `depot.cle_admin` de la configuration serveur (session PHP, cookie strict, 8 h).
 *   POST action=connexion cle                 → ouvre la session
 *   POST action=deconnexion
 *   POST action=liste                         → tous les dépôts (+ état du relais) ; purge les dépôts échus déjà classés
 *   POST action=creer nom, email, dossier, reference, consigne, validite, envoyer → nouveau lien
 *   POST action=envoyer id                    → (ré)envoie le lien au client par courriel
 *   POST action=prolonger id, jours           → repousse l'échéance (et rouvre)
 *   POST action=clore id                      → plus aucun envoi possible
 *   POST action=renvoyer id                   → reprend le relais SharePoint des pièces en attente ou en erreur
 *   POST action=supprimer id                  → efface le dépôt du serveur (les copies SharePoint restent)
 *   POST action=tester                        → vérifie l'accès à la bibliothèque SharePoint
 *   GET  ?action=zip&id=…                     → archive des pièces
 */
declare(strict_types=1);
require __DIR__ . '/_depot.php';

$cle = (string)depot_reglage('cle_admin');
if ($cle === '' || strlen($cle) < 12) {
    $etat = ['lue' => 'fichier serveur lu, clé absente ou trop courte', 'illisible' => 'fichier serveur ILLISIBLE (faute de syntaxe PHP) : le site tourne sur ses valeurs par défaut', 'absente' => 'aucun fichier serveur trouvé'][$CONFIG_ETAT ?? 'absente'];
    // Diagnostic sans valeur : noms des clés du fichier serveur et longueur des chaînes (jamais leur contenu).
    $forme = function ($t) use (&$forme) { $r = []; foreach ((array)$t as $k => $v) { $r[] = is_array($v) ? $k . '{' . implode(',', $forme($v)) . '}' : $k . ':' . (is_string($v) ? strlen($v) : gettype($v)); } return $r; };
    $local = [];
    foreach ([$racineHebergement . '/config/site-cabinet.php', __DIR__ . '/config.local.php'] as $f) { if (is_file($f)) { try { $local = (array)(require $f); } catch (\Throwable $e) { $local = []; } break; } }
    json_reponse(['ok' => false, 'connecte' => false, 'erreur' => 'Console fermée : définir une clé d’au moins 12 caractères dans depot.cle_admin (configuration serveur) — ' . $etat . '.', 'structure' => implode(' ', $forme($local))], 503);
}

session_name('depotadmin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/api/', 'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https', 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $methode === 'GET' ? (string)($_GET['action'] ?? '') : champ('action', 20);
$connecte = !empty($_SESSION['admin']) && $_SESSION['admin'] > time() - 8 * 3600;

if ($methode === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'depot-admin') {
    json_reponse(['ok' => false, 'erreur' => 'Requête refusée.'], 403);
}

if ($action === 'connexion') {
    limiter_debit('depot-admin-connexion', 10, true);
    if (hash_equals($cle, champ('cle', 200))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = time();
        journal('depot-admin', 'connexion');
        json_reponse(['ok' => true, 'connecte' => true]);
    }
    journal('depot-admin', 'cle-refusee');
    json_reponse(['ok' => false, 'connecte' => false, 'erreur' => 'Clé refusée.'], 401);
}
if ($action === 'deconnexion') {
    $_SESSION = [];
    session_destroy();
    json_reponse(['ok' => true, 'connecte' => false]);
}
if (!$connecte) { json_reponse(['ok' => false, 'connecte' => false, 'erreur' => 'Connexion requise.'], 401); }
$_SESSION['admin'] = time(); // glissant

/** Résumé d'un dépôt pour la console. */
function depot_resume(array $d): array {
    $sp = ['ok' => 0, 'attente' => 0, 'erreur' => 0];
    foreach ($d['fichiers'] as $f) { $e = $f['sharepoint']['etat'] ?? 'attente'; $sp[$e] = ($sp[$e] ?? 0) + 1; }
    return [
        'id' => $d['id'], 'lien' => depot_lien($d['jeton']), 'statut' => depot_statut($d),
        'client' => $d['client'], 'dossier' => $d['dossier'], 'reference' => $d['reference'], 'consigne' => $d['consigne'],
        'cree' => $d['cree'], 'expire' => $d['expire'], 'clos' => !empty($d['clos']),
        'nb' => count($d['fichiers']), 'total' => depot_total($d), 'sharepoint' => $sp, 'sharepoint_url' => $d['sharepoint_url'] ?? '',
        'dernier_envoi' => $d['dernier_envoi'] ?? null, 'lien_envoye' => $d['lien_envoye'] ?? null,
        'fichiers' => array_map(fn($f) => ['nom' => $f['nom'], 'original' => $f['original'], 'taille' => $f['taille'], 'date' => $f['date'], 'sharepoint' => $f['sharepoint']], $d['fichiers']),
        'depots' => $d['depots'],
    ];
}

if ($action === 'liste') {
    if (depot_veille_derniere() < time() - 20 * 3600) { depot_veille(true); } // à défaut de tâche planifiée
    $liste = depot_tous();
    // Purge : dépôt échu ou clos depuis 7 jours dont toutes les pièces sont dans SharePoint, ou vide — les copies SharePoint restent.
    $conservation = (int)depot_reglage('conservation_jours');
    $purges = 0;
    foreach ($liste as $k => $d) {
        $statut = depot_statut($d);
        if ($statut === 'ouvert') { continue; }
        $fin = max(strtotime($d['expire']), strtotime($d['clos_le'] ?? $d['expire']));
        $toutRelaye = $d['fichiers'] === [] || count(array_filter($d['fichiers'], fn($f) => ($f['sharepoint']['etat'] ?? '') !== 'ok')) === 0;
        if (($toutRelaye && $fin < time() - 7 * 86400) || $fin < time() - $conservation * 86400) {
            depot_supprimer($d['id']); unset($liste[$k]); $purges++;
        }
    }
    if ($purges) { journal('depot-admin', "purge-$purges"); }
    json_reponse(['ok' => true, 'connecte' => true, 'depots' => array_map('depot_resume', array_values($liste)), 'graph' => graph_configure(),
        'limites' => ['validite_jours' => (int)depot_reglage('validite_jours'), 'fichier_mo' => (int)depot_reglage('fichier_max_mo'), 'total_mo' => (int)depot_reglage('total_max_mo')]]);
}

if ($action === 'creer') {
    $nom = champ('nom', 120); $email = champ('email', 200); $dossier = champ('dossier', 120); $reference = champ('reference', 40);
    $consigne = texte('consigne', 1000); $validite = (int)champ('validite', 4); $envoyer = champ('envoyer', 2) === '1';
    if ($nom === '' || $dossier === '') { json_reponse(['ok' => false, 'erreur' => 'Le nom du client et l’intitulé du dossier sont nécessaires.'], 400); }
    if ($email !== '' && !email_valide($email)) { json_reponse(['ok' => false, 'erreur' => 'Courriel invalide.'], 400); }
    if ($validite < 1 || $validite > 365) { $validite = (int)depot_reglage('validite_jours'); }
    $jeton = depot_jeton_nouveau();
    $spDossier = depot_nom_sur(trim($dossier . ($reference !== '' ? " — $reference" : '')), 100);
    $d = [
        'id' => depot_id($jeton), 'jeton' => $jeton, 'cree' => date('c'), 'expire' => date('Y-m-d', strtotime("+$validite days")), 'clos' => false,
        'client' => ['nom' => $nom, 'email' => $email], 'dossier' => $dossier, 'reference' => $reference, 'consigne' => $consigne,
        'sharepoint_dossier' => $spDossier, 'sharepoint_url' => '', 'fichiers' => [], 'depots' => [],
    ];
    if (graph_configure()) {
        try { [, $d['sharepoint_url']] = graph_dossier(depot_chemin_sharepoint($d)); } catch (\Throwable $e) { error_log('site-cabinet dépôt : dossier SharePoint non créé — ' . $e->getMessage()); }
    }
    depot_ecrire($d);
    $envoi = null;
    if ($envoyer && $email !== '') {
        $envoi = depot_envoyer_lien($d);
        if ($envoi) { depot_modifier($d['id'], function (array &$x) { $x['lien_envoye'] = date('c'); }); $d['lien_envoye'] = date('c'); }
    }
    journal('depot-admin', 'creation');
    json_reponse(['ok' => true, 'depot' => depot_resume($d), 'envoye' => $envoi]);
}

if ($action === 'tester') { json_reponse(['ok' => true, 'test' => graph_tester()]); }

/* Actions sur un dépôt existant */
$id = $methode === 'GET' ? (string)($_GET['id'] ?? '') : champ('id', 40);
$d = depot_lire($id);
if (!$d) { json_reponse(['ok' => false, 'erreur' => 'Dépôt introuvable.'], 404); }

switch ($action) {
    case 'envoyer':
        if (!email_valide((string)$d['client']['email'])) { json_reponse(['ok' => false, 'erreur' => 'Ce dépôt n’a pas de courriel client : copiez le lien et envoyez-le vous-même.'], 400); }
        if (depot_statut($d) !== 'ouvert') { json_reponse(['ok' => false, 'erreur' => 'Prolongez d’abord ce dépôt : le lien n’est plus valable.'], 400); }
        $ok = depot_envoyer_lien($d);
        if ($ok) { depot_modifier($id, function (array &$x) { $x['lien_envoye'] = date('c'); }); }
        json_reponse(['ok' => $ok, 'erreur' => $ok ? null : 'Le courriel n’a pas pu partir (fonction mail du serveur).', 'depot' => depot_resume(depot_lire($id) ?? $d)]);

    case 'prolonger':
        $jours = (int)champ('jours', 4); if ($jours < 1 || $jours > 365) { $jours = 30; }
        depot_modifier($id, function (array &$x) use ($jours) {
            $base = max(time(), strtotime($x['expire']));
            $x['expire'] = date('Y-m-d', $base + $jours * 86400); $x['clos'] = false; unset($x['clos_le']);
        });
        json_reponse(['ok' => true, 'depot' => depot_resume(depot_lire($id) ?? $d)]);

    case 'clore':
        depot_modifier($id, function (array &$x) { $x['clos'] = true; $x['clos_le'] = date('c'); });
        json_reponse(['ok' => true, 'depot' => depot_resume(depot_lire($id) ?? $d)]);

    case 'renvoyer':
        @set_time_limit(600);
        $bilan = ['ok' => 0, 'erreur' => 0];
        foreach ($d['fichiers'] as $f) {
            if (($f['sharepoint']['etat'] ?? '') === 'ok') { continue; }
            $bilan[depot_relayer($id, $f['nom']) === 'ok' ? 'ok' : 'erreur']++;
        }
        json_reponse(['ok' => true, 'bilan' => $bilan, 'depot' => depot_resume(depot_lire($id) ?? $d)]);

    case 'supprimer':
        depot_supprimer($id);
        journal('depot-admin', 'suppression');
        json_reponse(['ok' => true]);

    case 'zip':
        if (!class_exists('ZipArchive')) { json_reponse(['ok' => false, 'erreur' => 'ZipArchive indisponible sur ce serveur.'], 500); }
        if ($d['fichiers'] === []) { json_reponse(['ok' => false, 'erreur' => 'Aucune pièce.'], 404); }
        if (!is_dir(DOSSIER_CACHE)) { @mkdir(DOSSIER_CACHE, 0700, true); }
        $tmp = DOSSIER_CACHE . '/zip-' . bin2hex(random_bytes(6)) . '.zip';
        $z = new ZipArchive();
        if ($z->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { json_reponse(['ok' => false, 'erreur' => 'Archive impossible.'], 500); }
        foreach ($d['fichiers'] as $f) {
            $p = depot_chemin($id) . '/' . $f['nom'];
            if (is_file($p)) { $z->addFile($p, $f['nom']); }
        }
        $z->addFromString('_depot.txt', "Dossier : {$d['dossier']}\nClient : {$d['client']['nom']} {$d['client']['email']}\nRéférence : {$d['reference']}\nCréé le : {$d['cree']}\n\n"
            . implode("\n", array_map(fn($f) => $f['nom'] . "\t" . $f['original'] . "\t" . $f['date'], $d['fichiers'])) . "\n\n"
            . implode("\n\n", array_map(fn($e) => $e['date'] . "\n" . ($e['message'] ?: '(sans message)'), $d['depots'])) . "\n");
        $z->close();
        $nomZip = depot_nom_sur($d['dossier'], 60) . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $nomZip) . '"; filename*=UTF-8\'\'' . rawurlencode($nomZip));
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
}
json_reponse(['ok' => false, 'erreur' => 'Action inconnue.'], 400);
