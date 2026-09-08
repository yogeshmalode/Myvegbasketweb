<?php
require_once __DIR__ . '/config.php';
if (!is_customer_logged_in()) { redirect('login.php'); }
$page_title = 'My Subscriptions';
$cid = $_SESSION['customer_id'];
$subs = $pdo->prepare('SELECT s.*, v.name as product_name, vv.label as variant_label FROM subscriptions s LEFT JOIN vegetables v ON v.id = s.product_id LEFT JOIN vegetable_variants vv ON vv.id = s.variant_id WHERE s.customer_id = ? ORDER BY s.next_delivery_date ASC');
$subs->execute([$cid]); $rows = $subs->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <div class="section-head"><h2>My Subscriptions</h2><p>Manage your recurring deliveries here.</p></div>
  <div class="form-card">
    <?php if (empty($rows)): ?><p>No subscriptions found.</p><?php else: ?>
      <table><thead><tr><th>Product</th><th>Qty</th><th>Frequency</th><th>Next</th><th>Active</th><th>Action</th></tr></thead><tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><?= h($r['product_name']) ?><?= $r['variant_label'] ? ' <small>(' . h($r['variant_label']) . ')</small>' : '' ?></td>
          <td><?= rtrim(rtrim(number_format($r['quantity'],3),'0'),'.') ?> <?= h($r['unit']) ?></td>
          <td><?= h($r['frequency']) ?></td>
          <td><?= $r['next_delivery_date'] ?: '<em>Not scheduled</em>' ?></td>
          <td><?= $r['active'] ? 'Yes' : 'No' ?></td>
          <td><?php if ($r['active']): ?><button onclick="toggle(<?= $r['id'] ?>, this)">Pause</button><?php else: ?><button onclick="toggle(<?= $r['id'] ?>, this)">Resume</button><?php endif; ?></td>
        </tr>
        <?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </div>
</div>
<script>
function toggle(id, btn){ fetch('ajax/toggle_subscription.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ id: id, csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{ if(data.success) location.reload(); else alert('Error'); }).catch(()=>alert('Network error')); }
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>