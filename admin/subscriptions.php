<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Subscriptions Management';

// Create table if missing (safe to run repeatedly)
$pdo->exec(file_get_contents(__DIR__ . '/../migration_subscriptions.sql'));

$subscriptions = $pdo->query("SELECT s.*, v.name as product_name, vv.label as variant_label FROM subscriptions s LEFT JOIN vegetables v ON v.id = s.product_id LEFT JOIN vegetable_variants vv ON vv.id = s.variant_id ORDER BY s.active DESC, s.next_delivery_date ASC, s.id DESC")->fetchAll();
$products = $pdo->query("SELECT id, name, unit FROM vegetables WHERE is_active=1 ORDER BY category, name")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head"><h2>Subscription Management</h2>
  <p>Manage customer recurring orders (daily/alternate/weekly). Create a subscription by choosing a product, quantity and delivery frequency. Use the Edit action to pause or change next delivery date.</p>
</div>

<div style="display:flex; gap:18px;">
  <div style="flex:1; max-width:680px;">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>Customer</th><th>Product</th><th>Qty</th><th>Frequency</th><th>Next delivery</th><th>Active</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($subscriptions as $s): ?>
            <tr>
              <td><?= $s['id'] ?></td>
              <td><?= h($s['customer_name']) ?><?= $s['customer_phone'] ? '<br><small>' . h($s['customer_phone']) . '</small>' : '' ?></td>
              <td><?= h($s['product_name']) ?><?= $s['variant_label'] ? ' <small>(' . h($s['variant_label']) . ')</small>' : '' ?></td>
              <td><?= rtrim(rtrim(number_format($s['quantity'],3), '0'), '.') ?> <?= h($s['unit']) ?></td>
              <td><?= h($s['frequency']) ?></td>
              <td><?= $s['next_delivery_date'] ? h($s['next_delivery_date']) : '<em>Not scheduled</em>' ?></td>
              <td><?= $s['active'] ? 'Yes' : 'No' ?></td>
              <td>
                <a href="edit_subscription.php?id=<?= $s['id'] ?>" class="action-link">Edit</a>
                <a href="delete_subscription.php?id=<?= $s['id'] ?>" class="action-link delete" onclick="return confirm('Delete subscription #<?= $s['id'] ?>?')">Delete</a>
                <?php if ($s['active']): ?>
                  <button type="button" class="action-link" onclick="toggleSub(<?= $s['id'] ?>, this)">Pause</button>
                <?php else: ?>
                  <button type="button" class="action-link" onclick="toggleSub(<?= $s['id'] ?>, this)">Resume</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($subscriptions)): ?>
            <tr><td colspan="8" style="text-align:center; color:#5B6656;">No subscriptions yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <aside style="width:340px;">
    <div class="form-card">
      <h3>Create new subscription</h3>
      <form id="createSubForm" method="post" action="../ajax/create_subscription.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-group"><label>Customer name</label><input name="customer_name" required></div>
        <div class="form-group"><label>Customer phone</label><input name="customer_phone"></div>
        <div class="form-group"><label>Product</label>
          <select name="product_id" id="productSelect" required>
            <option value="">— Choose product —</option>
            <?php foreach ($products as $p): ?><option value="<?= $p['id'] ?>" data-unit="<?= h($p['unit']) ?>"><?= h($p['name']) ?> (<?= h($p['unit']) ?>)</option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label>Variant (optional)</label><select name="variant_id" id="variantSelect"><option value="">— default —</option></select></div>
        <div class="form-group"><label>Quantity</label><input type="number" step="0.001" min="0.001" name="quantity" value="1"></div>
        <div class="form-group"><label>Frequency</label>
          <select name="frequency" id="frequency">
            <option value="daily">Daily</option>
            <option value="alternate">Alternate day</option>
            <option value="weekly">Weekly</option>
          </select>
        </div>
        <div class="form-group"><label>Next delivery date</label><input type="date" name="next_delivery_date"></div>
        <div style="text-align:right;"><button class="btn btn-primary" type="submit">Create subscription</button></div>
      </form>
      <p style="font-size:0.85rem; color:#666; margin-top:8px;">Note: This only creates subscription records. A scheduler/cron should run daily to generate orders from active subscriptions.</p>
    </div>
  </aside>
</div>

<script>
// Load variants for selected product
const productSelect = document.getElementById('productSelect');
const variantSelect = document.getElementById('variantSelect');
productSelect?.addEventListener('change', function(){
  const pid = this.value;
  variantSelect.innerHTML = '<option value="">— default —</option>';
  if (!pid) return;
  fetch('../ajax/get_variants_for_product.php?product_id=' + encodeURIComponent(pid)).then(r=>r.json()).then(data=>{
    if (data.success && data.variants.length){
      data.variants.forEach(v=>{ const o = document.createElement('option'); o.value = v.id; o.textContent = v.label + ' — ₹' + parseFloat(v.price).toFixed(2); variantSelect.appendChild(o); });
    }
  });
});

// AJAX form submit
document.getElementById('createSubForm').addEventListener('submit', function(e){
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  fetch(form.action, { method: 'POST', body: fd }).then(r=>r.json()).then(data=>{
    if (data.success) location.reload(); else alert('Error: ' + (data.error||'Failed'));
  }).catch(()=>alert('Network error'));
});
</script>

<script>
function toggleSub(id, btn){
  if(!confirm('Are you sure?')) return;
  btn.disabled = true;
  fetch('../ajax/toggle_subscription.php', { method: 'POST', headers: {'Content-Type':'application/json'}, body: JSON.stringify({ id: id, csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{
    if(data.success) location.reload(); else alert('Error: '+(data.error||'Failed'));
  }).catch(()=>alert('Network error')).finally(()=>btn.disabled=false);
}
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
