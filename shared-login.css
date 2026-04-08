<?php
/**
 * Secure file upload helper.
 * Validates type, size, and re-encodes images to strip metadata/payloads.
 */

define('UPLOAD_MAX_SIZE', 5 * 1024 * 1024); // 5 MB

define('UPLOAD_ALLOWED_IMAGES', [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
]);

define('UPLOAD_ALLOWED_DOCS', [
    'application/pdf' => 'pdf',
]);

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

    // For images: re-encode to strip metadata and any embedded payloads
    if (isset(UPLOAD_ALLOWED_IMAGES[$mime])) {
        $img = @imagecreatefromstring(file_get_contents($file['tmp_name']));
        if (!$img) return false;

        // Preserve transparency for PNG/GIF/WebP
        imagesavealpha($img, true);

        switch ($ext) {
            case 'png':
                imagepng($img, $targetPath, 9);
                break;
            case 'gif':
                imagegif($img, $targetPath);
                break;
            case 'webp':
                imagewebp($img, $targetPath, 85);
                break;
            default: // jpg
                imagejpeg($img, $targetPath, 85);
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
