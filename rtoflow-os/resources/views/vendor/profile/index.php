<?php if (!defined('ABSPATH')) exit;
global $wpdb;
$p      = $wpdb->prefix;
$userId = get_current_user_id();
$vendor = $wpdb->get_row($wpdb->prepare(
    "SELECT v.*, u.user_email FROM {$p}rto_vendors v JOIN {$p}users u ON u.ID=v.user_id WHERE v.user_id=%d", $userId
), ARRAY_A);
if (!$vendor) wp_die('Vendor profile not found.','Not Found',['response'=>404]);

$saved = false;
$errors = [];
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['rtoflow_nonce']) && wp_verify_nonce($_POST['rtoflow_nonce'],'rtoflow_vendor_profile')) {
    $mobile  = sanitize_text_field($_POST['mobile']   ?? '');
    $address = sanitize_textarea_field($_POST['address'] ?? '');
    $bank    = sanitize_text_field($_POST['bank_name']    ?? '');
    $acc     = sanitize_text_field($_POST['bank_account'] ?? '');
    $ifsc    = strtoupper(sanitize_text_field($_POST['bank_ifsc'] ?? ''));
    if (!preg_match('/^\d{10}$/', $mobile)) $errors['mobile'] = 'Enter a valid 10-digit mobile number.';
    if (!$errors) {
        $wpdb->update($p.'rto_vendors',['mobile'=>$mobile,'address'=>$address,'bank_name'=>$bank,'bank_account'=>$acc,'bank_ifsc'=>$ifsc,'updated_at'=>current_time('mysql')],['id'=>(int)$vendor['id']]);
        $saved = true;
        $vendor = array_merge($vendor,compact('mobile','address')+['bank_name'=>$bank,'bank_account'=>$acc,'bank_ifsc'=>$ifsc]);
    }
}

require RTOFLOW_DIR . 'resources/views/layouts/vendor-header.php';
?>
<div class="rto-page-wrap" style="max-width:600px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">My Profile</h1>
    <span class="rto-badge rto-badge-secondary"><?= esc_html($vendor['vendor_number']) ?></span>
  </div>

  <?php if ($saved): ?><div class="rto-msg rto-msg-success rto-mb-4">Profile updated successfully.</div><?php endif; ?>
  <?php if ($errors): ?>
  <div class="rto-msg rto-msg-error rto-mb-4"><?php foreach($errors as $e): ?><?= esc_html($e) ?><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($vendor['status'] === 'suspended'): ?>
  <!-- ENTERPRISE GAP FIX (Phase 4, item 5 — suspension appeal): mirrors the
       per-rating "Request Review" button below, same VendorAppealService
       backing both. -->
  <div class="rto-msg rto-msg-error rto-mb-4" id="suspensionNotice">
    <strong>Your account is suspended.</strong>
    <?php if (!empty($vendor['suspend_reason'])): ?><p style="margin:6px 0"><?= esc_html($vendor['suspend_reason']) ?></p><?php endif; ?>
    <?php
    $pendingSuspensionAppeal = (bool)$wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$p}rto_vendor_appeals WHERE vendor_id=%d AND subject_type='suspension' AND status='pending'", (int)$vendor['id']
    ));
    ?>
    <?php if ($pendingSuspensionAppeal): ?>
    <p class="rto-small" style="margin:6px 0 0">Your appeal is under review.</p>
    <?php else: ?>
    <button type="button" class="rto-btn rto-btn-sm rto-btn-outline appeal-btn" data-subject-type="suspension" data-subject-id="0" style="margin-top:6px">Request Review</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Account Info</h3></div>
    <div class="rto-card-body">
      <div class="rto-detail-grid">
        <?php foreach ([['Name',$vendor['full_name']],['Email',$vendor['user_email']],['KYC',$vendor['kyc_status']],['Rating',number_format((float)$vendor['rating'],1).' ⭐']] as [$l,$v]): ?>
        <div class="rto-detail-item"><span><?= esc_html($l) ?></span><strong><?= esc_html($v) ?></strong></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php
  // ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow):
  // vendors previously had no way to upload any actual KYC document —
  // uploadKycDocument() existed server-side with nowhere to call it from.
  $kycDocs = $wpdb->get_results($wpdb->prepare(
      "SELECT * FROM {$p}rto_vendor_documents WHERE vendor_id=%d ORDER BY created_at DESC", (int)$vendor['id']
  ), ARRAY_A) ?: [];
  $kycStatusColors = ['pending'=>'secondary','verified'=>'success','rejected'=>'danger'];
  ?>
  <div class="rto-card rto-mb-4" id="kycCard">
    <div class="rto-card-header"><h3>KYC Documents</h3></div>
    <div class="rto-card-body">
      <?php if (empty($kycDocs)): ?>
      <p class="rto-small rto-muted">No documents uploaded yet.</p>
      <?php else: ?>
      <div class="rto-table-scroll rto-mb-4">
        <table class="rto-table" data-rto-responsive="cards">
          <thead><tr><th>Document</th><th>Status</th><th>Uploaded</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($kycDocs as $d): ?>
          <tr>
            <td data-label="Document"><?= esc_html($d['doc_label']) ?></td>
            <td data-label="Status">
              <span class="rto-badge rto-badge-<?= esc_attr($kycStatusColors[$d['status']] ?? 'secondary') ?>"><?= esc_html(ucfirst($d['status'])) ?></span>
              <?php if ($d['status'] === 'rejected' && $d['reject_reason']): ?>
              <div class="rto-small rto-danger" style="margin-top:3px"><?= esc_html($d['reject_reason']) ?></div>
              <?php endif; ?>
            </td>
            <td data-label="Uploaded" class="rto-small rto-muted"><?= esc_html(rto_date($d['created_at'])) ?></td>
            <td data-label=""><a href="<?= esc_url(home_url('/rto-vendor/kyc-doc/' . (int)$d['id'] . '/')) ?>" class="rto-btn rto-btn-xs rto-btn-outline" target="_blank">View</a></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <div id="kycUploadMsg"></div>
      <div class="rto-form-row">
        <div class="rto-form-group">
          <label class="rto-label" for="kycDocLabel">Document Type</label>
          <select id="kycDocLabel" class="rto-input">
            <option value="PAN Card">PAN Card</option>
            <option value="Aadhaar Card">Aadhaar Card</option>
            <option value="Bank Proof">Bank Proof</option>
            <option value="Business Registration">Business Registration</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="kycDocFile">File (PDF/JPG/PNG, max 5MB)</label>
          <input type="file" id="kycDocFile" class="rto-input" accept=".pdf,.jpg,.jpeg,.png">
        </div>
      </div>
      <button type="button" id="kycUploadBtn" class="rto-btn rto-btn-primary">Upload Document</button>
    </div>
  </div>
  <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
  document.getElementById('kycUploadBtn').addEventListener('click', function(){
    var file = document.getElementById('kycDocFile').files[0];
    var label = document.getElementById('kycDocLabel').value;
    var msgEl = document.getElementById('kycUploadMsg');
    if (!file) { msgEl.innerHTML = '<div class="rto-msg rto-msg-error rto-mb-4">Please choose a file.</div>'; return; }
    var fd = new FormData();
    fd.append('action', 'rto_vendor');
    fd.append('rto_area', 'vendor');
    fd.append('rto_action', 'upload_kyc_doc');
    fd.append('doc_label', label);
    fd.append('file', file);
    fd.append('rto_nonce', rtoflowVendor.nonce);
    var btn = document.getElementById('kycUploadBtn');
    btn.disabled = true; btn.textContent = 'Uploading…';
    fetch(rtoflowVendor.ajax_url, { method:'POST', credentials:'same-origin', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (r.success) { location.reload(); }
        else {
          msgEl.innerHTML = '<div class="rto-msg rto-msg-error rto-mb-4">' + (r.data && r.data.message ? r.data.message : 'Upload failed.') + '</div>';
          btn.disabled = false; btn.textContent = 'Upload Document';
        }
      })
      .catch(function(){
        msgEl.innerHTML = '<div class="rto-msg rto-msg-error rto-mb-4">Upload failed. Please try again.</div>';
        btn.disabled = false; btn.textContent = 'Upload Document';
      });
  });
  </script>

  <!-- ENTERPRISE GAP FIX (Phase 4, item 5 — "no rating or suspension appeal
       workflow for vendors"): ratings were entirely admin/client-driven with
       no vendor-facing list at all, let alone a dispute path. -->
  <?php
  $myRatings = $wpdb->get_results($wpdb->prepare(
      "SELECT r.*, l.lead_number FROM {$p}rto_ratings r JOIN {$p}rto_leads l ON l.id=r.lead_id
       WHERE r.vendor_id=%d ORDER BY r.created_at DESC LIMIT 20", (int)$vendor['id']
  ), ARRAY_A) ?: [];
  $pendingRatingAppealIds = $myRatings ? array_flip($wpdb->get_col($wpdb->prepare(
      "SELECT subject_id FROM {$p}rto_vendor_appeals WHERE vendor_id=%d AND subject_type='rating' AND status='pending'", (int)$vendor['id']
  ))) : [];
  ?>
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>My Ratings</h3></div>
    <div class="rto-card-body">
      <?php if (empty($myRatings)): ?>
      <p class="rto-small rto-muted">No ratings yet.</p>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php foreach ($myRatings as $r): ?>
        <div style="padding:10px;background:var(--gray-50);border-radius:6px">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
            <div>
              <div><?= str_repeat('⭐', (int)$r['score']) ?> <span class="rto-small rto-muted"><?= esc_html($r['lead_number']) ?> — <?= esc_html(rto_date($r['created_at'])) ?></span></div>
              <?php if ($r['review']): ?><p class="rto-small" style="margin:4px 0 0"><?= esc_html($r['review']) ?></p><?php endif; ?>
            </div>
            <?php if (isset($pendingRatingAppealIds[$r['id']])): ?>
            <span class="rto-badge rto-badge-secondary" style="white-space:nowrap">Appeal Pending</span>
            <?php else: ?>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-outline appeal-btn" data-subject-type="rating" data-subject-id="<?= (int)$r['id'] ?>" style="white-space:nowrap">Request Review</button>
            <?php endif; ?>
          </div>
          <?php
          // ENTERPRISE GAP FIX (Phase 5, item 5 — "rating response feature
          // for vendors"): a short public reply, mirroring common
          // marketplace UX. See migration 2024_01_01_000040 for the schema.
          ?>
          <?php if (!empty($r['vendor_response'])): ?>
          <div class="rto-small" style="margin-top:8px;padding:8px;background:#fff;border-left:3px solid var(--rto-primary,#E97B28);border-radius:4px">
            <strong>Your reply:</strong> <?= esc_html($r['vendor_response']) ?>
          </div>
          <?php else: ?>
          <button type="button" class="rto-btn rto-btn-xs rto-btn-outline rating-reply-btn" data-rating-id="<?= (int)$r['id'] ?>" style="margin-top:8px">Reply to this rating</button>
          <div class="rating-reply-form" id="ratingReplyForm-<?= (int)$r['id'] ?>" style="display:none;margin-top:8px">
            <textarea class="rto-input rating-reply-text" rows="2" maxlength="500" placeholder="Write a short public reply…"></textarea>
            <button type="button" class="rto-btn rto-btn-xs rto-btn-primary rating-reply-save" data-rating-id="<?= (int)$r['id'] ?>" style="margin-top:6px">Post Reply</button>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Appeal modal, shared by the suspension notice and every rating row above -->
  <div id="appealModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:8px;padding:24px;max-width:420px;width:90%">
      <h3 style="margin:0 0 12px">Request Review</h3>
      <p class="rto-small rto-muted" style="margin:0 0 12px">Explain why you believe this should be reconsidered. An admin will review and respond.</p>
      <textarea id="appealReason" class="rto-input" rows="4" placeholder="Your explanation…"></textarea>
      <div id="appealMsg" style="margin-top:8px"></div>
      <div style="display:flex;gap:10px;margin-top:14px">
        <button type="button" id="appealSubmitBtn" class="rto-btn rto-btn-primary">Submit Appeal</button>
        <button type="button" id="appealCancelBtn" class="rto-btn rto-btn-outline">Cancel</button>
      </div>
    </div>
  </div>
  <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
  (function() {
    var modal = document.getElementById('appealModal');
    var reasonEl = document.getElementById('appealReason');
    var msgEl = document.getElementById('appealMsg');
    var submitBtn = document.getElementById('appealSubmitBtn');
    var activeType = null, activeId = null;

    document.querySelectorAll('.appeal-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        activeType = btn.dataset.subjectType; activeId = btn.dataset.subjectId;
        reasonEl.value = ''; msgEl.innerHTML = '';
        modal.style.display = 'flex';
      });
    });
    document.getElementById('appealCancelBtn').addEventListener('click', function() { modal.style.display = 'none'; });

    submitBtn.addEventListener('click', function() {
      var reason = reasonEl.value.trim();
      if (!reason) { msgEl.innerHTML = '<div class="rto-msg rto-msg-error">Please explain your appeal.</div>'; return; }
      var fd = new FormData();
      fd.append('action', 'rto_vendor'); fd.append('rto_area', 'vendor');
      fd.append('rto_action', 'submit_appeal');
      fd.append('subject_type', activeType); fd.append('subject_id', activeId);
      fd.append('reason', reason);
      fd.append('rto_nonce', rtoflowVendor.nonce);
      submitBtn.disabled = true;
      fetch(rtoflowVendor.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(r) {
          submitBtn.disabled = false;
          if (r.success) { modal.style.display = 'none'; location.reload(); }
          else { msgEl.innerHTML = '<div class="rto-msg rto-msg-error">' + (r.message || 'Failed.') + '</div>'; }
        })
        .catch(function() { submitBtn.disabled = false; msgEl.innerHTML = '<div class="rto-msg rto-msg-error">Request failed.</div>'; });
    });
  })();
  </script>

  <!-- ENTERPRISE GAP FIX (Phase 5, item 5 — "rating response feature for
       vendors"): wires the reply form added above each rating row. -->
  <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
  document.querySelectorAll('.rating-reply-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var form = document.getElementById('ratingReplyForm-' + btn.dataset.ratingId);
      if (form) { form.style.display = 'block'; btn.style.display = 'none'; }
    });
  });
  document.querySelectorAll('.rating-reply-save').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var ratingId = btn.dataset.ratingId;
      var form = document.getElementById('ratingReplyForm-' + ratingId);
      var textEl = form.querySelector('.rating-reply-text');
      var text = textEl.value.trim();
      if (!text) return;
      var fd = new FormData();
      fd.append('action', 'rto_vendor'); fd.append('rto_area', 'vendor');
      fd.append('rto_action', 'respond_to_rating');
      fd.append('rating_id', ratingId);
      fd.append('response', text);
      fd.append('rto_nonce', rtoflowVendor.nonce);
      btn.disabled = true;
      fetch(rtoflowVendor.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(r) {
          btn.disabled = false;
          if (r.success) { location.reload(); }
          else { alert(r.message || 'Could not post reply.'); }
        })
        .catch(function() { btn.disabled = false; alert('Request failed.'); });
    });
  });
  </script>

  <!-- ENTERPRISE GAP FIX (Phase 4, item 6 — "no notification preference
       controls anywhere"): backed by NotificationPreferenceService — see its
       docblock for scope (channel on/off only; quiet hours saved but not
       yet enforced by the queue). -->
  <?php $notifPrefs = \RTOFLOW\Services\NotificationPreferenceService::get($userId); ?>
  <div class="rto-card rto-mb-4">
    <div class="rto-card-header"><h3>Notification Preferences</h3></div>
    <div class="rto-card-body">
      <div id="prefsMsg"></div>
      <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="npEmail" <?= $notifPrefs['email_enabled']?'checked':'' ?>>
        <label for="npEmail" style="margin:0">Email notifications</label>
      </div>
      <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="npSms" <?= $notifPrefs['sms_enabled']?'checked':'' ?>>
        <label for="npSms" style="margin:0">SMS notifications</label>
      </div>
      <div class="rto-form-group rto-mb-4" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="npWhatsapp" <?= $notifPrefs['whatsapp_enabled']?'checked':'' ?>>
        <label for="npWhatsapp" style="margin:0">WhatsApp notifications</label>
      </div>
      <button type="button" id="npSaveBtn" class="rto-btn rto-btn-outline">Save Preferences</button>
    </div>
  </div>
  <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
  document.getElementById('npSaveBtn').addEventListener('click', function() {
    var btn = this, fd = new FormData();
    fd.append('action','rto_vendor'); fd.append('rto_area','vendor');
    fd.append('rto_action','save_notification_prefs');
    fd.append('email_enabled', document.getElementById('npEmail').checked ? '1' : '');
    fd.append('sms_enabled', document.getElementById('npSms').checked ? '1' : '');
    fd.append('whatsapp_enabled', document.getElementById('npWhatsapp').checked ? '1' : '');
    fd.append('rto_nonce', rtoflowVendor.nonce);
    btn.disabled = true;
    fetch(rtoflowVendor.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        btn.disabled = false;
        var m = document.getElementById('prefsMsg');
        m.innerHTML = '<div class="rto-msg rto-msg-' + (r.success?'success':'error') + ' rto-mb-4">' + (r.message || (r.success?'Saved.':'Failed.')) + '</div>';
      });
  });
  </script>

  <form method="POST">
    <?php wp_nonce_field('rtoflow_vendor_profile','rtoflow_nonce'); ?>
    <div class="rto-card rto-mb-4">
      <div class="rto-card-header"><h3>Contact Details</h3></div>
      <div class="rto-card-body" style="display:grid;gap:16px">
        <div class="rto-form-group">
          <label class="rto-label" for="vMob">Mobile <abbr title="Required">*</abbr></label>
          <input type="tel" id="vMob" name="mobile" class="rto-input <?= isset($errors['mobile'])?'rto-error':'' ?>"
                 value="<?= esc_attr($vendor['mobile']) ?>" placeholder="10-digit mobile">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vAddr">Address</label>
          <textarea id="vAddr" name="address" class="rto-input" rows="2"><?= esc_textarea($vendor['address']??'') ?></textarea>
        </div>
      </div>
    </div>
    <div class="rto-card rto-mb-4">
      <div class="rto-card-header"><h3>Bank Details <span class="rto-small rto-muted">(for payouts)</span></h3></div>
      <div class="rto-card-body" style="display:grid;gap:16px">
        <div class="rto-form-group">
          <label class="rto-label" for="vBk">Bank Name</label>
          <input type="text" id="vBk" name="bank_name" class="rto-input" value="<?= esc_attr($vendor['bank_name']??'') ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vAcc">Account Number</label>
          <input type="text" id="vAcc" name="bank_account" class="rto-input" value="<?= esc_attr($vendor['bank_account']??'') ?>">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="vIf">IFSC Code</label>
          <input type="text" id="vIf" name="bank_ifsc" class="rto-input" value="<?= esc_attr($vendor['bank_ifsc']??'') ?>" style="text-transform:uppercase">
        </div>
      </div>
    </div>
    <button type="submit" class="rto-btn rto-btn-primary">Save Changes</button>
  </form>
</div>
<?php require RTOFLOW_DIR . 'resources/views/layouts/vendor-footer.php'; ?>
