<?php
require_once __DIR__ . '/config.php';

if (!is_customer_logged_in()) {
    redirect(BASE_URL . '/login.php?redirect=my_account.php');
}

$orderId = (int)($_GET['order_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND customer_id = ?");
$stmt->execute([$orderId, $_SESSION['customer_id']]);
$order = $stmt->fetch();

if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => "Order not found."];
    redirect(BASE_URL . '/my_account.php');
}

$itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$added = 0;
$skipped = [];

foreach ($items as $item) {
    // Always re-check against the live catalog — prices update daily and
    // stock/availability may have changed since this order was placed.
    $veg = null;
    if ($item['vegetable_id']) {
        $vegStmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? AND is_active = 1");
        $vegStmt->execute([$item['vegetable_id']]);
        $veg = $vegStmt->fetch();
    }

    if (!$veg || $veg['stock'] <= 0) {
        $skipped[] = $item['name'];
        continue;
    }

    // If this line was a specific size (e.g. "250 g"), try to reorder that
    // same size at today's price. If that size no longer exists, fall back
    // to the product's normal price/unit rather than skipping it entirely.
    $variant = null;
    if ($item['vegetable_variant_id']) {
        $vStmt = $pdo->prepare("SELECT * FROM vegetable_variants WHERE id = ? AND vegetable_id = ?");
        $vStmt->execute([$item['vegetable_variant_id'], $veg['id']]);
        $variant = $vStmt->fetch();
    }

    $qty = min($item['quantity'], $veg['stock']);
    $key = $variant ? $veg['id'] . '-' . $variant['id'] : (string)$veg['id'];

    if (isset($_SESSION['cart'][$key])) {
        $_SESSION['cart'][$key]['qty'] = min($_SESSION['cart'][$key]['qty'] + $qty, $veg['stock']);
    } else {
        $_SESSION['cart'][$key] = [
            'key'        => $key,
            'id'         => $veg['id'],
            'variant_id' => $variant['id'] ?? null,
            'name'       => $variant ? $veg['name'] . ' (' . $variant['label'] . ')' : $veg['name'],
            'price'      => $variant ? (float)$variant['price'] : get_effective_price($veg),
            'unit'       => $variant ? $variant['label'] : $veg['unit'],
            'qty'        => $qty,
        ];
    }
    $added++;
}

if ($added && !$skipped) {
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Added $added item(s) from order #$orderId to your basket at today's prices."];
} elseif ($added && $skipped) {
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Added $added item(s) to your basket. Not currently available: " . implode(', ', $skipped) . "."];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => "None of the items from that order are currently available."];
}

redirect(BASE_URL . '/cart.php');
