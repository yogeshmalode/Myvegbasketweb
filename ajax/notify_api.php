<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

function notify_api_response($status, $payload) {
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    notify_api_response(405, ['success' => false, 'message' => 'POST requests only.']);
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    notify_api_response(419, ['success' => false, 'message' => 'Please refresh the page and try again.']);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS back_in_stock_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        vegetable_id INT NOT NULL,
        email VARCHAR(254) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_at TIMESTAMP NULL DEFAULT NULL,
        UNIQUE KEY uniq_back_in_stock_email (vegetable_id, email),
        KEY idx_back_in_stock_pending (notified_at, vegetable_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('Could not initialize back-in-stock notifications: ' . $e->getMessage());
    notify_api_response(500, ['success' => false, 'message' => 'Notifications are temporarily unavailable. Please try again.']);
}

$productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
if (!$productId || $productId < 1) {
    notify_api_response(422, ['success' => false, 'message' => 'Invalid product.']);
}

if (is_customer_logged_in()) {
    $customer = current_customer();
    $email = strtolower(trim((string)($customer['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        notify_api_response(422, ['success' => false, 'message' => 'Your account does not have a valid email address. Update your account email and try again.']);
    }
} else {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        notify_api_response(422, ['success' => false, 'error' => 'invalid_email', 'message' => 'Please enter a valid email address.']);
    }
}

try {
    $productStmt = $pdo->prepare("SELECT id, name, stock, stock_mode FROM vegetables WHERE id = ? AND is_active = 1");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product) {
        notify_api_response(404, ['success' => false, 'message' => 'Product not found.']);
    }

    $stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
    if ($stock > 0) {
        notify_api_response(409, ['success' => false, 'message' => 'This product is already back in stock.']);
    }

    $duplicateStmt = $pdo->prepare("SELECT id FROM back_in_stock_notifications WHERE vegetable_id = ? AND email = ? LIMIT 1");
    $duplicateStmt->execute([$productId, $email]);
    if ($duplicateStmt->fetchColumn()) {
        notify_api_response(409, [
            'success' => false,
            'error' => 'duplicate',
            'message' => 'You are already subscribed to this product\'s notification.',
        ]);
    }

    $insert = $pdo->prepare("INSERT INTO back_in_stock_notifications (vegetable_id, email) VALUES (?, ?)");
    $insert->execute([$productId, $email]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000' || (int)($e->errorInfo[1] ?? 0) === 1062) {
        notify_api_response(409, [
            'success' => false,
            'error' => 'duplicate',
            'message' => 'You are already subscribed to this product\'s notification.',
        ]);
    }
    error_log('Could not save back-in-stock notification: ' . $e->getMessage());
    notify_api_response(500, ['success' => false, 'message' => 'We could not save your request. Please try again.']);
}

$confirmationSent = false;
try {
    $confirmationSent = send_notification_confirmation($email, $product['name']);
} catch (Throwable $e) {
    error_log('Back-in-stock subscription confirmation failed: ' . $e->getMessage());
}
if (!$confirmationSent) {
    error_log('Could not send subscription confirmation for back-in-stock notification ' . (int)$pdo->lastInsertId());
}

notify_api_response(200, [
    'success' => true,
    'confirmation_sent' => $confirmationSent,
    'message' => 'Thank you! We will notify you via email when this product is back in stock.',
]);
