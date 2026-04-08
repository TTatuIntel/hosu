<?php
/**
 * HOSU Database Connection Diagnostics
 * ---------------------------------------------------
 * Visit this page ONCE to diagnose and fix the DB issue.
 * DELETE or password-protect this file after use.
 *
 * Usage: https://hosu.or.ug/db_check.php?key=HOSU_DIAG_2026
 */

// Simple access key — prevents public access
if (($_GET['key'] ?? '') !== 'HOSU_DIAG_2026') {
    http_response_code(403);
    die('403 Forbidden. Append ?key=HOSU_DIAG_2026 to the URL.');
}

require_once __DIR__ . '/env.php';

$host   = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'hosuweb_db';
$user   = getenv('DB_USER') ?: 'root';
$pass   = getenv('DB_PASS') ?: '';

header('Content-Type: text/html; charset=utf-8');
$ok   = '&#9989;';  // ✅
$fail = '&#10060;'; // ❌
$warn = '&#9888;';  // ⚠️

function row(string $icon, string $label, string $value, string $note = ''): void {
    $c = $icon === '&#9989;' ? '#15803d' : ($icon === '&#10060;' ? '#dc2626' : '#b45309');
    echo "<tr><td style='padding:8px 12px;font-weight:600;'>{$icon} {$label}</td>"
       . "<td style='padding:8px 12px;font-family:monospace;color:{$c};'>" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . "</td>"
       . "<td style='padding:8px 12px;color:#64748b;font-size:.82rem;'>{$note}</td></tr>";
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HOSU DB Diagnostics</title>
<style>
body{font-family:system-ui,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:2rem;}
h1{color:#0d4593;margin-bottom:.25rem;}
.card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.07);padding:1.5rem 2rem;margin-bottom:1.5rem;max-width:860px;}
table{width:100%;border-collapse:collapse;}
tr{border-bottom:1px solid #f1f5f9;}
tr:last-child{border-bottom:none;}
th{text-align:left;padding:8px 12px;background:#f8fafc;font-size:.8rem;color:#64748b;text-transform:uppercase;letter-spacing:.04em;}
pre{background:#1e293b;color:#86efac;border-radius:8px;padding:1rem 1.2rem;font-size:.83rem;overflow-x:auto;white-space:pre-wrap;word-break:break-all;}
.fix-box{background:#fef2f2;border-left:4px solid #dc2626;border-radius:0 8px 8px 0;padding:.8rem 1rem;margin:.6rem 0;}
.ok-box{background:#f0fdf4;border-left:4px solid #15803d;border-radius:0 8px 8px 0;padding:.8rem 1rem;margin:.6rem 0;}
.warn-box{background:#fffbeb;border-left:4px solid #d97706;border-radius:0 8px 8px 0;padding:.8rem 1rem;margin:.6rem 0;}
code{background:#f1f5f9;padding:.1rem .4rem;border-radius:4px;font-size:.88em;}
</style>
</head>
<body>
<div class="card">
<h1>&#x1F4BB; HOSU Database Diagnostics</h1>
<p style="color:#64748b;font-size:.85rem;">Run this page to diagnose the "Database connection failed" error on your payment pages.</p>

<table>
<thead><tr><th>Check</th><th>Value</th><th>Notes</th></tr></thead>
<tbody>
<?php

// 1. PHP version
$phpVer = PHP_VERSION;
$phpOk  = version_compare($phpVer, '7.4', '>=');
row($phpOk ? $ok : $fail, 'PHP Version', $phpVer, $phpOk ? 'OK' : 'Requires PHP 7.4+');

// 2. PDO extension
$pdoOk = extension_loaded('pdo') && extension_loaded('pdo_mysql');
row($pdoOk ? $ok : $fail, 'PDO + pdo_mysql', $pdoOk ? 'Loaded' : 'MISSING', $pdoOk ? '' : 'Enable pdo_mysql in php.ini');

// 3. .env file
$envPath = __DIR__ . '/.env';
$envExists = file_exists($envPath);
row($envExists ? $ok : $fail, '.env file', $envExists ? 'Found at ' . $envPath : 'NOT FOUND', $envExists ? '' : 'Create .env from the template below');

// 4. Loaded env values (masked password)
row($warn, 'DB_HOST (loaded)', $host ?: '(empty)');
row($warn, 'DB_NAME (loaded)', $dbname ?: '(empty)', empty($dbname) ? 'Set DB_NAME in .env' : '');
row($warn, 'DB_USER (loaded)', $user ?: '(empty)', empty($user) ? 'Set DB_USER in .env' : '');
row($warn, 'DB_PASS (loaded)', empty($pass) ? '(empty)' : str_repeat('*', min(strlen($pass), 8)) . '…', '');

// 5. TCP connection to MySQL host
$tcpOk = false;
$errno = 0; $errstr = '';
try {
    $sock = @fsockopen($host, 3306, $errno, $errstr, 3);
    $tcpOk = ($sock !== false);
    if ($sock) fclose($sock);
} catch (\Throwable $e) { $tcpOk = false; }
row($tcpOk ? $ok : $fail, 'TCP to MySQL :3306', $tcpOk ? "Reachable ($host:3306)" : "UNREACHABLE — $errstr",
    $tcpOk ? '' : 'MySQL is not running, or host/port is wrong');

// 6. Authenticate (no database selected)
$authOk = false; $authErr = '';
try {
    $tmp = new PDO("mysql:host=$host;charset=utf8", $user, $pass);
    $tmp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $authOk = true;
} catch (PDOException $e) {
    $authErr = $e->getMessage();
}
row($authOk ? $ok : $fail, 'Auth (user/password)', $authOk ? "User '$user' authenticated OK" : "FAILED: $authErr",
    $authOk ? '' : 'Wrong username or password for MySQL user');

// 7. Database exists
$dbExists = false; $dbErr = ''; $availableDbs = [];
if ($authOk) {
    try {
        $dbs = $tmp->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        $availableDbs = $dbs;
        $dbExists = in_array($dbname, $dbs);
    } catch (PDOException $e) { $dbErr = $e->getMessage(); }
}
row($dbExists ? $ok : $fail, "Database '$dbname' exists",
    $dbExists ? "Found" : ($dbErr ?: "NOT FOUND"),
    $dbExists ? '' : "Create the database or update DB_NAME in .env");

// 8. User has access to the database
$hasAccess = false;
if ($authOk && $dbExists) {
    try {
        $tmp2 = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $tmp2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $hasAccess = true;
    } catch (PDOException $e) {
        row($fail, 'DB Access', $e->getMessage(), "Grant user '$user' privileges on '$dbname'");
    }
}
if ($authOk && $dbExists) {
    row($hasAccess ? $ok : $fail, "User access to '$dbname'",
        $hasAccess ? "Full access confirmed" : "ACCESS DENIED",
        $hasAccess ? '' : "Run: GRANT ALL ON `$dbname`.* TO '$user'@'localhost';");
}

// 9. Full connection (same as db.php)
$fullOk = false; $fullErr = '';
if ($authOk && $dbExists && $hasAccess) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $fullOk = true;
    } catch (PDOException $e) { $fullErr = $e->getMessage(); }
}
row($fullOk ? $ok : ($authOk ? $fail : $warn), 'Full Connection (db.php test)',
    $fullOk ? 'SUCCESS — db.php will work' : ($fullOk === false && $authOk ? "FAILED: $fullErr" : 'Skipped (auth failed)'));

// 10. Payments table exists
if ($fullOk) {
    try {
        $tbls = $pdo->query("SHOW TABLES LIKE 'payments'")->fetchAll(PDO::FETCH_COLUMN);
        $paytbl = count($tbls) > 0;
        row($paytbl ? $ok : $fail, "Table 'payments' exists",
            $paytbl ? 'Found' : 'MISSING — run setup_db.php',
            $paytbl ? '' : 'Payment features will fail without this table');
    } catch (\Throwable $e) {
        row($fail, "Table check", $e->getMessage());
    }
}

echo '</tbody></table>';

// ── Diagnosis summary ──────────────────────────────────────────────
echo '<hr style="margin:1.5rem 0;border:none;border-top:1px solid #f1f5f9;">';
if ($fullOk) {
    echo '<div class="ok-box"><strong>&#9989; All checks passed.</strong> The database connection is working. '
       . 'The payment error may have been a temporary outage. '
       . 'If it still occurs, check PHP error logs at: <code>/var/log/apache2/error.log</code> or cPanel Error Log.</div>';
} elseif (!$tcpOk) {
    echo '<div class="fix-box"><strong>&#10060; MySQL is not reachable.</strong><br>
    <strong>On cPanel shared hosting:</strong> DB_HOST is almost always <code>localhost</code>.<br>
    <strong>On VPS:</strong> Make sure MySQL is running: <code>sudo systemctl start mysql</code></div>';
} elseif (!$authOk) {
    echo '<div class="fix-box"><strong>&#10060; Authentication failed.</strong><br>
    The username/password in <code>.env</code> is wrong.<br>
    <strong>On cPanel:</strong> Go to cPanel &#8594; MySQL Databases &#8594; verify/reset the user password, then update <code>.env</code>.<br>
    <strong>On XAMPP:</strong> Use <code>DB_USER=root</code> and <code>DB_PASS=</code> (blank).</div>';
} elseif (!$dbExists) {
    echo '<div class="fix-box"><strong>&#10060; Database not found.</strong><br>';
    if (!empty($availableDbs)) {
        echo 'Available databases for this user:<br><code>' . implode('</code>, <code>', array_map('htmlspecialchars', $availableDbs)) . '</code><br><br>';
        echo '<strong>On cPanel hosting</strong>, the database name is prefixed with your cPanel username.<br>'
           . 'Example: if your cPanel username is <code>hosumain</code>, the real DB name would be <code>hosumain_hosu_blog</code>.<br>'
           . 'Update <code>DB_NAME</code> in <code>.env</code> to match, then re-run this page.';
    } else {
        echo 'No databases visible. The user may not have SHOW DATABASES privilege, or no databases exist.<br>'
           . 'Create the database in cPanel &#8594; MySQL Databases, then run <code>setup_db.php</code>.';
    }
    echo '</div>';
} elseif (!$hasAccess) {
    echo '<div class="fix-box"><strong>&#10060; User has no access to the database.</strong><br>
    In cPanel &#8594; MySQL Databases &#8594; scroll to "Add User To Database" &#8594; select the user and database &#8594; grant ALL PRIVILEGES.</div>';
}

echo '</div>';

// ── Fix instructions ───────────────────────────────────────────────
?>
<div class="card">
<h2 style="margin-top:0;">&#x1F527; How to Fix on cPanel Hosting</h2>
<ol style="line-height:1.9;font-size:.9rem;">
<li>Log in to <strong>cPanel</strong> at <code>https://hosu.or.ug:2083</code></li>
<li>Go to <strong>MySQL Databases</strong></li>
<li>Under <em>"Create New Database"</em>, type <code>hosu_blog</code> &rarr; click <strong>Create Database</strong><br>
    <span style="color:#64748b;font-size:.82rem;">cPanel will auto-prefix it: e.g. <code>hosumain_hosu_blog</code></span></li>
<li>Under <em>"MySQL Users"</em>, create user <code>hosu_user</code> with a strong password</li>
<li>Under <em>"Add User To Database"</em>, add <code>hosu_user</code> to <code>hosu_blog</code> with <strong>ALL PRIVILEGES</strong></li>
<li>Update <code>.env</code> with the <strong>full prefixed names</strong>:<br>
<pre>DB_HOST=localhost
DB_NAME=hosumain_hosu_blog   # replace hosumain with your cPanel username
DB_USER=hosumain_hosu_user   # same prefix
DB_PASS=YourChosenPassword</pre></li>
<li>Run <a href="setup_db.php" target="_blank"><strong>setup_db.php</strong></a> to create all tables</li>
<li>Re-run this page to confirm everything is green &#9989;</li>
</ol>
<div class="warn-box">&#9888; <strong>Delete or rename this file</strong> (<code>db_check.php</code>) after fixing the issue. It exposes database name details.</div>
</div>

<div class="card">
<h2 style="margin-top:0;">&#x1F4E6; Correct .env for cPanel (template)</h2>
<p style="font-size:.83rem;color:#64748b;">Copy this to your <code>.env</code> file on the server, replacing the values with your real cPanel ones:</p>
<pre># Database — replace PREFIX_ with your cPanel username + underscore
DB_HOST=localhost
DB_NAME=PREFIX_hosu_blog
DB_USER=PREFIX_hosu_user
DB_PASS=YourStrongPassword

# PesaPal v3 (production)
PESAPAL_CONSUMER_KEY=lk7lFpodKnEq9mJyjwOBrr/9Ot/Lofof
PESAPAL_CONSUMER_SECRET=3JZdXlVouyKIbGu1pdg71cwwi4c=
PESAPAL_ENV=production

# App
APP_ENV=production
APP_DOMAIN=hosu.or.ug</pre>
</div>

<?php if ($authOk && !empty($availableDbs)): ?>
<div class="card">
<h2 style="margin-top:0;">&#x1F4CB; All Databases Visible to '<?= htmlspecialchars($user) ?>'</h2>
<code><?= implode('</code><br><code>', array_map('htmlspecialchars', $availableDbs)) ?></code>
<p style="font-size:.82rem;color:#64748b;margin-top:.8rem;">Update <code>DB_NAME</code> in <code>.env</code> to match the database you want to use.</p>
</div>
<?php endif; ?>

</body>
</html>
