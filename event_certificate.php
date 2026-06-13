<?php
/**
 * event_certificate.php — Printable HOSU Event Attendance Certificate
 *
 * Mirrors certificate.php but for event attendance. Renders print-optimised
 * HTML (no PHP-PDF library needed) — the attendee prints to PDF from the
 * browser. The same template is used by the email link and the admin
 * "issue certificate" button.
 *
 * Access rules:
 *   • Public link:  /event_certificate.php?reg=<ID>&t=<TOKEN>
 *                   — requires the signed cert_token stored on event_registrants.
 *                   The attendee opens this from the email we sent them.
 *   • Admin:        /event_certificate.php?reg=<ID>
 *                   — any logged-in admin can preview/issue without the token.
 *
 * Requirements for a certificate to render:
 *   • event_registrants.qr_scanned = 1   (the attendee was checked in)
 *   • event_registrants.cert_token IS NOT NULL OR caller is admin
 *
 * No mutation here — issuance is handled by api.php (issue_event_certificate).
 */

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

require_once __DIR__ . '/db.php';

function ecError(string $msg, int $code = 400): void {
    http_response_code($code);
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Certificate unavailable</title>
    <style>body{font-family:Inter,system-ui,sans-serif;background:#f7fafc;color:#1a202c;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:14px;padding:2.5rem 3rem;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.08);max-width:460px}
    h2{color:#dc2626;margin:0 0 .75rem}p{color:#4a5568;line-height:1.55}</style></head>
    <body><div class='box'><h2>Certificate unavailable</h2><p>$safe</p>
    <p style='margin-top:1.5rem'><a href='events.html' style='color:#0d4593;font-weight:600'>← Back to events</a></p>
    </div></body></html>";
    exit;
}

// Idempotent migration — make sure the cert columns exist before reading them.
try {
    $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_issued_at TIMESTAMP NULL DEFAULT NULL");
} catch (Exception $_e) { /* already exists */ }
try {
    $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_token VARCHAR(64) DEFAULT NULL");
} catch (Exception $_e) { /* already exists */ }

$regId   = (int)($_GET['reg'] ?? 0);
$token   = trim($_GET['t'] ?? '');
$isAdmin = !empty($_SESSION['user_id']) && ($_SESSION['user_role'] ?? '') === 'admin';

if ($regId <= 0) {
    ecError('Missing certificate reference.', 400);
}

try {
    $stmt = $pdo->prepare("
        SELECT r.*, e.title AS ev_title, e.date AS ev_date_str,
               e.date_start AS ev_start, e.date_end AS ev_end, e.location AS ev_location
          FROM event_registrants r
          LEFT JOIN events e ON e.id = r.event_id
         WHERE r.id = ?
         LIMIT 1
    ");
    $stmt->execute([$regId]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('event_certificate.php query: ' . $e->getMessage());
    ecError('Server error — please try again.', 500);
}

if (!$reg) {
    ecError('This certificate link is invalid or has expired.', 404);
}

// Token check — admins skip it. Constant-time compare to prevent timing attacks.
if (!$isAdmin) {
    if ($token === '' || empty($reg['cert_token']) || !hash_equals((string)$reg['cert_token'], $token)) {
        ecError('This certificate link is invalid. Please use the link from your email.', 403);
    }
}

// Attendance gate — no cert for no-shows.
$attended = (int)($reg['qr_scanned'] ?? 0) === 1;
if (!$attended && !$isAdmin) {
    ecError('A certificate is only issued after the organiser has confirmed your attendance.', 403);
}

// Build display variables.
$name        = htmlspecialchars($reg['full_name'] ?: 'Attendee', ENT_QUOTES, 'UTF-8');
$evTitle     = htmlspecialchars(($reg['ev_title'] ?: $reg['event_title']) ?: 'HOSU Event', ENT_QUOTES, 'UTF-8');
$institution = htmlspecialchars($reg['institution'] ?? '', ENT_QUOTES, 'UTF-8');
$profession  = htmlspecialchars($reg['profession'] ?? '', ENT_QUOTES, 'UTF-8');
$location    = htmlspecialchars($reg['ev_location'] ?? '', ENT_QUOTES, 'UTF-8');

// Prefer structured event dates; fall back to the freeform date string.
$evStart = $reg['ev_start'] ?? null;
$evEnd   = $reg['ev_end'] ?? null;
$evDateStr = $reg['ev_date_str'] ?? ($reg['event_date'] ?? '');
if ($evStart) {
    $startTs = strtotime($evStart);
    $endTs   = $evEnd ? strtotime($evEnd) : $startTs;
    $sameDay = date('Y-m-d', $startTs) === date('Y-m-d', $endTs ?: $startTs);
    $evDateFmt = $sameDay
        ? date('d F Y', $startTs)
        : date('d F Y', $startTs) . ' – ' . date('d F Y', $endTs);
} else {
    $evDateFmt = $evDateStr ?: date('d F Y');
}
$evDateFmt = htmlspecialchars($evDateFmt, ENT_QUOTES, 'UTF-8');

$certRef   = 'HOSU-EVT-' . date('Y', strtotime($reg['registered_at'] ?? 'now')) . '-' . str_pad((string)$reg['id'], 5, '0', STR_PAD_LEFT);
$certRef   = htmlspecialchars($certRef, ENT_QUOTES, 'UTF-8');
$issuedFmt = $reg['cert_issued_at'] ? date('d F Y', strtotime($reg['cert_issued_at'])) : date('d F Y');

// Verification token. If no cert_token is stamped yet (admin preview), fall back
// to a deterministic HMAC so the QR still resolves cleanly during a dry-run.
$verifyToken = !empty($reg['cert_token'])
    ? (string)$reg['cert_token']
    : substr(hash_hmac('sha256', 'evtcert|' . $reg['id'] . '|' . $reg['email'], getenv('CERT_HMAC_KEY') ?: 'hosu-cert-key'), 0, 32);

$verifyUrl = 'https://hosu.or.ug/verify_event.php?r=' . urlencode((string)$reg['id']) . '&t=' . urlencode($verifyToken);
$qrUrl     = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&format=png&margin=0&data=' . rawurlencode($verifyUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HOSU Event Certificate — <?= $name ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --primary: #16a34a;
    --secondary: #0d4593;
    --secondary-dark: #072a5e;
    --gold: #c9a227;
    --gold-light: #f4d35e;
    --ink: #1a202c;
    --muted: #64748b;
    --paper: #fdfcf8;
}
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: #e6e8ee;
    min-height: 100vh;
    padding: 2rem 1rem;
    color: var(--ink);
    -webkit-font-smoothing: antialiased;
}
.toolbar {
    max-width: 1040px;
    margin: 0 auto 1rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}
.toolbar a, .toolbar button {
    background: var(--secondary);
    color: #fff;
    border: none;
    padding: .65rem 1.2rem;
    border-radius: 8px;
    font-family: inherit;
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 2px 6px rgba(13,69,147,.18);
    transition: transform .15s, box-shadow .15s;
}
.toolbar a:hover, .toolbar button:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(13,69,147,.25); }
.toolbar .ghost { background: #fff; color: var(--secondary); border: 1px solid #c7d7fa; }
.toolbar .meta { font-size: .82rem; color: var(--muted); }

.certificate {
    background: var(--paper);
    max-width: 1040px;
    margin: 0 auto;
    aspect-ratio: 1.414 / 1;
    border: 12px solid var(--secondary);
    border-radius: 6px;
    position: relative;
    overflow: hidden;
    padding: 3.5rem 4rem 3rem;
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
}
.certificate::before {
    content: '';
    position: absolute;
    inset: 12px;
    border: 2px solid var(--gold);
    pointer-events: none;
    border-radius: 2px;
}
.certificate::after {
    content: '';
    position: absolute;
    inset: 20px;
    border: 1px solid rgba(13,69,147,.25);
    pointer-events: none;
    border-radius: 2px;
}
.ribbon {
    position: absolute;
    top: 0; right: 0;
    width: 0; height: 0;
    border-style: solid;
    border-width: 0 110px 110px 0;
    border-color: transparent var(--primary) transparent transparent;
    z-index: 2;
}
.ribbon-text {
    position: absolute;
    top: 24px; right: 8px;
    color: #fff;
    font-size: .62rem;
    font-weight: 800;
    letter-spacing: 1px;
    transform: rotate(45deg);
    transform-origin: center;
    text-transform: uppercase;
    z-index: 3;
}
.cert-head {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
    z-index: 1;
}
.cert-logo { height: 64px; margin-bottom: .6rem; }
.cert-org {
    font-family: 'Playfair Display', serif;
    font-size: 1.45rem;
    font-weight: 700;
    color: var(--secondary);
    letter-spacing: 1px;
}
.cert-org small {
    display: block;
    font-family: 'Inter', sans-serif;
    font-size: .72rem;
    font-weight: 500;
    color: var(--muted);
    letter-spacing: 4px;
    margin-top: .2rem;
    text-transform: uppercase;
}
.cert-title {
    font-family: 'Playfair Display', serif;
    font-size: 2.5rem;
    font-weight: 900;
    color: var(--ink);
    margin: 1.4rem 0 .15rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}
.cert-subtitle {
    font-size: .8rem;
    color: var(--muted);
    letter-spacing: 5px;
    text-transform: uppercase;
    font-weight: 600;
}
.cert-flourish {
    width: 220px;
    height: 14px;
    margin: 1.1rem auto 1.4rem;
    background: linear-gradient(90deg, transparent 0%, var(--gold) 35%, var(--gold) 65%, transparent 100%);
    position: relative;
}
.cert-flourish::before {
    content: '✦';
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    color: var(--gold);
    background: var(--paper);
    padding: 0 .55rem;
    font-size: 1.2rem;
}
.cert-presented {
    font-size: 1.05rem;
    color: var(--muted);
    text-align: center;
    margin-bottom: .35rem;
}
.cert-name {
    font-family: 'Playfair Display', serif;
    font-size: 2.7rem;
    font-weight: 700;
    color: var(--secondary-dark);
    text-align: center;
    margin: .35rem 0 .9rem;
    border-bottom: 2px solid var(--gold);
    padding-bottom: .6rem;
    line-height: 1.15;
}
.cert-body {
    font-size: 1rem;
    color: var(--ink);
    line-height: 1.75;
    text-align: center;
    max-width: 760px;
    margin: 0 auto;
}
.cert-body strong { color: var(--secondary); }
.cert-event {
    font-family: 'Playfair Display', serif;
    font-size: 1.5rem;
    color: var(--secondary);
    font-weight: 700;
    margin-top: .35rem;
    display: block;
}
.cert-event-meta {
    margin-top: .4rem;
    font-size: .9rem;
    color: var(--muted);
}

.cert-footer {
    position: absolute;
    bottom: 2.6rem;
    left: 4rem;
    right: 4rem;
    display: grid;
    grid-template-columns: 1.2fr 1fr 1.2fr;
    gap: 2rem;
    align-items: end;
}
.sig-block { text-align: center; }
.sig-line { height: 1px; background: var(--ink); margin-bottom: .35rem; }
.sig-label {
    font-size: .76rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 2px;
    font-weight: 600;
}
.sig-name {
    font-weight: 600;
    font-size: .92rem;
    color: var(--ink);
    margin-bottom: .2rem;
}

.cert-qr { text-align: center; }
.cert-qr img {
    width: 110px; height: 110px;
    border: 2px solid var(--secondary);
    padding: 4px;
    background: #fff;
    border-radius: 6px;
}
.cert-qr small {
    display: block;
    font-size: .68rem;
    color: var(--muted);
    margin-top: .4rem;
    letter-spacing: 1px;
    word-break: break-all;
}

.cert-meta-strip {
    position: absolute;
    bottom: .85rem;
    left: 0;
    right: 0;
    text-align: center;
    font-size: .7rem;
    color: var(--muted);
    letter-spacing: 1px;
}

@media print {
    @page { size: A4 landscape; margin: 0; }
    body { background: #fff; padding: 0; }
    .toolbar { display: none; }
    .certificate {
        box-shadow: none;
        margin: 0;
        max-width: none;
        width: 100%;
        height: 100vh;
        aspect-ratio: auto;
        border-radius: 0;
    }
}

@media (max-width: 720px) {
    .certificate { aspect-ratio: auto; padding: 2.2rem 1.6rem 5rem; }
    .cert-title { font-size: 1.7rem; }
    .cert-name { font-size: 1.9rem; }
    .cert-event { font-size: 1.2rem; }
    .cert-footer { position: static; grid-template-columns: 1fr; gap: 1.6rem; margin-top: 2rem; }
}
</style>
</head>
<body>

<div class="toolbar">
    <span class="meta">Issued <?= htmlspecialchars($issuedFmt, ENT_QUOTES, 'UTF-8') ?> · <?= $certRef ?></span>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a href="events.html" class="ghost">← Back to events</a>
        <button onclick="window.print()">🖨 Print / Save as PDF</button>
    </div>
</div>

<div class="certificate">
    <div class="ribbon"></div>
    <div class="ribbon-text">Attended</div>

    <div class="cert-head">
        <img src="img/logo.png" alt="HOSU" class="cert-logo" onerror="this.style.display='none'">
        <div class="cert-org">Haematology &amp; Oncology Society of Uganda
            <small>HOSU · hosu.or.ug</small>
        </div>
    </div>

    <h1 class="cert-title">Certificate of Attendance</h1>
    <div class="cert-subtitle"><?= $evDateFmt ?></div>
    <div class="cert-flourish"></div>

    <p class="cert-presented">This is to certify that</p>
    <h2 class="cert-name"><?= $name ?></h2>

    <p class="cert-body">
        <?= $profession ? '<strong>' . $profession . '</strong> ' : '' ?>
        <?= $institution ? ' of <strong>' . $institution . '</strong>' : '' ?>
        attended the HOSU event
        <span class="cert-event"><?= $evTitle ?></span>
        <span class="cert-event-meta">
            <?= $evDateFmt ?><?= $location ? ' &middot; ' . $location : '' ?>
        </span>
    </p>

    <div class="cert-footer">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">HOSU Secretariat</div>
            <div class="sig-label">Event Convenor</div>
        </div>
        <div class="cert-qr">
            <img src="<?= $qrUrl ?>" alt="Verify attendance">
            <small><?= $certRef ?></small>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">HOSU Secretariat</div>
            <div class="sig-label">Secretary</div>
        </div>
    </div>

    <div class="cert-meta-strip">
        Issued <?= htmlspecialchars($issuedFmt, ENT_QUOTES, 'UTF-8') ?> &middot; verify at hosu.or.ug/verify_event.php
    </div>
</div>

</body>
</html>
