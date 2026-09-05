<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$idToken = trim($_POST['id_token'] ?? '');
$name    = trim($_POST['name'] ?? '');

if ($idToken === '') {
    echo json_encode(['success' => false, 'message' => 'Missing verification token.']);
    exit;
}

$payload = verify_firebase_id_token($idToken);

if (!$payload) {
    echo json_encode(['success' => false, 'message' => 'Could not verify this login with Firebase. Please try again.']);
    exit;
}

// Firebase returns phone numbers in E.164 format, e.g. "+919876543210".
// Strip the +91 country code to match how phone numbers are stored
// everywhere else in the app (plain 10-digit).
$rawPhone = $payload['phone_number'];
$phone = preg_replace('/^\+?91/', '', $rawPhone);
$phone = preg_replace('/\D/', '', $phone);

if (strlen($phone) !== 10) {
    echo json_encode(['success' => false, 'message' => 'Unexpected phone number format from Firebase.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM customers WHERE phone = ?");
$stmt->execute([$phone]);
$customer = $stmt->fetch();

if ($customer) {
    $_SESSION['customer_id']   = $customer['id'];
    $_SESSION['customer_name'] = $customer['name'];
    echo json_encode(['success' => true]);
    exit;
}

// New number — need a name to create the account. If the front-end hasn't
// collected one yet, ask for it without making them redo the OTP step.
if ($name === '') {
    echo json_encode(['success' => false, 'need_name' => true, 'message' => 'This is a new number — please enter your name to finish creating your account.']);
    exit;
}

$insert = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
$insert->execute([$name, $phone]);
$_SESSION['customer_id']   = $pdo->lastInsertId();
$_SESSION['customer_name'] = $name;

echo json_encode(['success' => true]);
