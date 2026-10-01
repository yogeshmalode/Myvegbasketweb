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

$q = $pdo->prepare("SELECT a.id, a.username, a.role, a.dark_store_id, a.created_at, ds.name AS store_name
    FROM admins a LEFT JOIN dark_stores ds ON ds.id = a.dark_store_id ORDER BY a.id");
$q->execute();
$users = $q->fetchAll();
$stores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Staff &amp; Admin Users</h2><p>Create separate accounts instead of sharing the admin password. Staff/Delivery accounts can be tied to one store so they only see that store's orders, billing, and inventory — Admin accounts always see every store.</p></div>
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
          <option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary">Create User</button>
  </form>
</div>
<div class="table-wrap" style="margin-top:25px">
  <table>
    <tr><th>Username</th><th>Role</th><th>Store</th><th>Created</th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= h($u['username']) ?></td>
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
                  <option value="<?= $s['id'] ?>" <?= (int)$u['dark_store_id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn" style="padding:4px 10px; font-size:0.78rem;">Save</button>
            </form>
          <?php endif; ?>
        </td>
        <td><?= h(format_ist($u['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php include __DIR__ . '/includes/admin_footer.php';