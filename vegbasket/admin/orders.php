<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Orders';

$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Customer Orders</h2>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr><th>#</th><th>Customer</th><th>Phone</th><th>Total</th><th>Payment</th><th>Status</th><th>Placed</th></tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= $o['id'] ?></td>
          <td><?= h($o['customer_name']) ?><br><small style="color:#5B6656;"><?= h($o['email']) ?></small></td>
          <td><?= h($o['phone']) ?></td>
          <td>₹<?= number_format($o['total_amount'],2) ?></td>
          <td>
            <?php if ($o['payment_status'] === 'paid'): ?>
              <span class="badge badge-green">Paid</span>
            <?php else: ?>
              <span class="badge badge-red"><?= h($o['payment_status']) ?></span>
            <?php endif; ?>
          </td>
          <td><?= h(ucfirst(str_replace('_',' ',$o['order_status']))) ?></td>
          <td><?= date('d M Y, h:i A', strtotime($o['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?>
        <tr><td colspan="7" style="text-align:center; color:#5B6656;">No orders placed yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
