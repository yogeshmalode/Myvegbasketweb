<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../lib/team_portal_access.php';
ensure_team_portal_access_schema($pdo);

$page_title = 'Team Portal PIN';
$error = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pin = (string)($_POST['new_pin'] ?? '');
    $confirmPin = (string)($_POST['confirm_pin'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');

    $adminStmt = $pdo->prepare('SELECT password FROM admins WHERE id = ?');
    $adminStmt->execute([(int)$_SESSION['admin_id']]);
    $adminPasswordHash = $adminStmt->fetchColumn();

    if (!is_string($adminPasswordHash) || !password_verify($adminPassword, $adminPasswordHash)) {
        $error = 'Enter your current admin password to change the Team Portal PIN.';
    } elseif (!preg_match('/^[0-9]{8,12}$/', $pin)) {
        $error = 'The Team Portal PIN must contain 8 to 12 digits.';
    } elseif (!hash_equals($pin, $confirmPin)) {
        $error = 'The PIN entries do not match.';
    } else {
        $stmt = $pdo->prepare('UPDATE team_portal_access SET pin_hash = ?, pin_version = pin_version + 1 WHERE id = 1');
        $stmt->execute([password_hash($pin, PASSWORD_DEFAULT)]);
        $pdo->exec('DELETE FROM team_portal_pin_attempts');
        $pdo->exec('DELETE FROM team_portal_recovery_tokens');
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Team Portal PIN changed. Share the new PIN securely with your team.'];
        redirect('team_portal_access.php');
    }
}

$settings = team_portal_access_settings($pdo);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Team Portal PIN</h2><p>Set or change the shared PIN employees enter before they can see the team sign-in options. The PIN is stored as a one-way hash and cannot be retrieved from the database.</p></div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
<div class="form-card" style="max-width:560px">
  <p><strong>Status:</strong> <?= empty($settings['pin_hash']) ? 'PIN not configured' : 'PIN configured' ?></p>
  <p>Choose an 8-12 digit PIN. Do not reuse an employee's rider PIN or another account password. Changing this PIN signs out existing Team Portal sessions.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <div class="form-group"><label for="new_pin">New Team Portal PIN</label><input id="new_pin" name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{8,12}" minlength="8" maxlength="12" autocomplete="new-password" required></div>
    <div class="form-group"><label for="confirm_pin">Confirm new PIN</label><input id="confirm_pin" name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{8,12}" minlength="8" maxlength="12" autocomplete="new-password" required></div>
    <div class="form-group"><label for="admin_password">Your current admin password</label><input id="admin_password" name="admin_password" type="password" autocomplete="current-password" required></div>
    <button class="btn btn-primary" type="submit">Save Team Portal PIN</button>
  </form>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
