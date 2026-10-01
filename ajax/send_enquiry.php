<?php
// Receives the "Enquire Now" form submission. Direct server-side auto-send
// (CallMeBot) was removed — this uses the "login method" instead: it builds
// a wa.me click-to-chat URL and returns it so the browser opens WhatsApp
// (web/app) with the enquiry pre-filled, ready for the customer to hit send.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$name    = trim($_POST['name'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $phone === '' || $message === '') {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields.']);
    exit;
}

$lines = [
    "New enquiry on " . SITE_NAME,
    "",
    "Name: $name",
    "Phone: $phone",
    "Message: $message",
];

$whatsappUrl = 'https://wa.me/' . WHATSAPP_NUMBER . '?text=' . rawurlencode(implode("\n", $lines));

echo json_encode(['success' => true, 'whatsapp_url' => $whatsappUrl]);
