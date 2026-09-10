<?php
require_once __DIR__ . '/includes/auth.php';

$id    = (int)($_GET['id'] ?? 0);
$type  = $_GET['type'] ?? '';
$value = $_GET['value'] ?? '';

$allowedPaymentValues = ['pending', 'awaiting_verification', 'paid', 'failed'];
$allowedOrderValues   = array_keys(get_order_status_options());

$ok = false;

if ($id && $type === 'payment' && in_array($value, $allowedPaymentValues, true)) {
    $stmt = $pdo->prepare("UPDATE orders SET payment_status = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Order #$id payment status set to " . str_replace('_', ' ', $value) . "."];
    $ok = true;
} elseif ($id && $type === 'order' && in_array($value, $allowedOrderValues, true)) {
    $normalized = normalize_order_status($value);
    $orderStmt = $pdo->prepare("SELECT id, order_status, rider_id FROM orders WHERE id = ?");
    $orderStmt->execute([$id]);
    $order = $orderStmt->fetch();
    if ($order) {
        $currentStatus = normalize_order_status($order['order_status']);
        if (!can_transition_order_status($currentStatus, $normalized)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => "Order #$id cannot move from " . str_replace('_', ' ', $currentStatus) . " to " . str_replace('_', ' ', $normalized) . "."];
        } elseif ($normalized === 'out_for_delivery' && empty($order['rider_id'])) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => "Order #$id needs a rider before delivery can start."];
        } else {
            $fields = ['order_status = ?'];
            $params = [$normalized];
            if ($normalized === 'processing' && $currentStatus === 'placed') {
                $fields[] = 'packing_started_at = NOW()';
            }
            if ($normalized === 'delivered') {
                $fields[] = 'delivered_at = NOW()';
            }
            $params[] = $id;
            $stmt = $pdo->prepare("UPDATE orders SET " . implode(', ', $fields) . " WHERE id = ?");
            $stmt->execute($params);
            if (in_array($normalized, ['delivered', 'cancelled'], true) && !empty($order['rider_id'])) {
                $pdo->prepare("UPDATE riders SET availability_status = 'available' WHERE id = ?")->execute([(int)$order['rider_id']]);
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Order #$id status set to " . str_replace('_', ' ', $normalized) . "."];
            $ok = true;
        }
    }
}

if (!$ok && empty($_SESSION['flash'])) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid order or action.'];
}

redirect('orders.php');
