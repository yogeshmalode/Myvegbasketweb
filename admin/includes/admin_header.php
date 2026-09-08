<?php
$admin_username = $_SESSION['admin_username'] ?? 'Admin';
$admin_role_label = ucfirst($_SESSION['admin_role'] ?? 'admin');
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . ' | ' : '' ?>MyVegBasket Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="admin.css">
</head>
<body class="admin-body">
<header class="admin-topbar">
  <div class="admin-topbar-left">
    <button type="button" class="admin-sidebar-toggle" id="adminSidebarToggle" aria-expanded="true" aria-controls="adminSidebar" title="Hide/Show menu">☰</button>
    <a href="dashboard.php" class="admin-brand"><img src="../assets/images/logo-icon.png" alt="MyVegBasket"> <span>MyVegBasket Admin</span></a>
  </div>
  <div class="admin-user-top">
    <span class="admin-user-avatar"><?= h(strtoupper(substr($admin_username,0,1))) ?></span>
    <span class="admin-user-copy"><strong><?= h($admin_username) ?></strong><small><?= h($admin_role_label) ?></small></span>
    <span class="admin-user-chevron">⌄</span>
  </div>
</header>

<aside class="admin-sidebar" id="adminSidebar">
  <div class="admin-profile-card">
    <span class="admin-profile-avatar"><?= h(strtoupper(substr($admin_username,0,1))) ?></span>
    <div><strong><?= h($admin_username) ?></strong><small><?= h($admin_role_label) ?></small></div>
    <span class="admin-profile-arrow">⌄</span>
  </div>
  <nav class="admin-sidebar-nav" aria-label="Admin navigation">
    <?php
    $nav = [
      ['dashboard.php','⌂','Dashboard'],
      ['vegetables.php','♧','Vegetables'],
      ['inventory.php','▣','Inventory'],
      ['billing.php','▤','Billing (POS)'],
      ['wastage.php','♜','Wastage'],
      ['reports.php','▥','Reports (P&L)'],
      ['management_check.php','♢','System Check'],
      ['catalog_pricing.php','⚡','Catalog & Live Pricing'],
      ['subscriptions.php','♻','Subscriptions'],
      ['fulfillment.php','📦','Fulfillment'],
      ['procurement.php','🧾','Procurement'],
      ['daily_procurement_dashboard.php','📈','Daily Procurement'],
      ['deliveries.php','🚚','Deliveries'],
      ['delivery.php','🚚','Delivery Planner'],
      ['scan_delivery.php','📷','Scan Delivery'],
      ['orders.php','▱','Orders'],
      ['offers.php','🎁','Offers'],
      ['users.php','♙','Users'],
    ];
    foreach($nav as $item):
      if (($item[0]==='users.php' || $item[0]==='management_check.php') && !is_admin_role()) continue;
      $active = $current_page === $item[0] || ($item[0]==='dashboard.php' && $current_page==='index.php');
    ?>
      <a class="admin-side-link <?= $active ? 'active' : '' ?>" href="<?= h($item[0]) ?>"><span class="admin-side-icon"><?= $item[1] ?></span><span class="admin-side-text"><?= h($item[2]) ?></span></a>
    <?php endforeach; ?>
    <a class="admin-side-link" href="<?= BASE_URL ?>/index.php" target="_blank"><span class="admin-side-icon">▣</span><span class="admin-side-text">View Store</span><span class="admin-external">↗</span></a>
    <a class="admin-side-link admin-logout" href="logout.php"><span class="admin-side-icon">⇥</span><span class="admin-side-text">Logout</span></a>
  </nav>
  <div class="admin-sidebar-footer">© <?= date('Y') ?> MyVegBasket<br><span>All rights reserved.</span></div>
</aside>
<div class="admin-sidebar-overlay" id="adminSidebarOverlay"></div>

<script>
(function(){
  const body=document.body, btn=document.getElementById('adminSidebarToggle'), sidebar=document.getElementById('adminSidebar'), overlay=document.getElementById('adminSidebarOverlay');
  const key='myvegbasket_admin_sidebar_collapsed';
  if(localStorage.getItem(key)==='1') body.classList.add('admin-sidebar-collapsed');
  function sync(){ const collapsed=body.classList.contains('admin-sidebar-collapsed'); btn.setAttribute('aria-expanded', String(!collapsed)); btn.title=collapsed?'Show menu':'Hide menu'; }
  btn.addEventListener('click',function(){
    if(window.innerWidth<=900){ body.classList.toggle('admin-sidebar-mobile-open'); }
    else { body.classList.toggle('admin-sidebar-collapsed'); localStorage.setItem(key, body.classList.contains('admin-sidebar-collapsed')?'1':'0'); }
    sync();
  });
  overlay.addEventListener('click',function(){body.classList.remove('admin-sidebar-mobile-open');});
  sync();
})();
</script>
<main class="admin-main"><div class="container admin-content">
