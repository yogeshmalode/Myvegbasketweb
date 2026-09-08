<?php
// Called repeatedly (every few seconds) from admin/deliver.php while the
// delivery person's phone has location sharing turned on. Just overwrites
// the order's current lat/lng — we don't keep a location history, only
// "where are they right now."
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!is_admin_logged_in()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in.']);
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
$lat = isset($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) ? (float)$_POST['lng'] : null;

if (!$orderId || $lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode(['success' => false, 'message' => 'Invalid coordinates.']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, order_status, rider_id FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}
if (($order['order_status'] ?? '') === 'delivered') {
    echo json_encode(['success' => false, 'message' => 'Delivery already completed.']);
    exit;
}

$throttleKey = 'delivery_location_' . $orderId;
$now = time();
if (!empty($_SESSION[$throttleKey]) && ($now - (int)$_SESSION[$throttleKey] < 8)) {
    echo json_encode(['success' => false, 'message' => 'Too many updates.']);
    exit;
}
$_SESSION[$throttleKey] = $now;

$stmt = $pdo->prepare("UPDATE orders SET delivery_lat = ?, delivery_lng = ?, location_updated_at = NOW() WHERE id = ?");
$stmt->execute([$lat, $lng, $orderId]);

echo json_encode(['success' => true, 'updated_at' => date('c')]);
