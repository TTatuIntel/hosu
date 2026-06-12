<?php
/**
 * Same-origin proxy for public Google Drive images (fixes broken <img> on the homepage).
 */
declare(strict_types=1);

require_once __DIR__ . '/event_helpers.php';

$id = trim((string) ($_GET['id'] ?? ''));
$w = min(2048, max(120, (int) ($_GET['w'] ?? SPOTLIGHT_DISPLAY_MAX_WIDTH)));

if (!preg_match('/^[a-zA-Z0-9_-]{10,64}$/', $id)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid id';
    exit;
}

$cacheDir = __DIR__ . '/cache/drive/images';
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
$cachePath = $cacheDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $id) . '_' . $w . '.bin';

if (is_file($cachePath) && (time() - filemtime($cachePath)) < 3600) {
    $meta = @json_decode((string) file_get_contents($cachePath . '.meta'), true);
    $type = is_array($meta) ? ($meta['type'] ?? 'image/jpeg') : 'image/jpeg';
    header('Content-Type: ' . $type);
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    readfile($cachePath);
    exit;
}

$sources = [
    'https://drive.google.com/thumbnail?id=' . rawurlencode($id) . '&sz=w' . $w,
    'https://drive.google.com/uc?export=view&id=' . rawurlencode($id),
    'https://lh3.googleusercontent.com/d/' . $id . '=w' . $w,
];

foreach ($sources as $source) {
    $ch = curl_init($source);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HOSU/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($code >= 200 && $code < 400 && is_string($body) && $body !== '' && stripos($type, 'image') !== false) {
        @file_put_contents($cachePath, $body);
        @file_put_contents($cachePath . '.meta', json_encode(['type' => $type]));
        header('Content-Type: ' . $type);
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        echo $body;
        exit;
    }
}

/* Let the browser load Google directly when server-side fetch fails (no curl, firewall, etc.). */
header('Location: https://lh3.googleusercontent.com/d/' . rawurlencode($id) . '=w' . $w, true, 302);
exit;
