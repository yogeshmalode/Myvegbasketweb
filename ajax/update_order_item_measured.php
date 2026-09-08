<?php
require_once __DIR__ . '/../admin/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
$csrf = trim($input['csrf_token'] ?? '');
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$itemId = (int)($input['item_id'] ?? 0);
$measured = isset($input['measured_quantity']) && $input['measured_quantity'] !== '' ? (float)$input['measured_quantity'] : null;
if ($itemId <= 0) { echo json_encode(['success'=>false,'error'=>'Bad id']); exit; }
try{
    $st = $pdo->prepare('UPDATE order_items SET measured_quantity = ?, updated_at = NOW() WHERE id = ?');
    $st->execute([$measured, $itemId]);
    echo json_encode(['success'=>true]);
} catch(Exception $e){ echo json_encode(['success'=>false,'error'=>'DB']); }
