<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../lib/worker_attendance.php';

$page_title = 'Delivery Dashboard';
$rider = current_rider();
if (!$rider) {
    redirect('login.php');
}
$riderId = (int)$rider['id'];
close_due_worker_attendance($pdo, 'rider', $riderId);
$compensationStmt = $pdo->prepare("SELECT employment_type, pay_mode, per_km_rate, shift_work_minutes, break_minutes FROM worker_compensation
    WHERE worker_type = 'rider' AND worker_id = ?");
$compensationStmt->execute([$riderId]);
$riderCompensation = $compensationStmt->fetch() ?: [
    'employment_type' => 'full_time',
    'pay_mode' => 'attendance_only',
    'per_km_rate' => null,
    'shift_work_minutes' => 480,
    'break_minutes' => 60,
];

$orders = [];
if ($rider) {
    $stmt = $pdo->prepare("SELECT o.id, o.customer_name, o.phone, o.address, o.delivery_pincode, o.delivery_area, o.customer_note, o.address_lat, o.address_lng, o.delivery_lat, o.delivery_lng, o.order_status, o.payment_status, o.rider_id, o.rider_accepted_at, o.assigned_picker_id, o.total_amount, o.created_at FROM orders o WHERE o.rider_id = ? AND o.order_status IN ('delivery_partner_assigned', 'out_for_delivery', 'arriving_soon') ORDER BY o.created_at DESC");
    $stmt->execute([$riderId]);
    $orders = $stmt->fetchAll();
}
$assignedOrderCount = count(array_filter($orders, static fn(array $order): bool => $order['order_status'] === 'delivery_partner_assigned'));
$onRoadOrderCount = count(array_filter($orders, static fn(array $order): bool => in_array($order['order_status'], ['out_for_delivery', 'arriving_soon'], true)));
$attendanceStmt = $pdo->prepare("SELECT attendance.id, attendance.clock_in_at,
        overtime.status AS overtime_status, overtime.requested_minutes AS overtime_minutes
    FROM worker_attendance attendance
    LEFT JOIN worker_overtime_requests overtime ON overtime.attendance_id = attendance.id
    WHERE attendance.worker_type = 'rider' AND attendance.worker_id = ? AND attendance.clock_out_at IS NULL
    ORDER BY attendance.id DESC LIMIT 1");
$attendanceStmt->execute([$riderId]);
$openShift = $attendanceStmt->fetch() ?: null;
$attendanceHistoryStmt = $pdo->prepare("SELECT clock_in_at, clock_out_at, shift_summary
    FROM worker_attendance WHERE worker_type = 'rider' AND worker_id = ?
    ORDER BY clock_in_at DESC LIMIT 14");
$attendanceHistoryStmt->execute([$riderId]);
$attendanceHistory = $attendanceHistoryStmt->fetchAll();
$historyStmt = $pdo->prepare("SELECT id, customer_name, address, total_amount, delivered_at
    FROM orders WHERE rider_id = ? AND order_status = 'delivered'
    ORDER BY delivered_at DESC, id DESC LIMIT 50");
$historyStmt->execute([$riderId]);
$completedOrders = $historyStmt->fetchAll();
$orderRefreshVersion = get_order_refresh_version($orders);

$allRiders = $pdo->query('SELECT id, name, phone, vehicle FROM riders WHERE is_active = 1 ORDER BY name')->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
  body { background: #f4f7f5; }
  .rider-shell {
    max-width: 1120px;
    margin: 0 auto;
    padding: 28px 20px 56px;
  }
  .rider-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 22px;
    padding: 24px;
    border: 1px solid #dce9df;
    border-radius: 22px;
    background: linear-gradient(115deg, #fff 0%, #f0f8f1 65%, #fff5e8 100%);
    box-shadow: 0 12px 30px rgba(31, 77, 54, .08);
  }
  .rider-header-copy { min-width: 0; }
  .rider-header-kicker {
    margin: 0 0 5px;
    color: #43814f;
    font-size: .68rem;
    font-weight: 850;
    letter-spacing: .12em;
    text-transform: uppercase;
  }
  .rider-header-topline {
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
  }
  .rider-header h2 {
    margin: 0;
    color: #173d2b;
    font-size: clamp(1.55rem, 3vw, 2.15rem);
    letter-spacing: -.055em;
    font-weight: 900;
    line-height: 1.15;
  }
  .rider-header > .rider-header-copy > p:not(.rider-header-kicker) {
    margin: 7px 0 0;
    color: #65736c;
    font-size: .86rem;
  }
  .rider-header .rider-header-kicker { margin: 0 0 5px; color: #43814f; }
  .rider-header-logout {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    min-height: 42px;
    padding: 0 15px;
    border: 1px solid #f0d7d4;
    border-radius: 12px;
    background: #fff;
    color: #a33e36;
    font: 800 .8rem var(--font-body);
    box-shadow: 0 4px 12px rgba(31, 77, 54, .06);
  }
  .rider-header-logout:hover { background: #fff4f2; border-color: #e7b5af; }
  .rider-profile-strip {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 15px;
  }
  .rider-avatar {
    width: 38px;
    height: 38px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #e3f1e4;
    color: #1f6b37;
    font-size: 1.1rem;
  }
  .rider-profile-copy { display: grid; gap: 2px; }
  .rider-profile-copy strong { color: #21382a; font-size: .9rem; }
  .rider-profile-copy small { color: #748078; font-size: .72rem; }
  .rider-shift-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: 4px;
    padding: 6px 9px;
    border: 1px solid #e2e8e3;
    border-radius: 999px;
    background: #fff;
    color: #68746d;
    font-size: .66rem;
    font-weight: 800;
  }
  .rider-shift-pill.is-on { border-color: #cbe8d0; background: #eef9ef; color: #21743a; }
  .rider-shift-pill:before { width: 7px; height: 7px; border-radius: 50%; background: #a6b0a9; content: ""; }
  .rider-shift-pill.is-on:before { background: #24a148; box-shadow: 0 0 0 3px rgba(36,161,72,.13); }
  .rider-summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }
  .rider-summary-card {
    display: flex;
    align-items: center;
    gap: 13px;
    min-width: 0;
    padding: 16px;
    border: 1px solid #e2e9e4;
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 6px 18px rgba(16, 40, 32, .045);
  }
  .rider-summary-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: #edf5ff;
    font-size: 1.25rem;
  }
  .rider-summary-card:nth-child(2) .rider-summary-icon { background: #e9f7eb; }
  .rider-summary-card:nth-child(3) .rider-summary-icon { background: #fff2df; }
  .rider-summary-copy { min-width: 0; display: grid; gap: 2px; }
  .rider-summary-copy strong { color: #1c3023; font-size: 1.25rem; line-height: 1.1; }
  .rider-summary-copy span { color: #6d7971; font-size: .73rem; font-weight: 650; }
  .rider-section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    margin: 26px 0 13px;
  }
  .rider-section-heading h3 { margin: 0; color: #173d2b; font-size: 1.2rem; }
  .rider-section-heading p { margin: 4px 0 0; color: #718078; font-size: .78rem; }
  .rider-count-pill { padding: 6px 10px; border-radius: 999px; background: #e8f0e2; color: #1f4d36; font-size: .7rem; font-weight: 850; }
  .rider-order-filters { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 13px; }
  .rider-order-filters button { display: inline-flex; align-items: center; gap: 8px; min-height: 36px; padding: 0 12px; border: 1px solid #dfe8e2; border-radius: 999px; background: #fff; color: #52635a; font: 750 .72rem var(--font-body); }
  .rider-order-filters button span { min-width: 20px; padding: 2px 5px; border-radius: 999px; background: #f0f3f0; color: #5b6656; font-size: .64rem; text-align: center; }
  .rider-order-filters button.is-active { border-color: #1f6b37; background: #1f6b37; color: #fff; box-shadow: 0 4px 12px rgba(31,107,55,.15); }
  .rider-order-filters button.is-active span { background: rgba(255,255,255,.2); color: #fff; }
  .rider-card {
    background: #fff;
    border: 1px solid #e2e9e4;
    border-radius: 18px;
    box-shadow: 0 8px 22px rgba(16, 40, 32, 0.045);
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
    overflow: hidden;
    background: #fff;
    border: 1px solid #e0e9e2;
    border-radius: 18px;
    padding: 0;
    margin-bottom: 14px;
    box-shadow: 0 8px 22px rgba(16, 40, 32, 0.055);
    animation: rider-card-enter .35s ease both;
  }
  .order-card:nth-of-type(2) { animation-delay: .05s; }
  .order-card:nth-of-type(3) { animation-delay: .1s; }
  .order-card:nth-of-type(4) { animation-delay: .15s; }
  @keyframes rider-card-enter { from { opacity: 0; transform: translateY(9px); } to { opacity: 1; transform: translateY(0); } }
  .order-card-topline { padding: 16px 18px 13px; border-bottom: 1px solid #edf1ed; }
  .order-card-body { padding: 15px 18px 18px; }
  .order-card-identity { display: flex; align-items: center; gap: 11px; }
  .order-card-icon { width: 42px; height: 42px; flex: 0 0 42px; display: grid; place-items: center; border-radius: 13px; background: #edf5ff; font-size: 1.15rem; }
  .order-card[data-order-stage="on-road"] .order-card-icon { background: #eaf7eb; }
  .order-card-title { min-width: 0; }
  .order-card-title .eyebrow { font-size: .61rem; }
  .order-card-title .order-number { margin-top: 2px; font-size: 1.1rem; }
  .order-status {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 10px;
    border: 1px solid #d9e8f7;
    border-radius: 999px;
    background: #eef5ff;
    color: #28608e;
    font-size: .68rem;
    font-weight: 800;
    white-space: nowrap;
  }
  .order-status[data-status="out_for_delivery"], .order-status[data-status="arriving_soon"] { border-color: #cee9d1; background: #edf8ee; color: #26723a; }
  .order-progress { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 5px; margin: 3px 0 16px; }
  .order-progress-step { position: relative; display: grid; justify-items: center; gap: 6px; color: #99a39c; font-size: .62rem; font-weight: 700; text-align: center; }
  .order-progress-step:before { width: 100%; height: 4px; border-radius: 999px; background: #e8eeea; content: ""; }
  .order-progress-step.is-complete:before { background: #63ad70; }
  .order-progress-step.is-current:before { background: linear-gradient(90deg, #3f8b52, #e8934a); }
  .order-progress-step.is-complete,.order-progress-step.is-current { color: #315d3b; }
  .order-card-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) repeat(2, minmax(100px, .7fr)); gap: 9px; margin: 0 0 12px; }
  .order-info-tile { min-width: 0; padding: 10px 11px; border: 1px solid #edf1ed; border-radius: 12px; background: #fafcfb; }
  .order-info-tile .metric-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .order-address { display: flex; align-items: flex-start; gap: 8px; margin: 12px 0; padding: 11px 12px; border-radius: 12px; background: #f5f8f5; color: #4f6255; font-size: .82rem; line-height: 1.5; }
  .order-address-icon { flex: 0 0 auto; }
  .order-card-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
  .order-card-actions .btn { min-height: 42px; padding: 0 14px; border-radius: 11px; font-size: .78rem; font-weight: 800; }
  .order-card-actions .order-primary-action { min-width: 168px; background: #1f7a43; box-shadow: 0 5px 13px rgba(31,122,67,.17); }
  .order-card-actions .order-danger-action { border: 1px solid #f1d8d5; background: #fff7f6; color: #a33e36; }
  .order-card-actions .order-secondary-action { border: 1px solid #dfe8e2; background: #fff; color: #385444; }
  .order-feedback { margin: 12px 0 0; padding: 10px 12px; border-radius: 10px; background: #fff0ed; color: #9e332b; font-size: .8rem; }
  .order-feedback:empty { display: none; }
  .order-feedback.is-success { background: #edf8ee; color: #26723a; }
  .order-route-details { margin-top: 13px; border: 1px solid #dfe9e2; border-radius: 13px; background: #f9fbf9; }
  .order-route-details > summary { padding: 12px 14px; color: #285d38; cursor: pointer; font-size: .8rem; font-weight: 800; }
  .order-route-details[open] { padding-bottom: 13px; }
  .order-route-details[open] > summary { margin-bottom: 12px; border-bottom: 1px solid #e7eee8; }
  .order-route-details .delivery-route-panel { margin: 0 12px; }
  .order-completed-details { margin-top: 14px; }
  .order-completed-details > summary { color: #3b6044; cursor: pointer; font-size: .82rem; font-weight: 800; }
  .order-completed-details[open] > summary { margin-bottom: 12px; }
  .rider-empty-state { padding: 28px 18px; border: 1px dashed #cbd9ce; border-radius: 18px; background: #fbfdfb; text-align: center; }
  .rider-empty-state-icon { margin-bottom: 8px; font-size: 2rem; }
  .rider-empty-state h3 { margin: 0; color: #284934; }
  .rider-empty-state p { margin: 5px 0 0; color: #718078; font-size: .83rem; }
  .rider-section-heading + .order-card { margin-top: 0; }
  .rider-section-heading + .rider-card { margin-top: 0; }
  .rider-history-table { width: 100%; border-collapse: collapse; min-width: 560px; }
  .rider-history-table th { padding: 10px; border-bottom: 1px solid #e8eee9; color: #69766f; font-size: .68rem; text-align: left; text-transform: uppercase; letter-spacing: .05em; }
  .rider-history-table td { padding: 10px; border-bottom: 1px solid #eef2ef; color: #34443a; font-size: .8rem; }
  .rider-history-table tbody tr:hover { background: #f8fbf8; }
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
  .delivery-route-panel {
    margin-top: 16px;
    padding: 14px;
    border: 1px solid #dfe9e2;
    border-radius: 14px;
    background: #f8fbf9;
  }
  .delivery-route-panel h4 { margin: 0 0 10px; color: #163d2d; }
  .delivery-route-map {
    width: 100%;
    height: 260px;
    border: 1px solid #dfe9e2;
    border-radius: 11px;
  }
  .delivery-route-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
    color: #52635a;
    font-size: .85rem;
  }
  .delivery-route-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
  .delivery-route-actions .btn { min-height: 42px; }
  .delivery-route-note { margin: 9px 0 0; color: #66736f; font-size: .78rem; }
  @media (max-width: 640px) {
    .rider-shell { padding: 16px 12px 40px; }
    .rider-header { align-items: flex-start; padding: 18px 15px; border-radius: 17px; }
    .rider-header-topline { align-items: flex-start; }
    .rider-header-logout { min-height: 38px; padding: 0 10px; font-size: .7rem; }
    .rider-summary-grid { gap: 8px; margin-bottom: 18px; }
    .rider-summary-card { flex-direction: column; align-items: flex-start; gap: 8px; padding: 11px 10px; border-radius: 13px; }
    .rider-summary-icon { width: 34px; height: 34px; flex-basis: 34px; border-radius: 11px; font-size: 1rem; }
    .rider-summary-copy strong { font-size: 1.05rem; }
    .rider-summary-copy span { font-size: .64rem; }
    .rider-card { padding: 14px; border-radius: 15px; }
    .order-card-topline { padding: 13px 12px 11px; }
    .order-card-body { padding: 12px; }
    .order-card-identity { gap: 8px; }
    .order-card-icon { width: 36px; height: 36px; flex-basis: 36px; }
    .order-card-title .order-number { font-size: .98rem; }
    .order-status { padding: 6px 8px; font-size: .61rem; }
    .order-card-grid { grid-template-columns: minmax(0,1fr) repeat(2,minmax(66px,.55fr)); gap: 6px; }
    .order-info-tile { padding: 8px; }
    .metric-label { font-size: .58rem; }
    .metric-value { font-size: .85rem; }
    .order-progress-step { font-size: .54rem; }
    .order-card-actions { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); }
    .order-card-actions .btn { min-width: 0; width: 100%; padding: 0 8px; font-size: .7rem; }
    .order-card-actions .order-primary-action { grid-column: 1/-1; }
    .rider-order-filters { gap: 6px; }
    .rider-order-filters button { min-height: 34px; padding: 0 10px; font-size: .67rem; }
    .delivery-route-panel { padding: 10px; }
    .delivery-route-map { height: 220px; }
    .rider-section-heading { align-items: center; }
  }
  @media(max-width:380px) {
    .rider-header { gap: 8px; }
    .rider-header h2 { font-size: 1.4rem; }
    .rider-header-logout { min-height: 36px; padding: 0 8px; font-size: .65rem; }
    .rider-summary-card { padding: 9px 8px; }
    .rider-summary-copy span { font-size: .59rem; }
    .order-status { max-width: 112px; overflow: hidden; text-overflow: ellipsis; }
  }
  @media(prefers-reduced-motion:reduce) { .order-card { animation: none; } }
</style>

<div class="rider-shell">
  <div class="rider-header">
    <div class="rider-header-copy">
      <p class="rider-header-kicker">Rider workspace</p>
      <div class="rider-header-topline">
        <h2>My deliveries</h2>
      </div>
      <p>Your active orders and delivery actions, all in one place.</p>
      <div class="rider-profile-strip">
        <span class="rider-avatar" aria-hidden="true">🛵</span>
        <span class="rider-profile-copy"><strong><?= h($rider['name']) ?></strong><small><?= h($rider['vehicle'] ?: 'Delivery partner') ?></small></span>
        <span class="rider-shift-pill<?= $openShift ? ' is-on' : '' ?>"><?= $openShift ? 'On shift' : 'Off shift' ?></span>
      </div>
    </div>
    <div class="rider-header-actions">
      <?php if ($rider): ?>
        <a class="rider-header-logout" href="<?= BASE_URL ?>/delivery/logout.php">🚪 Log out</a>
      <?php endif; ?>
    </div>
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
    <div class="rider-summary-grid" aria-label="Delivery summary">
      <div class="rider-summary-card"><span class="rider-summary-icon" aria-hidden="true">📦</span><span class="rider-summary-copy"><strong><?= count($orders) ?></strong><span>Active orders</span></span></div>
      <div class="rider-summary-card"><span class="rider-summary-icon" aria-hidden="true">🛵</span><span class="rider-summary-copy"><strong><?= $onRoadOrderCount ?></strong><span>On the way</span></span></div>
      <div class="rider-summary-card"><span class="rider-summary-icon" aria-hidden="true">✓</span><span class="rider-summary-copy"><strong><?= count($completedOrders) ?></strong><span>Recent deliveries</span></span></div>
    </div>

    <div class="rider-card rider-attendance-card">
      <div class="rider-topline">
        <div>
          <div class="eyebrow"><?= $openShift ? 'Shift in progress' : 'Start your shift' ?></div>
          <div class="metric-value"><?= $openShift ? 'Clocked in since ' . h(format_ist($openShift['clock_in_at'])) : 'Clock in to start accepting deliveries' ?></div>
          <div class="metric-label" style="margin-top:8px;">Elapsed this shift (hh:mm:ss)</div>
          <div class="metric-value" style="font-variant-numeric:tabular-nums;color:#0c6a4a;" <?= $openShift ? 'data-live-start="' . (new DateTimeImmutable($openShift['clock_in_at'], new DateTimeZone('UTC')))->getTimestamp() . '" data-auto-end="' . worker_attendance_end_at($openShift + $riderCompensation)->getTimestamp() . '"' : '' ?>>00:00:00</div>
          <?php if ($openShift): ?><p class="address-copy">Scheduled clock-out (IST): <?= h(format_ist(worker_attendance_end_at($openShift + $riderCompensation)->format('Y-m-d H:i:s'))) ?></p><?php endif; ?>
          <p class="address-copy">Clock in to accept and update delivery orders. Active deliveries must be completed before clocking out.<?= $riderCompensation['employment_type'] !== 'gig' ? ' A work summary is required at shift end.' : '' ?></p>
          <?php if ($openShift && $riderCompensation['employment_type'] !== 'gig'): ?>
            <?php if (!empty($openShift['overtime_status'])): ?>
              <p class="address-copy">Overtime request: <?= h(ucfirst($openShift['overtime_status'])) ?> · <?= (int)$openShift['overtime_minutes'] ?> minutes.</p>
            <?php else: ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <select data-overtime-minutes aria-label="Requested overtime duration"><option value="30">30 minutes</option><option value="60">1 hour</option><option value="120">2 hours</option></select>
                <button type="button" class="btn" data-attendance-action="request_overtime">Request overtime</button>
              </div>
              <p class="address-copy">Request overtime before your regular shift ends. It starts only after admin approval.</p>
            <?php endif; ?>
          <?php endif; ?>
          <p class="address-copy" data-auto-clockout-message aria-live="polite"></p>
        </div>
        <button type="button" class="btn btn-primary" data-attendance-action="<?= $openShift ? 'clock_out' : 'clock_in' ?>" data-requires-summary="<?= $openShift && $riderCompensation['employment_type'] !== 'gig' ? '1' : '0' ?>">
          <?= $openShift ? 'Clock Out' : 'Clock In' ?>
        </button>
      </div>
      <?php if ($attendanceHistory): ?>
        <details class="order-completed-details">
          <summary>View shift history (<?= count($attendanceHistory) ?>)</summary>
        <div style="overflow-x:auto;margin-top:12px;">
          <table style="width:100%;border-collapse:collapse;min-width:480px;">
            <thead><tr><th style="text-align:left;padding:9px;border-bottom:1px solid #e8eee9;">Shift start</th><th style="text-align:left;padding:9px;border-bottom:1px solid #e8eee9;">Shift end</th><th style="text-align:left;padding:9px;border-bottom:1px solid #e8eee9;">Hours</th><th style="text-align:left;padding:9px;border-bottom:1px solid #e8eee9;">Shift summary</th></tr></thead>
            <tbody>
            <?php foreach ($attendanceHistory as $shift):
              $shiftStart = new DateTimeImmutable($shift['clock_in_at'], new DateTimeZone('UTC'));
              $shiftEnd = $shift['clock_out_at']
                  ? new DateTimeImmutable($shift['clock_out_at'], new DateTimeZone('UTC'))
                  : new DateTimeImmutable('now', new DateTimeZone('UTC'));
              $shiftSeconds = max(0, $shiftEnd->getTimestamp() - $shiftStart->getTimestamp());
            ?>
              <tr>
                <td style="padding:9px;border-bottom:1px solid #eef2ef;"><?= h(format_ist($shift['clock_in_at'])) ?></td>
                <td style="padding:9px;border-bottom:1px solid #eef2ef;"><?= $shift['clock_out_at'] ? h(format_ist($shift['clock_out_at'])) : 'In progress' ?></td>
                <td style="padding:9px;border-bottom:1px solid #eef2ef;"><?= intdiv($shiftSeconds, 3600) ?>h <?= intdiv($shiftSeconds % 3600, 60) ?>m <?= $shiftSeconds % 60 ?>s</td>
                <td style="padding:9px;border-bottom:1px solid #eef2ef;"><?= nl2br(h($shift['shift_summary'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        </details>
      <?php endif; ?>
    </div>

    <div class="rider-section-heading">
      <div><h3>Active deliveries</h3><p>Your next step is highlighted on every order.</p></div>
      <span class="rider-count-pill"><?= count($orders) ?> <?= count($orders) === 1 ? 'order' : 'orders' ?></span>
    </div>
    <?php if ($orders): ?>
      <div class="rider-order-filters" role="group" aria-label="Filter active deliveries">
        <button type="button" class="is-active" data-order-filter="all" aria-pressed="true">All <span><?= count($orders) ?></span></button>
        <button type="button" data-order-filter="pickup" aria-pressed="false">To pick up <span><?= $assignedOrderCount ?></span></button>
        <button type="button" data-order-filter="on-road" aria-pressed="false">On the way <span><?= $onRoadOrderCount ?></span></button>
      </div>
    <?php endif; ?>
    <?php foreach ($orders as $order): ?>
      <?php
      $customerLat = $order['address_lat'] !== null ? (float)$order['address_lat'] : null;
      $customerLng = $order['address_lng'] !== null ? (float)$order['address_lng'] : null;
      $distanceKm = null;
      if ($customerLat !== null && $customerLng !== null && $order['delivery_lat'] !== null && $order['delivery_lng'] !== null) {
          $distanceKm = haversine_km((float)$order['delivery_lat'], (float)$order['delivery_lng'], $customerLat, $customerLng);
      }
      $eta = $distanceKm !== null ? estimate_eta_minutes($distanceKm) : 0;
      $progressStep = $order['order_status'] === 'delivery_partner_assigned'
          ? (!empty($order['rider_accepted_at']) ? 2 : 1)
          : 3;
      $statusText = get_order_status_options()[$order['order_status']] ?? ucfirst(str_replace('_', ' ', $order['order_status']));
      $orderStage = $order['order_status'] === 'delivery_partner_assigned' ? 'pickup' : 'on-road';
      ?>
      <article class="order-card" data-order-card="<?= (int)$order['id'] ?>" data-order-stage="<?= h($orderStage) ?>">
        <div class="order-card-topline">
          <div class="order-head">
            <div class="order-card-identity">
              <span class="order-card-icon" aria-hidden="true"><?= in_array($order['order_status'], ['out_for_delivery', 'arriving_soon'], true) ? '🛵' : '📦' ?></span>
              <div class="order-card-title">
            <div class="eyebrow">Order</div>
            <div class="order-number">#ORD-<?= str_pad($order['id'], 5, '0', STR_PAD_LEFT) ?></div>
              </div>
            </div>
            <div class="order-status" data-status="<?= h($order['order_status']) ?>"><?= h($statusText) ?></div>
          </div>
        </div>
        <div class="order-card-body">
          <div class="order-progress" aria-label="Order progress">
            <?php foreach (['Assigned', 'Pickup', 'On the way', 'Delivered'] as $stepIndex => $stepLabel): ?>
              <span class="order-progress-step<?= ($stepIndex + 1) < $progressStep ? ' is-complete' : (($stepIndex + 1) === $progressStep ? ' is-current' : '') ?>"><?= h($stepLabel) ?></span>
            <?php endforeach; ?>
          </div>
          <div class="order-card-grid">
            <div class="order-info-tile"><div class="metric-label">Customer</div><div class="metric-value"><?= h($order['customer_name']) ?></div></div>
            <div class="order-info-tile"><div class="metric-label">Distance</div><div class="metric-value"><?= $distanceKm !== null ? number_format($distanceKm, 1) . ' km' : 'Waiting' ?></div></div>
            <div class="order-info-tile"><div class="metric-label">ETA</div><div class="metric-value"><?= $eta > 0 ? $eta . ' min' : 'Pending' ?></div></div>
          </div>

          <p class="order-address"><span class="order-address-icon" aria-hidden="true">📍</span><span><?= nl2br(h($order['address'])) ?><?php if (!empty($order['delivery_area'])): ?><br><strong>Area:</strong> <?= h($order['delivery_area']) ?><?php endif; ?><?php if (!empty($order['delivery_pincode'])): ?> · <?= h($order['delivery_pincode']) ?><?php endif; ?></span></p>
          <?php if (!empty($order['customer_note'])): ?>
            <p class="address-copy" style="padding:10px 12px;background:#fff8e6;border-radius:10px;color:#684e14;font-size:.8rem;"><strong>📝 Customer note:</strong><br><?= nl2br(h($order['customer_note'])) ?></p>
          <?php endif; ?>

          <?php if (in_array($order['order_status'], ['out_for_delivery', 'arriving_soon'], true)): ?>
            <details class="order-route-details" data-route-details>
              <summary>🗺️ Live map &amp; location sharing</summary>
              <section class="delivery-route-panel" aria-label="Live delivery route">
                <h4>Live delivery route</h4>
                <div class="delivery-route-map" data-delivery-map data-map-order-id="<?= (int)$order['id'] ?>" data-address-lat="<?= $customerLat !== null ? $customerLat : '' ?>" data-address-lng="<?= $customerLng !== null ? $customerLng : '' ?>"></div>
                <div class="delivery-route-meta">
                  <span data-location-status aria-live="polite">Live location sharing is off.</span>
                  <span>Distance: <strong data-route-distance><?= $distanceKm !== null ? number_format($distanceKm, 1) . ' km' : 'Waiting for GPS' ?></strong> · ETA: <strong data-route-eta><?= $eta > 0 ? $eta . ' min (approx.)' : 'Waiting' ?></strong></span>
                </div>
                <div class="delivery-route-actions">
                  <button type="button" class="btn btn-primary" data-location-order="<?= (int)$order['id'] ?>">Share live location</button>
                </div>
                <p class="delivery-route-note">Keep this page open while delivering. Customers can see your latest location while sharing is on. The map line is approximate; driving directions open in Google Maps.</p>
              </section>
            </details>
          <?php endif; ?>

          <div class="order-card-actions">
          <?php if ($order['order_status'] === 'delivery_partner_assigned'): ?>
            <?php if (empty($order['rider_accepted_at'])): ?>
              <button type="button" class="btn btn-primary order-primary-action" data-order-id="<?= (int)$order['id'] ?>" data-action="accept">✓ Accept order</button>
            <?php else: ?>
              <button type="button" class="btn btn-primary order-primary-action" data-order-id="<?= (int)$order['id'] ?>" data-action="pickup">📦 Confirm pickup</button>
            <?php endif; ?>
            <button type="button" class="btn order-danger-action" data-order-id="<?= (int)$order['id'] ?>" data-action="reject">Decline</button>
          <?php elseif ($order['order_status'] === 'out_for_delivery'): ?>
            <button type="button" class="btn btn-primary order-primary-action" data-order-id="<?= (int)$order['id'] ?>" data-action="arriving">📍 Mark arriving</button>
            <button type="button" class="btn order-secondary-action" data-order-id="<?= (int)$order['id'] ?>" data-action="deliver">✓ Delivered</button>
          <?php elseif ($order['order_status'] === 'arriving_soon'): ?>
            <button type="button" class="btn btn-primary order-primary-action" data-order-id="<?= (int)$order['id'] ?>" data-action="deliver">✓ Mark delivered</button>
          <?php endif; ?>
          <a class="btn order-secondary-action" href="tel:<?= h($order['phone']) ?>">📞 Call customer</a>
          <a class="btn order-secondary-action" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= rawurlencode($customerLat !== null && $customerLng !== null ? $customerLat . ',' . $customerLng : $order['address'] . ' ' . ($order['delivery_pincode'] ?? '')) ?>&amp;travelmode=driving" target="_blank" rel="noopener">🧭 Navigate</a>
          <a class="btn order-secondary-action" href="../track_order.php?order_id=<?= (int)$order['id'] ?>" target="_blank" rel="noopener">Track order</a>
          </div>
          <p class="order-feedback" data-order-feedback role="status" aria-live="polite"></p>
        </div>
      </article>
    <?php endforeach; ?>

    <?php if (empty($orders)): ?>
      <div class="rider-empty-state">
        <div class="rider-empty-state-icon" aria-hidden="true">✨</div>
        <h3>You're all caught up</h3>
        <p>New delivery assignments will appear here automatically.</p>
      </div>
    <?php endif; ?>

    <details class="rider-card order-completed-details">
      <summary>✅ Recent deliveries <span class="rider-count-pill"><?= count($completedOrders) ?></span></summary>
      <?php if ($completedOrders): ?>
        <div style="overflow-x:auto;">
          <table class="rider-history-table">
            <thead><tr><th>Order</th><th>Customer</th><th>Delivered</th><th style="text-align:right;">Total</th></tr></thead>
            <tbody>
            <?php foreach ($completedOrders as $completed): ?>
              <tr>
                <td>#ORD-<?= str_pad((string)$completed['id'], 5, '0', STR_PAD_LEFT) ?></td>
                <td><?= h($completed['customer_name']) ?></td>
                <td><?= h(format_ist($completed['delivered_at'])) ?></td>
                <td style="text-align:right;"><?= h(SITE_CURRENCY . number_format((float)$completed['total_amount'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="address-copy">No completed deliveries yet.</p>
      <?php endif; ?>
    </details>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('[data-attendance-action]').forEach((button) => {
  button.addEventListener('click', async () => {
    let shiftSummary = '';
    if (button.dataset.attendanceAction === 'clock_out' && button.dataset.requiresSummary === '1') {
      shiftSummary = prompt('Enter a short summary of your work and completed deliveries:') || '';
      if (!shiftSummary.trim()) {
        alert('A shift summary is required before clocking out.');
        return;
      }
    }
    button.disabled = true;
    try {
      await submitAttendanceAction(button.dataset.attendanceAction, shiftSummary);
    } catch (error) {
      alert(error.message || 'Network error while recording attendance.');
      button.disabled = false;
    }
  });
});

async function submitAttendanceAction(action, shiftSummary) {
  const overtimeSelect = document.querySelector('[data-overtime-minutes]');
  const response = await fetch('../ajax/worker_attendance_action.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action,
      shift_summary: shiftSummary,
      requested_minutes: overtimeSelect ? overtimeSelect.value : null,
      csrf_token: '<?= h(csrf_token()) ?>'
    })
  });
  const data = await response.json();
  if (!response.ok || !data.success) throw new Error(data.error || 'Attendance could not be saved.');
  if (action === 'auto_clock_out' && !data.closed) {
    const activeShiftClock = document.querySelector('[data-auto-end]');
    if (data.next_end && activeShiftClock) {
      activeShiftClock.dataset.autoEnd = String(data.next_end);
      autoClockOutSent = false;
      return;
    }
    const message = document.querySelector('[data-auto-clockout-message]');
    if (message) message.textContent = 'Shift limit reached. Complete your active delivery to clock out automatically.';
    return;
  }
  location.reload();
}

function updateAttendanceClocks() {
  const nowSeconds = Math.floor(Date.now() / 1000);
  document.querySelectorAll('[data-live-start]').forEach((clock) => {
    const elapsed = Math.max(0, nowSeconds - Number(clock.dataset.liveStart));
    const hours = Math.floor(elapsed / 3600);
    const minutes = Math.floor((elapsed % 3600) / 60);
    const seconds = elapsed % 60;
    clock.textContent = String(hours).padStart(2, '0') + ':' +
      String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
  });
}
updateAttendanceClocks();
window.setInterval(updateAttendanceClocks, 1000);
let autoClockOutSent = false;
window.setInterval(() => {
  const activeShiftClock = document.querySelector('[data-auto-end]');
  if (!activeShiftClock || autoClockOutSent || Date.now() < Number(activeShiftClock.dataset.autoEnd) * 1000) return;
  autoClockOutSent = true;
  submitAttendanceAction('auto_clock_out', '').catch((error) => {
    autoClockOutSent = false;
    console.error(error);
  });
}, 15000);

document.querySelectorAll('[data-order-filter]').forEach((filterButton) => {
  filterButton.addEventListener('click', () => {
    const filter = filterButton.dataset.orderFilter;
    document.querySelectorAll('[data-order-filter]').forEach((button) => {
      const active = button === filterButton;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', String(active));
    });
    document.querySelectorAll('[data-order-card]').forEach((card) => {
      card.hidden = filter !== 'all' && card.dataset.orderStage !== filter;
    });
  });
});

async function runDeliveryAction(button, label) {
  const {orderId, action} = button.dataset;
  const card = button.closest('[data-order-card]');
  const feedback = card.querySelector('[data-order-feedback]');
  const actionButtons = Array.from(card.querySelectorAll('button[data-order-id]'));

  if (action === 'reject' && !window.confirm('Decline this delivery assignment?')) return;
  if (action === 'deliver' && !window.confirm('Confirm that this order has been delivered?')) return;

  actionButtons.forEach((actionButton) => { actionButton.disabled = true; });
  button.dataset.previousLabel = button.textContent;
  button.textContent = 'Saving…';
  feedback.classList.remove('is-success');
  feedback.textContent = '';

  try {
    const response = await fetch('../ajax/rider_delivery_action.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({order_id: Number(orderId), action, csrf_token: '<?= h(csrf_token()) ?>'})
    });
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      console.error('Unexpected rider action response:', text);
      throw new Error('The server returned an unexpected response. Please try again.');
    }
    if (!response.ok || !data.success) throw new Error(data.error || 'The delivery action could not be completed.');

    feedback.classList.add('is-success');
    feedback.textContent = label + ' updated successfully.';
    if (data.customer_notification && data.customer_notification !== 'sent') {
      feedback.textContent += data.customer_notification === 'missing_email'
        ? ' The order has no valid customer email address, so an email could not be sent.'
        : ' The customer status email could not be sent. Please check the mail configuration.';
    }
    window.setTimeout(() => window.location.reload(), 900);
  } catch (error) {
    feedback.textContent = error.message || 'Network error while updating this delivery.';
    actionButtons.forEach((actionButton) => { actionButton.disabled = false; });
    button.textContent = button.dataset.previousLabel;
  }
}

document.querySelectorAll('[data-order-id]').forEach((button) => {
  button.addEventListener('click', () => {
    const action = button.dataset.action;
    const labels = {
      accept: 'Order accepted',
      pickup: 'Pickup confirmed',
      reject: 'Assignment rejected',
      arriving: 'Arriving soon',
      deliver: 'Order marked delivered'
    };
    runDeliveryAction(button, labels[action] || 'Order updated');
  });
});
</script>

<script>
(() => {
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  let watchId = null;
  let activeOrderId = null;
  let lastSentAt = 0;
  const routeMaps = new Map();

  document.querySelectorAll('[data-delivery-map]').forEach((element) => {
    const map = L.map(element).setView([18.5011, 73.9268], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
      maxZoom: 19
    }).addTo(map);
    const addressLat = Number(element.dataset.addressLat);
    const addressLng = Number(element.dataset.addressLng);
    let addressMarker = null;
    if (Number.isFinite(addressLat) && Number.isFinite(addressLng) && element.dataset.addressLat !== '' && element.dataset.addressLng !== '') {
      addressMarker = L.marker([addressLat, addressLng]).addTo(map).bindPopup('Customer delivery address');
      map.setView([addressLat, addressLng], 14);
    }
    routeMaps.set(Number(element.dataset.mapOrderId), {map, addressLat, addressLng, addressMarker, riderMarker: null, routeLine: null, element, mapFramed: false});
  });

  document.querySelectorAll('[data-route-details]').forEach((details) => {
    details.addEventListener('toggle', () => {
      if (!details.open) return;
      const mapElement = details.querySelector('[data-delivery-map]');
      const route = mapElement ? routeMaps.get(Number(mapElement.dataset.mapOrderId)) : null;
      if (route) window.requestAnimationFrame(() => route.map.invalidateSize());
    });
  });

  function distanceKm(lat1, lng1, lat2, lng2) {
    const rad = value => value * Math.PI / 180;
    const dLat = rad(lat2 - lat1);
    const dLng = rad(lng2 - lng1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(rad(lat1)) * Math.cos(rad(lat2)) * Math.sin(dLng / 2) ** 2;
    return 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function stopSharing(button, message) {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    watchId = null;
    activeOrderId = null;
    button.textContent = 'Share live location';
    button.disabled = false;
    button.dataset.sharing = '0';
    const panel = button.closest('.delivery-route-panel');
    panel.querySelector('[data-location-status]').textContent = message;
  }

  function updatePosition(position, button, orderId) {
    if (activeOrderId !== orderId) return;
    const {latitude: lat, longitude: lng} = position.coords;
    const route = routeMaps.get(orderId);
    if (!route) return;
    const point = [lat, lng];
    if (!route.riderMarker) {
      route.riderMarker = L.marker(point).addTo(route.map).bindPopup('Your location');
    } else {
      route.riderMarker.setLatLng(point);
    }
    if (route.addressMarker) {
      if (route.routeLine) {
        route.routeLine.setLatLngs([point, [route.addressLat, route.addressLng]]);
      } else {
        route.routeLine = L.polyline([point, [route.addressLat, route.addressLng]], {color: '#21845b', weight: 4, opacity: .8, dashArray: '7 8'}).addTo(route.map);
      }
      if (!route.mapFramed) {
        route.map.fitBounds(L.latLngBounds([point, [route.addressLat, route.addressLng]]), {padding: [28, 28]});
        route.mapFramed = true;
      } else if (!route.map.getBounds().contains(point)) {
        route.map.panTo(point);
      }
      const distance = distanceKm(lat, lng, route.addressLat, route.addressLng);
      const panel = button.closest('.delivery-route-panel');
      panel.querySelector('[data-route-distance]').textContent = distance.toFixed(1) + ' km';
      panel.querySelector('[data-route-eta]').textContent = Math.max(4, Math.round(distance / 24 * 60)) + ' min (approx.)';
    } else {
      route.map.panTo(point);
    }

    const now = Date.now();
    if (now - lastSentAt < 8000) return;
    lastSentAt = now;
    const data = new FormData();
    data.append('order_id', String(orderId));
    data.append('lat', String(lat));
    data.append('lng', String(lng));
    data.append('csrf_token', csrfToken);
    fetch('../ajax/update_location.php', {method: 'POST', body: data})
      .then(async response => {
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Location could not be shared.');
        button.closest('.delivery-route-panel').querySelector('[data-location-status]').textContent = 'Sharing live location · updated just now';
      })
      .catch(error => {
        button.closest('.delivery-route-panel').querySelector('[data-location-status]').textContent = error.message || 'Could not share location. Check your connection.';
      });
  }

  document.querySelectorAll('[data-location-order]').forEach((button) => {
    button.addEventListener('click', () => {
      if (button.dataset.sharing === '1') {
        stopSharing(button, 'Location sharing stopped.');
        return;
      }
      const orderId = Number(button.dataset.locationOrder);
      if (watchId !== null) {
        const previousButton = document.querySelector('[data-location-order][data-sharing="1"]');
        if (previousButton) stopSharing(previousButton, 'Location sharing stopped.');
      }
      if (!navigator.geolocation) {
        const status = button.closest('.delivery-route-panel').querySelector('[data-location-status]');
        status.textContent = window.isSecureContext
          ? 'GPS is not supported by this browser.'
          : 'Live GPS requires a secure HTTPS connection. Open the rider page on the secure website.';
        return;
      }
      activeOrderId = orderId;
      lastSentAt = 0;
      button.dataset.sharing = '1';
      button.textContent = 'Stop sharing';
      button.closest('.delivery-route-panel').querySelector('[data-location-status]').textContent = 'Waiting for GPS permission…';
      watchId = navigator.geolocation.watchPosition(
        position => updatePosition(position, button, orderId),
        error => {
          const message = error.code === 1
            ? 'Location permission is needed to share your live route. Allow location access in your browser settings.'
            : error.code === 3
              ? 'GPS timed out. Move to an open area and try again.'
              : 'Unable to get your location: ' + error.message;
          stopSharing(button, message);
        },
        {enableHighAccuracy: true, maximumAge: 5000, timeout: 20000}
      );
    });
  });
})();
</script>

<?php
$orderLiveRefreshScope = 'rider';
$orderLiveRefreshVersion = $orderRefreshVersion;
include __DIR__ . '/../includes/footer.php';
?>
