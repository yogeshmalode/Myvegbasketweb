<?php
require_once __DIR__ . '/config.php';
$page_title = 'My Cart';
$cart = $_SESSION['cart'] ?? [];
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Your Basket</h2>
    <p><?= count($cart) ?> item(s) in your basket</p>
  </div>

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
            <tr data-id="<?= $item['id'] ?>">
              <td><?= veg_emoji($item['name']) ?> <?= h($item['name']) ?></td>
              <td><?= SITE_CURRENCY ?><?= number_format($item['price'],2) ?> / <?= h($item['unit']) ?></td>
              <td>
                <input type="number" class="page-qty-input" data-id="<?= $item['id'] ?>" value="<?= (int)$item['qty'] ?>" min="1" style="width:60px; padding:6px; border:1px solid #D9E0CD; border-radius:6px;">
              </td>
              <td><?= SITE_CURRENCY ?><?= number_format($item['price']*$item['qty'],2) ?></td>
              <td><a href="#" class="action-link delete page-remove-link" data-id="<?= $item['id'] ?>">Remove</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="form-card" style="max-width:360px; margin-left:auto;">
      <div class="cart-total-row">
        <span>Total</span>
        <span><?= SITE_CURRENCY ?><?= number_format(cart_total(),2) ?></span>
      </div>
      <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-block">Proceed to Checkout</a>
    </div>
  <?php endif; ?>
</div>

<script>
document.getElementById('cartPageBody')?.addEventListener('change', (e) => {
  if (e.target.classList.contains('page-qty-input')) {
    const id = e.target.dataset.id, qty = e.target.value;
    fetch(window.__VEGBASKET_BASE__ + '/ajax/update_cart.php', {
      method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `id=${id}&qty=${qty}`
    }).then(() => location.reload());
  }
});
document.getElementById('cartPageBody')?.addEventListener('click', (e) => {
  if (e.target.classList.contains('page-remove-link')) {
    e.preventDefault();
    const id = e.target.dataset.id;
    fetch(window.__VEGBASKET_BASE__ + '/ajax/remove_from_cart.php', {
      method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
      body: `id=${id}`
    }).then(() => location.reload());
  }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
