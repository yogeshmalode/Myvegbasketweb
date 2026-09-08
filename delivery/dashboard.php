<?php
require_once __DIR__ . '/includes/auth.php';

$page_title = 'Delivery Dashboard';
$rider = current_rider();
if (!$rider) {
    redirect('login.php');
}
$riderId = (int)$rider['id'];

$orders = [];
if ($rider) {
    $stmt = $pdo->prepare("SELECT o.id, o.customer_name, o.phone, o.address, o.address_lat, o.address_lng, o.delivery_lat, o.delivery_lng, o.order_status, o.total_amount, o.created_at FROM orders o WHERE o.rider_id = ? AND o.order_status IN ('processing', 'delivery_partner_assigned', 'out_for_delivery', 'arriving_soon') ORDER BY o.created_at DESC");
    $stmt->execute([$riderId]);
    $orders = $stmt->fetchAll();
}

$allRiders = $pdo->query('SELECT id, name, phone, vehicle FROM riders WHERE is_active = 1 ORDER BY name')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
  body { background: #f7faf8; }
  .rider-shell {
    max-width: 980px;
    margin: 0 auto;
    padding: 24px 16px 48px;
  }
  .rider-header {
    margin-bottom: 18px;
  }
  .rider-header h2 {
    margin: 0 0 6px;
    color: #102820;
    font-size: clamp(1.8rem, 2.5vw, 2.8rem);
    letter-spacing: -0.06em;
    font-weight: 900;
  }
  .rider-header p {
    margin: 0;
    color: #65736c;
    font-size: 0.85rem;
  }
  .rider-card {
    background: #fff;
    border: 1px solid #e2e9e4;
    border-radius: 18px;
    box-shadow: 0 10px 25px rgba(16, 40, 32, 0.05);
    padding: 18px;
    margin-bottom: 18px;
  }
  .rider-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
  }
  .eyebrow {
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-size: 0.7rem;
    color: #69766f;
    font-weight: 700;
  }
  .rider-name {
    margin: 6px 0 0;
    font-size: 1.5rem;
    font-weight: 800;
    color: #13241f;
  }
  .rider-vehicle {
    font-weight: 800;
    color: #1d5f8d;
    background: #edf5ff;
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 0.75rem;
  }
  .order-card {
    background: #fff;
    border: 1px solid #dfe9e2;
    border-left: 4px solid #1d7a5d;
    border-radius: 18px;
    padding: 18px;
    margin-bottom: 18px;
    box-shadow: 0 10px 25px rgba(16, 40, 32, 0.04);
  }
  .order-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 10px;
  }
  .order-number {
    font-size: 1.35rem;
    font-weight: 900;
    color: #13241f;
    letter-spacing: -0.04em;
  }
  .order-status {
    font-weight: 700;
    color: #1d5f8d;
    background: #eef5ff;
    padding: 8px 12px;
    border-radius: 999px;
    font-size: 0.75rem;
  }
  .order-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
    margin: 12px 0;
  }
  .metric-box {
    background: #f9fbfa;
    border: 1px solid #edf2ef;
    border-radius: 10px;
    padding: 10px 12px;
  }
  .metric-label {
    font-size: 0.68rem;
    color: #66736f;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
  }
  .metric-value {
    margin-top: 5px;
    font-size: 1rem;
    font-weight: 800;
    color: #1c2a27;
  }
  .address-copy {
    margin: 8px 0;
    color: #5b6656;
    white-space: pre-line;
    line-height: 1.6;
  }
  .action-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 14px;
  }
  .action-row .btn {
    min-height: 42px;
    padding: 0 14px;
    border-radius: 10px;
    font-size: 0.78rem;
    font-weight: 700;
  }
  @media (max-width: 640px) {
    .rider-shell { padding: 18px 12px 40px; }
    .order-head, .rider-topline { align-items: flex-start; }
    .action-row .btn { flex: 1 1 calc(50% - 10px); }
  }
</style>

<div class="rider-shell">
  <div class="rider-header">
    <h2>MYVEGBASKET DELIVERY</h2>
    <p>Mobile-first rider dashboard for assigned orders.</p>
  </div>

  <?php if (!$rider): ?>
    <div class="rider-card">
      <h3>Select a rider</h3>
      <div style="display:grid; gap:12px; margin-top:12px;">
        <?php foreach ($allRiders as $row): ?>
          <a class="btn btn-primary" style="display:block; text-align:center;" href="dashboard.php?rider_id=<?= (int)$row['id'] ?>"><?= h($row['name']) ?><?= !empty($row['vehicle']) ? ' · ' . h($row['vehicle']) : '' ?></a>
        <?php endforeach; ?>
        <?php if (empty($allRiders)): ?>
          <p style="color:#5B6656; margin:0;">No active riders found. Add one from the admin panel first.</p>
        <?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="rider-card">
      <div class="rider-topline">
        <div>
          <div class="eyebrow">Delivery partner</div>
          <h3 class="rider-name"><?= h($rider['name']) ?></h3>
        </div>
        <div class="rider-vehicle"><?= h($rider['vehicle'] ?: 'Bike') ?></div>
      </div>
    </div>

    <?php foreach ($orders as $order): ?>
      <?php
      $customerLat = !empty($order['address_lat']) ? (float)$order['address_lat'] : null;
      $customerLng = !empty($order['address_lng']) ? (float)$order['address_lng'] : null;
      $distanceKm = null;
      if ($customerLat !== null && $customerLng !== null && !empty($order['delivery_lat']) && !empty($order['delivery_lng'])) {
          $distanceKm = haversine_km((float)$order['delivery_lat'], (float)$order['delivery_lng'], $customerLat, $customerLng);
      }
      $eta = $distanceKm !== null ? estimate_eta_minutes($distanceKm) : 0;
      ?>
      <div class="order-card">
        <div class="order-head">
          <div>
            <div class="eyebrow">Order</div>
            <div class="order-number">#ORD-<?= str_pad($order['id'], 5, '0', STR_PAD_LEFT) ?></div>
          </div>
          <div class="order-status"><?= h(get_order_status_options()[$order['order_status']] ?? ucfirst(str_replace('_', ' ', $order['order_status']))) ?></div>
        </div>

        <div class="order-metrics">
          <div class="metric-box">
            <div class="metric-label">Customer</div>
            <div class="metric-value"><?= h($order['customer_name']) ?></div>
          </div>
          <div class="metric-box">
            <div class="metric-label">Distance</div>
            <div class="metric-value"><?= $distanceKm !== null ? number_format($distanceKm, 1) . ' km' : 'Waiting' ?></div>
          </div>
          <div class="metric-box">
            <div class="metric-label">ETA</div>
            <div class="metric-value"><?= $eta > 0 ? $eta . ' min' : 'Pending' ?></div>
          </div>
        </div>

        <p class="address-copy"><?= nl2br(h($order['address'])) ?></p>

        <div class="action-row">
          <button type="button" class="btn btn-primary" data-order-id="<?= (int)$order['id'] ?>" data-action="start_delivery">Start Delivery</button>
          <button type="button" class="btn" data-order-id="<?= (int)$order['id'] ?>" data-action="mark_delivered">Mark Delivered</button>
          <a class="btn" href="tel:<?= h($order['phone']) ?>">Call customer</a>
          <a class="btn" href="../track_order.php?order_id=<?= (int)$order['id'] ?>" target="_blank">Open tracking</a>
          <?php if ($customerLat !== null && $customerLng !== null): ?>
            <a class="btn" href="https://www.google.com/maps/dir/?api=1&destination=<?= rawurlencode($customerLat . ',' . $customerLng) ?>" target="_blank" rel="noopener">Navigate</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (empty($orders)): ?>
      <div class="rider-card" style="text-align:center; color:#5B6656;">
        No active deliveries assigned to this rider right now.
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function updateOrderStatus(orderId, status, label) {
  fetch('../ajax/update_order_status.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: Number(orderId), status: status, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
    const text = await r.text();
    try {
      const data = JSON.parse(text);
      if (data.success) {
        alert(label + ' updated successfully.');
        location.reload();
        return;
      }
      alert('Failed: ' + (data.error || 'Unknown error'));
    } catch (e) {
      alert('Failed: ' + text.slice(0, 200));
    }
  }).catch(() => alert('Network error'));
}

document.querySelectorAll('[data-order-id]').forEach((button) => {
  button.addEventListener('click', () => {
    const orderId = button.dataset.orderId;
    const action = button.dataset.action;
    if (action === 'start_delivery') {
      updateOrderStatus(orderId, 'out_for_delivery', 'Delivery started');
      return;
    }
    if (action === 'mark_delivered') {
      updateOrderStatus(orderId, 'delivered', 'Order marked delivered');
    }
  });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
