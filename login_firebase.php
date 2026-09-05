<?php
require_once __DIR__ . '/config.php';

if (is_customer_logged_in()) {
    redirect(BASE_URL . '/my_account.php');
}

$redirectTo = $_GET['redirect'] ?? 'my_account.php';
$firebaseConfigured = defined('FIREBASE_API_KEY') && FIREBASE_API_KEY !== 'your_firebase_api_key_here';

$page_title = 'Login with Firebase OTP';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding:50px 24px; max-width:420px;">
  <div class="form-card">
    <h2 style="margin-top:0;">Login with mobile OTP</h2>
    <p style="color:#5B6656; margin-bottom:20px;">Powered by Firebase — we'll text you a one-time code.</p>

    <?php if (!$firebaseConfigured): ?>
      <div class="alert alert-error">
        Firebase isn't configured yet. Add your Firebase project's API key, auth domain, and project ID to <code>config.php</code> first — see the comments there for exactly where to get them from the Firebase console.
      </div>
    <?php else: ?>

    <div id="otpAlert"></div>

    <!-- STEP 1: phone number -->
    <div id="phoneStep">
      <div class="form-group">
        <label for="phone">Mobile number</label>
        <div style="display:flex; gap:8px;">
          <span style="display:flex; align-items:center; padding:0 10px; border:1px solid #D9E0CD; border-radius:8px; color:#5B6656; font-weight:600;">+91</span>
          <input type="tel" id="phone" pattern="[0-9]{10}" placeholder="10-digit mobile number" required autofocus style="flex:1;">
        </div>
      </div>
      <div id="recaptcha-container"></div>
      <button type="button" id="sendOtpBtn" class="btn btn-primary btn-block">Send OTP</button>
    </div>

    <!-- STEP 2: OTP entry -->
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
      Prefer email? <a href="<?= BASE_URL ?>/login_email_otp.php?redirect=<?= urlencode($redirectTo) ?>" style="color:#3F8B52;">Login with an email code (free)</a>
    </p>
    <?php endif; ?>
  </div>
</div>

<?php if ($firebaseConfigured): ?>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-auth-compat.js"></script>
<script>
const firebaseConfig = {
  apiKey: <?= json_encode(FIREBASE_API_KEY) ?>,
  authDomain: <?= json_encode(FIREBASE_AUTH_DOMAIN) ?>,
  projectId: <?= json_encode(FIREBASE_PROJECT_ID) ?>,
};
firebase.initializeApp(firebaseConfig);
const auth = firebase.auth();

const phoneStep       = document.getElementById('phoneStep');
const otpStep         = document.getElementById('otpStep');
const alertBox        = document.getElementById('otpAlert');
const phoneInput      = document.getElementById('phone');
const sendOtpBtn      = document.getElementById('sendOtpBtn');
const verifyOtpBtn    = document.getElementById('verifyOtpBtn');
const resendLink      = document.getElementById('resendLink');
const changeNumberLink= document.getElementById('changeNumberLink');
const nameField       = document.getElementById('nameField');

let confirmationResult = null;
let recaptchaVerifier = null;

function showAlert(type, msg) {
  alertBox.innerHTML = '<div class="alert alert-' + type + '">' + msg + '</div>';
}

function setupRecaptcha() {
  recaptchaVerifier = new firebase.auth.RecaptchaVerifier('recaptcha-container', {
    size: 'invisible'
  });
}
setupRecaptcha();

function sendOtp() {
  const phone = phoneInput.value.trim();
  if (!/^[0-9]{10}$/.test(phone)) {
    showAlert('error', 'Enter a valid 10-digit mobile number.');
    return;
  }
  sendOtpBtn.disabled = true;
  sendOtpBtn.textContent = 'Sending...';
  alertBox.innerHTML = '';

  auth.signInWithPhoneNumber('+91' + phone, recaptchaVerifier)
    .then((result) => {
      confirmationResult = result;
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send OTP';
      document.getElementById('otpPhoneDisplay').textContent = '+91 ' + phone;
      phoneStep.style.display = 'none';
      otpStep.style.display = 'block';
      document.getElementById('otp').focus();
    })
    .catch((error) => {
      sendOtpBtn.disabled = false;
      sendOtpBtn.textContent = 'Send OTP';
      showAlert('error', 'Could not send OTP: ' + (error.message || 'please try again.'));
      // Recaptcha widgets are single-use — rebuild it after a failure.
      setupRecaptcha();
    });
}

sendOtpBtn.addEventListener('click', sendOtp);
resendLink.addEventListener('click', (e) => { e.preventDefault(); sendOtp(); });
changeNumberLink.addEventListener('click', (e) => {
  e.preventDefault();
  otpStep.style.display = 'none';
  phoneStep.style.display = 'block';
  alertBox.innerHTML = '';
  window.__firebaseIdToken = null;
  confirmationResult = null;
});

verifyOtpBtn.addEventListener('click', () => {
  const otp = document.getElementById('otp').value.trim();
  const name = document.getElementById('name').value.trim();

  verifyOtpBtn.disabled = true;
  verifyOtpBtn.textContent = 'Verifying...';

  // If we already got a verified ID token from a previous attempt (i.e.
  // we're just resubmitting with a name now), reuse it — Firebase's
  // confirm() can only be called successfully once per code.
  const tokenPromise = window.__firebaseIdToken
    ? Promise.resolve(window.__firebaseIdToken)
    : (() => {
        if (!otp || !confirmationResult) {
          return Promise.reject(new Error('Enter the OTP you received.'));
        }
        return confirmationResult.confirm(otp)
          .then((userCredential) => userCredential.user.getIdToken())
          .then((idToken) => { window.__firebaseIdToken = idToken; return idToken; });
      })();

  tokenPromise
    .then((idToken) => fetch(window.__VEGBASKET_BASE__ + '/ajax/firebase_login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'id_token=' + encodeURIComponent(idToken) + '&name=' + encodeURIComponent(name)
    }))
    .then((r) => r.json())
    .then((res) => {
      if (res.success) {
        window.location.href = window.__VEGBASKET_BASE__ + '/<?= h(ltrim($redirectTo, '/')) ?>';
      } else if (res.need_name) {
        // Valid OTP, but this is a new number — ask for a name and let
        // them resubmit without needing to request another OTP.
        verifyOtpBtn.disabled = false;
        verifyOtpBtn.textContent = 'Create account';
        nameField.style.display = 'block';
        showAlert('error', res.message || 'Please enter your name to finish creating your account.');
      } else {
        verifyOtpBtn.disabled = false;
        verifyOtpBtn.textContent = 'Verify & Login';
        showAlert('error', res.message || 'Could not verify OTP.');
      }
    })
    .catch((error) => {
      verifyOtpBtn.disabled = false;
      verifyOtpBtn.textContent = 'Verify & Login';
      showAlert('error', error.message || 'Incorrect or expired OTP, please try again.');
    });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
