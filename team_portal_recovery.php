<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/team_portal_access.php';
ensure_team_portal_access_schema($pdo);

$page_title = 'Team Portal PIN Recovery';
$error = '';
$message = '';
$resetAttempt = isset($_GET['token']) || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset');
$token = is_string($_GET['token'] ?? null) ? trim($_GET['token']) : '';
$tokenHash = preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : '';
$validToken = false;

if ($tokenHash !== '') {
    $stmt = $pdo->prepare('SELECT id FROM team_portal_recovery_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([$tokenHash]);
    $validToken = (bool)$stmt->fetchColumn();
    if (!$validToken) $error = 'This recovery link is invalid or has expired. Request a new link.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'request') {
        if (!defined('ADMIN_ALERT_EMAIL') || !filter_var(ADMIN_ALERT_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $error = 'The configured admin recovery email is unavailable. Please ask your hosting administrator to check the email settings.';
        } elseif (!reserve_team_portal_recovery_request($pdo, team_portal_ip_hash())) {
            $message = 'Recovery requests are temporarily limited. Check the admin email for a recent reset link, or wait before trying again.';
        } else {
            $pdo->exec('DELETE FROM team_portal_recovery_tokens WHERE expires_at <= UTC_TIMESTAMP() OR used_at IS NOT NULL');
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $stmt = $pdo->prepare('INSERT INTO team_portal_recovery_tokens (token_hash, expires_at) VALUES (?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))');
            $stmt->execute([$tokenHash]);
            $resetUrl = rtrim(SITE_URL, '/') . '/team_portal_recovery.php?token=' . urlencode($token);
            $body = "A Team Portal PIN reset was requested for " . SITE_NAME . ".\n\nOpen this one-time link to set a new PIN (valid for 30 minutes):\n" . $resetUrl . "\n\nIf you did not request this, ignore this email. The current PIN has not changed.";
            if (send_transactional_email(ADMIN_ALERT_EMAIL, SITE_NAME . ' Team Portal PIN reset', $body)) {
                $message = 'A secure reset link has been sent to the configured admin email. Check the inbox and use the link within 30 minutes.';
            } else {
                $delete = $pdo->prepare('DELETE FROM team_portal_recovery_tokens WHERE token_hash = ?');
                $delete->execute([$tokenHash]);
                error_log('Team Portal PIN recovery email could not be sent.');
                $error = 'The recovery email could not be sent. Please ask the hosting administrator to check the email settings.';
            }
            $token = '';
            $tokenHash = '';
        }
    } elseif (($_POST['action'] ?? '') === 'reset') {
        $token = is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
        $tokenHash = preg_match('/^[a-f0-9]{64}$/', $token) ? hash('sha256', $token) : '';
        $pin = (string)($_POST['new_pin'] ?? '');
        $confirmPin = (string)($_POST['confirm_pin'] ?? '');

        if (!preg_match('/^[0-9]{8,12}$/', $pin)) {
            $error = 'The Team Portal PIN must contain 8 to 12 digits.';
        } elseif (!hash_equals($pin, $confirmPin)) {
            $error = 'The PIN entries do not match.';
        } elseif ($tokenHash === '') {
            $error = 'This recovery link is invalid or has expired. Request a new link.';
        } else {
            $pdo->beginTransaction();
            $lockToken = $pdo->prepare('SELECT id FROM team_portal_recovery_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
            $lockToken->execute([$tokenHash]);
            $recoveryId = $lockToken->fetchColumn();
            if (!$recoveryId) {
                $pdo->rollBack();
                $error = 'This recovery link is invalid or has expired. Request a new link.';
            } else {
                $update = $pdo->prepare('UPDATE team_portal_access SET pin_hash = ?, pin_version = pin_version + 1 WHERE id = 1');
                $update->execute([password_hash($pin, PASSWORD_DEFAULT)]);
                $markUsed = $pdo->prepare('UPDATE team_portal_recovery_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ?');
                $markUsed->execute([(int)$recoveryId]);
                $deleteOtherTokens = $pdo->prepare('DELETE FROM team_portal_recovery_tokens WHERE id <> ?');
                $deleteOtherTokens->execute([(int)$recoveryId]);
                $pdo->exec('DELETE FROM team_portal_pin_attempts');
                $pdo->commit();
                $_SESSION['team_portal_message'] = 'The Team Portal PIN was reset. Ask the administrator for the new PIN.';
                redirect('employee_login.php');
            }
        }
    }
}

if ($resetAttempt && $tokenHash !== '') {
    $stmt = $pdo->prepare('SELECT id FROM team_portal_recovery_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > UTC_TIMESTAMP()');
    $stmt->execute([$tokenHash]);
    $validToken = (bool)$stmt->fetchColumn();
}

include __DIR__ . '/includes/header.php';
?>
<main class="container" style="max-width:520px; padding:40px 16px 56px;">
  <div class="form-card">
    <?php if ($resetAttempt && $validToken): ?>
      <h1>Set a new Team Portal PIN</h1>
      <p>The reset link is valid for 30 minutes and can only be used once.</p>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= h($token) ?>">
        <div class="form-group"><label for="new_pin">New Team Portal PIN</label><input id="new_pin" name="new_pin" type="password" inputmode="numeric" pattern="[0-9]{8,12}" minlength="8" maxlength="12" autocomplete="new-password" required></div>
        <div class="form-group"><label for="confirm_pin">Confirm new PIN</label><input id="confirm_pin" name="confirm_pin" type="password" inputmode="numeric" pattern="[0-9]{8,12}" minlength="8" maxlength="12" autocomplete="new-password" required></div>
        <button class="btn btn-primary btn-block" type="submit">Reset Team Portal PIN</button>
      </form>
    <?php else: ?>
      <h1><?= $resetAttempt ? 'Recovery link expired' : 'Forgot Team Portal PIN?' ?></h1>
      <p>A single-use reset link will be sent to the admin recovery email configured for MyVegBasket. The existing PIN will not be sent by email.</p>
      <?php if ($message): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="request">
        <button class="btn btn-primary btn-block" type="submit">Email admin a secure reset link</button>
      </form>
    <?php endif; ?>
    <p style="text-align:center; margin-top:16px;"><a href="<?= BASE_URL ?>/employee_login.php">Back to Team Portal</a></p>
  </div>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
