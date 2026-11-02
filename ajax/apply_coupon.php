<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!empty($_POST['remove'])) {
    unset($_SESSION['applied_coupon_code']);
    $subtotal = cart_total();
    $deliveryCharge = get_delivery_charge($subtotal);
    echo json_encode([
        'success' => true,
        'applied' => false,
        'subtotal' => $subtotal,
        'discount' => 0,
        'delivery_charge' => $deliveryCharge,
        'total' => $subtotal + $deliveryCharge,
    ]);
    exit;
}

$codeInput = $_POST['code'] ?? '';
if (!is_string($codeInput)) {
    echo json_encode(['success' => false, 'message' => 'Invalid coupon code.']);
    exit;
}
$code = trim($codeInput);
$subtotal = cart_total();

$result = validate_coupon($pdo, $code, $subtotal);

if (!$result['valid']) {
    echo json_encode(['success' => false, 'message' => $result['message']]);
    exit;
}

$_SESSION['applied_coupon_code'] = strtoupper($code);
$deliveryCharge = $result['free_delivery'] ? 0 : get_delivery_charge($subtotal);

echo json_encode([
    'success' => true,
    'applied' => true,
    'coupon_code' => strtoupper($code),
    'offer_title' => $result['offer']['title'],
    'subtotal' => $subtotal,
    'discount' => (float)$result['discount'],
    'free_delivery' => (bool)$result['free_delivery'],
    'delivery_charge' => $deliveryCharge,
    'total' => $subtotal - $result['discount'] + $deliveryCharge,
]);
