<?php
require_once __DIR__ . '/config.php';
$page_title = 'Enquire Now';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:560px;">
  <div class="section-head" style="margin-top:0;">
    <h2>Enquire Now</h2>
    <p>Have a question about a product, bulk order, or delivery? Send it straight to us — no WhatsApp login needed, we'll get it instantly.</p>
  </div>

  <div id="enquiryAlert"></div>

  <div class="form-card">
    <form id="enquiryForm">
      <div class="form-group">
        <label for="enq_name">Full name</label>
        <input type="text" id="enq_name" name="name" required>
      </div>
      <div class="form-group">
        <label for="enq_phone">Phone number</label>
        <input type="tel" id="enq_phone" name="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" required>
      </div>
      <div class="form-group">
        <label for="enq_message">Your enquiry</label>
        <textarea id="enq_message" name="message" rows="4" required placeholder="e.g. Do you deliver fresh coriander to Hadapsar?"></textarea>
      </div>
      <button type="submit" class="btn btn-primary btn-block" id="enquirySubmitBtn">Send Enquiry</button>
    </form>
  </div>
</div>

<script>
document.getElementById('enquiryForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const btn = document.getElementById('enquirySubmitBtn');
  const alertBox = document.getElementById('enquiryAlert');
  btn.disabled = true;
  btn.textContent = 'Sending...';
  alertBox.innerHTML = '';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/send_enquiry.php', {
    method: 'POST',
    body: new FormData(this)
  })
    .then(r => r.json())
    .then(res => {
      btn.disabled = false;
      btn.textContent = 'Send Enquiry';
      if (res.success) {
        alertBox.innerHTML = '<div class="alert alert-success">Opening WhatsApp — just tap send to complete your enquiry.</div>';
        document.getElementById('enquiryForm').reset();
        window.open(res.whatsapp_url, '_blank');
      } else {
        alertBox.innerHTML = '<div class="alert alert-error">' + (res.message || 'Could not send your enquiry.') + '</div>';
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.textContent = 'Send Enquiry';
      alertBox.innerHTML = '<div class="alert alert-error">Something went wrong. Please try again.</div>';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
