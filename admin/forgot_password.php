<?php
require_once __DIR__ . '/../config.php';
if (is_admin_logged_in()) { redirect('dashboard.php'); }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    // Always show the same generic message, whether or not the account
    // exists or has an email on file — this avoids letting an attacker
    // use this form to discover valid admin usernames.
    $message = 'If that username exists and has an email on file, a password reset link has been sent to it.';

    if ($admin && !empty($admin['email'])) {
        $token = create_password_reset_token($pdo, 'admin', $admin['id']);
        $resetLink = rtrim(BASE_URL, '/') . '/admin/reset_password.php?token=' . urlencode($token);
        $body = "Hello " . $admin['username'] . ",\n\nA password reset was requested for your " . SITE_NAME . " admin account.\n\nClick the link below to set a new password (valid for 1 hour):\n$resetLink\n\nIf you didn't request this, you can safely ignore this email.\n\n— " . SITE_NAME;
        send_transactional_email($admin['email'], 'Reset your ' . SITE_NAME . ' admin password', $body);
    }
}

$page_title = 'Forgot Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Forgot Password | MyVegBasket</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
<div class="form-card" style="max-width:380px; width:100%;">
  <h2 style="margin-bottom:6px;">Forgot Password</h2>
  <p style="color:#5B6656; margin-bottom:20px; font-size:0.9rem;">Enter your admin/staff username — if your account has an email on file, we'll send a reset link to it. If you don't have an email on file, ask another admin to reset your password for you from Staff &amp; Admin Users.</p>

  <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

  <?php if (!$message): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
  </form>
  <?php endif; ?>
  <p style="text-align:center; margin-top:16px;"><a href="login.php" style="color:#3F8B52; font-size:0.9rem;">&larr; Back to login</a></p>
</div>
</body>
</html>
