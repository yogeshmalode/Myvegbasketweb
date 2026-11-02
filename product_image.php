<?php
require_once __DIR__ . '/lib/product_image_storage.php';
require_once __DIR__ . '/lib/vegetable_image_optimizer.php';

function get_watermarked_product_image_path($sourcePath, $filename) {
    $storageDirectory = vegetable_image_storage_dir();
    $cacheDirectory = $storageDirectory . DIRECTORY_SEPARATOR . 'watermarked';
    if (!is_dir($cacheDirectory) && !@mkdir($cacheDirectory, 0755, true) && !is_dir($cacheDirectory)) {
        error_log('Could not create product image watermark cache.');
        return $sourcePath;
    }

    $cachePath = $cacheDirectory . DIRECTORY_SEPARATOR . pathinfo($filename, PATHINFO_FILENAME) . '.webp';
    if (is_file($cachePath) && filemtime($cachePath) >= filemtime($sourcePath)) {
        return $cachePath;
    }

    if (!function_exists('imagewebp')) {
        error_log('Product image watermark unavailable: PHP GD WebP support is not enabled.');
        return $sourcePath;
    }

    $lock = @fopen($cachePath . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        error_log('Could not lock product image watermark cache.');
        return $sourcePath;
    }

    try {
        if (is_file($cachePath) && filemtime($cachePath) >= filemtime($sourcePath)) {
            return $cachePath;
        }

        $imageInfo = @getimagesize($sourcePath);
        $mime = $imageInfo['mime'] ?? '';
        $temporaryPath = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp.webp';
        $error = convert_vegetable_image_to_webp($sourcePath, $temporaryPath, $mime, true);
        if ($error !== null) {
            error_log('Could not watermark product image: ' . $error);
            return $sourcePath;
        }
        if (!@rename($temporaryPath, $cachePath)) {
            @unlink($temporaryPath);
            error_log('Could not publish watermarked product image.');
            return $sourcePath;
        }

        return $cachePath;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

$filename = (string)($_GET['file'] ?? '');
if (basename($filename) !== $filename
    || !preg_match('/\A[a-f0-9]{16}\.(?:webp|jpg|png)\z/i', $filename)) {
    http_response_code(404);
    exit;
}

$imagePath = resolve_vegetable_image_file('uploads/vegetables/' . $filename);
if (!$imagePath || !is_file($imagePath)) {
    http_response_code(404);
    exit;
}

$imagePath = get_watermarked_product_image_path($imagePath, $filename);
if (!$imagePath || !is_file($imagePath)) {
    http_response_code(503);
    exit('Product image is temporarily unavailable.');
}

$responseInfo = @getimagesize($imagePath);
if (!$responseInfo) {
    http_response_code(500);
    exit;
}

$mimeTypes = [
    'webp' => 'image/webp',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
];
header('Content-Type: ' . ($responseInfo['mime'] ?? $mimeTypes[strtolower(pathinfo($filename, PATHINFO_EXTENSION))]));
header('Content-Length: ' . (string)filesize($imagePath));
header('Cache-Control: private, max-age=86400');
header('X-Robots-Tag: noindex, noimageindex, noarchive');
header('Cross-Origin-Resource-Policy: same-site');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
