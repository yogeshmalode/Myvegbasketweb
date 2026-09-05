<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Edit Vegetable';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ?");
$stmt->execute([$id]);
$veg = $stmt->fetch();

if (!$veg) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Vegetable not found.'];
    redirect('dashboard.php');
}

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
    $stock       = (int)($_POST['stock'] ?? 0);
    $category    = trim($_POST['category'] ?? 'Vegetable');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    $variantLabels = $_POST['variant_label'] ?? [];
    $variantPrices = $_POST['variant_price'] ?? [];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') require_csrf();
    if ($name === '' || $price <= 0 || $cost_price < 0) {
        $error = 'Name and a valid price are required.';
        $veg = array_merge($veg, $_POST); // repopulate form with attempted values
    } elseif ($sale_price !== null && $sale_price >= $price) {
        $error = 'Sale price must be lower than the normal price.';
        $veg = array_merge($veg, $_POST);
    } else {
        try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE vegetables SET name=?, name_mr=?, description=?, price=?, sale_price=?, unit=?, stock=?, supplier_name=?, cost_price=?, category=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $name_mr ?: null, $description, $price, $sale_price, $unit, $stock, $supplier_name ?: null, $cost_price, $category, $is_active, $id]);

        // Simplest way to keep variants in sync with the form: replace the
        // whole set rather than trying to diff old vs new rows.
        $pdo->prepare("DELETE FROM vegetable_variants WHERE vegetable_id = ?")->execute([$id]);
        $vStmt = $pdo->prepare("INSERT INTO vegetable_variants (vegetable_id, label, price, sort_order) VALUES (?, ?, ?, ?)");
        $order = 0;
        foreach ($variantLabels as $i => $label) {
            $label = trim($label);
            $vPrice = (float)($variantPrices[$i] ?? 0);
            if ($label === '' || $vPrice <= 0) continue;
            $vStmt->execute([$id, $label, $vPrice, $order++]);
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => "$name updated successfully."];
        redirect('dashboard.php');
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $error = 'Could not update vegetable.'; }
    }
}

$existingVariants = $pdo->prepare("SELECT * FROM vegetable_variants WHERE vegetable_id = ? ORDER BY sort_order, price");
$existingVariants->execute([$id]);
$existingVariants = $existingVariants->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Edit Vegetable</h2>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="form-card" style="max-width:560px;">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group">
      <label for="name">Vegetable name</label>
      <input type="text" id="name" name="name" value="<?= h($veg['name']) ?>" required>
    </div>
    <div class="form-group">
      <label for="name_mr">Marathi name (optional)</label>
      <input type="text" id="name_mr" name="name_mr" value="<?= h($veg['name_mr'] ?? '') ?>" placeholder="e.g. टोमॅटो">
    </div>
    <div class="form-group">
      <label for="description">Short description</label>
      <input type="text" id="description" name="description" value="<?= h($veg['description']) ?>">
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="price">Price (₹)</label>
        <input type="number" id="price" name="price" step="0.01" min="0.01" value="<?= h($veg['price']) ?>" required>
      </div>
      <div class="form-group" style="flex:1;">
        <label for="sale_price">Sale price (₹, optional)</label>
        <input type="number" id="sale_price" name="sale_price" step="0.01" min="0" value="<?= h($veg['sale_price'] ?? '') ?>" placeholder="Leave blank for no sale">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
          <?php foreach (['kg','piece','bunch','dozen','gram','litre'] as $u): ?>
            <option value="<?= $u ?>" <?= $veg['unit'] === $u ? 'selected' : '' ?>><?= $u ?></option>
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
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="stock">Stock quantity</label>
        <input type="number" id="stock" name="stock" min="0" value="<?= h($veg['stock']) ?>">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="category">Category</label>
        <input type="text" id="category" name="category" value="<?= h($veg['category']) ?>">
      </div>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="is_active" <?= $veg['is_active'] ? 'checked' : '' ?> style="width:auto; display:inline-block;"> Visible in store</label>
    </div>

    <div class="form-group">
      <label>Size options (optional)</label>
      <p style="color:#5B6656; font-size:0.82rem; margin:2px 0 10px;">Let shoppers pick a size like "250 g" or "500 g" instead of buying by the full unit above. Leave empty to sell by the unit only.</p>
      <div id="variantRows">
        <?php foreach ($existingVariants as $v): ?>
          <div style="display:flex; gap:10px; margin-bottom:8px; align-items:center;">
            <input type="text" name="variant_label[]" class="variant-label-input" value="<?= h($v['label']) ?>" style="flex:1; padding:8px 10px; border-radius:8px; border:1px solid #D9E0CD;">
            <input type="number" name="variant_price[]" class="variant-price-input" value="<?= h($v['price']) ?>" step="0.01" min="0" style="width:110px; padding:8px 10px; border-radius:8px; border:1px solid #D9E0CD;">
            <button type="button" onclick="this.parentElement.remove()" style="background:#FCE8E6; color:#9A2E24; border:none; border-radius:8px; width:34px; height:34px; cursor:pointer; font-weight:700;">×</button>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" id="addVariantRow" class="btn" style="background:#fff; border:1px solid #E4E9DD; padding:8px 14px; font-size:0.85rem;">+ Add size option</button>
        <button type="button" id="recalcVariants" class="btn" style="background:#FFF4E5; border:1px solid #F0C989; padding:8px 14px; font-size:0.85rem;">🔄 Recalculate sizes from base price</button>
      </div>
      <p style="color:#5B6656; font-size:0.78rem; margin-top:8px;">Changed the price above? Click "Recalculate" to scale every size option to match — e.g. if 1 kg is now ₹62, a 250 g option becomes ₹15.50 automatically.</p>
    </div>

    <button type="submit" class="btn btn-primary">Update Vegetable</button>
    <a href="dashboard.php" class="btn btn-outline">Cancel</a>
  </form>
</div>

<script>
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

  // Parses a size label like "250 g", "500g", "1 kg", "1 litre", "250 ml"
  // into what fraction of the base unit (selected in the Unit dropdown)
  // it represents. Returns null if the label can't be understood.
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
    if (baseUnit === 'litre' && ml !== null) return ml / 1000;
    return null;
  }

  document.getElementById('recalcVariants').addEventListener('click', function () {
    const basePrice = parseFloat(document.getElementById('price').value);
    const baseUnit = document.getElementById('unit').value;

    if (!basePrice || basePrice <= 0) {
      alert('Enter a valid base price above first.');
      return;
    }
    if (!['kg', 'gram', 'litre'].includes(baseUnit)) {
      alert('Automatic recalculation only works when the unit above is kg, gram, or litre — sizes for "' + baseUnit + '" need to be set manually.');
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

    let msg = 'Updated ' + updated + ' size price(s) to match ₹' + basePrice.toFixed(2) + ' per ' + baseUnit + '.';
    if (unclear.length) msg += '\n\nCouldn\'t figure out these labels, please check them manually: ' + unclear.join(', ');
    alert(msg);
  });
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
