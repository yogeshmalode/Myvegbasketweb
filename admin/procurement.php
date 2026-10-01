<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Procurement & Inwarding';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($_POST['csrf_token'] ?? null);
    $action = $_POST['action'] ?? '';
    $errors = [];

    try {
        if ($action === 'save_vendor') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $type = in_array($_POST['type'] ?? 'vendor', ['farmer','vendor','aggregator'], true) ? $_POST['type'] : 'vendor';
            $address = trim((string)($_POST['address'] ?? ''));
            $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
            if ($name === '') { $errors[] = 'Vendor/farmer name is required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('INSERT INTO vendors (name, type, phone, address, payment_terms, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $type, $phone !== '' ? $phone : null, $address !== '' ? $address : null, $payment_terms !== '' ? $payment_terms : null, 'active']);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vendor saved.'];
                header('Location: procurement.php');
                exit;
            }
        }

        if ($action === 'save_farmer') {
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $village = trim((string)($_POST['village'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
            if ($name === '') { $errors[] = 'Farmer name is required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('INSERT INTO farmers (name, phone, village, address, payment_terms, status) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$name, $phone !== '' ? $phone : null, $village !== '' ? $village : null, $address !== '' ? $address : null, $payment_terms !== '' ? $payment_terms : null, 'active']);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Farmer saved.'];
                header('Location: procurement.php');
                exit;
            }
        }

        if ($action === 'save_vehicle') {
            $vehicleNo = trim((string)($_POST['vehicle_no'] ?? ''));
            $type = trim((string)($_POST['type'] ?? 'Bike'));
            $capacity = (float)($_POST['max_capacity_kg'] ?? 0);
            $driver = trim((string)($_POST['driver_name'] ?? ''));
            if ($vehicleNo === '' || $capacity <= 0) { $errors[] = 'Vehicle number and valid capacity are required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('INSERT INTO vehicles (vehicle_no, type, max_capacity_kg, driver_name, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$vehicleNo, $type !== '' ? $type : 'Bike', $capacity, $driver !== '' ? $driver : null, 'active']);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle saved.'];
                header('Location: procurement.php');
                exit;
            }
        }

        if ($action === 'save_purchase') {
            $sourceType = in_array($_POST['source_type'] ?? 'mandi', ['mandi','farm','direct'], true) ? $_POST['source_type'] : 'mandi';
            $vendorId = $_POST['vendor_id'] ?? null;
            $farmerId = $_POST['farmer_id'] ?? null;
            $itemName = trim((string)($_POST['item_name'] ?? ''));
            $marketName = trim((string)($_POST['market_name'] ?? ''));
            $purchaseDate = $_POST['purchase_date'] ?? date('Y-m-d');
            $quantity = (float)($_POST['quantity_kg'] ?? 0);
            $rate = (float)($_POST['rate_per_kg'] ?? 0);
            $commission = (float)($_POST['agent_commission'] ?? 0);
            $marketFee = (float)($_POST['market_fee'] ?? 0);
            $transport = (float)($_POST['transport_cost'] ?? 0);
            $paymentTerms = trim((string)($_POST['payment_terms'] ?? ''));
            $notes = trim((string)($_POST['notes'] ?? ''));

            if ($itemName === '' || $quantity <= 0 || $rate <= 0) { $errors[] = 'Item name, quantity, and rate are required.'; }
            if (empty($errors)) {
                $total = $quantity * $rate;
                $stmt = $pdo->prepare('INSERT INTO purchase_entries (vendor_id, farmer_id, source_type, market_name, purchase_date, item_name, quantity_kg, rate_per_kg, total_amount, agent_commission, market_fee, transport_cost, payment_terms, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $vendorId !== '' ? (int)$vendorId : null,
                    $farmerId !== '' ? (int)$farmerId : null,
                    $sourceType,
                    $marketName !== '' ? $marketName : null,
                    $purchaseDate,
                    $itemName,
                    $quantity,
                    $rate,
                    $total,
                    $commission,
                    $marketFee,
                    $transport,
                    $paymentTerms !== '' ? $paymentTerms : null,
                    $notes !== '' ? $notes : null,
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Purchase entry saved.'];
                header('Location: procurement.php');
                exit;
            }
        }

        if ($action === 'save_inward') {
            $purchaseId = (int)($_POST['purchase_id'] ?? 0);
            $receivedDate = $_POST['received_date'] ?? date('Y-m-d');
            $gross = (float)($_POST['gross_weight_kg'] ?? 0);
            $tare = (float)($_POST['tare_weight_kg'] ?? 0);
            $net = $gross - $tare;
            $grade = in_array($_POST['quality_grade'] ?? 'A', ['A','B','C'], true) ? $_POST['quality_grade'] : 'A';
            $wastage = (float)($_POST['wastage_kg'] ?? 0);
            $status = in_array($_POST['status'] ?? 'received', ['received','qc_pending','rejected'], true) ? $_POST['status'] : 'received';
            if ($purchaseId <= 0 || $gross <= 0) { $errors[] = 'Valid purchase and gross weight are required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('INSERT INTO inward_goods (purchase_id, received_date, gross_weight_kg, tare_weight_kg, net_weight_kg, quality_grade, wastage_kg, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$purchaseId, $receivedDate, $gross, $tare, max($net, 0), $grade, $wastage, $status, trim((string)($_POST['notes'] ?? '')) ?: null]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'GRN / inward record saved.'];
                header('Location: procurement.php');
                exit;
            }
        }

        if ($action === 'save_trip') {
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $routeName = trim((string)($_POST['route_name'] ?? ''));
            $dispatchDate = $_POST['dispatch_date'] ?? date('Y-m-d');
            $orderWeight = (float)($_POST['order_weight_kg'] ?? 0);
            $usableWeight = (float)($_POST['usable_weight_kg'] ?? 0);
            $fuelCost = (float)($_POST['fuel_cost'] ?? 0);
            $tollCost = (float)($_POST['toll_cost'] ?? 0);
            $driverName = trim((string)($_POST['driver_name'] ?? ''));
            $status = in_array($_POST['status'] ?? 'planned', ['planned','dispatched','completed','blocked'], true) ? $_POST['status'] : 'planned';
            if ($vehicleId <= 0 || $orderWeight <= 0) { $errors[] = 'Vehicle and total order weight are required.'; }

            $vehicle = $pdo->prepare('SELECT max_capacity_kg, vehicle_no FROM vehicles WHERE id = ?');
            $vehicle->execute([$vehicleId]);
            $vehicleRow = $vehicle->fetch();
            if (!$vehicleRow) { $errors[] = 'Selected vehicle not found.'; }
            if ($usableWeight > (float)($vehicleRow['max_capacity_kg'] ?? 0) && empty($errors)) {
                $errors[] = 'Dispatch blocked: usable weight ' . number_format($usableWeight, 2) . ' kg exceeds vehicle capacity ' . number_format((float)$vehicleRow['max_capacity_kg'], 2) . ' kg.';
            }

            if (empty($errors)) {
                $tripCost = $usableWeight > 0 ? (($fuelCost + $tollCost) / $usableWeight) : 0;
                $stmt = $pdo->prepare('INSERT INTO dispatch_trips (vehicle_id, route_name, dispatch_date, order_weight_kg, usable_weight_kg, capacity_kg, fuel_cost, toll_cost, driver_name, status, trip_cost_per_kg, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$vehicleId, $routeName !== '' ? $routeName : null, $dispatchDate, $orderWeight, $usableWeight, (float)($vehicleRow['max_capacity_kg'] ?? 0), $fuelCost, $tollCost, $driverName !== '' ? $driverName : null, $status, $tripCost, trim((string)($_POST['notes'] ?? '')) ?: null]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Dispatch trip saved.'];
                header('Location: procurement.php');
                exit;
            }
        }
        // ---- Edit / update actions ----
        if ($action === 'update_vendor') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $type = in_array($_POST['type'] ?? 'vendor', ['farmer','vendor','aggregator'], true) ? $_POST['type'] : 'vendor';
            $phone = trim((string)($_POST['phone'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
            $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
            if ($id <= 0 || $name === '') { $errors[] = 'Vendor/farmer name is required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('UPDATE vendors SET name=?, type=?, phone=?, address=?, payment_terms=?, status=? WHERE id=?');
                $stmt->execute([$name, $type, $phone !== '' ? $phone : null, $address !== '' ? $address : null, $payment_terms !== '' ? $payment_terms : null, $status, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vendor updated.'];
                header('Location: procurement.php'); exit;
            }
        }

        if ($action === 'delete_vendor') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM vendors WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vendor deleted.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'update_farmer') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $village = trim((string)($_POST['village'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $payment_terms = trim((string)($_POST['payment_terms'] ?? ''));
            $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
            if ($id <= 0 || $name === '') { $errors[] = 'Farmer name is required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('UPDATE farmers SET name=?, phone=?, village=?, address=?, payment_terms=?, status=? WHERE id=?');
                $stmt->execute([$name, $phone !== '' ? $phone : null, $village !== '' ? $village : null, $address !== '' ? $address : null, $payment_terms !== '' ? $payment_terms : null, $status, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Farmer updated.'];
                header('Location: procurement.php'); exit;
            }
        }

        if ($action === 'delete_farmer') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM farmers WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Farmer deleted.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'update_vehicle') {
            $id = (int)($_POST['id'] ?? 0);
            $vehicleNo = trim((string)($_POST['vehicle_no'] ?? ''));
            $type = trim((string)($_POST['type'] ?? 'Bike'));
            $capacity = (float)($_POST['max_capacity_kg'] ?? 0);
            $driver = trim((string)($_POST['driver_name'] ?? ''));
            $status = in_array($_POST['status'] ?? 'active', ['active','inactive'], true) ? $_POST['status'] : 'active';
            if ($id <= 0 || $vehicleNo === '' || $capacity <= 0) { $errors[] = 'Vehicle number and valid capacity are required.'; }
            if (empty($errors)) {
                $stmt = $pdo->prepare('UPDATE vehicles SET vehicle_no=?, type=?, max_capacity_kg=?, driver_name=?, status=? WHERE id=?');
                $stmt->execute([$vehicleNo, $type !== '' ? $type : 'Bike', $capacity, $driver !== '' ? $driver : null, $status, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle updated.'];
                header('Location: procurement.php'); exit;
            }
        }

        if ($action === 'delete_vehicle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                try {
                    $pdo->prepare('DELETE FROM vehicles WHERE id = ?')->execute([$id]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle deleted.'];
                } catch (Throwable $e) {
                    // Vehicles with dispatch trip history are protected by an
                    // ON DELETE RESTRICT foreign key — mark inactive instead
                    // so existing trip records stay intact.
                    $pdo->prepare("UPDATE vehicles SET status='inactive' WHERE id = ?")->execute([$id]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Vehicle has dispatch history, so it was marked inactive instead of deleted.'];
                }
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'delete_purchase') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                // GRN entries linked to this purchase cascade-delete automatically.
                $pdo->prepare('DELETE FROM purchase_entries WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Purchase entry (and any linked GRN) deleted.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'update_inward_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? 'received', ['received','qc_pending','rejected'], true) ? $_POST['status'] : 'received';
            if ($id > 0) {
                $pdo->prepare('UPDATE inward_goods SET status=? WHERE id=?')->execute([$status, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'GRN status updated.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'delete_inward') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM inward_goods WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'GRN entry deleted.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'update_trip_status') {
            $id = (int)($_POST['id'] ?? 0);
            $status = in_array($_POST['status'] ?? 'planned', ['planned','dispatched','completed','blocked'], true) ? $_POST['status'] : 'planned';
            if ($id > 0) {
                $pdo->prepare('UPDATE dispatch_trips SET status=? WHERE id=?')->execute([$status, $id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Dispatch trip status updated.'];
            }
            header('Location: procurement.php'); exit;
        }

        if ($action === 'delete_trip') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $pdo->prepare('DELETE FROM dispatch_trips WHERE id = ?')->execute([$id]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Dispatch trip deleted.'];
            }
            header('Location: procurement.php'); exit;
        }
    } catch (Throwable $e) {
        $errors[] = 'Could not save: ' . $e->getMessage();
    }

    if (!empty($errors)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => implode(' ', $errors)];
        header('Location: procurement.php');
        exit;
    }
}

$vendorCount = (int)$pdo->query('SELECT COUNT(*) FROM vendors')->fetchColumn();
$farmerCount = (int)$pdo->query('SELECT COUNT(*) FROM farmers')->fetchColumn();
$vehicleCount = (int)$pdo->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$purchaseCount = (int)$pdo->query('SELECT COUNT(*) FROM purchase_entries')->fetchColumn();
$inwardCount = (int)$pdo->query('SELECT COUNT(*) FROM inward_goods')->fetchColumn();

$vendors = $pdo->query('SELECT * FROM vendors ORDER BY id DESC LIMIT 20')->fetchAll();
$farmers = $pdo->query('SELECT * FROM farmers ORDER BY id DESC LIMIT 20')->fetchAll();
$vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY id DESC LIMIT 20')->fetchAll();
$purchaseEntries = $pdo->query('SELECT pe.*, v.name AS vendor_name, f.name AS farmer_name FROM purchase_entries pe LEFT JOIN vendors v ON v.id = pe.vendor_id LEFT JOIN farmers f ON f.id = pe.farmer_id ORDER BY pe.id DESC LIMIT 20')->fetchAll();
$inwardGoods = $pdo->query('SELECT ig.*, pe.item_name, pe.quantity_kg FROM inward_goods ig JOIN purchase_entries pe ON pe.id = ig.purchase_id ORDER BY ig.id DESC LIMIT 20')->fetchAll();
$dispatchTrips = $pdo->query('SELECT dt.*, v.vehicle_no FROM dispatch_trips dt JOIN vehicles v ON v.id = dt.vehicle_id ORDER BY dt.id DESC LIMIT 20')->fetchAll();

// ---- Inline "Edit" support: ?edit_vendor=5 / ?edit_farmer=5 / ?edit_vehicle=5
// pre-fills the Add form above with that record's data and switches it to
// update mode, instead of needing a separate edit page/modal.
$editVendor = null;
if (!empty($_GET['edit_vendor'])) {
    $st = $pdo->prepare('SELECT * FROM vendors WHERE id = ?');
    $st->execute([(int)$_GET['edit_vendor']]);
    $editVendor = $st->fetch() ?: null;
}
$editFarmer = null;
if (!empty($_GET['edit_farmer'])) {
    $st = $pdo->prepare('SELECT * FROM farmers WHERE id = ?');
    $st->execute([(int)$_GET['edit_farmer']]);
    $editFarmer = $st->fetch() ?: null;
}
$editVehicle = null;
if (!empty($_GET['edit_vehicle'])) {
    $st = $pdo->prepare('SELECT * FROM vehicles WHERE id = ?');
    $st->execute([(int)$_GET['edit_vehicle']]);
    $editVehicle = $st->fetch() ?: null;
}
$activeTab = $_GET['tab'] ?? ($editVendor ? 'vendor' : ($editFarmer ? 'farmer' : ($editVehicle ? 'vehicle' : 'vendor')));

include __DIR__ . '/includes/admin_header.php';
?>
<style>
  .proc-shell { background: transparent; }
  .proc-head { display:flex; justify-content:space-between; gap:16px; align-items:flex-end; flex-wrap:wrap; margin-bottom:18px; }
  .proc-head h2 { margin:0; font-size:clamp(1.6rem,2vw,2.3rem); letter-spacing:-0.05em; color:#11231d; font-weight:900; }
  .proc-head p { margin:8px 0 0; color:#66736f; font-size:0.82rem; }
  .proc-stats { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:14px; margin-bottom:20px; }
  .proc-stat { background:#fff; border:1px solid #e3eae4; border-radius:14px; padding:16px; box-shadow:0 8px 22px rgba(17,48,37,0.04); }
  .proc-stat .label { display:block; color:#69756f; font-size:0.7rem; letter-spacing:0.08em; text-transform:uppercase; font-weight:800; }
  .proc-stat strong { display:block; margin-top:10px; font-size:1.8rem; color:#122a24; }
  .proc-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; margin-bottom:18px; }
  .proc-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:16px; background:#fff; border:1px solid #e3eae4; border-radius:14px; padding:6px; box-shadow:0 8px 22px rgba(17,48,37,0.04); }
  .proc-tab-btn { border:none; background:transparent; padding:10px 16px; border-radius:10px; font-size:0.8rem; font-weight:700; color:#55615d; cursor:pointer; transition:background .15s,color .15s; }
  .proc-tab-btn:hover { background:#f2f6f3; }
  .proc-tab-btn.active { background:#0d7a59; color:#fff; }
  .proc-tab-panel { display:none; }
  .proc-tab-panel.active { display:block; }
  .proc-panel { background:#fff; border:1px solid #e3eae4; border-radius:16px; overflow:hidden; box-shadow:0 8px 22px rgba(17,48,37,0.04); }
  .proc-panel-head { padding:16px 18px; border-bottom:1px solid #edf0ee; background:#f7faf8; }
  .proc-panel-head h3 { margin:0; font-size:1.05rem; color:#17231f; }
  .proc-form { padding:18px; }
  .proc-form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
  .proc-form .field { display:flex; flex-direction:column; gap:6px; }
  .proc-form label { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.07em; color:#55615d; font-weight:800; }
  .proc-form input, .proc-form select, .proc-form textarea { width:100%; min-height:40px; border-radius:10px; border:1px solid #d7e1db; padding:0 11px; background:#fafcfb; font:inherit; }
  .proc-form textarea { min-height:80px; resize:vertical; padding:10px 11px; }
  .proc-form .full { grid-column:1 / -1; }
  .proc-btns { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
  .proc-btns .btn { min-height:40px; padding:0 14px; border-radius:10px; font-size:0.8rem; font-weight:700; }
  .proc-table-wrap { overflow-x:auto; }
  .proc-table { width:100%; min-width:900px; border-collapse:collapse; }
  .proc-table th, .proc-table td { border-bottom:1px solid #edf0ee; padding:10px 12px; text-align:left; vertical-align:top; font-size:0.8rem; color:#1f2b28; }
  .proc-table th { background:#f7faf8; color:#586560; font-size:0.68rem; letter-spacing:0.06em; text-transform:uppercase; }
  .proc-table tbody tr:hover { background:#fafdf9; }
  .status-pill { display:inline-flex; padding:5px 8px; border-radius:999px; font-size:0.7rem; background:#edf7f1; color:#0e7b57; font-weight:700; }
  .status-pill.blocked { background:#ffe9e5; color:#d5312d; }
  .status-pill.qc_pending { background:#fff2d8; color:#b5700d; }
  .status-pill.rejected { background:#fce8e6; color:#aa3129; }
  .status-pill.inactive { background:#f1f1ee; color:#6b6b63; }
  .warning-box { padding:12px 14px; border-radius:10px; background:#fff6eb; color:#955a14; border:1px solid #f1d3a4; font-size:0.82rem; }
  .row-actions { display:flex; gap:6px; flex-wrap:wrap; }
  .row-actions .btn { min-height:30px; padding:0 10px; font-size:0.72rem; border-radius:8px; font-weight:700; }
  .row-actions .btn.danger { background:#fce8e6; color:#9A2E24; border:1px solid #f4cfc9; }
  .row-actions form { display:inline-flex; align-items:center; gap:4px; }
  .row-actions select.status-mini { min-height:30px; border-radius:8px; border:1px solid #d7e1db; font-size:0.72rem; padding:0 6px; }
  @media (max-width:960px) { .proc-stats, .proc-grid { grid-template-columns:1fr 1fr; } }
  @media (max-width:720px) { .proc-stats, .proc-grid { grid-template-columns:1fr; } }
</style>

<div class="proc-shell">
  <div class="proc-head">
    <div>
      <h2>Procurement & Inwarding</h2>
      <p>Manage vendors, farmers, mandi purchases, quality checks, GRN, and dispatch capability.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>

  <div class="proc-stats">
    <div class="proc-stat"><span class="label">Vendors</span><strong><?= number_format($vendorCount) ?></strong></div>
    <div class="proc-stat"><span class="label">Farmers</span><strong><?= number_format($farmerCount) ?></strong></div>
    <div class="proc-stat"><span class="label">Vehicles</span><strong><?= number_format($vehicleCount) ?></strong></div>
    <div class="proc-stat"><span class="label">Purchases</span><strong><?= number_format($purchaseCount) ?></strong></div>
    <div class="proc-stat"><span class="label">Inward</span><strong><?= number_format($inwardCount) ?></strong></div>
  </div>

  <div class="proc-tabs" role="tablist" aria-label="Procurement forms">
    <button type="button" class="proc-tab-btn <?= $activeTab === 'vendor' ? 'active' : '' ?>" data-tab="vendor">Supplier / Vendor</button>
    <button type="button" class="proc-tab-btn <?= $activeTab === 'farmer' ? 'active' : '' ?>" data-tab="farmer">Farmer</button>
    <button type="button" class="proc-tab-btn <?= $activeTab === 'vehicle' ? 'active' : '' ?>" data-tab="vehicle">Vehicle</button>
    <button type="button" class="proc-tab-btn <?= $activeTab === 'purchase' ? 'active' : '' ?>" data-tab="purchase">Purchase Entry</button>
    <button type="button" class="proc-tab-btn <?= $activeTab === 'inward' ? 'active' : '' ?>" data-tab="inward">QC / GRN Inward</button>
    <button type="button" class="proc-tab-btn <?= $activeTab === 'dispatch' ? 'active' : '' ?>" data-tab="dispatch">Dispatch Capability</button>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'vendor' ? 'active' : '' ?>" id="tab-vendor">
    <section class="proc-panel" style="margin-bottom:18px;">
      <div class="proc-panel-head"><h3><?= $editVendor ? 'Edit Supplier / Vendor #' . (int)$editVendor['id'] : 'Add Supplier / Vendor' ?></h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="<?= $editVendor ? 'update_vendor' : 'save_vendor' ?>">
          <?php if ($editVendor): ?><input type="hidden" name="id" value="<?= (int)$editVendor['id'] ?>"><?php endif; ?>
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Name</label><input type="text" name="name" value="<?= h($editVendor['name'] ?? '') ?>" required></div>
            <div class="field"><label>Type</label><select name="type">
              <?php foreach (['vendor'=>'Vendor','farmer'=>'Farmer','aggregator'=>'Aggregator'] as $val=>$lbl): ?>
                <option value="<?= $val ?>" <?= ($editVendor['type'] ?? 'vendor') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select></div>
            <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?= h($editVendor['phone'] ?? '') ?>"></div>
            <div class="field"><label>Payment terms</label><input type="text" name="payment_terms" placeholder="7 days, 15 days" value="<?= h($editVendor['payment_terms'] ?? '') ?>"></div>
            <div class="field full"><label>Address</label><textarea name="address"><?= h($editVendor['address'] ?? '') ?></textarea></div>
            <?php if ($editVendor): ?>
              <div class="field"><label>Status</label><select name="status">
                <option value="active" <?= $editVendor['status']==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $editVendor['status']==='inactive'?'selected':'' ?>>Inactive</option>
              </select></div>
            <?php endif; ?>
          </div>
          <div class="proc-btns">
            <button type="submit" class="btn btn-primary"><?= $editVendor ? 'Update Vendor' : 'Save' ?></button>
            <?php if ($editVendor): ?><a class="btn" href="procurement.php?tab=vendor">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="proc-panel">
      <div class="proc-panel-head"><h3>All Vendors / Farmers (Suppliers)</h3></div>
      <div class="proc-table-wrap">
        <table class="proc-table">
          <thead><tr><th>ID</th><th>Name</th><th>Type</th><th>Phone</th><th>Payment Terms</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($vendors as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h(ucfirst($row['type'])) ?></td>
                <td><?= h($row['phone'] ?: '—') ?></td>
                <td><?= h($row['payment_terms'] ?: '—') ?></td>
                <td><span class="status-pill <?= $row['status'] === 'inactive' ? 'inactive' : '' ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                <td class="row-actions">
                  <a class="btn" href="procurement.php?tab=vendor&edit_vendor=<?= (int)$row['id'] ?>#tab-vendor">✏ Edit</a>
                  <form method="post" onsubmit="return confirm('Delete vendor &quot;<?= h(addslashes($row['name'])) ?>&quot;?');">
                    <input type="hidden" name="action" value="delete_vendor">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="btn danger">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if (!$vendors): ?><tr><td colspan="7" style="text-align:center; color:#68736f;">No vendors added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'farmer' ? 'active' : '' ?>" id="tab-farmer">
    <section class="proc-panel" style="margin-bottom:18px;">
      <div class="proc-panel-head"><h3><?= $editFarmer ? 'Edit Farmer #' . (int)$editFarmer['id'] : 'Add Farmer' ?></h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="<?= $editFarmer ? 'update_farmer' : 'save_farmer' ?>">
          <?php if ($editFarmer): ?><input type="hidden" name="id" value="<?= (int)$editFarmer['id'] ?>"><?php endif; ?>
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Name</label><input type="text" name="name" value="<?= h($editFarmer['name'] ?? '') ?>" required></div>
            <div class="field"><label>Phone</label><input type="tel" name="phone" value="<?= h($editFarmer['phone'] ?? '') ?>"></div>
            <div class="field"><label>Village</label><input type="text" name="village" value="<?= h($editFarmer['village'] ?? '') ?>"></div>
            <div class="field"><label>Payment terms</label><input type="text" name="payment_terms" placeholder="Weekly / advance" value="<?= h($editFarmer['payment_terms'] ?? '') ?>"></div>
            <div class="field full"><label>Address</label><textarea name="address"><?= h($editFarmer['address'] ?? '') ?></textarea></div>
            <?php if ($editFarmer): ?>
              <div class="field"><label>Status</label><select name="status">
                <option value="active" <?= $editFarmer['status']==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $editFarmer['status']==='inactive'?'selected':'' ?>>Inactive</option>
              </select></div>
            <?php endif; ?>
          </div>
          <div class="proc-btns">
            <button type="submit" class="btn btn-primary"><?= $editFarmer ? 'Update Farmer' : 'Save' ?></button>
            <?php if ($editFarmer): ?><a class="btn" href="procurement.php?tab=farmer">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="proc-panel">
      <div class="proc-panel-head"><h3>All Farmers</h3></div>
      <div class="proc-table-wrap">
        <table class="proc-table">
          <thead><tr><th>ID</th><th>Name</th><th>Village</th><th>Phone</th><th>Payment Terms</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($farmers as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><?= h($row['name']) ?></td>
                <td><?= h($row['village'] ?: '—') ?></td>
                <td><?= h($row['phone'] ?: '—') ?></td>
                <td><?= h($row['payment_terms'] ?: '—') ?></td>
                <td><span class="status-pill <?= $row['status'] === 'inactive' ? 'inactive' : '' ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                <td class="row-actions">
                  <a class="btn" href="procurement.php?tab=farmer&edit_farmer=<?= (int)$row['id'] ?>#tab-farmer">✏ Edit</a>
                  <form method="post" onsubmit="return confirm('Delete farmer &quot;<?= h(addslashes($row['name'])) ?>&quot;?');">
                    <input type="hidden" name="action" value="delete_farmer">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="btn danger">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if (!$farmers): ?><tr><td colspan="7" style="text-align:center; color:#68736f;">No farmers added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'vehicle' ? 'active' : '' ?>" id="tab-vehicle">
    <section class="proc-panel" style="margin-bottom:18px;">
      <div class="proc-panel-head"><h3><?= $editVehicle ? 'Edit Vehicle #' . (int)$editVehicle['id'] : 'Add Vehicle' ?></h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="<?= $editVehicle ? 'update_vehicle' : 'save_vehicle' ?>">
          <?php if ($editVehicle): ?><input type="hidden" name="id" value="<?= (int)$editVehicle['id'] ?>"><?php endif; ?>
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Vehicle no.</label><input type="text" name="vehicle_no" value="<?= h($editVehicle['vehicle_no'] ?? '') ?>" required></div>
            <div class="field"><label>Type</label><input type="text" name="type" value="<?= h($editVehicle['type'] ?? 'Bike') ?>" required></div>
            <div class="field"><label>Max capacity (kg)</label><input type="number" step="0.01" min="0" name="max_capacity_kg" value="<?= h($editVehicle['max_capacity_kg'] ?? '') ?>" required></div>
            <div class="field"><label>Driver</label><input type="text" name="driver_name" value="<?= h($editVehicle['driver_name'] ?? '') ?>"></div>
            <?php if ($editVehicle): ?>
              <div class="field"><label>Status</label><select name="status">
                <option value="active" <?= $editVehicle['status']==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $editVehicle['status']==='inactive'?'selected':'' ?>>Inactive</option>
              </select></div>
            <?php endif; ?>
          </div>
          <div class="proc-btns">
            <button type="submit" class="btn btn-primary"><?= $editVehicle ? 'Update Vehicle' : 'Save' ?></button>
            <?php if ($editVehicle): ?><a class="btn" href="procurement.php?tab=vehicle">Cancel</a><?php endif; ?>
          </div>
        </form>
      </div>
    </section>

    <section class="proc-panel">
      <div class="proc-panel-head"><h3>All Vehicles</h3></div>
      <div class="proc-table-wrap">
        <table class="proc-table">
          <thead><tr><th>ID</th><th>Vehicle No.</th><th>Type</th><th>Capacity</th><th>Driver</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            <?php foreach ($vehicles as $row): ?>
              <tr>
                <td>#<?= (int)$row['id'] ?></td>
                <td><?= h($row['vehicle_no']) ?></td>
                <td><?= h($row['type']) ?></td>
                <td><?= number_format((float)$row['max_capacity_kg'], 2) ?> kg</td>
                <td><?= h($row['driver_name'] ?: '—') ?></td>
                <td><span class="status-pill <?= $row['status'] === 'inactive' ? 'inactive' : '' ?>"><?= h(ucfirst($row['status'])) ?></span></td>
                <td class="row-actions">
                  <a class="btn" href="procurement.php?tab=vehicle&edit_vehicle=<?= (int)$row['id'] ?>#tab-vehicle">✏ Edit</a>
                  <form method="post" onsubmit="return confirm('Delete vehicle &quot;<?= h(addslashes($row['vehicle_no'])) ?>&quot;?');">
                    <input type="hidden" name="action" value="delete_vehicle">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="btn danger">🗑 Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; if (!$vehicles): ?><tr><td colspan="7" style="text-align:center; color:#68736f;">No vehicles added yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'purchase' ? 'active' : '' ?>" id="tab-purchase">
    <section class="proc-panel">
      <div class="proc-panel-head"><h3>Purchase Entry / Mandi Ledger</h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="save_purchase">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Source</label><select name="source_type"><option value="mandi">Mandi</option><option value="farm">Farm</option><option value="direct">Direct</option></select></div>
            <div class="field"><label>Market / origin</label><input type="text" name="market_name"></div>
            <div class="field"><label>Vendor</label><select name="vendor_id"><option value="">-- None --</option><?php foreach ($vendors as $v): ?><option value="<?= (int)$v['id'] ?>"><?= h($v['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Farmer</label><select name="farmer_id"><option value="">-- None --</option><?php foreach ($farmers as $f): ?><option value="<?= (int)$f['id'] ?>"><?= h($f['name']) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Item</label><input type="text" name="item_name" required></div>
            <div class="field"><label>Purchase date</label><input type="date" name="purchase_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Quantity (kg)</label><input type="number" step="0.01" min="0" name="quantity_kg" required></div>
            <div class="field"><label>Rate / kg</label><input type="number" step="0.01" min="0" name="rate_per_kg" required></div>
            <div class="field"><label>Agent commission</label><input type="number" step="0.01" min="0" name="agent_commission" value="0"></div>
            <div class="field"><label>Market fee</label><input type="number" step="0.01" min="0" name="market_fee" value="0"></div>
            <div class="field"><label>Transport cost</label><input type="number" step="0.01" min="0" name="transport_cost" value="0"></div>
            <div class="field"><label>Payment terms</label><input type="text" name="payment_terms" placeholder="7 days"></div>
            <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
          </div>
          <div class="proc-btns"><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
      </div>
    </section>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'inward' ? 'active' : '' ?>" id="tab-inward">
    <section class="proc-panel">
      <div class="proc-panel-head"><h3>QC / GRN Inward</h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="save_inward">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Purchase</label><select name="purchase_id" required><?php foreach ($purchaseEntries as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['item_name']) ?> (#<?= (int)$p['id'] ?>)</option><?php endforeach; ?></select></div>
            <div class="field"><label>Received date</label><input type="date" name="received_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Gross weight (kg)</label><input type="number" step="0.01" min="0" name="gross_weight_kg" required></div>
            <div class="field"><label>Tare weight (kg)</label><input type="number" step="0.01" min="0" name="tare_weight_kg" value="0"></div>
            <div class="field"><label>Quality grade</label><select name="quality_grade"><option value="A">A</option><option value="B">B</option><option value="C">C</option></select></div>
            <div class="field"><label>Wastage (kg)</label><input type="number" step="0.01" min="0" name="wastage_kg" value="0"></div>
            <div class="field"><label>Status</label><select name="status"><option value="received">Received</option><option value="qc_pending">QC Pending</option><option value="rejected">Rejected</option></select></div>
            <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
          </div>
          <div class="proc-btns"><button type="submit" class="btn btn-primary">Save GRN</button></div>
        </form>
      </div>
    </section>
  </div>

  <div class="proc-tab-panel <?= $activeTab === 'dispatch' ? 'active' : '' ?>" id="tab-dispatch">
    <section class="proc-panel">
      <div class="proc-panel-head"><h3>Dispatch Capability / Load Sheet</h3></div>
      <div class="proc-form">
        <form method="post">
          <input type="hidden" name="action" value="save_trip">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="proc-form-grid">
            <div class="field"><label>Vehicle</label><select name="vehicle_id" required><?php foreach ($vehicles as $v): ?><option value="<?= (int)$v['id'] ?>"><?= h($v['vehicle_no']) ?> (<?= number_format((float)$v['max_capacity_kg'], 2) ?> kg)</option><?php endforeach; ?></select></div>
            <div class="field"><label>Route</label><input type="text" name="route_name" placeholder="Hadapsar to city"></div>
            <div class="field"><label>Dispatch date</label><input type="date" name="dispatch_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field"><label>Driver</label><input type="text" name="driver_name" placeholder="Name"></div>
            <div class="field"><label>Total order weight (kg)</label><input type="number" step="0.01" min="0" name="order_weight_kg" required></div>
            <div class="field"><label>Usable weight (kg)</label><input type="number" step="0.01" min="0" name="usable_weight_kg" required></div>
            <div class="field"><label>Fuel cost</label><input type="number" step="0.01" min="0" name="fuel_cost" value="0"></div>
            <div class="field"><label>Toll cost</label><input type="number" step="0.01" min="0" name="toll_cost" value="0"></div>
            <div class="field"><label>Status</label><select name="status"><option value="planned">Planned</option><option value="dispatched">Dispatched</option><option value="completed">Completed</option><option value="blocked">Blocked</option></select></div>
            <div class="field full"><label>Notes</label><textarea name="notes"></textarea></div>
          </div>
          <div class="warning-box" style="margin-top:12px;">Formula: Transportation Cost per Kg = (Fuel + Toll) / Usable Weight Brought</div>
          <div class="proc-btns"><button type="submit" class="btn btn-primary">Save trip</button></div>
        </form>
      </div>
    </section>
  </div>

  <script>
    (function(){
      var buttons = document.querySelectorAll('.proc-tab-btn');
      buttons.forEach(function(btn){
        btn.addEventListener('click', function(){
          buttons.forEach(function(b){ b.classList.remove('active'); });
          document.querySelectorAll('.proc-tab-panel').forEach(function(p){ p.classList.remove('active'); });
          btn.classList.add('active');
          document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
        });
      });
    })();
  </script>

  <section class="proc-panel" style="margin-bottom:18px;">
    <div class="proc-panel-head"><h3>Purchases</h3></div>
    <div class="proc-table-wrap">
      <table class="proc-table">
        <thead><tr><th>ID</th><th>Item</th><th>Source</th><th>Vendor / Farmer</th><th>Qty</th><th>Rate</th><th>Total</th><th>Market Fee</th><th>Transport</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($purchaseEntries as $row): ?>
            <tr>
              <td>#<?= (int)$row['id'] ?></td>
              <td><?= h($row['item_name']) ?></td>
              <td><?= h($row['source_type']) ?></td>
              <td><?= h($row['vendor_name'] ?: $row['farmer_name'] ?: '—') ?></td>
              <td><?= number_format((float)$row['quantity_kg'], 2) ?> kg</td>
              <td>₹<?= number_format((float)$row['rate_per_kg'], 2) ?></td>
              <td>₹<?= number_format((float)$row['total_amount'], 2) ?></td>
              <td>₹<?= number_format((float)$row['market_fee'], 2) ?></td>
              <td>₹<?= number_format((float)$row['transport_cost'], 2) ?></td>
              <td class="row-actions">
                <form method="post" onsubmit="return confirm('Delete purchase #<?= (int)$row['id'] ?> and any GRN linked to it?');">
                  <input type="hidden" name="action" value="delete_purchase">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <button type="submit" class="btn danger">🗑 Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if (!$purchaseEntries): ?><tr><td colspan="10" style="text-align:center; color:#68736f;">No purchase entries yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="proc-panel" style="margin-bottom:18px;">
    <div class="proc-panel-head"><h3>GRN / Quality Checks</h3></div>
    <div class="proc-table-wrap">
      <table class="proc-table">
        <thead><tr><th>ID</th><th>Item</th><th>Received</th><th>Net Weight</th><th>Grade</th><th>Wastage</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($inwardGoods as $row): ?>
            <tr>
              <td>#<?= (int)$row['id'] ?></td>
              <td><?= h($row['item_name']) ?></td>
              <td><?= h($row['received_date']) ?></td>
              <td><?= number_format((float)$row['net_weight_kg'], 2) ?> kg</td>
              <td><?= h($row['quality_grade']) ?></td>
              <td><?= number_format((float)$row['wastage_kg'], 2) ?> kg</td>
              <td><span class="status-pill <?= h(str_replace(' ', '_', strtolower($row['status']))) ?>"><?= h($row['status']) ?></span></td>
              <td class="row-actions">
                <form method="post">
                  <input type="hidden" name="action" value="update_inward_status">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <select name="status" class="status-mini" onchange="this.form.submit()">
                    <?php foreach (['received'=>'Received','qc_pending'=>'QC Pending','rejected'=>'Rejected'] as $val=>$lbl): ?>
                      <option value="<?= $val ?>" <?= $row['status']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <form method="post" onsubmit="return confirm('Delete this GRN entry #<?= (int)$row['id'] ?>?');">
                  <input type="hidden" name="action" value="delete_inward">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <button type="submit" class="btn danger">🗑</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if (!$inwardGoods): ?><tr><td colspan="8" style="text-align:center; color:#68736f;">No inward goods yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="proc-panel">
    <div class="proc-panel-head"><h3>Dispatch Trips & Capacity Check</h3></div>
    <div class="proc-table-wrap">
      <table class="proc-table">
        <thead><tr><th>ID</th><th>Vehicle</th><th>Route</th><th>Order Wt</th><th>Usable Wt</th><th>Capacity</th><th>Trip Cost / kg</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($dispatchTrips as $row): ?>
            <tr>
              <td>#<?= (int)$row['id'] ?></td>
              <td><?= h($row['vehicle_no']) ?></td>
              <td><?= h($row['route_name'] ?: '—') ?></td>
              <td><?= number_format((float)$row['order_weight_kg'], 2) ?> kg</td>
              <td><?= number_format((float)$row['usable_weight_kg'], 2) ?> kg</td>
              <td><?= number_format((float)$row['capacity_kg'], 2) ?> kg</td>
              <td>₹<?= number_format((float)$row['trip_cost_per_kg'], 2) ?></td>
              <td><span class="status-pill <?= h(str_replace(' ', '_', strtolower($row['status']))) ?>"><?= h($row['status']) ?></span></td>
              <td class="row-actions">
                <form method="post">
                  <input type="hidden" name="action" value="update_trip_status">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <select name="status" class="status-mini" onchange="this.form.submit()">
                    <?php foreach (['planned'=>'Planned','dispatched'=>'Dispatched','completed'=>'Completed','blocked'=>'Blocked'] as $val=>$lbl): ?>
                      <option value="<?= $val ?>" <?= $row['status']===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
                <form method="post" onsubmit="return confirm('Delete dispatch trip #<?= (int)$row['id'] ?>?');">
                  <input type="hidden" name="action" value="delete_trip">
                  <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <button type="submit" class="btn danger">🗑</button>
                </form>
              </td>
            </tr>
          <?php endforeach; if (!$dispatchTrips): ?><tr><td colspan="9" style="text-align:center; color:#68736f;">No dispatch trips yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>