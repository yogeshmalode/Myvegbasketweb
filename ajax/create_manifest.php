<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$orderIds = array_filter(array_map('intval', explode(',', ($input['orders'] ?? ''))));
$riderId = isset($input['rider_id']) && $input['rider_id'] !== '' ? (int)$input['rider_id'] : null;
$totalKm = isset($input['total_km']) ? (float)$input['total_km'] : 0.0; // optional
if (empty($orderIds)) { echo json_encode(['success'=>false,'error'=>'No orders']); exit; }
try {
    $pdo->beginTransaction();
    $ins = $pdo->prepare('INSERT INTO delivery_manifests (rider_id, created_by, total_orders, total_km) VALUES (?, ?, ?, ?)');
    $ins->execute([$riderId, $_SESSION['admin_id'] ?? null, count($orderIds), $input['total_km'] ?? 0.0]);
    $mid = $pdo->lastInsertId();
    $seq = 1;
    $up = $pdo->prepare('UPDATE orders SET manifest_id = ?, rider_id = ? WHERE id = ?');
    $itIns = $pdo->prepare('INSERT INTO delivery_manifest_items (manifest_id, order_id, seq) VALUES (?, ?, ?)');
    foreach ($orderIds as $oid) {
        $itIns->execute([$mid, $oid, $seq]);
        $up->execute([$mid, $riderId, $oid]);
        $seq++;
    }
    $pdo->commit();
    echo json_encode(['success'=>true,'manifest_id'=>$mid]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
