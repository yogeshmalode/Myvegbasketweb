<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title = 'Inventory';
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

// Multi-store context: a restricted staff login is always locked to their
// own store. An unrestricted admin can switch stores via the dropdown (or
// view "All Stores", in which case per-store products become read-only
// here — pick one specific store to edit their stock).
$restrictedStoreId = session_store_id();
$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();
if ($restrictedStoreId !== null) {
    $storeFilter = $restrictedStoreId;
} else {
    $storeFilter = (isset($_GET['store']) && $_GET['store'] !== '') ? (int)$_GET['store'] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id=(int)($_POST['id']??0); $cost=max(0,(float)($_POST['cost_price']??0));
    $supplier=trim($_POST['supplier_name']??''); $stock=max(0,(float)($_POST['stock']??0));
    $price=max(0,(float)($_POST['price']??0));
    $postedStoreId = ($_POST['store_id'] ?? '') !== '' ? (int)$_POST['store_id'] : null;
    // A restricted staff account can never post a stock change for a store
    // other than their own, no matter what the form says.
    if ($restrictedStoreId !== null) $postedStoreId = $restrictedStoreId;
    $sale=isset($_POST['auto_price']) ? auto_sale_price($cost) : (trim($_POST['sale_price']??'')!==''?(float)$_POST['sale_price']:null);
    if ($id>0 && $price>0 && ($sale===null || $sale<$price)) {
      try { $pdo->beginTransaction();
        $prodSt=$pdo->prepare("SELECT stock, stock_mode FROM vegetables WHERE id=? FOR UPDATE"); $prodSt->execute([$id]); $current=$prodSt->fetch();
        if(!$current) throw new Exception('Product not found.');
        $isPerStore = ($current['stock_mode'] ?? 'shared') === 'per_store';

        if ($isPerStore && $postedStoreId) {
            $beforeSt = $pdo->prepare("SELECT stock FROM store_inventory WHERE dark_store_id=? AND vegetable_id=?");
            $beforeSt->execute([$postedStoreId, $id]);
            $before = $beforeSt->fetchColumn();
            $before = $before !== false ? (float)$before : 0.0;
            $delta = $stock - $before;
            $pdo->prepare("INSERT INTO store_inventory (dark_store_id, vegetable_id, stock) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE stock = VALUES(stock)")->execute([$postedStoreId, $id, $stock]);
            $stmt=$pdo->prepare("UPDATE vegetables SET cost_price=?, supplier_name=?, price=?, sale_price=? WHERE id=?");
            $stmt->execute([$cost,$supplier?:null,$price,$sale,$id]);
            $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,notes,created_by) VALUES (?, 'adjustment', ?, ?, ?)")->execute([$id,$delta,'Inventory stock/pricing update (per-store)',$_SESSION['admin_id']]);
        } elseif (!$isPerStore) {
            $delta=$stock-(float)$current['stock'];
            $stmt=$pdo->prepare("UPDATE vegetables SET cost_price=?, supplier_name=?, stock=?, price=?, sale_price=? WHERE id=?");
            $stmt->execute([$cost,$supplier?:null,$stock,$price,$sale,$id]);
            $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,notes,created_by) VALUES (?, 'adjustment', ?, ?, ?)")->execute([$id,$delta,'Inventory stock/pricing update',$_SESSION['admin_id']]);
        } else {
            // Per-store product but "All Stores" selected — only price/cost/supplier can be saved from here.
            $stmt=$pdo->prepare("UPDATE vegetables SET cost_price=?, supplier_name=?, price=?, sale_price=? WHERE id=?");
            $stmt->execute([$cost,$supplier?:null,$price,$sale,$id]);
        }
        $pdo->commit(); $_SESSION['flash']=['type'=>'success','message'=>'Inventory updated successfully.'];
      } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash']=['type'=>'error','message'=>'Inventory update failed: '.$e->getMessage()];}
    } else $_SESSION['flash']=['type'=>'error','message'=>'Please enter valid pricing values.'];
    header('Location: inventory.php' . ($storeFilter !== null ? '?store='.$storeFilter : '')); exit;
}

$q=$pdo->prepare("SELECT * FROM vegetables ORDER BY category,name");$q->execute();$vegetables=$q->fetchAll();

// Per-store stock lookup for the currently selected store (used to display
// the right number for stock_mode='per_store' products).
$storeStockMap = [];
if ($storeFilter !== null) {
    $ssq = $pdo->prepare("SELECT vegetable_id, stock FROM store_inventory WHERE dark_store_id = ?");
    $ssq->execute([$storeFilter]);
    foreach ($ssq->fetchAll() as $row) { $storeStockMap[(int)$row['vegetable_id']] = (float)$row['stock']; }
}

// Effective stock shown/used for stats + the Stock column: the store's own
// number for per-store products (when a store is selected), otherwise the
// shared global number.
$effectiveStock = function($v) use ($storeFilter, $storeStockMap) {
    if (($v['stock_mode'] ?? 'shared') === 'per_store') {
        return $storeFilter !== null ? ($storeStockMap[$v['id']] ?? 0.0) : (float)$v['stock'];
    }
    return (float)$v['stock'];
};

$totalProducts=count($vegetables); $lowStock=0; $outStock=0; $stockValue=0;
foreach($vegetables as $v){$s=$effectiveStock($v);if($s<=0)$outStock++;elseif($s<=10)$lowStock++;$stockValue += $s*(float)$v['cost_price'];}
include __DIR__ . '/includes/admin_header.php';
function inv_icon($name){$n=strtolower($name); if(strpos($n,'tomato')!==false)return '🍅';if(strpos($n,'potato')!==false)return '🥔';if(strpos($n,'onion')!==false)return '🧅';if(strpos($n,'carrot')!==false)return '🥕';if(strpos($n,'cucumber')!==false)return '🥒';if(strpos($n,'capsicum')!==false)return '🫑';return '🥬';}
?>
<div class="admin-page-heading"><div><h1>Inventory</h1><p>Manage your vegetable stock, prices and suppliers.</p></div><div class="admin-page-actions"><a class="admin-btn admin-btn-primary" href="add_vegetable.php">＋ Add Product</a></div></div>
<?php if($flash):?><div class="admin-alert admin-alert-<?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif;?>

<div class="admin-filter-bar">
  <div class="admin-field">
    <label>Store</label>
    <?php if ($restrictedStoreId !== null): ?>
      <?php $rsName = ''; foreach ($allStores as $s) { if ((int)$s['id'] === $restrictedStoreId) $rsName = $s['name']; } ?>
      <input type="text" value="<?= h($rsName ?: 'Your store') ?>" disabled style="background:#F4F6EF;">
    <?php else: ?>
      <select onchange="window.location.href = this.value ? ('inventory.php?store=' + this.value) : 'inventory.php';">
        <option value="">All Stores (shared stock only)</option>
        <?php foreach ($allStores as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $storeFilter === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
  </div>
</div>
<p style="color:#5B6656; font-size:0.82rem; margin:-8px 0 14px;">Products set to "Per-Store" mode (Add/Edit Vegetable page) show/edit the selected store's own stock here. "Shared" products use one stock number for every store.</p>

<div class="admin-stat-grid">
  <div class="admin-stat-card"><div class="stat-icon green">▣</div><span>Total Products</span><strong><?=number_format($totalProducts)?></strong><small>In Inventory</small></div>
  <div class="admin-stat-card"><div class="stat-icon orange">⚠</div><span>Low Stock Items</span><strong><?=number_format($lowStock)?></strong><small>Below attention level</small></div>
  <div class="admin-stat-card"><div class="stat-icon purple">□</div><span>Out of Stock</span><strong><?=$outStock?></strong><small>Need attention</small></div>
  <div class="admin-stat-card"><div class="stat-icon green">₹</div><span>Total Stock Value</span><strong>₹<?=number_format($stockValue,2)?></strong><small>At Cost Price</small></div>
</div>
<div class="admin-filter-bar"><div class="admin-field" style="flex:1;min-width:220px"><label>Search products</label><input id="inventorySearch" type="search" placeholder="Search products, suppliers..."></div><div class="admin-field"><label>Stock</label><select id="stockFilter"><option value="all">All</option><option value="in">In Stock</option><option value="low">Low Stock</option><option value="out">Out of Stock</option></select></div></div>
<div class="admin-table-wrap"><table class="admin-table" id="inventoryTable"><thead><tr><th>Product</th><th>Category</th><th>Supplier</th><th>Cost Price (₹)</th><th>Selling Price (₹)</th><th>Stock (Qty)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($vegetables as $v): $formId='inventory-form-'.$v['id']; $isPerStore = ($v['stock_mode'] ?? 'shared') === 'per_store'; $stock=$effectiveStock($v); $status=$stock<=0?'out':($stock<=10?'low':'in'); $stockEditable = !$isPerStore || $storeFilter !== null; ?>
<form id="<?=$formId?>" method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$v['id']?>"><input type="hidden" name="store_id" value="<?=h($storeFilter ?? '')?>"></form>
<tr data-search="<?=h(strtolower($v['name'].' '.$v['category'].' '.($v['supplier_name']??'')))?>" data-stock="<?=$status?>">
<td class="product-cell"><span style="font-size:22px;vertical-align:middle;margin-right:7px"><?=inv_icon($v['name'])?></span><?=h($v['name'])?><span class="subtext"><?=h($v['unit'])?> &middot; <?= $isPerStore ? 'Per-Store' : 'Shared' ?></span></td>
<td><?=h($v['category'])?></td><td><input form="<?=$formId?>" name="supplier_name" value="<?=h($v['supplier_name']??'')?>" maxlength="120" style="width:120px"></td>
<td><input form="<?=$formId?>" type="number" name="cost_price" value="<?=h($v['cost_price'])?>" step=".01" min="0"></td>
<td><input form="<?=$formId?>" type="number" name="price" value="<?=h($v['price'])?>" step=".01" min=".01"></td>
<td><input form="<?=$formId?>" type="number" name="stock" value="<?=h($stock)?>" step="0.001" min="0" <?= $stockEditable ? '' : 'disabled title="Pick a specific store above to edit this product\'s stock"' ?>></td>
<td><span class="admin-badge <?= $status==='in'?'badge-green':($status==='low'?'badge-orange':'badge-red')?>"><?= $status==='in'?'In Stock':($status==='low'?'Low Stock':'Out of Stock')?></span><label class="subtext" style="margin-top:6px"><input form="<?=$formId?>" type="checkbox" name="auto_price" value="1"> Auto 45% (₹<?=number_format(auto_sale_price($v['cost_price']),2)?>)</label></td>
<td><div class="admin-actions"><button class="admin-btn admin-btn-primary admin-btn-small" type="submit" form="<?=$formId?>">Save</button></div></td></tr>
<?php endforeach; if(!$vegetables):?><tr><td colspan="8" class="admin-empty">No products found.</td></tr><?php endif;?></tbody></table></div>
<script>const is=document.getElementById('inventorySearch'),sf=document.getElementById('stockFilter');function fi(){const q=is.value.toLowerCase(),f=sf.value;document.querySelectorAll('#inventoryTable tbody tr[data-search]').forEach(r=>{r.style.display=(r.dataset.search.includes(q)&&(f==='all'||r.dataset.stock===f))?'':'none'})}is.addEventListener('input',fi);sf.addEventListener('change',fi);</script>
<?php include __DIR__ . '/includes/admin_footer.php';
