<?php
/**
 * Clean all pending payments and orphan records from the database.
 * Run once: php clean_pending.php
 */
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';

echo "── Cleaning pending payments ──\n";

// Delete pending event registrants
$stmt = $pdo->prepare("DELETE FROM event_registrants WHERE payment_status = 'pending'");
$stmt->execute();
echo "Deleted {$stmt->rowCount()} pending event registrant(s)\n";

// Delete pending payments
$stmt = $pdo->prepare("DELETE FROM payments WHERE status = 'pending'");
$stmt->execute();
echo "Deleted {$stmt->rowCount()} pending payment(s)\n";

// Delete orphan members (pending members with no payments)
$stmt = $pdo->prepare("DELETE FROM members WHERE status = 'pending' AND id NOT IN (SELECT DISTINCT member_id FROM payments WHERE member_id IS NOT NULL)");
$stmt->execute();
echo "Deleted {$stmt->rowCount()} orphan pending member(s)\n";

echo "── Done ──\n";
