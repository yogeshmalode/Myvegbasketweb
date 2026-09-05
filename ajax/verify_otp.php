<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$otp  = trim($_POST['otp'] ?? '');
$name = trim($_POST['name'] ?? '');

if (empty($_SESSION['otp_session_id']) || empty($_SESSION['otp_phone'])) {
    echo json_encode(['success' => false, 'message' => 'Your OTP session expired. Please request a new one.']);
    exit;
}

if ($otp === '') {
    echo json_encode(['success' => false, 'message' => 'Enter the OTP you received.']);
    exit;
}

if (!verify_otp_sms($_SESSION['otp_session_id'], $otp)) {
    echo json_encode(['success' => false, 'message' => 'Incorrect or expired OTP. Please try again.']);
    exit;
}

$phone = $_SESSION['otp_phone'];

$stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
$stmt->execute([$phone]);
$customer = $stmt->fetch();

if ($customer) {
    $_SESSION['customer_id']   = $customer['id'];
    $_SESSION['customer_name'] = $customer['name'];
} else {
    // First time this phone has logged in — create the account. Name is
    // collected in the same step on the front-end for new numbers.
    $insert = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
    $insert->execute([$name !== '' ? $name : 'Customer', $phone]);
    $_SESSION['customer_id']   = $pdo->lastInsertId();
    $_SESSION['customer_name'] = $name !== '' ? $name : 'Customer';
}

// Clean up the OTP session markers now that login is complete.
unset($_SESSION['otp_session_id'], $_SESSION['otp_phone'], $_SESSION['otp_last_sent']);

echo json_encode(['success' => true]);
