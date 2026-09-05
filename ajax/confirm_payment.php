<?php
// Called when the customer clicks "Yes, I've paid" on the QR screen. This
// does NOT mark the order as paid automatically — a QR code has no way to
// tell your server that money actually arrived. It just records the
// customer's confirmation and sends an alert so you can check your UPI app
// and confirm the order in admin/orders.php.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$orderId = (int)($_POST['order_id'] ?? 0);

// Both sides must be compared as the same type — lastInsertId() in
// place_order.php returns a string, so cast the session value to int here
// too, otherwise a valid order can be wrongly rejected as a mismatch.
$pendingOrderId = (int)($_SESSION['pending_upi_order_id'] ?? 0);

if (!$orderId || $pendingOrderId !== $orderId) {
    echo json_encode(['success' => false, 'message' => 'Order/session mismatch.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    echo json_encode(['success' => false, 'message' => 'Order not found.']);
    exit;
}

send_order_alert(
    "Payment claimed for order #$orderId - please verify",
    [
        "The customer says they've paid for order #$orderId via UPI QR.",
        "",
        "Customer: {$order['customer_name']}",
        "Phone: {$order['phone']}",
        "Total: " . SITE_CURRENCY . number_format($order['total_amount'], 2),
        "",
        "Please check your PhonePe/UPI app for a matching credit, then mark this order as Paid in admin/orders.php.",
    ]
);

// Clear the cart and the pending-order marker now that checkout is done.
unset($_SESSION['cart'], $_SESSION['pending_upi_order_id']);

echo json_encode(['success' => true, 'order_id' => $orderId]);
