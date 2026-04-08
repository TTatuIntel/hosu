<?php
/**
 * payment.php — Server-side payment gateway proxy
 * 
 * Proxies MTN Mobile Money, Airtel Money, and Visa requests through the server
 * to avoid CORS issues and keep API credentials secure.
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

require_once 'db.php';
require_once 'mailer.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Gateway config (PesaPal v3) ───────────────────────────────────────
define('PESAPAL_ENV',             getenv('PESAPAL_ENV') ?: 'production');
define('PESAPAL_BASE',            PESAPAL_ENV === 'sandbox'
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3');
define('PESAPAL_CONSUMER_KEY',    getenv('PESAPAL_CONSUMER_KEY') ?: '');
define('PESAPAL_CONSUMER_SECRET', getenv('PESAPAL_CONSUMER_SECRET') ?: '');

/**
 * Make a request to the PesaPal API.
 */
function pesapalRequest(string $method, string $endpoint, ?array $body, string $token = ''): array
{
    $url     = PESAPAL_BASE . $endpoint;
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body ?? new stdClass()));
    }

    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException('PesaPal connection error: ' . $err);
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException("PesaPal returned invalid response (HTTP $httpCode)");
    return $data;
}

/**
 * Get a PesaPal bearer token (cached in temp dir for 4 minutes).
 */
function pesapalGetToken(): string
{
    $cache = sys_get_temp_dir() . '/hosu_pp_token.json';
    if (is_file($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if ($c && !empty($c['token']) && !empty($c['exp']) && time() < $c['exp']) {
            return $c['token'];
        }
    }
    $res = pesapalRequest('POST', '/api/Auth/RequestToken', [
        'consumer_key'    => PESAPAL_CONSUMER_KEY,
        'consumer_secret' => PESAPAL_CONSUMER_SECRET,
    ], '');
    if (($res['status'] ?? '') !== '200' || empty($res['token'])) {
        throw new RuntimeException('PesaPal auth failed: ' . ($res['message'] ?? json_encode($res)));
    }
    file_put_contents($cache, json_encode(['token' => $res['token'], 'exp' => time() + 240]));
    return $res['token'];
}

/**
 * Get (or register) the PesaPal IPN ID for this site.
 */
function pesapalGetIpnId(string $token): string
{
    $cache = sys_get_temp_dir() . '/hosu_pp_ipn.json';
    if (is_file($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if ($c && !empty($c['ipn_id'])) return $c['ipn_id'];
    }
    // Check for existing registrations
    $list = pesapalRequest('GET', '/api/URLSetup/GetIpnList', null, $token);
    if (is_array($list) && !empty($list[0]['ipn_id'])) {
        file_put_contents($cache, json_encode(['ipn_id' => $list[0]['ipn_id']]));
        return $list[0]['ipn_id'];
    }
    // Register a new IPN endpoint
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $ipnUrl   = $protocol . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/pesapal_ipn.php';
    $reg = pesapalRequest('POST', '/api/URLSetup/RegisterIPN', [
        'url'                   => $ipnUrl,
        'ipn_notification_type' => 'GET',
    ], $token);
    if (empty($reg['ipn_id'])) throw new RuntimeException('PesaPal IPN registration failed: ' . json_encode($reg));
    file_put_contents($cache, json_encode(['ipn_id' => $reg['ipn_id']]));
    return $reg['ipn_id'];
}

/**
 * Generate a unique transaction reference.
 */
function generateTxnRef(string $prefix = 'TXN'): string
{
    return $prefix . '-' . time() . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * Validate and sanitize phone number to digits only.
 * Accepts international numbers (with or without + prefix).
 * For Uganda numbers without country code, prepends 256.
 */
function sanitizePhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    // If already has a valid country code prefix (3+ digits for most countries)
    if (strlen($digits) >= 10) {
        return $digits;
    }
    // Handle 0-prefix (assume Uganda if no country code and starts with 0)
    if (str_starts_with($digits, '0') && strlen($digits) >= 9) {
        return '256' . substr($digits, 1);
    }
    // Short number without country code — assume Uganda
    if (strlen($digits) >= 7 && strlen($digits) <= 9) {
        return '256' . $digits;
    }
    return $digits;
}

/**
 * Send receipt email (reused from api.php).
 */
function sendEventReceiptEmail(PDO $pdo, int $regId): bool
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM event_registrants WHERE id = ?");
        $stmt->execute([$regId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return false;

        $name     = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');
        $email    = $row['email'];
        $amount   = number_format((float)$row['amount'], 0, '.', ',');
        $receipt  = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $method   = htmlspecialchars($row['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
        $paidAt   = $row['registered_at'] ?? date('Y-m-d H:i:s');
        $evTitle  = htmlspecialchars($row['event_title'] ?? '', ENT_QUOTES, 'UTF-8');
        $evDate   = htmlspecialchars($row['event_date'] ?? '', ENT_QUOTES, 'UTF-8');
        $token    = $row['receipt_token'] ?? '';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = filter_var($host, FILTER_SANITIZE_URL) ?: 'localhost';
        $receiptUrl = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/receipt.php?token=' . urlencode($token);

        $subject = "HOSU Event Registration Receipt — $receipt";
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:24px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — Event Registration Receipt</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 12px;color:#333;">Dear <strong>$name</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Thank you for registering for <strong>$evTitle</strong>. Your payment has been confirmed.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Receipt #</strong></td><td>$receipt</td></tr>
      <tr><td><strong>Event</strong></td><td>$evTitle</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Date</strong></td><td>$evDate</td></tr>
      <tr><td><strong>Amount</strong></td><td>UGX $amount</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Method</strong></td><td>$method</td></tr>
      <tr><td><strong>Date Paid</strong></td><td>$paidAt</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Status</strong></td><td style="color:#27ae60;font-weight:700;">Verified ✓</td></tr>
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
    } catch (\Throwable $e) {
        error_log('sendEventReceiptEmail: ' . $e->getMessage());
        return false;
    }
}


/**
 * Confirm a donation/membership payment (payments + members tables).
 */
function confirmDonationPayment(PDO $pdo, int $paymentId, string $txnRef = ''): void
{
    $pdo->beginTransaction();
    // Lock the row to prevent race conditions
    $row = $pdo->prepare("SELECT id, member_id, status, receipt_token FROM payments WHERE id = ? FOR UPDATE");
    $row->execute([$paymentId]);
    $pay = $row->fetch(PDO::FETCH_ASSOC);
    if (!$pay || $pay['status'] === 'verified') {
        $pdo->rollBack();
        return;
    }

    $upd = "UPDATE payments SET status='verified', paid_at=NOW()";
    $params = [];
    if ($txnRef) {
        $upd .= ", transaction_ref = ?";
        $params[] = $txnRef;
    }
    $upd .= " WHERE id = ?";
    $params[] = $paymentId;
    $pdo->prepare($upd)->execute($params);
    if ($pay['member_id']) {
        $pdo->prepare("UPDATE members SET status='active' WHERE id = ?")->execute([$pay['member_id']]);
    }
    $pdo->commit();

    // Send receipt email using the same logic as api.php
    sendDonationReceiptEmail($pdo, $paymentId);
}

/**
 * Send receipt email for donation/membership payments (payments + members tables).
 */
function sendDonationReceiptEmail(PDO $pdo, int $paymentId): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, m.full_name, m.email, m.phone
            FROM payments p JOIN members m ON m.id = p.member_id
            WHERE p.id = ?
        ");
        $stmt->execute([$paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return false;

        $name    = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');
        $email   = $row['email'];
        $amount  = number_format((float)$row['amount'], 0, '.', ',');
        $receipt = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $method  = htmlspecialchars($row['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
        $paidAt  = $row['paid_at'] ?? date('Y-m-d H:i:s');
        $type    = $row['payment_type'] ?? 'payment';
        $token   = $row['receipt_token'] ?? '';

        $typeLabels = ['membership' => 'Membership Payment', 'donation' => 'Donation', 'event_registration' => 'Event Registration'];
        $typeLabel = $typeLabels[$type] ?? 'Payment';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = filter_var($host, FILTER_SANITIZE_URL) ?: 'localhost';
        $receiptUrl = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/receipt.php?token=' . urlencode($token);

        $subject = "HOSU Receipt — $receipt";
        $htmlBody = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:24px 28px;"><h1 style="margin:0;color:#fff;font-size:20px;">HOSU — $typeLabel Receipt</h1></td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 12px;color:#333;">Dear <strong>$name</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Thank you for your $typeLabel. Here is your payment receipt.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Receipt #</strong></td><td>$receipt</td></tr>
      <tr><td><strong>Amount</strong></td><td>UGX $amount</td></tr>
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
    This is an auto-generated receipt.
  </td></tr>
</table></body></html>
HTML;

        $sent = hosuMail($email, $subject, $htmlBody);
        if ($sent) {
            // Auto-set invoice_sent flag
            try {
                $pdo->prepare("UPDATE payments SET invoice_sent = 1 WHERE id = ?")->execute([$paymentId]);
            } catch (Exception $e) { /* ignore if column missing */ }
        }
        return $sent;
    } catch (\Throwable $e) {
        error_log('sendDonationReceiptEmail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Helper: update the correct table on payment success.
 * Supports both event_registrants (registrant_id) and payments (payment_id).
 */
function markPaymentVerified(PDO $pdo, int $regId, int $payId, string $txnRef = '', string $txnId = ''): void
{
    if ($regId) {
        $sets = ["payment_status = 'verified'"];
        $params = [];
        if ($txnId) { $sets[] = "transaction_id = ?"; $params[] = $txnId; }
        if ($txnRef) { $sets[] = "transaction_ref = ?"; $params[] = $txnRef; }
        $params[] = $regId;
        $pdo->prepare("UPDATE event_registrants SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        sendEventReceiptEmail($pdo, $regId);
    }
    if ($payId) {
        confirmDonationPayment($pdo, $payId, $txnRef);
    }
}

/**
 * Helper: save transaction reference to the correct table.
 */
function saveTxnRef(PDO $pdo, int $regId, int $payId, string $txnRef, string $method): void
{
    if ($regId) {
        $pdo->prepare("UPDATE event_registrants SET transaction_ref = ?, payment_method = ? WHERE id = ?")
            ->execute([$txnRef, $method, $regId]);
    }
    if ($payId) {
        $pdo->prepare("UPDATE payments SET transaction_ref = ?, payment_method = ? WHERE id = ?")
            ->execute([$txnRef, $method, $payId]);
    }
}

// ── Route actions ─────────────────────────────────────────────────────
switch ($action) {

    // ──────────────────────────────────────────────────────────────────
    // Initialise a PesaPal hosted-page payment
    // Supports: membership, donation (payment_id) and event (registrant_id)
    // ──────────────────────────────────────────────────────────────────
    case 'init_pesapal':
        try {
            $payId   = (int)($_POST['payment_id']    ?? 0);
            $regId   = (int)($_POST['registrant_id'] ?? 0);
            $tok     = trim($_POST['receipt_token']  ?? '');
            $amount  = (float)($_POST['amount']      ?? 0);
            $email   = trim($_POST['email']          ?? '');
            $name    = trim($_POST['name']           ?? '');
            $phone   = trim($_POST['phone']          ?? '');
            $type    = trim($_POST['type']           ?? 'membership');

            if (!in_array($type, ['membership','event_registration','donation'])) $type = 'membership';

            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment amount.']);
                break;
            }
            if (!$payId && !$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'Payment reference required.']);
                break;
            }
            if (empty(PESAPAL_CONSUMER_KEY) || empty(PESAPAL_CONSUMER_SECRET)) {
                http_response_code(500);
                echo json_encode(['error' => 'Payment gateway not configured. Please contact info@hosu.or.ug.']);
                break;
            }

            $ppToken  = pesapalGetToken();
            $ipnId    = pesapalGetIpnId($ppToken);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host     = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $base     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

            $callbackUrl = $protocol . '://' . $host . $base . '/pesapal_callback.php?' . http_build_query([
                'payment_id'    => $payId,
                'registrant_id' => $regId,
                'receipt_token' => $tok,
                'type'          => $type,
            ]);

            $merchantRef = 'HOSU-' . strtoupper(substr($type, 0, 1)) . '-' . ($payId ?: $regId) . '-' . time();

            // Persist merchant reference
            if ($payId)  $pdo->prepare("UPDATE payments SET transaction_ref = ?, payment_method = 'PesaPal' WHERE id = ?")->execute([$merchantRef, $payId]);
            if ($regId)  $pdo->prepare("UPDATE event_registrants SET transaction_ref = ?, payment_method = 'PesaPal' WHERE id = ?")->execute([$merchantRef, $regId]);

            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? $parts[0];

            $result = pesapalRequest('POST', '/api/Transactions/SubmitOrderRequest', [
                'id'              => $merchantRef,
                'currency'        => 'UGX',
                'amount'          => $amount,
                'description'     => 'HOSU ' . ucfirst(str_replace('_', ' ', $type)),
                'callback_url'    => $callbackUrl,
                'notification_id' => $ipnId,
                'billing_address' => [
                    'email_address' => $email,
                    'phone_number'  => $phone,
                    'country_code'  => 'UG',
                    'first_name'    => $firstName,
                    'last_name'     => $lastName,
                ],
            ], $ppToken);

            if (!empty($result['redirect_url'])) {
                $trackingId = $result['order_tracking_id'] ?? '';
                if ($trackingId) {
                    if ($payId) $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")->execute([$trackingId, $payId]);
                    if ($regId) $pdo->prepare("UPDATE event_registrants SET transaction_id = ? WHERE id = ?")->execute([$trackingId, $regId]);
                }
                echo json_encode(['success' => true, 'redirect_url' => $result['redirect_url'], 'tracking_id' => $trackingId]);
            } else {
                error_log('PesaPal order failed: ' . json_encode($result));
                $msg = $result['message'] ?? ($result['error']['message'] ?? 'Failed to initialize payment. Please try again.');
                http_response_code(500);
                echo json_encode(['error' => $msg]);
            }
        } catch (\Throwable $e) {
            error_log('PesaPal init: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Payment gateway error. Please try again or contact info@hosu.or.ug.']);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Direct mobile money via PesaPal channel (USSD push to phone)
    // channel = UGMTNMOMODIR or UGAIRTELMODIR
    // ──────────────────────────────────────────────────────────────────
    case 'pay_mobile':
        try {
            $phone   = trim($_POST['phone']          ?? '');
            $amount  = (float)($_POST['amount']      ?? 0);
            $payId   = (int)($_POST['payment_id']    ?? 0);
            $regId   = (int)($_POST['registrant_id'] ?? 0);
            $email   = trim($_POST['email']          ?? '');
            $name    = trim($_POST['name']           ?? '');
            $channel = strtoupper(trim($_POST['channel'] ?? ''));
            $type    = trim($_POST['type']           ?? 'membership');
            $tok     = trim($_POST['receipt_token']  ?? '');

            if (!in_array($channel, ['UGMTNMOMODIR', 'UGAIRTELMODIR'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment channel.']);
                break;
            }
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment amount.']);
                break;
            }
            if (!$payId && !$regId) {
                http_response_code(400);
                echo json_encode(['error' => 'Payment reference required.']);
                break;
            }
            if (empty(PESAPAL_CONSUMER_KEY) || empty(PESAPAL_CONSUMER_SECRET)) {
                http_response_code(500);
                echo json_encode(['error' => 'Payment gateway not configured. Please contact info@hosu.or.ug.']);
                break;
            }

            $phone       = sanitizePhone($phone);
            $ppToken     = pesapalGetToken();
            $ipnId       = pesapalGetIpnId($ppToken);
            $methodName  = $channel === 'UGMTNMOMODIR' ? 'MTN Mobile Money' : 'Airtel Money';

            $protocol    = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host        = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
            $base        = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
            $callbackUrl = $protocol . '://' . $host . $base . '/pesapal_callback.php?' . http_build_query([
                'payment_id'    => $payId,
                'registrant_id' => $regId,
                'receipt_token' => $tok,
                'type'          => $type,
            ]);

            $merchantRef = 'HOSU-' . strtoupper(substr($type, 0, 1)) . '-' . ($payId ?: $regId) . '-' . time();
            if ($payId) $pdo->prepare("UPDATE payments SET transaction_ref = ?, payment_method = ? WHERE id = ?")->execute([$merchantRef, $methodName, $payId]);
            if ($regId) $pdo->prepare("UPDATE event_registrants SET transaction_ref = ?, payment_method = ? WHERE id = ?")->execute([$merchantRef, $methodName, $regId]);

            $parts     = explode(' ', trim($name), 2);
            $firstName = $parts[0];
            $lastName  = $parts[1] ?? $parts[0];

            $result = pesapalRequest('POST', '/api/Transactions/SubmitOrderRequest', [
                'id'              => $merchantRef,
                'currency'        => 'UGX',
                'amount'          => $amount,
                'description'     => 'HOSU ' . ucfirst(str_replace('_', ' ', $type)),
                'callback_url'    => $callbackUrl,
                'notification_id' => $ipnId,
                'channel'         => $channel,
                'billing_address' => [
                    'email_address' => $email,
                    'phone_number'  => $phone,
                    'country_code'  => 'UG',
                    'first_name'    => $firstName,
                    'last_name'     => $lastName,
                ],
            ], $ppToken);

            $trackingId = $result['order_tracking_id'] ?? '';
            if ($trackingId) {
                if ($payId) $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")->execute([$trackingId, $payId]);
                if ($regId) $pdo->prepare("UPDATE event_registrants SET transaction_id = ? WHERE id = ?")->execute([$trackingId, $regId]);
            }

            if ($trackingId && empty($result['redirect_url'])) {
                // Direct USSD push confirmed — poll using tracking_id
                echo json_encode(['success' => true, 'tracking_id' => $trackingId, 'status' => 'pending']);
            } elseif (!empty($result['redirect_url'])) {
                // PesaPal wants redirect for this account setting — return redirect_url as fallback
                echo json_encode(['success' => true, 'tracking_id' => $trackingId, 'redirect_url' => $result['redirect_url'], 'status' => 'redirect']);
            } else {
                $msg = $result['message'] ?? ($result['error']['message'] ?? 'Failed to initiate mobile money payment. Please try again.');
                error_log('pay_mobile failed: ' . json_encode($result));
                http_response_code(500);
                echo json_encode(['error' => $msg]);
            }
        } catch (\Throwable $e) {
            error_log('pay_mobile: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Payment gateway error. Please try again or contact info@hosu.or.ug.']);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Poll PesaPal status for mobile money USSD payment
    // ──────────────────────────────────────────────────────────────────
    case 'check_mobile':
        try {
            $trackingId = trim($_GET['tracking_id'] ?? '');
            $payId      = (int)($_GET['payment_id']    ?? 0);
            $regId      = (int)($_GET['registrant_id'] ?? 0);

            if (!$trackingId) {
                echo json_encode(['status' => 'failed', 'message' => 'Missing tracking ID.']);
                break;
            }

            $ppToken = pesapalGetToken();
            $status  = pesapalRequest('GET', '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($trackingId), null, $ppToken);
            $code    = (int)($status['status_code'] ?? -1);
            $txnRef  = $status['merchant_reference'] ?? '';
            $desc    = $status['payment_status_description'] ?? ($status['message'] ?? '');

            if ($code === 1) {
                markPaymentVerified($pdo, $regId, $payId, $txnRef, $trackingId);
                $receiptToken = '';
                if ($payId) {
                    $r = $pdo->prepare("SELECT receipt_token FROM payments WHERE id = ?");
                    $r->execute([$payId]);
                    $receiptToken = (string)($r->fetchColumn() ?: '');
                } elseif ($regId) {
                    $r = $pdo->prepare("SELECT receipt_token FROM event_registrants WHERE id = ?");
                    $r->execute([$regId]);
                    $receiptToken = (string)($r->fetchColumn() ?: '');
                }
                echo json_encode(['status' => 'completed', 'message' => 'Payment confirmed! ✅', 'receipt_token' => $receiptToken]);
            } elseif ($code === 2) {
                echo json_encode(['status' => 'failed', 'message' => $desc ?: 'Payment was declined. Please try again.']);
            } elseif ($code === 3) {
                echo json_encode(['status' => 'failed', 'message' => 'Payment was reversed.']);
            } else {
                echo json_encode(['status' => 'pending', 'message' => $desc ?: 'Waiting for your confirmation…']);
            }
        } catch (\Throwable $e) {
            error_log('check_mobile: ' . $e->getMessage());
            echo json_encode(['status' => 'pending', 'message' => 'Checking payment status…']);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Check PesaPal transaction status by orderTrackingId
    // ──────────────────────────────────────────────────────────────────
    case 'check_pesapal':
        try {
            $trackingId = trim($_GET['tracking_id'] ?? $_POST['tracking_id'] ?? '');
            $payId      = (int)($_GET['payment_id']    ?? $_POST['payment_id']    ?? 0);
            $regId      = (int)($_GET['registrant_id'] ?? $_POST['registrant_id'] ?? 0);

            if (!$trackingId) {
                http_response_code(400);
                echo json_encode(['error' => 'Tracking ID required.']);
                break;
            }

            $ppToken = pesapalGetToken();
            $status  = pesapalRequest('GET', '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($trackingId), null, $ppToken);

            $code = (int)($status['status_code'] ?? -1);
            if ($code === 1) {
                // COMPLETED
                $txnRef = $status['merchant_reference'] ?? '';
                markPaymentVerified($pdo, $regId, $payId, $txnRef, $trackingId);
                echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment confirmed!']);
            } elseif ($code === 2) {
                echo json_encode(['success' => false, 'status' => 'failed', 'message' => 'Payment was declined. Please try again.']);
            } elseif ($code === 3) {
                echo json_encode(['success' => false, 'status' => 'reversed', 'message' => 'Payment was reversed.']);
            } else {
                echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'Awaiting payment confirmation…']);
            }
        } catch (\Throwable $e) {
            error_log('PesaPal check: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Status check failed. Please try again.']);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Pre-register event attendee (before payment)
    // ──────────────────────────────────────────────────────────────────
    case 'pre_register_event':
        try {
            $name        = trim($_POST['fullName'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $profession  = trim($_POST['profession'] ?? '');
            $institution = trim($_POST['institution'] ?? '');
            $eventId     = trim($_POST['eventId'] ?? '');
            $eventTitle  = trim($_POST['eventTitle'] ?? '');
            $eventDate   = trim($_POST['eventDate'] ?? '');
            $amount      = (float)($_POST['amount'] ?? 0);
            $payMethod   = trim($_POST['paymentMethod'] ?? 'PesaPal');

            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and valid email are required.']);
                break;
            }
            if (!$phone) {
                http_response_code(400);
                echo json_encode(['error' => 'Phone number is required.']);
                break;
            }
            if (!$eventId) {
                http_response_code(400);
                echo json_encode(['error' => 'Event ID is required.']);
                break;
            }

            // Duplicate check
            $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrants WHERE email = ? AND event_id = ?");
            $dupStmt->execute([$email, $eventId]);
            if ($dupStmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode(['error' => 'You are already registered for this event with this email address.']);
                break;
            }

            // Insert as pending
            $stmt = $pdo->prepare("INSERT INTO event_registrants
                (event_id, event_title, event_date, full_name, email, phone, profession, institution,
                 amount, currency, payment_method, status, payment_status)
                VALUES (?,?,?,?,?,?,?,?,?,'UGX',?,'confirmed','pending')");
            $stmt->execute([
                $eventId, $eventTitle, $eventDate,
                $name, $email, $phone, $profession, $institution,
                $amount, $payMethod
            ]);
            $regId = (int)$pdo->lastInsertId();

            // Generate receipt token
            $receiptNum   = 'HOSU-EVT-' . date('Y') . '-' . str_pad($regId, 5, '0', STR_PAD_LEFT);
            $receiptToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE event_registrants SET receipt_number = ?, receipt_token = ? WHERE id = ?")
                ->execute([$receiptNum, $receiptToken, $regId]);

            echo json_encode([
                'success'        => true,
                'registrant_id'  => $regId,
                'receipt_token'  => $receiptToken,
                'receipt_number' => $receiptNum,
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Confirm payment (after gateway success) — marks verified + sends email
    // ──────────────────────────────────────────────────────────────────
    case 'confirm_event_payment':
        try {
            $regId        = (int)($_POST['registrant_id'] ?? 0);
            $receiptToken = trim($_POST['receipt_token'] ?? '');

            if (!$regId || strlen($receiptToken) !== 64) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment reference.']);
                break;
            }

            // Verify token matches
            $stmt = $pdo->prepare("SELECT id, payment_status FROM event_registrants WHERE id = ? AND receipt_token = ?");
            $stmt->execute([$regId, $receiptToken]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Registration not found.']);
                break;
            }

            if ($row['payment_status'] === 'verified') {
                echo json_encode(['success' => true, 'already_confirmed' => true]);
                break;
            }

            $pdo->prepare("UPDATE event_registrants SET payment_status = 'verified' WHERE id = ?")
                ->execute([$regId]);

            // Send receipt email
            sendEventReceiptEmail($pdo, $regId);

            echo json_encode(['success' => true, 'receipt_token' => $receiptToken]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Admin: re-query PesaPal for a single payment's current status
    // Usage: payment.php?action=sync_pesapal_payment&payment_id=X&source=payments
    //        or &registrant_id=X&source=event_registrants
    // ──────────────────────────────────────────────────────────────────
    case 'sync_pesapal_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $source  = $_GET['source'] ?? $_POST['source'] ?? 'payments';
            $payId   = (int)($_GET['payment_id']    ?? $_POST['payment_id']    ?? 0);
            $regId   = (int)($_GET['registrant_id'] ?? $_POST['registrant_id'] ?? 0);

            if (!in_array($source, ['payments', 'event_registrants'], true)) $source = 'payments';

            // Look up the transaction_id from DB
            $trackingId = '';
            if ($source === 'event_registrants' && $regId) {
                $row = $pdo->prepare("SELECT transaction_id, transaction_ref, payment_status FROM event_registrants WHERE id = ?");
                $row->execute([$regId]);
                $row = $row->fetch(PDO::FETCH_ASSOC);
            } else {
                $row = $pdo->prepare("SELECT transaction_id, transaction_ref, status AS payment_status FROM payments WHERE id = ?");
                $row->execute([$payId]);
                $row = $row->fetch(PDO::FETCH_ASSOC);
            }

            if (!$row) {
                http_response_code(404); echo json_encode(['error' => 'Payment record not found.']); break;
            }
            $trackingId = trim($row['transaction_id'] ?? '');
            if (!$trackingId) {
                echo json_encode(['success' => false, 'status' => $row['payment_status'], 'message' => 'No PesaPal tracking ID on record — cannot query PesaPal.']);
                break;
            }

            if (empty(PESAPAL_CONSUMER_KEY) || empty(PESAPAL_CONSUMER_SECRET)) {
                http_response_code(500); echo json_encode(['error' => 'PesaPal not configured.']); break;
            }

            $ppToken = pesapalGetToken();
            $status  = pesapalRequest('GET', '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($trackingId), null, $ppToken);
            $code    = (int)($status['status_code'] ?? -1);
            $txnRef  = $status['merchant_reference'] ?? ($row['transaction_ref'] ?? '');
            $desc    = $status['payment_status_description'] ?? ($status['message'] ?? '');

            if ($code === 1) {
                markPaymentVerified($pdo, $source === 'event_registrants' ? $regId : 0, $source !== 'event_registrants' ? $payId : 0, $txnRef, $trackingId);
                echo json_encode(['success' => true, 'status' => 'verified', 'message' => 'Payment confirmed and marked as verified.', 'pesapal_status' => $desc]);
            } elseif ($code === 2) {
                echo json_encode(['success' => true, 'status' => 'failed', 'message' => 'PesaPal reports payment was declined.', 'pesapal_status' => $desc]);
            } elseif ($code === 3) {
                echo json_encode(['success' => true, 'status' => 'reversed', 'message' => 'PesaPal reports payment was reversed.', 'pesapal_status' => $desc]);
            } else {
                echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'PesaPal reports payment is still pending.', 'pesapal_status' => $desc ?: 'Pending']);
            }
        } catch (\Throwable $e) {
            error_log('sync_pesapal_payment: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to query PesaPal: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')]);
        break;
}
