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
        'price'      => $variant ? (float)$variant['price'] : get_effective_price($veg),
        'unit'       => $variant ? $variant['label'] : $veg['unit'],
        'qty'        => $qty,
    ];
}

// Don't let cart quantity exceed available stock (tracked per base product)
if ($variant) {
    // compute fraction (e.g. 250 g => 0.25 of base unit)
    $fraction = null;
    try { $fraction = size_fraction_of_base_unit($variant['label'], $veg['unit']); } catch (Throwable $e) { $fraction = null; }
    if ($fraction !== null && $fraction > 0) {
        $maxPacks = (int)floor(((float)$veg['stock']) / $fraction);
        if ($_SESSION['cart'][$key]['qty'] > $maxPacks) {
            $_SESSION['cart'][$key]['qty'] = $maxPacks;
        }
    } else {
        if ($_SESSION['cart'][$key]['qty'] > (int)$veg['stock']) {
            $_SESSION['cart'][$key]['qty'] = (int)$veg['stock'];
        }
    }
} else {
    if ($_SESSION['cart'][$key]['qty'] > (int)$veg['stock']) {
        $_SESSION['cart'][$key]['qty'] = (int)$veg['stock'];
    }
}

echo json_encode([
    'success'     => true,
    'message'     => $_SESSION['cart'][$key]['name'] . ' added to basket',
    'cart_count'  => cart_count(),
    'cart_total'  => number_format(cart_total(), 2),
]);
