<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Delivery Dashboard';

$stats = [
    'active' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('delivery_partner_assigned','out_for_delivery','arriving_soon')")->fetchColumn(),
    'pending' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('placed','processing','ready_for_pickup','assigning_rider')")->fetchColumn(),
    'completed' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'delivered'")->fetchColumn(),
    'drivers' => (int)$pdo->query("SELECT COUNT(*) FROM riders WHERE is_active = 1")->fetchColumn(),
];

$activeDeliveries = $pdo->query("SELECT o.id, o.customer_name, o.phone, o.address, o.order_status, o.delivery_lat, o.delivery_lng, r.name AS rider_name FROM orders o LEFT JOIN riders r ON r.id = o.rider_id WHERE o.order_status IN ('delivery_partner_assigned','out_for_delivery','arriving_soon') ORDER BY o.updated_at DESC LIMIT 20")->fetchAll();
$pendingDeliveries = $pdo->query("SELECT o.id, o.customer_name, o.phone, o.address, o.order_status, r.name AS rider_name FROM orders o LEFT JOIN riders r ON r.id = o.rider_id WHERE o.order_status IN ('placed','processing','ready_for_pickup','assigning_rider') ORDER BY o.created_at DESC LIMIT 20")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<style>
  .delivery-shell { background: transparent; }
  .delivery-hero {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 18px;
  }
  .delivery-hero h1 {
    margin: 0;
    font-size: clamp(1.6rem, 2vw, 2.3rem);
    color: #17231f;
    letter-spacing: -0.04em;
    font-weight: 800;
  }
  .delivery-hero p {
    margin: 8px 0 0;
    color: #67736f;
    font-size: 0.82rem;
    line-height: 1.5;
  }
  .delivery-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }
  .delivery-stat {
    background: #fff;
    border: 1px solid #e2e9e4;
    border-radius: 16px;
    padding: 18px 16px;
    box-shadow: 0 8px 22px rgba(17, 48, 37, 0.04);
  }
  .delivery-stat-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
  }
  .delivery-stat-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
  }
  .delivery-stat-icon.green { background: #eaf7f0; color: #0d7a59; }
  .delivery-stat-icon.blue { background: #edf4ff; color: #3979d8; }
  .delivery-stat-icon.orange { background: #fff4e7; color: #d9822a; }
  .delivery-stat-icon.purple { background: #f2ebff; color: #8245d5; }
  .delivery-stat-label {
    color: #66736f;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 8px;
    display: block;
  }
  .delivery-stat strong {
    display: block;
    font-size: clamp(1.5rem, 2vw, 2.2rem);
    letter-spacing: -0.04em;
    color: #13241f;
    margin-bottom: 6px;
  }
  .delivery-stat small {
    color: #72807b;
    font-size: 0.72rem;
  }
  .delivery-panels {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
  }
  .delivery-panel {
    background: #fff;
    border: 1px solid #e2e9e4;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 8px 22px rgba(17, 48, 37, 0.04);
  }
  .delivery-panel-head {
    padding: 16px 18px 12px;
    border-bottom: 1px solid #edf0ee;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .delivery-panel-head h2 {
    margin: 0;
    font-size: 1.05rem;
    color: #17231f;
    letter-spacing: -0.02em;
  }
  .delivery-table-wrap {
    overflow-x: auto;
  }
  .delivery-panel table {
    width: 100%;
    min-width: 640px;
    border-collapse: collapse;
  }
  .delivery-panel th,
  .delivery-panel td {
    padding: 12px 14px;
    text-align: left;
    border-bottom: 1px solid #edf0ee;
    font-size: 0.8rem;
    color: #1f2b28;
  }
  .delivery-panel th {
    background: #f7faf8;
    color: #55625d;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 800;
  }
  .delivery-panel tbody tr:hover { background: #fafdf9; }
  .delivery-panel tbody tr:last-child td { border-bottom: none; }
  .status-tag {
    display: inline-flex;
    padding: 5px 8px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    background: #edf7f1;
    color: #0d7a59;
  }
  .empty-state {
    text-align: center;
    color: #68736f;
    padding: 18px;
  }
  @media (max-width: 900px) {
    .delivery-stats,
    .delivery-panels {
      grid-template-columns: 1fr 1fr;
    }
  }
  @media (max-width: 650px) {
    .delivery-stats,
    .delivery-panels {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="delivery-shell">
  <div class="delivery-hero">
    <div>
      <h1>Live Deliveries</h1>
      <p>Monitor rider assignments, active routes, and delivery status in real time.</p>
    </div>
  </div>

  <div class="delivery-stats">
    <div class="delivery-stat">
      <div class="delivery-stat-head">
        <span class="delivery-stat-label">Active</span>
        <div class="delivery-stat-icon green">🚚</div>
      </div>
      <strong><?= number_format($stats['active']) ?></strong>
      <small>On route</small>
    </div>
    <div class="delivery-stat">
      <div class="delivery-stat-head">
        <span class="delivery-stat-label">Pending</span>
        <div class="delivery-stat-icon blue">⏳</div>
      </div>
      <strong><?= number_format($stats['pending']) ?></strong>
      <small>Awaiting rider</small>
    </div>
    <div class="delivery-stat">
      <div class="delivery-stat-head">
        <span class="delivery-stat-label">Completed</span>
        <div class="delivery-stat-icon orange">✅</div>
      </div>
      <strong><?= number_format($stats['completed']) ?></strong>
      <small>Delivered</small>
    </div>
    <div class="delivery-stat">
      <div class="delivery-stat-head">
        <span class="delivery-stat-label">Riders</span>
        <div class="delivery-stat-icon purple">🛵</div>
      </div>
      <strong><?= number_format($stats['drivers']) ?></strong>
      <small>Available</small>
    </div>
  </div>

  <div class="delivery-panels">
    <section class="delivery-panel">
      <div class="delivery-panel-head"><h2>Active Deliveries</h2></div>
      <div class="delivery-table-wrap">
        <table>
          <thead>
            <tr><th>Order</th><th>Customer</th><th>Rider</th><th>Status</th><th>Location</th></tr>
          </thead>
          <tbody>
            <?php foreach ($activeDeliveries as $order): ?>
              <tr>
                <td>#ORD-<?= str_pad((int)$order['id'],5,'0',STR_PAD_LEFT) ?></td>
                <td><?= h($order['customer_name']) ?></td>
                <td><?= h($order['rider_name'] ?? 'Unassigned') ?></td>
                <td><span class="status-tag"><?= h(get_order_status_options()[$order['order_status']] ?? ucfirst(str_replace('_',' ',$order['order_status']))) ?></span></td>
                <td><?= $order['delivery_lat'] && $order['delivery_lng'] ? 'Live GPS' : 'Awaiting GPS' ?></td>
              </tr>
            <?php endforeach; if(!$activeDeliveries): ?><tr><td colspan="5" class="empty-state">No active deliveries.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="delivery-panel">
      <div class="delivery-panel-head"><h2>Pending Deliveries</h2></div>
      <div class="delivery-table-wrap">
        <table>
          <thead>
            <tr><th>Order</th><th>Customer</th><th>Rider</th><th>Status</th><th>Address</th></tr>
          </thead>
          <tbody>
            <?php foreach ($pendingDeliveries as $order): ?>
              <tr>
                <td>#ORD-<?= str_pad((int)$order['id'],5,'0',STR_PAD_LEFT) ?></td>
                <td><?= h($order['customer_name']) ?></td>
                <td><?= h($order['rider_name'] ?? 'Unassigned') ?></td>
                <td><span class="status-tag"><?= h(get_order_status_options()[$order['order_status']] ?? ucfirst(str_replace('_',' ',$order['order_status']))) ?></span></td>
                <td><?= h(substr($order['address'],0,50)) ?></td>
              </tr>
            <?php endforeach; if(!$pendingDeliveries): ?><tr><td colspan="5" class="empty-state">No pending orders.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
