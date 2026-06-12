<?php
require_once __DIR__ . '/env.php';

/**
 * @return array{0: PDO, 1: string} PDO instance and host that connected
 */
function hosu_create_pdo(): PDO
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
                "mysql:host=$host;dbname=$dbname;charset=utf8",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            return $pdo;
        } catch (PDOException $e) {
            $last = $e;
            error_log("HOSU DB connect failed ($host): " . $e->getMessage());
        }
    }

    throw $last ?? new PDOException('Database connection failed');
}

$pdo = null;
try {
    $pdo = hosu_create_pdo();
} catch (PDOException $e) {
    error_log('HOSU DB connection failed: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        die("Database connection failed.\n");
    }
    if (!defined('HOSU_DB_SOFT_FAIL')) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['error' => 'Database connection failed. Please try again later.']));
    }
}
