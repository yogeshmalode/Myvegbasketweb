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
  <style>
    .partner-login{max-width:980px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;overflow:hidden;border:1px solid #e4e9dd;border-radius:24px;background:#fff;box-shadow:0 20px 55px rgba(31,77,54,.12)}
    .partner-intro{padding:42px;background:linear-gradient(145deg,#104b35,#1f704b);color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:500px}
    .partner-brand{display:flex;align-items:center;gap:12px;font-size:1.05rem;font-weight:800}
    .partner-brand img{width:42px;height:42px;object-fit:contain;background:#fff;border-radius:12px;padding:4px}
    .partner-intro h1{font-size:2rem;line-height:1.15;margin:35px 0 12px;color:#fff}
    .partner-intro>div>p{color:#d6e8d9;line-height:1.65}
    .partner-benefits{display:grid;gap:12px;margin:25px 0 0;padding:0;list-style:none}
    .partner-benefits li{display:flex;gap:10px;align-items:center;color:#f3f8f2;font-size:.93rem}
    .partner-benefits span{width:27px;height:27px;display:grid;place-items:center;border-radius:50%;background:rgba(255,255,255,.14);color:#d7f0a8;font-weight:800}
    .partner-help{color:#d6e8d9;font-size:.85rem}
    .partner-help a{color:#fff;font-weight:700}
    .partner-form{padding:42px;align-self:center}
    .partner-form h2{margin:0;color:#1f4d36;font-size:1.55rem}
    .partner-form>p{margin:8px 0 24px;color:#5b6656;line-height:1.55}
    .partner-password{position:relative}
    .partner-password input{padding-right:76px}
    .partner-password button{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#246947;font-weight:700;cursor:pointer;padding:7px}
    .partner-submit{margin-top:8px;min-height:48px;font-size:1rem}
    .partner-secondary{margin-top:20px;text-align:center;font-size:.9rem;color:#5b6656}
    .partner-secondary a{color:#26784a;font-weight:700}
    @media(max-width:720px){.partner-login{grid-template-columns:1fr}.partner-intro{min-height:0;padding:28px}.partner-intro h1{font-size:1.7rem;margin:22px 0 8px}.partner-benefits{grid-template-columns:1fr 1fr;margin-top:18px}.partner-form{padding:30px 24px}}
    @media(max-width:420px){.partner-benefits{grid-template-columns:1fr}.partner-intro{padding:24px}.partner-form{padding:26px 20px}}
  </style>
  <div class="partner-login">
    <aside class="partner-intro">
      <div>
        <div class="partner-brand"><img src="<?= BASE_URL ?>/assets/images/logo-icon.png" alt=""><span><?= h(SITE_NAME) ?> Partners</span></div>
        <h1>Fresh supply.<br>Wholesale value.</h1>
        <p>A simpler way for hotels, restaurants and shops to order fresh produce for their business.</p>
        <ul class="partner-benefits">
          <li><span>✓</span> Partner wholesale prices</li>
          <li><span>✓</span> Credit billing when enabled</li>
          <li><span>✓</span> Orders and balances in one place</li>
        </ul>
      </div>
      <div class="partner-help">Need a partner account? <a href="tel:+917972381861">Call us for setup</a></div>
    </aside>
    <section class="partner-form">
      <h2>Welcome back</h2>
      <p>Sign in to shop partner prices and manage your business orders.</p>

      <?php if ($error): ?><div class="alert alert-error" role="alert"><?= $error ?></div><?php endif; ?>

      <form method="post">
        <input type="hidden" name="redirect" value="<?= h($redirectTo) ?>">
        <div class="form-group">
          <label for="email">Business account email</label>
          <input type="email" id="email" name="email" value="<?= h($_POST['email'] ?? '') ?>" autocomplete="username" required autofocus>
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <div class="partner-password">
            <input type="password" id="password" name="password" autocomplete="current-password" required>
            <button type="button" id="passwordToggle" aria-controls="password" aria-pressed="false">Show</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block partner-submit">Sign in to partner account</button>
      </form>
      <div class="partner-secondary"><a href="<?= BASE_URL ?>/forgot_password.php">Forgot password?</a></div>
      <div class="partner-secondary">Not a partner? <a href="<?= BASE_URL ?>/login.php">Use customer login</a></div>
    </section>
  </div>
</div>
<script>
document.getElementById('passwordToggle')?.addEventListener('click',event=>{
  const button=event.currentTarget;
  const password=document.getElementById('password');
  const reveal=password.type==='password';
  password.type=reveal?'text':'password';
  button.textContent=reveal?'Hide':'Show';
  button.setAttribute('aria-pressed',String(reveal));
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
