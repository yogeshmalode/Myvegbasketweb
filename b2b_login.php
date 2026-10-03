<?php
// Dedicated login page for Hotel & Shop (B2B) wholesale accounts — same
// underlying customers table/session as the regular customer login.php,
// just a separate, clearly-branded entry point so B2B partners aren't
// confused by (or mixed up with) the regular retail customer login.
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? ($_POST['redirect'] ?? 'my_account.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT *, GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS lock_remaining_seconds FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    $customer = $stmt->fetch();

    $lockSeconds = $customer ? (int)$customer['lock_remaining_seconds'] : 0;
    if ($lockSeconds > 0) {
        $error = 'Too many failed attempts. Try again in ' . ceil($lockSeconds / 60) . ' minute(s).';
    } elseif (!$customer || !in_array($customer['customer_type'] ?? 'retail', ['hotel', 'shop'], true)) {
        // Deliberately vague (don't reveal whether the email exists) and
        // points them to the right page instead of just failing silently.
        $error = 'No Hotel/Shop account found with this email. Use the regular <a href="' . h(BASE_URL) . '/login.php">customer login</a> instead, or contact us to set up a wholesale account.';
    } elseif ($customer['password'] && password_verify($password, $customer['password'])) {
        reset_failed_login($pdo, 'customers', $customer['id']);
        $_SESSION['customer_id']   = $customer['id'];
        $_SESSION['customer_name'] = $customer['name'];
        redirect(BASE_URL . '/' . ltrim($redirectTo, '/'));
    } else {
        record_failed_login($pdo, 'customers', $customer['id']);
        $error = 'Incorrect email or password.';
    }
}

$page_title = 'Hotel & Shop Partner Login';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">🏨 Hotel &amp; Shop Partner Login</h2>
    <p style="color:#5B6656; margin-bottom:20px;">Sign in to your wholesale account to order at wholesale prices and bill on credit.</p>

    <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>

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
    <p style="text-align:center; margin-top:10px; font-size:0.85rem;"><a href="<?= BASE_URL ?>/forgot_password.php" style="color:#3F8B52;">Forgot password?</a></p>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem; color:#5B6656;">
      Don't have a wholesale account yet? Contact us to get set up.
    </p>
    <p style="text-align:center; margin-top:6px; font-size:0.85rem;">
      <a href="<?= BASE_URL ?>/login.php" style="color:#5B6656;">← Regular customer login</a>
    </p>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
