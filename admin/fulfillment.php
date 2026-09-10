<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Fulfillment Center';

$pickerAdmins = $pdo->query("SELECT id, username FROM admins WHERE role IN ('admin','staff') ORDER BY username")->fetchAll();
$pickerMap = [];
foreach ($pickerAdmins as $pickerAdmin) {
    $pickerMap[(int) $pickerAdmin['id']] = $pickerAdmin['username'];
}

$activeFulfillmentStatuses = ['placed', 'processing', 'ready_for_pickup'];
$statusPlaceholders = implode(',', array_fill(0, count($activeFulfillmentStatuses), '?'));
$ordersStmt = $pdo->prepare("
    SELECT o.*, a.username AS assigned_picker_name
    FROM orders o
    LEFT JOIN admins a ON a.id = o.assigned_picker_id
    WHERE o.order_status IN ($statusPlaceholders)
    ORDER BY o.created_at DESC
    LIMIT 200
");
$ordersStmt->execute($activeFulfillmentStatuses);
$orders = $ordersStmt->fetchAll();

$itemsByOrder = [];
if ($orders) {
    $orderIds = array_map(static fn($order) => (int) $order['id'], $orders);
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemStmt = $pdo->prepare("
        SELECT order_id, name, quantity, variant_label
        FROM order_items
        WHERE order_id IN ($placeholders)
        ORDER BY id
    ");
    $itemStmt->execute($orderIds);
    foreach ($itemStmt->fetchAll() as $itemRow) {
        $itemsByOrder[(int) $itemRow['order_id']][] = $itemRow;
    }
}

$statusOptions = get_order_status_options();
$statusColors = [
    'pending' => ['bg' => '#eef2ef', 'fg' => '#55625b'],
    'placed' => ['bg' => '#e7f0ff', 'fg' => '#1b4f93'],
    'processing' => ['bg' => '#fff4cc', 'fg' => '#8a6700'],
    'ready_for_pickup' => ['bg' => '#efe6ff', 'fg' => '#5f38a5'],
    'delivery_partner_assigned' => ['bg' => '#e8f5ff', 'fg' => '#0b6b93'],
    'out_for_delivery' => ['bg' => '#ffe8d1', 'fg' => '#a55300'],
    'arriving_soon' => ['bg' => '#ffe3ea', 'fg' => '#ad355b'],
    'delivered' => ['bg' => '#ddf3e2', 'fg' => '#1f6a42'],
    'cancelled' => ['bg' => '#fde8e7', 'fg' => '#a3342a'],
];

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .fulfillment-shell {
    display: grid;
    gap: 18px;
  }
  .fulfillment-hero {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    align-items: flex-start;
  }
  .fulfillment-hero h2 {
    margin: 0;
    color: #0c5a42;
    font-size: clamp(1.5rem, 2vw, 2.2rem);
    line-height: 1.15;
    font-weight: 800;
    letter-spacing: -0.03em;
  }
  .fulfillment-hero p {
    margin: 8px 0 0;
    color: #5f6d65;
    max-width: 720px;
  }
  .fulfillment-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
  }
  .fulfillment-toolbar .btn {
    min-height: 42px;
    padding: 0 16px;
    border-radius: 999px;
    font-weight: 700;
  }
  .fulfillment-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }
  .fulfillment-stat {
    background: #fff;
    border: 1px solid #dfe8df;
    border-radius: 18px;
    padding: 16px 18px;
    box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05);
  }
  .fulfillment-stat span {
    display: block;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #6c7b73;
    font-weight: 700;
  }
  .fulfillment-stat strong {
    display: block;
    margin-top: 8px;
    color: #183a31;
    font-size: 1.65rem;
    line-height: 1;
  }
  .fulfillment-card {
    background: #fff;
    border: 1px solid #dfe8df;
    border-radius: 20px;
    box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05);
    overflow: hidden;
  }
  .fulfillment-table-wrap {
    overflow-x: auto;
  }
  .fulfillment-table {
    width: 100%;
    min-width: 1320px;
    border-collapse: collapse;
  }
  .fulfillment-table th {
    background: #dfe9dc;
    color: #1b342d;
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 800;
    text-align: left;
    padding: 13px 14px;
    border-bottom: 1px solid #cfddcc;
  }
  .fulfillment-table td {
    padding: 14px;
    border-bottom: 1px solid #edf1ed;
    vertical-align: top;
    color: #1f2d29;
    font-size: 0.85rem;
    line-height: 1.45;
  }
  .fulfillment-table tbody tr:hover {
    background: #fbfdfb;
  }
  .fulfillment-table tbody tr:last-child td {
    border-bottom: none;
  }
  .order-code {
    font-weight: 800;
    color: #173c32;
  }
  .order-meta,
  .assigned-note,
  .helper-text {
    color: #66756d;
    font-size: 0.76rem;
  }
  .items-list {
    display: grid;
    gap: 6px;
    max-width: 340px;
  }
  .item-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    max-width: 100%;
    padding: 5px 10px;
    border-radius: 999px;
    background: #eff6ee;
    color: #1c5d45;
    font-size: 0.76rem;
    font-weight: 700;
  }
  .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 800;
    margin-bottom: 8px;
    white-space: nowrap;
  }
  .fulfillment-table select {
    width: 100%;
    min-height: 38px;
    border: 1px solid #d8e2d9;
    border-radius: 10px;
    padding: 8px 10px;
    background: #f7faf7;
    color: #20312c;
    font-size: 0.82rem;
    font-weight: 700;
  }
  .action-stack {
    display: grid;
    gap: 8px;
    min-width: 200px;
  }
  .action-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .action-row .btn {
    min-height: 36px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 0.76rem;
    font-weight: 700;
  }
  .bulk-panel {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    padding: 16px 18px;
    border-top: 1px solid #ecf0eb;
    background: #fbfdfb;
  }
  .bulk-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
  }
  .bulk-actions select {
    min-width: 220px;
    max-width: 260px;
  }
  .empty-state {
    padding: 40px 20px;
    text-align: center;
    color: #66756d;
  }
  @media (max-width: 1100px) {
    .fulfillment-summary {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 700px) {
    .fulfillment-summary {
      grid-template-columns: 1fr;
    }
  }
</style>

<?php
$selectedCount = count($orders);
$readyCount = 0;
$assignedCount = 0;
$processingCount = 0;
foreach ($orders as $order) {
    $normalizedStatus = normalize_order_status($order['order_status']);
    if ($normalizedStatus === 'ready_for_pickup') {
        $readyCount++;
    }
    if (!empty($order['assigned_picker_id'])) {
        $assignedCount++;
    }
    if (in_array($normalizedStatus, ['processing', 'ready_for_pickup'], true)) {
        $processingCount++;
    }
}
?>

<script>
const STATUS_LABELS = <?= json_encode($statusOptions) ?>;
const STATUS_COLORS = <?= json_encode($statusColors) ?>;
const PICKER_MAP = <?= json_encode($pickerMap) ?>;
const CSRF_TOKEN = '<?= h(csrf_token()) ?>';

function getStatusStyle(status) {
  return STATUS_COLORS[status] || { bg: '#eef2ef', fg: '#55625b' };
}

function applySelectStyle(select) {
  const style = getStatusStyle(select.value);
  select.style.background = style.bg;
  select.style.color = style.fg;
}

function renderStatusBadge(status, orderId) {
  const badge = document.getElementById('status-badge-' + orderId);
  if (!badge) return;
  const style = getStatusStyle(status);
  badge.textContent = STATUS_LABELS[status] || status;
  badge.style.background = style.bg;
  badge.style.color = style.fg;
}

function removeOrderRow(orderId) {
  const row = document.getElementById('order-' + orderId);
  if (row) {
    row.remove();
  }
  const selectAll = document.getElementById('selectAll');
  if (selectAll) {
    selectAll.checked = false;
  }
  updateSelectionSummary();
}

function selectedOrderIds() {
  return Array.from(document.querySelectorAll('.pickChk:checked')).map((cb) => cb.value);
}

function updateSelectionSummary() {
  const selected = selectedOrderIds();
  const summary = document.getElementById('selectionSummary');
  const pickingButton = document.getElementById('genPicking');
  const bulkButton = document.getElementById('bulkStatusBtn');
  if (summary) {
    summary.textContent = selected.length ? selected.length + ' order(s) selected' : 'Select orders to create a batch picking sheet';
  }
  if (pickingButton) {
    pickingButton.disabled = selected.length === 0;
  }
  if (bulkButton) {
    bulkButton.disabled = selected.length === 0;
  }
}

function setStatusSaving(orderId, saving) {
  const button = document.getElementById('status-btn-' + orderId);
  if (button) {
    button.disabled = saving;
    button.textContent = saving ? 'Saving...' : 'Update';
  }
}

function updateStatus(orderId) {
  const select = document.getElementById('select-status-' + orderId);
  const nextStatus = select.value;
  setStatusSaving(orderId, true);
  fetch('../ajax/update_order_status.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id: orderId, status: nextStatus, csrf_token: CSRF_TOKEN })
  }).then((r) => r.json()).then((data) => {
    if (!data.success) {
      throw new Error(data.error || 'Unable to update status');
    }
    select.value = data.status || nextStatus;
    applySelectStyle(select);
    renderStatusBadge(select.value, orderId);
    if (['delivery_partner_assigned', 'out_for_delivery', 'arriving_soon', 'delivered', 'cancelled'].includes(select.value)) {
      removeOrderRow(orderId);
    }
  }).catch((error) => {
    alert(error.message || 'Failed to update status.');
  }).finally(() => {
    setStatusSaving(orderId, false);
  });
}

function setPickerSaving(orderId, saving) {
  const button = document.getElementById('picker-btn-' + orderId);
  if (button) {
    button.disabled = saving;
    button.textContent = saving ? 'Saving...' : 'Assign';
  }
}

function assignPicker(orderId) {
  const select = document.getElementById('picker-' + orderId);
  const pickerId = select ? select.value : '';
  if (!pickerId) {
    alert('Please select a packer before assigning.');
    return;
  }
  setPickerSaving(orderId, true);
  fetch('../ajax/assign_picker.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ order_id: orderId, picker_id: Number(pickerId), csrf_token: CSRF_TOKEN })
  }).then((r) => r.json()).then((data) => {
    if (!data.success) {
      throw new Error(data.error || 'Unable to assign packer');
    }
    const note = document.getElementById('assigned-note-' + orderId);
    if (note) {
      note.textContent = 'Assigned: ' + (PICKER_MAP[String(data.picker_id)] || PICKER_MAP[data.picker_id] || 'Packer');
    }
  }).catch((error) => {
    alert(error.message || 'Failed to assign packer.');
  }).finally(() => {
    setPickerSaving(orderId, false);
  });
}

function applyBulkStatus() {
  const ids = selectedOrderIds();
  const bulkSelect = document.getElementById('bulkStatusSelect');
  const targetStatus = bulkSelect ? bulkSelect.value : '';
  if (!ids.length) {
    alert('Select at least one order.');
    return;
  }
  if (!targetStatus) {
    alert('Choose a status to apply.');
    return;
  }
  Promise.all(ids.map((id) => fetch('../ajax/update_order_status.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id: Number(id), status: targetStatus, csrf_token: CSRF_TOKEN })
  }).then((r) => r.json()))).then((results) => {
    const failed = results.find((row) => !row.success);
    if (failed) {
      throw new Error(failed.error || 'One or more orders could not be updated');
    }
    ids.forEach((id) => {
      const select = document.getElementById('select-status-' + id);
      if (select) {
        select.value = targetStatus;
        applySelectStyle(select);
      }
      if (['delivery_partner_assigned', 'out_for_delivery', 'arriving_soon', 'delivered', 'cancelled'].includes(targetStatus)) {
        removeOrderRow(id);
      } else {
        renderStatusBadge(targetStatus, id);
        const checkbox = document.getElementById('pick-' + id);
        if (checkbox) {
          checkbox.checked = false;
        }
      }
    });
    updateSelectionSummary();
  }).catch((error) => {
    alert(error.message || 'Bulk status update failed.');
  });
}

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.status-select').forEach(applySelectStyle);
  document.querySelectorAll('.pickChk').forEach((checkbox) => {
    checkbox.addEventListener('change', updateSelectionSummary);
  });
  document.getElementById('refreshBtn')?.addEventListener('click', () => location.reload());
  document.getElementById('bulkStatusBtn')?.addEventListener('click', applyBulkStatus);
  document.getElementById('genPicking')?.addEventListener('click', () => {
    const ids = selectedOrderIds();
    if (!ids.length) {
      alert('Select at least one order to generate a picking sheet.');
      return;
    }
    window.open('picking_sheet.php?orders=' + encodeURIComponent(ids.join(',')), '_blank');
  });
  updateSelectionSummary();
});
</script>

<div class="fulfillment-shell">
  <div class="fulfillment-hero">
    <div>
      <h2>Order Management &amp; Fulfillment</h2>
      <p>Use the live dashboard to move orders through fulfillment, assign packers, and build a batch picking sheet from selected orders.</p>
    </div>
    <div class="fulfillment-toolbar">
      <button class="btn" id="refreshBtn" type="button">Refresh</button>
      <button class="btn" id="genPicking" type="button">Generate Picking Sheet</button>
    </div>
  </div>

  <div class="fulfillment-summary">
    <div class="fulfillment-stat"><span>Visible Orders</span><strong><?= $selectedCount ?></strong></div>
    <div class="fulfillment-stat"><span>Assigned to Packers</span><strong><?= $assignedCount ?></strong></div>
    <div class="fulfillment-stat"><span>Actively Picking</span><strong><?= $processingCount ?></strong></div>
    <div class="fulfillment-stat"><span>Ready for Dispatch</span><strong><?= $readyCount ?></strong></div>
  </div>

  <div class="fulfillment-card">
    <div class="fulfillment-table-wrap">
      <table class="fulfillment-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="selectAll" onclick="document.querySelectorAll('.pickChk').forEach((cb)=>{cb.checked=this.checked;}); updateSelectionSummary();"></th>
            <th>Order</th>
            <th>Customer</th>
            <th>Items</th>
            <th>Total</th>
            <th>Payment</th>
            <th>Status</th>
            <th>Packer</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $order): ?>
          <?php
          $orderId = (int) $order['id'];
          $normalizedStatus = normalize_order_status($order['order_status']);
          $statusStyle = $statusColors[$normalizedStatus] ?? ['bg' => '#eef2ef', 'fg' => '#55625b'];
          $orderItems = $itemsByOrder[$orderId] ?? [];
          ?>
          <tr id="order-<?= $orderId ?>">
            <td><input type="checkbox" class="pickChk" id="pick-<?= $orderId ?>" value="<?= $orderId ?>"></td>
            <td>
              <div class="order-code">#ORD-<?= str_pad((string) $orderId, 5, '0', STR_PAD_LEFT) ?></div>
              <div class="order-meta"><?= format_ist($order['created_at'], 'd M Y, h:i A') ?></div>
            </td>
            <td>
              <strong><?= h($order['customer_name']) ?></strong><br>
              <span class="order-meta"><?= h($order['phone']) ?></span>
              <?php if (!empty($order['address'])): ?><br><span class="helper-text"><?= nl2br(h($order['address'])) ?></span><?php endif; ?>
            </td>
            <td>
              <div class="items-list">
                <?php foreach ($orderItems as $item): ?>
                  <span class="item-pill">
                    <?= h($item['name']) ?>
                    <?php if (!empty($item['variant_label'])): ?>(<?= h($item['variant_label']) ?>)<?php endif; ?>
                    x <?= rtrim(rtrim(number_format($item['quantity'], 3), '0'), '.') ?>
                  </span>
                <?php endforeach; ?>
                <?php if (empty($orderItems)): ?><span class="helper-text">No items found.</span><?php endif; ?>
              </div>
            </td>
            <td><strong>₹<?= number_format((float) $order['total_amount'], 2) ?></strong></td>
            <td>
              <strong><?= h(strtoupper((string) $order['payment_method'])) ?></strong><br>
              <span class="helper-text"><?= h(ucfirst(str_replace('_', ' ', (string) $order['payment_status']))) ?></span>
            </td>
            <td>
              <span class="status-badge" id="status-badge-<?= $orderId ?>" style="background: <?= h($statusStyle['bg']) ?>; color: <?= h($statusStyle['fg']) ?>;">
                <?= h($statusOptions[$normalizedStatus] ?? ucfirst(str_replace('_', ' ', $normalizedStatus))) ?>
              </span>
              <select id="select-status-<?= $orderId ?>" class="status-select">
                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                  <option value="<?= h($statusValue) ?>" <?= $normalizedStatus === $statusValue ? 'selected' : '' ?>><?= h($statusLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <select id="picker-<?= $orderId ?>">
                <option value="">Select packer</option>
                <?php foreach ($pickerAdmins as $pickerAdmin): ?>
                  <option value="<?= (int) $pickerAdmin['id'] ?>" <?= (int) $order['assigned_picker_id'] === (int) $pickerAdmin['id'] ? 'selected' : '' ?>><?= h($pickerAdmin['username']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="assigned-note" id="assigned-note-<?= $orderId ?>">
                <?= !empty($order['assigned_picker_name']) ? 'Assigned: ' . h($order['assigned_picker_name']) : 'Not assigned yet' ?>
              </div>
            </td>
            <td>
              <div class="action-stack">
                <div class="action-row">
                  <button class="btn" type="button" id="status-btn-<?= $orderId ?>" onclick="updateStatus(<?= $orderId ?>)">Update</button>
                  <a class="btn" href="orders.php?id=<?= $orderId ?>" target="_blank" rel="noopener">Open</a>
                </div>
                <div class="action-row">
                  <button class="btn" type="button" id="picker-btn-<?= $orderId ?>" onclick="assignPicker(<?= $orderId ?>)">Assign</button>
                  <a class="btn" href="picking_sheet.php?orders=<?= $orderId ?>" target="_blank" rel="noopener">Single Sheet</a>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($orders)): ?>
          <tr><td colspan="9" class="empty-state">No orders found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="bulk-panel">
      <div>
        <strong id="selectionSummary">Select orders to create a batch picking sheet</strong><br>
        <span class="helper-text">Batch actions apply only to the currently selected rows.</span>
      </div>
      <div class="bulk-actions">
        <select id="bulkStatusSelect">
          <option value="">Bulk status update</option>
          <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
            <option value="<?= h($statusValue) ?>"><?= h($statusLabel) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn" type="button" id="bulkStatusBtn">Apply Status</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
