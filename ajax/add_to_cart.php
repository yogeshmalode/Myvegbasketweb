<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$id        = (int)($_POST['id'] ?? 0);
$qty       = max(1, (int)($_POST['qty'] ?? 1));
$variantId = (int)($_POST['variant_id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid item.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$veg = $stmt->fetch();

if (!$veg) {
    echo json_encode(['success' => false, 'message' => 'Vegetable not found.']);
    exit;
}

// Per_store products have no single global stock number — the shop doesn't
// know which store will fulfil this order until checkout resolves the
// delivery address, so cap against the TOTAL across every store instead
// (apply_stock_delta() still enforces the real per-store limit at checkout).
$displayStock = get_storefront_display_stock($pdo, $veg['id'], $veg['stock_mode'] ?? 'shared', $veg['stock']);
$veg['stock'] = $displayStock;

// If a size/weight variant was picked, look it up and make sure it really
// belongs to this product — never trust price/label from the client.
$variant = null;
if ($variantId) {
    try {
        $vStmt = $pdo->prepare("SELECT * FROM vegetable_variants WHERE id = ? AND vegetable_id = ?");
        $vStmt->execute([$variantId, $id]);
        $variant = $vStmt->fetch();
    } catch (PDOException $e) {
        // Variants table not set up yet — just treat this as a plain add.
        $variant = null;
    }
}

$price = $variant
    ? (float)$variant['price']
    : get_price_for_customer($pdo, $veg, current_customer_type());
if ($price <= 0) {
    echo json_encode(['success' => false, 'message' => 'This item is currently unavailable because it has no valid price.']);
    exit;
}

$maxQuantity = get_cart_item_stock_limit($pdo, $veg['id'], $veg['stock'], $veg['unit'], $variant['id'] ?? null);
if ($maxQuantity <= 0) {
    echo json_encode([
        'success' => false,
        'out_of_stock' => true,
        'message' => $veg['name'] . ' is out of stock. Sign up for an email when it is available.',
    ]);
    exit;
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Each size of a product is its own cart line (e.g. "Milk 250 ml" and
// "Milk 1 litre" are tracked separately), keyed by vegetableId-variantId.
// Plain products without variants keep the simple numeric key as before.
$key = $variant ? $id . '-' . $variant['id'] : (string)$id;

if (isset($_SESSION['cart'][$key])) {
    $_SESSION['cart'][$key]['qty'] += $qty;
} else {
    $_SESSION['cart'][$key] = [
        'key'        => $key,
        'id'         => $veg['id'],
        'variant_id' => $variant['id'] ?? null,
        'name'       => $variant ? $veg['name'] . ' (' . $variant['label'] . ')' : $veg['name'],
        'price'      => $price,
        'unit'       => $variant ? $variant['label'] : $veg['unit'],
        'qty'        => $qty,
    ];
}

// Don't let cart quantity exceed available stock (tracked per base product).
$_SESSION['cart'][$key]['qty'] = min($_SESSION['cart'][$key]['qty'], $maxQuantity);

echo json_encode([
    'success'     => true,
    'message'     => $_SESSION['cart'][$key]['name'] . ' added to basket',
    'cart_count'  => cart_count(),
    'cart_total'  => number_format(cart_total(), 2),
]);
