<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title = 'Inventory';
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id=(int)($_POST['id']??0); $cost=max(0,(float)($_POST['cost_price']??0));
    $supplier=trim($_POST['supplier_name']??''); $stock=max(0,(int)($_POST['stock']??0));
    $price=max(0,(float)($_POST['price']??0));
    $sale=isset($_POST['auto_price']) ? auto_sale_price($cost) : (trim($_POST['sale_price']??'')!==''?(float)$_POST['sale_price']:null);
    if ($id>0 && $price>0 && ($sale===null || $sale<$price)) {
      try { $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT stock FROM vegetables WHERE id=? FOR UPDATE"); $lock->execute([$id]); $current=$lock->fetch();
        if(!$current) throw new Exception('Product not found.'); $delta=$stock-(int)$current['stock'];
        $stmt=$pdo->prepare("UPDATE vegetables SET cost_price=?, supplier_name=?, stock=?, price=?, sale_price=? WHERE id=?");
        $stmt->execute([$cost,$supplier?:null,$stock,$price,$sale,$id]);
        $pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,notes,created_by) VALUES (?, 'adjustment', ?, ?, ?)")->execute([$id,$delta,'Inventory stock/pricing update',$_SESSION['admin_id']]);
        $pdo->commit(); $_SESSION['flash']=['type'=>'success','message'=>'Inventory updated successfully.'];
      } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash']=['type'=>'error','message'=>'Inventory update failed: '.$e->getMessage()];}
    } else $_SESSION['flash']=['type'=>'error','message'=>'Please enter valid pricing values.'];
    header('Location: inventory.php'); exit;
}
$q=$pdo->prepare("SELECT * FROM vegetables ORDER BY category,name");$q->execute();$vegetables=$q->fetchAll();
$totalProducts=count($vegetables); $lowStock=0; $outStock=0; $stockValue=0;
foreach($vegetables as $v){$s=(float)$v['stock'];if($s<=0)$outStock++;elseif($s<=10)$lowStock++;$stockValue += $s*(float)$v['cost_price'];}
include __DIR__ . '/includes/admin_header.php';
function inv_icon($name){$n=strtolower($name); if(strpos($n,'tomato')!==false)return '🍅';if(strpos($n,'potato')!==false)return '🥔';if(strpos($n,'onion')!==false)return '🧅';if(strpos($n,'carrot')!==false)return '🥕';if(strpos($n,'cucumber')!==false)return '🥒';if(strpos($n,'capsicum')!==false)return '🫑';return '🥬';}
?>
<div class="admin-page-heading"><div><h1>Inventory</h1><p>Manage your vegetable stock, prices and suppliers.</p></div><div class="admin-page-actions"><a class="admin-btn admin-btn-primary" href="add_vegetable.php">＋ Add Product</a></div></div>
<?php if($flash):?><div class="admin-alert admin-alert-<?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif;?>
<div class="admin-stat-grid">
  <div class="admin-stat-card"><div class="stat-icon green">▣</div><span>Total Products</span><strong><?=number_format($totalProducts)?></strong><small>In Inventory</small></div>
  <div class="admin-stat-card"><div class="stat-icon orange">⚠</div><span>Low Stock Items</span><strong><?=number_format($lowStock)?></strong><small>Below attention level</small></div>
  <div class="admin-stat-card"><div class="stat-icon purple">□</div><span>Out of Stock</span><strong><?=$outStock?></strong><small>Need attention</small></div>
  <div class="admin-stat-card"><div class="stat-icon green">₹</div><span>Total Stock Value</span><strong>₹<?=number_format($stockValue,2)?></strong><small>At Cost Price</small></div>
</div>
<div class="admin-filter-bar"><div class="admin-field" style="flex:1;min-width:220px"><label>Search products</label><input id="inventorySearch" type="search" placeholder="Search products, suppliers..."></div><div class="admin-field"><label>Stock</label><select id="stockFilter"><option value="all">All</option><option value="in">In Stock</option><option value="low">Low Stock</option><option value="out">Out of Stock</option></select></div></div>
<div class="admin-table-wrap"><table class="admin-table" id="inventoryTable"><thead><tr><th>Product</th><th>Category</th><th>Supplier</th><th>Cost Price (₹)</th><th>Selling Price (₹)</th><th>Stock (Qty)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach($vegetables as $v): $formId='inventory-form-'.$v['id']; $stock=(float)$v['stock']; $status=$stock<=0?'out':($stock<=10?'low':'in'); ?>
<form id="<?=$formId?>" method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="id" value="<?=$v['id']?>"></form>
<tr data-search="<?=h(strtolower($v['name'].' '.$v['category'].' '.($v['supplier_name']??'')))?>" data-stock="<?=$status?>">
<td class="product-cell"><span style="font-size:22px;vertical-align:middle;margin-right:7px"><?=inv_icon($v['name'])?></span><?=h($v['name'])?><span class="subtext"><?=h($v['unit'])?></span></td>
<td><?=h($v['category'])?></td><td><input form="<?=$formId?>" name="supplier_name" value="<?=h($v['supplier_name']??'')?>" maxlength="120" style="width:120px"></td>
<td><input form="<?=$formId?>" type="number" name="cost_price" value="<?=h($v['cost_price'])?>" step=".01" min="0"></td>
<td><input form="<?=$formId?>" type="number" name="price" value="<?=h($v['price'])?>" step=".01" min=".01"></td>
<td><input form="<?=$formId?>" type="number" name="stock" value="<?=h($v['stock'])?>" min="0"></td>
<td><span class="admin-badge <?= $status==='in'?'badge-green':($status==='low'?'badge-orange':'badge-red')?>"><?= $status==='in'?'In Stock':($status==='low'?'Low Stock':'Out of Stock')?></span><label class="subtext" style="margin-top:6px"><input form="<?=$formId?>" type="checkbox" name="auto_price" value="1"> Auto 45% (₹<?=number_format(auto_sale_price($v['cost_price']),2)?>)</label></td>
<td><div class="admin-actions"><button class="admin-btn admin-btn-primary admin-btn-small" type="submit" form="<?=$formId?>">Save</button></div></td></tr>
<?php endforeach; if(!$vegetables):?><tr><td colspan="8" class="admin-empty">No products found.</td></tr><?php endif;?></tbody></table></div>
<script>const is=document.getElementById('inventorySearch'),sf=document.getElementById('stockFilter');function fi(){const q=is.value.toLowerCase(),f=sf.value;document.querySelectorAll('#inventoryTable tbody tr[data-search]').forEach(r=>{r.style.display=(r.dataset.search.includes(q)&&(f==='all'||r.dataset.stock===f))?'':'none'})}is.addEventListener('input',fi);sf.addEventListener('change',fi);</script>
<?php include __DIR__ . '/includes/admin_footer.php';
