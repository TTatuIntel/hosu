<?php
/**
 * Secure file upload helper.
 * Validates type, size, and re-encodes images to strip metadata/payloads.
 */

define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 MB
define('UPLOAD_IMAGE_MAX_WIDTH', 1200);
define('UPLOAD_IMAGE_MAX_HEIGHT', 900);
define('UPLOAD_IMAGE_JPEG_QUALITY', 85);
define('UPLOAD_IMAGE_WEBP_QUALITY', 85);

/**
 * Downscale a GD image resource to fit within hero/spotlight display bounds.
 */
function downscaleImageResource(\GdImage $img, int $maxW = UPLOAD_IMAGE_MAX_WIDTH, int $maxH = UPLOAD_IMAGE_MAX_HEIGHT): \GdImage
{
    $w = imagesx($img);
    $h = imagesy($img);
    if ($w <= 0 || $h <= 0) {
        return $img;
    }
    if ($w <= $maxW && $h <= $maxH) {
        return $img;
    }

    $ratio = min($maxW / $w, $maxH / $h);
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));

    $resampled = imagecreatetruecolor($nw, $nh);
    imagesavealpha($resampled, true);
    $transparent = imagecolorallocatealpha($resampled, 0, 0, 0, 127);
    imagefill($resampled, 0, 0, $transparent);
    imagecopyresampled($resampled, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    return $resampled;
}

/**
 * Re-encode an on-disk upload at standard hero dimensions (idempotent).
 */
function optimizeUploadedImage(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }

    $info = @getimagesize($path);
    if (!$info || empty($info['mime']) || !isset(UPLOAD_ALLOWED_IMAGES[$info['mime']])) {
        return false;
    }

    // SVGs are sanitized at upload time; no re-encode pass.
    if ($info['mime'] === 'image/svg+xml') {
        return true;
    }

    $img = @imagecreatefromstring((string) file_get_contents($path));
    if (!$img) {
        return false;
    }

    imagesavealpha($img, true);
    $img = downscaleImageResource($img);
    $ext = UPLOAD_ALLOWED_IMAGES[$info['mime']];

    switch ($ext) {
        case 'png':
            imagepng($img, $path, 9);
            break;
        case 'gif':
            imagegif($img, $path);
            break;
        case 'webp':
            imagewebp($img, $path, UPLOAD_IMAGE_WEBP_QUALITY);
            break;
        default:
            imagejpeg($img, $path, UPLOAD_IMAGE_JPEG_QUALITY);
            break;
    }
    imagedestroy($img);

    return true;
}

define('UPLOAD_ALLOWED_IMAGES', [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/gif'     => 'gif',
    'image/webp'    => 'webp',
    'image/svg+xml' => 'svg',
]);

define('UPLOAD_ALLOWED_DOCS', [
    'application/pdf' => 'pdf',
]);

/**
 * Sanitize SVG markup — remove scripts, event handlers, and external references
 * that could be used for XSS. SVGs are XML so GD cannot re-encode them.
 */
function sanitizeSvgContent(string $svg): string
{
    // Strip <script> blocks (including malformed)
    $svg = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $svg);
    $svg = preg_replace('#<script\b[^>]*/?>#is', '', $svg);
    // Strip on* event handlers
    $svg = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $svg);
    $svg = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $svg);
    // Strip javascript: URLs
    $svg = preg_replace('#(href|xlink:href)\s*=\s*"\s*javascript:[^"]*"#i', '', $svg);
    // Strip <foreignObject> which can embed HTML
    $svg = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is', '', $svg);
    return $svg;
}

/**
 * Securely handle an uploaded file.
 *
 * @param array  $file        $_FILES['field']
 * @param string $destDir     e.g. 'uploads/posts/'
 * @param bool   $allowDocs   Also allow PDF uploads
 * @param int    $maxSize     Max bytes (default 5 MB)
 * @return string|false       Relative path on success, false on failure
 */
function secureUpload(array $file, string $destDir, bool $allowDocs = false, int $maxSize = UPLOAD_MAX_SIZE)
{
    if ($file['error'] !== UPLOAD_ERR_OK) return false;
    if ($file['size'] > $maxSize) return false;

    // Detect real MIME type from file content
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = UPLOAD_ALLOWED_IMAGES;
    if ($allowDocs) $allowed = array_merge($allowed, UPLOAD_ALLOWED_DOCS);

    if (!isset($allowed[$mime])) return false;

    // Create directory if needed
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);

    // Generate a safe random filename — never trust the original name
    $ext = $allowed[$mime];
    $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
    $targetPath = rtrim($destDir, '/') . '/' . $safeName;

    // SVG: sanitize XML (no GD re-encode possible)
    if ($mime === 'image/svg+xml') {
        $svg = file_get_contents($file['tmp_name']);
        if ($svg === false) return false;
        $svg = sanitizeSvgContent($svg);
        if (file_put_contents($targetPath, $svg) === false) return false;
        return $targetPath;
    }

    // For raster images: re-encode to strip metadata and any embedded payloads
    if (isset(UPLOAD_ALLOWED_IMAGES[$mime])) {
        $img = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$img) return false;

        imagesavealpha($img, true);
        $img = downscaleImageResource($img);

        switch ($ext) {
            case 'png':
                imagepng($img, $targetPath, 9);
                break;
            case 'gif':
                imagegif($img, $targetPath);
                break;
            case 'webp':
                imagewebp($img, $targetPath, UPLOAD_IMAGE_WEBP_QUALITY);
                break;
            default:
                imagejpeg($img, $targetPath, UPLOAD_IMAGE_JPEG_QUALITY);
                break;
        }
        imagedestroy($img);
    } else {
        // For PDFs: move without re-encoding but verify it starts with %PDF
        $header = file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (substr($header, 0, 5) !== '%PDF-') return false;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) return false;
    }

    return $targetPath;
}
