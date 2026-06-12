<?php
/**
 * send_renewal_reminders.php — Renewal reminder dispatcher
 *
 * Plan §10 (Phase 1): notify members before their membership expires so they can renew.
 *
 * Runs in two modes:
 *   CLI:   php send_renewal_reminders.php
 *   Web:   send_renewal_reminders.php?key=<RENEWAL_CRON_KEY>
 *          send_renewal_reminders.php?key=<...>&dry_run=1  (preview only)
 *
 * Reminder schedule (idempotent per member per kind via renewal_reminders table):
 *   t_minus_60  → 60 days before expiry  (gentle heads-up)
 *   t_minus_30  → 30 days before         (action needed)
 *   t_minus_7   →  7 days before         (urgent)
 *   t_zero      →  on expiry day         (final notice)
 *   t_plus_7    →  7 days after expiry   (grace period closing)
 *
 * Safe to re-run on the same day: the renewal_reminders table prevents duplicate sends.
 *
 * Wiring (one of):
 *   • Windows Task Scheduler → `php c:\xampp\htdocs\tattuintel\hosu\send_renewal_reminders.php`
 *   • Linux cron             → `0 7 * * * /usr/bin/php /var/www/hosu/send_renewal_reminders.php`
 *   • Manual button          → admin.html → Members → "Run renewal reminders" (uses key from .env)
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/membership_helpers.php';

// ── Auth ────────────────────────────────────────────────────────────────
$isCli  = PHP_SAPI === 'cli';
$dryRun = false;
$adminTriggered = false;

$includedFromApi = !empty($_SESSION['_renewal_admin_run']);

if (!$isCli) {
    // Don't override Content-Type if we were included from api.php (it set application/json)
    if (!$includedFromApi && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
    }
    $providedKey = $_GET['key'] ?? $_POST['key'] ?? '';
    $expectedKey = getenv('RENEWAL_CRON_KEY') ?: '';

    if ($includedFromApi) {
        // api.php already verified the admin session
        $adminTriggered = true;
    } elseif ($expectedKey !== '' && hash_equals($expectedKey, (string)$providedKey)) {
        // Cron key OK
    } else {
        // Try admin session
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(403);
            echo "Forbidden. Provide ?key=<RENEWAL_CRON_KEY> or sign in as admin.\n";
            exit;
        }
        $adminTriggered = true;
    }
    $dryRun = !empty($_GET['dry_run']) || !empty($_POST['dry_run']);
}

$today = new DateTimeImmutable('today');
$log = function (string $msg): void {
    $stamp = date('Y-m-d H:i:s');
    echo "[$stamp] $msg\n";
};

$log('Renewal reminder dispatcher start' . ($dryRun ? ' (DRY RUN)' : ''));

// ── Reminder windows (days from today to expiry) ────────────────────────
$windows = [
    't_minus_60' => 60,
    't_minus_30' => 30,
    't_minus_7'  => 7,
    't_zero'     => 0,
    't_plus_7'   => -7,
];

// We pull every active/expiring member once, then decide per-row which kind applies.
// Filters: must have email + expiry date, not suspended/rejected, not lifetime sentinel.
$members = $pdo->query("
    SELECT m.id, m.full_name, m.email, m.membership_number, m.expiry_date, m.status,
           m.approval_status, m.dues_paid_at, m.membership_type,
           mc.name AS category_name
      FROM members m
      LEFT JOIN membership_categories mc ON mc.id = m.category_id
     WHERE m.email IS NOT NULL AND m.email <> ''
       AND m.expiry_date IS NOT NULL
       AND m.expiry_date <> '2099-12-31'
       AND m.status NOT IN ('suspended','rejected','honorary')
       AND m.approval_status = 'approved'
")->fetchAll(PDO::FETCH_ASSOC);

$log('Candidates: ' . count($members));

$alreadyStmt = $pdo->prepare(
    'SELECT 1 FROM renewal_reminders WHERE member_id = ? AND reminder_kind = ? LIMIT 1'
);
$logStmt = $pdo->prepare(
    'INSERT INTO renewal_reminders (member_id, reminder_kind) VALUES (?, ?)'
);

$sent = 0;
$skipped = 0;
$failed = 0;
$detail = [];

foreach ($members as $m) {
    try {
        $expiry = new DateTimeImmutable($m['expiry_date']);
    } catch (Exception $e) {
        continue;
    }
    $daysToExpiry = (int)$today->diff($expiry)->format('%r%a');

    $kind = null;
    foreach ($windows as $k => $offset) {
        if ($daysToExpiry === $offset) { $kind = $k; break; }
    }
    if ($kind === null) continue;

    // Idempotency: skip if we've already sent this kind for this member
    $alreadyStmt->execute([(int)$m['id'], $kind]);
    if ($alreadyStmt->fetchColumn()) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $detail[] = sprintf('WOULD SEND: %s [%s] → %s (expires %s, %+d days)',
            $m['full_name'], $kind, $m['email'], $m['expiry_date'], $daysToExpiry);
        $sent++;
        continue;
    }

    $ok = sendRenewalEmail($m, $kind, $daysToExpiry);
    if ($ok) {
        $logStmt->execute([(int)$m['id'], $kind]);
        $sent++;
        $detail[] = "SENT: {$m['email']} [$kind]";
    } else {
        $failed++;
        $detail[] = "FAIL: {$m['email']} [$kind]";
    }
}

foreach ($detail as $d) $log($d);
$log("Done. sent=$sent skipped=$skipped failed=$failed candidates=" . count($members));

if (!$isCli && $adminTriggered) {
    // Render a small JSON tail so admin UI can parse the result
    echo "\n---\n";
    echo json_encode([
        'success'    => true,
        'sent'       => $sent,
        'skipped'    => $skipped,
        'failed'     => $failed,
        'dry_run'    => $dryRun,
        'candidates' => count($members),
    ]);
}

// ── Email composer ──────────────────────────────────────────────────────
function sendRenewalEmail(array $m, string $kind, int $daysToExpiry): bool
{
    $name     = htmlspecialchars($m['full_name'] ?? 'Member', ENT_QUOTES, 'UTF-8');
    $memNum   = htmlspecialchars($m['membership_number'] ?? '—', ENT_QUOTES, 'UTF-8');
    $category = htmlspecialchars($m['category_name'] ?? 'HOSU Member', ENT_QUOTES, 'UTF-8');
    $expiry   = $m['expiry_date'] ? date('d F Y', strtotime($m['expiry_date'])) : '—';

    $copy = renewalCopy($kind, $daysToExpiry, $expiry);
    $subject = $copy['subject'];
    $headline = $copy['headline'];
    $body = $copy['body'];
    $ctaColor = $copy['accent'];

    $renewUrl = 'https://hosu.or.ug/membership.html';
    $portalUrl = 'https://hosu.or.ug/portal.html';

    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,0.08);">
  <tr><td style="background:{$ctaColor};padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;">HOSU — Membership Renewal</h1>
    <div style="color:rgba(255,255,255,0.85);font-size:13px;margin-top:4px;">{$headline}</div>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 14px;font-size:15px;">Dear <strong>{$name}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;font-size:14px;line-height:1.55;">{$body}</p>
    <table width="100%" cellpadding="9" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;margin-bottom:22px;">
      <tr style="background:#f8fafc;"><td style="width:42%;"><strong>Membership #</strong></td><td>{$memNum}</td></tr>
      <tr><td><strong>Category</strong></td><td>{$category}</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Expires on</strong></td><td>{$expiry}</td></tr>
    </table>
    <table cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
      <tr>
        <td style="background:{$ctaColor};border-radius:8px;">
          <a href="{$renewUrl}" style="display:inline-block;padding:13px 28px;color:#fff;text-decoration:none;font-weight:700;font-size:14px;">Renew now →</a>
        </td>
        <td style="padding-left:10px;">
          <a href="{$portalUrl}" style="display:inline-block;padding:13px 22px;color:#0d4593;text-decoration:none;font-weight:600;font-size:14px;border:1px solid #c7d7fa;border-radius:8px;">Member portal</a>
        </td>
      </tr>
    </table>
    <p style="color:#777;margin:0;font-size:12.5px;line-height:1.5;">
      Need help renewing? Reply to this email or contact us at
      <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a>.
      <br>You receive this because your HOSU membership is approaching its renewal date.
    </p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU)<br>
    <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;

    return hosuMail($m['email'], $subject, $html, 'HOSU Membership');
}

function renewalCopy(string $kind, int $days, string $expiry): array
{
    switch ($kind) {
        case 't_minus_60':
            return [
                'subject'  => "HOSU Membership — renewal due in 60 days",
                'headline' => "Renewal opens — 60 days notice",
                'body'     => "Your HOSU membership is scheduled to expire on <strong>$expiry</strong>. You can renew now to keep continuous access to member benefits, the public directory, event registrations and society announcements.",
                'accent'   => '#0d4593',
            ];
        case 't_minus_30':
            return [
                'subject'  => "HOSU Membership — renewal due in 30 days",
                'headline' => "Renewal due in 30 days",
                'body'     => "A friendly reminder that your HOSU membership ends on <strong>$expiry</strong>. Renewing now avoids any gap in your membership benefits.",
                'accent'   => '#0d4593',
            ];
        case 't_minus_7':
            return [
                'subject'  => "HOSU Membership expires in 7 days — please renew",
                'headline' => "Last week to renew",
                'body'     => "Your HOSU membership expires on <strong>$expiry</strong> — less than a week away. Please renew to maintain your active status, directory listing and event eligibility.",
                'accent'   => '#d97706',
            ];
        case 't_zero':
            return [
                'subject'  => "HOSU Membership expires today",
                'headline' => "Renewal due today",
                'body'     => "Your HOSU membership reaches its expiry date today (<strong>$expiry</strong>). Renew now to remain in active status.",
                'accent'   => '#d97706',
            ];
        case 't_plus_7':
            return [
                'subject'  => "HOSU Membership has lapsed — renew to reactivate",
                'headline' => "Membership lapsed",
                'body'     => "Your HOSU membership expired on <strong>$expiry</strong> and is now lapsed. You can reactivate immediately by completing a renewal payment — no need to reapply.",
                'accent'   => '#dc2626',
            ];
    }
    return [
        'subject'  => "HOSU Membership — renewal notice",
        'headline' => "Renewal notice",
        'body'     => "Your HOSU membership expires on <strong>$expiry</strong>.",
        'accent'   => '#0d4593',
    ];
}
