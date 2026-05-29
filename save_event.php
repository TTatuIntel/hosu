<?php
// Add this at the very top to ensure no output before headers
ob_start();
session_start();
require 'db.php';
require_once 'upload_helper.php';

// Set headers first
header('Content-Type: application/json');

// Require admin authentication
if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

try {
    // Verify this is a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Handle image upload
    $uploadDir = 'uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Ensure event_images table exists (safe migration)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS event_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(100) NOT NULL,
            image_path VARCHAR(500) NOT NULL,
            image_alt VARCHAR(255) DEFAULT '',
            caption VARCHAR(255) DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_sort (sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $_e) { /* ignore */ }

    // Collect uploaded images — supports both new multi (imageFiles[]) and legacy single (imageFile)
    $uploadedPaths = [];

    // New multi-file input: imageFiles[]
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

    // Legacy single-file input: imageFile
    if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
        $up = secureUpload($_FILES['imageFile'], 'uploads/events/', false, 5000000);
        if ($up) $uploadedPaths[] = $up;
    }

    $imagePath = '';
    if (!empty($uploadedPaths)) {
        // Primary image = first uploaded file
        $imagePath = $uploadedPaths[0];
    } elseif (!empty($_POST['image'])) {
        $imagePath = filter_var($_POST['image'], FILTER_SANITIZE_URL);
    } else {
        throw new Exception('Either upload at least one image or provide an image URL');
    }

    // Validate required fields
    $required = ['id', 'type', 'status', 'imageAlt', 'date', 'title', 'description', 'location', 'category'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Field $field is required");
        }
    }

    // Reject events with a start date in the past
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

    // Auto-determine status & category from dates
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

    // Ensure date_start / date_end / is_free / event_fee columns exist (safe migration)
    foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try {
            $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1));
        } catch (Exception $e) { /* already exists */ }
    }

    // Insert into database
    $isFree = !empty($_POST['is_free']) ? 1 : 0;
    $eventFee = $isFree ? 0 : max(0, (float)($_POST['event_fee'] ?? 0));

    // Check for duplicate event ID
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE id = ?");
    $checkStmt->execute([$_POST['id']]);
    if ($checkStmt->fetchColumn() > 0) {
        throw new Exception('An event with this ID already exists. Please change the title to generate a unique ID.');
    }

    $stmt = $pdo->prepare("INSERT INTO events (id, type, status, image, imageAlt, countdown, date, date_start, date_end, title, description, location, featured, category, is_free, event_fee) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
        $eventFee
    ]);

    if (!$success) {
        throw new Exception('Failed to save to database');
    }

    // Persist gallery rows: every uploaded file goes into event_images (including the primary)
    if (!empty($uploadedPaths)) {
        try {
            $insImg = $pdo->prepare("INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)");
            foreach ($uploadedPaths as $idx => $path) {
                $insImg->execute([
                    $_POST['id'],
                    $path,
                    $_POST['imageAlt'],
                    $idx,
                    $idx === 0 ? 1 : 0
                ]);
            }
        } catch (Exception $_e) { /* gallery is best-effort; primary already saved on events.image */ }
    } elseif (!empty($_POST['image'])) {
        // URL-only image — also mirror to event_images so the gallery is consistent
        try {
            $pdo->prepare("INSERT INTO event_images (event_id, image_path, image_alt, sort_order, is_primary) VALUES (?, ?, ?, 0, 1)")
                ->execute([$_POST['id'], $imagePath, $_POST['imageAlt']]);
        } catch (Exception $_e) { /* ignore */ }
    }

    // Clear any accidental output
    ob_end_clean();

    echo json_encode(['success' => true]);
    exit;

} catch (Exception $e) {
    // Clear any buffered output
    ob_end_clean();

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
?>
