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

// Patch displayed stock for per_store products to the TOTAL across every
// store (see get_storefront_display_stock() in config.php for why) — the
// shop doesn't know which store will fulfil this order until checkout.
foreach ($vegetables as &$vegRow) {
    if (($vegRow['stock_mode'] ?? 'shared') === 'per_store') {
        $vegRow['stock'] = get_storefront_display_stock($pdo, $vegRow['id'], $vegRow['stock_mode'], $vegRow['stock']);
    }
}
unset($vegRow);

$aisleCategories = [
    ['name' => 'Dairy', 'icon' => '🥛'],
    ['name' => 'Exotic Vegetable', 'icon' => '🧺'],
    ['name' => 'Flower', 'icon' => '🧺'],
    ['name' => 'Fruit', 'icon' => '🍎'],
    ['name' => 'Fruit Salad', 'icon' => '🧺'],
    ['name' => 'Juice', 'icon' => '🧺'],
    ['name' => 'Leafy', 'icon' => '🥬'],
    ['name' => 'Root', 'icon' => '🥔'],
    ['name' => 'Vegetable', 'icon' => '🥦'],
];

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

<?php
$renderProductCard = static function (array $veg, bool $compact = false) use ($pdo, $variantsByVeg): string {
    $variants = $variantsByVeg[$veg['id']] ?? [];
    $cartPrice = $variants
        ? (float)$variants[0]['price']
        : get_price_for_customer($pdo, $veg, current_customer_type());
    $onSale = !$variants
        && !in_array(current_customer_type(), ['hotel', 'shop'], true)
        && get_effective_price($veg) < (float)$veg['price'];
    ob_start();
    ?>
    <article class="veg-card quick-product-card<?= $compact ? ' sponsored-product-card' : '' ?>" data-id="<?= (int)$veg['id'] ?>">
      <div class="quick-product-image">
        <?= veg_thumb_html($veg) ?>
        <?php if ($compact): ?><span class="product-promo-badge">Offer</span><?php endif; ?>
      </div>
      <div class="quick-product-copy">
        <h3><?= h($veg['name']) ?><?php if (!empty($veg['name_mr'])): ?> <span>/ <?= h($veg['name_mr']) ?></span><?php endif; ?></h3>
        <p class="veg-desc"><?= h($veg['description']) ?></p>
      </div>

      <?php if ($variants): ?>
        <label class="product-variant-label">
          <span class="visually-hidden">Choose pack size for <?= h($veg['name']) ?></span>
          <select class="variant-select">
            <?php foreach ($variants as $variant): ?>
              <option value="<?= (int)$variant['id'] ?>" data-price="<?= h($variant['price']) ?>" data-label="<?= h($variant['label']) ?>">
                <?= h($variant['label']) ?> — <?= SITE_CURRENCY ?><?= number_format((float)$variant['price'], 2) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endif; ?>

      <div class="quick-product-price">
        <div class="price-tag<?= $onSale ? ' on-sale' : '' ?>">
          <?php if ($onSale): ?><span class="old-price"><?= SITE_CURRENCY ?><?= number_format((float)$veg['price'], 2) ?></span><?php endif; ?>
          <span class="amount"><?= SITE_CURRENCY ?><?= number_format($onSale ? get_effective_price($veg) : $cartPrice, 2) ?></span>
          <span class="unit"><?= $variants ? 'per pack' : 'per ' . h($veg['unit']) ?></span>
        </div>
        <?php if ($onSale): ?>
          <span class="product-sale-badge"><?= (int)round((1 - get_effective_price($veg) / (float)$veg['price']) * 100) ?>% off</span>
        <?php elseif (in_array(current_customer_type(), ['hotel', 'shop'], true) && !$variants): ?>
          <span class="wholesale-badge">Wholesale</span>
        <?php endif; ?>
      </div>

      <?php if ($veg['stock'] > 0 && $veg['stock'] <= 10): ?>
        <p class="stock-low">Only <?= (int)$veg['stock'] ?> left</p>
      <?php elseif ($veg['stock'] <= 0): ?>
        <p class="stock-low">Currently out of stock</p>
      <?php endif; ?>

      <div class="veg-footer">
        <?php if ($veg['stock'] > 0): ?>
          <div class="qty-box">
            <button type="button" class="qty-minus" aria-label="Decrease quantity">−</button>
            <input type="number" class="qty-input" value="1" min="1" max="<?= (int)$veg['stock'] ?>" aria-label="Quantity">
            <button type="button" class="qty-plus" aria-label="Increase quantity">+</button>
          </div>
          <button type="button" class="add-btn"
                  data-id="<?= (int)$veg['id'] ?>"
                  data-name="<?= h($veg['name']) ?>"
                  data-price="<?= h($cartPrice) ?>"
                  data-unit="<?= h($veg['unit']) ?>"
                  data-csrf="<?= h(csrf_token()) ?>"
                  data-logged-in="<?= is_customer_logged_in() ? '1' : '0' ?>"
                  <?= $cartPrice <= 0 ? 'disabled' : '' ?>>
            <?= $cartPrice <= 0 ? 'Price unavailable' : 'ADD' ?>
          </button>
        <?php else: ?>
          <button type="button" class="notify-btn"
                  data-id="<?= (int)$veg['id'] ?>"
                  data-csrf="<?= h(csrf_token()) ?>"
                  data-logged-in="<?= is_customer_logged_in() ? '1' : '0' ?>">
            Notify me
          </button>
        <?php endif; ?>
      </div>
    </article>
    <?php
    return (string)ob_get_clean();
};

$dealProducts = array_values(array_filter(
    $vegetables,
    static fn(array $veg): bool => get_effective_price($veg) < (float)$veg['price']
));
?>

<main class="quick-storefront">
  <section class="promo-hero" aria-label="Store promotions">
    <div class="container">
      <div class="promo-slider" id="promoSlider" aria-roledescription="carousel">
        <?php if ($activeOffers): ?>
          <?php foreach ($activeOffers as $index => $offer):
            $offerBadge = $offer['discount_type'] === 'percent'
                ? number_format((float)$offer['discount_value'], 0) . '% OFF'
                : ($offer['discount_type'] === 'flat'
                    ? SITE_CURRENCY . number_format((float)$offer['discount_value'], 0) . ' OFF'
                    : 'FREE DELIVERY');
            $isWelcomeImageOffer = strtoupper(trim((string)($offer['coupon_code'] ?? ''))) === 'WELCOME10'
                && $offer['discount_type'] === 'percent'
                && (float)$offer['discount_value'] === 10.0;
            $isFreeDeliveryImageOffer = strtoupper(trim((string)($offer['coupon_code'] ?? ''))) === 'FREESHIP'
                && $offer['discount_type'] === 'free_delivery';
            $hasCustomOfferImage = $isWelcomeImageOffer || $isFreeDeliveryImageOffer;
            $offerImagePath = $isFreeDeliveryImageOffer
                ? 'free-delivery-weekend-banner.png'
                : 'welcome-offer-banner.png';
            $offerImageAlt = $isFreeDeliveryImageOffer
                ? 'MyVegBasket Free Delivery Weekend: get free delivery with code FREESHIP'
                : 'MyVegBasket Welcome Offer: get 10% off with code WELCOME10';
          ?>
            <article class="promo-slide<?= $index === 0 ? ' is-active' : '' ?><?= $hasCustomOfferImage ? ' promo-slide-image' : '' ?>" <?= $index === 0 ? '' : 'hidden' ?>>
              <?php if ($hasCustomOfferImage): ?>
                <?php if ($isWelcomeImageOffer): ?>
                <div class="promo-image-message">
                  <span class="promo-eyebrow">FRESH FROM OUR FARM PARTNERS</span>
                  <h1>Make room for something fresh.</h1>
                  <p>Explore seasonal picks and enjoy a little extra in your basket.</p>
                  <a href="<?= BASE_URL ?>/offers.php" class="promo-cta">Shop the welcome offer <span aria-hidden="true">→</span></a>
                </div>
                <?php else: ?>
                <div class="promo-image-message">
                  <span class="promo-eyebrow">A LITTLE EXTRA FOR YOUR WEEKEND</span>
                  <h1>Fresh groceries, delivered free.</h1>
                  <p>Fill your basket with farm-fresh favourites and enjoy free delivery.</p>
                  <a href="<?= BASE_URL ?>/offers.php" class="promo-cta">Shop the free-delivery offer <span aria-hidden="true">→</span></a>
                </div>
                <?php endif; ?>
                <a class="promo-image-link" href="<?= BASE_URL ?>/offers.php" aria-label="<?= h($isFreeDeliveryImageOffer ? 'Explore the Free Delivery Weekend offer with code FREESHIP' : 'Explore the Welcome Offer: 10% off with code WELCOME10') ?>">
                  <img src="<?= BASE_URL ?>/assets/images/<?= h($offerImagePath) ?>" alt="<?= h($offerImageAlt) ?>" fetchpriority="<?= $index === 0 ? 'high' : 'auto' ?>" loading="<?= $index === 0 ? 'eager' : 'lazy' ?>" decoding="async">
                </a>
              <?php else: ?>
                <div class="promo-copy">
                  <span class="promo-eyebrow">TODAY'S FRESH DEAL</span>
                  <h1><?= h($offer['title']) ?></h1>
                  <p><?= h($offerBadge) ?><?= !empty($offer['coupon_code']) ? ' · Use code ' . h($offer['coupon_code']) : '' ?></p>
                  <a href="<?= BASE_URL ?>/offers.php" class="promo-cta">Explore offer <span aria-hidden="true">→</span></a>
                </div>
                <?php if ($heroAdProduct): ?>
                  <div class="promo-art promo-art-photo">
                    <div class="promo-product-photo"><?= veg_thumb_html($heroAdProduct) ?></div>
                    <span class="promo-product-caption"><?= h($heroAdProduct['name']) ?></span>
                  </div>
                <?php else: ?>
                  <div class="promo-art" aria-hidden="true"><span>🥬</span><span>🥕</span><span>🍅</span></div>
                <?php endif; ?>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <article class="promo-slide is-active">
            <div class="promo-copy">
              <span class="promo-eyebrow">FRESH FROM THE MARKET</span>
              <h1>Good food, picked for your day.</h1>
              <p>Everyday fruits and vegetables, ready for your basket.</p>
              <a href="#shop" class="promo-cta">Shop fresh picks <span aria-hidden="true">→</span></a>
            </div>
            <?php if ($heroAdProduct): ?>
              <div class="promo-art promo-art-photo">
                <div class="promo-product-photo"><?= veg_thumb_html($heroAdProduct) ?></div>
                <span class="promo-product-caption"><?= h($heroAdProduct['name']) ?></span>
              </div>
            <?php else: ?>
              <div class="promo-art" aria-hidden="true"><span>🥬</span><span>🥕</span><span>🍅</span></div>
            <?php endif; ?>
          </article>
        <?php endif; ?>
        <?php if (count($activeOffers) > 1): ?>
          <div class="promo-controls<?= array_filter($activeOffers, static fn(array $offer): bool =>
              (strtoupper(trim((string)($offer['coupon_code'] ?? ''))) === 'WELCOME10'
                  && $offer['discount_type'] === 'percent'
                  && (float)$offer['discount_value'] === 10.0)
              || (strtoupper(trim((string)($offer['coupon_code'] ?? ''))) === 'FREESHIP'
                  && $offer['discount_type'] === 'free_delivery')
          ) ? ' promo-controls-image' : '' ?>" aria-label="Promotion slides">
            <button type="button" id="promoPrevious" aria-label="Previous promotion">‹</button>
            <span id="promoSlideCount">1 / <?= count($activeOffers) ?></span>
            <button type="button" id="promoNext" aria-label="Next promotion">›</button>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="container partner-spotlight-section" aria-labelledby="whyShopHeading">
    <header class="partner-spotlight-heading">
      <h2 id="whyShopHeading">WHY SHOP WITH US?</h2>
      <p>Freshness and savings delivered to your doorstep</p>
    </header>
    <div class="partner-spotlight-card">
      <div class="partner-spotlight-copy">
        <div class="partner-spotlight-eyebrow-row">
          <span class="partner-spotlight-eyebrow">MyVegBasket Exclusive</span>
          <span class="partner-spotlight-badge">FEATURED</span>
        </div>
        <h3>Your Local Veggie Market, <span>Online</span></h3>
        <p class="partner-spotlight-marathi" lang="mr">आता भाजी मार्केट तुमच्या मोबाईलवर!</p>
        <ul class="partner-spotlight-benefits">
          <li><span aria-hidden="true">✓</span> Directly from local farms</li>
          <li><span aria-hidden="true">✓</span> Superfast home delivery</li>
        </ul>
      </div>
      <div class="partner-spotlight-image">
        <img
          src="<?= BASE_URL ?>/assets/images/partner-spotlight-banner.png"
          alt="MyVegBasket brings your local vegetable market online with fresh produce and convenient delivery"
          loading="lazy"
          decoding="async">
      </div>
      <a class="partner-spotlight-cta" href="#shop">Order Now <span aria-hidden="true">→</span></a>
    </div>
  </section>

  <div class="store-catalog-layout container">
    <aside class="catalog-sidebar" aria-labelledby="categoryHeading">
      <div class="catalog-sidebar-heading">
        <h2 id="categoryHeading">SHOP BY AISLE</h2>
        <p>What are you looking for?</p>
      </div>
      <nav class="catalog-sidebar-scroll" aria-label="Product categories">
        <div class="catalog-category-list">
          <?php foreach ($aisleCategories as $aisle): ?>
            <?php $isAisleActive = $category === $aisle['name']; ?>
            <a
              class="catalog-category-item<?= $isAisleActive ? ' is-active' : '' ?>"
              href="<?= BASE_URL ?>/index.php?category=<?= urlencode($aisle['name']) ?><?= $search_q !== '' ? '&q=' . urlencode($search_q) : '' ?>"
              <?= $isAisleActive ? 'aria-current="page"' : '' ?>>
              <span class="catalog-category-icon" aria-hidden="true"><?= $aisle['icon'] ?></span>
              <span class="catalog-category-label"><?= h($aisle['name']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </nav>
    </aside>

    <div class="store-catalog-main">
      <?php if ($category === '' || $category === 'All'): ?>
      <section class="deal-section" aria-labelledby="dealHeading">
        <div class="quick-section-heading">
          <div><p class="section-kicker">LIMITED-TIME SAVINGS</p><h2 id="dealHeading">Sponsored / Brand Deals</h2></div>
          <a href="<?= BASE_URL ?>/offers.php" class="section-text-link">All offers <span aria-hidden="true">→</span></a>
        </div>
        <?php if ($dealProducts): ?>
          <div class="sponsored-row">
            <?php foreach (array_slice($dealProducts, 0, 10) as $dealProduct): ?>
              <?= $renderProductCard($dealProduct, true) ?>
            <?php endforeach; ?>
          </div>
          <p class="ad-disclosure">Deal-priced products shown here. Paid sponsored placements will be labelled when available.</p>
        <?php else: ?>
          <a class="empty-ad-slot" href="<?= BASE_URL ?>/enquiry.php">
            <span class="empty-ad-icon" aria-hidden="true">✳</span>
            <span><strong>Brand promotion space</strong><small>Contact us to feature a verified campaign here.</small></span>
            <span class="empty-ad-action">Enquire →</span>
          </a>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="product-section" id="shop" aria-labelledby="shopHeading">
        <div class="quick-section-heading product-heading">
          <div>
            <p class="section-kicker"><?= $category !== '' ? 'SHOP BY CATEGORY' : 'FRESH PICKS FOR YOU' ?></p>
            <h2 id="shopHeading"><?= $category !== '' ? h($category) : ($search_q !== '' ? 'Search results' : 'Fresh from the farm') ?></h2>
            <p class="section-description">Clear prices, fresh produce, and easy add-to-basket shopping.</p>
          </div>
          <?php if ($category !== '' || $search_q !== ''): ?>
            <a href="<?= BASE_URL ?>/index.php" class="section-text-link">Clear filters</a>
          <?php endif; ?>
        </div>

        <?php if (empty($vegetables)): ?>
          <div class="empty-products">
            <span aria-hidden="true">🧺</span>
            <h3>No products found</h3>
            <p>Nothing matched “<?= h($search_q !== '' ? $search_q : $category) ?>”. Try another search or category.</p>
            <a href="<?= BASE_URL ?>/index.php" class="empty-products-link">Browse all products</a>
          </div>
        <?php else: ?>
          <div class="veg-grid">
            <?php foreach ($vegetables as $veg): ?>
              <?= $renderProductCard($veg) ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </div>
  </div>
</main>

<div id="notifyMeOverlay" role="dialog" aria-modal="true" aria-labelledby="notifyMeTitle"
     style="display:none; position:fixed; inset:0; background:rgba(20,30,20,0.6); z-index:1100; align-items:center; justify-content:center; padding:20px;">
  <div style="position:relative; width:100%; max-width:390px; box-sizing:border-box; background:#fff; border-radius:16px; padding:28px; box-shadow:0 20px 50px rgba(0,0,0,0.3);">
    <button type="button" id="notifyMeClose" aria-label="Close"
            style="position:absolute; top:12px; right:14px; background:none; border:0; font-size:1.5rem; color:#5B6656; cursor:pointer;">&times;</button>
    <div style="font-size:2rem;">📧</div>
    <h2 id="notifyMeTitle" style="margin:8px 0;">Get a back-in-stock email</h2>
    <p style="color:#5B6656; margin:0 0 18px;">Enter your email and we’ll let you know when this product is available.</p>
    <form id="notifyMeForm" novalidate>
      <label for="notifyMeEmail" style="display:block; margin-bottom:6px; font-weight:600;">Email address</label>
      <input type="email" id="notifyMeEmail" name="email" required autocomplete="email"
             placeholder="you@example.com"
             style="width:100%; box-sizing:border-box; padding:11px 12px; border:1px solid #D9E0CD; border-radius:8px; margin-bottom:14px;">
      <button type="submit" id="notifyMeSubmit" class="btn btn-primary btn-block">Notify me</button>
    </form>
  </div>
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
