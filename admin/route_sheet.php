<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Route Sheet';
$orderIdsParam = $_GET['orders'] ?? '';
$riderId = isset($_GET['rider']) ? (int)$_GET['rider'] : 0;
$orderIds = array_filter(array_map('intval', explode(',', $orderIdsParam)));
if (!$orderIds) { echo '<p>No orders specified.</p>'; exit; }

// Default store coordinates (Hadapsar)
$depot = ['lat' => 18.5011, 'lng' => 73.9268];

function haversine($lat1,$lng1,$lat2,$lng2){ $R=6371; $dLat=deg2rad($lat2-$lat1); $dLon=deg2rad($lng2-$lng1); $a=sin($dLat/2)*sin($dLat/2)+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)*sin($dLon/2); $c=2*atan2(sqrt($a),sqrt(1-$a)); return $R*$c; }

$orders = [];
foreach ($orderIds as $oid) {
    $ord = get_order_with_geocoded_address($pdo, $oid);
    if (!$ord) continue;
    if (empty($ord['address_lat']) || empty($ord['address_lng'])) {
        // try geocode now
        $geo = geocode_address($ord['address']);
        if ($geo) { $ord['address_lat'] = $geo['lat']; $ord['address_lng'] = $geo['lng']; $pdo->prepare('UPDATE orders SET address_lat=?, address_lng=? WHERE id=?')->execute([$geo['lat'],$geo['lng'],$oid]); }
    }
    if (empty($ord['address_lat']) || empty($ord['address_lng'])) continue; // skip if still missing
    $orders[] = $ord;
}
if (empty($orders)) { echo '<p>No geocoded orders available.</p>'; exit; }

// Nearest-neighbor greedy route starting at depot
$remaining = $orders; $route = []; $curLat = $depot['lat']; $curLng = $depot['lng']; $totalKm = 0.0;
while (!empty($remaining)) {
    $bestIdx = null; $bestDist = PHP_INT_MAX;
    foreach ($remaining as $i=>$o) {
        $d = haversine($curLat,$curLng,(float)$o['address_lat'],(float)$o['address_lng']);
        if ($d < $bestDist) { $bestDist = $d; $bestIdx = $i; }
    }
    $next = $remaining[$bestIdx]; array_splice($remaining,$bestIdx,1);
    $route[] = ['order'=>$next, 'dist'=>$bestDist];
    $totalKm += $bestDist; $curLat = (float)$next['address_lat']; $curLng = (float)$next['address_lng'];
}
// add return to depot
$totalKm += haversine($curLat,$curLng,$depot['lat'],$depot['lng']);

$rider = $pdo->prepare('SELECT * FROM riders WHERE id = ?')->execute([$riderId]) ? $pdo->prepare('SELECT * FROM riders WHERE id = ?')->execute([$riderId]) : null; 
// fetch rider properly
$riderRow = null; if ($riderId) { $st = $pdo->prepare('SELECT * FROM riders WHERE id = ?'); $st->execute([$riderId]); $riderRow = $st->fetch(); }

include __DIR__ . '/includes/admin_header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<div class="section-head"><h2>Route Sheet</h2><p>Rider: <?= $riderRow ? h($riderRow['name']) : '<em>Unassigned</em>' ?> — Store: Hadapsar (<?= $depot['lat'] ?>, <?= $depot['lng'] ?>)</p></div>
<div class="form-card"><h3>Planned route (approx. <?= number_format($totalKm,2) ?> km)</h3>
  <div id="routeMap" style="height:360px; width:100%; border-radius:12px; border:1px solid #D9E0CD; margin-bottom:16px;"></div>
  <ol>
    <?php foreach ($route as $step): $o = $step['order']; ?>
      <li>
        <strong>#ORD-<?= str_pad($o['id'],5,'0',STR_PAD_LEFT) ?></strong> — <?= h($o['customer_name']) ?> — <?= h($o['phone']) ?> — <?= h($o['address']) ?> <br>
        <small>Distance from previous: <?= number_format($step['dist'],2) ?> km</small>
        <div style="margin-top:8px; display:flex; gap:8px; flex-wrap:wrap;">
          <button type="button" class="btn" data-order-id="<?= (int)$o['id'] ?>" data-status="processing" data-label="Mark picked up">Mark picked up</button>
          <button type="button" class="btn" data-order-id="<?= (int)$o['id'] ?>" data-status="out_for_delivery" data-label="Start delivery">Start delivery</button>
          <button type="button" class="btn" data-order-id="<?= (int)$o['id'] ?>" data-status="delivered" data-label="Mark delivered">Mark delivered</button>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
  <p style="margin-top:12px;">Total estimated route distance: <strong><?= number_format($totalKm,2) ?> km</strong></p>
  <div style="text-align:right;">
    <a class="btn" href="delivery.php">Back</a>
    <button class="btn" onclick="window.print();">Print</button>
    <button class="btn" id="createManifest">Create Manifest</button>
  </div>
</div>
<script>
const routeSteps = <?= json_encode(array_map(function($step){
    $o = $step['order'];
    return [
        'id' => (int)$o['id'],
        'name' => $o['customer_name'],
        'phone' => $o['phone'],
        'address' => $o['address'],
        'lat' => (float)($o['address_lat'] ?? 0),
        'lng' => (float)($o['address_lng'] ?? 0),
    ];
}, $route)) ?>;
const depot = { lat: <?= (float)$depot['lat'] ?>, lng: <?= (float)$depot['lng'] ?> };
const routeMap = L.map('routeMap').setView([depot.lat, depot.lng], 11);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors',
  maxZoom: 19
}).addTo(routeMap);

const points = [[depot.lat, depot.lng]];
routeSteps.forEach((s) => {
  const marker = L.marker([s.lat, s.lng]).addTo(routeMap).bindPopup(`#ORD-${String(s.id).padStart(5,'0')}<br>${s.name}<br>${s.address}`);
  points.push([s.lat, s.lng]);
  marker.bindTooltip(`#ORD-${String(s.id).padStart(5,'0')}`);
});
L.polyline(points, { color: '#2a7f62', weight: 4, opacity: 0.8 }).addTo(routeMap);
if (points.length > 1) {
  routeMap.fitBounds(L.latLngBounds(points), { padding: [20, 20] });
}

function updateOrderStatus(orderId, status, label) {
  fetch('<?= BASE_URL ?>/ajax/update_order_status.php', {
    method: 'POST',
    headers: { 'Content-Type':'application/json' },
    body: JSON.stringify({ id: Number(orderId), status: status, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
    const text = await r.text();
    try {
      const data = JSON.parse(text);
      if (data.success) {
        alert(label + ' updated successfully.');
        location.reload();
      } else {
        alert('Failed: ' + (data.error || 'Unknown error'));
      }
    } catch (e) {
      alert('Failed: ' + text.slice(0, 200));
    }
  }).catch(() => alert('Network error'));
}

document.querySelectorAll('[data-order-id]').forEach((button) => {
  button.addEventListener('click', () => {
    const orderId = button.dataset.orderId;
    const status = button.dataset.status;
    const label = button.dataset.label;
    updateOrderStatus(orderId, status, label);
  });
});

document.getElementById('createManifest').addEventListener('click', function(){
  const ids = <?= json_encode(array_values(array_map(fn($r)=>$r['order']['id'],$route))) ?>;
  if(!ids.length) return alert('No orders');
  const rider = <?= json_encode($riderRow ? (int)$riderRow['id'] : '') ?>;
  if(!rider) if(!confirm('No rider selected — create manifest unassigned?')) return;
  fetch('<?= BASE_URL ?>/ajax/create_manifest.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ orders: ids.join(','), rider_id: rider, total_km: <?= number_format($totalKm,2,'.','') ?>, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(r=>r.json()).then(data=>{
    if(data.success){ alert('Manifest created: #' + data.manifest_id); window.location = 'delivery.php'; }
    else alert('Failed: ' + (data.error||'')); 
  }).catch(()=>alert('Network error'));
});
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>