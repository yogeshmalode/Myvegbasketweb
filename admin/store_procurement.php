<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Store Procurement';

// Access is already enforced by require_page_access() -> can_access_store_procurement()
// in auth.php (admin always passes; staff only if admins.can_procure = 1 AND
// their store's procurement_mode = 'independent'). This page additionally
// hard-locks a non-admin to their own store — never a dropdown for them —
// so a staff login can never buy stock into a store they don't own.
$isAdmin = admin_role() === 'admin';
$independentStores = $pdo->query("SELECT id, name FROM dark_stores WHERE procurement_mode = 'independent' AND is_active = 1 ORDER BY name")->fetchAll();
$independentIds = array_map('intval', array_column($independentStores, 'id'));

if ($isAdmin) {
    $storeId = (int)($_GET['store_id'] ?? ($independentIds[0] ?? 0));
    if (!in_array($storeId, $independentIds, true)) {
        $storeId = $independentIds[0] ?? 0;
    }
} else {
    $storeId = (int)session_store_id();
}

if ($storeId <= 0 || !in_array($storeId, $independentIds, true)) {
    include __DIR__ . '/includes/admin_header.php';
    echo '<div class="section-head"><h2>Store Procurement</h2></div>';
    echo '<div class="alert alert-error">No independent store is set up for you yet. Ask an admin to mark a store as "Independent" on the Stores page and tie your account to it on the Users page.</div>';
    include __DIR__ . '/includes/admin_footer.php';
    exit;
}

$storeNameSt = $pdo->prepare("SELECT name FROM dark_stores WHERE id = ?");
$storeNameSt->execute([$storeId]);
$storeName = $storeNameSt->fetchColumn() ?: 'Store';

// ---- Small local helpers (kept separate from daily_procurement_dashboard.php's
// identically-named ones so both files can be edited independently without risk
// of a fatal "cannot redeclare" error if either is ever refactored into a shared include). ----
function sp_read_xlsx_simple($filepath) {
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) return null;

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssDom = new SimpleXMLElement($ssXml);
        foreach ($ssDom->si as $si) {
            $sharedStrings[] = isset($si->t) ? (string)$si->t : implode('', array_map(function ($r) { return (string)$r->t; }, $si->r ?? []));
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) return null;

    $dom = new SimpleXMLElement($sheetXml);
    $rows = [];
    foreach ($dom->sheetData->row as $row) {
        $rowData = [];
        $colIndex = 0;
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)/', $ref, $m);
            $colIndex = isset($m[1]) ? sp_col_to_index($m[1]) : $colIndex;
            $type = (string)$cell['t'];
            $value = isset($cell->v) ? (string)$cell->v : '';
            if ($type === 's' && isset($sharedStrings[(int)$value])) {
                $value = $sharedStrings[(int)$value];
            }
            while (count($rowData) < $colIndex) $rowData[] = '';
            $rowData[] = $value;
            $colIndex++;
        }
        $rows[] = $rowData;
    }
    return $rows;
}
function sp_col_to_index($col) {
    $index = 0;
    foreach (str_split($col) as $char) {
        $index = $index * 26 + (ord($char) - ord('A') + 1);
    }
    return $index - 1;
}
function sp_read_csv_simple($filepath) {
    $rows = [];
    if (($handle = fopen($filepath, 'r')) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}
function sp_resolve_bulk_vegetable_id($pdo, $value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    if (is_numeric($value)) return (int)$value;

    $stmt = $pdo->prepare('SELECT id FROM vegetables WHERE LOWER(name) = LOWER(?) OR LOWER(name_mr) = LOWER(?) LIMIT 1');
    $stmt->execute([$value, $value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (($_POST['action'] ?? '') === 'delete_inward') {
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId > 0) {
            // Ownership check: this page may only ever reverse/delete an
            // inward entry that belongs to THIS store, even for admin, so
            // nobody accidentally wipes out another store's purchase history
            // while viewing it through the store switcher.
            $row = $pdo->prepare('SELECT vegetable_id, usable_weight_kg, dark_store_id FROM procurement_inward WHERE id = ? AND dark_store_id = ?');
            $row->execute([$delId, $storeId]);
            $entry = $row->fetch();
            if ($entry) {
                apply_stock_delta($pdo, (int)$entry['vegetable_id'], -(float)$entry['usable_weight_kg'], $storeId, true);
                $pdo->prepare('DELETE FROM procurement_inward WHERE id = ?')->execute([$delId]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Inward entry deleted and stock adjusted back.'];
            }
        }
        header('Location: store_procurement.php' . ($isAdmin ? '?store_id=' . $storeId : ''));
        exit;
    }

    $bulkUploadError = '';
    $bulkUploadMessage = '';
    $purchaseRows = $_POST['purchase'] ?? [];

    // Only products set to "Per-Store" stock mode have an isolated bucket
    // this store can own independently — buying a "Shared" product here
    // would silently add to the one global number every other store (and
    // the central pool) also reads from, which defeats the whole point of
    // "independent". Build the valid id set once and filter against it.
    $perStoreIds = array_map('intval', $pdo->query("SELECT id FROM vegetables WHERE stock_mode = 'per_store'")->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($_FILES['bulk_excel_file']['tmp_name'])) {
        $tmpPath = $_FILES['bulk_excel_file']['tmp_name'];
        $origName = $_FILES['bulk_excel_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $excelRows = sp_read_xlsx_simple($tmpPath);
        } elseif ($ext === 'csv') {
            $excelRows = sp_read_csv_simple($tmpPath);
        } else {
            $excelRows = null;
            $bulkUploadError = 'Please upload a .csv or .xlsx spreadsheet.';
        }

        if ($excelRows !== null && !empty($excelRows)) {
            $header = array_map(function ($field) { return strtolower(trim((string)$field)); }, $excelRows[0]);

            $pdo->beginTransaction();
            try {
                foreach (array_slice($excelRows, 1) as $row) {
                    if (empty(array_filter($row, function ($value) { return trim((string)$value) !== ''; }))) continue;

                    $data = [];
                    foreach ($row as $index => $value) { $data[$header[$index] ?? $index] = $value; }

                    $vegId = sp_resolve_bulk_vegetable_id($pdo, $data['vegetable_id'] ?? $data['id'] ?? $data['item_id'] ?? $data['item'] ?? $data['name'] ?? '');
                    if ($vegId <= 0 || !in_array($vegId, $perStoreIds, true)) continue;

                    $raw = (float)($data['raw_weight_kg'] ?? $data['raw_weight'] ?? 0);
                    $usable = (float)($data['usable_weight_kg'] ?? $data['usable_weight'] ?? 0);
                    if ($raw <= 0 && $usable <= 0) continue;
                    $rate = (float)($data['mandi_rate_per_kg'] ?? $data['mandi_rate'] ?? $data['rate_per_kg'] ?? 0);
                    $sourceType = strtolower(trim((string)($data['source_type'] ?? 'mandi')));
                    if (!in_array($sourceType, ['mandi', 'farmer', 'direct'], true)) $sourceType = 'mandi';

                    $stmt = $pdo->prepare("INSERT INTO procurement_inward (vegetable_id, source_type, source_name, purchase_date, raw_weight_kg, usable_weight_kg, wastage_kg, mandi_rate_per_kg, total_cost, notes, dark_store_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $vegId, $sourceType,
                        trim((string)($data['source_name'] ?? '')) ?: 'Bulk Excel',
                        !empty($data['purchase_date']) ? $data['purchase_date'] : date('Y-m-d'),
                        $raw, $usable, max($raw - $usable, 0), $rate, $usable * $rate,
                        trim((string)($data['notes'] ?? '')) ?: null,
                        $storeId,
                    ]);

                    // Always lands directly in THIS store's own pool, never
                    // central — that's the entire point of "independent".
                    apply_stock_delta($pdo, $vegId, $usable, $storeId);
                }
                $pdo->commit();
                $bulkUploadMessage = 'Excel upload processed successfully.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                $bulkUploadError = 'Excel upload failed: ' . $e->getMessage();
            }
        }
    }

    $pdo->beginTransaction();
    try {
        if (!empty($purchaseRows)) {
            foreach ($purchaseRows as $row) {
                $vegId = (int)($row['vegetable_id'] ?? 0);
                if ($vegId <= 0 || !in_array($vegId, $perStoreIds, true)) continue;

                $raw = (float)($row['raw_weight_kg'] ?? 0);
                $usable = (float)($row['usable_weight_kg'] ?? 0);
                if ($raw <= 0 && $usable <= 0) continue;

                $wastage = max($raw - $usable, 0);
                $rate = (float)($row['mandi_rate_per_kg'] ?? 0);
                $totalCost = $usable * $rate;

                $stmt = $pdo->prepare("INSERT INTO procurement_inward (vegetable_id, source_type, source_name, purchase_date, raw_weight_kg, usable_weight_kg, wastage_kg, mandi_rate_per_kg, total_cost, notes, dark_store_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $vegId,
                    in_array(($row['source_type'] ?? 'mandi'), ['mandi','farmer','direct'], true) ? $row['source_type'] : 'mandi',
                    trim((string)($row['source_name'] ?? '')) ?: null,
                    !empty($row['purchase_date']) ? $row['purchase_date'] : date('Y-m-d'),
                    $raw, $usable, $wastage, $rate, $totalCost,
                    trim((string)($row['notes'] ?? '')) ?: null,
                    $storeId,
                ]);

                apply_stock_delta($pdo, $vegId, $usable, $storeId);
            }
        }

        $pdo->commit();
        if ($bulkUploadError === '') {
            $_SESSION['flash'] = ['type' => 'success', 'message' => !empty($bulkUploadMessage) ? $bulkUploadMessage : 'Store procurement saved successfully.'];
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => $bulkUploadError ?: 'Save failed: ' . $e->getMessage()];
    }

    if ($bulkUploadError !== '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $bulkUploadError];
    }

    header('Location: store_procurement.php' . ($isAdmin ? '?store_id=' . $storeId : ''));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Only Per-Store products are listed — this store owns an isolated bucket
// of these in store_inventory; Shared-mode products are a site-wide number
// that stays off this page entirely (unaffected either way).
$vegStmt = $pdo->prepare("SELECT v.id, v.name, v.name_mr, v.unit, v.category, v.sale_price, v.price, v.min_buffer_stock,
        COALESCE(si.stock, 0) AS stock,
        COALESCE(od.active_order_qty, 0) AS active_order_qty
    FROM vegetables v
    LEFT JOIN store_inventory si ON si.vegetable_id = v.id AND si.dark_store_id = ?
    LEFT JOIN (
        SELECT oi.vegetable_id, SUM(oi.quantity) AS active_order_qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.dark_store_id = ? AND o.order_status NOT IN ('cancelled', 'delivered')
        GROUP BY oi.vegetable_id
    ) od ON od.vegetable_id = v.id
    WHERE v.is_active = 1 AND v.stock_mode = 'per_store'
    ORDER BY v.category, v.name");
$vegStmt->execute([$storeId, $storeId]);
$vegetables = $vegStmt->fetchAll();
foreach ($vegetables as &$veg) {
    $veg['suggested_purchase_qty'] = max((float)$veg['active_order_qty'] + (float)$veg['min_buffer_stock'] - (float)$veg['stock'], 0);
}
unset($veg);

$piqSt = $pdo->prepare("SELECT pi.*, v.name AS vegetable_name, v.unit FROM procurement_inward pi JOIN vegetables v ON v.id = pi.vegetable_id WHERE pi.dark_store_id = ? ORDER BY pi.id DESC LIMIT 20");
$piqSt->execute([$storeId]);
$purchaseRows = $piqSt->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .daily-wrap { display:flex; flex-direction:column; gap:18px; }
  .daily-card { background:#fff; border:1px solid #e6ece7; border-radius:16px; overflow:hidden; box-shadow:0 12px 28px rgba(17,48,37,0.04); }
  .daily-card-head { background:#f6faf7; border-bottom:1px solid #ebf0ed; padding:14px 18px; font-weight:800; letter-spacing:0.02em; color:#1a2a25; }
  .daily-card-body { padding:18px; }
  .daily-table { width:100%; border-collapse:collapse; }
  .daily-table th, .daily-table td { padding:10px 12px; border-bottom:1px solid #edf2ef; text-align:left; font-size:0.83rem; }
  .daily-table th { background:#f8faf8; color:#5a6962; text-transform:uppercase; letter-spacing:0.06em; font-size:0.7rem; }
  .daily-table input, .daily-table select { width:100%; min-height:36px; border:1px solid #dfe8e0; border-radius:8px; padding:0 10px; font:inherit; }
  .daily-form-actions { display:flex; justify-content:flex-end; padding-top:4px; }
  .badge-soft { display:inline-flex; padding:6px 10px; border-radius:999px; font-size:0.72rem; font-weight:700; background:#edf9ef; color:#0e7d45; }
  .badge-soft.info { background:#edf3ff; color:#1457d6; }
  .daily-tabs { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
  .daily-tab { background:#edf4ef; border:1px solid #dce9df; border-radius:10px; padding:10px 16px; font-weight:700; color:#2f453d; cursor:pointer; }
  .daily-tab.active { background:#1f6f3d; color:#fff; border-color:#1f6f3d; }
  .tab-panel { display:none; }
  .tab-panel.active { display:block; }
  .upload-box { background:#f8fbf9; border:1px dashed #bfd3c1; border-radius:12px; padding:18px; }
  @media (max-width:820px) { .daily-table { min-width:900px; } }
</style>

<div class="daily-wrap">
  <div class="admin-page-heading" style="margin-top:0;">
    <div>
      <h1>Store Procurement — <?= h($storeName) ?></h1>
      <p>Independent store: buying here adds stock only to <strong><?= h($storeName) ?></strong>'s own pool — it never touches the central pool or any other store, and only products set to "Per-Store" mode can be bought here.</p>
    </div>
  </div>

  <?php if ($isAdmin && count($independentStores) > 1): ?>
    <form method="get" style="display:flex; gap:8px; align-items:center;">
      <label style="font-weight:700;">Viewing store:</label>
      <select name="store_id" onchange="this.form.submit()">
        <?php foreach ($independentStores as $s): ?>
          <option value="<?= $s['id'] ?>" <?= (int)$s['id'] === $storeId ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <?php if (empty($vegetables)): ?>
    <div class="alert alert-info">No "Per-Store" mode products yet. Switch at least one product to "Per-Store" stock mode on the Add/Edit Vegetable page so this store has something it can independently buy and stock.</div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <div class="daily-tabs">
      <button type="button" class="daily-tab active" data-tab="manual-entry">Manual Entry</button>
      <button type="button" class="daily-tab" data-tab="buy-next">Buy Next</button>
      <button type="button" class="daily-tab" data-tab="excel-upload">Excel Upload</button>
    </div>

    <div id="manual-entry" class="tab-panel active">
      <section class="daily-card">
        <div class="daily-card-head">1. Today's Purchase Inwarding</div>
        <div class="daily-card-body">
          <div style="overflow-x:auto;">
            <table class="daily-table">
              <thead>
                <tr>
                  <th>Item Name</th><th>Source</th><th>Source Name</th><th>Raw Weight Bought (kg)</th><th>Usable Weight (kg)</th><th>Sorting Wastage (kg)</th><th>Rate / Kg</th><th>Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vegetables as $row): ?>
                  <tr>
                    <td>
                      <input type="hidden" name="purchase[<?= (int)$row['id'] ?>][vegetable_id]" value="<?= (int)$row['id'] ?>">
                      <strong><?= h($row['name']) ?></strong><br><small><?= h($row['unit']) ?></small>
                    </td>
                    <td>
                      <select name="purchase[<?= (int)$row['id'] ?>][source_type]">
                        <option value="mandi">Mandi</option>
                        <option value="farmer">Farmer</option>
                        <option value="direct">Direct</option>
                      </select>
                    </td>
                    <td><input type="text" name="purchase[<?= (int)$row['id'] ?>][source_name]" placeholder="Mandi / Farmer name"></td>
                    <td><input type="number" step="0.01" min="0" class="raw-weight" name="purchase[<?= (int)$row['id'] ?>][raw_weight_kg]" value="0"></td>
                    <td><input type="number" step="0.01" min="0" class="usable-weight" name="purchase[<?= (int)$row['id'] ?>][usable_weight_kg]" value="0"></td>
                    <td><input type="number" step="0.01" min="0" class="wastage-weight" name="purchase[<?= (int)$row['id'] ?>][wastage_kg]" value="0" readonly></td>
                    <td><input type="number" step="0.01" min="0" name="purchase[<?= (int)$row['id'] ?>][mandi_rate_per_kg]" value="<?= number_format((float)($row['sale_price'] ?? $row['price'] ?? 0), 2, '.', '') ?>"></td>
                    <td><input type="text" name="purchase[<?= (int)$row['id'] ?>][notes]" placeholder="Optional"></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <div id="buy-next" class="tab-panel">
      <section class="daily-card">
        <div class="daily-card-head">2. What To Buy Next (this store only)</div>
        <div class="daily-card-body">
          <div style="overflow-x:auto;">
            <table class="daily-table">
              <thead><tr><th>Item</th><th>Store Stock</th><th>Live Active Orders</th><th>Min Buffer</th><th>Suggested Purchase Qty</th></tr></thead>
              <tbody>
                <?php foreach ($vegetables as $row): ?>
                  <tr>
                    <td><strong><?= h($row['name']) ?></strong></td>
                    <td><?= number_format((float)$row['stock'], 2) ?> kg</td>
                    <td><?= number_format((float)($row['active_order_qty'] ?? 0), 2) ?> kg</td>
                    <td><?= number_format((float)($row['min_buffer_stock'] ?? 0), 2) ?> kg</td>
                    <td><span class="badge-soft info"><?= number_format((float)($row['suggested_purchase_qty'] ?? 0), 2) ?> kg</span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>

    <div id="excel-upload" class="tab-panel">
      <section class="daily-card">
        <div class="daily-card-head">3. One-Click Excel / CSV Upload</div>
        <div class="daily-card-body">
          <div class="upload-box">
            <p style="margin-top:0; font-weight:700;">Upload an Excel / CSV sheet with columns like:</p>
            <p style="color:#53625b;">vegetable_id, raw_weight_kg, usable_weight_kg, mandi_rate_per_kg, source_type, source_name</p>
            <div class="form-group" style="margin-top:14px;">
              <label for="bulk_excel_file">Choose spreadsheet</label>
              <input type="file" id="bulk_excel_file" name="bulk_excel_file" accept=".csv,.xlsx" />
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="daily-form-actions">
      <button type="submit" class="btn btn-primary">Save Procurement</button>
    </div>
  </form>

  <section class="daily-card">
    <div class="daily-card-head">Recent Inward Entries — <?= h($storeName) ?></div>
    <div class="daily-card-body">
      <div style="overflow-x:auto;">
        <table class="daily-table">
          <thead><tr><th>Date</th><th>Item</th><th>Source</th><th>Raw</th><th>Usable</th><th>Wastage</th><th>Rate</th><th>Cost</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($purchaseRows as $row): ?>
              <tr>
                <td><?= h($row['purchase_date']) ?></td>
                <td><?= h($row['vegetable_name']) ?></td>
                <td><?= h($row['source_type']) ?><?= !empty($row['source_name']) ? ' / ' . h($row['source_name']) : '' ?></td>
                <td><?= number_format((float)$row['raw_weight_kg'], 2) ?> kg</td>
                <td><?= number_format((float)$row['usable_weight_kg'], 2) ?> kg</td>
                <td><?= number_format((float)$row['wastage_kg'], 2) ?> kg</td>
                <td>₹<?= number_format((float)$row['mandi_rate_per_kg'], 2) ?></td>
                <td>₹<?= number_format((float)$row['total_cost'], 2) ?></td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete this inward entry and subtract <?= number_format((float)$row['usable_weight_kg'], 2) ?> kg back out of this store\'s stock?');">
                    <input type="hidden" name="action" value="delete_inward">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="btn" style="background:#fce8e6; color:#9A2E24; border:1px solid #f4cfc9; min-height:30px; padding:0 10px; font-size:0.72rem; border-radius:8px;">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$purchaseRows): ?><tr><td colspan="9" style="text-align:center; color:#68736f;">No purchase inward entries yet for this store.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script>
  document.querySelectorAll('.daily-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      var target = tab.getAttribute('data-tab');
      document.querySelectorAll('.daily-tab').forEach(function (btn) { btn.classList.remove('active'); });
      tab.classList.add('active');
      document.querySelectorAll('.tab-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.id === target);
      });
    });
  });
  document.addEventListener('input', function (event) {
    if (event.target.classList.contains('raw-weight') || event.target.classList.contains('usable-weight')) {
      var row = event.target.closest('tr');
      var raw = parseFloat(row.querySelector('.raw-weight').value) || 0;
      var usable = parseFloat(row.querySelector('.usable-weight').value) || 0;
      row.querySelector('.wastage-weight').value = Math.max(raw - usable, 0).toFixed(2);
    }
  });
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
