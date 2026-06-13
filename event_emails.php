<?php
/**
 * event_emails.php — Event-attendee email helpers
 *
 * Pulled out of payment.php so api.php can call them (issue certificate)
 * without also loading payment.php's PesaPal switch and headers.
 *
 * Functions:
 *   sendEventReceiptEmail($pdo, $regId)        — payment receipt
 *   sendEventCertificateEmail($pdo, $regId)    — attendance certificate
 *   hosuEnsureEventCertToken($pdo, $regId)     — idempotent token issue
 */

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

if (!function_exists('hosuEnsureEventCertToken')) {
function hosuEnsureEventCertToken(PDO $pdo, int $regId): string
{
    try {
        try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_issued_at TIMESTAMP NULL DEFAULT NULL"); } catch (Exception $_e) {}
        try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN cert_token VARCHAR(64) DEFAULT NULL"); } catch (Exception $_e) {}

        $stmt = $pdo->prepare("SELECT cert_token FROM event_registrants WHERE id = ?");
        $stmt->execute([$regId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return '';
        $token = (string)($row['cert_token'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE event_registrants SET cert_token = ?, cert_issued_at = COALESCE(cert_issued_at, NOW()) WHERE id = ?")
                ->execute([$token, $regId]);
        } else {
            $pdo->prepare("UPDATE event_registrants SET cert_issued_at = NOW() WHERE id = ?")->execute([$regId]);
        }
        return $token;
    } catch (\Throwable $e) {
        error_log('hosuEnsureEventCertToken: ' . $e->getMessage());
        return '';
    }
}
}

if (!function_exists('sendEventCertificateEmail')) {
function sendEventCertificateEmail(PDO $pdo, int $regId): bool
{
    try {
        $token = hosuEnsureEventCertToken($pdo, $regId);
        if ($token === '') return false;

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
        if (!$reg || empty($reg['email']) || !filter_var($reg['email'], FILTER_VALIDATE_EMAIL)) return false;
        if ((int)($reg['qr_scanned'] ?? 0) !== 1) return false;

        $name    = htmlspecialchars($reg['full_name'], ENT_QUOTES, 'UTF-8');
        $email   = $reg['email'];
        $evTitle = htmlspecialchars(($reg['ev_title'] ?: $reg['event_title']) ?: 'HOSU Event', ENT_QUOTES, 'UTF-8');

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
            $evDateFmt = $reg['ev_date_str'] ?: ($reg['event_date'] ?: '');
        }
        $evDateFmt = htmlspecialchars($evDateFmt, ENT_QUOTES, 'UTF-8');

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'hosu.or.ug');
        $host = filter_var($host, FILTER_SANITIZE_URL) ?: 'hosu.or.ug';
        $basePath = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        $certUrl   = $protocol . '://' . $host . $basePath . '/event_certificate.php?reg=' . urlencode((string)$regId) . '&t=' . urlencode($token);
        $verifyUrl = $protocol . '://' . $host . $basePath . '/verify_event.php?r=' . urlencode((string)$regId) . '&t=' . urlencode($token);

        $subject = "HOSU Certificate of Attendance — " . html_entity_decode($evTitle, ENT_QUOTES, 'UTF-8');
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#16a34a;padding:24px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">🎓 Your HOSU Certificate is Ready</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 12px;color:#333;">Dear <strong>$name</strong>,</p>
    <p style="margin:0 0 18px;color:#555;line-height:1.55;">Thank you for attending <strong>$evTitle</strong>. Your certificate of attendance has been issued — click below to view, print, or save it as a PDF.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;margin-bottom:18px;">
      <tr style="background:#f8fafc;"><td><strong>Event</strong></td><td>$evTitle</td></tr>
      <tr><td><strong>Date</strong></td><td>$evDateFmt</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Attendance</strong></td><td style="color:#16a34a;font-weight:700;">Verified ✓</td></tr>
    </table>
    <p style="margin:18px 0 0;text-align:center;">
      <a href="$certUrl" style="display:inline-block;background:#0d4593;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">🎓 Open Certificate</a>
    </p>
    <p style="margin:18px 0 0;text-align:center;font-size:12px;color:#888;">
      Public verification: <a href="$verifyUrl" style="color:#0d4593;text-decoration:none;">verify this certificate</a>
    </p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU)<br>
    <a href="mailto:info@hosu.or.ug" style="color:#0d4593;text-decoration:none;">info@hosu.or.ug</a> &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table>
</body></html>
HTML;
        return hosuMail($email, $subject, $htmlBody);
    } catch (\Throwable $e) {
        error_log('sendEventCertificateEmail: ' . $e->getMessage());
        return false;
    }
}
}
