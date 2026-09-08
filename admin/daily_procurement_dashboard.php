<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Daily Procurement & Pricing Dashboard';

function read_xlsx_simple($filepath) {
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) return null;

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else {
                    foreach ($si->r as $r) {
                        $text .= (string)$r->t;
                    }
                }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) return null;

    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) return null;

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)(\d+)/', $ref, $m);
            $colIndex = 0;
            if (!empty($m[1])) {
                foreach (str_split($m[1]) as $ch) {
                    $colIndex = $colIndex * 26 + (ord($ch) - 64);
                }
            }
            $colIndex = max(0, $colIndex - 1);

            $type = (string)$cell['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)($cell->v ?? 0)] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }
            $cells[$colIndex] = $value;
        }

        ksort($cells);
        $rows[] = array_values($cells);
    }

    return $rows;
}

function read_csv_simple($filepath) {
    $rows = [];
    if (($handle = fopen($filepath, 'r')) !== false) {
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

function resolve_bulk_vegetable_id($pdo, $value) {
    $value = trim((string)$value);
    if ($value === '') return 0;

    if (is_numeric($value)) {
        return (int)$value;
    }

    $stmt = $pdo->prepare('SELECT id FROM vegetables WHERE LOWER(name) = LOWER(?) OR LOWER(name_mr) = LOWER(?) LIMIT 1');
    $stmt->execute([$value, $value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $bulkUploadError = '';
    $bulkUploadMessage = '';
    $purchaseRows = $_POST['purchase'] ?? [];
    $sellingPrices = $_POST['selling_price'] ?? [];

    if (!empty($_FILES['bulk_excel_file']['tmp_name'])) {
        $tmpPath = $_FILES['bulk_excel_file']['tmp_name'];
        $origName = $_FILES['bulk_excel_file']['name'];
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $excelRows = read_xlsx_simple($tmpPath);
        } elseif ($ext === 'csv') {
            $excelRows = read_csv_simple($tmpPath);
        } else {
            $excelRows = null;
            $bulkUploadError = 'Please upload a .csv or .xlsx spreadsheet.';
        }

        if ($excelRows !== null && !empty($excelRows)) {
            $header = array_map(function ($field) {
                return strtolower(trim((string)$field));
            }, $excelRows[0]);

            $pdo->beginTransaction();
            try {
                foreach (array_slice($excelRows, 1) as $row) {
                    if (empty(array_filter($row, function ($value) { return trim((string)$value) !== ''; }))) {
                        continue;
                    }

                    $data = [];
                    foreach ($row as $index => $value) {
                        $data[$header[$index] ?? $index] = $value;
                    }

                    $vegId = resolve_bulk_vegetable_id($pdo, $data['vegetable_id'] ?? $data['id'] ?? $data['item_id'] ?? $data['item'] ?? $data['name'] ?? '');
                    if ($vegId <= 0) {
                        continue;
                    }

                    $raw = (float)($data['raw_weight_kg'] ?? $data['raw_weight'] ?? 0);
                    $usable = (float)($data['usable_weight_kg'] ?? $data['usable_weight'] ?? 0);
                    $rate = (float)($data['mandi_rate_per_kg'] ?? $data['mandi_rate'] ?? $data['rate_per_kg'] ?? 0);
                    $sellingPrice = isset($data['selling_price']) ? (float)$data['selling_price'] : 0;
                    $sourceType = strtolower(trim((string)($data['source_type'] ?? 'mandi')));
                    if (!in_array($sourceType, ['mandi', 'farmer', 'direct'], true)) {
                        $sourceType = 'mandi';
                    }

                    $stmt = $pdo->prepare("INSERT INTO procurement_inward (vegetable_id, source_type, source_name, purchase_date, raw_weight_kg, usable_weight_kg, wastage_kg, mandi_rate_per_kg, total_cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $vegId,
                        $sourceType,
                        trim((string)($data['source_name'] ?? '')) ?: 'Bulk Excel',
                        !empty($data['purchase_date']) ? $data['purchase_date'] : date('Y-m-d'),
                        $raw,
                        $usable,
                        max($raw - $usable, 0),
                        $rate,
                        $usable * $rate,
                        trim((string)($data['notes'] ?? '')) ?: null,
                    ]);

                    $updateStock = $pdo->prepare("UPDATE vegetables SET stock = stock + ? WHERE id = ?");
                    $updateStock->execute([$usable, $vegId]);

                    if ($sellingPrice > 0) {
                        $pdo->prepare("UPDATE vegetables SET sale_price = ?, price = ? WHERE id = ?")->execute([$sellingPrice, $sellingPrice, $vegId]);
                        $pdo->prepare("UPDATE dynamic_prices SET is_current = 0 WHERE vegetable_id = ?")->execute([$vegId]);
                        $pdo->prepare("INSERT INTO dynamic_prices (vegetable_id, selling_price, effective_date, is_current) VALUES (?, ?, CURDATE(), 1)")->execute([$vegId, $sellingPrice]);
                    }
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
                if ($vegId <= 0) continue;

                $raw = (float)($row['raw_weight_kg'] ?? 0);
                $usable = (float)($row['usable_weight_kg'] ?? 0);
                $wastage = max($raw - $usable, 0);
                $rate = (float)($row['mandi_rate_per_kg'] ?? 0);
                $totalCost = $usable * $rate;

                $stmt = $pdo->prepare("INSERT INTO procurement_inward (vegetable_id, source_type, source_name, purchase_date, raw_weight_kg, usable_weight_kg, wastage_kg, mandi_rate_per_kg, total_cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $vegId,
                    in_array(($row['source_type'] ?? 'mandi'), ['mandi','farmer','direct'], true) ? $row['source_type'] : 'mandi',
                    trim((string)($row['source_name'] ?? '')) ?: null,
                    !empty($row['purchase_date']) ? $row['purchase_date'] : date('Y-m-d'),
                    $raw,
                    $usable,
                    $wastage,
                    $rate,
                    $totalCost,
                    trim((string)($row['notes'] ?? '')) ?: null,
                ]);

                $updateStock = $pdo->prepare("UPDATE vegetables SET stock = stock + ? WHERE id = ?");
                $updateStock->execute([$usable, $vegId]);
            }
        }

        if (!empty($sellingPrices)) {
            foreach ($sellingPrices as $vegId => $price) {
                $itemId = (int)$vegId;
                $priceValue = (float)$price;
                if ($itemId <= 0 || $priceValue <= 0) continue;

                $pdo->prepare("UPDATE vegetables SET sale_price = ?, price = ? WHERE id = ?")->execute([$priceValue, $priceValue, $itemId]);
                $pdo->prepare("UPDATE dynamic_prices SET is_current = 0 WHERE vegetable_id = ?")->execute([$itemId]);
                $pdo->prepare("INSERT INTO dynamic_prices (vegetable_id, selling_price, effective_date, is_current) VALUES (?, ?, CURDATE(), 1)")->execute([$itemId, $priceValue]);
            }
        }

        $pdo->commit();
        if ($bulkUploadError === '') {
            $_SESSION['flash'] = ['type' => 'success', 'message' => !empty($bulkUploadMessage) ? $bulkUploadMessage : 'Daily procurement and pricing data saved successfully.'];
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => $bulkUploadError ?: 'Save failed: ' . $e->getMessage()];
    }

    if ($bulkUploadError !== '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $bulkUploadError];
    }

    header('Location: daily_procurement_dashboard.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$vegetables = $pdo->query("SELECT v.*, COALESCE(od.active_order_qty, 0) AS active_order_qty,
    GREATEST(COALESCE(od.active_order_qty, 0) + COALESCE(v.min_buffer_stock, 0) - v.stock, 0) AS suggested_purchase_qty
    FROM vegetables v
    LEFT JOIN (
        SELECT oi.vegetable_id, SUM(oi.quantity) AS active_order_qty
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE o.order_status NOT IN ('cancelled', 'delivered')
        GROUP BY oi.vegetable_id
    ) od ON od.vegetable_id = v.id
    WHERE v.is_active = 1
    ORDER BY v.category, v.name")->fetchAll();

$purchaseRows = $pdo->query("SELECT pi.*, v.name AS vegetable_name, v.unit FROM procurement_inward pi JOIN vegetables v ON v.id = pi.vegetable_id ORDER BY pi.id DESC LIMIT 20")->fetchAll();

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
  .badge-soft.warning { background:#fff4dc; color:#aa6b00; }
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
      <h1>Daily Procurement & Pricing Dashboard</h1>
      <p>D2C fruit and vegetable operations dashboard.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

    <div class="daily-tabs">
      <button type="button" class="daily-tab active" data-tab="manual-entry">Manual Entry</button>
      <button type="button" class="daily-tab" data-tab="buy-next">Buy Next</button>
      <button type="button" class="daily-tab" data-tab="pricing">Selling Price</button>
      <button type="button" class="daily-tab" data-tab="excel-upload">Excel Upload</button>
    </div>

    <div id="manual-entry" class="tab-panel active">
      <section class="daily-card">
        <div class="daily-card-head">1. Today's Purchase Inwarding (Ajacha Kharedi Kelela Mal)</div>
        <div class="daily-card-body">
          <div style="overflow-x:auto;">
            <table class="daily-table">
              <thead>
                <tr>
                  <th>Item Name</th>
                  <th>Source</th>
                  <th>Source Name</th>
                  <th>Raw Weight Bought (kg)</th>
                  <th>Usable Weight (kg)</th>
                  <th>Sorting Wastage (kg)</th>
                  <th>Mandi Rate / Kg</th>
                  <th>Notes</th>
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
        <div class="daily-card-head">2. What To Buy Next Estimation (Navin Ky Kharedi Karave Lagel?)</div>
        <div class="daily-card-body">
          <div style="overflow-x:auto;">
            <table class="daily-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Available Stock</th>
                  <th>Live Active Orders</th>
                  <th>Min Buffer</th>
                  <th>Suggested Purchase Qty</th>
                </tr>
              </thead>
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

    <div id="pricing" class="tab-panel">
      <section class="daily-card">
        <div class="daily-card-head">3. Today's Selling Price Fixing (Ajchi Selling Price Ky Tharavayche)</div>
        <div class="daily-card-body">
          <div style="margin-bottom:18px;">
            <div class="alert alert-info" style="margin:0 0 12px;">Update All items quickly: change price, sale price, or stock and click Save for that row. Changes apply immediately.</div>
          </div>
          <div style="overflow-x:auto;">
            <table class="daily-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Price (₹)</th>
                  <th>Sale Price (₹)</th>
                  <th>Stock</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($vegetables as $row): ?>
                  <tr id="quick-row-<?= (int)$row['id'] ?>">
                    <td><strong><?= h($row['name']) ?></strong><br><small><?= h($row['unit']) ?></small></td>
                    <td><input type="number" step="0.01" min="0" class="quick-price" id="quick-price-<?= (int)$row['id'] ?>" value="<?= number_format((float)($row['price'] ?? 0), 2, '.', '') ?>"></td>
                    <td><input type="number" step="0.01" min="0" class="quick-sale-price" id="quick-sale-price-<?= (int)$row['id'] ?>" value="<?= number_format((float)($row['sale_price'] ?? $row['price'] ?? 0), 2, '.', '') ?>"></td>
                    <td><input type="number" step="1" min="0" class="quick-stock" id="quick-stock-<?= (int)$row['id'] ?>" value="<?= number_format((float)($row['stock'] ?? 0), 0, '.', '') ?>"></td>
                    <td>
                      <button type="button" class="btn btn-primary" onclick="saveQuickPrice(<?= (int)$row['id'] ?>, this)">Save</button>
                      <span class="save-status" id="save-status-<?= (int)$row['id'] ?>" style="margin-left:8px; color:#4b5d53; font-size:0.8rem;"></span>
                    </td>
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
        <div class="daily-card-head">4. One-Click Excel / CSV Upload</div>
        <div class="daily-card-body">
          <div class="upload-box">
            <p style="margin-top:0; font-weight:700;">Upload an Excel / CSV sheet with columns like:</p>
            <p style="color:#53625b;">vegetable_id, raw_weight_kg, usable_weight_kg, mandi_rate_per_kg, selling_price, source_type, source_name</p>
            <div class="form-group" style="margin-top:14px;">
              <label for="bulk_excel_file">Choose spreadsheet</label>
              <input type="file" id="bulk_excel_file" name="bulk_excel_file" accept=".csv,.xlsx" />
            </div>
            <div style="margin-top:12px; color:#5a6962; font-size:0.82rem;">
              Uploading a file saves inwarding and app selling price in one click without manually filling every row.
            </div>
          </div>
        </div>
      </section>
    </div>

    <div class="daily-form-actions">
      <button type="submit" class="btn btn-primary">Save Procurement & Pricing</button>
    </div>
  </form>

  <section class="daily-card">
    <div class="daily-card-head">Recent Inward Entries</div>
    <div class="daily-card-body">
      <div style="overflow-x:auto;">
        <table class="daily-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Item</th>
              <th>Source</th>
              <th>Raw</th>
              <th>Usable</th>
              <th>Wastage</th>
              <th>Rate</th>
              <th>Cost</th>
            </tr>
          </thead>
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
              </tr>
            <?php endforeach; ?>
            <?php if (!$purchaseRows): ?><tr><td colspan="8" style="text-align:center; color:#68736f;">No purchase inward entries yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</div>

<script>
  function saveQuickPrice(id, btn) {
    const price = document.getElementById('quick-price-' + id).value;
    const sale = document.getElementById('quick-sale-price-' + id).value;
    const stock = document.getElementById('quick-stock-' + id).value;
    const status = document.getElementById('save-status-' + id);
    btn.disabled = true;
    status.textContent = 'Saving...';

    fetch('<?= BASE_URL ?>/ajax/admin_update_price.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: id,
        price: price,
        sale_price: sale,
        stock: stock,
        csrf_token: '<?= h(csrf_token()) ?>'
      })
    }).then(function (response) { return response.json(); }).then(function (data) {
      if (data.success) {
        status.style.color = '#1b7b44';
        status.textContent = data.message || 'Saved';
      } else {
        status.style.color = '#b42318';
        status.textContent = data.error || 'Error';
      }
    }).catch(function () {
      status.style.color = '#b42318';
      status.textContent = 'Network error';
    }).finally(function () {
      btn.disabled = false;
      setTimeout(function () { status.textContent = ''; }, 3000);
    });
  }

  document.querySelectorAll('.daily-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      const target = this.dataset.tab;
      document.querySelectorAll('.daily-tab').forEach(function (btn) {
        btn.classList.toggle('active', btn === tab);
      });
      document.querySelectorAll('.tab-panel').forEach(function (panel) {
        panel.classList.toggle('active', panel.id === target);
      });
    });
  });

  document.addEventListener('input', function (event) {
    if (!event.target.classList.contains('raw-weight') && !event.target.classList.contains('usable-weight')) return;
    const row = event.target.closest('tr');
    if (!row) return;
    const raw = parseFloat(row.querySelector('.raw-weight')?.value || 0);
    const usable = parseFloat(row.querySelector('.usable-weight')?.value || 0);
    const wastage = Math.max(raw - usable, 0);
    const wastageField = row.querySelector('.wastage-weight');
    if (wastageField) wastageField.value = wastage.toFixed(2);
  });
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>