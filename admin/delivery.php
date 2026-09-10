<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Delivery & Route Planner';

// Fetch pending orders with geocoded addresses and any assigned rider
$orders = $pdo->query("SELECT o.id, o.customer_name, o.phone, o.address, o.address_lat, o.address_lng, o.total_amount, o.order_status, o.rider_id, o.dark_store_id, o.eta_minutes, r.name AS rider_name, ds.name AS dark_store_name FROM orders o LEFT JOIN riders r ON r.id = o.rider_id LEFT JOIN dark_stores ds ON ds.id = o.dark_store_id WHERE o.order_status IN ('placed','processing','ready_for_pickup','assigning_rider','delivery_partner_assigned','out_for_delivery','arriving_soon') ORDER BY o.created_at ASC")->fetchAll();
$riders = $pdo->query('SELECT id, name FROM riders WHERE is_active=1 ORDER BY name')->fetchAll();
include __DIR__ . '/includes/admin_header.php';
?>
<style>
  .route-planner-shell {
   background: transparent;
  }
  .route-header {
   display: flex;
   align-items: flex-end;
   justify-content: space-between;
   gap: 16px;
   flex-wrap: wrap;
   margin-bottom: 18px;
  }
  .route-header h2 {
   margin: 0;
   font-size: clamp(1.5rem, 2vw, 2.2rem);
   color: #17231f;
   letter-spacing: -0.04em;
   font-weight: 800;
  }
  .route-header p {
   margin: 8px 0 0;
   color: #64716d;
   font-size: 0.82rem;
   line-height: 1.5;
   max-width: 720px;
  }
  .route-toolbar {
   display: flex;
   align-items: center;
   gap: 10px;
   flex-wrap: wrap;
   padding: 14px 16px;
   margin-bottom: 18px;
   background: #fff;
   border: 1px solid #e2e9e4;
   border-radius: 14px;
   box-shadow: 0 8px 24px rgba(17, 48, 37, 0.04);
  }
  .route-toolbar select {
   min-height: 38px;
   min-width: 180px;
   border: 1px solid #d8e1db;
   border-radius: 9px;
   background: #f9fbfa;
   color: #1c2924;
   font-weight: 600;
   padding: 0 12px;
  }
  .route-toolbar .btn {
   min-height: 38px;
   padding: 0 14px;
   border-radius: 9px;
   font-size: 0.75rem;
   font-weight: 700;
  }
  .delivery-table-wrap {
   background: #fff;
   border-radius: 18px;
   border: 1px solid #e1e9e3;
   overflow: hidden;
   box-shadow: 0 10px 28px rgba(18, 52, 40, 0.04);
  }
  .delivery-table {
   width: 100%;
   min-width: 1100px;
   border-collapse: collapse;
  }
  .delivery-table thead th {
   background: #dfe9dc;
   color: #1b342d;
   font-size: 0.68rem;
   text-transform: uppercase;
   letter-spacing: 0.04em;
   padding: 12px 10px;
   text-align: left;
   font-weight: 800;
  }
  .delivery-table tbody td {
   padding: 12px 10px;
   border-bottom: 1px solid #edf0ee;
   font-size: 0.8rem;
   color: #1f2b28;
   vertical-align: top;
  }
  .delivery-table tbody tr:hover {
   background: #fafdf9;
  }
  .delivery-table tbody tr:last-child td {
   border-bottom: none;
  }
  .route-check {
   width: 18px;
   height: 18px;
   accent-color: #0d7a59;
  }
  .delivery-status {
   display: inline-flex;
   padding: 5px 8px;
   border-radius: 999px;
   background: #eef7f0;
   color: #0d7a59;
   font-size: 0.72rem;
   font-weight: 700;
   text-transform: capitalize;
  }
  .assignment-box {
   display: flex;
   flex-wrap: wrap;
   align-items: center;
   gap: 8px;
  }
  .assignment-box select {
   min-width: 150px;
   min-height: 34px;
   border: 1px solid #d7e1d9;
   border-radius: 8px;
   background: #f9fbfa;
   padding: 0 10px;
   font-size: 0.75rem;
  }
  .assignment-box .btn {
   min-height: 34px;
   padding: 0 10px;
   font-size: 0.72rem;
   border-radius: 8px;
  }
  .needs-geocode {
   color: #b16a31;
   font-style: italic;
   font-weight: 600;
  }
  @media (max-width: 768px) {
   .route-toolbar {
     align-items: stretch;
   }
   .route-toolbar select,
   .route-toolbar .btn {
     width: 100%;
   }
  }
</style>

<div class="route-planner-shell">
  <div class="route-header">
   <div>
     <h2>Delivery &amp; Route Planner</h2>
     <p>Select orders and a rider, then click Plan Route — the system will compute a simple route starting from the Hadapsar store.</p>
   </div>
  </div>

  <div class="route-toolbar">
   <select id="riderSelect">
     <option value="">— Choose rider —</option>
     <?php foreach($riders as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?>
   </select>
   <button class="btn" id="planRoute">🗺️ Plan Route</button>
   <button class="btn" id="refreshBtn">🔄 Refresh</button>
  </div>

  <div class="delivery-table-wrap">
   <table class="delivery-table">
     <thead>
       <tr><th></th><th>Order</th><th>Customer</th><th>Phone</th><th>Address</th><th>Lat,Lng</th><th>Dark Store</th><th>ETA</th><th>Status</th><th>Assigned Rider</th></tr>
     </thead>
     <tbody>
       <?php foreach($orders as $o): ?>
         <tr>
           <td><input type="checkbox" class="routeChk route-check" value="<?= $o['id'] ?>"></td>
           <td>#ORD-<?= str_pad($o['id'],5,'0',STR_PAD_LEFT) ?></td>
           <td><?= h($o['customer_name']) ?></td>
           <td><?= h($o['phone']) ?></td>
           <td><?= h($o['address']) ?></td>
           <td><?= $o['address_lat'] && $o['address_lng'] ? h($o['address_lat'] . ',' . $o['address_lng']) : '<span class="needs-geocode">needs geocode</span>' ?></td>
           <td><?= $o['dark_store_name'] ? h($o['dark_store_name']) : '<span class="needs-geocode">unassigned</span>' ?></td>
           <td><?= $o['eta_minutes'] !== null ? h(round($o['eta_minutes'])) . ' min' : '—' ?></td>
           <td><span class="delivery-status"><?= h($o['order_status']) ?></span></td>
           <td>
             <div class="assignment-box">
               <select id="rider-<?= $o['id'] ?>">
                 <option value="">— Unassigned —</option>
                 <?php foreach($riders as $r): ?>
                   <option value="<?= $r['id'] ?>" <?= (int)($o['rider_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>><?= h($r['name']) ?></option>
                 <?php endforeach; ?>
               </select>
               <button class="btn" onclick="assignRider(<?= $o['id'] ?>)">Assign</button>
               <button class="btn" onclick="autoAssignRider(<?= $o['id'] ?>)" title="Assign nearest available rider automatically">⚡ Auto</button>
             </div>
           </td>
         </tr>
       <?php endforeach; if(empty($orders)) echo '<tr><td colspan="10" style="text-align:center;color:#5B6656;">No orders to deliver.</td></tr>'; ?>
     </tbody>
   </table>
  </div>
</div>

<script>
const depotLat = 18.5011, depotLng = 73.9268; // Hadapsar store

function assignRider(orderId) {
  const sel = document.getElementById('rider-' + orderId);
  const riderId = sel ? sel.value : '';
  if (!riderId) {
   alert('Please select a rider before assigning.');
   return;
  }
  fetch('../ajax/assign_rider.php', {
   method: 'POST',
   headers: {'Content-Type':'application/json'},
   body: JSON.stringify({ order_id: orderId, rider_id: Number(riderId), csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
   const text = await r.text();
   try {
     const data = JSON.parse(text);
     if (data.success) {
       location.reload();
     } else {
       alert('Failed to assign rider: ' + (data.error || 'Unknown error'));
     }
   } catch (e) {
     alert('Failed to assign rider: ' + text.slice(0, 200));
   }
  }).catch(()=>alert('Network error'));
}

function autoAssignRider(orderId) {
  fetch('../ajax/assign_rider.php', {
   method: 'POST',
   headers: {'Content-Type':'application/json'},
   body: JSON.stringify({ order_id: orderId, auto: true, csrf_token: '<?= h(csrf_token()) ?>' })
  }).then(async (r) => {
   const text = await r.text();
   try {
     const data = JSON.parse(text);
     if (data.success) {
       location.reload();
     } else {
       alert('Auto-assign failed: ' + (data.error || 'Unknown error'));
     }
   } catch (e) {
     alert('Auto-assign failed: ' + text.slice(0, 200));
   }
  }).catch(()=>alert('Network error'));
}

document.getElementById('planRoute').addEventListener('click', ()=>{
  const ids = Array.from(document.querySelectorAll('.routeChk:checked')).map(cb=>cb.value);
  const rider = document.getElementById('riderSelect').value;
  if (!ids.length) return alert('Select orders to include in the route.');
  if (!rider) return alert('Select a rider.');
  const url = 'route_sheet.php?orders=' + encodeURIComponent(ids.join(',')) + '&rider=' + encodeURIComponent(rider);
  window.open(url, '_blank');
});

document.getElementById('refreshBtn').addEventListener('click', ()=> location.reload());
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>