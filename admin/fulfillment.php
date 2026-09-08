<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Fulfillment Center';

// Fetch recent orders and their status
$orders = $pdo->query("SELECT o.*, a.username AS assigned_picker_name FROM orders o LEFT JOIN admins a ON a.id = o.assigned_picker_id ORDER BY created_at DESC LIMIT 200")->fetchAll();

// Support AJAX refresh returning only tbody rows
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    ob_start();
}


include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head"><h2>Order Management & Fulfillment</h2>
  <p>Live order dashboard: change status, assign to packers, and generate batch picking sheets.</p>
</div>

<div style="margin-bottom:12px; display:flex; gap:8px; align-items:center;">
  <button class="btn" id="refreshBtn">🔄 Refresh</button>
  <button class="btn" id="genPicking">📋 Generate Picking Sheet for selected</button>
  <span style="color:#5B6656; margin-left:8px;">Select orders with the checkboxes on the left</span>
</div>

<div class="table-wrap">
  <table>
    <thead><tr><th></th><th>Order</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($orders as $o):
        $items = $pdo->prepare('SELECT name, quantity, variant_label FROM order_items WHERE order_id = ?'); $items->execute([$o['id']]); $its = $items->fetchAll();
        $itemLines = [];
        foreach($its as $it) $itemLines[] = h($it['name']) . ($it['variant_label'] ? ' (' . h($it['variant_label']) . ')' : '') . ' × ' . rtrim(rtrim(number_format($it['quantity'],3),'0'),'.');
    ?>
      <tr id="order-<?= $o['id'] ?>">
        <td><input type="checkbox" class="pickChk" value="<?= $o['id'] ?>"></td>
        <td>#ORD-<?= str_pad($o['id'],5,'0',STR_PAD_LEFT) ?></td>
        <td><?= h($o['customer_name']) ?><br><small><?= h($o['phone']) ?></small></td>
        <td style="max-width:360px;"><?php foreach($itemLines as $li) echo '<div style="font-size:0.95rem; color:#334">'.$li.'</div>'; ?></td>
        <td><?= SITE_CURRENCY ?><?= number_format($o['total_amount'],2) ?></td>
        <td><?= h(strtoupper($o['payment_method'])) ?><?php if($o['payment_status']!=='paid') echo '<br><small>'.h($o['payment_status']).'</small>'; ?></td>
        <td id="status-<?= $o['id'] ?>"><?= h(ucfirst(str_replace('_',' ',$o['order_status']))) ?></td>
        <td><?= date('d M H:i', strtotime($o['created_at'])) ?></td>
        <td>
          <select id="select-status-<?= $o['id'] ?>">
            <?php $statuses = array_keys(get_order_status_options()); foreach($statuses as $s): ?>
              <option value="<?= $s ?>" <?= normalize_order_status($o['order_status'])===$s ? 'selected' : '' ?>><?= get_order_status_options()[$s] ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn" onclick="updateStatus(<?= $o['id'] ?>)">Update</button>
          <a class="btn" href="orders.php?id=<?= $o['id'] ?>" target="_blank">Open</a>
          <div style="margin-top:6px;">
            <select id="picker-<?= $o['id'] ?>">
              <option value="">— Assign picker —</option>
              <?php $admins = $pdo->query("SELECT id, username FROM admins WHERE role IN ('admin','staff') ORDER BY username")->fetchAll(); foreach($admins as $ad): ?>
                <option value="<?= $ad['id'] ?>" <?= $o['assigned_picker_id']==$ad['id'] ? 'selected' : '' ?>><?= h($ad['username']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn" onclick="assignPicker(<?= $o['id'] ?>)">Assign</button>
            <?php if (!empty($o['assigned_picker_name'])): ?><div style="font-size:0.85rem; color:#5B6656;">Assigned: <?= h($o['assigned_picker_name']) ?></div><?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="9" style="text-align:center;color:#5B6656;">No orders found.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function updateStatus(id) {
  const sel = document.getElementById('select-status-' + id);
  const newStatus = sel.value;
  const statusCell = document.getElementById('status-' + id);
  statusCell.textContent = 'Saving...';
  fetch('<?= BASE_URL ?>/ajax/update_order_status.php', {
    method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ id: id, status: newStatus, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(r=>r.json()).then(data=>{
    if (data.success) statusCell.textContent = data.status_display || newStatus; else statusCell.textContent = 'Error';
  }).catch(()=> statusCell.textContent = 'Network error');
}

function assignPicker(orderId){
  const sel = document.getElementById('picker-' + orderId);
  const rawValue = sel ? sel.value : '';
  if (!rawValue) {
    alert('Please select a picker before assigning.');
    return;
  }
  const pickerId = Number(rawValue);
  fetch('<?= BASE_URL ?>/ajax/assign_picker.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ order_id: orderId, picker_id: pickerId, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
    const text = await r.text();
    try {
      const data = JSON.parse(text);
      if (data.success) {
        location.reload();
      } else {
        alert('Failed to assign: ' + (data.error || 'Unknown server error'));
      }
    } catch (e) {
      alert('Failed to assign: ' + text.slice(0, 200));
    }
  }).catch(()=>alert('Network error'));
}

// Auto-refresh (poll for updates and replace tbody)
setInterval(()=>{
  fetch(location.pathname + '?ajax=1').then(r=>r.text()).then(html=>{
    try{ const parser = new DOMParser(); const doc = parser.parseFromString(html, 'text/html'); const newTbody = doc.querySelector('tbody'); if(newTbody){ const old = document.querySelector('table tbody'); old.parentNode.replaceChild(newTbody, old); } }catch(e){/* ignore */}
  }).catch(()=>{});
}, 15000);

document.getElementById('refreshBtn').addEventListener('click', ()=> location.reload());
document.getElementById('genPicking').addEventListener('click', ()=>{
  const ids = Array.from(document.querySelectorAll('.pickChk:checked')).map(cb=>cb.value);
  if (!ids.length) return alert('Select at least one order to generate a picking sheet.');
  const url = 'picking_sheet.php?orders=' + encodeURIComponent(ids.join(','));
  window.open(url, '_blank');
});
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>