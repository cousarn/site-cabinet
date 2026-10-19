<?php
/**
 * Dépôt de pièces — API utilisée par la page /depot/<jeton> (JSON).
 *   GET  ?j=<jeton>                         → état du dépôt et pièces déjà reçues
 *   POST action=morceau  j, fid, nom, taille, i, n, c (fichier)  → un morceau de 4 Mo ; la dernière réponse décrit la pièce
 *   POST action=terminer j, message          → prévient le cabinet (et accuse réception au client)
 * Les morceaux évitent les plafonds d'envoi de l'hébergement et donnent une progression fiable ; chaque pièce
 * complète est contrôlée (type réel) puis relayée vers SharePoint.
 */
declare(strict_types=1);
require __DIR__ . '/_depot.php';

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action  = $methode === 'GET' ? 'info' : champ('action', 20);
$jeton   = $methode === 'GET' ? (string)($_GET['j'] ?? '') : champ('j', 64);

limiter_debit($action === 'morceau' ? 'depot-envoi' : 'depot-acces', $action === 'morceau' ? 2000 : 120, true);

$d = depot_par_jeton($jeton);
if (!$d) { json_reponse(['ok' => false, 'etat' => 'inconnu', 'erreur' => 'Ce lien de dépôt n’est pas reconnu. Vérifiez qu’il est complet, ou demandez-en un nouveau au cabinet.'], 404); }
$id = $d['id'];
$statut = depot_statut($d);

function depot_vue(array $d, string $statut): array {
    $dernier = $d['depots'] ? end($d['depots'])['date'] : null;
    return [
        'ok' => true, 'etat' => $statut,
        'client' => $d['client']['nom'], 'dossier' => $d['dossier'], 'consigne' => (string)($d['consigne'] ?? ''),
        'expire' => $d['expire'], 'expire_fr' => depot_date_fr($d['expire']),
        'fichiers' => array_map(fn($f) => ['nom' => $f['original'], 'taille' => $f['taille'], 'date' => $f['date'], 'signale' => $dernier !== null && $f['date'] <= $dernier], $d['fichiers']),
        'dernier_envoi' => $dernier,
        'limites' => [
            'fichier_mo' => (int)depot_reglage('fichier_max_mo'), 'total_mo' => (int)depot_reglage('total_max_mo'),
            'fichiers' => (int)depot_reglage('fichiers_max'), 'morceau' => DEPOT_MORCEAU,
            'reste_octets' => max(0, (int)depot_reglage('total_max_mo') * 1048576 - depot_total($d)),
            'extensions' => DEPOT_EXTENSIONS,
        ],
    ];
}

if ($action === 'info') {
    depot_nettoyer_morceaux($id);
    json_reponse(depot_vue($d, $statut));
}
exiger_post();
if ($statut !== 'ouvert') {
    json_reponse(['ok' => false, 'etat' => $statut, 'erreur' => $statut === 'clos'
        ? 'Ce dépôt est clos : le cabinet a classé vos pièces. Pour en ajouter, demandez-lui un nouveau lien.'
        : 'Ce lien a expiré le ' . depot_date_fr($d['expire']) . '. Demandez un nouveau lien au cabinet.'], 410);
}

/* ───────── Un morceau de fichier ───────── */
if ($action === 'morceau') {
    @set_time_limit(300);
    $fid    = champ('fid', 40);
    $nom    = champ('nom', 180);
    $taille = (int)champ('taille', 20);
    $i      = (int)champ('i', 6);
    $n      = (int)champ('n', 6);
    if (!preg_match('/^[A-Za-z0-9]{8,40}$/', $fid) || $nom === '' || $taille <= 0 || $n < 1 || $n > 2000 || $i < 0 || $i >= $n) {
        json_reponse(['ok' => false, 'erreur' => 'Envoi mal formé.'], 400);
    }
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!in_array($ext, DEPOT_EXTENSIONS, true)) {
        json_reponse(['ok' => false, 'erreur' => 'Ce type de fichier n’est pas accepté. Formats : PDF, photos, documents Word/Excel/PowerPoint, courriels (.eml, .msg), audio, vidéo, zip.'], 415);
    }
    if ($taille > (int)depot_reglage('fichier_max_mo') * 1048576) {
        json_reponse(['ok' => false, 'erreur' => 'Fichier trop lourd : ' . (int)depot_reglage('fichier_max_mo') . ' Mo au plus par fichier.'], 413);
    }
    if (count($d['fichiers']) >= (int)depot_reglage('fichiers_max')) {
        json_reponse(['ok' => false, 'erreur' => 'Nombre maximal de pièces atteint pour ce lien (' . (int)depot_reglage('fichiers_max') . '). Prévenez le cabinet, qui vous en adressera un nouveau.'], 413);
    }
    if (depot_total($d) + $taille > (int)depot_reglage('total_max_mo') * 1048576) {
        json_reponse(['ok' => false, 'erreur' => 'Volume maximal atteint pour ce lien (' . (int)depot_reglage('total_max_mo') . ' Mo). Prévenez le cabinet, qui vous en adressera un nouveau.'], 413);
    }
    $c = $_FILES['c'] ?? null;
    if (!$c || !is_string($c['tmp_name'] ?? null)) { json_reponse(['ok' => false, 'erreur' => 'Morceau manquant.'], 400); }
    if (($c['error'] ?? 1) !== UPLOAD_ERR_OK) {
        $trop = in_array($c['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        json_reponse(['ok' => false, 'erreur' => $trop ? 'Le serveur refuse des morceaux de cette taille.' : 'Morceau incomplet, nouvel essai.', 'reessayer' => !$trop], 400);
    }
    if ((int)$c['size'] > DEPOT_MORCEAU + 65536) { json_reponse(['ok' => false, 'erreur' => 'Morceau trop gros.'], 413); }

    $part = depot_chemin($id) . '/.part';
    if (!is_dir($part) && !@mkdir($part, 0700, true)) { json_reponse(['ok' => false, 'erreur' => 'Stockage indisponible, réessayez dans un instant.'], 500); }
    $pf = "$part/$fid"; $pm = "$part/$fid.json";
    if ($i === 0) {
        file_put_contents($pf, '');
        $meta = ['nom' => $nom, 'taille' => $taille, 'n' => $n, 'recu' => 0, 'octets' => 0, 'debut' => time()];
    } else {
        $meta = is_file($pm) ? json_decode((string)file_get_contents($pm), true) : null;
        if (!is_array($meta) || ($meta['recu'] ?? -1) !== $i || ($meta['n'] ?? 0) !== $n || !is_file($pf)) {
            json_reponse(['ok' => false, 'erreur' => 'Séquence interrompue : reprenez l’envoi de ce fichier.', 'recommencer' => true], 409);
        }
    }
    $in = fopen($c['tmp_name'], 'rb'); $out = fopen($pf, 'ab');
    $copie = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
    if ($in) { fclose($in); } if ($out) { fclose($out); }
    if ($copie === false) { @unlink($pf); @unlink($pm); json_reponse(['ok' => false, 'erreur' => 'Écriture impossible, réessayez.', 'recommencer' => true], 500); }
    $meta['recu'] = $i + 1; $meta['octets'] += (int)$copie;
    if ($meta['octets'] > $taille) { @unlink($pf); @unlink($pm); json_reponse(['ok' => false, 'erreur' => 'Taille incohérente, recommencez ce fichier.', 'recommencer' => true], 400); }
    file_put_contents($pm, json_encode($meta), LOCK_EX);
    if ($i < $n - 1) { json_reponse(['ok' => true, 'recu' => $i + 1]); }

    /* Dernier morceau : contrôle du fichier complet, classement, relais. */
    @unlink($pm);
    if ($meta['octets'] !== $taille) { @unlink($pf); json_reponse(['ok' => false, 'erreur' => 'Fichier incomplet, recommencez-le.', 'recommencer' => true], 400); }
    $extReelle = depot_type($pf, $nom);
    if ($extReelle === null) { @unlink($pf); json_reponse(['ok' => false, 'erreur' => 'Le contenu de « ' . $nom . ' » ne correspond pas à un type accepté (PDF, image, document Office, courriel, audio, vidéo, zip sans exécutable).'], 415); }
    try {
        $entree = depot_modifier($id, function (array &$d) use ($pf, $nom, $extReelle, $taille) {
            $base = depot_nom_sur(pathinfo($nom, PATHINFO_FILENAME));
            $nomDisque = sprintf('%02d - %s.%s', count($d['fichiers']) + 1, $base, $extReelle);
            if (!rename($pf, depot_chemin($d['id']) . '/' . $nomDisque)) { throw new RuntimeException('Classement impossible.'); }
            $e = ['nom' => $nomDisque, 'original' => $nom, 'taille' => $taille, 'date' => date('c'), 'sharepoint' => ['etat' => 'attente']];
            $d['fichiers'][] = $e;
            $d['dernier_envoi'] = $e['date'];
            return $e;
        });
    } catch (\Throwable $e) {
        @unlink($pf);
        json_reponse(['ok' => false, 'erreur' => 'Enregistrement impossible, réessayez.', 'recommencer' => true], 500);
    }
    $relais = depot_relayer($id, $entree['nom']);
    journal('depot', 'piece');
    json_reponse(['ok' => true, 'fichier' => ['nom' => $nom, 'taille' => $taille, 'date' => $entree['date'], 'classe' => $relais === 'ok']]);
}

/* ───────── Fin de dépôt : prévenir le cabinet ───────── */
if ($action === 'terminer') {
    $message = texte('message', 4000);
    if (empty($d['fichiers'])) { json_reponse(['ok' => false, 'erreur' => 'Aucune pièce n’a encore été reçue : déposez vos fichiers avant de terminer.'], 400); }
    $dernier = $d['depots'] ? end($d['depots'])['date'] : '';
    $nouvelles = array_values(array_filter($d['fichiers'], fn($f) => $f['date'] > $dernier));
    if (!$nouvelles && $message === '') { json_reponse(['ok' => false, 'erreur' => 'Le cabinet a déjà été prévenu de ces pièces. Ajoutez un fichier ou un message pour le prévenir à nouveau.'], 400); }

    depot_modifier($id, function (array &$d) use ($message, $nouvelles) {
        $d['depots'][] = ['date' => date('c'), 'message' => $message, 'fichiers' => array_map(fn($f) => $f['nom'], $nouvelles)];
    });
    $d = depot_lire($id) ?? $d;

    $ligne = fn(array $f) => '- ' . $f['original'] . ' (' . depot_taille_lisible((int)$f['taille']) . ')'
        . (($f['sharepoint']['etat'] ?? '') === 'ok' ? '' : (graph_configure() ? ' — relais SharePoint à reprendre' : ' — sur le serveur'));
    $listeCabinet = implode("\n", array_map($ligne, $nouvelles)) ?: '(aucune nouvelle pièce)';
    $listeClient  = implode("\n", array_map(fn($f) => '- ' . $f['original'] . ' (' . depot_taille_lisible((int)$f['taille']) . ')', $nouvelles)) ?: '(aucune nouvelle pièce)';
    $nb = count($nouvelles);
    $spLigne = !empty($d['sharepoint_url']) ? "Dossier SharePoint : {$d['sharepoint_url']}\n" : (graph_configure() ? "Dossier SharePoint : non encore créé (voir la console).\n" : "Relais SharePoint non configuré : les pièces sont sur le serveur, à télécharger depuis la console.\n");
    $corpsCabinet = "Dépôt de pièces — {$d['dossier']}\nClient : {$d['client']['nom']}" . (!empty($d['client']['email']) ? " <{$d['client']['email']}>" : '') . ($d['reference'] ? "\nRéférence : {$d['reference']}" : '') . "\n\n"
        . "Nouvelles pièces ($nb) :\n$listeCabinet\n\n"
        . ($message !== '' ? "Message du client :\n$message\n\n" : '')
        . $spLigne
        . "Console : " . SITE_URL . "/admin/depots\n"
        . "Lien valable jusqu'au " . depot_date_fr($d['expire']) . " · " . count($d['fichiers']) . ' pièce(s) au total, ' . depot_taille_lisible(depot_total($d)) . "\n";
    $envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Pièces déposées — {$d['dossier']} — {$d['client']['nom']} ($nb)", $corpsCabinet, (string)($d['client']['email'] ?? '') ?: null);

    if (email_valide((string)($d['client']['email'] ?? ''))) {
        $corpsClient = "Bonjour,\n\nLe cabinet a bien reçu vos pièces pour le dossier « {$d['dossier']} » ($nb) :\n$listeClient\n\n"
            . ($message !== '' ? "Votre message :\n$message\n\n" : '')
            . "Elles sont classées dans votre dossier. Vous pouvez déposer d'autres pièces avec le même lien jusqu'au " . depot_date_fr($d['expire']) . " :\n" . depot_lien($d['jeton']) . "\n\n"
            . depot_signature();
        envoyer_mail((string)$d['client']['email'], "Vos pièces sont bien reçues — {$d['dossier']}", $corpsClient, $CONFIG['cabinet_email']);
    }
    journal('depot', $envoye ? 'termine' : 'termine-echec-mail');
    json_reponse(['ok' => true, 'date' => date('c'), 'nb' => $nb, 'accuse' => email_valide((string)($d['client']['email'] ?? ''))]);
}

json_reponse(['ok' => false, 'erreur' => 'Action inconnue.'], 400);
