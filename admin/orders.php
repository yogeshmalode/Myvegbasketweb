<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Orders';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Staff/delivery accounts tied to one store only ever see that store's
// orders (plus any order whose store couldn't be auto-resolved yet, e.g.
// geocoding failed) — Admin role always sees every order across all stores.
$restrictedStoreId = session_store_id();
$orderParams = [];
$orderWhere = '';
if ($restrictedStoreId !== null) {
    $orderWhere = 'WHERE o.dark_store_id = ? OR o.dark_store_id IS NULL';
    $orderParams[] = $restrictedStoreId;
}

$ordersSt = $pdo->prepare("
    SELECT o.*, r.name AS rider_name, r.phone AS rider_phone, ds.name AS dark_store_name, pk.username AS assigned_picker_name
    FROM orders o
    LEFT JOIN riders r ON r.id = o.rider_id
    LEFT JOIN dark_stores ds ON ds.id = o.dark_store_id
    LEFT JOIN admins pk ON pk.id = o.assigned_picker_id
    $orderWhere
    ORDER BY o.created_at DESC
");
$ordersSt->execute($orderParams);
$orders = $ordersSt->fetchAll();

$riders = $pdo->query("SELECT id, name FROM riders WHERE is_active = 1 ORDER BY name")->fetchAll();

// Packers/pickers (same pool used by the old standalone Fulfillment Center
// page, now folded into the New/Processing tabs below so packer assignment
// and the batch picking sheet can be done without leaving this page).
$pickerAdmins = $pdo->query("SELECT id, username FROM admins WHERE role IN ('admin','staff') ORDER BY username")->fetchAll();
$pickerMap = [];
foreach ($pickerAdmins as $pk) { $pickerMap[(int)$pk['id']] = $pk['username']; }

// Pull every order's line items in one query and group them by order_id,
// so the admin can see exactly which vegetables (and how much of each)
// were ordered, right on each order card.
$itemsByOrder = [];
$itemRows = $pdo->query("SELECT oi.id, oi.order_id, oi.name, oi.quantity, oi.subtotal, oi.variant_label FROM order_items oi ORDER BY oi.id")->fetchAll();
foreach ($itemRows as $row) {
    $itemsByOrder[$row['order_id']][] = $row;
}

$orderStatusOptions = get_order_status_options();

// Inline colors so the badges are always colored, even if the CSS file on
// the server hasn't been updated yet or is browser-cached.
$colorMap = [
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
function status_color_style($value, $colorMap) {
    $c = $colorMap[$value] ?? ['bg' => '#fff', 'fg' => '#26301F'];
    return "background:{$c['bg']}; color:{$c['fg']};";
}

// ---- Bucket every order into one of the lifecycle-stage tabs ----
// This mirrors get_allowed_order_status_transitions() in config.php, just
// grouped into the stages an admin actually thinks in day-to-day.
//
// Billing/POS counter sales (source = 'pos') are walk-in customers who
// already paid and left with their bag — they never need rider assignment
// or a delivery workflow. Mixing them into the same New/Processing/Ready
// for Dispatch/Out for Delivery tabs as real online orders was exactly
// what made rider assignment confusing, so POS bills get their own
// dedicated tab instead and never enter the online delivery pipeline tabs.
$tabs = [
    'new'        => ['label' => '🆕 New',               'orders' => []],
    'processing' => ['label' => '📦 Processing',         'orders' => []],
    'ready'      => ['label' => '🚀 Ready for Dispatch',  'orders' => []],
    'transit'    => ['label' => '🛵 Out for Delivery',    'orders' => []],
    'completed'  => ['label' => '✅ Completed',           'orders' => []],
    'exceptions' => ['label' => '⚠️ Exceptions',          'orders' => []],
    'pos'        => ['label' => '🧾 POS Bills',           'orders' => []],
    'b2b'        => ['label' => '🏨 B2B Orders',          'orders' => []],
];

$todayRevenue = 0;
$cancelledToday = 0;
$delayedCount = 0;
$today = date('Y-m-d');

foreach ($orders as $o) {
    $status = normalize_order_status($o['order_status']);
    $isPos = ($o['source'] ?? 'online') === 'pos';
    $isB2BSource = ($o['source'] ?? 'online') === 'b2b';
    $isB2BCustomer = in_array($o['customer_type'] ?? 'retail', ['hotel', 'shop'], true);

    if ($isB2BSource || $isB2BCustomer) {
        // Every Hotel/Shop order lands here regardless of how it was
        // placed, so admins never have to hunt for B2B activity mixed in
        // with regular retail orders.
        $tabs['b2b']['orders'][] = $o;
    }

    if ($isB2BSource) {
        // Manually billed via B2B Billing — already paid/invoiced on the
        // spot, so (like a POS counter sale) it never needs rider/delivery
        // handling and stops here.
        continue;
    } elseif ($isPos) {
        // Counter sales never need rider/delivery handling — keep them
        // entirely separate from the online order lifecycle tabs.
        $tabs['pos']['orders'][] = $o;
    } else {
        // Self-service storefront orders (including wholesale orders
        // placed by a logged-in Hotel/Shop customer) still need real
        // delivery, so they also flow through the normal lifecycle tabs
        // below in addition to showing up in the B2B tab above.
        $isException = $status === 'cancelled' || $o['payment_status'] === 'failed';

        if ($isException) {
            $tabs['exceptions']['orders'][] = $o;
        } elseif (in_array($status, ['pending', 'placed'], true)) {
            $tabs['new']['orders'][] = $o;
        } elseif ($status === 'processing') {
            $tabs['processing']['orders'][] = $o;
        } elseif (in_array($status, ['ready_for_pickup', 'assigning_rider', 'delivery_partner_assigned'], true)) {
            $tabs['ready']['orders'][] = $o;
        } elseif (in_array($status, ['out_for_delivery', 'arriving_soon'], true)) {
            $tabs['transit']['orders'][] = $o;

            // Flag as delayed if we're past the ETA estimated from when the
            // order last changed status (updated_at is bumped on every
            // transition, including the move into out_for_delivery).
            if ($o['eta_minutes'] !== null && $o['updated_at']) {
                $minutesSince = (time() - strtotime($o['updated_at'])) / 60;
                if ($minutesSince > (float)$o['eta_minutes']) {
                    $delayedCount++;
                }
            }
        } elseif ($status === 'delivered') {
            $tabs['completed']['orders'][] = $o;
        }
    }

    if ($status === 'cancelled' && substr((string)$o['updated_at'], 0, 10) === $today) {
        $cancelledToday++;
    }
    if ($status === 'delivered' && substr((string)$o['delivered_at'], 0, 10) === $today) {
        $todayRevenue += (float)$o['total_amount'];
    }
    if ($isPos && $status !== 'cancelled' && substr((string)$o['created_at'], 0, 10) === $today) {
        $todayRevenue += (float)$o['total_amount'];
    }
}

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .orders-page-shell { background: transparent; }
  .orders-page-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 16px; flex-wrap: wrap; margin: 0 0 16px;
  }
  .orders-page-header h2 {
    margin: 0; color: #0c5a42; font-size: clamp(1.5rem, 2vw, 2.2rem);
    line-height: 1.2; letter-spacing: -0.03em; font-weight: 800;
  }
  .orders-page-header h2::after {
    content: ""; display: block; width: 100%; max-width: 120px; height: 3px;
    border-radius: 999px; background: linear-gradient(90deg, #f0a26f 0%, #f0a26f 100%);
    margin-top: 8px; opacity: 0.9;
  }
  .orders-page-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .orders-page-actions .btn {
    min-height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid #dfe9df;
    background: rgba(255,255,255,0.85); color: #1f2e2a; font-weight: 700; font-size: 0.76rem;
    box-shadow: 0 1px 0 rgba(18, 41, 34, 0.04);
  }
  .orders-page-actions .btn:hover { background: #f6faf7; border-color: #cfe0ce; }

  /* ---- KPI strip ---- */
  .kpi-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; }
  .kpi-chip {
    background: #fff; border: 1px solid #dfe8df; border-radius: 14px;
    padding: 10px 16px; font-size: 0.78rem; font-weight: 700; color: #26372f;
    box-shadow: 0 6px 16px rgba(18, 52, 40, 0.04);
    display: flex; align-items: center; gap: 8px;
  }
  .kpi-chip .kpi-value { font-size: 0.95rem; font-weight: 900; color: #0c5a42; }
  .kpi-chip.warn .kpi-value { color: #B25B00; }
  .kpi-chip.danger .kpi-value { color: #9A2E24; }

  /* ---- Tab bar ---- */
  .order-tabs {
    display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 18px;
    background: #fff; border: 1px solid #dfe8df; border-radius: 16px; padding: 6px;
    box-shadow: 0 6px 16px rgba(18, 52, 40, 0.04);
  }
  .order-tab-btn {
    border: none; background: transparent; padding: 10px 16px; border-radius: 12px;
    font-weight: 800; font-size: 0.8rem; color: #4a5a52; cursor: pointer;
    display: flex; align-items: center; gap: 6px;
  }
  .order-tab-btn:hover { background: #f3f8f4; }
  .order-tab-btn.active { background: #0c5a42; color: #fff; }
  .order-tab-count { background: rgba(0,0,0,0.08); border-radius: 999px; padding: 1px 8px; font-size: 0.72rem; }
  .order-tab-btn.active .order-tab-count { background: rgba(255,255,255,0.25); }
  .order-tab-btn[data-tab="exceptions"] .order-tab-count { background: #FCE8E6; color: #9A2E24; }

  .order-tab-panel { display: none; }
  .order-tab-panel.active { display: block; }

  /* ---- Picking toolbar + packer assignment (merged from Fulfillment Center) ---- */
  .picking-toolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
    background: #fff; border: 1px solid #dfe8df; border-radius: 14px; padding: 10px 16px; margin-bottom: 14px;
    box-shadow: 0 6px 16px rgba(18, 52, 40, 0.04);
  }
  .picking-toolbar span { font-size: 0.8rem; font-weight: 700; color: #4a5a52; }
  .picking-toolbar .btn { min-height: 36px; padding: 0 14px; border-radius: 999px; font-weight: 800; font-size: 0.78rem; border: 1px solid #0c5a42; background: #0c5a42; color: #fff; cursor: pointer; }
  .picking-toolbar .btn:disabled { opacity: 0.45; cursor: not-allowed; }
  .order-card-select { position: absolute; top: 14px; left: 14px; width: 17px; height: 17px; cursor: pointer; }
  .order-card.has-select { position: relative; padding-left: 36px; }
  .order-card-picker { margin-top: 8px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .order-card-picker select { min-height: 32px; border-radius: 8px; border: 1px solid #d5dfd7; font-size: 0.74rem; padding: 0 6px; flex: 1; min-width: 120px; }
  .order-card-picker .btn { min-height: 32px; padding: 0 10px; font-size: 0.72rem; }
  .order-card-picker-note { font-size: 0.7rem; color: #1c5f46; font-weight: 700; margin-top: 4px; }

  /* ---- Kanban card grid ---- */
  .order-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 14px; }
  .order-card {
    background: #fff; border: 1px solid #dfe8df; border-radius: 16px; padding: 14px 16px;
    box-shadow: 0 6px 16px rgba(18, 52, 40, 0.05); border-left: 4px solid #cfe0ce;
  }
  .order-card.urgent { border-left-color: #D64545; }
  .order-card.warn { border-left-color: #E0A030; }
  .order-card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
  .order-card-id { font-weight: 900; color: #15241e; font-size: 0.95rem; }
  .order-card-time { font-size: 0.7rem; color: #74847c; }
  .order-card-customer { font-weight: 700; color: #1c2d2b; font-size: 0.85rem; }
  .order-card-sub { font-size: 0.74rem; color: #5d6e65; margin-top: 2px; }
  .order-card-badge {
    display: inline-block; font-size: 0.68rem; font-weight: 800; padding: 3px 9px;
    border-radius: 999px; margin-top: 8px;
  }
  .order-card-items { margin-top: 8px; font-size: 0.74rem; color: #44544c; }
  .order-card-items summary { cursor: pointer; font-weight: 700; color: #1c5f46; }
  .order-card-total { font-weight: 900; color: #1a4e3c; font-size: 0.92rem; margin-top: 8px; }
  .order-card-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
  .order-card-actions .btn {
    min-height: 34px; padding: 0 12px; border-radius: 999px; font-size: 0.74rem; font-weight: 800;
    border: 1px solid #cfe0ce; background: #eef5f1; color: #1c4e3d; cursor: pointer;
  }
  .order-card-actions .btn.primary { background: #0c5a42; color: #fff; border-color: #0c5a42; }
  .order-card-actions .btn.danger { background: #FCE8E6; color: #9A2E24; border-color: #f4cfc9; }
  .order-card-actions select { min-height: 34px; border-radius: 8px; border: 1px solid #d5dfd7; font-size: 0.76rem; padding: 0 8px; }
  .order-card-empty { grid-column: 1 / -1; text-align: center; color: #5B6656; padding: 30px; }

  /* ---- Completed tab: compact table ---- */
  .table-wrap { overflow-x: auto; background: #fff; border-radius: 20px; border: 1px solid #dfe8df; box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05); }
  .orders-table { width: 100%; border-collapse: collapse; min-width: 900px; }
  .orders-table thead th {
    background: #dfe9dc; color: #1b342d; font-size: 0.7rem; letter-spacing: 0.04em;
    text-transform: uppercase; font-weight: 800; padding: 12px; border-bottom: 1px solid #cfe1ce; text-align: left;
  }
  .orders-table tbody td { padding: 12px; vertical-align: top; border-bottom: 1px solid #edf1ed; color: #1e2c28; font-size: 0.8rem; }
  .orders-table tbody tr:hover { background: #fafdf9; }
  .completed-search { min-height: 40px; border-radius: 10px; border: 1px solid #d5dfd7; padding: 0 14px; margin-bottom: 12px; width: 100%; max-width: 320px; font-size: 0.82rem; }

  /* ---- Exceptions inline form ---- */
  .exception-form { margin-top: 10px; display: flex; flex-direction: column; gap: 6px; }
  .exception-form input, .exception-form select { min-height: 32px; border-radius: 8px; border: 1px solid #d5dfd7; padding: 0 8px; font-size: 0.76rem; }

  @media (max-width: 900px) {
    .orders-page-header { align-items: flex-start; }
    .orders-page-header h2 { font-size: 2.3rem; }
    .orders-page-header h2::after { width: 72%; }
  }
</style>

<div class="orders-page-shell">
  <div class="orders-page-header">
    <h2>Orders</h2>
    <div class="orders-page-actions">
      <a href="export_orders.php" class="btn">⬇ Export Excel (CSV)</a>
      <a href="export_orders_pdf.php" class="btn">⬇ Download PDF</a>
    </div>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="kpi-strip">
  <div class="kpi-chip">🆕 New <span class="kpi-value"><?= count($tabs['new']['orders']) ?></span></div>
  <div class="kpi-chip">📦 Processing <span class="kpi-value"><?= count($tabs['processing']['orders']) ?></span></div>
  <div class="kpi-chip <?= count($tabs['ready']['orders']) > 0 ? 'warn' : '' ?>">🚀 Awaiting Rider <span class="kpi-value"><?= count($tabs['ready']['orders']) ?></span></div>
  <div class="kpi-chip">🛵 Active Deliveries <span class="kpi-value"><?= count($tabs['transit']['orders']) ?></span></div>
  <div class="kpi-chip <?= $delayedCount > 0 ? 'danger' : '' ?>">⏱ Delayed <span class="kpi-value"><?= $delayedCount ?></span></div>
  <div class="kpi-chip <?= $cancelledToday > 0 ? 'danger' : '' ?>">❌ Cancelled Today <span class="kpi-value"><?= $cancelledToday ?></span></div>
  <div class="kpi-chip">🧾 POS Bills <span class="kpi-value"><?= count($tabs['pos']['orders']) ?></span></div>
  <div class="kpi-chip">🏨 B2B Orders <span class="kpi-value"><?= count($tabs['b2b']['orders']) ?></span></div>
  <div class="kpi-chip">💰 Today's Revenue <span class="kpi-value">₹<?= number_format($todayRevenue, 2) ?></span></div>
</div>

<div class="order-tabs" role="tablist">
  <?php $first = true; foreach ($tabs as $key => $tab): ?>
    <button type="button" class="order-tab-btn <?= $first ? 'active' : '' ?>" data-tab="<?= $key ?>" onclick="showOrderTab('<?= $key ?>')">
      <?= $tab['label'] ?> <span class="order-tab-count"><?= count($tab['orders']) ?></span>
    </button>
  <?php $first = false; endforeach; ?>
</div>

<div class="picking-toolbar">
  <span id="pickingSelectionSummary">Select orders in New / Processing to build a batch picking sheet</span>
  <button class="btn" id="genPickingBtn" type="button" disabled onclick="generatePickingSheet()">📋 Generate Picking Sheet</button>
</div>

<script>
const CSRF_TOKEN = '<?= h(csrf_token()) ?>';

function showOrderTab(key) {
  document.querySelectorAll('.order-tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === key));
  document.querySelectorAll('.order-tab-panel').forEach(p => p.classList.toggle('active', p.dataset.tab === key));
}

function postAction(url, payload, onSuccess) {
  payload.csrf_token = CSRF_TOKEN;
  fetch(url, { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify(payload) })
    .then(async (r) => {
      const text = await r.text();
      try {
        const data = JSON.parse(text);
        if (data.success) { if (onSuccess) { onSuccess(); } else { location.reload(); } }
        else { alert('Action failed: ' + (data.error || 'Unknown error')); }
      } catch (e) { alert('Action failed: ' + text.slice(0, 200)); }
    }).catch(() => alert('Network error'));
}

function setOrderStatus(orderId, status) {
  postAction('../ajax/update_order_status.php', { id: orderId, status: status });
}

function verifyPayment(orderId) {
  if (!confirm('Mark order #' + orderId + ' as paid? Only do this after you have verified the UPI payment in your app.')) return;
  window.location = 'update_order.php?id=' + orderId + '&type=payment&value=paid';
}

function assignRider(orderId) {
  const sel = document.getElementById('rider-' + orderId);
  const riderId = sel ? sel.value : '';
  if (!riderId) { alert('Please select a rider first.'); return; }
  postAction('../ajax/assign_rider.php', { order_id: orderId, rider_id: Number(riderId) });
}

function autoAssignRider(orderId) {
  postAction('../ajax/assign_rider.php', { order_id: orderId, auto: true });
}

function saveException(orderId) {
  const reason = document.getElementById('reason-' + orderId).value;
  const refundStatus = document.getElementById('refund-status-' + orderId).value;
  const refundAmount = document.getElementById('refund-amount-' + orderId).value;
  postAction('../ajax/manage_exception.php', {
    order_id: orderId, exception_reason: reason, refund_status: refundStatus, refund_amount: refundAmount
  }, () => { alert('✅ Saved — reason & refund status updated for order #' + orderId); location.reload(); });
}

function filterCompleted() {
  const q = document.getElementById('completedSearch').value.toLowerCase();
  document.querySelectorAll('#completed-table tbody tr[data-search]').forEach(row => {
    row.style.display = row.dataset.search.includes(q) ? '' : 'none';
  });
}

function filterPos() {
  const q = document.getElementById('posSearch').value.toLowerCase();
  document.querySelectorAll('#pos-table tbody tr[data-search]').forEach(row => {
    row.style.display = row.dataset.search.includes(q) ? '' : 'none';
  });
}

function filterB2B() {
  const q = document.getElementById('b2bSearch').value.toLowerCase();
  document.querySelectorAll('#b2b-table tbody tr[data-search]').forEach(row => {
    row.style.display = row.dataset.search.includes(q) ? '' : 'none';
  });
}

// ---- Packer assignment + batch picking sheet (merged from the old
// standalone Fulfillment Center page so this is all trackable from one
// place instead of two separate dashboards going out of sync). ----
const PICKER_MAP = <?= json_encode($pickerMap) ?>;

function selectedPickOrderIds() {
  return Array.from(document.querySelectorAll('.order-card-select:checked')).map(cb => cb.value);
}

function updatePickingSelectionSummary() {
  const ids = selectedPickOrderIds();
  const summary = document.getElementById('pickingSelectionSummary');
  const btn = document.getElementById('genPickingBtn');
  if (summary) summary.textContent = ids.length ? ids.length + ' order(s) selected for picking' : 'Select orders in New / Processing to build a batch picking sheet';
  if (btn) btn.disabled = ids.length === 0;
}

function generatePickingSheet() {
  const ids = selectedPickOrderIds();
  if (!ids.length) { alert('Select at least one order to generate a picking sheet.'); return; }
  window.open('picking_sheet.php?orders=' + encodeURIComponent(ids.join(',')), '_blank');
}

function assignPicker(orderId) {
  const select = document.getElementById('picker-' + orderId);
  const pickerId = select ? select.value : '';
  if (!pickerId) { alert('Please select a packer first.'); return; }
  const btn = document.getElementById('picker-btn-' + orderId);
  if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
  fetch('../ajax/assign_picker.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ order_id: orderId, picker_id: Number(pickerId), csrf_token: CSRF_TOKEN })
  }).then(r => r.json()).then(data => {
    if (!data.success) throw new Error(data.error || 'Unable to assign packer');
    const note = document.getElementById('picker-note-' + orderId);
    if (note) note.textContent = '📦 Packer: ' + (PICKER_MAP[pickerId] || 'Assigned');
  }).catch(err => {
    alert(err.message || 'Failed to assign packer.');
  }).finally(() => {
    if (btn) { btn.disabled = false; btn.textContent = 'Assign'; }
  });
}

// ---- Delivery route planning (merged from the old standalone Delivery
// Planner page, which wrongly showed POS counter-sale orders alongside
// online orders needing a rider route). Scoped to Ready for Dispatch only,
// which already excludes POS orders by design. ----
function selectedRouteOrderIds() {
  return Array.from(document.querySelectorAll('.order-card-route-select:checked')).map(cb => cb.value);
}

function updateRouteSelectionSummary() {
  const ids = selectedRouteOrderIds();
  const summary = document.getElementById('routeSelectionSummary');
  const btn = document.getElementById('planRouteBtn');
  if (summary) summary.textContent = ids.length ? ids.length + ' order(s) selected for route planning' : 'Select orders below to plan a delivery route (merged from the old Delivery Planner page)';
  if (btn) btn.disabled = ids.length === 0;
}

function planRoute() {
  const ids = selectedRouteOrderIds();
  const rider = document.getElementById('routeRiderSelect').value;
  if (!ids.length) { alert('Select at least one order to include in the route.'); return; }
  if (!rider) { alert('Select a rider first.'); return; }
  window.open('route_sheet.php?orders=' + encodeURIComponent(ids.join(',')) + '&rider=' + encodeURIComponent(rider), '_blank');
}
</script>

<?php
function render_order_card($o, $itemsByOrder, $colorMap, $actionsHtml, $orderStatusOptions, $pickerHtml = '', $enablePickSelect = false, $enableRouteSelect = false) {
    $status = normalize_order_status($o['order_status']);
    $urgentClass = '';
    if (in_array($status, ['out_for_delivery', 'arriving_soon'], true) && $o['eta_minutes'] !== null && $o['updated_at']) {
        $minutesSince = (time() - strtotime($o['updated_at'])) / 60;
        if ($minutesSince > (float)$o['eta_minutes']) $urgentClass = 'urgent';
        elseif ($minutesSince > (float)$o['eta_minutes'] * 0.7) $urgentClass = 'warn';
    }
    ?>
    <div class="order-card <?= $urgentClass ?> <?= ($enablePickSelect || $enableRouteSelect) ? 'has-select' : '' ?>">
      <?php if ($enablePickSelect): ?>
        <input type="checkbox" class="order-card-select" value="<?= $o['id'] ?>" onchange="updatePickingSelectionSummary()" title="Select for batch picking sheet">
      <?php endif; ?>
      <?php if ($enableRouteSelect): ?>
        <input type="checkbox" class="order-card-route-select" value="<?= $o['id'] ?>" onchange="updateRouteSelectionSummary()" title="Select for route planning">
      <?php endif; ?>
      <div class="order-card-top">
        <span class="order-card-id">#<?= $o['id'] ?></span>
        <span class="order-card-time"><?= format_ist($o['created_at'], 'd M, h:i A') ?></span>
      </div>
      <div class="order-card-customer"><?= h($o['customer_name']) ?></div>
      <div class="order-card-sub">📞 <?= h($o['phone']) ?></div>
      <?php if (!empty($o['delivery_date'])): ?>
        <div class="order-card-sub">🗓 <?= h(date('d M', strtotime($o['delivery_date']))) ?>, <?= h($o['delivery_slot']) ?></div>
      <?php endif; ?>
      <?php if (!empty($o['rider_name'])): ?>
        <div class="order-card-sub">🛵 <?= h($o['rider_name']) ?><?= $o['rider_phone'] ? ' · <a href="tel:' . h($o['rider_phone']) . '">📞 Call</a>' : '' ?></div>
      <?php endif; ?>
      <?php if (!empty($o['dark_store_name'])): ?>
        <div class="order-card-sub">🏬 <?= h($o['dark_store_name']) ?></div>
      <?php else: ?>
        <div class="order-card-sub" style="color:#b35c00;">🏬 Store not auto-assigned yet</div>
      <?php endif; ?>
      <span class="order-card-badge" style="<?= status_color_style($status, $colorMap) ?>"><?= h($orderStatusOptions[$status] ?? ucfirst($status)) ?></span>
      <?php if (!empty($itemsByOrder[$o['id']])): ?>
        <details class="order-card-items">
          <summary><?= count($itemsByOrder[$o['id']]) ?> item(s)</summary>
          <?php foreach ($itemsByOrder[$o['id']] as $it): ?>
            <div>• <?= h($it['name']) ?><?= $it['variant_label'] ? ' (' . h($it['variant_label']) . ')' : '' ?> × <?= rtrim(rtrim(number_format($it['quantity'], 3), '0'), '.') ?></div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
      <?= $pickerHtml ?>
      <div class="order-card-total">₹<?= number_format($o['total_amount'], 2) ?></div>
      <div class="order-card-actions">
        <?= $actionsHtml ?>
      </div>
    </div>
    <?php
}

function render_picker_block($o, $pickerAdmins) {
    $orderId = (int)$o['id'];
    $html = '<div class="order-card-picker"><select id="picker-' . $orderId . '"><option value="">— Select packer —</option>';
    foreach ($pickerAdmins as $pk) {
        $sel = (int)($o['assigned_picker_id'] ?? 0) === (int)$pk['id'] ? 'selected' : '';
        $html .= '<option value="' . $pk['id'] . '" ' . $sel . '>' . h($pk['username']) . '</option>';
    }
    $html .= '</select><button class="btn" id="picker-btn-' . $orderId . '" type="button" onclick="assignPicker(' . $orderId . ')">Assign</button></div>';
    $html .= '<div class="order-card-picker-note" id="picker-note-' . $orderId . '">' . (!empty($o['assigned_picker_name']) ? '📦 Packer: ' . h($o['assigned_picker_name']) : '') . '</div>';
    return $html;
}
?>

<!-- ===================== NEW ===================== -->
<div class="order-tab-panel active" data-tab="new">
  <div class="order-card-grid">
    <?php if (empty($tabs['new']['orders'])): ?>
      <div class="order-card-empty">No new orders waiting.</div>
    <?php endif; ?>
    <?php foreach ($tabs['new']['orders'] as $o):
      $actions = '';
      if ($o['payment_status'] === 'awaiting_verification') {
          $actions .= '<button class="btn primary" onclick="verifyPayment(' . $o['id'] . ')">✅ Verify Payment</button>';
      }
      if (normalize_order_status($o['order_status']) === 'placed') {
          $actions .= '<button class="btn" onclick="setOrderStatus(' . $o['id'] . ", 'processing')\">▶ Start Processing</button>";
      }
      $actions .= '<button class="btn danger" onclick="if(confirm(\'Cancel order #' . $o['id'] . "')) setOrderStatus(" . $o['id'] . ", 'cancelled')\">✕ Cancel</button>";
      render_order_card($o, $itemsByOrder, $colorMap, $actions, $orderStatusOptions, render_picker_block($o, $pickerAdmins), true);
    endforeach; ?>
  </div>
</div>

<!-- ===================== PROCESSING ===================== -->
<div class="order-tab-panel" data-tab="processing">
  <div class="order-card-grid">
    <?php if (empty($tabs['processing']['orders'])): ?>
      <div class="order-card-empty">Nothing being packed right now.</div>
    <?php endif; ?>
    <?php foreach ($tabs['processing']['orders'] as $o):
      $actions = '<a class="btn" href="export_orders_pdf.php?id=' . $o['id'] . '" target="_blank">🖨 Print Packing Slip</a>';
      $actions .= '<button class="btn primary" onclick="setOrderStatus(' . $o['id'] . ", 'ready_for_pickup')\">✅ Ready for Dispatch</button>";
      $actions .= '<button class="btn danger" onclick="if(confirm(\'Cancel order #' . $o['id'] . "')) setOrderStatus(" . $o['id'] . ", 'cancelled')\">✕ Cancel</button>";
      render_order_card($o, $itemsByOrder, $colorMap, $actions, $orderStatusOptions, render_picker_block($o, $pickerAdmins), true);
    endforeach; ?>
  </div>
</div>

<!-- ===================== READY FOR DISPATCH ===================== -->
<div class="order-tab-panel" data-tab="ready">
  <div class="picking-toolbar">
    <span id="routeSelectionSummary">Select orders below to plan a delivery route (merged from the old Delivery Planner page)</span>
    <select id="routeRiderSelect">
      <option value="">— Choose rider —</option>
      <?php foreach ($riders as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn" id="planRouteBtn" type="button" disabled onclick="planRoute()">🗺️ Plan Route</button>
  </div>
  <div class="order-card-grid">
    <?php if (empty($tabs['ready']['orders'])): ?>
      <div class="order-card-empty">No orders waiting on a rider.</div>
    <?php endif; ?>
    <?php foreach ($tabs['ready']['orders'] as $o):
      $riderSelect = '<select id="rider-' . $o['id'] . '"><option value="">— Select rider —</option>';
      foreach ($riders as $r) {
          $sel = (int)($o['rider_id'] ?? 0) === (int)$r['id'] ? 'selected' : '';
          $riderSelect .= '<option value="' . $r['id'] . '" ' . $sel . '>' . h($r['name']) . '</option>';
      }
      $riderSelect .= '</select>';
      $actions = $riderSelect;
      $actions .= '<button class="btn" onclick="assignRider(' . $o['id'] . ')">Assign</button>';
      $actions .= '<button class="btn primary" onclick="autoAssignRider(' . $o['id'] . ')" title="Assign nearest available rider automatically">⚡ Auto-Assign</button>';
      // Once a rider is assigned, the order normally moves to "Out for
      // Delivery" automatically when the rider taps "Confirm Pickup" in
      // their app. If the rider hasn't done that yet (forgot, app issue,
      // handed the bag over in person, etc.) give the admin a manual
      // fallback so the order never gets stuck here.
      if (normalize_order_status($o['order_status']) === 'delivery_partner_assigned' && !empty($o['rider_id'])) {
          $actions .= '<button class="btn primary" onclick="setOrderStatus(' . $o['id'] . ", 'out_for_delivery')\" title=\"Use this if the rider already picked up but hasn't confirmed in their app\">🛵 Mark Out for Delivery</button>";
      }
      $actions .= '<a class="btn" href="export_orders_pdf.php?id=' . $o['id'] . '" target="_blank">🖨 Print Shipping Label</a>';
      $actions .= '<button class="btn danger" onclick="if(confirm(\'Cancel order #' . $o['id'] . "')) setOrderStatus(" . $o['id'] . ", 'cancelled')\">✕ Cancel</button>";
      render_order_card($o, $itemsByOrder, $colorMap, $actions, $orderStatusOptions, '', false, true);
    endforeach; ?>
  </div>
</div>

<!-- ===================== OUT FOR DELIVERY ===================== -->
<div class="order-tab-panel" data-tab="transit">
  <div class="order-card-grid">
    <?php if (empty($tabs['transit']['orders'])): ?>
      <div class="order-card-empty">No active deliveries right now.</div>
    <?php endif; ?>
    <?php foreach ($tabs['transit']['orders'] as $o):
      $actions = '<a class="btn" href="deliver.php?order_id=' . $o['id'] . '">📍 Track</a>';
      if (!empty($o['rider_phone'])) {
          $actions .= '<a class="btn" href="tel:' . h($o['rider_phone']) . '">📞 Call Rider</a>';
      }
      $actions .= '<button class="btn danger" onclick="if(confirm(\'Cancel order #' . $o['id'] . "')) setOrderStatus(" . $o['id'] . ", 'cancelled')\">✕ Cancel</button>";
      render_order_card($o, $itemsByOrder, $colorMap, $actions, $orderStatusOptions);
    endforeach; ?>
  </div>
</div>

<!-- ===================== COMPLETED ===================== -->
<div class="order-tab-panel" data-tab="completed">
  <input type="text" id="completedSearch" class="completed-search" placeholder="Search by order # or customer name..." oninput="filterCompleted()">
  <div class="table-wrap">
    <table class="orders-table" id="completed-table">
      <thead>
        <tr><th>#</th><th>Customer</th><th>Store</th><th>Delivered</th><th>Total</th><th>Invoice</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tabs['completed']['orders'] as $o): ?>
          <tr data-search="<?= h(strtolower('#' . $o['id'] . ' ' . $o['customer_name'])) ?>">
            <td><?= $o['id'] ?></td>
            <td><?= h($o['customer_name']) ?><br><small style="color:#5B6656;"><?= h($o['email']) ?></small></td>
            <td><?= h($o['dark_store_name'] ?? '—') ?></td>
            <td><?= $o['delivered_at'] ? format_ist($o['delivered_at'], 'd M Y, h:i A') : '—' ?></td>
            <td>₹<?= number_format($o['total_amount'], 2) ?></td>
            <td><a class="btn" href="export_orders_pdf.php?id=<?= $o['id'] ?>" target="_blank">⬇ Invoice</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($tabs['completed']['orders'])): ?>
          <tr><td colspan="6" style="text-align:center; color:#5B6656;">No completed orders yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===================== EXCEPTIONS ===================== -->
<div class="order-tab-panel" data-tab="exceptions">
  <div class="order-card-grid">
    <?php if (empty($tabs['exceptions']['orders'])): ?>
      <div class="order-card-empty">No cancellations, returns, or failed payments. 🎉</div>
    <?php endif; ?>
    <?php foreach ($tabs['exceptions']['orders'] as $o): ?>
      <div class="order-card urgent">
        <div class="order-card-top">
          <span class="order-card-id">#<?= $o['id'] ?></span>
          <span class="order-card-time"><?= format_ist($o['created_at'], 'd M, h:i A') ?></span>
        </div>
        <div class="order-card-customer"><?= h($o['customer_name']) ?></div>
        <div class="order-card-sub">📞 <?= h($o['phone']) ?></div>
        <?php if (!empty($o['dark_store_name'])): ?>
          <div class="order-card-sub">🏬 <?= h($o['dark_store_name']) ?></div>
        <?php endif; ?>
        <span class="order-card-badge" style="<?= status_color_style(normalize_order_status($o['order_status']) === 'cancelled' ? 'cancelled' : 'failed', $colorMap) ?>">
          <?= normalize_order_status($o['order_status']) === 'cancelled' ? 'Cancelled' : 'Payment Failed' ?>
        </span>
        <div class="order-card-total">₹<?= number_format($o['total_amount'], 2) ?></div>
        <div class="exception-form">
          <input type="text" id="reason-<?= $o['id'] ?>" placeholder="Reason / note" value="<?= h($o['exception_reason'] ?? '') ?>">
          <select id="refund-status-<?= $o['id'] ?>">
            <?php foreach (['none' => 'No refund', 'requested' => 'Refund requested', 'processed' => 'Refund processed'] as $val => $label): ?>
              <option value="<?= $val ?>" <?= ($o['refund_status'] ?? 'none') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" step="0.01" id="refund-amount-<?= $o['id'] ?>" placeholder="Refund amount (₹)" value="<?= h($o['refund_amount'] ?? '') ?>">
          <button class="btn primary" onclick="saveException(<?= $o['id'] ?>)">💾 Save</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===================== POS BILLS ===================== -->
<div class="order-tab-panel" data-tab="pos">
  <input type="text" id="posSearch" class="completed-search" placeholder="Search by bill # or customer name..." oninput="filterPos()">
  <div class="table-wrap">
    <table class="orders-table" id="pos-table">
      <thead>
        <tr><th>#</th><th>Customer</th><th>Billed</th><th>Payment</th><th>Status</th><th>Total</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tabs['pos']['orders'] as $o):
          $posStatus = normalize_order_status($o['order_status']);
        ?>
          <tr data-search="<?= h(strtolower('#' . $o['id'] . ' ' . $o['customer_name'])) ?>">
            <td><?= $o['id'] ?></td>
            <td><?= h($o['customer_name']) ?><br><small style="color:#5B6656;"><?= h($o['phone']) ?></small></td>
            <td><?= format_ist($o['created_at'], 'd M Y, h:i A') ?></td>
            <td><?= $o['payment_method'] === 'upi_qr' ? 'UPI' : ($o['payment_method'] === 'credit' ? 'Credit' : 'Cash') ?></td>
            <td><span class="order-card-badge" style="<?= status_color_style($posStatus, $colorMap) ?>"><?= h($orderStatusOptions[$posStatus] ?? ucfirst($posStatus)) ?></span></td>
            <td>₹<?= number_format($o['total_amount'], 2) ?></td>
            <td>
              <a class="btn" href="billing.php?receipt=<?= $o['id'] ?>" target="_blank">🖨 Receipt</a>
              <?php if ($posStatus !== 'cancelled'): ?>
                <button class="btn danger" onclick="if(confirm('Cancel bill #<?= $o['id'] ?> and restore its stock?')) setOrderStatus(<?= $o['id'] ?>, 'cancelled')">✕ Cancel</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($tabs['pos']['orders'])): ?>
          <tr><td colspan="7" style="text-align:center; color:#5B6656;">No POS bills yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ===================== B2B ORDERS (Hotel & Shop) ===================== -->
<div class="order-tab-panel" data-tab="b2b">
  <input type="text" id="b2bSearch" class="completed-search" placeholder="Search by order # or business/customer name..." oninput="filterB2B()">
  <div class="table-wrap">
    <table class="orders-table" id="b2b-table">
      <thead>
        <tr><th>#</th><th>Customer</th><th>Type</th><th>Placed</th><th>Payment</th><th>Status</th><th>Total</th><th>Actions</th></tr>
      </thead>
      <tbody>
        <?php foreach ($tabs['b2b']['orders'] as $o):
          $b2bStatus = normalize_order_status($o['order_status']);
          $isManualBill = ($o['source'] ?? '') === 'b2b';
        ?>
          <tr data-search="<?= h(strtolower('#' . $o['id'] . ' ' . $o['customer_name'])) ?>">
            <td><?= $o['id'] ?></td>
            <td><?= h($o['customer_name']) ?><br><small style="color:#5B6656;"><?= h($o['phone']) ?></small></td>
            <td><?= h(ucfirst($o['customer_type'] ?? 'retail')) ?></td>
            <td><?= format_ist($o['created_at'], 'd M Y, h:i A') ?></td>
            <td>
              <?= $o['payment_method'] === 'upi_qr' ? 'UPI' : ($o['payment_method'] === 'credit' ? 'Credit' : 'Cash') ?>
              <?php if ($o['payment_method'] === 'credit'): ?>
                <br><small style="color:<?= $o['payment_status'] === 'pending' ? '#df2e24' : '#159447' ?>;"><?= h(ucfirst($o['payment_status'])) ?></small>
              <?php endif; ?>
            </td>
            <td><span class="order-card-badge" style="<?= status_color_style($b2bStatus, $colorMap) ?>"><?= h($orderStatusOptions[$b2bStatus] ?? ucfirst($b2bStatus)) ?></span></td>
            <td>₹<?= number_format($o['total_amount'], 2) ?></td>
            <td>
              <?php if ($isManualBill): ?>
                <a class="btn" href="b2b_billing.php?receipt=<?= $o['id'] ?>" target="_blank">🖨 Receipt</a>
              <?php endif; ?>
              <?php if ($o['customer_id']): ?>
                <a class="btn" href="b2b_ledger.php?customer_id=<?= (int)$o['customer_id'] ?>">📒 Ledger</a>
              <?php endif; ?>
              <?php if ($isManualBill && $b2bStatus !== 'cancelled'): ?>
                <button class="btn danger" onclick="if(confirm('Cancel bill #<?= $o['id'] ?> and restore its stock?')) setOrderStatus(<?= $o['id'] ?>, 'cancelled')">✕ Cancel</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($tabs['b2b']['orders'])): ?>
          <tr><td colspan="8" style="text-align:center; color:#5B6656;">No B2B (Hotel/Shop) orders yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
