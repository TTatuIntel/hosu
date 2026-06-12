<?php
/**
 * Recover what Git still has from April 2026 (read-only checks + optional seed).
 *
 * Git never stored real member accounts or payments — only code and sample seed data.
 * Your live data may still be in database hosuweb_db (April .env name) not hosu_blog.
 *
 * CLI:  php recover_from_git_april.php
 *       php recover_from_git_april.php --seed-empty-tables
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only. Run: php recover_from_git_april.php\n");
}

require_once __DIR__ . '/env.php';

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$envDb = getenv('DB_NAME') ?: '';
$doSeed = in_array('--seed-empty-tables', $argv ?? [], true);

$aprilDbs = array_values(array_unique(array_filter([
    $envDb,
    'hosuweb_db',
    'hosu_blog',
])));

echo "=== HOSU recover from Git (April 2026) ===\n\n";
echo "Git commit with sample seeder: 060b0ea^ (removed 7 Apr 2026)\n";
echo "April .env.example used DB_NAME=hosuweb_db\n";
echo "Current .env DB_NAME=" . ($envDb ?: '(not set)') . "\n\n";

try {
    $root = new PDO("mysql:host=$host;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "MySQL connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

$tables = ['users', 'members', 'events', 'payments', 'posts', 'homepage_hero_slides', 'homepage_settings'];

echo "Scanning databases for your data:\n";
$best = ['db' => '', 'total' => 0];

foreach ($aprilDbs as $db) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "  $db — not accessible\n";
        continue;
    }

    $total = 0;
    $parts = [];
    foreach ($tables as $t) {
        try {
            $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $total += $n;
            $parts[] = "$t=$n";
        } catch (PDOException $e) {
            $parts[] = "$t=—";
        }
    }
    echo "  $db — " . implode(', ', $parts) . " (total $total)\n";
    if ($total > $best['total']) {
        $best = ['db' => $db, 'total' => $total];
    }
}

echo "\n";

if ($best['db'] !== '' && $best['total'] > 0) {
    echo ">>> Point .env to: DB_NAME={$best['db']}\n";
    if ($best['db'] !== $envDb) {
        echo "    Your .env may be aimed at the wrong empty database.\n";
    }
} else {
    echo ">>> No row data found in hosuweb_db or hosu_blog.\n";
    echo "    Real accounts cannot be restored from Git — use hosting MySQL backup.\n";
}

echo "\nWhat Git CAN restore:\n";
echo "  - Code from April:  git checkout c4dafb7 -- .   (on a copy/branch only)\n";
echo "  - Sample content:   php seed_sample_data.php    (only if tables are empty)\n";
echo "  - NOT real members, payments, or admin passwords from production\n";

if ($doSeed) {
    echo "\nRunning seed_sample_data.php (skips non-empty tables)...\n";
    if (!is_file(__DIR__ . '/seed_sample_data.php')) {
        echo "seed_sample_data.php missing — run: git restore --source=060b0ea^ -- seed_sample_data.php\n";
        exit(1);
    }
    require __DIR__ . '/seed_sample_data.php';
} else {
    echo "\nTo add April sample events/posts into EMPTY tables only:\n";
    echo "  php recover_from_git_april.php --seed-empty-tables\n";
}

echo "\nDone.\n";
