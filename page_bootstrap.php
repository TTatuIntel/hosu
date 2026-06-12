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

require_once __DIR__ . '/event_helpers.php';

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

function hosu_offline_home_bootstrap(): array
{
    return [
        'success' => true,
        'page' => 'home',
        'spotlight_slides' => [],
        'hero_spotlights' => [],
        'has_live' => false,
        'ongoing_settings' => defaultOngoingNowSettings(),
        'ongoing_mode' => 'empty',
        'featured' => ['events' => [], 'publications' => [], 'grants' => []],
        'slides' => heroSlidesFromDefaultSeed(),
        'homepage_extras' => [
            'partners' => defaultHomepagePartners(),
            'cta' => defaultHomepageCta(),
            'ongoing_settings' => defaultOngoingNowSettings(),
        ],
    ];
}

try {
    if (!defined('HOSU_DB_SOFT_FAIL')) {
        define('HOSU_DB_SOFT_FAIL', true);
    }
    if (!isset($pdo) || !$pdo instanceof PDO) {
        require_once __DIR__ . '/db.php';
    }

    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('No database connection');
    }

    if ($page === 'events') {
        $payload = [
            'success' => true,
            'page' => 'events',
            'eventsData' => fetchEventsPagePayload($pdo),
        ];
    } else {
        $spotlight = fetchHomeSpotlightPayload($pdo);
        $heroImages = loadHeroImageSettings($pdo);
        $payload = [
            'success' => true,
            'page' => 'home',
            'spotlight_slides' => $spotlight['spotlight_slides'],
            'hero_spotlights' => $spotlight['hero_spotlights'],
            'has_live' => $spotlight['has_live'] ?? false,
            'ongoing_settings' => $spotlight['ongoing_settings'] ?? defaultOngoingNowSettings(),
            'ongoing_mode' => $spotlight['ongoing_mode'] ?? 'empty',
            'featured' => fetchHomeFeaturedPayload($pdo),
            'slides' => loadHomepageHeroSlides($pdo, true),
            'image_mode' => $heroImages['mode'],
            'pool_images' => $heroImages['pool'],
            'homepage_extras' => fetchHomepageExtrasPayload($pdo),
        ];
    }

    echo 'window.__HOSU_PAGE_BOOTSTRAP=' . json_encode($payload, $flags) . ';';
} catch (Throwable $e) {
    error_log('page_bootstrap: ' . $e->getMessage());
    $fallback = $page === 'events'
        ? ['success' => false, 'page' => 'events']
        : hosu_offline_home_bootstrap();
    echo 'window.__HOSU_PAGE_BOOTSTRAP=' . json_encode($fallback, $flags) . ';';
}
