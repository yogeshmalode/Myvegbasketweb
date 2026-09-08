<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST only']); exit; }
try { require_once __DIR__ . '/../admin/includes/auth.php'; } catch(Exception $e) {}

$csrf = $_POST['csrf_token'] ?? '';
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }

$customer_name = trim($_POST['customer_name'] ?? '');
$customer_phone = trim($_POST['customer_phone'] ?? '');
$product_id = (int)($_POST['product_id'] ?? 0);
$variant_id = isset($_POST['variant_id']) && $_POST['variant_id'] !== '' ? (int)$_POST['variant_id'] : null;
$quantity = isset($_POST['quantity']) ? (float)$_POST['quantity'] : 1.0;
$frequency = $_POST['frequency'] ?? 'daily';
$next = $_POST['next_delivery_date'] ?: null;

if ($customer_name === '' || $product_id <= 0) { echo json_encode(['success'=>false,'error'=>'Missing fields']); exit; }
if (!in_array($frequency, ['daily','alternate','weekly'])) $frequency = 'daily';
$interval_days = $frequency === 'daily' ? 1 : ($frequency === 'alternate' ? 2 : 7);

// Validate product exists
$st = $pdo->prepare('SELECT id, unit FROM vegetables WHERE id = ?'); $st->execute([$product_id]); $p = $st->fetch();
if (!$p) { echo json_encode(['success'=>false,'error'=>'Product not found']); exit; }

// If variant_id provided, validate it
if ($variant_id) { $vst = $pdo->prepare('SELECT id FROM vegetable_variants WHERE id = ? AND vegetable_id = ?'); $vst->execute([$variant_id, $product_id]); if (!$vst->fetch()) $variant_id = null; }

$ins = $pdo->prepare('INSERT INTO subscriptions (customer_id, customer_name, customer_phone, product_id, variant_id, quantity, unit, frequency, interval_days, next_delivery_date, active, notes) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NULL)');
try {
  $ins->execute([$customer_name, $customer_phone ?: null, $product_id, $variant_id, $quantity, $p['unit'], $frequency, $interval_days, $next]);
  echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]);
} catch (Exception $e) {
  echo json_encode(['success'=>false,'error'=>'DB: ' . $e->getMessage()]);
}
