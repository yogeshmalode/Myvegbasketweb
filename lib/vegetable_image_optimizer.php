<?php

define('VEGETABLE_WEBP_QUALITY', 75);
define('VEGETABLE_WEBP_MAX_EDGE', 1000);

function add_vegetable_image_watermark($image) {
    $width = imagesx($image);
    $height = imagesy($image);
    $shortEdge = min($width, $height);
    $font = $shortEdge < 180 ? 1 : ($shortEdge < 350 ? 3 : 5);
    $label = 'MyVegBasket';
    $padding = 6;
    $scale = max(1, min(3, (int)round($shortEdge / 500)));
    $labelWidth = imagefontwidth($font) * strlen($label);
    $labelHeight = imagefontheight($font);
    $baseWidth = $labelWidth + ($padding * 2);
    $baseHeight = $labelHeight + ($padding * 2);
    $watermark = imagecreatetruecolor($baseWidth, $baseHeight);
    imagealphablending($watermark, false);
    imagesavealpha($watermark, true);
    imagefill($watermark, 0, 0, imagecolorallocatealpha($watermark, 0, 0, 0, 127));
    imagefilledrectangle(
        $watermark,
        0,
        0,
        $baseWidth - 1,
        $baseHeight - 1,
        imagecolorallocatealpha($watermark, 18, 45, 31, 48)
    );
    imagestring(
        $watermark,
        $font,
        $padding,
        $padding,
        $label,
        imagecolorallocatealpha($watermark, 255, 255, 255, 8)
    );

    $stampWidth = $baseWidth * $scale;
    $stampHeight = $baseHeight * $scale;
    $stamp = imagecreatetruecolor($stampWidth, $stampHeight);
    imagealphablending($stamp, false);
    imagesavealpha($stamp, true);
    imagefill($stamp, 0, 0, imagecolorallocatealpha($stamp, 0, 0, 0, 127));
    imagecopyresampled($stamp, $watermark, 0, 0, 0, 0, $stampWidth, $stampHeight, $baseWidth, $baseHeight);

    imagealphablending($image, true);
    imagecopy(
        $image,
        $stamp,
        max(0, $width - $stampWidth - (8 * $scale)),
        max(0, $height - $stampHeight - (8 * $scale)),
        0,
        0,
        $stampWidth,
        $stampHeight
    );

    imagedestroy($stamp);
    imagedestroy($watermark);
}

function convert_vegetable_image_to_webp($sourcePath, $destinationPath, $mime, $addWatermark = false) {
    $imageInfo = @getimagesize($sourcePath);
    if (!$imageInfo || ($imageInfo['mime'] ?? '') !== $mime) {
        return 'The uploaded image could not be decoded. Please choose a valid JPG, PNG, or WEBP image.';
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
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $source = @imagecreatefromwebp($sourcePath);
    } else {
        return 'WebP conversion is unavailable because the server PHP GD extension is missing support for this image format.';
    }

    if (!$source) {
        return 'The uploaded image could not be decoded. Please choose a valid JPG, PNG, or WEBP image.';
    }

    $hasTransparency = $mime !== 'image/jpeg';
    if ($hasTransparency) {
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
        if ($hasTransparency) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
            imagefill($image, 0, 0, $transparent);
        }
        imagecopyresampled($image, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    }

    if ($addWatermark) {
        add_vegetable_image_watermark($image);
    }

    // Re-encoding strips source metadata such as EXIF location and camera details.
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
