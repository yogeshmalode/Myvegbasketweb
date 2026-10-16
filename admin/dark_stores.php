<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Dark Stores';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    try {
        if (isset($_POST['add_store']) || isset($_POST['update_store'])) {
            $name = trim((string)($_POST['name'] ?? ''));
            $lat = $_POST['lat'] ?? '';
            $lng = $_POST['lng'] ?? '';
            $radius = $_POST['service_radius_km'] ?? '';

            if (
                $name === ''
                || mb_strlen($name) > 150
                || !is_numeric($lat)
                || !is_numeric($lng)
                || !is_numeric($radius)
                || !is_finite((float)$lat)
                || !is_finite((float)$lng)
                || !is_finite((float)$radius)
                || (float)$lat < -90
                || (float)$lat > 90
                || (float)$lng < -180
                || (float)$lng > 180
                || (float)$radius < 0.1
                || (float)$radius > 1000
            ) {
                throw new InvalidArgumentException('Enter a store name, valid coordinates, and a service radius between 0.1 and 1000 km.');
            }

            if (isset($_POST['update_store'])) {
                $id = (int)($_POST['store_id'] ?? 0);
                if ($id <= 0) {
                    throw new InvalidArgumentException('Choose a valid store to update.');
                }
                $st = $pdo->prepare('UPDATE dark_stores SET name = ?, lat = ?, lng = ?, service_radius_km = ? WHERE id = ?');
                $st->execute([$name, (float)$lat, (float)$lng, (float)$radius, $id]);
                if ($st->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT COUNT(*) FROM dark_stores WHERE id = ?');
                    $exists->execute([$id]);
                    if ((int)$exists->fetchColumn() === 0) {
                        throw new InvalidArgumentException('Store not found.');
                    }
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Store details updated.'];
            } else {
                $st = $pdo->prepare('INSERT INTO dark_stores (name, lat, lng, service_radius_km, is_active) VALUES (?, ?, ?, ?, 1)');
                $st->execute([$name, (float)$lat, (float)$lng, (float)$radius]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Store added.'];
            }
        } elseif (isset($_POST['toggle_store'])) {
            $id = (int)($_POST['store_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Choose a valid store.');
            }
            $pdo->prepare('UPDATE dark_stores SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Store availability updated.'];
        } elseif (isset($_POST['toggle_procurement_mode'])) {
            $id = (int)($_POST['store_id'] ?? 0);
            if ($id <= 0) {
                throw new InvalidArgumentException('Choose a valid store.');
            }
            $pdo->prepare("UPDATE dark_stores SET procurement_mode = IF(procurement_mode = 'independent', 'centralized', 'independent') WHERE id = ?")->execute([$id]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Procurement mode updated.'];
        } else {
            throw new InvalidArgumentException('Unknown store management action.');
        }
    } catch (InvalidArgumentException $exception) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $exception->getMessage()];
    } catch (PDOException $exception) {
        error_log('Store management database action failed: ' . $exception->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Store changes could not be saved. Please check the database and try again.'];
    }

    redirect('dark_stores.php');
}

$stores = $pdo->query("
    SELECT ds.*,
        (SELECT COUNT(*) FROM riders r WHERE r.dark_store_id = ds.id) AS rider_count,
        (SELECT COUNT(*) FROM orders o
         WHERE o.dark_store_id = ds.id
           AND o.created_at >= CURDATE()
           AND o.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
           AND o.order_status <> 'cancelled') AS today_order_count,
        (SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o
         WHERE o.dark_store_id = ds.id
           AND o.created_at >= CURDATE()
           AND o.created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
           AND o.order_status <> 'cancelled') AS today_sales
    FROM dark_stores ds
    ORDER BY ds.name
")->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Stores &amp; Branches</h2><p>Manage all physical branches from this central admin. Orders are assigned to the nearest active branch; each branch can have its own staff, riders, stock, and procurement flow. Use the daily figures below to compare branch activity. SaaS tenant setup remains separate from physical branch management.</p></div>
<?php if(!empty($_SESSION['flash'])){ echo '<div class="alert alert-'.h($_SESSION['flash']['type']).'">'.h($_SESSION['flash']['message']).'</div>'; unset($_SESSION['flash']); } ?>
<div style="display:flex; gap:20px; align-items:flex-start;">
  <div style="flex:1;">
    <div class="table-wrap"><table><thead><tr><th>#</th><th>Branch</th><th>Today</th><th>Riders</th><th>Service Area</th><th>Availability</th><th>Procurement</th><th>Actions</th></tr></thead><tbody>
      <?php foreach($stores as $s): ?>
        <tr>
          <td><?= $s['id'] ?></td>
          <td><strong><?= h($s['name']) ?></strong><br><small><?= h($s['lat']) ?>, <?= h($s['lng']) ?></small></td>
          <td><?= (int)$s['today_order_count'] ?> orders<br><strong>₹<?= number_format((float)$s['today_sales'], 2) ?></strong></td>
          <td><?= (int)$s['rider_count'] ?></td>
          <td><?= h($s['service_radius_km']) ?> km</td>
          <td><?= $s['is_active'] ? 'Active' : 'Inactive' ?></td>
          <td>
            <span class="badge-soft <?= $s['procurement_mode'] === 'independent' ? 'warning' : 'info' ?>" style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:0.72rem; font-weight:700; <?= $s['procurement_mode'] === 'independent' ? 'background:#fff4dc;color:#aa6b00;' : 'background:#edf3ff;color:#1457d6;' ?>">
              <?= $s['procurement_mode'] === 'independent' ? 'Independent' : 'Centralized' ?>
            </span>
          </td>
          <td>
            <details>
              <summary>Edit branch</summary>
              <form method="post" style="min-width:220px;padding:10px;">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="update_store" value="1">
                <input type="hidden" name="store_id" value="<?= (int)$s['id'] ?>">
                <div class="form-group"><label>Branch name</label><input name="name" maxlength="150" value="<?= h($s['name']) ?>" required></div>
                <div class="form-group"><label>Latitude</label><input name="lat" type="number" min="-90" max="90" step="0.0000001" value="<?= h($s['lat']) ?>" required></div>
                <div class="form-group"><label>Longitude</label><input name="lng" type="number" min="-180" max="180" step="0.0000001" value="<?= h($s['lng']) ?>" required></div>
                <div class="form-group"><label>Service radius (km)</label><input name="service_radius_km" type="number" min="0.1" max="1000" step="0.1" value="<?= h($s['service_radius_km']) ?>" required></div>
                <button class="btn btn-primary">Save branch</button>
              </form>
            </details>
            <form method="post" style="display:inline;">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="toggle_store" value="1">
              <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
              <button class="btn"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="post" style="display:inline;" onsubmit="return confirm('<?= $s['procurement_mode'] === 'independent' ? 'Switch this store back to centralized buying (its owner loses independent procurement access)?' : 'Make this store independent (its designated owner can then buy stock for it directly, outside the central pool)?' ?>');">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="toggle_procurement_mode" value="1">
              <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
              <button class="btn"><?= $s['procurement_mode'] === 'independent' ? 'Make Centralized' : 'Make Independent' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; if(empty($stores)) echo '<tr><td colspan="8" style="text-align:center;color:#5B6656;">No branches yet.</td></tr>'; ?>
    </tbody></table></div>
  </div>
  <aside style="width:340px;"><div class="form-card"><h3>Add branch</h3>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="add_store" value="1">
      <div class="form-group"><label>Branch name</label><input name="name" maxlength="150" required></div>
      <div class="form-group"><label>Latitude</label><input name="lat" type="number" min="-90" max="90" step="0.0000001" required></div>
      <div class="form-group"><label>Longitude</label><input name="lng" type="number" min="-180" max="180" step="0.0000001" required></div>
      <div class="form-group"><label>Service radius (km)</label><input name="service_radius_km" type="number" min="0.1" max="1000" step="0.1" value="5" required></div>
      <div style="text-align:right;"><button class="btn btn-primary">Add branch</button></div>
    </form></div></aside>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
