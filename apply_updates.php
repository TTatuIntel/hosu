<?php
/**
 * Safe production update script: schema migrations only.
 * Does NOT delete events, posts, hero slides, partners, or any live content.
 *
 * Usage: php apply_updates.php
 *
 * To wipe public content (dev/staging ONLY), use clear_public_content.php instead.
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

echo "HOSU apply_updates — schema & feature migrations (live data preserved)\n\n";

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

// 2. Run all event/homepage schema migrations (new columns, tables, etc.)
try {
    migrateEventSchema($pdo);
    echo "[OK] migrateEventSchema completed\n";
} catch (Exception $e) {
    echo '[ERR] migrateEventSchema: ' . $e->getMessage() . "\n";
}

echo "\n=== Done. All existing website content was left unchanged. ===\n";
echo "Deploy code with: git pull origin main\n";
echo "Do NOT run clear_public_content.php on production.\n";
