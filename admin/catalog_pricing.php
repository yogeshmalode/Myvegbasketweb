<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Catalog & Live Pricing';

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
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

function resolve_bulk_item_id($pdo, $value) {
    $value = trim((string)$value);
    if ($value === '') return 0;
    if (is_numeric($value)) return (int)$value;

    $stmt = $pdo->prepare('SELECT id FROM vegetables WHERE LOWER(name) = LOWER(?) OR LOWER(name_mr) = LOWER(?) LIMIT 1');
    $stmt->execute([$value, $value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['id'] : 0;
}

$uploadError = '';
$uploadSuccess = '';
$uploadStats = ['updated' => 0, 'skipped' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['price_file']['tmp_name'])) {
    require_csrf();
    $tmpPath = $_FILES['price_file']['tmp_name'];
    $origName = $_FILES['price_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        $rows = read_xlsx_simple($tmpPath);
    } elseif ($ext === 'csv') {
        $rows = read_csv_simple($tmpPath);
    } else {
        $rows = null;
        $uploadError = 'Please upload a .csv or .xlsx file.';
    }

    if ($rows !== null && empty($uploadError)) {
        if (empty($rows)) {
            $uploadError = 'The uploaded file is empty.';
        } else {
            $header = array_map(function($field){ return strtolower(trim((string)$field)); }, $rows[0]);
            $lookup = $pdo->prepare('SELECT id, name, unit, stock, price, sale_price FROM vegetables WHERE id = ? OR LOWER(name) = LOWER(?) OR LOWER(name_mr) = LOWER(?) LIMIT 1');
            $update = $pdo->prepare('UPDATE vegetables SET price = ?, sale_price = ?, stock = ? WHERE id = ?');
            $updated = 0;
            $skipped = 0;

            foreach (array_slice($rows, 1) as $row) {
                if (empty(array_filter($row, function($v){ return trim((string)$v) !== ''; }))) continue;

                $data = [];
                foreach ($row as $index => $value) {
                    $data[$header[$index] ?? $index] = $value;
                }

                $itemKey = $data['vegetable_id'] ?? $data['id'] ?? $data['item_id'] ?? $data['item'] ?? $data['name'] ?? '';
                $id = resolve_bulk_item_id($pdo, $itemKey);
                if ($id <= 0) { $skipped++; continue; }

                $priceRaw = trim((string)($data['price'] ?? $data['mrp'] ?? $data['selling_price'] ?? ''));
                $saleRaw = trim((string)($data['sale_price'] ?? $data['sale'] ?? ''));
                $stockRaw = trim((string)($data['stock'] ?? ''));

                if ($priceRaw === '') { $skipped++; continue; }
                $price = (float)$priceRaw;
                if ($price <= 0) { $skipped++; continue; }

                $sale = $saleRaw !== '' ? (float)$saleRaw : null;
                if ($sale !== null && $sale >= $price) $sale = null;
                $stock = $stockRaw !== '' ? max(0, (int)$stockRaw) : null;

                $lookup->execute([$id, (string)$itemKey, (string)$itemKey]);
                $product = $lookup->fetch(PDO::FETCH_ASSOC);
                if (!$product) { $skipped++; continue; }

                $update->execute([$price, $sale, $stock !== null ? $stock : (int)$product['stock'], $id]);
                if ($price > 0) {
                    recalculate_variant_prices($pdo, $id, $price, $product['unit']);
                }
                $updated++;
            }

            $uploadStats = ['updated' => $updated, 'skipped' => $skipped];
            if ($updated > 0) {
                $uploadSuccess = 'Excel file processed successfully. Updated ' . $updated . ' product(s).';
            } else {
                $uploadError = 'No valid rows were updated from the uploaded file.';
            }
        }
    }
}

$vegetables = $pdo->query("SELECT * FROM vegetables ORDER BY category, name")->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Catalog & Live Pricing</h2>
  <p>Update All items quickly: change price, sale price or stock and click Save for that row. Changes apply immediately and size variants are rescaled automatically.</p>
</div>

<?php if ($uploadError): ?><div class="alert alert-error"><?= h($uploadError) ?></div><?php endif; ?>
<?php if ($uploadSuccess): ?><div class="alert alert-success"><?= h($uploadSuccess) ?></div><?php endif; ?>

<div class="form-card" style="max-width:760px; margin-bottom:20px;">
  <h3 style="margin-top:0;">Upload Excel / CSV</h3>
  <p style="margin-top:0; color:#5B6656;">Use the exported sheet, edit Price / Sale Price / Stock, and upload once to update all products in one click.</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group">
      <label for="price_file">Choose spreadsheet (.csv / .xlsx)</label>
      <input type="file" id="price_file" name="price_file" accept=".csv,.xlsx" required>
    </div>
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
      <button type="submit" class="btn btn-primary">Upload & update all</button>
      <a href="export_prices.php" class="btn" style="background:#fff; border:1px solid #E4E9DD;">Download current sheet</a>
    </div>
  </form>
</div>

<div style="max-width:1100px;">
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Unit</th><th>Price (₹)</th><th>Sale price (₹)</th><th>Stock</th><th>Supplier</th><th></th></tr>
      </thead>
      <tbody>
        <?php $lastCategory = null; foreach ($vegetables as $veg): ?>
          <?php if ($veg['category'] !== $lastCategory): $lastCategory = $veg['category']; ?>
            <tr style="background:var(--leaf-light);"><td colspan="7" style="font-weight:700; color:var(--leaf-dark);"><?= h($veg['category']) ?></td></tr>
          <?php endif; ?>
          <tr id="row-<?= $veg['id'] ?>">
            <td style="min-width:200px;"><strong><?= h($veg['name']) ?></strong><?php if (!empty($veg['name_mr'])): ?> <div style="color:#5B6656; font-size:0.9rem;">/ <?= h($veg['name_mr']) ?></div><?php endif; ?></td>
            <td><?= h($veg['unit']) ?></td>
            <td><input type="number" step="0.01" min="0" id="price-<?= $veg['id'] ?>" value="<?= h($veg['price']) ?>" style="width:110px;"></td>
            <td><input type="number" step="0.01" min="0" id="sale-<?= $veg['id'] ?>" value="<?= h($veg['sale_price'] ?? '') ?>" style="width:110px;"></td>
            <td><input type="number" step="1" min="0" id="stock-<?= $veg['id'] ?>" value="<?= h($veg['stock']) ?>" style="width:90px;"></td>
            <td><input type="text" id="supplier-<?= $veg['id'] ?>" value="<?= h($veg['supplier_name'] ?? '') ?>" style="width:160px;"></td>
            <td><button class="btn" data-id="<?= $veg['id'] ?>" onclick="saveRow(<?= $veg['id'] ?>, this)">Save</button>
                <span class="row-status" id="status-<?= $veg['id'] ?>" style="margin-left:8px;color:#5B6656;"></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
function saveRow(id, btn) {
  const price = document.getElementById('price-' + id).value;
  const sale = document.getElementById('sale-' + id).value;
  const stock = document.getElementById('stock-' + id).value;
  const supplier = document.getElementById('supplier-' + id).value;
  const status = document.getElementById('status-' + id);
  btn.disabled = true;
  status.textContent = 'Saving...';

  fetch('<?= BASE_URL ?>/ajax/admin_update_price.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ id: id, price: price, sale_price: sale, stock: stock, supplier_name: supplier, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(r => r.json()).then(data => {
    if (data.success) {
      status.style.color = 'green';
      status.textContent = data.message || 'Saved';
    } else {
      status.style.color = 'crimson';
      status.textContent = data.error || 'Error';
    }
  }).catch(err => {
    status.style.color = 'crimson';
    status.textContent = 'Network error';
  }).finally(() => { btn.disabled = false; setTimeout(()=>status.textContent='',4000); });
}
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
