<?php
// Called while an assigned rider or authorized administrator shares the
// delivery phone's location. Store only the current position, not a history.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

function location_update_error($message, $statusCode) {
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$rider = is_rider_logged_in() ? current_rider() : null;
$isAdmin = is_admin_logged_in();
if (!$rider && !$isAdmin) {
    location_update_error('Please sign in as the assigned rider or an administrator.', 403);
}
if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    location_update_error('Your session expired. Refresh the page and try again.', 419);
}

$orderId = (int)($_POST['order_id'] ?? 0);
$latInput = $_POST['lat'] ?? null;
$lngInput = $_POST['lng'] ?? null;

if (!$orderId || !is_numeric($latInput) || !is_numeric($lngInput)) {
    location_update_error('Invalid order or coordinates.', 400);
}
$lat = (float)$latInput;
$lng = (float)$lngInput;
if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    location_update_error('Invalid coordinates.', 400);
}

$stmt = $pdo->prepare('SELECT id, order_status, rider_id, dark_store_id FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    location_update_error('Order not found.', 404);
}
if ($rider && (int)($order['rider_id'] ?? 0) !== (int)$rider['id']) {
    location_update_error('This delivery is not assigned to you.', 403);
}
if ($isAdmin && session_store_id() !== null) {
    if ($order['dark_store_id'] !== null && (int)$order['dark_store_id'] !== session_store_id()) {
        location_update_error('This order belongs to a different store.', 403);
    }
}
if (!in_array($order['order_status'], ['out_for_delivery', 'arriving_soon'], true)) {
    location_update_error('Live location can only be shared while the order is on the way.', 409);
}

$throttleKey = 'delivery_location_' . $orderId;
$now = time();
if (!empty($_SESSION[$throttleKey]) && ($now - (int)$_SESSION[$throttleKey] < 8)) {
    location_update_error('Location was updated recently.', 429);
}
$_SESSION[$throttleKey] = $now;

$stmt = $pdo->prepare("UPDATE orders SET delivery_lat = ?, delivery_lng = ?, location_updated_at = NOW() WHERE id = ?");
$stmt->execute([$lat, $lng, $orderId]);

echo json_encode(['success' => true, 'updated_at' => gmdate('c')]);
