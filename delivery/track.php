<?php
require_once __DIR__ . '/../config.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$trackingUrl = '../track_order.php' . ($orderId > 0 ? '?order_id=' . $orderId : '');
header('Location: ' . $trackingUrl, true, 302);
exit;
