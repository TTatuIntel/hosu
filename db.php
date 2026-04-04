<?php
require_once __DIR__ . '/env.php';

$host     = getenv('DB_HOST') ?: 'localhost';
$dbname   = getenv('DB_NAME') ?: 'hosu_blog';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch (PDOException $e) {
    error_log('HOSU DB connection failed: ' . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        die("Database connection failed.\n");
    }
    http_response_code(503);
    die(json_encode(['error' => 'Database connection failed. Please try again later.']));
}
?>