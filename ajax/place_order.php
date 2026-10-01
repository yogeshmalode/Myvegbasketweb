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
$deliveryDate = trim($_POST['delivery_date'] ?? '');
$deliverySlot = trim($_POST['delivery_slot'] ?? '');

if ($name === '' || $email === '' || $phone === '' || $address === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all delivery details.']);
    exit;
}

if ($deliveryDate === '' || $deliverySlot === '') {
    echo json_encode(['success' => false, 'message' => 'Please choose a delivery date and time slot.']);
    exit;
}

// Never trust a client-sent date — reject anything before today so no one
// can request delivery in the past via a tampered request.
$today = date('Y-m-d');
if ($deliveryDate < $today) {
    echo json_encode(['success' => false, 'message' => 'Delivery date cannot be in the past.']);
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

// Resolve which dark store this order belongs to BEFORE the stock
// transaction starts (not after, like dispatch_order_to_dark_store() used
// to do) — per_store-mode products need to know the target store at the
// exact moment stock gets deducted, not later. Geocoding failure here is
// still non-fatal: $darkStoreId just stays null and per_store items fall
// back to treating that line as "no store stock available" (same as a
// product variant that's out of stock), while shared-mode products are
// completely unaffected either way.
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

    $stmt = $pdo->prepare("INSERT INTO orders
        (customer_id, customer_name, email, phone, address, address_lat, address_lng, delivery_date, delivery_slot, total_amount, coupon_code, discount_amount, payment_method, payment_status, order_status, dark_store_id, eta_minutes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'upi_qr', 'awaiting_verification', 'placed', ?, ?)");
    $stmt->execute([$customerId, $name, $email, $phone, $address, $geo['lat'] ?? null, $geo['lng'] ?? null, $deliveryDate, $deliverySlot, $total, $couponCode, $discountAmount, $darkStoreId, $etaMinutes]);
    $orderId = $pdo->lastInsertId();

    $itemStmt  = $pdo->prepare("INSERT INTO order_items (order_id, vegetable_id, vegetable_variant_id, variant_label, name, price, quantity, subtotal, cost_price)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $lockStmt = $pdo->prepare("SELECT id, name, unit, stock, cost_price FROM vegetables WHERE id = ? FOR UPDATE");
    $movementStmt = $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes) VALUES (?,'sale',?,?,?)");

    $itemLines = [];
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

        // apply_stock_delta() deducts from store_inventory for a
        // per_store-mode product (using the $darkStoreId resolved above)
        // or the shared vegetables.stock column otherwise, atomically
        // rejecting the change if there isn't actually enough left.
        if (!apply_stock_delta($pdo, (int)$item['id'], -$baseQty, $darkStoreId)) {
            throw new RuntimeException('Stock changed while placing the order. Please try again.');
        }

        $movementStmt->execute([(int)$item['id'], -(float)$baseQty, $orderId, 'Online checkout']);
        $unitSuffix = $variantId ? '' : ' ' . $item['unit'];
        $itemLines[] = "  - {$item['name']} x {$item['qty']}{$unitSuffix} = " . SITE_CURRENCY . number_format($lineSubtotal, 2);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Could not save order: ' . $e->getMessage()]);
    exit;
}

// dark_store_id/eta_minutes/address_lat/address_lng were already resolved
// and saved above, so there's no need to call dispatch_order_to_dark_store()
// here too — doing so would just re-geocode the same address a second time.

// Hand this order to the least-busy packer at the resolved store right
// away, instead of making an admin pick someone from a dropdown manually.
auto_assign_picker_for_order($pdo, $orderId, $darkStoreId);

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
        "Delivery: " . date('d M Y', strtotime($deliveryDate)) . ", $deliverySlot",
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
