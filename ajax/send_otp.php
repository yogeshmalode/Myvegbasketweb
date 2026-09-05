<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');

if (strlen($phone) !== 10) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid 10-digit mobile number.']);
    exit;
}

// Simple resend rate-limit: no more than one SMS every 30 seconds per
// browser session, so a person can't hammer the button (and run up your
// 2Factor SMS credits) by mashing "Send OTP".
if (!empty($_SESSION['otp_last_sent']) && (time() - $_SESSION['otp_last_sent']) < 30) {
    $wait = 30 - (time() - $_SESSION['otp_last_sent']);
    echo json_encode(['success' => false, 'message' => "Please wait $wait seconds before requesting another OTP."]);
    exit;
}

$sessionId = send_otp_sms($phone);

if (!$sessionId) {
    echo json_encode(['success' => false, 'message' => 'Could not send OTP right now. Please try again in a moment, or use password login.']);
    exit;
}

$_SESSION['otp_session_id'] = $sessionId;
$_SESSION['otp_phone']      = $phone;
$_SESSION['otp_last_sent']  = time();

$stmt = $pdo->prepare("SELECT id FROM customers WHERE phone = ?");
$stmt->execute([$phone]);
$isNew = !$stmt->fetch();

echo json_encode(['success' => true, 'is_new' => $isNew]);
