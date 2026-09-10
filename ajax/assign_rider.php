<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF token mismatch']); exit; }

$orderId = (int)($input['order_id'] ?? 0);
$riderId = isset($input['rider_id']) && $input['rider_id'] !== '' ? (int)$input['rider_id'] : null;
$auto = !empty($input['auto']);
if ($orderId <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid order']); exit; }

$ord = $pdo->prepare('SELECT id, rider_id, dark_store_id, address_lat, address_lng, order_status FROM orders WHERE id = ?');
$ord->execute([$orderId]);
$order = $ord->fetch();
if (!$order) { echo json_encode(['success'=>false,'error'=>'Order not found']); exit; }
$currentStatus = normalize_order_status($order['order_status']);
if (!in_array($currentStatus, ['ready_for_pickup', 'assigning_rider', 'delivery_partner_assigned'], true)) {
    echo json_encode(['success'=>false,'error'=>'Rider can only be assigned after the order is ready for pickup.']);
    exit;
}
$previousRiderId = $order['rider_id'] ? (int)$order['rider_id'] : null;

// Smart Rider Allocation: auto-assign the nearest available rider at the
// order's dark store hub instead of trusting a rider_id from the client.
if ($auto) {
    $darkStoreId = $order['dark_store_id'];
    if (!$darkStoreId) {
        $store = find_nearest_dark_store($pdo, $order['address_lat'], $order['address_lng']);
        $darkStoreId = $store['id'] ?? null;
        if ($darkStoreId) {
            $pdo->prepare('UPDATE orders SET dark_store_id = ? WHERE id = ?')->execute([$darkStoreId, $orderId]);
        }
    }
    if (!$darkStoreId) { echo json_encode(['success'=>false,'error'=>'No dark store available to allocate from']); exit; }
    $best = allocate_nearest_available_rider($pdo, $darkStoreId);
    if (!$best) { echo json_encode(['success'=>false,'error'=>'No available riders at this dark store right now']); exit; }
    $riderId = (int)$best['id'];
}

if ($riderId !== null) {
    $rider = $pdo->prepare('SELECT id FROM riders WHERE id = ? AND is_active = 1');
    $rider->execute([$riderId]);
    if (!$rider->fetch()) {
        echo json_encode(['success'=>false,'error'=>'Selected rider is invalid or inactive']);
        exit;
    }
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE orders SET order_status = 'assigning_rider', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
    $st = $pdo->prepare("UPDATE orders SET rider_id = ?, order_status = 'delivery_partner_assigned', updated_at = NOW() WHERE id = ?");
    $st->execute([$riderId, $orderId]);

    // Free the previously-assigned rider (if any, and different from the new one).
    if ($previousRiderId && $previousRiderId !== $riderId) {
        $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([$previousRiderId]);
    }
    // Mark the newly-assigned rider as busy so they're skipped by future allocation.
    if ($riderId !== null) {
        $pdo->prepare("UPDATE riders SET availability_status = 'busy' WHERE id = ?")->execute([$riderId]);
    }
    $pdo->commit();
    send_order_alert("Order #$orderId rider assigned", ["Order #$orderId was assigned to rider #$riderId and is waiting for pickup confirmation."]);
    echo json_encode(['success'=>true, 'rider_id' => $riderId, 'status' => 'delivery_partner_assigned']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'Database update failed: ' . $e->getMessage()]);
}
