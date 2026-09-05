<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!empty($_POST['remove'])) {
    unset($_SESSION['applied_coupon_code']);
    echo json_encode(['success' => true]);
    exit;
}

$code = trim($_POST['code'] ?? '');
$subtotal = cart_total();

$result = validate_coupon($pdo, $code, $subtotal);

if (!$result['valid']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$_SESSION['applied_coupon_code'] = strtoupper($code);

echo json_encode([
    'success'  => true,
    'discount' => number_format($result['discount'], 2),
]);
