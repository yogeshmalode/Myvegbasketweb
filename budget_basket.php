<?php
require_once __DIR__ . '/config.php';
$page_title = 'Build a Basket on Your Budget';
$minimumBudget = 100.0;
$maximumBudget = 2000.0;
$budget = 500.0;
$category = 'all';
$error = '';
$recommendations = [];

$categoryStmt = $pdo->query(
    "SELECT DISTINCT v.category
     FROM vegetables v
     JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(v.category)) AND c.is_active = 1
     WHERE v.is_active = 1 AND TRIM(v.category) <> ''
     ORDER BY v.category"
);
$budgetCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $budgetInput = $_POST['budget'] ?? '';
    $categoryInput = $_POST['category'] ?? 'all';
    if (!is_string($budgetInput) || !is_numeric($budgetInput)) {
        $error = 'Enter a valid budget amount.';
    } else {
        $budget = round((float)$budgetInput, 2);
        if ($budget < $minimumBudget || $budget > $maximumBudget) {
            $error = 'Choose a budget between ' . SITE_CURRENCY . number_format($minimumBudget) . ' and ' . SITE_CURRENCY . number_format($maximumBudget) . '.';
        }
    }

    if (!is_string($categoryInput) || ($categoryInput !== 'all' && !in_array($categoryInput, $budgetCategories, true))) {
        $error = 'Choose a valid product preference.';
    } else {
        $category = $categoryInput;
    }

    $action = $_POST['action'] ?? 'build';
    if (!is_string($action) || !in_array($action, ['build', 'add'], true)) {
        $error = 'Choose whether to build or add your basket.';
    }

    if ($error === '' && $action === 'add') {
        $selected = $_POST['selected'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        if (!is_array($selected) || !is_array($quantities) || count($selected) < 1 || count($selected) > 8) {
            $error = 'Select between 1 and 8 products for your basket.';
        } else {
            $basketItems = [];
            $basketTotal = 0.0;
            $customerType = current_customer_type();
            $productStmt = $pdo->prepare(
                "SELECT v.* FROM vegetables v
                 JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(v.category)) AND c.is_active = 1
                 WHERE v.id = ? AND v.is_active = 1"
            );

            foreach ($selected as $productId => $selectedValue) {
                if (!ctype_digit((string)$productId) || !is_scalar($selectedValue) || (string)$selectedValue !== '1') {
                    $error = 'One of the selected products is invalid. Please build the basket again.';
                    break;
                }
                $quantityInput = $quantities[$productId] ?? '';
                $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 1 || $quantity > 100) {
                    $error = 'Choose a valid quantity for each selected product.';
                    break;
                }

                $productStmt->execute([(int)$productId]);
                $product = $productStmt->fetch();
                if (!$product || ($category !== 'all' && strcasecmp(trim($product['category']), $category) !== 0)) {
                    $error = 'A selected product is no longer available. Please build the basket again.';
                    break;
                }

                $stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
                $maxQuantity = get_cart_item_stock_limit($pdo, $product['id'], $stock, $product['unit']);
                $existingQuantity = (int)($_SESSION['cart'][(string)$product['id']]['qty'] ?? 0);
                if ($quantity + $existingQuantity > $maxQuantity) {
                    $error = $product['name'] . ' does not have enough stock for that quantity.';
                    break;
                }

                $price = get_price_for_customer($pdo, $product, $customerType);
                if ($price <= 0) {
                    $error = $product['name'] . ' does not have a valid price right now.';
                    break;
                }
                $basketTotal = round($basketTotal + ($price * $quantity), 2);
                $basketItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'price' => $price,
                    'stock' => $maxQuantity,
                ];
            }

            if ($error === '' && (!$basketItems || $basketTotal > $budget)) {
                $error = 'Your selected items exceed the budget. Adjust quantities and try again.';
            }

            if ($error === '') {
                foreach ($basketItems as $basketItem) {
                    $product = $basketItem['product'];
                    $key = (string)$product['id'];
                    if (isset($_SESSION['cart'][$key])) {
                        $_SESSION['cart'][$key]['qty'] += $basketItem['quantity'];
                    } else {
                        $_SESSION['cart'][$key] = [
                            'key' => $key,
                            'id' => (int)$product['id'],
                            'variant_id' => null,
                            'name' => $product['name'],
                            'price' => $basketItem['price'],
                            'unit' => $product['unit'],
                            'qty' => $basketItem['quantity'],
                        ];
                    }
                }
                $_SESSION['flash'] = [
                    'type' => 'success',
                    'message' => 'Your budget basket was added. Prices and stock were checked again.',
                ];
                redirect(BASE_URL . '/cart.php');
            }
        }
    }

    if ($error === '' && $action === 'build') {
        $productsStmt = $pdo->query(
            "SELECT v.* FROM vegetables v
             JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(v.category)) AND c.is_active = 1
             WHERE v.is_active = 1
             ORDER BY v.category, v.name"
        );
        $productsByCategory = [];
        foreach ($productsStmt->fetchAll() as $product) {
            if ($category !== 'all' && strcasecmp(trim($product['category']), $category) !== 0) {
                continue;
            }
            $stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
            $maxQuantity = get_cart_item_stock_limit($pdo, $product['id'], $stock, $product['unit']);
            $price = get_price_for_customer($pdo, $product, current_customer_type());
            if ($maxQuantity < 1 || $price <= 0 || $price > $budget) {
                continue;
            }
            $product['_builder_price'] = $price;
            $product['_builder_stock'] = $maxQuantity;
            $productsByCategory[trim($product['category'])][] = $product;
        }

        foreach ($productsByCategory as &$categoryProducts) {
            usort($categoryProducts, static function ($a, $b) {
                return $a['_builder_price'] <=> $b['_builder_price'];
            });
        }
        unset($categoryProducts);

        $categoryNames = array_keys($productsByCategory);
        $categoryOffsets = array_fill_keys($categoryNames, 0);
        $chosenIds = [];
        $remaining = $budget;

        // First pass: try to include one affordable item from each category.
        foreach ($categoryNames as $categoryName) {
            if (count($chosenIds) >= 8) {
                break;
            }
            foreach ($productsByCategory[$categoryName] as $index => $product) {
                $id = (int)$product['id'];
                if ($product['_builder_price'] <= $remaining && !isset($chosenIds[$id])) {
                    $chosenIds[$id] = [
                        'product' => $product,
                        'quantity' => 1,
                    ];
                    $remaining -= $product['_builder_price'];
                    $categoryOffsets[$categoryName] = $index + 1;
                    break;
                }
            }
        }

        // Then add variety from remaining products until the budget or 8-item limit is reached.
        while (count($chosenIds) < 8) {
            $added = false;
            foreach ($categoryNames as $categoryName) {
                $products = $productsByCategory[$categoryName];
                while ($categoryOffsets[$categoryName] < count($products)) {
                    $product = $products[$categoryOffsets[$categoryName]++];
                    $id = (int)$product['id'];
                    if (isset($chosenIds[$id]) || $product['_builder_price'] > $remaining) {
                        continue;
                    }
                    $chosenIds[$id] = ['product' => $product, 'quantity' => 1];
                    $remaining -= $product['_builder_price'];
                    $added = true;
                    break;
                }
                if (count($chosenIds) >= 8) {
                    break;
                }
            }
            if (!$added) {
                break;
            }
        }

        // Use any remaining budget to increase quantities, while respecting stock.
        do {
            $addedQuantity = false;
            foreach ($chosenIds as &$chosen) {
                $price = $chosen['product']['_builder_price'];
                if ($chosen['quantity'] < min(5, $chosen['product']['_builder_stock']) && $price <= $remaining) {
                    $chosen['quantity']++;
                    $remaining -= $price;
                    $addedQuantity = true;
                }
            }
            unset($chosen);
        } while ($addedQuantity);

        foreach ($chosenIds as $chosen) {
            $recommendations[] = $chosen;
        }
        if (!$recommendations) {
            $error = 'We could not find in-stock products that fit this budget and preference. Try a higher budget or choose all categories.';
        }
    }
}

$previewTotal = 0.0;
foreach ($recommendations as $recommendation) {
    $previewTotal += $recommendation['product']['_builder_price'] * $recommendation['quantity'];
}

include __DIR__ . '/includes/header.php';
?>

<style>
  .budget-builder{max-width:980px;padding-top:30px;padding-bottom:64px}
  .budget-hero{position:relative;overflow:hidden;padding:clamp(22px,5vw,38px);border-radius:24px;background:radial-gradient(circle at 90% 15%,rgba(226,157,83,.26),transparent 30%),linear-gradient(130deg,#123F2E,#276943);color:#fff;margin-bottom:20px;box-shadow:0 18px 45px rgba(24,73,49,.16)}
  .budget-hero:after{content:"🥬";position:absolute;right:7%;bottom:-30px;font-size:clamp(6rem,18vw,11rem);opacity:.14;transform:rotate(-18deg);pointer-events:none}
  .budget-hero>*{position:relative;z-index:1}
  .budget-eyebrow{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border:1px solid rgba(255,255,255,.25);border-radius:999px;background:rgba(255,255,255,.1);font-size:.76rem;font-weight:800;letter-spacing:.07em}
  .budget-hero h1{max-width:620px;margin:15px 0 8px;font-size:clamp(1.7rem,5vw,2.65rem);line-height:1.08;color:#fff}
  .budget-hero p{max-width:590px;margin:0;color:#e3f0e2;line-height:1.6}
  .budget-steps{display:flex;gap:8px;flex-wrap:wrap;margin-top:22px}
  .budget-step{display:flex;align-items:center;gap:8px;padding:8px 11px;border-radius:999px;background:rgba(255,255,255,.1);color:#d8ead8;font-size:.8rem;font-weight:700}
  .budget-step span{display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:#fff;color:#1F4D36;font-size:.72rem}
  .budget-step.is-current{background:#fff;color:#1F4D36}
  .budget-panel{padding:clamp(18px,4vw,26px);border:1px solid #E1E9DC;border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(31,77,54,.06)}
  .budget-panel-heading{display:flex;align-items:flex-start;gap:12px;margin-bottom:18px}
  .budget-panel-icon{display:grid;place-items:center;flex:0 0 42px;height:42px;border-radius:13px;background:#EDF5E9;font-size:1.25rem}
  .budget-panel-heading h2{margin:0 0 3px;font-size:1.15rem;color:#1F4D36}
  .budget-panel-heading p{margin:0;color:#697466;font-size:.87rem}
  .budget-controls{display:grid;grid-template-columns:minmax(180px,.8fr) minmax(210px,1fr) auto;gap:14px;align-items:end}
  .budget-controls .form-group{margin:0}
  .budget-controls input,.budget-controls select{min-height:48px}
  .budget-presets{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
  .budget-preset{padding:7px 12px;border:1px solid #DCE8D5;border-radius:999px;background:#fff;color:#285F3D;font:inherit;font-size:.82rem;font-weight:700;cursor:pointer;transition:background .18s ease,border-color .18s ease,transform .18s ease}
  .budget-preset:hover{background:#EAF3E6;border-color:#76A96D}
  .budget-preset.is-active,.budget-preset[aria-pressed="true"],.budget-preset.is-active:hover{background:#276943;border-color:#276943;color:#fff;box-shadow:0 3px 9px rgba(39,105,67,.2)}
  .budget-preset:hover{transform:translateY(-1px)}
  .budget-build-button{min-height:48px;white-space:nowrap;box-shadow:0 6px 14px rgba(187,56,46,.18)}
  .budget-how{display:flex;align-items:center;gap:8px;margin:15px 0 0;color:#64705F;font-size:.82rem}
  .budget-how span{display:grid;place-items:center;flex:0 0 20px;height:20px;border-radius:50%;background:#EDF5E9;color:#285F3D;font-size:.72rem;font-weight:800}
  .budget-results{margin-top:20px;animation:budgetReveal .45s cubic-bezier(.2,.8,.2,1) both}
  .budget-result-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:14px}
  .budget-result-top h2{margin:0 0 5px;font-size:1.3rem;color:#1F4D36}
  .budget-result-top p{margin:0;color:#697466;font-size:.88rem}
  .budget-result-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:999px;background:#EFF7EB;color:#28603A;font-size:.8rem;font-weight:800}
  .budget-product-list{display:grid;gap:9px}
  .budget-product{display:grid;grid-template-columns:24px 66px minmax(0,1fr) auto;gap:13px;align-items:center;padding:12px;border:1px solid #E7ECE3;border-radius:14px;background:#fff;cursor:pointer;transition:border-color .2s ease,background .2s ease,transform .2s ease,box-shadow .2s ease}
  .budget-product:hover{transform:translateY(-1px);border-color:#BDD5B5;box-shadow:0 6px 16px rgba(31,77,54,.06)}
  .budget-product.is-selected{border-color:#B8D5AE;background:#FBFDF9}
  .budget-product.is-unselected{opacity:.62;background:#F8F9F6}
  .budget-product>input[type=checkbox]{width:19px;height:19px;accent-color:#33834B;cursor:pointer}
  .budget-product img{width:66px;height:66px;object-fit:contain;border-radius:12px;background:#F4F7F1}
  .budget-product-copy{min-width:0}
  .budget-product-name{display:block;color:#273528;font-size:.96rem;font-weight:750}
  .budget-product-meta{display:block;margin-top:4px;color:#697466;font-size:.8rem}
  .budget-product-line-total{display:block;margin-top:5px;color:#285F3D;font-size:.81rem;font-weight:800}
  .budget-qty-wrap{display:flex;align-items:center;gap:5px;padding:4px;border:1px solid #E0E7DA;border-radius:11px;background:#fff}
  .budget-qty-adjust{display:grid;place-items:center;width:30px;height:30px;border:0;border-radius:8px;background:#F1F6ED;color:#285F3D;font-size:1rem;font-weight:800;cursor:pointer}
  .budget-qty-adjust:hover{background:#E2EEDB}
  .budget-qty{width:38px;padding:5px 2px;border:0;background:transparent;color:#26301F;text-align:center;font:inherit;font-size:.9rem;font-weight:700;appearance:textfield}
  .budget-qty::-webkit-inner-spin-button,.budget-qty::-webkit-outer-spin-button{appearance:none;margin:0}
  .budget-summary{position:sticky;bottom:10px;z-index:2;margin-top:16px;padding:15px 17px;border:1px solid #DCE8D5;border-radius:15px;background:rgba(255,255,255,.97);box-shadow:0 10px 28px rgba(31,77,54,.14);backdrop-filter:blur(8px)}
  .budget-summary-line{display:flex;justify-content:space-between;align-items:center;gap:12px}
  .budget-summary-total{color:#1F4D36;font-size:1.15rem;font-weight:850;font-variant-numeric:tabular-nums}
  .budget-summary-meta{color:#697466;font-size:.8rem}
  .budget-progress{height:8px;overflow:hidden;margin-top:11px;border-radius:999px;background:#EAF0E6}
  .budget-progress-fill{height:100%;width:0;border-radius:inherit;background:linear-gradient(90deg,#57A765,#E2A550);transition:width .32s ease,background .2s ease}
  .budget-progress-fill.is-over{background:#C94A3F}
  .budget-summary-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:12px}
  .budget-selection-count{color:#64705F;font-size:.82rem}
  .budget-add-button{min-height:45px}
  .budget-error{animation:budgetNudge .3s ease}
  @keyframes budgetReveal{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  @keyframes budgetNudge{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
  @media(max-width:700px){.budget-controls{grid-template-columns:1fr 1fr}.budget-build-button{grid-column:1/-1;width:100%}}
  @media(max-width:520px){.budget-builder{padding-top:18px;padding-bottom:38px}.budget-hero{border-radius:18px;margin-bottom:14px}.budget-hero:after{right:-2%;bottom:-16px}.budget-controls{grid-template-columns:1fr}.budget-build-button{grid-column:auto}.budget-product{grid-template-columns:22px 52px minmax(0,1fr);gap:9px;padding:10px}.budget-product img{width:52px;height:52px}.budget-product-copy{padding-right:2px}.budget-qty-wrap{grid-column:3;justify-self:start;margin-top:3px}.budget-summary{bottom:6px;padding:12px}.budget-summary-actions{align-items:flex-end}.budget-add-button{padding:10px 12px;font-size:.85rem}}
  @media(prefers-reduced-motion:reduce){.budget-results{animation:none}.budget-product,.budget-preset,.budget-progress-fill{transition:none}.budget-error{animation:none}}
</style>

<main class="container budget-builder">
  <div class="budget-hero">
    <span class="budget-eyebrow"><span aria-hidden="true">✦</span> SHOP SMARTER</span>
    <h1>Good food, within your budget.</h1>
    <p>Pick what you want to spend. We’ll make a fresh, in-stock mix for you—then you can adjust it before adding anything to your basket.</p>
    <div class="budget-steps" aria-label="How it works">
      <div class="budget-step is-current"><span>1</span> Set budget</div>
      <div class="budget-step"><span>2</span> Review your mix</div>
      <div class="budget-step"><span>3</span> Add to basket</div>
    </div>
  </div>

  <?php if ($error !== ''): ?>
    <div class="alert alert-error budget-error" role="alert"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="budget-panel" aria-labelledby="budgetSetupHeading">
    <form method="post" action="<?= BASE_URL ?>/budget_basket.php" id="budgetBuildForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="budget-panel-heading">
        <span class="budget-panel-icon" aria-hidden="true">💰</span>
        <div>
          <h2 id="budgetSetupHeading">What’s your budget today?</h2>
          <p>Choose an amount and tell us what you feel like buying.</p>
        </div>
      </div>
      <div class="budget-controls">
        <div class="form-group" style="margin:0;">
          <label for="budget">Budget amount</label>
          <input type="number" id="budget" name="budget" min="100" max="2000" step="50" value="<?= h(number_format($budget, 2, '.', '')) ?>" aria-describedby="budgetHelp" required>
          <div class="budget-presets" aria-label="Quick budget choices">
            <?php foreach ([300, 500, 1000, 1500] as $preset): ?>
              <button type="button" class="budget-preset<?= (float)$budget === (float)$preset ? ' is-active' : '' ?>" data-budget-preset="<?= $preset ?>" aria-pressed="<?= (float)$budget === (float)$preset ? 'true' : 'false' ?>"><?= SITE_CURRENCY ?><?= number_format($preset) ?></button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group" style="margin:0;">
          <label for="category">What would you like?</label>
          <select id="category" name="category">
            <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>A little of everything</option>
            <?php foreach ($budgetCategories as $budgetCategory): ?>
              <option value="<?= h($budgetCategory) ?>" <?= $category === $budgetCategory ? 'selected' : '' ?>><?= h($budgetCategory) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="action" value="build" class="btn btn-primary budget-build-button"><span aria-hidden="true">✨</span> Build my basket</button>
      </div>
      <div class="budget-how" id="budgetHelp"><span aria-hidden="true">i</span> Product total only. Delivery is calculated separately in your cart.</div>
    </form>
  </section>

  <?php if ($recommendations): ?>
    <form method="post" action="<?= BASE_URL ?>/budget_basket.php" id="budgetBasketForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="budget" value="<?= h(number_format($budget, 2, '.', '')) ?>">
      <input type="hidden" name="category" value="<?= h($category) ?>">
      <input type="hidden" name="action" value="add">
      <section class="budget-panel budget-results" aria-labelledby="budgetItemsHeading">
        <div class="budget-result-top">
          <div>
            <span class="budget-result-badge"><span aria-hidden="true">✓</span> Fits your <?= SITE_CURRENCY ?><?= number_format($budget) ?> budget</span>
            <h2 id="budgetItemsHeading" style="margin-top:12px;">Your fresh basket mix</h2>
            <p>Choose what you need and adjust quantities. Current prices are shown below.</p>
          </div>
          <button type="button" class="btn" id="budgetSelectAll" style="background:#fff;border:1px solid #DCE8D5;color:#1F4D36;">Select none</button>
        </div>
        <div class="budget-product-list">
        <?php foreach ($recommendations as $recommendation):
          $product = $recommendation['product'];
          $id = (int)$product['id'];
          $price = (float)$product['_builder_price'];
          $quantity = (int)$recommendation['quantity'];
          $image = veg_thumb_html($product, false, 'lazy');
        ?>
          <label class="budget-product is-selected" for="budget-product-<?= $id ?>">
            <input type="checkbox" class="budget-check" id="budget-product-<?= $id ?>" name="selected[<?= $id ?>]" value="1" checked aria-label="Include <?= h($product['name']) ?>">
            <?= $image ?>
            <span class="budget-product-copy">
              <strong class="budget-product-name"><?= h($product['name']) ?></strong>
              <small class="budget-product-meta"><?= h($product['category']) ?> · <?= SITE_CURRENCY ?><?= number_format($price, 2) ?> / <?= h($product['unit']) ?></small>
              <small class="budget-product-line-total"><?= SITE_CURRENCY ?><span class="budget-line-amount"><?= number_format($price * $quantity, 2) ?></span></small>
            </span>
            <span class="budget-qty-wrap" aria-label="Quantity controls for <?= h($product['name']) ?>">
              <button type="button" class="budget-qty-adjust" data-qty-step="-1" aria-label="Decrease <?= h($product['name']) ?> quantity">−</button>
              <input class="budget-qty" type="number" name="quantities[<?= $id ?>]" min="1" max="<?= min(100, (int)$product['_builder_stock']) ?>" value="<?= $quantity ?>" aria-label="Quantity of <?= h($product['name']) ?>">
              <button type="button" class="budget-qty-adjust" data-qty-step="1" aria-label="Increase <?= h($product['name']) ?> quantity">+</button>
            </span>
          </label>
          <input type="hidden" class="budget-item-price" data-product-id="<?= $id ?>" value="<?= h(number_format($price, 2, '.', '')) ?>">
        <?php endforeach; ?>
        </div>

        <div class="budget-summary">
          <div class="budget-summary-line">
            <span><strong>Basket estimate</strong><small class="budget-summary-meta" id="budgetRemaining"><?= SITE_CURRENCY ?><?= number_format(max(0, $budget - $previewTotal), 2) ?> left to your budget</small></span>
            <strong class="budget-summary-total" id="budgetPreviewTotal" data-budget="<?= h(number_format($budget, 2, '.', '')) ?>"><?= SITE_CURRENCY ?><span><?= number_format($previewTotal, 2) ?></span></strong>
          </div>
          <div class="budget-progress" role="progressbar" aria-label="Budget used" aria-valuemin="0" aria-valuemax="<?= h(number_format($budget, 2, '.', '')) ?>" aria-valuenow="<?= h(number_format(min($previewTotal, $budget), 2, '.', '')) ?>">
            <div class="budget-progress-fill" id="budgetProgressFill"></div>
          </div>
          <div class="budget-summary-actions">
            <span class="budget-selection-count" id="budgetSelectionCount" aria-live="polite"><?= count($recommendations) ?> items selected</span>
            <button type="submit" class="btn btn-primary budget-add-button" id="budgetAddButton">Add selected to basket <span aria-hidden="true">→</span></button>
          </div>
        </div>
      </section>
    </form>
  <?php endif; ?>
</main>

<script>
const budgetBuildForm = document.getElementById('budgetBuildForm');
const budgetInput = document.getElementById('budget');
const budgetPresets = Array.from(document.querySelectorAll('[data-budget-preset]'));
const buildButton = budgetBuildForm.querySelector('button[name="action"][value="build"]');

function syncBudgetPresetSelection() {
  const selectedBudget = Number(budgetInput.value);
  budgetPresets.forEach(function (preset) {
    const active = Number(preset.dataset.budgetPreset) === selectedBudget;
    preset.classList.toggle('is-active', active);
    preset.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
}

budgetPresets.forEach(function (button) {
  button.addEventListener('click', function () {
    if (budgetBuildForm.dataset.rebuilding === 'true') return;
    budgetInput.value = button.dataset.budgetPreset;
    syncBudgetPresetSelection();
    budgetBuildForm.dataset.rebuilding = 'true';
    buildButton.textContent = 'Refreshing your basket...';
    budgetBuildForm.requestSubmit(buildButton);
  });
});

budgetInput.addEventListener('input', syncBudgetPresetSelection);
syncBudgetPresetSelection();

const budgetForm = document.getElementById('budgetBasketForm');
if (budgetForm) {
  const totalElement = document.querySelector('#budgetPreviewTotal span');
  const totalWrap = document.getElementById('budgetPreviewTotal');
  const budgetAmount = Number(totalWrap.dataset.budget);
  const progress = document.querySelector('.budget-progress');
  const progressFill = document.getElementById('budgetProgressFill');
  const remainingLabel = document.getElementById('budgetRemaining');
  const countLabel = document.getElementById('budgetSelectionCount');
  const addButton = document.getElementById('budgetAddButton');
  const currencySymbol = <?= json_encode(SITE_CURRENCY) ?>;
  const money = new Intl.NumberFormat('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  function updateBudgetPreview() {
    let total = 0;
    let selectedCount = 0;
    budgetForm.querySelectorAll('.budget-product').forEach(function (row) {
      const checkbox = row.querySelector('.budget-check');
      const quantity = row.querySelector('.budget-qty');
      const price = budgetForm.querySelector('.budget-item-price[data-product-id="' + checkbox.id.replace('budget-product-', '') + '"]');
      const stepButtons = row.querySelectorAll('.budget-qty-adjust');
      quantity.disabled = !checkbox.checked;
      stepButtons.forEach(function (button) { button.disabled = !checkbox.checked; });
      row.classList.toggle('is-selected', checkbox.checked);
      row.classList.toggle('is-unselected', !checkbox.checked);
      if (checkbox.checked) {
        const safeQuantity = Math.max(1, Math.min(Number(quantity.max), Number(quantity.value) || 1));
        quantity.value = safeQuantity;
        const lineTotal = Number(price.value) * safeQuantity;
        total += lineTotal;
        selectedCount++;
        row.querySelector('.budget-line-amount').textContent = money.format(lineTotal);
      }
    });
    totalElement.textContent = money.format(total);
    const overBudget = total > budgetAmount;
    totalWrap.style.color = overBudget ? '#9A2E24' : '#1F4D36';
    progress.setAttribute('aria-valuenow', Math.min(total, budgetAmount).toFixed(2));
    progress.setAttribute('aria-valuetext', overBudget ? 'Over budget by ' + currencySymbol + money.format(total - budgetAmount) : currencySymbol + money.format(total) + ' used');
    progressFill.style.width = Math.min(100, (total / budgetAmount) * 100) + '%';
    progressFill.classList.toggle('is-over', overBudget);
    remainingLabel.textContent = overBudget
      ? 'Over budget by ' + currencySymbol + money.format(total - budgetAmount)
      : currencySymbol + money.format(budgetAmount - total) + ' left to your budget';
    countLabel.textContent = selectedCount + ' item' + (selectedCount === 1 ? '' : 's') + ' selected';
    addButton.disabled = selectedCount === 0 || overBudget;
  }

  budgetForm.addEventListener('input', updateBudgetPreview);
  budgetForm.addEventListener('change', updateBudgetPreview);
  budgetForm.querySelectorAll('.budget-qty-adjust').forEach(function (button) {
    button.addEventListener('click', function () {
      const quantity = this.parentElement.querySelector('.budget-qty');
      const next = Number(quantity.value) + Number(this.dataset.qtyStep);
      quantity.value = Math.max(Number(quantity.min), Math.min(Number(quantity.max), next));
      updateBudgetPreview();
    });
  });
  document.getElementById('budgetSelectAll').addEventListener('click', function () {
    const checks = budgetForm.querySelectorAll('.budget-check');
    const selectAll = Array.from(checks).some(function (checkbox) { return !checkbox.checked; });
    checks.forEach(function (checkbox) { checkbox.checked = selectAll; });
    this.textContent = selectAll ? 'Select none' : 'Select all';
    updateBudgetPreview();
  });
  updateBudgetPreview();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
