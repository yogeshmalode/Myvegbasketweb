<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Orders';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

// Pull every order's line items in one query and group them by order_id,
// so the admin can see exactly which vegetables (and how much of each)
// were ordered, right in the orders table.
$itemsByOrder = [];
$itemRows = $pdo->query("SELECT order_id, name, quantity, subtotal FROM order_items ORDER BY id")->fetchAll();
foreach ($itemRows as $row) {
    $itemsByOrder[$row['order_id']][] = $row;
}

$paymentOptions = [
    'pending'                => 'Pending',
    'awaiting_verification'  => 'Awaiting verification',
    'paid'                   => 'Paid',
    'failed'                 => 'Failed',
];
$orderStatusOptions = [
    'pending'          => 'Pending',
    'placed'           => 'Placed',
    'processing'       => 'Processing',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];

// Inline colors so the dropdowns are always colored, even if the CSS
// file on the server hasn't been updated yet or is browser-cached.
$colorMap = [
    'pending'                => ['bg' => '#EDEDE6', 'fg' => '#5B6656'], // gray
    'placed'                 => ['bg' => '#E3EDFB', 'fg' => '#1F4E8C'], // blue
    'processing'             => ['bg' => '#FFF1BF', 'fg' => '#8A6D00'], // yellow
    'awaiting_verification'  => ['bg' => '#FFF1BF', 'fg' => '#8A6D00'], // yellow
    'out_for_delivery'       => ['bg' => '#FFE1C2', 'fg' => '#B25B00'], // orange
    'delivered'              => ['bg' => '#DCEEDB', 'fg' => '#1F4D36'], // green
    'paid'                   => ['bg' => '#DCEEDB', 'fg' => '#1F4D36'], // green
    'cancelled'              => ['bg' => '#FCE8E6', 'fg' => '#9A2E24'], // red
    'failed'                 => ['bg' => '#FCE8E6', 'fg' => '#9A2E24'], // red
];
function status_color_style($value, $colorMap) {
    $c = $colorMap[$value] ?? ['bg' => '#fff', 'fg' => '#26301F'];
    return "background:{$c['bg']}; color:{$c['fg']};";
}

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .orders-table th, .orders-table td{ vertical-align: top; }
  .orders-table tbody tr:hover{ background:#FAFBF6; }
  .orders-table td{ padding-top:16px; padding-bottom:16px; }
  .item-chip{
    display:inline-block;
    background:var(--leaf-light);
    color:var(--leaf-dark);
    padding:3px 10px;
    border-radius:999px;
    font-size:0.75rem;
    font-weight:600;
    margin:0 5px 5px 0;
    white-space:nowrap;
  }
  .order-total{ font-weight:700; color:var(--leaf-dark); font-size:0.95rem; }
  .order-address{ color:var(--ink-soft); line-height:1.5; }
</style>

<div class="section-head" style="text-align:left; margin-top:0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
  <h2 style="display:block; margin:0;">Customer Orders</h2>
  <div style="display:flex; gap:10px;">
    <a href="export_orders.php" class="btn" style="background:#fff; border:1px solid #E4E9DD;">⬇ Export Excel (CSV)</a>
    <a href="export_orders_pdf.php" class="btn" style="background:#fff; border:1px solid #E4E9DD;">⬇ Download PDF</a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<!-- Color map used by JS below, kept in sync with the PHP $colorMap above -->
<script>
const STATUS_COLORS = <?= json_encode(array_map(fn($c) => ['bg' => $c['bg'], 'fg' => $c['fg']], $colorMap)) ?>;
function applyStatusColor(select) {
  const c = STATUS_COLORS[select.value] || { bg: '#fff', fg: '#26301F' };
  select.style.background = c.bg;
  select.style.color = c.fg;
}
</script>

<div class="table-wrap">
  <table class="orders-table">
    <thead>
      <tr><th>#</th><th>Customer</th><th>Phone</th><th>Address</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Placed</th></tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><?= $o['id'] ?></td>
          <td><?= h($o['customer_name']) ?><br><small style="color:#5B6656;"><?= h($o['email']) ?></small></td>
          <td><?= h($o['phone']) ?></td>
          <td class="order-address" style="max-width:200px; white-space:normal; font-size:0.85rem;"><?= nl2br(h($o['address'])) ?></td>
          <td style="max-width:220px; white-space:normal;">
            <?php if (!empty($itemsByOrder[$o['id']])): ?>
              <?php foreach ($itemsByOrder[$o['id']] as $it): ?>
                <span class="item-chip"><?= h($it['name']) ?> &times; <?= (int)$it['quantity'] ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color:#5B6656;">—</span>
            <?php endif; ?>
          </td>
          <td class="order-total">₹<?= number_format($o['total_amount'],2) ?></td>
          <td>
            <select
              onchange="var v=this.value; if(!v) return; if(v==='paid' && !confirm('Mark order #<?= $o['id'] ?> as paid? Only do this after you have verified the UPI payment in your app.')){ this.value='<?= $o['payment_status'] ?>'; applyStatusColor(this); return; } applyStatusColor(this); window.location='update_order.php?id=<?= $o['id'] ?>&type=payment&value='+v;"
              style="font-weight:700; font-size:0.85rem; padding:6px 10px; border-radius:8px; border:1px solid #D9E0CD; cursor:pointer; <?= status_color_style($o['payment_status'], $colorMap) ?>">
              <?php foreach ($paymentOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $o['payment_status'] === $val ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <br><small style="color:#5B6656;"><?= h($o['payment_method'] ?? 'razorpay') ?></small>
          </td>
          <td>
            <select
              style="font-weight:700; font-size:0.85rem; padding:6px 10px; border-radius:8px; border:1px solid #D9E0CD; cursor:pointer; <?= status_color_style($o['order_status'], $colorMap) ?>"
              onchange="if(!this.value) return; applyStatusColor(this); window.location='update_order.php?id=<?= $o['id'] ?>&type=order&value='+this.value;">
              <?php foreach ($orderStatusOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $o['order_status'] === $val ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!in_array($o['order_status'], ['delivered', 'cancelled'])): ?>
              <br><a href="deliver.php?order_id=<?= $o['id'] ?>" style="font-size:0.78rem; color:#1F4E8C;">📍 Share location</a>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap; color:#5B6656; font-size:0.85rem;"><?= format_ist($o['created_at'], 'd M Y') ?><br><?= format_ist($o['created_at'], 'h:i A') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($orders)): ?>
        <tr><td colspan="9" style="text-align:center; color:#5B6656;">No orders placed yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
