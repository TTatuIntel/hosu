<?php
/**
 * HOSU Member Portal — Phase 1 migration
 *
 * Run once (CLI or browser). Idempotent — safe to re-run on live data.
 *
 *   php migrate_member_portal.php
 *
 * SAFETY: This script does NOT truncate, drop, or bulk-delete any existing rows.
 * It only adds missing tables/columns/indexes and fills empty fields on existing
 * members (membership_number, expiry_date, category_id, approval_status).
 *
 * What it does:
 *   1. members: link to users, add expiry_date, approval_status, category_id,
 *      public_profile, verified_at, membership_number, dues_paid_at, country,
 *      specialty, internal_notes.
 *   2. New table: membership_categories (16 reference categories — inserted only if table is empty).
 *   3. New table: member_documents (uploads — license, CV, proof of training).
 *   4. New table: member_audit_notes (admin review comments).
 *   5. New table: renewal_reminders (track which reminders were sent).
 *   6. Backfill: membership_number, expiry_date from existing payments (NULL fields only).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/membership_helpers.php';

function colExists(PDO $pdo, string $table, string $col): bool {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $col]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception $e) { return false; }
}

function addCol(PDO $pdo, string $table, string $col, string $sqlFragment): void {
    if (!colExists($pdo, $table, $col)) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN $sqlFragment");
            echo "  + $table.$col added\n";
        } catch (Exception $e) {
            echo "  ! $table.$col: " . $e->getMessage() . "\n";
        }
    } else {
        echo "  = $table.$col already present\n";
    }
}

$isCli = php_sapi_name() === 'cli';
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');

echo "HOSU Member Portal migration\n";
echo "============================\n";
echo "SAFE MODE: no tables truncated, no rows deleted.\n\n";

// ─────────────────────────────────────────────────────────────────────
// 1. membership_categories (the 16 categories from the Improvement Plan)
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS membership_categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(60)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    discipline  VARCHAR(60)  NOT NULL DEFAULT 'general',
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table membership_categories\n";

$count = (int)$pdo->query("SELECT COUNT(*) FROM membership_categories")->fetchColumn();
if ($count === 0) {
    $categories = [
        ['hematologist',          'Hematologist',                  'hematology',       1],
        ['medical-oncologist',    'Medical Oncologist',            'medical-oncology', 2],
        ['pediatric-oncologist',  'Pediatric Oncologist',          'pediatric',        3],
        ['surgical-oncologist',   'Surgical Oncologist',           'surgical',         4],
        ['radiation-oncologist',  'Radiation Oncologist',          'radiation',        5],
        ['pathologist',           'Pathologist',                   'pathology',        6],
        ['cancer-biologist',      'Cancer Biologist',              'research',         7],
        ['pharmacist',            'Pharmacist',                    'pharmacy',         8],
        ['oncology-nurse',        'Oncology Nurse',                'nursing',          9],
        ['gynecology-oncologist', 'Gynecology Oncologist',         'medical-oncology', 10],
        ['medical-physicist',     'Medical Physicist',             'radiation',        11],
        ['radiologist',           'Radiologist',                   'radiation',        12],
        ['anesthesiologist',      'Anesthesiologist',              'surgical',         13],
        ['researcher',            'Researcher',                    'research',         14],
        ['palliative-care',       'Palliative Care Specialist',    'palliative',       15],
        ['civil-society',         'Civil Society Organization',    'cso',              16],
    ];
    $ins = $pdo->prepare("INSERT INTO membership_categories (slug, name, discipline, sort_order) VALUES (?,?,?,?)");
    foreach ($categories as $c) $ins->execute($c);
    echo "[✓] inserted 16 membership categories (table was empty)\n";
} else {
    echo "[=] membership_categories already populated ($count rows)\n";
}

// ─────────────────────────────────────────────────────────────────────
// 2. members table — extensions
// ─────────────────────────────────────────────────────────────────────
echo "\nExtending members table:\n";
addCol($pdo, 'members', 'user_id',            "user_id INT NULL DEFAULT NULL AFTER id");
addCol($pdo, 'members', 'category_id',        "category_id INT NULL DEFAULT NULL AFTER membership_type");
addCol($pdo, 'members', 'membership_number',  "membership_number VARCHAR(40) NULL DEFAULT NULL AFTER category_id");
addCol($pdo, 'members', 'country',            "country VARCHAR(80) NOT NULL DEFAULT 'Uganda' AFTER phone");
addCol($pdo, 'members', 'specialty',          "specialty VARCHAR(100) NOT NULL DEFAULT '' AFTER profession");
addCol($pdo, 'members', 'approval_status',    "approval_status ENUM('pending','approved','needs_correction','rejected') NOT NULL DEFAULT 'pending' AFTER status");
addCol($pdo, 'members', 'expiry_date',        "expiry_date DATE NULL DEFAULT NULL AFTER approval_status");
addCol($pdo, 'members', 'public_profile',     "public_profile TINYINT(1) NOT NULL DEFAULT 0 AFTER expiry_date");
addCol($pdo, 'members', 'verified_at',        "verified_at DATETIME NULL DEFAULT NULL AFTER public_profile");
addCol($pdo, 'members', 'verified_by',        "verified_by INT NULL DEFAULT NULL AFTER verified_at");
addCol($pdo, 'members', 'dues_paid_at',       "dues_paid_at DATETIME NULL DEFAULT NULL AFTER verified_by");
addCol($pdo, 'members', 'internal_notes',     "internal_notes TEXT NULL AFTER dues_paid_at");
addCol($pdo, 'members', 'committee',          "committee VARCHAR(150) NOT NULL DEFAULT '' AFTER internal_notes");
addCol($pdo, 'members', 'cpd_points',         "cpd_points INT NOT NULL DEFAULT 0 AFTER committee");
addCol($pdo, 'members', 'updated_at',         "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");

// Helpful indexes (idempotent — wrap each)
$idx = [
    'idx_members_user'       => "CREATE INDEX idx_members_user ON members(user_id)",
    'idx_members_category'   => "CREATE INDEX idx_members_category ON members(category_id)",
    'idx_members_expiry'     => "CREATE INDEX idx_members_expiry ON members(expiry_date)",
    'idx_members_approval'   => "CREATE INDEX idx_members_approval ON members(approval_status)",
    'idx_members_memnum'     => "CREATE UNIQUE INDEX idx_members_memnum ON members(membership_number)",
];
foreach ($idx as $name => $sql) {
    try { $pdo->exec($sql); echo "  + index $name\n"; } catch (Exception $e) { echo "  = index $name (already exists)\n"; }
}

// ─────────────────────────────────────────────────────────────────────
// 3. member_documents — uploads (license, CV, proof of training, etc.)
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS member_documents (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    member_id    INT          NOT NULL,
    doc_type     VARCHAR(40)  NOT NULL DEFAULT 'other',
    file_path    VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL DEFAULT '',
    file_size    INT          NOT NULL DEFAULT 0,
    mime_type    VARCHAR(120) NOT NULL DEFAULT '',
    verified     TINYINT(1)   NOT NULL DEFAULT 0,
    verified_by  INT          NULL DEFAULT NULL,
    verified_at  DATETIME     NULL DEFAULT NULL,
    notes        TEXT,
    uploaded_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_md_member (member_id),
    INDEX idx_md_type (doc_type),
    CONSTRAINT fk_md_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table member_documents\n";

// ─────────────────────────────────────────────────────────────────────
// 4. member_audit_notes — admin comments during review
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS member_audit_notes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    member_id   INT          NOT NULL,
    admin_id    INT          NULL,
    action      VARCHAR(60)  NOT NULL DEFAULT 'note',
    note        TEXT,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_man_member (member_id),
    CONSTRAINT fk_man_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table member_audit_notes\n";

// ─────────────────────────────────────────────────────────────────────
// 5. renewal_reminders — track sent reminders so we don't spam
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS renewal_reminders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    member_id   INT          NOT NULL,
    reminder_kind VARCHAR(30) NOT NULL DEFAULT 't_minus_30',
    sent_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rr_member (member_id),
    INDEX idx_rr_kind (reminder_kind),
    CONSTRAINT fk_rr_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table renewal_reminders\n";

// ─────────────────────────────────────────────────────────────────────
// 6. Backfill: membership_number + expiry_date for existing members
// ─────────────────────────────────────────────────────────────────────
echo "\nBackfilling membership numbers + expiry dates:\n";

// (a) membership_number for any member missing one
$rows = $pdo->query("SELECT id, created_at FROM members WHERE membership_number IS NULL OR membership_number = ''")->fetchAll();
$upd = $pdo->prepare("UPDATE members SET membership_number = ? WHERE id = ?");
foreach ($rows as $r) {
    $num = hosuMembershipNumber((int)$r['id'], $r['created_at']);
    try { $upd->execute([$num, $r['id']]); } catch (Exception $e) {}
}
echo "  + " . count($rows) . " membership numbers backfilled\n";

// (b) expiry_date from latest verified membership payment, applying calendar-year-end rule
$expRows = $pdo->query("
    SELECT m.id AS member_id, m.created_at,
           (SELECT p.membership_period FROM payments p
              WHERE p.member_id = m.id AND p.payment_type = 'membership'
              ORDER BY p.paid_at DESC LIMIT 1) AS period,
           (SELECT p.paid_at FROM payments p
              WHERE p.member_id = m.id AND p.payment_type = 'membership' AND p.status = 'verified'
              ORDER BY p.paid_at DESC LIMIT 1) AS paid_at
    FROM members m
    WHERE m.expiry_date IS NULL
")->fetchAll();
$updExp = $pdo->prepare("UPDATE members SET expiry_date = ?, dues_paid_at = ? WHERE id = ?");
$filled = 0;
foreach ($expRows as $r) {
    if (!$r['period'] || !$r['paid_at']) continue;
    $exp = hosuMembershipExpiry($r['period'], $r['paid_at']);
    if ($exp) {
        $updExp->execute([$exp, $r['paid_at'], $r['member_id']]);
        $filled++;
    }
}
echo "  + $filled expiry dates backfilled (calendar-year-end rule)\n";

// (c) approval_status: existing 'active' members are treated as approved
$pdo->exec("UPDATE members SET approval_status = 'approved' WHERE status = 'active' AND approval_status = 'pending'");

// (d) category_id from the legacy profession slug
$catMap = $pdo->query("SELECT slug, id FROM membership_categories")->fetchAll(PDO::FETCH_KEY_PAIR);
$updCat = $pdo->prepare("UPDATE members SET category_id = ? WHERE id = ? AND (category_id IS NULL)");
$catRows = $pdo->query("SELECT id, profession FROM members WHERE category_id IS NULL AND profession <> ''")->fetchAll();
$mapped = 0;
foreach ($catRows as $r) {
    $slug = strtolower(trim($r['profession']));
    if (isset($catMap[$slug])) { $updCat->execute([$catMap[$slug], $r['id']]); $mapped++; }
}
echo "  + $mapped members mapped to a category\n";

// ─────────────────────────────────────────────────────────────────────
// 6b. committees + committee_members (Phase 2 — working groups)
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS committees (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(80)  NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    discipline  VARCHAR(60)  NOT NULL DEFAULT 'general',
    sort_order  INT          NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table committees\n";

$pdo->exec("CREATE TABLE IF NOT EXISTS committee_members (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    committee_id INT NOT NULL,
    member_id    INT NOT NULL,
    role         VARCHAR(40) NOT NULL DEFAULT 'member',
    joined_at    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_committee_member (committee_id, member_id),
    INDEX idx_cm_member (member_id),
    CONSTRAINT fk_cm_committee FOREIGN KEY (committee_id) REFERENCES committees(id) ON DELETE CASCADE,
    CONSTRAINT fk_cm_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table committee_members\n";

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM committees")->fetchColumn();
if ($cnt === 0) {
    $pdo->exec("INSERT INTO committees (slug, name, description, discipline, sort_order) VALUES
        ('breast-cancer','Breast Cancer Working Group','Clinical, research and advocacy work on breast cancer in Uganda.','medical-oncology',1),
        ('pediatric-oncology','Pediatric Oncology Group','Care pathways, training and family support for children with cancer.','pediatric',2),
        ('hematology','Hematology Group','Sickle cell, leukemia, lymphoma and benign hematology in Uganda.','hematology',3),
        ('palliative-care','Palliative Care Group','Symptom control, dignity in care, community palliation.','palliative',4)");
    echo "[✓] seeded 4 default working groups\n";
}

// ─────────────────────────────────────────────────────────────────────
// 6c. cpd_entries (Phase 3 — CPD points accrual)
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS cpd_entries (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    member_id     INT NOT NULL,
    activity      VARCHAR(200) NOT NULL,
    points        INT NOT NULL DEFAULT 0,
    activity_date DATE NULL,
    source        VARCHAR(40) NOT NULL DEFAULT 'manual',
    awarded_by    INT NULL,
    awarded_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cpd_member (member_id),
    CONSTRAINT fk_cpd_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8");
echo "[✓] table cpd_entries\n";

// ─────────────────────────────────────────────────────────────────────
// 7. site_stats: live counter for active members (read by About page)
// ─────────────────────────────────────────────────────────────────────
$pdo->exec("INSERT INTO site_stats (stat_key, stat_value, stat_label, page, sort_order, is_active)
    VALUES ('live_active_members', '0', 'Active Members', 'about', 0, 1)
    ON DUPLICATE KEY UPDATE stat_label = stat_label");
echo "[✓] site_stats slot for live_active_members ensured\n";

echo "\nDONE.\n";
