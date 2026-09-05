<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$otp   = trim($_POST['otp'] ?? '');
$name  = trim($_POST['name'] ?? '');
$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');

if (empty($_SESSION['email_otp_code']) || empty($_SESSION['email_otp_email'])) {
    echo json_encode(['success' => false, 'message' => 'Your code expired. Please request a new one.']);
    exit;
}

if (time() > ($_SESSION['email_otp_expires'] ?? 0)) {
    unset($_SESSION['email_otp_code'], $_SESSION['email_otp_email'], $_SESSION['email_otp_expires']);
    echo json_encode(['success' => false, 'message' => 'Your code expired. Please request a new one.']);
    exit;
}

// Limit guessing attempts per requested code.
$_SESSION['email_otp_attempts'] = ($_SESSION['email_otp_attempts'] ?? 0) + 1;
if ($_SESSION['email_otp_attempts'] > 6) {
    unset($_SESSION['email_otp_code'], $_SESSION['email_otp_email'], $_SESSION['email_otp_expires']);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Please request a new code.']);
    exit;
}

if ($otp === '' || !hash_equals($_SESSION['email_otp_code'], $otp)) {
    echo json_encode(['success' => false, 'message' => 'Incorrect code. Please try again.']);
    exit;
}

$email = $_SESSION['email_otp_email'];

$stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
$stmt->execute([$email]);
$customer = $stmt->fetch();

if ($customer) {
    $_SESSION['customer_id']   = $customer['id'];
    $_SESSION['customer_name'] = $customer['name'];
} else {
    // First time this email has logged in — create the account. Phone is
    // still required (it's how deliveries get coordinated), collected in
    // the same step on the front-end for new emails.
    if (strlen($phone) !== 10) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit mobile number to finish creating your account.']);
        exit;
    }

    $phoneCheck = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
    $phoneCheck->execute([$phone]);
    if ($phoneCheck->fetch()) {
        echo json_encode(['success' => false, 'message' => 'That phone number is already linked to another account. Try logging in with mobile OTP instead.']);
        exit;
    }

    $insert = $pdo->prepare("INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)");
    $insert->execute([$name !== '' ? $name : 'Customer', $email, $phone]);
    $_SESSION['customer_id']   = $pdo->lastInsertId();
    $_SESSION['customer_name'] = $name !== '' ? $name : 'Customer';
}

unset($_SESSION['email_otp_code'], $_SESSION['email_otp_email'], $_SESSION['email_otp_expires'], $_SESSION['email_otp_last_sent'], $_SESSION['email_otp_attempts']);

echo json_encode(['success' => true]);
