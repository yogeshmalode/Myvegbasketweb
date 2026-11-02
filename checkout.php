<?php
require_once __DIR__ . '/config.php';
$page_title = 'Checkout';
reconcile_cart_stock($pdo);
if (empty($_SESSION['cart'])) {
    redirect(BASE_URL . '/cart.php');
}

$subtotal = cart_total();
$cart = $_SESSION['cart'] ?? [];
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
$customerType = $customer['customer_type'] ?? 'retail';
$creditAvailable = 0;
if ($customer && in_array($customerType, ['hotel', 'shop'], true) && (float)$customer['credit_limit'] > 0) {
    $creditAvailable = max(0, (float)$customer['credit_limit'] - get_customer_balance($pdo, $customer['id']));
}
include __DIR__ . '/includes/header.php';
?>

<style>
  #detailsStep{padding:0;border:0;background:transparent;box-shadow:none}
  #checkoutAlert:empty{display:none}
  .checkout-page{max-width:1120px;padding-top:36px;padding-bottom:56px}
  .checkout-heading{margin:0 0 20px}
  .checkout-heading h2{margin-bottom:4px}
  .checkout-heading p{margin:0;color:#5B6656}
  .checkout-layout{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(300px,.8fr);gap:22px;align-items:start}
  .checkout-details,.checkout-summary{background:#fff;border:1px solid #E4E9DD;border-radius:16px;padding:24px;box-shadow:0 8px 26px rgba(31,77,54,.06)}
  .checkout-summary{position:sticky;top:18px}
  .checkout-title{display:flex;align-items:center;gap:10px;margin:0 0 18px;font-size:1.08rem}
  .checkout-step{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#EAF3E6;color:#1F4D36;font-size:.85rem;font-weight:800}
  .checkout-contact,.checkout-schedule{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}
  .checkout-page .form-group{margin-bottom:14px}
  .checkout-page .form-group label{margin-bottom:5px}
  .checkout-page .form-group input,.checkout-page .form-group textarea,.checkout-page .form-group select{min-height:44px}
  .checkout-page textarea{resize:vertical}
  .checkout-summary h3{margin:0 0 12px;font-size:1.12rem}
  .checkout-items{list-style:none;padding:0;margin:0 0 14px}
  .checkout-item{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #EEF1EA;font-size:.9rem}
  .checkout-item-name{font-weight:600}
  .checkout-item-meta{display:block;color:#697466;font-size:.78rem;margin-top:3px}
  .checkout-prices{padding-top:4px}
  .checkout-prices .cart-total-row{font-family:inherit;font-size:.9rem;font-weight:500;margin-bottom:10px}
  .checkout-total{padding-top:12px;border-top:1px solid #E4E9DD;font-size:1.05rem!important;font-weight:800!important}
  .checkout-disclosure{margin:12px 0;border-top:1px solid #EEF1EA;padding-top:12px}
  .checkout-disclosure summary{cursor:pointer;color:#285F3D;font-weight:700;font-size:.9rem}
  .checkout-disclosure[open] summary{margin-bottom:12px}
  .checkout-payment-options{display:grid;gap:9px}
  .checkout-payment-option{display:flex!important;align-items:center;gap:9px;padding:11px;border:1px solid #E4E9DD;border-radius:10px;background:#FAFCF8}
  .checkout-payment-option input{width:auto!important;min-height:auto!important;margin:0}
  .checkout-submit{min-height:50px;margin-top:10px;font-size:1rem}
  @media(max-width:800px){.checkout-layout{grid-template-columns:1fr}.checkout-summary{position:static;order:2}.checkout-details{order:1}}
  @media(max-width:520px){.checkout-page{padding:24px 14px 40px}.checkout-details,.checkout-summary{padding:18px;border-radius:13px}.checkout-contact,.checkout-schedule{grid-template-columns:1fr;gap:0}.checkout-heading{margin-bottom:16px}}
</style>

<div class="container checkout-page">
  <div class="section-head checkout-heading">
    <h2>Checkout</h2>
    <p>Just the details we need to deliver your order.</p>
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
    <form id="checkoutForm" class="checkout-layout">
      <section class="checkout-details" aria-labelledby="deliveryDetailsTitle">
      <h3 class="checkout-title" id="deliveryDetailsTitle"><span class="checkout-step">1</span> Delivery details</h3>
      <div class="checkout-contact">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" autocomplete="name" placeholder="Your name" value="<?= h($customer['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" inputmode="numeric" autocomplete="tel" placeholder="10-digit mobile number" value="<?= h($customer['phone'] ?? '') ?>" required>
      </div>
      </div>
      <?php if (!$customer): ?>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="email" placeholder="name@example.com" required>
      </div>
      <?php else: ?>
        <input type="hidden" id="email" name="email" value="<?= h($customer['email'] ?? '') ?>">
      <?php endif; ?>
      <div class="form-group">
        <label for="address">Delivery address</label>
        <textarea id="address" name="address" rows="2" autocomplete="street-address" placeholder="House no., building, street" aria-describedby="addressAreaHelp" required></textarea>
        <small id="addressAreaHelp" style="color:#5B6656;">Type a supported area or PIN to select it automatically, or choose your area below.</small>
      </div>
      <div class="form-group">
        <label for="delivery_area">Delivery area</label>
        <select id="delivery_area" name="delivery_area" aria-describedby="deliveryAreaHelp" required>
          <option value="">Choose your locality</option>
          <?php foreach (get_serviceable_delivery_areas() as $areaName => $areaPin): ?>
            <option value="<?= h($areaName) ?>" data-pincode="<?= h($areaPin) ?>"><?= h($areaName) ?> — <?= h($areaPin) ?></option>
          <?php endforeach; ?>
        </select>
        <small id="deliveryAreaHelp" role="status" aria-live="polite" style="color:#5B6656;">Choose your area. The matching PIN code will appear below.</small>
        <div class="form-group" id="pincodeDisplayWrap" style="display:none;margin-top:10px;">
          <label for="pincode">Delivery PIN code</label>
          <input type="text" id="pincode" name="pincode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="postal-code" readonly required>
          <small style="color:#5B6656;">PIN code is filled from the selected locality.</small>
        </div>
      </div>
      <div class="checkout-schedule">
      <div class="form-group">
        <label for="delivery_date">Delivery date</label>
        <input type="date" id="delivery_date" name="delivery_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label for="delivery_slot">Time slot</label>
        <select id="delivery_slot" name="delivery_slot" required>
          <option value="">Select a time slot</option>
          <option value="8 AM - 10 AM">8 AM - 10 AM</option>
          <option value="10 AM - 12 PM">10 AM - 12 PM</option>
          <option value="12 PM - 2 PM">12 PM - 2 PM</option>
          <option value="2 PM - 5 PM">2 PM - 5 PM</option>
          <option value="5 PM - 7 PM">5 PM - 7 PM</option>
          <option value="7 PM - 9 PM">7 PM - 9 PM</option>
        </select>
      </div>
      </div>

      <?php if ($creditAvailable > 0): ?>
      <div class="form-group">
        <label>Payment</label>
        <div class="checkout-payment-options">
          <label class="checkout-payment-option"><input type="radio" name="payment_method" value="upi_qr" checked> Pay by UPI</label>
          <label class="checkout-payment-option"><input type="radio" name="payment_method" value="credit"> Bill me later</label>
        </div>
        <small style="color:#5B6656;">Available credit: <?= SITE_CURRENCY ?><?= number_format($creditAvailable, 2) ?></small>
      </div>
      <?php endif; ?>

      <details class="checkout-disclosure">
        <summary>Add delivery instructions (optional)</summary>
        <div class="form-group" style="margin:0;">
          <label for="customer_note">Delivery note</label>
          <textarea id="customer_note" name="customer_note" rows="2" maxlength="500" placeholder="Anything the delivery person should know?"></textarea>
        </div>
      </details>
      </section>

      <aside class="checkout-summary" aria-labelledby="checkoutSummaryTitle">
        <h3 id="checkoutSummaryTitle">Your order <span style="color:#697466;font-size:.84rem;font-weight:500;">(<?= count($cart) ?> items)</span></h3>
        <ul class="checkout-items">
          <?php foreach ($cart as $item): ?>
            <li class="checkout-item">
              <span><span class="checkout-item-name"><?= veg_emoji($item['name']) ?> <?= h($item['name']) ?></span><span class="checkout-item-meta"><?= (int)$item['qty'] ?> <?= h($item['unit']) ?> × <?= SITE_CURRENCY ?><?= number_format($item['price'], 2) ?></span></span>
              <strong><?= SITE_CURRENCY ?><?= number_format($item['price'] * $item['qty'], 2) ?></strong>
            </li>
          <?php endforeach; ?>
        </ul>

        <details class="checkout-disclosure">
          <summary><?= $appliedCoupon ? 'Coupon applied: ' . h($appliedCoupon['coupon_code']) : 'Have a coupon?' ?></summary>
          <div class="form-group" style="margin:0;">
            <label for="couponCode">Coupon code</label>
            <div style="display:flex;gap:8px;">
              <input type="text" id="couponCode" placeholder="Enter code" value="<?= h($appliedCoupon['coupon_code'] ?? '') ?>" style="flex:1;text-transform:uppercase;" <?= $appliedCoupon ? 'readonly' : '' ?>>
              <?php if ($appliedCoupon): ?>
                <button type="button" id="removeCouponBtn" class="btn" style="background:#FCE8E6;color:#9A2E24;border:1px solid #F1B6AF;white-space:nowrap;">Remove</button>
              <?php else: ?>
                <button type="button" id="applyCouponBtn" class="btn" style="background:#fff;border:1px solid #E4E9DD;white-space:nowrap;">Apply</button>
              <?php endif; ?>
            </div>
            <div id="couponFeedback" style="margin-top:6px;font-size:.85rem;">
              <?php if ($appliedCoupon): ?><span style="color:#1F4D36;font-weight:600;">✓ Coupon applied</span><?php endif; ?>
            </div>
            <a href="<?= BASE_URL ?>/offers.php" style="display:inline-block;margin-top:8px;font-size:.82rem;color:#3F8B52;">See offers</a>
          </div>
        </details>
        <div class="checkout-prices">
          <div class="cart-total-row"><span>Subtotal</span><span id="rowSubtotal"><?= SITE_CURRENCY ?><?= number_format($subtotal,2) ?></span></div>
          <div class="cart-total-row" id="discountRow" style="<?= $discountAmount > 0 ? '' : 'display:none;' ?>color:#1F4D36;"><span>Discount</span><span id="rowDiscount"><?= $discountAmount > 0 ? '−' . SITE_CURRENCY . number_format($discountAmount,2) : '' ?></span></div>
          <div class="cart-total-row"><span>Delivery</span><span id="rowDelivery"><?= $deliveryCharge > 0 ? SITE_CURRENCY . number_format($deliveryCharge,2) : 'FREE' ?></span></div>
          <div class="cart-total-row checkout-total"><span>Total</span><span id="rowTotal"><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span></div>
        </div>
        <button type="submit" class="btn btn-primary btn-block checkout-submit" id="payBtn">
          Place order · <span id="payBtnAmount"><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span>
        </button>
        <small style="display:block;text-align:center;margin-top:10px;color:#697466;">Payment instructions appear after order placement.</small>
      </aside>
    </form>
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

const deliveryAreaSelect = document.getElementById('delivery_area');
const pincodeInput = document.getElementById('pincode');
const pincodeDisplayWrap = document.getElementById('pincodeDisplayWrap');
const deliveryAddressInput = document.getElementById('address');
const deliveryAreaAliases = <?= json_encode(get_serviceable_delivery_area_aliases(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const selectDeliveryArea = function (areaName, message) {
  deliveryAreaSelect.value = areaName;
  const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
  pincodeInput.value = option && option.value ? option.dataset.pincode || '' : '';
  pincodeInput.disabled = !pincodeInput.value;
  pincodeDisplayWrap.style.display = pincodeInput.value ? 'block' : 'none';
  document.getElementById('deliveryAreaHelp').textContent = message || '';
};
deliveryAreaSelect.addEventListener('change', function () {
  const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
  pincodeInput.value = option.dataset.pincode || '';
  pincodeInput.disabled = !pincodeInput.value;
  pincodeDisplayWrap.style.display = pincodeInput.value ? 'block' : 'none';
  document.getElementById('deliveryAreaHelp').textContent = option.value
    ? 'Delivery available for ' + option.value + ' (' + pincodeInput.value + ').'
    : 'Select your delivery area; its PIN code will be shown below. An area or PIN typed in the address may be detected automatically.';
});
pincodeInput.disabled = !deliveryAreaSelect.value;

deliveryAddressInput.addEventListener('input', function () {
  const address = deliveryAddressInput.value.toLocaleLowerCase();
  const matchedAreas = Object.entries(deliveryAreaAliases)
    .filter(function (entry) {
      return entry[1].some(function (alias) { return address.includes(alias.toLocaleLowerCase()); });
    })
    .map(function (entry) { return entry[0]; });
  const pinMatches = address.match(/(?:^|\D)(\d{6})(?!\d)/g) || [];
  const enteredPin = pinMatches.length ? pinMatches[pinMatches.length - 1].replace(/\D/g, '') : '';
  let candidateAreas = matchedAreas;
  if (enteredPin) {
    const areasForPin = Array.from(deliveryAreaSelect.options).filter(function (option) {
      return option.dataset.pincode === enteredPin;
    }).map(function (option) { return option.value; });
    if (!areasForPin.length) {
      selectDeliveryArea('', 'Sorry, we do not deliver to this PIN code yet.');
      return;
    }
    candidateAreas = matchedAreas.length
      ? matchedAreas.filter(function (areaName) { return areasForPin.includes(areaName); })
      : areasForPin;
    if (!candidateAreas.length) {
      selectDeliveryArea('', 'The locality and PIN code do not match. Please check the address or choose the correct area.');
      return;
    }
  }

  candidateAreas = Array.from(new Set(candidateAreas));
  if (!enteredPin && candidateAreas.length === 0) {
    const selectedArea = deliveryAreaSelect.value;
    if (selectedArea) {
      const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
      document.getElementById('deliveryAreaHelp').textContent = 'Delivery available for '
        + selectedArea + ' (' + (option.dataset.pincode || '') + ').';
    } else {
      document.getElementById('deliveryAreaHelp').textContent = address.trim()
        ? 'Area not detected. Select your delivery area from the list.'
        : 'Select your delivery area; its PIN code will be shown below. An area or PIN typed in the address may be detected automatically.';
    }
    return;
  }

  if (candidateAreas.length === 1) {
    selectDeliveryArea(candidateAreas[0], 'Detected ' + candidateAreas[0] + '. PIN code filled automatically.');
  } else if (candidateAreas.length > 1) {
    if (candidateAreas.includes(deliveryAreaSelect.value)) {
      const selectedArea = deliveryAreaSelect.value;
      const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
      selectDeliveryArea(selectedArea, 'Selected ' + selectedArea + ' matches PIN '
        + (option.dataset.pincode || '') + '.');
    } else {
      selectDeliveryArea('', 'Multiple delivery areas match. Please choose your exact area above.');
    }
  } else {
    selectDeliveryArea('', address.trim() ? 'Add your locality name to the address, or select your area from the list.' : '');
  }
});

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

      if (res.credit) {
        // Credit orders don't need UPI scan/confirm — they're already
        // placed and added to the customer's running ledger balance.
        window.location.href = window.__VEGBASKET_BASE__ + '/order_success.php?order_id=' + res.order_id;
        return;
      }

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
