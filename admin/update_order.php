<?php
require_once __DIR__ . '/includes/auth.php';

$id    = (int)($_GET['id'] ?? 0);
$type  = $_GET['type'] ?? '';
$value = $_GET['value'] ?? '';

$allowedPaymentValues = ['pending', 'awaiting_verification', 'paid', 'failed'];
$allowedOrderValues   = ['pending', 'placed', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];

$ok = false;

if ($id && $type === 'payment' && in_array($value, $allowedPaymentValues, true)) {
    $stmt = $pdo->prepare("UPDATE orders SET payment_status = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Order #$id payment status set to " . str_replace('_', ' ', $value) . "."];
    $ok = true;
} elseif ($id && $type === 'order' && in_array($value, $allowedOrderValues, true)) {
    $stmt = $pdo->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
    $stmt->execute([$value, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Order #$id status set to " . str_replace('_', ' ', $value) . "."];
    $ok = true;
}

if (!$ok) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid order or action.'];
}

redirect('orders.php');
