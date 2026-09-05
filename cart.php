<?php
require_once __DIR__ . '/config.php';
$page_title = 'My Cart';
$cart = $_SESSION['cart'] ?? [];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$subtotal = cart_total();
$deliveryCharge = get_delivery_charge($subtotal);
$grandTotal = $subtotal + $deliveryCharge;

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Your Basket</h2>
    <p><?= count($cart) ?> item(s) in your basket</p>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div>
  <?php endif; ?>


  <?php if (empty($cart)): ?>
    <div class="form-card" style="text-align:center;">
      <p style="color:#5B6656; margin-bottom:20px;">Your basket is empty. Let's fix that! 🥕</p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Browse vegetables</a>
    </div>
  <?php else: ?>
    <div class="table-wrap" style="margin-bottom:26px;">
      <table>
        <thead>
          <tr><th>Item</th><th>Price</th><th>Quantity</th><th>Subtotal</th><th></th></tr>
        </thead>
        <tbody id="cartPageBody">
          <?php foreach ($cart as $item): ?>
            <?php $key = $item['key'] ?? $item['id']; ?>
            <tr data-id="<?= h($key) ?>">
              <td><?= veg_emoji($item['name']) ?> <?= h($item['name']) ?></td>
              <td><?= SITE_CURRENCY ?><?= number_format($item['price'],2) ?> / <?= h($item['unit']) ?></td>
              <td>
                <input type="number" class="page-qty-input" data-id="<?= h($key) ?>" value="<?= (int)$item['qty'] ?>" min="1" style="width:60px; padding:6px; border:1px solid #D9E0CD; border-radius:6px;">
              </td>
              <td><?= SITE_CURRENCY ?><?= number_format($item['price']*$item['qty'],2) ?></td>
              <td><a href="#" class="action-link delete page-remove-link" data-id="<?= h($key) ?>">Remove</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="form-card" style="max-width:360px; margin-left:auto;">
      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#5B6656;">
        <span>Subtotal</span>
        <span><?= SITE_CURRENCY ?><?= number_format($subtotal,2) ?></span>
      </div>
      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#5B6656; margin-top:4px;">
        <span>
          Delivery charge
          <a href="#" id="deliveryInfoLink" style="font-size:0.8rem; color:#3F8B52; text-decoration:underline;">ⓘ</a>
        </span>
        <span><?= $deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge,2) : 'FREE' ?></span>
      </div>
      <div class="cart-total-row" style="margin-top:10px; padding-top:10px; border-top:1px solid #E4E9DD;">
        <span>Total</span>
        <span><?= SITE_CURRENCY ?><?= number_format($grandTotal,2) ?></span>
      </div>
      <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-block" style="margin-top:14px;">Proceed to Checkout</a>
    </div>

    <!-- Delivery charge info popup -->
    <div id="deliveryModalOverlay" style="display:none; position:fixed; inset:0; background:rgba(20,30,20,0.55); z-index:999; align-items:center; justify-content:center;">
      <div style="background:#fff; border-radius:18px; padding:26px 28px; max-width:340px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,0.3);">
        <h3 style="margin:0 0 14px;">Delivery charge</h3>
        <p style="margin:0 0 10px; color:#26301F;"><?= SITE_CURRENCY ?><?= number_format(DELIVERY_CHARGE,2) ?> for orders below <?= SITE_CURRENCY ?><?= number_format(DELIVERY_FREE_THRESHOLD,2) ?></p>
        <p style="margin:0 0 16px; color:#26301F;"><?= SITE_CURRENCY ?>0 for orders <?= SITE_CURRENCY ?><?= number_format(DELIVERY_FREE_THRESHOLD,2) ?> and above</p>
        <div style="border-top:1px solid #E4E9DD; padding-top:14px; text-align:center;">
          <a href="#" id="deliveryModalClose" style="color:#3F8B52; font-weight:700; text-decoration:none;">Sounds good</a>
        </div>
      </div>
    </div>
    <?php if (!is_customer_logged_in()): ?>
    <!-- Login prompt popup (guests only) -->
    <div id="loginPromptOverlay" style="display:none; position:fixed; inset:0; background:rgba(20,30,20,0.55); z-index:998; align-items:center; justify-content:center;">
      <div style="background:#fff; border-radius:18px; padding:26px 28px; max-width:360px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,0.3); text-align:center;">
        <div style="font-size:2.2rem; margin-bottom:8px;">👋</div>
        <h3 style="margin:0 0 10px;">Log in for faster checkout</h3>
        <p style="color:#5B6656; margin:0 0 20px; font-size:0.92rem;">Save your details, track your orders, and reorder in one tap.</p>
        <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=cart.php" class="btn btn-primary btn-block" style="margin-bottom:10px;">📧 Login with an email code</a>
        <a href="#" id="loginPromptSkip" style="display:block; color:#5B6656; font-size:0.85rem; text-decoration:underline;">Continue as guest</a>
      </div>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
document.getElementById('cartPageBody')?.addEventListener('change', (e) => {
  if (e.target.classList.contains('page-qty-input')) {
    const id = e.target.dataset.id, qty = e.target.value;
    fetch(window.__VEGBASKET_BASE__ + '/ajax/update_cart.php', {
      method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `id=${encodeURIComponent(id)}&qty=${encodeURIComponent(qty)}`
    }).then(() => location.reload());
  }
});
document.getElementById('cartPageBody')?.addEventListener('click', (e) => {
  if (e.target.classList.contains('page-remove-link')) {
    e.preventDefault();
    const id = e.target.dataset.id;
    fetch(window.__VEGBASKET_BASE__ + '/ajax/remove_from_cart.php', {
      method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `id=${encodeURIComponent(id)}`
    }).then(() => location.reload());
  }
});

// Delivery charge info popup: shown automatically the first time in this
// browser session, and reopenable any time via the ⓘ link next to the
// delivery charge line.
const deliveryOverlay = document.getElementById('deliveryModalOverlay');
const loginOverlay = document.getElementById('loginPromptOverlay');

function maybeShowLoginPrompt() {
  if (loginOverlay && !sessionStorage.getItem('loginPromptSeen')) {
    loginOverlay.style.display = 'flex';
    sessionStorage.setItem('loginPromptSeen', '1');
  }
}

if (deliveryOverlay) {
  const openModal = () => deliveryOverlay.style.display = 'flex';
  const closeModal = () => { deliveryOverlay.style.display = 'none'; maybeShowLoginPrompt(); };

  document.getElementById('deliveryInfoLink')?.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
  document.getElementById('deliveryModalClose')?.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
  deliveryOverlay.addEventListener('click', (e) => { if (e.target === deliveryOverlay) closeModal(); });

  if (!sessionStorage.getItem('deliveryChargeSeen')) {
    openModal();
    sessionStorage.setItem('deliveryChargeSeen', '1');
  } else {
    // Delivery popup already seen in a past visit this session — go
    // straight to (maybe) showing the login prompt instead.
    maybeShowLoginPrompt();
  }
}

if (loginOverlay) {
  document.getElementById('loginPromptSkip')?.addEventListener('click', (e) => {
    e.preventDefault();
    loginOverlay.style.display = 'none';
  });
  loginOverlay.addEventListener('click', (e) => {
    if (e.target === loginOverlay) loginOverlay.style.display = 'none';
  });
  // If there's no delivery popup on this page load for some reason,
  // still give the login prompt a chance to show.
  if (!deliveryOverlay) maybeShowLoginPrompt();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
