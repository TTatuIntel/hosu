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

    // Map status to SVG icon
    $iconSvg = '';
    if ($pmStatus === 'success') {
        $iconSvg = '<div class="status-icon success"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>';
    } elseif ($pmStatus === 'error') {
        $iconSvg = '<div class="status-icon error"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>';
    } elseif (strpos($icon, '⏳') !== false) {
        $iconSvg = '<div class="status-icon pending"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>';
    } else {
        $iconSvg = '<div class="status-icon warning"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title} — HOSU</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:Inter,'Segoe UI',system-ui,-apple-system,sans-serif;
    background:linear-gradient(135deg,#f0f4ff 0%,#f8fafc 50%,#f0fdf4 100%);
    display:flex;align-items:center;justify-content:center;min-height:100vh;padding:16px}
  .card{background:#fff;border-radius:16px;
    box-shadow:0 20px 60px rgba(0,0,0,.08),0 1px 3px rgba(0,0,0,.04);
    padding:28px 24px;max-width:380px;width:100%;text-align:center;position:relative;overflow:hidden}
  .card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,#0d4593 0%,#2563eb 35%,#16a34a 65%,#22c55e 100%)}
  .brand{display:flex;align-items:center;justify-content:center;gap:6px;margin-bottom:14px}
  .brand svg{width:14px;height:14px;flex-shrink:0}
  .brand span{font-size:.58rem;font-weight:700;letter-spacing:.1em;color:#94a3b8;text-transform:uppercase}
  .status-icon{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    margin:0 auto 14px;animation:iconPop .4s cubic-bezier(.22,1,.36,1)}
  .status-icon svg{width:28px;height:28px}
  .status-icon.success{background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 4px 14px rgba(22,163,106,.25)}
  .status-icon.error{background:linear-gradient(135deg,#e63946,#dc2626);box-shadow:0 4px 14px rgba(230,57,70,.25)}
  .status-icon.pending{background:linear-gradient(135deg,#f59e0b,#eab308);box-shadow:0 4px 14px rgba(245,158,11,.25)}
  .status-icon.warning{background:linear-gradient(135deg,#e63946,#dc2626);box-shadow:0 4px 14px rgba(230,57,70,.25)}
  @keyframes iconPop{from{transform:scale(.5);opacity:0}to{transform:scale(1);opacity:1}}
  h1{margin:0 0 6px;font-size:1.1rem;font-weight:800;color:#0f172a;letter-spacing:-.01em}
  p{color:#64748b;line-height:1.6;margin:0 0 18px;font-size:.82rem}
  a.btn{display:inline-block;padding:10px 28px;background:linear-gradient(135deg,{$color},color-mix(in srgb,{$color} 80%,#000));
    color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:.82rem;
    box-shadow:0 2px 10px rgba(0,0,0,.12);transition:all .2s}
  a.btn:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.18)}
  a.contact-link{color:#0d4593;font-weight:700;text-decoration:none;white-space:nowrap}
  a.contact-link:hover{text-decoration:underline}
  .secure-badge{display:flex;align-items:center;justify-content:center;gap:4px;margin-top:18px;
    font-size:.58rem;color:#94a3b8;font-weight:600;letter-spacing:.04em}
  .secure-badge svg{width:10px;height:10px}
</style>
</head>
<body>
<div class="card">
  <div class="brand">
    <svg viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4" stroke="#16a34a"/></svg>
    <span>HOSU &mdash; Secure Payment</span>
  </div>
  {$iconSvg}
  <h1>{$heading}</h1>
  <p>{$body}</p>
  <a href="{$btnHref}" class="btn">{$btnLabel}</a>
  <div class="secure-badge">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Secured by PesaPal
  </div>
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
