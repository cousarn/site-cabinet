<?php
/**
 * Veille du dépôt de pièces — à lancer en ligne de commande par la tâche planifiée OVH, une fois par jour :
 *   php /home/arnaudcoqa/prod/api/depot-veille.php
 * (Hébergement › Tâches planifiées ; en CLI, la configuration serveur est lue depuis /home/arnaudcoqa/config/.)
 * Envoie au cabinet un seul courriel « [SITE] Veille dépôts » quand quelque chose demande attention ; silencieuse sinon.
 * Refusée en HTTP : rien n'est exposé sur le web.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/_depot.php';
$r = depot_veille(true);
echo date('c') . ' — ' . count($r['points']) . " point(s)" . ($r['mail'] ? ', courriel envoyé' : '') . "\n";
foreach ($r['points'] as $p) { echo "• $p\n"; }
