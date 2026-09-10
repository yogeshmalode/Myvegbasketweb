<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Deliver Order';

$orderId = (int)($_GET['order_id'] ?? 0);
$order = get_order_with_geocoded_address($pdo, $orderId);

if (!$order) {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Order not found.'];
    redirect('orders.php');
}

$orderStatusOptions = get_order_status_options();

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

  <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:16px;">
    <div class="form-card" style="padding:12px; margin:0; background:#F7FAF4;">
      <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Current lat</div>
      <div id="liveLat" style="font-weight:700; font-size:1.1rem; margin-top:6px;">—</div>
    </div>
    <div class="form-card" style="padding:12px; margin:0; background:#F7FAF4;">
      <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Current lng</div>
      <div id="liveLng" style="font-weight:700; font-size:1.1rem; margin-top:6px;">—</div>
    </div>
    <div class="form-card" style="padding:12px; margin:0; background:#F7FAF4;">
      <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">Distance</div>
      <div id="distanceKm" style="font-weight:700; font-size:1.1rem; margin-top:6px;">—</div>
    </div>
    <div class="form-card" style="padding:12px; margin:0; background:#F7FAF4;">
      <div style="font-size:0.72rem; letter-spacing:0.08em; text-transform:uppercase; color:#6D7A67;">ETA</div>
      <div id="etaDisplay" style="font-weight:700; font-size:1.1rem; margin-top:6px;">—</div>
    </div>
  </div>

  <div id="shareStatus" style="margin-bottom:12px; font-size:0.9rem; color:#5B6656;">Location sharing is off.</div>

  <button type="button" id="startShareBtn" class="btn btn-primary" style="margin-right:8px;">📍 Start sharing my location</button>
  <button type="button" id="stopShareBtn" class="btn" style="background:#fff; border:1px solid #E4E9DD; display:none;">Stop sharing</button>
  <a id="navLink" class="btn" href="#" target="_blank" rel="noopener" style="display:none; margin-top:10px;">🧭 Open navigation</a>

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

function toRad(value) { return value * Math.PI / 180; }
function haversineKm(lat1, lng1, lat2, lng2) {
  const R = 6371;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
  return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function updateDistanceLabel(lat, lng) {
  if (!addressLat || !addressLng) return;
  const distance = haversineKm(lat, lng, addressLat, addressLng);
  document.getElementById('distanceKm').textContent = distance.toFixed(1) + ' km';
  const eta = Math.max(4, Math.round((distance / 24) * 60));
  document.getElementById('etaDisplay').textContent = eta + ' min';
  document.getElementById('navLink').style.display = 'inline-block';
  document.getElementById('navLink').href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addressLat + ',' + addressLng) + '&travelmode=driving';
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
  document.getElementById('liveLat').textContent = lat.toFixed(5);
  document.getElementById('liveLng').textContent = lng.toFixed(5);
  updateDistanceLabel(lat, lng);

  if (!meMarker) {
    meMarker = L.marker([lat, lng]).addTo(map).bindPopup('You');
  } else {
    meMarker.setLatLng([lat, lng]);
  }
  map.panTo([lat, lng]);

  const now = Date.now();
  if (now - lastSent > 8000) {
    lastSent = now;
    sendLocation(lat, lng);
  }
}

function onError(err) {
  document.getElementById('shareStatus').textContent = 'Location permission is required to provide live delivery tracking.';
  if (err && err.code === 1) {
    document.getElementById('shareStatus').textContent = 'Location permission is required to provide live delivery tracking.';
  } else if (err && err.code === 3) {
    document.getElementById('shareStatus').textContent = 'GPS accuracy is poor. Please move to a clearer area and retry.';
  } else {
    document.getElementById('shareStatus').textContent = 'Could not get your location: ' + (err ? err.message : 'Unknown GPS error');
  }
}

document.getElementById('startShareBtn').addEventListener('click', function () {
  if (!navigator.geolocation) {
    document.getElementById('shareStatus').textContent = 'Your browser does not support GPS tracking.';
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
