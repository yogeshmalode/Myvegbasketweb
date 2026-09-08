<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if (!$product_id) { echo json_encode(['success'=>false,'variants'=>[]]); exit; }
$st = $pdo->prepare('SELECT id, label, price FROM vegetable_variants WHERE vegetable_id = ? ORDER BY sort_order, id');
$st->execute([$product_id]);
$variants = $st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['success'=>true,'variants'=>$variants]);
