<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Manage Vegetables';

// One-click recalc: rescale size-option prices for one product to match its base price
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalc_variants']) && isset($_POST['veg_id'])) {
    require_csrf();
    $vegId = (int)$_POST['veg_id'];
    try {
        $row = $pdo->prepare("SELECT price, unit FROM vegetables WHERE id = ?");
        $row->execute([$vegId]);
        $v = $row->fetch();
        if ($v) {
            $res = recalculate_variant_prices($pdo, $vegId, (float)$v['price'], $v['unit']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Rescaled {$res['updated']} variant(s) for product #{$vegId}. {$res['skipped']} skipped."];
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Product not found.'];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Failed to recalculate: ' . $e->getMessage()];
    }
    $returnCategory = trim($_POST['return_category'] ?? '');
    redirect('vegetables.php' . ($returnCategory !== '' ? '?category=' . urlencode($returnCategory) : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_visibility'])) {
    require_csrf();
    $vegId = filter_var($_POST['veg_id'] ?? null, FILTER_VALIDATE_INT);
    $visible = $_POST['visible'] ?? '';
    if (!$vegId || !in_array($visible, ['0', '1'], true)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid product visibility request.'];
    } else {
        $stmt = $pdo->prepare('UPDATE vegetables SET is_active = ? WHERE id = ?');
        $stmt->execute([(int)$visible, $vegId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => $visible === '1' ? 'Product is now visible in the store.' : 'Product is now hidden from the store.'];
    }
    $returnCategory = trim($_POST['return_category'] ?? '');
    redirect('vegetables.php' . ($returnCategory !== '' ? '?category=' . urlencode($returnCategory) : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_visibility'])) {
    require_csrf();
    $visibility = $_POST['bulk_visibility'];
    $submittedIds = $_POST['veg_ids'] ?? [];
    $vegIds = [];
    if (!in_array($visibility, ['0', '1'], true) || !is_array($submittedIds)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid bulk visibility request.'];
    } else {
        $invalidSelection = false;
        foreach ($submittedIds as $submittedId) {
            $vegId = filter_var($submittedId, FILTER_VALIDATE_INT);
            if (!$vegId || $vegId < 1) {
                $invalidSelection = true;
                break;
            }
            $vegIds[] = $vegId;
        }
        $vegIds = array_values(array_unique($vegIds));
        if ($invalidSelection) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'The selected product list was invalid. Please try again.'];
        } elseif (!$vegIds) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Select at least one product first.'];
        } else {
            $placeholders = implode(',', array_fill(0, count($vegIds), '?'));
            $stmt = $pdo->prepare("UPDATE vegetables SET is_active = ? WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([(int)$visibility], $vegIds));
            $action = $visibility === '1' ? 'shown' : 'hidden';
            $_SESSION['flash'] = ['type' => 'success', 'message' => count($vegIds) . " product(s) $action in the store."];
        }
    }
    $returnCategory = trim($_POST['return_category'] ?? '');
    redirect('vegetables.php' . ($returnCategory !== '' ? '?category=' . urlencode($returnCategory) : ''));
}

$selectedCategory = trim($_GET['category'] ?? '');
$allVegetables = $pdo->query("SELECT * FROM vegetables ORDER BY category ASC, name ASC, id DESC")->fetchAll();
$categoryNames = $pdo->query("SELECT name FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll(PDO::FETCH_COLUMN);
foreach ($allVegetables as $veg) {
    $name = trim((string)($veg['category'] ?? ''));
    if ($name !== '' && !in_array($name, $categoryNames, true)) {
        $categoryNames[] = $name;
    }
    if ($name === '' && !in_array('Uncategorized', $categoryNames, true)) {
        $categoryNames[] = 'Uncategorized';
    }
}
$categoryNames = array_values(array_unique($categoryNames));
natcasesort($categoryNames);
$vegetables = $allVegetables;
if ($selectedCategory !== '') {
    $vegetables = array_values(array_filter($vegetables, static function (array $veg) use ($selectedCategory): bool {
        return strcasecmp(trim((string)($veg['category'] ?? '')), $selectedCategory) === 0;
    }));
}
$vegetablesByCategory = [];
foreach ($vegetables as $veg) {
    $categoryName = trim((string)($veg['category'] ?? '')) ?: 'Uncategorized';
    $vegetablesByCategory[$categoryName][] = $veg;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/includes/admin_header.php';
?>

<style>
  .catalog-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin:18px 0}
  .catalog-actions,.catalog-filters,.catalog-bulk-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
  .catalog-control{min-height:40px;padding:8px 12px;border:1px solid #d9e0cd;border-radius:10px;background:#fff;color:#26382d;font:inherit}
  .catalog-search{width:min(280px,100%);box-sizing:border-box}
  .catalog-bulk-panel{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 16px;margin-bottom:16px;background:#f3f8f2;border:1px solid #dce9dc;border-radius:14px}
  .catalog-category{margin:18px 0 10px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff;border:1px solid #e5ebdf;border-left:4px solid #3b8b55;border-radius:12px}
  .catalog-category h3{margin:0;font-size:1.04rem}.catalog-category span{font-size:.84rem;color:#637166}
  .catalog-table-wrap{overflow:auto;border:1px solid #e7ebdf;border-radius:13px;background:#fff}
  .catalog-table{width:100%;border-collapse:collapse;min-width:880px}
  .catalog-table th{padding:11px 12px;text-align:left;background:#f7f9f5;color:#58665b;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em}
  .catalog-table td{padding:11px 12px;border-top:1px solid #edf0e9;vertical-align:middle}
  .catalog-table tr:hover td{background:#fbfdf9}
  .catalog-product-name{font-weight:650;color:#25382b}
  .catalog-row-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .catalog-empty{padding:28px;text-align:center;color:#637166;background:#fff;border:1px dashed #d9e0cd;border-radius:13px}
  @media(max-width:700px){.catalog-toolbar{align-items:stretch}.catalog-actions,.catalog-filters{width:100%}.catalog-search{flex:1}.catalog-bulk-panel{align-items:flex-start}}
</style>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Vegetable Catalog</h2>
  <p style="color:#5B6656;">Browse products by category, select several items, then show or hide them together. Stock and category assignments are not changed.</p>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div class="catalog-toolbar">
  <div class="catalog-filters">
    <select id="catalogCategoryFilter" class="catalog-control" aria-label="Filter products by category">
      <option value="">All categories (<?= count($allVegetables) ?>)</option>
      <?php foreach ($categoryNames as $categoryName): ?>
        <?php $categoryCount = count(array_filter($allVegetables, static function (array $veg) use ($categoryName): bool {
            $productCategory = trim((string)($veg['category'] ?? '')) ?: 'Uncategorized';
            return strcasecmp($productCategory, $categoryName) === 0;
        })); ?>
        <option value="<?= h($categoryName) ?>" <?= strcasecmp($selectedCategory, $categoryName) === 0 ? 'selected' : '' ?>><?= h($categoryName) ?> (<?= $categoryCount ?>)</option>
      <?php endforeach; ?>
    </select>
    <input type="search" id="catalogSearch" class="catalog-control catalog-search" placeholder="Search products..." aria-label="Search products">
  </div>
  <div class="catalog-actions">
    <a href="catalog_pricing.php" class="btn" title="Live per-item pricing">⚡ Catalog & Live Pricing</a>
    <a href="inventory.php" class="btn">Inventory</a>
    <a href="billing.php" class="btn">Billing</a>
    <a href="add_vegetable.php" class="btn btn-primary">+ Add Vegetable</a>
  </div>
</div>

<form id="bulkVisibilityForm" method="post" class="catalog-bulk-panel">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="return_category" value="<?= h($selectedCategory) ?>">
  <div>
    <strong><span id="selectedProductCount">0</span> selected</strong>
    <div style="font-size:.82rem;color:#637166;margin-top:3px;">Select products below to change their store visibility together.</div>
  </div>
  <div class="catalog-bulk-actions">
    <button type="button" class="btn" id="selectVisibleProducts">Select visible</button>
    <button type="button" class="btn" id="clearProductSelection">Clear selection</button>
    <button type="submit" class="btn" name="bulk_visibility" value="0" data-action="hide">Hide selected</button>
    <button type="submit" class="btn btn-primary" name="bulk_visibility" value="1" data-action="show">Show selected</button>
  </div>
</form>

<?php if (!$vegetablesByCategory): ?>
  <div class="catalog-empty"><?= $selectedCategory !== '' ? 'No products are assigned to this category yet.' : 'No vegetables yet. Add your first one!' ?></div>
<?php endif; ?>

<?php foreach ($vegetablesByCategory as $categoryName => $categoryVegetables): ?>
  <section class="catalog-category-group" data-category-group="<?= h($categoryName) ?>">
    <header class="catalog-category">
      <h3><?= h($categoryName) ?></h3>
      <span><?= count($categoryVegetables) ?> product(s)</span>
    </header>
    <div class="catalog-table-wrap">
      <table class="catalog-table">
        <thead>
          <tr><th><input type="checkbox" class="select-category-products" aria-label="Select all products in <?= h($categoryName) ?>"></th><th>Product</th><th>Price</th><th>Stock</th><th>Visibility</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($categoryVegetables as $veg): ?>
            <tr class="catalog-product-row" data-product-name="<?= h(strtolower($veg['name'])) ?>" data-category="<?= h(strtolower($categoryName)) ?>">
              <td><input type="checkbox" name="veg_ids[]" value="<?= (int)$veg['id'] ?>" form="bulkVisibilityForm" class="catalog-product-checkbox" aria-label="Select <?= h($veg['name']) ?>"></td>
              <td>
                <div style="display:flex;align-items:center;gap:10px;">
                  <div style="width:42px;height:42px;flex:0 0 42px;"><?= veg_thumb_html($veg) ?></div>
                  <div><div class="catalog-product-name"><?= veg_emoji($veg['name']) ?> <?= h($veg['name']) ?></div><small style="color:#718076;">#<?= (int)$veg['id'] ?></small></div>
                </div>
              </td>
              <td>₹<?= number_format((float)$veg['price'], 2) ?> / <?= h($veg['unit']) ?>
                <?php if (!empty($veg['sale_price']) && $veg['sale_price'] < $veg['price']): ?>
                  <br><span style="color:var(--tomato);font-weight:700;font-size:.82rem;">Sale: ₹<?= number_format((float)$veg['sale_price'], 2) ?></span>
                <?php endif; ?>
              </td>
              <td><?= h($veg['stock']) ?> <?= h($veg['unit']) ?></td>
              <td><?= $veg['is_active'] ? '<span class="badge badge-green">Visible</span>' : '<span class="badge badge-red">Hidden</span>' ?></td>
              <td>
                <div class="catalog-row-actions">
                  <a href="edit_vegetable.php?id=<?= (int)$veg['id'] ?>" class="action-link edit">Edit</a>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="veg_id" value="<?= (int)$veg['id'] ?>">
                    <input type="hidden" name="return_category" value="<?= h($selectedCategory) ?>">
                    <input type="hidden" name="visible" value="<?= $veg['is_active'] ? '0' : '1' ?>">
                    <button type="submit" name="set_visibility" value="1" class="action-link" style="background:none;border:none;padding:0;color:#2a7ae2;cursor:pointer;font-size:.9rem;"><?= $veg['is_active'] ? 'Hide' : 'Show' ?></button>
                  </form>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="veg_id" value="<?= (int)$veg['id'] ?>">
                    <input type="hidden" name="return_category" value="<?= h($selectedCategory) ?>">
                    <button type="submit" name="recalc_variants" value="1" class="action-link" style="background:none;border:none;padding:0;color:#2a7ae2;cursor:pointer;font-size:.9rem;">Rescale sizes</button>
                  </form>
                  <a href="delete_vegetable.php?id=<?= (int)$veg['id'] ?>" class="action-link delete" onclick="return confirm('Delete <?= h($veg['name']) ?>? This cannot be undone.');">Delete</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>

<script>
  (function () {
    const categoryFilter = document.getElementById('catalogCategoryFilter');
    const searchInput = document.getElementById('catalogSearch');
    const productRows = Array.from(document.querySelectorAll('.catalog-product-row'));
    const selectionCount = document.getElementById('selectedProductCount');
    const selectionBoxes = Array.from(document.querySelectorAll('.catalog-product-checkbox'));
    const updateSelectionCount = function () {
      selectionCount.textContent = String(selectionBoxes.filter(function (box) { return box.checked; }).length);
      document.querySelectorAll('.select-category-products').forEach(function (categoryBox) {
        const groupBoxes = Array.from(categoryBox.closest('.catalog-category-group').querySelectorAll('.catalog-product-checkbox'));
        const selectedCount = groupBoxes.filter(function (box) { return box.checked; }).length;
        categoryBox.checked = groupBoxes.length > 0 && selectedCount === groupBoxes.length;
        categoryBox.indeterminate = selectedCount > 0 && selectedCount < groupBoxes.length;
      });
    };
    const applyCatalogFilters = function () {
      const query = searchInput.value.trim().toLowerCase();
      productRows.forEach(function (row) {
        const matches = row.dataset.productName.includes(query);
        row.hidden = !matches;
      });
      document.querySelectorAll('.catalog-category-group').forEach(function (group) {
        group.hidden = !Array.from(group.querySelectorAll('.catalog-product-row')).some(function (row) { return !row.hidden; });
      });
    };
    categoryFilter.addEventListener('change', function () {
      const url = new URL(window.location.href);
      if (categoryFilter.value) url.searchParams.set('category', categoryFilter.value);
      else url.searchParams.delete('category');
      window.location.assign(url.toString());
    });
    searchInput.addEventListener('input', applyCatalogFilters);
    selectionBoxes.forEach(function (box) { box.addEventListener('change', updateSelectionCount); });
    document.querySelectorAll('.select-category-products').forEach(function (categoryBox) {
      categoryBox.addEventListener('change', function () {
        const group = categoryBox.closest('.catalog-category-group');
        group.querySelectorAll('.catalog-product-row:not([hidden]) .catalog-product-checkbox').forEach(function (box) {
          box.checked = categoryBox.checked;
        });
        updateSelectionCount();
      });
    });
    document.getElementById('selectVisibleProducts').addEventListener('click', function () {
      selectionBoxes.forEach(function (box) {
        box.checked = !box.closest('.catalog-product-row').hidden;
      });
      updateSelectionCount();
    });
    document.getElementById('clearProductSelection').addEventListener('click', function () {
      selectionBoxes.forEach(function (box) { box.checked = false; });
      document.querySelectorAll('.select-category-products').forEach(function (box) { box.checked = false; });
      updateSelectionCount();
    });
    document.getElementById('bulkVisibilityForm').addEventListener('submit', function (event) {
      if (!selectionBoxes.some(function (box) { return box.checked; })) {
        event.preventDefault();
        window.alert('Please select at least one product.');
        return;
      }
      const action = event.submitter && event.submitter.dataset.action === 'show' ? 'show' : 'hide';
      if (!window.confirm(action === 'show' ? 'Show the selected products in the store?' : 'Hide the selected products from the store?')) {
        event.preventDefault();
      }
    });
    updateSelectionCount();
  })();
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
