<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Manage Vegetables';

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
    <a href="bulk_update_prices.php" class="btn" style="background:#fff; border:1px solid #E4E9DD;">💰 Update all prices</a>
    <a href="inventory.php" class="btn">Inventory</a><a href="billing.php" class="btn">Billing</a><a href="add_vegetable.php" class="btn btn-primary">+ Add Vegetable</a>
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
