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
    $description = trim($_POST['description'] ?? '');
    $price       = (float)($_POST['price'] ?? 0);
    $unit        = trim($_POST['unit'] ?? 'kg');
    $stock       = (int)($_POST['stock'] ?? 0);
    $category    = trim($_POST['category'] ?? 'Vegetable');
    $is_active   = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '' || $price <= 0) {
        $error = 'Name and a valid price are required.';
        $veg = array_merge($veg, $_POST); // repopulate form with attempted values
    } else {
        $stmt = $pdo->prepare("UPDATE vegetables SET name=?, description=?, price=?, unit=?, stock=?, category=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $description, $price, $unit, $stock, $category, $is_active, $id]);

        $_SESSION['flash'] = ['type' => 'success', 'message' => "$name updated successfully."];
        redirect('dashboard.php');
    }
}

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Edit Vegetable</h2>
</div>

<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="form-card" style="max-width:560px;">
  <form method="post">
    <div class="form-group">
      <label for="name">Vegetable name</label>
      <input type="text" id="name" name="name" value="<?= h($veg['name']) ?>" required>
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
        <label for="unit">Unit</label>
        <select id="unit" name="unit">
          <?php foreach (['kg','piece','bunch','dozen','gram'] as $u): ?>
            <option value="<?= $u ?>" <?= $veg['unit'] === $u ? 'selected' : '' ?>><?= $u ?></option>
          <?php endforeach; ?>
        </select>
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
    <button type="submit" class="btn btn-primary">Update Vegetable</button>
    <a href="dashboard.php" class="btn btn-outline">Cancel</a>
  </form>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
