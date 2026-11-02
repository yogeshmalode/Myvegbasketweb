<?php

function vegetable_image_storage_dir() {
    $configuredPath = getenv('VEGBASKET_IMAGE_STORAGE_DIR');
    if ($configuredPath !== false && trim($configuredPath) !== '') {
        return rtrim(trim($configuredPath), '/\\');
    }

    return dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'myvegbasket-product-images';
}

function ensure_vegetable_image_storage_dir() {
    $directory = vegetable_image_storage_dir();
    if (is_dir($directory)) {
        return true;
    }
    if (@mkdir($directory, 0755, true) || is_dir($directory)) {
        return true;
    }

    error_log('Could not create persistent vegetable image directory: ' . $directory);
    return false;
}

function resolve_vegetable_image_file($relativePath) {
    $relativePath = str_replace('\\', '/', ltrim((string)$relativePath, '/'));
    $uploadPrefix = 'uploads/vegetables/';
    if (strpos($relativePath, $uploadPrefix) !== 0) {
        return null;
    }

    $filename = substr($relativePath, strlen($uploadPrefix));
    if ($filename === '' || basename($filename) !== $filename
        || !preg_match('/\A[a-f0-9]{16}\.(?:webp|jpg|png)\z/i', $filename)) {
        return null;
    }

    $persistentPath = vegetable_image_storage_dir() . DIRECTORY_SEPARATOR . $filename;
    if (is_file($persistentPath)) {
        return $persistentPath;
    }

    $legacyPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $uploadPrefix . $filename;
    if (!is_file($legacyPath)) {
        return null;
    }

    if (ensure_vegetable_image_storage_dir()) {
        if (@copy($legacyPath, $persistentPath)) {
            return $persistentPath;
        }
        error_log('Could not migrate vegetable image to persistent storage: ' . $filename);
    }

    return $legacyPath;
}
