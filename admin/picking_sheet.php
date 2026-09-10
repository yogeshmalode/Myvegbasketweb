<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Picking Sheet';
$orderIdsParam = $_GET['orders'] ?? '';
$orderIds = array_values(array_filter(array_map('intval', explode(',', $orderIdsParam))));
if (!$orderIds) {
    echo '<p>No orders specified.</p>';
    exit;
}

$placeholders = implode(',', array_fill(0, count($orderIds), '?'));
$stmt = $pdo->prepare("
    SELECT oi.vegetable_id, oi.vegetable_variant_id, oi.variant_label, oi.name, SUM(oi.quantity) AS total_qty, v.unit
    FROM order_items oi
    LEFT JOIN vegetables v ON v.id = oi.vegetable_id
    WHERE oi.order_id IN ($placeholders)
    GROUP BY oi.vegetable_id, COALESCE(oi.variant_label, '')
    ORDER BY oi.name, COALESCE(oi.variant_label, '')
");
$stmt->execute($orderIds);
$rows = $stmt->fetchAll();

$measuredStmt = $pdo->prepare("
    SELECT vegetable_id, COALESCE(variant_label, '') AS variant_label, SUM(COALESCE(measured_quantity, 0)) AS measured_total
    FROM order_items
    WHERE order_id IN ($placeholders)
    GROUP BY vegetable_id, COALESCE(variant_label, '')
");
$measuredStmt->execute($orderIds);
$measured = [];
foreach ($measuredStmt->fetchAll() as $measurementRow) {
    $measured[$measurementRow['vegetable_id'] . '||' . $measurementRow['variant_label']] = $measurementRow['measured_total'];
}

if (!empty($_GET['download'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="picking_sheet_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Vegetable ID', 'Name', 'Variant', 'Total Quantity', 'Unit']);
    foreach ($rows as $row) {
        fputcsv($out, [$row['vegetable_id'], $row['name'], $row['variant_label'], $row['total_qty'], $row['unit']]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .picking-shell {
    display: grid;
    gap: 18px;
  }
  .picking-hero {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
  }
  .picking-hero h2 {
    margin: 0;
    color: #0c5a42;
    font-size: clamp(1.4rem, 2vw, 2rem);
    font-weight: 800;
  }
  .picking-hero p {
    margin: 8px 0 0;
    color: #617068;
  }
  .picking-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
  }
  .picking-actions .btn {
    min-height: 40px;
    padding: 0 16px;
    border-radius: 999px;
    font-weight: 700;
  }
  .picking-card {
    background: #fff;
    border: 1px solid #dfe8df;
    border-radius: 20px;
    box-shadow: 0 10px 28px rgba(18, 52, 40, 0.05);
    overflow: hidden;
  }
  .picking-meta {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    padding: 18px;
    border-bottom: 1px solid #eef2ed;
    background: #fbfdfb;
  }
  .picking-meta strong {
    display: block;
    font-size: 1.2rem;
    color: #1d4338;
  }
  .picking-meta span {
    color: #67766d;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 700;
  }
  .picking-table-wrap {
    overflow-x: auto;
  }
  .picking-table {
    width: 100%;
    min-width: 860px;
    border-collapse: collapse;
  }
  .picking-table th {
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
  .picking-table td {
    padding: 14px;
    border-bottom: 1px solid #edf1ed;
    color: #1f2d29;
    font-size: 0.84rem;
  }
  .picking-table tbody tr:last-child td {
    border-bottom: none;
  }
  .meas-input {
    width: 110px;
    min-height: 38px;
    border: 1px solid #d6dfd7;
    border-radius: 10px;
    padding: 8px 10px;
  }
  .save-row {
    display: flex;
    justify-content: flex-end;
    padding: 16px 18px 18px;
  }
  @media print {
    .admin-topbar,
    .admin-sidebar,
    .admin-sidebar-overlay,
    .picking-actions,
    .save-row {
      display: none !important;
    }
    .admin-main,
    .container,
    .admin-content {
      margin: 0 !important;
      padding: 0 !important;
      width: 100% !important;
      max-width: 100% !important;
    }
    .picking-card {
      box-shadow: none;
      border: none;
    }
  }
</style>

<?php
$distinctProducts = count($rows);
$totalQty = 0.0;
foreach ($rows as $row) {
    $totalQty += (float) $row['total_qty'];
}
?>

<div class="picking-shell">
  <div class="picking-hero">
    <div>
      <h2>Batch Picking Sheet</h2>
      <p>Orders included: <?= h(implode(', ', $orderIds)) ?></p>
    </div>
    <div class="picking-actions">
      <button class="btn" type="button" onclick="window.print()">Print</button>
      <a class="btn" href="picking_sheet.php?orders=<?= urlencode($orderIdsParam) ?>&download=1">Download CSV</a>
    </div>
  </div>

  <div class="picking-card">
    <div class="picking-meta">
      <div><span>Selected Orders</span><strong><?= count($orderIds) ?></strong></div>
      <div><span>Distinct Products</span><strong><?= $distinctProducts ?></strong></div>
      <div><span>Total Quantity</span><strong><?= rtrim(rtrim(number_format($totalQty, 3), '0'), '.') ?></strong></div>
    </div>

    <div class="picking-table-wrap">
      <table class="picking-table">
        <thead><tr><th>#</th><th>Product</th><th>Variant</th><th>Total Quantity</th><th>Measured</th><th>Unit</th></tr></thead>
        <tbody>
        <?php $i = 1; foreach ($rows as $row): ?>
          <?php
          $key = $row['vegetable_id'] . '||' . ($row['variant_label'] ?? '');
          $measuredValue = isset($measured[$key]) ? rtrim(rtrim(number_format($measured[$key], 3), '0'), '.') : '';
          ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= h($row['name']) ?></td>
            <td><?= h($row['variant_label'] ?: '-') ?></td>
            <td><?= rtrim(rtrim(number_format($row['total_qty'], 3), '0'), '.') ?></td>
            <td><input type="number" step="0.001" min="0" value="<?= h($measuredValue) ?>" data-veg="<?= (int) $row['vegetable_id'] ?>" data-variant="<?= h($row['variant_label']) ?>" class="meas-input"></td>
            <td><?= h($row['unit']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="6" style="text-align:center;color:#5B6656;">No items found for selected orders.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="save-row">
      <button class="btn" id="saveMeasured" type="button">Save measured quantities</button>
    </div>
  </div>
</div>

<script>
document.getElementById('saveMeasured')?.addEventListener('click', function () {
  const button = this;
  const payload = Array.from(document.querySelectorAll('.meas-input')).map((input) => ({
    vegetable_id: input.dataset.veg,
    variant_label: input.dataset.variant,
    measured_total: input.value
  }));
  button.disabled = true;
  button.textContent = 'Saving...';
  fetch('../ajax/update_measured_totals.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ orders: '<?= h($orderIdsParam) ?>', payload: payload, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then((r) => r.json()).then((data) => {
    if (!data.success) {
      throw new Error(data.error || 'Save failed');
    }
    alert('Measured quantities saved.');
  }).catch((error) => {
    alert(error.message || 'Network error');
  }).finally(() => {
    button.disabled = false;
    button.textContent = 'Save measured quantities';
  });
});
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>