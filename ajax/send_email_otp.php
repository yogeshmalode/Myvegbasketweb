<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$email = trim($_POST['email'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

// Same resend rate-limit as mobile OTP: no more than one email every 30
// seconds per browser session.
if (!empty($_SESSION['email_otp_last_sent']) && (time() - $_SESSION['email_otp_last_sent']) < 30) {
    $wait = 30 - (time() - $_SESSION['email_otp_last_sent']);
    echo json_encode(['success' => false, 'message' => "Please wait $wait seconds before requesting another code."]);
    exit;
}

// We generate and check the OTP ourselves this time (no third-party
// service tracking it for us), so it needs to be stored with an expiry.
$otp = (string)random_int(100000, 999999);

if (!send_email_otp($email, $otp)) {
    echo json_encode(['success' => false, 'message' => 'Could not send the email right now. Please try again in a moment.']);
    exit;
}

$_SESSION['email_otp_code']      = $otp;
$_SESSION['email_otp_email']     = $email;
$_SESSION['email_otp_expires']   = time() + 600; // 10 minutes
$_SESSION['email_otp_last_sent'] = time();
$_SESSION['email_otp_attempts']  = 0;

$stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
$stmt->execute([$email]);
$isNew = !$stmt->fetch();

echo json_encode(['success' => true, 'is_new' => $isNew]);
