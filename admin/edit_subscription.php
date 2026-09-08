<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Edit Subscription';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('subscriptions.php');
$st = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ?'); $st->execute([$id]); $s = $st->fetch();
if (!$s) { $_SESSION['flash']=['type'=>'error','message'=>'Subscription not found']; redirect('subscriptions.php'); }
$products = $pdo->query("SELECT id, name, unit FROM vegetables WHERE is_active=1 ORDER BY category, name")->fetchAll();
$variants = $pdo->prepare('SELECT id, label FROM vegetable_variants WHERE vegetable_id = ? ORDER BY sort_order'); $variants->execute([$s['product_id']]); $currentVariants = $variants->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Edit Subscription #<?= $s['id'] ?></h2></div>
<div class="form-card" style="max-width:720px;">
  <form id="editSubForm">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= $s['id'] ?>">
    <div class="form-group"><label>Customer name</label><input name="customer_name" value="<?= h($s['customer_name']) ?>" required></div>
    <div class="form-group"><label>Customer phone</label><input name="customer_phone" value="<?= h($s['customer_phone']) ?>"></div>
    <div class="form-group"><label>Product</label>
      <select name="product_id" id="productSelect">
        <?php foreach($products as $p): ?><option value="<?= $p['id'] ?>" <?= $p['id']==$s['product_id'] ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h($p['unit']) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Variant</label>
      <select name="variant_id" id="variantSelect">
        <option value="">— default —</option>
        <?php foreach($currentVariants as $v): ?><option value="<?= $v['id'] ?>" <?= $v['id']==$s['variant_id'] ? 'selected' : '' ?>><?= h($v['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label>Quantity</label><input type="number" step="0.001" min="0.001" name="quantity" value="<?= h($s['quantity']) ?>"></div>
    <div class="form-group"><label>Frequency</label>
      <select name="frequency">
        <option value="daily" <?= $s['frequency']==='daily' ? 'selected' : '' ?>>Daily</option>
        <option value="alternate" <?= $s['frequency']==='alternate' ? 'selected' : '' ?>>Alternate day</option>
        <option value="weekly" <?= $s['frequency']==='weekly' ? 'selected' : '' ?>>Weekly</option>
      </select>
    </div>
    <div class="form-group"><label>Next delivery date</label><input type="date" name="next_delivery_date" value="<?= h($s['next_delivery_date']) ?>"></div>
    <div class="form-group"><label>Active</label><select name="active"><option value="1" <?= $s['active'] ? 'selected' : '' ?>>Yes</option><option value="0" <?= !$s['active'] ? 'selected' : '' ?>>No</option></select></div>
    <div class="form-group"><label>Notes</label><textarea name="notes"><?= h($s['notes']) ?></textarea></div>
    <div style="text-align:right;"><button class="btn btn-primary" type="submit">Save changes</button></div>
  </form>
</div>
<script>
const form = document.getElementById('editSubForm');
form.addEventListener('submit', function(e){
  e.preventDefault();
  const fd = new FormData(form);
  fetch('../ajax/update_subscription.php', {method:'POST', body: fd}).then(r=>r.json()).then(data=>{ if(data.success) location.href='subscriptions.php'; else alert('Error: '+(data.error||'Failed')); }).catch(()=>alert('Network error'));
});

// Load variants when product changes
document.getElementById('productSelect')?.addEventListener('change', function(){
  const pid = this.value; const sel = document.getElementById('variantSelect'); sel.innerHTML = '<option value="">— default —</option>';
  if(!pid) return;
  fetch('../ajax/get_variants_for_product.php?product_id='+encodeURIComponent(pid)).then(r=>r.json()).then(data=>{ if(data.success) data.variants.forEach(v=>{ const o=document.createElement('option'); o.value=v.id; o.textContent=v.label; sel.appendChild(o); }); });
});
</script>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>