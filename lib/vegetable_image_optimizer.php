<?php

define('VEGETABLE_WEBP_QUALITY', 75);

function convert_vegetable_image_to_webp($sourcePath, $destinationPath, $mime) {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
        return 'The uploaded image could not be decoded. Please choose a valid JPEG or PNG image.';
    }
    if ((int)$imageInfo[0] <= 0 || (int)$imageInfo[1] <= 0
        || (int)$imageInfo[0] * (int)$imageInfo[1] > 12000000) {
        return 'The image dimensions are too large to process. Please upload an image under 12 megapixels.';
    }

    if (!function_exists('imagewebp')) {
        return 'WebP conversion is unavailable because the server PHP GD extension does not support WebP.';
    }

    if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
        $source = @imagecreatefromjpeg($sourcePath);
    } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
        $source = @imagecreatefrompng($sourcePath);
    } else {
        return 'WebP conversion is unavailable because the server PHP GD extension is missing JPEG or PNG support.';
    }

    if (!$source) {
        return 'The uploaded image could not be decoded. Please choose a valid JPEG or PNG image.';
    }

    if ($mime === 'image/png') {
        imagealphablending($source, false);
        imagesavealpha($source, true);
    }

    $saved = imagewebp($source, $destinationPath, VEGETABLE_WEBP_QUALITY);
    imagedestroy($source);

    if (!$saved || !is_file($destinationPath) || filesize($destinationPath) === 0) {
        if (is_file($destinationPath)) {
            unlink($destinationPath);
        }
        return 'Could not convert the uploaded image to WebP. Please try another image.';
    }

    return null;
}
