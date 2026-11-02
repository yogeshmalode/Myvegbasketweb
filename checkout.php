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
        <textarea id="address" name="address" rows="3" placeholder="House / building, street, locality, city" aria-describedby="addressAreaHelp" required></textarea>
        <small id="addressAreaHelp" style="color:#5B6656;">Include your locality name (for example, Undri or Wanowrie). We’ll fill the matching delivery area and PIN automatically.</small>
      </div>
      <div class="form-group">
        <label for="delivery_area">Choose your delivery area</label>
        <select id="delivery_area" name="delivery_area" aria-describedby="deliveryAreaHelp" required>
          <option value="">Select your locality</option>
          <?php foreach (get_serviceable_delivery_areas() as $areaName => $areaPin): ?>
            <option value="<?= h($areaName) ?>" data-pincode="<?= h($areaPin) ?>"><?= h($areaName) ?> — <?= h($areaPin) ?></option>
          <?php endforeach; ?>
        </select>
        <small id="deliveryAreaHelp" style="color:#5B6656;">We’ll detect a supported locality from your address when possible. You can also choose it here.</small>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px;">
          <button type="button" class="btn" id="useCurrentLocationBtn">📍 Check delivery at my location</button>
          <span id="currentLocationStatus" role="status" aria-live="polite" style="font-size:.82rem;color:#5B6656;"></span>
        </div>
        <input type="hidden" id="deliveryLatitude" name="delivery_latitude">
        <input type="hidden" id="deliveryLongitude" name="delivery_longitude">
        <div class="form-group" id="pincodeDisplayWrap" style="display:none;margin-top:10px;">
          <label for="pincode">Delivery PIN code</label>
          <input type="text" id="pincode" name="pincode" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="postal-code" readonly required>
          <small style="color:#5B6656;">PIN code is filled from the selected locality.</small>
        </div>
      </div>
      <div class="form-group">
        <label for="customer_note">Order note or instructions (optional)</label>
        <textarea id="customer_note" name="customer_note" rows="3" maxlength="500" placeholder="For example: delivery instructions, preferred vegetables, or business order details"></textarea>
        <small style="color:#5B6656;">Your note will be shared with our order team and delivery partner (up to 500 characters).</small>
      </div>

      <div class="form-group">
        <label for="delivery_date">Preferred delivery date</label>
        <input type="date" id="delivery_date" name="delivery_date" min="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="form-group">
        <label for="delivery_slot">Preferred time slot</label>
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

      <?php if ($creditAvailable > 0): ?>
      <div class="form-group">
        <label>Payment method</label>
        <div style="display:flex; gap:16px; flex-wrap:wrap;">
          <label style="display:flex; align-items:center; gap:6px; font-weight:400;"><input type="radio" name="payment_method" value="upi_qr" checked> Pay via UPI now</label>
          <label style="display:flex; align-items:center; gap:6px; font-weight:400;"><input type="radio" name="payment_method" value="credit"> Bill Me Later (Credit)</label>
        </div>
        <p style="margin-top:6px; font-size:0.8rem; color:#5B6656;">Available credit: <?= SITE_CURRENCY ?><?= number_format($creditAvailable, 2) ?></p>
      </div>
      <?php endif; ?>

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

const deliveryAreaSelect = document.getElementById('delivery_area');
const pincodeInput = document.getElementById('pincode');
const pincodeDisplayWrap = document.getElementById('pincodeDisplayWrap');
const deliveryAddressInput = document.getElementById('address');
let detectedGpsPostcode = '';
const deliveryAreaAliases = <?= json_encode(get_serviceable_delivery_area_aliases(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const selectDeliveryArea = function (areaName, message) {
  deliveryAreaSelect.value = areaName;
  const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
  pincodeInput.value = option && option.value ? option.dataset.pincode || '' : '';
  pincodeInput.disabled = !pincodeInput.value;
  pincodeDisplayWrap.style.display = pincodeInput.value ? 'block' : 'none';
  if (detectedGpsPostcode && pincodeInput.value && detectedGpsPostcode !== pincodeInput.value) {
    document.getElementById('deliveryLatitude').value = '';
    document.getElementById('deliveryLongitude').value = '';
    document.getElementById('currentLocationStatus').textContent = 'Map detected PIN '
      + detectedGpsPostcode + ', but the selected area uses PIN ' + pincodeInput.value
      + '. Please correct the address; GPS coordinates were removed to avoid sending the rider to the wrong location.';
    document.getElementById('currentLocationStatus').style.color = '#9A2E24';
    return;
  }
  document.getElementById('currentLocationStatus').textContent = message || '';
};
deliveryAreaSelect.addEventListener('change', function () {
  const option = deliveryAreaSelect.options[deliveryAreaSelect.selectedIndex];
  pincodeInput.value = option.dataset.pincode || '';
  pincodeInput.disabled = !pincodeInput.value;
  pincodeDisplayWrap.style.display = pincodeInput.value ? 'block' : 'none';
  if (detectedGpsPostcode && pincodeInput.value && detectedGpsPostcode !== pincodeInput.value) {
    document.getElementById('deliveryLatitude').value = '';
    document.getElementById('deliveryLongitude').value = '';
    document.getElementById('currentLocationStatus').textContent = 'Map detected PIN '
      + detectedGpsPostcode + ', but the selected area uses PIN ' + pincodeInput.value
      + '. Please correct the address; GPS coordinates were removed to avoid sending the rider to the wrong location.';
    document.getElementById('currentLocationStatus').style.color = '#9A2E24';
    return;
  }
  document.getElementById('currentLocationStatus').textContent = option.value
    ? 'Delivery available for ' + option.value + ' (' + pincodeInput.value + ').'
    : '';
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
  if (candidateAreas.length === 1) {
    selectDeliveryArea(candidateAreas[0], 'Detected ' + candidateAreas[0] + '. PIN code filled automatically.');
  } else if (candidateAreas.length > 1) {
    selectDeliveryArea('', 'Multiple delivery areas match. Please choose your exact area above.');
  } else {
    selectDeliveryArea('', address.trim() ? 'Add your locality name to the address, or select your area from the list.' : '');
  }
});

document.getElementById('useCurrentLocationBtn').addEventListener('click', function () {
  const locationStatus = document.getElementById('currentLocationStatus');
  const locationButton = this;
  if (!navigator.geolocation) {
    locationStatus.textContent = 'Your browser does not support location. Please choose your area from the list.';
    return;
  }
  locationButton.disabled = true;
  locationStatus.textContent = 'Getting your location and checking delivery coverage…';
  document.getElementById('deliveryLatitude').value = '';
  document.getElementById('deliveryLongitude').value = '';
  navigator.geolocation.getCurrentPosition(function (position) {
    const accuracyMeters = position.coords.accuracy;
    if (!Number.isFinite(accuracyMeters) || accuracyMeters > 300) {
      locationStatus.textContent = 'Your device location is only accurate to about '
        + (Number.isFinite(accuracyMeters) ? Math.round(accuracyMeters) + ' metres' : 'an unknown distance')
        + '. We did not fill an address to avoid showing the wrong PIN. Enable precise location or enter your address manually.';
      locationStatus.style.color = '#9A2E24';
      locationButton.disabled = false;
      return;
    }

    const latitude = position.coords.latitude;
    const longitude = position.coords.longitude;
    const body = new URLSearchParams({ latitude: String(latitude), longitude: String(longitude) });
    fetch(window.__VEGBASKET_BASE__ + '/ajax/check_delivery_location.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (response) {
      return response.json().then(function (result) {
        if (!response.ok) throw new Error(result.message || 'Could not check delivery coverage.');
        return result;
      });
    }).then(function (result) {
      if (result.address) {
        deliveryAddressInput.value = result.address;
      }
      detectedGpsPostcode = result.postcode || '';

      if (result.serviceable) {
        const selectedPin = pincodeInput.value;
        if (detectedGpsPostcode && selectedPin && detectedGpsPostcode !== selectedPin) {
          document.getElementById('deliveryLatitude').value = '';
          document.getElementById('deliveryLongitude').value = '';
          locationStatus.textContent = 'Map detected PIN ' + detectedGpsPostcode
            + ', but your selected area uses PIN ' + selectedPin
            + '. Please correct the address; GPS coordinates were not attached to the order.';
          locationStatus.style.color = '#9A2E24';
        } else {
          document.getElementById('deliveryLatitude').value = String(latitude);
          document.getElementById('deliveryLongitude').value = String(longitude);
          locationStatus.textContent = result.message
            + (result.postcode ? ' Map PIN: ' + result.postcode + '.' : '')
            + ' Device accuracy: about ' + Math.round(accuracyMeters) + ' metres.';
          locationStatus.style.color = '#17643f';
        }
      } else {
        locationStatus.textContent = (result.message || 'Sorry, we do not deliver to your current location yet.')
          + (result.postcode ? ' Map PIN: ' + result.postcode + '.' : '')
          + ' Device accuracy: about ' + Math.round(accuracyMeters) + ' metres.';
        locationStatus.style.color = '#9A2E24';
      }
    }).catch(function (error) {
      locationStatus.textContent = error.message || 'We could not check delivery coverage. Please choose your area manually or try again.';
      locationStatus.style.color = '#9A2E24';
    }).finally(function () {
      locationButton.disabled = false;
    });
  }, function (error) {
    const messages = {
      1: 'Location permission was denied. Please choose your area from the list.',
      2: 'Your location could not be determined. Please try again or choose your area from the list.',
      3: 'Location check timed out. Please try again or choose your area from the list.'
    };
    locationStatus.textContent = messages[error.code] || 'Location check failed. Please choose your area from the list.';
    locationStatus.style.color = '#9A2E24';
    locationButton.disabled = false;
  }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
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
