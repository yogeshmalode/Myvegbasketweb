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
    <?php if ($order['payment_status'] === 'awaiting_verification'): ?>
      <div style="font-size:3rem;">🕒</div>
      <h2 style="margin:16px 0 8px;">Order received!</h2>
      <p style="color:#5B6656;">Thank you, <?= h($order['customer_name']) ?>. We're verifying your UPI payment and will confirm your order shortly.</p>
    <?php else: ?>
      <div style="font-size:3rem;">✅</div>
      <h2 style="margin:16px 0 8px;">Order confirmed!</h2>
      <p style="color:#5B6656;">Thank you, <?= h($order['customer_name']) ?>. Your fresh vegetables are on the way.</p>
    <?php endif; ?>

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
    <p style="color:#5B6656; font-size:0.85rem; margin-top:6px;">
      Order #<?= $order['id'] ?>
      <?php if (!empty($order['razorpay_payment_id'])): ?>
        &middot; Payment ID: <?= h($order['razorpay_payment_id']) ?>
      <?php endif; ?>
      &middot; Status:
      <?php if ($order['payment_status'] === 'awaiting_verification'): ?>
        Payment pending verification
      <?php else: ?>
        <?= h(ucfirst($order['payment_status'])) ?>
      <?php endif; ?>
    </p>

    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:20px;">
      <a href="<?= BASE_URL ?>/track_order.php?order_id=<?= $order['id'] ?>" class="btn" style="background:#fff; border:1px solid #E4E9DD;">📍 Track your order</a>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Continue shopping</a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
