<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Role Permissions';

// Every admin/*.php page a role could conceivably be granted, grouped for
// a friendlier checklist. Pages not listed here (login/logout/setup/etc.)
// aren't meaningful permission targets.
$allPages = [
    'dashboard.php' => 'Dashboard',
    'billing.php' => 'Billing (POS)',
    'vegetables.php' => 'Vegetables',
    'inventory.php' => 'Inventory',
    'orders.php' => 'Orders',
    'wastage.php' => 'Wastage',
    'reports.php' => 'Reports (P&L)',
    'catalog_pricing.php' => 'Catalog & Live Pricing',
    'subscriptions.php' => 'Subscriptions',
    'fulfillment.php' => 'Fulfillment',
    'procurement.php' => 'Procurement',
    'daily_procurement_dashboard.php' => 'Daily Procurement',
    'offers.php' => 'Offers',
    'deliveries.php' => 'Deliveries',
    'delivery.php' => 'Delivery Planner',
    'dark_stores.php' => 'Dark Stores',
    'riders.php' => 'Riders',
    'scan_delivery.php' => 'Scan Delivery',
    'manifest.php' => 'Manifest',
    'picking_sheet.php' => 'Picking Sheet',
    'route_sheet.php' => 'Route Sheet',
];
$roles = ['staff', 'delivery'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $role = in_array($_POST['role'] ?? '', $roles, true) ? $_POST['role'] : null;
    if ($role) {
        $selectedPages = array_intersect((array)($_POST['pages'] ?? []), array_keys($allPages));
        $pdo->prepare('DELETE FROM role_page_permissions WHERE role = ?')->execute([$role]);
        if ($selectedPages) {
            $ins = $pdo->prepare('INSERT IGNORE INTO role_page_permissions (role, page) VALUES (?, ?)');
            foreach ($selectedPages as $page) {
                $ins->execute([$role, $page]);
            }
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => ucfirst($role) . ' permissions updated.'];
    }
    redirect('role_permissions.php');
}

$currentPerms = [];
foreach ($roles as $role) {
    $currentPerms[$role] = get_role_allowed_pages($pdo, $role) ?? [];
}
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Role Permissions</h2><p>Choose exactly which pages Staff and Delivery accounts can open. Admin always has full access.</p></div>
<?php if(!empty($_SESSION['flash'])){ echo '<div class="alert alert-'.h($_SESSION['flash']['type']).'">'.h($_SESSION['flash']['message']).'</div>'; unset($_SESSION['flash']); } ?>

<div style="display:flex; gap:20px; flex-wrap:wrap;">
<?php foreach ($roles as $role): ?>
  <div class="form-card" style="flex:1; min-width:320px;">
    <h3><?= ucfirst($role) ?> role</h3>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="role" value="<?= h($role) ?>">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:6px; margin:12px 0;">
        <?php foreach ($allPages as $page => $label): ?>
          <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.85rem;">
            <input type="checkbox" name="pages[]" value="<?= h($page) ?>" <?= in_array($page, $currentPerms[$role], true) ? 'checked' : '' ?>>
            <?= h($label) ?>
          </label>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-primary">Save <?= ucfirst($role) ?> Permissions</button>
    </form>
  </div>
<?php endforeach; ?>
</div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
