<?php
if (!isset($pdo)) { require_once __DIR__ . '/../config.php'; }

// Force a customer with a pending admin-reset or completed-reset password
// to set their own new password before using any other page.
if (is_customer_logged_in()) {
    $__script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!in_array($__script, ['change_password.php', 'logout.php'], true) && !empty(current_customer()['must_change_password'])) {
        redirect(BASE_URL . '/change_password.php');
    }
}

$search_q = trim($_GET['q'] ?? '');

$__meta_desc = $meta_description ?? SITE_DESCRIPTION;
$__page_title_full = (isset($page_title) ? h($page_title) . ' | ' : '') . SITE_NAME;
$__canonical = SITE_URL . strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $__page_title_full ?></title>
<meta name="description" content="<?= h($__meta_desc) ?>">
<link rel="canonical" href="<?= h($__canonical) ?>">

<!-- Open Graph / social sharing -->
<meta property="og:site_name" content="<?= h(SITE_NAME) ?>">
<meta property="og:title" content="<?= $__page_title_full ?>">
<meta property="og:description" content="<?= h($__meta_desc) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= h($__canonical) ?>">
<meta property="og:image" content="<?= h(SITE_URL) ?>/assets/images/icon-512.png">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= $__page_title_full ?>">
<meta name="twitter:description" content="<?= h($__meta_desc) ?>">
<meta name="twitter:image" content="<?= h(SITE_URL) ?>/assets/images/icon-512.png">

<link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/icon-192.png">

<!-- Installable app (Android/iOS "Add to Home Screen") -->
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<meta name="theme-color" content="#1F4D36">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/images/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= h(SITE_NAME) ?>">
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(() => {});
    });
  }
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=DM+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
<script>window.__VEGBASKET_BASE__ = "<?= BASE_URL ?>";</script>

<?php if (!isset($page_title) || $page_title === 'Fresh Vegetables Online'): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "GroceryStore",
  "name": "<?= h(SITE_NAME) ?>",
  "description": "<?= h(SITE_DESCRIPTION) ?>",
  "url": "<?= h(SITE_URL) ?>",
  "telephone": "+91 79723 81861",
  "email": "myvegbasketcare@gmail.com",
  "image": "<?= h(SITE_URL) ?>/assets/images/icon-512.png",
  "priceRange": "₹₹"
}
</script>
<?php endif; ?>
</head>
<body>

<style>
  /* Mobile menu: hidden on desktop, shown only on narrow screens where the
     horizontal nav links get hidden by the existing responsive CSS. This
     guarantees Login/My Orders/Track Order/Admin stay reachable on phones. */
  #mobileMenuToggle{ display:none; }
  @media (max-width: 560px){
    #mobileMenuToggle{
      display:flex; align-items:center; justify-content:center;
      width:38px; height:38px; background:rgba(255,255,255,0.12);
      border:1px solid rgba(255,255,255,0.3); border-radius:8px;
      color:#fff; font-size:1.2rem; cursor:pointer;
    }
  }
</style>

<header class="site-header">
  <div class="header-inner">
    <a href="<?= BASE_URL ?>/index.php" class="logo">
      <img src="<?= BASE_URL ?>/assets/images/logo-horizontal-white.png" alt="<?= h(SITE_NAME) ?>" style="height:42px; width:auto; display:block;">
    </a>

    <form class="search-form" action="<?= BASE_URL ?>/index.php" method="get">
      <input type="text" name="q" placeholder="Search tomato, onion, spinach..." value="<?= h($search_q) ?>">
      <button type="submit">Search</button>
    </form>

    <div class="nav-actions">
      <button type="button" id="installAppBtn" style="display:none; align-items:center; gap:6px; background:#E8934A; color:#1F2A17; border:none; padding:7px 14px; border-radius:999px; font-weight:700; font-size:0.85rem; cursor:pointer; font-family:inherit;">
        ⬇ Install App
      </button>
      <a href="<?= BASE_URL ?>/index.php" class="nav-link">Shop</a>
      <a href="<?= BASE_URL ?>/offers.php" class="nav-link">🎉 Offers</a>
      <a href="<?= BASE_URL ?>/track_order.php" class="nav-link">📍 Track Order</a>
      <a href="<?= BASE_URL ?>/enquiry.php" class="nav-link">💬 Enquire Now</a>
      <?php if (is_customer_logged_in()): $__cust = current_customer(); ?>
        <div id="accountMenu" style="position:relative;">
          <button type="button" id="accountMenuBtn" onclick="event.stopPropagation(); var d=document.getElementById('accountDropdown'); d.style.display = (d.style.display==='block') ? 'none' : 'block';" style="display:flex; align-items:center; gap:9px; background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.3); color:#fff; padding:6px 14px 6px 6px; border-radius:999px; font-family:inherit; font-weight:600; font-size:0.9rem; cursor:pointer;">
            <span style="width:28px; height:28px; border-radius:50%; background:#E8934A; color:#1F2A17; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.85rem; flex-shrink:0;">
              <?= h(strtoupper(mb_substr($__cust['name'] ?? '?', 0, 1))) ?>
            </span>
            <?= h(explode(' ', trim($__cust['name'] ?? 'Account'))[0]) ?>
            <span style="font-size:0.7rem; opacity:0.8;">▾</span>
          </button>
          <div id="accountDropdown" onclick="event.stopPropagation();" style="display:none; position:absolute; top:calc(100% + 10px); right:0; background:#fff; color:#26301F; border-radius:12px; box-shadow:0 14px 34px rgba(31,77,54,0.25); min-width:220px; padding:8px; z-index:999;">
            <div style="padding:10px 12px 12px; border-bottom:1px solid #E4E9DD; margin-bottom:6px;">
              <div style="font-weight:700; color:#1F4D36; font-size:0.95rem;"><?= h($__cust['name'] ?? '') ?></div>
              <div style="color:#5B6656; font-size:0.78rem; margin-top:2px; word-break:break-all;"><?= h($__cust['email'] ?? '') ?></div>
            </div>
            <a href="<?= BASE_URL ?>/my_account.php" onmouseover="this.style.background='#E8F0E2'" onmouseout="this.style.background='transparent'" style="display:flex; align-items:center; gap:9px; padding:10px 12px; border-radius:8px; color:#26301F; text-decoration:none; font-size:0.9rem; font-weight:600;">🧾 My Orders</a>
            <a href="<?= BASE_URL ?>/logout.php" onmouseover="this.style.background='#FCE8E6'" onmouseout="this.style.background='transparent'" style="display:flex; align-items:center; gap:9px; padding:10px 12px; border-radius:8px; color:#D64545; text-decoration:none; font-size:0.9rem; font-weight:600;">🚪 Log out</a>
          </div>
        </div>
        <script>
          // Inline on purpose so the account menu works even if assets/js/script.js
          // hasn't been re-uploaded yet or is stuck in a browser/host cache.
          document.addEventListener('click', function () {
            var d = document.getElementById('accountDropdown');
            if (d) d.style.display = 'none';
          });
          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
              var d = document.getElementById('accountDropdown');
              if (d) d.style.display = 'none';
            }
          });
        </script>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/login.php" class="nav-link">Login</a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link">Admin</a>
      <button class="cart-btn" id="openCartBtn" type="button">
        🧺 Cart
        <span class="cart-count" id="cartCount"><?= cart_count() ?></span>
      </button>
      <button type="button" id="mobileMenuToggle" aria-label="Menu">☰</button>
    </div>
  </div>

  <!-- Mobile menu panel: only ever visible on narrow screens (the toggle
       button itself is hidden on desktop), so this never affects desktop -->
  <div id="mobileMenuPanel" style="display:none; background:#1F4D36; border-top:1px solid rgba(255,255,255,0.15); padding:8px 20px 16px;">
    <button type="button" id="installAppBtnMobile" style="display:none; width:100%; align-items:center; gap:8px; background:#E8934A; color:#1F2A17; border:none; padding:12px 4px; border-radius:8px; font-weight:700; font-size:0.95rem; cursor:pointer; font-family:inherit; margin-bottom:8px; text-align:left; justify-content:flex-start; padding-left:12px;">⬇ Install App</button>
    <a href="<?= BASE_URL ?>/index.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">🛒 Shop</a>
    <a href="<?= BASE_URL ?>/offers.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">🎉 Offers</a>
    <a href="<?= BASE_URL ?>/track_order.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">📍 Track Order</a>
    <a href="<?= BASE_URL ?>/enquiry.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">💬 Enquire Now</a>
    <?php if (is_customer_logged_in()): ?>
      <a href="<?= BASE_URL ?>/my_account.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">🧾 My Orders</a>
      <a href="<?= BASE_URL ?>/logout.php" style="display:block; padding:12px 4px; color:#FFB4A8; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">🚪 Log out</a>
    <?php else: ?>
      <a href="<?= BASE_URL ?>/login.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600; border-bottom:1px solid rgba(255,255,255,0.1);">👤 Login / Sign up</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/admin/dashboard.php" style="display:block; padding:12px 4px; color:#fff; text-decoration:none; font-weight:600;">⚙️ Admin</a>
  </div>
</header>

<script>
  (function () {
    const toggle = document.getElementById('mobileMenuToggle');
    const panel = document.getElementById('mobileMenuPanel');
    toggle?.addEventListener('click', (e) => {
      e.stopPropagation();
      panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
    });
    document.addEventListener('click', (e) => {
      if (panel && panel.style.display === 'block' && !panel.contains(e.target) && e.target !== toggle) {
        panel.style.display = 'none';
      }
    });
  })();
</script>

<!-- "Add to Home Screen" instructions fallback — shown when the browser
     either has no native install prompt (iOS Safari, some Android browsers)
     or hasn't fired beforeinstallprompt yet (Chrome has its own engagement
     heuristics and may delay/skip it entirely). -->
<div id="iosInstallOverlay" style="display:none; position:fixed; inset:0; background:rgba(20,30,20,0.55); z-index:999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:18px; padding:26px 24px; max-width:320px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,0.3); text-align:center;">
    <h3 style="margin:0 0 14px;">Install MyVegBasket</h3>
    <p id="installInstructionsText" style="color:#26301F; margin:0 0 20px;">Tap the <strong>Share</strong> button <span style="font-size:1.1rem;">⬆️</span> in Safari's toolbar, then choose <strong>"Add to Home Screen."</strong></p>
    <a href="#" id="iosInstallClose" style="color:#3F8B52; font-weight:700; text-decoration:none;">Got it</a>
  </div>
</div>

<script>
  (function () {
    const installBtn = document.getElementById('installAppBtn');
    const installBtnMobile = document.getElementById('installAppBtnMobile');
    const allInstallBtns = [installBtn, installBtnMobile].filter(Boolean);
    const iosOverlay  = document.getElementById('iosInstallOverlay');
    const instructionsText = document.getElementById('installInstructionsText');
    let deferredPrompt = null;

    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isIOS = /iphone|ipad|ipod/i.test(window.navigator.userAgent);

    if (isStandalone) {
      // Already installed and running as an app — nothing to show.
      return;
    }

    function showInstallButtons() {
      allInstallBtns.forEach(btn => { btn.style.display = 'flex'; });
    }
    function hideInstallButtons() {
      allInstallBtns.forEach(btn => { btn.style.display = 'none'; });
    }

    async function handleInstallClick() {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
        hideInstallButtons();
        return;
      }
      // No native prompt available (iOS, or Chrome hasn't offered one yet) —
      // fall back to manual "Add to Home Screen" instructions so the option
      // is never a dead end for the user.
      if (!isIOS) {
        instructionsText.innerHTML = 'Open your browser menu <strong>(⋮ or ⋯)</strong> and tap <strong>"Install app"</strong> or <strong>"Add to Home screen."</strong>';
      }
      iosOverlay.style.display = 'flex';
    }

    allInstallBtns.forEach(btn => btn.addEventListener('click', handleInstallClick));

    document.getElementById('iosInstallClose')?.addEventListener('click', (e) => {
      e.preventDefault();
      iosOverlay.style.display = 'none';
    });
    iosOverlay.addEventListener('click', (e) => {
      if (e.target === iosOverlay) iosOverlay.style.display = 'none';
    });

    // Chrome/Edge/Android: capture the real install prompt whenever it fires
    // so the click handler above can use it instead of the manual fallback.
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      showInstallButtons();
    });

    window.addEventListener('appinstalled', hideInstallButtons);

    // Always show the Install App option — on iOS immediately (Safari never
    // fires beforeinstallprompt), and everywhere else after a short delay so
    // the button isn't permanently invisible for users/browsers where the
    // native prompt event never arrives (it'll just open the manual
    // instructions overlay for those cases instead of doing nothing).
    if (isIOS) {
      showInstallButtons();
    } else {
      setTimeout(showInstallButtons, 1200);
    }
  })();
</script>

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
