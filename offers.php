<?php
require_once __DIR__ . '/config.php';
$page_title = 'Offers & Coupons';
$meta_description = 'Current discounts and coupon codes at ' . SITE_NAME . '.';

$offers = get_active_offers($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px;">
  <div class="section-head" style="margin-top:0;">
    <h2>🎉 Offers & Coupons</h2>
    <p>Grab a code below and apply it at checkout.</p>
  </div>

  <?php if (empty($offers)): ?>
    <div class="form-card" style="text-align:center; max-width:480px; margin:0 auto;">
      <p style="color:#5B6656;">No active offers right now — check back soon!</p>
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary" style="margin-top:10px;">Start shopping</a>
    </div>
  <?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:20px; max-width:900px;">
      <?php foreach ($offers as $offer): ?>
        <?php
          $badge = $offer['discount_type'] === 'percent' ? number_format($offer['discount_value'], 0) . '% OFF'
                 : ($offer['discount_type'] === 'flat' ? SITE_CURRENCY . number_format($offer['discount_value'], 0) . ' OFF'
                 : 'FREE DELIVERY');
        ?>
        <div class="form-card" style="border:1.5px dashed var(--carrot); position:relative;">
          <div style="display:inline-block; background:var(--leaf-light); color:var(--leaf-dark); font-weight:700; font-size:0.85rem; padding:5px 12px; border-radius:999px; margin-bottom:12px;">
            <?= h($badge) ?>
          </div>
          <h3 style="margin:0 0 6px;"><?= h($offer['title']) ?></h3>
          <p style="color:#5B6656; margin:0 0 14px; min-height:40px;"><?= h($offer['description']) ?></p>

          <?php if ($offer['min_order_amount'] > 0): ?>
            <p style="font-size:0.8rem; color:#5B6656; margin:0 0 4px;">Min. order <?= SITE_CURRENCY ?><?= number_format($offer['min_order_amount'],2) ?></p>
          <?php endif; ?>
          <?php if ($offer['valid_until']): ?>
            <p style="font-size:0.8rem; color:#5B6656; margin:0 0 10px;">Valid until <?= date('d M Y', strtotime($offer['valid_until'])) ?></p>
          <?php endif; ?>

          <?php if ($offer['coupon_code']): ?>
            <div style="display:flex; align-items:center; gap:8px; background:#F1F3EA; border:1px dashed #D9E0CD; border-radius:8px; padding:8px 12px; margin-top:10px;">
              <code style="font-family:var(--font-mono); font-weight:700; letter-spacing:1px; flex:1;"><?= h($offer['coupon_code']) ?></code>
              <button type="button" class="copy-coupon-btn" data-code="<?= h($offer['coupon_code']) ?>" style="background:var(--leaf); color:#fff; border:none; padding:6px 12px; border-radius:6px; font-size:0.8rem; font-weight:600; cursor:pointer;">Copy</button>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div style="margin-top:30px;">
      <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">Shop now &amp; use a code at checkout</a>
    </div>
  <?php endif; ?>
</div>

<script>
  document.querySelectorAll('.copy-coupon-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      navigator.clipboard.writeText(btn.dataset.code).then(() => {
        const original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = original; }, 1500);
      });
    });
  });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
