<?php
require_once __DIR__ . '/includes/auth.php';
require_role('admin');
$page_title = 'Wholesale Prices';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prices'])) {
    require_csrf();
    $prices = $_POST['wholesale_price'] ?? [];
    $upsert = $pdo->prepare("INSERT INTO wholesale_prices (vegetable_id, wholesale_price) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE wholesale_price = VALUES(wholesale_price)");
    $delete = $pdo->prepare("DELETE FROM wholesale_prices WHERE vegetable_id = ?");
    $saved = 0;
    foreach ($prices as $vegId => $price) {
        $vegId = (int)$vegId;
        $price = trim((string)$price);
        if ($vegId <= 0) continue;
        if ($price === '') {
            // Blank input clears the override — the product falls back to
            // its normal retail/effective price for hotel/shop customers.
            $delete->execute([$vegId]);
        } elseif (is_numeric($price) && (float)$price >= 0) {
            $upsert->execute([$vegId, (float)$price]);
            $saved++;
        }
    }
    $_SESSION['flash'] = ['type' => 'success', 'message' => "Wholesale prices updated ($saved product(s))."];
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
  <p>One shared wholesale price list used for every Hotel &amp; Shop (B2B) account, on both the storefront and B2B Billing. Leave a field blank to fall back to the normal retail/effective price for that product.</p>
</div>
<?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<form method="get" style="margin-bottom:16px; max-width:360px;">
  <input type="text" name="q" value="<?= h($search) ?>" placeholder="Search products" style="width:100%; padding:8px 10px; border:1px solid #E4E9DD; border-radius:8px;">
</form>
<form method="post">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="save_prices" value="1">
  <div class="table-wrap">
    <table>
      <tr><th>Product</th><th>Unit</th><th>Retail Price</th><th>Wholesale Price (₹)</th></tr>
      <?php foreach ($vegetables as $v): $retail = get_effective_price($v); ?>
        <tr>
          <td><?= h($v['name']) ?></td>
          <td><?= h($v['unit']) ?></td>
          <td>₹<?= number_format($retail, 2) ?></td>
          <td><input type="number" name="wholesale_price[<?= $v['id'] ?>]" min="0" step="0.01" value="<?= $v['wholesale_price'] !== null ? h($v['wholesale_price']) : '' ?>" placeholder="(use retail)" style="width:120px; padding:4px 6px;"></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$vegetables): ?><tr><td colspan="4" style="text-align:center; color:#5B6656;">No products found.</td></tr><?php endif; ?>
    </table>
  </div>
  <button class="btn btn-primary" style="margin-top:16px;">Save Wholesale Prices</button>
</form>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
