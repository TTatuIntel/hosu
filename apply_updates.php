<?php
/**
 * Safe production deployment script — schema migrations ONLY.
 *
 * - Adds missing DB columns/tables (migrateEventSchema)
 * - Does NOT delete or overwrite events, hero slides, partners, leaders, posts, etc.
 * - Live content at https://hosu.or.ug/ is stored in MySQL (database: hosu_blog) and is only changed via Admin.
 *
 * Usage on server:  php apply_updates.php
 * Never run:        php clear_public_content.php  (dev/staging only)
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

echo "HOSU apply_updates — schema migrations only (live database content preserved)\n\n";

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

echo "\n=== Done. No website content was modified. ===\n";
echo "Deploy code: git pull origin main\n";
echo "Public content (hero, partners, events, etc.) comes from the database — edit via Admin only.\n";

