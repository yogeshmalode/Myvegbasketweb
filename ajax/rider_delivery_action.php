<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

require_rider_login();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$csrf = trim($input['csrf_token'] ?? '');
try {
    require_csrf($csrf);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'CSRF']);
    exit;
}

$orderId = (int)($input['order_id'] ?? 0);
$action = trim((string)($input['action'] ?? ''));
$rider = current_rider();
$riderId = (int)($rider['id'] ?? 0);

if ($orderId <= 0 || $riderId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid order or rider']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, order_status, rider_id FROM orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }
    if ((int)($order['rider_id'] ?? 0) !== $riderId) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'This order is not assigned to you']);
        exit;
    }

    $currentStatus = normalize_order_status($order['order_status']);
    if ($action === 'accept') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Only assigned orders can be accepted']);
            exit;
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => $currentStatus]);
        exit;
    }

    if ($action === 'reject') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Only assigned orders can be rejected']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET rider_id = NULL, order_status = 'ready_for_pickup', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([$riderId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => 'ready_for_pickup', 'retry_assignment' => true]);
        exit;
    }

    if ($action === 'pickup') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Pickup is only allowed after rider assignment']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET order_status = 'out_for_delivery', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => 'out_for_delivery']);
        exit;
    }

    if ($action === 'arriving') {
        if ($currentStatus !== 'out_for_delivery') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Order must be out for delivery first']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET order_status = 'arriving_soon', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => 'arriving_soon']);
        exit;
    }

    if ($action === 'deliver') {
        if (!in_array($currentStatus, ['out_for_delivery', 'arriving_soon'], true)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Order must be on the way before it can be delivered']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET order_status = 'delivered', updated_at = NOW(), delivered_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([$riderId]);
        $ins = $pdo->prepare('INSERT INTO delivery_confirmations (order_id, rider_id, confirmed_by, method, note) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$orderId, $riderId, $rider['name'] ?? 'Rider', 'rider_app', 'Delivered from rider dashboard']);
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => 'delivered']);
        exit;
    }

    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Unsupported action']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => 'Database update failed']);
}
