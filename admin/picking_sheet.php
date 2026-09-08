<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Picking Sheet';
$orderIdsParam = $_GET['orders'] ?? '';
$orderIds = array_filter(array_map('intval', explode(',', $orderIdsParam)));
if (!$orderIds) {
    echo "<p>No orders specified.</p>"; exit;
}
// Aggregate items across orders: group by vegetable_id and variant_label
$placeholders = implode(',', array_fill(0, count($orderIds), '?'));
$stmt = $pdo->prepare("SELECT oi.vegetable_id, oi.vegetable_variant_id, oi.variant_label, oi.name, SUM(oi.quantity) as total_qty, v.unit
    FROM order_items oi
    LEFT JOIN vegetables v ON v.id = oi.vegetable_id
    WHERE oi.order_id IN ($placeholders)
    GROUP BY oi.vegetable_id, COALESCE(oi.variant_label, '')");
$stmt->execute($orderIds);
$rows = $stmt->fetchAll();

// Fetch measured quantities per vegetable/variant per order group (optional)
$measuredStmt = $pdo->prepare("SELECT vegetable_id, COALESCE(variant_label,'') as variant_label, SUM(COALESCE(measured_quantity,0)) as measured_total FROM order_items WHERE order_id IN ($placeholders) GROUP BY vegetable_id, COALESCE(variant_label,'')");
$measuredStmt->execute($orderIds);
$measured = [];
foreach($measuredStmt->fetchAll() as $m){ $measured[$m['vegetable_id'] . '||' . $m['variant_label']] = $m['measured_total']; }

include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Picking Sheet</h2>
  <p>Orders: <?= implode(', ', $orderIds) ?></p>
  <p><a class="btn" href="#" onclick="window.print();return false;">Print</a> <a class="btn" href="picking_sheet.php?orders=<?= urlencode($orderIdsParam) ?>&download=1">Download CSV</a></p>
</div>

<?php if (!empty($_GET['download'])) {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="picking_sheet_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w'); fputcsv($out, ['Vegetable ID','Name','Variant','Total Quantity','Unit']);
    foreach($rows as $r) fputcsv($out, [$r['vegetable_id'], $r['name'], $r['variant_label'], $r['total_qty'], $r['unit']]); fclose($out); exit;
}
?>

<div class="form-card">
  <h3>Pick list</h3>
  <table>
    <thead><tr><th>#</th><th>Product</th><th>Variant</th><th>Total Quantity</th><th>Measured (sum)</th><th>Unit</th></tr></thead>
    <tbody>
    <?php $i=1; foreach($rows as $r): $key = $r['vegetable_id'] . '||' . ($r['variant_label'] ?? ''); $meas = isset($measured[$key]) ? rtrim(rtrim(number_format($measured[$key],3),'0'),'.') : ''; ?>
      <tr>
        <td><?= $i++ ?></td>
        <td><?= h($r['name']) ?></td>
        <td><?= h($r['variant_label']) ?></td>
        <td><?= rtrim(rtrim(number_format($r['total_qty'],3),'0'),'.') ?></td>
        <td><input type="number" step="0.001" min="0" value="<?= $meas ?>" data-veg="<?= $r['vegetable_id'] ?>" data-variant="<?= h($r['variant_label']) ?>" class="meas-input" style="width:100px;"></td>
        <td><?= h($r['unit']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:#5B6656;">No items found for selected orders.</td></tr><?php endif; ?>
    </tbody>
  </table>
  <div style="text-align:right; margin-top:10px;"><button class="btn" id="saveMeasured">Save measured quantities</button></div>
  <script>
  document.getElementById('saveMeasured')?.addEventListener('click', function(){
    const inputs = Array.from(document.querySelectorAll('.meas-input'));
    const payload = inputs.map(i=>({ vegetable_id: i.dataset.veg, variant_label: i.dataset.variant, measured_total: i.value }));
    fetch('../ajax/update_measured_totals.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ orders: '<?= h($orderIdsParam) ?>', payload: payload, csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{ if(data.success) alert('Saved'); else alert('Failed'); }).catch(()=>alert('Network error'));
  });
  </script>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>