<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Categories';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category_order'])) {
    require_csrf();
    $submittedOrder = $_POST['category_ids'] ?? null;
    if (!is_array($submittedOrder)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'The category order submitted was invalid.'];
    } else {
        $categoryIds = array_map('intval', $pdo->query('SELECT id FROM categories')->fetchAll(PDO::FETCH_COLUMN));
        $submittedIds = [];
        $validOrder = true;
        foreach ($submittedOrder as $submittedId) {
            $categoryId = filter_var($submittedId, FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 1) {
                $validOrder = false;
                break;
            }
            $submittedIds[] = $categoryId;
        }
        sort($categoryIds);
        $sortedSubmittedIds = $submittedIds;
        sort($sortedSubmittedIds);
        $validOrder = $validOrder && $categoryIds === $sortedSubmittedIds
            && count($submittedIds) === count(array_unique($submittedIds));
        if (!$validOrder) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'The category order was invalid. Refresh the page and try again.'];
        } else {
            try {
                $pdo->beginTransaction();
                $updateOrder = $pdo->prepare('UPDATE categories SET sort_order = ? WHERE id = ?');
                foreach ($submittedIds as $position => $id) {
                    $updateOrder->execute([$position, $id]);
                }
                $pdo->commit();
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Category order saved. The storefront now uses your new order.'];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Could not save category order: ' . $e->getMessage());
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not save the category order. Please try again.'];
            }
        }
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Category name is required.'];
    } else {
        $upload = handle_vegetable_image_upload('category_image');
        try {
            if ($upload['error']) {
                $_SESSION['flash'] = ['type' => 'error', 'message' => $upload['error']];
            } else {
                $maxOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) FROM categories")->fetchColumn();
                $st = $pdo->prepare("INSERT INTO categories (name, image_path, sort_order, is_active) VALUES (?, ?, ?, 1)");
                $st->execute([$name, $upload['path'], $maxOrder + 1]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => "\"$name\" added. It'll show up in the Category dropdown on Add/Edit Vegetable right away."];
            }
        } catch (Throwable $e) {
            if (!empty($upload['path'])) {
                delete_vegetable_image_file($upload['path']);
            }
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'That category already exists.'];
            } else {
                error_log('Could not add category image/category: ' . $e->getMessage());
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not add the category. Please try again.'];
            }
        }
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category_image'])) {
    require_csrf();
    $id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
    $removeImage = isset($_POST['remove_category_image']);
    $upload = handle_vegetable_image_upload('category_image');
    if (!$id || $id < 1) {
        if (!empty($upload['path'])) {
            delete_vegetable_image_file($upload['path']);
        }
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid category.'];
    } elseif ($upload['error']) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $upload['error']];
    } else {
        $current = $pdo->prepare('SELECT image_path FROM categories WHERE id = ?');
        $current->execute([$id]);
        $oldImage = $current->fetchColumn();
        if ($oldImage === false) {
            if (!empty($upload['path'])) {
                delete_vegetable_image_file($upload['path']);
            }
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Category not found.'];
        } elseif ($upload['path'] || $removeImage) {
            $newImage = $upload['path'] ?: null;
            try {
                $stmt = $pdo->prepare('UPDATE categories SET image_path = ? WHERE id = ?');
                $stmt->execute([$newImage, $id]);
                if ($oldImage && $oldImage !== $newImage) {
                    delete_vegetable_image_file($oldImage);
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Category image updated.'];
            } catch (Throwable $e) {
                if (!empty($upload['path'])) {
                    delete_vegetable_image_file($upload['path']);
                }
                error_log('Could not update category image #' . $id . ': ' . $e->getMessage());
                $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not save the category image. Please try again.'];
            }
        } else {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Choose an image or select Remove image.'];
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
        $name = $pdo->prepare("SELECT name, image_path FROM categories WHERE id = ?");
        $name->execute([$id]);
        $categoryRow = $name->fetch();
        $catName = $categoryRow['name'] ?? false;
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
            if (!empty($categoryRow['image_path'])) {
                delete_vegetable_image_file($categoryRow['image_path']);
            }
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
  <p style="color:#5B6656; max-width:720px;">Drag categories using the handle to arrange their order in the storefront. On long lists, move the dragged category near the top or bottom of the screen to scroll automatically. Click Save category order when finished.</p>
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
          <tr>
            <th colspan="6">
              <form id="categoryOrderForm" method="post" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <span id="categoryOrderStatus" role="status" aria-live="polite" style="font-weight:500;color:#5B6656;">Drag categories to arrange them.</span>
                <button class="btn btn-primary" type="submit" name="save_category_order" value="1">Save category order</button>
              </form>
            </th>
          </tr>
          <tr><th>Order</th><th>Name</th><th>Image / logo</th><th>Products</th><th>Store visibility</th><th>Actions</th></tr>
        </thead>
        <tbody id="categoryOrderRows">
          <?php foreach ($categories as $c): ?>
            <tr data-category-row data-category-id="<?= (int)$c['id'] ?>">
              <td>
                <button type="button" class="category-drag-handle" aria-label="Drag to arrange <?= h($c['name']) ?>" title="Drag to arrange" style="border:1px solid #D9E0CD;border-radius:8px;background:#F7F9F5;color:#53645a;padding:8px 10px;cursor:grab;touch-action:none;font-size:1.1rem;line-height:1;">☰</button>
                <input type="hidden" form="categoryOrderForm" name="category_ids[]" value="<?= (int)$c['id'] ?>">
              </td>
              <td>
                <form method="post" style="display:flex; gap:8px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="rename_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                  <input type="text" name="name" value="<?= h($c['name']) ?>" style="flex:1; padding:6px 8px; border-radius:8px; border:1px solid #D9E0CD;">
                  <button class="btn" type="submit" style="padding:6px 10px; font-size:0.78rem;">Save</button>
                </form>
              </td>
              <td>
                <form method="post" enctype="multipart/form-data" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;min-width:220px;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="save_category_image" value="1">
                  <input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>">
                  <?php if (!empty($c['image_path'])): ?>
                    <?php $categoryImageUrl = vegetable_image_url($c['image_path']); ?>
                    <?php if ($categoryImageUrl): ?>
                      <img src="<?= h($categoryImageUrl) ?>" alt="<?= h($c['name']) ?> category" style="width:44px;height:44px;object-fit:cover;border-radius:10px;border:1px solid #D9E0CD;">
                    <?php else: ?>
                      <span style="font-size:.75rem;color:#9A2E24;">Image missing</span>
                    <?php endif; ?>
                    <label style="display:inline-flex;align-items:center;gap:4px;font-size:.75rem;">
                      <input type="checkbox" name="remove_category_image" style="width:auto;"> Remove
                    </label>
                  <?php else: ?>
                    <span style="width:44px;height:44px;display:grid;place-items:center;background:#F3F7EF;border-radius:10px;font-size:1.2rem;" aria-hidden="true">🧺</span>
                  <?php endif; ?>
                  <input type="file" name="category_image" accept="image/jpeg,image/png,image/webp" style="max-width:180px;font-size:.75rem;">
                  <button class="btn" type="submit" style="padding:6px 9px;font-size:.78rem;">Save image</button>
                </form>
              </td>
              <td><?= (int)$c['product_count'] ?></td>
              <td><?= $c['is_active'] ? 'Visible' : 'Hidden' ?></td>
              <td style="white-space:nowrap;">
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="toggle_category" value="1">
                  <input type="hidden" name="category_id" value="<?= $c['id'] ?>">
                  <button class="btn" style="padding:6px 10px; font-size:0.78rem;"><?= $c['is_active'] ? 'Hide category' : 'Show category' ?></button>
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
            <tr><td colspan="6" style="text-align:center; color:#5B6656;">No categories yet. Add your first one!</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <aside style="width:320px;">
    <div class="form-card">
      <h3>Add category</h3>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="add_category" value="1">
        <div class="form-group">
          <label for="name">Category name</label>
          <input type="text" id="name" name="name" placeholder="e.g. Exotic Vegetables" required>
        </div>
        <div class="form-group">
          <label for="newCategoryImage">Category image or logo (optional)</label>
          <input type="file" id="newCategoryImage" name="category_image" accept="image/jpeg,image/png,image/webp">
          <small style="color:#5B6656;">JPG, PNG, or WEBP; up to 5 MB.</small>
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

<script>
  (function () {
    const list = document.getElementById('categoryOrderRows');
    const orderForm = document.getElementById('categoryOrderForm');
    const status = document.getElementById('categoryOrderStatus');
    if (!list || !orderForm || !status) return;

    let activeRow = null;
    let pointerX = 0;
    let pointerY = 0;
    let autoScrollFrame = 0;

    const syncOrderInputs = function () {
      const inputs = Array.from(document.querySelectorAll('input[form="categoryOrderForm"][name="category_ids[]"]'));
      const rows = Array.from(list.querySelectorAll('[data-category-row]'));
      rows.forEach(function (row, index) {
        inputs[index].value = row.dataset.categoryId;
      });
    };

    const moveActiveRow = function () {
      if (!activeRow) return;
      const target = document.elementFromPoint(pointerX, pointerY);
      const targetRow = target && target.closest('[data-category-row]');
      if (!targetRow || targetRow === activeRow || targetRow.parentElement !== list) return;
      const bounds = targetRow.getBoundingClientRect();
      list.insertBefore(activeRow, pointerY < bounds.top + bounds.height / 2 ? targetRow : targetRow.nextSibling);
    };

    const autoScroll = function () {
      if (!activeRow) {
        autoScrollFrame = 0;
        return;
      }
      const edgeSize = 90;
      const maxStep = 18;
      if (pointerY < edgeSize) {
        window.scrollBy(0, -Math.ceil((edgeSize - pointerY) / edgeSize * maxStep));
      } else if (pointerY > window.innerHeight - edgeSize) {
        window.scrollBy(0, Math.ceil((pointerY - (window.innerHeight - edgeSize)) / edgeSize * maxStep));
      }
      moveActiveRow();
      autoScrollFrame = window.requestAnimationFrame(autoScroll);
    };

    list.querySelectorAll('.category-drag-handle').forEach(function (handle) {
      handle.addEventListener('pointerdown', function (event) {
        if (event.button !== undefined && event.button !== 0) return;
        activeRow = handle.closest('[data-category-row]');
        pointerX = event.clientX;
        pointerY = event.clientY;
        activeRow.classList.add('category-row-dragging');
        handle.setPointerCapture(event.pointerId);
        status.textContent = 'Move the category, then release to place it.';
        if (!autoScrollFrame) autoScrollFrame = window.requestAnimationFrame(autoScroll);
        event.preventDefault();
      });

      handle.addEventListener('pointermove', function (event) {
        if (!activeRow) return;
        pointerX = event.clientX;
        pointerY = event.clientY;
        moveActiveRow();
      });

      const finishDrag = function () {
        if (!activeRow) return;
        activeRow.classList.remove('category-row-dragging');
        activeRow = null;
        if (autoScrollFrame) {
          window.cancelAnimationFrame(autoScrollFrame);
          autoScrollFrame = 0;
        }
        syncOrderInputs();
        status.textContent = 'Order changed. Click Save category order to apply it to the store.';
      };
      handle.addEventListener('pointerup', finishDrag);
      handle.addEventListener('pointercancel', finishDrag);
      handle.addEventListener('lostpointercapture', finishDrag);
    });

    orderForm.addEventListener('submit', syncOrderInputs);
  })();
</script>
<style>
  #categoryOrderRows tr.category-row-dragging{opacity:.55;background:#eef7ed}
  #categoryOrderRows tr{transition:background-color .15s ease}
  .category-drag-handle:active{cursor:grabbing!important}
</style>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
