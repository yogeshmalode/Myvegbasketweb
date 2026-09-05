<?php
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? 'my_account.php';

$page_title = 'Login with Email Code';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Login with an email code</h2>
    <p style="color:#5B6656; margin-bottom:20px;">No password needed — we'll email you a one-time code.</p>

    <div id="otpAlert"></div>

    <!-- STEP 1: email address -->
    <div id="emailStep">
      <div class="form-group">
        <label for="email">Email address</label>
        <input type="email" id="email" placeholder="you@example.com" required autofocus>
      </div>
      <button type="button" id="sendOtpBtn" class="btn btn-primary btn-block">Send code</button>
    </div>

    <!-- STEP 2: code entry (shown after email step) -->
    <div id="otpStep" style="display:none;">
      <p style="color:#5B6656; font-size:0.9rem;">Code sent to <strong id="otpEmailDisplay"></strong>. <a href="#" id="changeEmailLink" style="color:#3F8B52;">Change email</a></p>

      <div id="newUserFields" style="display:none;">
        <div class="form-group">
          <label for="name">Your name</label>
          <input type="text" id="name" placeholder="So we know what to call you">
        </div>
        <div class="form-group">
          <label for="phone">Mobile number</label>
          <input type="tel" id="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number — needed for delivery">
        </div>
      </div>

      <div class="form-group">
        <label for="otp">Enter code</label>
        <input type="text" id="otp" inputmode="numeric" placeholder="6-digit code" maxlength="6">
      </div>
      <button type="button" id="verifyOtpBtn" class="btn btn-primary btn-block">Verify &amp; Login</button>
      <p style="text-align:center; margin-top:12px; font-size:0.85rem;">
        Didn't get it? <a href="#" id="resendLink" style="color:#3F8B52;">Resend code</a>
      </p>
    </div>

    <p style="text-align:center; margin-top:16px; font-size:0.9rem;">
      Have a password? <a href="<?= BASE_URL ?>/login.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Log in with password</a>
    </p>
  </div>
</div>

<script>
const emailStep       = document.getElementById('emailStep');
const otpStep         = document.getElementById('otpStep');
const alertBox        = document.getElementById('otpAlert');
const emailInput      = document.getElementById('email');
const sendOtpBtn      = document.getElementById('sendOtpBtn');
const verifyOtpBtn    = document.getElementById('verifyOtpBtn');
const resendLink      = document.getElementById('resendLink');
const changeEmailLink = document.getElementById('changeEmailLink');
const newUserFields   = document.getElementById('newUserFields');

function showAlert(type, msg) {
  alertBox.innerHTML = '<div class="alert alert-' + type + '">' + msg + '</div>';
}

function sendOtp() {
  const email = emailInput.value.trim();
  if (!email || !email.includes('@')) {
    showAlert('error', 'Enter a valid email address.');
    return;
  }
  sendOtpBtn.disabled = true;
  sendOtpBtn.textContent = 'Sending...';
  alertBox.innerHTML = '';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/send_email_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'email=' + encodeURIComponent(email)
  })
    .then(r => r.json())
    .then(res => {
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send code';
      if (!res.success) {
        showAlert('error', res.message || 'Could not send code.');
        return;
      }
      document.getElementById('otpEmailDisplay').textContent = email;
      newUserFields.style.display = res.is_new ? 'block' : 'none';
      emailStep.style.display = 'none';
      otpStep.style.display = 'block';
      document.getElementById('otp').focus();
    })
    .catch(() => {
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send code';
      showAlert('error', 'Network error, please try again.');
    });
}

sendOtpBtn.addEventListener('click', sendOtp);
resendLink.addEventListener('click', (e) => { e.preventDefault(); sendOtp(); });
changeEmailLink.addEventListener('click', (e) => {
  e.preventDefault();
  otpStep.style.display = 'none';
  emailStep.style.display = 'block';
  alertBox.innerHTML = '';
});

verifyOtpBtn.addEventListener('click', () => {
  const otp = document.getElementById('otp').value.trim();
  const name = document.getElementById('name').value.trim();
  const phone = document.getElementById('phone').value.trim();
  if (!otp) {
    showAlert('error', 'Enter the code you received.');
    return;
  }
  verifyOtpBtn.disabled = true;
  verifyOtpBtn.textContent = 'Verifying...';

  fetch(window.__VEGBASKET_BASE__ + '/ajax/verify_email_otp.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'otp=' + encodeURIComponent(otp) + '&name=' + encodeURIComponent(name) + '&phone=' + encodeURIComponent(phone)
  })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        window.location.href = window.__VEGBASKET_BASE__ + '/<?= h(ltrim($redirectTo, '/')) ?>';
      } else {
        verifyOtpBtn.disabled = false;
        verifyOtpBtn.textContent = 'Verify & Login';
        showAlert('error', res.message || 'Could not verify code.');
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
