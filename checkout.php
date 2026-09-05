<?php
require_once __DIR__ . '/config.php';
$page_title = 'Checkout';
$cart = $_SESSION['cart'] ?? [];

if (empty($cart)) {
    redirect(BASE_URL . '/cart.php');
}

$subtotal = cart_total();
$deliveryCharge = get_delivery_charge($subtotal);

// If a coupon was applied earlier in this session, re-validate it against
// the CURRENT cart every time — never trust a stale discount amount if
// items changed since it was applied.
$appliedCoupon = null;
$discountAmount = 0;
if (!empty($_SESSION['applied_coupon_code'])) {
    $check = validate_coupon($pdo, $_SESSION['applied_coupon_code'], $subtotal);
    if ($check['valid']) {
        $appliedCoupon = $check['offer'];
        $discountAmount = $check['discount'];
        if ($check['free_delivery']) $deliveryCharge = 0;
    } else {
        unset($_SESSION['applied_coupon_code']);
    }
}

$total = $subtotal - $discountAmount + $deliveryCharge;
$customer = current_customer();
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:760px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Checkout</h2>
    <p>Enter your delivery details, then pay via UPI.</p>
  </div>

  <?php if (!$customer): ?>
    <div class="alert alert-success" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
      <span>Have an account? Log in to save this order to your order history.</span>
      <a href="<?= BASE_URL ?>/login.php?redirect=checkout.php" style="color:#1F4D36; font-weight:700;">Log in</a>
    </div>
  <?php endif; ?>

  <div id="checkoutAlert"></div>

  <!-- STEP 1: delivery details -->
  <div class="form-card" id="detailsStep">
    <form id="checkoutForm">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" value="<?= h($customer['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= h($customer['email'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" value="<?= h($customer['phone'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="address">Delivery address</label>
        <textarea id="address" name="address" rows="3" required></textarea>
      </div>

      <div class="form-group">
        <label for="couponCode">Coupon code (optional)</label>
        <div style="display:flex; gap:8px;">
          <input type="text" id="couponCode" placeholder="e.g. WELCOME10" value="<?= h($appliedCoupon['coupon_code'] ?? '') ?>" style="flex:1; text-transform:uppercase;" <?= $appliedCoupon ? 'readonly' : '' ?>>
          <?php if ($appliedCoupon): ?>
            <button type="button" id="removeCouponBtn" class="btn" style="background:#FCE8E6; color:#9A2E24; border:1px solid #F1B6AF; white-space:nowrap;">Remove</button>
          <?php else: ?>
            <button type="button" id="applyCouponBtn" class="btn" style="background:#fff; border:1px solid #E4E9DD; white-space:nowrap;">Apply</button>
          <?php endif; ?>
        </div>
        <div id="couponFeedback" style="margin-top:6px; font-size:0.85rem;">
          <?php if ($appliedCoupon): ?>
            <span style="color:#1F4D36; font-weight:600;">✓ "<?= h($appliedCoupon['coupon_code']) ?>" applied</span>
          <?php endif; ?>
        </div>
        <p style="margin-top:4px;"><a href="<?= BASE_URL ?>/offers.php" style="font-size:0.8rem; color:#3F8B52;">See available offers →</a></p>
      </div>

      <div class="table-wrap" style="margin-bottom:20px;">
        <table>
          <thead><tr><th>Item</th><th>Qty</th><th>Subtotal</th></tr></thead>
          <tbody>
            <?php foreach ($cart as $item): ?>
              <tr>
                <td><?= veg_emoji($item['name']) ?> <?= h($item['name']) ?></td>
                <td><?= (int)$item['qty'] ?> <?= h($item['unit']) ?></td>
                <td><?= SITE_CURRENCY ?><?= number_format($item['price']*$item['qty'],2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#5B6656;">
        <span>Subtotal</span>
        <span id="rowSubtotal"><?= SITE_CURRENCY ?><?= number_format($subtotal,2) ?></span>
      </div>
      <?php if ($discountAmount > 0): ?>
      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#1F4D36;" id="discountRow">
        <span>Discount</span>
        <span id="rowDiscount">−<?= SITE_CURRENCY ?><?= number_format($discountAmount,2) ?></span>
      </div>
      <?php else: ?>
      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#1F4D36; display:none;" id="discountRow">
        <span>Discount</span>
        <span id="rowDiscount"></span>
      </div>
      <?php endif; ?>
      <div class="cart-total-row" style="font-weight:400; font-size:0.9rem; color:#5B6656; margin-top:4px;">
        <span>
          Delivery charge
          <a href="#" id="deliveryInfoLink" style="font-size:0.8rem; color:#3F8B52; text-decoration:underline;">ⓘ</a>
        </span>
        <span id="rowDelivery"><?= $deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge,2) : 'FREE' ?></span>
      </div>
      <div class="cart-total-row" style="margin-top:10px; padding-top:10px; border-top:1px solid #E4E9DD;">
        <span>Total payable</span>
        <span id="rowTotal"><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span>
      </div>

      <button type="submit" class="btn btn-primary btn-block" id="payBtn">
        Proceed to pay <span id="payBtnAmount"><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span>
      </button>
    </form>
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

  <!-- STEP 2: UPI QR code (shown after step 1 is submitted) -->
  <div class="form-card" id="qrStep" style="display:none; text-align:center;">
    <h3 style="margin-top:0;">Scan &amp; pay with any UPI app</h3>
    <p style="color:#5B6656;">GPay, PhonePe, Paytm — scan the code below and pay the exact amount.</p>

    <div style="max-width:280px; margin:0 auto 18px;">
      <div class="cart-total-row" style="font-weight:400; font-size:0.85rem; color:#5B6656;">
        <span>Subtotal</span>
        <span><?= SITE_CURRENCY ?><?= number_format($subtotal,2) ?></span>
      </div>
      <?php if ($discountAmount > 0): ?>
      <div class="cart-total-row" style="font-weight:400; font-size:0.85rem; color:#1F4D36;">
        <span>Discount (<?= h($appliedCoupon['coupon_code']) ?>)</span>
        <span>−<?= SITE_CURRENCY ?><?= number_format($discountAmount,2) ?></span>
      </div>
      <?php endif; ?>
      <div class="cart-total-row" style="font-weight:400; font-size:0.85rem; color:#5B6656;">
        <span>Delivery charge</span>
        <span><?= $deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge,2) : 'FREE' ?></span>
      </div>
      <div class="cart-total-row" style="margin-top:6px; padding-top:6px; border-top:1px solid #E4E9DD;">
        <span>Amount to pay</span>
        <span><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span>
      </div>
    </div>

    <img src="<?= BASE_URL . PAYMENT_QR_IMAGE ?>" alt="UPI QR code" style="max-width:280px; width:100%; border-radius:12px; border:1px solid #E4E9DD; margin-bottom:8px;">
    <p style="color:#5B6656; font-size:0.85rem; margin-bottom:24px;">Pay to: <?= h(PAYMENT_QR_NAME) ?></p>

    <p style="font-weight:600; margin-bottom:14px;">Have you completed the payment?</p>
    <div style="display:flex; gap:12px; max-width:420px; margin:0 auto;">
      <button type="button" class="btn btn-primary" id="confirmPaidBtn" style="flex:1;">Yes, I've paid</button>
      <button type="button" class="btn" id="notPaidBtn" style="flex:1; background:#fff; border:1px solid #E4E9DD;">Not yet</button>
    </div>
    <p style="color:#5B6656; font-size:0.8rem; margin-top:14px;">
      Your order (#<span id="qrOrderId"></span>) is saved. We'll confirm it manually once we verify the payment in our UPI app.
    </p>
  </div>
</div>

<script>
const checkoutForm = document.getElementById('checkoutForm');
const detailsStep  = document.getElementById('detailsStep');
const qrStep       = document.getElementById('qrStep');
const alertBox     = document.getElementById('checkoutAlert');
const payBtn       = document.getElementById('payBtn');
const confirmBtn   = document.getElementById('confirmPaidBtn');
const notPaidBtn   = document.getElementById('notPaidBtn');
let currentOrderId = null;

checkoutForm.addEventListener('submit', function (e) {
  e.preventDefault();
  alertBox.innerHTML = '';
  payBtn.disabled = true;
  payBtn.textContent = 'Saving your order...';

  const formData = new FormData(this);

  fetch(window.__VEGBASKET_BASE__ + '/ajax/place_order.php', {
    method: 'POST',
    body: formData
  })
    .then(r => r.json())
    .then(res => {
      payBtn.disabled = false;
      payBtn.textContent = "Proceed to pay <?= SITE_CURRENCY ?><?= number_format($total,2) ?>";

      if (!res.success) {
        alertBox.innerHTML = '<div class="alert alert-error">' + (res.message || 'Could not save order.') + '</div>';
        return;
      }

      currentOrderId = res.order_id;
      document.getElementById('qrOrderId').textContent = res.order_id;
      detailsStep.style.display = 'none';
      qrStep.style.display = 'block';
      qrStep.scrollIntoView({ behavior: 'smooth' });
    })
    .catch(() => {
      payBtn.disabled = false;
      payBtn.textContent = "Proceed to pay <?= SITE_CURRENCY ?><?= number_format($total,2) ?>";
      alertBox.innerHTML = '<div class="alert alert-error">Something went wrong. Please try again.</div>';
    });
});

notPaidBtn.addEventListener('click', function () {
  alertBox.innerHTML = '<div class="alert alert-success">No problem — scan the QR code and pay, then tap "Yes, I\'ve paid".</div>';
  alertBox.scrollIntoView({ behavior: 'smooth' });
});

confirmBtn.addEventListener('click', function () {
  if (!currentOrderId) return;
  confirmBtn.disabled = true;
  confirmBtn.textContent = 'Confirming...';

  const data = new FormData();
  data.append('order_id', currentOrderId);

  fetch(window.__VEGBASKET_BASE__ + '/ajax/confirm_payment.php', {
    method: 'POST',
    body: data
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        window.location.href = window.__VEGBASKET_BASE__ + '/order_success.php?order_id=' + res.order_id;
      } else {
        confirmBtn.disabled = false;
        confirmBtn.textContent = "Yes, I've paid";
        alertBox.innerHTML = '<div class="alert alert-error">' + (res.message || 'Could not confirm payment.') + '</div>';
      }
    })
    .catch(() => {
      confirmBtn.disabled = false;
      confirmBtn.textContent = "Yes, I've paid";
      alertBox.innerHTML = '<div class="alert alert-error">Something went wrong. Please try again.</div>';
    });
});

// Delivery charge info popup
const deliveryOverlay = document.getElementById('deliveryModalOverlay');
if (deliveryOverlay) {
  const openModal = () => deliveryOverlay.style.display = 'flex';
  const closeModal = () => deliveryOverlay.style.display = 'none';

  document.getElementById('deliveryInfoLink')?.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
  document.getElementById('deliveryModalClose')?.addEventListener('click', (e) => { e.preventDefault(); closeModal(); });
  deliveryOverlay.addEventListener('click', (e) => { if (e.target === deliveryOverlay) closeModal(); });

  if (!sessionStorage.getItem('deliveryChargeSeen')) {
    openModal();
    sessionStorage.setItem('deliveryChargeSeen', '1');
  }
}

// Coupon apply / remove
document.getElementById('applyCouponBtn')?.addEventListener('click', function () {
  const code = document.getElementById('couponCode').value.trim();
  const feedback = document.getElementById('couponFeedback');
  if (!code) {
    feedback.innerHTML = '<span style="color:#9A2E24;">Enter a code first.</span>';
    return;
  }
  this.disabled = true;
  this.textContent = 'Applying...';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/apply_coupon.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'code=' + encodeURIComponent(code)
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        // Simplest reliable way to reflect the new totals everywhere
        // (details form, QR step, pay button) is to reload with the
        // coupon now stored server-side in the session.
        location.reload();
      } else {
        this.disabled = false;
        this.textContent = 'Apply';
        feedback.innerHTML = '<span style="color:#9A2E24;">' + (res.message || 'Invalid coupon.') + '</span>';
      }
    })
    .catch(() => {
      this.disabled = false;
      this.textContent = 'Apply';
      feedback.innerHTML = '<span style="color:#9A2E24;">Something went wrong, please try again.</span>';
    });
});

document.getElementById('removeCouponBtn')?.addEventListener('click', function () {
  this.disabled = true;
  fetch(window.__VEGBASKET_BASE__ + '/ajax/apply_coupon.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'remove=1'
  }).then(() => location.reload());
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
