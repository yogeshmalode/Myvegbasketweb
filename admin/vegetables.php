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
    redirect('vegetables.php');
}

$vegetables = $pdo->query("SELECT * FROM vegetables ORDER BY id DESC")->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Vegetable Catalog</h2>
</div>

<?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
<?php endif; ?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
  <p style="color:#5B6656;"><?= count($vegetables) ?> item(s) in catalog</p>
  <div style="display:flex; gap:10px;">
  <a href="catalog_pricing.php" class="btn" title="Live per-item pricing">⚡ Catalog & Live Pricing</a>
  <a href="inventory.php" class="btn">Inventory</a>
  <a href="billing.php" class="btn">Billing</a>
  <a href="add_vegetable.php" class="btn btn-primary">+ Add Vegetable</a>
</div>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Status</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($vegetables as $veg): ?>
        <tr>
          <td><?= $veg['id'] ?></td>
          <td><?= veg_emoji($veg['name']) ?> <?= h($veg['name']) ?></td>
          <td><?= h($veg['category']) ?></td>
          <td>
            ₹<?= number_format($veg['price'],2) ?> / <?= h($veg['unit']) ?>
            <?php if (!empty($veg['sale_price']) && $veg['sale_price'] < $veg['price']): ?>
              <br><span style="color:var(--tomato); font-weight:700; font-size:0.82rem;">Sale: ₹<?= number_format($veg['sale_price'],2) ?></span>
            <?php endif; ?>
          </td>
          <td><?= (int)$veg['stock'] ?></td>
          <td>
            <?php if ($veg['is_active']): ?>
              <span class="badge badge-green">Active</span>
            <?php else: ?>
              <span class="badge badge-red">Hidden</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="edit_vegetable.php?id=<?= $veg['id'] ?>" class="action-link edit">Edit</a>
            <a href="delete_vegetable.php?id=<?= $veg['id'] ?>" class="action-link delete" onclick="return confirm('Delete <?= h($veg['name']) ?>? This cannot be undone.');">Delete</a>
                      <form method="post" style="display:inline; margin-left:8px">
                        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="veg_id" value="<?= $veg['id'] ?>">
                        <button type="submit" name="recalc_variants" value="1" class="action-link" style="background:none;border:none;padding:0;color:#2a7ae2;cursor:pointer;font-size:0.9rem;">🔄 Rescale sizes</button>
                      </form>
                    </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($vegetables)): ?>
        <tr><td colspan="7" style="text-align:center; color:#5B6656;">No vegetables yet. Add your first one!</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
