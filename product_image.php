<?php
require_once __DIR__ . '/lib/product_image_storage.php';

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

$mimeTypes = [
    'webp' => 'image/webp',
    'jpg' => 'image/jpeg',
    'png' => 'image/png',
];
$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Length: ' . (string)filesize($imagePath));
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($imagePath);
