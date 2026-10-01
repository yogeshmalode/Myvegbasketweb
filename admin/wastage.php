<?php
require_once __DIR__ . '/includes/auth.php';require_role(['admin','staff']);$page_title='Wastage';

// Same per-store context pattern as billing.php: a restricted staff login
// always records wastage against their own store; an unrestricted admin
// can switch which store they're recording for (remembered in session).
$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();
if (isset($_GET['set_store']) && session_store_id() === null) {
    $_SESSION['wastage_store_id'] = (int)$_GET['set_store'] ?: null;
    header('Location: wastage.php');
    exit;
}
$wasteStoreId = session_store_id();
if ($wasteStoreId === null) {
    $wasteStoreId = $_SESSION['wastage_store_id'] ?? (isset($allStores[0]) ? (int)$allStores[0]['id'] : null);
}

if($_SERVER['REQUEST_METHOD']==='POST'){require_csrf();$id=(int)$_POST['vegetable_id'];$qty=max(1,(int)$_POST['quantity']);$reason=$_POST['reason']??'';$notes=trim($_POST['notes']??'');if(!in_array($reason,['Expired','Damaged','Spoiled','Other'],true))$_SESSION['flash']=['type'=>'error','message'=>'Invalid wastage reason.'];else{try{$pdo->beginTransaction();if(get_effective_stock($pdo,$id,$wasteStoreId)<$qty)throw new Exception('Insufficient stock for wastage.');if(!apply_stock_delta($pdo,$id,-$qty,$wasteStoreId))throw new Exception('Stock changed. Please retry.');$i=$pdo->prepare("INSERT INTO wastage (vegetable_id,quantity,reason,notes,created_by,dark_store_id) VALUES (?,?,?,?,?,?)");$i->execute([$id,$qty,$reason,$notes?:null,$_SESSION['admin_id'],$wasteStoreId]);$wid=$pdo->lastInsertId();$m=$pdo->prepare("INSERT INTO inventory_movements (vegetable_id,movement_type,quantity,reference_id,notes,created_by) VALUES (?,'wastage',?,?,?,?)");$m->execute([$id,-$qty,$wid,$reason.($notes?' - '.$notes:''),$_SESSION['admin_id']]);$pdo->commit();$_SESSION['flash']=['type'=>'success','message'=>'Wastage recorded and stock reduced.'];}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$_SESSION['flash']=['type'=>'error','message'=>'Could not record wastage: '.$e->getMessage()];}}header('Location: wastage.php');exit;}
$q=$pdo->prepare("SELECT v.id,v.name,v.unit,
        CASE WHEN v.stock_mode='per_store' THEN COALESCE(si.stock,0) ELSE v.stock END AS stock
    FROM vegetables v
    LEFT JOIN store_inventory si ON si.vegetable_id = v.id AND si.dark_store_id = ?
    WHERE v.is_active=1
    ORDER BY v.name");
$q->execute([$wasteStoreId]);$products=$q->fetchAll();
// Restricted staff only ever see/record wastage for their own store; Admin
// sees every store's records (dark_store_id IS NULL = recorded before
// multi-store existed, always shown so old history doesn't disappear).
$restrictedStoreId = session_store_id();
$wq="SELECT w.*,v.name,a.username,ds.name AS store_name FROM wastage w JOIN vegetables v ON v.id=w.vegetable_id LEFT JOIN admins a ON a.id=w.created_by LEFT JOIN dark_stores ds ON ds.id=w.dark_store_id";
$wParams=[];
if($restrictedStoreId!==null){$wq.=" WHERE w.dark_store_id = ? OR w.dark_store_id IS NULL";$wParams[]=$restrictedStoreId;}
$wq.=" ORDER BY w.created_at DESC LIMIT 100";
$q=$pdo->prepare($wq);$q->execute($wParams);$rows=$q->fetchAll();$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);$qtyTotal=0;$valueTotal=0;$reasons=[];foreach($rows as $r){$qtyTotal+=(float)$r['quantity'];$reasons[$r['reason']]=($reasons[$r['reason']]??0)+1;}
$commonReason=$reasons?array_search(max($reasons),$reasons):'—';include __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-heading"><div><h1>Wastage</h1><p>Record and manage vegetable wastage and stock losses.</p></div><div class="admin-page-actions"><div class="admin-field"><label>Start Date</label><input type="date" id="wStart"></div><div class="admin-field"><label>End Date</label><input type="date" id="wEnd"></div></div></div>
<?php if(!is_store_restricted() && count($allStores) > 1): ?>
<div class="admin-panel" style="margin-bottom:12px;display:flex;align-items:center;gap:10px;padding:10px 14px"><label style="font-weight:700;font-size:13px">Recording wastage for store:</label><select onchange="window.location.href='wastage.php?set_store='+this.value" style="height:32px;border:1px solid #d8e1dc;border-radius:7px;padding:0 8px"><?php foreach($allStores as $s): ?><option value="<?=$s['id']?>" <?=((int)$s['id']===(int)$wasteStoreId)?'selected':''?>><?=h($s['name'])?></option><?php endforeach; ?></select></div>
<?php elseif(is_store_restricted()): ?>
<div class="admin-panel" style="margin-bottom:12px;padding:10px 14px;font-size:13px;font-weight:700">Recording wastage for store: <?php $curS=array_values(array_filter($allStores,fn($s)=>(int)$s['id']===(int)$wasteStoreId)); echo h($curS[0]['name'] ?? 'Your store'); ?></div>
<?php endif; ?>
<?php if($flash):?><div class="admin-alert admin-alert-<?=h($flash['type'])?>"><?=h($flash['message'])?></div><?php endif;?>
<div class="admin-card-grid"><div class="admin-mini-card"><span class="label">Total Wastage (Qty)</span><span class="value"><?=number_format($qtyTotal,1)?> kg</span><span class="hint">Latest 100 records</span></div><div class="admin-mini-card"><span class="label">Wastage Records</span><span class="value"><?=number_format(count($rows))?></span><span class="hint">Tracked records</span></div><div class="admin-mini-card"><span class="label">Most Common Reason</span><span class="value" style="font-size:18px"><?=h($commonReason)?></span><span class="hint">Based on records shown</span></div><div class="admin-mini-card"><span class="label">Stock Control</span><span class="value" style="font-size:18px">Atomic</span><span class="hint">Stock reduces with each record</span></div></div>
<div class="wastage-layout"><section class="admin-form-card"><div class="admin-panel-head" style="padding:0 0 15px"><h2>Add Wastage Record</h2></div><form method="post"><input type="hidden" name="csrf_token" value="<?=h(csrf_token())?>"><div class="admin-field"><label>Product</label><select name="vegetable_id" required><?php foreach($products as $v):?><option value="<?=$v['id']?>"><?=h($v['name'])?> — <?=$v['stock']?> <?=h($v['unit'])?> available</option><?php endforeach;?></select></div><div class="admin-form-grid" style="margin-top:14px"><div class="admin-field"><label>Quantity</label><input type="number" name="quantity" min="1" required></div><div class="admin-field"><label>Reason</label><select name="reason" required><option>Expired</option><option>Damaged</option><option>Spoiled</option><option>Other</option></select></div></div><div class="admin-field" style="margin-top:14px"><label>Notes</label><input name="notes" maxlength="255" placeholder="Optional details"></div><div class="admin-form-actions"><button class="admin-btn admin-btn-primary" type="submit">＋ Record Wastage</button></div></form></section>
<section class="admin-table-wrap"><div class="admin-panel-head"><h2>Wastage Records</h2><span style="font-size:11px;color:#68736f">Latest 100</span></div><table class="admin-table" id="wastageTable"><thead><tr><th>Date</th><th>Product</th><th>Quantity</th><th>Reason</th><th>Notes</th><th>By</th><?php if(!is_store_restricted()):?><th>Store</th><?php endif;?></tr></thead><tbody><?php foreach($rows as $r):$rc=strtolower($r['reason']);?><tr data-date="<?=date('Y-m-d',strtotime($r['created_at']))?>"><td><?=h(date('d M Y',strtotime($r['created_at'])))?></td><td class="product-cell"><?=h($r['name'])?></td><td><?=h($r['quantity'])?></td><td><span class="reason-badge reason-<?=h($rc)?>"><?=h($r['reason'])?></span></td><td><?=h($r['notes']??'—')?></td><td><?=h($r['username']??'—')?></td><?php if(!is_store_restricted()):?><td><?=h($r['store_name']??'—')?></td><?php endif;?></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="admin-empty">No wastage records yet.</td></tr><?php endif;?></tbody></table></section></div>
<script>const ws=document.getElementById('wStart'),we=document.getElementById('wEnd');function wf(){document.querySelectorAll('#wastageTable tbody tr[data-date]').forEach(r=>r.style.display=((!ws.value||r.dataset.date>=ws.value)&&(!we.value||r.dataset.date<=we.value))?'':'none')}ws.addEventListener('change',wf);we.addEventListener('change',wf);</script>
<?php include __DIR__.'/includes/admin_footer.php';
