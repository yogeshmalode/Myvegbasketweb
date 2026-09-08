<?php
require_once __DIR__ . '/includes/auth.php';
$page_title = 'Scan Delivery (Confirm)';
include __DIR__ . '/includes/admin_header.php';
?>
<div class="section-head"><h2>Scan Delivery</h2><p>Scan an order barcode to mark it delivered. Use a mobile device or laptop camera.</p></div>
<div class="form-card">
  <div style="display:flex; gap:20px; align-items:flex-start;">
    <div style="flex:1;">
      <div id="interactive" style="width:100%; height:360px; border:1px solid #E6EAE2; background:#000; display:flex;align-items:center;justify-content:center; color:#fff;">Camera preview</div>
      <p style="margin-top:8px; color:#5B6656;">Point camera at barcode. On success the order will be looked up and you can confirm delivery.</p>
    </div>
    <aside style="width:360px;">
      <div class="form-card">
        <h3>Manual confirm</h3>
        <form id="manualForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <div class="form-group"><label>Order barcode (e.g., ORD123)</label><input name="barcode" required></div>
          <div class="form-group"><label>Note</label><input name="note"></div>
          <div style="text-align:right;"><button class="btn btn-primary" type="submit">Confirm delivery</button></div>
        </form>
      </div>
    </aside>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
<script>
function onDetected(result){
  const code = result.codeResult.code;
  if(!code) return;
  // Expect barcode like ORD123 or raw numeric id; extract numeric
  let orderId = null;
  const m = code.match(/ORD(\d+)/i);
  if(m) orderId = parseInt(m[1],10);
  else if (/^\d+$/.test(code)) orderId = parseInt(code,10);
  if(!orderId) { alert('Unrecognized barcode: ' + code); return; }
  if(!confirm('Mark order #' + orderId + ' as delivered?')) return;
  fetch('<?= BASE_URL ?>/ajax/scan_mark_delivered.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ order_id: orderId, csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{
    if(data.success){ alert('Order marked delivered.'); location.reload(); } else alert('Failed: ' + (data.error||''));
  }).catch(()=>alert('Network error'));
}

if (navigator.mediaDevices && typeof Quagga !== 'undefined'){
  Quagga.init({
    inputStream: { type: 'LiveStream', target: document.querySelector('#interactive'), constraints: { facingMode: 'environment' } },
    decoder: { readers: ['code_128_reader','ean_reader','ean_8_reader','code_39_reader'] },
    locate: true
  }, function(err){ if(err){ console.error(err); document.getElementById('interactive').textContent = 'Camera not available'; return; } Quagga.start(); });
  Quagga.onDetected(onDetected);
}

// Manual form
document.getElementById('manualForm').addEventListener('submit', function(e){ e.preventDefault(); const fd = new FormData(this); const b = fd.get('barcode').trim(); if(!b) return; fetch('<?= BASE_URL ?>/ajax/scan_mark_delivered.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ order_id_raw: b, note: fd.get('note'), csrf_token: '<?= h(csrf_token()) ?>' }) }).then(r=>r.json()).then(data=>{ if(data.success){ alert('Order marked delivered'); location.reload(); } else alert('Error: '+(data.error||'')); }).catch(()=>alert('Network error')); });
</script>

<?php include __DIR__ . '/includes/admin_footer.php'; ?>