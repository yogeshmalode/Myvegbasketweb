<?php
require_once __DIR__ . '/config.php';
if (is_customer_logged_in()) { redirect(BASE_URL . '/my_account.php'); }

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');

    $stmt = $pdo->prepare("SELECT * FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    // Always show the same generic message whether or not the account
    // exists — this avoids letting the form be used to discover which
    // emails have an account.
    $message = 'If an account exists with that email, a password reset link has been sent to it.';

    if ($customer && $customer['password']) {
        $token = create_password_reset_token($pdo, 'customer', $customer['id']);
        $resetLink = rtrim(BASE_URL, '/') . '/reset_password.php?token=' . urlencode($token);
        $body = "Hello " . $customer['name'] . ",\n\nA password reset was requested for your " . SITE_NAME . " account.\n\nClick the link below to set a new password (valid for 1 hour):\n$resetLink\n\nIf you didn't request this, you can safely ignore this email.\n\n— " . SITE_NAME;
        send_transactional_email($customer['email'], 'Reset your ' . SITE_NAME . ' password', $body);
    }
}

$page_title = 'Forgot Password';
include __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Forgot Password</h2>
    <p style="color:#5B6656; margin-bottom:20px;">Enter your account email and we'll send you a link to reset your password.</p>

    <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

    <?php if (!$message): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autofocus>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
    </form>
    <?php endif; ?>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem;"><a href="<?= BASE_URL ?>/login.php" style="color:#3F8B52;">&larr; Back to login</a></p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
