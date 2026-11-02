<?php
require_once __DIR__ . '/config.php';
$page_title = 'Build a Basket on Your Budget';
$minimumBudget = 100.0;
$maximumBudget = 2000.0;
$budget = 500.0;
$category = 'all';
$basketMode = 'recommended';
$error = '';
$recommendations = [];
$historyBasedBasket = false;
$restoreBasketAfterError = false;
$restoreSelected = [];
$restoreQuantities = [];
$restoreVariants = [];

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
    $modeInput = $_POST['mode'] ?? 'recommended';
    if (!is_string($modeInput) || !in_array($modeInput, ['recommended', 'custom'], true)) {
        $error = 'Choose a valid basket-building option.';
    } else {
        $basketMode = $modeInput;
    }

    $action = $_POST['action'] ?? 'build';
    if (!is_string($action) || !in_array($action, ['build', 'add'], true)) {
        $error = 'Choose whether to build or add your basket.';
    }

    if ($error === '' && $action === 'add') {
        $selected = $_POST['selected'] ?? [];
        $quantities = $_POST['quantities'] ?? [];
        $variantsInput = $_POST['variants'] ?? [];
        if (is_array($selected)) {
            foreach ($selected as $productId => $selectedValue) {
                if (ctype_digit((string)$productId) && is_scalar($selectedValue) && (string)$selectedValue === '1') {
                    $restoreSelected[(int)$productId] = true;
                }
            }
        }
        if (is_array($quantities)) {
            foreach ($quantities as $productId => $quantityValue) {
                $quantityValue = filter_var($quantityValue, FILTER_VALIDATE_INT);
                if (ctype_digit((string)$productId) && $quantityValue !== false && $quantityValue > 0) {
                    $restoreQuantities[(int)$productId] = $quantityValue;
                }
            }
        }
        if (is_array($variantsInput)) {
            foreach ($variantsInput as $productId => $variantIdValue) {
                if (ctype_digit((string)$productId) && is_scalar($variantIdValue) && ctype_digit((string)$variantIdValue)) {
                    $restoreVariants[(int)$productId] = (int)$variantIdValue;
                }
            }
        }
        if (!is_array($selected) || !is_array($quantities) || !is_array($variantsInput) || count($selected) < 1 || count($selected) > 50) {
            $error = 'Select between 1 and 50 products for your basket.';
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

                $variantIdInput = $variantsInput[$productId] ?? '';
                $variant = null;
                if ($variantIdInput !== '' && $variantIdInput !== '0') {
                    if (!is_string($variantIdInput) || !ctype_digit($variantIdInput)) {
                        $error = 'Choose a valid pack size for ' . $product['name'] . '.';
                        break;
                    }
                    try {
                        $variantStmt = $pdo->prepare("SELECT * FROM vegetable_variants WHERE id = ? AND vegetable_id = ?");
                        $variantStmt->execute([(int)$variantIdInput, (int)$product['id']]);
                        $variant = $variantStmt->fetch();
                    } catch (PDOException $exception) {
                        $variant = null;
                    }
                    if (!$variant) {
                        $error = 'That pack size is no longer available for ' . $product['name'] . '.';
                        break;
                    }
                }

                $stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
                $maxQuantity = get_cart_item_stock_limit($pdo, $product['id'], $stock, $product['unit'], $variant['id'] ?? null);
                $cartKey = $variant ? $product['id'] . '-' . $variant['id'] : (string)$product['id'];
                $existingQuantity = (int)($_SESSION['cart'][$cartKey]['qty'] ?? 0);
                if ($quantity + $existingQuantity > $maxQuantity) {
                    $error = $product['name'] . ' does not have enough stock for that quantity.';
                    break;
                }

                $price = $variant
                    ? get_price_for_variant_for_customer($pdo, $product, $variant, $customerType)
                    : get_price_for_customer($pdo, $product, $customerType);
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
                    'variant' => $variant,
                ];
            }

            if ($error === '' && (!$basketItems || $basketTotal > $budget)) {
                $error = 'Your selected items exceed the budget. Adjust quantities and try again.';
            }

            if ($error === '') {
                foreach ($basketItems as $basketItem) {
                    $product = $basketItem['product'];
                    $variant = $basketItem['variant'];
                    $key = $variant ? $product['id'] . '-' . $variant['id'] : (string)$product['id'];
                    if (isset($_SESSION['cart'][$key])) {
                        $_SESSION['cart'][$key]['qty'] += $basketItem['quantity'];
                    } else {
                        $_SESSION['cart'][$key] = [
                            'key' => $key,
                            'id' => (int)$product['id'],
                            'variant_id' => $variant['id'] ?? null,
                            'name' => $variant ? $product['name'] . ' (' . $variant['label'] . ')' : $product['name'],
                            'price' => $basketItem['price'],
                            'unit' => $variant['label'] ?? $product['unit'],
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

    if (($error === '' && $action === 'build') || ($action === 'add' && $error !== '')) {
        $restoreBasketAfterError = $action === 'add';
        $productsStmt = $pdo->query(
            "SELECT v.* FROM vegetables v
             JOIN categories c ON LOWER(TRIM(c.name)) = LOWER(TRIM(v.category)) AND c.is_active = 1
             WHERE v.is_active = 1
             ORDER BY v.category, v.name"
        );
        $catalogProducts = $productsStmt->fetchAll();
        $variantsByProduct = get_variants_by_vegetable($pdo, array_column($catalogProducts, 'id'));
        $uniqueProductsByName = [];
        foreach ($catalogProducts as $product) {
            if ($category !== 'all' && strcasecmp(trim($product['category']), $category) !== 0) {
                continue;
            }
            $stock = get_storefront_display_stock($pdo, $product['id'], $product['stock_mode'] ?? 'shared', $product['stock']);
            $maxQuantity = get_cart_item_stock_limit($pdo, $product['id'], $stock, $product['unit']);
            if ($maxQuantity < 1) {
                continue;
            }
            $productVariants = [];
            foreach ($variantsByProduct[(int)$product['id']] ?? [] as $variant) {
                $variantPrice = get_price_for_variant_for_customer($pdo, $product, $variant, current_customer_type());
                $variantStock = get_cart_item_stock_limit($pdo, $product['id'], $stock, $product['unit'], $variant['id']);
                if ($variantPrice <= 0 || $variantStock < 1) {
                    continue;
                }
                $productVariants[] = [
                    'id' => (int)$variant['id'],
                    'label' => $variant['label'],
                    'price' => $variantPrice,
                    'stock' => $variantStock,
                ];
            }
            if ($productVariants) {
                $defaultVariant = $productVariants[0];
                foreach ($productVariants as $variantOption) {
                    if ($variantOption['price'] < $defaultVariant['price']) {
                        $defaultVariant = $variantOption;
                    }
                }
                $price = $defaultVariant['price'];
                $product['_builder_variant_id'] = $defaultVariant['id'];
                $product['_builder_variant_label'] = $defaultVariant['label'];
                $product['_builder_variants'] = $productVariants;
                $maxQuantity = $defaultVariant['stock'];
            } else {
                $price = get_price_for_customer($pdo, $product, current_customer_type());
                $product['_builder_variant_id'] = 0;
                $product['_builder_variant_label'] = $product['unit'];
                $product['_builder_variants'] = [];
            }
            if ($price <= 0 || $price > $budget) {
                continue;
            }
            $product['_builder_price'] = $price;
            $product['_builder_stock'] = $maxQuantity;
            $productNameKey = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)$product['name'])));
            if (
                !isset($uniqueProductsByName[$productNameKey])
                || $price < $uniqueProductsByName[$productNameKey]['_builder_price']
            ) {
                $uniqueProductsByName[$productNameKey] = $product;
            }
        }

        $productsByCategory = [];
        $productsById = [];
        foreach ($uniqueProductsByName as $product) {
            $productsByCategory[trim($product['category'])][] = $product;
            $productsById[(int)$product['id']] = $product;
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

        if ($basketMode === 'recommended' && is_customer_logged_in()) {
            $historyStmt = $pdo->prepare(
                "SELECT oi.vegetable_id, MAX(recent.created_at) AS last_ordered
                 FROM (
                     SELECT id, created_at
                     FROM orders
                     WHERE customer_id = ?
                       AND order_status NOT IN ('cancelled', 'failed')
                       AND (order_status IN ('delivered', 'paid') OR payment_status = 'paid')
                     ORDER BY created_at DESC
                     LIMIT 10
                 ) recent
                 JOIN order_items oi ON oi.order_id = recent.id
                 WHERE oi.vegetable_id IS NOT NULL
                 GROUP BY oi.vegetable_id
                 ORDER BY last_ordered DESC"
            );
            $historyStmt->execute([(int)current_customer()['id']]);
            foreach ($historyStmt->fetchAll() as $historyItem) {
                $id = (int)$historyItem['vegetable_id'];
                if (!isset($productsById[$id]) || count($chosenIds) >= 8) {
                    continue;
                }

                $product = $productsById[$id];
                if ($product['_builder_price'] > $remaining) {
                    continue;
                }

                $chosenIds[$id] = [
                    'product' => $product,
                    'quantity' => 1,
                ];
                $remaining -= $product['_builder_price'];
                $historyBasedBasket = true;
            }
        }

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

        if ($basketMode === 'custom') {
            foreach ($productsById as $product) {
                $recommendations[] = ['product' => $product, 'quantity' => 1];
            }
        } else {
            foreach ($chosenIds as $chosen) {
                $recommendations[] = $chosen;
            }
        }
        if (!$recommendations && $error === '') {
            $error = 'We could not find in-stock products that fit this budget and preference. Try a higher budget or choose all categories.';
        }
    }
}

$previewTotal = 0.0;
foreach ($recommendations as $recommendation) {
    $product = $recommendation['product'];
    $productId = (int)$product['id'];
    $selected = $restoreBasketAfterError
        ? isset($restoreSelected[$productId])
        : $basketMode !== 'custom';
    if (!$selected) {
        continue;
    }

    $variantId = $restoreBasketAfterError && isset($restoreVariants[$productId])
        ? $restoreVariants[$productId]
        : (int)$product['_builder_variant_id'];
    $selectedPrice = (float)$product['_builder_price'];
    foreach ($product['_builder_variants'] as $variantOption) {
        if ($variantOption['id'] === $variantId) {
            $selectedPrice = (float)$variantOption['price'];
            break;
        }
    }
    $quantity = $restoreBasketAfterError && isset($restoreQuantities[$productId])
        ? $restoreQuantities[$productId]
        : (int)$recommendation['quantity'];
    $previewTotal += $selectedPrice * $quantity;
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
  .budget-controls{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px 20px;align-items:start}
  .budget-controls .form-group{min-width:0;margin:0}
  .budget-controls input,.budget-controls select{box-sizing:border-box;width:100%;min-width:0;min-height:50px}
  .budget-controls select{padding-right:38px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .budget-category-field{min-width:0;padding:16px;border:1px solid #E5ECE1;border-radius:14px;background:#F9FCF7}
  .budget-category-field>label{margin-bottom:8px;color:#334B38;font-weight:750}
  .budget-controls .budget-category-select{display:block;max-width:100%;padding:0 36px 0 12px;border:1px solid #D9E4D5;border-radius:10px;background-color:#fff;color:#26382b;font:inherit;cursor:pointer}
  .budget-category-field .budget-scale-help{margin-top:9px}
  .budget-scale{margin-top:16px;padding:14px 15px;border:1px solid #E5ECE1;border-radius:13px;background:#F9FCF7}
  .budget-scale-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;color:#425846;font-size:.84rem;font-weight:750}
  .budget-scale-value{color:#1F4D36;font-size:1.05rem;font-weight:850;font-variant-numeric:tabular-nums}
  .budget-scale input[type=range]{width:100%;min-height:0;height:7px;margin:15px 0 7px;padding:0;border:0;border-radius:999px;background:transparent;accent-color:#33834B;cursor:pointer}
  .budget-scale-ends{display:flex;justify-content:space-between;color:#718073;font-size:.72rem}
  .budget-scale-help{margin:7px 0 0;color:#697466;font-size:.78rem}
  .budget-presets{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
  .budget-preset{padding:7px 12px;border:1px solid #DCE8D5;border-radius:999px;background:#fff;color:#285F3D;font:inherit;font-size:.82rem;font-weight:700;cursor:pointer;transition:background .18s ease,border-color .18s ease,transform .18s ease}
  .budget-preset:hover{background:#EAF3E6;border-color:#76A96D}
  .budget-preset.is-active,.budget-preset[aria-pressed="true"],.budget-preset.is-active:hover{background:#276943;border-color:#276943;color:#fff;box-shadow:0 3px 9px rgba(39,105,67,.2)}
  .budget-preset:hover{transform:translateY(-1px)}
  .budget-build-actions{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;grid-column:1/-1;margin-top:18px}
  .budget-build-button,.budget-custom-button{box-sizing:border-box;display:flex;align-items:center;justify-content:center;gap:9px;width:100%;min-width:0;min-height:62px;padding:12px clamp(10px,2vw,20px);white-space:normal;text-align:center;line-height:1.3;font-size:1rem;font-weight:800}
  .budget-build-button>span[aria-hidden],.budget-custom-button>span[aria-hidden]{flex:0 0 auto}
  .budget-build-label,.budget-custom-label{min-width:0}
  .budget-build-button{box-shadow:0 8px 18px rgba(187,56,46,.2)}
  .budget-custom-button{border:1px solid #DCE8D5;background:#fff;color:#245A39;font-weight:750}
  .budget-custom-button:hover{background:#F1F7EE;border-color:#AFCDA5}
  .budget-how{display:flex;align-items:center;gap:8px;margin:15px 0 0;color:#64705F;font-size:.82rem}
  .budget-how span{display:grid;place-items:center;flex:0 0 20px;height:20px;border-radius:50%;background:#EDF5E9;color:#285F3D;font-size:.72rem;font-weight:800}
  .budget-results{margin-top:20px;animation:budgetReveal .45s cubic-bezier(.2,.8,.2,1) both}
  .budget-workspace{display:grid;grid-template-columns:minmax(0,1fr) 285px;gap:18px;align-items:start}
  .budget-products-panel{min-width:0}
  .budget-result-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px}
  .budget-result-top h2{margin:0 0 5px;font-size:1.3rem;color:#1F4D36}
  .budget-result-top p{margin:0;color:#697466;font-size:.88rem}
  .budget-result-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:999px;background:#EFF7EB;color:#28603A;font-size:.8rem;font-weight:800}
  .budget-product-tools{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
  .budget-product-search{flex:1 1 220px;min-height:44px;padding:0 13px;border:1px solid #DCE5D8;border-radius:11px;background:#fff;font:inherit}
  .budget-product-filters{display:flex;gap:7px;overflow-x:auto;padding:2px 0 7px;margin-bottom:9px}
  .budget-product[hidden],.budget-how[hidden]{display:none!important}
  .budget-filter-chip{flex:0 0 auto;padding:7px 11px;border:1px solid #DCE8D5;border-radius:999px;background:#fff;color:#3F5946;font:inherit;font-size:.78rem;font-weight:700;cursor:pointer}
  .budget-filter-chip.is-active{background:#276943;border-color:#276943;color:#fff}
  .budget-product-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(225px,1fr));gap:11px}
  .budget-product{display:grid;grid-template-columns:20px 58px minmax(0,1fr);grid-template-rows:auto auto;gap:9px 10px;align-items:center;padding:12px;border:1px solid #E7ECE3;border-radius:15px;background:#fff;cursor:pointer;transition:border-color .2s ease,background .2s ease,transform .2s ease,box-shadow .2s ease}
  .budget-product:hover{transform:translateY(-1px);border-color:#BDD5B5;box-shadow:0 6px 16px rgba(31,77,54,.06)}
  .budget-product.is-selected{border-color:#B8D5AE;background:#FBFDF9}
  .budget-product.is-unselected{background:#fff}
  .budget-product.is-unselected .budget-product-line-total{visibility:hidden}
  .budget-product>input[type=checkbox]{grid-column:1;grid-row:1;width:19px;height:19px;accent-color:#33834B;cursor:pointer}
  .budget-product-image{grid-column:2;grid-row:1;width:58px;height:58px;overflow:hidden;border-radius:12px;background:#F4F7F1}
  .budget-product-image img{width:100%;height:100%;object-fit:contain}
  .budget-product-copy{grid-column:3;grid-row:1;min-width:0}
  .budget-product-name{display:block;color:#273528;font-size:.96rem;font-weight:750}
  .budget-product-meta{display:block;margin-top:4px;color:#697466;font-size:.8rem}
  .budget-variant-label{display:block;grid-column:1/-1;margin-top:2px;color:#526455;font-size:.75rem;font-weight:700}
  .budget-variant-select{box-sizing:border-box;width:100%;min-height:41px;margin-top:5px;padding:7px 32px 7px 10px;border:1px solid #D9E4D5;border-radius:10px;background-color:#fff;color:#26382b;font:inherit;font-size:.82rem;cursor:pointer}
  .budget-variant-select:focus,.budget-product-search:focus,.budget-scale input:focus{outline:3px solid rgba(51,131,75,.22);outline-offset:1px}
  .budget-product-line-total{display:block;margin-top:5px;color:#285F3D;font-size:.81rem;font-weight:800}
  .budget-qty-wrap{box-sizing:border-box;grid-column:2/4;display:flex;align-items:center;justify-self:start;gap:5px;padding:4px;border:1px solid #E0E7DA;border-radius:11px;background:#fff}
  .budget-qty-adjust{box-sizing:border-box;display:grid;place-items:center;flex:0 0 32px;width:32px;height:32px;padding:0;border:0;border-radius:8px;background:#F1F6ED;color:#285F3D;font-size:1rem;font-weight:800;cursor:pointer}
  .budget-qty-adjust:hover{background:#E2EEDB}
  .budget-qty{box-sizing:border-box;flex:0 1 42px;width:42px;min-width:0;padding:5px 2px;border:0;background:transparent;color:#26301F;text-align:center;font:inherit;font-size:.9rem;font-weight:700;appearance:textfield}
  .budget-qty::-webkit-inner-spin-button,.budget-qty::-webkit-outer-spin-button{appearance:none;margin:0}
  .budget-summary{position:sticky;top:18px;margin:0;padding:19px;border:1px solid #DCE8D5;border-radius:17px;background:linear-gradient(160deg,#fff,#F8FCF6);box-shadow:0 12px 30px rgba(31,77,54,.1)}
  .budget-summary-line{display:grid;gap:8px}
  .budget-summary-total{color:#1F4D36;font-size:1.15rem;font-weight:850;font-variant-numeric:tabular-nums}
  .budget-summary-meta{color:#697466;font-size:.8rem}
  .budget-progress{height:8px;overflow:hidden;margin-top:11px;border-radius:999px;background:#EAF0E6}
  .budget-progress-fill{height:100%;width:0;border-radius:inherit;background:linear-gradient(90deg,#57A765,#E2A550);transition:width .32s ease,background .2s ease}
  .budget-progress-fill.is-over{background:#C94A3F}
  .budget-summary-actions{display:grid;gap:10px;margin-top:14px}
  .budget-summary-actions>.budget-custom-button,.budget-summary-actions>.budget-add-button{box-sizing:border-box;width:100%;min-width:0;white-space:normal;text-align:center;justify-content:center;line-height:1.25;overflow-wrap:anywhere}
  .budget-selection-count{color:#64705F;font-size:.82rem}
  .budget-add-button{min-height:45px}
  .budget-error{animation:budgetNudge .3s ease}
  @keyframes budgetReveal{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  @keyframes budgetNudge{25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
  @media(max-width:800px){.budget-workspace{grid-template-columns:1fr}.budget-summary{position:sticky;top:auto;bottom:8px;z-index:4;padding:14px;background:rgba(255,255,255,.97);backdrop-filter:blur(10px)}.budget-summary-line{grid-template-columns:1fr auto;align-items:center}.budget-summary-actions{grid-template-columns:repeat(2,minmax(0,1fr))}.budget-selection-count{grid-column:1/-1}.budget-add-button{min-width:0}}
  @media(max-width:680px){.budget-controls{grid-template-columns:minmax(0,1fr);gap:14px}.budget-build-actions{grid-template-columns:minmax(0,1fr);gap:10px;margin-top:14px}.budget-build-button,.budget-custom-button{min-height:54px}.budget-category-field{padding:14px}}
  @media(max-width:520px){.budget-builder{padding-top:18px;padding-bottom:38px}.budget-hero{border-radius:18px;margin-bottom:14px}.budget-hero:after{right:-2%;bottom:-16px}.budget-product-list{grid-template-columns:1fr 1fr;gap:8px}.budget-product{grid-template-columns:18px 42px minmax(0,1fr);gap:8px;padding:9px}.budget-product-image{width:42px;height:42px}.budget-product-name{font-size:.85rem}.budget-product-meta{font-size:.72rem}.budget-variant-select{min-height:39px;padding-left:7px;font-size:.76rem}.budget-qty-wrap{grid-column:1/-1;justify-self:stretch;justify-content:space-between}.budget-summary{bottom:5px;padding:11px}.budget-summary-total{font-size:1.04rem}.budget-summary-meta{font-size:.72rem}.budget-summary-actions{grid-template-columns:1fr}.budget-add-button{width:100%;min-width:0;padding:10px 12px;font-size:.85rem}}
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
      <input type="hidden" name="mode" id="budgetMode" value="<?= h($basketMode) ?>">
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
          <div class="budget-scale">
            <div class="budget-scale-heading">
              <label for="budgetScale" style="margin:0;">Adjust your basket budget</label>
              <output class="budget-scale-value" id="budgetScaleValue" for="budgetScale"><?= SITE_CURRENCY ?><?= number_format($budget) ?></output>
            </div>
            <input type="range" id="budgetScale" min="<?= (int)$minimumBudget ?>" max="<?= (int)$maximumBudget ?>" step="50" value="<?= h((string)$budget) ?>" aria-label="Scale basket budget">
            <div class="budget-scale-ends"><span><?= SITE_CURRENCY ?><?= number_format($minimumBudget) ?></span><span><?= SITE_CURRENCY ?><?= number_format($maximumBudget) ?></span></div>
            <p class="budget-scale-help">Move the slider or choose an amount above, then build your basket.</p>
          </div>
        </div>
        <div class="form-group budget-category-field">
          <label for="category">Choose a product category</label>
          <select id="category" class="budget-category-select" name="category" aria-describedby="categoryHelp">
            <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>All categories — a mixed basket</option>
            <?php foreach ($budgetCategories as $budgetCategory): ?>
              <option value="<?= h($budgetCategory) ?>" <?= $category === $budgetCategory ? 'selected' : '' ?>><?= h($budgetCategory) ?></option>
            <?php endforeach; ?>
          </select>
          <p id="categoryHelp" class="budget-scale-help">Choose one category or keep all categories for more variety.</p>
        </div>
      </div>
      <div class="budget-build-actions">
        <button type="submit" name="action" value="build" data-build-mode="recommended" class="btn btn-primary budget-build-button"><span aria-hidden="true">✨</span><span class="budget-build-label">Suggest a basket</span></button>
        <button type="submit" name="action" value="build" data-build-mode="custom" class="btn budget-custom-button"><span aria-hidden="true">🧺</span><span class="budget-custom-label">I’ll choose my products</span></button>
      </div>
      <div class="budget-how" id="budgetHelp"><span aria-hidden="true">i</span> Product total only. Delivery is calculated separately in your cart.</div>
    </form>
  </section>

  <?php if ($recommendations): ?>
    <form method="post" action="<?= BASE_URL ?>/budget_basket.php" id="budgetBasketForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="budget" value="<?= h(number_format($budget, 2, '.', '')) ?>">
      <input type="hidden" name="category" value="<?= h($category) ?>">
      <input type="hidden" name="mode" value="<?= h($basketMode) ?>">
      <input type="hidden" name="action" value="add">
      <section class="budget-results" aria-labelledby="budgetItemsHeading">
        <div class="budget-workspace">
          <div class="budget-panel budget-products-panel">
            <div class="budget-result-top">
              <div>
            <span class="budget-result-badge"><span aria-hidden="true">✓</span> Fits your <?= SITE_CURRENCY ?><?= number_format($budget) ?> budget</span>
            <h2 id="budgetItemsHeading" style="margin-top:12px;"><?= $basketMode === 'custom' ? 'Choose your fresh picks' : 'Your suggested basket' ?></h2>
            <p><?= $basketMode === 'custom' ? 'Pick the products you want, then adjust quantities. We’ll keep your total within budget.' : ($historyBasedBasket ? 'Inspired by your recent orders and current availability. Adjust anything you like.' : 'Choose what you need and adjust quantities. Current prices are shown below.') ?></p>
              </div>
              <span class="budget-result-badge"><?= count($recommendations) ?> fresh picks</span>
            </div>
            <?php if ($basketMode === 'custom'): ?>
              <div class="budget-product-tools">
                <input type="search" class="budget-product-search" id="budgetProductSearch" placeholder="Search vegetables..." aria-label="Search products">
              </div>
              <div class="budget-product-filters" id="budgetProductFilters" aria-label="Filter by category">
                <button type="button" class="budget-filter-chip is-active" data-category-filter="all" aria-pressed="true">All products</button>
                <?php foreach ($categoryNames as $categoryName): ?>
                  <button type="button" class="budget-filter-chip" data-category-filter="<?= h(mb_strtolower($categoryName)) ?>" aria-pressed="false"><?= h($categoryName) ?></button>
                <?php endforeach; ?>
              </div>
              <p id="budgetSelectionLimit" class="budget-how" role="status" hidden>Your basket has reached the 50-product limit. Remove a product to select another.</p>
            <?php endif; ?>
            <div class="budget-product-list" id="budgetProductList">
            <?php foreach ($recommendations as $recommendation):
              $product = $recommendation['product'];
              $id = (int)$product['id'];
              $quantity = (int)$recommendation['quantity'];
              $variantOptions = $product['_builder_variants'];
              $selectedVariantId = $restoreBasketAfterError && isset($restoreVariants[$id])
                ? $restoreVariants[$id]
                : (int)$product['_builder_variant_id'];
              $selectedVariant = null;
              foreach ($variantOptions as $variantOption) {
                if ($variantOption['id'] === $selectedVariantId) {
                  $selectedVariant = $variantOption;
                  break;
                }
              }
              if ($variantOptions && !$selectedVariant) {
                $selectedVariant = $variantOptions[0];
                $selectedVariantId = $selectedVariant['id'];
              }
              $price = $selectedVariant ? $selectedVariant['price'] : (float)$product['_builder_price'];
              $maxQuantity = $selectedVariant ? $selectedVariant['stock'] : (int)$product['_builder_stock'];
              if ($restoreBasketAfterError && isset($restoreQuantities[$id])) {
                $quantity = min($restoreQuantities[$id], min(100, $maxQuantity));
              }
              $isSelected = $restoreBasketAfterError
                ? isset($restoreSelected[$id])
                : $basketMode !== 'custom';
              $image = veg_thumb_html($product, false, 'lazy');
            ?>
              <article class="budget-product<?= $isSelected ? ' is-selected' : ' is-unselected' ?>" data-product-name="<?= h(mb_strtolower($product['name'])) ?>" data-product-category="<?= h(mb_strtolower($product['category'])) ?>">
                <input type="checkbox" class="budget-check" id="budget-product-<?= $id ?>" name="selected[<?= $id ?>]" value="1" <?= $isSelected ? 'checked' : '' ?> aria-label="Include <?= h($product['name']) ?>">
                <span class="budget-product-image"><?= $image ?></span>
                <label class="budget-product-copy" for="budget-product-<?= $id ?>">
                  <strong class="budget-product-name"><?= h($product['name']) ?></strong>
                  <small class="budget-product-meta"><?= h($product['category']) ?></small>
                  <small class="budget-product-line-total"><?= SITE_CURRENCY ?><span class="budget-line-amount"><?= number_format($price * $quantity, 2) ?></span></small>
                </label>
                <label class="budget-variant-label" for="budget-variant-<?= $id ?>">Pack size
                  <select class="budget-variant-select" id="budget-variant-<?= $id ?>" name="variants[<?= $id ?>]" data-product-id="<?= $id ?>">
                    <?php if ($variantOptions): ?>
                      <?php foreach ($variantOptions as $variantOption): ?>
                        <option value="<?= (int)$variantOption['id'] ?>" data-price="<?= h(number_format($variantOption['price'], 2, '.', '')) ?>" data-stock="<?= (int)$variantOption['stock'] ?>" <?= (int)$variantOption['id'] === $selectedVariantId ? 'selected' : '' ?>>
                          <?= h($variantOption['label']) ?> · <?= SITE_CURRENCY ?><?= number_format($variantOption['price'], 2) ?>
                        </option>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <option value="0" data-price="<?= h(number_format($price, 2, '.', '')) ?>" data-stock="<?= (int)$maxQuantity ?>" selected>
                        <?= h($product['unit']) ?> · <?= SITE_CURRENCY ?><?= number_format($price, 2) ?>
                      </option>
                    <?php endif; ?>
                  </select>
                </label>
                <span class="budget-qty-wrap" aria-label="Quantity controls for <?= h($product['name']) ?>">
                  <button type="button" class="budget-qty-adjust" data-qty-step="-1" aria-label="Decrease <?= h($product['name']) ?> quantity">−</button>
                  <input class="budget-qty" type="number" name="quantities[<?= $id ?>]" min="1" max="<?= min(100, $maxQuantity) ?>" value="<?= $quantity ?>" aria-label="Quantity of <?= h($product['name']) ?>" <?= $isSelected ? '' : 'disabled' ?>>
                  <button type="button" class="budget-qty-adjust" data-qty-step="1" aria-label="Increase <?= h($product['name']) ?> quantity" <?= $isSelected ? '' : 'disabled' ?>>+</button>
                </span>
              </article>
              <input type="hidden" class="budget-item-price" data-product-id="<?= $id ?>" value="<?= h(number_format($price, 2, '.', '')) ?>">
            <?php endforeach; ?>
            </div>
            <?php if ($basketMode === 'custom'): ?>
              <p id="budgetNoProducts" class="budget-how" hidden>No products match your search. Try another name or category.</p>
            <?php endif; ?>
          </div>
          <aside class="budget-summary" aria-label="Basket summary">
            <div class="budget-summary-line">
              <strong>Basket summary</strong>
              <small class="budget-summary-meta" id="budgetRemaining"><?= SITE_CURRENCY ?><?= number_format(max(0, $budget - $previewTotal), 2) ?> remaining</small>
              <strong class="budget-summary-total" id="budgetPreviewTotal" data-budget="<?= h(number_format($budget, 2, '.', '')) ?>"><?= SITE_CURRENCY ?><span><?= number_format($previewTotal, 2) ?></span></strong>
            </div>
            <div class="budget-progress" role="progressbar" aria-label="Budget used" aria-valuemin="0" aria-valuemax="<?= h(number_format($budget, 2, '.', '')) ?>" aria-valuenow="<?= h(number_format(min($previewTotal, $budget), 2, '.', '')) ?>">
              <div class="budget-progress-fill" id="budgetProgressFill"></div>
            </div>
            <div class="budget-summary-actions">
              <span class="budget-selection-count" id="budgetSelectionCount" aria-live="polite"><?= $basketMode === 'custom' ? 'Choose your first item' : count($recommendations) . ' items selected' ?></span>
              <button type="button" class="btn budget-custom-button" id="budgetSelectAll"><?= $basketMode === 'custom' ? 'Select all products' : 'Select none' ?></button>
              <button type="submit" class="btn btn-primary budget-add-button" id="budgetAddButton" <?= $basketMode === 'custom' ? 'disabled' : '' ?>>Add to basket <span aria-hidden="true">→</span></button>
            </div>
        </aside>
        </div>
      </section>
    </form>
  <?php endif; ?>
</main>

<script>
const budgetBuildForm = document.getElementById('budgetBuildForm');
const budgetInput = document.getElementById('budget');
const budgetScale = document.getElementById('budgetScale');
const budgetScaleValue = document.getElementById('budgetScaleValue');
const budgetPresets = Array.from(document.querySelectorAll('[data-budget-preset]'));
const budgetMode = document.getElementById('budgetMode');
const buildButton = budgetBuildForm.querySelector('button[name="action"][value="build"]');

function syncBudgetPresetSelection() {
  const selectedBudget = Number(budgetInput.value);
  if (Number.isFinite(selectedBudget)) {
    budgetScale.value = String(Math.max(Number(budgetScale.min), Math.min(Number(budgetScale.max), selectedBudget)));
    budgetScaleValue.value = <?= json_encode(SITE_CURRENCY) ?> + new Intl.NumberFormat('en-IN', {maximumFractionDigits: 0}).format(selectedBudget);
    budgetScaleValue.textContent = budgetScaleValue.value;
  }
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
budgetScale.addEventListener('input', function () {
  budgetInput.value = budgetScale.value;
  syncBudgetPresetSelection();
});
budgetBuildForm.querySelectorAll('[data-build-mode]').forEach(function (button) {
  button.addEventListener('click', function () {
    budgetMode.value = button.dataset.buildMode;
  });
});
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
  const selectAllButton = document.getElementById('budgetSelectAll');
  const isCustomMode = budgetForm.querySelector('input[name="mode"]').value === 'custom';
  const selectionLimit = 50;
  const filterButtons = Array.from(document.querySelectorAll('[data-category-filter]'));
  const searchInput = document.getElementById('budgetProductSearch');
  const noProductsMessage = document.getElementById('budgetNoProducts');
  const limitMessage = document.getElementById('budgetSelectionLimit');
  const currencySymbol = <?= json_encode(SITE_CURRENCY) ?>;
  const money = new Intl.NumberFormat('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  function filterProducts() {
    const searchTerm = searchInput ? searchInput.value.trim().toLocaleLowerCase() : '';
    const activeCategory = filterButtons.find(function (button) { return button.classList.contains('is-active'); });
    let visibleCount = 0;
    budgetForm.querySelectorAll('.budget-product').forEach(function (row) {
      const matchesSearch = row.dataset.productName.includes(searchTerm);
      const matchesCategory = !activeCategory || activeCategory.dataset.categoryFilter === 'all' || row.dataset.productCategory === activeCategory.dataset.categoryFilter;
      const visible = matchesSearch && matchesCategory;
      row.hidden = !visible;
      if (visible) visibleCount++;
    });
    if (noProductsMessage) noProductsMessage.hidden = visibleCount > 0;
  }

  function updateBudgetPreview() {
    let total = 0;
    let selectedCount = 0;
    let selectedVisibleCount = 0;
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
        if (!row.hidden) selectedVisibleCount++;
        row.querySelector('.budget-line-amount').textContent = money.format(lineTotal);
      }
    });
    if (selectAllButton) {
      selectAllButton.textContent = selectedVisibleCount ? 'Clear visible selection' : (isCustomMode ? 'Select visible products' : 'Select all');
    }
    if (limitMessage && selectedCount < selectionLimit) limitMessage.hidden = true;
    if (limitMessage && selectedCount >= selectionLimit) limitMessage.hidden = false;
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
    countLabel.textContent = selectedCount
      ? selectedCount + ' product' + (selectedCount === 1 ? '' : 's') + ' selected'
      : 'Choose your first item';
    addButton.disabled = selectedCount === 0 || overBudget;
  }

  budgetForm.querySelectorAll('.budget-check').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
      if (this.checked && budgetForm.querySelectorAll('.budget-check:checked').length > selectionLimit) {
        this.checked = false;
        if (limitMessage) limitMessage.hidden = false;
      }
    });
  });

  budgetForm.querySelectorAll('.budget-variant-select').forEach(function (select) {
    select.addEventListener('change', function () {
      const productId = this.dataset.productId;
      const option = this.selectedOptions[0];
      const quantity = budgetForm.querySelector('.budget-qty[name="quantities[' + productId + ']"]');
      const price = budgetForm.querySelector('.budget-item-price[data-product-id="' + productId + '"]');
      price.value = option.dataset.price;
      quantity.max = option.dataset.stock;
      quantity.value = Math.min(Number(quantity.value) || 1, Number(quantity.max));
      updateBudgetPreview();
    });
  });

  if (searchInput) searchInput.addEventListener('input', filterProducts);
  filterButtons.forEach(function (button) {
    button.addEventListener('click', function () {
      filterButtons.forEach(function (filter) {
        const active = filter === button;
        filter.classList.toggle('is-active', active);
        filter.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      filterProducts();
    });
  });

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
  selectAllButton.addEventListener('click', function () {
    const visibleChecks = Array.from(budgetForm.querySelectorAll('.budget-product:not([hidden]) .budget-check'));
    const selectedVisible = visibleChecks.some(function (checkbox) { return checkbox.checked; });
    if (selectedVisible) {
      visibleChecks.forEach(function (checkbox) { checkbox.checked = false; });
    } else {
      let selectedCount = budgetForm.querySelectorAll('.budget-check:checked').length;
      visibleChecks.forEach(function (checkbox) {
        if (!checkbox.checked && selectedCount < selectionLimit) {
          checkbox.checked = true;
          selectedCount++;
        }
      });
    }
    updateBudgetPreview();
  });
  filterProducts();
  updateBudgetPreview();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
