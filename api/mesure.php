<?php
/**
 * Cabinet Arnaud Cousin — réception d'une page vue (mesure d'audience interne).
 * Appelé par /mesure.js en navigator.sendBeacon ; répond 204 sans corps.
 * Aucun cookie, aucune adresse IP conservée : voir _mesure.php.
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
require __DIR__ . '/_mesure.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); exit; }

/* Même origine seulement : un signal posté depuis un autre site n'est pas compté. */
$origine = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origine !== '') {
    $hote = strtolower((string)parse_url($origine, PHP_URL_HOST));
    // HTTP_HOST peut porter un port (serveur de test, proxy) : on compare les seuls noms d'hôte.
    $attendu = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($hote !== '' && $attendu !== '' && $hote !== $attendu) { http_response_code(403); exit; }
}

/* Le corps est un petit JSON envoyé en text/plain (sendBeacon n'ouvre pas de préflight). */
$brut = (string)file_get_contents('php://input', false, null, 0, 2048);
$d = json_decode($brut, true);
if (!is_array($d)) { http_response_code(204); exit; }

mesure_enregistrer(
    (string)($d['p'] ?? ''),
    (string)($d['r'] ?? ''),
    (string)($d['s'] ?? '')
);

http_response_code(204);
