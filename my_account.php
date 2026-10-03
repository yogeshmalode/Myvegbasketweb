<?php
require_once __DIR__ . '/config.php';

if (!is_customer_logged_in()) {
    redirect(BASE_URL . '/login.php?redirect=my_account.php');
}

$customer = current_customer();
if (!$customer) {
    // Session pointed at a customer that no longer exists — log out cleanly.
    redirect(BASE_URL . '/logout.php');
}

$orders = $pdo->prepare("SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
$orders->execute([$customer['id']]);
$orders = $orders->fetchAll();

$itemsByOrder = [];
if ($orders) {
    $ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $itemStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders) ORDER BY id");
    $itemStmt->execute($ids);
    foreach ($itemStmt->fetchAll() as $row) {
        $itemsByOrder[$row['order_id']][] = $row;
    }
}

$statusColors = [
    'pending'                => ['bg' => '#EDEDE6', 'fg' => '#5B6656'],
    'placed'                 => ['bg' => '#E3EDFB', 'fg' => '#1F4E8C'],
    'processing'             => ['bg' => '#FFF1BF', 'fg' => '#8A6D00'],
    'ready_for_pickup'       => ['bg' => '#EFE6FF', 'fg' => '#5F38A5'],
    'assigning_rider'        => ['bg' => '#EDF3FF', 'fg' => '#355DA8'],
    'delivery_partner_assigned' => ['bg' => '#E8F5FF', 'fg' => '#0B6B93'],
    'awaiting_verification'  => ['bg' => '#FFF1BF', 'fg' => '#8A6D00'],
    'out_for_delivery'       => ['bg' => '#FFE1C2', 'fg' => '#B25B00'],
    'arriving_soon'          => ['bg' => '#FFE3EA', 'fg' => '#AD355B'],
    'delivered'              => ['bg' => '#DCEEDB', 'fg' => '#1F4D36'],
    'paid'                   => ['bg' => '#DCEEDB', 'fg' => '#1F4D36'],
    'cancelled'              => ['bg' => '#FCE8E6', 'fg' => '#9A2E24'],
    'failed'                 => ['bg' => '#FCE8E6', 'fg' => '#9A2E24'],
];

$isB2B = in_array($customer['customer_type'] ?? 'retail', ['hotel', 'shop'], true);
$b2bBalance = 0.0;
$pendingOrdersCount = 0;
if ($isB2B) {
    $b2bBalance = get_customer_balance($pdo, $customer['id']);
    foreach ($orders as $o) {
        if (($o['payment_method'] ?? '') === 'credit' && ($o['payment_status'] ?? '') === 'pending') {
            $pendingOrdersCount++;
        }
    }
}

$page_title = 'My Account';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:840px;">
  <div class="form-card" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; margin-bottom:28px;">
    <div style="display:flex; align-items:center; gap:16px;">
      <span style="width:56px; height:56px; border-radius:50%; background:var(--carrot); color:#1F2A17; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.5rem; font-family:var(--font-display); flex-shrink:0;">
        <?= h(strtoupper(mb_substr($customer['name'] ?? '?', 0, 1))) ?>
      </span>
      <div>
        <h2 style="margin:0;">Hi, <?= h($customer['name']) ?> 👋</h2>
        <p style="color:#5B6656; margin:4px 0 0;"><?= h($customer['email']) ?> &middot; <?= h($customer['phone'] ?: '—') ?></p>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/logout.php" class="btn" style="background:#FCE8E6; color:#9A2E24; border:1px solid #F1B6AF; white-space:nowrap;">🚪 Log out</a>
  </div>

  <?php if ($isB2B):
    $availableCredit = max(0, (float)$customer['credit_limit'] - $b2bBalance);
    $overLimit = $b2bBalance > (float)$customer['credit_limit'];
  ?>
  <div class="form-card" style="margin-bottom:28px; border:1px solid #E4E9DD;">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
      <h3 style="margin:0;">🏨 B2B Partner Account — <?= h($customer['business_name'] ?: $customer['name']) ?></h3>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="padding:8px 18px; font-size:0.85rem;">+ New order</a>
    </div>
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:14px;">
      <div style="background:#F6F8F1; border-radius:14px; padding:14px;">
        <div style="color:#5B6656; font-size:0.78rem; font-weight:600; text-transform:uppercase;">Credit limit</div>
        <div style="font-size:1.3rem; font-weight:700; color:var(--leaf-dark);"><?= SITE_CURRENCY ?><?= number_format((float)$customer['credit_limit'], 2) ?></div>
      </div>
      <div style="background:#F6F8F1; border-radius:14px; padding:14px;">
        <div style="color:#5B6656; font-size:0.78rem; font-weight:600; text-transform:uppercase;">Outstanding balance</div>
        <div style="font-size:1.3rem; font-weight:700; color:<?= $overLimit ? '#9A2E24' : '#B25B00' ?>;"><?= SITE_CURRENCY ?><?= number_format($b2bBalance, 2) ?></div>
      </div>
      <div style="background:#F6F8F1; border-radius:14px; padding:14px;">
        <div style="color:#5B6656; font-size:0.78rem; font-weight:600; text-transform:uppercase;">Available credit</div>
        <div style="font-size:1.3rem; font-weight:700; color:#1F4D36;"><?= SITE_CURRENCY ?><?= number_format($availableCredit, 2) ?></div>
      </div>
      <div style="background:#F6F8F1; border-radius:14px; padding:14px;">
        <div style="color:#5B6656; font-size:0.78rem; font-weight:600; text-transform:uppercase;">Payment terms</div>
        <div style="font-size:1.3rem; font-weight:700; color:var(--leaf-dark);"><?= (int)($customer['payment_terms_days'] ?: 0) ?> days</div>
      </div>
    </div>
    <?php if ($pendingOrdersCount > 0): ?>
      <p style="margin:16px 0 0; background:#FFF1BF; color:#8A6D00; padding:10px 14px; border-radius:10px; font-weight:600;">
        ⏳ You have <?= $pendingOrdersCount ?> order<?= $pendingOrdersCount > 1 ? 's' : '' ?> billed on credit awaiting payment.
      </p>
    <?php endif; ?>
    <?php if ($overLimit): ?>
      <p style="margin:12px 0 0; background:#FCE8E6; color:#9A2E24; padding:10px 14px; border-radius:10px; font-weight:600;">
        ⚠️ Your outstanding balance has exceeded your credit limit. Please clear dues to keep placing credit orders.
      </p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <h3 style="margin-bottom:14px;">Your orders</h3>

  <?php if (empty($orders)): ?>
    <div class="form-card" style="text-align:center;">
      <p style="color:#5B6656; margin-bottom:20px;">You haven't placed any orders yet.</p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Start shopping</a>
    </div>
  <?php else: ?>
    <?php foreach ($orders as $o): ?>
      <?php
        $pColor = $statusColors[$o['payment_status']] ?? ['bg' => '#fff', 'fg' => '#26301F'];
        $sColor = $statusColors[$o['order_status']] ?? ['bg' => '#fff', 'fg' => '#26301F'];
      ?>
      <div class="form-card" style="margin-bottom:18px;">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:12px;">
          <div>
            <strong>Order #<?= $o['id'] ?></strong>
            <div style="color:#5B6656; font-size:0.85rem;"><?= format_ist($o['created_at']) ?></div>
          </div>
          <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <span style="background:<?= $pColor['bg'] ?>; color:<?= $pColor['fg'] ?>; padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:700;">
              <?= h(ucwords(str_replace('_',' ',$o['payment_status']))) ?>
            </span>
            <span style="background:<?= $sColor['bg'] ?>; color:<?= $sColor['fg'] ?>; padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:700;">
              <?= h(ucwords(str_replace('_',' ',$o['order_status']))) ?>
            </span>
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <?php foreach ($itemsByOrder[$o['id']] ?? [] as $it): ?>
            <span class="item-chip" style="display:inline-block; background:var(--leaf-light); color:var(--leaf-dark); padding:3px 10px; border-radius:999px; font-size:0.75rem; font-weight:600; margin:0 5px 5px 0;">
              <?= h($it['name']) ?> &times; <?= (int)$it['quantity'] ?>
            </span>
          <?php endforeach; ?>
        </div>

        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
          <span style="font-weight:700; color:var(--leaf-dark);"><?= SITE_CURRENCY ?><?= number_format($o['total_amount'],2) ?></span>
          <div style="display:flex; gap:8px;">
            <?php if (!in_array($o['order_status'], ['delivered', 'cancelled'])): ?>
              <a href="<?= BASE_URL ?>/track_order.php?order_id=<?= $o['id'] ?>" class="btn" style="padding:8px 18px; font-size:0.85rem; background:#fff; border:1px solid #E4E9DD;">📍 Track</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/reorder.php?order_id=<?= $o['id'] ?>" class="btn btn-primary" style="padding:8px 18px; font-size:0.85rem;">Order again</a>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
