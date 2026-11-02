<?php
define('JSON_API_REQUEST', true);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../admin/includes/auth.php';
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit;
}
$csrf = trim($input['csrf_token'] ?? '');
if (!verify_csrf($csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
    exit;
}
$id = (int)($input['id'] ?? 0);
$status = normalize_order_status($input['status'] ?? '');
$allowed = array_keys(get_order_status_options());
if ($id<=0 || !in_array($status, $allowed, true)) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
try {
    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare('SELECT id, order_status, rider_id, dark_store_id FROM orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$id]);
    $order = $orderStmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'Order not found']);
        exit;
    }
    if (session_store_id() !== null && $order['dark_store_id'] !== null
        && (int)$order['dark_store_id'] !== session_store_id()) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'This order belongs to a different store']);
        exit;
    }

    $currentStatus = normalize_order_status($order['order_status']);
    if (!can_transition_order_status($currentStatus, $status)) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Invalid workflow step. Move the order through the required sequence.',
            'current_status' => $currentStatus,
            'allowed_next_statuses' => get_allowed_order_status_transitions()[$currentStatus] ?? [],
        ]);
        exit;
    }

    if ($status === 'out_for_delivery' && empty($order['rider_id'])) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'Assign and confirm a rider before starting delivery.']);
        exit;
    }

    $updateFields = ['order_status = ?', 'updated_at = NOW()'];
    $params = [$status];
    if ($status === 'processing' && $currentStatus === 'placed') {
        $updateFields[] = 'packing_started_at = NOW()';
    }
    if ($status === 'ready_for_pickup' && $currentStatus === 'processing') {
        $updateFields[] = 'packing_completed_at = UTC_TIMESTAMP()';
    }
    if ($status === 'delivered') {
        $updateFields[] = 'delivered_at = NOW()';
    }
    $params[] = $id;
    $st = $pdo->prepare('UPDATE orders SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
    $st->execute($params);

    $freedRiderId = null;
    if (in_array($status, ['delivered', 'cancelled'], true) && !empty($order['rider_id'])) {
        $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([(int)$order['rider_id']]);
        $freedRiderId = (int)$order['rider_id'];
    }

    // Order is being cancelled — the stock deducted when it was placed was
    // never actually sold, so put it back on the shelf now.
    if ($status === 'cancelled' && $currentStatus !== 'cancelled') {
        restock_cancelled_order($pdo, $id);
    }

    $pdo->commit();

    $riderAssignment = null;
    if ($status === 'ready_for_pickup' && $currentStatus === 'processing') {
        $riderAssignment = auto_assign_ready_order_to_nearest_rider($pdo, $id);
    }

    // The rider is free again — immediately hand them the next queued
    // order at their dark store instead of leaving them idle until an
    // admin manually clicks Auto-Assign.
    if ($freedRiderId) {
        auto_assign_next_order_to_rider($pdo, $freedRiderId);
    }

    if (in_array($status, ['processing', 'ready_for_pickup', 'assigning_rider', 'delivery_partner_assigned', 'out_for_delivery', 'arriving_soon', 'delivered'], true)) {
        try {
            send_order_alert("Order #$id status: $status", ["Order #$id changed from $currentStatus to $status."]);
        } catch (Throwable $notificationError) {
            error_log('Order status alert failed for order #' . $id . ': ' . $notificationError->getMessage());
        }
    }
    $customerNotification = null;
    if ($currentStatus !== $status) {
        $customerStatus = ($status === 'ready_for_pickup' && is_array($riderAssignment) && !empty($riderAssignment['assigned']))
            ? 'delivery_partner_assigned'
            : $status;
        try {
            $customerNotification = send_customer_order_status_notification($pdo, $id, $customerStatus);
        } catch (Throwable $notificationError) {
            error_log('Customer status notification failed for order #' . $id . ': ' . $notificationError->getMessage());
            $customerNotification = 'failed';
        }
    }

    $response = [
        'success' => true,
        'status' => $status,
        'status_display' => get_order_status_options()[$status] ?? ucfirst(str_replace('_',' ',$status)),
    ];
    if ($riderAssignment !== null) {
        $response['rider_assignment'] = $riderAssignment['assigned'] ? 'assigned' : 'pending';
        if ($riderAssignment['assigned']) {
            $response['notice'] = 'Ready for Dispatch. Automatically assigned to rider ' . $riderAssignment['rider_name'] . '.';
        } else {
            $response['notice'] = 'Ready for Dispatch, but rider assignment is pending: ' . $riderAssignment['reason'] . '. The order remains in the Ready tab.';
        }
    }
    if ($customerNotification !== null) {
        $response['customer_notification'] = $customerNotification;
        if ($customerNotification !== 'sent') {
            $response['notification_warning'] = $customerNotification === 'missing_email'
                ? 'No valid customer email is saved for this order; no email was sent.'
                : 'Customer status email could not be sent. Check Hostinger mail/SMTP configuration.';
        }
    }
    echo json_encode($response);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order status update failed for order #' . $id . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'The order could not be updated. Please retry; if it continues, contact an administrator.']);
}
