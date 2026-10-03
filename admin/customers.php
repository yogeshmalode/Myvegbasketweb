<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Customers';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    require_csrf();
    $customerId = (int)($_POST['customer_id'] ?? 0);
    if ($customerId > 0) {
        $tempPassword = generate_temp_password();
        $pdo->prepare("UPDATE customers SET password = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $customerId]);
        // Shown exactly once, right here — never stored or retrievable again
        // afterward. The customer is forced to set their own password at
        // next login (must_change_password = 1).
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Temporary password generated: ' . $tempPassword . ' — share it securely with the customer. They must change it at next login.'];
    }
    header('Location: customers.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare("SELECT id, name, email, phone, password, created_at FROM customers
        WHERE name LIKE ? OR email LIKE ? OR phone LIKE ? ORDER BY id DESC LIMIT 200");
    $like = '%' . $search . '%';
    $stmt->execute([$like, $like, $like]);
} else {
    $stmt = $pdo->query("SELECT id, name, email, phone, password, created_at FROM customers ORDER BY id DESC LIMIT 200");
}
$customers = $stmt->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Customers</h2>
  <p>View every customer's name, email, and phone (their login username). Passwords are securely hashed and can never be viewed — use <strong>Reset Password</strong> to generate a one-time temporary password if a customer is locked out.</p>
</div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<form method="get" style="margin-bottom:16px; max-width:360px;">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search name, email or phone" style="width:100%; padding:8px 10px; border:1px solid #E4E9DD; border-radius:8px;">
</form>
<div class="table-wrap">
  <table>
    <tr><th>Name</th><th>Email (login)</th><th>Phone</th><th>Login type</th><th>Joined</th><th>Security</th></tr>
    <?php foreach ($customers as $c): ?>
      <tr>
        <td><?= h($c['name']) ?></td>
        <td><?= h($c['email']) ?></td>
        <td><?= h($c['phone']) ?></td>
        <td><?= $c['password'] ? 'Password' : 'OTP only' ?></td>
        <td><?= h(format_ist($c['created_at'])) ?></td>
        <td>
          <?php if ($c['password']): ?>
          <form method="post" onsubmit="return confirm('Generate a new temporary password for <?= h($c['email']) ?>? Their current password will stop working immediately.');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="customer_id" value="<?= $c['id'] ?>">
            <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Reset Password</button>
          </form>
          <?php else: ?>
            <span style="color:#5B6656;">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$customers): ?><tr><td colspan="6" style="text-align:center; color:#5B6656;">No customers found.</td></tr><?php endif; ?>
  </table>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
