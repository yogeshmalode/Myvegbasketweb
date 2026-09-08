<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Delivery Manifest';
$mid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($mid<=0) { echo '<p>Manifest id required.</p>'; exit; }
$st = $pdo->prepare('SELECT m.*, r.name as rider_name FROM delivery_manifests m LEFT JOIN riders r ON r.id = m.rider_id WHERE m.id = ?'); $st->execute([$mid]); $m = $st->fetch();
if (!$m) { echo '<p>Manifest not found.</p>'; exit; }
$items = $pdo->prepare('SELECT dmi.*, o.customer_name, o.phone, o.address FROM delivery_manifest_items dmi JOIN orders o ON o.id = dmi.order_id WHERE dmi.manifest_id = ? ORDER BY dmi.seq'); $items->execute([$mid]); $rows = $items->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Manifest #<?= $m['id'] ?></h2>
  <p>Rider: <?= $m['rider_name'] ?: '<em>Unassigned</em>' ?> — Orders: <?= $m['total_orders'] ?> — Created: <?= $m['created_at'] ?></p></div>

<div class="form-card">
  <div style="display:flex; justify-content:space-between; align-items:center;">
    <h3>Delivery Manifest</h3>
    <div><button class="btn" onclick="window.print();">Print</button></div>
  </div>
  <ol>
    <?php foreach($rows as $r): ?>
      <li style="margin-bottom:10px; padding-bottom:6px; border-bottom:1px dashed #E6EAE2;">
        <div style="display:flex; gap:12px; align-items:center;">
          <div style="width:110px; text-align:center;"><svg id="barcode-<?= $r['order_id'] ?>"></svg><div style="font-size:0.8rem; color:#5B6656;">#ORD-<?= str_pad($r['order_id'],5,'0',STR_PAD_LEFT) ?></div></div>
          <div>
            <strong><?= h($r['customer_name']) ?></strong><br>
            <div style="color:#5B6656; font-size:0.95rem;"><?= h($r['phone']) ?> — <?= h($r['address']) ?></div>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
</div>

<!-- JsBarcode CDN -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
<?php foreach($rows as $r): ?>
  try { JsBarcode('#barcode-<?= $r['order_id'] ?>', 'ORD<?= $r['order_id'] ?>', {format: 'CODE39', width:1.5, height:40, displayValue:false}); } catch(e) {}
<?php endforeach; ?>
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>