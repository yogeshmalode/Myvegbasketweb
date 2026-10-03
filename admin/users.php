<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Staff & Admin Users';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    require_csrf();
    $username = trim($_POST['username'] ?? '');
    $userPass = trim($_POST['password'] ?? '');
    $role = in_array($_POST['role'] ?? '', ['admin', 'staff', 'delivery'], true) ? $_POST['role'] : 'staff';
    $storeId = ($role !== 'admin' && !empty($_POST['dark_store_id'])) ? (int)$_POST['dark_store_id'] : null;
    if (strlen($username) < 3 || strlen($userPass) < 6) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Username must be 3+ characters and password 6+ characters.'];
    } else {
        try {
            $st = $pdo->prepare("INSERT INTO admins (username, password, role, dark_store_id) VALUES (?, ?, ?, ?)");
            $st->execute([$username, password_hash($userPass, PASSWORD_DEFAULT), $role, $storeId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'User created.'];
        } catch (PDOException $e) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Could not create user; username may already exist.'];
        }
    }
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_store'])) {
    require_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $storeId = $_POST['dark_store_id'] !== '' ? (int)$_POST['dark_store_id'] : null;
    if ($userId > 0) {
        $pdo->prepare("UPDATE admins SET dark_store_id = ? WHERE id = ?")->execute([$storeId, $userId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Store assignment updated.'];
    }
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_can_procure'])) {
    require_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        $pdo->prepare("UPDATE admins SET can_procure = 1 - can_procure WHERE id = ?")->execute([$userId]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Independent procurement right updated.'];
    }
    header('Location: users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    require_csrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        $tempPassword = generate_temp_password();
        $pdo->prepare("UPDATE admins SET password = ?, must_change_password = 1, failed_login_count = 0, locked_until = NULL WHERE id = ?")
            ->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $userId]);
        // Shown exactly once, right here — never stored or retrievable again
        // afterward. The user will be forced to set their own password at
        // next login (must_change_password = 1).
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Temporary password generated: ' . $tempPassword . ' — share it securely with the user. They must change it at next login.'];
    }
    header('Location: users.php');
    exit;
}

$q = $pdo->prepare("SELECT a.id, a.username, a.email, a.role, a.dark_store_id, a.can_procure, a.created_at, ds.name AS store_name, ds.procurement_mode
    FROM admins a LEFT JOIN dark_stores ds ON ds.id = a.dark_store_id ORDER BY a.id");
$q->execute();
$users = $q->fetchAll();
$stores = $pdo->query("SELECT id, name, procurement_mode FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Staff &amp; Admin Users</h2><p>Create separate accounts instead of sharing the admin password. Staff/Delivery accounts can be tied to one store so they only see that store's orders, billing, and inventory — Admin accounts always see every store. If a staff member's store is set to "Independent" (Stores page), you can additionally grant them <strong>Can Procure</strong> rights so they alone can buy stock for that store directly on the Store Procurement page.</p></div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<div class="form-card" style="max-width:560px">
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="create_user" value="1">
    <div class="form-group"><label>Username</label><input name="username" required minlength="3" maxlength="50"></div>
    <div class="form-group"><label>Password</label><input type="password" name="password" required minlength="6"></div>
    <div class="form-group">
      <label>Role</label>
      <select name="role" id="newUserRole" onchange="document.getElementById('newUserStoreWrap').style.display = this.value==='admin' ? 'none' : 'block';">
        <option value="staff">Staff</option>
        <option value="admin">Admin</option>
        <option value="delivery">Delivery</option>
      </select>
    </div>
    <div class="form-group" id="newUserStoreWrap">
      <label>Store</label>
      <select name="dark_store_id">
        <option value="">All stores (not restricted)</option>
        <?php foreach ($stores as $s): ?>
          <option value="<?= $s['id'] ?>"><?= h($s['name']) ?><?= $s['procurement_mode'] === 'independent' ? ' (Independent)' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary">Create User</button>
  </form>
</div>
<div class="table-wrap" style="margin-top:25px">
  <table>
    <tr><th>Username</th><th>Email</th><th>Role</th><th>Store</th><th>Can Procure</th><th>Created</th><th>Security</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
        <td><?= h($u['email'] ?: '—') ?></td>
        <td><?= h(ucfirst($u['role'])) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
            <span style="color:#5B6656;">All stores</span>
          <?php else: ?>
            <form method="post" style="display:flex; gap:6px; align-items:center;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="update_store" value="1">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <select name="dark_store_id" style="padding:4px 6px;">
                <option value="">All stores</option>
                <?php foreach ($stores as $s): ?>
                  <option value="<?= $s['id'] ?>" <?= (int)$u['dark_store_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?><?= $s['procurement_mode'] === 'independent' ? ' (Independent)' : '' ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Save</button>
            </form>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
            <span style="color:#5B6656;">—</span>
          <?php elseif ($u['procurement_mode'] !== 'independent'): ?>
            <span style="color:#5B6656;" title="Only meaningful when this user's store is set to Independent mode">Store not independent</span>
          <?php else: ?>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="toggle_can_procure" value="1">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button class="btn" style="padding:4px 10px; font-size:0.78rem; <?= $u['can_procure'] ? 'background:#DCEEDB;color:#1F4D36;' : '' ?>"><?= $u['can_procure'] ? '✅ Granted' : 'Grant' ?></button>
            </form>
          <?php endif; ?>
        </td>
        <td><?= h(format_ist($u['created_at'])) ?></td>
        <td>
          <form method="post" onsubmit="return confirm('Generate a new temporary password for <?= h($u['username']) ?>? Their current password will stop working immediately.');" style="display:inline;">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="reset_password" value="1">
            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
            <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Reset Password</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/includes/admin_footer.php';