<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$scope = $_GET['scope'] ?? '';
$orders = [];

if ($scope === 'admin') {
    if (!is_admin_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Admin login required.']);
        exit;
    }

    $page = $_GET['page'] ?? '';
    $liveOrderPages = ['orders.php', 'deliveries.php', 'dashboard.php'];
    if (!in_array($page, $liveOrderPages, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid order page.']);
        exit;
    }

    if (admin_role() !== 'admin' && $page !== 'dashboard.php') {
        $allowedPages = get_role_allowed_pages($pdo, admin_role());
        if (!is_array($allowedPages) || !in_array($page, $allowedPages, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You cannot view this page.']);
            exit;
        }
    }

    $storeId = session_store_id();
    if ($page === 'deliveries.php' || $storeId === null) {
        $stmt = $pdo->query('SELECT id, order_status, payment_status, rider_id, rider_accepted_at, assigned_picker_id, total_amount FROM orders');
    } else {
        $stmt = $pdo->prepare('SELECT id, order_status, payment_status, rider_id, rider_accepted_at, assigned_picker_id, total_amount FROM orders WHERE dark_store_id = ? OR dark_store_id IS NULL');
        $stmt->execute([$storeId]);
    }
    $orders = $stmt->fetchAll();
} elseif ($scope === 'customer') {
    if (!is_customer_logged_in()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Customer login required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, order_status, payment_status, rider_id, rider_accepted_at, assigned_picker_id, total_amount FROM orders WHERE customer_id = ?');
    $stmt->execute([(int)$_SESSION['customer_id']]);
    $orders = $stmt->fetchAll();
} elseif ($scope === 'rider') {
    if (!is_rider_logged_in() || !current_rider()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Rider login required.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id, order_status, payment_status, rider_id, rider_accepted_at, assigned_picker_id, total_amount
        FROM orders
        WHERE rider_id = ?
          AND order_status IN ('delivery_partner_assigned', 'out_for_delivery', 'arriving_soon')
    ");
    $stmt->execute([(int)$_SESSION['rider_id']]);
    $orders = $stmt->fetchAll();
} elseif ($scope === 'single') {
    $orderId = (int)($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid order ID is required.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, customer_id, order_status, payment_status, rider_id, rider_accepted_at, assigned_picker_id, total_amount FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $customerOwnsOrder = is_customer_logged_in()
        && $order['customer_id']
        && (int)$order['customer_id'] === (int)$_SESSION['customer_id'];
    $guestOrderAccess = $_SESSION['guest_order_access'] ?? [];
    $guestOwnsOrder = is_array($guestOrderAccess)
        && in_array($orderId, array_map('intval', $guestOrderAccess), true);
    if (!$customerOwnsOrder && !$guestOwnsOrder) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You cannot view this order.']);
        exit;
    }
    $orders = [$order];
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order update scope.']);
    exit;
}

echo json_encode([
    'success' => true,
    'version' => get_order_refresh_version($orders),
]);
