<?php
/**
 * Assistant de pré-entretien — réception du questionnaire (POST JSON), synthèse structurée pour le cabinet,
 * accusé de réception au client. La charge attendue est construite par `charge()` dans
 * src/pages/services/pre-entretien.astro ; l'arbre des questions vit dans src/data/pre-entretien.ts.
 *
 * Couche IA facultative, dormante par défaut : si `$CONFIG['assistant']['cle_api']` est renseignée dans le fichier
 * serveur hors Git (/config/site-cabinet.php), une analyse préparatoire est demandée à l'API Messages d'Anthropic
 * (consigne : _assistant-consigne.php) et jointe AU SEUL courriel du cabinet — jamais à celui du client. Seules les
 * réponses au questionnaire sont transmises à l'API (ni nom, ni courriel, ni téléphone). Tout échec est silencieux.
 *
 * Clés `assistant` reconnues (toutes facultatives) : cle_api, modele (défaut claude-haiku-5-5), max_tokens (1500), timeout (40 s),
 * url (défaut https://api.anthropic.com/v1/messages — à ne changer que pour une passerelle).
 */
declare(strict_types=1);
require __DIR__ . '/_lib.php';
exiger_post();

$brut = (string)file_get_contents('php://input');
if (strlen($brut) > 200000) { json_reponse(['ok' => false, 'erreur' => 'Vos réponses sont trop longues pour être transmises. Raccourcissez les textes libres.'], 413); }
$d = json_decode($brut, true);
if (!is_array($d)) { json_reponse(['ok' => false, 'erreur' => 'Requête illisible.'], 400); }
// Pot de miel : le champ caché « site » a été rempli (robot) → on fait semblant d'accepter.
if (trim((string)($d['site'] ?? '')) !== '') { json_reponse(['ok' => true, 'ref' => reference()]); }
// Jeton anti-robot (voir _lib.php) : demandé par la page à son ouverture, à usage unique.
$motif = jeton_motif('pre-entretien', is_string($d['jeton'] ?? null) ? $d['jeton'] : '');
if ($motif !== '') {
    journal('pre-entretien', 'robot-jeton-' . $motif);
    json_reponse(['ok' => false, 'erreur' => 'Votre envoi n’a pas pu être vérifié. Réessayez dans un instant ; si le problème persiste, rechargez la page ou appelez le cabinet au 02 30 96 33 35.'], 400);
}
limiter_debit('pre-entretien', null, true);

/* ───────────── Nettoyage ───────────── */

/** Une ligne : caractères de contrôle et retours supprimés, espaces normalisés. */
function pe_ligne(mixed $v, int $max = 300): string {
    if (!is_string($v) && !is_numeric($v)) return '';
    $v = preg_replace('/[\p{C}\s]+/u', ' ', (string)$v) ?? '';
    return mb_substr(trim($v), 0, $max);
}
/** Un texte : retours à la ligne conservés, autres caractères de contrôle supprimés. */
function pe_bloc(mixed $v, int $max = 4000): string {
    if (!is_string($v)) return '';
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[^\P{C}\n\t]+/u', '', $v) ?? '';
    $v = preg_replace("/\n{3,}/", "\n\n", $v) ?? '';
    return mb_substr(trim($v), 0, $max);
}
function pe_liste(mixed $v, int $maxItems, int $maxLen): array {
    if (!is_array($v)) return [];
    $out = [];
    foreach (array_slice(array_values($v), 0, $maxItems) as $x) { $l = pe_ligne($x, $maxLen); if ($l !== '') $out[] = $l; }
    return $out;
}

/* ───────────── Lecture et validation ───────────── */

$domaine   = pe_ligne($d['domaine']['label'] ?? '', 80);
$domaineId = preg_replace('/[^a-z0-9-]/', '', (string)($d['domaine']['id'] ?? '')) ?: 'autre';
$contact   = is_array($d['contact'] ?? null) ? $d['contact'] : [];
$nom       = pe_ligne($contact['nom'] ?? '', 120);
$email     = pe_ligne($contact['email'] ?? '', 200);
$telephone = pe_ligne($contact['telephone'] ?? '', 40);
$consent   = ($d['consentement'] ?? false) === true;

if ($domaine === '' || $nom === '' || !email_valide($email)) { json_reponse(['ok' => false, 'erreur' => 'Nom et courriel valide sont nécessaires pour vous adresser le récapitulatif.'], 400); }
if (!$consent) { json_reponse(['ok' => false, 'erreur' => 'Sans votre accord, le cabinet ne peut pas recevoir vos réponses.'], 400); }

$rubriques = ['situation', 'recit', 'parties', 'chrono', 'demarches', 'objectif', 'urgence'];
$reponses = [];
foreach (array_slice(is_array($d['reponses'] ?? null) ? array_values($d['reponses']) : [], 0, 40) as $r) {
    if (!is_array($r)) continue;
    $q = pe_ligne($r['question'] ?? '', 300);
    $rep = pe_bloc($r['reponse'] ?? '', 4000);
    if ($q === '') continue;
    $reponses[] = [
        'id'       => preg_replace('/[^a-z0-9-]/', '', (string)($r['id'] ?? '')) ?: 'q' . count($reponses),
        'question' => $q,
        'synthese' => in_array($r['synthese'] ?? '', $rubriques, true) ? $r['synthese'] : 'situation',
        'reponse'  => $rep !== '' ? $rep : '—',
    ];
}
if (count($reponses) < 2) { json_reponse(['ok' => false, 'erreur' => 'Le questionnaire est incomplet : reprenez-le depuis la page de l’assistant.'], 400); }

/* Dates signalées : ancienneté calculée côté serveur (en jours), jamais de délai nommé. */
$aujourdhui = new DateTimeImmutable('today');
$dates = [];
foreach (array_slice(is_array($d['dates'] ?? null) ? array_values($d['dates']) : [], 0, 20) as $x) {
    if (!is_array($x)) continue;
    $label = pe_ligne($x['label'] ?? '', 200);
    if ($label === '') continue;
    $iso = pe_ligne($x['date'] ?? '', 10);
    $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) ? DateTimeImmutable::createFromFormat('!Y-m-d', $iso) : false;
    if ($dt === false || $dt->format('Y-m-d') !== $iso || (int)$dt->format('Y') < 1950) { $dates[] = ['label' => $label, 'date' => null, 'jours' => null, 'texte' => "$label : date non précisée"]; continue; }
    $jours = (int)$dt->diff($aujourdhui)->format('%r%a'); // positif si la date est passée
    $quand = match (true) {
        $jours > 1  => "il y a $jours jours",
        $jours === 1 => 'hier',
        $jours === 0 => 'aujourd’hui',
        $jours === -1 => 'demain',
        default      => 'dans ' . (-$jours) . ' jours',
    };
    $dates[] = ['label' => $label, 'date' => $iso, 'jours' => $jours, 'texte' => "$label : " . $dt->format('d/m/Y') . " ($quand)"];
}

$piecesIn = is_array($d['pieces'] ?? null) ? $d['pieces'] : [];
$pieces = ['disponibles' => pe_liste($piecesIn['disponibles'] ?? [], 30, 300), 'manquantes' => pe_liste($piecesIn['manquantes'] ?? [], 30, 300)];

$formatIn    = is_array($d['format'] ?? null) ? $d['format'] : [];
$formatId    = in_array($formatIn['id'] ?? '', ['cabinet', 'visio', 'telephone', 'aucun'], true) ? $formatIn['id'] : 'aucun';
$formatLabel = pe_ligne($formatIn['label'] ?? '', 60) ?: 'Non précisé';
$lienBookings = (string)($formatIn['lien'] ?? '');
// Seul un lien Microsoft Bookings du cabinet (fourni par la page, source : cabinet.ts) est repris dans le courriel.
if ($formatId === 'aucun' || !preg_match('~^https://bookings\.cloud\.microsoft/bookwithme/[A-Za-z0-9@._/?&=%-]{1,300}$~', $lienBookings)) { $lienBookings = ''; }
$lienRdv = $lienBookings !== '' ? $lienBookings : SITE_URL . '/services/rendez-vous';

/* ───────────── Synthèse structurée ───────────── */

$ref = reference();
$dossier = rtrim($CONFIG['stockage'], '/') . '/pre-entretien-' . $ref;
@mkdir($dossier, 0700, true);

$parRubrique = array_fill_keys($rubriques, []);
foreach ($reponses as $r) { $parRubrique[$r['synthese']][] = $r; }
$puces = fn(array $l): string => $l ? '- ' . implode("\n- ", $l) : '—';
$qr = fn(array $l): string => $l ? implode("\n", array_map(function ($r) {
    $q = rtrim($r['question'], ' :'); // « Vous êtes : » → « Vous êtes — Salarié(e) »
    return str_contains($r['reponse'], "\n") ? "- $q\n  " . str_replace("\n", "\n  ", $r['reponse']) : "- $q — {$r['reponse']}";
}, $l)) : '—';
$texteSeul = fn(array $l): string => $l ? implode("\n\n", array_map(fn($r) => $r['reponse'], $l)) : '—';
$section = fn(string $titre, string $contenu): string => mb_strtoupper($titre) . "\n$contenu\n\n";

// Corps du questionnaire, sans aucune coordonnée : sert au courriel du cabinet, au fichier et à l'analyse IA.
$questionnaire = "Domaine : $domaine\n\n"
    . $section('Situation', $qr($parRubrique['situation']) . ($parRubrique['recit'] ? "\n\nRécit :\n" . $texteSeul($parRubrique['recit']) : ''))
    . $section('Personnes concernées', $texteSeul($parRubrique['parties']))
    . $section('Chronologie', $texteSeul($parRubrique['chrono']))
    . $section('Dates signalées', $puces(array_column($dates, 'texte')))
    . $section('Démarches déjà faites', $texteSeul($parRubrique['demarches']))
    . $section('Objectif', $texteSeul($parRubrique['objectif']))
    . $section('Urgence', $texteSeul($parRubrique['urgence']))
    . $section('Pièces disponibles', $puces($pieces['disponibles']))
    . $section('Pièces manquantes (à réunir)', $puces($pieces['manquantes']))
    . $section('Format de rendez-vous souhaité', $formatLabel);

$entete = "PRÉ-ENTRETIEN — réf. $ref — reçu le " . date('d/m/Y à H:i') . "\n"
    . "Client : $nom · $email" . ($telephone !== '' ? " · $telephone" : '') . "\n\n";

@file_put_contents("$dossier/synthese.txt", $entete . $questionnaire, LOCK_EX);
@file_put_contents("$dossier/synthese.json", json_encode([
    'ref' => $ref, 'date' => date('c'), 'domaine' => ['id' => $domaineId, 'label' => $domaine],
    'contact' => ['nom' => $nom, 'email' => $email, 'telephone' => $telephone],
    'format' => ['id' => $formatId, 'label' => $formatLabel, 'lien' => $lienRdv],
    'reponses' => $reponses, 'dates' => $dates, 'pieces' => $pieces,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

/* ───────────── Analyse préparatoire (IA, facultative, pour l'avocat seulement) ───────────── */

/** Renvoie le texte de l'analyse, ou null si la couche IA est inactive ou en échec (silencieux). */
function assistant_analyse(string $questionnaire): ?string {
    global $CONFIG;
    $cfg = array_replace(['cle_api' => '', 'modele' => 'claude-haiku-5-5', 'max_tokens' => 1500, 'timeout' => 40, 'url' => 'https://api.anthropic.com/v1/messages'], is_array($CONFIG['assistant'] ?? null) ? $CONFIG['assistant'] : []);
    if (!is_string($cfg['cle_api']) || trim($cfg['cle_api']) === '' || !function_exists('curl_init')) return null;
    try {
        if (!is_file(__DIR__ . '/_assistant-consigne.php')) return null;
        require_once __DIR__ . '/_assistant-consigne.php';
        if (!defined('ASSISTANT_CONSIGNE') || trim(ASSISTANT_CONSIGNE) === '') return null;
        @set_time_limit((int)$cfg['timeout'] + 60);
        $corps = json_encode([
            'model'      => (string)$cfg['modele'],
            'max_tokens' => max(256, (int)$cfg['max_tokens']),
            'system'     => ASSISTANT_CONSIGNE,
            'messages'   => [['role' => 'user', 'content' => "Réponses du questionnaire de pré-entretien (date du jour : " . date('d/m/Y') . ") :\n\n" . $questionnaire]],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init((string)$cfg['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $corps,
            CURLOPT_HTTPHEADER     => ['content-type: application/json', 'x-api-key: ' . trim($cfg['cle_api']), 'anthropic-version: 2023-06-01'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(5, (int)$cfg['timeout']),
        ]);
        $rep  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($rep) || $code !== 200) { journal('pre-entretien', 'ia-echec-' . ($code ?: 'reseau')); return null; }
        $j = json_decode($rep, true);
        $texte = '';
        foreach ((array)($j['content'] ?? []) as $bloc) { if (is_array($bloc) && ($bloc['type'] ?? '') === 'text') $texte .= (string)($bloc['text'] ?? ''); }
        $texte = trim($texte);
        if ($texte === '') { journal('pre-entretien', 'ia-vide'); return null; }
        journal('pre-entretien', 'ia-ok');
        return $texte;
    } catch (\Throwable $e) {
        journal('pre-entretien', 'ia-exception');
        return null;
    }
}
$analyse = assistant_analyse($questionnaire);
if ($analyse !== null) { @file_put_contents("$dossier/analyse-ia.txt", $analyse . "\n", LOCK_EX); }

/* ───────────── Courriel au cabinet ───────────── */

$corpsCabinet = $entete . $questionnaire
    . ($analyse !== null ? "══════════════════════════════════════\nANALYSE PRÉPARATOIRE (IA — à vérifier, pour l'avocat seulement)\n══════════════════════════════════════\n$analyse\n\n" : '')
    . "Dossier serveur : $dossier\n— Envoyé le " . date('d/m/Y à H:i') . " · IP " . ($_SERVER['REMOTE_ADDR'] ?? '?') . "\n";
$envoye = envoyer_mail($CONFIG['cabinet_email'], "[SITE] Pré-entretien — $domaine — $nom ($ref)", $corpsCabinet, $email);
if (!$envoye) {
    journal('pre-entretien', 'echec-mail');
    json_reponse(['ok' => false, 'erreur' => 'Le courriel n’a pas pu partir. Réessayez dans un instant ou appelez le cabinet au 02 30 96 33 35.'], 500);
}

/* ───────────── Accusé de réception au client ───────────── */

$blocRdv = $formatId !== 'aucun'
    ? "PRENDRE RENDEZ-VOUS — " . mb_strtoupper($formatLabel) . "\nChoisissez votre créneau : $lienRdv\nSignalez dès la réservation les dates mentionnées ci-dessous : certains délais sont courts."
    : "PRENDRE RENDEZ-VOUS\nQuand vous le souhaiterez, au cabinet, en visioconférence ou par téléphone : " . SITE_URL . "/services/rendez-vous\nSignalez dès la réservation les dates mentionnées ci-dessous : certains délais sont courts.";

$corpsClient = "Bonjour,\n\n"
    . "Votre pré-entretien est bien transmis au cabinet (référence $ref). Vous trouverez ci-dessous les dates à signaler, les pièces à réunir et le récapitulatif de vos réponses.\n\n"
    . "$blocRdv\n\n"
    . $section('Dates à signaler', $puces(array_column($dates, 'texte')))
    . $section('Pièces à réunir pour le rendez-vous', $puces($pieces['manquantes']) . ($pieces['disponibles'] ? "\n\nVous avez déjà : " . implode(' ; ', $pieces['disponibles']) . '.' : ''))
    . "Pas tout, mais daté : mieux vaut cinq pièces classées que cinquante en vrac. Ne transmettez pas de pièces par courriel ; si le cabinet prend votre dossier, un lien de dépôt sécurisé vous sera adressé.\n\n"
    . $section('Vos réponses', "Domaine : $domaine\n" . $qr($reponses))
    . "Ce récapitulatif n’est pas un avis juridique : l’avocat vous répond au rendez-vous. Vos réponses sont couvertes par le secret professionnel et ne servent qu’à le préparer.\n"
    . "En cas d’urgence — délai qui expire, audience imminente — appelez le 02 30 96 33 35 : un message est pris 24h/24.\n\n"
    . "Cabinet Arnaud Cousin — Avocat au Barreau de Rennes\n15 rue du Puits Mauger, 35000 Rennes · 02 30 96 33 35 · " . SITE_URL . "\n";
envoyer_mail($email, "Votre pré-entretien avec le cabinet Arnaud Cousin ($ref)", $corpsClient);

journal('pre-entretien', 'ok');
json_reponse(['ok' => true, 'ref' => $ref]);
