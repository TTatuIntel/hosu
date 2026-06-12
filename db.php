<?php
require_once __DIR__ . '/env.php';

/**
 * Connect to MySQL. Tries localhost and 127.0.0.1 when one fails (common on Ubuntu/XAMPP).
 */
function hosu_connect_pdo(): ?PDO
{
    $dbname   = getenv('DB_NAME') ?: 'hosu_blog';
    $username = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASS') ?: '';
    $configuredHost = getenv('DB_HOST') ?: '127.0.0.1';

    $hosts = array_values(array_unique(array_filter([
        $configuredHost,
        $configuredHost === 'localhost' ? '127.0.0.1' : null,
        $configuredHost === '127.0.0.1' ? 'localhost' : null,
    ])));

    $last = null;
    foreach ($hosts as $host) {
        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            error_log("HOSU DB connect failed ($host / $dbname): " . $e->getMessage());
        }
    }

    error_log('HOSU DB connection failed: ' . ($last ? $last->getMessage() : 'unknown'));
    return null;
}

$softFail = defined('HOSU_DB_SOFT_FAIL') && HOSU_DB_SOFT_FAIL;
$pdo = hosu_connect_pdo();

if (!$pdo instanceof PDO) {
    if (php_sapi_name() === 'cli') {
        die("Database connection failed.\n");
    }
    if ($softFail) {
        $pdo = null;
    } else {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['error' => 'Database connection failed. Please try again later.']));
    }
}
