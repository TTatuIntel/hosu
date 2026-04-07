<?php
/**
 * Database Setup Script
 * Creates the configured database, all tables, and seeds initial data.
 * Run once: php setup_db.php   (CLI)   or visit setup_db.php in the browser.
 */

require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: 'localhost';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$dbname   = getenv('DB_NAME') ?: 'hosuweb_db';

// -------------------------------------------------------------------
// 1. Connect without a database so we can CREATE it
// -------------------------------------------------------------------
try {
    $pdo = new PDO("mysql:host=$host;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8 COLLATE utf8_general_ci");
$pdo->exec("USE `$dbname`");

echo "✓ Database '$dbname' ready.\n";

// -------------------------------------------------------------------
// 2. Create tables
// -------------------------------------------------------------------

// Users
$pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50)  NOT NULL UNIQUE,
        email    VARCHAR(100) NOT NULL UNIQUE,
        phone    VARCHAR(30)  DEFAULT '',
        password VARCHAR(255) NOT NULL,
        role     ENUM('admin','member') NOT NULL DEFAULT 'member',
        is_locked       TINYINT(1)  NOT NULL DEFAULT 0,
        failed_attempts INT         NOT NULL DEFAULT 0,
        locked_until    DATETIME    DEFAULT NULL,
        last_login      DATETIME    DEFAULT NULL,
        must_change_password TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'users' created.\n";

// Login attempts tracking (brute-force protection)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS login_attempts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        ip_address    VARCHAR(45)  NOT NULL,
        identity      VARCHAR(100) NOT NULL,
        attempted_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        success       TINYINT(1)   NOT NULL DEFAULT 0,
        INDEX idx_ip (ip_address),
        INDEX idx_time (attempted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'login_attempts' created.\n";

// Password reset tokens
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT         NOT NULL,
        token      VARCHAR(64) NOT NULL UNIQUE,
        reset_code VARCHAR(6)  NOT NULL,
        expires_at DATETIME    NOT NULL,
        used       TINYINT(1)  NOT NULL DEFAULT 0,
        created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_token (token),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'password_resets' created.\n";

// Events
$pdo->exec("
    CREATE TABLE IF NOT EXISTS events (
        id          VARCHAR(100) PRIMARY KEY,
        type        VARCHAR(50)  NOT NULL,
        status      VARCHAR(20)  NOT NULL,
        image       VARCHAR(255) NOT NULL DEFAULT '',
        imageAlt    VARCHAR(255) NOT NULL DEFAULT '',
        countdown   VARCHAR(100) DEFAULT '',
        date        VARCHAR(100) NOT NULL,
        date_start  DATE         DEFAULT NULL,
        date_end    DATE         DEFAULT NULL,
        title       VARCHAR(255) NOT NULL,
        description TEXT         NOT NULL,
        location    VARCHAR(255) NOT NULL,
        featured    TINYINT(1)   NOT NULL DEFAULT 0,
        category    VARCHAR(50)  NOT NULL DEFAULT 'upcoming',
        is_free     TINYINT(1)   NOT NULL DEFAULT 1,
        event_fee   DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'events' created.\n";

// Posts (blog)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS posts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL,
        content       TEXT         NOT NULL,
        category      VARCHAR(50)  NOT NULL DEFAULT 'General',
        author        VARCHAR(100) NOT NULL DEFAULT 'Anonymous',
        image         VARCHAR(255) DEFAULT 'uploads/default-blog.jpg',
        avatar        VARCHAR(255) DEFAULT 'uploads/default-avatar.jpg',
        comment_count INT          NOT NULL DEFAULT 0,
        created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'posts' created.\n";

// Comments
$pdo->exec("
    CREATE TABLE IF NOT EXISTS comments (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        post_id    INT          NOT NULL,
        author     VARCHAR(100) NOT NULL,
        content    TEXT         NOT NULL,
        created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'comments' created.\n";

// Members
$pdo->exec("
    CREATE TABLE IF NOT EXISTS members (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        full_name       VARCHAR(150) NOT NULL,
        email           VARCHAR(150) NOT NULL,
        phone           VARCHAR(30)  DEFAULT '',
        profession      VARCHAR(100) DEFAULT '',
        institution     VARCHAR(200) DEFAULT '',
        membership_type VARCHAR(50)  NOT NULL DEFAULT 'annual',
        status          ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'members' created.\n";

// Payments
$pdo->exec("
    CREATE TABLE IF NOT EXISTS payments (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        member_id       INT          NOT NULL,
        amount          DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency        VARCHAR(10)  NOT NULL DEFAULT 'UGX',
        payment_method  VARCHAR(50)  DEFAULT 'unknown',
        transaction_ref VARCHAR(100) DEFAULT '',
        transaction_id  VARCHAR(100) DEFAULT '',
        proof_file      VARCHAR(255) DEFAULT '',
        status          ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
        invoice_sent    TINYINT(1)   NOT NULL DEFAULT 0,
        receipt_number  VARCHAR(30)  DEFAULT '',
        receipt_token   VARCHAR(64)  DEFAULT '',
        qr_scanned      TINYINT(1)  NOT NULL DEFAULT 0,
        scanned_at      TIMESTAMP   NULL DEFAULT NULL,
        notes           TEXT         DEFAULT '',
        payment_type    ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership',
        membership_period VARCHAR(20) DEFAULT '1_year',
        membership_expires_at DATE  NULL DEFAULT NULL,
        event_id        VARCHAR(100) DEFAULT '',
        event_title     VARCHAR(255) DEFAULT '',
        event_date      VARCHAR(100) DEFAULT '',
        paid_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'payments' created.\n";

// Event Registrants (separate from members — anyone can register for events)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_registrants (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        event_id       VARCHAR(100) NOT NULL,
        event_title    VARCHAR(255) NOT NULL DEFAULT '',
        event_date     VARCHAR(100) DEFAULT '',
        full_name      VARCHAR(150) NOT NULL,
        email          VARCHAR(150) NOT NULL,
        phone          VARCHAR(30)  DEFAULT '',
        profession     VARCHAR(100) DEFAULT '',
        institution    VARCHAR(200) DEFAULT '',
        amount         DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency       VARCHAR(10)  NOT NULL DEFAULT 'UGX',
        payment_method VARCHAR(50)  DEFAULT 'free',
        transaction_ref VARCHAR(100) DEFAULT '',
        transaction_id VARCHAR(100) DEFAULT '',
        proof_file     VARCHAR(255) DEFAULT '',
        status         ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
        payment_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified',
        receipt_number VARCHAR(30)  DEFAULT '',
        receipt_token  VARCHAR(64)  DEFAULT '',
        qr_scanned    TINYINT(1)   NOT NULL DEFAULT 0,
        scanned_at    TIMESTAMP    NULL DEFAULT NULL,
        notes          TEXT         DEFAULT '',
        registered_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'event_registrants' created.\n";

// Publications
$pdo->exec("
    CREATE TABLE IF NOT EXISTS publications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        pub_type    VARCHAR(50)  NOT NULL DEFAULT 'Journal',
        title       VARCHAR(255) NOT NULL,
        authors     VARCHAR(255) NOT NULL DEFAULT '',
        pub_date    VARCHAR(50)  NOT NULL DEFAULT '',
        link        VARCHAR(500) DEFAULT '',
        link_label  VARCHAR(100) DEFAULT 'Read abstract',
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'publications' created.\n";

// Grants
$pdo->exec("
    CREATE TABLE IF NOT EXISTS grants_opportunities (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL,
        amount      DECIMAL(15,2) NOT NULL DEFAULT 0,
        currency    VARCHAR(10)  NOT NULL DEFAULT 'UGX',
        deadline    VARCHAR(100) DEFAULT '',
        status      VARCHAR(50)  NOT NULL DEFAULT 'open',
        description TEXT         DEFAULT '',
        apply_link  VARCHAR(500) DEFAULT '',
        sort_order  INT          NOT NULL DEFAULT 0,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'grants_opportunities' created.\n";

// Grant Applications (links to payments)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS grant_applications (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        grant_id    INT          NOT NULL,
        full_name   VARCHAR(150) NOT NULL,
        email       VARCHAR(150) NOT NULL,
        phone       VARCHAR(30)  DEFAULT '',
        institution VARCHAR(200) DEFAULT '',
        proposal    TEXT         DEFAULT '',
        status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        payment_id  INT          DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (grant_id) REFERENCES grants_opportunities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'grant_applications' created.\n";

// -------------------------------------------------------------------
// 3. Seed admin user (skip if one already exists)
// -------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$stmt->execute();
if ((int)$stmt->fetchColumn() === 0) {
    $adminSeedPassword = getenv('ADMIN_SEED_PASSWORD') ?: 'ad@hosu256';
    $hash = password_hash($adminSeedPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (username, email, phone, password, role, must_change_password) VALUES (?, ?, ?, ?, 'admin', 0)")
        ->execute(['admin', 'admin@hosu.or.ug', '+256766529869', $hash]);
    echo "✓ Admin user seeded (admin@hosu.or.ug / {$adminSeedPassword}).\n";
} else {
    echo "– Admin user already exists, skipping.\n";
}

// Leadership / About page bios
$pdo->exec("
    CREATE TABLE IF NOT EXISTS leaders (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(150) NOT NULL,
        title        VARCHAR(150) NOT NULL DEFAULT '',
        qualifications VARCHAR(300) DEFAULT '',
        biography    TEXT DEFAULT '',
        photo_url    VARCHAR(500) DEFAULT '',
        sort_order   INT NOT NULL DEFAULT 0,
        is_active    TINYINT(1) NOT NULL DEFAULT 1,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'leaders' created.\n";

// Site Stats — admin-managed counters shown across public pages
$pdo->exec("
    CREATE TABLE IF NOT EXISTS site_stats (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        stat_key   VARCHAR(80)  NOT NULL UNIQUE,
        stat_value VARCHAR(100) NOT NULL DEFAULT '',
        stat_label VARCHAR(100) NOT NULL DEFAULT '',
        page       VARCHAR(50)  NOT NULL DEFAULT 'global',
        sort_order INT          NOT NULL DEFAULT 0,
        is_active  TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'site_stats' created.\n";

// Seed default stats if empty
$stmt = $pdo->prepare("SELECT COUNT(*) FROM site_stats");
$stmt->execute();
if ((int)$stmt->fetchColumn() === 0) {
    $defaultStats = [
        ['members_count',    '500+', 'Members',        'membership', 1],
        ['specialties_count','12+',  'Specialties',    'membership', 2],
        ['institutions_count','50+', 'Institutions',   'membership', 3],
        ['about_members',    '150+', 'Members',        'about',      1],
        ['about_founded',    '2019', 'Founded',        'about',      2],
        ['about_events',     '50+',  'Events',         'about',      3],
        ['research_studies', '12+',  'Active Studies',  'research',   1],
        ['research_centers', '8',    'Partner Centers', 'research',   2],
        ['research_domains', '4',    'Focus Domains',   'research',   3],
    ];
    $ins = $pdo->prepare("INSERT INTO site_stats (stat_key, stat_value, stat_label, page, sort_order) VALUES (?,?,?,?,?)");
    foreach ($defaultStats as $s) { $ins->execute($s); }
    echo "✓ Default site stats seeded.\n";
}

// Site Media — admin-uploaded images / content
$pdo->exec("
    CREATE TABLE IF NOT EXISTS site_media (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT         DEFAULT '',
        file_path   VARCHAR(500) NOT NULL,
        file_type   VARCHAR(50)  NOT NULL DEFAULT 'image',
        file_size   INT          NOT NULL DEFAULT 0,
        category    VARCHAR(80)  NOT NULL DEFAULT 'general',
        uploaded_by INT          DEFAULT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'site_media' created.\n";

// Audit logs table
$pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(50) DEFAULT NULL,
        entity_id VARCHAR(100) DEFAULT NULL,
        details TEXT DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action (action),
        INDEX idx_user (user_id),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'audit_logs' created.\n";

// -------------------------------------------------------------------
// 4. Seed leadership data (skip if leaders already exist)
// -------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM leaders");
$stmt->execute();
if ((int)$stmt->fetchColumn() === 0) {
    $leaders = [
        ['Dr. Ddungu Henry',          'President',               'MD, PhD',                  'img/Picture1.jpg',  1,
         'Dr. Ddungu graduated from Makerere University in 1998 and later obtained a Masters in Internal Medicine from the same University in 2003. He trained in Hematology at McMaster University, Ontario, Canada, and holds a PhD from Makerere University, Kampala, Uganda.

Dr. Ddungu is a Senior Consultant (Hematology-Oncology) and Head of Division of Medical Oncology and Hematology at the Uganda Cancer Institute. He is interested in global health and in advancements in the treatment of both classical and malignant hematological illnesses including immunotherapy and cellular therapies.

He is the Founding President of the Hematology & Oncology Society of Uganda (HOSU).

He is an Honorary Lecturer at Makerere University College of Health Sciences; Associate Clinical Professor (Adjunct), McMaster University, Faculty of Health Sciences Department of Medicine; and Honorary Lecturer, Department of Medicine, Mbarara University of Science and Technology, Uganda.

For a long time, Dr. Ddungu worked with Palliative Care Organizations in Uganda and Africa, advocating for the advancement of quality palliative care to persons with life threatening illnesses.

Dr. Ddungu is active in research and has been a principal investigator and co-investigator on several clinical studies. He has also supervised several Master of Medicine dissertations and has published papers in referenced journals.'],
        ['Dr. Nabbosa Valeria',       'President Elect',         '',  'img/Picture2.jpg',  2, ''],
        ['Dr. Odhiambo Clara',        'General Secretary',       '',  'img/Picture3.jpg',  3, ''],
        ['Dr. Niyonzima Nixon',       'Treasurer',               '',  'img/Picture4.jpg',  4, ''],
        ['Dr. Kakungulu Edward',      'Publicity Secretary',     '',  'img/Picture5.jpg',  5, ''],
        ['Dr. Namazzi Ruth',          'Rep. Hematology',         '',  'img/Picture6.jpg',  6, ''],
        ['Dr. Akullo Anne',           'Rep. Pediatric Oncology', '',  'img/Picture7.jpg',  7, ''],
        ['Dr. Bogere Naghib',         'Rep. Medical Oncology',   '',  'img/Picture8.jpg',  8, ''],
        ['Dr. Asiimwe Lois',          'Rep. Surgical Oncology',  '',  'img/Picture9.jpg',  9, ''],
        ['Dr. Kibudde Solomon',       'Rep. Radiation Oncology', '',  'img/Picture10.jpg', 10, ''],
        ['Dr. Ssali Francis',         'Rep. Research',           '',  'img/Picture11.jpg', 11, ''],
        ['Dr. Lukande Robert',        'Rep. Pathology',          '',  'img/Picture12.jpg', 12, ''],
        ['Dr. Kadhumbula Sylvester',  'Rep. Laboratory',         '',  'img/Picture13.jpg', 13, ''],
        ['Ms. Irumba Lisa',           'Rep. Palliative Care',    '',  'img/Picture14.jpg', 14, ''],
        ['Mr. Moses Echodu',          'Rep. Civil Society',      '',  'img/Picture15.jpg', 15, ''],
    ];
    $ins = $pdo->prepare("INSERT INTO leaders (name, title, qualifications, photo_url, sort_order, biography, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
    foreach ($leaders as $l) {
        $ins->execute($l);
    }
    echo "✓ 15 leadership members seeded.\n";
} else {
    echo "– Leaders already exist, skipping seed.\n";
}

echo "– System running with real data only. Use the Admin panel to add content.\n";

// -------------------------------------------------------------------
// 6. Regenerate data.js from the database (if script exists)
// -------------------------------------------------------------------
if (file_exists(__DIR__ . '/generate_js.php')) {
    require 'generate_js.php';
    echo "✓ data.js regenerated from database.\n";
} else {
    echo "– generate_js.php not found, skipping data.js generation.\n";
}

echo "\n══════════════════════════════════════════\n";
echo "  Setup complete!\n";
echo "  Admin login: admin / use ADMIN_SEED_PASSWORD from .env\n";
echo "══════════════════════════════════════════\n";
