<?php
// Updates the cancellation reason / refund status for an order shown in
// the admin Orders "Exceptions" tab. Does not change order_status itself
// (that still goes through update_order_status.php's transition rules).
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch (Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF token mismatch']); exit; }

$orderId = (int)($input['order_id'] ?? 0);
$reason = trim((string)($input['exception_reason'] ?? ''));
$refundStatus = trim((string)($input['refund_status'] ?? ''));
$refundAmount = $input['refund_amount'] ?? null;

if ($orderId <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid order']); exit; }

$allowedRefundStatuses = ['none', 'requested', 'processed'];
if (!in_array($refundStatus, $allowedRefundStatuses, true)) {
    echo json_encode(['success'=>false,'error'=>'Invalid refund status']);
    exit;
}

$refundAmount = ($refundAmount === null || $refundAmount === '') ? null : (float)$refundAmount;

try {
    $stmt = $pdo->prepare('UPDATE orders SET exception_reason = ?, refund_status = ?, refund_amount = ? WHERE id = ?');
    $stmt->execute([$reason !== '' ? $reason : null, $refundStatus, $refundAmount, $orderId]);
    echo json_encode(['success'=>true]);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>'Database update failed']);
}
