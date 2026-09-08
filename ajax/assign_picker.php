<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF token mismatch']); exit; }

$orderId = (int)($input['order_id'] ?? 0);
$pickerId = isset($input['picker_id']) && $input['picker_id'] !== '' ? (int)$input['picker_id'] : (isset($input['rider_id']) && $input['rider_id'] !== '' ? (int)$input['rider_id'] : null);

if ($orderId <= 0) {
    echo json_encode(['success'=>false,'error'=>'Invalid order']);
    exit;
}

$ord = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
$ord->execute([$orderId]);
if (!$ord->fetch()) {
    echo json_encode(['success'=>false,'error'=>'Order not found']);
    exit;
}

if ($pickerId !== null) {
    $admin = $pdo->prepare('SELECT id FROM admins WHERE id = ? AND role IN ("admin","staff")');
    $admin->execute([$pickerId]);
    if (!$admin->fetch()) {
        echo json_encode(['success'=>false,'error'=>'Selected picker is invalid']);
        exit;
    }
}

try {
    $st = $pdo->prepare('UPDATE orders SET assigned_picker_id = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$pickerId, $orderId]);
    echo json_encode(['success'=>true, 'picker_id' => $pickerId]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>'Database update failed: ' . $e->getMessage()]);
}
