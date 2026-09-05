<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Bulk Update Prices';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    require_csrf();
    $prices     = $_POST['price'] ?? [];
    $salePrices = $_POST['sale_price'] ?? [];
    $costPrices = $_POST['cost_price'] ?? [];
    $suppliers = $_POST['supplier_name'] ?? [];
    $stocks     = $_POST['stock'] ?? [];

    $unitsById = $pdo->query("SELECT id, unit FROM vegetables")->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $pdo->prepare("UPDATE vegetables SET price = ?, sale_price = ?, stock = ?, cost_price = ?, supplier_name = ? WHERE id = ?");

    $updated = 0;
    $variantsUpdated = 0;
    $pdo->beginTransaction();
    try {
        foreach ($prices as $id => $price) {
            $id = (int)$id;
            $price = (float)$price;
            if ($id <= 0 || $price <= 0) continue; // skip anything left blank/invalid rather than zeroing it out

            $salePrice = trim($salePrices[$id] ?? '') !== '' ? (float)$salePrices[$id] : null;
            if ($salePrice !== null && $salePrice >= $price) $salePrice = null; // ignore a nonsensical sale price silently rather than failing the whole batch

            $stock = isset($stocks[$id]) ? max(0, (int)$stocks[$id]) : 0;
            $cost = isset($costPrices[$id]) ? max(0, (float)$costPrices[$id]) : 0;
            $supplier = trim($suppliers[$id] ?? '');

            $stmt->execute([$price, $salePrice, $stock, $cost, $supplier ?: null, $id]);
            $updated++;

            // Keep this product's size options (250 g / 500 g / 1 kg etc.)
            // proportional to its new price automatically — no separate
            // step needed.
            $result = recalculate_variant_prices($pdo, $id, $price, $unitsById[$id] ?? '');
            $variantsUpdated += $result['updated'];
        }
        $pdo->commit();
        $msg = "Updated $updated product(s).";
        if ($variantsUpdated > 0) $msg .= " Also rescaled $variantsUpdated size option(s) to match.";
        $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Something went wrong, nothing was saved: ' . $e->getMessage()];
    }
    redirect('bulk_update_prices.php');
}

// Standalone action: just recalculate every product's size options from
// whatever their prices already are right now, without changing any prices.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalc_all'])) {
    require_csrf();
    $result = recalculate_all_variant_prices($pdo);
    $msg = "Rescaled {$result['updated']} size option(s) across {$result['products']} product(s) to match their current prices.";
    if ($result['skipped'] > 0) $msg .= " ({$result['skipped']} size label(s) couldn't be understood and were left as-is.)";
    $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];
    redirect('bulk_update_prices.php');
}

$vegetables = $pdo->query("SELECT * FROM vegetables ORDER BY category, name")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Bulk Update Prices</h2>
  <p>Update today's prices for everything at once — change what you need, leave the rest, and hit Save all at the bottom. A blank price field is skipped, not zeroed out. Size options (250 g / 500 g / 1 kg etc.) rescale automatically whenever you save a new price here.</p>
</div>

<div style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
  <a href="import_prices.php" class="btn" style="background:#fff; border:1px solid #E4E9DD;">📊 Update via Excel instead</a>
  <form method="post" onsubmit="return confirm('Rescale every product\'s size options to match its current price? This only touches size options, not the base prices themselves.');" style="display:inline;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="recalc_all" value="1">
    <button type="submit" class="btn" style="background:#FFF4E5; border:1px solid #F0C989;">🔄 Recalculate ALL size options now</button>
  </form>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="bulk_save" value="1">
  <div style="position:sticky; top:0; background:var(--paper); z-index:10; padding:10px 0; margin-bottom:10px;">
    <button type="submit" class="btn btn-primary">💾 Save all changes</button>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Product</th><th>Unit</th><th>Cost (₹)</th><th>Price (₹)</th><th>Sale price (₹)</th><th>Stock</th><th>Supplier</th></tr>
      </thead>
      <tbody>
        <?php $lastCategory = null; ?>
        <?php foreach ($vegetables as $veg): ?>
          <?php if ($veg['category'] !== $lastCategory): $lastCategory = $veg['category']; ?>
            <tr style="background:var(--leaf-light);">
              <td colspan="7" style="font-weight:700; color:var(--leaf-dark);"><?= h($veg['category']) ?></td>
            </tr>
          <?php endif; ?>
          <tr>
            <td><?= h($veg['name']) ?><?= !empty($veg['name_mr']) ? ' / ' . h($veg['name_mr']) : '' ?></td>
            <td style="color:#5B6656;"><?= h($veg['unit']) ?></td>
            <td>
              <input type="number" name="cost_price[<?= $veg['id'] ?>]" value="<?= h($veg['cost_price']) ?>" step="0.01" min="0" style="width:90px; padding:6px 8px; border:1px solid #D9E0CD; border-radius:6px;">
            </td>
            <td>
              <input type="number" name="price[<?= $veg['id'] ?>]" value="<?= h($veg['price']) ?>" step="0.01" min="0" style="width:100px; padding:6px 8px; border:1px solid #D9E0CD; border-radius:6px;">
            </td>
            <td>
              <input type="number" name="sale_price[<?= $veg['id'] ?>]" value="<?= h($veg['sale_price'] ?? '') ?>" step="0.01" min="0" placeholder="—" style="width:100px; padding:6px 8px; border:1px solid #D9E0CD; border-radius:6px;">
            </td>
            <td>
              <input type="number" name="stock[<?= $veg['id'] ?>]" value="<?= h($veg['stock']) ?>" min="0" style="width:80px; padding:6px 8px; border:1px solid #D9E0CD; border-radius:6px;">
            </td> 
            <td><input type="text" name="supplier_name[<?= $veg['id'] ?>]" value="<?= h($veg['supplier_name'] ?? '') ?>" maxlength="120" style="width:130px; padding:6px 8px; border:1px solid #D9E0CD; border-radius:6px;"></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($vegetables)): ?>
          <tr><td colspan="7" style="text-align:center; color:#5B6656;">No products yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <button type="submit" class="btn btn-primary" style="margin-top:16px;">💾 Save all changes</button>
</form>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
