<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title='Billing'; $order=null; $items=[];
function bill_icon($name){$n=strtolower($name);if(strpos($n,'tomato')!==false)return '🍅';if(strpos($n,'potato')!==false)return '🥔';if(strpos($n,'onion')!==false)return '🧅';if(strpos($n,'carrot')!==false)return '🥕';if(strpos($n,'cucumber')!==false)return '🥒';if(strpos($n,'capsicum')!==false)return '🫑';return '🥬';}
if(isset($_GET['receipt'])){ $rid=(int)$_GET['receipt'];$st=$pdo->prepare("SELECT * FROM orders WHERE id=?");$st->execute([$rid]);$ro=$st->fetch();if(!$ro)exit('Receipt not found.');$it=$pdo->prepare("SELECT * FROM order_items WHERE order_id=?");$it->execute([$rid]);$ri=$it->fetchAll();$sub=array_sum(array_column($ri,'subtotal'));$discount=(float)$ro['discount_amount'];?><!doctype html><html><head><meta charset="utf-8"><title>Receipt #<?=$rid?></title><link rel="stylesheet" href="../assets/css/style.css"><link rel="stylesheet" href="admin.css"><style>@media print{.no-print{display:none!important}}body{padding:30px;background:#f7f9f8}.receipt{max-width:620px;margin:auto;background:#fff;padding:28px;border:1px solid #e3e9e5;border-radius:14px}.receipt-header{display:flex;align-items:center;gap:14px;padding-bottom:12px;margin-bottom:8px;border-bottom:1px solid #edf0ee}.receipt-logo{width:74px;height:74px;object-fit:contain;border-radius:12px;background:#f3f8f4;padding:10px}.receipt-brand h2{margin:0;font-size:28px;line-height:1.1;color:#183329}.receipt-brand small{display:block;color:#5d6963;margin-top:4px}.receipt table{width:100%;border-collapse:collapse}.receipt th,.receipt td{padding:10px;border-bottom:1px solid #edf0ee;text-align:left}.receipt-summary{margin-top:8px}.receipt-summary p{margin:4px 0}.receipt-meta{margin:14px 0 10px;color:#42524d}.receipt-meta strong{display:block;color:#17231f}.receipt-total{font-size:18px;color:#103328}</style></head><body><div class="receipt"><div class="receipt-header"><img class="receipt-logo" src="../assets/images/logo-icon.png" alt="<?=h(SITE_NAME)?> logo"><div class="receipt-brand"><h2><?=h(SITE_NAME)?></h2><small>Fresh groceries &amp; daily delivery</small></div></div><div class="receipt-meta"><strong>Bill #<?=$rid?></strong><span><?=h(format_ist($ro['created_at']))?></span></div><p><?=h($ro['customer_name'])?><br><?=h($ro['phone'])?></p><table><tr><th>Item</th><th>Qty</th><th>Total</th></tr><?php foreach($ri as $r):?><tr><td><?=h($r['name'])?></td><td><?=$r['quantity']?></td><td>₹<?=number_format($r['subtotal'],2)?></td></tr><?php endforeach;?></table><div class="receipt-summary"><p>Subtotal: ₹<?=number_format($sub,2)?></p><p>Discount (<?=number_format($sub>0?$discount/$sub*100:0,2)?>%): -₹<?=number_format($discount,2)?></p><p class="receipt-total"><strong>Total: ₹<?=number_format($ro['total_amount'],2)?></strong></p></div><button class="admin-btn admin-btn-primary no-print" onclick="window.print()">Print Receipt</button> <a class="admin-btn no-print" href="billing.php">New Bill</a></div></body></html><?php exit; }
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['checkout'])){
    require_csrf();
    $name=trim($_POST['customer_name']??'Walk-in Customer');
    $email=trim($_POST['email']??'walkin@local');
    $phone=trim($_POST['phone']??'0000000000');
    $address=trim($_POST['address']??'Store counter');
    $discountPct=max(0,min(100,(float)($_POST['discount_percent']??0)));
    $payment=in_array($_POST['payment_method']??'', ['cash','upi_qr'], true)?$_POST['payment_method']:'cash';
    $cart=$_SESSION['billing_cart']??[];

    if(!$cart){
        $_SESSION['flash']=['type'=>'error','message'=>'Add at least one item.'];
    } else {
        try {
            $pdo->beginTransaction();
            $subtotal=0;
            $linesToSave=[];

            foreach($cart as $entry){
                if(!is_array($entry)) continue;

                $vegId=(int)($entry['id'] ?? 0);
                $qty=(float)($entry['qty'] ?? 0);
                if($vegId <= 0 || $qty <= 0) continue;

                $productStmt=$pdo->prepare("SELECT id,name,unit,stock,cost_price,price,sale_price FROM vegetables WHERE id=? FOR UPDATE");
                $productStmt->execute([$vegId]);
                $product=$productStmt->fetch();
                if(!$product) throw new Exception('Invalid product.');

                $variantId = isset($entry['variant_id']) && $entry['variant_id'] !== null ? (int)$entry['variant_id'] : null;
                $variantLabel = null;
                $baseQty = $qty;

                if($variantId){
                    $variantStmt=$pdo->prepare("SELECT id,label,price FROM vegetable_variants WHERE id=? AND vegetable_id=?");
                    $variantStmt->execute([$variantId,$vegId]);
                    $variant=$variantStmt->fetch();
                    if($variant){
                        $variantLabel = (string)($variant['label'] ?? '');
                        $fraction = size_fraction_of_base_unit($variantLabel, $product['unit']);
                        if($fraction !== null && $fraction > 0){
                            $baseQty = round($qty * $fraction, 4);
                        }
                    }
                } else {
                    $unitLabel = trim((string)($entry['unit'] ?? $product['unit']));
                    if($unitLabel !== ''){
                        $fraction = size_fraction_of_base_unit($unitLabel, $product['unit']);
                        if($fraction !== null && $fraction > 0){
                            $baseQty = round($qty * $fraction, 4);
                        }
                    }
                }

                if((float)$product['stock'] < (float)$baseQty) {
                    throw new Exception('Insufficient stock for '.($entry['name'] ?? $product['name']).'.');
                }

                $unitPrice=(float)($entry['price'] ?? get_effective_price($product));
                $lineTotal=round($unitPrice * $qty, 2);
                $subtotal += $lineTotal;
                $linesToSave[] = [
                    'veg_id' => $vegId,
                    'variant_id' => $variantId,
                    'variant_label' => $variantLabel ?: null,
                    'name' => $entry['name'] ?? $product['name'],
                    'qty' => $qty,
                    'base_qty' => $baseQty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'before_stock' => (float)$product['stock'],
                    'cost_price' => (float)($product['cost_price'] ?? 0),
                ];
            }

            if(!$linesToSave) throw new Exception('Add at least one item.');

            $discount=round($subtotal*$discountPct/100,2);
            $total=round($subtotal-$discount,2);

            $st=$pdo->prepare("INSERT INTO orders (customer_name,email,phone,address,total_amount,payment_method,payment_status,order_status,discount_amount) VALUES (?,?,?,?,?,?, 'paid','placed',?)");
            $st->execute([$name,$email,$phone,$address,$total,$payment,$discount]);
            $oid=$pdo->lastInsertId();

            $hasVariantCols=false;
            try {
                $c=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'order_items' AND COLUMN_NAME = 'vegetable_variant_id'");
                $c->execute([DB_NAME]);
                $hasVariantCols=$c->fetchColumn()>0;
            } catch (PDOException $e) {
                $hasVariantCols=false;
            }

            if($hasVariantCols){
                $it=$pdo->prepare("INSERT INTO order_items (order_id,vegetable_id,vegetable_variant_id,variant_label,name,price,quantity,subtotal,cost_price) VALUES (?,?,?,?,?,?,?,?,?)");
            } else {
                $it=$pdo->prepare("INSERT INTO order_items (order_id,vegetable_id,name,price,quantity,subtotal,cost_price) VALUES (?,?,?,?,?,?,?)");
            }

            foreach($linesToSave as $line){
                if($hasVariantCols){
                    $it->execute([$oid,$line['veg_id'],$line['variant_id'],$line['variant_label'],$line['name'],$line['unit_price'],$line['qty'],$line['line_total'],$line['cost_price']]);
                } else {
                    $it->execute([$oid,$line['veg_id'],$line['name'],$line['unit_price'],$line['qty'],$line['line_total'],$line['cost_price']]);
                }
            }

            $up=$pdo->prepare("UPDATE vegetables SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $mv=$pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes,created_by) VALUES (?,'sale',?,?,?,?)");

            foreach($linesToSave as $line){
                $updateResult=$up->execute([$line['base_qty'],$line['veg_id'],$line['base_qty']]);
                if($updateResult === false){
                    throw new Exception('Stock changed during checkout. Please retry.');
                }

                $verify=$pdo->prepare("SELECT stock FROM vegetables WHERE id = ?");
                $verify->execute([$line['veg_id']]);
                $afterStock=(float)($verify->fetchColumn() ?? 0);
                $expectedAfter = $line['before_stock'] - $line['base_qty'];
                if($afterStock < 0 || abs($afterStock - $expectedAfter) > 0.01){
                    throw new Exception('Stock changed during checkout. Please retry.');
                }

                $mv->execute([$line['veg_id'], -(float)$line['base_qty'], $oid, 'Billing sale', $_SESSION['admin_id']]);
            }

            $pdo->commit();
            unset($_SESSION['billing_cart']);
            $order=$oid;
            $_SESSION['flash']=['type'=>'success','message'=>"Bill #$oid created."];
        } catch (Throwable $e) {
            if($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['flash']=['type'=>'error','message'=>'Checkout failed: '.$e->getMessage()];
        }
    }
}
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_item'])){require_csrf();$id=(int)$_POST['vegetable_id'];$q=max(1,(int)$_POST['quantity']);$variantId=(int)($_POST['variant_id']??0);$st=$pdo->prepare("SELECT * FROM vegetables WHERE id=?");$st->execute([$id]);$v=$st->fetch();$variant=null;if($variantId){try{$vStmt=$pdo->prepare("SELECT * FROM vegetable_variants WHERE id=? AND vegetable_id=?");$vStmt->execute([$variantId,$id]);$variant=$vStmt->fetch();}catch(PDOException $e){$variant=null;}}if($v){
    // compute max allowed packs if variant
    $maxAllow = (int)$v['stock'];
    if($variant){
        $frac = size_fraction_of_base_unit($variant['label'],$v['unit']);
        if($frac!==null && $frac>0){
            $maxAllow = (int)floor($v['stock'] / $frac);
        }
    }
    if($maxAllow < 1){ $_SESSION['flash']=['type'=>'error','message'=>'Insufficient stock.']; header('Location: billing.php'); exit; }
    $q = min($q, $maxAllow);
    if(!isset($_SESSION['billing_cart'])) $_SESSION['billing_cart']=[];
    $key = $variant ? $id.'-'.$variant['id'] : (string)$id;
    if(isset($_SESSION['billing_cart'][$key])){ $_SESSION['billing_cart'][$key]['qty'] += $q; }
    else{ $_SESSION['billing_cart'][$key] = ['key'=>$key,'id'=>$v['id'],'variant_id'=>$variant['id']??null,'name'=>$variant ? $v['name'].' ('.$variant['label'].')' : $v['name'],'price'=>$variant ? (float)$variant['price'] : get_effective_price($v),'unit'=>$variant ? $variant['label'] : $v['unit'],'qty'=>$q]; }
    // clamp to maxAllow
    if($_SESSION['billing_cart'][$key]['qty'] > $maxAllow) $_SESSION['billing_cart'][$key]['qty'] = $maxAllow;
}else $_SESSION['flash']=['type'=>'error','message'=>'Invalid product or insufficient stock.'];header('Location: billing.php');exit;}
if(isset($_GET['clear'])){unset($_SESSION['billing_cart']);header('Location: billing.php');exit;}
$q=$pdo->prepare("SELECT id,name,unit,price,sale_price,stock FROM vegetables WHERE is_active=1 AND stock>0 ORDER BY name");$q->execute();$products=$q->fetchAll();$productVariantsRaw = get_variants_by_vegetable($pdo, array_column($products,'id'));// Deduplicate variants by label (keep lowest price for repeated labels)
$productVariants = [];
foreach ($productVariantsRaw as $vid => $variantsList) {
    $map = [];
    foreach ($variantsList as $vv) {
        $label = trim($vv['label']);
        if ($label === '') continue;
        if (!isset($map[$label]) || (float)$vv['price'] < (float)$map[$label]['price']) {
            $map[$label] = $vv;
        }
    }
    $productVariants[$vid] = array_values($map);
}
$cart=$_SESSION['billing_cart']??[];$lines=[];$subtotal=0;if($cart){$qItem=$pdo->prepare("SELECT id,name,unit,price,sale_price,stock,cost_price FROM vegetables WHERE id=?");foreach($cart as $key=>$entry){if(is_array($entry)){ $vId=(int)$entry['id']; $qty=(int)$entry['qty']; $qItem->execute([$vId]); if($vRow=$qItem->fetch()){ $price=(float)$entry['price']; $line=$price*$qty; $subtotal+=$line; $lines[]=['v'=>$vRow,'qty'=>$qty,'price'=>$price,'line'=>$line,'unit'=>$entry['unit'],'variant_id'=>$entry['variant_id']??null]; } } else { $id=(int)$key; $qty=(int)$entry; $qItem->execute([$id]); if($vRow=$qItem->fetch()){ $price=($vRow['sale_price']!==null&&$vRow['sale_price']<$vRow['price'])?$vRow['sale_price']:$vRow['price']; $line=$price*$qty; $subtotal+=$line; $lines[]=['v'=>$vRow,'qty'=>$qty,'price'=>$price,'line'=>$line]; } } } }$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);include __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-heading"><div><h1>Billing (POS)</h1><p>Create bills and manage customer purchases.</p></div><a class="admin-btn" href="billing.php?clear=1">Clear Cart</a></div>
<?php if($flash):?><div class="admin-alert admin-alert-<?=h($flash['type'])?>"><?=h($flash['message'])?><?php if($order):?> <a href="billing.php?receipt=<?=$order?>">Print receipt</a><?php endif;?></div><?php endif;?>
<div class="billing-grid">
<section class="admin-panel"><div class="admin-panel-head"><h2>Select Products</h2></div><div class="billing-search"><input id="billSearch" type="search" placeholder="⌕ Search products by name..."></div><div class="billing-product-list">
<?php foreach($products as $v):$price=($v['sale_price']!==null&&$v['sale_price']<$v['price'])?$v['sale_price']:$v['price'];?><div class="billing-row" data-name="<?=h(strtolower($v['name']))?>"><div class="billing-thumb"><?=bill_icon($v['name'])?></div><div><div class="billing-name"><?=h($v['name'])?></div><div class="billing-meta">₹<?=number_format($price,2)?> / <?=h($v['unit'])?> · <?=$v['stock']?> available</div></div><form method="post" style="display:flex;gap:5px;align-items:center"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="vegetable_id" value="<?=$v['id']?>"><?php if(!empty($productVariants[$v['id']])): ?><select name="variant_id" style="height:31px;border:1px solid #d8e1dc;border-radius:7px;padding:0 5px;margin-right:5px"><?php foreach($productVariants[$v['id']] as $vv): ?><option value="<?=$vv['id']?>"><?=h($vv['label'])?> — ₹<?=number_format($vv['price'],2)?></option><?php endforeach; ?></select><?php endif; ?><?php $maxAttr = $v['stock']; if(!empty($productVariants[$v['id']])){ $firstVar = $productVariants[$v['id']][0]; $frac = size_fraction_of_base_unit($firstVar['label'],$v['unit']); if($frac!==null && $frac>0){ $maxAttr = (int)floor($v['stock'] / $frac); } } ?><input type="number" name="quantity" min="1" max="<?=$maxAttr?>" value="1" style="width:50px;height:31px;border:1px solid #d8e1dc;border-radius:7px;padding:0 5px"><button class="billing-add" name="add_item" value="1">＋</button></form></div><?php endforeach;?></div></section>
<section class="admin-panel"><div class="admin-panel-head"><h2>Current Bill</h2><span style="font-size:11px;color:#df2e24;font-weight:700"><?=count($lines)?> item(s)</span></div><div class="billing-cart">
<?php if($lines):?><table class="billing-cart-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead><tbody><?php foreach($lines as $x):?><tr><td><?=bill_icon($x['v']['name'])?> <?=h($x['v']['name'])?></td><td><?=$x['qty']?> <?=h($x['unit'] ?? $x['v']['unit'])?></td><td>₹<?=number_format($x['price'],2)?></td><td>₹<?=number_format($x['line'],2)?></td></tr><?php endforeach;?></tbody></table>
<div class="billing-summary"><div class="billing-summary-line"><span>Subtotal</span><strong>₹<?=number_format($subtotal,2)?></strong></div></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><input type="hidden" name="checkout" value="1"><div class="admin-form-grid"><div class="admin-field"><label>Customer</label><input name="customer_name" value="Walk-in Customer" required></div><div class="admin-field"><label>Phone</label><input name="phone" value="0000000000"></div><div class="admin-field"><label>Email</label><input type="email" name="email" value="walkin@local"></div><div class="admin-field"><label>Address</label><input name="address" value="Store counter"></div><div class="admin-field"><label>Discount (%)</label><input id="discount" type="number" name="discount_percent" min="0" max="100" step=".01" value="0"></div><div class="admin-field"><label>Payment</label><select name="payment_method"><option value="cash">Cash Payment</option><option value="upi_qr">UPI Payment</option></select></div></div><div class="billing-summary"><div class="billing-summary-line"><span>Discount</span><strong id="discountValue">- ₹0.00</strong></div><div class="billing-total"><span>Total Payable</span><strong id="grandTotal">₹<?=number_format($subtotal,2)?></strong></div></div><div class="payment-grid"><button type="submit" name="checkout" value="1" class="active">Checkout & Create Bill</button><a class="admin-btn" href="billing.php?clear=1">Clear</a></div></form><?php else:?><div class="admin-empty">Your bill is empty.<br>Add products from the left.</div><?php endif;?></div></section></div>
<script>const bs=document.getElementById('billSearch');bs?.addEventListener('input',()=>{const q=bs.value.toLowerCase();document.querySelectorAll('.billing-row').forEach(r=>r.style.display=r.dataset.name.includes(q)?'':'none')});const d=document.getElementById('discount'),dv=document.getElementById('discountValue'),gt=document.getElementById('grandTotal'),sub=<?=json_encode($subtotal)?>;function calc(){const p=Math.min(100,Math.max(0,parseFloat(d?.value||0)));const x=sub*p/100;dv.textContent='- ₹'+x.toFixed(2);gt.textContent='₹'+(sub-x).toFixed(2)}d?.addEventListener('input',calc);</script>
<?php include __DIR__.'/includes/admin_footer.php';
