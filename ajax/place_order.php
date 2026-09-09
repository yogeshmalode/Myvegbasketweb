<?php
// Called when the customer submits the checkout form under the UPI QR
// payment flow. Saves the order immediately (payment_status =
// 'awaiting_verification') so nothing is lost even if the customer closes
// the tab before confirming payment, then returns the order id so the
// front-end can show the QR code screen.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit;
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

if ($name === '' || $email === '' || $phone === '' || $address === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all delivery details.']);
    exit;
}

// Recalculate total on the server — never trust a client-sent amount.
// Re-validate any applied coupon fresh here too, rather than trusting
// whatever was computed when it was first applied.
$subtotal = cart_total();
$deliveryCharge = get_delivery_charge($subtotal);

$couponCode = null;
$discountAmount = 0;
if (!empty($_SESSION['applied_coupon_code'])) {
    $couponCheck = validate_coupon($pdo, $_SESSION['applied_coupon_code'], $subtotal);
    if ($couponCheck['valid']) {
        $couponCode = $couponCheck['offer']['coupon_code'];
        $discountAmount = $couponCheck['discount'];
        if ($couponCheck['free_delivery']) $deliveryCharge = 0;
    }
}

$total = $subtotal - $discountAmount + $deliveryCharge;

// If the person is logged in, tie the order to their account so it shows
// up in "My Orders". Guests can still check out fine — customer_id is
// simply left null for them.
$customerId = $_SESSION['customer_id'] ?? null;

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("INSERT INTO orders
        (customer_id, customer_name, email, phone, address, total_amount, coupon_code, discount_amount, payment_method, payment_status, order_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'upi_qr', 'awaiting_verification', 'placed')");
    $stmt->execute([$customerId, $name, $email, $phone, $address, $total, $couponCode, $discountAmount]);
    $orderId = $pdo->lastInsertId();

    $itemStmt  = $pdo->prepare("INSERT INTO order_items (order_id, vegetable_id, vegetable_variant_id, variant_label, name, price, quantity, subtotal, cost_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $lockStmt = $pdo->prepare("SELECT id, name, unit, stock, cost_price FROM vegetables WHERE id = ? FOR UPDATE");
    $stockStmt = $pdo->prepare("UPDATE vegetables SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $movementStmt = $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes) VALUES (?,'sale',?,?,?)");

    $itemLines = [];
    foreach ($cart as $item) {
        $lockStmt->execute([(int)$item['id']]);
        $product = $lockStmt->fetch();
        $beforeStock = (float)($product['stock'] ?? 0);
        $qtyToReduce = (float)($item['qty'] ?? 0);
        if (!$product || $beforeStock < $qtyToReduce) {
            throw new RuntimeException('Insufficient stock for ' . ($item['name'] ?? 'an item') . '.');
        }

        $lineSubtotal = $item['price'] * $item['qty'];
        $variantId = $item['variant_id'] ?? null;
        $itemStmt->execute([
            $orderId,
            $item['id'],
            $variantId,
            $variantId ? $item['unit'] : null,
            $item['name'],
            $item['price'],
            $item['qty'],
            $lineSubtotal,
            $product['cost_price'],
        ]);

        $stockStmt->execute([$qtyToReduce, $item['id'], $qtyToReduce]);
        $verifyStmt = $pdo->prepare("SELECT stock FROM vegetables WHERE id = ?");
        $verifyStmt->execute([(int)$item['id']]);
        $afterStock = (float)($verifyStmt->fetchColumn() ?? 0);
        $expectedAfter = $beforeStock - $qtyToReduce;
        if (abs($afterStock - $expectedAfter) > 0.01) {
            throw new RuntimeException('Stock changed while placing the order. Please try again.');
        }

        $movementStmt->execute([(int)$item['id'], -(float)$qtyToReduce, $orderId, 'Online checkout']);
        $unitSuffix = $variantId ? '' : ' ' . $item['unit'];
        $itemLines[] = "  - {$item['name']} x {$item['qty']}{$unitSuffix} = " . SITE_CURRENCY . number_format($lineSubtotal, 2);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Could not save order: ' . $e->getMessage()]);
    exit;
}

// The coupon (if any) has now been spent on this order — clear it so it
// doesn't silently carry over onto whatever the person orders next.
unset($_SESSION['applied_coupon_code']);

// Remember which order this session is currently paying for, so
// confirm_payment.php can't be used to tamper with someone else's order.
$_SESSION['pending_upi_order_id'] = $orderId;

// Let this browser session track this order's live location later,
// without needing to type in a phone number to prove ownership
// (useful for guest checkouts that never log in).
$_SESSION['guest_order_access'][] = $orderId;

// Alert the admin straight away — this is the earliest point we know a
// customer intends to pay, even before they confirm the UPI transaction.
send_order_alert(
    "New order #$orderId - awaiting UPI payment",
    array_filter(array_merge([
        "A new order was placed on " . SITE_NAME . " and is awaiting UPI payment confirmation.",
        "",
        "Order #: $orderId",
        "Customer: $name",
        "Phone: $phone",
        "Email: $email",
        "Address: $address",
        "Subtotal: " . SITE_CURRENCY . number_format($subtotal, 2),
        $couponCode ? "Coupon: $couponCode (-" . SITE_CURRENCY . number_format($discountAmount, 2) . ")" : null,
        "Delivery charge: " . ($deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge, 2) : 'FREE'),
        "Total: " . SITE_CURRENCY . number_format($total, 2),
        "",
        "Items:",
    ], $itemLines, [
        "",
        "Check admin/orders.php once the customer confirms payment, and verify the UPI transaction before marking it paid.",
    ]), fn($line) => $line !== null)
);

echo json_encode([
    'success'  => true,
    'order_id' => $orderId,
    'amount'   => $total,
]);
