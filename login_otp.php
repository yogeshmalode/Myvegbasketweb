<?php
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? 'my_account.php';

$page_title = 'Login with OTP';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Login with mobile OTP</h2>
    <p style="color:#5B6656; margin-bottom:20px;">No password needed — we'll text you a one-time code.</p>

    <div id="otpAlert"></div>

    <!-- STEP 1: phone number -->
    <div id="phoneStep">
      <div class="form-group">
        <label for="phone">Mobile number</label>
        <input type="tel" id="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" required autofocus>
      </div>
      <button type="button" id="sendOtpBtn" class="btn btn-primary btn-block">Send OTP</button>
    </div>

    <!-- STEP 2: OTP entry (shown after phone step) -->
    <div id="otpStep" style="display:none;">
      <p style="color:#5B6656; font-size:0.9rem;">Code sent to <strong id="otpPhoneDisplay"></strong>. <a href="#" id="changeNumberLink" style="color:#3F8B52;">Change number</a></p>

      <div id="nameField" class="form-group" style="display:none;">
        <label for="name">Your name</label>
        <input type="text" id="name" placeholder="So we know what to call you">
      </div>

      <div class="form-group">
        <label for="otp">Enter OTP</label>
        <input type="text" id="otp" inputmode="numeric" placeholder="6-digit code" maxlength="6">
      </div>
      <button type="button" id="verifyOtpBtn" class="btn btn-primary btn-block">Verify &amp; Login</button>
      <p style="text-align:center; margin-top:12px; font-size:0.85rem;">
        Didn't get it? <a href="#" id="resendLink" style="color:#3F8B52;">Resend OTP</a>
      </p>
    </div>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem;">
      Prefer email instead? <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Login with an email code</a>
    </p>
    <p style="text-align:center; margin-top:6px; font-size:0.9rem;">
      Have a password? <a href="<?= BASE_URL ?>/login.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Log in with password</a>
    </p>
  </div>
</div>

<script>
const phoneStep      = document.getElementById('phoneStep');
const otpStep         = document.getElementById('otpStep');
const alertBox        = document.getElementById('otpAlert');
const phoneInput      = document.getElementById('phone');
const sendOtpBtn      = document.getElementById('sendOtpBtn');
const verifyOtpBtn    = document.getElementById('verifyOtpBtn');
const resendLink      = document.getElementById('resendLink');
const changeNumberLink= document.getElementById('changeNumberLink');
const nameField       = document.getElementById('nameField');

function showAlert(type, msg) {
  alertBox.innerHTML = '<div class="alert alert-' + type + '">' + msg + '</div>';
}

function sendOtp() {
  const phone = phoneInput.value.trim();
  if (!/^[0-9]{10}$/.test(phone)) {
    showAlert('error', 'Enter a valid 10-digit mobile number.');
    return;
  }
  sendOtpBtn.disabled = true;
  sendOtpBtn.textContent = 'Sending...';
  alertBox.innerHTML = '';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/send_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'phone=' + encodeURIComponent(phone)
  })
    .then(r => r.json())
    .then(res => {
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send OTP';
      if (!res.success) {
        showAlert('error', res.message || 'Could not send OTP.');
        return;
      }
      document.getElementById('otpPhoneDisplay').textContent = phone;
      nameField.style.display = res.is_new ? 'block' : 'none';
      phoneStep.style.display = 'none';
      otpStep.style.display = 'block';
      document.getElementById('otp').focus();
    })
    .catch(() => {
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send OTP';
      showAlert('error', 'Network error, please try again.');
    });
}

sendOtpBtn.addEventListener('click', sendOtp);
resendLink.addEventListener('click', (e) => { e.preventDefault(); sendOtp(); });
changeNumberLink.addEventListener('click', (e) => {
  e.preventDefault();
  otpStep.style.display = 'none';
  phoneStep.style.display = 'block';
  alertBox.innerHTML = '';
});

verifyOtpBtn.addEventListener('click', () => {
  const otp = document.getElementById('otp').value.trim();
  const name = document.getElementById('name').value.trim();
  if (!otp) {
    showAlert('error', 'Enter the OTP you received.');
    return;
  }
  verifyOtpBtn.disabled = true;
  verifyOtpBtn.textContent = 'Verifying...';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/verify_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'otp=' + encodeURIComponent(otp) + '&name=' + encodeURIComponent(name)
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        window.location.href = window.__VEGBASKET_BASE__ + '/<?= h(ltrim($redirectTo, '/')) ?>';
      } else {
        verifyOtpBtn.disabled = false;
        verifyOtpBtn.textContent = 'Verify & Login';
        showAlert('error', res.message || 'Could not verify OTP.');
      }
    })
    .catch(() => {
      verifyOtpBtn.disabled = false;
      verifyOtpBtn.textContent = 'Verify & Login';
      showAlert('error', 'Network error, please try again.');
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
