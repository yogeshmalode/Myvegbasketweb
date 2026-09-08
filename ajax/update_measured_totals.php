<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$orderIdsRaw = $input['orders'] ?? '';
$orderIds = array_filter(array_map('intval', explode(',', $orderIdsRaw)));
if (empty($orderIds)) { echo json_encode(['success'=>false,'error'=>'No orders']); exit; }
$payload = $input['payload'] ?? [];
try {
    $pdo->beginTransaction();
    // For each payload entry, find matching order_items rows by vegetable_id and variant_label across selected orders,
    // then distribute measured_total proportionally across those rows by quantity.
    foreach ($payload as $p) {
        $veg = (int)$p['vegetable_id'];
        $variant_label = trim($p['variant_label']);
        $measTotal = (float)$p['measured_total'];
        if ($veg <= 0) continue;

        // Fetch order_items matching criteria
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $sql = "SELECT id, quantity FROM order_items WHERE vegetable_id = ? AND COALESCE(variant_label,'') = ? AND order_id IN ($placeholders)";
        $params = array_merge([$veg, $variant_label], $orderIds);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (empty($rows)) continue;

        $totalQty = array_sum(array_column($rows, 'quantity'));
        if ($totalQty <= 0) continue;

        // Distribute measured_total proportionally
        foreach ($rows as $r) {
            $portion = $r['quantity'] / $totalQty;
            $measuredForRow = round($measTotal * $portion, 3);
            $upd = $pdo->prepare('UPDATE order_items SET measured_quantity = ? WHERE id = ?');
            $upd->execute([$measuredForRow, $r['id']]);
        }
    }
    $pdo->commit();
    echo json_encode(['success'=>true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
