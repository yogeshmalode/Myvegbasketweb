<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

$razorpay_payment_id = $_POST['razorpay_payment_id'] ?? '';
$razorpay_order_id   = $_POST['razorpay_order_id'] ?? '';
$razorpay_signature  = $_POST['razorpay_signature'] ?? '';

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');

$cart = $_SESSION['cart'] ?? [];

if (empty($cart) || !$razorpay_payment_id || !$razorpay_order_id || !$razorpay_signature) {
    echo json_encode(['success' => false, 'message' => 'Missing payment details or empty cart.']);
    exit;
}

// The order id must match the one we generated in create_order.php for this session
if (($_SESSION['pending_razorpay_order_id'] ?? '') !== $razorpay_order_id) {
    echo json_encode(['success' => false, 'message' => 'Order/session mismatch.']);
    exit;
}

// ---- Verify the HMAC-SHA256 signature Razorpay sent back ----
$expected_signature = hash_hmac(
    'sha256',
    $razorpay_order_id . '|' . $razorpay_payment_id,
    RAZORPAY_KEY_SECRET
);

if (!hash_equals($expected_signature, $razorpay_signature)) {
    echo json_encode(['success' => false, 'message' => 'Signature verification failed.']);
    exit;
}

// ---- Signature valid: record the order ----
try {
    $pdo->beginTransaction();

    $total = cart_total();

    $stmt = $pdo->prepare("INSERT INTO orders
        (customer_name, email, phone, address, total_amount, razorpay_order_id, razorpay_payment_id, payment_status, order_status)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', 'placed')");
    $stmt->execute([$name, $email, $phone, $address, $total, $razorpay_order_id, $razorpay_payment_id]);
    $orderId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, vegetable_id, name, price, quantity, subtotal, cost_price)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $lockStmt = $pdo->prepare("SELECT id, name, stock, cost_price FROM vegetables WHERE id = ? FOR UPDATE");
    $stockStmt = $pdo->prepare("UPDATE vegetables SET stock = stock - ? WHERE id = ? AND stock >= ?");
    $movementStmt = $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes) VALUES (?,'sale',?,?,?)");

    foreach ($cart as $item) {
        $lockStmt->execute([(int)$item['id']]);
        $product = $lockStmt->fetch();
        $beforeStock = (float)($product['stock'] ?? 0);
        $qtyToReduce = (float)($item['qty'] ?? 0);
        if (!$product || $beforeStock < $qtyToReduce) {
            throw new RuntimeException('Insufficient stock for ' . ($item['name'] ?? 'an item') . '.');
        }
        $subtotal = $item['price'] * $item['qty'];
        $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['price'], $item['qty'], $subtotal, $product['cost_price']]);
        $stockStmt->execute([$qtyToReduce, $item['id'], $qtyToReduce]);
        $verifyStmt = $pdo->prepare("SELECT stock FROM vegetables WHERE id = ?");
        $verifyStmt->execute([(int)$item['id']]);
        $afterStock = (float)($verifyStmt->fetchColumn() ?? 0);
        $expectedAfter = $beforeStock - $qtyToReduce;
        if (abs($afterStock - $expectedAfter) > 0.01) {
            throw new RuntimeException('Stock changed while placing the order. Please retry.');
        }
        $movementStmt->execute([(int)$item['id'], -(float)$qtyToReduce, $orderId, 'Razorpay checkout']);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Could not save order: ' . $e->getMessage()]);
    exit;
}

// Clear the cart now that the order is placed
unset($_SESSION['cart'], $_SESSION['pending_razorpay_order_id']);

echo json_encode(['success' => true, 'order_id' => $orderId]);
