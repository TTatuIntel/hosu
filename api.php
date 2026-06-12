<?php
// Secure session config (matches auth.php)
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Access-Control-Allow-Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-CSRF-Token");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require 'db.php';
require_once 'upload_helper.php';
require_once 'event_helpers.php';
require_once 'membership_helpers.php';

function ensureMailer(): void
{
    static $loaded = false;
    if (!$loaded) {
        require_once __DIR__ . '/mailer.php';
        $loaded = true;
    }
}

function hosuPublicJsonCache(int $seconds = 30): void
{
    header('Cache-Control: public, max-age=' . $seconds);
}

/**
 * Ensure the site_media table and the durable-slot columns exist.
 * Safe to call repeatedly — uses IF NOT EXISTS / try-catch on each ALTER so old
 * installs upgrade in place without losing rows.
 */
function ensureSiteMediaSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT DEFAULT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(50) NOT NULL DEFAULT 'image',
        file_size INT NOT NULL DEFAULT 0,
        category VARCHAR(80) NOT NULL DEFAULT 'general',
        usage_key VARCHAR(80) DEFAULT NULL,
        alt_text VARCHAR(255) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        uploaded_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_site_media_usage (usage_key),
        INDEX idx_site_media_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach ([
        "ALTER TABLE site_media ADD COLUMN usage_key VARCHAR(80) DEFAULT NULL",
        "ALTER TABLE site_media ADD COLUMN alt_text VARCHAR(255) NOT NULL DEFAULT ''",
        "ALTER TABLE site_media ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE site_media ADD INDEX idx_site_media_usage (usage_key)",
        "ALTER TABLE site_media ADD INDEX idx_site_media_category (category)",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* already exists */ }
    }
    $done = true;
}

// Server-side idle timeout check for API calls
define('API_SESSION_IDLE_TIMEOUT', 900);
if (!empty($_SESSION['user_id']) && !empty($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > API_SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['error' => 'Session expired', 'expired' => true]);
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Get action from either GET or POST (needed early for read-only fast path)
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Auto-purge stale pending payments (older than 1 day) ──
// Skip on public read-only GET requests to keep event/home APIs fast.
$publicReadActions = [
    'get_events', 'get_home_featured', 'get_home_spotlight', 'get_home_content', 'get_home_hero', 'get_homepage_extras', 'get_site_chrome',
    'get_posts', 'get_leaders', 'get_site_stats', 'get_publications', 'get_grants',
    'get_about_stats', 'list_public_members', 'list_membership_categories',
];
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !in_array($action, $publicReadActions, true)) {
    try {
        $pdo->exec("DELETE FROM payments WHERE status = 'pending' AND paid_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $pdo->exec("DELETE FROM event_registrants WHERE payment_status = 'pending' AND registered_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
    } catch (Exception $e) {
        error_log('Auto-purge error: ' . $e->getMessage());
    }
}

// ── CSRF protection for all mutation (POST) requests ──
// Exempt actions that don't require authentication (public submissions)
$csrfExemptActions = ['register_event', 'submit_membership', 'add_comment', 'apply_grant', 'pre_register', 'cancel_pending_payment'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($action, $csrfExemptActions)) {
    if (!empty($_SESSION['user_id'])) {
        $csrfToken = trim((string) (
            $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? ''
        ));
        if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid or missing CSRF token', 'csrf_error' => true]);
            exit;
        }
    }
}

// ── Audit logging helper ──
function auditLog($pdo, $action, $entityType = null, $entityId = null, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }
}

// ── Committees + CPD: idempotent schema bootstrap ────────────────────
function ensureCommitteeTables(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS committees (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            slug        VARCHAR(80)  NOT NULL UNIQUE,
            name        VARCHAR(150) NOT NULL,
            description TEXT NULL,
            discipline  VARCHAR(60)  NOT NULL DEFAULT 'general',
            sort_order  INT          NOT NULL DEFAULT 0,
            is_active   TINYINT(1)   NOT NULL DEFAULT 1,
            created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $pdo->exec("CREATE TABLE IF NOT EXISTS committee_members (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            committee_id INT NOT NULL,
            member_id    INT NOT NULL,
            role         VARCHAR(40) NOT NULL DEFAULT 'member',
            joined_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_committee_member (committee_id, member_id),
            INDEX idx_cm_member (member_id),
            CONSTRAINT fk_cm_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
            CONSTRAINT fk_cm_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM committees")->fetchColumn();
        if ($cnt === 0) {
            $pdo->exec("INSERT INTO committees (slug, name, description, discipline, sort_order) VALUES
                ('breast-cancer','Breast Cancer Working Group','Clinical, research and advocacy work on breast cancer in Uganda.','medical-oncology',1),
                ('pediatric-oncology','Pediatric Oncology Group','Care pathways, training and family support for children with cancer.','pediatric',2),
                ('hematology','Hematology Group','Sickle cell, leukemia, lymphoma and benign hematology in Uganda.','hematology',3),
                ('palliative-care','Palliative Care Group','Symptom control, dignity in care, community palliation.','palliative',4)");
        }
    } catch (Exception $e) {
        error_log('ensureCommitteeTables: ' . $e->getMessage());
    }
    $checked = true;
}

function ensureCpdTables(PDO $pdo): void {
    static $checked = false;
    if ($checked) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS cpd_entries (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            member_id     INT NOT NULL,
            activity      VARCHAR(200) NOT NULL,
            points        INT NOT NULL DEFAULT 0,
            activity_date DATE NULL,
            source        VARCHAR(40) NOT NULL DEFAULT 'manual',
            awarded_by    INT NULL,
            awarded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cpd_member (member_id),
            CONSTRAINT fk_cpd_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
    } catch (Exception $e) {
        error_log('ensureCpdTables: ' . $e->getMessage());
    }
    $checked = true;
}

// Resolve a broadcast audience identifier to a list of {full_name,email} rows.
function hosuResolveBroadcastAudience(PDO $pdo, string $audience, int $committeeId = 0): array {
    $sql = '';
    $params = [];
    switch ($audience) {
        case 'all':
            $sql = "SELECT DISTINCT full_name, email FROM members
                    WHERE email IS NOT NULL AND email <> '' AND status NOT IN ('rejected','suspended')";
            break;
        case 'active':
            $sql = "SELECT DISTINCT full_name, email FROM members
                    WHERE email IS NOT NULL AND email <> ''
                      AND approval_status = 'approved' AND dues_paid_at IS NOT NULL
                      AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                      AND status NOT IN ('rejected','suspended')";
            break;
        case 'expired':
            $sql = "SELECT DISTINCT full_name, email FROM members
                    WHERE email IS NOT NULL AND email <> ''
                      AND expiry_date IS NOT NULL AND expiry_date < CURDATE()
                      AND status NOT IN ('rejected','suspended')";
            break;
        case 'pending':
            $sql = "SELECT DISTINCT full_name, email FROM members
                    WHERE email IS NOT NULL AND email <> ''
                      AND approval_status IN ('pending','needs_correction')";
            break;
        case 'committee':
            if (!$committeeId) return [];
            ensureCommitteeTables($pdo);
            $sql = "SELECT DISTINCT m.full_name, m.email FROM committee_members cm
                    JOIN members m ON m.id = cm.member_id
                    WHERE cm.committee_id = ? AND m.email IS NOT NULL AND m.email <> ''";
            $params[] = $committeeId;
            break;
        default:
            return [];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function hosuWrapBroadcastTemplate(string $subject, string $bodyHtml): string {
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 14px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;font-weight:700;">HOSU — Announcement</h1>
    <div style="color:rgba(255,255,255,0.85);font-size:13px;margin-top:4px;">{$safeSubject}</div>
  </td></tr>
  <tr><td style="padding:28px;color:#333;font-size:14.5px;line-height:1.65;">
    {$bodyHtml}
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU)<br>
    <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a> &middot;
    <a href="mailto:info@hosu.or.ug" style="color:#0d4593;text-decoration:none;">info@hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
}

// ── Admin notification email ──────────────────────────────────────────
function notifyAdmin(string $subject, string $htmlBody): void {
    ensureMailer();
    hosuMail('info@hosu.or.ug', $subject, $htmlBody, 'HOSU Website');
}

// ── Membership pending acknowledgment ────────────────────────────────
function sendMembershipPendingEmail(string $toEmail, string $name, string $membershipType, float $amount, string $receiptNum): void {
    ensureMailer();
    $safeName    = htmlspecialchars($name,           ENT_QUOTES, 'UTF-8');
    $safeType    = htmlspecialchars($membershipType, ENT_QUOTES, 'UTF-8');
    $safeReceipt = htmlspecialchars($receiptNum,     ENT_QUOTES, 'UTF-8');
    $safeAmount  = number_format($amount, 0, '.', ',');
    $subject = "HOSU Membership Application Received — $safeReceipt";
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Application Received</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 12px;">Dear <strong>{$safeName}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;">Thank you for applying for HOSU membership. Your application has been received and is currently under review. You will be notified once your payment is verified.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Reference #</strong></td><td>{$safeReceipt}</td></tr>
      <tr><td><strong>Membership Type</strong></td><td>{$safeType}</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Amount</strong></td><td>UGX {$safeAmount}</td></tr>
      <tr><td><strong>Status</strong></td><td style="color:#d97706;font-weight:700;">Pending Verification</td></tr>
    </table>
    <p style="color:#555;margin:20px 0 0;font-size:13px;">If you have questions, contact us at <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a>.</p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU) &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
    hosuMail($toEmail, $subject, $html, 'HOSU Membership');
}

// ── Member status change notification ────────────────────────────────
function sendMemberStatusEmail(string $toEmail, string $name, string $status): void {
    ensureMailer();
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    if ($status === 'active') {
        $subject   = 'HOSU Membership Approved — Welcome!';
        $statusHtml = '<td style="color:#27ae60;font-weight:700;">Active ✓</td>';
        $bodyMsg    = 'We are pleased to inform you that your HOSU membership has been <strong>approved and activated</strong>. Welcome to the Haematology &amp; Oncology Society of Uganda!';
    } elseif ($status === 'rejected') {
        $subject   = 'HOSU Membership Application Update';
        $statusHtml = '<td style="color:#e63946;font-weight:700;">Not Approved</td>';
        $bodyMsg    = 'After review, we are unable to approve your membership application at this time. Please contact us at <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a> for more information.';
    } elseif ($status === 'expired') {
        $subject   = 'HOSU Membership Expired — Renewal Needed';
        $statusHtml = '<td style="color:#d97706;font-weight:700;">Expired</td>';
        $bodyMsg    = 'Your HOSU membership has expired. Please renew your membership to continue enjoying HOSU benefits. Visit <a href="https://hosu.or.ug/membership.html" style="color:#0d4593;">hosu.or.ug/membership.html</a> to renew.';
    } else {
        return; // No email for other statuses
    }
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Membership Update</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 12px;">Dear <strong>{$safeName}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;">{$bodyMsg}</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Membership Status</strong></td>{$statusHtml}</tr>
    </table>
    <p style="color:#555;margin:20px 0 0;font-size:13px;">Questions? Email <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a></p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU) &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
    hosuMail($toEmail, $subject, $html, 'HOSU Membership');
}

// ── Grant application acknowledgment ─────────────────────────────────
function sendGrantAckEmail(string $toEmail, string $name, string $grantTitle): void {
    ensureMailer();
    $safeName  = htmlspecialchars($name,       ENT_QUOTES, 'UTF-8');
    $safeGrant = htmlspecialchars($grantTitle, ENT_QUOTES, 'UTF-8');
    $subject   = "HOSU Grant Application Received — {$safeGrant}";
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Grant Application Received</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 12px;">Dear <strong>{$safeName}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;">Thank you for applying for the <strong>{$safeGrant}</strong> grant opportunity. Your application has been received and will be reviewed by the HOSU team. You will be notified of the outcome by email.</p>
    <p style="color:#555;font-size:13px;">Questions? Email <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a></p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU) &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
    hosuMail($toEmail, $subject, $html, 'HOSU Grants');
}

// ── Grant application status update ──────────────────────────────────
function sendGrantStatusEmail(string $toEmail, string $name, string $grantTitle, string $status): void {
    ensureMailer();
    $safeName  = htmlspecialchars($name,       ENT_QUOTES, 'UTF-8');
    $safeGrant = htmlspecialchars($grantTitle, ENT_QUOTES, 'UTF-8');
    if ($status === 'approved') {
        $subject = "HOSU Grant Application Approved — {$safeGrant}";
        $msg     = "Congratulations! Your application for the <strong>{$safeGrant}</strong> grant has been <strong style=\"color:#27ae60;\">approved</strong>. The HOSU team will contact you with next steps.";
    } elseif ($status === 'rejected') {
        $subject = "HOSU Grant Application Update — {$safeGrant}";
        $msg     = "After careful review, we regret to inform you that your application for the <strong>{$safeGrant}</strong> grant was not successful at this time. We encourage you to apply for future opportunities.";
    } else {
        return;
    }
    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Grant Application Update</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 12px;">Dear <strong>{$safeName}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;">{$msg}</p>
    <p style="color:#555;font-size:13px;">Questions? Email <a href="mailto:info@hosu.or.ug" style="color:#0d4593;">info@hosu.or.ug</a></p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU) &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
    hosuMail($toEmail, $subject, $html, 'HOSU Grants');
}

// ── Email receipt helper ──────────────────────────────────────────────
function sendReceiptEmail($pdo, $paymentId, $receiptToken) {
    ensureMailer();
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, m.full_name, m.email, m.phone
            FROM payments p
            JOIN members m ON m.id = p.member_id
            WHERE p.id = ? AND p.receipt_token = ?
        ");
        $stmt->execute([$paymentId, $receiptToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return false;

        $name    = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');
        $email   = $row['email'];
        $amount  = number_format((float)$row['amount'], 0, '.', ',');
        $currency = htmlspecialchars($row['currency'] ?? 'UGX', ENT_QUOTES, 'UTF-8');
        $receipt = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $method  = htmlspecialchars($row['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
        $paidAt  = $row['paid_at'] ?? date('Y-m-d H:i:s');
        $type    = $row['payment_type'] ?? 'payment';

        $typeLabels = [
            'membership'         => 'Membership Payment',
            'event_registration' => 'Event Registration',
            'donation'           => 'Donation',
        ];
        $typeLabel = $typeLabels[$type] ?? 'Payment';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = filter_var($host, FILTER_SANITIZE_URL) ?: 'localhost';
        $receiptUrl = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/receipt.php?token=' . urlencode($receiptToken);

        $subject = "HOSU Receipt — $receipt";
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:24px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — $typeLabel Receipt</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 12px;color:#333;">Dear <strong>$name</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Thank you for your $typeLabel. Here is your payment receipt.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Receipt #</strong></td><td>$receipt</td></tr>
      <tr><td><strong>Amount</strong></td><td>$currency $amount</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Method</strong></td><td>$method</td></tr>
      <tr><td><strong>Date</strong></td><td>$paidAt</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Status</strong></td><td style="color:#27ae60;font-weight:700;">Verified</td></tr>
    </table>
    <p style="margin:24px 0 0;text-align:center;">
      <a href="$receiptUrl" style="display:inline-block;background:#e63946;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">View Full Receipt</a>
    </p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU)<br>
    Contact: <a href="mailto:info@hosu.or.ug" style="color:#0d4593;text-decoration:none;">info@hosu.or.ug</a> &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a><br>
    This is an auto-generated receipt. Please do not reply to this email.
  </td></tr>
</table>
</body></html>
HTML;

        return hosuMail($email, $subject, $htmlBody);
    } catch (Exception $e) {
        return false;
    }
}

switch ($action) {
    case 'check_login':
        if (!empty($_SESSION['user_id'])) {
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'username' => $_SESSION['username'] ?? '',
                    'role' => $_SESSION['user_role'] ?? 'member',
                ]
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;
        
    case 'get_posts':
    try {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        // Get total count
        $total = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT 
                p.*, 
                DATE_FORMAT(p.created_at, '%M %e, %Y') as formatted_date,
                COUNT(c.id) as comment_count 
            FROM posts p 
            LEFT JOIN comments c ON p.id = c.post_id 
            GROUP BY p.id 
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get 2 most recent comments for each post
        foreach ($posts as &$post) {
            $stmt = $pdo->prepare("
                SELECT 
                    *,
                    DATE_FORMAT(created_at, '%M %e, %Y') as formatted_date
                FROM comments 
                WHERE post_id = ? 
                ORDER BY created_at DESC 
                LIMIT 2
            ");
            $stmt->execute([$post['id']]);
            $post['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['posts' => $posts, 'total' => $total, 'page' => $page, 'limit' => $limit]);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Failed to fetch posts']);
    }
    break;
        
    case 'get_comments':
    $postId = $_GET['post_id'] ?? 0;
    try {
        $stmt = $pdo->prepare("
            SELECT 
                *,
                DATE_FORMAT(created_at, '%M %e, %Y') as formatted_date
            FROM comments 
            WHERE post_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$postId]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comments);
    } catch (PDOException $e) {
        error_log('API: ' . $e->getMessage()); echo json_encode(['error' => 'Failed to fetch comments']);
    }
    break;
        
case 'create_post':
    // Require admin authentication
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        break;
    }
    try {
        // Handle post image upload (secure)
        $imagePath = '';
        if (isset($_FILES['image'])) {
            $uploaded = secureUpload($_FILES['image'], 'uploads/posts/');
            if ($uploaded) $imagePath = $uploaded;
        }

        // Handle avatar upload (secure)
        $avatarPath = '';
        if (isset($_FILES['avatar'])) {
            $uploaded = secureUpload($_FILES['avatar'], 'uploads/avatars/');
            if ($uploaded) $avatarPath = $uploaded;
        }

        // Basic validation
        if (empty($_POST['title'])) {
            throw new Exception('Title is required');
        }
        if (empty($_POST['content'])) {
            throw new Exception('Content is required');
        }

        // Insert into posts table
        $stmt = $pdo->prepare("
            INSERT INTO posts (title, content, category, author, image, avatar, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $_POST['title'],
            $_POST['content'],
            $_POST['category'] ?? 'General',
            $_POST['author'] ?? 'Anonymous',
            $imagePath,
            $avatarPath
        ]);

        if ($result) {
            auditLog($pdo, 'create_post', 'post', $pdo->lastInsertId(), $title);
            echo json_encode([
                'success' => true,
                'post_id' => $pdo->lastInsertId()
            ]);
        } else {
            throw new Exception('Failed to insert post');
        }

    } catch (Exception | PDOException $e) {
        error_log('API: ' . $e->getMessage()); echo json_encode(['error' => 'Server error']);
    }
    break;

    case 'delete_post':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            $stmt = $pdo->prepare("SELECT image, avatar FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (['image', 'avatar'] as $col) {
                    if (!empty($row[$col]) && strpos($row[$col], 'uploads/') === 0
                        && strpos($row[$col], 'default') === false && file_exists($row[$col])) {
                        @unlink($row[$col]);
                    }
                }
            }
            $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
            auditLog($pdo, 'delete_post', 'post', $id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_post':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Post ID required']); break; }
        try {
            if (empty($_POST['title']) || empty($_POST['content'])) {
                throw new Exception('Title and content are required');
            }
            $sets = ['title = ?', 'content = ?', 'category = ?', 'author = ?'];
            $params = [$_POST['title'], $_POST['content'], $_POST['category'] ?? 'General', $_POST['author'] ?? 'Anonymous'];

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = secureUpload($_FILES['image'], 'uploads/posts/');
                if ($uploaded) {
                    $sets[] = 'image = ?';
                    $params[] = $uploaded;
                }
            }
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploaded = secureUpload($_FILES['avatar'], 'uploads/avatars/');
                if ($uploaded) {
                    $sets[] = 'avatar = ?';
                    $params[] = $uploaded;
                }
            }
            $params[] = $id;
            $pdo->prepare("UPDATE posts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            echo json_encode(['success' => true]);
        } catch (Exception | PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_events':
        try {
            hosuPublicJsonCache(30);
            echo json_encode(['success' => true, 'eventsData' => fetchEventsPagePayload($pdo)]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch events']);
        }
        break;

    case 'delete_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = $_POST['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            // Remove image file if it exists in uploads
            $stmt = $pdo->prepare("SELECT image FROM events WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['image']) && strpos($row['image'], 'uploads/') === 0 && file_exists($row['image'])) {
                @unlink($row['image']);
            }
            // Cascade: remove all gallery images for this event
            try {
                $g = $pdo->prepare("SELECT image_path FROM event_images WHERE event_id = ?");
                $g->execute([$id]);
                foreach ($g->fetchAll(PDO::FETCH_COLUMN) as $p) {
                    if (!empty($p) && strpos($p, 'uploads/') === 0 && file_exists($p)) @unlink($p);
                }
                $pdo->prepare("DELETE FROM event_images WHERE event_id = ?")->execute([$id]);
            } catch (Exception $_e) {}
            try {
                $m = $pdo->prepare("SELECT media_path, media_type FROM event_media WHERE event_id = ?");
                $m->execute([$id]);
                foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                    if ($mr['media_type'] === 'document' && strpos($mr['media_path'], 'uploads/') === 0 && file_exists($mr['media_path'])) {
                        @unlink($mr['media_path']);
                    }
                }
                $pdo->prepare("DELETE FROM event_media WHERE event_id = ?")->execute([$id]);
            } catch (Exception $_e) {}
            try {
                $pdo->prepare('DELETE FROM event_live_content WHERE event_id = ?')->execute([$id]);
            } catch (Exception $_e) {}
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$id]);
            auditLog($pdo, 'delete_event', 'event', $id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_events_admin':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // Ensure date_start / date_end / is_free / event_fee columns exist (safe migration)
            foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }

            autoExpirePastEvents($pdo);
            migrateEventSchema($pdo);
            $stmt = $pdo->query("SELECT id, title, type, status, category, date, date_start, date_end, location, image, imageAlt, description, countdown, featured, pinned, home_priority, display_start, display_end, display_for_event, speakers, highlights, announcements, live_message, live_cta_label, live_cta_url, recap_cta_label, show_live_on_home, is_free, event_fee, created_at, updated_at FROM events ORDER BY created_at DESC");
            echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_event_registrants':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_GET['event_id'] ?? $_POST['event_id'] ?? '');
            if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); break; }
            foreach (['qr_scanned TINYINT(1) NOT NULL DEFAULT 0', 'scanned_at TIMESTAMP NULL DEFAULT NULL'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }
            $stmt = $pdo->prepare("
                SELECT id, event_id, full_name, email, phone, profession, institution,
                       amount, currency, payment_method, transaction_ref,
                       status, payment_status,
                       receipt_number, receipt_token,
                       qr_scanned,
                       DATE_FORMAT(registered_at,'%d %b %Y %H:%i') as registered_date,
                       DATE_FORMAT(registered_at,'%Y-%m-%d %H:%i:%s') as registered_at_iso,
                       DATE_FORMAT(scanned_at,'%d %b %Y %H:%i') as attended_date,
                       DATE_FORMAT(scanned_at,'%Y-%m-%d %H:%i:%s') as scanned_at_iso
                FROM event_registrants
                WHERE event_id = ?
                ORDER BY registered_at DESC
            ");
            $stmt->execute([$eventId]);
            $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN payment_status='verified' THEN 1 ELSE 0 END) as verified,
                       SUM(CASE WHEN qr_scanned=1 THEN 1 ELSE 0 END) as attended,
                       SUM(amount) as revenue
                FROM event_registrants WHERE event_id=?
            ");
            $countStmt->execute([$eventId]);
            $stats = $countStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'registrants' => $registrants,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'verified' => (int)($stats['verified'] ?? 0),
                    'attended' => (int)($stats['attended'] ?? 0),
                    'revenue' => (float)($stats['revenue'] ?? 0)
                ]
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'mark_registrant_attendance':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['registrant_id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid registrant']); break; }
            $attended = !empty($_POST['attended']) && $_POST['attended'] !== '0' ? 1 : 0;
            foreach (['qr_scanned TINYINT(1) NOT NULL DEFAULT 0', 'scanned_at TIMESTAMP NULL DEFAULT NULL'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }
            $pdo->prepare(
                'UPDATE event_registrants SET qr_scanned = ?, scanned_at = ' . ($attended ? 'NOW()' : 'NULL') . ' WHERE id = ?'
            )->execute([$attended, $id]);
            auditLog($pdo, 'mark_registrant_attendance', 'event_registrant', (string)$id, $attended ? 'attended' : 'not_attended');
            echo json_encode(['success' => true, 'attended' => (bool)$attended]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'export_event_registrants':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_GET['event_id'] ?? $_POST['event_id'] ?? '');
            if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); break; }
            $evStmt = $pdo->prepare('SELECT title, date FROM events WHERE id = ? LIMIT 1');
            $evStmt->execute([$eventId]);
            $evRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: ['title' => $eventId, 'date' => ''];
            $stmt = $pdo->prepare("
                SELECT full_name, email, phone, profession, institution,
                       amount, payment_method, payment_status, receipt_number,
                       qr_scanned, registered_at, scanned_at
                FROM event_registrants WHERE event_id = ? ORDER BY registered_at ASC
            ");
            $stmt->execute([$eventId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="event-registrants-' . preg_replace('/[^a-z0-9_-]+/i', '-', $eventId) . '.csv"');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Event', $evRow['title'] ?? $eventId]);
            fputcsv($out, ['Event Date', $evRow['date'] ?? '']);
            fputcsv($out, []);
            fputcsv($out, ['Name', 'Email', 'Phone', 'Profession', 'Institution', 'Amount (UGX)', 'Payment Method', 'Payment Status', 'Receipt #', 'Attended', 'Registered At', 'Checked In At']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['full_name'] ?? '',
                    $r['email'] ?? '',
                    $r['phone'] ?? '',
                    $r['profession'] ?? '',
                    $r['institution'] ?? '',
                    $r['amount'] ?? 0,
                    $r['payment_method'] ?? '',
                    $r['payment_status'] ?? '',
                    $r['receipt_number'] ?? '',
                    !empty($r['qr_scanned']) ? 'Yes' : 'No',
                    $r['registered_at'] ?? '',
                    $r['scanned_at'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_event_reg_counts':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT event_id, COUNT(*) as reg_count
                FROM event_registrants
                WHERE event_id != ''
                GROUP BY event_id
            ");
            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['event_id']] = (int)$row['reg_count'];
            }
            echo json_encode(['success' => true, 'counts' => $counts]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'verify_registrant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','verified','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['registrant_id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) {
                http_response_code(400); echo json_encode(['error' => 'Invalid input']); break;
            }
            $pdo->prepare("UPDATE event_registrants SET payment_status=? WHERE id=?")->execute([$status, $id]);
            // Send email on verified — fetch row and send event registration receipt
            if ($status === 'verified') {
                $erRow = $pdo->prepare("SELECT full_name, email, event_title, event_date, receipt_number, receipt_token, amount, payment_method FROM event_registrants WHERE id=?");
                $erRow->execute([$id]);
                $er = $erRow->fetch(PDO::FETCH_ASSOC);
                if ($er && !empty($er['email']) && filter_var($er['email'], FILTER_VALIDATE_EMAIL)) {
                    $safeName   = htmlspecialchars($er['full_name'],   ENT_QUOTES, 'UTF-8');
                    $safeEvent  = htmlspecialchars($er['event_title'], ENT_QUOTES, 'UTF-8');
                    $safeDate   = htmlspecialchars($er['event_date'],  ENT_QUOTES, 'UTF-8');
                    $safeRec    = htmlspecialchars($er['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
                    $safeMethod = htmlspecialchars($er['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
                    $safeAmt    = number_format((float)($er['amount'] ?? 0), 0, '.', ',');
                    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host       = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
                    $receiptUrl = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/receipt.php?token=' . urlencode($er['receipt_token'] ?? '');
                    $html = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:22px 28px;"><h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Event Registration Confirmed</h1></td></tr>
  <tr><td style="padding:28px;">
    <p style="color:#333;margin:0 0 12px;">Dear <strong>{$safeName}</strong>,</p>
    <p style="color:#555;margin:0 0 20px;">Your payment for <strong>{$safeEvent}</strong> has been verified. You are now confirmed for the event.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Receipt #</strong></td><td>{$safeRec}</td></tr>
      <tr><td><strong>Event</strong></td><td>{$safeEvent}</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Date</strong></td><td>{$safeDate}</td></tr>
      <tr><td><strong>Amount</strong></td><td>UGX {$safeAmt}</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Method</strong></td><td>{$safeMethod}</td></tr>
      <tr><td><strong>Status</strong></td><td style="color:#27ae60;font-weight:700;">Verified ✓</td></tr>
    </table>
    <p style="margin:24px 0 0;text-align:center;"><a href="{$receiptUrl}" style="display:inline-block;background:#e63946;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">View Receipt</a></p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU) &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a>
  </td></tr>
</table></body></html>
HTML;
                    ensureMailer();
                    hosuMail($er['email'], "HOSU Event Registration Confirmed — {$safeEvent}", $html, 'HOSU Events');
                }
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_registrant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['registrant_id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            $pdo->prepare("DELETE FROM event_registrants WHERE id=?")->execute([$id]);
            auditLog($pdo, 'delete_registrant', 'event_registrant', (string)$id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'add_comment':
        try {
            $postId = $_POST['post_id'] ?? 0;
            $author = $_POST['author'] ?? '';
            $content = $_POST['content'] ?? '';
            
            // Insert comment
            $stmt = $pdo->prepare("
                INSERT INTO comments (post_id, author, content, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$postId, $author, $content]);
            
            // Update comment count in posts table
            $stmt = $pdo->prepare("
                UPDATE posts 
                SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = ?) 
                WHERE id = ?
            ");
            $stmt->execute([$postId, $postId]);
            
            echo json_encode([
                'success' => true,
                'comment_id' => $pdo->lastInsertId()
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); echo json_encode(['error' => 'Failed to add comment']);
        }
        break;

    case 'delete_comment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Comment ID required']); break; }
        try {
            // Get post_id before deleting so we can update the count
            $stmt = $pdo->prepare("SELECT post_id FROM comments WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Comment not found']);
                break;
            }
            $postId = $row['post_id'];
            $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$id]);
            // Update comment count
            $pdo->prepare("UPDATE posts SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = ?) WHERE id = ?")
                ->execute([$postId, $postId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_all_comments':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        try {
            $stmt = $pdo->query("
                SELECT c.*, p.title as post_title,
                    DATE_FORMAT(c.created_at, '%M %e, %Y') as formatted_date
                FROM comments c
                LEFT JOIN posts p ON c.post_id = p.id
                ORDER BY c.created_at DESC
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Members ──────────────────────────────────────────────────────────
    case 'register_member':
        try {
            // Ensure base tables exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                profession VARCHAR(100) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                membership_type VARCHAR(50) NOT NULL DEFAULT 'annual',
                status ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                payment_method VARCHAR(50) DEFAULT 'unknown',
                transaction_ref VARCHAR(100) DEFAULT '',
                transaction_id VARCHAR(100) DEFAULT '',
                proof_file VARCHAR(255) DEFAULT '',
                status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                invoice_sent TINYINT(1) NOT NULL DEFAULT 0,
                receipt_number VARCHAR(30) DEFAULT '',
                receipt_token VARCHAR(64) DEFAULT '',
                qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                scanned_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT '',
                payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership',
                membership_period VARCHAR(20) DEFAULT '1_year',
                membership_expires_at DATE NULL DEFAULT NULL,
                event_id VARCHAR(100) DEFAULT '',
                event_title VARCHAR(255) DEFAULT '',
                event_date VARCHAR(100) DEFAULT '',
                paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            // Live schema migration — add new columns to existing tables if missing
            $existingCols = array_column($pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $newCols = [
                'transaction_id'       => "ALTER TABLE payments ADD COLUMN transaction_id VARCHAR(100) DEFAULT '' AFTER transaction_ref",
                'payment_type'         => "ALTER TABLE payments ADD COLUMN payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership' AFTER notes",
                'membership_period'    => "ALTER TABLE payments ADD COLUMN membership_period VARCHAR(20) DEFAULT '1_year' AFTER payment_type",
                'membership_expires_at'=> "ALTER TABLE payments ADD COLUMN membership_expires_at DATE NULL DEFAULT NULL AFTER membership_period",
                'event_id'             => "ALTER TABLE payments ADD COLUMN event_id VARCHAR(100) DEFAULT '' AFTER membership_expires_at",
                'event_title'          => "ALTER TABLE payments ADD COLUMN event_title VARCHAR(255) DEFAULT '' AFTER event_id",
                'event_date'           => "ALTER TABLE payments ADD COLUMN event_date VARCHAR(100) DEFAULT '' AFTER event_title",
            ];
            foreach ($newCols as $col => $sql) {
                if (!in_array($col, $existingCols)) { try { $pdo->exec($sql); } catch (Exception $_e) {} }
            }

            // ── Input validation ──────────────────────────────────────────
            $name         = trim($_POST['fullName']        ?? '');
            $email        = trim($_POST['email']           ?? '');
            $paymentType  = trim($_POST['paymentType']     ?? 'membership');
            $memPeriod    = trim($_POST['membershipPeriod']?? '1_year');
            $txId         = trim($_POST['transactionId']   ?? '');
            $txRef        = trim($_POST['transactionRef']  ?? '');
            $eventId      = trim($_POST['eventId']         ?? '');
            $eventTitle   = trim($_POST['eventTitle']      ?? '');
            $eventDate    = trim($_POST['eventDate']       ?? '');

            if (!in_array($paymentType, ['membership','event_registration','donation'])) $paymentType = 'membership';
            if (!in_array($memPeriod, ['1_year','2_years','3_years','lifetime'])) $memPeriod = '1_year';

            // Amount: prefer submitted value; fall back to plan price for membership
            $planPrices = ['1_year'=>100000,'2_years'=>180000,'3_years'=>250000,'lifetime'=>500000];
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0 && $paymentType === 'membership') {
                $amount = $planPrices[$memPeriod] ?? 100000;
            }

            // Calendar-year-end expiry rule (Improvement Plan §6, §12, roll-forward)
            $expiresAt = ($paymentType === 'membership') ? hosuMembershipExpiry($memPeriod) : null;

            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and valid email are required.']);
                break;
            }

            // Prevent duplicate event registrations (same email + same event)
            if ($paymentType === 'event_registration' && $eventId) {
                // Ensure event_registrants table exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS event_registrants (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(100) NOT NULL,
                    event_title VARCHAR(255) NOT NULL DEFAULT '',
                    event_date VARCHAR(100) DEFAULT '',
                    full_name VARCHAR(150) NOT NULL,
                    email VARCHAR(150) NOT NULL,
                    phone VARCHAR(30) DEFAULT '',
                    profession VARCHAR(100) DEFAULT '',
                    institution VARCHAR(200) DEFAULT '',
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                    payment_method VARCHAR(50) DEFAULT 'free',
                    transaction_ref VARCHAR(100) DEFAULT '',
                    transaction_id VARCHAR(100) DEFAULT '',
                    proof_file VARCHAR(255) DEFAULT '',
                    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
                    payment_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified',
                    receipt_number VARCHAR(30) DEFAULT '',
                    receipt_token VARCHAR(64) DEFAULT '',
                    qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                    scanned_at TIMESTAMP NULL DEFAULT NULL,
                    notes TEXT DEFAULT '',
                    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

                // Live schema migration for older event_registrants tables
                $erCols = array_column($pdo->query("DESCRIBE event_registrants")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                if (!in_array('qr_scanned', $erCols)) {
                    try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN qr_scanned TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_token"); } catch (Exception $_e) {}
                }
                if (!in_array('scanned_at', $erCols)) {
                    try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN scanned_at TIMESTAMP NULL DEFAULT NULL AFTER qr_scanned"); } catch (Exception $_e) {}
                }

                $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrants WHERE email = ? AND event_id = ?");
                $dupStmt->execute([$email, $eventId]);
                if ($dupStmt->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => 'You are already registered for this event with this email address.']);
                    break;
                }

                // Handle payment proof upload for event registration (secure)
                $proofPath = '';
                if (isset($_FILES['paymentProof'])) {
                    $uploaded = secureUpload($_FILES['paymentProof'], 'uploads/payments/', true);
                    if ($uploaded) $proofPath = $uploaded;
                }

                // Determine if this is a free or paid event
                $isFreeEvent = ($amount <= 0);
                $payStatus = $isFreeEvent ? 'verified' : 'pending';

                // Insert into event_registrants (NOT members table)
                $payMethod = trim($_POST['paymentMethod'] ?? ($isFreeEvent ? 'free' : 'unknown'));
                $stmt = $pdo->prepare("INSERT INTO event_registrants
                    (event_id, event_title, event_date, full_name, email, phone, profession, institution,
                     amount, currency, payment_method, transaction_ref, transaction_id, proof_file,
                     status, payment_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'confirmed',?)");
                $stmt->execute([
                    $eventId, $eventTitle, $eventDate,
                    $name, $email,
                    trim($_POST['phone'] ?? ''),
                    trim($_POST['profession'] ?? ''),
                    trim($_POST['institution'] ?? ''),
                    $amount, 'UGX', $payMethod,
                    $txRef, $txId, $proofPath,
                    $payStatus
                ]);
                $regId = (int)$pdo->lastInsertId();

                // Generate receipt
                $receiptNum   = 'HOSU-EVT-' . date('Y') . '-' . str_pad($regId, 5, '0', STR_PAD_LEFT);
                $receiptToken = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE event_registrants SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $regId]);

                echo json_encode(['success' => true, 'registrant_id' => $regId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
                break;
            }

            // membership_type for members table: period label for members, type for others
            $membershipTypeLabel = $paymentType === 'membership' ? $memPeriod : $paymentType;

            $pdo->beginTransaction();

            // Resolve category from profession slug if portal categories table is populated
            $catId = null;
            $profSlug = trim($_POST['profession'] ?? '');
            if ($profSlug !== '') {
                try {
                    $catStmt = $pdo->prepare("SELECT id FROM membership_categories WHERE slug = ? LIMIT 1");
                    $catStmt->execute([$profSlug]);
                    $catId = $catStmt->fetchColumn() ?: null;
                } catch (Exception $_e) { /* membership_categories may not exist yet — ignore */ }
            }

            $stmt = $pdo->prepare("INSERT INTO members
                (full_name, email, phone, profession, institution, membership_type,
                 category_id, expiry_date, approval_status, status)
                VALUES (?,?,?,?,?,?,?,?,'pending','pending')");
            $stmt->execute([
                $name, $email,
                trim($_POST['phone']       ?? ''),
                trim($_POST['profession']  ?? ''),
                trim($_POST['institution'] ?? ''),
                $membershipTypeLabel,
                $catId,
                $expiresAt
            ]);
            $memberId = (int)$pdo->lastInsertId();

            // Stamp a stable membership number now (HOSU-YYYY-#### form)
            try {
                $memNum = hosuMembershipNumber($memberId);
                $pdo->prepare("UPDATE members SET membership_number = ? WHERE id = ?")->execute([$memNum, $memberId]);
            } catch (Exception $_e) {}

            // Handle payment proof upload (secure)
            $proofPath = '';
            if (isset($_FILES['paymentProof'])) {
                $uploaded = secureUpload($_FILES['paymentProof'], 'uploads/payments/', true);
                if ($uploaded) $proofPath = $uploaded;
            }

            $stmt = $pdo->prepare("INSERT INTO payments
                (member_id, amount, currency, payment_method, transaction_ref, transaction_id, proof_file,
                 payment_type, membership_period, membership_expires_at, event_id, event_title, event_date)
                VALUES (?,?,'UGX',?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $memberId, $amount,
                trim($_POST['paymentMethod'] ?? 'unknown'),
                $txRef, $txId, $proofPath,
                $paymentType, $memPeriod, $expiresAt,
                $eventId, $eventTitle, $eventDate
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // Generate unique receipt number and one-time token
            $typePrefix = ['membership'=>'MEM','event_registration'=>'EVT','donation'=>'DON'];
            $receiptNum   = 'HOSU-' . ($typePrefix[$paymentType] ?? 'PAY') . '-' . date('Y') . '-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
            $receiptToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE payments SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $paymentId]);

            // Auto-verify event registrations and donations — no gateway used, confirmation is instant
            if (in_array($paymentType, ['event_registration', 'donation'])) {
                $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW() WHERE id=?")->execute([$paymentId]);
                $pdo->prepare("UPDATE members  SET status='active'             WHERE id=?")->execute([$memberId]);
            }

            $pdo->commit();

            // Send receipt email for auto-verified payments
            if (in_array($paymentType, ['event_registration', 'donation'])) {
                sendReceiptEmail($pdo, $paymentId, $receiptToken);
            }

            // For membership: send pending acknowledgment to applicant + admin alert
            if ($paymentType === 'membership') {
                sendMembershipPendingEmail($email, $name, $memPeriod, $amount, $receiptNum);
                $safeAdminName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                $safeAdminEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
                notifyAdmin(
                    "New Membership Application — {$safeAdminName}",
                    "<p>A new membership application has been submitted.</p>"
                    . "<table style='font-size:14px;border-collapse:collapse;'>"
                    . "<tr><td style='padding:6px 12px;'><strong>Name</strong></td><td>{$safeAdminName}</td></tr>"
                    . "<tr><td style='padding:6px 12px;'><strong>Email</strong></td><td><a href='mailto:{$safeAdminEmail}'>{$safeAdminEmail}</a></td></tr>"
                    . "<tr><td style='padding:6px 12px;'><strong>Type</strong></td><td>" . htmlspecialchars($memPeriod, ENT_QUOTES, 'UTF-8') . "</td></tr>"
                    . "<tr><td style='padding:6px 12px;'><strong>Amount</strong></td><td>UGX " . number_format($amount, 0) . "</td></tr>"
                    . "<tr><td style='padding:6px 12px;'><strong>Receipt #</strong></td><td>" . htmlspecialchars($receiptNum, ENT_QUOTES, 'UTF-8') . "</td></tr>"
                    . "</table>"
                );
            }

            echo json_encode(['success' => true, 'member_id' => $memberId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Pre-register: save member+payment as PENDING before gateway call ─
    case 'pre_register':
        try {
            // Ensure tables + columns exist (wrapped in separate try/catch so DDL failures don't block payment)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS members (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    full_name VARCHAR(150) NOT NULL,
                    email VARCHAR(150) NOT NULL,
                    phone VARCHAR(30) DEFAULT '',
                    profession VARCHAR(100) DEFAULT '',
                    institution VARCHAR(200) DEFAULT '',
                    membership_type VARCHAR(50) NOT NULL DEFAULT 'annual',
                    status ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    member_id INT NOT NULL,
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                    payment_method VARCHAR(50) DEFAULT 'unknown',
                    transaction_ref VARCHAR(100) DEFAULT '',
                    transaction_id VARCHAR(100) DEFAULT '',
                    proof_file VARCHAR(255) DEFAULT '',
                    status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                    invoice_sent TINYINT(1) NOT NULL DEFAULT 0,
                    receipt_number VARCHAR(30) DEFAULT '',
                    receipt_token VARCHAR(64) DEFAULT '',
                    qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                    scanned_at TIMESTAMP NULL DEFAULT NULL,
                    notes TEXT DEFAULT '',
                    payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership',
                    membership_period VARCHAR(20) DEFAULT '1_year',
                    membership_expires_at DATE NULL DEFAULT NULL,
                    event_id VARCHAR(100) DEFAULT '',
                    event_title VARCHAR(255) DEFAULT '',
                    event_date VARCHAR(100) DEFAULT '',
                    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Live schema migration — add new columns to existing tables if missing
                $existingCols = array_column($pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                $newCols = [
                    'transaction_id'        => "ALTER TABLE payments ADD COLUMN transaction_id VARCHAR(100) DEFAULT '' AFTER transaction_ref",
                    'payment_type'          => "ALTER TABLE payments ADD COLUMN payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership' AFTER notes",
                    'membership_period'     => "ALTER TABLE payments ADD COLUMN membership_period VARCHAR(20) DEFAULT '1_year' AFTER payment_type",
                    'membership_expires_at' => "ALTER TABLE payments ADD COLUMN membership_expires_at DATE NULL DEFAULT NULL AFTER membership_period",
                    'event_id'              => "ALTER TABLE payments ADD COLUMN event_id VARCHAR(100) DEFAULT '' AFTER membership_expires_at",
                    'event_title'           => "ALTER TABLE payments ADD COLUMN event_title VARCHAR(255) DEFAULT '' AFTER event_id",
                    'event_date'            => "ALTER TABLE payments ADD COLUMN event_date VARCHAR(100) DEFAULT '' AFTER event_title",
                ];
                foreach ($newCols as $col => $sql) {
                    if (!in_array($col, $existingCols)) { try { $pdo->exec($sql); } catch (Exception $_e) {} }
                }
            } catch (Exception $ddlErr) {
                error_log('pre_register DDL migration: ' . $ddlErr->getMessage());
                // Continue — tables likely already exist on production
            }

            $name        = trim($_POST['fullName']         ?? '');
            $email       = trim($_POST['email']            ?? '');
            $paymentType = trim($_POST['paymentType']      ?? 'membership');
            $memPeriod   = trim($_POST['membershipPeriod'] ?? '1_year');
            $txId        = trim($_POST['transactionId']    ?? '');
            $txRef       = trim($_POST['transactionRef']   ?? $txId);
            $eventId     = trim($_POST['eventId']          ?? '');
            $eventTitle  = trim($_POST['eventTitle']       ?? '');
            $eventDate   = trim($_POST['eventDate']        ?? '');

            if (!in_array($paymentType, ['membership','event_registration','donation'])) $paymentType = 'membership';
            if (!in_array($memPeriod, ['1_year','2_years','3_years','lifetime']))        $memPeriod   = '1_year';

            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and valid email are required.']);
                break;
            }

            $planPrices = ['1_year'=>100000,'2_years'=>180000,'3_years'=>250000,'lifetime'=>500000];
            $amount     = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) $amount = $paymentType === 'membership' ? ($planPrices[$memPeriod] ?? 100000) : 50000;

            // Calendar-year-end expiry rule (Improvement Plan §6, §12, roll-forward)
            $expiresAt = ($paymentType === 'membership') ? hosuMembershipExpiry($memPeriod) : null;

            $membershipTypeLabel = $paymentType === 'membership' ? $memPeriod : $paymentType;

            $pdo->beginTransaction();

            // Reuse existing member if email already exists (e.g. repeat donor)
            $existingMember = $pdo->prepare("SELECT id FROM members WHERE email = ? LIMIT 1");
            $existingMember->execute([$email]);
            $existing = $existingMember->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $memberId = (int)$existing['id'];
                // Update profile fields in case they changed
                $pdo->prepare("UPDATE members SET full_name=?, phone=?, profession=?, institution=? WHERE id=?")
                    ->execute([
                        $name,
                        trim($_POST['phone']       ?? ''),
                        trim($_POST['profession']  ?? ''),
                        trim($_POST['institution'] ?? ''),
                        $memberId
                    ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO members (full_name, email, phone, profession, institution, membership_type, status) VALUES (?,?,?,?,?,?,'pending')");
                $stmt->execute([
                    $name, $email,
                    trim($_POST['phone']       ?? ''),
                    trim($_POST['profession']  ?? ''),
                    trim($_POST['institution'] ?? ''),
                    $membershipTypeLabel
                ]);
                $memberId = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("INSERT INTO payments
                (member_id, amount, currency, payment_method, transaction_ref, transaction_id,
                 payment_type, membership_period, membership_expires_at, event_id, event_title, event_date, status)
                VALUES (?,?,'UGX',?,?,?,?,?,?,?,?,?,'pending')");
            $stmt->execute([
                $memberId, $amount,
                trim($_POST['paymentMethod'] ?? 'unknown'),
                $txRef, $txId,
                $paymentType, $memPeriod, $expiresAt,
                $eventId, $eventTitle, $eventDate
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $typePrefix  = ['membership'=>'MEM','event_registration'=>'EVT','donation'=>'DON'];
            $receiptNum  = 'HOSU-' . ($typePrefix[$paymentType] ?? 'PAY') . '-' . date('Y') . '-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
            $receiptToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE payments SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $paymentId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'payment_id' => $paymentId, 'member_id' => $memberId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errMsg = $e->getMessage();
            error_log('pre_register error: ' . $errMsg . ' | File: ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            // Provide actionable message based on error type
            if (stripos($errMsg, 'connect') !== false || stripos($errMsg, 'SQLSTATE[HY000]') !== false) {
                echo json_encode(['error' => 'Database temporarily unavailable. Please try again in a moment.']);
            } elseif (stripos($errMsg, 'Duplicate') !== false) {
                echo json_encode(['error' => 'A record with this email already exists. Try a different email or contact info@hosu.or.ug.']);
            } else {
                echo json_encode(['error' => 'Payment setup failed. Please try again or contact info@hosu.or.ug.']);
            }
        }
        break;

    // ── Confirm payment: called immediately after gateway success ─────────
    case 'confirm_payment':
        try {
            $paymentId    = (int)(($_POST['payment_id']    ?? $_GET['payment_id']    ?? 0));
            $receiptToken = trim($_POST['receipt_token']   ?? $_GET['receipt_token'] ?? '');

            if (!$paymentId || strlen($receiptToken) !== 64) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment reference.']);
                break;
            }

            // Verify token matches payment_id to prevent tampering
            $row = $pdo->prepare("SELECT id, member_id, payment_type, membership_period, membership_expires_at
                                  FROM payments WHERE id=? AND receipt_token=? AND status='pending'");
            $row->execute([$paymentId, $receiptToken]);
            $pay = $row->fetch(PDO::FETCH_ASSOC);

            if (!$pay) {
                // Already confirmed or mismatch — check if already verified (idempotent)
                $chk = $pdo->prepare("SELECT id FROM payments WHERE id=? AND receipt_token=? AND status='verified'");
                $chk->execute([$paymentId, $receiptToken]);
                if ($chk->fetch()) {
                    echo json_encode(['success' => true, 'already_confirmed' => true]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Payment not found or already processed.']);
                }
                break;
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW() WHERE id=?")->execute([$pay['id']]);

            hosuStampMemberPaymentVerified($pdo, (int)$pay['id']);
            $pdo->commit();

            // Send receipt email
            sendReceiptEmail($pdo, $pay['id'], $receiptToken);

            echo json_encode(['success' => true, 'receipt_token' => $receiptToken]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_members':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT m.id, m.full_name, m.email, m.phone, m.profession, m.institution,
                    m.membership_type, m.status, m.approval_status, m.membership_number,
                    m.expiry_date, m.dues_paid_at, m.public_profile, m.category_id,
                    mc.name AS category_name,
                    DATE_FORMAT(m.created_at,'%d %b %Y') AS joined_date,
                    (SELECT p.membership_period FROM payments p
                        WHERE p.member_id = m.id AND p.payment_type = 'membership'
                        ORDER BY p.id DESC LIMIT 1) AS membership_period,
                    (SELECT p.membership_expires_at FROM payments p
                        WHERE p.member_id = m.id AND p.payment_type = 'membership'
                        ORDER BY p.id DESC LIMIT 1) AS membership_expires_at,
                    (SELECT p.status FROM payments p
                        WHERE p.member_id = m.id AND p.payment_type = 'membership'
                        ORDER BY p.id DESC LIMIT 1) AS payment_status
                FROM members m
                LEFT JOIN membership_categories mc ON mc.id = m.category_id
                WHERE m.membership_type IN ('1_year','2_years','3_years','lifetime')
                   OR m.membership_type = 'membership'
                ORDER BY m.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['derived_status'] = hosuMembershipStatus($row);
            }
            unset($row);
            echo json_encode(['success' => true, 'members' => $rows]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_membership_categories':
        try {
            $stmt = $pdo->query('SELECT id, slug, name, discipline, sort_order FROM membership_categories WHERE is_active = 1 ORDER BY sort_order, name');
            echo json_encode(['success' => true, 'categories' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'categories' => []]);
        }
        break;

    case 'list_public_members':
        try {
            hosuPublicJsonCache(60);
            $stmt = $pdo->query("
                SELECT m.full_name, m.institution, m.country, m.specialty, m.profession,
                    mc.name AS category_name
                FROM members m
                LEFT JOIN membership_categories mc ON mc.id = m.category_id
                WHERE m.public_profile = 1
                  AND m.approval_status = 'approved'
                  AND m.dues_paid_at IS NOT NULL
                  AND (m.expiry_date IS NULL OR m.expiry_date >= CURDATE())
                  AND m.status NOT IN ('suspended','rejected')
                ORDER BY m.full_name ASC
            ");
            echo json_encode(['success' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_member_approval':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            $approval = $_POST['approval_status'] ?? '';
            $allowed = ['pending', 'approved', 'needs_correction', 'rejected'];
            if (!$id || !in_array($approval, $allowed, true)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid input']); break;
            }
            if ($approval === 'rejected') {
                $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
                auditLog($pdo, 'reject_member', 'member', (string)$id);
                echo json_encode(['success' => true, 'deleted' => true]);
                break;
            }
            $catId = (int)($_POST['category_id'] ?? 0);
            $notes = trim($_POST['internal_notes'] ?? '');
            $sets = ['approval_status = ?'];
            $params = [$approval];
            if ($catId > 0) {
                $sets[] = 'category_id = ?';
                $params[] = $catId;
            }
            if ($notes !== '') {
                $sets[] = 'internal_notes = ?';
                $params[] = $notes;
            }
            if ($approval === 'approved') {
                $sets[] = "verified_at = COALESCE(verified_at, NOW())";
                $sets[] = 'verified_by = ?';
                $params[] = (int)$_SESSION['user_id'];
                $sets[] = "status = CASE WHEN dues_paid_at IS NOT NULL AND (expiry_date IS NULL OR expiry_date >= CURDATE()) THEN 'active' ELSE status END";
            }
            $params[] = $id;
            $pdo->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
            auditLog($pdo, 'update_member_approval', 'member', (string)$id, $approval);
            $memStmt = $pdo->prepare('SELECT full_name, email, status FROM members WHERE id = ?');
            $memStmt->execute([$id]);
            $memRow = $memStmt->fetch(PDO::FETCH_ASSOC);
            if ($memRow && !empty($memRow['email'])) {
                $notifyStatus = $approval === 'approved' ? ($memRow['status'] === 'active' ? 'active' : 'pending') : $approval;
                sendMemberStatusEmail($memRow['email'], $memRow['full_name'], $notifyStatus);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_member_status':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','active','expired','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            if ($status === 'rejected') {
                // Remove member completely if rejected
                $pdo->prepare("DELETE FROM members WHERE id=?")->execute([$id]);
                auditLog($pdo, 'delete_member', 'member', $id, 'rejected');
                echo json_encode(['success' => true, 'deleted' => true]);
                break;
            }
            $pdo->prepare("UPDATE members SET status=? WHERE id=?")->execute([$status, $id]);
            auditLog($pdo, 'update_member_status', 'member', $id, $status);
            // Notify member of status change
            $memStmt = $pdo->prepare("SELECT full_name, email FROM members WHERE id=?");
            $memStmt->execute([$id]);
            $memRow = $memStmt->fetch(PDO::FETCH_ASSOC);
            if ($memRow && !empty($memRow['email'])) {
                sendMemberStatusEmail($memRow['email'], $memRow['full_name'], $status);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'delete_member':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Invalid member id']); break; }
            $memStmt = $pdo->prepare('SELECT full_name, email FROM members WHERE id = ?');
            $memStmt->execute([$id]);
            $memRow = $memStmt->fetch(PDO::FETCH_ASSOC);
            if (!$memRow) { http_response_code(404); echo json_encode(['error' => 'Member not found']); break; }
            $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
            auditLog($pdo, 'delete_member', 'member', (string)$id, $memRow['full_name'] ?? $memRow['email'] ?? '');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'verify_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','verified','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['payment_id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$status, $id]);
            if ($status === 'verified') {
                $pdo->prepare("UPDATE payments SET paid_at = COALESCE(paid_at, NOW()) WHERE id = ?")->execute([$id]);
                hosuStampMemberPaymentVerified($pdo, $id);
                $payRow = $pdo->prepare('SELECT receipt_token FROM payments WHERE id=?');
                $payRow->execute([$id]);
                $pr = $payRow->fetch(PDO::FETCH_ASSOC);
                if (!empty($pr['receipt_token'])) {
                    sendDonationReceiptEmail($pdo, $id);
                    $memRow = $pdo->prepare('SELECT m.full_name, m.email, m.status FROM members m JOIN payments p ON p.member_id = m.id WHERE p.id = ?');
                    $memRow->execute([$id]);
                    $mr = $memRow->fetch(PDO::FETCH_ASSOC);
                    if ($mr && !empty($mr['email'])) {
                        sendMemberStatusEmail($mr['email'], $mr['full_name'], $mr['status'] === 'active' ? 'active' : 'pending');
                    }
                }
            }
            auditLog($pdo, 'verify_payment', 'payment', $id, $status);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'delete_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['payment_id'] ?? 0);
            $source = $_POST['source'] ?? 'payments';
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Invalid payment id']); break; }
            if ($source === 'event_registrants') {
                $pdo->prepare('DELETE FROM event_registrants WHERE id = ?')->execute([$id]);
                auditLog($pdo, 'delete_payment', 'event_registrant', (string)$id);
                echo json_encode(['success' => true]);
                break;
            }
            $payStmt = $pdo->prepare('SELECT id, member_id, proof_file FROM payments WHERE id = ?');
            $payStmt->execute([$id]);
            $payRow = $payStmt->fetch(PDO::FETCH_ASSOC);
            if (!$payRow) { http_response_code(404); echo json_encode(['error' => 'Payment not found']); break; }
            if (!empty($payRow['proof_file']) && strpos($payRow['proof_file'], 'uploads/') === 0 && file_exists($payRow['proof_file'])) {
                @unlink($payRow['proof_file']);
            }
            $pdo->prepare('DELETE FROM payments WHERE id = ?')->execute([$id]);
            auditLog($pdo, 'delete_payment', 'payment', (string)$id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'mark_invoice_sent':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['payment_id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'payment_id required']); break; }
            $pdo->prepare("UPDATE payments SET invoice_sent=1 WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'update_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $payId  = (int)($_POST['payment_id'] ?? 0);
            $source = $_POST['source'] ?? 'payments';
            $txId   = trim($_POST['transaction_id'] ?? '');
            $txRef  = trim($_POST['transaction_ref'] ?? '');

            if (!$payId) { http_response_code(400); echo json_encode(['error' => 'payment_id required']); break; }
            if (!in_array($source, ['payments', 'event_registrants', 'grant_application'])) {
                $source = 'payments';
            }
            // Normalise grant_application source → payments table
            $table = ($source === 'event_registrants') ? 'event_registrants' : 'payments';

            $proofPath = null;
            if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
                $uploaded = secureUpload($_FILES['proof_file'], 'uploads/payments/', true);
                if ($uploaded) $proofPath = $uploaded;
            }

            $sql  = "UPDATE $table SET transaction_id=?, transaction_ref=?";
            $vals = [$txId, $txRef];
            if ($proofPath !== null) { $sql .= ', proof_file=?'; $vals[] = $proofPath; }
            $sql .= ' WHERE id=?';
            $vals[] = $payId;
            $pdo->prepare($sql)->execute($vals);
            auditLog($pdo, 'update_payment', $table, $payId, "tx_id=$txId tx_ref=$txRef");
            echo json_encode(['success' => true, 'proof_file' => $proofPath]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'export_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT m.id, m.full_name, m.email, m.phone, m.profession, m.institution,
                    m.membership_type, m.status as member_status,
                    DATE_FORMAT(m.created_at,'%Y-%m-%d') as joined_date,
                    p.payment_type, p.membership_period,
                    DATE_FORMAT(p.membership_expires_at,'%Y-%m-%d') as membership_expires_at,
                    p.event_title, p.event_date,
                    p.amount, p.currency, p.payment_method, p.transaction_ref, p.transaction_id,
                    p.status as payment_status, p.invoice_sent, p.receipt_number,
                    DATE_FORMAT(p.paid_at,'%Y-%m-%d') as paid_date
                FROM members m
                LEFT JOIN payments p ON p.member_id = m.id
                ORDER BY m.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Remove JSON header and output CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_members_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Receipt validation (one-time QR scan) ────────────────────────────
    case 'receipt_validate':
        $token = trim($_GET['token'] ?? '');
        if (!$token || strlen($token) !== 64) { http_response_code(400); echo json_encode(['error' => 'Invalid token']); break; }
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, m.full_name, m.email, m.phone, m.membership_type, m.profession, m.institution
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.receipt_token = ?
            ");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Receipt not found']); break; }
            echo json_encode(['success' => true, 'receipt' => $row]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'receipt_scan':
        // Mark QR as scanned (one-time) — called by receipt.php server-side, not exposed to public JS
        $token = trim($_POST['token'] ?? '');
        if (!$token || strlen($token) !== 64) { http_response_code(400); echo json_encode(['error' => 'Invalid token']); break; }
        try {
            $stmt = $pdo->prepare("SELECT id, qr_scanned FROM payments WHERE receipt_token=?");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); break; }
            if (!$row['qr_scanned']) {
                $pdo->prepare("UPDATE payments SET qr_scanned=1, scanned_at=NOW() WHERE receipt_token=?")->execute([$token]);
            }
            echo json_encode(['success' => true, 'already_scanned' => (bool)$row['qr_scanned']]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Events (update) ──────────────────────────────────────────────────
    case 'get_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        $id = $_GET['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            migrateEventSchema($pdo);
            $stmt = $pdo->prepare('SELECT * FROM events WHERE id=?');
            $stmt->execute([$id]);
            $ev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Not found']); break; }
            enrichEventRow($ev);
            $events = [$ev];
            attachGalleryToEvents($events, loadEventGalleries($pdo, [$id]));
            attachMediaToEvents($events, loadEventMedia($pdo, [$id]));
            attachLiveContentToEvents($events, loadLiveContent($pdo, [$id], false), false);
            echo json_encode(['success' => true, 'event' => $events[0]]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'update_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = trim($_POST['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }

            migrateEventSchema($pdo);
            $existingStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
            $existingStmt->execute([$id]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['error' => 'Event not found']);
                break;
            }

            $contentOnly = !empty($_POST['content_only']) && $_POST['content_only'] !== '0';
            if ($contentOnly) {
                $result = saveEventOngoingContentUpdate($pdo, $id, $existing, $_POST, $_FILES);
                echo json_encode([
                    'success' => true,
                    'event' => $result['event'],
                    'drive_sync' => $result['drive_sync'],
                    'images_added_from_urls' => $result['images_added_from_urls'],
                ]);
                break;
            }

            $partial = !empty($_POST['partial']) && $_POST['partial'] !== '0';
            $mergeField = function (string $key, $fallback = '') use ($partial, $existing) {
                if ($partial && !array_key_exists($key, $_POST)) {
                    return $existing[$key] ?? $fallback;
                }
                return $_POST[$key] ?? ($existing[$key] ?? $fallback);
            };

            // Ensure is_free / event_fee / date_start / date_end columns exist (safe migration)
            foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }

            // Validate date_start / date_end
            $dateStartRaw = $mergeField('date_start', $existing['date_start'] ?? '');
            $dateEndRaw   = $mergeField('date_end', $existing['date_end'] ?? '');
            $dateStart = !empty($dateStartRaw) ? trim((string)$dateStartRaw) : null;
            $dateEnd   = !empty($dateEndRaw) ? trim((string)$dateEndRaw) : null;

            // Past events: never change stored dates — the event already happened.
            $preserveDates = (!empty($_POST['preserve_dates']) && $_POST['preserve_dates'] !== '0')
                || eventHasPassed($existing);
            if ($preserveDates) {
                $dateStart = !empty($existing['date_start']) ? trim((string) $existing['date_start']) : $dateStart;
                $dateEndRaw = $existing['date_end'] ?? '';
                $dateEnd = !empty($dateEndRaw) ? trim((string) $dateEndRaw) : null;
                $status = 'past';
                $category = 'past';
            } else {
                $status   = $mergeField('status', $existing['status'] ?? 'open');
                $category = $mergeField('category', $existing['category'] ?? 'upcoming');
                if ($dateStart) {
                    $today    = new DateTimeImmutable('today');
                    $startDt  = new DateTimeImmutable($dateStart);
                    $endDt    = $dateEnd ? new DateTimeImmutable($dateEnd) : $startDt;
                    if ($today > $endDt) {
                        $status   = 'past';
                        $category = 'past';
                    } elseif ($today >= $startDt && $today <= $endDt) {
                        $status = 'open';
                        if ($category === 'past') $category = 'current';
                    } else {
                        if ($status === 'past') $status = 'open';
                        if ($category === 'past') $category = 'upcoming';
                    }
                }
            }

            // Ensure event_images table exists (safe migration)
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS event_images (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(100) NOT NULL,
                    image_path VARCHAR(500) NOT NULL,
                    image_alt VARCHAR(255) DEFAULT '',
                    caption VARCHAR(255) DEFAULT '',
                    sort_order INT NOT NULL DEFAULT 0,
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_event_id (event_id),
                    INDEX idx_sort (sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $_e) {}

            // Handle optional new image uploads (multi via imageFiles[], legacy single via imageFile)
            $newUploads = [];
            $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
            $dir = 'uploads/events/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);

            // Multi
            if (!empty($_FILES['imageFiles']) && is_array($_FILES['imageFiles']['name'])) {
                $cnt = count($_FILES['imageFiles']['name']);
                for ($i = 0; $i < $cnt; $i++) {
                    if ($_FILES['imageFiles']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if ($_FILES['imageFiles']['size'][$i] > 5000000) continue;
                    $fi = finfo_open(FILEINFO_MIME_TYPE);
                    $ft = finfo_file($fi, $_FILES['imageFiles']['tmp_name'][$i]);
                    finfo_close($fi);
                    if (!in_array($ft, $allowed)) continue;
                    $fname = uniqid() . '_' . basename($_FILES['imageFiles']['name'][$i]);
                    if (move_uploaded_file($_FILES['imageFiles']['tmp_name'][$i], $dir . $fname)) {
                        $saved = $dir . $fname;
                        optimizeUploadedImage($saved);
                        $newUploads[] = $saved;
                    }
                }
            }

            // Legacy single
            if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $ft = finfo_file($fi, $_FILES['imageFile']['tmp_name']);
                finfo_close($fi);
                if (in_array($ft, $allowed) && $_FILES['imageFile']['size'] <= 5000000) {
                    $fname = uniqid() . '_' . basename($_FILES['imageFile']['name']);
                    if (move_uploaded_file($_FILES['imageFile']['tmp_name'], $dir . $fname)) {
                        $saved = $dir . $fname;
                        optimizeUploadedImage($saved);
                        $newUploads[] = $saved;
                    }
                }
            }

            // Handle deletion of existing extra images (delete_image_ids[])
            if (!empty($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids'])) {
                $delStmt = $pdo->prepare("SELECT id, image_path FROM event_images WHERE id = ? AND event_id = ?");
                $delExec = $pdo->prepare("DELETE FROM event_images WHERE id = ? AND event_id = ?");
                foreach ($_POST['delete_image_ids'] as $imgId) {
                    $imgId = (int)$imgId;
                    if ($imgId <= 0) continue;
                    $delStmt->execute([$imgId, $id]);
                    $row = $delStmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        if (!empty($row['image_path']) && strpos($row['image_path'], 'uploads/') === 0 && file_exists($row['image_path'])) {
                            @unlink($row['image_path']);
                        }
                        $delExec->execute([$imgId, $id]);
                    }
                }
            }

            // Decide primary image for events.image column
            $imagePath = null;
            if (!empty($newUploads)) {
                $imagePath = $newUploads[0]; // first new upload becomes primary
            } elseif (!empty($_POST['image'])) {
                $imagePath = filter_var($_POST['image'], FILTER_SANITIZE_URL);
            }

            // Insert all new uploads into event_images
            if (!empty($newUploads)) {
                try {
                    // Determine next sort_order
                    $maxStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM event_images WHERE event_id = ?");
                    $maxStmt->execute([$id]);
                    $nextOrder = (int)$maxStmt->fetchColumn() + 1;

                    // If no current gallery exists, mark first as primary
                    $hasAnyStmt = $pdo->prepare("SELECT COUNT(*) FROM event_images WHERE event_id = ?");
                    $hasAnyStmt->execute([$id]);
                    $hasAny = (int)$hasAnyStmt->fetchColumn() > 0;

                    $ins = $pdo->prepare("INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)");
                    foreach ($newUploads as $i => $path) {
                        $isPrim = (!$hasAny && $i === 0) ? 1 : 0;
                        $ins->execute([$id, $path, $_POST['imageAlt'] ?? '', $nextOrder + $i, $isPrim]);
                    }

                    // If a new file replaces primary (admin uploaded new while no kept primary), clear old primary flags so the latest takes effect
                    if (!$hasAny) {
                        // first upload is already primary
                    }
                } catch (Exception $_e) { /* gallery is best-effort */ }
            }

            $displayFields = $partial
                ? parseDisplayFields(array_merge($existing, $_POST))
                : parseDisplayFields($_POST);

            // Add image URLs to gallery without replacing existing images
            $imageUrls = [];
            if (!empty($_POST['imageUrls']) && is_array($_POST['imageUrls'])) {
                $imageUrls = $_POST['imageUrls'];
            } elseif (!empty($_POST['imageUrls'])) {
                $imageUrls = preg_split('/[\r\n,]+/', $_POST['imageUrls']);
            }
            $imagesAddedFromUrls = 0;
            if (!empty($imageUrls)) {
                $imagesAddedFromUrls = insertEventImageUrls($pdo, $id, $imageUrls, $_POST['imageAlt'] ?? '');
            }

            saveEventMediaFromRequest($pdo, $id, $_POST, $_FILES);
            if (array_key_exists('live_content_json', $_POST)) {
                saveLiveContentFromRequest($pdo, $id, $_POST);
            }
            $liveFields = parseLiveFields(array_merge($existing, $_POST));

            $featuredRaw = $mergeField('featured', $existing['featured'] ?? 0);
            $isFreeRaw = $mergeField('is_free', $existing['is_free'] ?? 1);
            $isFree = !empty($isFreeRaw) && $isFreeRaw !== '0' ? 1 : 0;
            $eventFee = $isFree ? 0 : max(0, (float)$mergeField('event_fee', $existing['event_fee'] ?? 0));

            $fields = "type=?, status=?, imageAlt=?, countdown=?, date=?, title=?, description=?, location=?, featured=?, category=?, is_free=?, event_fee=?, date_start=?, date_end=?, speakers=?, highlights=?, announcements=?, display_start=?, display_end=?, display_for_event=?, pinned=?, home_priority=?, post_event_display_days=?, live_message=?, live_cta_label=?, live_cta_url=?, drive_folder_url=?, show_live_on_home=?, show_upcoming_in_ongoing=?, recap_cta_label=?";
            $vals = [
                $mergeField('type', $existing['type'] ?? ''),
                $status,
                $mergeField('imageAlt', $existing['imageAlt'] ?? ''),
                $mergeField('countdown', $existing['countdown'] ?? ''),
                $preserveDates ? ($existing['date'] ?? '') : $mergeField('date', $existing['date'] ?? ''),
                $mergeField('title', $existing['title'] ?? ''),
                $mergeField('description', $existing['description'] ?? ''),
                $mergeField('location', $existing['location'] ?? ''),
                (!empty($featuredRaw) && $featuredRaw !== '0') ? 1 : 0,
                $category,
                $isFree,
                $eventFee,
                $dateStart,
                $dateEnd,
                $displayFields['speakers'],
                $displayFields['highlights'],
                $displayFields['announcements'],
                $displayFields['display_start'],
                $displayFields['display_end'],
                $displayFields['display_for_event'],
                $displayFields['pinned'],
                $displayFields['home_priority'],
                $displayFields['post_event_display_days'],
                $liveFields['live_message'],
                $liveFields['live_cta_label'],
                $liveFields['live_cta_url'],
                $liveFields['drive_folder_url'],
                $liveFields['show_live_on_home'],
                $liveFields['show_upcoming_in_ongoing'],
                $displayFields['recap_cta_label'],
            ];
            if ($imagePath !== null) {
                $fields .= ', image=?';
                $vals[] = $imagePath;
            }
            $vals[] = $id;
            $pdo->prepare("UPDATE events SET $fields WHERE id=?")->execute($vals);

            // Sync events.image from gallery only when uploads/deletes changed the gallery
            $galleryTouched = !empty($newUploads)
                || !empty($imageUrls)
                || (!empty($_POST['delete_image_ids']) && is_array($_POST['delete_image_ids']));
            if ($galleryTouched) {
                try {
                    $galleryStmt = $pdo->prepare(
                        'SELECT image_path FROM event_images WHERE event_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC'
                    );
                    $galleryStmt->execute([$id]);
                    $bestPath = null;
                    foreach ($galleryStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
                        if (isUsableSpotlightMediaUrl((string)$path, 'image')) {
                            $bestPath = $path;
                            break;
                        }
                    }
                    if ($bestPath) {
                        $pdo->prepare('UPDATE event_images SET is_primary = 0 WHERE event_id = ?')->execute([$id]);
                        $pdo->prepare('UPDATE event_images SET is_primary = 1 WHERE event_id = ? AND image_path = ? LIMIT 1')->execute([$id, $bestPath]);
                        $pdo->prepare('UPDATE events SET image = ? WHERE id = ?')->execute([$bestPath, $id]);
                    }
                } catch (Exception $_e) {}
            }

            $driveSync = null;
            $folderUrl = resolveEventDriveFolderUrl(array_merge($existing, $liveFields));
            if ($folderUrl !== '') {
                $folderId = extractDriveFolderId($folderUrl);
                if ($folderId !== '') {
                    invalidateDriveFolderCache($folderId);
                    $driveIds = fetchDriveFolderFileIds($folderId, true);
                    $driveSync = [
                        'ok' => count($driveIds) > 0,
                        'photo_count' => count($driveIds),
                        'folder_id' => $folderId,
                    ];
                }
            }

            $eventRow = null;
            try {
                $evStmt = $pdo->prepare('SELECT id, title, type, status, category, date, date_start, date_end, location, image, imageAlt, description, countdown, featured, pinned, home_priority, display_start, display_end, display_for_event, speakers, highlights, announcements, live_message, live_cta_label, live_cta_url, recap_cta_label, drive_folder_url, show_live_on_home, is_free, event_fee, created_at, updated_at FROM events WHERE id = ?');
                $evStmt->execute([$id]);
                $eventRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Exception $_evFetch) {}

            echo json_encode([
                'success' => true,
                'event' => $eventRow,
                'drive_sync' => $driveSync,
                'images_added_from_urls' => $imagesAddedFromUrls,
            ]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── List images attached to a single event (admin) ──
    case 'list_event_images':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_GET['event_id'] ?? $_POST['event_id'] ?? '');
            if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); break; }
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS event_images (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(100) NOT NULL,
                    image_path VARCHAR(500) NOT NULL,
                    image_alt VARCHAR(255) DEFAULT '',
                    caption VARCHAR(255) DEFAULT '',
                    sort_order INT NOT NULL DEFAULT 0,
                    is_primary TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_event_id (event_id),
                    INDEX idx_sort (sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            } catch (Exception $_e) {}
            $st = $pdo->prepare("SELECT id, image_path, image_alt, caption, sort_order, is_primary, COALESCE(caption_disabled,0) AS caption_disabled FROM event_images WHERE event_id = ? ORDER BY sort_order ASC, id ASC");
            $st->execute([$eventId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['image_path'] = str_replace('\\', '/', $r['image_path']); }
            echo json_encode(['success' => true, 'images' => $rows]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Delete a single gallery image (admin) ──
    case 'delete_event_image':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $imgId   = (int)($_POST['image_id'] ?? 0);
            $eventId = trim($_POST['event_id'] ?? '');
            if ($imgId <= 0 || !$eventId) { http_response_code(400); echo json_encode(['error' => 'image_id and event_id required']); break; }
            $st = $pdo->prepare("SELECT image_path, is_primary FROM event_images WHERE id = ? AND event_id = ?");
            $st->execute([$imgId, $eventId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Image not found']); break; }
            if (!empty($row['image_path']) && strpos($row['image_path'], 'uploads/') === 0 && file_exists($row['image_path'])) {
                @unlink($row['image_path']);
            }
            $pdo->prepare("DELETE FROM event_images WHERE id = ? AND event_id = ?")->execute([$imgId, $eventId]);

            // If we removed the primary, promote next image and sync events.image
            if (!empty($row['is_primary'])) {
                $next = $pdo->prepare("SELECT id, image_path FROM event_images WHERE event_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
                $next->execute([$eventId]);
                $n = $next->fetch(PDO::FETCH_ASSOC);
                if ($n) {
                    $pdo->prepare("UPDATE event_images SET is_primary = 1 WHERE id = ?")->execute([$n['id']]);
                    $pdo->prepare("UPDATE events SET image = ? WHERE id = ?")->execute([$n['image_path'], $eventId]);
                } else {
                    // No images left — leave events.image as-is (legacy fallback)
                }
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Unified payments list (memberships + donations + paid event registrations) ──
    case 'list_payments':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // 1. Membership & donation payments from payments table
            $stmt1 = $pdo->query("
                SELECT
                    p.id           AS payment_id,
                    'payments'     AS source,
                    NULL           AS registrant_id,
                    m.full_name, m.email, m.phone,
                    p.payment_type,
                    p.amount, p.currency, p.payment_method,
                    p.transaction_ref, p.transaction_id,
                    p.proof_file, p.status AS payment_status,
                    p.invoice_sent, p.receipt_number, p.receipt_token,
                    p.membership_period, p.membership_expires_at,
                    p.event_id, p.event_title, p.event_date,
                    p.paid_at,
                    m.id AS member_id
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.payment_type IN ('membership','donation')
                ORDER BY p.paid_at DESC
            ");
            $membPay = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // 2. Paid event registrations — only for events that are currently NOT free
                        $stmt2 = $pdo->query("
                                SELECT
                                        r.id           AS payment_id,
                                        'event_registrants' AS source,
                                        r.id           AS registrant_id,
                                        r.full_name, r.email, r.phone,
                                        'event_registration' AS payment_type,
                                        r.amount, r.currency, r.payment_method,
                                        r.transaction_ref, r.transaction_id,
                                        r.proof_file, r.payment_status,
                                        0              AS invoice_sent,
                                        r.receipt_number, r.receipt_token,
                                        NULL           AS membership_period,
                                        NULL           AS membership_expires_at,
                                        r.event_id, r.event_title, r.event_date,
                                        r.registered_at AS paid_at,
                                        NULL           AS member_id
                                FROM event_registrants r
                                INNER JOIN events e ON e.id = r.event_id
                                WHERE e.is_free = 0
                                    AND r.amount > 0
                                ORDER BY r.registered_at DESC
                        ");
            $evPay = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // 3. Grant application payments (linked via grant_applications.payment_id)
            $grantPay = [];
            try {
                $stmt3 = $pdo->query("
                    SELECT
                        p.id           AS payment_id,
                        'grant_application' AS source,
                        ga.id          AS registrant_id,
                        ga.full_name, ga.email, ga.phone,
                        'grant_application' AS payment_type,
                        p.amount, p.currency, p.payment_method,
                        p.transaction_ref, p.transaction_id,
                        p.proof_file, p.status AS payment_status,
                        0              AS invoice_sent,
                        p.receipt_number, p.receipt_token,
                        NULL           AS membership_period,
                        NULL           AS membership_expires_at,
                        NULL           AS event_id,
                        g.title        AS event_title,
                        g.deadline     AS event_date,
                        p.paid_at,
                        NULL           AS member_id
                    FROM grant_applications ga
                    INNER JOIN payments p ON ga.payment_id = p.id
                    INNER JOIN grants_opportunities g ON g.id = ga.grant_id
                    ORDER BY p.paid_at DESC
                ");
                $grantPay = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* table may not exist yet */ }

            // 4. Merge, sort by date descending
            $all = array_merge($membPay, $evPay, $grantPay);
            usort($all, function($a, $b) {
                return strtotime($b['paid_at'] ?? '0') - strtotime($a['paid_at'] ?? '0');
            });

            echo json_encode(['success' => true, 'payments' => $all]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Payments CSV export (includes event registrations) ─────────────
    case 'export_payments_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // Membership & donation payments
            $stmt1 = $pdo->query("
                SELECT 'membership/donation' AS source,
                    m.full_name, m.email, m.phone,
                    p.payment_type, p.membership_period,
                    DATE_FORMAT(p.membership_expires_at,'%Y-%m-%d') AS membership_expires_at,
                    p.event_title, p.event_date,
                    p.amount, p.currency, p.payment_method,
                    p.transaction_ref, p.transaction_id,
                    p.status AS payment_status, p.invoice_sent, p.receipt_number,
                    DATE_FORMAT(p.paid_at,'%Y-%m-%d') AS paid_date
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.payment_type IN ('membership','donation')
            ");
            $rows1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // Paid event registrations
            $stmt2 = $pdo->query("
                SELECT 'event_registration' AS source,
                    r.full_name, r.email, r.phone,
                    'event_registration' AS payment_type, '' AS membership_period,
                    '' AS membership_expires_at,
                    r.event_title, r.event_date,
                    r.amount, r.currency, r.payment_method,
                    r.transaction_ref, r.transaction_id,
                    r.payment_status, 0 AS invoice_sent, r.receipt_number,
                    DATE_FORMAT(r.registered_at,'%Y-%m-%d') AS paid_date
                FROM event_registrants r
                INNER JOIN events e ON e.id = r.event_id
                WHERE e.is_free = 0 AND r.amount > 0
            ");
            $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Grant application payments
            $rows3 = [];
            try {
                $stmt3 = $pdo->query("
                    SELECT 'grant_application' AS source,
                        ga.full_name, ga.email, ga.phone,
                        'grant_application' AS payment_type, '' AS membership_period,
                        '' AS membership_expires_at,
                        g.title AS event_title, g.deadline AS event_date,
                        p.amount, p.currency, p.payment_method,
                        p.transaction_ref, p.transaction_id,
                        p.status AS payment_status, 0 AS invoice_sent, p.receipt_number,
                        DATE_FORMAT(p.paid_at,'%Y-%m-%d') AS paid_date
                    FROM grant_applications ga
                    INNER JOIN payments p ON ga.payment_id = p.id
                    INNER JOIN grants_opportunities g ON g.id = ga.grant_id
                ");
                $rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* tables may not exist */ }

            $all = array_merge($rows1, $rows2, $rows3);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_payments_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($all) fputcsv($out, array_keys($all[0]));
            foreach ($all as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Publications ─────────────────────────────────────────────────
    case 'get_publications':
        try {
            // Auto-create table if missing
            $pdo->exec("CREATE TABLE IF NOT EXISTS publications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pub_type VARCHAR(50) NOT NULL DEFAULT 'Journal',
                title VARCHAR(255) NOT NULL,
                authors VARCHAR(255) NOT NULL DEFAULT '',
                pub_date VARCHAR(50) NOT NULL DEFAULT '',
                link VARCHAR(500) DEFAULT '',
                link_label VARCHAR(100) DEFAULT 'Read abstract',
                sort_order INT NOT NULL DEFAULT 0,
                show_on_home TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            // Add show_on_home column if missing
            try { $pdo->exec("ALTER TABLE publications ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $_e) {}
            $stmt = $pdo->query("SELECT * FROM publications ORDER BY sort_order ASC, created_at DESC");
            $pubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pubs as &$p) { $p['show_on_home'] = (bool)($p['show_on_home'] ?? 0); }
            echo json_encode(['success' => true, 'publications' => $pubs]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'create_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $title = trim($_POST['title'] ?? '');
            $pub_type = trim($_POST['pub_type'] ?? 'Journal');
            $authors = trim($_POST['authors'] ?? '');
            $pub_date = trim($_POST['pub_date'] ?? '');
            $link = trim($_POST['link'] ?? '');
            $link_label = trim($_POST['link_label'] ?? 'Read abstract');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("INSERT INTO publications (pub_type, title, authors, pub_date, link, link_label, sort_order, show_on_home) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$pub_type, $title, $authors, $pub_date, $link, $link_label, $sort_order, $show_on_home]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $title = trim($_POST['title'] ?? '');
            $pub_type = trim($_POST['pub_type'] ?? 'Journal');
            $authors = trim($_POST['authors'] ?? '');
            $pub_date = trim($_POST['pub_date'] ?? '');
            $link = trim($_POST['link'] ?? '');
            $link_label = trim($_POST['link_label'] ?? 'Read abstract');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("UPDATE publications SET pub_type=?, title=?, authors=?, pub_date=?, link=?, link_label=?, sort_order=?, show_on_home=? WHERE id=?");
            $stmt->execute([$pub_type, $title, $authors, $pub_date, $link, $link_label, $sort_order, $show_on_home, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM publications WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Grants & Opportunities ────────────────────────────────────────
    case 'get_grants':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grants_opportunities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                deadline VARCHAR(100) DEFAULT '',
                status VARCHAR(50) NOT NULL DEFAULT 'open',
                description TEXT DEFAULT '',
                apply_link VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                show_on_home TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            // Add show_on_home column if missing
            try { $pdo->exec("ALTER TABLE grants_opportunities ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $_e) {}
            $stmt = $pdo->query("SELECT * FROM grants_opportunities ORDER BY sort_order ASC, created_at DESC");
            $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($grants as &$g) { $g['show_on_home'] = (bool)($g['show_on_home'] ?? 0); }
            echo json_encode(['success' => true, 'grants' => $grants]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'create_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $title = trim($_POST['title'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = trim($_POST['currency'] ?? 'UGX');
            $deadline = trim($_POST['deadline'] ?? '');
            $status = trim($_POST['status'] ?? 'open');
            $description = trim($_POST['description'] ?? '');
            $apply_link = trim($_POST['apply_link'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("INSERT INTO grants_opportunities (title, amount, currency, deadline, status, description, apply_link, sort_order, show_on_home) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$title, $amount, $currency, $deadline, $status, $description, $apply_link, $sort_order, $show_on_home]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $title = trim($_POST['title'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = trim($_POST['currency'] ?? 'UGX');
            $deadline = trim($_POST['deadline'] ?? '');
            $status = trim($_POST['status'] ?? 'open');
            $description = trim($_POST['description'] ?? '');
            $apply_link = trim($_POST['apply_link'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("UPDATE grants_opportunities SET title=?, amount=?, currency=?, deadline=?, status=?, description=?, apply_link=?, sort_order=?, show_on_home=? WHERE id=?");
            $stmt->execute([$title, $amount, $currency, $deadline, $status, $description, $apply_link, $sort_order, $show_on_home, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM grants_opportunities WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_grant_applications':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $grantId = (int)($_GET['grant_id'] ?? $_POST['grant_id'] ?? 0);
            if (!$grantId) { http_response_code(400); echo json_encode(['error' => 'grant_id required']); break; }
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->prepare("
                SELECT ga.*, p.amount AS paid_amount, p.currency AS paid_currency, p.status AS payment_status
                FROM grant_applications ga
                LEFT JOIN payments p ON ga.payment_id = p.id
                WHERE ga.grant_id = ?
                ORDER BY ga.created_at DESC
            ");
            $stmt->execute([$grantId]);
            echo json_encode(['success' => true, 'applications' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'submit_grant_application':
        try {
            $grant_id    = (int)($_POST['grant_id'] ?? 0);
            $full_name   = trim($_POST['full_name'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $institution = trim($_POST['institution'] ?? '');
            $proposal    = trim($_POST['proposal'] ?? '');

            if (!$grant_id || !$full_name || !$email) {
                http_response_code(400);
                echo json_encode(['error' => 'Grant ID, full name and email are required']);
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email address']);
                break;
            }

            // Verify grant exists and is open
            $stmt = $pdo->prepare("SELECT id, title, status FROM grants_opportunities WHERE id = ?");
            $stmt->execute([$grant_id]);
            $grant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$grant) {
                http_response_code(404);
                echo json_encode(['error' => 'Grant not found']);
                break;
            }
            if ($grant['status'] === 'closed') {
                http_response_code(400);
                echo json_encode(['error' => 'This grant is no longer accepting applications']);
                break;
            }

            // Check for duplicate application
            $stmt = $pdo->prepare("SELECT id FROM grant_applications WHERE grant_id = ? AND email = ?");
            $stmt->execute([$grant_id, $email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'You have already applied for this grant']);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO grant_applications (grant_id, full_name, email, phone, institution, proposal) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$grant_id, $full_name, $email, $phone, $institution, $proposal]);
            // Send acknowledgment to applicant
            sendGrantAckEmail($email, $full_name, $grant['title']);
            // Notify admin
            $safeName  = htmlspecialchars($full_name,      ENT_QUOTES, 'UTF-8');
            $safeEmail = htmlspecialchars($email,          ENT_QUOTES, 'UTF-8');
            $safeTitle = htmlspecialchars($grant['title'], ENT_QUOTES, 'UTF-8');
            notifyAdmin(
                "New Grant Application — {$safeTitle}",
                "<p><strong>{$safeName}</strong> (<a href='mailto:{$safeEmail}'>{$safeEmail}</a>) has applied for the <strong>{$safeTitle}</strong> grant.</p>"
            );
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'update_grant_application_status':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['pending', 'approved', 'rejected'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid application ID and status required']);
                break;
            }
            // Fetch applicant + grant title before updating
            $appStmt = $pdo->prepare(
                "SELECT ga.full_name, ga.email, go.title FROM grant_applications ga
                 JOIN grants_opportunities go ON go.id = ga.grant_id WHERE ga.id = ?"
            );
            $appStmt->execute([$id]);
            $appRow = $appStmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare("UPDATE grant_applications SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            // Email applicant on approved/rejected
            if ($appRow && !empty($appRow['email'])) {
                sendGrantStatusEmail($appRow['email'], $appRow['full_name'], $appRow['title'] ?? 'Grant', $status);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'link_grant_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $application_id = (int)($_POST['application_id'] ?? 0);
            $payment_id     = (int)($_POST['payment_id'] ?? 0);
            if (!$application_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Application ID required']);
                break;
            }
            // Allow unlinking by passing payment_id=0
            $pid = $payment_id > 0 ? $payment_id : null;
            $stmt = $pdo->prepare("UPDATE grant_applications SET payment_id = ? WHERE id = ?");
            $stmt->execute([$pid, $application_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_grant_app_counts':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->query("
                SELECT grant_id,
                       COUNT(*) AS total,
                       SUM(status='approved') AS approved,
                       SUM(status='pending') AS pending,
                       SUM(status='rejected') AS rejected
                FROM grant_applications
                GROUP BY grant_id
            ");
            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['grant_id']] = [
                    'total'    => (int)$row['total'],
                    'approved' => (int)$row['approved'],
                    'pending'  => (int)$row['pending'],
                    'rejected' => (int)$row['rejected']
                ];
            }
            echo json_encode(['success' => true, 'counts' => $counts]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_grant_application':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Application ID required']); break; }
            $stmt = $pdo->prepare("DELETE FROM grant_applications WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'export_grants_report_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $grantId = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
            $where = $grantId ? "WHERE ga.grant_id = " . $grantId : "";
            $stmt = $pdo->query("
                SELECT g.title AS grant_title, ga.full_name, ga.email, ga.phone, ga.institution,
                       ga.status AS application_status, ga.proposal,
                       IFNULL(p.amount,'') AS paid_amount, IFNULL(p.currency,'') AS paid_currency,
                       IFNULL(p.status,'') AS payment_status,
                       DATE_FORMAT(ga.created_at,'%Y-%m-%d') AS applied_date
                FROM grant_applications ga
                JOIN grants_opportunities g ON g.id = ga.grant_id
                LEFT JOIN payments p ON ga.payment_id = p.id
                $where
                ORDER BY ga.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_grant_applications_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    // ── Toggle Event Featured (Show on Home) ────────────────────────
    case 'toggle_event_featured':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = $_POST['id'] ?? '';
            $featured = (!empty($_POST['featured']) && $_POST['featured'] !== '0') ? 1 : 0;
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing event id']); break; }
            $stmt = $pdo->prepare("UPDATE events SET featured = ? WHERE id = ?");
            $stmt->execute([$featured, $id]);
            if ($stmt->rowCount() === 0) { echo json_encode(['success' => false, 'error' => 'Event not found']); break; }
            echo json_encode(['success' => true, 'featured' => $featured]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'toggle_event_pinned':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = $_POST['id'] ?? '';
            $pinned = (!empty($_POST['pinned']) && $_POST['pinned'] !== '0') ? 1 : 0;
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing event id']); break; }
            $stmt = $pdo->prepare('UPDATE events SET pinned = ? WHERE id = ?');
            $stmt->execute([$pinned, $id]);
            if ($stmt->rowCount() === 0) { echo json_encode(['success' => false, 'error' => 'Event not found']); break; }
            auditLog($pdo, 'toggle_event_pinned', 'event', $id, $pinned ? 'pinned' : 'unpinned');
            echo json_encode(['success' => true, 'pinned' => $pinned]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'toggle_event_spotlight':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            $id = $_POST['id'] ?? '';
            $show = (!empty($_POST['show_live_on_home']) && $_POST['show_live_on_home'] !== '0') ? 1 : 0;
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing event id']); break; }
            $stmt = $pdo->prepare('UPDATE events SET show_live_on_home = ? WHERE id = ?');
            $stmt->execute([$show, $id]);
            if ($stmt->rowCount() === 0) { echo json_encode(['success' => false, 'error' => 'Event not found']); break; }
            auditLog($pdo, 'toggle_event_spotlight', 'event', $id, $show ? 'on' : 'off');
            echo json_encode(['success' => true, 'show_live_on_home' => $show]);
        } catch (PDOException $e) { error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']); }
        break;

    case 'append_event_assets':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_POST['event_id'] ?? $_POST['id'] ?? '');
            if (!$eventId) {
                http_response_code(400); echo json_encode(['error' => 'event_id required']); break;
            }
            $check = $pdo->prepare('SELECT id FROM events WHERE id = ?');
            $check->execute([$eventId]);
            if (!$check->fetchColumn()) {
                http_response_code(404); echo json_encode(['error' => 'Event not found']); break;
            }
            $summary = appendEventAssets($pdo, $eventId, $_POST, $_FILES);
            echo json_encode(['success' => true, 'summary' => $summary]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'set_event_primary_image':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_POST['event_id'] ?? '');
            $imageId = (int)($_POST['image_id'] ?? 0);
            if (!$eventId || !$imageId) {
                http_response_code(400); echo json_encode(['error' => 'event_id and image_id required']); break;
            }
            if (!setEventPrimaryImage($pdo, $eventId, $imageId)) {
                http_response_code(404); echo json_encode(['error' => 'Image not found']); break;
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'remove_event_from_home':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = $_POST['id'] ?? '';
            if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing event id']); break; }
            migrateEventSchema($pdo);
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $pdo->prepare('UPDATE events SET featured = 0, pinned = 0, show_live_on_home = 0, show_upcoming_in_ongoing = 0, display_end = ?, display_for_event = 0, post_event_display_days = 0 WHERE id = ?');
            $stmt->execute([$now, $id]);
            auditLog($pdo, 'remove_event_from_home', 'event', $id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_spotlights':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            echo json_encode(['success' => true, 'spotlights' => loadHomepageSpotlights($pdo, false)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_spotlight':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }

            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                http_response_code(400); echo json_encode(['error' => 'Title is required']); break;
            }

            $images = collectPostedSlideImages('images_json', 'image_urls', 'image_url', '', 'uploads/spotlights/', 5000000);
            /* Merge admin-supplied video URLs (YouTube/Vimeo/.mp4 etc.) into the same gallery. */
            if (!empty($_POST['video_urls'])) {
                $images = appendSlideVideoUrlsFromText((string)$_POST['video_urls'], $images);
            }
            $imagesJson = encodeSlideImagesJson($images);
            /* Cover image is the first non-video item; falls back to first item or legacy field. */
            $imageUrl = '';
            foreach ($images as $it) {
                if (($it['type'] ?? 'image') !== 'video') { $imageUrl = $it['url']; break; }
            }
            if ($imageUrl === '') {
                $imageUrl = $images[0]['url'] ?? trim($_POST['image_url'] ?? '');
            }

            $data = [
                trim($_POST['title'] ?? ''),
                trim($_POST['headline'] ?? ''),
                trim($_POST['body'] ?? ''),
                $imageUrl,
                $imagesJson,
                trim($_POST['badge_label'] ?? 'Important'),
                trim($_POST['content_type'] ?? 'announcement'),
                trim($_POST['cta_primary_label'] ?? ''),
                trim($_POST['cta_primary_url'] ?? ''),
                trim($_POST['cta_secondary_label'] ?? ''),
                trim($_POST['cta_secondary_url'] ?? ''),
                !empty($_POST['display_start']) ? trim($_POST['display_start']) : null,
                !empty($_POST['display_end']) ? trim($_POST['display_end']) : null,
                !empty($_POST['show_in_hero']) && $_POST['show_in_hero'] !== '0' ? 1 : 0,
                !isset($_POST['show_in_spotlight']) || $_POST['show_in_spotlight'] !== '0' ? 1 : 0,
                (int)($_POST['sort_order'] ?? 0),
                (int)($_POST['priority'] ?? 0),
                !isset($_POST['is_active']) || $_POST['is_active'] !== '0' ? 1 : 0,
                trim($_POST['event_id'] ?? '') ?: null,
            ];

            if ($id > 0) {
                $pdo->prepare('UPDATE homepage_spotlights SET title=?, headline=?, body=?, image_url=?, images_json=?, badge_label=?, content_type=?, cta_primary_label=?, cta_primary_url=?, cta_secondary_label=?, cta_secondary_url=?, display_start=?, display_end=?, show_in_hero=?, show_in_spotlight=?, sort_order=?, priority=?, is_active=?, event_id=? WHERE id=?')
                    ->execute(array_merge($data, [$id]));
            } else {
                $pdo->prepare('INSERT INTO homepage_spotlights (title, headline, body, image_url, images_json, badge_label, content_type, cta_primary_label, cta_primary_url, cta_secondary_label, cta_secondary_url, display_start, display_end, show_in_hero, show_in_spotlight, sort_order, priority, is_active, event_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($data);
                $id = (int)$pdo->lastInsertId();
            }
            $row = $pdo->query('SELECT * FROM homepage_spotlights WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['is_active'] = (bool)($row['is_active'] ?? 0);
                $row['show_in_hero'] = (bool)($row['show_in_hero'] ?? 0);
                $row['show_in_spotlight'] = !isset($row['show_in_spotlight']) || (bool)$row['show_in_spotlight'];
                $row['images'] = parseSlideImageList($row, 'image_url', 'image_alt');
                /* Split out videos for the admin form (so the textarea pre-fills on edit). */
                $row['videos'] = array_values(array_filter($row['images'], function ($i) {
                    return ($i['type'] ?? 'image') === 'video';
                }));
                /* Keep `images` as image-only for the gallery preview. */
                $row['images'] = array_values(array_filter($row['images'], function ($i) {
                    return ($i['type'] ?? 'image') !== 'video';
                }));
            }
            auditLog($pdo, 'save_spotlight', 'spotlight', (string)$id, trim($_POST['title'] ?? ''));
            echo json_encode(['success' => true, 'id' => $id, 'spotlight' => $row ?: null]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_spotlight':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('DELETE FROM homepage_spotlights WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_spotlight':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            $active = !empty($_POST['is_active']) && $_POST['is_active'] !== '0' ? 1 : 0;
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE homepage_spotlights SET is_active = ? WHERE id = ?')->execute([$active, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Homepage Hero Slides ───────────────────────────────────────────
    case 'get_home_hero':
        try {
            hosuPublicJsonCache(30);
            migrateEventSchema($pdo);
            $slides = filterReachableHeroSlides(loadHomepageHeroSlides($pdo, true));
            $heroImages = resolvePublicHeroImages($pdo);
            echo json_encode([
                'success' => true,
                'slides' => $slides,
                'image_mode' => $heroImages['mode'],
                'pool_images' => $heroImages['pool'],
                'pool_count' => $heroImages['pool_count'],
                'pool_missing' => $heroImages['pool_missing'],
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_hero_slides':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            $seeded = seedDefaultHeroSlidesIfEmpty($pdo);
            if ($seeded) {
                auditLog($pdo, 'seed_hero_slides', 'hero_slides', 'defaults', 'Auto-seeded editable homepage hero slides');
            }
            $rows = $pdo->query('SELECT * FROM homepage_hero_slides ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $slides = array_map(function ($row) use ($pdo) {
                return normalizeHeroSlideRowWithPersist($pdo, $row);
            }, $rows);
            $heroImages = loadHeroImageSettings($pdo);
            $reachablePool = filterReachableHeroImages(normalizeHeroPoolImages($heroImages['pool']));
            $heroImages['pool_missing'] = max(0, count($heroImages['pool']) - count($reachablePool));
            echo json_encode([
                'success' => true,
                'slides' => $slides,
                'image_settings' => $heroImages,
                'seeded' => $seeded,
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_hero_image_settings':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $mode = trim($_POST['image_mode'] ?? 'per_slide');
            $poolAlt = trim($_POST['pool_alt'] ?? '');
            $skipUploads = !empty($_POST['skip_uploads']) && $_POST['skip_uploads'] !== '0';
            if ($skipUploads) {
                $pool = normalizeHeroPoolImages(parsePostedSlideImages($_POST['pool_images_json'] ?? '[]', $poolAlt));
                $pool = appendSlideImageUrlsFromText(trim($_POST['pool_image_urls'] ?? ''), $pool, $poolAlt);
            } else {
                $pool = collectPostedHeroPoolImages($poolAlt);
            }
            $settings = saveHeroImageSettings($pdo, $mode, $pool, $poolAlt);
            $reachablePool = filterReachableHeroImages(normalizeHeroPoolImages($settings['pool']));
            $settings['pool_missing'] = max(0, count($settings['pool']) - count($reachablePool));
            auditLog($pdo, 'save_hero_image_settings', 'hero_images', 'settings', $settings['mode']);
            echo json_encode([
                'success' => true,
                'image_settings' => $settings,
                'upload_attempted' => (int)($GLOBALS['__hero_pool_upload_attempted'] ?? 0),
                'upload_accepted' => (int)($GLOBALS['__hero_pool_upload_accepted'] ?? 0),
                'upload_errors' => $GLOBALS['__hero_pool_upload_errors'] ?? [],
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'append_hero_pool_images':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $poolAlt = trim($_POST['pool_alt'] ?? '');
            $uploads = collectPostedHeroPoolUploads($poolAlt);
            if (empty($uploads)) {
                http_response_code(400);
                echo json_encode([
                    'error' => 'No images uploaded',
                    'upload_attempted' => (int)($GLOBALS['__hero_pool_upload_attempted'] ?? 0),
                    'upload_errors' => $GLOBALS['__hero_pool_upload_errors'] ?? [],
                ]);
                break;
            }
            $settings = appendHeroPoolImages($pdo, $uploads, $poolAlt);
            $reachablePool = filterReachableHeroImages(normalizeHeroPoolImages($settings['pool']));
            $settings['pool_missing'] = max(0, count($settings['pool']) - count($reachablePool));
            auditLog($pdo, 'append_hero_pool_images', 'hero_images', 'pool', count($uploads) . ' image(s)');
            echo json_encode([
                'success' => true,
                'image_settings' => $settings,
                'added' => count($uploads),
                'upload_errors' => $GLOBALS['__hero_pool_upload_errors'] ?? [],
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_hero_slide':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $id = (int)($_POST['id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            if ($title === '') {
                http_response_code(400); echo json_encode(['error' => 'Title is required']); break;
            }

            $defaultAlt = trim($_POST['image_alt'] ?? '');
            $images = collectPostedSlideImages('images_json', 'image_urls', 'image_path', $defaultAlt, 'uploads/hero/', 8000000);
            $imagesJson = encodeSlideImagesJson($images);
            $imagePath = $images[0]['url'] ?? trim($_POST['image_path'] ?? '');
            $imageAlt = $images[0]['alt'] ?? $defaultAlt;

            $pillsJson = trim($_POST['pills_json'] ?? '[]');
            $pillsDecoded = json_decode($pillsJson, true);
            if (!is_array($pillsDecoded)) {
                $pillsJson = '[]';
            }

            $slideKey = trim($_POST['slide_key'] ?? '');
            if ($slideKey === '') {
                $slideKey = slugifyHeroKey($title, $id);
            }

            $data = [
                $title,
                trim($_POST['body'] ?? ''),
                trim($_POST['badge_label'] ?? ''),
                $pillsJson,
                trim($_POST['popup_title'] ?? ''),
                trim($_POST['popup_html'] ?? ''),
                $imagePath,
                $imageAlt,
                $imagesJson,
                trim($_POST['cta_label'] ?? ''),
                trim($_POST['cta_url'] ?? ''),
                trim($_POST['cta_secondary_label'] ?? ''),
                trim($_POST['cta_secondary_url'] ?? ''),
                trim($_POST['read_more_label'] ?? 'Read More →'),
                $slideKey,
                (int)($_POST['sort_order'] ?? 0),
                !isset($_POST['is_active']) || $_POST['is_active'] !== '0' ? 1 : 0,
                !empty($_POST['display_start']) ? trim($_POST['display_start']) : null,
                !empty($_POST['display_end']) ? trim($_POST['display_end']) : null,
            ];

            if ($id > 0) {
                $pdo->prepare('UPDATE homepage_hero_slides SET title=?, body=?, badge_label=?, pills_json=?, popup_title=?, popup_html=?, image_path=?, image_alt=?, images_json=?, cta_label=?, cta_url=?, cta_secondary_label=?, cta_secondary_url=?, read_more_label=?, slide_key=?, sort_order=?, is_active=?, display_start=?, display_end=? WHERE id=?')
                    ->execute(array_merge($data, [$id]));
            } else {
                $pdo->prepare('INSERT INTO homepage_hero_slides (title, body, badge_label, pills_json, popup_title, popup_html, image_path, image_alt, images_json, cta_label, cta_url, cta_secondary_label, cta_secondary_url, read_more_label, slide_key, sort_order, is_active, display_start, display_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($data);
                $id = (int)$pdo->lastInsertId();
            }
            auditLog($pdo, 'save_hero_slide', 'hero_slide', (string)$id, $title);
            $row = $pdo->query('SELECT * FROM homepage_hero_slides WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
            $resp = ['success' => true, 'id' => $id, 'slide' => $row ? normalizeHeroSlideRowWithPersist($pdo, $row) : null];
            $attempted = $GLOBALS['__upload_attempted'] ?? 0;
            $accepted  = $GLOBALS['__upload_accepted'] ?? 0;
            if ($attempted > $accepted) {
                $rejectedCount = $attempted - $accepted;
                $names = $GLOBALS['__upload_rejected_names'] ?? [];
                $resp['warning'] = $rejectedCount . ' of ' . $attempted . ' image upload(s) were rejected'
                    . (count($names) ? ': ' . implode(', ', array_slice($names, 0, 3)) : '')
                    . '. Check that files are under 12 MB and JPG/PNG/WebP/GIF/SVG.';
            }
            echo json_encode($resp);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_hero_slide':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $stmt = $pdo->prepare('SELECT image_path FROM homepage_hero_slides WHERE id = ?');
            $stmt->execute([$id]);
            $img = $stmt->fetchColumn();
            if ($img && strpos($img, 'uploads/') === 0 && file_exists($img)) {
                @unlink($img);
            }
            $pdo->prepare('DELETE FROM homepage_hero_slides WHERE id = ?')->execute([$id]);
            auditLog($pdo, 'delete_hero_slide', 'hero_slide', (string)$id);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_hero_slide':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            $active = !empty($_POST['is_active']) && $_POST['is_active'] !== '0' ? 1 : 0;
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE homepage_hero_slides SET is_active = ? WHERE id = ?')->execute([$active, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'restore_hero_slides':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            $restored = restoreHeroSlidesIfMissing($pdo);
            $rows = $pdo->query('SELECT * FROM homepage_hero_slides ORDER BY sort_order ASC, id ASC')->fetchAll(PDO::FETCH_ASSOC);
            $slides = array_map(function ($row) use ($pdo) {
                return normalizeHeroSlideRowWithPersist($pdo, $row);
            }, $rows);
            $heroImages = loadHeroImageSettings($pdo);
            auditLog($pdo, 'restore_hero_slides', 'hero_slides', 'all', $restored ? 'restored' : 'refreshed');
            echo json_encode([
                'success' => true,
                'restored' => $restored,
                'slides' => $slides,
                'image_settings' => $heroImages,
                'count' => count($slides),
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_homepage_extras':
        try {
            hosuPublicJsonCache(30);
            migrateEventSchema($pdo);
            echo json_encode(['success' => true] + fetchHomepageExtrasPayload($pdo));
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_site_chrome':
        try {
            hosuPublicJsonCache(30);
            migrateEventSchema($pdo);
            echo json_encode(['success' => true, 'chrome' => fetchSiteChromePayload($pdo)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_homepage_settings':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            echo json_encode(['success' => true] + fetchHomepageExtrasPayload($pdo) + ['chrome' => fetchSiteChromePayload($pdo)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_homepage_admin_overview':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            echo json_encode(['success' => true] + fetchHomepageAdminOverview($pdo));
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_publication_home':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int) ($_POST['id'] ?? 0);
            $show = !empty($_POST['show_on_home']) && $_POST['show_on_home'] !== '0' ? 1 : 0;
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE publications SET show_on_home = ? WHERE id = ?')->execute([$show, $id]);
            auditLog($pdo, 'toggle_publication_home', 'publication', (string) $id, $show ? 'on' : 'off');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_grant_home':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int) ($_POST['id'] ?? 0);
            $show = !empty($_POST['show_on_home']) && $_POST['show_on_home'] !== '0' ? 1 : 0;
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE grants_opportunities SET show_on_home = ? WHERE id = ?')->execute([$show, $id]);
            auditLog($pdo, 'toggle_grant_home', 'grant', (string) $id, $show ? 'on' : 'off');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_site_chrome':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $chrome = json_decode($_POST['chrome_json'] ?? '', true);
            if (!is_array($chrome)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid chrome_json']); break;
            }
            saveSiteChromePayload($pdo, $chrome);
            auditLog($pdo, 'save_site_chrome', 'site', 'chrome', 'Site chrome updated');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_homepage_extras':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $partnersJson = $_POST['partners_json'] ?? '';
            $ctaJson = $_POST['cta_json'] ?? '';
            $partners = json_decode($partnersJson, true);
            $cta = json_decode($ctaJson, true);
            if (!is_array($partners) || !is_array($cta)) {
                http_response_code(400); echo json_encode(['error' => 'Invalid JSON payload']); break;
            }
            if (!empty($_FILES['partner_logo_new']) && $_FILES['partner_logo_new']['error'] === UPLOAD_ERR_OK) {
                $idx = (int)($_POST['partner_logo_index'] ?? -1);
                $up = secureUpload($_FILES['partner_logo_new'], 'uploads/partners/', false, 8000000);
                if ($up && isset($partners['items'][$idx])) {
                    $partners['items'][$idx]['logo'] = $up;
                }
            }
            saveHomepageSetting($pdo, 'partners', $partners);
            saveHomepageSetting($pdo, 'cta', $cta);
            auditLog($pdo, 'save_homepage_extras', 'homepage', 'extras', $partners['title'] ?? 'Homepage sections');
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_ongoing_admin_panel':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            echo json_encode(['success' => true] + fetchOngoingAdminPanel($pdo));
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_spotlight_ongoing':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int) ($_POST['id'] ?? 0);
            $show = !empty($_POST['show_in_spotlight']) && $_POST['show_in_spotlight'] !== '0' ? 1 : 0;
            if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE homepage_spotlights SET show_in_spotlight = ? WHERE id = ?')->execute([$show, $id]);
            auditLog($pdo, 'toggle_spotlight_ongoing', 'spotlight', (string) $id, $show ? 'in_lineup' : 'out');
            echo json_encode(['success' => true, 'show_in_spotlight' => $show]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'toggle_event_upcoming_ongoing':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = trim((string) ($_POST['id'] ?? ''));
            $show = !empty($_POST['show_upcoming_in_ongoing']) && $_POST['show_upcoming_in_ongoing'] !== '0' ? 1 : 0;
            if ($id === '') { http_response_code(400); echo json_encode(['error' => 'Invalid id']); break; }
            $pdo->prepare('UPDATE events SET show_upcoming_in_ongoing = ? WHERE id = ?')->execute([$show, $id]);
            auditLog($pdo, 'toggle_event_upcoming_ongoing', 'event', $id, $show ? 'in_lineup' : 'out');
            echo json_encode(['success' => true, 'show_upcoming_in_ongoing' => $show]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_ongoing_settings':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            migrateEventSchema($pdo);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405); echo json_encode(['error' => 'POST required']); break;
            }
            $defaults = defaultOngoingNowSettings();
            $settings = normalizeOngoingNowSettings([
                'section_title' => trim((string) ($_POST['section_title'] ?? $defaults['section_title'])),
                'section_subtitle' => trim((string) ($_POST['section_subtitle'] ?? $defaults['section_subtitle'])),
                'subtitle_upcoming' => trim((string) ($_POST['subtitle_upcoming'] ?? $defaults['subtitle_upcoming'])),
                'eyebrow_live' => trim((string) ($_POST['eyebrow_live'] ?? $defaults['eyebrow_live'])),
                'eyebrow_upcoming' => trim((string) ($_POST['eyebrow_upcoming'] ?? $defaults['eyebrow_upcoming'])),
                'eyebrow_updates' => trim((string) ($_POST['eyebrow_updates'] ?? $defaults['eyebrow_updates'])),
                'show_upcoming_events' => !isset($_POST['show_upcoming_events']) || $_POST['show_upcoming_events'] === '1',
                'show_past_events' => !isset($_POST['show_past_events']) || $_POST['show_past_events'] === '1',
                'show_curated' => !isset($_POST['show_curated']) || $_POST['show_curated'] === '1',
                'past_hide_when_upcoming' => !empty($_POST['past_hide_when_upcoming']) && $_POST['past_hide_when_upcoming'] === '1',
                'arrangement' => ($_POST['arrangement'] ?? 'priority') === 'random' ? 'random' : 'priority',
            ]);
            saveHomepageSetting($pdo, 'ongoing_now', $settings);
            auditLog($pdo, 'save_ongoing_settings', 'homepage', 'ongoing_now', $settings['section_title']);
            echo json_encode(['success' => true, 'ongoing_settings' => $settings]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Home Featured Content ─────────────────────────────────────────
    case 'get_home_featured':
        try {
            hosuPublicJsonCache(30);
            echo json_encode([
                'success' => true,
                'featured' => fetchHomeFeaturedPayload($pdo),
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'preview_pasted_image_urls':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $raw = file_get_contents('php://input');
            $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $urls = [];
            if (!empty($body['urls']) && is_array($body['urls'])) {
                $urls = $body['urls'];
            } elseif (!empty($_POST['urls'])) {
                $urls = is_array($_POST['urls']) ? $_POST['urls'] : preg_split('/[\r\n,]+/', $_POST['urls']);
            }
            $urls = array_values(array_filter(array_map('trim', $urls)));
            if (empty($urls)) {
                http_response_code(400);
                echo json_encode(['error' => 'Paste at least one URL']);
                break;
            }
            foreach ($urls as $url) {
                if (isDriveFolderUrl($url)) {
                    invalidateDriveFolderCache(extractDriveFolderId($url));
                }
            }
            $parsed = expandPastedImageUrls($urls);
            $previews = array_map(function ($url) {
                $id = extractDriveFileId($url);
                return $id !== '' ? driveProxiedImageUrl($id, 320) : $url;
            }, array_slice($parsed['urls'], 0, 16));
            echo json_encode([
                'success' => true,
                'photo_count' => $parsed['photo_count'],
                'folders' => $parsed['folders'],
                'urls' => $parsed['urls'],
                'previews' => $previews,
            ]);
        } catch (Throwable $e) {
            error_log('API preview_pasted_image_urls: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not resolve links']);
        }
        break;

    case 'preview_drive_folder':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $url = trim($_GET['url'] ?? $_POST['url'] ?? '');
            if (!isDriveFolderUrl($url)) {
                http_response_code(400);
                echo json_encode(['error' => 'Paste a Google Drive folder link']);
                break;
            }
            $folderId = extractDriveFolderId($url);
            invalidateDriveFolderCache($folderId);
            $ids = fetchDriveFolderFileIds($folderId, true);
            $previews = array_map(
                fn($id) => driveProxiedImageUrl($id, 480),
                array_slice($ids, 0, 8)
            );
            echo json_encode([
                'success' => true,
                'folder_id' => $folderId,
                'photo_count' => count($ids),
                'previews' => $previews,
            ]);
        } catch (Throwable $e) {
            error_log('API preview_drive_folder: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Could not read folder']);
        }
        break;

    case 'get_home_spotlight':
        try {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $payload = fetchHomeSpotlightPayload($pdo);
            echo json_encode([
                'success' => true,
                'spotlight_slides' => $payload['spotlight_slides'],
                'hero_spotlights' => $payload['hero_spotlights'],
                'has_live' => $payload['has_live'] ?? false,
                'ongoing_settings' => $payload['ongoing_settings'] ?? defaultOngoingNowSettings(),
                'ongoing_mode' => $payload['ongoing_mode'] ?? 'empty',
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Homepage Dynamic Sections ─────────────────────────────────────
    case 'get_home_content':
        try {
            migrateEventSchema($pdo);
            autoExpirePastEvents($pdo);

            $stmt = $pdo->query('SELECT * FROM events ORDER BY updated_at DESC, created_at DESC');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $today = new DateTimeImmutable('today');
            $all = [];

            foreach ($rows as $ev) {
                enrichEventRow($ev, $today);
                $all[] = $ev;
            }

            $ids = array_column($all, 'id');
            attachGalleryToEvents($all, loadEventGalleries($pdo, $ids));
            attachMediaToEvents($all, loadEventMedia($pdo, $ids));
            attachLiveContentToEvents($all, loadLiveContent($pdo, $ids, true), true);

            $visible = array_values(array_filter($all, fn($ev) => $ev['is_display_active'] || $ev['featured'] || $ev['pinned']));

            // Live/ongoing events always surface on homepage when event day is reached
            $liveEvents = array_values(array_filter($all, function ($ev) {
                return $ev['is_live']
                    && ($ev['status'] ?? '') !== 'past'
                    && $ev['show_live_on_home'];
            }));
            usort($liveEvents, function ($a, $b) {
                if ($a['home_priority'] !== $b['home_priority']) {
                    return $b['home_priority'] <=> $a['home_priority'];
                }
                return strcmp($a['date_start'] ?? '', $b['date_start'] ?? '');
            });
            $featuredEvents = array_values(array_filter($visible, fn($ev) => ($ev['featured'] || $ev['pinned']) && $ev['is_display_active']));
            usort($featuredEvents, function ($a, $b) {
                if ($a['pinned'] !== $b['pinned']) return $b['pinned'] <=> $a['pinned'];
                if ($a['home_priority'] !== $b['home_priority']) return $b['home_priority'] <=> $a['home_priority'];
                return strcmp($a['date_start'] ?? '', $b['date_start'] ?? '');
            });
            $featuredEvents = array_slice($featuredEvents, 0, 6);

            $upcomingEvents = array_values(array_filter($visible, fn($ev) => !$ev['is_live'] && ($ev['category'] ?? '') === 'upcoming'));
            usort($upcomingEvents, fn($a, $b) => strcmp($a['date_start'] ?? '', $b['date_start'] ?? ''));
            $upcomingEvents = array_slice($upcomingEvents, 0, 8);

            $recentlyUpdated = array_slice($visible, 0, 6);

            $conferences = array_values(array_filter($visible, fn($ev) => ($ev['type'] ?? '') === 'conference'));
            $workshops = array_values(array_filter($visible, fn($ev) => ($ev['type'] ?? '') === 'workshop'));
            $webinars = array_values(array_filter($visible, fn($ev) => ($ev['type'] ?? '') === 'webinar'));

            $announcements = [];
            foreach ($visible as $ev) {
                if (!empty($ev['announcements'])) {
                    $announcements[] = [
                        'id' => $ev['id'],
                        'title' => $ev['title'],
                        'text' => $ev['announcements'],
                        'date' => $ev['date'],
                        'is_live' => $ev['is_live'],
                        'pinned' => $ev['pinned'],
                    ];
                }
            }
            usort($announcements, fn($a, $b) => ($b['pinned'] <=> $a['pinned']) ?: strcmp($a['date'] ?? '', $b['date'] ?? ''));
            $announcements = array_slice($announcements, 0, 8);

            $highlights = [];
            foreach ($visible as $ev) {
                if (!empty($ev['highlights'])) {
                    $highlights[] = [
                        'id' => $ev['id'],
                        'title' => $ev['title'],
                        'text' => $ev['highlights'],
                        'image' => $ev['image_urls'][0] ?? $ev['image'] ?? '',
                        'is_live' => $ev['is_live'],
                        'countdown' => $ev['countdown'],
                    ];
                }
                $blocks = filterEventContentBlocks($ev['live_content'] ?? [], $ev, 'homepage');
                foreach ($blocks as $block) {
                    if (($block['content_type'] ?? '') === 'image' && !empty($block['image_url'])) {
                        $highlights[] = [
                            'id' => $ev['id'],
                            'title' => $block['title'] ?: $ev['title'],
                            'text' => $block['body'] ?? '',
                            'image' => $block['image_url'],
                            'is_live' => $ev['is_live'],
                            'countdown' => $ev['countdown'],
                        ];
                    }
                }
            }
            $highlights = array_slice($highlights, 0, 8);

            $mediaGallery = [];
            foreach ($visible as $ev) {
                foreach ($ev['image_urls'] ?? [] as $img) {
                    $mediaGallery[] = ['type' => 'image', 'url' => $img, 'event_id' => $ev['id'], 'title' => $ev['title']];
                }
                foreach ($ev['videos'] ?? [] as $vid) {
                    $mediaGallery[] = ['type' => 'video', 'url' => $vid['media_path'], 'event_id' => $ev['id'], 'title' => $vid['title'] ?: $ev['title']];
                }
            }
            $mediaGallery = array_slice($mediaGallery, 0, 12);

            $successStories = array_values(array_filter($visible, fn($ev) => !empty($ev['highlights']) && ($ev['status'] ?? '') === 'past'));
            $successStories = array_slice($successStories, 0, 4);

            $publications = [];
            try {
                $pubStmt = $pdo->query("SELECT id, title, authors, pub_type, pub_date, link, link_label FROM publications WHERE show_on_home = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 4");
                $publications = $pubStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $_e) {}

            $customSpotlights = loadHomepageSpotlights($pdo, true);
            $spotlightSlides = buildSpotlightSlides($all, $customSpotlights);
            $heroSpotlights = array_values(array_filter($spotlightSlides, fn($s) => !empty($s['show_in_hero'])));

            echo json_encode([
                'success' => true,
                'spotlight_slides' => $spotlightSlides,
                'hero_spotlights' => $heroSpotlights,
                'sections' => [
                    'live_events' => $liveEvents,
                    'spotlight_slides' => $spotlightSlides,
                    'featured_events' => $featuredEvents,
                    'upcoming_events' => $upcomingEvents,
                    'recently_updated' => $recentlyUpdated,
                    'conferences' => array_slice($conferences, 0, 6),
                    'workshops' => array_slice($workshops, 0, 4),
                    'webinars' => array_slice($webinars, 0, 4),
                    'announcements' => $announcements,
                    'highlights' => $highlights,
                    'media_gallery' => $mediaGallery,
                    'success_stories' => $successStories,
                    'publications' => $publications,
                ],
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── About Page Stats — live from DB (Public) ──────────────────────
    case 'get_about_stats':
        try {
            hosuPublicJsonCache(60);
            $custom = [];
            try {
                $customStmt = $pdo->query("SELECT stat_key, stat_value, stat_label FROM site_stats WHERE page = 'about' AND is_active = 1 ORDER BY sort_order");
                foreach ($customStmt->fetchAll(PDO::FETCH_ASSOC) as $cs) {
                    $custom[$cs['stat_key']] = $cs;
                }
            } catch (Exception $_e) {}

            $activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE status = 'active' AND membership_type IN ('1_year','2_years','3_years','lifetime')")->fetchColumn();
            $eventCount = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
            $foundedYear = (int)$pdo->query('SELECT YEAR(MIN(created_at)) FROM members')->fetchColumn();
            if (!$foundedYear || $foundedYear < 2015) {
                $foundedYear = 2019;
            }

            $membersVal = $custom['about_members']['stat_value'] ?? ($activeMembers > 0 ? (string)$activeMembers : '0');
            $foundedVal = $custom['about_founded']['stat_value'] ?? (string)$foundedYear;
            $eventsVal  = $custom['about_events']['stat_value'] ?? (string)$eventCount;

            echo json_encode(['success' => true, 'stats' => [
                ['value' => $membersVal, 'label' => $custom['about_members']['stat_label'] ?? 'Members'],
                ['value' => $foundedVal, 'label' => $custom['about_founded']['stat_label'] ?? 'Founded'],
                ['value' => $eventsVal,  'label' => $custom['about_events']['stat_label'] ?? 'Events'],
            ]]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Membership Stats (Public) ─────────────────────────────────────
    case 'get_membership_stats':
        try {
            // Ensure site_stats table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_stats (
                id INT AUTO_INCREMENT PRIMARY KEY, stat_key VARCHAR(80) NOT NULL UNIQUE, stat_value VARCHAR(100) NOT NULL DEFAULT '',
                stat_label VARCHAR(100) NOT NULL DEFAULT '', page VARCHAR(50) NOT NULL DEFAULT 'global', sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            // Check if admin has set custom stats
            $customStmt = $pdo->query("SELECT stat_key, stat_value, stat_label FROM site_stats WHERE page='membership' AND is_active=1 ORDER BY sort_order");
            $customStats = $customStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($customStats) >= 3) {
                $stats = [];
                foreach ($customStats as $cs) {
                    $stats[$cs['stat_key']] = $cs['stat_value'];
                }
                echo json_encode([
                    'success' => true,
                    'stats' => [
                        'members' => $stats['members_count'] ?? '500+',
                        'specialties' => $stats['specialties_count'] ?? '12+',
                        'institutions' => $stats['institutions_count'] ?? '50+'
                    ]
                ]);
            } else {
                // Fallback: compute from DB
                $memberStmt = $pdo->query("SELECT COUNT(*) as total FROM members WHERE membership_type != 'event_registration' AND status IN ('active', 'pending')");
                $memberCount = (int)$memberStmt->fetch(PDO::FETCH_ASSOC)['total'];
                $profStmt = $pdo->query("SELECT COUNT(DISTINCT profession) as total FROM members WHERE membership_type != 'event_registration' AND profession IS NOT NULL AND profession != ''");
                $profCount = (int)$profStmt->fetch(PDO::FETCH_ASSOC)['total'];
                $instStmt = $pdo->query("SELECT COUNT(DISTINCT institution) as total FROM members WHERE membership_type != 'event_registration' AND institution IS NOT NULL AND institution != ''");
                $instCount = (int)$instStmt->fetch(PDO::FETCH_ASSOC)['total'];
                echo json_encode([
                    'success' => true,
                    'stats' => [
                        'members' => $memberCount > 0 ? $memberCount . '+' : '500+',
                        'specialties' => $profCount > 0 ? $profCount . '+' : '12+',
                        'institutions' => $instCount > 0 ? $instCount . '+' : '50+'
                    ]
                ]);
            }
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Stats — get by page (Public) ─────────────────────────────
    case 'get_site_stats':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_stats (
                id INT AUTO_INCREMENT PRIMARY KEY, stat_key VARCHAR(80) NOT NULL UNIQUE, stat_value VARCHAR(100) NOT NULL DEFAULT '',
                stat_label VARCHAR(100) NOT NULL DEFAULT '', page VARCHAR(50) NOT NULL DEFAULT 'global', sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $page = trim($_GET['page'] ?? '');
            if ($page) {
                $stmt = $pdo->prepare("SELECT id, stat_key, stat_value, stat_label, page, sort_order, is_active FROM site_stats WHERE page=? AND is_active=1 ORDER BY sort_order");
                $stmt->execute([$page]);
            } else {
                $stmt = $pdo->query("SELECT id, stat_key, stat_value, stat_label, page, sort_order, is_active FROM site_stats ORDER BY page, sort_order");
            }
            echo json_encode(['success' => true, 'stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Stats — list all for admin ────────────────────────────────
    case 'list_site_stats':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_stats (
                id INT AUTO_INCREMENT PRIMARY KEY, stat_key VARCHAR(80) NOT NULL UNIQUE, stat_value VARCHAR(100) NOT NULL DEFAULT '',
                stat_label VARCHAR(100) NOT NULL DEFAULT '', page VARCHAR(50) NOT NULL DEFAULT 'global', sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->query("SELECT * FROM site_stats ORDER BY page, sort_order");
            echo json_encode(['success' => true, 'stats' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Stats — save (admin) ─────────────────────────────────────
    case 'save_site_stat':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS site_stats (
                id INT AUTO_INCREMENT PRIMARY KEY, stat_key VARCHAR(80) NOT NULL UNIQUE, stat_value VARCHAR(100) NOT NULL DEFAULT '',
                stat_label VARCHAR(100) NOT NULL DEFAULT '', page VARCHAR(50) NOT NULL DEFAULT 'global', sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $id = (int)($_POST['id'] ?? 0);
            $stat_key   = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['stat_key'] ?? '')));
            $stat_value = trim($_POST['stat_value'] ?? '');
            $stat_label = trim($_POST['stat_label'] ?? '');
            $page       = trim($_POST['page'] ?? 'global');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active  = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if (!$stat_key || !$stat_value || !$stat_label) {
                http_response_code(400); echo json_encode(['error' => 'Key, value and label are required']); break;
            }
            if ($id) {
                $stmt = $pdo->prepare("UPDATE site_stats SET stat_key=?, stat_value=?, stat_label=?, page=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$stat_key, $stat_value, $stat_label, $page, $sort_order, $is_active, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO site_stats (stat_key, stat_value, stat_label, page, sort_order, is_active) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$stat_key, $stat_value, $stat_label, $page, $sort_order, $is_active]);
                $id = $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Stats — delete (admin) ───────────────────────────────────
    case 'delete_site_stat':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM site_stats WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Media — list (admin) ─────────────────────────────────────
    case 'list_site_media':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureSiteMediaSchema($pdo);
            $cat = trim($_GET['category'] ?? '');
            if ($cat) {
                $stmt = $pdo->prepare("SELECT * FROM site_media WHERE category=? ORDER BY created_at DESC");
                $stmt->execute([$cat]);
            } else {
                $stmt = $pdo->query("SELECT * FROM site_media ORDER BY created_at DESC");
            }
            echo json_encode(['success' => true, 'media' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Media — fetch a single permanent image by usage_key (public) ──
    case 'get_site_media_by_key':
        try {
            ensureSiteMediaSchema($pdo);
            $key = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_GET['key'] ?? '')));
            if ($key === '') { echo json_encode(['success' => true, 'media' => null]); break; }
            $stmt = $pdo->prepare("SELECT id, title, description, file_path, file_type, alt_text, usage_key, category
                                   FROM site_media WHERE usage_key=? AND is_active=1
                                   ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'media' => $row ?: null]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Media — upload (admin) ───────────────────────────────────
    case 'upload_site_media':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureSiteMediaSchema($pdo);

            if (!isset($_FILES['file'])) {
                http_response_code(400); echo json_encode(['error' => 'No file uploaded']); break;
            }
            if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize.',
                    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
                    UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Server failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
                ];
                $msg = $uploadErrors[$_FILES['file']['error']] ?? 'Upload failed.';
                http_response_code(400); echo json_encode(['error' => $msg]); break;
            }

            $category = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['category'] ?? 'general')));
            if ($category === '') $category = 'general';
            $uploadDir = 'uploads/' . $category . '/';

            // secureUpload handles JPG/PNG/WebP/GIF/SVG plus HEIC/AVIF/TIFF/BMP from phones
            // (Imagick converts those to JPG), strips metadata, and downscales large photos.
            $savedPath = secureUpload($_FILES['file'], $uploadDir, true, 12 * 1024 * 1024);
            if ($savedPath === false) {
                http_response_code(400);
                echo json_encode(['error' => 'Could not save file. Check the file type (JPG, PNG, WebP, GIF, SVG, HEIC, PDF) and that it is under 12 MB.']);
                break;
            }

            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            $ftype = $finfo ? @finfo_file($finfo, $savedPath) : '';
            if ($finfo) finfo_close($finfo);
            $fileType = ($ftype && str_starts_with($ftype, 'image/')) ? 'image' : 'document';

            $title       = trim($_POST['title'] ?? pathinfo($_FILES['file']['name'], PATHINFO_FILENAME));
            $description = trim($_POST['description'] ?? '');
            $altText     = trim($_POST['alt_text'] ?? '');
            $usageKey    = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['usage_key'] ?? '')));
            if ($usageKey === '') $usageKey = null;
            $fileSize    = is_file($savedPath) ? filesize($savedPath) : (int)$_FILES['file']['size'];

            // If a usage_key is supplied, retire any previous active image in that slot.
            // The old file row stays in the table (so links elsewhere don't break) but is
            // marked inactive so get_site_media_by_key returns the latest one.
            if ($usageKey !== null) {
                $pdo->prepare("UPDATE site_media SET is_active=0 WHERE usage_key=? AND is_active=1")
                    ->execute([$usageKey]);
            }

            $stmt = $pdo->prepare("INSERT INTO site_media
                (title, description, file_path, file_type, file_size, category, usage_key, alt_text, is_active, uploaded_by)
                VALUES (?,?,?,?,?,?,?,?,1,?)");
            $stmt->execute([$title, $description, $savedPath, $fileType, $fileSize, $category, $usageKey, $altText, $_SESSION['user_id']]);
            echo json_encode([
                'success'   => true,
                'id'        => $pdo->lastInsertId(),
                'file_path' => $savedPath,
                'usage_key' => $usageKey,
            ]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        } catch (Throwable $e) {
            error_log('upload_site_media: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Site Media — delete (admin) ───────────────────────────────────
    case 'delete_site_media':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $stmt = $pdo->prepare("SELECT file_path FROM site_media WHERE id=?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['file_path']) && file_exists($row['file_path'])) {
                @unlink($row['file_path']);
            }
            $pdo->prepare("DELETE FROM site_media WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Leadership / Biography Management ─────────────────────────────
    case 'get_leaders':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT,
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->query("SELECT * FROM leaders WHERE is_active = 1 ORDER BY
                CASE
                    WHEN LOWER(title) LIKE '%president' AND LOWER(title) NOT LIKE '%elect%' AND LOWER(title) NOT LIKE '%vice%' THEN 1
                    WHEN LOWER(title) LIKE '%vice president%' THEN 2
                    WHEN LOWER(title) LIKE '%president elect%' THEN 3
                    WHEN LOWER(title) LIKE '%general secretary%' THEN 4
                    WHEN LOWER(title) LIKE '%treasurer%' THEN 5
                    WHEN LOWER(title) LIKE '%secretary%' THEN 6
                    ELSE 100
                END ASC, sort_order ASC, name ASC");
            echo json_encode(['success' => true, 'leaders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_leader_bio':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT,
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $name = trim($_GET['name'] ?? '');
            if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required']); break; }
            $stmt = $pdo->prepare("SELECT * FROM leaders WHERE name = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$name]);
            $leader = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'leader' => $leader ?: null]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_leader':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT,
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $qualifications = trim($_POST['qualifications'] ?? '');
            $biography = trim($_POST['biography'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name is required']); break; }

            // Handle photo upload
            $photo_url = trim($_POST['existing_photo'] ?? '');
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
                $file_type = mime_content_type($_FILES['photo']['tmp_name']);
                if (!in_array($file_type, $allowed_types)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Only JPG, PNG or WebP images are allowed']);
                    break;
                }
                if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Photo must be under 5 MB']);
                    break;
                }
                $uploadDir = 'uploads/leaders/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
                $photoName = uniqid('leader_') . ($ext[$file_type] ?? '.jpg');
                $targetPath = $uploadDir . $photoName;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                    $photo_url = $targetPath;
                }
            } elseif (!empty($_POST['photo_url'])) {
                $photo_url = trim($_POST['photo_url']);
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE leaders SET name=?, title=?, qualifications=?, biography=?, photo_url=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $title, $qualifications, $biography, $photo_url, $sort_order, $is_active, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO leaders (name, title, qualifications, biography, photo_url, sort_order, is_active) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$name, $title, $qualifications, $biography, $photo_url, $sort_order, $is_active]);
                $id = $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_leader':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM leaders WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'get_audit_logs':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            $total = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
            $stmt = $pdo->prepare("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");
            $stmt->execute([$limit, $offset]);
            echo json_encode(['success' => true, 'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page]);
        } catch (PDOException $e) {
            error_log('API: ' . $e->getMessage()); http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ── Cancel a pending payment (user-initiated, no auth needed — uses receipt_token) ──
    case 'cancel_pending_payment':
        try {
            $paymentId    = (int)($_POST['payment_id'] ?? 0);
            $receiptToken = trim($_POST['receipt_token'] ?? '');

            if (!$paymentId || strlen($receiptToken) !== 64) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment reference.']);
                break;
            }

            // Only allow cancellation of pending payments, verified by receipt_token
            $stmt = $pdo->prepare("SELECT id, member_id, payment_type, event_id FROM payments WHERE id = ? AND receipt_token = ? AND status = 'pending'");
            $stmt->execute([$paymentId, $receiptToken]);
            $pay = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$pay) {
                http_response_code(404);
                echo json_encode(['error' => 'Payment not found, already processed, or cannot be cancelled.']);
                break;
            }

            $pdo->beginTransaction();

            // If event registration, also delete the pending registrant
            if ($pay['payment_type'] === 'event_registration' && !empty($pay['event_id'])) {
                $pdo->prepare("DELETE FROM event_registrants WHERE event_id = ? AND payment_status = 'pending' AND email = (SELECT email FROM members WHERE id = ? LIMIT 1)")->execute([$pay['event_id'], $pay['member_id']]);
            }

            // Delete the pending payment
            $pdo->prepare("DELETE FROM payments WHERE id = ? AND status = 'pending'")->execute([$pay['id']]);

            // Check if the member has any other payments — if not, remove orphan member
            $otherPay = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE member_id = ?");
            $otherPay->execute([$pay['member_id']]);
            if ((int)$otherPay->fetchColumn() === 0) {
                $pdo->prepare("DELETE FROM members WHERE id = ? AND status = 'pending'")->execute([$pay['member_id']]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Pending payment cancelled. You can initiate a new payment.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('cancel_pending_payment error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to cancel payment. Please try again.']);
        }
        break;

    case 'run_renewal_reminders':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $dry = !empty($_POST['dry_run']);
            // Capture stdout from the dispatcher
            ob_start();
            $_GET['admin_inline'] = '1';
            if ($dry) $_GET['dry_run'] = '1';
            // Mark this as admin-triggered so the dispatcher knows
            $_SESSION['_renewal_admin_run'] = true;
            include __DIR__ . '/send_renewal_reminders.php';
            unset($_SESSION['_renewal_admin_run']);
            $output = ob_get_clean();
            // Parse the JSON tail if present
            $sent = 0; $skipped = 0; $failed = 0; $candidates = 0;
            if (preg_match('/\n---\n(\{.*\})\s*$/s', $output, $mm)) {
                $tail = json_decode($mm[1], true);
                if (is_array($tail)) {
                    $sent       = (int)($tail['sent'] ?? 0);
                    $skipped    = (int)($tail['skipped'] ?? 0);
                    $failed     = (int)($tail['failed'] ?? 0);
                    $candidates = (int)($tail['candidates'] ?? 0);
                }
            }
            auditLog($pdo, 'run_renewal_reminders', 'system', null, "sent=$sent failed=$failed dry=" . ($dry ? '1' : '0'));
            echo json_encode([
                'success'    => true,
                'sent'       => $sent,
                'skipped'    => $skipped,
                'failed'     => $failed,
                'candidates' => $candidates,
                'dry_run'    => (bool)$dry,
                'log'        => $output,
            ]);
        } catch (Exception $e) {
            error_log('run_renewal_reminders: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to run renewal reminders']);
        }
        break;

    case 'issue_membership_certificate':
        // Auth handled inside certificate.php directly; this is just a discovery endpoint.
        // Returns a tokenised certificate URL for the given verified member id.
        if (empty($_SESSION['user_id'])) {
            http_response_code(401); echo json_encode(['error' => 'Sign in required']); break;
        }
        try {
            $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
            $userId  = (int)$_SESSION['user_id'];
            $memberId = (int)($_POST['member_id'] ?? $_GET['member_id'] ?? 0);

            if (!$isAdmin) {
                // Members can only mint a certificate for their own row
                $s = $pdo->prepare("SELECT id FROM members WHERE user_id = ? LIMIT 1");
                $s->execute([$userId]);
                $ownId = (int)$s->fetchColumn();
                if (!$ownId) { http_response_code(404); echo json_encode(['error' => 'No member record']); break; }
                $memberId = $ownId;
            }
            if (!$memberId) { http_response_code(400); echo json_encode(['error' => 'member_id required']); break; }

            // Verify member is active (approved + paid + not expired)
            $s = $pdo->prepare("SELECT approval_status, dues_paid_at, expiry_date, status FROM members WHERE id = ?");
            $s->execute([$memberId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Member not found']); break; }
            $derived = hosuMembershipStatus($row);
            if (!in_array($derived, ['active','honorary'], true)) {
                http_response_code(403);
                echo json_encode(['error' => 'Certificate only available for active or honorary members', 'status' => $derived]);
                break;
            }
            $url = 'certificate.php?member=' . $memberId;
            echo json_encode(['success' => true, 'certificate_url' => $url]);
        } catch (Exception $e) {
            error_log('issue_membership_certificate: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ─── COMMITTEES / WORKING GROUPS ────────────────────────────────────
    case 'list_committees':
        try {
            hosuPublicJsonCache(60);
            // Ensure tables exist (idempotent first-run safety)
            ensureCommitteeTables($pdo);
            $rows = $pdo->query("
                SELECT c.id, c.slug, c.name, c.description, c.discipline, c.is_active, c.sort_order,
                       (SELECT COUNT(*) FROM committee_members cm WHERE cm.committee_id = c.id) AS member_count
                  FROM committees c
                 WHERE c.is_active = 1
                 ORDER BY c.sort_order, c.name
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'committees' => $rows]);
        } catch (Exception $e) {
            error_log('list_committees: ' . $e->getMessage());
            echo json_encode(['success' => true, 'committees' => []]);
        }
        break;

    case 'list_committees_admin':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $rows = $pdo->query("
                SELECT c.id, c.slug, c.name, c.description, c.discipline, c.is_active, c.sort_order,
                       (SELECT COUNT(*) FROM committee_members cm WHERE cm.committee_id = c.id) AS member_count
                  FROM committees c
                 ORDER BY c.sort_order, c.name
            ")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'committees' => $rows]);
        } catch (Exception $e) {
            error_log('list_committees_admin: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'save_committee':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $id          = (int)($_POST['id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $discipline  = trim($_POST['discipline'] ?? 'general');
            $sort        = (int)($_POST['sort_order'] ?? 0);
            $active      = !empty($_POST['is_active']) ? 1 : 0;
            if ($name === '') { http_response_code(400); echo json_encode(['error' => 'Name required']); break; }
            $slug = trim($_POST['slug'] ?? '');
            if ($slug === '') {
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
                $slug = trim($slug, '-') ?: ('committee-' . time());
            }
            if ($id > 0) {
                $pdo->prepare("UPDATE committees SET name=?, description=?, discipline=?, slug=?, sort_order=?, is_active=? WHERE id=?")
                    ->execute([$name, $description, $discipline, $slug, $sort, $active, $id]);
                auditLog($pdo, 'update_committee', 'committee', (string)$id, $name);
            } else {
                $pdo->prepare("INSERT INTO committees (slug, name, description, discipline, sort_order, is_active) VALUES (?,?,?,?,?,?)")
                    ->execute([$slug, $name, $description, $discipline, $sort, $active]);
                $id = (int)$pdo->lastInsertId();
                auditLog($pdo, 'create_committee', 'committee', (string)$id, $name);
            }
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (Exception $e) {
            error_log('save_committee: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_committee':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); break; }
            $pdo->prepare("DELETE FROM committee_members WHERE committee_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM committees WHERE id = ?")->execute([$id]);
            auditLog($pdo, 'delete_committee', 'committee', (string)$id);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('delete_committee: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'list_committee_members':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $committeeId = (int)($_GET['committee_id'] ?? $_POST['committee_id'] ?? 0);
            if (!$committeeId) { http_response_code(400); echo json_encode(['error' => 'committee_id required']); break; }
            $stmt = $pdo->prepare("
                SELECT cm.id, cm.member_id, cm.role, cm.joined_at,
                       m.full_name, m.email, m.institution, m.membership_number, m.approval_status,
                       mc.name AS category_name
                  FROM committee_members cm
                  JOIN members m ON m.id = cm.member_id
                  LEFT JOIN membership_categories mc ON mc.id = m.category_id
                 WHERE cm.committee_id = ?
                 ORDER BY cm.role = 'chair' DESC, cm.role = 'co-chair' DESC, m.full_name
            ");
            $stmt->execute([$committeeId]);
            echo json_encode(['success' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) {
            error_log('list_committee_members: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'add_committee_member':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $committeeId = (int)($_POST['committee_id'] ?? 0);
            $memberId    = (int)($_POST['member_id'] ?? 0);
            $role        = trim($_POST['role'] ?? 'member');
            $allowedRoles = ['member','chair','co-chair','secretary','treasurer'];
            if (!in_array($role, $allowedRoles, true)) $role = 'member';
            if (!$committeeId || !$memberId) { http_response_code(400); echo json_encode(['error' => 'committee_id and member_id required']); break; }

            // Verify member exists
            $check = $pdo->prepare("SELECT full_name, committee FROM members WHERE id = ?");
            $check->execute([$memberId]);
            $mrow = $check->fetch(PDO::FETCH_ASSOC);
            if (!$mrow) { http_response_code(404); echo json_encode(['error' => 'Member not found']); break; }

            // Already in committee? upsert role
            $exist = $pdo->prepare("SELECT id FROM committee_members WHERE committee_id = ? AND member_id = ?");
            $exist->execute([$committeeId, $memberId]);
            $existingId = (int)$exist->fetchColumn();
            if ($existingId) {
                $pdo->prepare("UPDATE committee_members SET role = ? WHERE id = ?")->execute([$role, $existingId]);
            } else {
                $pdo->prepare("INSERT INTO committee_members (committee_id, member_id, role) VALUES (?,?,?)")
                    ->execute([$committeeId, $memberId, $role]);
            }

            // Update the denormalised committee label on members.committee with the first committee name (back-compat)
            $cn = $pdo->prepare("SELECT name FROM committees WHERE id = ?");
            $cn->execute([$committeeId]);
            $cname = (string)$cn->fetchColumn();
            if ($cname && empty($mrow['committee'])) {
                $pdo->prepare("UPDATE members SET committee = ? WHERE id = ?")->execute([$cname, $memberId]);
            }
            auditLog($pdo, 'add_committee_member', 'committee', (string)$committeeId, "member=$memberId role=$role");
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('add_committee_member: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'remove_committee_member':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCommitteeTables($pdo);
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); break; }
            $pdo->prepare("DELETE FROM committee_members WHERE id = ?")->execute([$id]);
            auditLog($pdo, 'remove_committee_member', 'committee', (string)$id);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('remove_committee_member: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ─── BULK EMAIL BROADCAST ────────────────────────────────────────────
    case 'broadcast_email_preview':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $audience = $_POST['audience'] ?? 'active';
            $recipients = hosuResolveBroadcastAudience($pdo, $audience, (int)($_POST['committee_id'] ?? 0));
            echo json_encode([
                'success'    => true,
                'audience'   => $audience,
                'recipient_count' => count($recipients),
                'sample'     => array_slice(array_map(fn($r) => ['name' => $r['full_name'], 'email' => $r['email']], $recipients), 0, 8),
            ]);
        } catch (Exception $e) {
            error_log('broadcast_email_preview: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'broadcast_email_send':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $audience = $_POST['audience'] ?? 'active';
            $subject  = trim($_POST['subject'] ?? '');
            $bodyHtml = trim($_POST['body'] ?? '');
            $committeeId = (int)($_POST['committee_id'] ?? 0);
            if ($subject === '' || $bodyHtml === '') {
                http_response_code(400); echo json_encode(['error' => 'Subject and body are required']); break;
            }
            if (strlen($subject) > 200) $subject = substr($subject, 0, 200);

            $recipients = hosuResolveBroadcastAudience($pdo, $audience, $committeeId);
            if (!count($recipients)) { echo json_encode(['success' => true, 'sent' => 0, 'failed' => 0, 'audience' => $audience, 'note' => 'No recipients in this audience']); break; }

            ensureMailer();
            $sent = 0; $failed = 0;
            foreach ($recipients as $r) {
                $safeName = htmlspecialchars($r['full_name'] ?? 'Member', ENT_QUOTES, 'UTF-8');
                $personal = str_replace(['{{name}}', '{{first_name}}'], [$safeName, explode(' ', $safeName)[0]], $bodyHtml);
                $html = hosuWrapBroadcastTemplate($subject, $personal);
                $ok = hosuMail($r['email'], $subject, $html, 'HOSU Secretariat');
                if ($ok) $sent++; else $failed++;
                // Light throttle — avoid bursts (skip in dev if needed)
                if ($sent % 25 === 0) usleep(200000);
            }
            auditLog($pdo, 'broadcast_email_send', 'system', null, "audience=$audience sent=$sent failed=$failed");
            echo json_encode(['success' => true, 'sent' => $sent, 'failed' => $failed, 'audience' => $audience, 'total' => count($recipients)]);
        } catch (Exception $e) {
            error_log('broadcast_email_send: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    // ─── CPD POINTS ──────────────────────────────────────────────────────
    case 'list_cpd_entries':
        if (empty($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error' => 'Sign in required']); break; }
        try {
            ensureCpdTables($pdo);
            $isAdmin = ($_SESSION['user_role'] ?? '') === 'admin';
            $memberId = (int)($_GET['member_id'] ?? $_POST['member_id'] ?? 0);
            if (!$isAdmin) {
                $s = $pdo->prepare("SELECT id FROM members WHERE user_id = ? LIMIT 1");
                $s->execute([(int)$_SESSION['user_id']]);
                $memberId = (int)$s->fetchColumn();
            }
            if (!$memberId) { echo json_encode(['success' => true, 'entries' => [], 'total_points' => 0]); break; }
            $stmt = $pdo->prepare("SELECT id, activity, points, activity_date, source, awarded_at FROM cpd_entries WHERE member_id = ? ORDER BY activity_date DESC, id DESC");
            $stmt->execute([$memberId]);
            $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total = (int)$pdo->query("SELECT COALESCE(SUM(points),0) FROM cpd_entries WHERE member_id = $memberId")->fetchColumn();
            echo json_encode(['success' => true, 'entries' => $entries, 'total_points' => $total]);
        } catch (Exception $e) {
            error_log('list_cpd_entries: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'add_cpd_entry':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCpdTables($pdo);
            $memberId = (int)($_POST['member_id'] ?? 0);
            $activity = trim($_POST['activity'] ?? '');
            $points   = (int)($_POST['points'] ?? 0);
            $date     = trim($_POST['activity_date'] ?? date('Y-m-d'));
            $source   = trim($_POST['source'] ?? 'manual');
            if (!$memberId || $activity === '' || $points <= 0) {
                http_response_code(400); echo json_encode(['error' => 'member_id, activity and positive points required']); break;
            }
            $pdo->prepare("INSERT INTO cpd_entries (member_id, activity, points, activity_date, source, awarded_by) VALUES (?,?,?,?,?,?)")
                ->execute([$memberId, $activity, $points, $date, $source, (int)$_SESSION['user_id']]);
            // Recompute denormalised total on members
            $pdo->exec("UPDATE members SET cpd_points = (SELECT COALESCE(SUM(points),0) FROM cpd_entries WHERE member_id = $memberId) WHERE id = $memberId");
            auditLog($pdo, 'add_cpd_entry', 'member', (string)$memberId, "+$points $activity");
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (Exception $e) {
            error_log('add_cpd_entry: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    case 'delete_cpd_entry':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            ensureCpdTables($pdo);
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); break; }
            $row = $pdo->prepare("SELECT member_id FROM cpd_entries WHERE id = ?");
            $row->execute([$id]);
            $memberId = (int)$row->fetchColumn();
            $pdo->prepare("DELETE FROM cpd_entries WHERE id = ?")->execute([$id]);
            if ($memberId) {
                $pdo->exec("UPDATE members SET cpd_points = (SELECT COALESCE(SUM(points),0) FROM cpd_entries WHERE member_id = $memberId) WHERE id = $memberId");
            }
            auditLog($pdo, 'delete_cpd_entry', 'cpd_entry', (string)$id);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            error_log('delete_cpd_entry: ' . $e->getMessage());
            http_response_code(500); echo json_encode(['error' => 'Server error']);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}