<?php
require_once __DIR__ . '/includes/auth.php';
require_role(['admin','staff']);
$page_title = 'Reports';

$start = $_GET['start'] ?? date('Y-m-01');
$end   = $_GET['end'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) $start = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) $end = date('Y-m-d');
if ($start > $end) { $tmp = $start; $start = $end; $end = $tmp; }

// Store filter: a restricted staff login is always locked to their own
// store's numbers; an unrestricted admin can pick a specific store or
// leave it on "All Stores" (the historical, pre-multi-store behavior).
$allStores = $pdo->query("SELECT id, name FROM dark_stores WHERE is_active = 1 ORDER BY name")->fetchAll();
$restrictedStoreId = session_store_id();
$storeFilter = $restrictedStoreId;
if ($restrictedStoreId === null && isset($_GET['store']) && $_GET['store'] !== '') {
    $storeFilter = (int)$_GET['store'];
}
$storeCondOrders = $storeFilter !== null ? " AND o.dark_store_id = ?" : "";
$storeCondOrdersUnaliased = $storeFilter !== null ? " AND dark_store_id = ?" : "";
$storeCondWastage = $storeFilter !== null ? " AND w.dark_store_id = ?" : "";
$storeParam = $storeFilter !== null ? [$storeFilter] : [];

// A "paid" order whose order_status is 'cancelled' was refunded/voided and must
// NOT be counted as real revenue — this condition is applied consistently to
// every query below (previously the summary cards counted cancelled-but-paid
// orders while the chart excluded them, so the two never matched).
$paidNotCancelled = "payment_status='paid' AND order_status<>'cancelled'";

// Revenue is read straight off orders.total_amount (what the customer actually
// paid: items − discount + delivery charge) instead of re-deriving it from
// order_items.subtotal, which omits delivery charge and discounts entirely and
// was understating/overstating real sales.
$st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) revenue, COUNT(*) orders FROM orders WHERE $paidNotCancelled AND DATE(created_at) BETWEEN ? AND ?$storeCondOrdersUnaliased");
$st->execute(array_merge([$start, $end], $storeParam));
$r = $st->fetch();
$revenue = (float)$r['revenue'];
$orderCount = (int)$r['orders'];

$ct = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity*oi.cost_price),0) cost FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.$paidNotCancelled AND DATE(o.created_at) BETWEEN ? AND ?$storeCondOrders");
$ct->execute(array_merge([$start, $end], $storeParam));
$cost = (float)$ct->fetchColumn();

$w = $pdo->prepare("SELECT COALESCE(SUM(w.quantity*v.cost_price),0) loss FROM wastage w JOIN vegetables v ON v.id=w.vegetable_id WHERE DATE(w.created_at) BETWEEN ? AND ?$storeCondWastage");
$w->execute(array_merge([$start, $end], $storeParam));
$waste = (float)$w->fetchColumn();

// Refunds actually paid back out on orders that were NOT cancelled (e.g. a
// partial refund for a damaged/missing item on an otherwise completed order).
// Cancelled orders are already excluded from revenue above, so including them
// here too would double-count the loss.
$rf = $pdo->prepare("SELECT COALESCE(SUM(refund_amount),0) FROM orders WHERE refund_status='processed' AND order_status<>'cancelled' AND DATE(created_at) BETWEEN ? AND ?$storeCondOrdersUnaliased");
$rf->execute(array_merge([$start, $end], $storeParam));
$refundsProcessed = (float)$rf->fetchColumn();

// Refunds promised but not yet paid out — shown as a heads-up, not deducted
// from profit yet since the money hasn't left the business.
$pr = $pdo->prepare("SELECT COUNT(*) cnt, COALESCE(SUM(refund_amount),0) amt FROM orders WHERE refund_status='requested' AND DATE(created_at) BETWEEN ? AND ?$storeCondOrdersUnaliased");
$pr->execute(array_merge([$start, $end], $storeParam));
$pendingRefund = $pr->fetch();

$gross = $revenue - $cost;
$net = $gross - $waste - $refundsProcessed;
$margin = $revenue > 0 ? ($net / $revenue) * 100 : 0;
$aov = $orderCount > 0 ? $revenue / $orderCount : 0;

$top = $pdo->prepare("SELECT oi.name,SUM(oi.subtotal) amount,SUM(oi.quantity) qty FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.$paidNotCancelled AND DATE(o.created_at) BETWEEN ? AND ?$storeCondOrders GROUP BY oi.vegetable_id,oi.name ORDER BY amount DESC LIMIT 5");
$top->execute(array_merge([$start, $end], $storeParam));
$topProducts = $top->fetchAll();

// Online vs POS (billing counter) revenue split — one query instead of two.
$src = $pdo->prepare("SELECT source, COALESCE(SUM(total_amount),0) revenue, COUNT(*) orders FROM orders WHERE $paidNotCancelled AND DATE(created_at) BETWEEN ? AND ?$storeCondOrdersUnaliased GROUP BY source");
$src->execute(array_merge([$start, $end], $storeParam));
$bySource = ['online' => ['revenue' => 0.0, 'orders' => 0], 'pos' => ['revenue' => 0.0, 'orders' => 0]];
foreach ($src->fetchAll() as $row) {
    $bySource[$row['source']] = ['revenue' => (float)$row['revenue'], 'orders' => (int)$row['orders']];
}

// Daily sales + daily cost charts: one grouped query each (previously this ran
// one query PER DAY, up to 31 of them, and silently stopped at day 31 for any
// longer range even though the axis labels kept showing the full selected end
// date).
$cq = $pdo->prepare("SELECT DATE(created_at) d, SUM(total_amount) total FROM orders WHERE $paidNotCancelled AND DATE(created_at) BETWEEN ? AND ?$storeCondOrdersUnaliased GROUP BY DATE(created_at)");
$cq->execute(array_merge([$start, $end], $storeParam));
$byDay = [];
foreach ($cq->fetchAll() as $row) { $byDay[$row['d']] = (float)$row['total']; }

$ccq = $pdo->prepare("SELECT DATE(o.created_at) d, SUM(oi.quantity*oi.cost_price) total FROM orders o JOIN order_items oi ON oi.order_id=o.id WHERE o.$paidNotCancelled AND DATE(o.created_at) BETWEEN ? AND ?$storeCondOrders GROUP BY DATE(o.created_at)");
$ccq->execute(array_merge([$start, $end], $storeParam));
$costByDay = [];
foreach ($ccq->fetchAll() as $row) { $costByDay[$row['d']] = (float)$row['total']; }

$days = [];
$cursor = new DateTime($start);
$finish = new DateTime($end);
$finish->modify('+1 day');
while ($cursor < $finish) {
    $d = $cursor->format('Y-m-d');
    $days[] = ['date' => $d, 'value' => $byDay[$d] ?? 0.0, 'cost' => $costByDay[$d] ?? 0.0];
    $cursor->modify('+1 day');
}

// Downsample long ranges into buckets so the chart stays readable instead of
// cramming hundreds of daily points into one polyline.
$maxPoints = 60;
if (count($days) > $maxPoints) {
    $bucketSize = (int)ceil(count($days) / $maxPoints);
    $buckets = [];
    foreach ($days as $i => $d) {
        $bi = intdiv($i, $bucketSize);
        if (!isset($buckets[$bi])) $buckets[$bi] = ['date' => $d['date'], 'value' => 0.0, 'cost' => 0.0];
        $buckets[$bi]['value'] += $d['value'];
        $buckets[$bi]['cost'] += $d['cost'];
    }
    $days = array_values($buckets);
}
$max = max(1, ...array_column($days, 'value'), ...array_column($days, 'cost'));

// Profit-allocation donut: how the sales revenue split between product cost,
// wastage loss, refunds paid out, and what's left as net profit.
$netForChart = max(0, $net);
$donutTotal = max(0.01, $cost + $waste + $refundsProcessed + $netForChart);
$donutSegments = [
    ['label' => 'Net Profit',  'value' => $netForChart,      'color' => '#087354'],
    ['label' => 'Product Cost','value' => $cost,             'color' => '#e87812'],
    ['label' => 'Wastage',     'value' => $waste,            'color' => '#8245d5'],
    ['label' => 'Refunds',     'value' => $refundsProcessed, 'color' => '#df2e24'],
];
$donutCircumference = 2 * M_PI * 70;
$donutOffset = 0;
foreach ($donutSegments as $i => $seg) {
    $frac = $seg['value'] / $donutTotal;
    $donutSegments[$i]['pct'] = $frac * 100;
    $donutSegments[$i]['dash'] = $frac * $donutCircumference;
    $donutSegments[$i]['gap'] = $donutCircumference - $donutSegments[$i]['dash'];
    $donutSegments[$i]['dashoffset'] = -$donutOffset;
    $donutOffset += $donutSegments[$i]['dash'];
}

// Top-products bar scale and online/POS split-bar scale.
$topMax = $topProducts ? max(array_column($topProducts, 'amount')) : 0;
$topMax = max(1, $topMax);
$splitTotal = max(0.01, $bySource['online']['revenue'] + $bySource['pos']['revenue']);
$onlinePct = $bySource['online']['revenue'] / $splitTotal * 100;
$posPct = $bySource['pos']['revenue'] / $splitTotal * 100;

include __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-heading"><div><h1>Reports (P&amp;L)</h1><p>Analyze your business performance and profits for a selected time frame.</p></div><a class="admin-btn" href="export_orders.php?start=<?=h($start)?>&end=<?=h($end)?>">⇩ Download Report</a></div>
<form class="admin-filter-bar report-filter" method="get"><div class="admin-field"><label>Start Date</label><input type="date" name="start" value="<?=h($start)?>"></div><span style="padding-bottom:10px;color:#7a8580;font-size:12px">to</span><div class="admin-field"><label>End Date</label><input type="date" name="end" value="<?=h($end)?>"></div><?php if(is_store_restricted()):?><?php $curS=array_values(array_filter($allStores,fn($s)=>(int)$s['id']===(int)$storeFilter)); ?><div class="admin-field"><label>Store</label><input type="text" value="<?=h($curS[0]['name'] ?? 'Your store')?>" disabled style="background:#f3f6f4"></div><?php elseif(count($allStores) > 1): ?><div class="admin-field"><label>Store</label><select name="store"><option value="">All Stores</option><?php foreach($allStores as $s):?><option value="<?=$s['id']?>" <?=((int)$s['id']===(int)$storeFilter)?'selected':''?>><?=h($s['name'])?></option><?php endforeach;?></select></div><?php endif;?><button class="admin-btn admin-btn-primary" type="submit">Filter</button></form>
<?php if ((int)$pendingRefund['cnt'] > 0): ?>
<div class="admin-alert admin-alert-error" style="margin-bottom:18px">⚠ <?=number_format((int)$pendingRefund['cnt'])?> refund request(s) worth ₹<?=number_format((float)$pendingRefund['amt'],2)?> are still pending in this period — not yet deducted from profit below. <a href="orders.php" style="color:inherit;text-decoration:underline;font-weight:800">Review in Exceptions tab →</a></div>
<?php endif; ?>
<div class="report-cards">
  <div class="report-card"><div class="label">Total Sales</div><div class="value">₹<?=number_format($revenue,2)?></div><div class="trend">↑ <?=number_format($orderCount)?> paid order(s)</div></div>
  <div class="report-card"><div class="label">Total Cost</div><div class="value">₹<?=number_format($cost,2)?></div><div class="trend">Product cost</div></div>
  <div class="report-card"><div class="label">Gross Profit</div><div class="value">₹<?=number_format($gross,2)?></div><div class="trend">Sales − product cost</div></div>
  <div class="report-card"><div class="label">Net Profit</div><div class="value">₹<?=number_format($net,2)?></div><div class="trend"><?=number_format($margin,1)?>% margin</div></div>
</div>
<div class="report-cards" style="margin-top:-2px">
  <div class="report-card"><div class="label">Avg Order Value</div><div class="value">₹<?=number_format($aov,2)?></div><div class="trend">Revenue ÷ orders</div></div>
  <div class="report-card"><div class="label">Online Sales</div><div class="value">₹<?=number_format($bySource['online']['revenue'],2)?></div><div class="trend"><?=number_format($bySource['online']['orders'])?> order(s)</div></div>
  <div class="report-card"><div class="label">POS / Billing Sales</div><div class="value">₹<?=number_format($bySource['pos']['revenue'],2)?></div><div class="trend"><?=number_format($bySource['pos']['orders'])?> order(s)</div></div>
  <div class="report-card"><div class="label">Refunds Processed</div><div class="value">₹<?=number_format($refundsProcessed,2)?></div><div class="trend">Already paid back</div></div>
</div>
<div class="report-grid"><section class="admin-panel"><div class="admin-panel-head"><h2>Revenue vs Cost Trend <small>(Selected Period)</small></h2><span style="font-size:11px;color:#68736f"><?=number_format($orderCount)?> paid order(s)</span></div><div class="report-chart"><svg viewBox="0 0 760 260" preserveAspectRatio="none" aria-label="Revenue vs cost chart"><line x1="0" y1="220" x2="760" y2="220" stroke="#dfe7e2"/><line x1="0" y1="165" x2="760" y2="165" stroke="#edf2ef"/><line x1="0" y1="110" x2="760" y2="110" stroke="#edf2ef"/><line x1="0" y1="55" x2="760" y2="55" stroke="#edf2ef"/><polyline fill="none" stroke="#e87812" stroke-width="3" stroke-dasharray="6 4" points="<?php foreach($days as $i=>$d){$x=count($days)>1?($i/(count($days)-1))*760:380;$y=220-($d['cost']/$max)*175;echo number_format($x,1).','.number_format($y,1).' ';}?>"/><polyline fill="none" stroke="#087354" stroke-width="4" points="<?php foreach($days as $i=>$d){$x=count($days)>1?($i/(count($days)-1))*760:380;$y=220-($d['value']/$max)*175;echo number_format($x,1).','.number_format($y,1).' ';}?>"/></svg><div class="chart-legend"><span><i style="background:#087354"></i>Revenue</span><span><i style="background:#e87812;border-radius:0"></i>Cost</span></div><div class="sales-axis"><span><?=h($start)?></span><span><?=h($end)?></span></div></div></section><section class="admin-panel"><div class="admin-panel-head"><h2>Profit Allocation</h2></div><div class="donut-wrap"><svg viewBox="0 0 200 200" width="170" height="170" aria-label="Profit allocation donut chart"><g transform="rotate(-90 100 100)"><?php foreach($donutSegments as $seg): if($seg['value']<=0) continue; ?><circle cx="100" cy="100" r="70" fill="none" stroke="<?=$seg['color']?>" stroke-width="30" stroke-dasharray="<?=number_format($seg['dash'],2)?> <?=number_format($seg['gap'],2)?>" stroke-dashoffset="<?=number_format($seg['dashoffset'],2)?>"/><?php endforeach; ?></g><circle cx="100" cy="100" r="54" fill="#fff"/><text x="100" y="95" text-anchor="middle" font-size="20" font-weight="800" fill="#17231f"><?=number_format($margin,1)?>%</text><text x="100" y="114" text-anchor="middle" font-size="10" fill="#7a8580">margin</text></svg><div class="donut-legend"><?php foreach($donutSegments as $seg):?><div class="donut-legend-row"><span><i style="background:<?=$seg['color']?>"></i><?=h($seg['label'])?></span><strong>₹<?=number_format($seg['value'],0)?> <small>(<?=number_format($seg['pct'],1)?>%)</small></strong></div><?php endforeach; if($net<0):?><p style="font-size:11px;color:#df2e24;margin:8px 0 0;font-weight:700">⚠ Net loss this period — costs exceeded revenue, chart shows ₹0 profit share.</p><?php endif; ?></div></div></section></div>
<div class="report-grid" style="margin-top:18px;grid-template-columns:.8fr 1.2fr"><section class="admin-panel"><div class="admin-panel-head"><h2>Online vs POS Split</h2></div><div style="padding:4px 20px 20px"><div class="split-bar"><span style="width:<?=number_format($onlinePct,2)?>%;background:#087354"></span><span style="width:<?=number_format($posPct,2)?>%;background:#3777d6"></span></div><div class="split-legend"><span><i style="background:#087354"></i>Online ₹<?=number_format($bySource['online']['revenue'],0)?> (<?=number_format($onlinePct,1)?>%)</span><span><i style="background:#3777d6"></i>POS ₹<?=number_format($bySource['pos']['revenue'],0)?> (<?=number_format($posPct,1)?>%)</span></div></div></section><section class="admin-panel top-products"><div class="admin-panel-head"><h2>Top Selling Products</h2></div><div class="admin-product-list" style="padding:4px 20px 18px"><?php foreach($topProducts as $i=>$p):?><div class="hbar-row"><div class="hbar-label"><span class="top-rank"><?=$i+1?>.</span><?=h($p['name'])?><small><?=number_format($p['qty'],1)?> units</small></div><div class="hbar-track"><span class="hbar-fill" style="width:<?=number_format($p['amount']/$topMax*100,2)?>%"></span></div><div class="hbar-value">₹<?=number_format($p['amount'],0)?></div></div><?php endforeach;if(!$topProducts):?><div class="admin-empty">No sales in this period.</div><?php endif;?></div></section></div>
<div class="admin-panel" style="margin-top:18px"><div class="admin-panel-head"><h2>Profit Summary</h2></div><div style="padding:0 20px 20px">
  <div class="billing-summary-line"><span>Sales Revenue <small style="color:#9aa39e">(items − discount + delivery)</small></span><strong>₹<?=number_format($revenue,2)?></strong></div>
  <div class="billing-summary-line"><span>Product Cost</span><strong>− ₹<?=number_format($cost,2)?></strong></div>
  <div class="billing-total" style="border-top:0;padding-top:6px;margin-top:0"><span>Gross Profit</span><strong>₹<?=number_format($gross,2)?></strong></div>
  <div class="billing-summary-line" style="margin-top:10px"><span>Wastage Loss</span><strong>− ₹<?=number_format($waste,2)?></strong></div>
  <div class="billing-summary-line"><span>Refunds Processed</span><strong>− ₹<?=number_format($refundsProcessed,2)?></strong></div>
  <div class="billing-total"><span>Net Profit</span><strong>₹<?=number_format($net,2)?></strong></div>
  <p style="font-size:11px;color:#7a8580;margin:12px 0 0">Cancelled/refunded-void orders are excluded from Sales Revenue entirely. Refund requests still awaiting processing are shown as an alert above but not deducted here until paid out. Product cost uses the purchase cost captured on each order item at the time of sale — older online orders without a cost snapshot may show zero product cost.</p>
</div></div>
<?php include __DIR__ . '/includes/admin_footer.php'; ?>
