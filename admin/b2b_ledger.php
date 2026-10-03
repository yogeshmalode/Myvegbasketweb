<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Credit Ledger';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    require_csrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $cs = $pdo->prepare("SELECT id FROM customers WHERE id = ? AND customer_type IN ('hotel','shop')");
    $cs->execute([$customerId]);
    if (!$cs->fetch()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid customer.'];
    } elseif ($amount <= 0) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Enter a payment amount greater than zero.'];
    } else {
        // A payment received reduces what the customer owes, so it is
        // posted as a negative amount into the signed ledger.
        record_ledger_entry($pdo, $customerId, 'payment', -$amount, null, $notes ?: 'Payment received', $_SESSION['admin_id']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Payment of ₹' . number_format($amount, 2) . ' recorded.'];
    }
    header('Location: b2b_ledger.php?customer_id=' . $customerId);
    exit;
}

$customerId = (int)($_GET['customer_id'] ?? 0);
$customers = $pdo->query("SELECT id, name, business_name, customer_type, credit_limit FROM customers WHERE customer_type IN ('hotel','shop') ORDER BY COALESCE(business_name, name)")->fetchAll();

$customer = null;
$entries = [];
$balance = 0;
if ($customerId) {
    $cs = $pdo->prepare("SELECT * FROM customers WHERE id = ? AND customer_type IN ('hotel','shop')");
    $cs->execute([$customerId]);
    $customer = $cs->fetch();
    if ($customer) {
        $es = $pdo->prepare("SELECT * FROM customer_ledger WHERE customer_id = ? ORDER BY created_at ASC, id ASC");
        $es->execute([$customerId]);
        $entries = $es->fetchAll();
        $running = 0;
        foreach ($entries as &$en) {
            $running += (float)$en['amount'];
            $en['running_balance'] = $running;
        }
        unset($en);
        $entries = array_reverse($entries);
        $balance = $running;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading"><div><h1>Credit Ledger</h1><p>Per-customer statement of orders placed on credit and payments received.</p></div></div>
<?php if ($flash): ?><div class="admin-alert admin-alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="admin-panel" style="margin-bottom:14px;padding:12px 14px;">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <label style="font-weight:700;font-size:13px;">Customer:</label>
    <select name="customer_id" onchange="this.form.submit()" style="height:36px;border:1px solid #d8e1dc;border-radius:7px;padding:0 8px;min-width:240px;">
      <option value="">— Select a Hotel/Shop account —</option>
      <?php foreach ($customers as $c): ?>
        <option value="<?= $c['id'] ?>" <?= (int)$c['id'] === $customerId ? 'selected' : '' ?>><?= h($c['business_name'] ?: $c['name']) ?> (<?= h(ucfirst($c['customer_type'])) ?>)</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (!$customer): ?>
  <div class="admin-empty">Select a Hotel/Shop account above to view their credit ledger.</div>
<?php else: ?>
  <div class="admin-panel" style="margin-bottom:14px;padding:12px 14px;display:flex;gap:20px;flex-wrap:wrap;">
    <span><strong>Account:</strong> <?= h($customer['business_name'] ?: $customer['name']) ?></span>
    <span><strong>Credit limit:</strong> ₹<?= number_format($customer['credit_limit'], 2) ?></span>
    <span><strong>Outstanding balance:</strong> <span style="color:<?= $balance > 0 ? '#df2e24' : '#1c7b3f' ?>; font-weight:800;">₹<?= number_format($balance, 2) ?></span></span>
  </div>

  <div class="admin-form-card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;font-size:16px;">Record a Payment</h2>
    <form method="post" class="admin-form-grid">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="record_payment" value="1">
      <input type="hidden" name="customer_id" value="<?= $customer['id'] ?>">
      <div class="admin-field"><label>Amount Received (₹)</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
      <div class="admin-field"><label>Notes (optional)</label><input type="text" name="notes" placeholder="e.g. Cash, Cheque #1234, UPI ref"></div>
      <div class="admin-form-actions"><button class="admin-btn admin-btn-primary">Record Payment</button></div>
    </form>
  </div>

  <div class="admin-table-wrap">
    <table class="admin-table">
      <tr><th>Date</th><th>Type</th><th>Notes</th><th>Amount</th><th>Running Balance</th></tr>
      <?php foreach ($entries as $en): ?>
        <tr>
          <td><?= h(format_ist($en['created_at'])) ?></td>
          <td><span class="admin-badge <?= $en['entry_type'] === 'order' ? 'badge-orange' : ($en['entry_type'] === 'payment' ? 'badge-green' : 'badge-purple') ?>"><?= h(ucfirst($en['entry_type'])) ?></span></td>
          <td><?= h($en['notes'] ?: '—') ?></td>
          <td style="color:<?= $en['amount'] > 0 ? '#df2e24' : '#1c7b3f' ?>;"><?= $en['amount'] > 0 ? '+' : '' ?>₹<?= number_format($en['amount'], 2) ?></td>
          <td>₹<?= number_format($en['running_balance'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$entries): ?><tr><td colspan="5" style="text-align:center;color:#5B6656;">No ledger entries yet.</td></tr><?php endif; ?>
    </table>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
