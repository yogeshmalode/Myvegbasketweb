<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Add Vegetable';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $name_mr     = trim($_POST['name_mr'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = (float)($_POST['price'] ?? 0);
    $cost_price  = (float)($_POST['cost_price'] ?? 0);
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $sale_price  = trim($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null;
    $unit        = trim($_POST['unit'] ?? 'kg');
    $category    = trim($_POST['category'] ?? 'Vegetable');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;
    $stock_mode  = in_array($_POST['stock_mode'] ?? '', ['shared', 'per_store'], true) ? $_POST['stock_mode'] : 'shared';
    $storeStockInput = $_POST['store_stock'] ?? [];
    if ($stock_mode === 'per_store') {
        // The single vegetables.stock column still gets the sum of all
        // stores' stock, purely so catalog lists / low-stock alerts that
        // read it directly keep showing a sensible total.
        $stock = 0.0;
        foreach ($storeStockInput as $v) { $stock += max(0, (float)$v); }
    } else {
        $stock = (float)($_POST['stock'] ?? 0);
    }

    $variantLabels = $_POST['variant_label'] ?? [];
    $variantPrices = $_POST['variant_price'] ?? [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();
    if ($name === '' || $price <= 0 || $cost_price < 0) {
        $error = 'Name and a valid price are required.';
    } elseif ($sale_price !== null && $sale_price >= $price) {
        $error = 'Sale price must be lower than the normal price.';
    } else {
        $upload = handle_vegetable_image_upload('image');
        if ($upload['error']) {
            $error = $upload['error'];
        } else {
        try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO vegetables (name, name_mr, description, price, sale_price, unit, stock, stock_mode, supplier_name, cost_price, category, is_active, image_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $name_mr ?: null, $description, $price, $sale_price, $unit, $stock, $stock_mode, $supplier_name ?: null, $cost_price, $category, $is_active, $upload['path']]);
        $vegId = $pdo->lastInsertId();

        if ($stock_mode === 'per_store') {
            $storeStockStmt = $pdo->prepare("INSERT INTO store_inventory (dark_store_id, vegetable_id, stock) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE stock = VALUES(stock)");
            foreach ($storeStockInput as $storeId => $qty) {
                $storeStockStmt->execute([(int)$storeId, $vegId, max(0, (float)$qty)]);
            }
        }

        $vStmt = $pdo->prepare("INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order) VALUES (?, ?, ?, ?)");
        $order = 0;
        foreach ($variantLabels as $i => $label) {
            $label = trim($label);
            $vPrice = (float)($variantPrices[$i] ?? 0);
            if ($label === '' || $vPrice <= 0) continue;
            $vStmt->execute([$vegId, $label, $vPrice, $order++]);
        }

        $pdo->commit();
        $_SESSION['flash'] = ['type' => 'success', 'message' => "$name added to the catalog."];
        redirect('dashboard.php');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = 'Could not save vegetable.'; }
        }
    }
}

$categoryOptions = $pdo->query("SELECT name FROM categories WHERE is_active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Add Vegetable</h2>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="form-card" style="max-width:560px;">
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group">
      <label for="name">Vegetable name</label>
      <input type="text" id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label for="image">Product photo (optional)</label>
      <p style="color:#5B6656; font-size:0.82rem; margin:2px 0 10px;">Browse a photo from your computer — JPG, PNG, or WEBP, up to 5 MB. No code changes needed; it's used immediately for this product.</p>
      <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" onchange="previewVegImage(this)">
      <div id="imagePreviewWrap" style="margin-top:10px; display:none;">
        <img id="imagePreview" src="" alt="Preview" style="width:120px; height:120px; object-fit:contain; background:#fff; border:1px solid #D9E0CD; border-radius:10px; padding:6px;">
      </div>
    </div>
    <div class="form-group">
      <label for="name_mr">Marathi name (optional)</label>
      <input type="text" id="name_mr" name="name_mr" value="<?= h($_POST['name_mr'] ?? '') ?>" placeholder="e.g. टोमॅटो">
    </div>
    <div class="form-group">
      <label for="description">Short description</label>
      <input type="text" id="description" name="description" value="<?= h($_POST['description'] ?? '') ?>" placeholder="e.g. Farm fresh, hand picked">
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="price">Price (₹)</label>
        <input type="number" id="price" name="price" step="0.01" min="0.01" value="<?= h($_POST['price'] ?? '') ?>" required>
      </div>
      <div class="form-group" style="flex:1;">
        <label for="sale_price">Sale price (₹, optional)</label>
        <input type="number" id="sale_price" name="sale_price" step="0.01" min="0" value="<?= h($_POST['sale_price'] ?? '') ?>" placeholder="Leave blank for no sale">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
          <?php foreach (['kg','piece','bunch','dozen','gram','100g','litre'] as $u): ?>
            <option value="<?= $u ?>" <?= ($_POST['unit'] ?? 'kg') === $u ? 'selected' : '' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="cost_price">Cost / Purchase Price (₹)</label>
        <input type="number" id="cost_price" name="cost_price" step="0.01" min="0" value="<?= h($veg['cost_price'] ?? ($_POST['cost_price'] ?? '0')) ?>">
        <small style="color:#5B6656;">Selling price can be set at 145% of cost.</small>
      </div>
      <div class="form-group" style="flex:1;">
        <label for="supplier_name">Supplier Name</label>
        <input type="text" id="supplier_name" name="supplier_name" maxlength="120" value="<?= h($veg['supplier_name'] ?? ($_POST['supplier_name'] ?? '')) ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Stock mode</label>
      <div style="display:flex; gap:18px; align-items:center; margin-bottom:8px;">
        <label style="width:auto; display:inline-flex; align-items:center; gap:6px; font-weight:400;">
          <input type="radio" name="stock_mode" value="shared" checked onchange="toggleStockMode()" style="width:auto;"> Shared (one stock number for all stores)
        </label>
        <label style="width:auto; display:inline-flex; align-items:center; gap:6px; font-weight:400;">
          <input type="radio" name="stock_mode" value="per_store" onchange="toggleStockMode()" style="width:auto;"> Per-Store (track separately per store)
        </label>
      </div>
      <div id="sharedStockWrap" class="form-group" style="margin-bottom:0;">
        <label for="stock">Stock quantity</label>
        <input type="number" id="stock" name="stock" min="0" value="<?= h($_POST['stock'] ?? 50) ?>">
      </div>
      <div id="perStoreStockWrap" style="display:none;">
        <p style="color:#5B6656; font-size:0.82rem; margin:2px 0 10px;">Set this product's starting stock at each store. A sale, wastage entry, or restock at one store won't affect another store's number.</p>
        <?php foreach ($allStores as $s): ?>
          <div class="form-group" style="margin-bottom:8px;">
            <label style="font-weight:400;"><?= h($s['name']) ?></label>
            <input type="number" name="store_stock[<?= $s['id'] ?>]" min="0" step="0.001" value="0">
          </div>
        <?php endforeach; ?>
        <?php if (empty($allStores)): ?>
          <p style="color:#9A2E24; font-size:0.82rem;">No stores set up yet — add one on the Stores page first.</p>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="category">Category</label>
        <select id="category" name="category">
          <?php
            $selectedCat = $_POST['category'] ?? 'Vegetable';
            $catList = $categoryOptions;
            if ($selectedCat !== '' && !in_array($selectedCat, $catList, true)) { $catList[] = $selectedCat; }
          ?>
          <?php foreach ($catList as $catOpt): ?>
            <option value="<?= h($catOpt) ?>" <?= $catOpt === $selectedCat ? 'selected' : '' ?>><?= h($catOpt) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:6px; font-size:0.78rem;"><a href="categories.php">+ Manage categories</a></div>
      </div>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="is_active" checked style="width:auto; display:inline-block;"> Visible in store</label>
    </div>

    <div class="form-group">
      <label>Size options (optional)</label>
      <p style="color:#5B6656; font-size:0.82rem; margin:2px 0 10px;">Let shoppers pick a size like "250 g" or "500 g" instead of buying by the full unit above. Leave empty to sell by the unit only.</p>
      <div id="variantRows"></div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" id="addVariantRow" class="btn" style="background:#fff; border:1px solid #E4E9DD; padding:8px 14px; font-size:0.85rem;">+ Add size option</button>
        <button type="button" id="addStandardSizes" class="btn" style="background:#EAF6EF; border:1px solid #BEE3CE; padding:8px 14px; font-size:0.85rem;">⚡ Add standard sizes (250 g / 500 g / 1 kg)</button>
        <button type="button" id="recalcVariants" class="btn" style="background:#FFF4E5; border:1px solid #F0C989; padding:8px 14px; font-size:0.85rem;">🔄 Recalculate sizes from base price</button>
      </div>
      <p style="color:#5B6656; font-size:0.78rem; margin-top:8px;">Prices auto-recalculate whenever you change the base price or unit above — no need to click Recalculate every time.</p>
    </div>

    <button type="submit" class="btn btn-primary">Save Vegetable</button>
    <a href="dashboard.php" class="btn btn-outline">Cancel</a>
  </form>
</div>

<script>
  function toggleStockMode() {
    const perStore = document.querySelector('input[name="stock_mode"]:checked').value === 'per_store';
    document.getElementById('sharedStockWrap').style.display = perStore ? 'none' : 'block';
    document.getElementById('perStoreStockWrap').style.display = perStore ? 'block' : 'none';
  }

  function previewVegImage(input) {
    const wrap = document.getElementById('imagePreviewWrap');
    const img = document.getElementById('imagePreview');
    if (input.files && input.files[0]) {
      img.src = URL.createObjectURL(input.files[0]);
      wrap.style.display = 'block';
    } else {
      wrap.style.display = 'none';
    }
  }

  function variantRowHtml(label, price) {
    return '<div style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">' +
      '<input type="text" name="variant_label[]" class="variant-label-input" placeholder="e.g. 250 g" value="' + (label || '') + '" style="flex:1; padding:8px 10px; border-radius:8px; border:1px solid #D9E0CD;">' +
      '<input type="number" name="variant_price[]" class="variant-price-input" placeholder="Price" step="0.01" min="0" value="' + (price || '') + '" style="width:110px; padding:8px 10px; border-radius:8px; border:1px solid #D9E0CD;">' +
      '<button type="button" onclick="this.parentElement.remove()" style="background:#FCE8E6; color:#9A2E24; border:none; border-radius:8px; width:34px; height:34px; cursor:pointer; font-weight:700;">×</button>' +
    '</div>';
  }
  document.getElementById('addVariantRow').addEventListener('click', function () {
    document.getElementById('variantRows').insertAdjacentHTML('beforeend', variantRowHtml());
  });

  // Also auto-recalculate when a size label is typed/changed (e.g. the
  // admin adds a blank row and types "250 g" after the price is already set).
  document.getElementById('variantRows').addEventListener('input', function (e) {
    if (e.target.classList.contains('variant-label-input')) recalcAllVariants(true);
  });

  function sizeFractionOfBaseUnit(label, baseUnit) {
    const m = (label || '').trim().match(/^([\d.]+)\s*(kilogram|kilograms|kg|gram|grams|g|litre|litres|liter|liters|l|millilitre|millilitres|ml)\b/i);
    if (!m) return null;
    const value = parseFloat(m[1]);
    if (isNaN(value)) return null;
    const unit = m[2].toLowerCase();

    let grams = null, ml = null;
    if (unit === 'kg' || unit.startsWith('kilogram')) grams = value * 1000;
    else if (unit === 'g' || unit.startsWith('gram')) grams = value;
    else if (unit === 'l' || unit.startsWith('litre') || unit.startsWith('liter')) ml = value * 1000;
    else if (unit === 'ml' || unit.startsWith('millilitre')) ml = value;

    if (baseUnit === 'kg' && grams !== null) return grams / 1000;
    if (baseUnit === 'gram' && grams !== null) return grams;
    if (baseUnit === '100g' && grams !== null) return grams / 100;
    if (baseUnit === 'litre' && ml !== null) return ml / 1000;
    return null;
  }

  document.getElementById('recalcVariants').addEventListener('click', function () {
    recalcAllVariants(false);
  });

  // Core recalculation logic, shared by: the manual "Recalculate" button,
  // live auto-recalc whenever price/unit changes, and the "Add standard
  // sizes" button below. silent=true suppresses the summary alert (used
  // for the live auto-recalc so it doesn't interrupt typing).
  function recalcAllVariants(silent) {
    const basePrice = parseFloat(document.getElementById('price').value);
    const baseUnit = document.getElementById('unit').value;

    if (!basePrice || basePrice <= 0) {
      if (!silent) alert('Enter a valid base price above first.');
      return;
    }
    if (!['kg', 'gram', '100g', 'litre'].includes(baseUnit)) {
      if (!silent) alert('Automatic recalculation only works when the unit above is kg, gram, 100g, or litre — sizes for "' + baseUnit + '" need to be set manually.');
      return;
    }

    const rows = document.querySelectorAll('#variantRows > div');
    let updated = 0;
    const unclear = [];

    rows.forEach(function (row) {
      const labelInput = row.querySelector('.variant-label-input');
      const priceInput = row.querySelector('.variant-price-input');
      const fraction = sizeFractionOfBaseUnit(labelInput.value, baseUnit);
      if (fraction === null) {
        if (labelInput.value.trim() !== '') unclear.push(labelInput.value);
        return;
      }
      priceInput.value = (basePrice * fraction).toFixed(2);
      updated++;
    });

    if (!silent) {
      let msg = 'Updated ' + updated + ' size price(s) to match ₹' + basePrice.toFixed(2) + ' per ' + baseUnit + '.';
      if (unclear.length) msg += '\n\nCouldn\'t figure out these labels, please check them manually: ' + unclear.join(', ');
      alert(msg);
    }
  }

  // Auto-recalculate pricing the moment the base price or unit changes —
  // no manual click needed for the common case of just adjusting price.
  document.getElementById('price').addEventListener('input', function () { recalcAllVariants(true); });
  document.getElementById('unit').addEventListener('change', function () { recalcAllVariants(true); });

  // One click to add the three most common size options with correctly
  // scaled prices already filled in, instead of typing each one manually.
  document.getElementById('addStandardSizes').addEventListener('click', function () {
    const basePrice = parseFloat(document.getElementById('price').value);
    const baseUnit = document.getElementById('unit').value;
    if (!basePrice || basePrice <= 0) { alert('Enter a valid base price above first.'); return; }
    if (!['kg', 'gram', '100g', 'litre'].includes(baseUnit)) {
      alert('Standard sizes only make sense when the unit above is kg, gram, 100g, or litre.');
      return;
    }
    const sizes = baseUnit === 'litre' ? ['250 ml', '500 ml', '1 litre'] : ['250 g', '500 g', '1 kg'];
    sizes.forEach(function (label) {
      const fraction = sizeFractionOfBaseUnit(label, baseUnit);
      const price = fraction !== null ? (basePrice * fraction).toFixed(2) : '';
      document.getElementById('variantRows').insertAdjacentHTML('beforeend', variantRowHtml(label, price));
    });
  });
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
