<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Category name is required.'];
    } else {
        try {
            $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) FROM categories")->fetchColumn();
            $st = $pdo->prepare("INSERT INTO categories (name, sort_order, is_active) VALUES (?, ?, 1)");
            $st->execute([$name, $maxOrder + 1]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "\"$name\" added. It'll show up in the Category dropdown on Add/Edit Vegetable right away."];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'That category already exists.'];
        }
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_category'])) {
    require_csrf();
    $id = (int)($_POST['category_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id > 0 && $name !== '') {
        $old = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $old->execute([$id]);
        $oldName = $old->fetchColumn();
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?")->execute([$name, $id]);
            if ($oldName !== false && $oldName !== $name) {
                // Keep every product's category text in sync with the rename,
                // otherwise they'd silently fall back to the old (now gone)
                // category name and disappear from this list's product counts.
                $pdo->prepare("UPDATE vegetables SET category = ? WHERE category = ?")->execute([$name, $oldName]);
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Category renamed.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'A category with that name already exists.'];
        }
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_category'])) {
    require_csrf();
    $id = (int)($_POST['category_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    require_csrf();
    $id = (int)($_POST['category_id'] ?? 0);
    if ($id > 0) {
        $name = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $name->execute([$id]);
        $catName = $name->fetchColumn();
        $inUseCount = 0;
        if ($catName !== false) {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM vegetables WHERE category = ?");
            $countStmt->execute([$catName]);
            $inUseCount = (int)$countStmt->fetchColumn();
        }
        if ($inUseCount > 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => "Can't delete \"$catName\" — $inUseCount product(s) still use it. Move them to another category first."];
        } else {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Category deleted.'];
        }
    }
    redirect('categories.php');
}

$categories = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM vegetables v WHERE v.category = c.name) AS product_count
    FROM categories c
    ORDER BY c.sort_order ASC, c.name ASC
")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Categories</h2>
  <p style="color:#5B6656; max-width:720px;">Manage the category list used on the Add/Edit Vegetable form and the storefront's filter chips. Add a category here first, then it shows up as a dropdown option — no more re-typing (and no more near-duplicates like "Vegetable" vs "Vegetables").</p>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-<?= h($_SESSION['flash']['type']) ?>"><?= h($_SESSION['flash']['message']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
  <div style="flex:2; min-width:420px;">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Name</th><th>Products</th><th>Active</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($categories as $c): ?>
            <tr>
              <td><?= $c['id'] ?></td>
              <td>
                <form method="post" style="display:flex; gap:8px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="rename_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                  <input type="text" name="name" value="<?= h($c['name']) ?>" style="flex:1; padding:6px 8px; border-radius:8px; border:1px solid #D9E0CD;">
                  <button class="btn" type="submit" style="padding:6px 10px; font-size:0.78rem;">Save</button>
                </form>
              </td>
              <td><?= (int)$c['product_count'] ?></td>
              <td><?= $c['is_active'] ? 'Yes' : 'No' ?></td>
              <td style="white-space:nowrap;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="toggle_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                  <button class="btn" style="padding:6px 10px; font-size:0.78rem;"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                </form>
                <form method="post" style="display:inline;" onsubmit="return confirm('Delete category \'<?= h($c['name']) ?>\'? Only possible if no products use it.');">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="delete_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                  <button class="btn" style="padding:6px 10px; font-size:0.78rem; background:#FCE8E6; color:#9A2E24; border-color:#f4cfc9;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($categories)): ?>
            <tr><td colspan="5" style="text-align:center; color:#5B6656;">No categories yet. Add your first one!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <aside style="width:320px;">
    <div class="form-card">
      <h3>Add category</h3>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="add_category" value="1">
        <div class="form-group">
          <label for="name">Category name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Exotic Vegetables" required>
        </div>
        <div style="text-align:right;">
          <button class="btn btn-primary">Add category</button>
        </div>
      </form>
    </div>
    <div class="form-card" style="margin-top:16px;">
      <a href="add_vegetable.php" class="btn" style="width:100%; text-align:center; display:block; box-sizing:border-box;">+ Add Vegetable</a>
    </div>
  </aside>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
