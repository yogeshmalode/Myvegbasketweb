<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
try { require_once __DIR__ . '/../admin/includes/auth.php'; } catch(Exception $e) {}
$csrf = $input['csrf_token'] ?? '';
try { require_csrf($csrf); } catch(Exception $e) { echo json_encode(['success'=>false,'error'=>'CSRF']); exit; }
$id = (int)($input['id'] ?? 0);
if ($id<=0) { echo json_encode(['success'=>false,'error'=>'Bad id']); exit; }
$st = $pdo->prepare('SELECT active FROM subscriptions WHERE id = ?'); $st->execute([$id]); $cur = $st->fetchColumn(); if($cur===false){ echo json_encode(['success'=>false,'error'=>'Not found']); exit; }
$new = $cur ? 0 : 1; $upd = $pdo->prepare('UPDATE subscriptions SET active = ?, updated_at = NOW() WHERE id = ?'); try{ $upd->execute([$new,$id]); echo json_encode(['success'=>true,'active'=>$new]); } catch(Exception $e){ echo json_encode(['success'=>false,'error'=>'DB: '.$e->getMessage()]); }
