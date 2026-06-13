<?php
/**
 * verify_event.php — Public event attendance verification
 *
 * Scanned from the QR on an event certificate. Confirms an attendance
 * record without exposing private data.
 *
 * URL:  /verify_event.php?r=<registrant_id>&t=<token>
 *
 * Shows: attendee name, event title, event date, institution, status.
 * Never shows: email, phone, payment amount, transaction refs.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

$regId = (int)($_GET['r'] ?? 0);
$token = trim($_GET['t'] ?? '');

$state   = 'invalid';
$display = null;

// Idempotent migration — keeps verify working even if columns weren't created yet.
try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_issued_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $_e) {}
try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_token VARCHAR(64) DEFAULT NULL"); } catch (Exception $_e) {}

try {
    if ($regId > 0 && $token !== '') {
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

        if ($reg && !empty($reg['cert_token']) && hash_equals((string)$reg['cert_token'], $token)) {
            $attended = (int)($reg['qr_scanned'] ?? 0) === 1;
            $state = $attended ? 'attended' : 'registered_only';

            $evStart = $reg['ev_start'] ?? null;
            $evEnd   = $reg['ev_end'] ?? null;
            if ($evStart) {
                $startTs = strtotime($evStart);
                $endTs   = $evEnd ? strtotime($evEnd) : $startTs;
                $sameDay = date('Y-m-d', $startTs) === date('Y-m-d', $endTs ?: $startTs);
                $evDateFmt = $sameDay
                    ? date('d F Y', $startTs)
                    : date('d F Y', $startTs) . ' – ' . date('d F Y', $endTs);
            } else {
                $evDateFmt = $reg['ev_date_str'] ?: $reg['event_date'] ?: '—';
            }

            $display = [
                'name'        => $reg['full_name'] ?? 'Attendee',
                'event'       => $reg['ev_title'] ?: ($reg['event_title'] ?: 'HOSU Event'),
                'date'        => $evDateFmt,
                'location'    => $reg['ev_location'] ?? '',
                'institution' => $reg['institution'] ?? '',
                'issued'      => $reg['cert_issued_at'] ? date('d F Y', strtotime($reg['cert_issued_at'])) : '—',
                'certRef'     => 'HOSU-EVT-' . date('Y', strtotime($reg['registered_at'] ?? 'now')) . '-' . str_pad((string)$reg['id'], 5, '0', STR_PAD_LEFT),
            ];
        }
    }
} catch (Exception $e) {
    error_log('verify_event.php: ' . $e->getMessage());
}

$badge = match ($state) {
    'attended'         => ['Verified attendance', '#16a34a', '#dcfce7'],
    'registered_only'  => ['Registered (not checked in)', '#d97706', '#fef3c7'],
    default            => ['Not found', '#dc2626', '#fee2e2'],
};
[$badgeText, $badgeColor, $badgeBg] = $badge;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify HOSU Event Attendance</title>
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
.empty { color: #64748b; font-size: .95rem; line-height: 1.55; padding: 1rem 0; }
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
        <h1>HOSU Event Verification</h1>
        <small>hosu.or.ug</small>
    </div>
    <div class="body">
        <div class="badge"><?= htmlspecialchars($badgeText, ENT_QUOTES, 'UTF-8') ?></div>

        <?php if ($display): ?>
            <div class="name"><?= htmlspecialchars($display['name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="subtitle"><?= htmlspecialchars($display['event'], ENT_QUOTES, 'UTF-8') ?></div>
            <dl class="info">
                <dt>Certificate reference</dt><dd><?= htmlspecialchars($display['certRef'], ENT_QUOTES, 'UTF-8') ?></dd>
                <dt>Event date</dt><dd><?= htmlspecialchars($display['date'], ENT_QUOTES, 'UTF-8') ?></dd>
                <?php if ($display['location']): ?>
                <dt>Location</dt><dd><?= htmlspecialchars($display['location'], ENT_QUOTES, 'UTF-8') ?></dd>
                <?php endif; ?>
                <?php if ($display['institution']): ?>
                <dt>Institution</dt><dd><?= htmlspecialchars($display['institution'], ENT_QUOTES, 'UTF-8') ?></dd>
                <?php endif; ?>
                <dt>Certificate issued</dt><dd><?= htmlspecialchars($display['issued'], ENT_QUOTES, 'UTF-8') ?></dd>
            </dl>
        <?php else: ?>
            <p class="empty">
                This verification link could not be matched to a HOSU event record.
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
