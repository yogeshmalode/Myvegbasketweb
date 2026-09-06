<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Import Prices from Excel';

// Reads a simple single-sheet .xlsx file without needing any external
// library — an xlsx is just a zip file containing XML, and PHP's built-in
// ZipArchive + SimpleXML can read that directly.
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
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string)$r->t; }
                    $sharedStrings[] = $text;
                }
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
        $rowData = [];
        $maxCol = -1;
        foreach ($row->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)(\d+)/', $ref, $m);
            $colIndex = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) { $colIndex = $colIndex * 26 + (ord($ch) - 64); }
            $colIndex--;
            if ($colIndex > $maxCol) $maxCol = $colIndex;

            $type = (string)$cell['t'];
            if ($type === 's') {
                $value = $sharedStrings[(int)($cell->v ?? 0)] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string)($cell->is->t ?? '');
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }
            $rowData[$colIndex] = $value;
        }
        // Ensure missing cells are represented as empty strings so columns don't shift.
        if ($maxCol >= 0) {
            for ($i = 0; $i <= $maxCol; $i++) {
                if (!array_key_exists($i, $rowData)) $rowData[$i] = '';
            }
            ksort($rowData);
            $rows[] = array_values($rowData);
        } else {
            $rows[] = [];
        }
    }
    return $rows;
}

function read_csv_simple($filepath) {
    $rows = [];
    if (($handle = fopen($filepath, 'r')) !== false) {
        // Strip a UTF-8 BOM if present, so the header row matches cleanly.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

$results = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['price_file']['tmp_name'])) {
    $tmpPath = $_FILES['price_file']['tmp_name'];
    $origName = $_FILES['price_file']['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

    if ($ext === 'xlsx') {
        $rows = read_xlsx_simple($tmpPath);
        if ($rows === null) {
            $error = 'Could not read that .xlsx file. Try saving it as .csv instead (File → Save As → CSV in Excel) and upload that.';
        }
    } elseif ($ext === 'csv') {
        $rows = read_csv_simple($tmpPath);
    } else {
        $error = 'Please upload a .csv or .xlsx file.';
        $rows = null;
    }

    if ($rows !== null && !$error) {
        // Skip the header row (first row) — everything after is data.
        array_shift($rows);

        $lookup = $pdo->prepare("SELECT id, name, stock, unit FROM vegetables WHERE id = ?");
        $update = $pdo->prepare("UPDATE vegetables SET price = ?, sale_price = ?, stock = ? WHERE id = ?");

        $updated = [];
        $skipped = [];
        $variantsUpdated = 0;

        foreach ($rows as $row) {
            $id = (int)trim($row[0] ?? '');
            $name = trim($row[1] ?? '');
            if ($id <= 0) continue; // blank row

            $price = isset($row[2]) ? (float)$row[2] : 0;
            $salePriceRaw = trim((string)($row[3] ?? ''));
            $salePrice = $salePriceRaw !== '' ? (float)$salePriceRaw : null;
            $stockRaw = trim((string)($row[4] ?? ''));
            $stock = $stockRaw !== '' ? max(0, (int)$stockRaw) : null;

            $label = $name !== '' ? $name : "ID $id";

            if ($price <= 0) {
                $skipped[] = "$label (no valid price)";
                continue;
            }
            if ($salePrice !== null && $salePrice >= $price) $salePrice = null;

            $lookup->execute([$id]);
            $match = $lookup->fetch();
            if (!$match) {
                $skipped[] = "$label (ID $id not found — was it deleted? Don't add new rows, only edit existing ones)";
                continue;
            }

            $finalStock = $stock !== null ? $stock : (int)$match['stock'];
            $update->execute([$price, $salePrice, $finalStock, $id]);
            $updated[] = $match['name'];

            // Keep this product's size options proportional to its new price.
            $variantsUpdated += recalculate_variant_prices($pdo, $id, $price, $match['unit'])['updated'];
        }

        $results = ['updated' => $updated, 'skipped' => $skipped, 'variantsUpdated' => $variantsUpdated];
    }
}

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Import Prices from Excel</h2>
  <p>Download today's prices, edit them in Excel, then upload the file back here to update everything automatically.</p>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<?php if ($results): ?>
  <div class="alert alert-success">
    Updated <?= count($results['updated']) ?> product(s)<?= $results['skipped'] ? ', skipped ' . count($results['skipped']) : '' ?>.
    <?php if (!empty($results['variantsUpdated'])): ?>
      Also rescaled <?= $results['variantsUpdated'] ?> size option(s) to match the new prices.
    <?php endif; ?>
  </div>
  <?php if ($results['skipped']): ?>
    <div class="form-card" style="max-width:560px;">
      <strong>Skipped rows:</strong>
      <ul style="margin:10px 0 0; padding-left:20px; color:#5B6656;">
        <?php foreach ($results['skipped'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?>
      </ul>
      <p style="margin-top:12px; font-size:0.85rem; color:#5B6656;">Rows are matched by the ID column — if a row's ID doesn't exist anymore, or the ID column got edited/removed, that row is skipped.</p>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="form-card" style="max-width:560px;">
  <ol style="padding-left:20px; color:#26301F; margin-bottom:20px;">
    <li style="margin-bottom:8px;"><a href="export_prices.php">⬇ Download current prices as a spreadsheet</a></li>
    <li style="margin-bottom:8px;">Open it in Excel (or Google Sheets), edit the <strong>Price</strong>, <strong>Sale Price</strong>, and/or <strong>Stock</strong> columns — leave the <strong>ID</strong> column untouched (that's what matches each row back to the right product, even if you rename it or two products share a name).</li>
    <li style="margin-bottom:8px;">Save it (keep it as .xlsx, or Save As → CSV — both work).</li>
    <li>Upload it below.</li>
  </ol>

  <form method="post" enctype="multipart/form-data">
    <div class="form-group">
      <label for="price_file">Upload spreadsheet (.xlsx or .csv)</label>
      <input type="file" id="price_file" name="price_file" accept=".xlsx,.csv" required>
    </div>
    <button type="submit" class="btn btn-primary">Upload &amp; update prices</button>
  </form>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
