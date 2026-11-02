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
    $quantityInput = trim((string)($_POST['quantity'] ?? ''));
    $qty = is_numeric($quantityInput) ? (float)$quantityInput : 0.0;
    $notes = trim($_POST['notes'] ?? '');

    if ($vegId <= 0 || $storeId <= 0 || !is_finite($qty) || $qty <= 0 || round($qty, 3) !== $qty || strlen($notes) > 255) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Choose a product and store, enter a quantity greater than zero with up to 3 decimal places, and keep notes under 255 characters.'];
    } else {
        try {
            $pdo->beginTransaction();

            $modeSt = $pdo->prepare("SELECT stock_mode, is_active FROM vegetables WHERE id = ? FOR UPDATE");
            $modeSt->execute([$vegId]);
            $product = $modeSt->fetch();
            if (!$product || !(int)$product['is_active']) {
                throw new Exception('This product is no longer active. Refresh the page and choose another product.');
            }
            if ($product['stock_mode'] !== 'per_store') {
                throw new Exception('This product is in Shared mode — all stores already use the same stock number, no transfer needed.');
            }

            // Guard against a tampered form posting an independent store's
            // id directly — that store manages its own stock on Store
            // Procurement and should never receive a silent central push.
            $modeCheckSt = $pdo->prepare("SELECT procurement_mode FROM dark_stores WHERE id = ? AND is_active = 1 FOR UPDATE");
            $modeCheckSt->execute([$storeId]);
            $storeMode = $modeCheckSt->fetchColumn();
            if ($storeMode === false) {
                throw new Exception('That destination store is inactive or no longer available. Refresh and choose an active store.');
            }
            if ($storeMode !== 'centralized') {
                throw new Exception('That store manages its own purchasing and cannot receive a central stock transfer.');
            }

            // Pull out of the central pool first; this fails (returns false)
            // if the central pool doesn't have enough, so nothing is ever
            // double-counted or allowed to go negative.
            if (!apply_stock_delta($pdo, $vegId, -$qty, null)) {
                throw new Exception('Not enough stock in the central pool to send that much.');
            }
            if (!apply_stock_delta($pdo, $vegId, $qty, $storeId)) {
                throw new Exception('Could not add the quantity to the destination store. No stock was moved; please retry.');
            }

            $pdo->prepare("INSERT INTO stock_transfers (vegetable_id, dark_store_id, quantity, notes, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$vegId, $storeId, $qty, $notes ?: null, $_SESSION['admin_id']]);

            $pdo->commit();
            try {
                dispatch_back_in_stock_notifications($pdo, $vegId);
            } catch (Throwable $e) {
                error_log('Could not dispatch back-in-stock alerts after stock transfer: ' . $e->getMessage());
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Transfer complete. Central stock was reduced and destination stock increased.'];
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
foreach ($perStoreProducts as $p) {
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

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading">
  <div>
    <h1>Stock Transfer</h1>
    <p>Move stock from your central pool to a centralized-procurement store. Shared products need no transfer.</p>
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
  .transfer-source-summary{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:16px;padding:13px 15px;border:1px solid #dfe9e2;border-radius:10px;background:#f7fbf8}
  .transfer-stock-value{font-size:18px;font-weight:800;color:#145b3e;font-variant-numeric:tabular-nums}
  .transfer-source-summary small{display:block;margin-top:2px;color:var(--admin-muted);font-size:11px}
  .transfer-destination-summary{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;margin-top:12px;padding:14px 15px;background:#f6f8ff;border:1px solid #e2e8fa;border-radius:10px}
  .transfer-destination-summary .transfer-stock-value{color:#315d9b}
  .transfer-arrow{color:#59728e;font-size:22px;font-weight:800}
  .transfer-confirm{margin-top:14px;padding:12px 14px;border-left:3px solid #e8934a;border-radius:5px;background:#fff8ee;color:#5e4a32;font-size:13px;line-height:1.5}
  .transfer-submit-row{display:flex;justify-content:flex-end;margin-top:16px}
  .transfer-submit-row .admin-btn{min-height:44px;padding:0 20px;font-size:14px}
  .transfer-submit-row .admin-btn:disabled{opacity:.5;cursor:not-allowed}
  .transfer-empty{padding:20px;margin-bottom:18px;line-height:1.55}
  .transfer-table-scroll{overflow-x:auto}
  @media(max-width:700px){.transfer-flow{grid-template-columns:1fr}.transfer-form-grid{grid-template-columns:1fr}.transfer-form{padding:15px}.transfer-destination-summary{grid-template-columns:1fr auto 1fr;padding:12px}.transfer-submit-row .admin-btn{width:100%}}
</style>

<div class="transfer-flow" aria-label="Transfer steps">
  <div class="transfer-flow-step"><span class="transfer-flow-number">1</span><div><strong>Choose a product</strong><span>Only Per-Store products can be moved.</span></div></div>
  <div class="transfer-flow-step"><span class="transfer-flow-number">2</span><div><strong>Choose a destination</strong><span>Independent stores manage their own purchasing.</span></div></div>
  <div class="transfer-flow-step"><span class="transfer-flow-number">3</span><div><strong>Confirm the quantity</strong><span>Central stock decreases as store stock increases.</span></div></div>
</div>

<?php if (!$perStoreProducts): ?>
  <div class="admin-panel transfer-empty"><strong>No transferable products yet.</strong><br>Set a product to <em>Per-Store</em> stock mode on its Add/Edit Vegetable page. Shared products are available to all stores automatically and should not be transferred.</div>
<?php elseif (!$allStores): ?>
  <div class="admin-panel transfer-empty"><strong>No eligible destination stores.</strong><br>Add or activate a store using centralized procurement in the Stores page before transferring stock.</div>
<?php else: ?>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>New stock transfer</h2><span style="font-size:12px;color:var(--admin-muted)">Central pool → store stock</span></div>
  <form method="post" class="transfer-form" id="stockTransferForm">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="transfer-form-grid">
      <div class="admin-field">
        <label for="transferProduct">1. Product</label>
        <select id="transferProduct" name="vegetable_id" required>
          <option value="">Choose a Per-Store product</option>
          <?php foreach ($perStoreProducts as $p): ?>
            <option value="<?= (int)$p['id'] ?>" data-unit="<?= h($p['unit']) ?>" data-central="<?= h((string)$p['stock']) ?>"><?= h($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="transferStore">2. Destination store</label>
        <select id="transferStore" name="dark_store_id" required>
          <option value="">Choose a centralized store</option>
          <?php foreach ($allStores as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="admin-field">
        <label for="transferQuantity">3. Quantity to move</label>
        <input type="number" id="transferQuantity" name="quantity" step="0.001" min="0.001" inputmode="decimal" placeholder="e.g. 5" required>
      </div>
      <div class="admin-field">
        <label for="transferNotes">Transfer note <span style="font-weight:400">(optional)</span></label>
        <input type="text" id="transferNotes" name="notes" maxlength="255" placeholder="e.g. Morning delivery">
      </div>
    </div>
    <div class="transfer-source-summary">
      <div><strong>Available in central pool</strong><small id="transferProductUnit">Choose a product to view stock</small></div>
      <span class="transfer-stock-value" id="centralStockValue">—</span>
    </div>
    <div class="transfer-destination-summary">
      <div><strong>Central after transfer</strong><span class="transfer-stock-value" id="centralAfterValue">—</span></div>
      <span class="transfer-arrow" aria-hidden="true">→</span>
      <div><strong>Store after transfer</strong><span class="transfer-stock-value" id="storeAfterValue">—</span></div>
    </div>
    <div class="transfer-confirm" id="transferConfirmation" aria-live="polite">Choose a product and destination to preview how this transfer changes both stock balances.</div>
    <div class="transfer-submit-row">
      <button type="submit" class="admin-btn admin-btn-primary" id="transferSubmit" disabled>🚚 Transfer stock</button>
    </div>
  </form>
</section>

<section class="admin-panel transfer-panel">
  <div class="admin-panel-head"><h2>Stock by product and store</h2><span style="font-size:12px;color:var(--admin-muted)">Central stock is not allocated to any one store</span></div>
  <div class="transfer-table-scroll">
  <table class="admin-table">
    <thead><tr><th>Product</th><th>Central pool</th><?php foreach ($allStores as $store): ?><th><?= h($store['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($perStoreProducts as $p): ?>
        <tr>
          <td class="product-cell"><?= h($p['name']) ?></td>
          <td><?= number_format((float)$p['stock'], 3) ?> <?= h($p['unit']) ?></td>
          <?php foreach ($allStores as $store): ?>
            <td><?= number_format($storeStocks[(int)$p['id']][(int)$store['id']] ?? 0, 3) ?> <?= h($p['unit']) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
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
  const product = document.getElementById('transferProduct');
  const store = document.getElementById('transferStore');
  const quantity = document.getElementById('transferQuantity');
  const unitLabel = document.getElementById('transferProductUnit');
  const centralValue = document.getElementById('centralStockValue');
  const centralAfter = document.getElementById('centralAfterValue');
  const storeAfter = document.getElementById('storeAfterValue');
  const confirmation = document.getElementById('transferConfirmation');
  const submit = document.getElementById('transferSubmit');
  const centralStock = <?= json_encode(array_column($perStoreProducts, 'stock', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const units = <?= json_encode(array_column($perStoreProducts, 'unit', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const names = <?= json_encode(array_column($perStoreProducts, 'name', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const storeNames = <?= json_encode(array_column($allStores, 'name', 'id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const destinationStock = <?= json_encode($storeStocks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const format = value => Number(value).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 3});

  function updatePreview() {
    const productId = product.value;
    const storeId = store.value;
    const selectedProduct = product.options[product.selectedIndex];
    const available = productId ? Number(centralStock[productId] || 0) : 0;
    const destination = productId && storeId ? Number(destinationStock[productId]?.[storeId] || 0) : 0;
    const amount = Number(quantity.value);
    const validAmount = Number.isFinite(amount) && amount > 0 && amount <= available && Math.round(amount * 1000) / 1000 === amount;
    const hasSelections = Boolean(productId && storeId);

    quantity.max = productId ? String(available) : '';
    unitLabel.textContent = productId ? 'Unit: ' + (units[productId] || selectedProduct.dataset.unit || '') : 'Choose a product to view stock';
    centralValue.textContent = productId ? format(available) + ' ' + units[productId] : '—';
    centralAfter.textContent = hasSelections && validAmount ? format(available - amount) + ' ' + units[productId] : '—';
    storeAfter.textContent = hasSelections && validAmount ? format(destination + amount) + ' ' + units[productId] : hasSelections ? format(destination) + ' ' + units[productId] : '—';
    submit.disabled = !hasSelections || !validAmount;

    if (!productId || !storeId) {
      confirmation.textContent = 'Choose a product and destination to preview how this transfer changes both stock balances.';
    } else if (available <= 0) {
      confirmation.textContent = 'There is no central stock available for ' + names[productId] + '. Record a purchase first.';
    } else if (!quantity.value) {
      confirmation.textContent = 'Available to transfer: ' + format(available) + ' ' + units[productId] + '. Enter a quantity for ' + storeNames[storeId] + '.';
    } else if (!validAmount) {
      confirmation.textContent = amount > available
        ? 'Not enough central stock. Maximum available is ' + format(available) + ' ' + units[productId] + '.'
        : 'Enter a quantity greater than zero with no more than 3 decimal places.';
    } else {
      confirmation.textContent = 'Confirm: move ' + format(amount) + ' ' + units[productId] + ' of ' + names[productId] +
        ' to ' + storeNames[storeId] + '. Central stock will be ' + format(available - amount) +
        ' ' + units[productId] + '; store stock will be ' + format(destination + amount) + ' ' + units[productId] + '.';
    }
  }

  [product, store, quantity].forEach(field => field.addEventListener('input', updatePreview));
  [product, store].forEach(field => field.addEventListener('change', updatePreview));
  form.addEventListener('submit', event => {
    updatePreview();
    if (submit.disabled) {
      event.preventDefault();
      confirmation.focus();
    } else if (!window.confirm(confirmation.textContent)) {
      event.preventDefault();
    } else {
      submit.disabled = true;
      submit.textContent = 'Transferring…';
    }
  });
  updatePreview();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
