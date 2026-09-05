<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Deliver Order';

$orderId = (int)($_GET['order_id'] ?? 0);
$order = get_order_with_geocoded_address($pdo, $orderId);

if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Order not found.'];
    redirect('orders.php');
}

$orderStatusOptions = [
    'pending'          => 'Pending',
    'placed'           => 'Placed',
    'processing'       => 'Processing',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
];

include __DIR__ . '/includes/admin_header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<div class="section-head" style="text-align:left; margin-top:0;">
  <h2 style="margin:0;">Deliver Order #<?= $order['id'] ?></h2>
  <p><?= h($order['customer_name']) ?> &middot; <?= h($order['phone']) ?></p>
</div>

<div class="form-card" style="max-width:700px;">
  <p style="color:#5B6656; margin-bottom:14px;"><strong>Deliver to:</strong> <?= nl2br(h($order['address'])) ?></p>

  <div style="margin-bottom:16px;">
    <label style="display:block; font-weight:600; margin-bottom:6px;">Order status</label>
    <select
      style="font-weight:700; font-size:0.9rem; padding:8px 12px; border-radius:8px; border:1px solid #D9E0CD; cursor:pointer;"
      onchange="if(this.value) window.location='update_order.php?id=<?= $order['id'] ?>&type=order&value='+this.value;">
      <?php foreach ($orderStatusOptions as $val => $label): ?>
        <option value="<?= $val ?>" <?= $order['order_status'] === $val ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div id="map" style="width:100%; height:360px; border-radius:12px; border:1px solid #D9E0CD; margin-bottom:16px;"></div>

  <div id="shareStatus" style="margin-bottom:12px; font-size:0.9rem; color:#5B6656;">Location sharing is off.</div>

  <button type="button" id="startShareBtn" class="btn btn-primary" style="margin-right:8px;">📍 Start sharing my location</button>
  <button type="button" id="stopShareBtn" class="btn" style="background:#fff; border:1px solid #E4E9DD; display:none;">Stop sharing</button>

  <p style="color:#5B6656; font-size:0.82rem; margin-top:14px;">
    Keep this page open in your phone's browser while you deliver. Your location updates every few seconds
    and the customer sees it move on their own tracking page. Closing this tab stops the sharing.
  </p>
</div>

<script>
const orderId = <?= (int)$order['id'] ?>;
const addressLat = <?= $order['address_lat'] !== null ? (float)$order['address_lat'] : 'null' ?>;
const addressLng = <?= $order['address_lng'] !== null ? (float)$order['address_lng'] : 'null' ?>;
const startLat = <?= $order['delivery_lat'] !== null ? (float)$order['delivery_lat'] : 'null' ?>;
const startLng = <?= $order['delivery_lng'] !== null ? (float)$order['delivery_lng'] : 'null' ?>;

const map = L.map('map').setView([addressLat || 20.5937, addressLng || 78.9629], addressLat ? 14 : 5);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
  attribution: '&copy; OpenStreetMap contributors',
  maxZoom: 19
}).addTo(map);

let addressMarker = null;
if (addressLat && addressLng) {
  addressMarker = L.marker([addressLat, addressLng]).addTo(map).bindPopup('Delivery address');
}

let meMarker = null;
if (startLat && startLng) {
  meMarker = L.marker([startLat, startLng]).addTo(map).bindPopup('You');
}

let watchId = null;
let lastSent = 0;

function sendLocation(lat, lng) {
  const data = new FormData();
  data.append('order_id', orderId);
  data.append('lat', lat);
  data.append('lng', lng);
  fetch(window.__VEGBASKET_BASE__ + '/ajax/update_location.php', { method: 'POST', body: data });
}

function onPosition(pos) {
  const lat = pos.coords.latitude;
  const lng = pos.coords.longitude;

  if (!meMarker) {
    meMarker = L.marker([lat, lng]).addTo(map).bindPopup('You');
  } else {
    meMarker.setLatLng([lat, lng]);
  }
  map.panTo([lat, lng]);

  // Throttle network calls to roughly once every 8 seconds
  const now = Date.now();
  if (now - lastSent > 8000) {
    lastSent = now;
    sendLocation(lat, lng);
  }
}

function onError(err) {
  document.getElementById('shareStatus').textContent = 'Could not get your location: ' + err.message;
}

document.getElementById('startShareBtn').addEventListener('click', function () {
  if (!navigator.geolocation) {
    document.getElementById('shareStatus').textContent = 'Your browser does not support location sharing.';
    return;
  }
  watchId = navigator.geolocation.watchPosition(onPosition, onError, {
    enableHighAccuracy: true,
    maximumAge: 5000,
    timeout: 15000
  });
  document.getElementById('shareStatus').textContent = '🟢 Sharing your live location...';
  document.getElementById('startShareBtn').style.display = 'none';
  document.getElementById('stopShareBtn').style.display = 'inline-block';
});

document.getElementById('stopShareBtn').addEventListener('click', function () {
  if (watchId !== null) navigator.geolocation.clearWatch(watchId);
  document.getElementById('shareStatus').textContent = 'Location sharing is off.';
  document.getElementById('startShareBtn').style.display = 'inline-block';
  document.getElementById('stopShareBtn').style.display = 'none';
});
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>
