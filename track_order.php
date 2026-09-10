<?php
require_once __DIR__ . '/config.php';

$orderId = (int)($_GET['order_id'] ?? 0);
$phone   = trim($_GET['phone'] ?? '');

$order = null;
$allowed = false;
$lookupError = '';

if ($orderId) {
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if ($order) {
        if (is_customer_logged_in() && $order['customer_id'] && (int)$order['customer_id'] === (int)$_SESSION['customer_id']) {
            $allowed = true;
        } elseif (!empty($_SESSION['guest_order_access']) && in_array($orderId, $_SESSION['guest_order_access'])) {
            $allowed = true;
        } elseif ($phone !== '' && hash_equals($order['phone'], $phone)) {
            $allowed = true;
        } elseif ($phone !== '') {
            $lookupError = 'Order number or phone number did not match.';
        }
    } else {
        $lookupError = 'Order not found.';
    }
}

// Geocode the delivery address (once, cached) only once we know the
// person is actually allowed to see this order.
if ($allowed) {
    $order = get_order_with_geocoded_address($pdo, $orderId);
}

$steps = get_delivery_status_steps();
$stepKeys = array_keys($steps);
$currentIndex = $allowed ? array_search(normalize_order_status($order['order_status'] === 'pending' ? 'placed' : $order['order_status']), $stepKeys, true) : false;
if ($currentIndex === false) $currentIndex = 0;

$page_title = 'Track Order';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<style>
  body { background: #f7faf8; }
  .tracking-shell {
    max-width: 880px;
    margin: 0 auto;
    padding: 42px 18px 56px;
  }
  .tracking-header {
    margin-bottom: 22px;
  }
  .tracking-header h2 {
    margin: 0 0 8px;
    font-size: clamp(2rem, 3vw, 3rem);
    letter-spacing: -0.06em;
    color: #11231d;
    font-weight: 900;
  }
  .tracking-header p {
    margin: 0;
    color: #69756f;
    font-size: 0.9rem;
  }
  .tracking-card {
    background: #fff;
    border: 1px solid #e1e9e3;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(16, 40, 32, 0.05);
    padding: 18px;
  }
  .tracking-status-card {
    background: linear-gradient(135deg, #f8fdf8 0%, #eefaf3 100%);
  }
  .tracking-topline {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 18px;
  }
  .tracking-order-id {
    font-size: 1.2rem;
    font-weight: 900;
    color: #10251f;
  }
  .tracking-total {
    font-size: 1.05rem;
    font-weight: 800;
    color: #185b42;
  }
  .tracking-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
  }
  .tracking-metric {
    background: #f8fbf9;
    border: 1px solid #edf1ee;
    border-radius: 12px;
    padding: 12px 14px;
  }
  .tracking-metric .label {
    display: block;
    font-size: 0.7rem;
    color: #69756f;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 800;
  }
  .tracking-metric .value {
    display: block;
    margin-top: 7px;
    font-weight: 800;
    font-size: 1.05rem;
    color: #152923;
  }
  .call-btn-wrap {
    margin-top: 18px;
  }
  .delivery-map-wrap {
    margin-top: 22px;
  }
  .delivery-map-wrap h3 {
    margin: 0 0 12px;
    color: #162b26;
    font-size: 1.3rem;
    letter-spacing: -0.03em;
  }
  #map {
    width: 100%;
    height: 380px;
    border-radius: 14px;
    border: 1px solid #dfe7e1;
    margin-bottom: 12px;
  }
  .map-meta {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    color: #5b6656;
    font-size: 0.85rem;
    padding: 0 2px;
  }
  .tracking-lookup {
    max-width: 460px;
    margin: 0 auto;
  }
  .tracking-stepper {
    display: flex;
    justify-content: space-between;
    margin-bottom: 28px;
    position: relative;
    gap: 10px;
  }
  .tracking-stepper::before {
    content: "";
    position: absolute;
    top: 13px;
    left: 5%;
    right: 5%;
    height: 3px;
    background: #e4e9dd;
    z-index: 0;
    border-radius: 999px;
  }
  .tracking-stepper::after {
    content: "";
    position: absolute;
    top: 13px;
    left: 5%;
    width: var(--progress, 0%);
    height: 3px;
    background: #3f8b52;
    z-index: 1;
    border-radius: 999px;
  }
  .tracking-step {
    position: relative;
    z-index: 2;
    text-align: center;
    flex: 1;
    min-width: 0;
  }
  .tracking-step-dot {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    margin: 0 auto 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.8rem;
    background: #e4e9dd;
    color: #5b6656;
  }
  .tracking-step.active .tracking-step-dot {
    background: #3f8b52;
    color: #fff;
  }
  .tracking-step.complete .tracking-step-dot {
    background: #3f8b52;
    color: #fff;
  }
  .tracking-step-label {
    font-size: 0.72rem;
    font-weight: 700;
    color: #5b6656;
    line-height: 1.3;
  }
  .tracking-step.active .tracking-step-label,
  .tracking-step.complete .tracking-step-label {
    color: #1f4d36;
  }
  @media (max-width: 640px) {
    .tracking-shell { padding-top: 24px; }
    .tracking-stepper { gap: 6px; }
    .tracking-step-label { font-size: 0.62rem; }
    .tracking-card { padding: 14px; }
  }
</style>

<div class="tracking-shell">
  <div class="tracking-header">
    <h2>Track your order</h2>
    <p>See your order's status and live delivery location.</p>
  </div>

  <?php if (!$allowed): ?>
    <div class="tracking-card tracking-lookup">
      <?php if ($lookupError): ?><div class="alert alert-error"><?= h($lookupError) ?></div><?php endif; ?>
      <form method="get">
        <div class="form-group">
          <label for="order_id">Order number</label>
          <input type="number" id="order_id" name="order_id" value="<?= $orderId ?: '' ?>" required autofocus>
        </div>
        <div class="form-group">
          <label for="phone">Phone number used at checkout</label>
          <input type="tel" id="phone" name="phone" value="<?= h($phone) ?>" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">Track order</button>
      </form>
      <?php if (is_customer_logged_in()): ?>
        <p style="text-align:center; margin-top:14px; font-size:0.85rem;">
          Logged in? <a href="<?= BASE_URL ?>/my_account.php" style="color:#3F8B52;">See all your orders</a>
        </p>
      <?php endif; ?>
    </div>

  <?php else: ?>

    <?php
    $trackedStatusKeys = array_keys(get_delivery_status_steps());
    $orderStatus = normalize_order_status($order['order_status'] ?? 'placed');
    $currentStatusIndex = array_search($orderStatus, $trackedStatusKeys, true);
    if ($currentStatusIndex === false) { $currentStatusIndex = 0; }

    $driver = null;
    if (!empty($order['rider_id'])) {
        $driverStmt = $pdo->prepare('SELECT * FROM riders WHERE id = ?');
        $driverStmt->execute([(int)$order['rider_id']]);
        $driver = $driverStmt->fetch();
    }

    $customerLat = !empty($order['address_lat']) ? (float)$order['address_lat'] : null;
    $customerLng = !empty($order['address_lng']) ? (float)$order['address_lng'] : null;
    $driverLat = !empty($order['delivery_lat']) ? (float)$order['delivery_lat'] : null;
    $driverLng = !empty($order['delivery_lng']) ? (float)$order['delivery_lng'] : null;
    $distanceKm = null;
    if ($driverLat !== null && $driverLng !== null && $customerLat !== null && $customerLng !== null) {
        $distanceKm = haversine_km($driverLat, $driverLng, $customerLat, $customerLng);
    }
    $etaMinutes = $distanceKm !== null ? estimate_eta_minutes($distanceKm) : null;
    ?>

    <?php if ($order['order_status'] === 'cancelled'): ?>
      <div class="alert alert-error" style="margin-bottom:24px;">This order was cancelled.</div>
    <?php else: ?>
      <div class="tracking-stepper" style="--progress: <?= min(100, (($currentStatusIndex + 1) / count($trackedStatusKeys)) * 100) ?>%;">
        <?php foreach ($trackedStatusKeys as $i => $key): ?>
          <div class="tracking-step <?= $i < $currentStatusIndex ? 'complete' : ($i === $currentStatusIndex ? 'active' : '') ?>">
            <div class="tracking-step-dot"><?= $i < $currentStatusIndex ? '✓' : $i + 1 ?></div>
            <div class="tracking-step-label"><?= get_delivery_status_steps()[$key] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="tracking-card" style="margin-bottom:20px;">
      <div class="tracking-topline">
        <div>
          <div class="tracking-order-id">Order #<?= $order['id'] ?></div>
          <div style="color:#5B6656; font-size:0.85rem; margin-top:4px;">
            <?= format_ist($order['created_at']) ?>
          </div>
        </div>
        <div class="tracking-total"><?= SITE_CURRENCY ?><?= number_format($order['total_amount'],2) ?></div>
      </div>
    </div>

    <div class="tracking-card tracking-status-card" style="margin-bottom:20px;">
      <div class="tracking-metrics">
        <div class="tracking-metric">
          <span class="label">Delivery partner</span>
          <span class="value"><?= $driver ? h($driver['name']) : 'Awaiting assignment' ?></span>
        </div>
        <div class="tracking-metric">
          <span class="label">Estimated arrival</span>
          <span class="value"><?= $etaMinutes !== null ? $etaMinutes . ' min' : 'Waiting for route' ?></span>
        </div>
        <div class="tracking-metric">
          <span class="label">Distance remaining</span>
          <span class="value"><?= $distanceKm !== null ? number_format($distanceKm, 1) . ' km' : '—' ?></span>
        </div>
        <div class="tracking-metric">
          <span class="label">Status</span>
          <span class="value"><?= h(get_order_status_options()[$orderStatus] ?? 'In progress') ?></span>
        </div>
      </div>
      <?php if ($driver && !empty($driver['phone'])): ?>
        <div class="call-btn-wrap">
          <a href="tel:<?= h($driver['phone']) ?>" class="btn btn-primary">Call delivery partner</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if (in_array($orderStatus, ['delivery_partner_assigned', 'out_for_delivery', 'arriving_soon'], true) || $order['order_status'] === 'out_for_delivery'): ?>
      <div class="tracking-card delivery-map-wrap">
        <h3>🚴 Live delivery map</h3>
        <div id="map"></div>
        <div class="map-meta">
          <span id="locStatus">Waiting for the delivery partner's location...</span>
          <span id="etaLabel"><?= $etaMinutes !== null ? 'ETA: ' . $etaMinutes . ' minutes' : 'ETA: pending' ?></span>
        </div>
      </div>

      <script>
        const orderId = <?= (int)$order['id'] ?>;
        const trackPhone = <?= json_encode($phone) ?>;
        const customerLat = <?= $customerLat !== null ? (float)$customerLat : 'null' ?>;
        const customerLng = <?= $customerLng !== null ? (float)$customerLng : 'null' ?>;
        const mapCenter = customerLat && customerLng ? [customerLat, customerLng] : [18.5011, 73.9268];

        const map = L.map('map').setView(mapCenter, customerLat ? 13 : 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap contributors',
          maxZoom: 19
        }).addTo(map);

        let customerMarker = null;
        let riderMarker = null;
        let routeLine = null;

        if (customerLat && customerLng) {
          customerMarker = L.marker([customerLat, customerLng]).addTo(map).bindPopup('Delivery address');
        }

        function fmtAgo(seconds) {
          if (seconds < 60) return 'just now';
          if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
          return Math.floor(seconds / 3600) + ' hr ago';
        }

        function updateRoute(lat, lng) {
          const riderPoint = [lat, lng];
          if (customerLat && customerLng) {
            if (routeLine) { routeLine.remove(); }
            routeLine = L.polyline([riderPoint, [customerLat, customerLng]], { color: '#1f9d6b', weight: 4, opacity: 0.8 }).addTo(map);
          }
          if (!riderMarker) {
            riderMarker = L.marker(riderPoint).addTo(map).bindPopup('Delivery partner').openPopup();
          } else {
            riderMarker.setLatLng(riderPoint);
          }
          if (customerMarker) {
            map.fitBounds(L.latLngBounds([riderPoint, [customerLat, customerLng]]), { padding: [40, 40] });
          }
        }

        function poll() {
          const url = window.__VEGBASKET_BASE__ + '/ajax/get_order_location.php?order_id=' + orderId + '&phone=' + encodeURIComponent(trackPhone);
          fetch(url).then(r => r.json()).then(res => {
            if (!res.success) return;

            if (res.delivery_lat && res.delivery_lng) {
              updateRoute(res.delivery_lat, res.delivery_lng);
              if (res.location_updated_at) {
                const updated = new Date(res.location_updated_at.replace(' ', 'T') + 'Z');
                const seconds = Math.floor((Date.now() - updated.getTime()) / 1000);
                document.getElementById('locStatus').textContent = 'Last updated ' + fmtAgo(seconds);
              }
            }

            if (res.order_status !== '<?= $order['order_status'] ?>') {
              location.reload();
            }
          });
        }

        poll();
        setInterval(poll, 10000);
      </script>

    <?php elseif ($order['order_status'] === 'delivered'): ?>
      <div class="form-card" style="text-align:center;">
        <div style="font-size:2.5rem;">✅</div>
        <p style="color:#5B6656;">This order has been delivered. Enjoy your fresh vegetables!</p>
      </div>
    <?php else: ?>
      <div class="form-card" style="text-align:center;">
        <p style="color:#5B6656;">Live tracking will appear here once your delivery partner is assigned and on the way.</p>
      </div>

      <script>
        (function () {
          const orderId = <?= (int)$order['id'] ?>;
          const trackPhone = <?= json_encode($phone) ?>;
          const knownStatus = <?= json_encode($order['order_status']) ?>;

          function poll() {
            const url = window.__VEGBASKET_BASE__ + '/ajax/get_order_location.php?order_id=' + orderId + '&phone=' + encodeURIComponent(trackPhone);
            fetch(url).then(r => r.json()).then(res => {
              if (res.success && res.order_status !== knownStatus) {
                location.reload();
              }
            });
          }

          setInterval(poll, 10000);
        })();
      </script>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
