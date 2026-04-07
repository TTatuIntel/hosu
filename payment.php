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

// ── Gateway config (from environment) ─────────────────────────────────
// Flutterwave API (supports MTN MoMo, Airtel Money, Visa for Uganda)
// Sign up: https://dashboard.flutterwave.com — get keys from Settings > API
define('FLW_BASE', 'https://api.flutterwave.com/v3');
define('FLW_SECRET_KEY', getenv('FLW_SECRET_KEY') ?: '');
define('FLW_PUBLIC_KEY', getenv('FLW_PUBLIC_KEY') ?: '');

// Live payment mode — keep `PAYMENT_TEST_MODE=false` in .env for real Flutterwave processing only
define('PAYMENT_TEST_MODE', filter_var(getenv('PAYMENT_TEST_MODE') ?: 'false', FILTER_VALIDATE_BOOLEAN));

/**
 * Make an authenticated request to Flutterwave API.
 */
function flwRequest(string $method, string $endpoint, array $body = null): array
{
    $url = FLW_BASE . $endpoint;

    $headers = [
        'Authorization: Bearer ' . FLW_SECRET_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => $headers,
    ]);

    if ($method === 'POST' && $body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new RuntimeException('Gateway connection error: ' . $err);
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        throw new RuntimeException("Gateway returned invalid response (HTTP $httpCode)");
    }

    return $data;
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
    Contact: <a href="mailto:infor@hosu.or.ug" style="color:#0d4593;text-decoration:none;">infor@hosu.or.ug</a> &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a><br>
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
    Contact: <a href="mailto:infor@hosu.or.ug" style="color:#0d4593;text-decoration:none;">infor@hosu.or.ug</a> &middot; <a href="https://hosu.or.ug" style="color:#0d4593;text-decoration:none;">www.hosu.or.ug</a><br>
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
    } catch (Exception $e) {
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
    // Initiate MTN Mobile Money payment (via Flutterwave)
    // ──────────────────────────────────────────────────────────────────
    case 'pay_mtn':
        try {
            $phone  = sanitizePhone(trim($_POST['phone'] ?? ''));
            $amount = (int)($_POST['amount'] ?? 0);
            $regId  = (int)($_POST['registrant_id'] ?? 0);
            $payId  = (int)($_POST['payment_id'] ?? 0);
            $email  = trim($_POST['email'] ?? 'infor@hosu.or.ug');

            if (!$phone || strlen($phone) < 7) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid phone number. Please enter a valid number with country code.']);
                break;
            }
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment amount.']);
                break;
            }
            if (empty(FLW_SECRET_KEY)) {
                http_response_code(500);
                echo json_encode(['error' => 'Payment gateway not configured. Please set your live Flutterwave key in .env to enable real-time payments.']);
                break;
            }

            $txnRef = generateTxnRef('MTN');
            saveTxnRef($pdo, $regId, $payId, $txnRef, 'MTN Mobile Money');

            // Lookup email from DB if not passed
            if ($email === 'infor@hosu.or.ug') {
                if ($payId) {
                    $r = $pdo->prepare("SELECT m.email FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=?");
                    $r->execute([$payId]); $row = $r->fetch(); if ($row) $email = $row['email'];
                } elseif ($regId) {
                    $r = $pdo->prepare("SELECT email FROM event_registrants WHERE id=?");
                    $r->execute([$regId]); $row = $r->fetch(); if ($row) $email = $row['email'];
                }
            }


            $result = flwRequest('POST', '/charges?type=mobile_money_uganda', [
                'tx_ref'       => $txnRef,
                'amount'       => $amount,
                'currency'     => 'UGX',
                'email'        => $email,
                'phone_number' => $phone,
                'network'      => 'MTN',
                'order_id'     => $txnRef,
            ]);

            $status = $result['status'] ?? '';
            $flwId  = $result['data']['id'] ?? null;

            // Save Flutterwave transaction ID
            if ($flwId) {
                if ($regId) $pdo->prepare("UPDATE event_registrants SET transaction_id = ? WHERE id = ?")->execute([(string)$flwId, $regId]);
                if ($payId) $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")->execute([(string)$flwId, $payId]);
            }

            if ($status === 'success' && ($result['data']['status'] ?? '') === 'successful') {
                markPaymentVerified($pdo, $regId, $payId, $txnRef, (string)$flwId);
                echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment successful!', 'txn_ref' => $txnRef]);
            } elseif ($status === 'success') {
                echo json_encode([
                    'success' => true,
                    'status'  => 'pending',
                    'message' => 'Payment prompt sent to your phone. Please approve on your MTN phone.',
                    'txn_ref' => $txnRef,
                    'txn_id'  => (string)$flwId,
                ]);
            } else {
                $msg = $result['message'] ?? 'Payment request failed. Please try again.';
                echo json_encode(['error' => $msg]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Payment service error: ' . $e->getMessage()]);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Initiate Airtel Money payment (via Flutterwave)
    // ──────────────────────────────────────────────────────────────────
    case 'pay_airtel':
        try {
            $phone  = sanitizePhone(trim($_POST['phone'] ?? ''));
            $amount = (int)($_POST['amount'] ?? 0);
            $regId  = (int)($_POST['registrant_id'] ?? 0);
            $payId  = (int)($_POST['payment_id'] ?? 0);
            $email  = trim($_POST['email'] ?? 'infor@hosu.or.ug');

            if (!$phone || strlen($phone) < 7) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid phone number. Please enter a valid number with country code.']);
                break;
            }
            if ($amount <= 0) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment amount.']);
                break;
            }
            if (empty(FLW_SECRET_KEY)) {
                http_response_code(500);
                echo json_encode(['error' => 'Payment gateway not configured. Please set your live Flutterwave key in .env to enable real-time payments.']);
                break;
            }

            $txnRef = generateTxnRef('AIR');
            saveTxnRef($pdo, $regId, $payId, $txnRef, 'Airtel Money');

            // Lookup email from DB if not passed
            if ($email === 'infor@hosu.or.ug') {
                if ($payId) {
                    $r = $pdo->prepare("SELECT m.email FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=?");
                    $r->execute([$payId]); $row = $r->fetch(); if ($row) $email = $row['email'];
                } elseif ($regId) {
                    $r = $pdo->prepare("SELECT email FROM event_registrants WHERE id=?");
                    $r->execute([$regId]); $row = $r->fetch(); if ($row) $email = $row['email'];
                }
            }


            $result = flwRequest('POST', '/charges?type=mobile_money_uganda', [
                'tx_ref'       => $txnRef,
                'amount'       => $amount,
                'currency'     => 'UGX',
                'email'        => $email,
                'phone_number' => $phone,
                'network'      => 'AIRTEL',
                'order_id'     => $txnRef,
            ]);

            $status = $result['status'] ?? '';
            $flwId  = $result['data']['id'] ?? null;

            // Save transaction ID
            if ($flwId) {
                if ($regId) $pdo->prepare("UPDATE event_registrants SET transaction_id = ? WHERE id = ?")->execute([(string)$flwId, $regId]);
                if ($payId) $pdo->prepare("UPDATE payments SET transaction_id = ? WHERE id = ?")->execute([(string)$flwId, $payId]);
            }

            if ($status === 'success' && ($result['data']['status'] ?? '') === 'successful') {
                markPaymentVerified($pdo, $regId, $payId, $txnRef, (string)$flwId);
                echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment successful!', 'txn_ref' => $txnRef, 'txn_id' => (string)$flwId]);
            } elseif ($status === 'success') {
                echo json_encode([
                    'success' => true,
                    'status'  => 'pending',
                    'message' => 'Payment prompt sent to your phone. Please enter your Airtel Money PIN to approve.',
                    'txn_ref' => $txnRef,
                    'txn_id'  => (string)$flwId,
                ]);
            } else {
                $msg = $result['message'] ?? 'Airtel payment request failed. Please try again.';
                echo json_encode(['error' => $msg]);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Payment service error: ' . $e->getMessage()]);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Poll Airtel payment status (via Flutterwave verify)
    // ──────────────────────────────────────────────────────────────────
    case 'check_airtel':
        try {
            $txnId = trim($_GET['txn_id'] ?? $_POST['txn_id'] ?? '');
            $regId = (int)($_GET['registrant_id'] ?? $_POST['registrant_id'] ?? 0);
            $payId = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);

            if (!$txnId) {
                http_response_code(400);
                echo json_encode(['error' => 'Transaction ID required.']);
                break;
            }


            $result = flwRequest('GET', '/transactions/' . urlencode($txnId) . '/verify');

            $txStatus = $result['data']['status'] ?? '';

            if ($txStatus === 'successful') {
                markPaymentVerified($pdo, $regId, $payId, '', $txnId);
                echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment confirmed!']);
            } elseif ($txStatus === 'failed') {
                echo json_encode(['success' => false, 'status' => 'failed', 'message' => 'Payment was declined. Please try again.']);
            } else {
                echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'Waiting for payment approval...']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Status check failed: ' . $e->getMessage()]);
        }
        break;

    // ──────────────────────────────────────────────────────────────────
    // Poll MTN payment status (via Flutterwave verify)
    // ──────────────────────────────────────────────────────────────────
    case 'check_mtn':
        try {
            $txnRef = trim($_GET['txn_ref'] ?? $_POST['txn_ref'] ?? '');
            $txnId  = trim($_GET['txn_id'] ?? $_POST['txn_id'] ?? '');
            $regId  = (int)($_GET['registrant_id'] ?? $_POST['registrant_id'] ?? 0);
            $payId  = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);


            // Try by Flutterwave ID first, then by tx_ref
            if ($txnId) {
                $result = flwRequest('GET', '/transactions/' . urlencode($txnId) . '/verify');
            } elseif ($txnRef) {
                $result = flwRequest('GET', '/transactions/verify_by_reference?tx_ref=' . urlencode($txnRef));
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Transaction reference required.']);
                break;
            }

            $txStatus = $result['data']['status'] ?? '';

            if ($txStatus === 'successful') {
                markPaymentVerified($pdo, $regId, $payId, $txnRef);
                echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment confirmed!']);
            } elseif ($txStatus === 'failed') {
                $msg = $result['data']['processor_response'] ?? 'Payment failed.';
                echo json_encode(['success' => false, 'status' => 'failed', 'message' => $msg]);
            } else {
                echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'Waiting for payment approval...']);
            }
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Status check failed: ' . $e->getMessage()]);
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
            $payMethod   = trim($_POST['paymentMethod'] ?? '');

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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8')]);
        break;
}
