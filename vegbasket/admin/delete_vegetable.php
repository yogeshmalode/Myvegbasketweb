<?php
require_once __DIR__ . '/includes/auth.php';

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT name FROM vegetables WHERE id = ?");
$stmt->execute([$id]);
$name = $stmt->fetchColumn();

if ($name) {
    $del = $pdo->prepare("DELETE FROM vegetables WHERE id = ?");
    $del->execute([$id]);
    $_SESSION['flash'] = ['type' => 'success', 'message' => "$name deleted."];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vegetable not found.'];
}

redirect('dashboard.php');
