<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$id = (int)($input['id'] ?? 0);
$status = normalize_order_status($input['status'] ?? '');
$allowed = array_keys(get_order_status_options());
if ($id<=0 || !in_array($status, $allowed, true)) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
try {
    $pdo->beginTransaction();
    $orderStmt = $pdo->prepare('SELECT id, order_status, rider_id FROM orders WHERE id = ? FOR UPDATE');
    $orderStmt->execute([$id]);
    $order = $orderStmt->fetch();
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'Order not found']);
        exit;
    }

    $currentStatus = normalize_order_status($order['order_status']);
    if (!can_transition_order_status($currentStatus, $status)) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => 'Invalid workflow step. Move the order through the required sequence.',
            'current_status' => $currentStatus,
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
    if ($status === 'delivered') {
        $updateFields[] = 'delivered_at = NOW()';
    }
    $params[] = $id;
    $st = $pdo->prepare('UPDATE orders SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
    $st->execute($params);

    if (in_array($status, ['delivered', 'cancelled'], true) && !empty($order['rider_id'])) {
        $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([(int)$order['rider_id']]);
    }

    $pdo->commit();

    if (in_array($status, ['processing', 'ready_for_pickup', 'assigning_rider', 'delivery_partner_assigned', 'out_for_delivery', 'arriving_soon', 'delivered'], true)) {
        send_order_alert("Order #$id status: $status", ["Order #$id changed from $currentStatus to $status."]);
    }
    echo json_encode(['success'=>true,'status'=>$status,'status_display'=>get_order_status_options()[$status] ?? ucfirst(str_replace('_',' ',$status))]);
} catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'DB']); }
