<?php
/**
 * DEV/STAGING ONLY — deletes all public content from the database.
 * NEVER run this on production. Use apply_updates.php for live deployments.
 *
 * Usage: php clear_public_content.php --confirm
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$confirm = in_array('--confirm', $argv ?? [], true);
if (!$confirm) {
    fwrite(STDERR, "Refusing to run without --confirm flag.\n");
    fwrite(STDERR, "This deletes events, posts, hero slides, leaders, partners settings, etc.\n");
    fwrite(STDERR, "Usage: php clear_public_content.php --confirm\n");
    exit(1);
}

require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

echo "WARNING: Clearing all public content...\n";
$cleared = clearPublicContent($pdo);
foreach ($cleared as $table => $n) {
    if (is_int($n)) {
        echo "  $table: $n row(s)\n";
    } else {
        echo "  $table: $n\n";
    }
}
echo "Done.\n";
