<?php
/**
 * Site-wide chrome (navbar, footer, donate, SEO) for instant render on all pages.
 * Usage: <script src="site_bootstrap.php"></script>
 */
declare(strict_types=1);

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: private, max-age=30');

require_once __DIR__ . '/event_helpers.php';

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$chrome = defaultSiteChrome();

try {
    if (!defined('HOSU_DB_SOFT_FAIL')) {
        define('HOSU_DB_SOFT_FAIL', true);
    }
    require_once __DIR__ . '/db.php';

    if ($pdo instanceof PDO) {
        $chrome = fetchSiteChromePayload($pdo);
    }
} catch (Throwable $e) {
    error_log('site_bootstrap: ' . $e->getMessage());
}

echo 'window.__HOSU_SITE_CHROME=' . json_encode(['success' => true, 'chrome' => $chrome], $flags) . ';';
if (!empty($chrome['carousel']['interval_ms'])) {
    echo 'window.HOSU_CAROUSEL_INTERVAL=' . (int) $chrome['carousel']['interval_ms'] . ';';
}
