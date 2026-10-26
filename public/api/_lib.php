<?php
/**
 * Cabinet Arnaud Cousin — bibliothèque commune des points d'entrée PHP (OVH mutualisé, PHP ≥ 8.1).
 * Aucun secret ici : les réglages sensibles vont dans api/config.local.php (non versionné, créé sur le serveur).
 */
declare(strict_types=1);

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Paris');

const SITE_URL = 'https://arnaudcousin-avocat.fr';

/** Racine web réelle : DOCUMENT_ROOT en HTTP ; en ligne de commande (tâche planifiée) PHP la laisse vide → dossier parent d'api/. */
$RACINE_WEB = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
if ($RACINE_WEB === '') { $RACINE_WEB = dirname(__DIR__); }

/** Réglages par défaut — surchargés par config.local.php si présent. */
$CONFIG = [
    'cabinet_email'   => 'cabinet@arnaudcousin-avocat.fr', // destinataire des formulaires
    'from_email'      => 'site@arnaudcousin-avocat.fr',    // expéditeur technique (alias de cabinet@ dans Microsoft 365)
    'from_name'       => 'Site du cabinet Arnaud Cousin',
    'stockage'        => dirname($RACINE_WEB) . '/consultations', // hors racine web
    'rate_par_heure'  => 6,     // envois max par IP et par formulaire
    'taille_max_mo'   => 20,    // pièces jointes cumulées
    'paiement'        => 'paypal',   // 'paypal' (PayPal ou carte, page hébergée par PayPal ; RIB en secours) | 'virement' (RIB seul) | 'stripe' (phase 2)
    'paypal_id'       => 'YKJVL9Z4Z43ZQ', // identifiant marchand PayPal du cabinet (public : il figure dans tout bouton de paiement)
    'rib'             => '[RIB du cabinet — IBAN à insérer dans /config/site-cabinet.php]',
    'tva'             => 0.20,
    'devis'           => [ // divorce par consentement mutuel — honoraires HT (grille du cabinet, oct. 2026)
        'base'          => 800,  // sans enfant, sans partage, sans négociation, sans prestation compensatoire
        'parentale'     => 400,  // convention parentale (enfants mineurs ou à charge)
        'compensatoire' => 300,  // prestation compensatoire
        'partage'       => 700,  // partage de régime matrimonial ou convention d'indivision
        'negociation'   => 500,  // accord non encore obtenu à l'ouverture du dossier
        // déplacement hors Rennes : en sus, au réel
    ],
    'depot'           => [ // dépôt de pièces par lien personnel (/depot/<jeton>) — voir _depot.php
        'dossier'          => dirname($RACINE_WEB) . '/depots', // hors racine web
        'cle_admin'        => '',   // phrase secrète de la console /admin/depots — À DÉFINIR dans /config/site-cabinet.php (vide = console fermée)
        'validite_jours'   => 30,   // durée de vie d'un lien par défaut
        'fichier_max_mo'   => 50,   // par fichier
        'total_max_mo'     => 500,  // par lien
        'fichiers_max'     => 60,   // par lien
        'conservation_jours' => 90, // purge des pièces sur le serveur après la fin de validité (les copies SharePoint restent)
    ],
    'graph'           => [ // relais vers SharePoint (Microsoft Graph, application Entra avec la permission Sites.Selected) — tout dans /config/site-cabinet.php
        'tenant'        => '',  // ex. arnaudcousin-avocat.fr ou l'identifiant du locataire
        'client_id'     => '',
        'client_secret' => '',  // JAMAIS dans le dépôt Git
        'site'          => '',  // ex. cabinetcousin.sharepoint.com:/sites/DepotsClients (bibliothèque par défaut du site, résolue et mise en cache)
        'drive_id'      => '',  // facultatif : identifiant explicite de la bibliothèque (prime sur `site`)
        'racine'        => 'Dépôts du site', // dossier racine dans la bibliothèque
    ],
];
/* Configuration locale : d'abord hors de la racine web (survit à tous les déploiements Git/FTP),
   sinon api/config.local.php (ancien emplacement). */
$racineHebergement = dirname($RACINE_WEB);
/** État de la configuration serveur : 'lue' | 'illisible' (faute de syntaxe) | 'absente' — affiché par la console, sans contenu. */
$CONFIG_ETAT = 'absente';
foreach ([$racineHebergement . '/config/site-cabinet.php', __DIR__ . '/config.local.php'] as $fichierConfig) {
    if (is_file($fichierConfig)) {
        try {
            $local = require $fichierConfig;
            if (is_array($local)) { $CONFIG = array_replace_recursive($CONFIG, $local); $CONFIG_ETAT = 'lue'; }
            else { $CONFIG_ETAT = 'illisible'; }
        } catch (\Throwable $e) {
            /* Fichier de configuration invalide (faute de frappe) : on garde les valeurs par défaut
               plutôt que de casser les formulaires ; l'erreur est journalisée. */
            error_log('site-cabinet : configuration serveur illisible (' . $e->getMessage() . ')');
            $CONFIG_ETAT = 'illisible';
        }
        break;
    }
}
/* Dossier de travail (compteurs anti-abus, journal) hors racine web. */
define('DOSSIER_CACHE', $racineHebergement . '/cache/site-cabinet');

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

/* ——— Protection anti-robots des formulaires (oct. 2026) ———
 * 1. Pot de miel (champ « site ») : l'envoi est ignoré en silence.
 * 2. Jeton signé (champ « jeton ») : horodatage émis par api/jeton.php à l'ouverture de la page, signé et à usage unique.
 *    Refusé s'il manque, s'il est faux ou déjà servi, s'il a moins de JETON_DELAI_MIN secondes ou plus de JETON_VALIDITE.
 *    Refus = page d'erreur lisible (une personne sans JavaScript sait quoi faire) ; aucun courriel ne part.
 * 3. Contenu suspect (lien dans le texte libre) : le message arrive au cabinet préfixé « [SPAM ?] »,
 *    sans accusé de réception à l'adresse saisie, qui peut être celle d'un tiers.
 * 4. Démarchage commercial présumé (formulaire de contact, voir _demarchage.php) : préfixe « [DÉMARCHAGE ?] »,
 *    sans accusé de réception ; rien n'est supprimé.
 * Les refus sont comptés dans le journal (cache/site-cabinet/journal.log), sans contenu personnel. */
const FORMULAIRES_JETON = ['contact', 'saisine', 'devis', 'consultation', 'pre-entretien'];
const JETON_DELAI_MIN   = 3;          // secondes : personne ne remplit un formulaire plus vite
const JETON_VALIDITE    = 7 * 86400;  // une page laissée ouverte une semaine reste utilisable

/** Secret de signature : `jeton_secret` du fichier serveur s'il existe, sinon tiré au hasard une fois pour toutes dans le cache (hors racine web). */
function secret_formulaires(): string {
    global $CONFIG;
    if (!empty($CONFIG['jeton_secret'])) { return (string)$CONFIG['jeton_secret']; }
    $dir = DOSSIER_CACHE;
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $f = $dir . '/jeton.secret';
    if (!is_file($f) && ($h = @fopen($f, 'x'))) { fwrite($h, bin2hex(random_bytes(32))); fclose($h); @chmod($f, 0600); }
    $s = is_file($f) ? trim((string)@file_get_contents($f)) : '';
    // Cache inaccessible (anormal) : secret de repli propre au serveur, pour ne pas bloquer les formulaires.
    return strlen($s) >= 32 ? $s : hash('sha256', __DIR__ . '|' . php_uname('n') . '|' . (string)$CONFIG['cabinet_email']);
}

/** Jeton « horodatage.aléa.signature » pour le formulaire $form. */
function jeton_emettre(string $form): string {
    $t = time();
    $n = bin2hex(random_bytes(8));
    return $t . '.' . $n . '.' . hash_hmac('sha256', "$form|$t|$n", secret_formulaires());
}

/** '' si le jeton reçu est valable (et le marque comme servi) ; sinon le motif : absent, invalide, trop-rapide, expire, rejoue.
 *  $jeton : jeton transmis hors formulaire (requête JSON du pré-entretien) ; à défaut, le champ POST « jeton ». */
function jeton_motif(string $form, ?string $jeton = null): string {
    $j = $jeton ?? ($_POST['jeton'] ?? '');
    if (!is_string($j) || $j === '') return 'absent';
    if (!preg_match('/^(\d{10})\.([0-9a-f]{16})\.([0-9a-f]{64})$/', $j, $m)) return 'invalide';
    $t = (int)$m[1]; $n = $m[2];
    if (!hash_equals(hash_hmac('sha256', "$form|$t|$n", secret_formulaires()), $m[3])) return 'invalide';
    $age = time() - $t;
    if ($age < JETON_DELAI_MIN) return 'trop-rapide';
    if ($age > JETON_VALIDITE) return 'expire';
    $dir = DOSSIER_CACHE;
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $h = @fopen($dir . '/jetons-servis.txt', 'c+');
    if ($h) {
        flock($h, LOCK_EX);
        $servis = array_filter(explode("\n", (string)stream_get_contents($h)));
        if (in_array($n, $servis, true)) { flock($h, LOCK_UN); fclose($h); return 'rejoue'; }
        $servis = array_slice($servis, -5000);
        $servis[] = $n;
        ftruncate($h, 0); rewind($h); fwrite($h, implode("\n", $servis) . "\n");
        flock($h, LOCK_UN); fclose($h);
    }
    return '';
}

/** Pot de miel puis jeton : arrête ici les robots, avant tout envoi de courriel. */
function controle_anti_robot(string $form): void {
    global $CONFIG;
    if (!honeypot_ok()) { journal($form, 'robot-pot-de-miel'); merci($form); }
    $motif = jeton_motif($form);
    if ($motif === '') return;
    journal($form, 'robot-jeton-' . $motif);
    if ($motif === 'absent') {
        erreur('Ce formulaire vérifie, par un court script, que l’envoi vient bien d’une personne, et ce script n’a pas pu s’exécuter (JavaScript désactivé ou bloqué). Activez-le puis renvoyez le formulaire, ou écrivez directement à ' . $CONFIG['cabinet_email'] . ' — ou appelez le 02 30 96 33 35.');
    }
    erreur('Votre envoi n’a pas pu être vérifié (envoi trop rapide, déjà effectué, ou page ouverte depuis trop longtemps). Rechargez la page et renvoyez le formulaire ; en cas de difficulté, écrivez à ' . $CONFIG['cabinet_email'] . ' ou appelez le 02 30 96 33 35.');
}

/** Lien dans un texte libre (http, www, raccourcisseurs, BBCode, HTML) : la signature habituelle des robots publicitaires. */
function contenu_suspect(string ...$textes): bool {
    foreach ($textes as $texte) {
        if (preg_match('~https?://|www\.|\[url|<a\s|telegra\.ph|t\.me/|bit\.ly~i', $texte)) return true;
    }
    return false;
}

/** Bandeau placé en tête du courriel au cabinet lorsqu'un envoi est suspect. */
const AVERTISSEMENT_SUSPECT = "⚠ Envoi signalé comme suspect (lien dans le texte) : aucun accusé de réception n'a été adressé à l'expéditeur. S'il s'agit d'un vrai client, répondez-lui directement.\n\n";

/** Limite de débit par IP et par formulaire (compteurs hors racine web). $max : plafond horaire propre à l'action (défaut : rate_par_heure).
 *  $json : répondre en JSON (API du dépôt) plutôt qu'en texte. */
function limiter_debit(string $formulaire, ?int $max = null, bool $json = false): void {
    global $CONFIG;
    $dir = DOSSIER_CACHE;
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $f   = $dir . '/' . $formulaire . '-' . hash('sha256', $ip) . '.json';
    $now = time();
    $hits = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
    if (count($hits) >= ($max ?? (int)$CONFIG['rate_par_heure'])) {
        $message = 'Trop de demandes depuis votre connexion. Réessayez dans une heure ou appelez le cabinet.';
        if ($json) { json_reponse(['ok' => false, 'erreur' => $message], 429); }
        http_response_code(429);
        exit($message);
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
    $dir = DOSSIER_CACHE;
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    @file_put_contents($dir . '/journal.log', date('c') . " $formulaire $etat\n", FILE_APPEND | LOCK_EX);
}

/** Lien de paiement PayPal (page hébergée : compte PayPal ou carte bancaire sans compte).
 *  Aucun secret : seul l'identifiant marchand public est utilisé. La référence voyage dans `invoice` et `custom`. */
function lien_paypal(string $libelle, int $montant, string $ref, string $formule = ''): string {
    global $CONFIG;
    $hote = 'https://' . ($_SERVER['HTTP_HOST'] ?? parse_url(SITE_URL, PHP_URL_HOST));
    return 'https://www.paypal.com/cgi-bin/webscr?' . http_build_query([
        'cmd'           => '_xclick',
        'business'      => $CONFIG['paypal_id'],
        'item_name'     => 'Cabinet Arnaud Cousin — ' . $libelle . ' (réf. ' . $ref . ')',
        'item_number'   => $formule,
        'amount'        => number_format($montant, 2, '.', ''),
        'currency_code' => 'EUR',
        'invoice'       => $ref,
        'custom'        => $ref,
        'no_shipping'   => '1',
        'no_note'       => '1',
        'lc'            => 'fr_FR',
        'charset'       => 'utf-8',
        'return'        => $hote . '/merci?objet=paiement&ref=' . $ref,
        'cancel_return' => $hote . '/merci?objet=consultation&ref=' . $ref . '&prix=' . $montant . '&annule=1',
        'notify_url'    => $hote . '/api/paypal-ipn.php', // notification IPN : marque la demande « payée » (voir paypal-ipn.php)
    ], '', '&', PHP_QUERY_RFC3986);
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

/** Réponse JSON (API du dépôt de pièces et console) puis arrêt. */
function json_reponse(array $donnees, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
