<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title = 'B2B Billing';

// Receipt view (reuses the same print-friendly layout style as billing.php).
if (isset($_GET['receipt'])) {
    $rid = (int)$_GET['receipt'];
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND source = 'b2b'");
    $st->execute([$rid]);
    $ro = $st->fetch();
    if (!$ro) exit('Receipt not found.');
    $it = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");
    $it->execute([$rid]);
    $ri = $it->fetchAll();
    $sub = array_sum(array_column($ri, 'subtotal'));
    ?><!doctype html><html><head><meta charset="utf-8"><title>Bill #<?= $rid ?></title><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="admin.css"><style>@media print{.no-print{display:none!important}}body{padding:30px;background:#f7f9f8}.receipt{max-width:620px;margin:auto;background:#fff;padding:28px;border:1px solid #e3e9e5;border-radius:14px}.receipt table{width:100%;border-collapse:collapse}.receipt th,.receipt td{padding:10px;border-bottom:1px solid #edf0ee;text-align:left}.receipt-total{font-size:18px;color:#103328}</style></head><body><div class="receipt">
      <h2><?= h(SITE_NAME) ?> — Hotel &amp; Shop Bill</h2>
      <p><strong>Bill #<?= $rid ?></strong> · <?= h(format_ist($ro['created_at'])) ?></p>
      <p><?= h($ro['customer_name']) ?><br><?= h($ro['phone']) ?></p>
      <table><tr><th>Item</th><th>Qty</th><th>Total</th></tr><?php foreach ($ri as $r): ?><tr><td><?= h($r['name']) ?></td><td><?= $r['quantity'] ?></td><td>₹<?= number_format($r['subtotal'], 2) ?></td></tr><?php endforeach; ?></table>
      <p>Subtotal: ₹<?= number_format($sub, 2) ?></p>
      <p class="receipt-total"><strong>Total: ₹<?= number_format($ro['total_amount'], 2) ?></strong></p>
      <p>Payment: <?= h(ucfirst(str_replace('_', ' ', $ro['payment_method']))) ?> (<?= h($ro['payment_status']) ?>)</p>
      <button class="admin-btn admin-btn-primary no-print" onclick="window.print()">Print</button> <a class="admin-btn no-print" href="b2b_billing.php">New Bill</a>
    </div></body></html><?php
    exit;
}

// ---- Select which B2B customer this bill is for ----
if (isset($_GET['set_customer'])) {
    $cid = (int)$_GET['set_customer'];
    $check = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND customer_type IN ('hotel','shop')");
    $check->execute([$cid]);
    $_SESSION['b2b_customer_id'] = $check->fetch() ? $cid : null;
    unset($_SESSION['b2b_cart']);
    header('Location: b2b_billing.php');
    exit;
}
$customerId = $_SESSION['b2b_customer_id'] ?? null;
$customer = null;
if ($customerId) {
    $cs = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND customer_type IN ('hotel','shop')");
    $cs->execute([$customerId]);
    $customer = $cs->fetch();
    if (!$customer) { $customerId = null; $_SESSION['b2b_customer_id'] = null; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    require_csrf();
    unset($_SESSION['b2b_cart']);
    header('Location: b2b_billing.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_item'])) {
    require_csrf();
    $rk = (int)($_POST['vegetable_id'] ?? 0);
    unset($_SESSION['b2b_cart'][$rk]);
    header('Location: b2b_billing.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item']) && $customerId) {
    require_csrf();
    $vegId = (int)($_POST['vegetable_id'] ?? 0);
    $quantityInput = trim((string)($_POST['quantity'] ?? ''));
    $qty = preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $quantityInput) ? (float)$quantityInput : 0;
    $vs = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? AND is_active = 1");
    $vs->execute([$vegId]);
    $v = $vs->fetch();
    if (!$v) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Product not found or no longer available.'];
    } elseif ($qty <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter a quantity greater than zero.'];
    } else {
        $effStock = get_effective_stock($pdo, $vegId, null);
        if (!isset($_SESSION['b2b_cart'])) $_SESSION['b2b_cart'] = [];
        $existingQty = (float)($_SESSION['b2b_cart'][$vegId]['qty'] ?? 0);
        if ($effStock <= 0) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'This product is out of stock.'];
        } elseif ($existingQty + $qty > $effStock) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Only ' . number_format(max(0, $effStock - $existingQty), 2) . ' more ' . $v['unit'] . ' available.'];
        } else {
            $price = get_price_for_customer($pdo, $v, $customer['customer_type']);
            $_SESSION['b2b_cart'][$vegId] = [
                'id' => $vegId,
                'name' => $v['name'],
                'unit' => $v['unit'],
                'price' => $price,
                'qty' => $existingQty + $qty,
            ];
            $_SESSION['flash'] = ['type' => 'success', 'message' => $v['name'] . ' added to the bill.'];
        }
    }
    header('Location: b2b_billing.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item']) && $customerId) {
    require_csrf();
    $vegId = (int)($_POST['vegetable_id'] ?? 0);
    $quantityInput = trim((string)($_POST['quantity'] ?? ''));
    $qty = preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $quantityInput) ? (float)$quantityInput : 0;
    $productStmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? AND is_active = 1");
    $productStmt->execute([$vegId]);
    $product = $productStmt->fetch();
    if (!isset($_SESSION['b2b_cart'][$vegId]) || !$product) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'This bill item is no longer available.'];
    } elseif ($qty <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter a quantity greater than zero.'];
    } else {
        $stock = get_effective_stock($pdo, $vegId, null);
        if ($qty > $stock) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Only ' . number_format($stock, 2) . ' ' . $product['unit'] . ' available.'];
        } else {
            $_SESSION['b2b_cart'][$vegId]['qty'] = $qty;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Bill quantity updated.'];
        }
    }
    header('Location: b2b_billing.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout']) && $customerId) {
    require_csrf();
    $payment = in_array($_POST['payment_method'] ?? '', ['cash', 'upi_qr', 'credit'], true) ? $_POST['payment_method'] : 'cash';
    $cart = $_SESSION['b2b_cart'] ?? [];
    if (!$cart) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Add at least one item.'];
    } else {
        try {
            $pdo->beginTransaction();
            $subtotal = 0;
            $linesToSave = [];
            foreach ($cart as $entry) {
                $vegId = (int)$entry['id'];
                $qty = (float)$entry['qty'];
                $ps = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? FOR UPDATE");
                $ps->execute([$vegId]);
                $product = $ps->fetch();
                if (!$product) throw new Exception('Invalid product.');
                if (get_effective_stock($pdo, $vegId, null) < $qty) {
                    throw new Exception('Insufficient stock for ' . $entry['name'] . '.');
                }
                $unitPrice = get_price_for_customer($pdo, $product, $customer['customer_type']);
                $lineTotal = round($unitPrice * $qty, 2);
                $subtotal += $lineTotal;
                $linesToSave[] = ['veg_id' => $vegId, 'name' => $entry['name'], 'qty' => $qty, 'unit_price' => $unitPrice, 'line_total' => $lineTotal, 'cost_price' => (float)($product['cost_price'] ?? 0)];
            }
            $total = round($subtotal, 2);

            if ($payment === 'credit') {
                if ((float)$customer['credit_limit'] <= 0) throw new Exception('This account has no credit limit set. Use Cash or UPI instead.');
                $balance = get_customer_balance($pdo, $customerId);
                if ($balance + $total > (float)$customer['credit_limit']) {
                    throw new Exception('This order would exceed the customer\'s credit limit (available: ₹' . number_format(max(0, $customer['credit_limit'] - $balance), 2) . ').');
                }
            }
            $paymentStatus = $payment === 'credit' ? 'pending' : 'paid';

            $st = $pdo->prepare("INSERT INTO orders (customer_id, customer_name, email, phone, address, total_amount, payment_method, payment_status, order_status, source, customer_type) VALUES (?,?,?,?,?,?,?,?,'placed','b2b',?)");
            $st->execute([$customerId, $customer['business_name'] ?: $customer['name'], $customer['email'], $customer['phone'], $customer['address'] ?? 'B2B account', $total, $payment, $paymentStatus, $customer['customer_type']]);
            $oid = $pdo->lastInsertId();

            $it = $pdo->prepare("INSERT INTO order_items (order_id, vegetable_id, name, price, quantity, subtotal, cost_price) VALUES (?,?,?,?,?,?,?)");
            foreach ($linesToSave as $line) {
                $it->execute([$oid, $line['veg_id'], $line['name'], $line['unit_price'], $line['qty'], $line['line_total'], $line['cost_price']]);
                if (!apply_stock_delta($pdo, $line['veg_id'], -$line['qty'], null)) {
                    throw new Exception('Stock changed during checkout. Please retry.');
                }
            }

            if ($payment === 'credit') {
                record_ledger_entry($pdo, $customerId, 'order', $total, $oid, "Bill #$oid", $_SESSION['admin_id']);
            }

            $pdo->commit();
            unset($_SESSION['b2b_cart']);
            $order = $oid;
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Bill #$oid created.", 'order' => $oid];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Checkout failed: ' . $e->getMessage()];
        }
    }
    header('Location: b2b_billing.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$custStmt = $pdo->prepare("SELECT id, name, business_name, customer_type, credit_limit FROM customers WHERE customer_type IN ('hotel','shop')" . ($search !== '' ? " AND (name LIKE :q OR business_name LIKE :q)" : "") . " ORDER BY COALESCE(business_name, name) LIMIT 30");
if ($search !== '') $custStmt->execute(['q' => '%' . $search . '%']);
else $custStmt->execute();
$customerOptions = $custStmt->fetchAll();

$products = $pdo->query("SELECT id, name, unit, price, sale_price, stock FROM vegetables WHERE is_active = 1 AND stock > 0 ORDER BY name")->fetchAll();

$cart = $_SESSION['b2b_cart'] ?? [];
$cartProductStmt = $pdo->prepare("SELECT * FROM vegetables WHERE id = ? AND is_active = 1");
foreach ($cart as $cartKey => &$cartEntry) {
    $cartProductStmt->execute([(int)$cartEntry['id']]);
    $cartProduct = $cartProductStmt->fetch();
    if ($cartProduct && $customer) {
        $cartEntry['name'] = $cartProduct['name'];
        $cartEntry['unit'] = $cartProduct['unit'];
        $cartEntry['price'] = get_price_for_customer($pdo, $cartProduct, $customer['customer_type']);
    }
}
unset($cartEntry);
$_SESSION['b2b_cart'] = $cart;
$subtotal = 0;
foreach ($cart as $e) { $subtotal += round((float)$e['price'] * (float)$e['qty'], 2); }
$balance = $customerId ? get_customer_balance($pdo, $customerId) : 0;
$availableCredit = $customer ? max(0, (float)$customer['credit_limit'] - $balance) : 0;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading"><div><h1>B2B Billing</h1><p>Manual order entry for Hotel &amp; Shop accounts, using the shared wholesale price list.</p></div><?php if ($customerId && $cart): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><button class="admin-btn" name="clear_cart" value="1">Clear Bill</button></form><?php endif; ?></div>
<?php if ($flash): ?><div class="admin-alert admin-alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?> <?php if (!empty($flash['order'])): ?><a href="b2b_billing.php?receipt=<?= (int)$flash['order'] ?>">Print receipt</a><?php endif; ?></div><?php endif; ?>

<div class="admin-panel" style="margin-bottom:12px;padding:12px 14px;">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search hotel/shop by name" style="padding:6px 10px;border:1px solid #d8e1dc;border-radius:7px;min-width:220px;">
    <button class="admin-btn">Search</button>
  </form>
  <?php if (!$customerOptions): ?><p style="margin-top:8px;color:#5B6656;">No Hotel/Shop accounts found. <a href="b2b_customers.php">Create one</a>.</p><?php endif; ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
    <?php foreach ($customerOptions as $c): ?>
      <a class="admin-btn <?= (int)$c['id'] === (int)$customerId ? 'admin-btn-primary' : '' ?>" href="b2b_billing.php?set_customer=<?= $c['id'] ?>"><?= h($c['business_name'] ?: $c['name']) ?> <small>(<?= h(ucfirst($c['customer_type'])) ?>)</small></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$customerId): ?>
  <div class="admin-empty">Select a Hotel/Shop account above to start billing.</div>
<?php else: ?>
  <div class="admin-panel" style="margin-bottom:12px;padding:10px 14px;display:flex;gap:18px;flex-wrap:wrap;">
    <span><strong>Billing for:</strong> <?= h($customer['business_name'] ?: $customer['name']) ?></span>
    <span><strong>Credit limit:</strong> ₹<?= number_format($customer['credit_limit'], 2) ?></span>
    <span><strong>Current balance:</strong> <span style="color:<?= $balance > 0 ? '#df2e24' : '#1c7b3f' ?>">₹<?= number_format($balance, 2) ?></span></span>
    <span><strong>Available credit:</strong> ₹<?= number_format($availableCredit, 2) ?></span>
  </div>
  <div class="billing-grid">
    <section class="admin-panel"><div class="admin-panel-head"><h2>Select Products <small>Wholesale pricing</small></h2></div><div class="billing-search"><input id="billSearch" type="search" placeholder="Search products by name..." aria-label="Search products"><span id="billSearchCount"><?= count($products) ?> products</span></div><div class="billing-product-list">
      <?php foreach ($products as $v): $price = get_price_for_customer($pdo, $v, $customer['customer_type']); ?>
        <div class="billing-row" data-name="<?= h(strtolower($v['name'])) ?>">
          <div><div class="billing-name"><?= h($v['name']) ?></div><div class="billing-meta">₹<?= number_format($price, 2) ?> / <?= h($v['unit']) ?> · <?= $v['stock'] ?> available</div></div>
          <form method="post" style="display:flex;gap:5px;align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="vegetable_id" value="<?= $v['id'] ?>">
            <input type="number" name="quantity" min="0.01" max="<?= h($v['stock']) ?>" step="0.01" value="<?= h(min(1, (float)$v['stock'])) ?>" aria-label="Quantity for <?= h($v['name']) ?>" style="width:82px;height:36px;border:1px solid #d8e1dc;border-radius:7px;padding:0 7px;">
            <button class="billing-add" name="add_item" value="1" aria-label="Add <?= h($v['name']) ?>">＋ Add</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div></section>
    <section class="admin-panel"><div class="admin-panel-head"><h2>Current Bill</h2><span style="font-size:11px;color:#df2e24;font-weight:700;"><?= count($cart) ?> item(s)</span></div><div class="billing-cart">
      <?php if ($cart): ?>
        <div class="billing-cart-items"><table class="billing-cart-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead><tbody>
        <?php foreach ($cart as $x): ?><tr>
          <td><?= h($x['name']) ?></td>
          <td><form method="post" class="b2b-quantity-form"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="vegetable_id" value="<?= (int)$x['id'] ?>"><input class="b2b-cart-quantity" type="number" name="quantity" min="0.01" step="0.01" value="<?= h($x['qty']) ?>" data-price="<?= h($x['price']) ?>" aria-label="Quantity for <?= h($x['name']) ?>"><button name="update_item" value="1">Update</button><span><?= h($x['unit']) ?></span></form></td>
          <td>₹<?= number_format($x['price'], 2) ?></td><td class="b2b-line-total" data-price="<?= h($x['price']) ?>">₹<?= number_format($x['price'] * $x['qty'], 2) ?></td>
          <td><form method="post" onsubmit="return confirm('Remove this item?')"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="vegetable_id" value="<?= (int)$x['id'] ?>"><button class="billing-remove" name="remove_item" value="1" aria-label="Remove <?= h($x['name']) ?>">✕</button></form></td>
        </tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="billing-summary"><div class="billing-summary-line"><span>Subtotal</span><strong id="b2bSubtotal" data-value="<?= h(number_format($subtotal, 2, '.', '')) ?>">₹<?= number_format($subtotal, 2) ?></strong></div></div>
        <form method="post">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="checkout" value="1">
          <div class="admin-field"><label>Payment</label>
            <select name="payment_method">
              <option value="cash">Cash Payment</option>
              <option value="upi_qr">UPI Payment</option>
              <?php if ($availableCredit > 0): ?><option value="credit">Bill Me Later · ₹<?= number_format($availableCredit, 2) ?> available</option><?php endif; ?>
            </select>
          </div>
          <div class="billing-total" style="margin-top:10px;"><span>Total Payable</span><strong id="b2bTotal">₹<?= number_format($subtotal, 2) ?></strong></div>
          <div class="payment-grid"><button type="submit" class="active">Checkout &amp; Create Bill</button></div>
        </form>
      <?php else: ?>
        <div class="admin-empty">Your bill is empty.<br>Add products from the left.</div>
      <?php endif; ?>
    </div></section>
  </div>
  <script>
  const billSearch=document.getElementById('billSearch');
  billSearch?.addEventListener('input',()=>{
    const query=billSearch.value.trim().toLowerCase();
    let visible=0;
    document.querySelectorAll('.billing-row').forEach(row=>{
      const matches=row.dataset.name.includes(query);
      row.hidden=!matches;
      if(matches) visible++;
    });
    document.getElementById('billSearchCount').textContent=visible+' product'+(visible===1?'':'s');
  });
  const formatB2bMoney=value=>new Intl.NumberFormat('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}).format(value);
  const updateBillPreview=()=>{
    let subtotal=0;
    document.querySelectorAll('.b2b-cart-quantity').forEach(input=>{
      const lineTotal=Math.round(Number(input.value||0)*Number(input.dataset.price||0)*100)/100;
      subtotal+=lineTotal;
      input.closest('tr').querySelector('.b2b-line-total').textContent='₹'+formatB2bMoney(lineTotal);
    });
    document.getElementById('b2bSubtotal').textContent='₹'+formatB2bMoney(subtotal);
    document.getElementById('b2bTotal').textContent='₹'+formatB2bMoney(subtotal);
  };
  document.querySelectorAll('.b2b-cart-quantity').forEach(input=>{
    input.addEventListener('input',updateBillPreview);
    input.addEventListener('change',()=>input.form.requestSubmit());
  });
  </script>
<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
