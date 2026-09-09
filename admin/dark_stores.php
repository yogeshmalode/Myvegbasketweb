<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Dark Stores';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_store'])) {
    require_csrf();
    $name = trim($_POST['name'] ?? '');
    $lat = trim($_POST['lat'] ?? '');
    $lng = trim($_POST['lng'] ?? '');
    $radius = (float)($_POST['service_radius_km'] ?? 5);
    if ($name !== '' && is_numeric($lat) && is_numeric($lng)) {
        $st = $pdo->prepare('INSERT INTO dark_stores (name, lat, lng, service_radius_km, is_active) VALUES (?, ?, ?, ?, 1)');
        $st->execute([$name, (float)$lat, (float)$lng, $radius > 0 ? $radius : 5]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'Dark store added.'];
    } else {
        $_SESSION['flash'] = ['type'=>'error','message'=>'Name, latitude and longitude are required.'];
    }
    redirect('dark_stores.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_store'])) {
    require_csrf();
    $id = (int)($_POST['store_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('UPDATE dark_stores SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
    }
    redirect('dark_stores.php');
}

$stores = $pdo->query('SELECT ds.*, (SELECT COUNT(*) FROM riders r WHERE r.dark_store_id = ds.id) AS rider_count FROM dark_stores ds ORDER BY ds.name')->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Dark Stores</h2><p>Fulfilment hubs used for nearest-store routing and rider allocation. Orders are auto-assigned to whichever active hub is geographically closest.</p></div>
<?php if(!empty($_SESSION['flash'])){ echo '<div class="alert alert-'.h($_SESSION['flash']['type']).'">'.h($_SESSION['flash']['message']).'</div>'; unset($_SESSION['flash']); } ?>
<div style="display:flex; gap:20px; align-items:flex-start;">
  <div style="flex:1;">
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Name</th><th>Lat</th><th>Lng</th><th>Service Radius (km)</th><th>Riders</th><th>Active</th><th></th></tr></thead><tbody>
      <?php foreach($stores as $s): ?>
        <tr>
          <td><?= $s['id'] ?></td>
          <td><?= h($s['name']) ?></td>
          <td><?= h($s['lat']) ?></td>
          <td><?= h($s['lng']) ?></td>
          <td><?= h($s['service_radius_km']) ?></td>
          <td><?= (int)$s['rider_count'] ?></td>
          <td><?= $s['is_active'] ? 'Yes' : 'No' ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="toggle_store" value="1">
              <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
              <button class="btn"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; if(empty($stores)) echo '<tr><td colspan="8" style="text-align:center;color:#5B6656;">No dark stores yet.</td></tr>'; ?>
    </tbody></table></div>
  </div>
  <aside style="width:340px;"><div class="form-card"><h3>Add dark store</h3>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="add_store" value="1">
      <div class="form-group"><label>Name</label><input name="name" required></div>
      <div class="form-group"><label>Latitude</label><input name="lat" type="number" step="0.0000001" required></div>
      <div class="form-group"><label>Longitude</label><input name="lng" type="number" step="0.0000001" required></div>
      <div class="form-group"><label>Service Radius (km)</label><input name="service_radius_km" type="number" step="0.1" value="5"></div>
      <div style="text-align:right;"><button class="btn btn-primary">Add store</button></div>
    </form></div></aside>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
