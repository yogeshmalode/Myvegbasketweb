<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
if (!$orderId && !empty($input['order_id_raw'])){
    $raw = trim($input['order_id_raw']); if (preg_match('/ORD(\d+)/i',$raw,$m)) $orderId = (int)$m[1]; elseif (preg_match('/^(\d+)$/',$raw,$m)) $orderId = (int)$m[1];
}
if (!$orderId){ echo json_encode(['success'=>false,'error'=>'Bad order id']); exit; }
try{
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT id, order_status, manifest_id FROM orders WHERE id = ? FOR UPDATE');
    $st->execute([$orderId]); $ord = $st->fetch(); if(!$ord){ $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'Order not found']); exit; }
    if ($ord['order_status'] === 'delivered'){ $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>'Already delivered']); exit; }
    $upd = $pdo->prepare('UPDATE orders SET order_status = ?, updated_at = NOW(), delivered_at = NOW() WHERE id = ?');
    $upd->execute(['delivered', $orderId]);
    $ins = $pdo->prepare('INSERT INTO delivery_confirmations (order_id, manifest_id, rider_id, confirmed_by, method, note) VALUES (?, ?, ?, ?, ?, ?)');
    $ins->execute([$orderId, $ord['manifest_id'] ?: null, $_SESSION['admin_id'] ?? null, $_SESSION['admin_id'] ?? null, 'scan', $input['note'] ?? null]);
    $pdo->commit();
    // Notify admin
    send_order_alert("Order #$orderId delivered", ["Order #$orderId has been marked delivered via scan."]);
    echo json_encode(['success'=>true]);
} catch(Exception $e){ $pdo->rollBack(); echo json_encode(['success'=>false,'error'=>$e->getMessage()]); }
