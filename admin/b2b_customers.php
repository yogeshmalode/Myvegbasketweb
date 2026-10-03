<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title = 'Hotel & Shop Customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_customer'])) {
    require_csrf();
    $businessName = trim($_POST['business_name'] ?? '');
    $contactName  = trim($_POST['name'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone        = trim($_POST['phone'] ?? '');
    $type         = in_array($_POST['customer_type'] ?? '', ['hotel','shop'], true) ? $_POST['customer_type'] : 'hotel';
    $creditLimit  = max(0, (float)($_POST['credit_limit'] ?? 0));
    $terms        = max(0, (int)($_POST['payment_terms_days'] ?? 0));
    $gst          = trim($_POST['gst_number'] ?? '');

    if ($businessName === '' || $contactName === '' || $email === '' || $phone === '') {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Business name, contact name, email, and phone are required.'];
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please enter a valid email address.'];
    } else {
        $dupe = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $dupe->execute([$email]);
        if ($dupe->fetch()) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'An account with this email already exists.'];
        } else {
            // Admin sets up the account with a one-time temporary password —
            // same pattern as the staff/customer "Reset Password" action —
            // shown once here, then the account must change it at first login.
            $tempPassword = generate_temp_password();
            $pdo->prepare("INSERT INTO customers (name, email, phone, password, customer_type, business_name, gst_number, credit_limit, payment_terms_days, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)")
                ->execute([$contactName, $email, $phone, password_hash($tempPassword, PASSWORD_DEFAULT), $type, $businessName, $gst !== '' ? $gst : null, $creditLimit, $terms]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "Account created for $businessName. Temporary login password: $tempPassword — share it securely. They must change it at first login."];
        }
    }
    header('Location: b2b_customers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_terms'])) {
    require_csrf();
    $customerId  = (int)($_POST['customer_id'] ?? 0);
    $creditLimit = max(0, (float)($_POST['credit_limit'] ?? 0));
    $terms       = max(0, (int)($_POST['payment_terms_days'] ?? 0));
    if ($customerId > 0) {
        $pdo->prepare("UPDATE customers SET credit_limit = ?, payment_terms_days = ? WHERE id = ?")
            ->execute([$creditLimit, $terms, $customerId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Credit terms updated.'];
    }
    header('Location: b2b_customers.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    require_csrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    if ($customerId > 0) {
        $tempPassword = generate_temp_password();
        $pdo->prepare("UPDATE customers SET password = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $customerId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Temporary password generated: ' . $tempPassword . ' — share it securely. They must change it at next login.'];
    }
    header('Location: b2b_customers.php');
    exit;
}

$customers = $pdo->query("SELECT c.*, COALESCE((SELECT SUM(amount) FROM customer_ledger cl WHERE cl.customer_id = c.id), 0) AS balance
    FROM customers c WHERE c.customer_type IN ('hotel','shop') ORDER BY c.business_name, c.id")->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Hotel &amp; Shop Customers</h2>
  <p>Manage wholesale (B2B) accounts that order in bulk — either on the storefront themselves (at wholesale prices) or via <a href="b2b_billing.php">B2B Billing</a> entered manually by staff. Set a <strong>Credit Limit</strong> above 0 to allow "Bill Me Later" orders for that account; outstanding balances are tracked on the <a href="b2b_ledger.php">Ledger &amp; Payments</a> page.</p>
</div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>

<div class="form-card" style="max-width:640px">
  <h3 style="margin-top:0;">Add Hotel/Shop Account</h3>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="create_customer" value="1">
    <div class="form-group"><label>Business Name</label><input name="business_name" required maxlength="150" placeholder="e.g. Green Leaf Hotel"></div>
    <div class="form-group"><label>Contact Person</label><input name="name" required maxlength="100"></div>
    <div class="form-group"><label>Email (login)</label><input type="email" name="email" required></div>
    <div class="form-group"><label>Phone</label><input name="phone" required></div>
    <div class="form-group">
      <label>Type</label>
      <select name="customer_type">
        <option value="hotel">Hotel / Restaurant</option>
        <option value="shop">Vegetable Shop</option>
      </select>
    </div>
    <div class="form-group"><label>GST Number (optional)</label><input name="gst_number" maxlength="20"></div>
    <div class="form-group"><label>Credit Limit (₹, 0 = cash/UPI only)</label><input type="number" name="credit_limit" min="0" step="0.01" value="0"></div>
    <div class="form-group"><label>Payment Terms (days)</label><input type="number" name="payment_terms_days" min="0" value="7"></div>
    <button class="btn btn-primary">Create Account</button>
  </form>
</div>

<div class="table-wrap" style="margin-top:25px">
  <table>
    <tr><th>Business</th><th>Contact</th><th>Type</th><th>Email</th><th colspan="2">Credit Limit / Terms (days)</th><th>Balance Due</th><th>Actions</th></tr>
    <?php foreach ($customers as $c): ?>
      <tr>
        <td><?= h($c['business_name'] ?: '—') ?></td>
        <td><?= h($c['name']) ?><br><small><?= h($c['phone']) ?></small></td>
        <td><?= h(ucfirst($c['customer_type'])) ?></td>
        <td><?= h($c['email']) ?></td>
        <td colspan="2">
          <form method="post" style="display:flex; gap:4px; align-items:center;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="update_terms" value="1">
            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
            <input type="number" name="credit_limit" min="0" step="0.01" value="<?= h($c['credit_limit']) ?>" style="width:90px; padding:4px;" title="Credit limit">
            <input type="number" name="payment_terms_days" min="0" value="<?= h($c['payment_terms_days']) ?>" style="width:60px; padding:4px;" title="Payment terms (days)">
            <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Save</button>
          </form>
        </td>
        <td style="<?= (float)$c['balance'] > (float)$c['credit_limit'] ? 'color:#D64545;font-weight:700;' : '' ?>">₹<?= number_format((float)$c['balance'], 2) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Generate a new temporary password for <?= h($c['email']) ?>?');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
            <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Reset Password</button>
          </form>
          <a class="btn" style="padding:4px 10px; font-size:0.78rem;" href="b2b_ledger.php?customer_id=<?= $c['id'] ?>">Ledger</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="8" style="text-align:center; color:#5B6656;">No Hotel/Shop accounts yet — add one above.</td></tr><?php endif; ?>
  </table>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
