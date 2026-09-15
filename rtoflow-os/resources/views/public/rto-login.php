<?php
if (!defined('ABSPATH')) exit;

$company  = get_option('rtoflow_company_name', 'RTOASSIST');
$phone    = get_option('rtoflow_company_phone', '');
$mode     = $mode     ?? 'login';  // 'login' | 'register'
$redirect = $redirect ?? '';
$needs2fa = $needs2fa ?? false;
$msg      = sanitize_text_field($_GET['msg'] ?? '');

// Post-logout message
$flash = match($msg) {
    'logged_out'    => ['type' => 'info',    'text' => 'You have been logged out successfully.'],
    'unauthorized'  => ['type' => 'warning', 'text' => 'Please log in to access that page.'],
    'magic_expired' => ['type' => 'warning', 'text' => 'That login link has expired or was already used. Request a new one below.'],
    default         => null,
};
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= esc_html($page_title ?? "Login — {$company}") ?></title>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/public.css') ?>?v=<?= RTOFLOW_VERSION ?>">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#F1F5F9;min-height:100vh;display:flex;flex-direction:column}
.rto-auth-page{flex:1;display:flex;align-items:center;justify-content:center;padding:32px 20px}
.rto-auth-wrap{width:100%;max-width:440px}
.rto-auth-brand{text-align:center;margin-bottom:28px}
.rto-auth-brand a{text-decoration:none;display:inline-flex;align-items:center;gap:10px}
.rto-auth-brand-logo{background:#E97B28;border-radius:10px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;font-size:22px}
.rto-auth-brand-name{font-size:22px;font-weight:900;color:#1B2A6B}
.rto-auth-card{background:#fff;border-radius:16px;box-shadow:0 4px 28px rgba(0,0,0,.08);overflow:hidden}
.rto-auth-tabs{display:grid;grid-template-columns:1fr 1fr;border-bottom:1px solid #e2e8f0}
.rto-auth-tab{padding:14px;text-align:center;font-size:14px;font-weight:600;cursor:pointer;background:#F8FAFC;color:#64748b;border:none;transition:all .15s}
.rto-auth-tab.active{background:#fff;color:#1B2A6B;box-shadow:inset 0 -2px 0 #1B2A6B}
.rto-auth-body{padding:28px}
.rto-field{margin-bottom:16px}
.rto-label{display:block;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.rto-input{width:100%;padding:10px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .15s}
.rto-input:focus{outline:none;border-color:#1B2A6B;box-shadow:0 0 0 3px rgba(27,42,107,.1)}
.rto-btn-full{width:100%;background:#1B2A6B;color:#fff;border:none;padding:13px;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;font-family:inherit;transition:background .15s}
.rto-btn-full:hover{background:#243B8A}
.rto-btn-full:disabled{opacity:.7;cursor:not-allowed}
.rto-auth-msg{padding:11px 14px;border-radius:8px;font-size:13px;margin-bottom:16px;display:none}
.rto-auth-msg.error{background:#FEE2E2;color:#DC2626;border:1px solid #FECACA;display:block}
.rto-auth-msg.success{background:#DCFCE7;color:#166534;border:1px solid #BBF7D0;display:block}
.rto-auth-msg.info{background:#EFF6FF;color:#1D4ED8;border:1px solid #BFDBFE;display:block}
.rto-auth-msg.warning{background:#FEF3C7;color:#92400E;border:1px solid #FDE68A;display:block}
.rto-auth-footer{text-align:center;margin-top:18px;font-size:13px;color:#64748b}
.rto-auth-footer a{color:#1B2A6B;font-weight:600;text-decoration:none}
.rto-auth-footer a:hover{text-decoration:underline}
.rto-divider{display:flex;align-items:center;gap:12px;margin:16px 0;color:#94a3b8;font-size:12px}
.rto-divider::before,.rto-divider::after{content:'';flex:1;height:1px;background:#e2e8f0}
.rto-role-selector{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.rto-role-btn{padding:10px 8px;border:2px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;text-align:center;font-size:13px;font-weight:600;color:#374151;transition:all .15s;font-family:inherit}
.rto-role-btn.selected{border-color:#1B2A6B;background:#EFF6FF;color:#1B2A6B}
.rto-portal-links{display:flex;flex-direction:column;gap:8px;margin-top:6px}
.rto-portal-link{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;text-decoration:none;font-size:13px;font-weight:600;transition:all .15s;border:1.5px solid #e2e8f0}
.rto-portal-link:hover{border-color:#1B2A6B;background:#F8FAFC}
.rto-portal-link-icon{font-size:20px}
.rto-portal-link-text{flex:1}
.rto-portal-link-sub{font-size:11px;color:#94a3b8;font-weight:400}
.rto-site-footer{background:#1B2A6B;color:rgba(255,255,255,.7);text-align:center;padding:14px 20px;font-size:12px}
.rto-site-footer a{color:#fff}
</style>
<?php wp_head(); ?>
<?php
// FIX (mobile pass — public site bottom nav): standalone page, never went
// through layouts/website-header.php — same gap as apply.php/home.php.
?>
<link rel="stylesheet" href="<?= esc_url(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/css/mobile-nav.css')) ?>">
</head>
<body class="rto-website">
<?php require RTOFLOW_DIR . 'resources/views/public/partials/bottom-nav.php'; ?>

<div class="rto-auth-page">
  <div class="rto-auth-wrap">

    <!-- Brand -->
    <div class="rto-auth-brand">
      <a href="<?= esc_url(home_url('/')) ?>">
        <span class="rto-auth-brand-logo">🔑</span>
        <span class="rto-auth-brand-name"><?= esc_html($company) ?></span>
      </a>
      <p style="font-size:13px;color:#64748b;margin-top:6px">RTO Service Platform</p>
    </div>

    <?php if ($flash): ?>
    <div class="rto-auth-msg <?= esc_attr($flash['type']) ?>" style="display:block;margin-bottom:16px"><?= esc_html($flash['text']) ?></div>
    <?php endif; ?>

    <div class="rto-auth-card">
      <?php if ($needs2fa): ?>
      <!-- ── 2FA CHALLENGE PANEL ── -->
      <!-- BUG FIX (ERR_TOO_MANY_REDIRECTS): rendered when Router's 'rto-login'
           case detects an authenticated session that TwoFactor::isEnabled()
           but not yet isVerifiedForSession() — see Router.php and
           rtoflow_ajax_2fa_session_verify_handler() in rtoflow-os.php. -->
      <div class="rto-auth-body" id="panel-2fa">
        <div class="rto-auth-msg" id="tfa-msg"></div>
        <p style="font-size:14px;color:#374151;margin-bottom:18px">
          This account has two-factor authentication enabled. Enter the 6-digit
          code from your authenticator app (or a backup code) to continue.
        </p>
        <div class="rto-field">
          <label class="rto-label" for="tfa-code">Authentication Code</label>
          <input type="text" id="tfa-code" class="rto-input" placeholder="123456" maxlength="10" autocomplete="one-time-code" inputmode="numeric" autofocus>
        </div>
        <button class="rto-btn-full" id="tfa-btn">Verify →</button>
        <p style="font-size:12px;color:#94a3b8;text-align:center;margin-top:14px">
          <a href="<?= esc_url(home_url('/rto-logout/')) ?>" style="color:#64748b">Log out and use a different account</a>
        </p>
      </div>
      <?php else: ?>
      <!-- Tabs -->
      <div class="rto-auth-tabs">
        <button class="rto-auth-tab <?= $mode!=='register'?'active':'' ?>" data-mode="login">Login</button>
        <button class="rto-auth-tab <?= $mode==='register'?'active':'' ?>" data-mode="register">New Account</button>
      </div>

      <!-- ── LOGIN PANEL ── -->
      <div class="rto-auth-body" id="panel-login" style="<?= $mode==='register'?'display:none':'' ?>">
        <div class="rto-auth-msg" id="login-msg"></div>

        <!-- "Logging in as" selector — user request: "frontend login options
             will be shown only for vendor and client not for admin". Email
             OTP / Magic Link are passwordless conveniences meant for
             client/vendor accounts; admin sign-in stays password-only (2FA
             is separately optional, see My Security). Client is the
             default since most visitors to this screen are customers. This
             is a UI convenience only — rtoflow_ajax_verify_email_otp_handler()
             and the magic-link completion in Router.php independently
             refuse to complete either passwordless method for an admin
             account server-side, since a hidden button is not a security
             boundary by itself. -->
        <div class="rto-role-selector" style="margin-bottom:14px">
          <button type="button" class="rto-role-btn selected" data-login-as="user">Client / Vendor</button>
          <button type="button" class="rto-role-btn" data-login-as="admin">Admin</button>
        </div>

        <!-- Login method switcher: user request — "login normally with
             email otp maximum ... custom login screen that works as per
             the desire email otp or magic login". Password stays available
             (existing accounts already have one) alongside the two
             passwordless methods. Hidden entirely when "Admin" is selected
             above — see #login-as-admin/#login-as-user toggle JS below. -->
        <div class="rto-role-selector" id="login-method-switcher" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:18px">
          <button type="button" class="rto-role-btn selected" data-login-method="password">Password</button>
          <button type="button" class="rto-role-btn" data-login-method="otp">Email OTP</button>
          <button type="button" class="rto-role-btn" data-login-method="magic">Magic Link</button>
        </div>

        <!-- Password method -->
        <div id="login-method-password">
          <div class="rto-field">
            <label class="rto-label" for="login-user">Email / Username</label>
            <input type="text" id="login-user" class="rto-input" placeholder="your@email.com" autocomplete="username">
          </div>
          <div class="rto-field" style="margin-bottom:8px">
            <label class="rto-label" for="login-pass">Password</label>
            <input type="password" id="login-pass" class="rto-input" placeholder="••••••••" autocomplete="current-password">
          </div>
          <div style="text-align:right;margin-bottom:18px">
            <a href="<?= esc_url(wp_lostpassword_url()) ?>" style="font-size:12px;color:#64748b">Forgot password?</a>
          </div>
          <button class="rto-btn-full" id="login-btn">Log In →</button>
        </div>

        <!-- Email OTP method -->
        <div id="login-method-otp" style="display:none">
          <div class="rto-field">
            <label class="rto-label" for="otp-email">Email Address</label>
            <input type="email" id="otp-email" class="rto-input" placeholder="your@email.com" autocomplete="email">
          </div>
          <div class="rto-field" id="otp-code-field" style="display:none;margin-bottom:8px">
            <label class="rto-label" for="otp-code">6-Digit Code</label>
            <input type="text" id="otp-code" class="rto-input" placeholder="123456" maxlength="6" autocomplete="one-time-code" inputmode="numeric">
          </div>
          <button class="rto-btn-full" id="otp-send-btn">Send Login Code →</button>
          <button class="rto-btn-full" id="otp-verify-btn" style="display:none">Verify & Log In →</button>
          <p id="otp-resend" style="display:none;font-size:12px;color:#64748b;text-align:center;margin-top:10px">
            Didn't get it? <a href="#" id="otp-resend-link" style="color:#1B2A6B">Send again</a>
          </p>
        </div>

        <!-- Magic link method -->
        <div id="login-method-magic" style="display:none">
          <div class="rto-field">
            <label class="rto-label" for="magic-email">Email Address</label>
            <input type="email" id="magic-email" class="rto-input" placeholder="your@email.com" autocomplete="email">
          </div>
          <button class="rto-btn-full" id="magic-send-btn">Email Me a Login Link →</button>
        </div>

        <div class="rto-divider">or access portals directly</div>

        <!-- Quick portal links (for users who just need to navigate) -->
        <div class="rto-portal-links">
          <a href="<?= esc_url(home_url('/rto-admin/')) ?>" class="rto-portal-link">
            <span class="rto-portal-link-icon">🏛</span>
            <div class="rto-portal-link-text">
              Admin Panel <div class="rto-portal-link-sub">Manage orders, vendors, settings</div>
            </div>
            <span style="color:#94a3b8">›</span>
          </a>
          <a href="<?= esc_url(home_url('/rto-vendor/')) ?>" class="rto-portal-link">
            <span class="rto-portal-link-icon">🔧</span>
            <div class="rto-portal-link-text">
              Vendor Portal <div class="rto-portal-link-sub">View jobs, earnings, documents</div>
            </div>
            <span style="color:#94a3b8">›</span>
          </a>
          <a href="<?= esc_url(home_url('/rto-dashboard/')) ?>" class="rto-portal-link">
            <span class="rto-portal-link-icon">📋</span>
            <div class="rto-portal-link-text">
              Client Dashboard <div class="rto-portal-link-sub">Track your RTO requests</div>
            </div>
            <span style="color:#94a3b8">›</span>
          </a>
        </div>
      </div>

      <!-- ── REGISTER PANEL ── -->
      <div class="rto-auth-body" id="panel-register" style="<?= $mode!=='register'?'display:none':'' ?>">
        <div class="rto-auth-msg" id="reg-msg"></div>
        <p style="font-size:13px;color:#64748b;margin-bottom:16px">Register to track and manage your RTO service requests.</p>

        <div class="rto-field">
          <label class="rto-label" for="reg-name">Full Name *</label>
          <input type="text" id="reg-name" class="rto-input" placeholder="As per Aadhaar" autocomplete="name">
        </div>
        <div class="rto-field">
          <label class="rto-label" for="reg-mobile">Mobile Number *</label>
          <div style="display:flex;gap:8px">
            <input type="tel" id="reg-mobile" class="rto-input" placeholder="10-digit mobile" maxlength="10" autocomplete="tel" style="flex:1">
            <!-- ENTERPRISE GAP FIX: mobile numbers were collected and stored
                 but never verified — see rtoflow_send_registration_otp_handler()
                 in rtoflow-os.php. This button is a no-op (shows a message and
                 lets the customer continue straight through) when the install
                 has no SMS provider configured, so it can't ever block
                 registration on an unconfigured site. -->
            <button type="button" id="reg-mobile-otp-btn" class="rto-btn" style="white-space:nowrap;padding:0 14px;font-size:13px">Send Code</button>
          </div>
        </div>
        <div class="rto-field" id="reg-otp-field" style="display:none">
          <label class="rto-label" for="reg-otp">Verification Code *</label>
          <input type="text" id="reg-otp" class="rto-input" placeholder="6-digit code" maxlength="6" autocomplete="one-time-code" inputmode="numeric">
        </div>
        <div class="rto-field">
          <label class="rto-label" for="reg-email">Email Address *</label>
          <input type="email" id="reg-email" class="rto-input" placeholder="your@email.com" autocomplete="email">
        </div>
        <div class="rto-field" style="margin-bottom:20px">
          <label class="rto-label" for="reg-pass">Password *</label>
          <input type="password" id="reg-pass" class="rto-input" placeholder="Min 8 characters" autocomplete="new-password" minlength="8">
        </div>
        <button class="rto-btn-full" id="reg-btn">Create Account →</button>
        <p style="font-size:11px;color:#94a3b8;text-align:center;margin-top:10px">By registering you agree to our <a href="<?= esc_url(home_url('/terms-conditions')) ?>" style="color:#1B2A6B">Terms of Service</a></p>
      </div>
      <?php endif; ?>
    </div>

    <div class="rto-auth-footer">
      <a href="<?= esc_url(home_url('/rto-apply/')) ?>">Submit a Service Request</a>
      &nbsp;·&nbsp;
      <a href="<?= esc_url(home_url('/')) ?>">← Back to Home</a>
      <?php if ($phone): ?>
      &nbsp;·&nbsp;
      <a href="tel:<?= esc_attr($phone) ?>">📞 <?= esc_html($phone) ?></a>
      <?php endif; ?>
    </div>
  </div>
</div>

<footer class="rto-site-footer">
  © <?= date('Y') ?> <?= esc_html($company) ?> &nbsp;·&nbsp;
  <a href="<?= esc_url(home_url('/privacy-policy')) ?>">Privacy</a> &nbsp;·&nbsp;
  <a href="<?= esc_url(home_url('/terms-conditions')) ?>">Terms</a>
</footer>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var _rtoRedirect = <?= json_encode($redirect ?: '') ?>;
var _ajaxUrl     = <?= json_encode(admin_url('admin-ajax.php')) ?>;
var _nonce       = <?= json_encode(wp_create_nonce('rtoflow_login')) ?>;

document.querySelectorAll('.rto-auth-tab').forEach(function(t){
  t.addEventListener('click', function(){ switchMode(t.dataset.mode); });
});
if (document.getElementById('login-btn')) document.getElementById('login-btn').addEventListener('click', doLogin);
if (document.getElementById('reg-mobile-otp-btn')) document.getElementById('reg-mobile-otp-btn').addEventListener('click', sendRegOtp);
if (document.getElementById('reg-btn')) document.getElementById('reg-btn').addEventListener('click', doRegister);

// ── "Logging in as" selector (user request: passwordless options are for
// client/vendor only, admin sign-in stays password-only) ──────────────────
document.querySelectorAll('[data-login-as]').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('[data-login-as]').forEach(function(b){ b.classList.remove('selected'); });
    btn.classList.add('selected');
    var isAdmin = (btn.dataset.loginAs === 'admin');
    var switcher = document.getElementById('login-method-switcher');
    if (isAdmin) {
      switcher.style.display = 'none';
      // Force the password panel regardless of whatever was selected before.
      document.querySelectorAll('[data-login-method]').forEach(function(b){ b.classList.remove('selected'); });
      document.querySelector('[data-login-method="password"]').classList.add('selected');
      ['password','otp','magic'].forEach(function(m){
        var el = document.getElementById('login-method-' + m);
        if (el) el.style.display = (m === 'password') ? '' : 'none';
      });
    } else {
      switcher.style.display = '';
    }
    document.getElementById('login-msg').style.display = 'none';
  });
});

// ── Login method switcher (Password / Email OTP / Magic Link) ─────────────
document.querySelectorAll('[data-login-method]').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('[data-login-method]').forEach(function(b){ b.classList.remove('selected'); });
    btn.classList.add('selected');
    ['password','otp','magic'].forEach(function(m){
      var el = document.getElementById('login-method-' + m);
      if (el) el.style.display = (m === btn.dataset.loginMethod) ? '' : 'none';
    });
    document.getElementById('login-msg').style.display = 'none';
  });
});

// ── Email OTP login ─────────────────────────────────────────────────────
var _otpEmailSent = '';
if (document.getElementById('otp-send-btn')) {
  document.getElementById('otp-send-btn').addEventListener('click', sendLoginOtp);
}
if (document.getElementById('otp-verify-btn')) {
  document.getElementById('otp-verify-btn').addEventListener('click', verifyLoginOtp);
}
if (document.getElementById('otp-resend-link')) {
  document.getElementById('otp-resend-link').addEventListener('click', function(e){ e.preventDefault(); sendLoginOtp(); });
}
if (document.getElementById('otp-code')) {
  document.getElementById('otp-code').addEventListener('keydown', function(e){ if (e.key === 'Enter') verifyLoginOtp(); });
}
function sendLoginOtp() {
  var email = document.getElementById('otp-email').value.trim();
  if (!email) { showMsg('login-msg','error','Enter your email address.'); return; }
  var btn = document.getElementById('otp-send-btn');
  btn.disabled = true; btn.textContent = 'Sending…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_request_email_otp'); fd.append('nonce',_nonce);
  fd.append('email', email);
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Send Login Code →';
      if (d.success) {
        _otpEmailSent = email;
        document.getElementById('otp-code-field').style.display = '';
        document.getElementById('otp-send-btn').style.display = 'none';
        document.getElementById('otp-verify-btn').style.display = '';
        document.getElementById('otp-resend').style.display = 'block';
        showMsg('login-msg','success', (d.data && d.data.message) || 'Code sent — check your email.');
      } else {
        showMsg('login-msg','error', d.data || 'Could not send code. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Send Login Code →';
      showMsg('login-msg','error','Connection error. Please try again.');
    });
}
function verifyLoginOtp() {
  var code = document.getElementById('otp-code').value.trim();
  if (!code) { showMsg('login-msg','error','Enter the 6-digit code we emailed you.'); return; }
  var btn = document.getElementById('otp-verify-btn');
  btn.disabled = true; btn.textContent = 'Verifying…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_verify_email_otp'); fd.append('nonce',_nonce);
  fd.append('email', _otpEmailSent); fd.append('code', code); fd.append('redirect', _rtoRedirect || '');
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Verify & Log In →';
      if (d.success) {
        showMsg('login-msg','success','Login successful! Redirecting…');
        window.location.href = d.data.redirect || '<?= esc_js(home_url('/rto-dashboard/')) ?>';
      } else {
        showMsg('login-msg','error', d.data || 'Incorrect or expired code.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Verify & Log In →';
      showMsg('login-msg','error','Connection error. Please try again.');
    });
}

// ── Magic link login ────────────────────────────────────────────────────
if (document.getElementById('magic-send-btn')) {
  document.getElementById('magic-send-btn').addEventListener('click', sendMagicLink);
}
function sendMagicLink() {
  var email = document.getElementById('magic-email').value.trim();
  if (!email) { showMsg('login-msg','error','Enter your email address.'); return; }
  var btn = document.getElementById('magic-send-btn');
  btn.disabled = true; btn.textContent = 'Sending…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_request_magic_link'); fd.append('nonce',_nonce);
  fd.append('email', email); fd.append('redirect', _rtoRedirect || '');
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Email Me a Login Link →';
      if (d.success) {
        showMsg('login-msg','success', (d.data && d.data.message) || 'Login link sent — check your email.');
      } else {
        showMsg('login-msg','error', d.data || 'Could not send login link. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Email Me a Login Link →';
      showMsg('login-msg','error','Connection error. Please try again.');
    });
}

// BUG FIX (ERR_TOO_MANY_REDIRECTS): 2FA session-challenge panel, rendered
// only when the server determined this authenticated session still needs
// verification. See rtoflow_ajax_2fa_session_verify_handler() in rtoflow-os.php.
if (document.getElementById('tfa-btn')) {
  document.getElementById('tfa-btn').addEventListener('click', doVerify2fa);
  document.getElementById('tfa-code').addEventListener('keydown', function(e){
    if (e.key === 'Enter') doVerify2fa();
  });
}
function doVerify2fa() {
  var code = document.getElementById('tfa-code').value.trim();
  var btn  = document.getElementById('tfa-btn');
  if (!code) { showMsg('tfa-msg','error','Enter the 6-digit code from your authenticator app.'); return; }
  btn.disabled = true; btn.textContent = 'Verifying…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_2fa_session_verify'); fd.append('nonce',_nonce);
  fd.append('code', code); fd.append('redirect', _rtoRedirect || '');
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Verify →';
      if (d.success) {
        showMsg('tfa-msg','success','Verified! Redirecting…');
        window.location.href = d.data.redirect || '<?= esc_js(home_url('/rto-dashboard/')) ?>';
      } else {
        showMsg('tfa-msg','error', d.data || 'Incorrect code. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Verify →';
      showMsg('tfa-msg','error','Connection error. Please try again.');
    });
}

function switchMode(mode) {
  document.getElementById('panel-login').style.display    = mode==='login'    ? '' : 'none';
  document.getElementById('panel-register').style.display = mode==='register' ? '' : 'none';
  document.querySelectorAll('.rto-auth-tab').forEach(function(t,i){
    t.classList.toggle('active', (i===0 && mode==='login') || (i===1 && mode==='register'));
  });
}

function showMsg(id, type, text) {
  var el = document.getElementById(id);
  el.className = 'rto-auth-msg ' + type;
  el.textContent = text;
  el.style.display = 'block';
}

function doLogin() {
  var u = document.getElementById('login-user').value.trim();
  var p = document.getElementById('login-pass').value;
  var btn = document.getElementById('login-btn');
  if (!u || !p) { showMsg('login-msg','error','Please enter your email and password.'); return; }
  btn.disabled = true; btn.textContent = 'Logging in…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_login'); fd.append('nonce',_nonce);
  fd.append('username',u); fd.append('password',p);
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Log In →';
      if (d.success) {
        showMsg('login-msg','success','Login successful! Redirecting…');
        window.location.href = _rtoRedirect || d.data.redirect || '<?= esc_js(home_url('/rto-dashboard/')) ?>';
      } else {
        showMsg('login-msg','error', d.data || 'Invalid email or password. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Log In →';
      showMsg('login-msg','error','Connection error. Please try again.');
    });
}

// ENTERPRISE GAP FIX: closes the missing verification step for
// RTOFLOW_SMS::sendOtp()/verifyOtp() — see rtoflow-os.php's
// rtoflow_send_registration_otp_handler() docblock for the full trace.
// otpRequired starts true (fail-safe): if this call fails outright
// (network error) the OTP field is left visible rather than silently
// assuming verification isn't needed, since a false negative here just
// costs an extra click, while a false positive would let an unverified
// number straight through.
var otpRequired = true;
function sendRegOtp() {
  var mobile = document.getElementById('reg-mobile').value.trim().replace(/\D/g,'');
  if (mobile.length !== 10) { showMsg('reg-msg','error','Enter a valid 10-digit mobile number first.'); return; }
  var btn = document.getElementById('reg-mobile-otp-btn');
  btn.disabled = true; btn.textContent = 'Sending…';
  var fd = new FormData();
  fd.append('action','rtoflow_send_registration_otp');
  fd.append('nonce', <?= json_encode(wp_create_nonce('rtoflow_register_otp')) ?>);
  fd.append('mobile', mobile);
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      if (d.success) {
        otpRequired = !!(d.data && d.data.otp_required);
        if (otpRequired) {
          document.getElementById('reg-otp-field').style.display = '';
          showMsg('reg-msg','success', (d.data && d.data.message) || 'Code sent to your mobile number.');
          btn.textContent = 'Resend';
          btn.disabled = false;
        } else {
          // No SMS provider configured on this install — nothing to verify.
          document.getElementById('reg-otp-field').style.display = 'none';
          btn.textContent = 'Not required';
        }
      } else {
        showMsg('reg-msg','error', d.data || 'Could not send the code. Please try again.');
        btn.disabled = false; btn.textContent = 'Send Code';
      }
    })
    .catch(function(){
      showMsg('reg-msg','error','Connection error. Please try again.');
      btn.disabled = false; btn.textContent = 'Send Code';
    });
}

function doRegister() {
  var name   = document.getElementById('reg-name').value.trim();
  var mobile = document.getElementById('reg-mobile').value.trim().replace(/\D/g,'');
  var email  = document.getElementById('reg-email').value.trim();
  var pass   = document.getElementById('reg-pass').value;
  var otp    = document.getElementById('reg-otp').value.trim();
  var btn    = document.getElementById('reg-btn');

  if (!name || !mobile || !email || !pass) { showMsg('reg-msg','error','All fields are required.'); return; }
  if (mobile.length !== 10) { showMsg('reg-msg','error','Enter a valid 10-digit mobile number.'); return; }
  if (pass.length < 8) { showMsg('reg-msg','error','Password must be at least 8 characters.'); return; }
  if (otpRequired && document.getElementById('reg-otp-field').style.display !== 'none' && !otp) {
    showMsg('reg-msg','error','Enter the verification code sent to your mobile number.'); return;
  }

  btn.disabled = true; btn.textContent = 'Creating account…';
  var fd = new FormData();
  fd.append('action','rtoflow_ajax_register');
  fd.append('nonce', <?= json_encode(wp_create_nonce('rtoflow_register')) ?>);
  fd.append('name',name); fd.append('mobile',mobile);
  fd.append('email',email); fd.append('password',pass); fd.append('otp',otp);
  fetch(_ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(d){
      btn.disabled = false; btn.textContent = 'Create Account →';
      if (d.success) {
        showMsg('reg-msg','success','Account created! Logging you in…');
        window.location.href = d.data.redirect || '<?= esc_js(home_url('/rto-dashboard/')) ?>';
      } else {
        showMsg('reg-msg','error', d.data || 'Registration failed. Please try again.');
      }
    })
    .catch(function(){
      btn.disabled = false; btn.textContent = 'Create Account →';
      showMsg('reg-msg','error','Connection error. Please try again.');
    });
}

// Enter key support
document.addEventListener('keydown', function(e) {
  if (e.key !== 'Enter') return;
  if (document.getElementById('panel-login').style.display !== 'none') doLogin();
  else doRegister();
});
</script>
<script src="<?= esc_url(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') ?>?v=<?= esc_attr(rto_asset_version('resources/assets/js/mobile-nav.js')) ?>"></script>
<?php wp_footer(); ?>
</body>
</html>
