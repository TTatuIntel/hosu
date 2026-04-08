<?php
/**
 * Database Cleanup Script
 * Removes all data except leaders and admin users.
 * Run once: php cleanup_db.php (CLI) or visit in browser.
 */
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';

echo "══════════════════════════════════════════\n";
echo "  HOSU Database Cleanup\n";
echo "══════════════════════════════════════════\n\n";

// Tables to TRUNCATE (all data content tables)
$truncateTables = [
    'comments',
    'posts',
    'event_registrants',
    'grant_applications',
    'grants_opportunities',
    'publications',
    'payments',
    'members',
    'events',
    'site_media',
    'audit_logs',
    'login_attempts',
    'password_resets',
];

// Disable FK checks for truncation
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

foreach ($truncateTables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
        $count = (int) $stmt->fetchColumn();
        $pdo->exec("TRUNCATE TABLE `$table`");
        echo "✓ Truncated '$table' ($count rows removed)\n";
    } catch (PDOException $e) {
        echo "– Skipped '$table' (table may not exist)\n";
    }
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

// Remove non-admin users
try {
    $stmt = $pdo->prepare("DELETE FROM users WHERE role != 'admin'");
    $stmt->execute();
    $removed = $stmt->rowCount();
    echo "\n✓ Removed $removed non-admin user(s)\n";
} catch (PDOException $e) {
    echo "– Could not clean non-admin users: " . $e->getMessage() . "\n";
}

// Reset admin password to default + force change
$adminSeedPassword = getenv('ADMIN_SEED_PASSWORD') ?: 'ad@hosu256';
$hash = password_hash($adminSeedPassword, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare("UPDATE users SET password = ?, must_change_password = 1, failed_attempts = 0, is_locked = 0, locked_until = NULL WHERE role = 'admin'")
    ->execute([$hash]);
echo "✓ Admin password reset to default (must_change_password = 1)\n";

// Show what's preserved
echo "\n── Preserved data ──\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM leaders");
echo "  Leaders: " . $stmt->fetchColumn() . " rows\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
echo "  Admin users: " . $stmt->fetchColumn() . " rows\n";
$stmt = $pdo->query("SELECT COUNT(*) FROM site_stats");
echo "  Site stats: " . $stmt->fetchColumn() . " rows\n";

echo "\n══════════════════════════════════════════\n";
echo "  Cleanup complete!\n";
echo "  Leaders + admin preserved.\n";
echo "  Admin must change password on next login.\n";
echo "══════════════════════════════════════════\n";
