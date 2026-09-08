<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid request']); exit; }

$id = (int)($input['id'] ?? 0);
$price = isset($input['price']) && $input['price'] !== '' ? (float)$input['price'] : null;
$sale = isset($input['sale_price']) && $input['sale_price'] !== '' ? (float)$input['sale_price'] : null;
$stock = isset($input['stock']) && $input['stock'] !== '' ? (int)$input['stock'] : null;
$supplier = isset($input['supplier_name']) ? trim($input['supplier_name']) : null;
$csrf = trim($input['csrf_token'] ?? '');

try {
    require_csrf($csrf);
} catch (Exception $e) {
    echo json_encode(['success'=>false,'error'=>'CSRF failure']); exit;
}

if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid product id']); exit; }

// Fetch current product
$stmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ?");
$stmt->execute([$id]);
$veg = $stmt->fetch();
if (!$veg) { echo json_encode(['success'=>false,'error'=>'Product not found']); exit; }

// Validation: don't accept zero/negative price
if ($price !== null && $price <= 0) { echo json_encode(['success'=>false,'error'=>'Price must be > 0']); exit; }
if ($sale !== null && $sale >= $price) $sale = null;

$update = $pdo->prepare("UPDATE vegetables SET price = COALESCE(?, price), sale_price = COALESCE(?, sale_price), stock = COALESCE(?, stock), supplier_name = COALESCE(?, supplier_name) WHERE id = ?");
$pdo->beginTransaction();
try {
    $update->execute([$price, $sale, $stock, $supplier ?: null, $id]);

    // If price changed, rescale variants for this product
    if ($price !== null) {
        $result = recalculate_variant_prices($pdo, $id, $price, $veg['unit']);
        $msg = 'Saved';
        if (!empty($result['updated'])) $msg .= ", rescaled {$result['updated']} variant(s)";
    } else {
        $msg = 'Saved';
    }

    $pdo->commit();
    echo json_encode(['success'=>true,'message'=>$msg]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'DB error: '. $e->getMessage()]);
}
