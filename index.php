<?php
require_once __DIR__ . '/config.php';
$page_title = 'Fresh Vegetables Online';

$search_q = trim($_GET['q'] ?? '');
$category = trim($_GET['category'] ?? '');

$sql = "SELECT * FROM vegetables WHERE is_active = 1";
$params = [];
if ($search_q !== '') {
    $sql .= " AND name LIKE :q";
    $params[':q'] = "%$search_q%";
}
if ($category !== '' && $category !== 'All') {
    $sql .= " AND category = :cat";
    $params[':cat'] = $category;
}
$sql .= " ORDER BY name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vegetables = $stmt->fetchAll();

$categories = $pdo->query("SELECT DISTINCT category FROM vegetables ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$variantsByVeg = get_variants_by_vegetable($pdo, array_column($vegetables, 'id'));
$activeOffers = get_active_offers($pdo);

include __DIR__ . '/includes/header.php';

if ($vegetables): ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ItemList",
  "itemListElement": [
    <?php foreach ($vegetables as $i => $veg): ?>
    {
      "@type": "ListItem",
      "position": <?= $i + 1 ?>,
      "item": {
        "@type": "Product",
        "name": <?= json_encode($veg['name']) ?>,
        "description": <?= json_encode($veg['description'] ?: ($veg['name'] . ' available at ' . SITE_NAME)) ?>,
        "category": <?= json_encode($veg['category']) ?>,
        "offers": {
          "@type": "Offer",
          "priceCurrency": "INR",
          "price": <?= json_encode((string)$veg['price']) ?>,
          "availability": "<?= $veg['stock'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock' ?>",
          "url": <?= json_encode(SITE_URL . '/index.php') ?>
        }
      }
    }<?= $i < count($vegetables) - 1 ? ',' : '' ?>
    <?php endforeach; ?>
  ]
}
</script>
<?php endif; ?>

<?php if ($activeOffers): ?>
<a href="<?= BASE_URL ?>/offers.php" style="display:block; background:var(--carrot); overflow:hidden; text-decoration:none; position:relative;">
  <div class="offers-ticker-track">
    <?php
      // Repeat the offer list a few times so the strip loops seamlessly
      // regardless of how many offers are active.
      $tickerItems = array_merge($activeOffers, $activeOffers, $activeOffers);
      foreach ($tickerItems as $offer):
        $badge = $offer['discount_type'] === 'percent' ? number_format($offer['discount_value'], 0) . '% OFF'
               : ($offer['discount_type'] === 'flat' ? SITE_CURRENCY . number_format($offer['discount_value'], 0) . ' OFF'
               : 'FREE DELIVERY');
    ?>
      <span class="offers-ticker-item">
        🎉 <strong><?= h($badge) ?></strong> — <?= h($offer['title']) ?><?= $offer['coupon_code'] ? ' · code ' . h($offer['coupon_code']) : '' ?>
      </span>
    <?php endforeach; ?>
  </div>
</a>
<?php endif; ?>

<section class="hero">
  <div class="container hero-inner">
    <div>
      <span class="eyebrow">🚜 Harvested this morning</span>
      <h1>Farm-fresh vegetables, <span class="accent">delivered today.</span></h1>
      <p>Skip the crowded market. Pick your vegetables, add them to your basket, and pay online — we'll have them at your door within hours.</p>
      <a href="#shop" class="btn btn-primary">Start shopping 🛒</a>
    </div>
    <div class="hero-stamp">
      <div class="stamp-circle">FRESH<br>TODAY</div>
      <h3>Today's basket average</h3>
      <div class="big-num"><?= SITE_CURRENCY ?><?= number_format(count($vegetables) ? array_sum(array_column($vegetables,'price'))/count($vegetables) : 0, 0) ?></div>
      <p style="color:#5B6656; margin-top:6px;">per kg across <?= count($vegetables) ?> items in stock</p>
    </div>
  </div>
</section>


<div class="container" id="shop">
  <div class="section-head">
    <h2>Today's Vegetable Stall</h2>
    <p>Prices update daily based on the morning harvest.</p>
  </div>

  <div class="chip-row">
    <a href="<?= BASE_URL ?>/index.php" class="chip <?= $category==='' ? 'active' : '' ?>">All</a>
    <?php foreach ($categories as $cat): ?>
      <a href="<?= BASE_URL ?>/index.php?category=<?= urlencode($cat) ?>" class="chip <?= $category===$cat ? 'active' : '' ?>"><?= h($cat) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (empty($vegetables)): ?>
    <p style="text-align:center; color:#5B6656; padding:40px 0;">No vegetables matched "<?= h($search_q) ?>". Try another search.</p>
  <?php else: ?>
  <div class="veg-grid">
    <?php foreach ($vegetables as $veg): ?>
      <div class="veg-card" data-id="<?= $veg['id'] ?>">
        <div class="veg-thumb" style="overflow:hidden; background:#fff; border:1px solid #E4E9DD;"><?= veg_thumb_html($veg) ?></div>
        <h4>
          <?= h($veg['name']) ?>
          <?php if (!empty($veg['name_mr'])): ?>
            <span style="font-weight:600; color:#5B6656; font-size:0.85rem;">/ <?= h($veg['name_mr']) ?></span>
          <?php endif; ?>
        </h4>
        <div class="veg-desc"><?= h($veg['description']) ?></div>

        <?php $variants = $variantsByVeg[$veg['id']] ?? []; ?>

        <?php if ($variants): ?>
          <div class="form-group" style="margin-bottom:10px;">
            <select class="variant-select" style="width:100%; padding:8px 10px; border-radius:8px; border:1px solid #D9E0CD; font-weight:600; font-size:0.9rem;">
              <?php foreach ($variants as $v): ?>
                <option value="<?= $v['id'] ?>" data-price="<?= $v['price'] ?>" data-label="<?= h($v['label']) ?>">
                  <?= h($v['label']) ?> — <?= SITE_CURRENCY ?><?= number_format($v['price'],2) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="price-tag">
            <span class="amount"><?= SITE_CURRENCY ?><?= number_format($variants[0]['price'],2) ?></span>
            <span class="unit">per pack</span>
          </div>
        <?php else: ?>
          <?php $onSale = get_effective_price($veg) < (float)$veg['price']; ?>
          <div class="price-tag" style="<?= $onSale ? 'border-color:var(--tomato);' : '' ?>">
            <?php if ($onSale): ?>
              <span style="text-decoration:line-through; color:#9CA88F; font-size:0.8rem; margin-right:6px;"><?= SITE_CURRENCY ?><?= number_format($veg['price'],2) ?></span>
              <span class="amount" style="color:var(--tomato);"><?= SITE_CURRENCY ?><?= number_format($veg['sale_price'],2) ?></span>
              <span class="unit">per <?= h($veg['unit']) ?></span>
            <?php else: ?>
              <span class="amount"><?= SITE_CURRENCY ?><?= number_format($veg['price'],2) ?></span>
              <span class="unit">per <?= h($veg['unit']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($onSale): ?>
            <div style="display:inline-block; background:var(--tomato); color:#fff; font-weight:700; font-size:0.7rem; padding:2px 8px; border-radius:999px; margin-top:6px;">
              SALE — <?= round((1 - $veg['sale_price']/$veg['price']) * 100) ?>% OFF
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($veg['stock'] <= 10): ?>
          <div class="stock-low">Only <?= (int)$veg['stock'] ?> left!</div>
        <?php endif; ?>
        <div class="veg-footer">
          <div class="qty-box">
            <button type="button" class="qty-minus">−</button>
            <input type="number" class="qty-input" value="1" min="1" max="<?= (int)$veg['stock'] ?>">
            <button type="button" class="qty-plus">+</button>
          </div>
          <button type="button" class="add-btn"
                  data-id="<?= $veg['id'] ?>"
                  data-name="<?= h($veg['name']) ?>"
                  data-price="<?= $veg['price'] ?>"
                  data-unit="<?= h($veg['unit']) ?>">
            Add to basket
          </button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if (!is_customer_logged_in()): ?>
<!-- Login prompt popup (guests only, shown once per browser session) -->
<div id="loginPromptOverlay" style="display:none; position:fixed; inset:0; background:rgba(20,30,20,0.55); z-index:999; align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:18px; padding:28px 26px; max-width:360px; width:90%; box-shadow:0 20px 50px rgba(0,0,0,0.3); text-align:center; position:relative;">
    <button type="button" id="loginPromptClose" aria-label="Close" style="position:absolute; top:12px; right:14px; background:none; border:none; font-size:1.3rem; color:#5B6656; cursor:pointer; line-height:1;">&times;</button>
    <div style="font-size:2.2rem; margin-bottom:8px;">🥕</div>
    <h3 style="margin:0 0 10px;">Welcome to <?= h(SITE_NAME) ?>!</h3>
    <p style="color:#5B6656; margin:0 0 20px;">Log in to save your addresses, track orders live, and reorder your basket in one tap.</p>
    <a href="<?= BASE_URL ?>/login_email_otp.php" class="btn btn-primary btn-block" style="margin-bottom:10px;">📧 Login with an email code</a>
    <a href="<?= BASE_URL ?>/login.php" class="btn" style="background:#fff; border:1px solid #E4E9DD; width:100%; box-sizing:border-box;">Login with password</a>
    <p style="margin-top:16px;">
      <a href="#" id="continueGuestLink" style="color:#5B6656; font-size:0.85rem; text-decoration:underline;">Continue browsing as guest</a>
    </p>
  </div>
</div>
<script>
  (function () {
    const overlay = document.getElementById('loginPromptOverlay');
    if (!overlay) return;
    const close = () => overlay.style.display = 'none';
    document.getElementById('loginPromptClose')?.addEventListener('click', close);
    document.getElementById('continueGuestLink')?.addEventListener('click', (e) => { e.preventDefault(); close(); });
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    if (!sessionStorage.getItem('loginPromptSeen')) {
      overlay.style.display = 'flex';
      sessionStorage.setItem('loginPromptSeen', '1');
    }
  })();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
