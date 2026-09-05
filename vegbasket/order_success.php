<?php
require_once __DIR__ . '/config.php';
$page_title = 'Order Confirmed';

$orderId = (int)($_GET['order_id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    redirect(BASE_URL . '/index.php');
}

$itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
$itemStmt->execute([$orderId]);
$items = $itemStmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:60px 24px; max-width:640px; text-align:center;">
  <div class="form-card">
    <div style="font-size:3rem;">✅</div>
    <h2 style="margin:16px 0 8px;">Order confirmed!</h2>
    <p style="color:#5B6656;">Thank you, <?= h($order['customer_name']) ?>. Your fresh vegetables are on the way.</p>

    <div class="table-wrap" style="margin:24px 0; text-align:left;">
      <table>
        <thead><tr><th>Item</th><th>Qty</th><th>Subtotal</th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td><?= veg_emoji($item['name']) ?> <?= h($item['name']) ?></td>
              <td><?= (int)$item['quantity'] ?></td>
              <td><?= SITE_CURRENCY ?><?= number_format($item['subtotal'],2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="cart-total-row" style="justify-content:center; gap:10px;">
      <span>Total paid:</span>
      <span><?= SITE_CURRENCY ?><?= number_format($order['total_amount'],2) ?></span>
    </div>
    <p style="color:#5B6656; font-size:0.85rem; margin-top:6px;">Order #<?= $order['id'] ?> &middot; Payment ID: <?= h($order['razorpay_payment_id']) ?></p>

    <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="margin-top:20px;">Continue shopping</a>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
