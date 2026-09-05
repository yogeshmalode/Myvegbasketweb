<?php
require_once __DIR__ . '/../config.php';

$cart = $_SESSION['cart'] ?? [];
?>
<?php if (empty($cart)): ?>
  <p style="color:#5B6656; text-align:center; padding:30px 0;">Your basket is empty.<br>Add some fresh vegetables! 🥬</p>
<?php else: ?>
  <?php foreach ($cart as $item): ?>
    <div class="cart-item" data-id="<?= h($item['key'] ?? $item['id']) ?>">
      <span class="emoji"><?= veg_emoji($item['name']) ?></span>
      <div>
        <div class="cart-item-name"><?= h($item['name']) ?></div>
        <div class="cart-item-meta">
          <?= SITE_CURRENCY ?><?= number_format($item['price'],2) ?> x
          <input type="number" class="cart-qty-input" data-id="<?= h($item['key'] ?? $item['id']) ?>" value="<?= (int)$item['qty'] ?>" min="1" style="width:44px; border:1px solid #D9E0CD; border-radius:4px;">
          <?= h($item['unit']) ?>
        </div>
      </div>
      <a href="#" class="remove-link" data-id="<?= h($item['key'] ?? $item['id']) ?>">Remove</a>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
