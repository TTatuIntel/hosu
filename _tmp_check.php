<?php
// Temporary diagnostic file — safe to delete
echo "OK\n";

// Step 1: Test pre_register via api.php
echo "1) Testing pre_register (api.php)...\n";
$ch = curl_init('http://localhost/hosu/api.php?action=pre_register');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'fullName' => 'Test Donor',
        'email' => 'test@example.com',
        'phone' => '256770495837',
        'profession' => 'Donor',
        'institution' => '',
        'paymentMethod' => 'MTN Mobile Money',
        'paymentType' => 'donation',
        'membershipPeriod' => '',
        'amount' => 25000,
        'transactionId' => '',
        'transactionRef' => '',
    ]),
    CURLOPT_COOKIEJAR => __DIR__ . '/_test_cookies.txt',
    CURLOPT_COOKIEFILE => __DIR__ . '/_test_cookies.txt',
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP $httpCode: $resp\n";
$preData = json_decode($resp, true);
$paymentId = $preData['payment_id'] ?? 0;
$receiptToken = $preData['receipt_token'] ?? '';
echo "   payment_id=$paymentId, receipt_token=" . substr($receiptToken, 0, 16) . "...\n\n";

if (!$paymentId) { echo "FAILED: No payment_id returned.\n"; exit(1); }

// Step 2: Test pay_mtn (payment.php) in TEST MODE
echo "2) Testing pay_mtn (payment.php) in TEST MODE...\n";
$ch = curl_init('http://localhost/hosu/payment.php?action=pay_mtn');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'phone' => '256770495837',
        'amount' => 25000,
        'payment_id' => $paymentId,
    ]),
    CURLOPT_COOKIEJAR => __DIR__ . '/_test_cookies.txt',
    CURLOPT_COOKIEFILE => __DIR__ . '/_test_cookies.txt',
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP $httpCode: $resp\n";
$payData = json_decode($resp, true);
$txnRef = $payData['txn_ref'] ?? '';
$txnId = $payData['txn_id'] ?? '';
echo "   status={$payData['status']}, txn_ref=$txnRef, txn_id=$txnId\n\n";

// Step 3: Poll check_mtn — 1st poll should be pending
echo "3) Testing check_mtn poll #1 (should be pending)...\n";
$url = 'http://localhost/hosu/payment.php?action=check_mtn&txn_ref=' . urlencode($txnRef) . '&txn_id=' . urlencode($txnId) . '&payment_id=' . $paymentId;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => __DIR__ . '/_test_cookies.txt',
    CURLOPT_COOKIEFILE => __DIR__ . '/_test_cookies.txt',
]);
$resp = curl_exec($ch);
curl_close($ch);
echo "   $resp\n";
$checkData = json_decode($resp, true);
echo "   status={$checkData['status']}\n\n";

// Step 4: Poll check_mtn — 2nd poll should be completed
echo "4) Testing check_mtn poll #2 (should be completed)...\n";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => __DIR__ . '/_test_cookies.txt',
    CURLOPT_COOKIEFILE => __DIR__ . '/_test_cookies.txt',
]);
$resp = curl_exec($ch);
curl_close($ch);
echo "   $resp\n";
$checkData = json_decode($resp, true);
echo "   status={$checkData['status']}\n\n";

// Step 5: Verify DB state
require 'db.php';
$stmt = $pdo->prepare("SELECT p.status, p.transaction_ref, p.transaction_id, p.receipt_number, m.status as member_status FROM payments p JOIN members m ON m.id=p.member_id WHERE p.id=?");
$stmt->execute([$paymentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo "5) DB state for payment #$paymentId:\n";
echo "   payment_status={$row['status']}, member_status={$row['member_status']}\n";
echo "   txn_ref={$row['transaction_ref']}, txn_id={$row['transaction_id']}\n";
echo "   receipt={$row['receipt_number']}\n\n";

if ($row['status'] === 'verified' && $row['member_status'] === 'active') {
    echo "=== ALL TESTS PASSED ===\n";
} else {
    echo "=== SOME CHECKS FAILED ===\n";
}

// Cleanup cookies
@unlink(__DIR__ . '/_test_cookies.txt');

// Check the real user's email
$stmt = $pdo->prepare("SELECT id FROM members WHERE email = ?");
$stmt->execute(['lugemwa174@gmail.com']);
$r = $stmt->fetch();
echo "\n6) lugemwa174@gmail.com exists: " . ($r ? "yes (id={$r['id']})" : "no") . "\n";
echo "   Repeat donations will now reuse this member record.\n";
