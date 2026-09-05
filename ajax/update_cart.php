<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

// Cart keys are either a plain vegetable id ("7") or a variant-specific
// key ("7-3" = vegetable 7, variant 3), so we can't just cast to int here.
$key = preg_replace('/[^0-9\-]/', '', $_POST['id'] ?? '');
$qty = (int)($_POST['qty'] ?? 1);

if ($key === '' || !isset($_SESSION['cart'][$key])) {
    echo json_encode(['success' => false, 'message' => 'Item not in cart.']);
    exit;
}

if ($qty <= 0) {
    unset($_SESSION['cart'][$key]);
} else {
    // clamp to available stock (tracked at the base product level)
    $vegId = $_SESSION['cart'][$key]['id'];
    $stmt = $pdo->prepare("SELECT stock FROM vegetables WHERE id = ?");
    $stmt->execute([$vegId]);
    $stock = (int)($stmt->fetchColumn() ?: $qty);
    $_SESSION['cart'][$key]['qty'] = min($qty, $stock);
}

echo json_encode([
    'success'    => true,
    'cart_count' => cart_count(),
    'cart_total' => number_format(cart_total(), 2),
]);
