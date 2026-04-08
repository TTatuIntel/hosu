<?php
/**
 * Payment Flow Diagnostic — DELETE AFTER USE
 * Visit: https://hosu.or.ug/payment_test.php?key=HOSU_DIAG_2026
 * 
 * Tests: DB connection, table existence, pre_register INSERT, PesaPal auth
 */
if (($_GET['key'] ?? '') !== 'HOSU_DIAG_2026') {
    http_response_code(403);
    die('403 Forbidden');
}

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';

$results = [];

function test($label, $fn) {
    global $results;
    try {
        $val = $fn();
        $results[] = ['ok' => true, 'label' => $label, 'detail' => $val];
    } catch (Throwable $e) {
        $results[] = ['ok' => false, 'label' => $label, 'detail' => $e->getMessage()];
    }
}

// 1. DB connection
test('Database connection', function() use ($pdo) {
    $pdo->query('SELECT 1');
    return 'Connected to ' . (getenv('DB_NAME') ?: 'unknown');
});

// 2. Members table
test('Members table exists', function() use ($pdo) {
    $cols = $pdo->query("DESCRIBE members")->fetchAll(PDO::FETCH_COLUMN);
    return 'Columns: ' . implode(', ', $cols);
});

// 3. Payments table
test('Payments table exists', function() use ($pdo) {
    $cols = $pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_COLUMN);
    return 'Columns: ' . implode(', ', $cols);
});

// 4. Event registrants table
test('Event registrants table exists', function() use ($pdo) {
    $cols = $pdo->query("DESCRIBE event_registrants")->fetchAll(PDO::FETCH_COLUMN);
    return 'Columns: ' . implode(', ', $cols);
});

// 5. Test INSERT + rollback (non-destructive)
test('Test INSERT into members (rolled back)', function() use ($pdo) {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO members (full_name, email, phone, profession, institution, membership_type, status) VALUES (?,?,?,?,?,?,'pending')");
    $stmt->execute(['Test User', 'test@test.com', '256700000000', 'Test', 'Test', '1_year']);
    $id = $pdo->lastInsertId();
    $pdo->rollBack();
    return "INSERT succeeded (id=$id, rolled back)";
});

// 6. Test INSERT into payments (rolled back)
test('Test INSERT into payments (rolled back)', function() use ($pdo) {
    // Need a real member_id for FK — use the first one
    $memberId = $pdo->query("SELECT id FROM members LIMIT 1")->fetchColumn();
    if (!$memberId) return 'SKIPPED — no members in table. The first real payment will create one.';
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO payments (member_id, amount, currency, payment_method, status, payment_type, membership_period) VALUES (?,?,'UGX',?,'pending',?,?)");
    $stmt->execute([$memberId, 100000, 'test', 'membership', '1_year']);
    $id = $pdo->lastInsertId();
    $pdo->rollBack();
    return "INSERT succeeded (id=$id, rolled back)";
});

// 7. PesaPal auth
test('PesaPal API authentication', function() {
    $env = getenv('PESAPAL_ENV') ?: 'production';
    $base = $env === 'sandbox' ? 'https://cybqa.pesapal.com/pesapalv3' : 'https://pay.pesapal.com/v3';
    $key = getenv('PESAPAL_CONSUMER_KEY') ?: '';
    $secret = getenv('PESAPAL_CONSUMER_SECRET') ?: '';
    if (!$key || !$secret) return 'MISSING CREDENTIALS — check .env file';

    $ch = curl_init($base . '/api/Auth/RequestToken');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['consumer_key' => $key, 'consumer_secret' => $secret]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return "CURL ERROR: $err";
    $data = json_decode($resp, true);
    if (($data['status'] ?? '') === '200' && !empty($data['token'])) {
        return "Auth OK (token: " . substr($data['token'], 0, 20) . "... env=$env)";
    }
    return "FAILED (HTTP $code): " . substr($resp, 0, 300);
});

// 8. PHP version
test('PHP version', function() {
    $v = phpversion();
    return $v . (version_compare($v, '8.0', '>=') ? ' (OK)' : ' — WARNING: PHP 8.0+ recommended');
});

// 9. .env loaded
test('.env variables loaded', function() {
    $vars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'PESAPAL_ENV', 'PESAPAL_CONSUMER_KEY'];
    $loaded = [];
    foreach ($vars as $v) {
        $val = getenv($v);
        $loaded[] = $v . '=' . ($val ? substr($val, 0, 10) . '...' : 'EMPTY');
    }
    return implode(', ', $loaded);
});

?><!DOCTYPE html>
<html><head><title>HOSU Payment Diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;background:#f8fafc;padding:2rem;color:#1e293b;}
h1{color:#0d4593;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:1.5rem;margin-bottom:1rem;max-width:800px;}
.ok{color:#15803d;} .fail{color:#dc2626;}
table{width:100%;border-collapse:collapse;} tr{border-bottom:1px solid #f1f5f9;}
td{padding:10px 12px;} td:first-child{font-weight:600;width:280px;}
.detail{font-family:monospace;font-size:.85rem;word-break:break-all;}
.warn{background:#fef3c7;border-left:4px solid #d97706;padding:12px;border-radius:0 8px 8px 0;margin:1rem 0;}
</style></head><body>
<h1>HOSU Payment Flow Diagnostic</h1>
<p style="color:#64748b;">Tests database, tables, inserts, and PesaPal connectivity. <strong>Delete this file after use.</strong></p>
<div class="card"><table>
<?php foreach ($results as $r): ?>
<tr>
    <td class="<?= $r['ok'] ? 'ok' : 'fail' ?>"><?= $r['ok'] ? '✅' : '❌' ?> <?= htmlspecialchars($r['label']) ?></td>
    <td class="detail <?= $r['ok'] ? 'ok' : 'fail' ?>"><?= htmlspecialchars($r['detail']) ?></td>
</tr>
<?php endforeach; ?>
</table></div>
<div class="warn">⚠️ This file exposes diagnostic information. <strong>Delete it from the server after you're done.</strong></div>
</body></html>
