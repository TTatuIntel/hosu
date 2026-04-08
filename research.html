<?php
/**
 * Leadership Seeder
 * Seeds the `leaders` table with the initial HOSU board data.
 *
 * Usage (CLI only):
 *   php seed_leaders.php           # Skips if leaders already exist
 *   php seed_leaders.php --force   # Truncates and re-seeds
 *
 * This script is blocked from web access via .htaccess.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'hosuweb_db';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$force    = in_array('--force', $argv ?? [], true);

// ── Connect ──────────────────────────────────────────────────────────
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    echo "✓ Connected to database '$dbname' on '$host'.\n";
} catch (PDOException $e) {
    echo "✗ Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// ── Ensure table exists ───────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS leaders (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        name           VARCHAR(150) NOT NULL,
        title          VARCHAR(150) NOT NULL DEFAULT '',
        qualifications VARCHAR(300) DEFAULT '',
        biography      TEXT,
        photo_url      VARCHAR(500) DEFAULT '',
        sort_order     INT          NOT NULL DEFAULT 0,
        is_active      TINYINT(1)   NOT NULL DEFAULT 1,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'leaders' ready.\n";

// ── Guard: skip if data already exists (unless --force) ───────────────
$count = (int)$pdo->query("SELECT COUNT(*) FROM leaders")->fetchColumn();
if ($count > 0 && !$force) {
    echo "– Leaders already exist ($count rows). Use --force to truncate and re-seed.\n";
    exit(0);
}

if ($force && $count > 0) {
    $pdo->exec("TRUNCATE TABLE leaders");
    echo "✓ Existing leaders truncated (--force).\n";
}

// ── Leadership data ───────────────────────────────────────────────────
// Format: [name, title, qualifications, photo_url, sort_order, biography]
$leaders = [
    [
        'Dr. Ddungu Henry',
        'President',
        'MD, PhD',
        'img/Picture1.jpg',
        1,
        'Dr. Ddungu graduated from Makerere University in 1998 and later obtained a Masters in Internal Medicine from the same University in 2003. He trained in Hematology at McMaster University, Ontario, Canada, and holds a PhD from Makerere University, Kampala, Uganda.

Dr. Ddungu is a Senior Consultant (Hematology-Oncology) and Head of Division of Medical Oncology and Hematology at the Uganda Cancer Institute. He is interested in global health and in advancements in the treatment of both classical and malignant hematological illnesses including immunotherapy and cellular therapies.

He is the Founding President of the Hematology & Oncology Society of Uganda (HOSU).

He is an Honorary Lecturer at Makerere University College of Health Sciences; Associate Clinical Professor (Adjunct), McMaster University, Faculty of Health Sciences Department of Medicine; and Honorary Lecturer, Department of Medicine, Mbarara University of Science and Technology, Uganda.

For a long time, Dr. Ddungu worked with Palliative Care Organizations in Uganda and Africa, advocating for the advancement of quality palliative care to persons with life threatening illnesses.

Dr. Ddungu is active in research and has been a principal investigator and co-investigator on several clinical studies. He has also supervised several Master of Medicine dissertations and has published papers in referenced journals.',
    ],
    ['Dr. Nabbosa Valeria',      'President Elect',         '',  'img/Picture2.jpg',   2, ''],
    ['Dr. Odhiambo Clara',       'General Secretary',       '',  'img/Picture3.jpg',   3, ''],
    ['Dr. Niyonzima Nixon',      'Treasurer',               '',  'img/Picture4.jpg',   4, ''],
    ['Dr. Kakungulu Edward',     'Publicity Secretary',     '',  'img/Picture5.jpg',   5, ''],
    ['Dr. Namazzi Ruth',         'Rep. Hematology',         '',  'img/Picture6.jpg',   6, ''],
    ['Dr. Akullo Anne',          'Rep. Pediatric Oncology', '',  'img/Picture7.jpg',   7, ''],
    ['Dr. Bogere Naghib',        'Rep. Medical Oncology',   '',  'img/Picture8.jpg',   8, ''],
    ['Dr. Asiimwe Lois',         'Rep. Surgical Oncology',  '',  'img/Picture9.jpg',   9, ''],
    ['Dr. Kibudde Solomon',      'Rep. Radiation Oncology', '',  'img/Picture10.jpg', 10, ''],
    ['Dr. Ssali Francis',        'Rep. Research',           '',  'img/Picture11.jpg', 11, ''],
    ['Dr. Lukande Robert',       'Rep. Pathology',          '',  'img/Picture12.jpg', 12, ''],
    ['Dr. Kadhumbula Sylvester', 'Rep. Laboratory',         '',  'img/Picture13.jpg', 13, ''],
    ['Ms. Irumba Lisa',          'Rep. Palliative Care',    '',  'img/Picture14.jpg', 14, ''],
    ['Mr. Moses Echodu',         'Rep. Civil Society',      '',  'img/Picture15.jpg', 15, ''],
];

// ── Insert ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare(
    "INSERT INTO leaders (name, title, qualifications, photo_url, sort_order, biography, is_active)
     VALUES (?, ?, ?, ?, ?, ?, 1)"
);

$inserted = 0;
foreach ($leaders as $l) {
    $stmt->execute($l);
    echo "  + " . $l[0] . " — " . $l[1] . "\n";
    $inserted++;
}

echo "\n✓ $inserted leadership members seeded successfully.\n";
