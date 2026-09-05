<?php
require_once __DIR__ . '/../config.php';

// Safety: if an admin already exists, don't allow creating another one here.
$existingCount = (int) $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $existingCount === 0) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
        $stmt->execute([$username, $hash]);
        $success = 'Admin account created! You can now log in.';
        $existingCount = 1;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Setup | VegBasket</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
<div class="form-card" style="max-width:400px; width:100%;">
  <h2 style="margin-bottom:6px;">Admin Setup</h2>
  <p style="color:#5B6656; margin-bottom:20px; font-size:0.9rem;">One-time step: create your admin login, then delete this file.</p>

  <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert alert-success"><?= h($success) ?> <a href="login.php">Go to login &rarr;</a></div><?php endif; ?>

  <?php if ($existingCount > 0 && !$success): ?>
    <div class="alert alert-error">An admin account already exists. <a href="login.php">Go to login</a>. Please delete setup.php for security.</div>
  <?php elseif (!$success): ?>
    <form method="post">
      <div class="form-group">
        <label for="username">Admin username</label>
        <input type="text" id="username" name="username" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="6">
      </div>
      <div class="form-group">
        <label for="confirm">Confirm password</label>
        <input type="password" id="confirm" name="confirm" required minlength="6">
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create admin account</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
