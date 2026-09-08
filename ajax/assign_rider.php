<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF token mismatch']); exit; }

$orderId = (int)($input['order_id'] ?? 0);
$riderId = isset($input['rider_id']) && $input['rider_id'] !== '' ? (int)$input['rider_id'] : null;
if ($orderId <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid order']); exit; }

$ord = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
$ord->execute([$orderId]);
if (!$ord->fetch()) { echo json_encode(['success'=>false,'error'=>'Order not found']); exit; }

if ($riderId !== null) {
    $rider = $pdo->prepare('SELECT id FROM riders WHERE id = ? AND is_active = 1');
    $rider->execute([$riderId]);
    if (!$rider->fetch()) {
        echo json_encode(['success'=>false,'error'=>'Selected rider is invalid or inactive']);
        exit;
    }
}

try {
    $st = $pdo->prepare('UPDATE orders SET rider_id = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$riderId, $orderId]);
    echo json_encode(['success'=>true, 'rider_id' => $riderId]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>'Database update failed: ' . $e->getMessage()]);
}
