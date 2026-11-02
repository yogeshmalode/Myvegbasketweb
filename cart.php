<?php
require_once __DIR__ . '/config.php';
$page_title = 'My Cart';
reconcile_cart_stock($pdo);
$subtotal = cart_total();
$cart = $_SESSION['cart'] ?? [];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$deliveryCharge = get_delivery_charge($subtotal);
$grandTotal = $subtotal + $deliveryCharge;
$freeDeliveryRemaining = max(0, DELIVERY_FREE_THRESHOLD - $subtotal);

include __DIR__ . '/includes/header.php';
?>

<style>
  .cart-page{max-width:1120px;padding-top:34px;padding-bottom:60px}
  .cart-page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:20px}
  .cart-page-heading h1{margin:0;color:#173f2d;font-size:clamp(1.7rem,4vw,2.25rem)}
  .cart-page-heading p{margin:5px 0 0;color:#68756b}
  .cart-continue-link{color:#2c7040;font-weight:750;text-decoration:none}
  .cart-continue-link:hover{text-decoration:underline}
  .cart-layout{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:22px;align-items:start}
  .cart-lines{display:grid;gap:11px}
  .cart-line{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:14px;align-items:center;padding:16px;border:1px solid #e3ebe1;border-radius:16px;background:#fff;box-shadow:0 6px 20px rgba(31,77,54,.045);transition:transform .18s ease,border-color .18s ease}
  .cart-line:hover{transform:translateY(-1px);border-color:#c5d9bf}
  .cart-line-icon{display:grid;place-items:center;width:52px;height:52px;border-radius:14px;background:#f0f7ed;font-size:1.75rem}
  .cart-line-name{display:block;color:#23352a;font-weight:800}
  .cart-line-meta{display:block;margin-top:4px;color:#6b766e;font-size:.82rem}
  .cart-line-controls{display:flex;align-items:center;gap:9px;margin-top:10px}
  .cart-qty-control{display:flex;align-items:center;gap:4px;padding:3px;border:1px solid #e0e8dc;border-radius:10px;background:#fafcf9}
  .cart-qty-step{width:30px;height:30px;border:0;border-radius:7px;background:#edf5e9;color:#245a39;font-size:1rem;font-weight:800;cursor:pointer}
  .cart-qty-step:hover{background:#dcebd6}
  .cart-qty-input{width:38px;border:0;background:transparent;text-align:center;font:inherit;font-weight:750;appearance:textfield}
  .cart-qty-input::-webkit-inner-spin-button,.cart-qty-input::-webkit-outer-spin-button{appearance:none;margin:0}
  .cart-remove{border:0;background:transparent;color:#a33e36;font:inherit;font-size:.8rem;font-weight:700;cursor:pointer}
  .cart-remove:hover{text-decoration:underline}
  .cart-line-total{text-align:right}
  .cart-line-total strong{display:block;color:#1f4d36;font-size:1rem;font-variant-numeric:tabular-nums}
  .cart-summary{position:sticky;top:125px;padding:22px;border:1px solid #dfe9da;border-radius:18px;background:linear-gradient(155deg,#fff,#f8fcf6);box-shadow:0 12px 30px rgba(31,77,54,.09)}
  .cart-summary h2{margin:0 0 17px;color:#1f4d36;font-size:1.15rem}
  .cart-summary-row{display:flex;justify-content:space-between;gap:12px;margin:12px 0;color:#58665b;font-size:.91rem}
  .cart-summary-row strong{color:#293a2d}
  .cart-delivery-progress{height:8px;overflow:hidden;margin:13px 0 6px;border-radius:999px;background:#e8efe5}
  .cart-delivery-progress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#61ab66,#93c67b)}
  .cart-free-delivery-note{margin:0;color:#52705a;font-size:.78rem;line-height:1.45}
  .cart-summary-total{padding-top:14px;margin-top:14px;border-top:1px solid #e1e9de;font-size:1.02rem}
  .cart-summary-total strong{color:#1f4d36;font-size:1.3rem}
  .cart-summary .btn{min-height:47px;border-radius:11px}
  .cart-login-note{margin:13px 0 0;padding:11px;border-radius:11px;background:#eff6ec;color:#506553;font-size:.8rem;line-height:1.45;text-align:center}
  .cart-login-note a{color:#24623a;font-weight:750}
  .cart-flash{display:flex;align-items:center;gap:12px;margin-bottom:17px;padding:15px 17px;border:1px solid #cde2c4;border-radius:14px;background:#f2faee;color:#285b37}
  .cart-flash-icon{display:grid;place-items:center;flex:0 0 36px;height:36px;border-radius:50%;background:#dff0d8;font-size:1.15rem}
  .cart-update-message{margin:0 0 12px}
  .cart-empty{padding:42px 20px;border:1px dashed #c9dbc2;border-radius:20px;background:#fbfdf9;text-align:center}
  .cart-empty-icon{font-size:3rem}
  @media(max-width:820px){.cart-layout{grid-template-columns:1fr}.cart-summary{position:static}.cart-summary{order:-1}}
  @media(max-width:520px){.cart-page{padding-top:22px;padding-bottom:35px}.cart-line{grid-template-columns:44px minmax(0,1fr);gap:10px;padding:12px}.cart-line-icon{width:44px;height:44px;font-size:1.45rem}.cart-line-total{grid-column:2;display:flex;align-items:center;justify-content:space-between;text-align:left}.cart-line-total:before{content:'Item total';color:#6b766e;font-size:.78rem}.cart-line-controls{gap:7px}.cart-summary{padding:17px}.cart-summary{order:initial}}
  @media(prefers-reduced-motion:reduce){.cart-line{transition:none}}
</style>

<main class="container cart-page">
  <div class="cart-page-heading">
    <div>
      <h1>Your basket <span aria-hidden="true">🧺</span></h1>
      <p><?= count($cart) ?> product<?= count($cart) === 1 ? '' : 's' ?> ready for you</p>
    </div>
    <a class="cart-continue-link" href="<?= BASE_URL ?>/index.php">← Continue shopping</a>
  </div>

  <?php if ($flash): ?>
    <div class="cart-flash" role="status">
      <span class="cart-flash-icon" aria-hidden="true"><?= ($flash['type'] ?? '') === 'success' ? '✓' : '!' ?></span>
      <strong><?= h($flash['message']) ?></strong>
    </div>
  <?php endif; ?>

  <?php if (empty($cart)): ?>
    <div class="cart-empty">
      <div class="cart-empty-icon" aria-hidden="true">🥕</div>
      <h2>Your basket is waiting for something fresh</h2>
      <p style="color:#68756b;margin:0 0 20px;">Browse today’s vegetables and add your favourites.</p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Explore vegetables <span aria-hidden="true">→</span></a>
    </div>
  <?php else: ?>
    <p class="cart-update-message alert alert-error" id="cartUpdateMessage" role="alert" hidden></p>
    <div class="cart-layout">
      <div class="cart-lines" id="cartPageBody">
        <?php foreach ($cart as $item): ?>
          <?php $key = $item['key'] ?? $item['id']; ?>
          <article class="cart-line" data-id="<?= h($key) ?>">
            <span class="cart-line-icon" aria-hidden="true"><?= veg_emoji($item['name']) ?></span>
            <div class="cart-line-info">
              <strong class="cart-line-name"><?= h($item['name']) ?></strong>
              <span class="cart-line-meta"><?= SITE_CURRENCY ?><?= number_format($item['price'], 2) ?> / <?= h($item['unit']) ?></span>
              <div class="cart-line-controls">
                <span class="cart-qty-control" aria-label="Quantity of <?= h($item['name']) ?>">
                  <button type="button" class="cart-qty-step" data-qty-step="-1" aria-label="Decrease quantity">−</button>
                  <input type="number" class="cart-qty-input page-qty-input" data-id="<?= h($key) ?>" value="<?= (int)$item['qty'] ?>" min="1" aria-label="Quantity">
                  <button type="button" class="cart-qty-step" data-qty-step="1" aria-label="Increase quantity">+</button>
                </span>
                <button type="button" class="cart-remove page-remove-link" data-id="<?= h($key) ?>">Remove</button>
              </div>
            </div>
            <div class="cart-line-total">
              <strong><?= SITE_CURRENCY ?><?= number_format($item['price'] * $item['qty'], 2) ?></strong>
            </div>
          </article>
        <?php endforeach; ?>
      </div>

      <aside class="cart-summary" aria-label="Order summary">
        <h2>Order summary</h2>
        <div class="cart-summary-row"><span>Product subtotal</span><strong><?= SITE_CURRENCY ?><?= number_format($subtotal, 2) ?></strong></div>
        <div class="cart-summary-row">
          <span>Delivery <a href="#" id="deliveryInfoLink" aria-label="More about delivery charges" style="color:#3f8b52;text-decoration:none;">ⓘ</a></span>
          <strong><?= $deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge, 2) : 'FREE' ?></strong>
        </div>
        <div class="cart-delivery-progress" aria-hidden="true"><span style="width:<?= min(100, DELIVERY_FREE_THRESHOLD > 0 ? ($subtotal / DELIVERY_FREE_THRESHOLD) * 100 : 100) ?>%"></span></div>
        <p class="cart-free-delivery-note"><?= $freeDeliveryRemaining > 0 ? 'Add ' . SITE_CURRENCY . number_format($freeDeliveryRemaining, 2) . ' more for free delivery.' : 'You’ve unlocked free delivery!' ?></p>
        <div class="cart-summary-row cart-summary-total"><span>Total</span><strong><?= SITE_CURRENCY ?><?= number_format($grandTotal, 2) ?></strong></div>
        <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-block">Continue to checkout <span aria-hidden="true">→</span></a>
        <a href="<?= h(cart_whatsapp_url()) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-block" style="margin-top:9px;background:#25D366;color:#fff;">Order via WhatsApp</a>
        <?php if (!is_customer_logged_in()): ?>
          <p class="cart-login-note">Want to track orders and reorder easily? <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=cart.php">Sign in</a> or continue as guest at checkout.</p>
        <?php endif; ?>
      </aside>
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
  <?php endif; ?>
</main>

<script>
const cartPageBody = document.getElementById('cartPageBody');
const cartUpdateMessage = document.getElementById('cartUpdateMessage');

function updateCartItem(url, values) {
  const body = new URLSearchParams(values);
  fetch(window.__VEGBASKET_BASE__ + url, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: body.toString()
  })
    .then(function (response) {
      if (!response.ok) throw new Error('Cart update failed. Please try again.');
      return response.json();
    })
    .then(function (result) {
      if (!result.success) throw new Error(result.message || 'Cart update failed. Please try again.');
      location.reload();
    })
    .catch(function (error) {
      cartUpdateMessage.textContent = error.message;
      cartUpdateMessage.hidden = false;
    });
}

cartPageBody?.addEventListener('change', function (event) {
  if (event.target.matches('.page-qty-input')) {
    const quantity = Math.max(1, parseInt(event.target.value, 10) || 1);
    event.target.value = quantity;
    updateCartItem('/ajax/update_cart.php', {id: event.target.dataset.id, qty: quantity});
  }
});

cartPageBody?.addEventListener('click', function (event) {
  const removeButton = event.target.closest('.page-remove-link');
  if (removeButton) {
    updateCartItem('/ajax/remove_from_cart.php', {id: removeButton.dataset.id});
    return;
  }

  const stepButton = event.target.closest('.cart-qty-step');
  if (stepButton) {
    const quantityInput = stepButton.closest('.cart-qty-control').querySelector('.page-qty-input');
    quantityInput.value = Math.max(1, (parseInt(quantityInput.value, 10) || 1) + Number(stepButton.dataset.qtyStep));
    updateCartItem('/ajax/update_cart.php', {id: quantityInput.dataset.id, qty: quantityInput.value});
  }
});

const deliveryOverlay = document.getElementById('deliveryModalOverlay');
if (deliveryOverlay) {
  const closeModal = function () { deliveryOverlay.style.display = 'none'; };
  document.getElementById('deliveryInfoLink')?.addEventListener('click', function (event) {
    event.preventDefault();
    deliveryOverlay.style.display = 'flex';
  });
  document.getElementById('deliveryModalClose')?.addEventListener('click', function (event) {
    event.preventDefault();
    closeModal();
  });
  deliveryOverlay.addEventListener('click', function (event) {
    if (event.target === deliveryOverlay) closeModal();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeModal();
  });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
