<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Add Vegetable';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price       = (float)($_POST['price'] ?? 0);
    $unit        = trim($_POST['unit'] ?? 'kg');
    $stock       = (int)($_POST['stock'] ?? 0);
    $category    = trim($_POST['category'] ?? 'Vegetable');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $price <= 0) {
        $error = 'Name and a valid price are required.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO vegetables (name, description, price, unit, stock, category, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $price, $unit, $stock, $category, $is_active]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => "$name added to the catalog."];
        redirect('dashboard.php');
    }
}

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Add Vegetable</h2>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="form-card" style="max-width:560px;">
  <form method="post">
    <div class="form-group">
      <label for="name">Vegetable name</label>
      <input type="text" id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>" required>
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
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
          <?php foreach (['kg','piece','bunch','dozen','gram'] as $u): ?>
            <option value="<?= $u ?>" <?= ($_POST['unit'] ?? 'kg') === $u ? 'selected' : '' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="stock">Stock quantity</label>
        <input type="number" id="stock" name="stock" min="0" value="<?= h($_POST['stock'] ?? 50) ?>">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="category">Category</label>
        <input type="text" id="category" name="category" value="<?= h($_POST['category'] ?? 'Vegetable') ?>">
      </div>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="is_active" checked style="width:auto; display:inline-block;"> Visible in store</label>
    </div>
    <button type="submit" class="btn btn-primary">Save Vegetable</button>
    <a href="dashboard.php" class="btn btn-outline">Cancel</a>
  </form>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
