<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success'=>false,'error'=>'POST only']); exit; }
try { require_once __DIR__ . '/../admin/includes/auth.php'; } catch(Exception $e) {}
$csrf = $_POST['csrf_token'] ?? '';
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { echo json_encode(['success'=>false,'error'=>'Invalid id']); exit; }
$fields = ['customer_name','customer_phone','product_id','variant_id','quantity','frequency','next_delivery_date','active','notes'];
$updates = [];
$params = [];
foreach($fields as $f){ if(isset($_POST[$f])){ $updates[] = "$f = ?"; $params[] = $_POST[$f] === '' ? null : $_POST[$f]; }}
if(empty($updates)){ echo json_encode(['success'=>false,'error'=>'Nothing to update']); exit; }
$params[] = $id;
$sql = "UPDATE subscriptions SET " . implode(', ', $updates) . " WHERE id = ?";
$st = $pdo->prepare($sql);
try{ $st->execute($params); echo json_encode(['success'=>true]); } catch(Exception $e){ echo json_encode(['success'=>false,'error'=>'DB: '.$e->getMessage()]); }
