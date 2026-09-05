<?php
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT title FROM offers WHERE id = ?");
$stmt->execute([$id]);
$title = $stmt->fetchColumn();

if ($title) {
    $del = $pdo->prepare("DELETE FROM offers WHERE id = ?");
    $del->execute([$id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "\"$title\" deleted."];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Offer not found.'];
}

redirect('offers.php');
