<?php
/* ══════════════════════════════════════════════════════════════════════════
   LUMIIA — RECRUTEMENT · guichet mail et relecture de candidature
   ──────────────────────────────────────────────────────────────────────────
   À poser dans :  public_html/api/recrutement-mail.php  (serveur réservation)

   PRINCIPE DE SÉCURITÉ — à ne pas assouplir sans y réfléchir :
   l'appelant n'envoie JAMAIS ni le destinataire ni le contenu du message.
   Il envoie seulement l'identifiant de la candidature. Ce script lit la fiche
   dans Firebase, compose lui-même le message, et l'expédie à l'adresse
   enregistrée DANS la fiche. Conséquence : personne ne peut se servir de ce
   point d'entrée pour envoyer un mail à un tiers — c'est ce qui protège la
   réputation de l'expéditeur, qui sert aussi aux billets de réservation.

   POINTS D'ENTRÉE
     POST {"id":"<clé>","type":"recap"}   → envoie le récapitulatif au candidat
     POST {"id":"<clé>","type":"offre"}   → envoie l'offre (lien vers le PDF)
     GET  ?fiche=<clé>                    → renvoie la fiche en JSON
                                            (relecture depuis un autre appareil,
                                             sans ouvrir les règles Firebase)

   CONFIGURATION — deux fichiers, tous deux HORS du dépôt git :
     config/config.php          déjà là : MAIL_SERVER / MAIL_USER / MAIL_PASS
     ../.fb_recrutement         le secret de base de données Firebase, seul
                                sur sa ligne, permissions 600
   ══════════════════════════════════════════════════════════════════════════ */

declare(strict_types=1);

/* ── réglages ── */
const FB_HOST      = 'https://lumiia-live-default-rtdb.europe-west1.firebasedatabase.app';
const FB_PATH      = 'recrutement/candidats';
const ORIGINE_OK   = 'https://i-immersion.github.io';
const APP_URL      = 'https://i-immersion.github.io/recrutement/';
const PDF_URL      = 'https://i-immersion.github.io/recrutement/offre-lumiia.pdf';
const LIEN_PUBLIC  = 'https://www.lumiia.fr/link';
const DELAI_MINI   = 60;      /* secondes entre deux envois pour une même fiche */
const JOURNAL      = '/home/I-IMMERSION/logs/recrutement-mail.log';

/* ── CORS : seule l'app du recrutement peut appeler ── */
$origine = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origine === ORIGINE_OK) {
    header('Access-Control-Allow-Origin: ' . ORIGINE_OK);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
header('Content-Type: application/json; charset=utf-8');

function repond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function journal(string $ligne): void {
    @file_put_contents(JOURNAL, date('c') . ' ' . $ligne . "\n", FILE_APPEND);
}

/* ── secret Firebase ── */
$secretFichier = __DIR__ . '/../../.fb_recrutement';
if (!is_readable($secretFichier)) { journal('SECRET ABSENT'); repond(500, ['erreur' => 'configuration']); }
$FB_SECRET = trim((string)file_get_contents($secretFichier));
if ($FB_SECRET === '') { journal('SECRET VIDE'); repond(500, ['erreur' => 'configuration']); }

/* ── identifiant demandé ── */
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($methode === 'GET') {
    $id   = (string)($_GET['fiche'] ?? '');
    $type = 'fiche';
} else {
    $brut = file_get_contents('php://input') ?: '';
    $in   = json_decode($brut, true);
    if (!is_array($in)) { repond(400, ['erreur' => 'requête illisible']); }
    $id   = (string)($in['id'] ?? '');
    $type = (string)($in['type'] ?? 'recap');
}
/* une clé push Firebase : 20 caractères de l'alphabet Firebase, rien d'autre */
if (!preg_match('/^-[A-Za-z0-9_-]{18,30}$/', $id)) { repond(400, ['erreur' => 'identifiant invalide']); }
if (!in_array($type, ['recap', 'offre', 'fiche'], true)) { repond(400, ['erreur' => 'type inconnu']); }

/* ── lecture de la candidature ── */
$url = FB_HOST . '/' . FB_PATH . '/' . rawurlencode($id) . '.json?auth=' . urlencode($FB_SECRET);
$ch  = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_FAILONERROR => false]);
$rep  = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($http !== 200 || $rep === false) { journal("LECTURE KO $id http=$http"); repond(502, ['erreur' => 'lecture impossible']); }
$c = json_decode((string)$rep, true);
if (!is_array($c)) { repond(404, ['erreur' => 'candidature introuvable']); }

/* ── relecture depuis un autre appareil ── */
if ($type === 'fiche') {
    unset($c['suivi_statut'], $c['suivi_hist'], $c['relance_date'], $c['rdv_date'], $c['rdv_heure'], $c['suivi_maj']);
    journal("FICHE $id");
    repond(200, ['ok' => true, 'fiche' => $c]);
}

/* ── destinataire : celui de la fiche, jamais celui de la requête ── */
$dest = trim((string)($c['email'] ?? ''));
if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) { repond(422, ['erreur' => 'pas d’email dans la candidature']); }

/* ── limite de débit, par fiche ── */
$marque = sys_get_temp_dir() . '/recmail_' . preg_replace('/[^A-Za-z0-9_-]/', '', $id);
if (is_file($marque) && (time() - (int)filemtime($marque)) < DELAI_MINI) {
    repond(429, ['erreur' => 'déjà envoyé il y a moins d’une minute']);
}
@touch($marque);

/* ── configuration SMTP de la réservation ──
   config.php ne RETOURNE pas son tableau : il définit $CONFIG puis lui ajoute
   des clés ligne à ligne. On l'inclut donc simplement, et $CONFIG existe.
   Les clés sont écrites en guillemets doubles dans ce fichier — sans importance
   ici, mais c'est ce détail qui avait fait croire qu'elles n'existaient pas. */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/PHPMailer-6.10.0/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer-6.10.0/src/SMTP.php';
require_once __DIR__ . '/../lib/PHPMailer-6.10.0/src/Exception.php';
foreach (['MAIL_SERVER', 'MAIL_USER', 'MAIL_PASS'] as $k) {
    if (empty($CONFIG[$k])) { journal("CONFIG INCOMPLETE : $k"); repond(500, ['erreur' => 'configuration']); }
}

/* ── composition du message ── */
function e(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ligne(string $k, ?string $v): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    return '<tr><td style="padding:7px 0;border-bottom:1px solid #ede8f7;font-size:13px;color:#7c6f96;width:38%">'
         . e($k) . '</td><td style="padding:7px 0;border-bottom:1px solid #ede8f7;font-size:15px;color:#2d1b4e">'
         . nl2br(e($v)) . '</td></tr>';
}
function listeToTexte(?string $v): string { return trim(str_replace('|', ' · ', (string)$v), ' ·'); }

$prenom = trim((string)($c['prenom'] ?? ''));
$nom    = trim((string)($c['nom'] ?? ''));
$qui    = trim($prenom . ' ' . $nom);
$nbMed  = 0;
foreach (['pitch1', 'pitch2', 'pitch3', 'motmedia'] as $k) { if (!empty($c[$k . '_url'])) $nbMed++; }

$corps = '';
if ($type === 'recap') {
    $sujet = 'Votre candidature chez LUMIIA';
    $corps = '<tr><td style="padding:0 22px 6px"><p style="font-size:15px;color:#2d1b4e;line-height:1.6;margin:0">'
        . 'Bonjour ' . e($prenom) . ',<br><br>Nous avons bien reçu votre candidature. '
        . 'Voici ce que vous nous avez transmis — vous pouvez le corriger si besoin.</p></td></tr>'
        . '<tr><td style="padding:14px 22px"><table style="width:100%;border-collapse:collapse">'
        . ligne('Prénom et nom', $qui)
        . ligne('Téléphone', $c['tel'] ?? '')
        . ligne('Email', $c['email'] ?? '')
        . ligne('Permis B', $c['permis'] ?? '')
        . ligne('Transport', $c['transport'] ?? '')
        . ligne('Ancienneté', $c['anciennete'] ?? '')
        . ligne('Dans ce métier', $c['anciennete_metier'] ?? '')
        . ligne('Domaines', listeToTexte($c['experiences'] ?? ''))
        . ligne('Savoir-faire', listeToTexte($c['savoirs'] ?? ''))
        . ligne('Créneaux de rappel', listeToTexte($c['grille'] ?? ''))
        . ligne('Pourquoi LUMIIA', $c['pourquoi'] ?? '')
        . ligne('Ce qu’on dit de vous', $c['portrait'] ?? '')
        . ligne('Un dernier mot', $c['mot'] ?? '')
        . ligne('Enregistrements', $nbMed ? ($nbMed . ($nbMed > 1 ? ' envoyés' : ' envoyé')) : '')
        . '</table></td></tr>'
        . '<tr><td style="padding:10px 22px 4px" align="center">'
        . '<a href="' . APP_URL . '?c=' . e($id) . '" style="display:inline-block;background:#b8ff3c;color:#08130a;'
        . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:9px">MODIFIER MES INFORMATIONS</a>'
        . '</td></tr>'
        . '<tr><td style="padding:6px 22px 0" align="center"><p style="font-size:12px;color:#8b7fa8;margin:0">'
        . 'Ce lien reste actif tant que nous n’avons pas commencé à étudier les candidatures.</p></td></tr>';
} else {
    $sujet = 'L’offre d’emploi LUMIIA';
    $corps = '<tr><td style="padding:0 22px 6px"><p style="font-size:15px;color:#2d1b4e;line-height:1.6;margin:0">'
        . 'Bonjour ' . e($prenom) . ',<br><br>Voici l’offre d’emploi LUMIIA, comme demandé.</p></td></tr>'
        . '<tr><td style="padding:14px 22px 4px" align="center">'
        . '<a href="' . PDF_URL . '" style="display:inline-block;background:#b8ff3c;color:#08130a;text-decoration:none;'
        . 'font-weight:700;font-size:15px;padding:13px 26px;border-radius:9px">TÉLÉCHARGER L’OFFRE (PDF)</a></td></tr>'
        . '<tr><td style="padding:10px 22px 0" align="center"><p style="font-size:13px;color:#7c6f96;margin:0">'
        . 'Pour postuler : <a href="' . LIEN_PUBLIC . '" style="color:#7c3aed">' . LIEN_PUBLIC . '</a></p></td></tr>';
}

$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>'
    . '<body style="margin:0;padding:22px 0;background:#f4f1fa;font-family:Arial,Helvetica,sans-serif">'
    . '<table style="max-width:560px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;'
    . 'border-collapse:collapse;box-shadow:0 2px 14px rgba(45,27,78,.10)">'
    . '<tr><td style="background:#0b0520;padding:22px;text-align:center">'
    . '<span style="color:#b8ff3c;font-size:12px;letter-spacing:4px;text-transform:uppercase">LUMIIA recrute</span>'
    . '<div style="color:#fff;font-size:22px;font-weight:700;margin-top:6px">' . e($sujet) . '</div></td></tr>'
    . $corps
    . '<tr><td style="padding:22px;border-top:1px solid #ede8f7"><p style="font-size:11px;color:#a094bb;margin:0;line-height:1.5">'
    . 'LUMIIA — complexe de loisirs immersif · Aouste-sur-Sye<br>'
    . 'Message automatique, envoyé parce que vous avez déposé une candidature.</p></td></tr>'
    . '</table></body></html>';

/* ── envoi ── */
try {
    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    $m->isSMTP();
    $m->CharSet  = 'UTF-8';
    $m->Encoding = '8bit';
    $m->Host     = $CONFIG['MAIL_SERVER'];
    $m->SMTPAuth = true;
    $m->Username = $CONFIG['MAIL_USER'];
    $m->Password = $CONFIG['MAIL_PASS'];
    $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $m->Port = 587;
    $m->setFrom($CONFIG['MAIL_USER'], $CONFIG['NAME'] ?? 'LUMIIA');
    $m->addAddress($dest, $qui !== '' ? $qui : $dest);
    $m->isHTML(true);
    $m->Subject = $sujet;
    $m->Body    = $html;
    $m->AltBody = $sujet . ' — ' . APP_URL;
    $m->send();
} catch (\Throwable $ex) {
    journal("ENVOI KO $id " . $ex->getMessage());
    repond(502, ['erreur' => 'envoi impossible']);
}

/* ── trace dans la fiche, avec le même secret ── */
$patch = json_encode([($type === 'offre' ? 'offre_envoyee' : 'recap_envoye') => round(microtime(true) * 1000)]);
$ch = curl_init(FB_HOST . '/' . FB_PATH . '/' . rawurlencode($id) . '.json?auth=' . urlencode($FB_SECRET));
curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_POSTFIELDS => $patch,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
]);
curl_exec($ch); curl_close($ch);

journal("ENVOI OK $id type=$type");
repond(200, ['ok' => true]);
