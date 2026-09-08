<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Dashboard';

$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE created_at BETWEEN ? AND ? AND order_status <> 'cancelled'");
$st->execute([$todayStart,$todayEnd]);
$totalSales = (float)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ? AND order_status <> 'cancelled'");
$st->execute([$todayStart,$todayEnd]);
$totalOrders = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM vegetables WHERE is_active=1");
$st->execute();
$totalProducts = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM wastage WHERE created_at BETWEEN ? AND ?");
$st->execute([$todayStart,$todayEnd]);
$wasteQty = (int)$st->fetchColumn();

$st = $pdo->prepare("SELECT id, customer_name, total_amount, payment_method, order_status, created_at FROM orders ORDER BY id DESC LIMIT 5");
$st->execute();
$recentOrders = $st->fetchAll();

$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND order_status <> 'cancelled'");
$st->execute();
$weekSales = (float)$st->fetchColumn();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading">
  <div><h1>Dashboard</h1><p>Welcome back, <?= h($_SESSION['admin_username'] ?? 'Admin') ?>! Here's what's happening today.</p></div>
</div>

<div class="admin-stat-grid">
  <div class="admin-stat-card"><div class="stat-icon green">🛒</div><span>Total Sales</span><strong>₹<?= number_format($totalSales,2) ?></strong><small>Today</small><em>↑ Live sales</em></div>
  <div class="admin-stat-card"><div class="stat-icon blue">▣</div><span>Total Orders</span><strong><?= number_format($totalOrders) ?></strong><small>Today</small><em>↑ Current orders</em></div>
  <div class="admin-stat-card"><div class="stat-icon orange">▦</div><span>Total Products</span><strong><?= number_format($totalProducts) ?></strong><small>In inventory</small><em>Active catalog</em></div>
  <div class="admin-stat-card"><div class="stat-icon purple">♜</div><span>Wastage</span><strong><?= number_format($wasteQty) ?></strong><small>Units today</small><em>Track from Wastage</em></div>
</div>

<div class="admin-dashboard-grid">
  <section class="admin-panel admin-orders-panel">
    <div class="admin-panel-head"><h2>Recent Orders</h2><a href="orders.php">View All</a></div>
    <div class="admin-table-scroll"><table class="admin-modern-table"><thead><tr><th>Order ID</th><th>Customer</th><th>Amount</th><th>Payment</th><th>Status</th><th>Date</th></tr></thead><tbody>
      <?php foreach($recentOrders as $o): ?>
      <tr><td>#ORD-<?= str_pad((int)$o['id'],5,'0',STR_PAD_LEFT) ?></td><td><?= h($o['customer_name']) ?></td><td>₹<?= number_format((float)$o['total_amount'],2) ?></td><td><?= h(strtoupper($o['payment_method'])) ?></td><td><span class="status-pill <?= h($o['order_status']) ?>"><?= h(ucfirst(str_replace('_',' ',$o['order_status']))) ?></span></td><td><?= date('d M Y', strtotime($o['created_at'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if(!$recentOrders): ?><tr><td colspan="6" class="empty-state">No orders yet.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

<div class="admin-dashboard-bottom">
  <section class="admin-panel sales-overview"><div class="admin-panel-head"><h2>Sales Overview <small>(Last 7 Days)</small></h2></div><div class="sales-big">₹<?= number_format($weekSales,2) ?></div><div class="sales-bar"><span style="width:<?= min(100,max(4,$weekSales>0?72:4)) ?>%"></span></div><div class="sales-axis"><span>7 days ago</span><span>Today</span></div></section>
  <section class="admin-panel quick-links"><div class="admin-panel-head"><h2>Quick Actions</h2></div><a href="billing.php">＋ New Billing</a><a href="inventory.php">▣ Update Inventory</a><a href="wastage.php">♜ Record Wastage</a><a href="reports.php">▥ View P&amp;L Reports</a><a href="catalog_pricing.php">⚡ Catalog &amp; Live Pricing</a><a href="fulfillment.php">📦 Fulfillment Center</a></section>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
