<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$id = (int)($input['id'] ?? 0);
$status = normalize_order_status($input['status'] ?? '');
$allowed = array_keys(get_order_status_options());
if ($id<=0 || !in_array($status, $allowed, true)) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
try {
    $st = $pdo->prepare('UPDATE orders SET order_status = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$status, $id]);
    // Alert admin for important transitions.
    if (in_array($status, ['processing', 'out_for_delivery'], true)) {
        send_order_alert("Order #$id status: $status", ["Order #$id changed to $status by admin."]);
    }
    echo json_encode(['success'=>true,'status'=>$status,'status_display'=>get_order_status_options()[$status] ?? ucfirst(str_replace('_',' ',$status))]);
} catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'DB']); }
