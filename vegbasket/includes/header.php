<?php
if (!isset($pdo)) { require_once __DIR__ . '/../config.php'; }
$search_q = trim($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($page_title) ? h($page_title) . ' | ' : '' ?><?= SITE_NAME ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script>window.__VEGBASKET_BASE__ = "<?= BASE_URL ?>";</script>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="<?= BASE_URL ?>/index.php" class="logo">
      <span class="logo-badge">🥕</span> VegBasket
    </a>

    <form class="search-form" action="<?= BASE_URL ?>/index.php" method="get">
      <input type="text" name="q" placeholder="Search tomato, onion, spinach..." value="<?= h($search_q) ?>">
      <button type="submit">Search</button>
    </form>

    <div class="nav-actions">
      <a href="<?= BASE_URL ?>/index.php" class="nav-link">Shop</a>
      <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link">Admin</a>
      <button class="cart-btn" id="openCartBtn" type="button">
        🧺 Cart
        <span class="cart-count" id="cartCount"><?= cart_count() ?></span>
      </button>
    </div>
  </div>
</header>

<!-- Cart Drawer (interactive, loaded on every page) -->
<div class="cart-overlay" id="cartOverlay"></div>
<aside class="cart-drawer" id="cartDrawer" aria-label="Shopping cart">
  <div class="cart-drawer-head">
    <strong>Your Basket</strong>
    <button class="cart-close" id="closeCartBtn" aria-label="Close cart">✕</button>
  </div>
  <div class="cart-drawer-body" id="cartDrawerBody">
    <p style="color:#5B6656;">Loading your basket...</p>
  </div>
  <div class="cart-drawer-foot">
    <div class="cart-total-row">
      <span>Total</span>
      <span id="cartDrawerTotal"><?= SITE_CURRENCY ?>0</span>
    </div>
    <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-block">Proceed to Checkout</a>
  </div>
</aside>

<div class="toast" id="toast"></div>
