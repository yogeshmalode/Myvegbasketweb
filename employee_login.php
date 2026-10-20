<?php
require_once __DIR__ . '/config.php';
$page_title = 'Employee Login';
$meta_description = 'Choose the correct MyVegBasket login for staff, delivery team, riders, and gig riders.';
include __DIR__ . '/includes/header.php';
?>
<style>
  .employee-login-shell { max-width:900px; margin:0 auto; padding:38px 16px 56px; }
  .employee-login-heading { text-align:center; margin-bottom:24px; }
  .employee-login-heading h1 { margin:0 0 8px; color:#173b2e; }
  .employee-login-heading p { color:#5b6656; margin:0; }
  .employee-login-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(235px,1fr)); gap:16px; }
  .employee-login-card { display:flex; flex-direction:column; gap:10px; padding:22px; background:#fff; border:1px solid #dfe8df; border-radius:16px; box-shadow:0 8px 22px rgba(18,52,40,.05); }
  .employee-login-card .icon { font-size:2rem; }
  .employee-login-card h2 { margin:0; color:#173b2e; font-size:1.2rem; }
  .employee-login-card p { flex:1; margin:0 0 8px; color:#5b6656; line-height:1.5; }
</style>

<main class="employee-login-shell">
  <div class="employee-login-heading">
    <h1>Employee Login</h1>
    <p>Select your role to open the correct sign-in page.</p>
  </div>
  <div class="employee-login-grid">
    <section class="employee-login-card">
      <div class="icon" aria-hidden="true">🧑‍💼</div>
      <h2>Staff / Picker</h2>
      <p>For permanent and part-time store staff. Sign in with the username and password provided by your admin.</p>
      <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/admin/login.php?employee=staff">Staff Login</a>
    </section>
    <section class="employee-login-card">
      <div class="icon" aria-hidden="true">🚚</div>
      <h2>Delivery Team</h2>
      <p>For delivery-role staff using a username and password. This is separate from rider phone-and-PIN access.</p>
      <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/admin/login.php?employee=delivery">Delivery Staff Login</a>
    </section>
    <section class="employee-login-card">
      <div class="icon" aria-hidden="true">🛵</div>
      <h2>Rider / Gig Rider</h2>
      <p>For permanent, part-time, and gig riders. Sign in with the phone number and PIN provided by your admin.</p>
      <a class="btn btn-primary btn-block" href="<?= BASE_URL ?>/delivery/login.php">Rider Login</a>
    </section>
    <section class="employee-login-card">
      <div class="icon" aria-hidden="true">⚙️</div>
      <h2>Admin</h2>
      <p>For administrators managing staff, riders, orders, and reports. Sign in with your admin username and password.</p>
      <a class="btn btn-block" href="<?= BASE_URL ?>/admin/login.php?employee=admin">Admin Login</a>
    </section>
  </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
