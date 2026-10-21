<?php

define('VEGETABLE_WEBP_QUALITY', 75);
define('VEGETABLE_WEBP_MAX_EDGE', 1000);

function convert_vegetable_image_to_webp($sourcePath, $destinationPath, $mime) {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
        return 'The uploaded image could not be decoded. Please choose a valid JPEG or PNG image.';
    }
    if ((int)$imageInfo[0] <= 0 || (int)$imageInfo[1] <= 0
        || (int)$imageInfo[0] * (int)$imageInfo[1] > 24000000) {
        return 'The image dimensions are too large to process. Please upload an image under 24 megapixels.';
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

    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = min(1, VEGETABLE_WEBP_MAX_EDGE / max($sourceWidth, $sourceHeight));
    $image = $source;
    if ($scale < 1) {
        $targetWidth = max(1, (int)round($sourceWidth * $scale));
        $targetHeight = max(1, (int)round($sourceHeight * $scale));
        $image = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
        }
        imagecopyresampled($image, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    }

    $saved = imagewebp($image, $destinationPath, VEGETABLE_WEBP_QUALITY);
    if ($image !== $source) {
        imagedestroy($image);
    }
    imagedestroy($source);

    if (!$saved || !is_file($destinationPath) || filesize($destinationPath) === 0) {
        if (is_file($destinationPath)) {
            unlink($destinationPath);
        }
        return 'Could not convert the uploaded image to WebP. Please try another image.';
    }

    return null;
}
