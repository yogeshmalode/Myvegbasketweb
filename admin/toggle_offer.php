<?php
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT title, is_active FROM offers WHERE id = ?");
$stmt->execute([$id]);
$offer = $stmt->fetch();

if ($offer) {
    $newStatus = $offer['is_active'] ? 0 : 1;
    $upd = $pdo->prepare("UPDATE offers SET is_active = ? WHERE id = ?");
    $upd->execute([$newStatus, $id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "\"{$offer['title']}\" " . ($newStatus ? 'enabled' : 'disabled') . "."];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Offer not found.'];
}

redirect('offers.php');
