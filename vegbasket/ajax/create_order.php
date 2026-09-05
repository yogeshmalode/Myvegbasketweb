<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Your cart is empty.']);
    exit;
}

// Recalculate total on the server — never trust a client-sent amount.
$total = cart_total();
$amountPaise = (int) round($total * 100); // Razorpay expects the smallest currency unit

$receipt = 'vb_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);

$payload = json_encode([
    'amount'   => $amountPaise,
    'currency' => 'INR',
    'receipt'  => $receipt,
    'payment_capture' => 1,
]);

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_USERPWD        => RAZORPAY_KEY_ID . ':' . RAZORPAY_KEY_SECRET,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $httpCode >= 400) {
    echo json_encode([
        'success' => false,
        'message' => 'Razorpay order creation failed. Check your API keys in config.php. ' .
                     ($curlErr ?: "HTTP $httpCode: $response"),
    ]);
    exit;
}

$order = json_decode($response, true);

// Remember this order id in the session so verify_payment.php can
// double-check the payment belongs to this cart/session.
$_SESSION['pending_razorpay_order_id'] = $order['id'];

echo json_encode([
    'success'           => true,
    'amount'            => $amountPaise,
    'currency'          => 'INR',
    'razorpay_order_id' => $order['id'],
]);
