<?php
require_once __DIR__ . '/../config.php';

if (is_rider_logged_in()) {
    redirect('dashboard.php');
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim((string)($_POST['phone'] ?? ''));
    $pin = trim((string)($_POST['pin'] ?? ''));
    if (rider_login($pdo, $phone, $pin)) {
        redirect('dashboard.php');
    }
    $loginError = 'Invalid rider phone or PIN.';
}

$page_title = 'Rider Login';
include __DIR__ . '/../includes/header.php';
?>
<style>
  body { background: #f6faf7; }
  .rider-login-shell {
    max-width: 480px;
    margin: 0 auto;
    padding: 36px 16px 56px;
  }
  .rider-login-box {
    background: #fff;
    border: 1px solid #e1e9e3;
    border-radius: 22px;
    box-shadow: 0 12px 30px rgba(17, 48, 37, 0.05);
    padding: 26px 22px;
  }
  .rider-login-header {
    text-align: center;
    margin-bottom: 18px;
  }
  .rider-login-header h2 {
    margin: 0 0 8px;
    font-size: clamp(1.8rem, 2.5vw, 2.4rem);
    letter-spacing: -0.05em;
    color: #11231d;
    font-weight: 900;
  }
  .rider-login-header p {
    margin: 0;
    color: #68756f;
    font-size: 0.82rem;
    line-height: 1.5;
  }
  .rider-login-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 68px;
    height: 68px;
    border-radius: 18px;
    background: linear-gradient(135deg, #eaf7f0, #dff4e8);
    font-size: 2rem;
    margin-bottom: 14px;
  }
  .form-card.rider-form-card { padding: 0; border: none; box-shadow: none; }
  .rider-login-box .form-group {
    margin-bottom: 16px;
  }
  .rider-login-box label {
    display: block;
    margin-bottom: 7px;
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 800;
    color: #55635d;
  }
  .rider-login-box input {
    width: 100%;
    min-height: 46px;
    border: 1px solid #d7e2db;
    border-radius: 12px;
    padding: 0 14px;
    background: #fafcfb;
    font-size: 0.95rem;
  }
  .rider-login-box input:focus {
    border-color: #56a684;
    box-shadow: 0 0 0 3px rgba(86, 166, 132, 0.12);
    outline: none;
  }
  .rider-login-box .btn {
    width: 100%;
    min-height: 48px;
    border-radius: 12px;
    font-size: 0.92rem;
    font-weight: 800;
    letter-spacing: 0.01em;
  }
  @media (max-width: 640px) {
    .rider-login-shell { padding-top: 20px; }
    .rider-login-box { padding: 20px 16px; }
  }
</style>

<div class="rider-login-shell">
  <div class="rider-login-box">
    <div class="rider-login-header">
      <div class="rider-login-badge">🛵</div>
      <h2>Delivery Partner Login</h2>
      <p>Access your assigned MyVegBasket deliveries.</p>
    </div>

    <div class="form-card rider-form-card">
      <?php if ($loginError): ?>
        <div class="alert alert-error"><?= h($loginError) ?></div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="form-group">
          <label for="phone">Phone number</label>
          <input id="phone" name="phone" type="tel" inputmode="numeric" required placeholder="9876543210">
        </div>
        <div class="form-group">
          <label for="pin">Login PIN</label>
          <input id="pin" name="pin" type="password" inputmode="numeric" required minlength="4" maxlength="6" placeholder="Enter PIN">
        </div>
        <button type="submit" class="btn btn-primary btn-block">Login</button>
      </form>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
