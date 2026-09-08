<?php
require_once __DIR__ . '/../config.php';

$orderId = (int)($_GET['order_id'] ?? 0);
if (!$orderId) {
    header('Location: ../track_order.php');
    exit;
}

$stmt = $pdo->prepare("SELECT o.*, r.name AS rider_name, r.phone AS rider_phone FROM orders o LEFT JOIN riders r ON r.id = o.rider_id WHERE o.id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();
if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Order not found.'];
    header('Location: ../track_order.php');
    exit;
}

$page_title = 'Delivery Live Route';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<div class="container" style="max-width:980px; padding:24px 16px 48px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Delivery Route</h2>
    <p>Order #ORD-<?= str_pad($order['id'], 5, '0', STR_PAD_LEFT) ?> · <?= h(get_order_status_options()[$order['order_status']] ?? 'In progress') ?></p>
  </div>

  <div class="form-card" style="margin-bottom:20px;">
    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px;">
      <div>
        <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Driver</div>
        <div style="font-weight:700; margin-top:6px; font-size:1.1rem;">
          <?= $order['rider_name'] ? h($order['rider_name']) : 'Unassigned' ?>
        </div>
      </div>
      <div>
        <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Current status</div>
        <div style="font-weight:700; margin-top:6px; font-size:1.1rem;">
          <?= h(get_order_status_options()[$order['order_status']] ?? ucfirst(str_replace('_', ' ', $order['order_status']))) ?>
        </div>
      </div>
      <div>
        <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Customer</div>
        <div style="font-weight:700; margin-top:6px; font-size:1rem;">
          <?= h($order['customer_name']) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="form-card">
    <div id="map" style="width:100%; height:420px; border-radius:12px; border:1px solid #D9E0CD; margin-bottom:12px;"></div>
    <div id="statusText" style="color:#5B6656; font-size:0.9rem;">Waiting for delivery partner location...</div>
  </div>
</div>

<script>
const orderId = <?= (int)$order['id'] ?>;
const customerLat = <?= !empty($order['address_lat']) ? (float)$order['address_lat'] : 'null' ?>;
const customerLng = <?= !empty($order['address_lng']) ? (float)$order['address_lng'] : 'null' ?>;

const map = L.map('map').setView([customerLat || 18.5011, customerLng || 73.9268], customerLat ? 13 : 10);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors',
  maxZoom: 19
}).addTo(map);

let customerMarker = null;
let driverMarker = null;
let routeLine = null;

if (customerLat && customerLng) {
  customerMarker = L.marker([customerLat, customerLng]).addTo(map).bindPopup('Customer location');
}

function poll() {
  fetch('../ajax/get_order_location.php?order_id=' + orderId).then(r => r.json()).then(res => {
    if (!res.success) {
      document.getElementById('statusText').textContent = res.message || 'Unable to load route.';
      return;
    }
    if (res.delivery_lat && res.delivery_lng) {
      const driverPoint = [res.delivery_lat, res.delivery_lng];
      if (!driverMarker) {
        driverMarker = L.marker(driverPoint).addTo(map).bindPopup('Delivery partner');
      } else {
        driverMarker.setLatLng(driverPoint);
      }
      if (customerLat && customerLng) {
        if (routeLine) { routeLine.remove(); }
        routeLine = L.polyline([driverPoint, [customerLat, customerLng]], { color: '#2a7f62', weight: 4, opacity: 0.8 }).addTo(map);
        map.fitBounds(L.latLngBounds([driverPoint, [customerLat, customerLng]]), { padding: [40, 40] });
      }
      document.getElementById('statusText').textContent = 'Live delivery location updated at ' + (res.location_updated_at || 'recently');
    } else {
      document.getElementById('statusText').textContent = 'Waiting for delivery partner location...';
    }
  }).catch(() => {
    document.getElementById('statusText').textContent = 'Network issue while loading live route.';
  });
}

poll();
setInterval(poll, 10000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
