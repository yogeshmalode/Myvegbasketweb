<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$id = (int)($_POST['id'] ?? 0);
unset($_SESSION['cart'][$id]);

echo json_encode([
    'success'    => true,
    'cart_count' => cart_count(),
    'cart_total' => number_format(cart_total(), 2),
]);
