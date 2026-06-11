<?php
ob_start();
session_start();
require 'db.php';
require_once 'upload_helper.php';
require_once 'event_helpers.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    migrateEventSchema($pdo);

    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $uploadedPaths = [];

    if (!empty($_FILES['imageFiles']) && is_array($_FILES['imageFiles']['name'])) {
        $count = count($_FILES['imageFiles']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($_FILES['imageFiles']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $single = [
                'name'     => $_FILES['imageFiles']['name'][$i],
                'type'     => $_FILES['imageFiles']['type'][$i],
                'tmp_name' => $_FILES['imageFiles']['tmp_name'][$i],
                'error'    => $_FILES['imageFiles']['error'][$i],
                'size'     => $_FILES['imageFiles']['size'][$i],
            ];
            $up = secureUpload($single, 'uploads/events/', false, 5000000);
            if ($up) $uploadedPaths[] = $up;
        }
    }

    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        $up = secureUpload($_FILES['imageFile'], 'uploads/events/', false, 5000000);
        if ($up) $uploadedPaths[] = $up;
    }

    $imageUrls = [];
    if (!empty($_POST['imageUrls']) && is_array($_POST['imageUrls'])) {
        $imageUrls = $_POST['imageUrls'];
    } elseif (!empty($_POST['imageUrls'])) {
        $imageUrls = preg_split('/[\r\n,]+/', $_POST['imageUrls']);
    } elseif (!empty($_POST['image'])) {
        $imageUrls = [$_POST['image']];
    }

    $imagePath = '';
    if (!empty($uploadedPaths)) {
        $imagePath = $uploadedPaths[0];
    } elseif (!empty($imageUrls)) {
        $imagePath = filter_var(trim($imageUrls[0]), FILTER_SANITIZE_URL);
    } else {
        throw new Exception('Either upload at least one image or provide an image URL');
    }

    $required = ['id', 'type', 'status', 'imageAlt', 'date', 'title', 'description', 'location', 'category'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }

    $dateStart = !empty($_POST['date_start']) ? trim($_POST['date_start']) : null;
    $dateEnd   = !empty($_POST['date_end'])   ? trim($_POST['date_end'])   : null;
    if ($dateStart) {
        $ts = strtotime($dateStart);
        if ($ts === false || $ts < strtotime('today')) {
            throw new Exception('Event start date cannot be in the past.');
        }
    }
    if ($dateEnd && $dateStart && strtotime($dateEnd) < strtotime($dateStart)) {
        throw new Exception('End date cannot be before start date.');
    }

    $status   = $_POST['status'] ?? 'open';
    $category = $_POST['category'] ?? 'upcoming';
    if ($dateStart) {
        $today   = new DateTimeImmutable('today');
        $startDt = new DateTimeImmutable($dateStart);
        $endDt   = $dateEnd ? new DateTimeImmutable($dateEnd) : $startDt;
        if ($today > $endDt) {
            $status   = 'past';
            $category = 'past';
        } elseif ($today >= $startDt && $today <= $endDt) {
            if ($status === 'past') $status = 'open';
            if ($category === 'past') $category = 'current';
        } else {
            if ($status === 'past') $status = 'open';
            if ($category === 'past') $category = 'upcoming';
        }
    }

    $isFree = !empty($_POST['is_free']) ? 1 : 0;
    $eventFee = $isFree ? 0 : max(0, (float)($_POST['event_fee'] ?? 0));
    $displayFields = parseDisplayFields($_POST);
    $liveFields = parseLiveFields($_POST);

    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM events WHERE id = ?');
    $checkStmt->execute([$_POST['id']]);
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception('An event with this ID already exists. Please change the title to generate a unique ID.');
    }

    $stmt = $pdo->prepare("INSERT INTO events (
        id, type, status, image, imageAlt, countdown, date, date_start, date_end,
        title, description, location, featured, category, is_free, event_fee,
        speakers, highlights, announcements, display_start, display_end, display_for_event, pinned, home_priority,
        post_event_display_days, live_message, live_cta_label, live_cta_url, show_live_on_home
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $success = $stmt->execute([
        $_POST['id'],
        $_POST['type'],
        $status,
        $imagePath,
        $_POST['imageAlt'],
        $_POST['countdown'] ?? null,
        $_POST['date'],
        $dateStart,
        $dateEnd,
        $_POST['title'],
        $_POST['description'],
        $_POST['location'],
        (!empty($_POST['featured']) && $_POST['featured'] !== '0') ? 1 : 0,
        $category,
        $isFree,
        $eventFee,
        $displayFields['speakers'],
        $displayFields['highlights'],
        $displayFields['announcements'],
        $displayFields['display_start'],
        $displayFields['display_end'],
        $displayFields['display_for_event'],
        $displayFields['pinned'],
        $displayFields['home_priority'],
        $displayFields['post_event_display_days'],
        $liveFields['live_message'],
        $liveFields['live_cta_label'],
        $liveFields['live_cta_url'],
        $liveFields['show_live_on_home'],
    ]);

    if (!$success) {
        throw new Exception('Failed to save to database');
    }

    if (!empty($uploadedPaths)) {
        $insImg = $pdo->prepare('INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary, source_type) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($uploadedPaths as $idx => $path) {
            $insImg->execute([$_POST['id'], $path, $_POST['imageAlt'], $idx, $idx === 0 ? 1 : 0, 'upload']);
        }
    }

    $urlOnly = array_values(array_filter(array_map('trim', $imageUrls)));
    if (!empty($urlOnly)) {
        if (!empty($uploadedPaths)) {
            insertEventImageUrls($pdo, $_POST['id'], $urlOnly, $_POST['imageAlt']);
        } else {
            insertEventImageUrls($pdo, $_POST['id'], $urlOnly, $_POST['imageAlt']);
        }
    }

    saveEventMediaFromRequest($pdo, $_POST['id'], $_POST, $_FILES);
    saveLiveContentFromRequest($pdo, $_POST['id'], $_POST);

    $eventRow = null;
    try {
        $evStmt = $pdo->prepare('SELECT id, title, type, status, category, date, date_start, date_end, location, image, imageAlt, description, countdown, featured, pinned, home_priority, display_start, display_end, display_for_event, speakers, highlights, announcements, live_message, live_cta_label, live_cta_url, drive_folder_url, show_live_on_home, is_free, event_fee, created_at, updated_at FROM events WHERE id = ?');
        $evStmt->execute([$_POST['id']]);
        $eventRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $_evFetch) {}

    ob_end_clean();
    echo json_encode(['success' => true, 'event' => $eventRow]);
    exit;

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
