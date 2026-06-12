<?php
/**
 * certificate.php — Printable HOSU Membership Certificate
 *
 * Plan §4.2 / §10 Phase 2: members get a downloadable, professional
 * certificate of membership. We render print-optimised HTML (no PHP-PDF
 * library needed) — the user prints to PDF from the browser.
 *
 * Access rules:
 *   • Self-serve:  /certificate.php             → logged-in member, own record
 *   • Self-serve:  /certificate.php?member=ID   → member must own that row
 *   • Admin:       /certificate.php?member=ID   → any active member
 *
 * Requirements for a certificate to render:
 *   • approval_status = 'approved' AND not suspended/rejected
 *   • status derived as 'active' or 'honorary'
 *
 * No mutation, no audit log here — the act of viewing is logged via web access logs.
 */

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/membership_helpers.php';

function certError(string $msg, int $code = 400): void {
    http_response_code($code);
    $safe = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Certificate unavailable</title>
    <style>body{font-family:Inter,system-ui,sans-serif;background:#f7fafc;color:#1a202c;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
    .box{background:#fff;border-radius:14px;padding:2.5rem 3rem;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.08);max-width:460px}
    h2{color:#dc2626;margin:0 0 .75rem}p{color:#4a5568;line-height:1.55}</style></head>
    <body><div class='box'><h2>Certificate unavailable</h2><p>$safe</p>
    <p style='margin-top:1.5rem'><a href='portal.html' style='color:#0d4593;font-weight:600'>← Member portal</a></p>
    </div></body></html>";
    exit;
}

if (empty($_SESSION['user_id'])) {
    certError('Please sign in to access your membership certificate.', 401);
}

$userId  = (int)$_SESSION['user_id'];
$isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
$reqMember = (int)($_GET['member'] ?? 0);

try {
    if ($isAdmin && $reqMember > 0) {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$reqMember]);
    } elseif ($reqMember > 0) {
        // Member explicitly requesting an ID — must own it
        $stmt = $pdo->prepare("SELECT * FROM members WHERE id = ? AND user_id = ?");
        $stmt->execute([$reqMember, $userId]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM members WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
    }
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('certificate.php query: ' . $e->getMessage());
    certError('Server error — please try again.', 500);
}

if (!$member) {
    certError('No membership record was found for your account. If you have just paid, please allow a few minutes for processing.', 404);
}

$derived = hosuMembershipStatus($member);
if (!in_array($derived, ['active', 'honorary'], true)) {
    certError("A membership certificate is only available for active or honorary members. Your current status is: $derived. Please complete payment / await approval, then return here.", 403);
}

$catName = '';
if (!empty($member['category_id'])) {
    $s = $pdo->prepare("SELECT name FROM membership_categories WHERE id = ?");
    $s->execute([$member['category_id']]);
    $catName = (string)$s->fetchColumn();
}

$name        = htmlspecialchars($member['full_name'] ?: 'Member', ENT_QUOTES, 'UTF-8');
$memNum      = htmlspecialchars($member['membership_number'] ?: ('HOSU-' . date('Y') . '-' . str_pad((string)$member['id'], 4, '0', STR_PAD_LEFT)), ENT_QUOTES, 'UTF-8');
$category    = htmlspecialchars($catName ?: 'Member', ENT_QUOTES, 'UTF-8');
$institution = htmlspecialchars($member['institution'] ?? '', ENT_QUOTES, 'UTF-8');
$country     = htmlspecialchars($member['country'] ?? 'Uganda', ENT_QUOTES, 'UTF-8');
$expiryRaw   = $member['expiry_date'] ?? null;
$expiryFmt   = $expiryRaw ? date('d F Y', strtotime($expiryRaw)) : 'Lifetime';
$joinedFmt   = $member['created_at'] ? date('F Y', strtotime($member['created_at'])) : '—';
$issuedFmt   = date('d F Y');

// Verification token (deterministic hash for QR — verifiers can compare server-side)
$verifyToken = substr(hash_hmac('sha256', $memNum . '|' . ($member['email'] ?? ''), getenv('CERT_HMAC_KEY') ?: 'hosu-cert-key'), 0, 16);
$verifyUrl   = 'https://hosu.or.ug/verify.php?m=' . urlencode($memNum) . '&t=' . $verifyToken;
$qrUrl       = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&format=png&margin=0&data=' . rawurlencode($verifyUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HOSU Membership Certificate — <?= $name ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700;900&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --primary: #e63946;
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
.toolbar .ghost {
    background: #fff;
    color: var(--secondary);
    border: 1px solid #c7d7fa;
}
.toolbar .meta { font-size: .82rem; color: var(--muted); }

.certificate {
    background: var(--paper);
    max-width: 1040px;
    margin: 0 auto;
    aspect-ratio: 1.414 / 1;       /* A4 landscape proportions */
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
    font-size: .68rem;
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
.cert-logo {
    height: 64px;
    margin-bottom: .6rem;
}
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
    font-size: 2.7rem;
    font-weight: 900;
    color: var(--ink);
    margin: 1.4rem 0 .15rem;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}
.cert-subtitle {
    font-size: .85rem;
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
    font-size: 3rem;
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
.sig-line {
    height: 1px;
    background: var(--ink);
    margin-bottom: .35rem;
}
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

.cert-qr {
    text-align: center;
}
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

/* Print */
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
    .cert-title { font-size: 1.9rem; }
    .cert-name { font-size: 2rem; }
    .cert-footer { position: static; grid-template-columns: 1fr; gap: 1.6rem; margin-top: 2rem; }
}
</style>
</head>
<body>

<div class="toolbar">
    <span class="meta">Issued <?= $issuedFmt ?> · <?= $memNum ?></span>
    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <a href="portal.html" class="ghost">← Member portal</a>
        <button onclick="window.print()">🖨 Print / Save as PDF</button>
    </div>
</div>

<div class="certificate">
    <div class="ribbon"></div>
    <div class="ribbon-text">HOSU</div>

    <div class="cert-head">
        <img src="img/logo.png" alt="HOSU" class="cert-logo" onerror="this.style.display='none'">
        <div class="cert-org">Haematology &amp; Oncology Society of Uganda
            <small>HOSU · hosu.or.ug</small>
        </div>
    </div>

    <h1 class="cert-title">Certificate of Membership</h1>
    <div class="cert-subtitle"><?= date('Y') ?> &middot; <?= $category ?></div>
    <div class="cert-flourish"></div>

    <p class="cert-presented">This is to certify that</p>
    <h2 class="cert-name"><?= $name ?></h2>

    <p class="cert-body">
        is a duly registered <strong><?= $category ?></strong>
        <?= $institution ? 'with <strong>' . $institution . '</strong> ' : '' ?>
        of the Haematology &amp; Oncology Society of Uganda, and is in good standing
        as of <?= $issuedFmt ?>. This certificate is valid through
        <strong><?= htmlspecialchars($expiryFmt, ENT_QUOTES, 'UTF-8') ?></strong>.
    </p>

    <div class="cert-footer">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">HOSU Secretariat</div>
            <div class="sig-label">President</div>
        </div>
        <div class="cert-qr">
            <img src="<?= $qrUrl ?>" alt="Verify membership">
            <small><?= $memNum ?></small>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name">HOSU Secretariat</div>
            <div class="sig-label">Secretary</div>
        </div>
    </div>

    <div class="cert-meta-strip">
        Member since <?= $joinedFmt ?> &middot; <?= $country ?> &middot; verify at hosu.or.ug
    </div>
</div>

</body>
</html>
