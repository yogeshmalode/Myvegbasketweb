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
// Resolve the dark store BEFORE the stock transaction (same reasoning as
// ajax/place_order.php) so per_store-mode products deduct from the right
// store's pool at the moment of sale, not after the fact.
$geo = geocode_address($address);
$darkStoreId = null;
$etaMinutes = null;
if ($geo) {
    $nearestStore = find_nearest_dark_store($pdo, $geo['lat'], $geo['lng']);
    if ($nearestStore) {
        $darkStoreId = (int)$nearestStore['id'];
        $eta = estimate_delivery_eta($nearestStore['lat'], $nearestStore['lng'], $geo['lat'], $geo['lng']);
        $etaMinutes = $eta['total_minutes'];
    }
}

try {
    $pdo->beginTransaction();

    $total = cart_total();

    $stmt = $pdo->prepare("INSERT INTO orders
        (customer_name, email, phone, address, address_lat, address_lng, total_amount, razorpay_order_id, razorpay_payment_id, payment_status, order_status, dark_store_id, eta_minutes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paid', 'placed', ?, ?)");
    $stmt->execute([$name, $email, $phone, $address, $geo['lat'] ?? null, $geo['lng'] ?? null, $total, $razorpay_order_id, $razorpay_payment_id, $darkStoreId, $etaMinutes]);
    $orderId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, vegetable_id, name, price, quantity, subtotal, cost_price)
        VALUES (?, ?, ?, ?, ?, ?, ?)");
    $lockStmt = $pdo->prepare("SELECT id, name, stock, cost_price FROM vegetables WHERE id = ? FOR UPDATE");
    $movementStmt = $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes) VALUES (?,'sale',?,?,?)");

    foreach ($cart as $item) {
        $lockStmt->execute([(int)$item['id']]);
        $product = $lockStmt->fetch();
        if (!$product) {
            throw new RuntimeException('Insufficient stock for ' . ($item['name'] ?? 'an item') . '.');
        }

        $baseQty = (float)($item['qty'] ?? 0);
        $unitLabel = trim((string)($item['unit'] ?? ''));
        $unitFraction = null;
        if ($unitLabel !== '') {
            try { $unitFraction = size_fraction_of_base_unit($unitLabel, $product['unit'] ?? 'kg'); } catch (Throwable $e) { $unitFraction = null; }
        }
        if ($unitFraction !== null && $unitFraction > 0) {
            $baseQty = round((float)$item['qty'] * $unitFraction, 4);
        }

        $subtotal = $item['price'] * $item['qty'];
        $itemStmt->execute([$orderId, $item['id'], $item['name'], $item['price'], $item['qty'], $subtotal, $product['cost_price']]);

        if (!apply_stock_delta($pdo, (int)$item['id'], -$baseQty, $darkStoreId)) {
            throw new RuntimeException('Stock changed while placing the order. Please retry.');
        }
        $movementStmt->execute([(int)$item['id'], -(float)$baseQty, $orderId, 'Razorpay checkout']);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Could not save order: ' . $e->getMessage()]);
    exit;
}

// dark_store_id/eta_minutes/address_lat/address_lng were already resolved
// and saved above — no need to call dispatch_order_to_dark_store() here too.

// Hand this order to the least-busy packer at the resolved store right
// away, instead of making an admin pick someone from a dropdown manually.
auto_assign_picker_for_order($pdo, $orderId, $darkStoreId);

// Clear the cart now that the order is placed
unset($_SESSION['cart'], $_SESSION['pending_razorpay_order_id']);

echo json_encode(['success' => true, 'order_id' => $orderId]);
