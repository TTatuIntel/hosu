<?php
/**
 * Database Setup Script
 * Creates the configured database and all tables (no sample content).
 * Safe on existing databases: uses CREATE TABLE IF NOT EXISTS only — never truncates or deletes data.
 * Run once: php setup_db.php   (CLI)   or visit setup_db.php in the browser.
 */

require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: '127.0.0.1';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
$dbname   = getenv('DB_NAME') ?: 'hosu_blog';

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
        speakers    TEXT         DEFAULT NULL,
        highlights  TEXT         DEFAULT NULL,
        announcements TEXT       DEFAULT NULL,
        display_start DATETIME   DEFAULT NULL,
        display_end   DATETIME   DEFAULT NULL,
        display_for_event TINYINT(1) NOT NULL DEFAULT 0,
        pinned      TINYINT(1)   NOT NULL DEFAULT 0,
        home_priority INT        NOT NULL DEFAULT 0,
        live_message VARCHAR(500) DEFAULT NULL,
        live_cta_label VARCHAR(120) DEFAULT NULL,
        show_live_on_home TINYINT(1) NOT NULL DEFAULT 1,
        updated_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'events' created.\n";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_images (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(100) NOT NULL,
        image_path VARCHAR(500) NOT NULL,
        image_alt VARCHAR(255) DEFAULT '',
        caption VARCHAR(255) DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        source_type VARCHAR(20) NOT NULL DEFAULT 'upload',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_id (event_id),
        INDEX idx_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "✓ Table 'event_images' created.\n";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_media (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(100) NOT NULL,
        media_type ENUM('video','document') NOT NULL DEFAULT 'video',
        media_path VARCHAR(500) NOT NULL,
        title VARCHAR(255) DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        source_type VARCHAR(20) NOT NULL DEFAULT 'url',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_media_event (event_id),
        INDEX idx_event_media_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "✓ Table 'event_media' created.\n";

$pdo->exec("
    CREATE TABLE IF NOT EXISTS event_live_content (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(100) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        body TEXT NULL,
        content_type VARCHAR(30) NOT NULL DEFAULT 'update',
        image_url VARCHAR(500) DEFAULT '',
        link_url VARCHAR(500) DEFAULT '',
        link_label VARCHAR(120) DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        visibility VARCHAR(20) NOT NULL DEFAULT 'always',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_elc_event (event_id),
        INDEX idx_elc_sort (sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
echo "✓ Table 'event_live_content' created.\n";

// Posts (blog)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS posts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        title         VARCHAR(255) NOT NULL,
        content       TEXT         NOT NULL,
        category      VARCHAR(50)  NOT NULL DEFAULT 'General',
        author        VARCHAR(100) NOT NULL DEFAULT 'Anonymous',
        image         VARCHAR(255) DEFAULT '',
        avatar        VARCHAR(255) DEFAULT '',
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
        notes           TEXT,
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
        notes          TEXT,
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
        description TEXT,
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
        proposal    TEXT,
        status      ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        payment_id  INT          DEFAULT NULL,
        created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (grant_id) REFERENCES grants_opportunities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");
echo "✓ Table 'grant_applications' created.\n";

// -------------------------------------------------------------------
// 3. Admin user (only if none exists — never overwrites existing accounts)
// -------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
$stmt->execute();
if ((int)$stmt->fetchColumn() === 0) {
    $adminSeedPassword = getenv('ADMIN_SEED_PASSWORD') ?: 'ad@hosu256';
    $hash = password_hash($adminSeedPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (username, email, phone, password, role, must_change_password) VALUES (?, ?, ?, ?, 'admin', 1)")
        ->execute(['admin', 'info@hosu.or.ug', '+256766529869', $hash]);
    echo "✓ Admin user created (info@hosu.or.ug / {$adminSeedPassword}).\n";
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
        biography    TEXT,
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

// Site Media — admin-uploaded images / content
$pdo->exec("
    CREATE TABLE IF NOT EXISTS site_media (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        title       VARCHAR(255) NOT NULL DEFAULT '',
        description TEXT,
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

echo "– Tables ready. Add all public content via the Admin panel.\n";

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
