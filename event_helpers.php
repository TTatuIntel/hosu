<?php
/**
 * Shared event schema migrations, enrichment, and display-period logic.
 */

function migrateEventSchema(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;

    foreach ([
        'date_start DATE NULL',
        'date_end DATE NULL',
        'is_free TINYINT(1) NOT NULL DEFAULT 1',
        'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0',
        'speakers TEXT NULL',
        'highlights TEXT NULL',
        'announcements TEXT NULL',
        'display_start DATETIME NULL',
        'display_end DATETIME NULL',
        'display_for_event TINYINT(1) NOT NULL DEFAULT 0',
        'pinned TINYINT(1) NOT NULL DEFAULT 0',
        'home_priority INT NOT NULL DEFAULT 0',
        'live_message VARCHAR(500) NULL',
        'live_cta_label VARCHAR(120) NULL',
        'live_cta_url VARCHAR(500) NULL',
        'drive_folder_url VARCHAR(500) NULL',
        'show_live_on_home TINYINT(1) NOT NULL DEFAULT 1',
        'show_upcoming_in_ongoing TINYINT(1) NOT NULL DEFAULT 0',
        'post_event_display_days INT NOT NULL DEFAULT 0',
        'recap_cta_label VARCHAR(120) NULL',
    ] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try {
            $pdo->exec('ALTER TABLE events ADD COLUMN ' . $colName . ' ' . substr($colDef, strlen($colName) + 1));
        } catch (Exception $e) { /* already exists */ }
    }

    try {
        $pdo->exec("ALTER TABLE events ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
    } catch (Exception $e) { /* already exists */ }

    try {
        $pdo->exec('ALTER TABLE events MODIFY live_message TEXT NULL');
    } catch (Exception $e) { /* already TEXT or unsupported */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(100) NOT NULL,
            image_path VARCHAR(500) NOT NULL,
            image_alt VARCHAR(255) DEFAULT '',
            caption VARCHAR(255) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            source_type VARCHAR(20) NOT NULL DEFAULT 'upload',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("ALTER TABLE event_images ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT 'upload'");
    } catch (Exception $e) { /* already exists */ }

    /* caption_disabled=1 means: even when the event has a title/description, do NOT
       fall back to it for this image. Lets admin keep an image text-free on purpose. */
    try {
        $pdo->exec("ALTER TABLE event_images ADD COLUMN caption_disabled TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Exception $e) { /* already exists */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_media (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(100) NOT NULL,
            media_type ENUM('video','document') NOT NULL DEFAULT 'video',
            media_path VARCHAR(500) NOT NULL,
            title VARCHAR(255) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            source_type VARCHAR(20) NOT NULL DEFAULT 'url',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_media_event (event_id),
            INDEX idx_event_media_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_live_content (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(100) NOT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NULL,
            content_type VARCHAR(30) NOT NULL DEFAULT 'update',
            image_url VARCHAR(500) DEFAULT '',
            link_url VARCHAR(500) DEFAULT '',
            link_label VARCHAR(120) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            visibility VARCHAR(20) NOT NULL DEFAULT 'always',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_elc_event (event_id),
            INDEX idx_elc_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("ALTER TABLE event_live_content ADD COLUMN visibility VARCHAR(20) NOT NULL DEFAULT 'always'");
    } catch (Exception $e) { /* already exists */ }

    try {
        $pdo->exec('ALTER TABLE event_live_content ADD COLUMN media_json TEXT NULL');
    } catch (Exception $e) { /* already exists */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS homepage_spotlights (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            headline VARCHAR(500) DEFAULT '',
            body TEXT NULL,
            image_url VARCHAR(500) DEFAULT '',
            badge_label VARCHAR(120) DEFAULT 'Important',
            content_type VARCHAR(30) NOT NULL DEFAULT 'announcement',
            cta_primary_label VARCHAR(120) DEFAULT '',
            cta_primary_url VARCHAR(500) DEFAULT '',
            cta_secondary_label VARCHAR(120) DEFAULT '',
            cta_secondary_url VARCHAR(500) DEFAULT '',
            display_start DATETIME NULL,
            display_end DATETIME NULL,
            show_in_hero TINYINT(1) NOT NULL DEFAULT 0,
            show_in_spotlight TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            event_id VARCHAR(100) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_spotlight_active (is_active),
            INDEX idx_spotlight_sort (sort_order),
            INDEX idx_spotlight_priority (priority)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS homepage_hero_slides (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            body TEXT NULL,
            badge_label VARCHAR(120) DEFAULT '',
            pills_json TEXT NULL,
            popup_title VARCHAR(255) DEFAULT '',
            popup_html TEXT NULL,
            image_path VARCHAR(500) DEFAULT '',
            image_alt VARCHAR(255) DEFAULT '',
            cta_label VARCHAR(120) DEFAULT '',
            cta_url VARCHAR(500) DEFAULT '',
            cta_secondary_label VARCHAR(120) DEFAULT '',
            cta_secondary_url VARCHAR(500) DEFAULT '',
            read_more_label VARCHAR(80) DEFAULT 'Read More →',
            slide_key VARCHAR(80) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            display_start DATETIME NULL,
            display_end DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_hero_active (is_active),
            INDEX idx_hero_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS homepage_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) { /* ignore */ }

    try {
        $pdo->exec('ALTER TABLE homepage_hero_slides ADD COLUMN images_json TEXT NULL');
    } catch (Exception $e) { /* already exists */ }

    try {
        $pdo->exec('ALTER TABLE homepage_spotlights ADD COLUMN images_json TEXT NULL');
    } catch (Exception $e) { /* already exists */ }
}

function parseSlideImageList(array $row, string $legacyKey = 'image_path', string $altKey = 'image_alt'): array
{
    $items = [];
    if (!empty($row['images_json'])) {
        $decoded = json_decode($row['images_json'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = ['url' => trim($item), 'alt' => '', 'type' => 'image'];
                } elseif (is_array($item) && !empty($item['url'])) {
                    $items[] = [
                        'url' => trim((string)$item['url']),
                        'alt' => trim((string)($item['alt'] ?? '')),
                        'type' => (($item['type'] ?? 'image') === 'video') ? 'video' : 'image',
                        'title' => trim((string)($item['title'] ?? '')),
                        'body' => trim((string)($item['body'] ?? '')),
                        'headline' => trim((string)($item['headline'] ?? '')),
                        'cta_label' => trim((string)($item['cta_label'] ?? '')),
                        'cta_url' => trim((string)($item['cta_url'] ?? '')),
                    ];
                }
            }
        }
    }

    $legacy = trim((string)($row[$legacyKey] ?? ''));
    $legacyAlt = trim((string)($row[$altKey] ?? ''));
    if ($legacy !== '') {
        $exists = false;
        foreach ($items as $it) {
            if ($it['url'] === $legacy) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            array_unshift($items, ['url' => $legacy, 'alt' => $legacyAlt, 'type' => 'image']);
        }
    }

    return dedupeSlideImages($items);
}

function dedupeSlideImages(array $items): array
{
    $seen = [];
    $out = [];
    foreach ($items as $item) {
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '' || isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $entry = ['url' => $url, 'alt' => trim((string)($item['alt'] ?? ''))];
        foreach (['title', 'body', 'headline', 'cta_label', 'cta_url'] as $copyKey) {
            if (!empty($item[$copyKey])) {
                $entry[$copyKey] = trim((string)$item[$copyKey]);
            }
        }
        /* Preserve type so videos round-trip through save/load (default = image). */
        if (($item['type'] ?? '') === 'video') {
            $entry['type'] = 'video';
        }
        $out[] = $entry;
    }
    return $out;
}

/** Append video URLs (one per line / comma-separated) as type:'video' entries. */
function appendSlideVideoUrlsFromText(string $text, array $items): array
{
    if (trim($text) === '') {
        return $items;
    }
    foreach (preg_split('/[\r\n,]+/', $text) as $url) {
        $url = trim($url);
        if ($url !== '') {
            $items[] = ['url' => $url, 'alt' => '', 'type' => 'video'];
        }
    }
    return dedupeSlideImages($items);
}

function encodeSlideImagesJson(array $items): string
{
    return json_encode(dedupeSlideImages($items), JSON_UNESCAPED_SLASHES) ?: '[]';
}

/** Expand Drive folder links into individual file URLs (hero/spotlight galleries). */
function expandHeroSlideImages(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (isDriveFolderUrl($url)) {
            $parsed = expandPastedImageUrls([$url]);
            if (empty($parsed['urls'])) {
                continue;
            }
            foreach ($parsed['urls'] as $i => $expandedUrl) {
                $copy = $item;
                $copy['url'] = $expandedUrl;
                if ($i > 0) {
                    foreach (['title', 'body', 'headline', 'cta_label', 'cta_url'] as $k) {
                        unset($copy[$k]);
                    }
                }
                $out[] = $copy;
            }
            continue;
        }
        $out[] = $item;
    }
    return dedupeSlideImages($out);
}

function parsePostedSlideImages(string $raw, string $defaultAlt = ''): array
{
    $items = [];
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $items[] = ['url' => trim($item), 'alt' => $defaultAlt];
                } elseif (is_array($item) && !empty($item['url'])) {
                    $entry = [
                        'url' => trim((string)$item['url']),
                        'alt' => trim((string)($item['alt'] ?? $defaultAlt)),
                    ];
                    foreach (['title', 'body', 'headline', 'cta_label', 'cta_url'] as $copyKey) {
                        if (!empty($item[$copyKey])) {
                            $entry[$copyKey] = trim((string)$item[$copyKey]);
                        }
                    }
                    $items[] = $entry;
                }
            }
        }
    }
    return dedupeSlideImages($items);
}

function appendSlideImageUrlsFromText(string $text, array $items, string $defaultAlt = '', bool $expandDrive = true): array
{
    if (trim($text) === '') {
        return $items;
    }
    $urls = [];
    foreach (preg_split('/[\r\n,]+/', $text) as $url) {
        $url = trim($url);
        if ($url !== '') {
            $urls[] = $url;
        }
    }
    if ($expandDrive && !empty($urls)) {
        $parsed = expandPastedImageUrls($urls);
        foreach ($parsed['urls'] as $url) {
            $items[] = ['url' => $url, 'alt' => $defaultAlt];
        }
    } else {
        foreach ($urls as $url) {
            $items[] = ['url' => $url, 'alt' => $defaultAlt];
        }
    }
    return dedupeSlideImages($items);
}

function collectPostedSlideImages(string $imagesJsonField, string $urlsField, string $legacyField, string $defaultAlt, string $uploadDir, int $maxBytes = 8000000): array
{
    require_once __DIR__ . '/upload_helper.php';

    $items = parsePostedSlideImages($_POST[$imagesJsonField] ?? '[]', $defaultAlt);
    $items = appendSlideImageUrlsFromText(trim($_POST[$urlsField] ?? ''), $items, $defaultAlt);

    $legacy = trim($_POST[$legacyField] ?? '');
    if ($legacy !== '') {
        $items = dedupeSlideImages(array_merge([['url' => $legacy, 'alt' => $defaultAlt]], $items));
    }

    if (!empty($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        $up = secureUpload($_FILES['imageFile'], $uploadDir, false, $maxBytes);
        if ($up) {
            $items[] = ['url' => $up, 'alt' => $defaultAlt];
        }
    }

    if (!empty($_FILES['imageFiles']) && is_array($_FILES['imageFiles']['name'])) {
        foreach ($_FILES['imageFiles']['name'] as $i => $name) {
            if (($_FILES['imageFiles']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $file = [
                'name' => $_FILES['imageFiles']['name'][$i],
                'type' => $_FILES['imageFiles']['type'][$i],
                'tmp_name' => $_FILES['imageFiles']['tmp_name'][$i],
                'error' => $_FILES['imageFiles']['error'][$i],
                'size' => $_FILES['imageFiles']['size'][$i],
            ];
            $up = secureUpload($file, $uploadDir, false, $maxBytes);
            if ($up) {
                $items[] = ['url' => $up, 'alt' => $defaultAlt];
            }
        }
    }

    return expandHeroSlideImages(dedupeSlideImages($items));
}

function eventHasPassed(array $ev, ?DateTimeImmutable $today = null): bool
{
    $today = $today ?? new DateTimeImmutable('today');
    if (!empty($ev['date_start'])) {
        $start = new DateTimeImmutable($ev['date_start']);
        $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;
        return $today > $end;
    }

    return ($ev['status'] ?? '') === 'past' || ($ev['category'] ?? '') === 'past';
}

function resolveEventBucketCategory(array $ev, ?DateTimeImmutable $today = null): string
{
    $today = $today ?? new DateTimeImmutable('today');
    if (eventHasPassed($ev, $today)) {
        return 'past';
    }
    if (eventIsLive($ev, $today)) {
        return 'current';
    }

    $cat = $ev['category'] ?? 'upcoming';
    if ($cat === 'past') {
        return 'upcoming';
    }
    if ($cat === 'current') {
        return 'upcoming';
    }

    return in_array($cat, ['current', 'upcoming', 'past'], true) ? $cat : 'upcoming';
}

function autoExpirePastEvents(PDO $pdo): void
{
    $pdo->exec("
        UPDATE events
        SET status = 'past',
            category = 'past',
            featured = 0
        WHERE (
              (date_end IS NOT NULL AND date_end < CURDATE())
           OR (date_end IS NULL AND date_start IS NOT NULL AND date_start < CURDATE())
          )
          AND (status != 'past' OR category != 'past' OR featured = 1)
    ");
}

function computeEventCountdown(array $ev, ?DateTimeImmutable $today = null): string
{
    $today = $today ?? new DateTimeImmutable('today');
    if (empty($ev['date_start'])) {
        return $ev['countdown'] ?? '';
    }

    $start = new DateTimeImmutable($ev['date_start']);
    $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;

    if ($today > $end) {
        return 'Event Ended';
    }
    if ($today >= $start && $today <= $end) {
        return 'Happening Now';
    }

    $diff = $today->diff($start);
    if ($diff->y > 0) {
        return 'In ' . $diff->y . ' year' . ($diff->y > 1 ? 's' : '')
            . ($diff->m > 0 ? ', ' . $diff->m . ' month' . ($diff->m > 1 ? 's' : '') : '');
    }
    if ($diff->m > 0) {
        return 'In ' . $diff->m . ' month' . ($diff->m > 1 ? 's' : '')
            . ($diff->d > 0 ? ', ' . $diff->d . ' day' . ($diff->d > 1 ? 's' : '') : '');
    }
    if ($diff->d > 1) {
        return 'In ' . $diff->d . ' days';
    }
    if ($diff->d === 1) {
        return 'Tomorrow';
    }
    return 'Today';
}

function eventIsLive(array $ev, ?DateTimeImmutable $today = null): bool
{
    $today = $today ?? new DateTimeImmutable('today');
    if (empty($ev['date_start'])) {
        return false;
    }
    $start = new DateTimeImmutable($ev['date_start']);
    $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;
    return $today >= $start && $today <= $end;
}

function eventIsDisplayActive(array $ev, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? new DateTimeImmutable();

    if (!empty($ev['display_for_event']) && !empty($ev['date_start'])) {
        $start = new DateTimeImmutable($ev['date_start'] . ' 00:00:00');
        $end   = !empty($ev['date_end'])
            ? new DateTimeImmutable($ev['date_end'] . ' 23:59:59')
            : new DateTimeImmutable($ev['date_start'] . ' 23:59:59');
        return $now >= $start && $now <= $end;
    }

    if (!empty($ev['display_start'])) {
        $ds = new DateTimeImmutable($ev['display_start']);
        if ($now < $ds) {
            return false;
        }
    }
    if (!empty($ev['display_end'])) {
        $de = new DateTimeImmutable($ev['display_end']);
        if ($now > $de) {
            return false;
        }
    }

    return true;
}

function enrichEventRow(array &$ev, ?DateTimeImmutable $today = null): void
{
    $today = $today ?? new DateTimeImmutable('today');

    $ev['featured'] = (bool)($ev['featured'] ?? 0);
    $ev['pinned'] = (bool)($ev['pinned'] ?? 0);
    $ev['display_for_event'] = (bool)($ev['display_for_event'] ?? 0);
    $ev['is_free'] = (bool)($ev['is_free'] ?? 1);
    $ev['event_fee'] = (float)($ev['event_fee'] ?? 0);
    $ev['home_priority'] = (int)($ev['home_priority'] ?? 0);
    $ev['image'] = str_replace('\\', '/', $ev['image'] ?? '');
    /* If the admin pasted a Google Drive share link, rewrite it to a direct image URL
       so plain <img src> works on every page that reads ev.image. */
    if ($ev['image'] !== '' && (stripos($ev['image'], 'drive.google.com') !== false || stripos($ev['image'], 'docs.google.com') !== false)) {
        $ev['image'] = normalizeDriveImageUrl($ev['image']);
    }

    $ev['countdown'] = computeEventCountdown($ev, $today);
    $ev['is_live'] = eventIsLive($ev, $today);
    $ev['is_past'] = eventHasPassed($ev, $today);
    if ($ev['is_past']) {
        $ev['status'] = 'past';
        $ev['category'] = 'past';
    }
    $ev['is_display_active'] = eventIsDisplayActive($ev);
    $ev['show_live_on_home'] = !isset($ev['show_live_on_home']) || (bool)$ev['show_live_on_home'];
    $ev['show_upcoming_in_ongoing'] = !empty($ev['show_upcoming_in_ongoing']);
    $ev['live_cta_label'] = !empty($ev['live_cta_label']) ? $ev['live_cta_label'] : 'Register & Join Now';
    $ev['post_event_display_days'] = (int)($ev['post_event_display_days'] ?? 0);
    $ev['in_spotlight'] = eventInSpotlightPeriod($ev, $today);
}

function loadLiveContent(PDO $pdo, array $eventIds, bool $activeOnly = false): array
{
    $byEvent = [];
    if (empty($eventIds)) {
        return $byEvent;
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $sql = "SELECT id, event_id, title, body, content_type, image_url, link_url, link_label, sort_order, is_active, visibility, media_json
            FROM event_live_content
            WHERE event_id IN ($placeholders)";
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY event_id, sort_order ASC, id ASC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['is_active'] = (bool)$row['is_active'];
            $row['media_items'] = parseContentMediaItems($row);
            $byEvent[$row['event_id']][] = $row;
        }
    } catch (Exception $e) { /* ignore */ }

    return $byEvent;
}

function attachLiveContentToEvents(array &$events, array $liveByEvent, bool $activeOnly = true): void
{
    foreach ($events as &$ev) {
        $items = $liveByEvent[$ev['id']] ?? [];
        if ($activeOnly) {
            $items = array_values(array_filter($items, fn($it) => !empty($it['is_active'])));
        }
        $ev['live_content'] = $items;
        $ev['content_blocks'] = $items;
    }
    unset($ev);
}

function filterEventContentBlocks(array $items, array $ev, string $context = 'public'): array
{
    $isLive = !empty($ev['is_live']);
    return array_values(array_filter($items, function ($it) use ($isLive, $context) {
        if (empty($it['is_active'])) {
            return false;
        }
        $vis = $it['visibility'] ?? 'always';
        if ($vis === 'always') {
            return true;
        }
        if ($vis === 'live') {
            return $isLive;
        }
        if ($vis === 'homepage') {
            return $context === 'homepage';
        }
        return true;
    }));
}

function saveLiveContentFromRequest(PDO $pdo, string $eventId, array $post): void
{
    if (empty($post['live_content_json'])) {
        return;
    }

    $items = json_decode($post['live_content_json'], true);
    if (!is_array($items)) {
        return;
    }

    $allowedVis = ['always', 'live', 'homepage'];
    $upd = $pdo->prepare('UPDATE event_live_content
        SET title = ?, body = ?, content_type = ?, image_url = ?, link_url = ?, link_label = ?, sort_order = ?, is_active = ?, visibility = ?, media_json = ?
        WHERE id = ? AND event_id = ?');
    $ins = $pdo->prepare('INSERT INTO event_live_content
        (event_id, title, body, content_type, image_url, link_url, link_label, sort_order, is_active, visibility, media_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $del = $pdo->prepare('DELETE FROM event_live_content WHERE id = ? AND event_id = ?');

    $allowedTypes = ['update', 'announcement', 'link', 'image', 'video', 'schedule'];

    foreach ($items as $i => $item) {
        if (!empty($item['_delete'])) {
            if (!empty($item['id']) && (int)$item['id'] > 0) {
                $del->execute([(int)$item['id'], $eventId]);
            }
            continue;
        }

        $title = trim($item['title'] ?? '');
        $body = trim($item['body'] ?? '');
        $mediaItems = normalizeContentMediaItems($item['media_items'] ?? []);
        if ($title === '' && $body === '' && empty($mediaItems)) {
            continue;
        }

        $type = in_array($item['content_type'] ?? '', $allowedTypes, true) ? $item['content_type'] : 'update';
        $imageUrl = trim($item['image_url'] ?? '');
        if ($imageUrl === '' && !empty($mediaItems[0]['url']) && ($mediaItems[0]['type'] ?? 'image') === 'image') {
            $imageUrl = $mediaItems[0]['url'];
        }
        $mediaJson = encodeContentMediaJson($mediaItems);
        $linkUrl = trim($item['link_url'] ?? '');
        $linkLabel = trim($item['link_label'] ?? '');
        $active = !isset($item['is_active']) || $item['is_active'] === true || $item['is_active'] === 1 || $item['is_active'] === '1' ? 1 : 0;
        $sort = isset($item['sort_order']) ? (int)$item['sort_order'] : $i;
        $visibility = in_array($item['visibility'] ?? '', $allowedVis, true) ? $item['visibility'] : 'always';

        if (!empty($item['id']) && (int)$item['id'] > 0) {
            $upd->execute([$title, $body, $type, $imageUrl, $linkUrl, $linkLabel, $sort, $active, $visibility, $mediaJson, (int)$item['id'], $eventId]);
        } else {
            $ins->execute([$eventId, $title, $body, $type, $imageUrl, $linkUrl, $linkLabel, $sort, $active, $visibility, $mediaJson]);
        }
    }
}

function getExistingEventVideoUrls(PDO $pdo, string $eventId): array
{
    try {
        $stmt = $pdo->prepare("SELECT media_path FROM event_media WHERE event_id = ? AND media_type = 'video'");
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return [];
    }
}

function setEventPrimaryImage(PDO $pdo, string $eventId, int $imageId): bool
{
    $stmt = $pdo->prepare('SELECT image_path FROM event_images WHERE id = ? AND event_id = ?');
    $stmt->execute([$imageId, $eventId]);
    $path = $stmt->fetchColumn();
    if (!$path) {
        return false;
    }
    $pdo->prepare('UPDATE event_images SET is_primary = 0 WHERE event_id = ?')->execute([$eventId]);
    $pdo->prepare('UPDATE event_images SET is_primary = 1 WHERE id = ? AND event_id = ?')->execute([$imageId, $eventId]);
    $pdo->prepare('UPDATE events SET image = ? WHERE id = ?')->execute([$path, $eventId]);
    return true;
}

function appendEventAssets(PDO $pdo, string $eventId, array $post, array $files): array
{
    migrateEventSchema($pdo);
    $summary = ['images' => 0, 'videos' => 0, 'documents' => 0, 'content_blocks' => 0];

    $uploadedPaths = [];
    if (!empty($files['imageFiles']) && is_array($files['imageFiles']['name'])) {
        $count = count($files['imageFiles']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['imageFiles']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $single = [
                'name' => $files['imageFiles']['name'][$i],
                'type' => $files['imageFiles']['type'][$i],
                'tmp_name' => $files['imageFiles']['tmp_name'][$i],
                'error' => $files['imageFiles']['error'][$i],
                'size' => $files['imageFiles']['size'][$i],
            ];
            $up = secureUpload($single, 'uploads/events/', false, 5000000);
            if ($up) {
                $uploadedPaths[] = $up;
            }
        }
    }

    $pathsForBlocks = [];
    $pathsForGallery = $uploadedPaths;
    if (!empty($post['new_content_json']) && !empty($uploadedPaths)) {
        $pending = json_decode($post['new_content_json'], true);
        if (is_array($pending)) {
            $need = 0;
            foreach ($pending as $item) {
                $mediaItems = $item['media_items'] ?? [];
                $wantsImage = empty($mediaItems) || array_filter($mediaItems, fn($m) => empty($m['url']));
                if ($wantsImage) {
                    $need++;
                }
            }
            if ($need > 0) {
                $pathsForBlocks = array_slice($uploadedPaths, 0, $need);
                $pathsForGallery = array_slice($uploadedPaths, $need);
            }
        }
    }

    if (!empty($pathsForGallery)) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM event_images WHERE event_id = ?');
        $maxStmt->execute([$eventId]);
        $nextOrder = (int)$maxStmt->fetchColumn() + 1;
        $hasAnyStmt = $pdo->prepare('SELECT COUNT(*) FROM event_images WHERE event_id = ?');
        $hasAnyStmt->execute([$eventId]);
        $hasAny = (int)$hasAnyStmt->fetchColumn() > 0;
        $alt = trim($post['imageAlt'] ?? '');
        $ins = $pdo->prepare('INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary, source_type) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($pathsForGallery as $i => $path) {
            $isPrim = (!$hasAny && $i === 0) ? 1 : 0;
            $ins->execute([$eventId, $path, $alt, $nextOrder + $i, $isPrim, 'upload']);
            if ($isPrim) {
                $pdo->prepare('UPDATE events SET image = ? WHERE id = ?')->execute([$path, $eventId]);
                $hasAny = true;
            }
            $summary['images']++;
        }
    }

    $imageUrls = [];
    if (!empty($post['imageUrls']) && is_array($post['imageUrls'])) {
        $imageUrls = $post['imageUrls'];
    } elseif (!empty($post['imageUrls'])) {
        $imageUrls = preg_split('/[\r\n,]+/', $post['imageUrls']);
    }
    if (!empty($imageUrls)) {
        $summary['images'] += insertEventImageUrls($pdo, $eventId, $imageUrls, trim($post['imageAlt'] ?? ''));
    }

    if (!empty($post['new_content_json'])) {
        $newItems = json_decode($post['new_content_json'], true);
        if (is_array($newItems)) {
            $allowedTypes = ['update', 'announcement', 'link', 'image', 'video', 'schedule'];
            $allowedVis = ['always', 'live', 'homepage'];
            $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM event_live_content WHERE event_id = ?');
            $maxStmt->execute([$eventId]);
            $sort = (int)$maxStmt->fetchColumn() + 1;
            $ins = $pdo->prepare('INSERT INTO event_live_content
                (event_id, title, body, content_type, image_url, link_url, link_label, sort_order, is_active, visibility, media_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $uploadIdx = 0;
            foreach ($newItems as $item) {
                $title = trim($item['title'] ?? '');
                $body = trim($item['body'] ?? '');
                $mediaItems = normalizeContentMediaItems($item['media_items'] ?? []);
                if ($title === '' && $body === '' && empty($mediaItems) && empty($pathsForBlocks)) {
                    continue;
                }
                if (!empty($pathsForBlocks[$uploadIdx])) {
                    $path = $pathsForBlocks[$uploadIdx];
                    if (empty($mediaItems)) {
                        $mediaItems = [[
                            'type' => 'image',
                            'url' => $path,
                            'title' => $title,
                            'headline' => $body,
                        ]];
                    } else {
                        foreach ($mediaItems as &$mi) {
                            if (empty($mi['url'])) {
                                $mi['url'] = $path;
                            }
                        }
                        unset($mi);
                    }
                    $uploadIdx++;
                }
                $type = in_array($item['content_type'] ?? '', $allowedTypes, true) ? $item['content_type'] : 'update';
                $vis = in_array($item['visibility'] ?? '', $allowedVis, true) ? $item['visibility'] : 'always';
                $active = !isset($item['is_active']) || $item['is_active'] === true || $item['is_active'] === 1 || $item['is_active'] === '1' ? 1 : 0;
                $imageUrl = trim($item['image_url'] ?? '');
                if ($imageUrl === '' && !empty($mediaItems[0]['url']) && ($mediaItems[0]['type'] ?? 'image') === 'image') {
                    $imageUrl = $mediaItems[0]['url'];
                }
                $ins->execute([
                    $eventId, $title, $body, $type,
                    $imageUrl, trim($item['link_url'] ?? ''), trim($item['link_label'] ?? ''),
                    $sort++, $active, $vis, encodeContentMediaJson($mediaItems),
                ]);
                $summary['content_blocks']++;
            }
        }
    }

    saveEventMediaFromRequest($pdo, $eventId, $post, $files);
    if (!empty($post['video_urls'])) {
        $summary['videos'] = count(array_filter(preg_split('/[\r\n,]+/', is_array($post['video_urls']) ? implode(',', $post['video_urls']) : $post['video_urls'])));
    }

    if (!empty($post['enable_live_home']) && $post['enable_live_home'] !== '0') {
        $pdo->prepare('UPDATE events SET show_live_on_home = 1 WHERE id = ?')->execute([$eventId]);
        $rowStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $rowStmt->execute([$eventId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            ensurePastEventHomeDisplay($pdo, $eventId, $row, true);
        }
    }

    if (!empty($post['drive_folder_url']) || !empty($post['live_message'])) {
        $liveFields = parseLiveFields($post);
        $pdo->prepare(
            'UPDATE events SET drive_folder_url = ?, live_cta_url = ?, live_message = ?, live_cta_label = ?,
             show_live_on_home = GREATEST(show_live_on_home, ?) WHERE id = ?'
        )->execute([
            $liveFields['drive_folder_url'],
            $liveFields['live_cta_url'],
            $liveFields['live_message'],
            $liveFields['live_cta_label'],
            (int) $liveFields['show_live_on_home'],
            $eventId,
        ]);
        $rowStmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $rowStmt->execute([$eventId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            ensurePastEventHomeDisplay($pdo, $eventId, array_merge($row, $liveFields), !empty($post['enable_live_home']));
        }
    }

    return $summary;
}

function parseLiveFields(array $post): array
{
    $showLive = !isset($post['show_live_on_home']) || $post['show_live_on_home'] === '' || $post['show_live_on_home'] === '1';
    $showUpcoming = !empty($post['show_upcoming_in_ongoing']) && $post['show_upcoming_in_ongoing'] !== '0';
    $cta = trim($post['live_cta_label'] ?? '');

    $folder = trim($post['drive_folder_url'] ?? '');
    $ctaUrl = trim($post['live_cta_url'] ?? '');
    if ($folder === '' && isDriveFolderUrl($ctaUrl)) {
        $folder = $ctaUrl;
    }

    return [
        'live_message' => trim($post['live_message'] ?? ''),
        'live_cta_label' => $cta !== '' ? $cta : 'Register & Join Now',
        'live_cta_url' => $ctaUrl !== '' ? $ctaUrl : $folder,
        'drive_folder_url' => $folder,
        'show_live_on_home' => $showLive ? 1 : 0,
        'show_upcoming_in_ongoing' => $showUpcoming ? 1 : 0,
    ];
}

/**
 * Keep past events visible on Ongoing Now when admin adds photos or enables live-on-home.
 */
function ensurePastEventHomeDisplay(PDO $pdo, string $eventId, array $ev, bool $forceEnable = false): void
{
    $today = new DateTimeImmutable('today');
    enrichEventRow($ev, $today);
    $showLive = $forceEnable || !empty($ev['show_live_on_home']);
    if (!$showLive || empty($ev['is_past'])) {
        return;
    }

    $days = (int) ($ev['post_event_display_days'] ?? 0);
    if ($days === 0) {
        $pdo->prepare('UPDATE events SET show_live_on_home = 1, post_event_display_days = -1 WHERE id = ?')
            ->execute([$eventId]);
        return;
    }

    if ($forceEnable) {
        $pdo->prepare('UPDATE events SET show_live_on_home = 1 WHERE id = ?')->execute([$eventId]);
    }
}

/**
 * Update Ongoing Now / live content without changing event dates or core details.
 *
 * @return array{event: ?array, drive_sync: ?array, images_added_from_urls: int}
 */
function saveEventOngoingContentUpdate(PDO $pdo, string $eventId, array $existing, array $post, array $files = []): array
{
    migrateEventSchema($pdo);
    $merged = array_merge($existing, $post);
    $imagesAddedFromUrls = 0;

    $imageUrls = [];
    if (!empty($post['imageUrls']) && is_array($post['imageUrls'])) {
        $imageUrls = $post['imageUrls'];
    } elseif (!empty($post['imageUrls'])) {
        $imageUrls = preg_split('/[\r\n,]+/', (string) $post['imageUrls']);
    }
    if (!empty($imageUrls)) {
        $imagesAddedFromUrls = insertEventImageUrls($pdo, $eventId, $imageUrls, $post['imageAlt'] ?? ($existing['imageAlt'] ?? ''));
    }

    if (!empty($files['imageFiles'])) {
        appendEventAssets($pdo, $eventId, array_merge($post, ['enable_live_home' => $post['enable_live_home'] ?? '1']), $files);
    }

    saveEventMediaFromRequest($pdo, $eventId, $post, $files);
    if (array_key_exists('live_content_json', $post)) {
        saveLiveContentFromRequest($pdo, $eventId, $post);
    }

    $liveFields = parseLiveFields($merged);
    $displayFields = parseDisplayFields(array_merge($existing, $post));
    $postEventDays = (int) ($displayFields['post_event_display_days'] ?? $existing['post_event_display_days'] ?? 0);
    if (!empty($liveFields['show_live_on_home']) && eventHasPassed($existing) && $postEventDays === 0) {
        $postEventDays = -1;
    }

    $pdo->prepare(
        'UPDATE events SET live_message = ?, live_cta_label = ?, live_cta_url = ?, drive_folder_url = ?,
         show_live_on_home = ?, show_upcoming_in_ongoing = ?, post_event_display_days = ?,
         highlights = ?, announcements = ?, speakers = ?, recap_cta_label = ?
         WHERE id = ?'
    )->execute([
        $liveFields['live_message'],
        $liveFields['live_cta_label'],
        $liveFields['live_cta_url'],
        $liveFields['drive_folder_url'],
        $liveFields['show_live_on_home'],
        $liveFields['show_upcoming_in_ongoing'],
        $postEventDays,
        $displayFields['highlights'],
        $displayFields['announcements'],
        $displayFields['speakers'],
        $displayFields['recap_cta_label'],
        $eventId,
    ]);

    ensurePastEventHomeDisplay($pdo, $eventId, array_merge($existing, $liveFields, ['post_event_display_days' => $postEventDays]), true);

    $driveSync = null;
    $folderUrl = resolveEventDriveFolderUrl(array_merge($existing, $liveFields));
    if ($folderUrl !== '') {
        $folderId = extractDriveFolderId($folderUrl);
        if ($folderId !== '') {
            invalidateDriveFolderCache($folderId);
            $driveIds = fetchDriveFolderFileIds($folderId, true);
            $driveSync = [
                'ok' => count($driveIds) > 0,
                'photo_count' => count($driveIds),
                'folder_id' => $folderId,
            ];
        }
    }

    $eventRow = null;
    try {
        $evStmt = $pdo->prepare(
            'SELECT id, title, type, status, category, date, date_start, date_end, location, image, imageAlt, description,
                    countdown, featured, pinned, home_priority, display_start, display_end, display_for_event,
                    speakers, highlights, announcements, live_message, live_cta_label, live_cta_url, recap_cta_label,
                    drive_folder_url, show_live_on_home, show_upcoming_in_ongoing, post_event_display_days,
                    is_free, event_fee, created_at, updated_at
             FROM events WHERE id = ?'
        );
        $evStmt->execute([$eventId]);
        $eventRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $_e) { /* ignore */ }

    return [
        'event' => $eventRow,
        'drive_sync' => $driveSync,
        'images_added_from_urls' => $imagesAddedFromUrls,
    ];
}

function loadEventGalleries(PDO $pdo, array $eventIds): array
{
    $galleryByEvent = [];
    if (empty($eventIds)) {
        return $galleryByEvent;
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id, event_id, image_path, image_alt, caption, sort_order, is_primary, source_type,
                                      COALESCE(caption_disabled, 0) AS caption_disabled
                               FROM event_images
                               WHERE event_id IN ($placeholders)
                               ORDER BY event_id, sort_order ASC, id ASC");
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['image_path'] = str_replace('\\', '/', $row['image_path']);
            if (stripos($row['image_path'], 'drive.google.com') !== false || stripos($row['image_path'], 'docs.google.com') !== false) {
                $row['image_path'] = normalizeDriveImageUrl($row['image_path']);
            }
            $galleryByEvent[$row['event_id']][] = $row;
        }
    } catch (Exception $e) { /* ignore */ }

    return $galleryByEvent;
}

function loadEventMedia(PDO $pdo, array $eventIds): array
{
    $mediaByEvent = [];
    if (empty($eventIds)) {
        return $mediaByEvent;
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id, event_id, media_type, media_path, title, sort_order, source_type
                               FROM event_media
                               WHERE event_id IN ($placeholders)
                               ORDER BY event_id, sort_order ASC, id ASC");
        $stmt->execute($eventIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['media_path'] = str_replace('\\', '/', $row['media_path']);
            $mediaByEvent[$row['event_id']][] = $row;
        }
    } catch (Exception $e) { /* ignore */ }

    return $mediaByEvent;
}

function attachGalleryToEvents(array &$events, array $galleryByEvent): void
{
    foreach ($events as &$ev) {
        $gallery = $galleryByEvent[$ev['id']] ?? [];
        $urls = [];
        foreach ($gallery as $g) {
            $urls[] = $g['image_path'];
        }
        if (empty($urls) && !empty($ev['image'])) {
            $urls[] = $ev['image'];
        }
        $ev['images'] = $gallery;
        $ev['image_urls'] = $urls;
        $ev['image_display_urls'] = array_values(array_map('spotlightDisplayUrl', $urls));
    }
    unset($ev);
}

function attachMediaToEvents(array &$events, array $mediaByEvent): void
{
    foreach ($events as &$ev) {
        $ev['media'] = $mediaByEvent[$ev['id']] ?? [];
        $ev['videos'] = array_values(array_filter($ev['media'], fn($m) => $m['media_type'] === 'video'));
        $ev['documents'] = array_values(array_filter($ev['media'], fn($m) => $m['media_type'] === 'document'));
    }
    unset($ev);
}

function bucketEventsForPublic(array $rows, PDO $pdo): array
{
    $today = new DateTimeImmutable('today');
    $ids = array_column($rows, 'id');
    $galleryByEvent = loadEventGalleries($pdo, $ids);
    $mediaByEvent = loadEventMedia($pdo, $ids);
    $liveByEvent = loadLiveContent($pdo, $ids, true);

    $eventsData = [
        'featured' => [], 'current' => [], 'upcoming' => [],
        'conferences' => [], 'workshops' => [], 'webinars' => [], 'past' => [],
    ];
    $typeMap = ['conference' => 'conferences', 'workshop' => 'workshops', 'webinar' => 'webinars'];

    foreach ($rows as $ev) {
        enrichEventRow($ev, $today);
        $cat = resolveEventBucketCategory($ev, $today);

        $gallery = $galleryByEvent[$ev['id']] ?? [];
        $urls = [];
        foreach ($gallery as $img) {
            $urls[] = $img['image_path'];
        }
        if (empty($urls) && !empty($ev['image'])) {
            $urls[] = $ev['image'];
        }
        $ev['images'] = $gallery;
        $ev['image_urls'] = $urls;
        $ev['media'] = $mediaByEvent[$ev['id']] ?? [];
        $ev['videos'] = array_values(array_filter($ev['media'], fn($m) => $m['media_type'] === 'video'));
        $ev['documents'] = array_values(array_filter($ev['media'], fn($m) => $m['media_type'] === 'document'));
        $ev['live_content'] = $liveByEvent[$ev['id']] ?? [];
        $ev['content_blocks'] = $ev['live_content'];

        unset($ev['category']);

        if (array_key_exists($cat, $eventsData)) {
            $eventsData[$cat][] = $ev;
        } else {
            $eventsData['upcoming'][] = $ev;
        }

        $typeKey = $typeMap[$ev['type'] ?? ''] ?? null;
        if ($typeKey) {
            $eventsData[$typeKey][] = $ev;
        }
        if ($ev['featured'] && !$ev['is_past']) {
            $eventsData['featured'][] = $ev;
        }
    }

    return $eventsData;
}

function galleryImagePathKey(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $driveId = extractDriveFileId($path);
    if ($driveId !== '') {
        return 'drive:' . $driveId;
    }
    return strtolower($path);
}

/**
 * Expand pasted lines: Drive folders → file links; skip bare folder rows in gallery list.
 *
 * @return array{urls: string[], folders: string[], photo_count: int}
 */
function expandPastedImageUrls(array $urls): array
{
    $expanded = [];
    $folders = [];

    foreach ($urls as $url) {
        $url = trim((string) $url);
        if ($url === '') {
            continue;
        }

        if (isDriveFolderUrl($url)) {
            $folders[] = $url;
            $folderId = extractDriveFolderId($url);
            if ($folderId === '') {
                continue;
            }
            foreach (fetchDriveFolderFileIds($folderId, false) as $fileId) {
                $expanded[] = 'https://drive.google.com/file/d/' . $fileId . '/view';
            }
            continue;
        }

        if (extractDriveFileId($url) !== '' || isUsableSpotlightMediaUrl($url, 'image')) {
            $expanded[] = $url;
        }
    }

    $unique = [];
    $seen = [];
    foreach ($expanded as $url) {
        $key = galleryImagePathKey($url);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $url;
    }

    return [
        'urls' => $unique,
        'folders' => array_values(array_unique($folders)),
        'photo_count' => count($unique),
    ];
}

function syncEventDriveFoldersFromPaste(PDO $pdo, string $eventId, array $folders): void
{
    if (empty($folders)) {
        return;
    }
    $folder = trim($folders[0]);
    if ($folder === '') {
        return;
    }
    $pdo->prepare('UPDATE events SET drive_folder_url = ? WHERE id = ?')->execute([$folder, $eventId]);
    $row = $pdo->prepare('SELECT live_cta_url FROM events WHERE id = ?');
    $row->execute([$eventId]);
    $cta = trim((string) $row->fetchColumn());
    if ($cta === '' || isDriveFolderUrl($cta)) {
        $pdo->prepare('UPDATE events SET live_cta_url = ? WHERE id = ?')->execute([$folder, $eventId]);
    }
}

function insertEventImageUrls(PDO $pdo, string $eventId, array $urls, string $alt = ''): int
{
    if (empty($urls)) {
        return 0;
    }

    $parsed = expandPastedImageUrls($urls);
    syncEventDriveFoldersFromPaste($pdo, $eventId, $parsed['folders']);
    if (empty($parsed['urls'])) {
        return 0;
    }

    $existingStmt = $pdo->prepare('SELECT image_path FROM event_images WHERE event_id = ?');
    $existingStmt->execute([$eventId]);
    $existingKeys = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
        $existingKeys[galleryImagePathKey((string) $path)] = true;
    }

    $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM event_images WHERE event_id = ?');
    $maxStmt->execute([$eventId]);
    $nextOrder = (int) $maxStmt->fetchColumn() + 1;

    $hasAnyStmt = $pdo->prepare('SELECT COUNT(*) FROM event_images WHERE event_id = ?');
    $hasAnyStmt->execute([$eventId]);
    $hasAny = (int) $hasAnyStmt->fetchColumn() > 0;

    $ins = $pdo->prepare('INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary, source_type) VALUES (?, ?, ?, ?, ?, ?)');
    $added = 0;
    foreach ($parsed['urls'] as $url) {
        $key = galleryImagePathKey($url);
        if (isset($existingKeys[$key])) {
            continue;
        }
        $isPrim = (!$hasAny && $added === 0) ? 1 : 0;
        $ins->execute([$eventId, $url, $alt, $nextOrder + $added, $isPrim, 'url']);
        $existingKeys[$key] = true;
        if ($isPrim) {
            $pdo->prepare('UPDATE events SET image = ? WHERE id = ?')->execute([$url, $eventId]);
            $hasAny = true;
        }
        $added++;
    }

    return $added;
}

function saveEventMediaFromRequest(PDO $pdo, string $eventId, array $post, array $files): void
{
    $videoUrls = [];
    if (!empty($post['video_urls']) && is_array($post['video_urls'])) {
        $videoUrls = $post['video_urls'];
    } elseif (!empty($post['video_urls'])) {
        $videoUrls = preg_split('/[\r\n,]+/', $post['video_urls']);
    }

    $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM event_media WHERE event_id = ? AND media_type = ?');
    $maxStmt->execute([$eventId, 'video']);
    $vOrder = (int)$maxStmt->fetchColumn() + 1;

    $existingVideos = getExistingEventVideoUrls($pdo, $eventId);
    $ins = $pdo->prepare('INSERT INTO event_media (event_id, media_type, media_path, title, sort_order, source_type) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($videoUrls as $url) {
        $url = trim($url);
        if ($url === '') {
            continue;
        }
        $clean = filter_var($url, FILTER_SANITIZE_URL);
        if (in_array($clean, $existingVideos, true)) {
            continue;
        }
        $ins->execute([$eventId, 'video', $clean, '', $vOrder++, 'url']);
        $existingVideos[] = $clean;
    }

    $docDir = 'uploads/events/docs/';
    if (!is_dir($docDir)) {
        mkdir($docDir, 0755, true);
    }
    if (!empty($files['docFiles']) && is_array($files['docFiles']['name'])) {
        $maxStmt->execute([$eventId, 'document']);
        $dOrder = (int)$maxStmt->fetchColumn() + 1;
        $count = count($files['docFiles']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['docFiles']['error'][$i] !== UPLOAD_ERR_OK) {
                continue;
            }
            $single = [
                'name' => $files['docFiles']['name'][$i],
                'type' => $files['docFiles']['type'][$i],
                'tmp_name' => $files['docFiles']['tmp_name'][$i],
                'error' => $files['docFiles']['error'][$i],
                'size' => $files['docFiles']['size'][$i],
            ];
            $up = secureUpload($single, $docDir, true, 10000000);
            if ($up) {
                $title = $files['docFiles']['name'][$i] ?? '';
                $ins->execute([$eventId, 'document', $up, $title, $dOrder++, 'upload']);
            }
        }
    }

    if (!empty($post['delete_media_ids']) && is_array($post['delete_media_ids'])) {
        $sel = $pdo->prepare('SELECT id, media_path, media_type FROM event_media WHERE id = ? AND event_id = ?');
        $del = $pdo->prepare('DELETE FROM event_media WHERE id = ? AND event_id = ?');
        foreach ($post['delete_media_ids'] as $mediaId) {
            $mediaId = (int)$mediaId;
            if ($mediaId <= 0) {
                continue;
            }
            $sel->execute([$mediaId, $eventId]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($row['media_type'] === 'document'
                    && strpos($row['media_path'], 'uploads/') === 0
                    && file_exists($row['media_path'])) {
                    @unlink($row['media_path']);
                }
                $del->execute([$mediaId, $eventId]);
            }
        }
    }
}

function parseDisplayFields(array $post): array
{
    $displayForEvent = !empty($post['display_for_event']) && $post['display_for_event'] !== '0' ? 1 : 0;
    $displayStart = !empty($post['display_start']) ? trim($post['display_start']) : null;
    $displayEnd = !empty($post['display_end']) ? trim($post['display_end']) : null;
    $pinned = !empty($post['pinned']) && $post['pinned'] !== '0' ? 1 : 0;
    $homePriority = (int)($post['home_priority'] ?? 0);

    if ($displayForEvent) {
        $displayStart = null;
        $displayEnd = null;
    }

    $postEventDays = (int)($post['post_event_display_days'] ?? 0);
    if ($postEventDays < -3) {
        $postEventDays = 0;
    }

    return [
        'display_for_event' => $displayForEvent,
        'display_start' => $displayStart,
        'display_end' => $displayEnd,
        'pinned' => $pinned,
        'home_priority' => $homePriority,
        'post_event_display_days' => $postEventDays,
        'speakers' => trim($post['speakers'] ?? ''),
        'highlights' => trim($post['highlights'] ?? ''),
        'announcements' => trim($post['announcements'] ?? ''),
        'recap_cta_label' => trim($post['recap_cta_label'] ?? ''),
    ];
}

function eventQualifiesForSpotlight(array $ev): bool
{
    if (!empty($ev['is_past']) || eventHasPassed($ev)) {
        return !empty($ev['show_live_on_home']);
    }

    return !empty($ev['show_live_on_home']) || !empty($ev['featured']) || !empty($ev['pinned']);
}

function eventInSpotlightPeriod(array $ev, ?DateTimeImmutable $today = null): bool
{
    if (!eventQualifiesForSpotlight($ev)) {
        return false;
    }

    $today = $today ?? new DateTimeImmutable('today');
    $now = new DateTimeImmutable();

    if (!empty($ev['display_end'])) {
        $de = new DateTimeImmutable($ev['display_end']);
        if ($now > $de) {
            return false;
        }
    }
    if (!empty($ev['display_start'])) {
        $ds = new DateTimeImmutable($ev['display_start']);
        if ($now < $ds) {
            return false;
        }
    }

    $isLive = eventIsLive($ev, $today);
    if ($isLive && !empty($ev['show_live_on_home'])) {
        return true;
    }

    if (empty($ev['date_start'])) {
        return eventIsDisplayActive($ev, $now) && (!empty($ev['featured']) || !empty($ev['pinned']));
    }

    $start = new DateTimeImmutable($ev['date_start']);
    $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;

    if ($today < $start) {
        return (!empty($ev['featured']) || !empty($ev['pinned'])) && eventIsDisplayActive($ev, $now);
    }

    if ($today <= $end) {
        return !empty($ev['show_live_on_home']) || !empty($ev['featured']) || !empty($ev['pinned']);
    }

    $days = (int)($ev['post_event_display_days'] ?? 0);
    if ($days === -1 || $days === -3 || $days === -2) {
        return !empty($ev['show_live_on_home']);
    }
    if ($days <= 0) {
        return !empty($ev['show_live_on_home']);
    }

    $graceEnd = $end->modify('+' . $days . ' days')->setTime(23, 59, 59);
    return $now <= $graceEnd;
}

function loadHomepageSpotlights(PDO $pdo, bool $activeOnly = true): array
{
    $rows = [];
    try {
        $sql = 'SELECT * FROM homepage_spotlights';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY priority DESC, sort_order ASC, id DESC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table may not exist yet */ }

    foreach ($rows as &$row) {
        $row['is_active'] = (bool)($row['is_active'] ?? 0);
        $row['show_in_hero'] = (bool)($row['show_in_hero'] ?? 0);
        $row['show_in_spotlight'] = !isset($row['show_in_spotlight']) || (bool)$row['show_in_spotlight'];
        $row['priority'] = (int)($row['priority'] ?? 0);
        $row['sort_order'] = (int)($row['sort_order'] ?? 0);
        $allMedia = parseSlideImageList($row, 'image_url', 'image_alt');
        $row['videos'] = array_values(array_filter($allMedia, function ($i) {
            return ($i['type'] ?? 'image') === 'video';
        }));
        $row['images'] = array_values(array_filter($allMedia, function ($i) {
            return ($i['type'] ?? 'image') !== 'video';
        }));
        if (!empty($row['images'])) {
            $row['image_url'] = $row['images'][0]['url'];
        }
    }
    unset($row);

    return $rows;
}

function spotlightRowIsActive(array $row, ?DateTimeImmutable $now = null): bool
{
    if (empty($row['is_active'])) {
        return false;
    }
    $now = $now ?? new DateTimeImmutable();
    if (!empty($row['display_start'])) {
        $ds = new DateTimeImmutable($row['display_start']);
        if ($now < $ds) {
            return false;
        }
    }
    if (!empty($row['display_end'])) {
        $de = new DateTimeImmutable($row['display_end']);
        if ($now > $de) {
            return false;
        }
    }
    return true;
}

function normalizeSpotlightMediaItem(string $url, string $type = 'image'): ?array
{
    return enrichSpotlightMediaItem(['url' => $url, 'type' => $type]);
}

/**
 * Convert a Google Drive share link into a direct-display URL.
 * Returns the original URL unchanged if it isn't a Drive link or no file id can be parsed.
 *
 * Accepts: https://drive.google.com/file/d/FILE_ID/view?usp=sharing
 *          https://drive.google.com/open?id=FILE_ID
 *          https://drive.google.com/uc?id=FILE_ID&export=...
 *          https://docs.google.com/uc?id=FILE_ID
 *
 * Returns:  https://lh3.googleusercontent.com/d/FILE_ID=w900
 * (The lh3 endpoint serves the file as a plain image so <img src> works without auth redirects.)
 */
function extractDriveFileId(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#/d/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    return '';
}

/** Same-origin URL the browser can load reliably (proxied via drive_image.php). */
function driveProxiedImageUrl(string $fileId, int $width = 900): string
{
    $fileId = trim($fileId);
    if ($fileId === '') {
        return '';
    }
    $w = max(120, min(2048, $width));
    return 'drive_image.php?id=' . rawurlencode($fileId) . '&w=' . $w;
}

function normalizeDriveImageUrl(string $url, int $width = 900): string
{
    $url = trim($url);
    if ($url === '' || stripos($url, 'drive.google.com') === false && stripos($url, 'docs.google.com') === false) {
        return $url;
    }
    $id = extractDriveFileId($url);
    if ($id === '') {
        return $url;
    }
    return driveProxiedImageUrl($id, $width);
}

/** Max images pulled from one public Drive folder for homepage rotation. */
define('DRIVE_FOLDER_MAX_IMAGES', 50);

/** Cache TTL (seconds) for parsed Drive folder file lists. */
define('DRIVE_FOLDER_CACHE_TTL', 600);

function isDriveFolderUrl(string $url): bool
{
    $url = trim($url);
    return $url !== '' && (bool) preg_match('/drive\.google\.com\/.*\/folders\//i', $url);
}

function extractDriveFolderId(string $url): string
{
    if (preg_match('#/folders/([a-zA-Z0-9_-]+)#', $url, $m)) {
        return $m[1];
    }
    return '';
}

function httpGetString(string $url, int $timeout = 25): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HOSU/1.0)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) {
            return (string) $body;
        }
        return null;
    }
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'header' => "User-Agent: Mozilla/5.0 (compatible; HOSU/1.0)\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

function driveFolderCachePath(string $folderId): string
{
    $dir = __DIR__ . '/cache/drive';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $folderId) . '.json';
}

function invalidateDriveFolderCache(string $folderId): void
{
    $folderId = trim($folderId);
    if ($folderId === '') {
        return;
    }
    $path = driveFolderCachePath($folderId);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * List image file IDs inside a public Google Drive folder (embedded view HTML parse).
 */
function fetchDriveFolderFileIds(string $folderId, bool $forceRefresh = false): array
{
    $folderId = trim($folderId);
    if ($folderId === '') {
        return [];
    }

    $cacheFile = driveFolderCachePath($folderId);
    if (!$forceRefresh && is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)
            && !empty($cached['ids'])
            && is_array($cached['ids'])
            && (time() - (int)($cached['fetched_at'] ?? 0)) < DRIVE_FOLDER_CACHE_TTL
        ) {
            return array_slice($cached['ids'], 0, DRIVE_FOLDER_MAX_IMAGES);
        }
    }

    $embedUrl = 'https://drive.google.com/embeddedfolderview?id=' . rawurlencode($folderId);
    $html = httpGetString($embedUrl);
    if ($html === null || $html === '') {
        return [];
    }

    $ids = [];
    if (preg_match_all('#entry-([a-zA-Z0-9_-]{20,})#', $html, $matches)) {
        foreach ($matches[1] as $id) {
            if ($id === $folderId) {
                continue;
            }
            $ids[$id] = $id;
        }
    }
    if (empty($ids) && preg_match_all('#/file/d/([a-zA-Z0-9_-]{20,})#', $html, $matches)) {
        foreach ($matches[1] as $id) {
            if ($id === $folderId) {
                continue;
            }
            $ids[$id] = $id;
        }
    }

    $idList = array_slice(array_values($ids), 0, DRIVE_FOLDER_MAX_IMAGES);
    @file_put_contents($cacheFile, json_encode([
        'folder_id' => $folderId,
        'fetched_at' => time(),
        'ids' => $idList,
    ], JSON_UNESCAPED_SLASHES));

    return $idList;
}

function collectDriveFolderSpotlightMedia(string $folderUrl, string $titleFallback = '', string $headlineFallback = ''): array
{
    $folderId = extractDriveFolderId($folderUrl);
    if ($folderId === '') {
        return [];
    }

    $fileIds = fetchDriveFolderFileIds($folderId);
    if (empty($fileIds)) {
        return [];
    }

    $items = [];
    foreach ($fileIds as $i => $fileId) {
        $shareUrl = 'https://drive.google.com/file/d/' . $fileId . '/view';
        $item = enrichSpotlightMediaItem([
            'url' => $shareUrl,
            'type' => 'image',
            'title' => $i === 0 ? $titleFallback : '',
            'headline' => $i === 0 ? $headlineFallback : '',
            'drive_file_id' => $fileId,
        ]);
        if ($item) {
            $items[] = $item;
        }
    }
    return $items;
}

function resolveEventDriveFolderUrl(array $ev): string
{
    $candidates = [
        trim((string)($ev['drive_folder_url'] ?? '')),
        trim((string)($ev['live_cta_url'] ?? '')),
    ];
    foreach ($ev['image_urls'] ?? [] as $url) {
        $candidates[] = trim((string) $url);
    }
    foreach ($candidates as $url) {
        if (isDriveFolderUrl($url)) {
            return $url;
        }
    }
    return '';
}

function isUploadedOrRemoteMediaUrl(string $url): bool
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '' || isDriveFolderUrl($url)) {
        return false;
    }
    if (preg_match('/^https?:\/\//i', $url)) {
        return true;
    }
    return str_contains($url, 'uploads/');
}

/**
 * Returns false for folder links, missing local files, and other non-displayable URLs.
 */
/** Max width for spotlight/hero background images served to the browser. */
define('SPOTLIGHT_DISPLAY_MAX_WIDTH', 1280);

/**
 * Return a display-sized URL (matches hero section bandwidth profile).
 */
function spotlightDisplayUrl(string $url): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return '';
    }

    /* Google Drive share links → direct image endpoint. Done first so the rest of
       the function (Unsplash, ?w= suffix) treats it as a normal image URL. */
    if (stripos($url, 'drive.google.com') !== false || stripos($url, 'docs.google.com') !== false) {
        return normalizeDriveImageUrl($url, SPOTLIGHT_DISPLAY_MAX_WIDTH);
    }

    if (preg_match('/images\.unsplash\.com/i', $url)) {
        $parts = parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['w'] = (string) SPOTLIGHT_DISPLAY_MAX_WIDTH;
        $query['q'] = $query['q'] ?? '88';
        $query['auto'] = $query['auto'] ?? 'format';
        $query['fit'] = $query['fit'] ?? 'max';
        $base = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '')
            . ($parts['path'] ?? '');
        return $base . '?' . http_build_query($query);
    }

    if (preg_match('/^(https?:)?\/\//i', $url) && preg_match('/[?&]w=\d+/i', $url)) {
        return $url;
    }

    if (preg_match('/^https?:\/\//i', $url) && preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $url)) {
        $sep = str_contains($url, '?') ? '&' : '?';
        return $url . $sep . 'w=' . SPOTLIGHT_DISPLAY_MAX_WIDTH;
    }

    return $url;
}

function isUsableSpotlightMediaUrl(string $url, string $type = 'image'): bool
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return false;
    }

    if ($type === 'video') {
        return (bool) preg_match('/\.(mp4|webm|ogg|mov)(\?|$)/i', $url)
            || (bool) preg_match('/youtube\.com|youtu\.be|vimeo\.com/i', $url);
    }

    if (preg_match('/drive\.google\.com\/drive\/.*\/folders\//i', $url)) {
        return false;
    }

    /* A Drive file share link is displayable — we will rewrite it to lh3.googleusercontent.com on render. */
    if (preg_match('#drive\.google\.com/(file/d/|open\?id=|uc\?)#i', $url)
        || preg_match('#docs\.google\.com/uc\?#i', $url)) {
        return true;
    }

    if (preg_match('/^drive_image\.php\?/i', $url)) {
        return true;
    }

    if (preg_match('/^https?:\/\//i', $url)) {
        return (bool) preg_match('/\.(jpe?g|png|gif|webp|svg|avif|bmp)(\?|#|$)/i', $url)
            || (bool) preg_match(
                '/unsplash\.com|images\.unsplash|googleusercontent\.com|cloudinary\.com|imgur\.com|i\.imgur\.com/i',
                $url
            );
    }

    if (str_starts_with($url, '//')) {
        return true;
    }

    $local = ltrim($url, '/');
    if (str_contains($local, '..')) {
        return false;
    }

    return is_file(__DIR__ . '/' . $local);
}

function enrichSpotlightMediaItem(array $m): ?array
{
    $url = trim(str_replace('\\', '/', (string)($m['url'] ?? '')));
    if ($url === '') {
        return null;
    }

    $type = ($m['type'] ?? 'image') === 'video' ? 'video' : 'image';
    if (!isUsableSpotlightMediaUrl($url, $type)) {
        return null;
    }
    if ($type === 'image' && (
        preg_match('/\.(mp4|webm|ogg|mov)(\?|$)/i', $url)
        || preg_match('/youtube\.com|youtu\.be|vimeo\.com/i', $url)
    )) {
        $type = 'video';
    }

    $item = ['type' => $type, 'url' => $url];
    if ($type === 'image') {
        $driveId = trim((string) ($m['drive_file_id'] ?? ''));
        if ($driveId === '') {
            $driveId = extractDriveFileId($url);
        }
        if ($driveId !== '') {
            $item['drive_file_id'] = $driveId;
            $item['display_url'] = driveProxiedImageUrl($driveId, SPOTLIGHT_DISPLAY_MAX_WIDTH);
        } else {
            $item['display_url'] = spotlightDisplayUrl($url);
        }
    }
    $caption = trim((string)($m['caption'] ?? ''));
    $title = trim((string)($m['title'] ?? ''));
    $headline = trim((string)($m['headline'] ?? $m['body'] ?? $caption));
    $body = trim((string)($m['body'] ?? ''));
    $ctaLabel = trim((string)($m['cta_label'] ?? $m['link_label'] ?? ''));
    $ctaUrl = trim((string)($m['cta_url'] ?? $m['link_url'] ?? ''));

    if ($title !== '') {
        $item['title'] = truncatePlain($title, 56);
    }
    if ($headline !== '') {
        $item['headline'] = truncatePlain($headline, 120);
    }
    if ($body !== '' && $body !== $headline) {
        $item['body'] = truncatePlain($body, 120);
    }
    if ($ctaLabel !== '') {
        $item['cta_label'] = truncatePlain($ctaLabel, 40);
    }
    if ($ctaUrl !== '') {
        $item['cta_url'] = $ctaUrl;
    }

    return $item;
}

function dedupeSpotlightMedia(array $items): array
{
    $byKey = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $enriched = enrichSpotlightMediaItem($it);
        if (!$enriched) {
            continue;
        }
        $key = ($enriched['type'] ?? 'image') . '|' . $enriched['url'];
        if (!isset($byKey[$key])) {
            $byKey[$key] = $enriched;
            continue;
        }
        foreach (['title', 'headline', 'body', 'cta_label', 'cta_url'] as $field) {
            if (empty($byKey[$key][$field]) && !empty($enriched[$field])) {
                $byKey[$key][$field] = $enriched[$field];
            }
        }
    }

    return array_values($byKey);
}

/**
 * Mixed display order (not Drive folder order). Seeded so order stays stable
 * through the 45s homepage refresh — changes daily for variety.
 */
function shuffleSpotlightMedia(array $items, string $seed = ''): array
{
    if (count($items) <= 1) {
        return $items;
    }
    if ($seed === '') {
        $keys = array_keys($items);
        shuffle($keys);
        $shuffled = [];
        foreach ($keys as $k) {
            $shuffled[] = $items[$k];
        }
        return $shuffled;
    }
    usort($items, function ($a, $b) use ($seed) {
        $ka = md5($seed . '|' . ($a['url'] ?? '') . '|' . ($a['type'] ?? 'image'));
        $kb = md5($seed . '|' . ($b['url'] ?? '') . '|' . ($b['type'] ?? 'image'));
        return strcmp($ka, $kb);
    });
    return $items;
}

function normalizeContentMediaItems($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $items = [];
    foreach ($raw as $m) {
        if (!is_array($m)) {
            continue;
        }
        $enriched = enrichSpotlightMediaItem($m);
        if ($enriched) {
            $items[] = $enriched;
        }
    }
    return dedupeSpotlightMedia($items);
}

function encodeContentMediaJson(array $mediaItems): string
{
    return json_encode(normalizeContentMediaItems($mediaItems), JSON_UNESCAPED_SLASHES) ?: '[]';
}

function parseContentMediaItems(array $block): array
{
    $items = [];
    if (!empty($block['media_json'])) {
        $decoded = json_decode($block['media_json'], true);
        if (is_array($decoded)) {
            $items = array_merge($items, normalizeContentMediaItems($decoded));
        }
    }
    if (!empty($block['media_items']) && is_array($block['media_items'])) {
        $items = array_merge($items, normalizeContentMediaItems($block['media_items']));
    }
    if (!empty($block['image_url'])) {
        $legacy = enrichSpotlightMediaItem([
            'url' => $block['image_url'],
            'type' => 'image',
            'title' => $block['title'] ?? '',
            'headline' => $block['body'] ?? '',
            'cta_label' => $block['link_label'] ?? '',
            'cta_url' => $block['link_url'] ?? '',
        ]);
        if ($legacy) {
            $items[] = $legacy;
        }
    }
    return dedupeSpotlightMedia($items);
}

function collectEventSpotlightMedia(array $ev, array $contentBlocks = [], bool $mergeBlocks = false): array
{
    $items = [];
    $primaryUrl = trim(str_replace('\\', '/', (string)($ev['image'] ?? '')));

    /* Caption fallback: when an image has no caption of its own, surface the event's
       title + description so the spotlight slide never renders silent text under media. */
    $eventTitleFallback   = truncatePlain((string)($ev['title'] ?? ''), 80);
    $eventBodyFallback    = truncatePlain((string)($ev['announcements'] ?? $ev['description'] ?? ''), 140);

    $driveFolderUrl = resolveEventDriveFolderUrl($ev);
    $driveMedia = $driveFolderUrl !== ''
        ? collectDriveFolderSpotlightMedia($driveFolderUrl, $eventTitleFallback, $eventBodyFallback)
        : [];
    $useDriveFolder = !empty($driveMedia);

    foreach ($ev['images'] ?? [] as $img) {
        if (empty($img['image_path'])) {
            continue;
        }
        if ($useDriveFolder && !isUploadedOrRemoteMediaUrl((string) $img['image_path'])) {
            continue;
        }
        if (empty($img['image_path'])) {
            continue;
        }
        $captionDisabled = !empty($img['caption_disabled']);
        $caption = trim((string)($img['caption'] ?? ''));
        $altText = trim((string)($img['image_alt'] ?? ''));
        $title    = $caption !== '' ? $caption : ($altText !== '' ? $altText : ($captionDisabled ? '' : $eventTitleFallback));
        $headline = $caption !== '' ? $caption : ($captionDisabled ? '' : $eventBodyFallback);

        $enriched = enrichSpotlightMediaItem([
            'url' => $img['image_path'],
            'type' => 'image',
            'title' => $title,
            'headline' => $headline,
        ]);
        if ($enriched) {
            $items[] = $enriched;
        }
    }

    if ($primaryUrl !== '' && (!$useDriveFolder || isUploadedOrRemoteMediaUrl($primaryUrl))) {
        $primary = enrichSpotlightMediaItem(['url' => $primaryUrl, 'type' => 'image']);
        if ($primary) {
            $items = array_values(array_filter(
                $items,
                fn($it) => ($it['url'] ?? '') !== $primary['url']
            ));
            array_unshift($items, $primary);
        }
    }

    if (empty($items) && !$useDriveFolder) {
        foreach ($ev['image_urls'] ?? [] as $url) {
            if (isDriveFolderUrl((string) $url)) {
                continue;
            }
            $enriched = enrichSpotlightMediaItem(['url' => (string)$url, 'type' => 'image']);
            if ($enriched) {
                $items[] = $enriched;
            }
        }
    }

    foreach ($ev['videos'] ?? [] as $video) {
        $enriched = enrichSpotlightMediaItem([
            'url' => (string)($video['media_path'] ?? ''),
            'type' => 'video',
            'title' => $video['title'] ?? '',
        ]);
        if ($enriched) {
            $items[] = $enriched;
        }
    }

    if ($mergeBlocks && !$useDriveFolder) {
        foreach ($contentBlocks as $block) {
            $blockItems = parseContentMediaItems($block);
            foreach ($blockItems as &$bi) {
                if (empty($bi['title']) && !empty($block['title'])) {
                    $bi['title'] = truncatePlain($block['title'], 56);
                }
                if (empty($bi['headline']) && !empty($block['body'])) {
                    $bi['headline'] = truncatePlain($block['body'], 120);
                }
                if (empty($bi['cta_label']) && !empty($block['link_label'])) {
                    $bi['cta_label'] = truncatePlain($block['link_label'], 40);
                }
                if (empty($bi['cta_url']) && !empty($block['link_url'])) {
                    $bi['cta_url'] = $block['link_url'];
                }
            }
            unset($bi);
            $items = array_merge($items, $blockItems);
        }
    }

    $shuffleSeed = (string) ($ev['id'] ?? '') . ':' . date('Y-m-d');
    if ($useDriveFolder) {
        return shuffleSpotlightMedia(dedupeSpotlightMedia(array_merge($driveMedia, $items)), $shuffleSeed);
    }

    return shuffleSpotlightMedia(dedupeSpotlightMedia($items), $shuffleSeed);
}

function collectBlockSpotlightMedia(array $block, array $fallbackMedia = []): array
{
    $items = parseContentMediaItems($block);
    if (empty($items)) {
        return $fallbackMedia;
    }
    return $items;
}

function buildSpotlightSlideFromEvent(array $ev, array $overrides = []): array
{
    $today = new DateTimeImmutable('today');
    $isLive = eventIsLive($ev, $today);
    $start = !empty($ev['date_start']) ? new DateTimeImmutable($ev['date_start']) : null;
    $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;
    $isPast = $end && $today > $end;

    $type = $isLive ? 'live_event' : ($isPast ? 'post_event' : 'upcoming_event');
    $badgeInfo = computeOngoingEventBadge($ev, $today);
    $badge = $overrides['badge'] ?? $badgeInfo['label'];
    $ongoingPhase = $overrides['ongoing_phase'] ?? $badgeInfo['phase'];

    $image = $overrides['image'] ?? ((($ev['image_urls'] ?? [])[0] ?? null) ?: ($ev['image'] ?? ''));
    $headline = $overrides['headline'] ?? ($isLive
        ? truncatePlain($ev['live_message'] ?: 'Sessions are live — join us today.', 82)
        : truncatePlain($ev['announcements'] ?: ($ev['description'] ?? ''), 82));

    $countdownLabel = computeEventCountdown($ev, $today);
    $statusLabel = $isLive ? 'Happening Now' : ($ongoingPhase === 'upcoming' ? $countdownLabel : ($ev['countdown'] ?? $countdownLabel));
    $media = !empty($overrides['media'])
        ? dedupeSpotlightMedia($overrides['media'])
        : collectEventSpotlightMedia($ev, [], false);
    if (empty($media) && $image !== '') {
        $legacy = normalizeSpotlightMediaItem($image, 'image');
        if ($legacy) {
            $media = [$legacy];
        }
    }
    $primaryImage = $media[0]['display_url'] ?? $media[0]['url'] ?? $image;
    if ($primaryImage !== '') {
        $primaryImage = spotlightDisplayUrl($primaryImage);
    }

    $slide = [
        'id' => $overrides['id'] ?? ('event-' . $ev['id']),
        'event_id' => $ev['id'],
        'source' => 'event',
        'type' => $overrides['type'] ?? $type,
        'badge' => $badge,
        'ongoing_phase' => $ongoingPhase,
        'is_live' => array_key_exists('is_live', $overrides) ? (bool) $overrides['is_live'] : $isLive,
        'countdown_label' => $ongoingPhase === 'upcoming' ? $countdownLabel : '',
        'date_start' => $ev['date_start'] ?? '',
        'date_end' => $ev['date_end'] ?? '',
        'title' => $overrides['title'] ?? $ev['title'],
        'headline' => $headline,
        'body' => $overrides['body'] ?? '',
        'image' => $primaryImage,
        'media' => $media,
        'meta' => [
            'date' => $ev['date'] ?? '',
            'location' => truncatePlain($ev['location'] ?? '', 40),
            'speakers' => truncatePlain(str_replace("\n", ' · ', $ev['speakers'] ?? ''), 32),
            'status' => truncatePlain($statusLabel, 18),
        ],
        'cta_primary' => $overrides['cta_primary'] ?? ($isLive ? ($ev['live_cta_label'] ?? 'Register & Join') : 'View Event'),
        'cta_primary_url' => $overrides['cta_primary_url'] ?? (
            ($isLive && !empty($ev['live_cta_url']))
                ? $ev['live_cta_url']
                : ('events.html#evt-' . $ev['id'] . ($isLive ? '&reg=1' : ''))
        ),
        'cta_secondary' => $overrides['cta_secondary'] ?? '',
        'cta_secondary_url' => $overrides['cta_secondary_url'] ?? '',
        'priority' => (int) ($overrides['priority'] ?? (
            (int) ($ev['home_priority'] ?? 0)
            + ($isLive ? 1000 : ($ongoingPhase === 'upcoming' ? ongoingEventUpcomingPriority($ev) : ($ev['pinned'] ? 500 : 0)))
        )),
        'show_in_hero' => $isLive && !empty($ev['show_live_on_home']),
        'updates' => $overrides['updates'] ?? [],
        'recap_items' => [],
        'has_recap' => false,
        'cta_action' => 'link',
    ];

    $slideType = $slide['type'] ?? $type;
    if ($isPast || $slideType === 'post_event') {
        $slide = applyPostEventRecapToSlide($ev, $slide);
    }

    return $slide;
}

/**
 * Build admin-authored recap bullets for past events (homepage Ongoing Now).
 *
 * @return array<int, array{title: string, body: string}>
 */
function buildEventRecapItems(array $ev): array
{
    $items = [];

    $liveMessage = trim((string) ($ev['live_message'] ?? ''));
    if ($liveMessage !== '') {
        $items[] = ['title' => 'Summary', 'body' => $liveMessage];
    }

    $highlights = trim((string) ($ev['highlights'] ?? ''));
    if ($highlights !== '') {
        $parts = preg_split('/\n\s*\n/', $highlights) ?: [$highlights];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $lines = preg_split('/\r\n|\n/', $part) ?: [$part];
            $lines = array_values(array_filter(array_map('trim', $lines), fn($l) => $l !== ''));
            if (count($lines) === 1) {
                $items[] = ['title' => 'Key highlights', 'body' => $lines[0]];
                continue;
            }
            foreach ($lines as $line) {
                $items[] = ['title' => 'Highlight', 'body' => $line];
            }
        }
    }

    $announcements = trim((string) ($ev['announcements'] ?? ''));
    if ($announcements !== '') {
        $items[] = ['title' => 'Notices', 'body' => $announcements];
    }

    foreach ($ev['live_content'] ?? [] as $block) {
        if (isset($block['is_active']) && empty($block['is_active'])) {
            continue;
        }
        $title = trim((string) ($block['title'] ?? ''));
        $body = trim((string) ($block['body'] ?? ''));
        if ($title === '' && $body === '') {
            continue;
        }
        $items[] = ['title' => $title !== '' ? $title : 'Update', 'body' => $body];
    }

    $speakers = trim((string) ($ev['speakers'] ?? ''));
    if ($speakers !== '') {
        $items[] = ['title' => 'People & hosts', 'body' => $speakers];
    }

    return $items;
}

function eventHasRecapSummary(array $ev): bool
{
    return !empty(buildEventRecapItems($ev));
}

function applyPostEventRecapToSlide(array $ev, array $slide): array
{
    $recap = buildEventRecapItems($ev);
    $slide['recap_items'] = $recap;
    $slide['has_recap'] = !empty($recap);

    if (!empty($recap)) {
        $customCta = trim((string) ($ev['recap_cta_label'] ?? ''));
        $slide['cta_primary'] = $customCta !== '' ? $customCta : 'Read more';
        $slide['cta_primary_url'] = '';
        $slide['cta_action'] = 'toggle_recap';
        $slide['cta_secondary'] = '';
        $slide['cta_secondary_url'] = '';
        if (empty($slide['headline']) && !empty($recap[0]['body'])) {
            $slide['headline'] = truncatePlain($recap[0]['body'], 82);
        }
    } else {
        $slide['cta_primary'] = 'View Events';
        $slide['cta_primary_url'] = 'events.html#evt-' . ($ev['id'] ?? '');
        $slide['cta_action'] = 'link';
    }

    return $slide;
}

function buildSpotlightSlideFromCustom(array $row): array
{
    /* Honor per-item type so admin-pasted YouTube/Vimeo/.mp4 links render as videos. */
    $media = dedupeSpotlightMedia(array_values(array_filter(array_map(function ($img) {
        return enrichSpotlightMediaItem([
            'url' => $img['url'],
            'type' => ($img['type'] ?? 'image') === 'video' ? 'video' : 'image',
            'title' => $img['alt'] ?? '',
        ]);
    }, parseSlideImageList($row, 'image_url', 'image_alt')))));

    /* Cover image: first image, falling back to first media item if only videos exist. */
    $coverUrl = '';
    foreach ($media as $m) {
        if (($m['type'] ?? 'image') === 'image') { $coverUrl = $m['display_url'] ?? $m['url'] ?? ''; break; }
    }
    if ($coverUrl === '' && !empty($media)) {
        $coverUrl = $media[0]['display_url'] ?? $media[0]['url'] ?? '';
    }

    $contentType = $row['content_type'] ?? 'announcement';

    return [
        'id' => 'spotlight-' . $row['id'],
        'event_id' => $row['event_id'] ?? null,
        'source' => 'custom',
        'type' => $contentType,
        'badge' => $row['badge_label'] ?: 'Important Update',
        'ongoing_phase' => $contentType,
        'is_live' => $contentType === 'live',
        'title' => $row['title'],
        'headline' => truncatePlain($row['headline'] ?? '', 100),
        'body' => '',
        'image' => $coverUrl,
        'media' => $media,
        'meta' => [
            'date' => '',
            'location' => '',
            'speakers' => '',
            'status' => '',
        ],
        'cta_primary' => $row['cta_primary_label'] ?? '',
        'cta_primary_url' => $row['cta_primary_url'] ?? '',
        'cta_secondary' => $row['cta_secondary_label'] ?? '',
        'cta_secondary_url' => $row['cta_secondary_url'] ?? '',
        'priority' => (int)($row['priority'] ?? 0),
        'show_in_hero' => !empty($row['show_in_hero']),
        'updates' => [],
    ];
}

function truncatePlain(string $text, int $len): string
{
    $text = trim(strip_tags($text));
    if (strlen($text) <= $len) {
        return $text;
    }
    return substr($text, 0, $len) . '…';
}

function defaultOngoingNowSettings(): array
{
    return [
        'section_title' => 'Ongoing Now',
        'section_subtitle' => 'Live updates, recent events, and announcements from HOSU.',
        'subtitle_upcoming' => 'Upcoming HOSU events — save the date and register early.',
        'eyebrow_live' => 'Live · Right Now',
        'eyebrow_upcoming' => 'Upcoming · Save the Date',
        'eyebrow_updates' => 'Updates · From HOSU',
        'show_upcoming_events' => false,
        'show_past_events' => true,
        'show_curated' => true,
        'past_hide_when_upcoming' => false,
        'arrangement' => 'priority',
    ];
}

function describePostEventDisplayMode(int $days): string
{
    return match ($days) {
        0 => 'Hidden after event ends',
        1 => '1 day (just ended / yesterday)',
        4 => '4 days',
        7 => '1 week',
        14 => '2 weeks',
        28 => '4 weeks',
        -1 => 'Until auto-live is turned off',
        -2 => 'Until an upcoming event appears in this section',
        -3 => 'Keep with upcoming events (until turned off)',
        default => $days > 0 ? ($days . ' days') : 'Custom',
    };
}

function normalizeOngoingNowSettings(array $settings = []): array
{
    $merged = array_merge(defaultOngoingNowSettings(), $settings);
    $merged['show_upcoming_events'] = !empty($merged['show_upcoming_events']);
    $merged['show_past_events'] = !isset($merged['show_past_events']) || !empty($merged['show_past_events']);
    $merged['show_curated'] = !isset($merged['show_curated']) || !empty($merged['show_curated']);
    $merged['past_hide_when_upcoming'] = !empty($merged['past_hide_when_upcoming']);
    $merged['arrangement'] = ($merged['arrangement'] ?? 'priority') === 'random' ? 'random' : 'priority';
    return $merged;
}

function fetchOngoingNowSettings(PDO $pdo): array
{
    $saved = loadHomepageSetting($pdo, 'ongoing_now', defaultOngoingNowSettings());
    return normalizeOngoingNowSettings(is_array($saved) ? $saved : []);
}

function computeOngoingEventBadge(array $ev, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    if (eventIsLive($ev, $today)) {
        return ['phase' => 'live', 'label' => 'Live Now'];
    }

    $start = !empty($ev['date_start']) ? new DateTimeImmutable($ev['date_start']) : null;
    $end = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;

    if ($start && $today < $start) {
        $countdown = computeEventCountdown($ev, $today);
        return ['phase' => 'upcoming', 'label' => $countdown ?: 'Coming Soon'];
    }

    if ($end && $today > $end) {
        $yesterday = $today->modify('-1 day');
        if ($end->format('Y-m-d') === $yesterday->format('Y-m-d')) {
            return ['phase' => 'just_ended', 'label' => 'Just Ended'];
        }
        $twoDaysAgo = $today->modify('-2 days');
        if ($end->format('Y-m-d') === $twoDaysAgo->format('Y-m-d')) {
            return ['phase' => 'yesterday', 'label' => 'Yesterday'];
        }
        $dateLabel = trim((string) ($ev['date'] ?? ''));
        if ($dateLabel === '') {
            $dateLabel = $end->format('j M Y');
        }
        return ['phase' => 'past', 'label' => 'Past Event · ' . $dateLabel];
    }

    return ['phase' => 'featured', 'label' => 'Featured'];
}

function eventPassesAdminDisplayWindow(array $ev, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? new DateTimeImmutable();
    if (!empty($ev['display_start'])) {
        try {
            if ($now < new DateTimeImmutable($ev['display_start'])) {
                return false;
            }
        } catch (Exception $e) { /* ignore */ }
    }
    if (!empty($ev['display_end'])) {
        try {
            if ($now > new DateTimeImmutable($ev['display_end'])) {
                return false;
            }
        } catch (Exception $e) { /* ignore */ }
    }
    return true;
}

function eventInOngoingUpcomingPeriod(array $ev, ?DateTimeImmutable $today = null): bool
{
    $today = $today ?? new DateTimeImmutable('today');
    if (empty($ev['show_upcoming_in_ongoing']) && empty($ev['show_live_on_home'])) {
        return false;
    }
    if (eventIsLive($ev, $today)) {
        return false;
    }
    $start = !empty($ev['date_start']) ? new DateTimeImmutable($ev['date_start']) : null;
    if (!$start || $today >= $start) {
        return false;
    }
    return eventPassesAdminDisplayWindow($ev);
}

function ongoingEventUpcomingPriority(array $ev): int
{
    $base = (int) ($ev['home_priority'] ?? 0) + ($ev['pinned'] ? 200 : 0);
    if (empty($ev['date_start'])) {
        return $base;
    }
    try {
        $start = new DateTimeImmutable($ev['date_start']);
        $days = (new DateTimeImmutable('today'))->diff($start)->days;
        return $base + max(0, 365 - (int) $days);
    } catch (Exception $e) {
        return $base;
    }
}

function enrichOngoingSlideFromLinkedEvent(array $slide, array $eventsById, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $eid = $slide['event_id'] ?? null;
    if (!$eid || !isset($eventsById[$eid])) {
        return $slide;
    }
    $ev = $eventsById[$eid];
    if (eventIsLive($ev, $today)) {
        return $slide;
    }
    $start = !empty($ev['date_start']) ? new DateTimeImmutable($ev['date_start']) : null;
    if ($start && $today < $start) {
        $countdown = computeEventCountdown($ev, $today);
        $slide['ongoing_phase'] = 'upcoming';
        $slide['countdown_label'] = $countdown;
        $slide['date_start'] = $ev['date_start'] ?? '';
        $slide['date_end'] = $ev['date_end'] ?? '';
        $slide['meta'] = is_array($slide['meta'] ?? null) ? $slide['meta'] : [];
        $slide['meta']['status'] = $countdown;
        $slide['meta']['date'] = $slide['meta']['date'] ?? ($ev['date'] ?? '');
        $slide['meta']['location'] = $slide['meta']['location'] ?? truncatePlain($ev['location'] ?? '', 40);
        if (($slide['type'] ?? '') === 'upcoming_event' || empty($slide['badge']) || $slide['badge'] === 'Important Update') {
            $slide['badge'] = $countdown ?: 'Coming Soon';
        }
        $slide['priority'] = max((int) ($slide['priority'] ?? 0), ongoingEventUpcomingPriority($ev));
    }
    return $slide;
}

function eventInOngoingPostEventPeriod(
    array $ev,
    ?DateTimeImmutable $today = null,
    bool $hasUpcomingInSection = false,
    array $settings = []
): bool {
    $today = $today ?? new DateTimeImmutable('today');
    $now = new DateTimeImmutable();
    $settings = normalizeOngoingNowSettings($settings);

    if (empty($ev['show_live_on_home']) || eventIsLive($ev, $today)) {
        return false;
    }

    $end = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : null;
    if (!$end || $today <= $end) {
        return false;
    }

    if (!eventPassesAdminDisplayWindow($ev, $now)) {
        return false;
    }

    $days = (int) ($ev['post_event_display_days'] ?? 0);
    if ($days === 0) {
        return true;
    }

    if ($hasUpcomingInSection) {
        if ($days === -2) {
            return false;
        }
        if (!empty($settings['past_hide_when_upcoming']) && $days !== -3) {
            return false;
        }
    }

    if ($days === -1 || $days === -3) {
        return true;
    }
    if ($days === -2) {
        return true;
    }

    $graceEnd = $end->modify('+' . $days . ' days')->setTime(23, 59, 59);
    return $now <= $graceEnd;
}

function sortOngoingSlides(array $slides, string $arrangement = 'priority'): array
{
    if ($arrangement === 'random' && count($slides) > 1) {
        $seed = crc32(date('Y-m-d-H'));
        usort($slides, function ($a, $b) use ($seed) {
            $ha = crc32(($a['id'] ?? '') . ':' . $seed) % 10000;
            $hb = crc32(($b['id'] ?? '') . ':' . $seed) % 10000;
            return $ha <=> $hb;
        });
        return $slides;
    }

    usort($slides, function ($a, $b) {
        if (($a['priority'] ?? 0) !== ($b['priority'] ?? 0)) {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        }
        return strcmp($a['id'] ?? '', $b['id'] ?? '');
    });

    return $slides;
}

/**
 * Ongoing Now carousel: live events take over on event day; otherwise mix per admin settings.
 */
function buildOngoingNowSlides(array $events, array $customSpotlights = [], $settings = []): array
{
    $settings = is_string($settings)
        ? normalizeOngoingNowSettings(['arrangement' => $settings])
        : normalizeOngoingNowSettings(is_array($settings) ? $settings : []);
    $arrangement = $settings['arrangement'];
    $today = new DateTimeImmutable('today');
    $now = new DateTimeImmutable();
    $liveSlides = [];
    $mixedSlides = [];
    $eventsById = [];
    foreach ($events as $ev) {
        if (!empty($ev['id'])) {
            $eventsById[(string) $ev['id']] = $ev;
        }
    }

    foreach ($events as $ev) {
        if (empty($ev['show_live_on_home']) || !eventPassesAdminDisplayWindow($ev, $now)) {
            continue;
        }
        if (!eventIsLive($ev, $today)) {
            continue;
        }

        $blocks = filterEventContentBlocks($ev['live_content'] ?? [], $ev, 'live');
        $feedBlocks = array_values(array_filter($blocks, fn($b) => !empty($b['title']) || !empty($b['body'])));
        $eventMedia = collectEventSpotlightMedia($ev, $blocks, true);
        $badge = computeOngoingEventBadge($ev, $today);

        $liveSlides[] = buildSpotlightSlideFromEvent($ev, [
            'type' => 'live_event',
            'badge' => $badge['label'],
            'ongoing_phase' => $badge['phase'],
            'updates' => $feedBlocks,
            'media' => $eventMedia,
        ]);
    }

    if (!empty($liveSlides)) {
        return sortOngoingSlides($liveSlides, $arrangement);
    }

    $upcomingSlides = [];
    if (!empty($settings['show_upcoming_events'])) {
        foreach ($events as $ev) {
            if (!eventInOngoingUpcomingPeriod($ev, $today)) {
                continue;
            }
            $blocks = filterEventContentBlocks($ev['live_content'] ?? [], $ev, 'homepage');
            $eventMedia = collectEventSpotlightMedia($ev, $blocks, false);
            $badge = computeOngoingEventBadge($ev, $today);

            $upcomingSlides[] = buildSpotlightSlideFromEvent($ev, [
                'type' => 'upcoming_event',
                'badge' => $badge['label'],
                'ongoing_phase' => $badge['phase'],
                'is_live' => false,
                'headline' => truncatePlain($ev['announcements'] ?: ($ev['description'] ?? ''), 82),
                'cta_primary' => 'View & Register',
                'media' => $eventMedia,
            ]);
        }
    }
    $mixedSlides = array_merge($mixedSlides, $upcomingSlides);
    $hasUpcomingInSection = !empty($upcomingSlides);

    if (!empty($settings['show_past_events'])) {
        foreach ($events as $ev) {
            if (!eventInOngoingPostEventPeriod($ev, $today, $hasUpcomingInSection, $settings)) {
                continue;
            }

            $blocks = filterEventContentBlocks($ev['live_content'] ?? [], $ev, 'homepage');
            $eventMedia = collectEventSpotlightMedia($ev, $blocks, true);
            $badge = computeOngoingEventBadge($ev, $today);

            $mixedSlides[] = buildSpotlightSlideFromEvent($ev, [
                'type' => 'post_event',
                'badge' => $badge['label'],
                'ongoing_phase' => $badge['phase'],
                'is_live' => false,
                'headline' => truncatePlain($ev['highlights'] ?: $ev['announcements'] ?: ($ev['description'] ?? ''), 82),
                'media' => $eventMedia,
                'updates' => array_values(array_filter($blocks, fn($b) => !empty($b['title']) || !empty($b['body']))),
            ]);
        }
    }

    if (!empty($settings['show_curated'])) {
        foreach ($customSpotlights as $row) {
            if (empty($row['show_in_spotlight']) || !spotlightRowIsActive($row, $now)) {
                continue;
            }
            $slide = buildSpotlightSlideFromCustom($row);
            $mixedSlides[] = enrichOngoingSlideFromLinkedEvent($slide, $eventsById, $today);
        }
    }

    // Admin-enabled live-on-home: surface whenever within the display window,
    // even outside live dates or default post-event grace settings.
    $onSlideIds = [];
    foreach (array_merge($liveSlides, $mixedSlides) as $slide) {
        if (!empty($slide['event_id'])) {
            $onSlideIds[(string) $slide['event_id']] = true;
        }
    }

    foreach ($events as $ev) {
        if (empty($ev['show_live_on_home']) || !eventPassesAdminDisplayWindow($ev, $now)) {
            continue;
        }
        $eid = (string) ($ev['id'] ?? '');
        if ($eid === '' || isset($onSlideIds[$eid]) || eventIsLive($ev, $today)) {
            continue;
        }

        $badge = computeOngoingEventBadge($ev, $today);
        $isUpcoming = !empty($ev['date_start']) && $today < new DateTimeImmutable($ev['date_start']);
        $isPast = eventHasPassed($ev, $today);
        $visibility = $isPast || $isUpcoming ? 'homepage' : 'live';
        $blocks = filterEventContentBlocks($ev['live_content'] ?? [], $ev, $visibility);
        $eventMedia = collectEventSpotlightMedia($ev, $blocks, $isPast || eventIsLive($ev, $today));

        if ($isUpcoming) {
            $mixedSlides[] = buildSpotlightSlideFromEvent($ev, [
                'type' => 'upcoming_event',
                'badge' => $badge['label'],
                'ongoing_phase' => $badge['phase'],
                'is_live' => false,
                'headline' => truncatePlain($ev['announcements'] ?: ($ev['description'] ?? ''), 82),
                'cta_primary' => 'View & Register',
                'media' => $eventMedia,
            ]);
            continue;
        }

        $feedBlocks = array_values(array_filter($blocks, fn($b) => !empty($b['title']) || !empty($b['body'])));
        $mixedSlides[] = buildSpotlightSlideFromEvent($ev, [
            'type' => $isPast ? 'post_event' : 'live_event',
            'badge' => $badge['label'],
            'ongoing_phase' => $badge['phase'],
            'is_live' => false,
            'headline' => truncatePlain(
                $isPast
                    ? ($ev['highlights'] ?: $ev['announcements'] ?: ($ev['description'] ?? ''))
                    : ($ev['live_message'] ?: $ev['announcements'] ?: ($ev['description'] ?? '')),
                82
            ),
            'updates' => $feedBlocks,
            'media' => $eventMedia,
        ]);
    }

    return sortOngoingSlides($mixedSlides, $arrangement);
}

/**
 * Admin planner: what is on the homepage vs what is in the admin's lineup pool.
 *
 * @return array<string, mixed>
 */
function fetchOngoingAdminPanel(PDO $pdo): array
{
    migrateEventSchema($pdo);
    autoExpirePastEvents($pdo);

    $settings = fetchOngoingNowSettings($pdo);
    $payload = fetchHomeSpotlightPayload($pdo);
    $today = new DateTimeImmutable('today');
    $now = new DateTimeImmutable();

    $evRows = $pdo->query(
        'SELECT * FROM events
         WHERE show_live_on_home = 1 OR show_upcoming_in_ongoing = 1
         ORDER BY home_priority DESC, updated_at DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
    $events = [];
    foreach ($evRows as $ev) {
        enrichEventRow($ev, $today);
        $events[] = $ev;
    }
    $ids = array_column($events, 'id');
    attachGalleryToEvents($events, loadEventGalleries($pdo, $ids));
    attachMediaToEvents($events, loadEventMedia($pdo, $ids));
    attachLiveContentToEvents($events, loadLiveContent($pdo, $ids, true), true);

    $spotlights = loadHomepageSpotlights($pdo, false);
    $displayingIds = [];
    foreach ($payload['spotlight_slides'] as $slide) {
        if (!empty($slide['event_id'])) {
            $displayingIds['event:' . $slide['event_id']] = true;
        } elseif (!empty($slide['id']) && strpos((string) $slide['id'], 'spotlight-') === 0) {
            $displayingIds['spotlight:' . str_replace('spotlight-', '', (string) $slide['id'])] = true;
        }
    }

    $lineupCurated = [];
    $availableCurated = [];
    foreach ($spotlights as $row) {
        $active = spotlightRowIsActive($row, $now);
        $inLineup = !empty($row['show_in_spotlight']);
        $item = [
            'id' => (int) $row['id'],
            'title' => $row['title'] ?? '',
            'headline' => truncatePlain($row['headline'] ?? '', 80),
            'content_type' => $row['content_type'] ?? 'announcement',
            'priority' => (int) ($row['priority'] ?? 0),
            'is_active' => !empty($row['is_active']),
            'in_lineup' => $inLineup,
            'on_homepage' => isset($displayingIds['spotlight:' . $row['id']]),
            'event_id' => $row['event_id'] ?? null,
        ];
        if ($inLineup) {
            $lineupCurated[] = $item;
        } elseif ($active) {
            $availableCurated[] = $item;
        }
    }

    $lineupUpcoming = [];
    $availableUpcoming = [];
    $automaticLive = [];
    $lineupPast = [];

    foreach ($events as $ev) {
        $eid = (string) $ev['id'];
        $onHome = isset($displayingIds['event:' . $eid]);
        $countdown = computeEventCountdown($ev, $today);
        $base = [
            'id' => $eid,
            'title' => $ev['title'] ?? '',
            'date' => $ev['date'] ?? '',
            'countdown' => $countdown,
            'show_live_on_home' => !empty($ev['show_live_on_home']),
            'show_upcoming_in_ongoing' => !empty($ev['show_upcoming_in_ongoing']),
            'on_homepage' => $onHome,
        ];

        if (!empty($ev['show_live_on_home'])) {
            $automaticLive[] = array_merge($base, [
                'phase' => !empty($ev['is_live']) ? 'live' : (eventInOngoingUpcomingPeriod($ev, $today) ? 'waiting' : 'scheduled'),
                'note' => !empty($ev['is_live']) ? 'Live now — homepage shows this automatically' : 'Goes live automatically on event day',
            ]);
        }

        if (eventInOngoingUpcomingPeriod($ev, $today)) {
            $lineupUpcoming[] = array_merge($base, ['phase' => 'upcoming']);
        } elseif (!empty($ev['date_start'])) {
            try {
                $start = new DateTimeImmutable($ev['date_start']);
                if ($today < $start && eventPassesAdminDisplayWindow($ev, $now) && empty($ev['show_upcoming_in_ongoing'])) {
                    $availableUpcoming[] = array_merge($base, [
                        'phase' => 'upcoming',
                        'note' => 'Add to lineup for countdown preview',
                    ]);
                }
            } catch (Exception $e) { /* ignore */ }
        }

        $hasUpcoming = !empty($lineupUpcoming);
        if (eventInOngoingPostEventPeriod($ev, $today, $hasUpcoming, $settings) && !empty($settings['show_past_events'])) {
            $badge = computeOngoingEventBadge($ev, $today);
            $days = (int) ($ev['post_event_display_days'] ?? 0);
            $lineupPast[] = array_merge($base, [
                'phase' => $badge['phase'],
                'badge' => $badge['label'],
                'post_event_mode' => describePostEventDisplayMode($days),
                'note' => describePostEventDisplayMode($days),
            ]);
        }
    }

    usort($lineupCurated, fn($a, $b) => ($b['priority'] <=> $a['priority']) ?: strcmp($a['title'], $b['title']));
    usort($availableCurated, fn($a, $b) => ($b['priority'] <=> $a['priority']) ?: strcmp($a['title'], $b['title']));

    return [
        'settings' => $settings,
        'displaying_now' => $payload['spotlight_slides'],
        'ongoing_mode' => $payload['ongoing_mode'] ?? 'empty',
        'has_live' => $payload['has_live'] ?? false,
        'lineup' => [
            'curated' => $lineupCurated,
            'upcoming_events' => $lineupUpcoming,
            'past_events' => $lineupPast,
        ],
        'available' => [
            'curated' => $availableCurated,
            'upcoming_events' => $availableUpcoming,
        ],
        'automatic_live' => $automaticLive,
    ];
}

function computeOngoingSectionMode(array $slides, bool $hasLive): string
{
    if ($hasLive) {
        return 'live';
    }
    if (empty($slides)) {
        return 'empty';
    }
    $phases = [];
    foreach ($slides as $slide) {
        $phases[] = $slide['ongoing_phase'] ?? 'other';
    }
    $phases = array_values(array_unique($phases));
    if (count($phases) === 1 && $phases[0] === 'upcoming') {
        return 'upcoming';
    }
    if (!empty(array_intersect($phases, ['past', 'yesterday'])) && !in_array('upcoming', $phases, true)) {
        return 'past';
    }
    return 'mixed';
}

function buildSpotlightSlides(array $events, array $customSpotlights = [], bool $liveOnly = false): array
{
    unset($liveOnly);
    return buildOngoingNowSlides($events, $customSpotlights, defaultOngoingNowSettings());
}

/**
 * Lightweight payload for homepage spotlight + hero bridge (no full event listing).
 */
function fetchHomeSpotlightPayload(PDO $pdo): array
{
    migrateEventSchema($pdo);
    autoExpirePastEvents($pdo);

    $stmt = $pdo->query('
        SELECT * FROM events
        WHERE show_live_on_home = 1
        ORDER BY home_priority DESC, updated_at DESC, created_at DESC
    ');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $today = new DateTimeImmutable('today');
    $events = [];

    foreach ($rows as $ev) {
        enrichEventRow($ev, $today);
        $events[] = $ev;
    }

    $ids = array_column($events, 'id');
    attachGalleryToEvents($events, loadEventGalleries($pdo, $ids));
    attachMediaToEvents($events, loadEventMedia($pdo, $ids));
    attachLiveContentToEvents($events, loadLiveContent($pdo, $ids, true), true);

    $settings = fetchOngoingNowSettings($pdo);
    $customSpotlights = loadHomepageSpotlights($pdo, true);
    $spotlightSlides = buildOngoingNowSlides($events, $customSpotlights, $settings);
    $hasLiveEvent = !empty(array_filter($spotlightSlides, fn($s) => !empty($s['is_live'])));
    $ongoingMode = computeOngoingSectionMode($spotlightSlides, $hasLiveEvent);
    $heroSpotlights = array_values(array_filter($spotlightSlides, fn($s) => !empty($s['show_in_hero'])));

    return [
        'spotlight_slides' => $spotlightSlides,
        'hero_spotlights' => $heroSpotlights,
        'has_live' => $hasLiveEvent,
        'ongoing_mode' => $ongoingMode,
        'ongoing_settings' => $settings,
    ];
}

function fetchHomeFeaturedPayload(PDO $pdo): array
{
    migrateEventSchema($pdo);
    autoExpirePastEvents($pdo);

    $evStmt = $pdo->query("
        SELECT id, title, description, date, date_start, date_end, location, image, type, countdown,
               is_free, event_fee, status, featured, pinned, home_priority,
               display_start, display_end, display_for_event, speakers, highlights, announcements
        FROM events
        WHERE featured = 1 OR pinned = 1
        ORDER BY pinned DESC, home_priority DESC, date_start ASC
        LIMIT 12
    ");
    $events = $evStmt->fetchAll(PDO::FETCH_ASSOC);
    $today = new DateTimeImmutable('today');

    foreach ($events as &$ev) {
        enrichEventRow($ev, $today);
    }
    unset($ev);

    $events = array_values(array_filter($events, fn($ev) => ($ev['featured'] || $ev['pinned']) && $ev['is_display_active']));
    usort($events, function ($a, $b) {
        if ($a['pinned'] !== $b['pinned']) return $b['pinned'] <=> $a['pinned'];
        if ($a['home_priority'] !== $b['home_priority']) return $b['home_priority'] <=> $a['home_priority'];
        if ($a['is_live'] !== $b['is_live']) return $b['is_live'] <=> $a['is_live'];
        return strcmp($a['date_start'] ?? '', $b['date_start'] ?? '');
    });
    $events = array_slice($events, 0, 6);

    $ids = array_column($events, 'id');
    attachGalleryToEvents($events, loadEventGalleries($pdo, $ids));
    attachMediaToEvents($events, loadEventMedia($pdo, $ids));

    $publications = [];
    try {
        $pubStmt = $pdo->query("SELECT id, title, authors, pub_type, pub_date, link, link_label FROM publications WHERE show_on_home = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 3");
        $publications = $pubStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $_e) { /* ignore */ }

    $grants = [];
    try {
        $grantStmt = $pdo->query("SELECT id, title, amount, currency, deadline, status, description FROM grants_opportunities WHERE show_on_home = 1 AND status != 'closed' ORDER BY sort_order ASC, created_at DESC LIMIT 3");
        $grants = $grantStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $_e) { /* ignore */ }

    return [
        'events' => $events,
        'publications' => $publications,
        'grants' => $grants,
    ];
}

function fetchEventsPagePayload(PDO $pdo): array
{
    migrateEventSchema($pdo);
    autoExpirePastEvents($pdo);

    $stmt = $pdo->query('SELECT * FROM events ORDER BY created_at DESC');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return bucketEventsForPublic($rows, $pdo);
}

function normalizeHeroSlideRow(array $row): array
{
    $row['is_active'] = (bool)($row['is_active'] ?? 1);
    $row['sort_order'] = (int)($row['sort_order'] ?? 0);
    $row['pills'] = [];
    if (!empty($row['pills_json'])) {
        $decoded = json_decode($row['pills_json'], true);
        if (is_array($decoded)) {
            $row['pills'] = $decoded;
        }
    }
    $row['images'] = expandHeroSlideImages(parseSlideImageList($row, 'image_path', 'image_alt'));
    foreach ($row['images'] as &$heroImg) {
        if (isDriveFolderUrl($heroImg['url'] ?? '')) {
            continue;
        }
        $heroImg['display_url'] = spotlightDisplayUrl($heroImg['url'] ?? '');
    }
    unset($heroImg);
    $row['images'] = array_values(array_filter($row['images'], function ($img) {
        return !empty($img['display_url']) || (!isDriveFolderUrl($img['url'] ?? '') && !empty($img['url']));
    }));
    if (!empty($row['images'])) {
        $row['image_path'] = $row['images'][0]['display_url'] ?: $row['images'][0]['url'];
        $imageAlt = trim((string)($row['image_alt'] ?? ''));
        if ($imageAlt === '' && !empty($row['images'][0]['alt'])) {
            $row['image_alt'] = $row['images'][0]['alt'];
        }
    }
    return $row;
}

/** One-time DB repair: replace a stored Drive folder link with expanded file URLs. */
function persistHeroSlideExpandedImages(PDO $pdo, array $rawRow, array $normalized): void
{
    $id = (int)($rawRow['id'] ?? 0);
    if ($id <= 0 || empty($normalized['images']) || count($normalized['images']) <= 1) {
        return;
    }
    $legacyPath = trim((string)($rawRow['image_path'] ?? ''));
    $jsonRaw = (string)($rawRow['images_json'] ?? '');
    $hadFolder = isDriveFolderUrl($legacyPath)
        || ($jsonRaw !== '' && stripos($jsonRaw, '/folders/') !== false);
    if (!$hadFolder) {
        return;
    }
    $items = [];
    foreach ($normalized['images'] as $img) {
        $entry = ['url' => $img['url'], 'alt' => $img['alt'] ?? ''];
        foreach (['title', 'body', 'headline', 'cta_label', 'cta_url'] as $k) {
            if (!empty($img[$k])) {
                $entry[$k] = $img[$k];
            }
        }
        $items[] = $entry;
    }
    $first = $normalized['images'][0];
    $cover = $first['url'] ?? '';
    try {
        $pdo->prepare('UPDATE homepage_hero_slides SET images_json = ?, image_path = ? WHERE id = ?')
            ->execute([encodeSlideImagesJson($items), $cover, $id]);
    } catch (Exception $e) {
        /* non-fatal */
    }
}

function normalizeHeroSlideRowWithPersist(PDO $pdo, array $row): array
{
    $raw = $row;
    $normalized = normalizeHeroSlideRow($row);
    persistHeroSlideExpandedImages($pdo, $raw, $normalized);
    return $normalized;
}

function heroSlideIsDisplayActive(array $slide, ?DateTimeImmutable $now = null): bool
{
    if (empty($slide['is_active'])) {
        return false;
    }
    $now = $now ?? new DateTimeImmutable();
    if (!empty($slide['display_start'])) {
        $ds = new DateTimeImmutable($slide['display_start']);
        if ($now < $ds) {
            return false;
        }
    }
    if (!empty($slide['display_end'])) {
        $de = new DateTimeImmutable($slide['display_end']);
        if ($now > $de) {
            return false;
        }
    }
    return true;
}

function loadHomepageHeroSlides(PDO $pdo, bool $activeOnly = true, bool $attemptRestore = true): array
{
    $rows = [];
    try {
        migrateEventSchema($pdo);
        $sql = 'SELECT * FROM homepage_hero_slides';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { /* table may not exist yet */ }

    $now = new DateTimeImmutable();
    $out = [];
    foreach ($rows as $row) {
        $row = normalizeHeroSlideRowWithPersist($pdo, $row);
        if ($activeOnly && !heroSlideIsDisplayActive($row, $now)) {
            continue;
        }
        $out[] = $row;
    }

    if (empty($out) && $activeOnly && $attemptRestore) {
        // Do not auto-write default hero slides into the database on page render.
        // The live site should preserve only existing data and show defaults in memory only.
        return heroSlidesFromDefaultSeed();
    }

    if (empty($out) && $activeOnly) {
        return heroSlidesFromDefaultSeed();
    }

    return $out;
}

function slugifyHeroKey(string $title, int $id = 0): string
{
    $key = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    if ($key === '') {
        $key = 'slide-' . $id;
    }
    return substr($key, 0, 80);
}

function defaultHeroImageSettings(): array
{
    return [
        'mode' => 'per_slide',
        'pool' => [],
        'pool_alt' => '',
    ];
}

function normalizeHeroPoolImages(array $items): array
{
    $items = expandHeroSlideImages(dedupeSlideImages($items));
    $out = [];
    foreach ($items as $img) {
        if (isDriveFolderUrl($img['url'] ?? '')) {
            continue;
        }
        $img['display_url'] = spotlightDisplayUrl($img['url'] ?? '');
        if (!empty($img['display_url']) || !empty($img['url'])) {
            $out[] = $img;
        }
    }
    return $out;
}

function loadHeroImageSettings(PDO $pdo): array
{
    $raw = loadHomepageSetting($pdo, 'hero_images', defaultHeroImageSettings());
    $mode = (($raw['mode'] ?? '') === 'global_pool') ? 'global_pool' : 'per_slide';
    $pool = normalizeHeroPoolImages(is_array($raw['pool'] ?? null) ? $raw['pool'] : []);
    return [
        'mode' => $mode,
        'pool' => $pool,
        'pool_alt' => trim((string)($raw['pool_alt'] ?? '')),
    ];
}

function saveHeroImageSettings(PDO $pdo, string $mode, array $pool, string $poolAlt = ''): array
{
    $mode = ($mode === 'global_pool') ? 'global_pool' : 'per_slide';
    $pool = normalizeHeroPoolImages($pool);
    $payload = [
        'mode' => $mode,
        'pool' => array_map(function ($img) {
            return [
                'url' => $img['url'] ?? '',
                'alt' => trim((string)($img['alt'] ?? '')),
            ];
        }, $pool),
        'pool_alt' => trim($poolAlt),
    ];
    saveHomepageSetting($pdo, 'hero_images', $payload);
    return loadHeroImageSettings($pdo);
}

function collectPostedHeroPoolImages(string $defaultAlt = ''): array
{
    require_once __DIR__ . '/upload_helper.php';

    $items = parsePostedSlideImages($_POST['pool_images_json'] ?? '[]', $defaultAlt);
    $items = appendSlideImageUrlsFromText(trim($_POST['pool_image_urls'] ?? ''), $items, $defaultAlt);

    if (!empty($_FILES['poolImageFiles']) && is_array($_FILES['poolImageFiles']['name'])) {
        foreach ($_FILES['poolImageFiles']['name'] as $i => $name) {
            if (($_FILES['poolImageFiles']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $file = [
                'name' => $_FILES['poolImageFiles']['name'][$i],
                'type' => $_FILES['poolImageFiles']['type'][$i],
                'tmp_name' => $_FILES['poolImageFiles']['tmp_name'][$i],
                'error' => $_FILES['poolImageFiles']['error'][$i],
                'size' => $_FILES['poolImageFiles']['size'][$i],
            ];
            $up = secureUpload($file, 'uploads/hero/pool/', false, 8000000);
            if ($up) {
                $items[] = ['url' => $up, 'alt' => $defaultAlt];
            }
        }
    }

    return normalizeHeroPoolImages($items);
}

function defaultHomepagePartners(): array
{
    return [
        'title' => 'Our Partners',
        'visible' => true,
        'items' => [
            ['name' => 'Uganda Cancer Institute', 'logo' => 'img/uci.png', 'css_class' => 'partner-logo--uci'],
            ['name' => 'Adonis Healthcare', 'logo' => 'img/ado.png', 'css_class' => ''],
            ['name' => 'Aga Khan University Hospital', 'logo' => 'img/Agakhan.png', 'css_class' => ''],
            ['name' => 'Future Healthcare', 'logo' => 'img/future.png', 'css_class' => ''],
            ['name' => 'Hetero', 'logo' => 'img/hetero.png', 'css_class' => ''],
            ['name' => 'Metropolis', 'logo' => 'img/metro.png', 'css_class' => ''],
            ['name' => 'MSN', 'logo' => 'img/msn.png', 'css_class' => ''],
            ['name' => 'Mulago National Referral Hospital', 'logo' => 'img/mulago.png', 'css_class' => 'partner-logo--mulago'],
            ['name' => 'Nsambya Hospital', 'logo' => 'img/nsambya.png', 'css_class' => ''],
            ['name' => 'OncoPharm', 'logo' => 'img/onco.png', 'css_class' => 'partner-logo--onco'],
        ],
    ];
}

function defaultHomepageCta(): array
{
    return [
        'title' => 'Make a Difference',
        'body' => 'Join us in our mission to eliminate suffering from cancer and blood diseases through care, research, education, and advocacy.',
        'primary_label' => 'Learn More About HOSU',
        'primary_url' => 'about.html',
        'secondary_label' => 'Support Us',
        'secondary_action' => 'donate',
        'secondary_url' => '',
        'visible' => true,
    ];
}

/**
 * Read admin-managed homepage settings from the database only.
 * Code defaults are empty shells for new installs — they never overwrite saved rows.
 */
function loadHomepageSetting(PDO $pdo, string $key, ?array $default = null): array
{
    try {
        migrateEventSchema($pdo);
        $stmt = $pdo->prepare('SELECT setting_value FROM homepage_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $raw = $stmt->fetchColumn();
        if ($raw !== false && $raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    } catch (Exception $e) { /* ignore */ }
    return $default ?? [];
}

function saveHomepageSetting(PDO $pdo, string $key, array $value): void
{
    migrateEventSchema($pdo);
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO homepage_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
        ->execute([$key, $json]);
}

function fetchHomepageExtrasPayload(PDO $pdo): array
{
    $partners = loadHomepageSetting($pdo, 'partners', defaultHomepagePartners());
    $cta = loadHomepageSetting($pdo, 'cta', defaultHomepageCta());
    if (!isset($partners['items']) || !is_array($partners['items'])) {
        $partners['items'] = [];
    }
    if (empty($partners['items'])) {
        $partners = defaultHomepagePartners();
    }
    if (empty(trim((string) ($cta['title'] ?? '')))) {
        $cta = defaultHomepageCta();
    }
    if (!array_key_exists('visible', $partners)) {
        $partners['visible'] = !empty($partners['items']);
    }
    if (!array_key_exists('visible', $cta)) {
        $cta['visible'] = !empty($cta['title']) || !empty($cta['body']);
    }
    return [
        'partners' => $partners,
        'cta' => $cta,
        'ongoing_settings' => fetchOngoingNowSettings($pdo),
    ];
}

function defaultSiteChrome(): array
{
    return [
        'meta' => [
            'home_title' => 'HOSU - Hematology & Oncology Society of Uganda',
            'home_description' => 'Discover, prevent, and cure — advancing hematology and oncology care across Uganda.',
            'site_name' => 'HOSU',
        ],
        'carousel' => [
            'interval_ms' => 6000,
        ],
        'navbar' => [
            'logo' => 'img/logo2.png',
            'logo_alt' => 'HOSU - Hematology & Oncology Society of Uganda',
            'portal_label' => 'Member Portal',
            'links' => [
                ['label' => 'Home', 'url' => 'index.html'],
                ['label' => 'Events', 'url' => 'events.html'],
                ['label' => 'Research', 'url' => 'research.html'],
                ['label' => 'Membership', 'url' => 'membership.html'],
                ['label' => 'About', 'url' => 'about.html'],
                ['label' => 'Blog', 'url' => 'blog.html'],
                ['label' => 'Contact', 'url' => 'contact.html'],
            ],
        ],
        'footer' => [
            'copyright' => '© 2026 Hematology & Oncology Society of Uganda. All rights reserved.',
            'quick_links_title' => 'Quick Links',
            'quick_links' => [
                ['label' => 'About HOSU', 'url' => 'about.html'],
                ['label' => 'Membership', 'url' => 'membership.html'],
                ['label' => 'Research', 'url' => 'research.html'],
                ['label' => 'Events', 'url' => 'events.html'],
                ['label' => 'Blog', 'url' => 'blog.html'],
                ['label' => 'Contact Us', 'url' => 'contact.html'],
            ],
            'contact_title' => 'Contact Us',
            'contact_lines' => ['Mulago Hospital Complex', 'Kampala, Uganda', 'P.O. Box 170251'],
            'phone' => '+256 766 529869',
            'whatsapp' => '+256 709 752107',
            'email' => 'info@hosu.or.ug',
            'website' => 'https://hosu.or.ug',
            'social_title' => 'Stay Connected',
            'social_blurb' => 'Follow us for updates.',
            'social' => [
                'linkedin' => 'https://www.linkedin.com/company/hematology-oncology-society-of-uganda',
                'twitter' => 'https://x.com/Hem0nc_Uganda',
                'facebook' => '',
                'youtube' => '',
            ],
            'support_title' => 'Support',
            'support_name' => 'Official HOSU Support',
        ],
        'donate' => [
            'float_label' => 'Support Us',
            'modal_title' => 'Support HOSU',
            'modal_subtitle' => 'Your donation transforms cancer care in Uganda.',
            'amounts' => [5000, 10000, 25000, 50000],
            'min_amount' => 1000,
        ],
    ];
}

function isListArray(array $arr): bool
{
    if ($arr === []) {
        return true;
    }

    return array_keys($arr) === range(0, count($arr) - 1);
}

function mergeSiteChrome(array $defaults, array $saved): array
{
    foreach ($defaults as $key => $val) {
        if (!array_key_exists($key, $saved) || $saved[$key] === null) {
            $saved[$key] = $val;
            continue;
        }
        if (!is_array($val) || !is_array($saved[$key])) {
            if (is_string($val) && is_string($saved[$key]) && trim($saved[$key]) === '' && trim($val) !== '') {
                $saved[$key] = $val;
            }
            continue;
        }
        if (isListArray($val)) {
            if (empty($saved[$key]) && !empty($val)) {
                $saved[$key] = $val;
            }
            continue;
        }
        $saved[$key] = mergeSiteChrome($val, $saved[$key]);
    }

    return $saved;
}

function fetchSiteChromePayload(PDO $pdo): array
{
    $defaults = defaultSiteChrome();
    $saved = loadHomepageSetting($pdo, 'site_chrome', []);
    if (empty($saved)) {
        return $defaults;
    }
    return mergeSiteChrome($defaults, $saved);
}

function saveSiteChromePayload(PDO $pdo, array $chrome): void
{
    $merged = mergeSiteChrome(defaultSiteChrome(), $chrome);
    saveHomepageSetting($pdo, 'site_chrome', $merged);
}

/**
 * Admin dashboard: every item currently tied to the public homepage.
 *
 * @return array{items: array<int, array<string, mixed>>, sections: array<string, array<string, mixed>>}
 */
function fetchHomepageAdminOverview(PDO $pdo): array
{
    migrateEventSchema($pdo);
    $items = [];
    $today = new DateTimeImmutable('today');
    $spotlightPayload = fetchHomeSpotlightPayload($pdo);
    $displayingEventIds = [];
    foreach ($spotlightPayload['spotlight_slides'] as $slide) {
        if (!empty($slide['event_id'])) {
            $displayingEventIds[(string) $slide['event_id']] = true;
        }
    }

    try {
        $heroRows = $pdo->query(
            'SELECT id, title, sort_order, is_active, display_start, display_end
             FROM homepage_hero_slides ORDER BY sort_order ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($heroRows as $row) {
            $title = trim(strip_tags((string) ($row['title'] ?? '')));
            $items[] = [
                'kind' => 'hero_slide',
                'id' => (int) $row['id'],
                'section' => 'Hero Carousel',
                'title' => $title !== '' ? $title : 'Hero slide #' . $row['id'],
                'is_visible' => !empty($row['is_active']),
                'meta' => 'Order ' . (int) ($row['sort_order'] ?? 0),
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $spotRows = $pdo->query(
            'SELECT id, title, headline, content_type, priority, sort_order, is_active, show_in_hero, show_in_spotlight, event_id
             FROM homepage_spotlights ORDER BY priority DESC, sort_order ASC, id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($spotRows as $row) {
            $items[] = [
                'kind' => 'spotlight',
                'id' => (int) $row['id'],
                'section' => 'Extra Spotlight',
                'title' => trim((string) ($row['title'] ?? '')) ?: 'Spotlight #' . $row['id'],
                'is_visible' => !empty($row['is_active']),
                'meta' => trim((string) ($row['content_type'] ?? 'announcement')) . ' · priority ' . (int) ($row['priority'] ?? 0),
                'show_in_hero' => !empty($row['show_in_hero']),
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $evRows = $pdo->query(
            "SELECT id, title, featured, pinned, show_live_on_home, date_start, date_end, live_message
             FROM events
             WHERE featured = 1 OR pinned = 1 OR show_live_on_home = 1
             ORDER BY home_priority DESC, updated_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($evRows as $row) {
            enrichEventRow($row, $today);
            if (!empty($row['show_live_on_home'])) {
                $onHomepage = isset($displayingEventIds[(string) $row['id']]);
                $items[] = [
                    'kind' => 'ongoing_event',
                    'id' => (string) $row['id'],
                    'section' => 'Ongoing Now',
                    'title' => trim((string) ($row['title'] ?? '')),
                    'is_visible' => $onHomepage,
                    'meta' => !empty($row['is_live'])
                        ? 'Live now'
                        : ($onHomepage ? 'On homepage' : 'Enabled — not visible (check display dates)'),
                    'event_id' => (string) $row['id'],
                ];
            }
            if (!empty($row['featured']) || !empty($row['pinned'])) {
                $items[] = [
                    'kind' => 'featured_event',
                    'id' => (string) $row['id'],
                    'section' => 'Hero Featured',
                    'title' => trim((string) ($row['title'] ?? '')),
                    'is_visible' => !empty($row['is_display_active']) && ($row['featured'] || $row['pinned']),
                    'meta' => !empty($row['pinned']) ? 'Pinned · priority ' . (int) ($row['home_priority'] ?? 0) : 'Featured event',
                    'event_id' => (string) $row['id'],
                ];
            }
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $pubRows = $pdo->query(
            'SELECT id, title, pub_type, show_on_home FROM publications WHERE show_on_home = 1 ORDER BY sort_order ASC, created_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pubRows as $row) {
            $items[] = [
                'kind' => 'publication',
                'id' => (int) $row['id'],
                'section' => 'Hero Featured',
                'title' => trim((string) ($row['title'] ?? '')),
                'is_visible' => true,
                'meta' => trim((string) ($row['pub_type'] ?? 'publication')),
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    try {
        $grantRows = $pdo->query(
            "SELECT id, title, status, show_on_home FROM grants_opportunities WHERE show_on_home = 1 ORDER BY sort_order ASC, created_at DESC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($grantRows as $row) {
            $items[] = [
                'kind' => 'grant',
                'id' => (int) $row['id'],
                'section' => 'Hero Featured',
                'title' => trim((string) ($row['title'] ?? '')),
                'is_visible' => ($row['status'] ?? '') !== 'closed',
                'meta' => 'Grant · ' . trim((string) ($row['status'] ?? 'open')),
            ];
        }
    } catch (Exception $e) { /* ignore */ }

    $extras = fetchHomepageExtrasPayload($pdo);
    $partnerCount = count($extras['partners']['items'] ?? []);
    $partnersVisible = !isset($extras['partners']['visible']) || !empty($extras['partners']['visible']);
    $ctaVisible = !isset($extras['cta']['visible']) || !empty($extras['cta']['visible']);
    $ctaHasContent = !empty($extras['cta']['title']) || !empty($extras['cta']['body'])
        || !empty($extras['cta']['primary_label']) || !empty($extras['cta']['secondary_label']);

    return [
        'items' => $items,
        'sections' => [
            'partners' => [
                'visible' => $partnersVisible,
                'count' => $partnerCount,
                'title' => trim((string) ($extras['partners']['title'] ?? 'Our Partners')),
            ],
            'cta' => [
                'visible' => $ctaVisible,
                'has_content' => $ctaHasContent,
                'title' => trim((string) ($extras['cta']['title'] ?? 'Make a Difference')),
            ],
        ],
    ];
}

function isProductionEnvironment(): bool
{
    $env = strtolower(trim((string) (getenv('APP_ENV') ?: '')));
    if ($env === 'production') {
        return true;
    }
    $domain = strtolower(trim((string) (getenv('APP_DOMAIN') ?: '')));
    return $domain === 'hosu.or.ug' || $domain === 'www.hosu.or.ug';
}

function homepageSettingKeyExists(PDO $pdo, string $key): bool
{
    migrateEventSchema($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM homepage_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    return (bool) $stmt->fetchColumn();
}

function insertHomepageSettingIfMissing(PDO $pdo, string $key, array $value): bool
{
    if (homepageSettingKeyExists($pdo, $key)) {
        return false;
    }
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO homepage_settings (setting_key, setting_value) VALUES (?, ?)')
        ->execute([$key, $json]);
    return true;
}

/**
 * Standard HOSU homepage hero slides (used only when the table is completely empty).
 *
 * @return array<int, array<string, mixed>>
 */
function defaultHomepageHeroSlideSeedRows(): array
{
    return [
        [
            'slide_key' => 'intro',
            'title' => 'A Uganda Free from Cancer & Blood Diseases',
            'body' => 'The Hematology & Oncology Society of Uganda (HOSU) is a nonprofit organization dedicated to eliminating suffering from cancer and blood diseases through care, research, education, and advocacy.',
            'cta_label' => 'Join Our Community →',
            'cta_url' => 'membership.html',
            'sort_order' => 0,
        ],
        [
            'slide_key' => 'surgical-oncology',
            'title' => 'Surgical Oncology',
            'body' => 'Focuses on the diagnosis, staging, and treatment of cancer through surgery — often combined with other therapies for the best outcomes.',
            'pills_json' => json_encode(['Diagnosis', 'Staging', 'Curative', 'Palliative', 'Reconstructive']),
            'read_more_label' => 'Read More →',
            'sort_order' => 1,
        ],
        [
            'slide_key' => 'medical-oncology',
            'title' => 'Medical Oncology',
            'body' => 'Treats cancer with medicines — chemotherapy, targeted therapy, immunotherapy, and hormonal therapy — tailored to each patient\'s needs.',
            'pills_json' => json_encode(['Chemotherapy', 'Immunotherapy', 'Targeted Therapy', 'Hormone Therapy', 'Precision Medicine']),
            'read_more_label' => 'Read More →',
            'sort_order' => 2,
        ],
        [
            'slide_key' => 'radiation-oncology',
            'title' => 'Radiation Oncology',
            'body' => 'Uses high-energy radiation to target and destroy cancer cells while sparing healthy tissue, alone or combined with other treatments.',
            'pills_json' => json_encode(['External Beam', 'Brachytherapy', 'Stereotactic', 'Proton Therapy', 'Image-Guided']),
            'read_more_label' => 'Read More →',
            'sort_order' => 3,
        ],
        [
            'slide_key' => 'pediatric-oncology',
            'title' => 'Pediatric Oncology',
            'body' => 'Focuses on cancers in children and adolescents — leukemia, brain tumors, and sarcomas — with care tailored to young patients.',
            'pills_json' => json_encode(['Leukemia', 'Brain Tumors', 'Sarcoma Care', 'Supportive Care', 'Follow-Up']),
            'read_more_label' => 'Read More →',
            'sort_order' => 4,
        ],
    ];
}

function heroSlidesFromDefaultSeed(): array
{
    $slides = [];
    foreach (defaultHomepageHeroSlideSeedRows() as $row) {
        $slides[] = normalizeHeroSlideRow(array_merge([
            'id' => 0,
            'is_active' => 1,
            'badge_label' => '',
            'image_path' => '',
            'image_alt' => '',
            'cta_secondary_label' => '',
            'cta_secondary_url' => '',
            'popup_title' => '',
            'popup_html' => '',
            'display_start' => null,
            'display_end' => null,
        ], $row));
    }
    return $slides;
}

function insertDefaultHeroSlideRows(PDO $pdo): void
{
    $ins = $pdo->prepare(
        'INSERT INTO homepage_hero_slides
        (title, body, badge_label, pills_json, popup_title, popup_html, image_path, image_alt,
         cta_label, cta_url, cta_secondary_label, cta_secondary_url, read_more_label, slide_key, sort_order, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
    );

    foreach (defaultHomepageHeroSlideSeedRows() as $row) {
        $ins->execute([
            $row['title'],
            $row['body'] ?? '',
            $row['badge_label'] ?? '',
            $row['pills_json'] ?? null,
            $row['popup_title'] ?? '',
            $row['popup_html'] ?? '',
            $row['image_path'] ?? '',
            $row['image_alt'] ?? '',
            $row['cta_label'] ?? '',
            $row['cta_url'] ?? '',
            $row['cta_secondary_label'] ?? '',
            $row['cta_secondary_url'] ?? '',
            $row['read_more_label'] ?? 'Read More →',
            $row['slide_key'] ?? '',
            (int) ($row['sort_order'] ?? 0),
        ]);
    }
}

function seedDefaultHeroSlidesIfEmpty(PDO $pdo): bool
{
    migrateEventSchema($pdo);
    $count = (int) $pdo->query('SELECT COUNT(*) FROM homepage_hero_slides')->fetchColumn();
    if ($count > 0) {
        return false;
    }

    insertDefaultHeroSlideRows($pdo);
    return true;
}

/**
 * Restore the homepage hero carousel when slides were deleted or are all hidden.
 * Re-seeds the standard HOSU slides only if nothing is displayable on the homepage.
 */
function restoreHeroSlidesIfMissing(PDO $pdo): bool
{
    migrateEventSchema($pdo);

    $displayable = loadHomepageHeroSlides($pdo, true, false);
    if (!empty($displayable)) {
        return false;
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM homepage_hero_slides')->fetchColumn();
    if ($total > 0) {
        try {
            $pdo->exec(
                'UPDATE homepage_hero_slides
                 SET is_active = 1, display_start = NULL, display_end = NULL'
            );
        } catch (Exception $e) { /* ignore */ }

        $displayable = loadHomepageHeroSlides($pdo, true, false);
        if (!empty($displayable)) {
            return true;
        }

        $pdo->exec('DELETE FROM homepage_hero_slides');
    }

    insertDefaultHeroSlideRows($pdo);
    return true;
}

/**
 * Re-insert site defaults only when rows are missing (never overwrites admin edits).
 */
function repairEmptyHomepageSettings(PDO $pdo): array
{
    $repaired = [];
    if (homepageSettingKeyExists($pdo, 'partners')) {
        $partners = loadHomepageSetting($pdo, 'partners', []);
        if (empty($partners['items'])) {
            saveHomepageSetting($pdo, 'partners', defaultHomepagePartners());
            $repaired[] = 'partners';
        }
    }
    if (homepageSettingKeyExists($pdo, 'cta')) {
        $cta = loadHomepageSetting($pdo, 'cta', []);
        if (empty(trim((string) ($cta['title'] ?? '')))) {
            saveHomepageSetting($pdo, 'cta', defaultHomepageCta());
            $repaired[] = 'cta';
        }
    }
    return $repaired;
}

function restoreMissingSiteDefaults(PDO $pdo): array
{
    migrateEventSchema($pdo);
    $restored = [];

    if (insertHomepageSettingIfMissing($pdo, 'partners', defaultHomepagePartners())) {
        $restored[] = 'partners';
    }
    if (insertHomepageSettingIfMissing($pdo, 'cta', defaultHomepageCta())) {
        $restored[] = 'cta';
    }
    if (insertHomepageSettingIfMissing($pdo, 'site_chrome', defaultSiteChrome())) {
        $restored[] = 'site_chrome';
    }
    foreach (repairEmptyHomepageSettings($pdo) as $key) {
        $restored[] = $key . ' (repaired)';
    }
    if (restoreHeroSlidesIfMissing($pdo)) {
        $restored[] = 'homepage_hero_slides';
    }

    return $restored;
}

function eventRowLooksLikeTest(array $row): bool
{
    $title = strtolower(trim((string) ($row['title'] ?? '')));
    $id = strtolower(trim((string) ($row['id'] ?? '')));
    $needles = ['test event', 'dummy', 'sample event', 'placeholder', 'demo event', 'fake event'];
    foreach ($needles as $needle) {
        if ($title !== '' && str_contains($title, $needle)) {
            return true;
        }
    }
    if ($id !== '' && (str_contains($id, 'test-') || str_contains($id, 'dummy') || str_contains($id, 'sample'))) {
        return true;
    }
    if (preg_match('/\btest\b/i', $title) && preg_match('/\bevent\b/i', $title)) {
        return true;
    }
    return false;
}

/**
 * Remove only obvious test/dummy events — never homepage, footer, leaders, or real content.
 *
 * @return array{deleted: string[], skipped: int}
 */
function deleteTestEventsOnly(PDO $pdo): array
{
    migrateEventSchema($pdo);
    $deleted = [];
    $skipped = 0;

    $rows = $pdo->query('SELECT id, title FROM events')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (!eventRowLooksLikeTest($row)) {
            $skipped++;
            continue;
        }
        $id = $row['id'];
        $pdo->prepare('DELETE FROM event_live_content WHERE event_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM event_images WHERE event_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM event_media WHERE event_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM event_registrants WHERE event_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        $deleted[] = $id . ' (' . ($row['title'] ?? '') . ')';
    }

    return ['deleted' => $deleted, 'skipped' => $skipped];
}

/**
 * @deprecated Never wipe live site data. Use deleteTestEventsOnly() or restoreMissingSiteDefaults().
 */
function clearPublicContent(PDO $pdo): array
{
    throw new RuntimeException(
        'clearPublicContent() was removed — it deleted real homepage/footer data by mistake. '
        . 'Use deleteTestEventsOnly() for test events, or restoreMissingSiteDefaults() to fill missing rows only.'
    );
}

