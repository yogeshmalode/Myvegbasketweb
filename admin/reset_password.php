<?php
require_once __DIR__ . '/../config.php';
if (is_admin_logged_in()) { redirect('dashboard.php'); }

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$reset = find_password_reset($pdo, 'admin', $token);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!$reset) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } else {
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (strlen($new) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($new !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $pdo->prepare("UPDATE admins SET password = ?, must_change_password = 0, failed_login_count = 0, locked_until = NULL WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $reset['user_id']]);
            consume_password_reset($pdo, $reset['id']);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password reset successfully. You can now log in.'];
            redirect('login.php');
        }
    }
}

$page_title = 'Reset Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Reset Password | MyVegBasket</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
<div class="form-card" style="max-width:380px; width:100%;">
  <h2 style="margin-bottom:6px;">Reset Password</h2>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

  <?php if (!$reset): ?>
    <p style="color:#5B6656; font-size:0.9rem;">This reset link is invalid or has expired.</p>
    <p style="text-align:center; margin-top:16px;"><a href="forgot_password.php" style="color:#3F8B52; font-size:0.9rem;">Request a new link</a></p>
  <?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="token" value="<?= h($token) ?>">
    <div class="form-group"><label>New Password</label><input type="password" name="new_password" required minlength="6" autofocus></div>
    <div class="form-group"><label>Confirm New Password</label><input type="password" name="confirm_password" required minlength="6"></div>
    <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
