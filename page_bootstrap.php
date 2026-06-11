<?php
/**
 * Embeds DB content as inline JS so pages render instantly (no waiting for fetch).
 * Usage: <script src="page_bootstrap.php?page=home"></script>
 */
declare(strict_types=1);

$page = $_GET['page'] ?? 'home';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

try {
    if ($page === 'events') {
        $payload = [
            'success' => true,
            'page' => 'events',
            'eventsData' => fetchEventsPagePayload($pdo),
        ];
    } else {
        $spotlight = fetchHomeSpotlightPayload($pdo);
        $payload = [
            'success' => true,
            'page' => 'home',
            'spotlight_slides' => $spotlight['spotlight_slides'],
            'hero_spotlights' => $spotlight['hero_spotlights'],
            'has_live' => $spotlight['has_live'] ?? false,
            'featured' => fetchHomeFeaturedPayload($pdo),
            'slides' => loadHomepageHeroSlides($pdo, true),
            'homepage_extras' => fetchHomepageExtrasPayload($pdo),
        ];
    }

    echo 'window.__HOSU_PAGE_BOOTSTRAP=' . json_encode($payload, $flags) . ';';
} catch (Throwable $e) {
    error_log('page_bootstrap: ' . $e->getMessage());
    echo 'window.__HOSU_PAGE_BOOTSTRAP={success:false,page:' . json_encode($page) . '};';
}
