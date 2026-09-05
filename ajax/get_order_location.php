<?php
// Polled every few seconds by the customer's tracking page to move the
// delivery marker. Guarded so a random person can't watch someone else's
// delivery just by guessing an order number: they must either be logged in
// as that order's customer, or supply the exact phone number on the order
// (the same lightweight check used by track_order.php).
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$orderId = (int)($_GET['order_id'] ?? 0);
$phone   = trim($_GET['phone'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

$allowed = false;
if (is_customer_logged_in() && $order['customer_id'] && (int)$order['customer_id'] === (int)$_SESSION['customer_id']) {
    $allowed = true;
} elseif (!empty($_SESSION['guest_order_access']) && in_array($orderId, $_SESSION['guest_order_access'])) {
    $allowed = true;
} elseif ($phone !== '' && hash_equals($order['phone'], $phone)) {
    $allowed = true;
}

if (!$allowed) {
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

echo json_encode([
    'success'             => true,
    'order_status'        => $order['order_status'],
    'payment_status'      => $order['payment_status'],
    'delivery_lat'        => $order['delivery_lat'] !== null ? (float)$order['delivery_lat'] : null,
    'delivery_lng'        => $order['delivery_lng'] !== null ? (float)$order['delivery_lng'] : null,
    'location_updated_at' => $order['location_updated_at'],
]);
