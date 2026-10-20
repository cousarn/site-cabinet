<?php
/** Jeton anti-robot des formulaires : horodatage signé et à usage unique, demandé par la page à son ouverture (voir _lib.php). */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
header('X-Robots-Tag: noindex');
$formulaire = (string)($_GET['f'] ?? '');
if (!in_array($formulaire, FORMULAIRES_JETON, true)) { json_reponse(['ok' => false], 400); }
limiter_debit('jeton', 120, true);
json_reponse(['ok' => true, 'jeton' => jeton_emettre($formulaire)]);
