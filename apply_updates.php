<?php
/**
 * Safe production deployment — schema migrations + restore missing homepage content.
 * Does NOT delete real events or overwrite good admin data.
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

try {
    $restored = restoreMissingSiteDefaults($pdo);
    if (empty($restored)) {
        echo "[OK] Homepage content OK (nothing to restore)\n";
    } else {
        echo "[OK] Restored: " . implode(', ', $restored) . "\n";
    }
} catch (Exception $e) {
    echo '[ERR] restoreMissingSiteDefaults: ' . $e->getMessage() . "\n";
}

echo "\n=== Done. ===\n";
