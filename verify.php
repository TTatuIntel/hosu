<?php
/**
 * verify.php — Public membership verification
 *
 * Plan §4.1: anyone can scan the QR code on a HOSU certificate to confirm
 * a membership is genuine without exposing private data.
 *
 * URL:  /verify.php?m=<membership_number>&t=<hmac-token>
 *
 * Shows: name, category, institution, status (active/honorary/expired),
 *        validity dates. Never shows email, phone, documents.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/membership_helpers.php';

$memNum = trim($_GET['m'] ?? '');
$token  = trim($_GET['t'] ?? '');

$state    = 'invalid';
$display  = null;

try {
    if ($memNum !== '' && $token !== '') {
        $stmt = $pdo->prepare("
            SELECT m.*, mc.name AS category_name
              FROM members m
              LEFT JOIN membership_categories mc ON mc.id = m.category_id
             WHERE m.membership_number = ?
             LIMIT 1
        ");
        $stmt->execute([$memNum]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($member) {
            $expected = substr(hash_hmac('sha256', $member['membership_number'] . '|' . ($member['email'] ?? ''), getenv('CERT_HMAC_KEY') ?: 'hosu-cert-key'), 0, 16);
            if (hash_equals($expected, $token)) {
                $derived = hosuMembershipStatus($member);
                $state = $derived;
                $display = [
                    'name'        => $member['full_name'] ?? 'Member',
                    'memNum'      => $member['membership_number'],
                    'category'    => $member['category_name'] ?: '—',
                    'institution' => $member['institution'] ?: '',
                    'country'     => $member['country'] ?: 'Uganda',
                    'expiry'      => $member['expiry_date'] ? date('d F Y', strtotime($member['expiry_date'])) : 'Lifetime',
                    'joined'      => $member['created_at'] ? date('F Y', strtotime($member['created_at'])) : '—',
                ];
            }
        }
    }
} catch (Exception $e) {
    error_log('verify.php: ' . $e->getMessage());
}

$badge = match ($state) {
    'active'     => ['Active member', '#16a34a', '#dcfce7'],
    'honorary'   => ['Honorary member', '#0d4593', '#dbeafe'],
    'expired'    => ['Membership lapsed', '#d97706', '#fef3c7'],
    'suspended'  => ['Suspended', '#dc2626', '#fee2e2'],
    'pending', 'approved_unpaid', 'needs_correction' => ['Not yet active', '#d97706', '#fef3c7'],
    default      => ['Not found', '#dc2626', '#fee2e2'],
};
[$badgeText, $badgeColor, $badgeBg] = $badge;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify HOSU Membership</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', system-ui, sans-serif;
    background: #f4f6f9;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    color: #1a202c;
    -webkit-font-smoothing: antialiased;
}
.card {
    background: #fff;
    max-width: 460px;
    width: 100%;
    border-radius: 14px;
    box-shadow: 0 6px 28px rgba(0,0,0,.08);
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.head {
    background: linear-gradient(135deg, #0d4593, #072a5e);
    padding: 1.6rem 1.6rem 1.3rem;
    color: #fff;
    text-align: center;
}
.head h1 { font-size: 1.05rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; }
.head small { display: block; opacity: .8; font-size: .72rem; margin-top: .3rem; letter-spacing: 2px; }
.body { padding: 1.8rem 1.6rem; text-align: center; }
.badge {
    display: inline-block;
    padding: .35rem 1.1rem;
    border-radius: 999px;
    color: <?= $badgeColor ?>;
    background: <?= $badgeBg ?>;
    font-weight: 700;
    font-size: .85rem;
    letter-spacing: .5px;
    margin-bottom: 1.25rem;
}
.name {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0d4593;
    margin-bottom: .35rem;
    line-height: 1.2;
}
.subtitle { font-size: .92rem; color: #64748b; margin-bottom: 1.4rem; }
.info {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 1rem 1.1rem;
    text-align: left;
    font-size: .88rem;
}
.info dt { font-weight: 600; color: #475569; font-size: .72rem; text-transform: uppercase; letter-spacing: 1px; margin-bottom: .15rem; }
.info dd { color: #1a202c; margin-bottom: .8rem; }
.info dd:last-child { margin-bottom: 0; }
.empty {
    color: #64748b;
    font-size: .95rem;
    line-height: 1.55;
    padding: 1rem 0;
}
.foot {
    padding: 1.1rem 1.6rem 1.4rem;
    text-align: center;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
    font-size: .78rem;
    color: #64748b;
}
.foot a { color: #0d4593; text-decoration: none; font-weight: 600; }
</style>
</head>
<body>

<div class="card">
    <div class="head">
        <h1>HOSU Membership Verification</h1>
        <small>hosu.or.ug</small>
    </div>
    <div class="body">
        <div class="badge"><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></div>

        <?php if ($display): ?>
            <div class="name"><?= htmlspecialchars($display['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="subtitle"><?= htmlspecialchars($display['category'], ENT_QUOTES, 'UTF-8') ?></div>
            <dl class="info">
                <dt>Membership number</dt><dd><?= htmlspecialchars($display['memNum'], ENT_QUOTES, 'UTF-8') ?></dd>
                <?php if ($display['institution']): ?>
                <dt>Institution</dt><dd><?= htmlspecialchars($display['institution'], ENT_QUOTES, 'UTF-8') ?></dd>
                <?php endif; ?>
                <dt>Country</dt><dd><?= htmlspecialchars($display['country'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Member since</dt><dd><?= htmlspecialchars($display['joined'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Valid through</dt><dd><?= htmlspecialchars($display['expiry'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        <?php else: ?>
            <p class="empty">
                This verification link could not be matched to an active HOSU membership.
                If you believe this is an error, please contact <a href="mailto:info@hosu.or.ug">info@hosu.or.ug</a>.
            </p>
        <?php endif; ?>
    </div>
    <div class="foot">
        Issued by the Haematology &amp; Oncology Society of Uganda &middot;
        <a href="https://hosu.or.ug" target="_blank">www.hosu.or.ug</a>
    </div>
</div>

</body>
</html>
