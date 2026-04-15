<?php
/**
 * One-time update script: apply DB changes + logout all users.
 * Safe to run multiple times. Delete after use.
 */
require __DIR__ . '/db.php';

// 1. Add seed_cooldown_until column if missing
try {
    $pdo->exec('ALTER TABLE users ADD COLUMN seed_cooldown_until DATETIME DEFAULT NULL');
    echo "[OK] Added seed_cooldown_until column\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "[OK] seed_cooldown_until column already exists\n";
    } else {
        echo '[ERR] ' . $e->getMessage() . "\n";
    }
}

// 2. Clear all PHP sessions (logout all users)
$sessionPath = session_save_path() ?: sys_get_temp_dir();
$count = 0;
foreach (glob($sessionPath . '/sess_*') as $f) {
    @unlink($f);
    $count++;
}
echo "[OK] Cleared $count session(s) — all users logged out\n";

// 3. Reset seed admin cooldown so it's ready for next login
$pdo->prepare("UPDATE users SET seed_cooldown_until = NULL WHERE username = 'admin'")->execute();
echo "[OK] Seed admin cooldown reset\n";

// 4. Ensure seed admin has must_change_password = 1
$pdo->prepare("UPDATE users SET must_change_password = 1 WHERE username = 'admin'")->execute();
echo "[OK] Seed admin must_change_password = 1\n";

echo "\n=== All updates applied. All users logged out. ===\n";
