<?php
/**
 * pesapal_callback.php — PesaPal payment redirect handler
 *
 * PesaPal redirects the user here after they complete (or cancel) payment.
 * URL params from PesaPal: OrderTrackingId, OrderMerchantReference
 * Our params (passed in the callback_url we gave PesaPal): payment_id,
 * registrant_id, receipt_token, type
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

// ── Re-use PesaPal helpers from payment.php ───────────────────────────
define('PESAPAL_ENV',             getenv('PESAPAL_ENV') ?: 'production');
define('PESAPAL_BASE',            PESAPAL_ENV === 'sandbox'
    ? 'https://cybqa.pesapal.com/pesapalv3'
    : 'https://pay.pesapal.com/v3');
define('PESAPAL_CONSUMER_KEY',    getenv('PESAPAL_CONSUMER_KEY') ?: '');
define('PESAPAL_CONSUMER_SECRET', getenv('PESAPAL_CONSUMER_SECRET') ?: '');

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
    if (!is_array($data)) throw new RuntimeException("PesaPal invalid response (HTTP $httpCode)");
    return $data;
}

function pesapalGetToken(): string
{
    $cache = sys_get_temp_dir() . '/hosu_pp_token.json';
    if (is_file($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if ($c && !empty($c['token']) && !empty($c['exp']) && time() < $c['exp']) return $c['token'];
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

// ── Output helper ─────────────────────────────────────────────────────
function renderPage(string $title, string $icon, string $heading, string $body, string $btnLabel, string $btnHref, string $color = '#0d4593', string $pmStatus = '', string $pmReceipt = ''): void
{
    $pmScript = '';
    if ($pmStatus) {
        $safeStatus  = htmlspecialchars($pmStatus,  ENT_QUOTES, 'UTF-8');
        $safeReceipt = htmlspecialchars($pmReceipt, ENT_QUOTES, 'UTF-8');
        $pmScript = "<script>(function(){try{if(window.parent&&window.parent!==window){window.parent.postMessage({type:'hosu_payment',status:'{$safeStatus}',receiptToken:'{$safeReceipt}'},'*');}}catch(e){}})();</script>";
    }
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — HOSU</title>
<style>
  body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:#f4f6f9;display:flex;align-items:center;justify-content:center;min-height:100vh;}
  .card{background:#fff;border-radius:12px;box-shadow:0 3px 16px rgba(0,0,0,.10);padding:28px 24px;max-width:360px;width:90%;text-align:center;}
  .icon{font-size:2rem;margin-bottom:8px;}
  h1{margin:0 0 8px;font-size:1.15rem;color:{$color};}
  p{color:#555;line-height:1.55;margin:0 0 18px;font-size:0.88rem;}
  a.btn{display:inline-block;padding:9px 26px;background:{$color};color:#fff;border-radius:7px;text-decoration:none;font-weight:700;font-size:0.88rem;}
  a.btn:hover{opacity:.9;}
  .logo{margin-bottom:14px;}
  .logo img{height:34px;}
  a.contact-link{color:{$color};font-weight:600;text-decoration:none;white-space:nowrap;}
  a.contact-link:hover{text-decoration:underline;}
</style>
</head>
<body>
<div class="card">
  <div class="logo"><img src="img/hosu-logo.png" alt="HOSU" onerror="this.style.display='none'"></div>
  <div class="icon">{$icon}</div>
  <h1>{$heading}</h1>
  <p>{$body}</p>
  <a href="{$btnHref}" class="btn">{$btnLabel}</a>
</div>
{$pmScript}
</body>
</html>
HTML;
    exit;
}

// ── Read parameters ───────────────────────────────────────────────────
$orderTrackingId = trim($_GET['OrderTrackingId'] ?? '');
$payId           = (int)($_GET['payment_id']    ?? 0);
$regId           = (int)($_GET['registrant_id'] ?? 0);
$receiptToken    = trim($_GET['receipt_token']  ?? '');
$type            = trim($_GET['type']           ?? 'membership');

// Guard: no tracking ID means PesaPal never processed
if (!$orderTrackingId) {
    renderPage('Payment Error', '⚠️', 'Missing Payment Reference',
        'We could not find a valid payment reference. If you completed a payment, contact us at <a class="contact-link" href="mailto:info@hosu.or.ug">info@hosu.or.ug</a> or <a class="contact-link" href="https://wa.me/256709752107">WhatsApp +256 709 752107</a>.',
        'Return to Home', 'index.html', '#e63946');
}

// ── Verify with PesaPal API ───────────────────────────────────────────
try {
    $ppToken = pesapalGetToken();
    $status  = pesapalRequest('GET',
        '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($orderTrackingId),
        null, $ppToken);

    $statusCode  = (int)($status['status_code'] ?? -1);
    $paymentDesc = htmlspecialchars($status['payment_status_description'] ?? '', ENT_QUOTES, 'UTF-8');
    $confCode    = htmlspecialchars($status['confirmation_code']          ?? '', ENT_QUOTES, 'UTF-8');
    $merchantRef = $status['merchant_reference'] ?? '';

    if ($statusCode === 1) {
        // ── COMPLETED ────────────────────────────────────────────────
        // Mark verified in DB
        if ($regId) {
            $sets   = ["payment_status = 'verified'", "transaction_id = ?"];
            $params = [$orderTrackingId, $regId];
            $pdo->prepare("UPDATE event_registrants SET " . implode(', ', $sets) . " WHERE id = ?")
                ->execute($params);
        }
        if ($payId) {
            $pdo->beginTransaction();
            $row = $pdo->prepare("SELECT id, member_id, status FROM payments WHERE id = ? FOR UPDATE");
            $row->execute([$payId]);
            $pay = $row->fetch(PDO::FETCH_ASSOC);
            if ($pay && $pay['status'] !== 'verified') {
                $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW(), transaction_id=? WHERE id=?")
                    ->execute([$orderTrackingId, $payId]);
                if ($pay['member_id']) {
                    $pdo->prepare("UPDATE members SET status='active' WHERE id=?")->execute([$pay['member_id']]);
                }
            }
            $pdo->commit();
        }

        // Send receipt email
        if ($regId) {
            try {
                // Inline minimal receipt email for events
                $r = $pdo->prepare("SELECT * FROM event_registrants WHERE id = ?");
                $r->execute([$regId]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['email'])) {
                    $name    = htmlspecialchars($row['full_name'],    ENT_QUOTES, 'UTF-8');
                    $receipt = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
                    $evTitle = htmlspecialchars($row['event_title']   ?? '', ENT_QUOTES, 'UTF-8');
                    $amount  = number_format((float)$row['amount'], 0, '.', ',');
                    $recUrl  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                             . '://' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost')
                             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
                             . '/receipt.php?token=' . urlencode($receiptToken);
                    hosuMail($row['email'], "HOSU Event Receipt — $receipt",
                        "<p>Dear $name,</p><p>Your payment of UGX $amount for <strong>$evTitle</strong> has been confirmed (Receipt: $receipt).</p>"
                        . "<p><a href='$recUrl'>View your receipt</a></p>");
                }
            } catch (\Throwable $e) { error_log('Callback event email: ' . $e->getMessage()); }
        }
        if ($payId) {
            try {
                $r = $pdo->prepare("SELECT p.*, m.full_name, m.email FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=?");
                $r->execute([$payId]);
                $row = $r->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['email'])) {
                    $name    = htmlspecialchars($row['full_name']     ?? '', ENT_QUOTES, 'UTF-8');
                    $receipt = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
                    $amount  = number_format((float)$row['amount'], 0, '.', ',');
                    $ptype   = $row['payment_type'] ?? 'payment';
                    $labels  = ['membership'=>'Membership','donation'=>'Donation','event_registration'=>'Event'];
                    $label   = $labels[$ptype] ?? 'Payment';
                    $recUrl  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                             . '://' . preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost')
                             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/')
                             . '/receipt.php?token=' . urlencode($receiptToken);
                    hosuMail($row['email'], "HOSU Receipt — $receipt",
                        "<p>Dear $name,</p><p>Your $label payment of UGX $amount has been confirmed (Receipt: $receipt).</p>"
                        . "<p><a href='$recUrl'>View your receipt</a></p>");
                    $pdo->prepare("UPDATE payments SET invoice_sent=1 WHERE id=?")->execute([$payId]);
                }
            } catch (\Throwable $e) { error_log('Callback pay email: ' . $e->getMessage()); }
        }

        // Redirect to receipt (or postMessage if in iframe)
        if ($receiptToken) {
            renderPage('Payment Confirmed', '✅', 'Payment Confirmed!',
                "Your payment has been received and confirmed. Redirecting to receipt…",
                'View Receipt', 'receipt.php?token=' . urlencode($receiptToken), '#27ae60', 'success', $receiptToken);
        }
        renderPage('Payment Confirmed', '✅', 'Payment Confirmed!',
            "Your payment has been received and confirmed. Confirmation code: <strong>$confCode</strong>.",
            'Return to Home', 'index.html', '#27ae60', 'success', '');

    } elseif ($statusCode === 2) {
        // FAILED
        renderPage('Payment Failed', '❌', 'Payment Failed',
            'Your payment was not completed. Please try again or contact us at <a class="contact-link" href="mailto:info@hosu.or.ug">info@hosu.or.ug</a> or <a class="contact-link" href="https://wa.me/256709752107">WhatsApp +256 709 752107</a>.',
            'Try Again', 'membership.html', '#e63946', 'error');

    } elseif ($statusCode === 3) {
        // REVERSED
        renderPage('Payment Reversed', '↩️', 'Payment Reversed',
            'Your payment was reversed. Please contact us at <a class="contact-link" href="mailto:info@hosu.or.ug">info@hosu.or.ug</a> or <a class="contact-link" href="https://wa.me/256709752107">WhatsApp +256 709 752107</a>.',
            'Contact Us', 'contact.html', '#e63946', 'error');

    } else {
        // PENDING — user may have cancelled or payment is still processing
        renderPage('Payment Pending', '⏳', 'Payment Pending',
            "Your payment is still being processed (status: $paymentDesc). If you completed payment, your receipt will be sent to your email. Contact us at <a class='contact-link' href='mailto:info@hosu.or.ug'>info@hosu.or.ug</a> or <a class='contact-link' href='https://wa.me/256709752107'>WhatsApp +256 709 752107</a> if you need help.",
            'Return to Home', 'index.html', '#f39c12');
    }

} catch (\Throwable $e) {
    error_log('PesaPal callback error: ' . $e->getMessage());
    renderPage('Payment Error', '⚠️', 'Server Error',
        'We could not verify your payment at this time. If money was deducted, provide proof of payment to <a class="contact-link" href="mailto:info@hosu.or.ug">info@hosu.or.ug</a> or <a class="contact-link" href="https://wa.me/256709752107">WhatsApp +256 709 752107</a>.',
        'Return to Home', 'index.html', '#e63946');
}
