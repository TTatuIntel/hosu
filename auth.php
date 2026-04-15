<?php
// ── Secure session configuration ──
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.gc_maxlifetime', '3600'); // 1 hour hard limit
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
session_start();

// ── Security headers — prevent caching of admin pages ──
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require 'db.php';
require_once 'mailer.php';

// ── Brute-force protection constants ──
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes

// ── Session idle timeout (30 min) ──
define('SESSION_IDLE_TIMEOUT', 1800);

function checkSessionExpiry(): bool {
    if (!empty($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) {
            // Session expired — destroy it
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            return false;
        }
    }
    // Refresh activity timestamp
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    return true;
}

// ── Session fingerprint validation ──
function getSessionFingerprint(): string {
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

function validateFingerprint(): bool {
    if (!empty($_SESSION['fingerprint'])) {
        if (!hash_equals($_SESSION['fingerprint'], getSessionFingerprint())) {
            return false;
        }
    }
    // Also validate IP hasn't changed (prevents session hijacking from different network)
    if (!empty($_SESSION['ip_address']) && !empty($_SERVER['REMOTE_ADDR'])) {
        if ($_SESSION['ip_address'] !== $_SERVER['REMOTE_ADDR']) {
            return false;
        }
    }
    return true;
}

// Run expiry + fingerprint check on every request
if (!checkSessionExpiry() || !validateFingerprint()) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($action !== 'login' && $action !== 'setup' && $action !== 'request_reset' && $action !== 'reset_password' && $action !== 'verify_reset_token') {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired', 'expired' => true]);
        exit;
    }
}

// --- CSRF Token Helpers ---
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- Ensure users table exists ---
function ensureUsersTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            phone VARCHAR(30) DEFAULT '',
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','member') NOT NULL DEFAULT 'member',
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            failed_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME DEFAULT NULL,
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");
    // Safe migration: add columns if they don't exist
    foreach (['phone VARCHAR(30) DEFAULT \'\'', 'is_locked TINYINT(1) NOT NULL DEFAULT 0', 'failed_attempts INT NOT NULL DEFAULT 0', 'locked_until DATETIME DEFAULT NULL', 'last_login DATETIME DEFAULT NULL', 'must_change_password TINYINT(1) NOT NULL DEFAULT 0'] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try { $pdo->exec("ALTER TABLE users ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (\Exception $e) {}
    }

    // Login attempts tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            identity VARCHAR(100) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_ip (ip_address),
            INDEX idx_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    // Password reset tokens table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            reset_code VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    // Cleanup expired reset tokens
    try { $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW()"); } catch (\Exception $e) {}
    // Cleanup old login attempts (older than 24h)
    try { $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"); } catch (\Exception $e) {}
}

// --- Seed default admin if none exists ---
function seedAdmin(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $adminSeedPassword = getenv('ADMIN_SEED_PASSWORD') ?: 'Admin@hosu2026';
        $hash = password_hash($adminSeedPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, phone, password, role, must_change_password) VALUES (?, ?, ?, ?, 'admin', 1)");
        $stmt->execute(['admin', 'info@hosu.or.ug', '+256766529869', $hash]);
    } else {
        // Ensure seed admin always has must_change_password = 1 (it's a gateway, not a personal account)
        try {
            $pdo->prepare("UPDATE users SET must_change_password = 1 WHERE username = 'admin'")->execute();
        } catch (\Exception $e) {}
    }
}

// --- Brute-force protection ---
function isIPLocked(PDO $pdo, string $ip): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE ip_address = ? AND success = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, LOCKOUT_DURATION]);
    return (int) $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

function isAccountLocked(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT is_locked, locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if ($user['is_locked'] && $user['locked_until']) {
        if (new \DateTime($user['locked_until']) > new \DateTime()) {
            return true;
        }
        // Lockout expired — unlock
        $pdo->prepare("UPDATE users SET is_locked = 0, failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$userId]);
    }
    return false;
}

function recordLoginAttempt(PDO $pdo, string $ip, string $identity, bool $success): void {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, identity, success) VALUES (?, ?, ?)");
    $stmt->execute([$ip, $identity, $success ? 1 : 0]);
}

function incrementFailedAttempts(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn();
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockUntil = (new \DateTime())->modify('+' . LOCKOUT_DURATION . ' seconds')->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE users SET is_locked = 1, locked_until = ? WHERE id = ?")->execute([$lockUntil, $userId]);
    }
}

function clearFailedAttempts(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET failed_attempts = 0, is_locked = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")->execute([$userId]);
}

function getRemainingLockoutSeconds(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $until = $stmt->fetchColumn();
    if (!$until) return 0;
    $diff = (new \DateTime($until))->getTimestamp() - time();
    return max(0, $diff);
}

function getIPRemainingLockout(PDO $pdo, string $ip): int {
    $stmt = $pdo->prepare("
        SELECT attempted_at FROM login_attempts
        WHERE ip_address = ? AND success = 0
        ORDER BY attempted_at ASC
        LIMIT 1 OFFSET " . (MAX_LOGIN_ATTEMPTS - 1) . "
    ");
    // Get the Nth failed attempt time
    $stmt2 = $pdo->prepare("
        SELECT MIN(attempted_at) as first_fail FROM (
            SELECT attempted_at FROM login_attempts
            WHERE ip_address = ? AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY attempted_at ASC
            LIMIT " . MAX_LOGIN_ATTEMPTS . "
        ) as recent
    ");
    $stmt2->execute([$ip, LOCKOUT_DURATION]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['first_fail']) return LOCKOUT_DURATION;
    $unlockAt = (new \DateTime($row['first_fail']))->modify('+' . LOCKOUT_DURATION . ' seconds');
    return max(0, $unlockAt->getTimestamp() - time());
}

// --- Auth guard (include this in protected pages) ---
function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        header('Location: login.html');
        exit;
    }
}

function requireAdmin(): void {
    requireAuth();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
}

// --- Run setup on first load ---
ensureUsersTable($pdo);
seedAdmin($pdo);

// --- Route actions ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }

        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($identity === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username/email and password are required']);
            exit;
        }

        // ── Check IP-level lockout ──
        if (isIPLocked($pdo, $clientIP)) {
            $remaining = getIPRemainingLockout($pdo, $clientIP);
            http_response_code(429);
            echo json_encode([
                'error' => 'Too many failed attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).',
                'locked' => true,
                'retry_after' => $remaining
            ]);
            exit;
        }

        // Look up user by username or email
        $stmt = $pdo->prepare("SELECT id, username, email, password, role, is_locked, locked_until, failed_attempts, must_change_password FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Seed/gateway admin auto-unlock: always allow login attempts ──
        if ($user && $user['username'] === 'admin' && $user['must_change_password']) {
            if ($user['is_locked']) {
                $pdo->prepare("UPDATE users SET is_locked = 0, failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);
                $user['is_locked'] = 0;
                $user['failed_attempts'] = 0;
                $user['locked_until'] = null;
            }
        }

        // ── Check account-level lockout (non-seed accounts) ──
        if ($user && isAccountLocked($pdo, $user['id'])) {
            $remaining = getRemainingLockoutSeconds($pdo, $user['id']);
            recordLoginAttempt($pdo, $clientIP, $identity, false);
            http_response_code(429);
            echo json_encode([
                'error' => 'Account locked due to too many failed attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).',
                'locked' => true,
                'retry_after' => $remaining
            ]);
            exit;
        }

        if (!$user || !password_verify($password, $user['password'])) {
            // Record failed attempt
            recordLoginAttempt($pdo, $clientIP, $identity, false);
            if ($user) {
                incrementFailedAttempts($pdo, $user['id']);
                $attemptsLeft = MAX_LOGIN_ATTEMPTS - ((int)$user['failed_attempts'] + 1);
                if ($attemptsLeft <= 0) {
                    http_response_code(429);
                    echo json_encode([
                        'error' => 'Account locked due to too many failed attempts. Try again in 15 minutes.',
                        'locked' => true,
                        'retry_after' => LOCKOUT_DURATION
                    ]);
                    exit;
                }
                http_response_code(401);
                echo json_encode([
                    'error' => 'Invalid credentials. ' . max(0, $attemptsLeft) . ' attempt(s) remaining.',
                    'attempts_left' => max(0, $attemptsLeft)
                ]);
            } else {
                // Don't reveal whether user exists — use generic timing
                usleep(random_int(100000, 300000)); // 100-300ms delay
                http_response_code(401);
                echo json_encode(['error' => 'Invalid credentials']);
            }
            exit;
        }

        // ── Successful login: clear lockout, record success ──
        clearFailedAttempts($pdo, $user['id']);
        recordLoginAttempt($pdo, $clientIP, $identity, true);

        // Rehash password if needed (cost upgrade)
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        $_SESSION['fingerprint'] = getSessionFingerprint();
        $_SESSION['ip_address'] = $clientIP;

        echo json_encode([
            'success' => true,
            'user' => [
                'username' => $user['username'],
                'role' => $user['role'],
            ],
            'csrf_token' => $_SESSION['csrf_token'],
            'redirect' => $user['role'] === 'admin' ? 'admin.html' : 'index.html',
            'idle_timeout' => SESSION_IDLE_TIMEOUT,
            'must_change_password' => (bool)($user['must_change_password'] ?? false),
            // Seed account detection: if this is the default "admin" gateway account,
            // the user must create their own personal admin account before proceeding.
            'must_create_account' => ($user['username'] === 'admin' && !empty($user['must_change_password']))
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'check_session':
        if (!empty($_SESSION['user_id'])) {
            $mustChange = false;
            $isSeed = false;
            try {
                $stmt = $pdo->prepare("SELECT username, must_change_password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $mustChange = (bool)(int)($row['must_change_password'] ?? 0);
                $isSeed = ($row['username'] === 'admin' && $mustChange);
            } catch (\Exception $e) {}
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['user_role'],
                ],
                'csrf_token' => generateCsrfToken(),
                'idle_timeout' => SESSION_IDLE_TIMEOUT,
                'must_change_password' => $mustChange,
                'must_create_account' => $isSeed
            ]);
        } else {
            echo json_encode(['logged_in' => false, 'expired' => true]);
        }
        break;

    case 'heartbeat':
        // Lightweight keep-alive — only refreshes last_activity
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
            $mustChange = false;
            try {
                $stmt = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $mustChange = (bool)(int)($stmt->fetchColumn() ?: 0);
            } catch (\Exception $e) {}
            echo json_encode([
                'success'  => true,
                'alive'    => true,
                'username' => $_SESSION['username'] ?? 'Admin',
                'role'     => $_SESSION['user_role'] ?? 'member',
                'must_change_password' => $mustChange
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'alive' => false, 'expired' => true]);
        }
        break;

    case 'setup':
        // One-time setup endpoint — creates tables and seeds admin
        echo json_encode(['success' => true, 'message' => 'Database setup complete. Default admin created.']);
        break;

    // ── Password Reset: Request ──
    case 'request_reset':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($email === '' && $phone === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Please provide your email or phone number']);
            exit;
        }

        // Rate limit: max 10 reset requests globally per hour
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() >= 10) {
                http_response_code(429);
                echo json_encode(['error' => 'Too many reset requests. Please try again later.']);
                exit;
            }
        } catch (\Exception $e) {}

        // Find user by email, phone, or username
        $user = null;
        if ($email !== '') {
            // Try email first, then as username
            $stmt = $pdo->prepare("SELECT id, username, email, phone, role FROM users WHERE (email = ? OR username = ?) LIMIT 1");
            $stmt->execute([$email, $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user && $phone !== '') {
            $stmt = $pdo->prepare("SELECT id, username, email, phone, role FROM users WHERE phone = ? LIMIT 1");
            $stmt->execute([$phone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // User not found — inform clearly so they can correct their input
        if (!$user) {
            usleep(random_int(200000, 500000)); // timing padding
            echo json_encode([
                'success' => false,
                'error' => 'No account found with that email or username. Please check and try again.'
            ]);
            exit;
        }

        // ── Block password reset for the seed/default admin account ──
        if ($user['username'] === 'admin') {
            echo json_encode([
                'success' => false,
                'error' => 'The default admin password cannot be reset. Please log in with the admin credentials and create your own personal admin account, or contact the developer.'
            ]);
            exit;
        }

        // User found but no email on file — can't send reset
        if (empty($user['email'])) {
            echo json_encode([
                'success' => false,
                'error' => 'No email address on file for this account. Please contact support at info@hosu.or.ug.'
            ]);
            exit;
        }

        // Generate reset token and 6-digit code
        $token = bin2hex(random_bytes(32));
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTime())->modify('+15 minutes')->format('Y-m-d H:i:s');

        // Invalidate any existing tokens for this user
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")->execute([$user['id']]);

        // Insert new reset token
        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, reset_code, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $token, $code, $expiresAt]);

        $maskedEmail = '';
        if ($user['email']) {
            $parts = explode('@', $user['email']);
            $maskedEmail = substr($parts[0], 0, 2) . '***@' . $parts[1];
        }
        $maskedPhone = '';
        if (!empty($user['phone'])) {
            $maskedPhone = substr($user['phone'], 0, 4) . '****' . substr($user['phone'], -3);
        }

        // Send reset email to the USER's own email address
        $resetRecipient = $user['email'];
        $mailSent = false;

        // Build reset link URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'hosu.or.ug';
        $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $resetUrl = $protocol . '://' . $host . $basePath . '/reset-password.html?token=' . urlencode($token);

        $subject = 'HOSU Password Reset';
        $safeName = htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeExpiry = htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');
        $safeResetUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $htmlBody = "
            <div style=\"max-width:500px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#333;\">
                <div style=\"background:linear-gradient(135deg,#0d4593,#1a6dd4);padding:1.2rem;text-align:center;border-radius:10px 10px 0 0;\">
                    <h2 style=\"color:#fff;margin:0;font-size:1.2rem;\">&#128274; Password Reset</h2>
                    <p style=\"color:rgba(255,255,255,0.8);margin:0.3rem 0 0;font-size:0.85rem;\">Hematology &amp; Oncology Society of Uganda</p>
                </div>
                <div style=\"padding:1.5rem;background:#fff;border:1px solid #e5e7eb;border-top:none;\">
                    <p>Hello <strong>{$safeName}</strong>,</p>
                    <p>We received a password reset request for your HOSU account. Use either option below to reset your password:</p>

                    <div style=\"text-align:center;margin:1.5rem 0;\">
                        <a href=\"{$safeResetUrl}\" style=\"display:inline-block;padding:0.75rem 2rem;background:#e63946;color:#fff;font-weight:700;font-size:1rem;border-radius:8px;text-decoration:none;\">Reset My Password</a>
                    </div>

                    <p style=\"text-align:center;color:#6b7280;font-size:0.85rem;\">Or enter this code manually:</p>
                    <div style=\"text-align:center;margin:1rem 0;\">
                        <span style=\"display:inline-block;font-size:28px;font-weight:800;letter-spacing:8px;color:#0d4593;background:#f0f4ff;padding:0.6rem 1.5rem;border-radius:8px;border:2px dashed #0d4593;\">{$safeCode}</span>
                    </div>

                    <p style=\"font-size:0.82rem;color:#6b7280;text-align:center;\">This link and code expire at <strong>{$safeExpiry}</strong> (15 minutes).</p>
                    <hr style=\"border:none;border-top:1px solid #e5e7eb;margin:1.2rem 0;\">
                    <p style=\"font-size:0.78rem;color:#9ca3af;\">If you did not request this reset, please ignore this email. Your password will remain unchanged.</p>
                </div>
                <div style=\"background:#f9fafb;padding:0.8rem;text-align:center;border-radius:0 0 10px 10px;border:1px solid #e5e7eb;border-top:none;\">
                    <p style=\"font-size:0.7rem;color:#9ca3af;margin:0;\">&copy; " . date('Y') . " HOSU &mdash; hosu.or.ug</p>
                </div>
            </div>";
        $mailSent = hosuMail($resetRecipient, $subject, $htmlBody, 'HOSU');

        // Also notify admin inbox for audit
        $adminSubject = 'HOSU: Password Reset Requested';
        $adminBody = "<p>A password reset was requested for account: <strong>{$safeName}</strong> ({$user['email']})</p>"
            . "<p>Reset sent to user's email at " . date('Y-m-d H:i:s') . ".</p>"
            . "<p style=\"font-size:0.8rem;color:#9ca3af;\">This is an audit notification. No action needed.</p>";
        hosuMail('info@hosu.or.ug', $adminSubject, $adminBody, 'HOSU System');

        if ($mailSent) {
            echo json_encode([
                'success' => true,
                'message' => 'A password reset link has been sent to ' . $maskedEmail . '. Check your inbox (and spam folder). Valid for 15 minutes.',
                'token' => $token,
                'masked_email' => $maskedEmail,
                'delivery' => 'email'
            ]);
        } else {
            // Email failed — do NOT expose the token; user cannot proceed
            echo json_encode([
                'success' => false,
                'error' => 'We could not send the reset email right now. Please try again later or contact support at info@hosu.or.ug.'
            ]);
        }
        break;

    // ── Password Reset: Verify token is still valid (for reset-password.html page) ──
    case 'verify_reset_token':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $token = trim($_POST['token'] ?? '');
        if ($token === '') {
            echo json_encode(['valid' => false]);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT id FROM password_resets
                WHERE token = ? AND used = 0 AND expires_at > NOW()
                LIMIT 1
            ");
            $stmt->execute([$token]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['valid' => !!$exists]);
        } catch (\Exception $e) {
            echo json_encode(['valid' => false]);
        }
        break;

    // ── Password Reset: Verify code and set new password ──
    case 'reset_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $token = trim($_POST['token'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if ($token === '' || $code === '' || $newPassword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Token, code, and new password are required']);
            exit;
        }

        // Validate password strength
        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }
        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must contain uppercase, lowercase, and a number']);
            exit;
        }

        // Find valid token
        $stmt = $pdo->prepare("
            SELECT pr.*, u.id as uid, u.username FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired reset token. Please request a new one.']);
            exit;
        }

        // Verify code (timing-safe comparison)
        if (!hash_equals($reset['reset_code'], $code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid reset code']);
            exit;
        }

        // ── Block password reset for the seed/default admin account ──
        if ($reset['username'] === 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'The default admin password cannot be reset through this form. Contact the developer.']);
            exit;
        }

        // Update password and clear must_change_password flag
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0, failed_attempts = 0, is_locked = 0, locked_until = NULL WHERE id = ?")->execute([$hash, $reset['uid']]);

        // Mark token as used
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);

        // Destroy any existing sessions for this user (force re-login)
        // (Can't target specific sessions in file-based storage, but the password change will invalidate next check)

        echo json_encode([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.'
        ]);
        break;

    // ── Admin: Change password (while logged in) ──
    case 'change_password':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }

        // ── Block password change for the seed/default admin account ──
        // Only the developer can change this password directly in the database.
        $seedGuard = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $seedGuard->execute([$_SESSION['user_id']]);
        $seedGuardRow = $seedGuard->fetch(PDO::FETCH_ASSOC);
        if ($seedGuardRow && $seedGuardRow['username'] === 'admin') {
            http_response_code(403);
            echo json_encode(['error' => 'The default admin password cannot be changed here. Please create your own admin account instead, or contact the developer to change it directly in the database.']);
            exit;
        }

        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $csrf = $_POST['csrf_token'] ?? '';

        if (!verifyCsrfToken($csrf)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid security token']);
            exit;
        }

        if ($current === '' || $newPass === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Current and new password are required']);
            exit;
        }
        if (strlen($newPass) < 8 || !preg_match('/[A-Z]/', $newPass) || !preg_match('/[a-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
            http_response_code(400);
            echo json_encode(['error' => 'New password must be 8+ chars with uppercase, lowercase, and a number']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect']);
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);

        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        break;

    // ── Admin: Get login history ──
    case 'login_history':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT ip_address, identity, attempted_at,
                   CASE WHEN success = 1 THEN 'success' ELSE 'failed' END as status
            FROM login_attempts
            WHERE identity IN (
                SELECT username FROM users WHERE id = ?
                UNION SELECT email FROM users WHERE id = ?
            )
            ORDER BY attempted_at DESC LIMIT 20
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ── Create personal admin account (from seed/default admin gateway) ──
    case 'create_admin_account':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        // Must be logged in as the seed admin account
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'You must be logged in with the default admin credentials']);
            exit;
        }
        // Verify caller is actually the seed admin
        $seedCheck = $pdo->prepare("SELECT id, username, must_change_password FROM users WHERE id = ?");
        $seedCheck->execute([$_SESSION['user_id']]);
        $seedUser = $seedCheck->fetch(PDO::FETCH_ASSOC);
        if (!$seedUser || $seedUser['username'] !== 'admin' || !$seedUser['must_change_password']) {
            http_response_code(403);
            echo json_encode(['error' => 'This action is only available when logged in with the default admin account']);
            exit;
        }

        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        // Validate required fields
        if ($fullName === '' || $email === '' || $newPassword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Full name, email, and password are required']);
            exit;
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Please enter a valid email address']);
            exit;
        }

        // Validate password strength
        if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be 8+ characters with uppercase, lowercase, and a number']);
            exit;
        }

        // Generate username from email (part before @)
        $username = preg_replace('/[^a-z0-9_]/', '', strtolower(explode('@', $email)[0]));
        if (strlen($username) < 3) $username = 'admin_' . $username;

        // Check if email or derived username already exists
        $dupeCheck = $pdo->prepare("SELECT id, email FROM users WHERE email = ? OR username = ?");
        $dupeCheck->execute([$email, $username]);
        $existing = $dupeCheck->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['email'] === $email) {
                http_response_code(409);
                echo json_encode(['error' => 'An account with this email already exists. Please log in with your email and password instead.']);
            } else {
                // Username collision — add random suffix
                $username = $username . '_' . random_int(100, 999);
                $dupeCheck2 = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $dupeCheck2->execute([$username]);
                if ($dupeCheck2->fetch()) {
                    $username = $username . random_int(10, 99);
                }
            }
            if (isset($existing) && $existing['email'] === $email) exit;
        }

        // Create the new admin account
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, phone, password, role, must_change_password) VALUES (?, ?, ?, ?, 'admin', 0)");
            $stmt->execute([$username, $email, $phone, $hash]);
            $newUserId = (int)$pdo->lastInsertId();

            // Update members table if full_name provided (link admin to a member profile)
            try {
                $memberCheck = $pdo->prepare("SELECT id FROM members WHERE email = ?");
                $memberCheck->execute([$email]);
                if (!$memberCheck->fetch()) {
                    $pdo->prepare("INSERT INTO members (full_name, email, phone, membership_type, status) VALUES (?, ?, ?, 'admin', 'active')")
                        ->execute([$fullName, $email, $phone]);
                }
            } catch (\Exception $e) { /* members table may not exist yet - ignore */ }

            // Switch session to the new account
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newUserId;
            $_SESSION['username'] = $username;
            $_SESSION['user_role'] = 'admin';
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['last_activity'] = time();
            $_SESSION['login_time'] = time();
            $_SESSION['fingerprint'] = getSessionFingerprint();
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

            // Update last_login on new account
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$newUserId]);

            echo json_encode([
                'success' => true,
                'message' => 'Your admin account has been created successfully!',
                'user' => [
                    'username' => $username,
                    'role' => 'admin',
                ],
                'csrf_token' => $_SESSION['csrf_token']
            ]);
        } catch (PDOException $e) {
            error_log('Auth create_admin_account: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Failed to create account. Please try again.']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
