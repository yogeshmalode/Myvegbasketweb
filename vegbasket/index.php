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

include __DIR__ . '/includes/header.php';
?>

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
        <div class="veg-thumb"><?= veg_emoji($veg['name']) ?></div>
        <h4><?= h($veg['name']) ?></h4>
        <div class="veg-desc"><?= h($veg['description']) ?></div>
        <div class="price-tag">
          <span class="amount"><?= SITE_CURRENCY ?><?= number_format($veg['price'],2) ?></span>
          <span class="unit">per <?= h($veg['unit']) ?></span>
        </div>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
