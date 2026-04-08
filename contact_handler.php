<?php
/**
 * contact_handler.php — Processes the public contact form and forwards
 * the message to info@hosu.or.ug via PHPMailer / SMTP.
 */

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

require_once __DIR__ . '/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Rate limiting: max 5 submissions per hour per session ──
if (!isset($_SESSION['contact_attempts'], $_SESSION['contact_window_start'])) {
    $_SESSION['contact_attempts']     = 0;
    $_SESSION['contact_window_start'] = time();
}
if (time() - $_SESSION['contact_window_start'] >= 3600) {
    $_SESSION['contact_attempts']     = 0;
    $_SESSION['contact_window_start'] = time();
}
if ($_SESSION['contact_attempts'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit;
}

// ── Validate & sanitise inputs ────────────────────────────────────────
$name    = trim(strip_tags($_POST['name']    ?? ''));
$email   = trim($_POST['email']   ?? '');
$subject = trim(strip_tags($_POST['subject'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

$allowedSubjects = ['Membership Inquiry', 'Research Collaboration', 'Events Information', 'Other'];

if (empty($name) || strlen($name) > 100) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid name.']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}
if (!in_array($subject, $allowedSubjects, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please select a valid subject.']);
    exit;
}
if (empty($message) || strlen($message) > 5000) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Message is required and must be under 5000 characters.']);
    exit;
}

$_SESSION['contact_attempts']++;

// ── Build HTML email body ─────────────────────────────────────────────
$safeName    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
$safeEmail   = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
$safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$htmlBody = <<<HTML
<div style="font-family:Inter,Arial,sans-serif;max-width:620px;margin:auto;background:#f1faee;border-radius:12px;overflow:hidden;">
  <div style="background:#0d4593;padding:18px 24px;">
    <h2 style="color:#fff;margin:0;font-size:18px;">New Contact Form Message</h2>
    <p style="color:rgba(255,255,255,0.7);margin:4px 0 0;font-size:13px;">Sent via the HOSU website contact form</p>
  </div>
  <div style="background:#ffffff;padding:24px;border:1px solid #e2e8f0;border-top:none;">
    <table style="width:100%;border-collapse:collapse;font-size:14px;margin-bottom:18px;">
      <tr>
        <td style="padding:8px 0;color:#4a5568;font-weight:600;width:90px;vertical-align:top;">Name:</td>
        <td style="padding:8px 0;color:#001848;">{$safeName}</td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#4a5568;font-weight:600;vertical-align:top;">Email:</td>
        <td style="padding:8px 0;"><a href="mailto:{$safeEmail}" style="color:#0d4593;">{$safeEmail}</a></td>
      </tr>
      <tr>
        <td style="padding:8px 0;color:#4a5568;font-weight:600;vertical-align:top;">Subject:</td>
        <td style="padding:8px 0;color:#001848;">{$safeSubject}</td>
      </tr>
    </table>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;">
    <h4 style="color:#0d4593;margin:0 0 10px;font-size:14px;">Message:</h4>
    <p style="color:#001848;line-height:1.7;font-size:14px;margin:0;">{$safeMessage}</p>
    <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0 14px;">
    <p style="color:#a0aec0;font-size:12px;margin:0;">
      Reply directly to <a href="mailto:{$safeEmail}" style="color:#0d4593;">{$safeEmail}</a> to respond to this enquiry.
    </p>
  </div>
</div>
HTML;

// ── Send the email to HOSU inbox ──────────────────────────────────────
$emailSubject = "Contact Form [{$subject}] — {$name}";
$sent = hosuMail('info@hosu.or.ug', $emailSubject, $htmlBody, "HOSU Website");

if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Thank you! Your message has been sent. We will get back to you soon.']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not send your message right now. Please email us directly at info@hosu.or.ug.']);
}
