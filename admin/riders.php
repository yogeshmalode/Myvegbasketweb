<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Delivery Riders';

// Handle new rider POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_rider'])) {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $vehicle = trim($_POST['vehicle'] ?? '');
    $darkStoreId = (int)($_POST['dark_store_id'] ?? 0) ?: null;
    if ($name !== '') {
        $phone = preg_replace('/\D+/', '', trim((string)($phone ?? '')));
        $pin = trim((string)($_POST['pin'] ?? ''));
        if ($phone === '') {
            $_SESSION['flash'] = ['type'=>'error','message'=>'Phone is required for rider login.'];
            redirect('riders.php');
        }
        $pinHash = $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null;
        $st = $pdo->prepare('INSERT INTO riders (name, phone, vehicle, password_hash, pin_code, is_active, dark_store_id) VALUES (?, ?, ?, ?, ?, 1, ?)');
        $st->execute([$name, $phone, $vehicle ?: null, $pinHash, $pin !== '' ? $pin : null, $darkStoreId]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Rider added. Use phone + PIN to login.'];
        redirect('riders.php');
    }
}

// Handle availability/dark-store update for an existing rider
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rider'])) {
    require_csrf();
    $riderId = (int)($_POST['rider_id'] ?? 0);
    $darkStoreId = (int)($_POST['dark_store_id'] ?? 0) ?: null;
    $availability = in_array($_POST['availability_status'] ?? '', ['available','busy','offline'], true) ? $_POST['availability_status'] : 'available';
    if ($riderId > 0) {
        $st = $pdo->prepare('UPDATE riders SET dark_store_id = ?, availability_status = ? WHERE id = ?');
        $st->execute([$darkStoreId, $availability, $riderId]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Rider updated.'];
    }
    redirect('riders.php');
}

$riders = $pdo->query('SELECT r.*, ds.name AS dark_store_name FROM riders r LEFT JOIN dark_stores ds ON ds.id = r.dark_store_id ORDER BY r.name')->fetchAll();
$darkStores = $pdo->query('SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name')->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Delivery Riders</h2><p>Manage rider accounts used for delivery assignment and route planning.</p></div>
<?php if(!empty($_SESSION['flash'])){ echo '<div class="alert alert-'.h($_SESSION['flash']['type']).'">'.h($_SESSION['flash']['message']).'</div>'; unset($_SESSION['flash']); } ?>
<div style="display:flex; gap:20px; align-items:flex-start;">
  <div style="flex:1;">
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Vehicle</th><th>Login PIN</th><th>Dark Store</th><th>Availability</th><th>Active</th></tr></thead><tbody>
      <?php foreach($riders as $r): ?>
        <tr>
          <td><?= $r['id'] ?></td>
          <td><?= h($r['name']) ?></td>
          <td><?= h($r['phone']) ?></td>
          <td><?= h($r['vehicle']) ?></td>
          <td><?= $r['pin_code'] ? 'Set' : 'Not set' ?></td>
          <td>
            <form method="post" style="display:flex; gap:6px; align-items:center;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="update_rider" value="1">
              <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
              <select name="dark_store_id" onchange="this.form.submit()" style="min-height:30px;">
                <option value="">— None —</option>
                <?php foreach($darkStores as $ds): ?>
                  <option value="<?= $ds['id'] ?>" <?= (int)($r['dark_store_id'] ?? 0) === (int)$ds['id'] ? 'selected' : '' ?>><?= h($ds['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <input type="hidden" name="availability_status" value="<?= h($r['availability_status'] ?? 'available') ?>">
            </form>
          </td>
          <td>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="update_rider" value="1">
              <input type="hidden" name="rider_id" value="<?= $r['id'] ?>">
              <input type="hidden" name="dark_store_id" value="<?= (int)($r['dark_store_id'] ?? 0) ?>">
              <select name="availability_status" onchange="this.form.submit()" style="min-height:30px;">
                <?php foreach(['available'=>'Available','busy'=>'Busy','offline'=>'Offline'] as $val=>$label): ?>
                  <option value="<?= $val ?>" <?= ($r['availability_status'] ?? 'available') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
          <td><?= $r['is_active'] ? 'Yes' : 'No' ?></td>
        </tr>
      <?php endforeach; if(empty($riders)) echo '<tr><td colspan="8" style="text-align:center;color:#5B6656;">No riders yet.</td></tr>'; ?>
    </tbody></table></div>
  </div>
  <aside style="width:340px;"><div class="form-card"><h3>Add rider</h3>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="add_rider" value="1">
      <div class="form-group"><label>Name</label><input name="name" required></div>
      <div class="form-group"><label>Phone</label><input name="phone" required></div>
      <div class="form-group"><label>Vehicle</label><input name="vehicle"></div>
      <div class="form-group"><label>Dark Store</label>
        <select name="dark_store_id">
          <option value="">— None —</option>
          <?php foreach($darkStores as $ds): ?><option value="<?= $ds['id'] ?>"><?= h($ds['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Login PIN</label><input name="pin" type="password" inputmode="numeric" minlength="4" maxlength="6" placeholder="4-6 digits"></div>
      <div style="text-align:right;"><button class="btn btn-primary">Add rider</button></div>
    </form></div></aside>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>