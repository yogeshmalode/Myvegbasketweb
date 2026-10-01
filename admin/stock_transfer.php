<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Stock Transfer';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 AND procurement_mode = 'centralized' ORDER BY name")->fetchAll();

// Only per_store products ever need a transfer — shared products are already
// visible to every store from the same central number, nothing to move.
$perStoreProducts = $pdo->query("SELECT id, name, unit, stock FROM vegetables WHERE is_active = 1 AND stock_mode = 'per_store' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $vegId = (int)($_POST['vegetable_id'] ?? 0);
    $storeId = (int)($_POST['dark_store_id'] ?? 0);
    $qty = (float)($_POST['quantity'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($vegId <= 0 || $storeId <= 0 || $qty <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please choose a product, a destination store, and a quantity greater than 0.'];
    } else {
        try {
            $pdo->beginTransaction();

            $modeSt = $pdo->prepare("SELECT stock_mode FROM vegetables WHERE id = ? FOR UPDATE");
            $modeSt->execute([$vegId]);
            $mode = $modeSt->fetchColumn();
            if ($mode !== 'per_store') {
                throw new Exception('This product is in Shared mode — all stores already use the same stock number, no transfer needed.');
            }

            // Guard against a tampered form posting an independent store's
            // id directly — that store manages its own stock on Store
            // Procurement and should never receive a silent central push.
            $modeCheckSt = $pdo->prepare("SELECT procurement_mode FROM dark_stores WHERE id = ?");
            $modeCheckSt->execute([$storeId]);
            if ($modeCheckSt->fetchColumn() !== 'centralized') {
                throw new Exception('That store is set to Independent procurement — it manages its own stock and cannot receive a central transfer.');
            }

            // Pull out of the central pool first; this fails (returns false)
            // if the central pool doesn't have enough, so nothing is ever
            // double-counted or allowed to go negative.
            if (!apply_stock_delta($pdo, $vegId, -$qty, null)) {
                throw new Exception('Not enough stock in the central pool to send that much.');
            }
            apply_stock_delta($pdo, $vegId, $qty, $storeId);

            $pdo->prepare("INSERT INTO stock_transfers (vegetable_id, dark_store_id, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$vegId, $storeId, $qty, $notes ?: null, $_SESSION['admin_id']]);

            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Stock transferred to store successfully.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Transfer failed: ' . $e->getMessage()];
        }
    }
    header('Location: stock_transfer.php');
    exit;
}

// Current central vs per-store breakdown, so admin can see at a glance what's
// still unallocated (central) vs what's already been sent to each store.
$breakdown = [];
foreach ($perStoreProducts as $p) {
    $siSt = $pdo->prepare("SELECT ds.name AS store_name, si.stock FROM store_inventory si JOIN dark_stores ds ON ds.id = si.dark_store_id WHERE si.vegetable_id = ? ORDER BY ds.name");
    $siSt->execute([$p['id']]);
    $breakdown[$p['id']] = $siSt->fetchAll();
}

$recentTransfers = $pdo->query("SELECT st.*, v.name AS vegetable_name, v.unit, ds.name AS store_name, a.username
    FROM stock_transfers st
    JOIN vegetables v ON v.id = st.vegetable_id
    JOIN dark_stores ds ON ds.id = st.dark_store_id
    LEFT JOIN admins a ON a.id = st.created_by
    ORDER BY st.id DESC LIMIT 20")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading">
  <div>
    <h1>Stock Transfer</h1>
    <p>Send purchased stock from the central pool out to a specific store. Only "Per-Store" mode products need this — "Shared" products are already visible to every store automatically.</p>
  </div>
</div>
<?php if ($flash): ?><div class="admin-alert admin-alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<?php if (!$perStoreProducts): ?>
  <div class="admin-panel" style="padding:18px;">No "Per-Store" mode products yet. Set a product to Per-Store mode on the Add/Edit Vegetable page first.</div>
<?php else: ?>

<section class="admin-table-wrap" style="margin-bottom:18px;">
  <div class="admin-panel-head"><h2>Send Stock to a Store</h2></div>
  <form method="post" style="padding:16px; display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="admin-field">
      <label>Product</label>
      <select name="vegetable_id" required style="min-width:220px;">
        <option value="">Select product...</option>
        <?php foreach ($perStoreProducts as $p): ?>
          <option value="<?= $p['id'] ?>">
            <?= h($p['name']) ?> — Central: <?= number_format((float)$p['stock'], 2) ?> <?= h($p['unit']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label>Destination Store</label>
      <select name="dark_store_id" required style="min-width:180px;">
        <option value="">Select store...</option>
        <?php foreach ($allStores as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="admin-field">
      <label>Quantity</label>
      <input type="number" name="quantity" step="0.001" min="0.001" required style="width:120px;">
    </div>
    <div class="admin-field" style="flex:1; min-width:200px;">
      <label>Notes (optional)</label>
      <input type="text" name="notes" maxlength="255" placeholder="e.g. Morning delivery van #2">
    </div>
    <button type="submit" class="admin-btn admin-btn-primary">🚛 Send to Store</button>
  </form>
</section>

<section class="admin-table-wrap" style="margin-bottom:18px;">
  <div class="admin-panel-head"><h2>Current Stock Breakdown</h2><span style="font-size:11px;color:#68736f">Central pool vs. what each store already has</span></div>
  <table class="admin-table">
    <thead><tr><th>Product</th><th>Central (Unallocated)</th><th>Sent to Stores</th></tr></thead>
    <tbody>
      <?php foreach ($perStoreProducts as $p): ?>
        <tr>
          <td class="product-cell"><?= h($p['name']) ?></td>
          <td><?= number_format((float)$p['stock'], 2) ?> <?= h($p['unit']) ?></td>
          <td>
            <?php if (!empty($breakdown[$p['id']])): ?>
              <?php foreach ($breakdown[$p['id']] as $row): ?>
                <span class="admin-badge badge-green" style="margin-right:6px;"><?= h($row['store_name']) ?>: <?= number_format((float)$row['stock'], 2) ?></span>
              <?php endforeach; ?>
            <?php else: ?>
              <span style="color:#68736f;">Not sent to any store yet</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<section class="admin-table-wrap">
  <div class="admin-panel-head"><h2>Recent Transfers</h2><span style="font-size:11px;color:#68736f">Latest 20</span></div>
  <table class="admin-table">
    <thead><tr><th>Date</th><th>Product</th><th>Store</th><th>Quantity</th><th>Notes</th><th>By</th></tr></thead>
    <tbody>
      <?php foreach ($recentTransfers as $t): ?>
        <tr>
          <td><?= h(date('d M Y, h:i A', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['vegetable_name']) ?></td>
          <td><?= h($t['store_name']) ?></td>
          <td><?= number_format((float)$t['quantity'], 2) ?> <?= h($t['unit']) ?></td>
          <td><?= h($t['notes'] ?? '—') ?></td>
          <td><?= h($t['username'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentTransfers): ?><tr><td colspan="6" class="admin-empty">No transfers yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
</section>

<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
