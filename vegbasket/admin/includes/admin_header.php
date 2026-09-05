<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . ' | ' : '' ?>VegBasket Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<header class="site-header">
  <div class="header-inner">
    <a href="dashboard.php" class="logo"><span class="logo-badge">🥕</span> VegBasket Admin</a>
    <div class="nav-actions">
      <a href="dashboard.php" class="nav-link">Vegetables</a>
      <a href="orders.php" class="nav-link">Orders</a>
      <a href="<?= BASE_URL ?>/index.php" class="nav-link">View Store</a>
      <a href="logout.php" class="nav-link" style="color:#F1B6AF;">Logout (<?= h($_SESSION['admin_username'] ?? '') ?>)</a>
    </div>
  </div>
</header>
<div class="container" style="padding:40px 24px;">
