<?php
require_once __DIR__ . '/../config.php';
if (!is_admin_logged_in()) { header('Location: login.php'); exit; }

$page_title = 'Change Password';
$error = '';
$success = '';
$forced = !empty($_SESSION['admin_must_change_password']);

$stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $current  = $_POST['current_password'] ?? '';
    $new      = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $email    = trim($_POST['email'] ?? '');

    if (!$admin || !password_verify($current, $admin['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $pdo->prepare("UPDATE admins SET password = ?, must_change_password = 0, email = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $email !== '' ? $email : null, $admin['id']]);
        unset($_SESSION['admin_must_change_password']);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password updated successfully.'];
        header('Location: dashboard.php');
        exit;
    }
}

include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Change Password</h2>
  <p><?= $forced ? 'For security, you must set a new password before continuing. ' : '' ?>You can also add/update your email here so a "Forgot password" link can be sent to you in future if needed.</p>
</div>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<div class="form-card" style="max-width:420px">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group"><label>Current Password</label><input type="password" name="current_password" required autofocus></div>
    <div class="form-group"><label>New Password</label><input type="password" name="new_password" required minlength="6"></div>
    <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" required minlength="6"></div>
    <div class="form-group"><label>Email (optional, for password reset)</label><input type="email" name="email" value="<?= h($admin['email'] ?? '') ?>"></div>
    <button class="btn btn-primary btn-block">Update Password</button>
  </form>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
