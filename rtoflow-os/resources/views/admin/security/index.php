<?php if (!defined('ABSPATH')) exit;
/** @var bool $enabled @var int $remainingBackupCodes */
$pageTitle = 'My Security';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$nonce = wp_create_nonce('rto_admin_lead');
?>
<div class="rto-page-wrap" style="max-width:640px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">My Security</h1>
  </div>

  <div id="tfaMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <?php if (!empty($_GET['rto_2fa_required']) && !$enabled): ?>
  <div class="rto-msg rto-msg-warning rto-mb-4" role="alert">
    Two-factor authentication is now required for RTO Admin accounts before you can use any other RTOFLOW screen.
    Set it up below — it takes under a minute with any authenticator app (Google Authenticator, Authy, Microsoft Authenticator).
  </div>
  <?php endif; ?>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Two-Factor Authentication (2FA)</h3></div>
    <div class="rto-card-body">
      <?php if ($enabled): ?>
      <p class="rto-small" style="color:#16A34A;font-weight:600">✓ Two-factor authentication is enabled on your account.</p>
      <p class="rto-small rto-muted">Unused backup codes remaining: <strong><?= (int)$remainingBackupCodes ?></strong> of 8.</p>
      <div id="disableSection">
        <label class="rto-label" for="disableCode">Enter a current 6-digit code (or a backup code) to disable 2FA</label>
        <div style="display:flex;gap:8px;margin-top:6px">
          <input type="text" id="disableCode" class="rto-input" style="max-width:200px" placeholder="123456" autocomplete="off">
          <button id="disableBtn" class="rto-btn rto-btn-outline" style="color:#DC2626;border-color:#DC2626">Disable 2FA</button>
        </div>
      </div>
      <?php else: ?>
      <p class="rto-small rto-muted">Two-factor authentication adds a second step (a 6-digit code from an authenticator app) when logging in, protecting your account even if your password is ever compromised.</p>
      <button id="setupBtn" class="rto-btn rto-btn-primary">Set Up 2FA</button>

      <div id="setupFlow" style="display:none;margin-top:20px">
        <p class="rto-small">1. Scan this QR code with Google Authenticator, Authy, or any TOTP app:</p>
        <img id="tfaQr" src="" alt="2FA setup QR code" style="border:1px solid #e2e8f0;border-radius:8px;padding:8px">
        <p class="rto-small rto-muted">Can't scan? Enter this key manually: <code id="tfaSecret" style="user-select:all"></code></p>
        <p class="rto-small" style="margin-top:16px">2. Enter the 6-digit code your app now shows:</p>
        <div style="display:flex;gap:8px">
          <input type="text" id="confirmCode" class="rto-input" style="max-width:200px" placeholder="123456" autocomplete="off">
          <button id="confirmBtn" class="rto-btn rto-btn-success">Confirm & Enable</button>
        </div>
      </div>

      <div id="backupCodesSection" style="display:none;margin-top:20px;padding:16px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:8px">
        <p class="rto-small" style="font-weight:700;color:#92400E">⚠ Save these backup codes now — they will not be shown again. Each can be used once if you lose access to your authenticator app.</p>
        <div id="backupCodesList" style="font-family:monospace;font-size:14px;line-height:1.8;margin-top:8px"></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var nonce = '<?= esc_js($nonce) ?>';
var ajaxUrl = '<?= esc_js(admin_url('admin-ajax.php')) ?>';
var msg = document.getElementById('tfaMsg');

function showMsg(text, isError) {
  msg.className = 'rto-msg rto-msg-' + (isError ? 'error' : 'success');
  msg.textContent = text;
  msg.style.display = 'block';
}

function post(action, extra) {
  var fd = new FormData();
  fd.append('action', 'rto_admin'); fd.append('rto_area', 'admin');
  fd.append('rto_action', action); fd.append('rto_nonce', nonce);
  for (var k in extra) fd.append(k, extra[k]);
  return fetch(ajaxUrl, {method: 'POST', credentials: 'same-origin', body: fd}).then(function(r) { return r.json(); });
}

var setupBtn = document.getElementById('setupBtn');
if (setupBtn) {
  setupBtn.addEventListener('click', function() {
    setupBtn.disabled = true;
    post('2fa_generate', {}).then(function(r) {
      setupBtn.disabled = false;
      if (!r.success) { showMsg(r.data ? r.data.message : 'Failed to start setup.', true); return; }
      document.getElementById('tfaQr').src = r.data.qr_url;
      document.getElementById('tfaSecret').textContent = r.data.secret;
      document.getElementById('setupFlow').style.display = 'block';
      setupBtn.style.display = 'none';
    }).catch(function() { setupBtn.disabled = false; showMsg('Request failed.', true); });
  });
}

var confirmBtn = document.getElementById('confirmBtn');
if (confirmBtn) {
  confirmBtn.addEventListener('click', function() {
    var code = document.getElementById('confirmCode').value.trim();
    if (!/^\d{6}$/.test(code)) { showMsg('Enter the 6-digit code from your authenticator app.', true); return; }
    confirmBtn.disabled = true;
    post('2fa_confirm', {code: code}).then(function(r) {
      confirmBtn.disabled = false;
      if (!r.success) { showMsg(r.data ? r.data.message : 'Incorrect code.', true); return; }
      showMsg(r.data.message, false);
      document.getElementById('setupFlow').style.display = 'none';
      var list = document.getElementById('backupCodesList');
      list.innerHTML = '';
      (r.data.backup_codes || []).forEach(function(c) {
        var div = document.createElement('div');
        div.textContent = c;
        list.appendChild(div);
      });
      document.getElementById('backupCodesSection').style.display = 'block';
    }).catch(function() { confirmBtn.disabled = false; showMsg('Request failed.', true); });
  });
}

var disableBtn = document.getElementById('disableBtn');
if (disableBtn) {
  disableBtn.addEventListener('click', function() {
    var code = document.getElementById('disableCode').value.trim();
    if (!code) { showMsg('Enter a code to confirm.', true); return; }
    if (!confirm('Disable two-factor authentication on your account?')) return;
    disableBtn.disabled = true;
    post('2fa_disable', {code: code}).then(function(r) {
      disableBtn.disabled = false;
      if (!r.success) { showMsg(r.data ? r.data.message : 'Failed.', true); return; }
      showMsg(r.data.message, false);
      setTimeout(function() { location.reload(); }, 1200);
    }).catch(function() { disableBtn.disabled = false; showMsg('Request failed.', true); });
  });
}
</script>

<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
