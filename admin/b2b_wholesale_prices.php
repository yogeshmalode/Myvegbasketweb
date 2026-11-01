<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Wholesale Prices';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prices'])) {
    require_csrf();
    $prices = $_POST['wholesale_price'] ?? [];
    $invalidPrices = !is_array($prices);
    if (!$invalidPrices) {
        foreach ($prices as $vegId => $price) {
            if (filter_var($vegId, FILTER_VALIDATE_INT) === false || (int)$vegId <= 0 || !is_scalar($price)) {
                $invalidPrices = true;
                break;
            }
            $price = trim((string)$price);
            if ($price !== '' && (!preg_match('/\A\d+(?:\.\d{1,2})?\z/D', $price) || (float)$price <= 0 || (float)$price > 99999999.99)) {
                $invalidPrices = true;
                break;
            }
        }
    }
    if ($invalidPrices) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'No prices were changed. Enter a price greater than ₹0.00, or leave it blank to use the retail price.'];
    } else {
        $upsert = $pdo->prepare("INSERT INTO wholesale_prices (vegetable_id, wholesale_price) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE wholesale_price = VALUES(wholesale_price)");
        $delete = $pdo->prepare("DELETE FROM wholesale_prices WHERE vegetable_id = ?");
        $saved = 0;
        $cleared = 0;
        $pdo->beginTransaction();
        try {
            foreach ($prices as $vegId => $price) {
                $vegId = (int)$vegId;
                $price = trim((string)$price);
                if ($vegId <= 0) continue;
                if ($price === '') {
                    // Blank input clears the override — the product falls back to
                    // its normal retail/effective price for hotel/shop customers.
                    $delete->execute([$vegId]);
                    $cleared += $delete->rowCount();
                } else {
                    $upsert->execute([$vegId, (float)$price]);
                    $saved++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $_SESSION['flash'] = ['type' => 'success', 'message' => "Wholesale prices saved: $saved updated, $cleared cleared."];
    }
    header('Location: b2b_wholesale_prices.php');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT v.id, v.name, v.unit, v.price, v.sale_price, wp.wholesale_price
    FROM vegetables v LEFT JOIN wholesale_prices wp ON wp.vegetable_id = v.id
    WHERE v.is_active = 1" . ($search !== '' ? " AND v.name LIKE :q" : "") . " ORDER BY v.name";
$stmt = $pdo->prepare($sql);
if ($search !== '') $stmt->execute(['q' => '%' . $search . '%']);
else $stmt->execute();
$vegetables = $stmt->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head" style="text-align:left;margin-top:0"><h2 style="display:block">Wholesale Prices</h2>
  <p>One shared wholesale price list used for every Hotel &amp; Shop (B2B) account, on both the storefront and B2B Billing. Enter a price per product unit; gram/kg/litre pack options use the matching portion of that rate. Leave a field blank to use the normal retail/effective price.</p>
</div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<form method="get" class="b2b-price-search">
  <label for="wholesaleSearch">Find products</label>
  <input type="search" id="wholesaleSearch" name="q" value="<?= h($search) ?>" placeholder="Search products">
  <button class="btn">Search</button>
  <span id="wholesaleCount"><?= count($vegetables) ?> products</span>
</form>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="save_prices" value="1">
  <div class="table-wrap">
    <table>
      <tr><th>Product</th><th>Unit</th><th>Retail Price</th><th>Wholesale Price (₹)</th><th>Price preview</th></tr>
      <?php foreach ($vegetables as $v): $retail = get_effective_price($v); ?>
        <tr class="b2b-price-row" data-name="<?= h(mb_strtolower($v['name'])) ?>" data-retail="<?= h(number_format($retail, 2, '.', '')) ?>">
          <td><?= h($v['name']) ?></td>
          <td><?= h($v['unit']) ?></td>
          <td>₹<?= number_format($retail, 2) ?></td>
          <td><input class="b2b-price-input" type="number" name="wholesale_price[<?= $v['id'] ?>]" min="0.01" step="0.01" value="<?= $v['wholesale_price'] !== null ? h($v['wholesale_price']) : '' ?>" placeholder="Retail fallback" aria-label="Wholesale price for <?= h($v['name']) ?>"></td>
          <td class="b2b-price-preview"><?= $v['wholesale_price'] !== null && (float)$v['wholesale_price'] > 0 ? ((float)$v['wholesale_price'] < $retail ? 'Save ₹' . number_format($retail - (float)$v['wholesale_price'], 2) . ' / unit' : ((float)$v['wholesale_price'] > $retail ? 'Above retail' : 'Same as retail')) : 'Retail fallback' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vegetables): ?><tr><td colspan="5" style="text-align:center; color:#5B6656;">No products found.</td></tr><?php else: ?><tr id="b2bPriceNoMatch" hidden><td colspan="5" style="text-align:center;color:#5B6656;">No matching products.</td></tr><?php endif; ?>
    </table>
  </div>
  <button class="btn btn-primary b2b-save-prices" style="margin-top:16px;">Save Wholesale Prices</button>
</form>
<script>
document.querySelectorAll('.b2b-price-row').forEach(row=>{
  const input=row.querySelector('.b2b-price-input');
  const preview=row.querySelector('.b2b-price-preview');
  const retail=Number(row.dataset.retail);
  input.addEventListener('input',()=>{
    const wholesale=Number(input.value);
    if(input.value.trim()===''||!Number.isFinite(wholesale)){
      preview.textContent='Retail fallback';
    }else if(wholesale<=0){
      preview.textContent='Enter a price above ₹0';
    }else if(wholesale<retail){
      preview.textContent='Save ₹'+(retail-wholesale).toFixed(2)+' / unit';
    }else if(wholesale>retail){
      preview.textContent='Above retail';
    }else{
      preview.textContent='Same as retail';
    }
  });
});
const priceSearch=document.getElementById('wholesaleSearch');
priceSearch?.addEventListener('input',()=>{
  const query=priceSearch.value.trim().toLowerCase();
  let visible=0;
  document.querySelectorAll('.b2b-price-row').forEach(row=>{
    const matches=row.dataset.name.includes(query);
    row.hidden=!matches;
    if(matches) visible++;
  });
  document.getElementById('wholesaleCount').textContent=visible+' product'+(visible===1?'':'s');
  const noMatch=document.getElementById('b2bPriceNoMatch');
  if(noMatch) noMatch.hidden=visible!==0;
});
</script>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
