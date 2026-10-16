<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST requests only.']);
    exit;
}

if (!verify_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
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
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Notifications are temporarily unavailable. Please try again.']);
    exit;
}

$rawProductId = $_POST['product_id'] ?? '';
$vegetableId = is_scalar($rawProductId) ? (int)$rawProductId : 0;
$rawEmail = $_POST['email'] ?? '';
$email = is_string($rawEmail) ? strtolower(trim($rawEmail)) : '';
if ($vegetableId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

$productStmt = $pdo->prepare("SELECT id, name, stock, stock_mode FROM vegetables WHERE id = ? AND is_active = 1");
$productStmt->execute([$vegetableId]);
$product = $productStmt->fetch();
if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found.']);
    exit;
}

$stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
if ($stock > 0) {
    echo json_encode(['success' => false, 'message' => 'This product is already back in stock.']);
    exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO back_in_stock_notifications (vegetable_id, email)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE created_at = CURRENT_TIMESTAMP, notified_at = NULL");
    $stmt->execute([$vegetableId, $email]);
} catch (PDOException $e) {
    error_log('Could not save back-in-stock notification: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'We could not save your request. Please try again.']);
    exit;
}

echo json_encode(['success' => true, 'message' => "We'll email you when {$product['name']} is back in stock."]);
