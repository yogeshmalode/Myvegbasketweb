<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Offers & Coupons';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title           = trim($_POST['title'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $coupon_code     = strtoupper(trim($_POST['coupon_code'] ?? ''));
    $discount_type   = $_POST['discount_type'] ?? 'percent';
    $discount_value  = (float)($_POST['discount_value'] ?? 0);
    $min_order       = (float)($_POST['min_order_amount'] ?? 0);
    $valid_until     = trim($_POST['valid_until'] ?? '');

    if ($title === '') {
        $error = 'Give the offer a title.';
    } elseif ($coupon_code === '') {
        $error = 'A coupon code is required so customers can redeem this offer.';
    } elseif ($discount_type !== 'free_delivery' && $discount_value <= 0) {
        $error = 'Enter a discount value greater than 0.';
    } else {
        $dup = $pdo->prepare("SELECT id FROM offers WHERE coupon_code = ?");
        $dup->execute([$coupon_code]);
        if ($dup->fetch()) {
            $error = "The code \"$coupon_code\" is already in use by another offer.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO offers (title, description, coupon_code, discount_type, discount_value, min_order_amount, valid_until, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmt->execute([
                $title, $description, $coupon_code, $discount_type,
                $discount_type === 'free_delivery' ? 0 : $discount_value,
                $min_order, $valid_until !== '' ? $valid_until : null,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => "\"$title\" ($coupon_code) added."];
            redirect('offers.php');
        }
    }
}

$offers = $pdo->query("SELECT * FROM offers ORDER BY created_at DESC")->fetchAll();

include __DIR__ . '/includes/admin_header.php';
?>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="display:block;">Offers &amp; Coupons</h2>
  <p>Create coupon codes here — they show up automatically on your public <a href="<?= BASE_URL ?>/offers.php" target="_blank">Offers page</a> and can be redeemed at checkout.</p>
</div>

<?php if ($flash): ?><div class="alert alert-<?= $flash['type'] ?>"><?= h($flash['message']) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

<div class="form-card" style="max-width:640px; margin-bottom:30px;">
  <h3 style="margin-top:0;">New offer</h3>
  <form method="post">
    <div class="form-group">
      <label for="title">Title</label>
      <input type="text" id="title" name="title" value="<?= h($_POST['title'] ?? '') ?>" placeholder="e.g. Weekend Special" required>
    </div>
    <div class="form-group">
      <label for="description">Description</label>
      <input type="text" id="description" name="description" value="<?= h($_POST['description'] ?? '') ?>" placeholder="e.g. 15% off all fruits this weekend">
    </div>
    <div class="form-group">
      <label for="coupon_code">Coupon code</label>
      <input type="text" id="coupon_code" name="coupon_code" value="<?= h($_POST['coupon_code'] ?? '') ?>" placeholder="e.g. WEEKEND15" style="text-transform:uppercase;" required>
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="discount_type">Discount type</label>
        <select id="discount_type" name="discount_type" onchange="document.getElementById('discountValueRow').style.display = this.value === 'free_delivery' ? 'none' : 'block';">
          <option value="percent">Percentage off</option>
          <option value="flat">Flat amount off</option>
          <option value="free_delivery">Free delivery</option>
        </select>
      </div>
      <div class="form-group" style="flex:1;" id="discountValueRow">
        <label for="discount_value">Discount value</label>
        <input type="number" id="discount_value" name="discount_value" step="0.01" min="0" value="<?= h($_POST['discount_value'] ?? '') ?>" placeholder="e.g. 10 for 10%, or 20 for ₹20">
      </div>
    </div>
    <div style="display:flex; gap:14px;">
      <div class="form-group" style="flex:1;">
        <label for="min_order_amount">Minimum order amount (₹)</label>
        <input type="number" id="min_order_amount" name="min_order_amount" step="0.01" min="0" value="<?= h($_POST['min_order_amount'] ?? '0') ?>">
      </div>
      <div class="form-group" style="flex:1;">
        <label for="valid_until">Valid until (optional)</label>
        <input type="date" id="valid_until" name="valid_until" value="<?= h($_POST['valid_until'] ?? '') ?>">
      </div>
    </div>
    <button type="submit" class="btn btn-primary">Create offer</button>
  </form>
</div>

<div class="table-wrap">
  <table>
    <thead>
      <tr><th>Title</th><th>Code</th><th>Discount</th><th>Min. order</th><th>Valid until</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <?php foreach ($offers as $o): ?>
        <?php
          $badge = $o['discount_type'] === 'percent' ? number_format($o['discount_value'], 0) . '% off'
                 : ($o['discount_type'] === 'flat' ? SITE_CURRENCY . number_format($o['discount_value'], 0) . ' off'
                 : 'Free delivery');
          $expired = $o['valid_until'] && $o['valid_until'] < date('Y-m-d');
        ?>
        <tr>
          <td><?= h($o['title']) ?><br><small style="color:#5B6656;"><?= h($o['description']) ?></small></td>
          <td><code style="font-family:var(--font-mono); font-weight:700;"><?= h($o['coupon_code']) ?></code></td>
          <td><?= h($badge) ?></td>
          <td><?= $o['min_order_amount'] > 0 ? SITE_CURRENCY . number_format($o['min_order_amount'],2) : '—' ?></td>
          <td><?= $o['valid_until'] ? format_ist($o['valid_until'], 'd M Y') : '—' ?></td>
          <td>
            <?php if ($expired): ?>
              <span class="badge badge-red">Expired</span>
            <?php elseif ($o['is_active']): ?>
              <span class="badge badge-green">Active</span>
            <?php else: ?>
              <span class="badge badge-red">Disabled</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="toggle_offer.php?id=<?= $o['id'] ?>" style="font-size:0.82rem; color:#1F4E8C;"><?= $o['is_active'] ? 'Disable' : 'Enable' ?></a>
            <br>
            <a href="delete_offer.php?id=<?= $o['id'] ?>" style="font-size:0.82rem; color:#9A2E24;" onclick="return confirm('Delete \'<?= h(addslashes($o['title'])) ?>\'? This can\'t be undone.');">Delete</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($offers)): ?>
        <tr><td colspan="7" style="text-align:center; color:#5B6656;">No offers yet — create one above.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
