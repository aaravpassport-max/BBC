<?php if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }
$user  = wp_get_current_user();
$saved = false; $errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rtoflow_nonce']) && wp_verify_nonce($_POST['rtoflow_nonce'],'rtoflow_client_profile')) {
    $display = sanitize_text_field($_POST['display_name'] ?? '');
    $mobile  = preg_replace('/\D/','',$_POST['mobile']??''  );
    if (!preg_match('/^[6-9]\d{9}$/',$mobile)) $errors['mobile']= 'Enter a valid 10-digit Indian mobile number.';
    if (!$errors) {
        wp_update_user(['ID'=>$user->ID,'display_name'=>$display]);
        update_user_meta($user->ID,'rtoflow_mobile',$mobile);
        $saved = true;
        $user  = wp_get_current_user(); // refresh
    }
}
$mobile = get_user_meta($user->ID,'rtoflow_mobile',true);

// ENTERPRISE GAP FIX (Phase 4, item 6 — "no notification preference
// controls anywhere"): plain-POST, same pattern as the profile form above
// (this page has no AJAX nonce object set up elsewhere), backed by
// NotificationPreferenceService — see its docblock for scope (channel
// on/off only; quiet hours are saved but not yet enforced by the queue).
$prefsSaved = false;
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rtoflow_prefs_nonce']) && wp_verify_nonce($_POST['rtoflow_prefs_nonce'],'rtoflow_client_prefs')) {
    \RTOFLOW\Services\NotificationPreferenceService::save($user->ID, [
        'email_enabled'    => !empty($_POST['email_enabled']),
        'sms_enabled'      => !empty($_POST['sms_enabled']),
        'whatsapp_enabled' => !empty($_POST['whatsapp_enabled']),
    ]);
    $prefsSaved = true;
}
$notifPrefs = \RTOFLOW\Services\NotificationPreferenceService::get($user->ID);

require RTOFLOW_DIR . 'resources/views/layouts/client-header.php';
?>
<div class="rto-page-wrap" style="max-width:520px">
  <h1 class="rto-page-title">My Profile</h1>

  <?php if ($saved): ?><div class="rto-msg rto-msg-success rto-mb-4">Profile updated.</div><?php endif; ?>
  <?php if (!empty($errors)): ?>
  <div class="rto-msg rto-msg-error rto-mb-4"><?= esc_html(implode(' ', $errors)) ?></div>
  <?php endif; ?>

  <div class="rto-card">
    <div class="rto-card-body">
      <form method="POST">
        <?php wp_nonce_field('rtoflow_client_profile','rtoflow_nonce'); ?>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label">Email</label>
          <input type="email" class="rto-input" value="<?= esc_attr($user->user_email) ?>" disabled aria-disabled="true">
          <div class="rto-small rto-muted" style="margin-top:4px">Email cannot be changed here.</div>
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="pName">Display Name</label>
          <input type="text" id="pName" name="display_name" class="rto-input" value="<?= esc_attr($user->display_name) ?>">
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="pMobile">Mobile Number</label>
          <input type="tel" id="pMobile" name="mobile" class="rto-input <?= isset($errors['mobile'])?'rto-error':'' ?>"
                 value="<?= esc_attr($mobile) ?>" placeholder="10-digit mobile">
        </div>
        <button type="submit" class="rto-btn rto-btn-primary">Save Profile</button>
      </form>
    </div>
  </div>

  <div class="rto-card rto-mt-4">
    <div class="rto-card-header"><h3>Notification Preferences</h3></div>
    <div class="rto-card-body">
      <?php if ($prefsSaved): ?><div class="rto-msg rto-msg-success rto-mb-4">Notification preferences saved.</div><?php endif; ?>
      <form method="POST">
        <?php wp_nonce_field('rtoflow_client_prefs','rtoflow_prefs_nonce'); ?>
        <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="npEmail" name="email_enabled" value="1" <?= $notifPrefs['email_enabled']?'checked':'' ?>>
          <label for="npEmail" style="margin:0">Email notifications</label>
        </div>
        <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="npSms" name="sms_enabled" value="1" <?= $notifPrefs['sms_enabled']?'checked':'' ?>>
          <label for="npSms" style="margin:0">SMS notifications</label>
        </div>
        <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" id="npWhatsapp" name="whatsapp_enabled" value="1" <?= $notifPrefs['whatsapp_enabled']?'checked':'' ?>>
          <label for="npWhatsapp" style="margin:0">WhatsApp notifications</label>
        </div>
        <button type="submit" class="rto-btn rto-btn-outline">Save Preferences</button>
      </form>
    </div>
  </div>

  <?php
  // ENTERPRISE GAP FIX (Phase 5, item 2 — "client referral/loyalty
  // program"): referral link + wallet balance/history. See ReferralService
  // for the crediting rule (both sides credited on the referred client's
  // first paid order) and Bootstrap::init() for how ?ref= is captured.
  $refUrl     = \RTOFLOW\Services\ReferralService::referralUrl($user->ID);
  $refCount   = \RTOFLOW\Services\ReferralService::referralCount($user->ID);
  $walletBal  = \RTOFLOW\Services\ReferralService::balance($user->ID);
  $walletTxns = \RTOFLOW\Services\ReferralService::history($user->ID, 10);
  ?>
  <div class="rto-card rto-mt-4">
    <div class="rto-card-header"><h3>Refer &amp; Earn</h3></div>
    <div class="rto-card-body">
      <p class="rto-small rto-muted" style="margin:0 0 10px">Share your link — when a friend signs up and completes their first paid order, you both get a wallet credit.</p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
        <input type="text" readonly value="<?= esc_attr($refUrl) ?>" class="rto-input" id="refLinkInput" style="flex:1;min-width:200px">
        <button type="button" class="rto-btn rto-btn-outline rto-btn-xs" id="refLinkCopy">Copy</button>
      </div>
      <div class="rto-small rto-muted">Friends referred: <strong><?= (int)$refCount ?></strong></div>
    </div>
  </div>

  <div class="rto-card rto-mt-4">
    <div class="rto-card-header"><h3>Wallet</h3></div>
    <div class="rto-card-body">
      <div style="font-size:22px;font-weight:700;margin-bottom:10px">₹<?= number_format($walletBal, 2) ?></div>
      <?php if (empty($walletTxns)): ?>
      <div class="rto-empty-small">No wallet activity yet.</div>
      <?php else: ?>
      <div class="rto-table-scroll">
        <table class="rto-table" data-rto-responsive="cards">
          <thead><tr><th>Date</th><th>Reason</th><th>Amount</th></tr></thead>
          <tbody>
          <?php foreach ($walletTxns as $t): ?>
          <tr>
            <td data-label="Date"><?= esc_html(date('d M Y', strtotime($t['created_at']))) ?></td>
            <td data-label="Reason"><?= esc_html($t['reason']) ?></td>
            <td data-label="Amount" style="color:<?= $t['type']==='credit'?'#16a34a':'#dc2626' ?>">
              <?= $t['type']==='credit'?'+':'-' ?>₹<?= number_format((float)$t['amount'], 2) ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
(function(){
  var btn = document.getElementById('refLinkCopy');
  var input = document.getElementById('refLinkInput');
  if (!btn || !input) return;
  btn.addEventListener('click', function(){
    input.select();
    input.setSelectionRange(0, 99999);
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value);
      } else {
        document.execCommand('copy');
      }
      var orig = btn.textContent;
      btn.textContent = 'Copied!';
      setTimeout(function(){ btn.textContent = orig; }, 1500);
    } catch (e) { /* clipboard unavailable — link remains selected for manual copy */ }
  });
})();
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/client-footer.php'; ?>
