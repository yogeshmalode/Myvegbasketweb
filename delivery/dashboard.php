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
    $stmt = $pdo->prepare("SELECT o.id, o.customer_name, o.phone, o.address, o.address_lat, o.address_lng, o.delivery_lat, o.delivery_lng, o.order_status, o.payment_status, o.rider_id, o.rider_accepted_at, o.assigned_picker_id, o.total_amount, o.created_at FROM orders o WHERE o.rider_id = ? AND o.order_status IN ('delivery_partner_assigned', 'out_for_delivery', 'arriving_soon') ORDER BY o.created_at DESC");
    $stmt->execute([$riderId]);
    $orders = $stmt->fetchAll();
}
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

    <div class="rider-card">
      <div class="rider-topline">
        <div>
          <div class="eyebrow">Attendance</div>
          <div class="metric-value"><?= $openShift ? 'Clocked in since ' . h(format_ist($openShift['clock_in_at'])) : 'Not clocked in' ?></div>
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
      <?php endif; ?>
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
          <?php if ($order['order_status'] === 'delivery_partner_assigned'): ?>
            <?php if (empty($order['rider_accepted_at'])): ?>
              <button type="button" class="btn btn-primary" data-order-id="<?= (int)$order['id'] ?>" data-action="accept">Accept Order</button>
            <?php else: ?>
              <button type="button" class="btn btn-primary" data-order-id="<?= (int)$order['id'] ?>" data-action="pickup">Confirm Pickup</button>
            <?php endif; ?>
            <button type="button" class="btn" data-order-id="<?= (int)$order['id'] ?>" data-action="reject">Reject</button>
          <?php elseif ($order['order_status'] === 'out_for_delivery'): ?>
            <button type="button" class="btn btn-primary" data-order-id="<?= (int)$order['id'] ?>" data-action="arriving">Arriving Soon</button>
            <button type="button" class="btn" data-order-id="<?= (int)$order['id'] ?>" data-action="deliver">Mark Delivered</button>
          <?php elseif ($order['order_status'] === 'arriving_soon'): ?>
            <button type="button" class="btn btn-primary" data-order-id="<?= (int)$order['id'] ?>" data-action="deliver">Mark Delivered</button>
          <?php endif; ?>
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

    <div class="rider-card">
      <div class="rider-topline"><div><div class="eyebrow">Completed deliveries</div><h3 class="rider-name"><?= count($completedOrders) ?> recent orders</h3></div></div>
      <?php if ($completedOrders): ?>
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:560px;">
            <thead><tr><th style="text-align:left;padding:10px;border-bottom:1px solid #e8eee9;">Order</th><th style="text-align:left;padding:10px;border-bottom:1px solid #e8eee9;">Customer</th><th style="text-align:left;padding:10px;border-bottom:1px solid #e8eee9;">Delivered</th><th style="text-align:right;padding:10px;border-bottom:1px solid #e8eee9;">Total</th></tr></thead>
            <tbody>
            <?php foreach ($completedOrders as $completed): ?>
              <tr>
                <td style="padding:10px;border-bottom:1px solid #eef2ef;">#ORD-<?= str_pad((string)$completed['id'], 5, '0', STR_PAD_LEFT) ?></td>
                <td style="padding:10px;border-bottom:1px solid #eef2ef;"><?= h($completed['customer_name']) ?></td>
                <td style="padding:10px;border-bottom:1px solid #eef2ef;"><?= h(format_ist($completed['delivered_at'])) ?></td>
                <td style="padding:10px;border-bottom:1px solid #eef2ef;text-align:right;"><?= h(SITE_CURRENCY . number_format((float)$completed['total_amount'], 2)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="address-copy">No completed deliveries yet.</p>
      <?php endif; ?>
    </div>
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

function runDeliveryAction(orderId, action, label) {
  fetch('../ajax/rider_delivery_action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ order_id: Number(orderId), action: action, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
    const text = await r.text();
    try {
      const data = JSON.parse(text);
      if (data.success) {
        if (data.customer_notification && data.customer_notification !== 'sent') {
          alert(data.customer_notification === 'missing_email'
            ? 'Action completed, but this order has no valid customer email address.'
            : 'Action completed, but the customer status email could not be sent. Check Hostinger mail/SMTP configuration.');
        }
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
    const labels = {
      accept: 'Order accepted',
      pickup: 'Pickup confirmed',
      reject: 'Assignment rejected',
      arriving: 'Arriving soon',
      deliver: 'Order marked delivered'
    };
    runDeliveryAction(orderId, action, labels[action] || 'Order updated');
  });
});
</script>

<?php
$orderLiveRefreshScope = 'rider';
$orderLiveRefreshVersion = $orderRefreshVersion;
include __DIR__ . '/../includes/footer.php';
?>
