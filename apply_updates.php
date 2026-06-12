<?php
/**
 * Safe production deployment — schema migrations only.
 * Does NOT restore or overwrite homepage/footer content (use restore_site_defaults.php for that).
 *
 * Usage: php apply_updates.php
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

echo "HOSU apply_updates — safe deployment (live content preserved)\n\n";

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

try {
    migrateEventSchema($pdo);
    echo "[OK] migrateEventSchema completed\n";
} catch (Exception $e) {
    echo '[ERR] migrateEventSchema: ' . $e->getMessage() . "\n";
}

echo "[INFO] Homepage/footer content is not changed by this script.\n";
echo "[INFO] To fill only missing rows (never overwrites admin edits), run: php restore_site_defaults.php\n";

echo "\n=== Done. ===\n";

