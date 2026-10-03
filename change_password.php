<?php
require_once __DIR__ . '/config.php';
if (!is_customer_logged_in()) { redirect(BASE_URL . '/login.php'); }

$customer = current_customer();
$forced = !empty($customer['must_change_password']);
$error = '';

$stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->execute([$_SESSION['customer_id']]);
$row = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!$row || !$row['password'] || !password_verify($current, $row['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $pdo->prepare("UPDATE customers SET password = ?, must_change_password = 0 WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $row['id']]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password updated successfully.'];
        redirect(BASE_URL . '/my_account.php');
    }
}

$page_title = 'Change Password';
include __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Change Password</h2>
    <?php if ($forced): ?><p style="color:#5B6656;">For security, you must set a new password before continuing.</p><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="form-group"><label>Current Password</label><input type="password" name="current_password" required autofocus></div>
      <div class="form-group"><label>New Password</label><input type="password" name="new_password" required minlength="6"></div>
      <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" required minlength="6"></div>
      <button type="submit" class="btn btn-primary btn-block">Update Password</button>
    </form>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
