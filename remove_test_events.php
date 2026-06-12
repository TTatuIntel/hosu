<?php
/**
 * Remove ONLY test/dummy events (title contains test, dummy, sample, etc.).
 * Does NOT touch homepage, footer, partners, hero slides, leaders, or real events.
 *
 * Usage:
 *   php remove_test_events.php           # dry run — list what would be deleted
 *   php remove_test_events.php --confirm # actually delete
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

$confirm = in_array('--confirm', $argv ?? [], true);
$rows = $pdo->query('SELECT id, title FROM events')->fetchAll(PDO::FETCH_ASSOC);
$targets = array_values(array_filter($rows, fn($r) => eventRowLooksLikeTest($r)));

if (empty($targets)) {
    echo "No test/dummy events found.\n";
    exit(0);
}

echo ($confirm ? "Deleting" : "Would delete") . " " . count($targets) . " test event(s):\n";
foreach ($targets as $row) {
    echo "  - {$row['id']}: {$row['title']}\n";
}

if (!$confirm) {
    echo "\nDry run only. To delete, run: php remove_test_events.php --confirm\n";
    exit(0);
}

$result = deleteTestEventsOnly($pdo);
echo "\nDeleted " . count($result['deleted']) . " event(s). Kept {$result['skipped']} real event(s).\n";
