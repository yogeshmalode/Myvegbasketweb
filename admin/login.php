<?php
require_once __DIR__ . '/../config.php';

if (is_admin_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
        redirect('dashboard.php');
    } else {
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | MyVegBasket</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
<div class="form-card" style="max-width:380px; width:100%;">
  <h2 style="margin-bottom:6px; display:flex; align-items:center; justify-content:center; gap:8px;"><img src="<?= BASE_URL ?>/assets/images/logo-icon.png" alt="" style="height:30px; width:auto;"> MyVegBasket Admin</h2>
  <p style="color:#5B6656; margin-bottom:20px; font-size:0.9rem;">Sign in to manage the vegetable catalog.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" required autofocus>
    </div>
    <div class="form-group">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">Log in</button>
  </form>
  <p style="text-align:center; margin-top:16px;"><a href="<?= BASE_URL ?>/index.php" style="color:#3F8B52; font-size:0.9rem;">&larr; Back to store</a></p>
</div>
</body>
</html>
