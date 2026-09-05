<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$id  = (int)($_POST['id'] ?? 0);
$qty = (int)($_POST['qty'] ?? 1);

if (!isset($_SESSION['cart'][$id])) {
    echo json_encode(['success' => false, 'message' => 'Item not in cart.']);
    exit;
}

if ($qty <= 0) {
    unset($_SESSION['cart'][$id]);
} else {
    // clamp to available stock
    $stmt = $pdo->prepare("SELECT stock FROM vegetables WHERE id = ?");
    $stmt->execute([$id]);
    $stock = (int)($stmt->fetchColumn() ?: $qty);
    $_SESSION['cart'][$id]['qty'] = min($qty, $stock);
}

echo json_encode([
    'success'    => true,
    'cart_count' => cart_count(),
    'cart_total' => number_format(cart_total(), 2),
]);
