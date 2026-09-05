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

$steps = ['placed' => 'Placed', 'processing' => 'Processing', 'out_for_delivery' => 'Out for delivery', 'delivered' => 'Delivered'];
$stepKeys = array_keys($steps);
$currentIndex = $allowed ? array_search($order['order_status'] === 'pending' ? 'placed' : $order['order_status'], $stepKeys) : false;
if ($currentIndex === false) $currentIndex = 0;

$page_title = 'Track Order';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<div class="container" style="padding:50px 24px; max-width:720px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Track your order</h2>
    <p>See your order's status and live delivery location.</p>
  </div>

  <?php if (!$allowed): ?>
    <div class="form-card" style="max-width:420px; margin:0 auto;">
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

    <!-- Status timeline -->
    <?php if ($order['order_status'] === 'cancelled'): ?>
      <div class="alert alert-error" style="margin-bottom:24px;">This order was cancelled.</div>
    <?php else: ?>
      <div style="display:flex; justify-content:space-between; margin-bottom:32px; position:relative;">
        <div style="position:absolute; top:13px; left:5%; right:5%; height:3px; background:#E4E9DD; z-index:0;"></div>
        <div style="position:absolute; top:13px; left:5%; width:<?= min(100, $currentIndex / (count($steps)-1) * 90) ?>%; height:3px; background:#3F8B52; z-index:1;"></div>
        <?php foreach ($stepKeys as $i => $key): ?>
          <div style="position:relative; z-index:2; text-align:center; flex:1;">
            <div style="width:28px; height:28px; border-radius:50%; margin:0 auto 8px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:0.8rem;
              background:<?= $i <= $currentIndex ? '#3F8B52' : '#E4E9DD' ?>; color:<?= $i <= $currentIndex ? '#fff' : '#5B6656' ?>;">
              <?= $i < $currentIndex ? '✓' : $i + 1 ?>
            </div>
            <div style="font-size:0.78rem; font-weight:600; color:<?= $i <= $currentIndex ? '#1F4D36' : '#5B6656' ?>;"><?= $steps[$key] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="form-card" style="margin-bottom:20px;">
      <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div>
          <strong>Order #<?= $order['id'] ?></strong>
          <div style="color:#5B6656; font-size:0.85rem;"><?= format_ist($order['created_at']) ?></div>
        </div>
        <div style="font-weight:700; color:var(--leaf-dark);"><?= SITE_CURRENCY ?><?= number_format($order['total_amount'],2) ?></div>
      </div>
    </div>

    <?php if ($order['order_status'] === 'out_for_delivery'): ?>
      <div class="form-card">
        <h3 style="margin-top:0;">🚴 Live location</h3>
        <div id="map" style="width:100%; height:360px; border-radius:12px; border:1px solid #D9E0CD; margin-bottom:10px;"></div>
        <p id="locStatus" style="color:#5B6656; font-size:0.85rem;">Waiting for the delivery location to update...</p>
      </div>

      <script>
        const orderId = <?= (int)$order['id'] ?>;
        const trackPhone = <?= json_encode($phone) ?>;
        const addressLat = <?= $order['address_lat'] !== null ? (float)$order['address_lat'] : 'null' ?>;
        const addressLng = <?= $order['address_lng'] !== null ? (float)$order['address_lng'] : 'null' ?>;

        const map = L.map('map').setView([addressLat || 20.5937, addressLng || 78.9629], addressLat ? 13 : 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap contributors',
          maxZoom: 19
        }).addTo(map);

        let addressMarker = null;
        if (addressLat && addressLng) {
          addressMarker = L.marker([addressLat, addressLng]).addTo(map).bindPopup('Delivery address');
        }
        let riderMarker = null;

        function fmtAgo(seconds) {
          if (seconds < 60) return 'just now';
          if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
          return Math.floor(seconds / 3600) + ' hr ago';
        }

        function poll() {
          const url = window.__VEGBASKET_BASE__ + '/ajax/get_order_location.php?order_id=' + orderId + '&phone=' + encodeURIComponent(trackPhone);
          fetch(url).then(r => r.json()).then(res => {
            if (!res.success) return;

            if (res.delivery_lat && res.delivery_lng) {
              const pos = [res.delivery_lat, res.delivery_lng];
              if (!riderMarker) {
                riderMarker = L.marker(pos).addTo(map).bindPopup('Your delivery').openPopup();
                map.setView(pos, 14);
              } else {
                riderMarker.setLatLng(pos);
              }
              if (res.location_updated_at) {
                // The server stores/returns this in UTC; append 'Z' so the
                // browser's Date object treats it as UTC too, instead of
                // misreading it as if it were already in the visitor's
                // local timezone (which would double up the offset).
                const updated = new Date(res.location_updated_at.replace(' ', 'T') + 'Z');
                const seconds = Math.floor((Date.now() - updated.getTime()) / 1000);
                document.getElementById('locStatus').textContent = 'Last updated ' + fmtAgo(seconds);
              }
            }

            // Reload the whole page if the order status has moved on
            // (e.g. delivered), so the timeline/map update accordingly.
            if (res.order_status !== '<?= $order['order_status'] ?>') {
              location.reload();
            }
          });
        }

        poll();
        setInterval(poll, 8000);
      </script>

    <?php elseif ($order['order_status'] === 'delivered'): ?>
      <div class="form-card" style="text-align:center;">
        <div style="font-size:2.5rem;">✅</div>
        <p style="color:#5B6656;">This order has been delivered. Enjoy your fresh vegetables!</p>
      </div>
    <?php else: ?>
      <div class="form-card" style="text-align:center;">
        <p style="color:#5B6656;">Live tracking will appear here once your order is out for delivery.</p>
      </div>

      <script>
        // The order wasn't "out for delivery" when this page loaded, so
        // there's no live-location script running yet. Poll just the
        // status every few seconds and reload the page the moment it
        // changes, so the customer doesn't have to refresh manually.
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

          setInterval(poll, 8000);
        })();
      </script>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
