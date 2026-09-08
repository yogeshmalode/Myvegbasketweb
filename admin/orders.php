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
$itemRows = $pdo->query("SELECT oi.id, oi.order_id, oi.name, oi.quantity, oi.subtotal, oi.variant_label, oi.measured_quantity, v.unit AS item_unit FROM order_items oi LEFT JOIN vegetables v ON v.id = oi.vegetable_id ORDER BY oi.id")->fetchAll();
foreach ($itemRows as $row) {
    $itemsByOrder[$row['order_id']][] = $row;
}

function item_requires_measurement($item) {
    $unit = strtolower(trim((string)($item['item_unit'] ?? '')));
    if (in_array($unit, ['kg','gram','grams','g','litre','litres','liter','liters','l','ml'], true)) {
        return true;
    }

    $variant = strtolower(trim((string)($item['variant_label'] ?? '')));
    if ($variant === '') {
        return false;
    }

    return preg_match('/\b(kg|g|gram|grams|litre|liters|liter|l|ml)\b/i', $variant) === 1;
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
  .orders-page-shell {
    background: transparent;
  }
  .orders-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    margin: 0 0 18px;
  }
  .orders-page-header h2 {
    margin: 0;
    color: #0c5a42;
    font-size: clamp(1.5rem, 2vw, 2.2rem);
    line-height: 1.2;
    letter-spacing: -0.03em;
    font-weight: 800;
  }
  .orders-page-header h2::after {
    content: "";
    display: block;
    width: 100%;
    max-width: 120px;
    height: 3px;
    border-radius: 999px;
    background: linear-gradient(90deg, #f0a26f 0%, #f0a26f 100%);
    margin-top: 8px;
    opacity: 0.9;
  }
  .orders-page-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
  }
  .orders-page-actions .btn {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 999px;
    border: 1px solid #dfe9df;
    background: rgba(255,255,255,0.85);
    color: #1f2e2a;
    font-weight: 700;
    font-size: 0.76rem;
    box-shadow: 0 1px 0 rgba(18, 41, 34, 0.04);
  }
  .orders-page-actions .btn:hover {
    background: #f6faf7;
    border-color: #cfe0ce;
  }
  .orders-card {
    background: #fff;
    border: 1px solid #dfe8df;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05);
  }
  .table-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 20px;
    border: 1px solid #dfe8df;
    box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05);
  }
  .orders-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
  }
  .orders-table thead th {
    background: #dfe9dc;
    color: #1b342d;
    font-size: 0.7rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    font-weight: 800;
    padding: 12px 12px;
    border-bottom: 1px solid #cfe1ce;
    text-align: left;
    vertical-align: middle;
  }
  .orders-table tbody td {
    padding: 12px 12px;
    vertical-align: top;
    border-bottom: 1px solid #edf1ed;
    color: #1e2c28;
    font-size: 0.8rem;
    line-height: 1.45;
  }
  .orders-table tbody tr:hover {
    background: #fafdf9;
  }
  .orders-table tbody tr:last-child td {
    border-bottom: none;
  }
  .order-id {
    font-weight: 800;
    color: #1c2d2b;
  }
  .customer-name {
    font-weight: 700;
    color: #1c2d2b;
  }
  .customer-email {
    color: #5d6e65;
    font-size: 0.72rem;
    margin-top: 4px;
  }
  .order-address {
    color: #596760;
    line-height: 1.45;
    max-width: 220px;
    white-space: normal;
  }
  .item-chip {
    display: inline-block;
    background: #edf5eb;
    color: #1c5f46;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 700;
    margin: 0 6px 6px 0;
    white-space: nowrap;
  }
  .measured-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 8px;
  }
  .measured-wrap input {
    width: 90px;
    min-height: 32px;
    border: 1px solid #d4ddd6;
    border-radius: 8px;
    padding: 6px 8px;
    background: #fff;
    color: #1c2d2b;
  }
  .measured-wrap .btn {
    min-height: 32px;
    padding: 0 12px;
    border-radius: 999px;
    border: 1px solid #d7e6db;
    background: #eef5f1;
    color: #1c4e3d;
    font-size: 0.76rem;
    font-weight: 700;
  }
  .order-total {
    font-weight: 800;
    color: #1a4e3c;
    font-size: 0.96rem;
  }
  .payment-select,
  .status-select {
    min-width: 150px;
    min-height: 40px;
    padding: 8px 12px;
    border-radius: 10px;
    border: 1px solid #d5dfd7;
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
    background: #f1f5f2;
    color: #1d2d2a;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.4);
  }
  .status-select {
    min-width: 170px;
  }
  .share-link {
    display: inline-block;
    margin-top: 10px;
    color: #1e5f9d;
    font-weight: 700;
    font-size: 0.8rem;
  }
  .share-link:hover { text-decoration: underline; }
  .orders-table small {
    color: #65736a;
  }
  @media (max-width: 900px) {
    .orders-page-header { align-items: flex-start; }
    .orders-page-header h2 {
      font-size: 2.3rem;
    }
    .orders-page-header h2::after {
      width: 72%;
    }
  }
</style>

<div class="orders-page-shell">
  <div class="orders-page-header">
    <h2>Customer Orders</h2>
    <div class="orders-page-actions">
      <a href="export_orders.php" class="btn">⬇ Export Excel (CSV)</a>
      <a href="export_orders_pdf.php" class="btn">⬇ Download PDF</a>
    </div>
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

<div class="table-wrap orders-card">
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
                            <div style="margin-bottom:8px;">
                              <span class="item-chip"><?= h($it['name']) ?><?= $it['variant_label'] ? ' (' . h($it['variant_label']) . ')' : '' ?> &times; <?= rtrim(rtrim(number_format($it['quantity'],3),'0'),'.') ?></span>
                              <?php if (item_requires_measurement($it)): ?>
                                <div style="margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                  <span style="color:#5B6656; font-size:0.85rem;">Measured:</span>
                                  <input type="number" step="0.001" min="0" id="meas-<?= $it['id'] ?>" value="<?= $it['measured_quantity'] !== null ? rtrim(rtrim(number_format($it['measured_quantity'],3),'0'),'.') : '' ?>" style="width:90px; padding:4px 6px;">
                                  <button class="btn" onclick="saveMeasuredItem(<?= $it['id'] ?>)">Save</button>
                                </div>
                              <?php endif; ?>
                            </div>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <span style="color:#5B6656;">—</span>
                        <?php endif; ?>          </td>
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

<script>
function saveMeasuredItem(itemId){
  const el = document.getElementById('meas-' + itemId);
  if(!el) return; const val = el.value;
  el.disabled = true;
  fetch('../ajax/update_order_item_measured.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ item_id: itemId, measured_quantity: val, csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{ if(data.success){ el.style.borderColor = '#4CAF50'; setTimeout(()=>el.style.borderColor='',800); } else { alert('Error: '+(data.error||'Failed')); } }).catch(()=>alert('Network error')).finally(()=>el.disabled=false);
}
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
