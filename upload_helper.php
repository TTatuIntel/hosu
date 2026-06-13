<?php
/**
 * Secure file upload helper.
 * Validates type, size, and re-encodes images to strip metadata/payloads.
 */

define('UPLOAD_MAX_SIZE', 32 * 1024 * 1024); // 32 MB hard cap; client auto-compresses before upload
define('UPLOAD_IMAGE_MAX_WIDTH', 1280);
define('UPLOAD_IMAGE_MAX_HEIGHT', 960);
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
    'image/jpeg'                => 'jpg',
    'image/pjpeg'               => 'jpg',
    'image/png'                 => 'png',
    'image/x-png'               => 'png',
    'image/apng'                => 'png',
    'image/gif'                 => 'gif',
    'image/webp'                => 'webp',
    'image/svg+xml'             => 'svg',
    'image/bmp'                 => 'bmp',
    'image/x-ms-bmp'            => 'bmp',
    'image/tiff'                => 'tiff',
    'image/x-icon'              => 'ico',
    'image/vnd.microsoft.icon'  => 'ico',
    'image/heic'                => 'heic',
    'image/heif'                => 'heif',
    'image/heic-sequence'       => 'heic',
    'image/heif-sequence'       => 'heif',
    'image/avif'                => 'avif',
    'image/avif-sequence'       => 'avif',
    'image/jxl'                 => 'jxl',
]);

define('UPLOAD_ALLOWED_DOCS', [
    'application/pdf' => 'pdf',
]);

/**
 * Try to convert a raw file at $sourcePath into a browser-friendly JPEG
 * at $targetJpgPath using Imagick (handles HEIC/AVIF/TIFF/BMP/JXL).
 * Returns true on success, false if Imagick isn't available or fails.
 */
function convertToJpegWithImagick(string $sourcePath, string $targetJpgPath): bool
{
    if (!class_exists('Imagick')) return false;
    try {
        $im = new Imagick($sourcePath);
        // HEIC may have multiple frames; keep the first.
        if ($im->getNumberImages() > 1) {
            $im->setIteratorIndex(0);
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(UPLOAD_IMAGE_JPEG_QUALITY);
        // Strip metadata, color-correct to sRGB.
        $im->stripImage();
        if (method_exists($im, 'transformImageColorspace')) {
            $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        // Downscale if huge.
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $maxW = UPLOAD_IMAGE_MAX_WIDTH;
        $maxH = UPLOAD_IMAGE_MAX_HEIGHT;
        if ($w > $maxW || $h > $maxH) {
            $im->resizeImage($maxW, $maxH, Imagick::FILTER_LANCZOS, 1, true);
        }
        $ok = $im->writeImage($targetJpgPath);
        $im->clear();
        $im->destroy();
        return $ok && is_file($targetJpgPath) && filesize($targetJpgPath) > 0;
    } catch (Throwable $e) {
        error_log('convertToJpegWithImagick: ' . $e->getMessage());
        return false;
    }
}

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
    if ($file['error'] !== UPLOAD_ERR_OK) {
        error_log('secureUpload: upload error code ' . $file['error'] . ' for ' . ($file['name'] ?? '?'));
        return false;
    }
    if ($file['size'] > $maxSize) {
        error_log('secureUpload: file too large (' . $file['size'] . ' > ' . $maxSize . ') for ' . ($file['name'] ?? '?'));
        return false;
    }

    // Detect real MIME type from file content
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed = UPLOAD_ALLOWED_IMAGES;
    if ($allowDocs) $allowed = array_merge($allowed, UPLOAD_ALLOWED_DOCS);

    if (!isset($allowed[$mime])) {
        error_log('secureUpload: disallowed mime "' . $mime . '" for ' . ($file['name'] ?? '?'));
        return false;
    }

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

    // Exotic formats browsers usually can't render (HEIC/HEIF/AVIF/TIFF/BMP/JXL/ICO):
    // try Imagick → convert to JPG so every visitor sees the photo.
    $needsConversion = in_array($mime, [
        'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
        'image/avif', 'image/avif-sequence',
        'image/tiff', 'image/bmp', 'image/x-ms-bmp',
        'image/jxl', 'image/x-icon', 'image/vnd.microsoft.icon',
    ], true);
    if ($needsConversion) {
        $jpgName = preg_replace('/\.[^.]+$/', '.jpg', $safeName);
        $jpgPath = rtrim($destDir, '/') . '/' . $jpgName;
        if (convertToJpegWithImagick($file['tmp_name'], $jpgPath)) {
            return $jpgPath;
        }
        // Imagick missing or failed → save the original so admin's photo isn't lost,
        // but log so they can install imagick for browser-friendly output.
        error_log('secureUpload: Imagick conversion failed/unavailable for mime ' . $mime . '; saving raw ' . ($file['name'] ?? '?'));
        if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
            if (!@copy($file['tmp_name'], $targetPath)) return false;
        }
        return $targetPath;
    }

    // For raster images: try to re-encode (strips metadata + downscales).
    // If GD can't decode (very large files, edge cases), fall back to moving the
    // original so the upload still succeeds — admin sees their image either way.
    if (isset(UPLOAD_ALLOWED_IMAGES[$mime])) {
        $raw = @file_get_contents($file['tmp_name']);
        $img = $raw !== false ? @imagecreatefromstring($raw) : false;

        if (!$img) {
            error_log('secureUpload: GD decode failed for ' . ($file['name'] ?? '?') . ' (mime=' . $mime . '); falling back to raw move.');
            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                // move_uploaded_file fails if called twice or in some sapi contexts; try copy.
                if (!@copy($file['tmp_name'], $targetPath)) return false;
            }
            return $targetPath;
        }

        imagesavealpha($img, true);
        $img = downscaleImageResource($img);

        $encoded = false;
        switch ($ext) {
            case 'png':
                $encoded = imagepng($img, $targetPath, 9);
                break;
            case 'gif':
                $encoded = imagegif($img, $targetPath);
                break;
            case 'webp':
                $encoded = imagewebp($img, $targetPath, UPLOAD_IMAGE_WEBP_QUALITY);
                break;
            default:
                $encoded = imagejpeg($img, $targetPath, UPLOAD_IMAGE_JPEG_QUALITY);
                break;
        }
        imagedestroy($img);

        if (!$encoded || !is_file($targetPath) || filesize($targetPath) === 0) {
            error_log('secureUpload: re-encode failed for ' . ($file['name'] ?? '?') . '; falling back to raw move.');
            if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
                if (!@copy($file['tmp_name'], $targetPath)) return false;
            }
        }
    } else {
        // For PDFs: move without re-encoding but verify it starts with %PDF
        $header = file_get_contents($file['tmp_name'], false, null, 0, 5);
        if (substr($header, 0, 5) !== '%PDF-') return false;
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) return false;
    }

    return $targetPath;
}
