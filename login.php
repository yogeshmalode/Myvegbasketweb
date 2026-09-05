<?php
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? ($_POST['redirect'] ?? 'my_account.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    if ($customer && $customer['password'] && password_verify($password, $customer['password'])) {
        $_SESSION['customer_id']   = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        redirect(BASE_URL . '/' . ltrim($redirectTo, '/'));
    } elseif ($customer && !$customer['password']) {
        $error = 'This account uses mobile OTP login, not a password. Use "Login with OTP" below.';
    } else {
        $error = 'Incorrect email or password.';
    }
}

$page_title = 'Login';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Welcome back</h2>
    <p style="color:#5B6656; margin-bottom:20px;">Log in to see your past orders and reorder in one click.</p>

    <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=<?= urlencode($redirectTo) ?>" class="btn" style="background:#fff; border:1px solid #E4E9DD; width:100%; box-sizing:border-box; text-align:center; margin-bottom:16px;">📧 Login with an email code instead</a>

    <div style="text-align:center; color:#5B6656; font-size:0.8rem; margin-bottom:16px;">— or use your password —</div>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="redirect" value="<?= h($redirectTo) ?>">
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required autofocus>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Log in</button>
    </form>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem;">
      New here?
      <a href="<?= BASE_URL ?>/register.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Create an account</a>
    </p>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
