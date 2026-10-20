<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/team_portal_access.php';
ensure_team_portal_access_schema($pdo);

$settings = team_portal_access_settings($pdo);
$ipHash = team_portal_ip_hash();
$portalError = '';
$portalMessage = '';
if (!empty($_SESSION['team_portal_message'])) {
    $portalMessage = $_SESSION['team_portal_message'];
    unset($_SESSION['team_portal_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_team_portal'])) {
    require_csrf();
    $lockSeconds = team_portal_pin_lock_seconds($pdo, $ipHash);
    if ($lockSeconds > 0) {
        $portalError = 'Too many incorrect PIN attempts. Try again in ' . ceil($lockSeconds / 60) . ' minute(s).';
    } elseif (empty($settings['pin_hash'])) {
        $portalError = 'The Team Portal PIN has not been set. Please contact an administrator.';
    } else {
        $pin = (string)($_POST['access_pin'] ?? '');
        if (preg_match('/^[0-9]{8,12}$/', $pin) && password_verify($pin, $settings['pin_hash'])) {
            clear_team_portal_pin_failures($pdo, $ipHash);
            session_regenerate_id(true);
            $_SESSION['team_portal_verified_at'] = time();
            $_SESSION['team_portal_pin_version'] = (int)$settings['pin_version'];
            redirect('employee_login.php');
        }
        $isLocked = record_team_portal_pin_failure($pdo, $ipHash);
        $portalError = $isLocked
            ? 'Too many incorrect PIN attempts. Access is locked for 15 minutes.'
            : 'Incorrect Team Portal PIN.';
    }
}

$portalVerified = team_portal_has_access($pdo);
$page_title = 'Team Portal';
$meta_description = 'Secure access to MyVegBasket team sign-in options.';
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
  .team-portal-pin-card { max-width:440px; margin:0 auto; }
</style>

<main class="employee-login-shell">
  <?php if ($portalVerified): ?>
  <div class="employee-login-heading">
    <h1>Team Portal</h1>
    <p>Select your role to continue to the correct sign-in page.</p>
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
  <?php else: ?>
  <div class="employee-login-heading">
    <h1>Team Portal</h1>
    <p>Enter the access PIN provided by your administrator.</p>
  </div>
  <section class="employee-login-card team-portal-pin-card">
    <?php if ($portalMessage): ?><div class="alert alert-success"><?= h($portalMessage) ?></div><?php endif; ?>
    <?php if ($portalError): ?><div class="alert alert-error"><?= h($portalError) ?></div><?php endif; ?>
    <?php if (empty($settings['pin_hash'])): ?>
      <p>The portal access PIN has not been configured yet. Please contact an administrator.</p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="verify_team_portal" value="1">
        <div class="form-group">
          <label for="access_pin">Team Portal access PIN</label>
          <input id="access_pin" name="access_pin" type="password" inputmode="numeric" pattern="[0-9]{8,12}" minlength="8" maxlength="12" autocomplete="off" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Continue to Team Portal</button>
      </form>
    <?php endif; ?>
    <p><a href="<?= BASE_URL ?>/team_portal_recovery.php" style="color:#3F8B52;">Admin: reset a forgotten portal PIN by email</a></p>
  </section>
  <?php endif; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
