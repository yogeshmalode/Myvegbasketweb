<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/worker_attendance.php';
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
    close_due_worker_attendance($pdo, 'rider', $riderId);
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, order_status, rider_id, rider_accepted_at FROM orders WHERE id = ? FOR UPDATE');
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
    if (in_array($action, ['accept', 'reject', 'pickup', 'arriving', 'deliver'], true)) {
        $attendanceStmt = $pdo->prepare("SELECT 1 FROM worker_attendance
            WHERE worker_type = 'rider' AND worker_id = ? AND clock_out_at IS NULL
            LIMIT 1");
        $attendanceStmt->execute([$riderId]);
        $isClockedIn = (bool)$attendanceStmt->fetchColumn();
        $canRejectAssignedOrder = $action === 'reject' && $currentStatus === 'delivery_partner_assigned';
        $canFinishAcceptedOrder = !empty($order['rider_accepted_at'])
            && (($action === 'pickup' && $currentStatus === 'delivery_partner_assigned')
                || ($action === 'arriving' && $currentStatus === 'out_for_delivery')
                || ($action === 'deliver' && in_array($currentStatus, ['out_for_delivery', 'arriving_soon'], true)));
        if (!$isClockedIn && !$canRejectAssignedOrder && !$canFinishAcceptedOrder) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Clock in before working on delivery orders']);
            exit;
        }
    }

    if ($action === 'accept') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Only assigned orders can be accepted']);
            exit;
        }
        if (empty($order['rider_accepted_at'])) {
            $pdo->prepare('UPDATE orders SET rider_accepted_at = NOW(), updated_at = NOW() WHERE id = ?')
                ->execute([$orderId]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'status' => $currentStatus, 'accepted' => true]);
        exit;
    }

    if ($action === 'reject') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Only assigned orders can be rejected']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET rider_id = NULL, rider_accepted_at = NULL, order_status = 'ready_for_pickup', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE riders SET availability_status = CASE
            WHEN EXISTS (SELECT 1 FROM worker_attendance WHERE worker_type = 'rider' AND worker_id = ? AND clock_out_at IS NULL)
            THEN 'available' ELSE 'offline' END WHERE id = ?")->execute([$riderId, $riderId]);
        $pdo->commit();
        close_due_worker_attendance($pdo, 'rider', $riderId);
        // The rider is free again — immediately hand them the next queued
        // order at their dark store instead of leaving them idle until an
        // admin manually clicks Auto-Assign.
        auto_assign_next_order_to_rider($pdo, $riderId, $orderId);
        $rejectedOrder = $pdo->prepare('SELECT order_status FROM orders WHERE id = ?');
        $rejectedOrder->execute([$orderId]);
        if (normalize_order_status($rejectedOrder->fetchColumn()) === 'ready_for_pickup') {
            $customerNotification = send_customer_order_status_notification($pdo, $orderId, 'ready_for_pickup');
        } else {
            $customerNotification = 'sent';
        }
        echo json_encode(['success' => true, 'status' => 'ready_for_pickup', 'retry_assignment' => true, 'customer_notification' => $customerNotification]);
        exit;
    }

    if ($action === 'pickup') {
        if ($currentStatus !== 'delivery_partner_assigned') {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Pickup is only allowed after rider assignment']);
            exit;
        }
        if (empty($order['rider_accepted_at'])) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Accept the assigned order before confirming pickup']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET order_status = 'out_for_delivery', updated_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->commit();
        $customerNotification = send_customer_order_status_notification($pdo, $orderId, 'out_for_delivery');
        echo json_encode(['success' => true, 'status' => 'out_for_delivery', 'customer_notification' => $customerNotification]);
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
        $customerNotification = send_customer_order_status_notification($pdo, $orderId, 'arriving_soon');
        echo json_encode(['success' => true, 'status' => 'arriving_soon', 'customer_notification' => $customerNotification]);
        exit;
    }

    if ($action === 'deliver') {
        if (!in_array($currentStatus, ['out_for_delivery', 'arriving_soon'], true)) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Order must be on the way before it can be delivered']);
            exit;
        }
        $pdo->prepare("UPDATE orders SET order_status = 'delivered', updated_at = NOW(), delivered_at = NOW() WHERE id = ?")->execute([$orderId]);
        $pdo->prepare("UPDATE riders SET availability_status = CASE
            WHEN EXISTS (SELECT 1 FROM worker_attendance WHERE worker_type = 'rider' AND worker_id = ? AND clock_out_at IS NULL)
            THEN 'available' ELSE 'offline' END WHERE id = ?")->execute([$riderId, $riderId]);
        $ins = $pdo->prepare('INSERT INTO delivery_confirmations (order_id, rider_id, confirmed_by, method, note) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$orderId, $riderId, $rider['name'] ?? 'Rider', 'rider_app', 'Delivered from rider dashboard']);
        $pdo->commit();
        $customerNotification = send_customer_order_status_notification($pdo, $orderId, 'delivered');
        close_due_worker_attendance($pdo, 'rider', $riderId);
        // Rider just finished a delivery and is free — pull them straight
        // into the next waiting order at their dark store automatically.
        auto_assign_next_order_to_rider($pdo, $riderId);
        echo json_encode(['success' => true, 'status' => 'delivered', 'customer_notification' => $customerNotification]);
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
