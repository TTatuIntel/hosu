<?php
/**
 * pesapal_ipn.php — PesaPal Instant Payment Notification handler
 *
 * PesaPal calls this URL server-to-server when a payment status changes.
 * Must return: {"orderNotificationType":"IPNCHANGE","orderTrackingId":"...","orderMerchantReference":"...","status":200}
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json');

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
    if ($err) throw new RuntimeException('PesaPal IPN connection error: ' . $err);
    $data = json_decode($resp, true);
    if (!is_array($data)) throw new RuntimeException("PesaPal IPN invalid response (HTTP $httpCode)");
    return $data;
}

function pesapalGetToken(): string
{
    $cache = sys_get_temp_dir() . '/hosu_pp_token.json';
    if (is_file($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if ($c && !empty($c['token']) && !empty($c['exp']) && time() < $c['exp']) return $c['token'];
        @unlink($cache);
    }
    $res = pesapalRequest('POST', '/api/Auth/RequestToken', [
        'consumer_key'    => PESAPAL_CONSUMER_KEY,
        'consumer_secret' => PESAPAL_CONSUMER_SECRET,
    ], '');
    if (($res['status'] ?? '') !== '200' || empty($res['token'])) {
        @unlink($cache);
        error_log('PesaPal auth failed (IPN): ' . json_encode($res));
        throw new RuntimeException('PesaPal auth failed: ' . ($res['message'] ?? 'Invalid credentials or service unavailable'));
    }
    file_put_contents($cache, json_encode(['token' => $res['token'], 'exp' => time() + 240]));
    return $res['token'];
}

$orderTrackingId  = trim($_GET['OrderTrackingId']        ?? '');
$merchantRef      = trim($_GET['OrderMerchantReference'] ?? '');
$notificationType = trim($_GET['OrderNotificationType']  ?? '');

// PesaPal requires this exact response format
$response = [
    'orderNotificationType'   => $notificationType ?: 'IPNCHANGE',
    'orderTrackingId'         => $orderTrackingId,
    'orderMerchantReference'  => $merchantRef,
    'status'                  => 200,
];

if (!$orderTrackingId) {
    echo json_encode($response);
    exit;
}

try {
    $ppToken = pesapalGetToken();
    $status  = pesapalRequest('GET',
        '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($orderTrackingId),
        null, $ppToken);

    $statusCode = (int)($status['status_code'] ?? -1);

    if ($statusCode === 1) {
        // Parse merchant reference: HOSU-{M|E|D}-{id}-{timestamp}
        $parts = explode('-', $merchantRef);
        $payId = 0;
        $regId = 0;
        if (count($parts) >= 3) {
            $prefix = strtoupper($parts[1] ?? '');
            $id     = (int)($parts[2] ?? 0);
            if ($prefix === 'M' || $prefix === 'D') $payId = $id;
            if ($prefix === 'E')                    $regId = $id;
        }

        if ($regId) {
            $r = $pdo->prepare("SELECT payment_status FROM event_registrants WHERE id = ?");
            $r->execute([$regId]);
            $row = $r->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['payment_status'] !== 'verified') {
                $pdo->prepare("UPDATE event_registrants SET payment_status='verified', transaction_id=? WHERE id=?")
                    ->execute([$orderTrackingId, $regId]);
                // Send receipt email
                try {
                    $er = $pdo->prepare("SELECT * FROM event_registrants WHERE id=?");
                    $er->execute([$regId]);
                    $erow = $er->fetch(PDO::FETCH_ASSOC);
                    if ($erow && !empty($erow['email'])) {
                        $nm  = htmlspecialchars($erow['full_name']     ?? '', ENT_QUOTES, 'UTF-8');
                        $rcn = htmlspecialchars($erow['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
                        $amt = number_format((float)$erow['amount'], 0, '.', ',');
                        $evt = htmlspecialchars($erow['event_title']   ?? '', ENT_QUOTES, 'UTF-8');
                        $tok = $erow['receipt_token'] ?? '';
                        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'hosu.or.ug');
                        $recUrl = 'https://' . $host . '/receipt.php?token=' . urlencode($tok);
                        hosuMail($erow['email'], "HOSU Event Receipt — $rcn",
                            "<p>Dear $nm,</p><p>Your payment of UGX $amt for <strong>$evt</strong> is confirmed (Receipt: $rcn).</p>"
                            . "<p><a href='$recUrl'>View your receipt</a></p>");
                    }
                } catch (\Throwable $mailEx) { error_log('IPN event email: ' . $mailEx->getMessage()); }
            }
        }

        if ($payId) {
            $pdo->beginTransaction();
            $r   = $pdo->prepare("SELECT id, member_id, status, receipt_token FROM payments WHERE id = ? FOR UPDATE");
            $r->execute([$payId]);
            $pay = $r->fetch(PDO::FETCH_ASSOC);
            if ($pay && $pay['status'] !== 'verified') {
                $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW(), transaction_id=? WHERE id=?")
                    ->execute([$orderTrackingId, $payId]);
                if ($pay['member_id']) {
                    $pdo->prepare("UPDATE members SET status='active' WHERE id=?")->execute([$pay['member_id']]);
                }
                $pdo->commit();
                // Send receipt email
                try {
                    $pr = $pdo->prepare("SELECT p.*, m.full_name, m.email FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=?");
                    $pr->execute([$payId]);
                    $prow = $pr->fetch(PDO::FETCH_ASSOC);
                    if ($prow && !empty($prow['email'])) {
                        $nm  = htmlspecialchars($prow['full_name']      ?? '', ENT_QUOTES, 'UTF-8');
                        $rcn = htmlspecialchars($prow['receipt_number']  ?? '', ENT_QUOTES, 'UTF-8');
                        $amt = number_format((float)$prow['amount'], 0, '.', ',');
                        $tok = $prow['receipt_token'] ?? '';
                        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'hosu.or.ug');
                        $recUrl = 'https://' . $host . '/receipt.php?token=' . urlencode($tok);
                        $ptype  = $prow['payment_type'] ?? 'payment';
                        $labels = ['membership'=>'Membership','donation'=>'Donation','event_registration'=>'Event'];
                        $lbl    = $labels[$ptype] ?? 'Payment';
                        hosuMail($prow['email'], "HOSU Receipt — $rcn",
                            "<p>Dear $nm,</p><p>Your $lbl payment of UGX $amt is confirmed (Receipt: $rcn).</p>"
                            . "<p><a href='$recUrl'>View your receipt</a></p>");
                        $pdo->prepare("UPDATE payments SET invoice_sent=1 WHERE id=?")->execute([$payId]);
                    }
                } catch (\Throwable $mailEx) { error_log('IPN payment email: ' . $mailEx->getMessage()); }
            } else {
                $pdo->rollBack();
            }
        }
    }

    echo json_encode($response);

} catch (\Throwable $e) {
    error_log('PesaPal IPN error: ' . $e->getMessage());
    // Always respond 200 to stop PesaPal retrying
    echo json_encode($response);
}
