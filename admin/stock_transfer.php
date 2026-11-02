<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Stock Transfer';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 AND procurement_mode = 'centralized' ORDER BY name")->fetchAll();

$products = $pdo->query("SELECT id, name, unit, stock, stock_mode FROM vegetables WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $storeId = (int)($_POST['dark_store_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $postedItems = $_POST['items'] ?? [];
    $items = [];
    $validationError = null;

    if (!is_array($postedItems) || !$postedItems || !$storeId || strlen($notes) > 255) {
        $validationError = 'Choose a destination and at least one product. Keep notes under 255 characters.';
    } else {
        $seenProducts = [];
        foreach ($postedItems as $item) {
            if (!is_array($item)) {
                $validationError = 'Invalid product row. Refresh the page and try again.';
                break;
            }
            $vegId = (int)($item['vegetable_id'] ?? 0);
            $quantityInput = trim((string)($item['quantity'] ?? ''));
            if ($vegId <= 0 && $quantityInput === '') {
                continue;
            }
            $qty = is_numeric($quantityInput) ? (float)$quantityInput : 0.0;
            if ($vegId <= 0 || !is_finite($qty) || $qty <= 0 || round($qty, 3) !== $qty) {
                $validationError = 'Each selected product needs a quantity greater than zero with up to 3 decimal places.';
                break;
            }
            if (isset($seenProducts[$vegId])) {
                $validationError = 'Select each product only once in the batch.';
                break;
            }
            $seenProducts[$vegId] = true;
            $items[] = [
                'vegetable_id' => $vegId,
                'quantity' => $qty,
                'confirm_stock_mode_change' => ($item['confirm_stock_mode_change'] ?? '') === '1',
            ];
        }
        if (!$validationError && !$items) {
            $validationError = 'Add at least one product and enter its transfer quantity.';
        }
    }

    if ($validationError) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $validationError];
    } else {
        usort($items, function ($left, $right) {
            return $left['vegetable_id'] <=> $right['vegetable_id'];
        });
        try {
            $pdo->beginTransaction();

            // Guard against a tampered form posting an independent store's
            // id directly — independent stores manage their own purchasing.
            $modeCheckSt = $pdo->prepare("SELECT procurement_mode FROM dark_stores WHERE id = ? AND is_active = 1 FOR UPDATE");
            $modeCheckSt->execute([$storeId]);
            $storeMode = $modeCheckSt->fetchColumn();
            if ($storeMode === false) {
                throw new Exception('That destination store is inactive or no longer available. Refresh and choose an active store.');
            }
            if ($storeMode !== 'centralized') {
                throw new Exception('That store manages its own purchasing and cannot receive a central stock transfer.');
            }

            $modeSt = $pdo->prepare("SELECT stock_mode, is_active FROM vegetables WHERE id = ? FOR UPDATE");
            $transferSt = $pdo->prepare("INSERT INTO stock_transfers (vegetable_id, dark_store_id, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)");
            $transferredProductIds = [];
            foreach ($items as $item) {
                $vegId = $item['vegetable_id'];
                $qty = $item['quantity'];
                $modeSt->execute([$vegId]);
                $product = $modeSt->fetch();
                if (!$product || !(int)$product['is_active']) {
                    throw new Exception('One selected product is no longer active. No stock was moved; refresh and try again.');
                }
                if ($product['stock_mode'] === 'shared') {
                    if (!$item['confirm_stock_mode_change']) {
                        throw new Exception('Confirm the Shared-to-Per-Store change for every Shared product in this batch.');
                    }
                    $pdo->prepare("UPDATE vegetables SET stock_mode = 'per_store' WHERE id = ?")->execute([$vegId]);
                } elseif ($product['stock_mode'] !== 'per_store') {
                    throw new Exception('A selected product has an unsupported stock mode. Refresh the page and try again.');
                }

                if (!apply_stock_delta($pdo, $vegId, -$qty, null)) {
                    throw new Exception('Not enough central stock for product #' . $vegId . '. No stock was moved.');
                }
                if (!apply_stock_delta($pdo, $vegId, $qty, $storeId)) {
                    throw new Exception('Could not add stock for product #' . $vegId . '. No stock was moved.');
                }

                $transferSt->execute([$vegId, $storeId, $qty, $notes ?: null, $_SESSION['admin_id']]);
                $transferredProductIds[] = $vegId;
            }

            $pdo->commit();
            foreach ($transferredProductIds as $vegId) {
                try {
                    dispatch_back_in_stock_notifications($pdo, $vegId);
                } catch (Throwable $e) {
                    error_log('Could not dispatch back-in-stock alerts after stock transfer for product #' . $vegId . ': ' . $e->getMessage());
                }
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => count($items) . ' product(s) transferred successfully. Central stock was reduced and destination stock increased.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Transfer failed: ' . $e->getMessage()];
        }
    }
    header('Location: stock_transfer.php');
    exit;
}

// Build the same centralized-store stock map used by the transfer form and
// the breakdown table; independent stores are deliberately excluded.
$breakdown = [];
$storeStocks = [];
foreach ($products as $p) {
    $siSt = $pdo->prepare("SELECT ds.id AS store_id, ds.name AS store_name, COALESCE(si.stock, 0) AS stock
        FROM dark_stores ds
        LEFT JOIN store_inventory si ON si.dark_store_id = ds.id AND si.vegetable_id = ?
        WHERE ds.is_active = 1 AND ds.procurement_mode = 'centralized'
        ORDER BY ds.name");
    $siSt->execute([$p['id']]);
    $breakdown[$p['id']] = $siSt->fetchAll();
    foreach ($breakdown[$p['id']] as $row) {
        $storeStocks[(int)$p['id']][(int)$row['store_id']] = (float)$row['stock'];
    }
}

$recentTransfers = $pdo->query("SELECT st.*, v.name AS vegetable_name, v.unit, ds.name AS store_name, a.username
    FROM stock_transfers st
    JOIN vegetables v ON v.id = st.vegetable_id
    JOIN dark_stores ds ON ds.id = st.dark_store_id
    LEFT JOIN admins a ON a.id = st.created_by
    ORDER BY st.id DESC LIMIT 20")->fetchAll();
$todayTransfers = $pdo->query("SELECT st.*, v.name AS vegetable_name, v.unit, ds.name AS store_name, a.username
    FROM stock_transfers st
    JOIN vegetables v ON v.id = st.vegetable_id
    JOIN dark_stores ds ON ds.id = st.dark_store_id
    LEFT JOIN admins a ON a.id = st.created_by
    WHERE st.created_at >= CURDATE() AND st.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    ORDER BY st.id DESC")->fetchAll();
$todayTransferCount = count($todayTransfers);
$todayProductCount = count(array_unique(array_column($todayTransfers, 'vegetable_id')));
$todayStoreCount = count(array_unique(array_column($todayTransfers, 'dark_store_id')));

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading">
  <div>
    <h1>Stock Transfer</h1>
    <p>Move stock from your central pool to a centralized-procurement store. A Shared product becomes Per-Store when you confirm its first transfer.</p>
  </div>
</div>
<?php if ($flash): ?><div class="admin-alert admin-alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<style>
  .transfer-flow{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}
  .transfer-flow-step{display:flex;align-items:flex-start;gap:11px;padding:14px;background:#fff;border:1px solid var(--admin-line);border-radius:12px}
  .transfer-flow-number{display:grid;place-items:center;flex:0 0 30px;height:30px;border-radius:50%;background:#e5f7ed;color:var(--admin-green);font-weight:800}
  .transfer-flow-step strong{display:block;color:#17231f;font-size:13px}
  .transfer-flow-step span{display:block;margin-top:3px;color:var(--admin-muted);font-size:12px;line-height:1.45}
  .transfer-panel{margin-bottom:18px}
  .transfer-form{padding:18px 20px 20px}
  .transfer-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
  .transfer-form-grid .admin-field{min-width:0}
  .transfer-form-grid .admin-field label{font-size:13px}
  .transfer-form-grid .admin-field select,.transfer-form-grid .admin-field input{width:100%;height:44px;font-size:14px}
  .transfer-items{display:grid;gap:12px;margin-top:18px}
  .transfer-item-row{display:grid;grid-template-columns:minmax(220px,1.3fr) minmax(130px,.55fr) minmax(190px,.9fr) auto;align-items:end;gap:12px;padding:14px;border:1px solid var(--admin-line);border-radius:11px;background:#fbfdfb}
  .transfer-item-row .admin-field{min-width:0}
  .transfer-item-row .admin-field label{display:block;margin-bottom:6px;color:#45524b;font-size:12px;font-weight:700}
  .transfer-item-row select,.transfer-item-row input[type=number]{width:100%;height:42px}
  .transfer-item-stock{min-height:42px;display:flex;align-items:center;padding:8px 11px;border-radius:8px;background:#f0f7f2;color:#385247;font-size:12px;line-height:1.5}
  .transfer-item-row.is-invalid .transfer-item-stock{background:#fff2ef;color:#9a362c}
  .transfer-shared-confirm{grid-column:1/-1;margin:0;padding:9px 11px;border-radius:8px;background:#fff8ee;color:#5e4a32;font-size:12px}
  .transfer-shared-confirm label{display:flex;align-items:flex-start;gap:8px}
  .transfer-shared-confirm input{margin-top:2px}
  .transfer-remove{height:42px;min-width:42px;border:1px solid #f0d7d2;border-radius:8px;background:#fff;color:#a33d32;font-size:18px;cursor:pointer}
  .transfer-actions{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:14px}
  .transfer-summary{margin-top:14px;padding:12px 14px;border-left:3px solid #e8934a;border-radius:5px;background:#fff8ee;color:#5e4a32;font-size:13px;line-height:1.5}
  .transfer-confirm{margin-top:14px;padding:12px 14px;border-left:3px solid #e8934a;border-radius:5px;background:#fff8ee;color:#5e4a32;font-size:13px;line-height:1.5}
  .transfer-submit-row{display:flex;justify-content:flex-end;margin-top:16px}
  .transfer-submit-row .admin-btn{min-height:44px;padding:0 20px;font-size:14px}
  .transfer-submit-row .admin-btn:disabled{opacity:.5;cursor:not-allowed}
  .transfer-empty{padding:20px;margin-bottom:18px;line-height:1.55}
  .transfer-table-scroll{overflow-x:auto}
  .transfer-today-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}
  .transfer-today-stat{padding:13px;border:1px solid var(--admin-line);border-radius:10px;background:#f8fbf8}
  .transfer-today-stat strong{display:block;color:#145b3e;font-size:21px}
  .transfer-today-stat span{display:block;margin-top:3px;color:var(--admin-muted);font-size:12px}
  @media(max-width:760px){.transfer-flow{grid-template-columns:1fr}.transfer-form-grid{grid-template-columns:1fr}.transfer-form{padding:15px}.transfer-item-row{grid-template-columns:1fr 1fr}.transfer-item-stock{grid-column:1/-1}.transfer-shared-confirm{grid-column:1/-1}.transfer-remove{grid-column:2;justify-self:end}.transfer-actions{align-items:stretch;flex-direction:column}.transfer-submit-row .admin-btn{width:100%}.transfer-today-stats{grid-template-columns:1fr}}
</style>

<div class="transfer-flow" aria-label="Transfer steps">
  <div class="transfer-flow-step"><span class="transfer-flow-number">1</span><div><strong>Add products</strong><span>Choose multiple products and enter each quantity in one batch.</span></div></div>
  <div class="transfer-flow-step"><span class="transfer-flow-number">2</span><div><strong>Choose a destination</strong><span>Independent stores manage their own purchasing.</span></div></div>
  <div class="transfer-flow-step"><span class="transfer-flow-number">3</span><div><strong>Review today’s batch</strong><span>All products move together or none move; today’s history is saved below.</span></div></div>
</div>

<?php if (!$allStores): ?>
  <div class="admin-panel transfer-empty"><strong>No eligible destination stores.</strong><br>Add or activate a store using centralized procurement in the Stores page before transferring stock.</div>
<?php else: ?>

<?php if (!$products): ?>
  <div class="admin-panel transfer-empty"><strong>No active products found.</strong><br>Add or activate a product before creating a stock transfer.</div>
<?php endif; ?>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>New daily stock transfer</h2><span style="font-size:12px;color:var(--admin-muted)">Central pool → store stock</span></div>
  <form method="post" class="transfer-form" id="stockTransferForm">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="transfer-form-grid">
      <div class="admin-field">
        <label for="transferStore">Destination store</label>
        <select id="transferStore" name="dark_store_id" required <?= !$products ? 'disabled' : '' ?>>
          <option value="">Choose a centralized store</option>
          <?php foreach ($allStores as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="transferNotes">Daily batch note <span style="font-weight:400">(optional)</span></label>
        <input type="text" id="transferNotes" name="notes" maxlength="255" placeholder="e.g. Morning stock transfer" <?= !$products ? 'disabled' : '' ?>>
      </div>
    </div>
    <div id="transferItems" class="transfer-items"></div>
    <template id="transferItemTemplate">
      <div class="transfer-item-row">
        <div class="admin-field">
          <label>Product</label>
          <select data-field="vegetable_id">
            <option value="">Choose product</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= (int)$p['id'] ?>" data-unit="<?= h($p['unit']) ?>" data-mode="<?= h($p['stock_mode']) ?>" data-stock="<?= h((string)$p['stock']) ?>"><?= h($p['name']) ?><?= $p['stock_mode'] === 'shared' ? ' (Shared)' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-field">
          <label>Quantity</label>
          <input type="number" data-field="quantity" step="0.001" min="0.001" inputmode="decimal" placeholder="e.g. 5">
        </div>
        <div class="transfer-item-stock" data-field="stock-preview">Choose a product to view stock.</div>
        <button type="button" class="transfer-remove" data-action="remove" aria-label="Remove product row" title="Remove product">×</button>
        <div class="transfer-shared-confirm" data-field="shared-confirm" hidden>
          <label><input type="checkbox" data-field="confirm_stock_mode_change"><span>Change this product from Shared to Per-Store stock. Only the quantity transferred will be allocated to this store; remaining central stock stays central.</span></label>
        </div>
      </div>
    </template>
    <div id="transferBatchSummary" class="transfer-summary" aria-live="polite" tabindex="-1">Add products and quantities. The batch will run only when every row is valid.</div>
    <div class="transfer-actions">
      <button type="button" class="admin-btn" id="addTransferItem" <?= !$products ? 'disabled' : '' ?>>＋ Add another product</button>
      <span id="transferItemCount" style="color:var(--admin-muted);font-size:12px">0 products selected</span>
    </div>
    <div class="transfer-submit-row">
      <button type="submit" class="admin-btn admin-btn-primary" id="transferSubmit" disabled>🚚 Transfer today’s batch</button>
    </div>
  </form>
</section>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>Stock by product and store</h2><span style="font-size:12px;color:var(--admin-muted)">Shared products have no separate store allocation</span></div>
  <div class="transfer-table-scroll">
  <table class="admin-table">
    <thead><tr><th>Product</th><th>Stock mode</th><th>Central pool</th><?php foreach ($allStores as $store): ?><th><?= h($store['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($products as $p): ?>
        <tr>
          <td class="product-cell"><?= h($p['name']) ?></td>
          <td><?= $p['stock_mode'] === 'shared' ? 'Shared' : 'Per-Store' ?></td>
          <td><?= number_format((float)$p['stock'], 3) ?> <?= h($p['unit']) ?></td>
          <?php foreach ($allStores as $store): ?>
            <td><?= $p['stock_mode'] === 'shared' ? '—' : number_format($storeStocks[(int)$p['id']][(int)$store['id']] ?? 0, 3) . ' ' . h($p['unit']) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>Today’s transfers</h2><span style="font-size:11px;color:#68736f"><?= h(date('d M Y')) ?></span></div>
  <div class="transfer-today-stats">
    <div class="transfer-today-stat"><strong><?= $todayTransferCount ?></strong><span>Product transfer lines</span></div>
    <div class="transfer-today-stat"><strong><?= $todayProductCount ?></strong><span>Unique products</span></div>
    <div class="transfer-today-stat"><strong><?= $todayStoreCount ?></strong><span>Destination stores</span></div>
  </div>
  <div class="transfer-table-scroll">
  <table class="admin-table">
    <thead><tr><th>Time</th><th>Product</th><th>Store</th><th>Quantity</th><th>Batch note</th><th>By</th></tr></thead>
    <tbody>
      <?php foreach ($todayTransfers as $t): ?>
        <tr>
          <td><?= h(date('h:i A', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['vegetable_name']) ?></td>
          <td><?= h($t['store_name']) ?></td>
          <td><?= number_format((float)$t['quantity'], 3) ?> <?= h($t['unit']) ?></td>
          <td><?= h($t['notes'] ?? '—') ?></td>
          <td><?= h($t['username'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$todayTransfers): ?><tr><td colspan="6" class="admin-empty">No transfers recorded today.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>Recent Transfers</h2><span style="font-size:11px;color:#68736f">Latest 20</span></div>
  <div class="transfer-table-scroll">
  <table class="admin-table">
    <thead><tr><th>Date</th><th>Product</th><th>Store</th><th>Quantity</th><th>Notes</th><th>By</th></tr></thead>
    <tbody>
      <?php foreach ($recentTransfers as $t): ?>
        <tr>
          <td><?= h(date('d M Y, h:i A', strtotime($t['created_at']))) ?></td>
          <td><?= h($t['vegetable_name']) ?></td>
          <td><?= h($t['store_name']) ?></td>
          <td><?= number_format((float)$t['quantity'], 3) ?> <?= h($t['unit']) ?></td>
          <td><?= h($t['notes'] ?? '—') ?></td>
          <td><?= h($t['username'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentTransfers): ?><tr><td colspan="6" class="admin-empty">No transfers yet.</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</section>

<script>
(() => {
  const form = document.getElementById('stockTransferForm');
  if (!form) return;
  const store = document.getElementById('transferStore');
  const rows = document.getElementById('transferItems');
  const template = document.getElementById('transferItemTemplate');
  const addButton = document.getElementById('addTransferItem');
  const batchSummary = document.getElementById('transferBatchSummary');
  const itemCount = document.getElementById('transferItemCount');
  const submit = document.getElementById('transferSubmit');
  const storeNames = <?= json_encode(array_column($allStores, 'name', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const destinationStock = <?= json_encode($storeStocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const format = value => Number(value).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 3});
  let nextRowIndex = 0;

  function addRow() {
    const row = template.content.firstElementChild.cloneNode(true);
    const index = nextRowIndex++;
    row.querySelectorAll('[data-field]').forEach(field => {
      field.name = 'items[' + index + '][' + field.dataset.field + ']';
    });
    rows.appendChild(row);
    updatePreview();
    row.querySelector('select').focus();
  }

  function updatePreview() {
    const selectedIds = new Set();
    const batchItems = [];
    let valid = Boolean(store.value);
    let selectedCount = 0;
    rows.querySelectorAll('.transfer-item-row').forEach(row => {
      const product = row.querySelector('[data-field="vegetable_id"]');
      const quantity = row.querySelector('[data-field="quantity"]');
      const preview = row.querySelector('[data-field="stock-preview"]');
      const sharedConfirm = row.querySelector('[data-field="shared-confirm"]');
      const acknowledgement = row.querySelector('[data-field="confirm_stock_mode_change"]');
      const option = product.options[product.selectedIndex];
      const productId = product.value;
      const quantityText = quantity.value.trim();
      const hasInput = Boolean(productId || quantityText);
      row.classList.remove('is-invalid');

      if (!hasInput) {
        preview.textContent = 'Choose a product and enter a quantity.';
        return;
      }

      selectedCount++;
      const unit = option.dataset.unit || '';
      const mode = option.dataset.mode || '';
      const available = Number(option.dataset.stock || 0);
      const destination = productId && store.value ? Number(destinationStock[productId]?.[store.value] || 0) : 0;
      const amount = Number(quantityText);
      const validAmount = Number.isFinite(amount) && amount > 0 && amount <= available && Math.round(amount * 1000) / 1000 === amount;
      const duplicate = productId && selectedIds.has(productId);
      const isShared = mode === 'shared';
      sharedConfirm.hidden = !isShared;
      acknowledgement.required = isShared;
      quantity.max = productId ? String(available) : '';

      if (productId) selectedIds.add(productId);
      if (!productId || !quantityText) {
        preview.textContent = 'Select a product and enter its transfer quantity.';
        valid = false;
        row.classList.add('is-invalid');
      } else if (duplicate) {
        preview.textContent = 'This product is already in the batch. Use one row per product.';
        valid = false;
        row.classList.add('is-invalid');
      } else if (!validAmount) {
        preview.textContent = available <= 0
          ? 'No central stock available.'
          : amount > available
            ? 'Maximum central stock: ' + format(available) + ' ' + unit + '.'
            : 'Quantity must be above zero with up to 3 decimal places.';
        valid = false;
        row.classList.add('is-invalid');
      } else if (isShared && !acknowledgement.checked) {
        preview.textContent = 'Confirm Shared → Per-Store conversion below.';
        valid = false;
        row.classList.add('is-invalid');
      } else {
        preview.textContent = 'Central now: ' + format(available) + ' ' + unit +
          ' · after: ' + format(available - amount) + ' ' + unit +
          ' | ' + (storeNames[store.value] || 'Store') + ' now: ' + format(destination) +
          ' · after: ' + format(destination + amount) + ' ' + unit;
        batchItems.push(option.text.replace(' (Shared)', '') + ': ' + format(amount) + ' ' + unit);
      }
    });

    itemCount.textContent = selectedCount + (selectedCount === 1 ? ' product selected' : ' products selected');
    submit.disabled = !valid || selectedCount === 0;
    if (!store.value) {
      batchSummary.textContent = 'Choose the destination store, add products and quantities, then transfer the entire batch together.';
    } else if (!selectedCount) {
      batchSummary.textContent = 'Add at least one product to today’s batch for ' + storeNames[store.value] + '.';
    } else if (!valid) {
      batchSummary.textContent = 'Review the highlighted rows. The batch cannot be transferred until every selected product is valid.';
    } else {
      batchSummary.textContent = 'Ready to transfer ' + selectedCount + ' product(s) to ' + storeNames[store.value] + ': ' + batchItems.join('; ') + '.';
    }
  }

  addButton.addEventListener('click', addRow);
  store.addEventListener('change', updatePreview);
  rows.addEventListener('input', updatePreview);
  rows.addEventListener('change', event => {
    if (event.target.matches('[data-field="vegetable_id"]')) {
      const row = event.target.closest('.transfer-item-row');
      row.querySelector('[data-field="confirm_stock_mode_change"]').checked = false;
    }
    updatePreview();
  });
  rows.addEventListener('click', event => {
    const removeButton = event.target.closest('[data-action="remove"]');
    if (removeButton) {
      removeButton.closest('.transfer-item-row').remove();
      updatePreview();
    }
  });
  form.addEventListener('submit', event => {
    updatePreview();
    if (submit.disabled) {
      event.preventDefault();
      batchSummary.focus();
    } else if (!window.confirm(batchSummary.textContent)) {
      event.preventDefault();
    } else {
      submit.disabled = true;
      submit.textContent = 'Transferring batch…';
    }
  });
  addRow();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
