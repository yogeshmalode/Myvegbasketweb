<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$key = preg_replace('/[^0-9\-]/', '', $_POST['id'] ?? '');
unset($_SESSION['cart'][$key]);

echo json_encode([
    'success'    => true,
    'cart_count' => cart_count(),
    'cart_total' => number_format(cart_total(), 2),
]);
