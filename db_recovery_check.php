<?php
/**
 * READ-ONLY — find which MySQL database still has your live HOSU data.
 * Does not delete or modify anything.
 *
 * Browser: https://hosu.or.ug/db_recovery_check.php?key=HOSU_RECOVER_2026
 * CLI:     php db_recovery_check.php
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli && (($_GET['key'] ?? '') !== 'HOSU_RECOVER_2026')) {
    http_response_code(403);
    exit('403 Forbidden. Append ?key=HOSU_RECOVER_2026 to the URL.');
}

require_once __DIR__ . '/env.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$currentDb = getenv('DB_NAME') ?: 'hosu_blog';

$tables = [
    'users' => 'Login accounts (admin + members)',
    'members' => 'Member registrations',
    'events' => 'Events',
    'event_registrants' => 'Event sign-ups',
    'payments' => 'Payments / receipts',
    'posts' => 'Blog posts',
    'homepage_hero_slides' => 'Homepage hero carousel',
    'homepage_settings' => 'Footer, partners, CTA (JSON)',
    'leaders' => 'About page leaders',
];

function out(string $line): void
{
    global $isCli;
    if ($isCli) {
        echo $line . PHP_EOL;
        return;
    }
    echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . "<br>\n";
}

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>HOSU Data Recovery Check</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:960px;margin:2rem auto;padding:0 1rem;}';
    echo 'table{border-collapse:collapse;width:100%;margin:1rem 0;}th,td{border:1px solid #e2e8f0;padding:.5rem .75rem;text-align:left;font-size:.9rem;}';
    echo 'th{background:#f8fafc;}.best{background:#f0fdf4;}.empty{color:#94a3b8;}.warn{background:#fffbeb;}';
    echo 'code{background:#f1f5f9;padding:.1rem .35rem;border-radius:4px;}</style></head><body>';
    echo '<h1>HOSU data recovery check (read-only)</h1>';
    echo '<p>Scans every database this MySQL user can see. <strong>Nothing is changed.</strong></p>';
}

try {
    $root = new PDO("mysql:host=$host;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    out('Cannot connect to MySQL: ' . $e->getMessage());
    exit(1);
}

out('Connected as: ' . $user . '@' . $host);
out('Current .env DB_NAME: ' . $currentDb);
out('');

$allDbs = $root->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
$candidates = array_values(array_filter($allDbs, static function (string $db): bool {
    if (in_array($db, ['information_schema', 'performance_schema', 'mysql', 'sys'], true)) {
        return false;
    }
    return stripos($db, 'hosu') !== false
        || stripos($db, 'hosuweb') !== false
        || stripos($db, 'blog') !== false;
}));

if (empty($candidates)) {
    $candidates = array_values(array_filter($allDbs, static function (string $db): bool {
        return !in_array($db, ['information_schema', 'performance_schema', 'mysql', 'sys'], true);
    }));
}

$scores = [];

foreach ($candidates as $db) {
    $counts = ['_total' => 0];
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        $counts['_error'] = $e->getMessage();
        $scores[$db] = $counts;
        continue;
    }

    foreach (array_keys($tables) as $table) {
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            $counts[$table] = $n;
            $counts['_total'] += $n;
        } catch (PDOException $e) {
            $counts[$table] = null;
        }
    }
    $scores[$db] = $counts;
}

uasort($scores, static function (array $a, array $b): int {
    return ($b['_total'] ?? 0) <=> ($a['_total'] ?? 0);
});

if (!$isCli) {
    echo '<h2>Database row counts</h2><table><thead><tr><th>Database</th>';
    foreach ($tables as $label) {
        echo '<th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    echo '<th>Total rows</th></tr></thead><tbody>';
}

$bestDb = null;
$bestTotal = 0;
foreach ($scores as $db => $counts) {
    $total = (int) ($counts['_total'] ?? 0);
    if ($total > $bestTotal) {
        $bestTotal = $total;
        $bestDb = $db;
    }

    if ($isCli) {
        out("=== $db (total: $total) ===");
        if (!empty($counts['_error'])) {
            out('  ERROR: ' . $counts['_error']);
            continue;
        }
        foreach ($tables as $table => $label) {
            $n = $counts[$table] ?? null;
            out('  ' . $table . ': ' . ($n === null ? '—' : (string) $n));
        }
        out('');
        continue;
    }

    $rowClass = ($db === $currentDb) ? 'warn' : '';
    if ($db === $bestDb && $bestTotal > 0) {
        $rowClass = 'best';
    }
    echo '<tr class="' . $rowClass . '"><td><code>' . htmlspecialchars($db, ENT_QUOTES, 'UTF-8') . '</code>';
    if ($db === $currentDb) {
        echo ' <small>(.env)</small>';
    }
    echo '</td>';
    foreach (array_keys($tables) as $table) {
        $n = $counts[$table] ?? null;
        $cell = $n === null ? '—' : (string) $n;
        $cls = ($n === 0 || $n === null) ? 'empty' : '';
        echo '<td class="' . $cls . '">' . htmlspecialchars($cell, ENT_QUOTES, 'UTF-8') . '</td>';
    }
    echo '<td><strong>' . $total . '</strong></td></tr>';
}

if (!$isCli) {
    echo '</tbody></table>';
}

out('');
if ($bestDb && $bestTotal > 0 && $bestDb !== $currentDb) {
    out('LIKELY FIX: Your data is probably in database "' . $bestDb . '" (' . $bestTotal . ' rows).');
    out('Update /var/www/hosu/.env → DB_NAME=' . $bestDb . ' then test login and homepage.');
} elseif ($bestDb && $bestTotal > 0) {
    out('Current database "' . $currentDb . '" has ' . $bestTotal . ' rows across key tables.');
    if (($scores[$currentDb]['users'] ?? 0) === 0) {
        out('users table is empty — restore from a MySQL backup (cPanel / hosting snapshots).');
    }
} else {
    out('No data found in any scanned database. Restore from hosting backup:');
    out('  cPanel → Backup → Restore a backup from BEFORE the update');
    out('  or AWS snapshot / mysqldump if you have one');
}

out('');
out('What our update scripts deleted (if clear_public_content was run):');
out('  events, registrants, posts, hero slides, leaders, homepage_settings — NOT users/members.');
out('If members/users are also gone, the whole database may have been replaced or restored empty.');

if (!$isCli) {
    echo '<p style="color:#64748b;font-size:.85rem;">Delete this file after recovery. Key: HOSU_RECOVER_2026</p></body></html>';
}
