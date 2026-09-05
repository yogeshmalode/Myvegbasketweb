<?php
require_once __DIR__ . '/config.php';
$page_title = 'Checkout';
$cart = $_SESSION['cart'] ?? [];

if (empty($cart)) {
    redirect(BASE_URL . '/cart.php');
}

$total = cart_total();
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:760px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Checkout</h2>
    <p>Enter your delivery details, then pay securely.</p>
  </div>

  <div id="checkoutAlert"></div>

  <div class="form-card">
    <form id="checkoutForm">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required>
      </div>
      <div class="form-group">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required>
      </div>
      <div class="form-group">
        <label for="phone">Phone number</label>
        <input type="tel" id="phone" name="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" required>
      </div>
      <div class="form-group">
        <label for="address">Delivery address</label>
        <textarea id="address" name="address" rows="3" required></textarea>
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

      <div class="cart-total-row">
        <span>Total payable</span>
        <span><?= SITE_CURRENCY ?><?= number_format($total,2) ?></span>
      </div>

      <button type="submit" class="btn btn-primary btn-block" id="payBtn">
        Pay <?= SITE_CURRENCY ?><?= number_format($total,2) ?> with Razorpay
      </button>
    </form>
  </div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
const RAZORPAY_KEY_ID = "<?= RAZORPAY_KEY_ID ?>";

document.getElementById('checkoutForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const payBtn = document.getElementById('payBtn');
  const alertBox = document.getElementById('checkoutAlert');
  alertBox.innerHTML = '';
  payBtn.disabled = true;
  payBtn.textContent = 'Preparing payment...';

  const formData = new FormData(this);

  // Step 1: create a Razorpay order on the server
  fetch(window.__VEGBASKET_BASE__ + '/ajax/create_order.php', {
    method: 'POST',
    body: formData
  })
    .then(r => r.json())
    .then(order => {
      if (!order.success) {
        throw new Error(order.message || 'Could not create order.');
      }

      payBtn.disabled = false;
      payBtn.textContent = "Pay <?= SITE_CURRENCY ?><?= number_format($total,2) ?> with Razorpay";

      const options = {
        key: RAZORPAY_KEY_ID,
        amount: order.amount,
        currency: order.currency,
        name: "<?= SITE_NAME ?>",
        description: "Vegetable basket order",
        order_id: order.razorpay_order_id,
        prefill: {
          name: formData.get('name'),
          email: formData.get('email'),
          contact: formData.get('phone')
        },
        theme: { color: "#1F4D36" },
        handler: function (response) {
          // Step 2: verify the payment signature on the server
          const verifyData = new FormData();
          verifyData.append('razorpay_payment_id', response.razorpay_payment_id);
          verifyData.append('razorpay_order_id', response.razorpay_order_id);
          verifyData.append('razorpay_signature', response.razorpay_signature);
          verifyData.append('name', formData.get('name'));
          verifyData.append('email', formData.get('email'));
          verifyData.append('phone', formData.get('phone'));
          verifyData.append('address', formData.get('address'));

          fetch(window.__VEGBASKET_BASE__ + '/verify_payment.php', {
            method: 'POST',
            body: verifyData
          }).then(r => r.json()).then(res => {
            if (res.success) {
              window.location.href = window.__VEGBASKET_BASE__ + '/order_success.php?order_id=' + res.order_id;
            } else {
              alertBox.innerHTML = '<div class="alert alert-error">Payment verification failed: ' + (res.message || '') + '</div>';
            }
          });
        },
        modal: {
          ondismiss: function () {
            alertBox.innerHTML = '<div class="alert alert-error">Payment cancelled.</div>';
          }
        }
      };

      const rzp = new Razorpay(options);
      rzp.open();
    })
    .catch(err => {
      payBtn.disabled = false;
      payBtn.textContent = "Pay <?= SITE_CURRENCY ?><?= number_format($total,2) ?> with Razorpay";
      alertBox.innerHTML = '<div class="alert alert-error">' + err.message + '</div>';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
