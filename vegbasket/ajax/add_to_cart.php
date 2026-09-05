<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$id    = (int)($_POST['id'] ?? 0);
$qty   = max(1, (int)($_POST['qty'] ?? 1));

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

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

if (isset($_SESSION['cart'][$id])) {
    $_SESSION['cart'][$id]['qty'] += $qty;
} else {
    $_SESSION['cart'][$id] = [
        'id'    => $veg['id'],
        'name'  => $veg['name'],
        'price' => (float)$veg['price'],
        'unit'  => $veg['unit'],
        'qty'   => $qty,
    ];
}

// Don't let cart quantity exceed available stock
if ($_SESSION['cart'][$id]['qty'] > $veg['stock']) {
    $_SESSION['cart'][$id]['qty'] = (int)$veg['stock'];
}

echo json_encode([
    'success'     => true,
    'message'     => $veg['name'] . ' added to basket',
    'cart_count'  => cart_count(),
    'cart_total'  => number_format(cart_total(), 2),
]);
