<?php
/**
 * Restore the homepage hero carousel (intro + oncology specialty slides).
 * Safe when slides were deleted or all hidden — re-seeds only if nothing is displayable.
 *
 * Usage: php restore_hero_slides.php
 */
require __DIR__ . '/db.php';
require_once __DIR__ . '/event_helpers.php';

echo "Restore homepage hero slides\n\n";

$before = (int) $pdo->query('SELECT COUNT(*) FROM homepage_hero_slides')->fetchColumn();
echo "Slides in database before: $before\n";

if (restoreHeroSlidesIfMissing($pdo)) {
    $after = (int) $pdo->query('SELECT COUNT(*) FROM homepage_hero_slides')->fetchColumn();
    echo "Restored hero section — $after slide(s) now in database.\n";
    echo "Refresh https://hosu.or.ug/ to see the carousel.\n";
} else {
    echo "Hero slides already displayable — no restore needed.\n";
}

$visible = loadHomepageHeroSlides($pdo, true, false);
echo "\nVisible slides:\n";
foreach ($visible as $slide) {
    echo '  - ' . strip_tags((string) ($slide['title'] ?? '')) . "\n";
}
