<?php
/**
 * Cabinet Arnaud Cousin — API de la console d'audience (/admin/stats).
 * Accès par la clé `depot.cle_admin` de la configuration serveur (session PHP, cookie strict, 8 h).
 *   POST action=connexion cle        → ouvre la session
 *   POST action=deconnexion
 *   POST action=rapport jours preprod → chiffres agrégés (jamais de ligne brute)
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_mesure.php';

$cle = (string)($CONFIG['depot']['cle_admin'] ?? '');
if ($cle === '' || strlen($cle) < 12) {
    $etat = ['lue' => 'fichier serveur lu, clé absente ou trop courte',
             'illisible' => 'fichier serveur ILLISIBLE (faute de syntaxe PHP) : le site tourne sur ses valeurs par défaut',
             'absente' => 'aucun fichier serveur trouvé'][$CONFIG_ETAT ?? 'absente'];
    json_reponse(['ok' => false, 'connecte' => false,
                  'erreur' => 'Console fermée : définir une clé d’au moins 12 caractères dans depot.cle_admin (configuration serveur) — ' . $etat . '.'], 503);
}

session_name('statsadmin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/api/',
    'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

exiger_post();
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'stats-admin') {
    json_reponse(['ok' => false, 'erreur' => 'Requête refusée.'], 403);
}

$action   = champ('action', 20);
$connecte = !empty($_SESSION['admin']) && $_SESSION['admin'] > time() - 8 * 3600;

if ($action === 'connexion') {
    limiter_debit('stats-connexion', 10, true);
    if (!hash_equals($cle, champ('cle', 200))) {
        journal('stats', 'cle-refusee');
        json_reponse(['ok' => false, 'erreur' => 'Clé incorrecte.'], 401);
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = time();
    json_reponse(['ok' => true, 'connecte' => true]);
}

if ($action === 'deconnexion') {
    $_SESSION = [];
    session_destroy();
    json_reponse(['ok' => true, 'connecte' => false]);
}

if (!$connecte) {
    json_reponse(['ok' => false, 'connecte' => false, 'erreur' => 'Session expirée.'], 401);
}
$_SESSION['admin'] = time();

if ($action === 'rapport') {
    $jours = (int)champ('jours', 4);
    if ($jours < 1 || $jours > 400) { $jours = 30; }
    $preprod = champ('preprod', 1) === '1';
    json_reponse(['ok' => true, 'connecte' => true, 'rapport' => mesure_rapport($jours, $preprod)]);
}

json_reponse(['ok' => false, 'erreur' => 'Action inconnue.'], 400);
