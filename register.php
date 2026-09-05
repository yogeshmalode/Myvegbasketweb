<?php
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? ($_POST['redirect'] ?? 'my_account.php');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $phone === '' || $password === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists. Try logging in instead.';
        } else {
            $stmt = $pdo->prepare("SELECT id, password FROM customers WHERE phone = ?");
            $stmt->execute([$phone]);
            $existingByPhone = $stmt->fetch();

            if ($existingByPhone && $existingByPhone['password']) {
                $error = 'An account with this phone number already exists. Try logging in instead.';
            } elseif ($existingByPhone && !$existingByPhone['password']) {
                $error = 'This phone number already has an OTP-only account. Use "Login with mobile OTP" instead.';
            } else {
                $insert = $pdo->prepare("INSERT INTO customers (name, email, phone, password) VALUES (?, ?, ?, ?)");
                $insert->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);

                $_SESSION['customer_id']   = $pdo->lastInsertId();
                $_SESSION['customer_name'] = $name;
                redirect(BASE_URL . '/' . ltrim($redirectTo, '/'));
            }
        }
    }
}

$page_title = 'Create account';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:460px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Create your account</h2>
    <p style="color:#5B6656; margin-bottom:20px;">Save your details and see your past orders anytime.</p>

    <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=<?= urlencode($redirectTo) ?>" class="btn" style="background:#fff; border:1px solid #E4E9DD; width:100%; box-sizing:border-box; text-align:center; margin-bottom:16px;">📧 Skip this — sign up with an email code instead</a>

    <div style="text-align:center; color:#5B6656; font-size:0.8rem; margin-bottom:16px;">— or fill in your details —</div>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
      <input type="hidden" name="redirect" value="<?= h($redirectTo) ?>">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" value="<?= h($_POST['name'] ?? '') ?>" required autofocus>
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" value="<?= h($_POST['phone'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" minlength="6" required>
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="6" required>
      </div>
      <button type="submit" class="btn btn-primary btn-block">Create account</button>
    </form>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem;">
      Already have an account?
      <a href="<?= BASE_URL ?>/login.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Log in</a>
    </p>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
