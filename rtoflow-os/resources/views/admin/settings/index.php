<?php if (!defined('ABSPATH')) exit;
/** @var string $tab @var bool $saved @var array $settings
 * @var bool $submittedForApproval @var array $pendingForTab
 * ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval workflow):
 * $submittedForApproval/$pendingForTab back the banners below for the
 * Payment/SMS/WhatsApp tabs — see SettingsController::REQUIRES_APPROVAL_TABS
 * and ConfigApprovalService. */
$pageTitle = 'Settings';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$action = home_url('/rto-admin/settings/');
$tabs   = ['company'=>'Company','payment'=>'Payment Gateway','sms'=>'SMS','whatsapp'=>'WhatsApp','notifications'=>'Notifications','matching'=>'Matching','ai_summary'=>'🤖 AI Lead Briefing','colors'=>'🎨 Colors','privacy'=>'🔒 Data Retention'];
// FIX (Known Limitations audit): tabs holding live secrets or legal/tax
// identity fields (Company/Payment/SMS/WhatsApp/AI) are now admin-only —
// see SettingsController::ADMIN_ONLY_TABS. Hide them from an rto_staff
// account's nav so staff aren't shown a tab that 403s the moment it's clicked.
if (!rto_is_admin()) {
    $tabs = array_intersect_key($tabs, array_flip(['notifications', 'matching', 'colors']));
}
$s = fn(string $k, string $d='') => esc_attr($settings[$k] ?? $d);
?>
<div class="rto-page-wrap" style="max-width:720px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Settings</h1>
  </div>

  <?php if ($saved): ?>
  <div class="rto-msg rto-msg-success rto-mb-4" role="status">Settings saved successfully.</div>
  <?php endif; ?>

  <?php if ($submittedForApproval): ?>
  <div class="rto-msg rto-msg-info rto-mb-4" role="status">
    Submitted for approval. This tab's changes require a different RTO Admin to review and approve them before
    they take effect — see <a href="<?= esc_url(home_url('/rto-admin/config-approvals/')) ?>">Config Approvals</a>.
    Nothing has changed yet.
  </div>
  <?php endif; ?>

  <?php if (!empty($pendingForTab)): ?>
  <div class="rto-msg rto-msg-warning rto-mb-4" role="status">
    <?= count($pendingForTab) ?> change<?= count($pendingForTab) > 1 ? 's' : '' ?> for this tab
    <?= count($pendingForTab) > 1 ? 'are' : 'is' ?> awaiting a second admin's approval and not yet live —
    see <a href="<?= esc_url(home_url('/rto-admin/config-approvals/')) ?>">Config Approvals</a>. The values shown
    below are still the currently LIVE settings, not the pending change.
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="rto-tabs rto-mb-4">
    <?php foreach ($tabs as $t=>$l): ?>
    <a href="<?= esc_url(add_query_arg(['rto_area'=>'admin','rto_page'=>'settings','tab'=>$t],home_url('/rto-admin/settings/'))) ?>"
       class="rto-tab <?= $tab===$t?'active':'' ?>"><?= esc_html($l) ?></a>
    <?php endforeach; ?>
  </div>

  <form method="POST" action="<?= esc_url($action) ?>">
    <?php wp_nonce_field('rtoflow_settings_save','rtoflow_nonce'); ?>
    <input type="hidden" name="tab" value="<?= esc_attr($tab) ?>">
    <input type="hidden" name="rto_area" value="admin">
    <input type="hidden" name="rto_page" value="settings">

    <div class="rto-card">
      <div class="rto-card-body" style="display:grid;gap:16px">

      <?php if ($tab==='company'): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <?php foreach ([
            ['company_name','Company Name','text',true,'e.g. RTO Assist Pvt. Ltd.'],
            ['company_gstin','GSTIN','text',false,'15-char GST Identification Number'],
            ['company_tan','TAN','text',false,'Tax Deduction Account Number'],
            ['company_phone','Phone','tel',false,'Support contact number'],
            ['company_email','Email','email',false,'company@example.com'],
            ['company_state','State Code','text',false,'e.g. MH, DL, KA'],
          ] as [$k,$l,$t,$req,$ph]): ?>
          <div class="rto-form-group">
            <label class="rto-label" for="s_<?= esc_attr($k) ?>"><?= esc_html($l) ?><?= $req?' <abbr title="Required">*</abbr>':''  ?></label>
            <input type="<?= esc_attr($t) ?>" id="s_<?= esc_attr($k) ?>" name="<?= esc_attr($k) ?>"
                   class="rto-input" value="<?= $s($k) ?>" placeholder="<?= esc_attr($ph) ?>" <?= $req?'required':'' ?>>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="rto-form-group" style="grid-column:1/-1">
          <label class="rto-label" for="s_company_address">Company Address</label>
          <textarea id="s_company_address" name="company_address" class="rto-input" rows="2"><?= esc_textarea($settings['company_address']??'') ?></textarea>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="rto-form-group">
            <label class="rto-label" for="s_admin_user_id">Admin User ID</label>
            <input type="number" id="s_admin_user_id" name="admin_user_id" class="rto-input" value="<?= $s('admin_user_id','0') ?>">
            <div class="rto-small rto-muted" style="margin-top:4px">WordPress user ID for SLA alerts.</div>
          </div>
          <div class="rto-form-group">
            <label class="rto-label" for="s_sla_days">Default SLA (days)<?= rto_field_tooltip('Number of days from lead creation before an order is considered overdue and triggers SLA breach alerts/escalation.') ?></label>
            <input type="number" id="s_sla_days" name="sla_days" class="rto-input" value="<?= $s('sla_days','7') ?>" min="1">
          </div>
          <div class="rto-form-group">
            <label class="rto-label" for="s_inactivity_days">Inactivity Alert (days)<?= rto_field_tooltip('An admin is alerted when an order sits in an actionable status (e.g. Assigned, Payment Received) without any status change for this many days. This was previously read from the database with no way to actually set it from this screen — it always silently used the hardcoded default of 3 days.') ?></label>
            <input type="number" id="s_inactivity_days" name="inactivity_days" class="rto-input" value="<?= $s('inactivity_days','3') ?>" min="1">
          </div>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_company_logo_url">Logo URL</label>
          <input type="url" id="s_company_logo_url" name="company_logo_url" class="rto-input" value="<?= $s('company_logo_url') ?>" placeholder="https://…">
        </div>
        <div class="rto-form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="override_front_page" value="1" <?= ($s('override_front_page','1') !== '0') ? 'checked' : '' ?>>
            <span>Use the RTOFLOW home page as this site's front page<?= rto_field_tooltip('When checked (the default), RTOFLOW\'s own home page takes over the site\'s front page even if WordPress Settings → Reading has a different static front page selected. Uncheck this to let WordPress\'s own front-page setting win instead. This checkbox previously had nothing to control — the underlying option existed in code but no admin screen could ever change it from its default.') ?></span>
          </label>
        </div>

      <?php elseif ($tab==='payment'): ?>
        <div class="rto-msg rto-msg-info" role="note">
          Get your API keys from the <a href="https://dashboard.razorpay.com" target="_blank" rel="noopener">Razorpay Dashboard</a>.
          Use Test mode keys during development.
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_razorpay_mode">Mode</label>
          <select id="s_razorpay_mode" name="razorpay_mode" class="rto-select">
            <option value="test" <?= ($settings['razorpay_mode']??'test')==='test'?'selected':'' ?>>Test (Sandbox)</option>
            <option value="live" <?= ($settings['razorpay_mode']??'test')==='live'?'selected':'' ?>>Live (Production)</option>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_razorpay_key_id">Razorpay Key ID</label>
          <input type="text" id="s_razorpay_key_id" name="razorpay_key_id" class="rto-input" value="<?= $s('razorpay_key_id') ?>" placeholder="rzp_test_…">
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_razorpay_key_secret">Razorpay Key Secret</label>
          <input type="password" id="s_razorpay_key_secret" name="razorpay_key_secret" class="rto-input"
                 value="" placeholder="<?= !empty($settings['razorpay_key_secret_configured']) ? '•••••••• (configured — leave blank to keep)' : 'Not configured' ?>">
          <div class="rto-small rto-muted" style="margin-top:4px">Stored encrypted. Leave blank to keep current value.</div>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_razorpay_webhook_secret">Razorpay Webhook Secret</label>
          <input type="password" id="s_razorpay_webhook_secret" name="razorpay_webhook_secret" class="rto-input"
                 value="" placeholder="<?= !empty($settings['razorpay_webhook_secret_configured']) ? '•••••••• (configured — leave blank to keep)' : 'Not configured' ?>">
          <div class="rto-small rto-muted" style="margin-top:4px">
            A separate secret from the Key Secret above — generated when you set up a webhook in the
            <a href="https://dashboard.razorpay.com/app/webhooks" target="_blank" rel="noopener">Razorpay Dashboard → Webhooks</a>.
            Stored encrypted. Leave blank to keep current value.
          </div>
        </div>

      <?php elseif ($tab==='sms'): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="sms_enabled" value="1" <?= ($settings['sms_enabled']??'0')==='1'?'checked':'' ?>>
          <strong>Enable SMS Notifications</strong>
        </label>
        <div class="rto-form-group">
          <label class="rto-label" for="s_sms_provider">SMS Provider</label>
          <select id="s_sms_provider" name="sms_provider" class="rto-select">
            <option value="">— None —</option>
            <?php foreach (['msg91'=>'MSG91','fast2sms'=>'Fast2SMS','textlocal'=>'TextLocal','twilio'=>'Twilio'] as $k=>$l): ?>
            <option value="<?= esc_attr($k) ?>" <?= ($settings['sms_provider']??'')===$k?'selected':'' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_sms_api_key">API Key / Auth Token</label>
          <input type="password" id="s_sms_api_key" name="sms_api_key" class="rto-input" value="" placeholder="<?= !empty($settings['sms_api_key_configured']) ? '•••••••• (configured — leave blank to keep)' : 'Not configured' ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="rto-form-group">
            <label class="rto-label" for="s_sms_sender_id">Sender ID / From</label>
            <input type="text" id="s_sms_sender_id" name="sms_sender_id" class="rto-input" value="<?= $s('sms_sender_id') ?>" placeholder="RTOFLW">
          </div>
          <div class="rto-form-group">
            <label class="rto-label" for="s_sms_dlt_entity">DLT Entity ID</label>
            <input type="text" id="s_sms_dlt_entity" name="sms_dlt_entity" class="rto-input" value="<?= $s('sms_dlt_entity') ?>" placeholder="TRAI DLT Entity ID">
          </div>
        </div>

      <?php elseif ($tab==='whatsapp'): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="wa_enabled" value="1" <?= ($settings['wa_enabled']??'0')==='1'?'checked':'' ?>>
          <strong>Enable WhatsApp Notifications</strong>
        </label>
        <div class="rto-form-group">
          <label class="rto-label" for="s_wa_provider">Provider</label>
          <select id="s_wa_provider" name="wa_provider" class="rto-select">
            <option value="">— None —</option>
            <?php foreach (['meta'=>'Meta (Official API)','interakt'=>'Interakt','wati'=>'WATI','gupshup'=>'Gupshup'] as $k=>$l): ?>
            <option value="<?= esc_attr($k) ?>" <?= ($settings['wa_provider']??'')===$k?'selected':'' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_wa_token">Access Token / API Key</label>
          <input type="password" id="s_wa_token" name="wa_token" class="rto-input" value="" placeholder="<?= !empty($settings['wa_token_configured']) ? '•••••••• (configured — leave blank to keep)' : 'Not configured' ?>">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div class="rto-form-group">
            <label class="rto-label" for="s_wa_phone_id">Phone Number ID</label>
            <input type="text" id="s_wa_phone_id" name="wa_phone_id" class="rto-input" value="<?= $s('wa_phone_id') ?>">
          </div>
          <div class="rto-form-group">
            <label class="rto-label" for="s_wa_waba_id">WABA ID</label>
            <input type="text" id="s_wa_waba_id" name="wa_waba_id" class="rto-input" value="<?= $s('wa_waba_id') ?>">
          </div>
        </div>
        <?php // ENTERPRISE GAP FIX (Phase 4, item 7 — no fallback provider for
              // SMS/WhatsApp): WhatsApp now automatically retries a failed or
              // disabled WhatsApp send as a plain SMS (RTOFLOW_WhatsApp::
              // fallbackToSms()); this lets an operator turn that off. ?>
        <div class="rto-form-group" style="margin-top:8px">
          <label class="rto-checkbox-label">
            <input type="checkbox" name="wa_sms_fallback" value="1" <?= ($settings['wa_sms_fallback']??'1')==='1'?'checked':'' ?>>
            Automatically fall back to SMS when a WhatsApp message can't be sent
          </label>
        </div>

      <?php elseif ($tab==='notifications'): ?>
        <div class="rto-form-group">
          <label class="rto-label" for="s_admin_email">Admin Notification Email</label>
          <input type="email" id="s_admin_email" name="admin_email" class="rto-input" value="<?= $s('admin_email') ?>" placeholder="admin@example.com">
        </div>
        <h4 style="font-size:14px;font-weight:600;margin:8px 0 4px">Send notifications when:</h4>
        <?php foreach ([
          ['notif_lead_created',  'New application submitted (client + admin)'],
          ['notif_vendor_assign', 'Vendor assigned to an order (vendor)'],
          ['notif_status_change', 'Order status changes (client)'],
          ['notif_payment_done',  'Payment received (client + admin)'],
          ['notif_sla_warning',   'Order approaching SLA deadline (admin)'],
        ] as [$k,$l]): ?>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:10px">
          <input type="checkbox" name="<?= esc_attr($k) ?>" value="1"
                 <?= ($settings[$k]??'1')==='1'?'checked':'' ?>>
          <?= esc_html($l) ?>
        </label>
        <?php endforeach; ?>
      <?php elseif ($tab==='matching'): ?>
        <div class="rto-msg rto-msg-info" role="note">
          These values control how <strong>Vendor Auto-Assignment</strong> scores and filters vendors when a new order needs a vendor.
          The four weights below <strong>must add up to 100</strong> — saving a set of weights that doesn't will be rejected with an error showing the actual total, so the resulting score always stays on a true 0–100 scale.
        </div>
        <h4 style="font-size:14px;font-weight:600;margin:8px 0 4px">Scoring Weights</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <?php foreach (['rating_weight','completion_weight','acceptance_weight','load_weight'] as $mk): $m = $settings['matching'][$mk]; ?>
          <div class="rto-form-group">
            <label class="rto-label" for="m_<?= esc_attr($mk) ?>"><?= esc_html($m['label']) ?><?= rto_field_tooltip('Weight (0-100) this factor contributes to a vendor\'s total auto-assignment score. All four weights must sum to exactly 100 — a save that does not will be rejected.') ?></label>
            <input type="number" step="0.1" min="0" max="100" id="m_<?= esc_attr($mk) ?>" name="<?= esc_attr($mk) ?>"
                   class="rto-input matching-weight" value="<?= esc_attr($m['value']) ?>">
            <div class="rto-small rto-muted" style="margin-top:4px"><?= esc_html($m['description']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="rto-small" id="weightSumHint" style="margin-top:-8px"></div>

        <!-- Live ranking preview: re-scores the real, currently-eligible
             vendor pool for a picked city/service using whatever weights
             are currently typed above (not yet saved), via the same
             VendorService::previewRanking()/scoreVendor() formula
             auto_assign() uses at run time. Writes nothing. -->
        <div class="rto-card" style="margin-top:20px;padding:16px">
          <h4 style="font-size:14px;font-weight:600;margin:0 0 12px">Preview Ranking</h4>
          <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <div class="rto-form-group" style="margin-bottom:0">
              <label class="rto-label" for="previewCity">City</label>
              <select id="previewCity" class="rto-input">
                <option value="">Select a city…</option>
                <?php foreach ($previewCities as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= esc_html($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="rto-form-group" style="margin-bottom:0">
              <label class="rto-label" for="previewService">Service</label>
              <select id="previewService" class="rto-input">
                <option value="">Select a service…</option>
                <?php foreach ($previewServices as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= esc_html($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" id="previewMatchingBtn" class="rto-btn rto-btn-outline">Preview</button>
          </div>
          <div id="previewMatchingMsg" class="rto-msg" style="display:none;margin-top:12px"></div>
          <div id="previewMatchingResult" style="margin-top:12px;display:none">
            <table class="rto-table rto-table-hover" data-rto-responsive="cards">
              <thead><tr><th>Vendor</th><th>Rating</th><th>Completion</th><th>Acceptance</th><th>Active Jobs</th><th>Score</th></tr></thead>
              <tbody id="previewMatchingRows"></tbody>
            </table>
          </div>
          <div id="previewImpactResult" style="margin-top:16px;display:none;border-top:1px solid #e2e8f0;padding-top:12px">
            <h5 style="font-size:13px;font-weight:600;margin:0 0 6px">Impact on Recent Assignments</h5>
            <p class="rto-small rto-muted" id="previewImpactSummary" style="margin:0 0 8px"></p>
            <p class="rto-small rto-muted" style="margin:0 0 8px;font-style:italic">Vendors are re-scored against today's live stats, not a historical snapshot from when each lead was actually assigned — this shows what would happen if these recent leads were assigned today under the new weights.</p>
            <table class="rto-table" data-rto-responsive="cards" id="previewImpactTable" style="display:none">
              <thead><tr><th>Lead</th><th>Actually Assigned To</th><th>Would Top Pick Change?</th></tr></thead>
              <tbody id="previewImpactRows"></tbody>
            </table>
          </div>
        </div>
        <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
        document.getElementById('previewMatchingBtn').addEventListener('click', function(){
          var btn = this, cityId = document.getElementById('previewCity').value, serviceId = document.getElementById('previewService').value;
          var msg = document.getElementById('previewMatchingMsg'), resultBox = document.getElementById('previewMatchingResult'), rows = document.getElementById('previewMatchingRows');
          msg.style.display='none'; resultBox.style.display='none'; document.getElementById('previewImpactResult').style.display='none';
          if (!cityId || !serviceId) {
            msg.className = 'rto-msg rto-msg-error'; msg.textContent = 'Pick a city and a service first.'; msg.style.display='block';
            return;
          }
          var fd = new FormData();
          fd.append('action','rto_admin'); fd.append('rto_area','admin'); fd.append('rto_action','settings.preview_matching');
          fd.append('city_id', cityId); fd.append('service_id', serviceId);
          document.querySelectorAll('.matching-weight').forEach(function(i){ fd.append(i.name, i.value); });
          fd.append('rto_nonce', rtoflowAdmin.nonce);
          btn.disabled = true;
          fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(r){
              if (!r.success) {
                msg.className = 'rto-msg rto-msg-error'; msg.textContent = (r.data && r.data.message) || 'Preview failed.'; msg.style.display='block';
                return;
              }
              var ranking = r.data.ranking || [];
              rows.innerHTML = '';
              if (ranking.length === 0) {
                msg.className = 'rto-msg rto-msg-info'; msg.textContent = 'No eligible vendors found for this city/service.'; msg.style.display='block';
                return;
              }
              ranking.forEach(function(v){
                var tr = document.createElement('tr');
                if (!v.eligible) tr.style.opacity = '0.5';
                tr.innerHTML = '<td data-label="Vendor">#'+v.id+'</td><td data-label="Rating">'+v.rating+'</td><td data-label="Completion">'+v.completion_rate+'%</td><td data-label="Acceptance">'+v.acceptance_rate+'%</td><td data-label="Active Jobs">'+v.active_jobs+'</td><td data-label="Score">'+v.score.toFixed(2)+(v.eligible?'':' (over capacity)')+'</td>';
                rows.appendChild(tr);
              });
              resultBox.style.display = 'block';

              var impact = r.data.impact || null;
              var impactBox = document.getElementById('previewImpactResult');
              var impactSummary = document.getElementById('previewImpactSummary');
              var impactTable = document.getElementById('previewImpactTable');
              var impactRows = document.getElementById('previewImpactRows');
              impactRows.innerHTML = '';
              if (impact && impact.checked > 0) {
                impactSummary.textContent = 'Checked the last ' + impact.checked + ' assignment(s) made for this city/service. '
                  + (impact.changed > 0
                      ? 'Under the proposed weights, the top-ranked vendor would change for all ' + impact.changed + ' of them (same city/service pool, so the outcome is the same for each).'
                      : 'Under the proposed weights, the top-ranked vendor would stay the same for these assignments.');
                impact.rows.slice(0, 10).forEach(function(row){
                  var tr = document.createElement('tr');
                  tr.innerHTML = '<td data-label="Lead">#'+row.lead_id+'</td><td data-label="Actually Assigned To">'+row.actual_vendor_name+' (#'+row.actual_vendor_id+')</td><td data-label="Would Change">'+(row.would_change ? 'Yes' : 'No')+'</td>';
                  impactRows.appendChild(tr);
                });
                impactTable.style.display = impact.rows.length ? 'table' : 'none';
                impactBox.style.display = 'block';
              } else {
                impactSummary.textContent = 'No recent assignments found for this city/service to compare against.';
                impactTable.style.display = 'none';
                impactBox.style.display = 'block';
              }
            })
            .catch(function(){ msg.className = 'rto-msg rto-msg-error'; msg.textContent = 'Preview request failed.'; msg.style.display='block'; })
            .finally(function(){ btn.disabled = false; });
        });
        </script>

        <h4 style="font-size:14px;font-weight:600;margin:16px 0 4px">Eligibility Thresholds</h4>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
          <?php foreach (['min_rating','max_active_jobs','candidate_pool_size'] as $mk): $m = $settings['matching'][$mk]; ?>
          <div class="rto-form-group">
            <label class="rto-label" for="m_<?= esc_attr($mk) ?>"><?= esc_html($m['label']) ?><?= $mk==='candidate_pool_size' ? rto_field_tooltip('Maximum number of top-scoring vendors considered before one is picked. A larger pool spreads jobs across more vendors; a smaller pool favors the single best match.') : '' ?></label>
            <input type="number" step="<?= $mk==='min_rating'?'0.1':'1' ?>" min="0" <?= $mk==='min_rating'?'max="5"':'' ?>
                   id="m_<?= esc_attr($mk) ?>" name="<?= esc_attr($mk) ?>" class="rto-input" value="<?= esc_attr($m['value']) ?>">
            <div class="rto-small rto-muted" style="margin-top:4px"><?= esc_html($m['description']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
        (function() {
          var inputs = document.querySelectorAll('.matching-weight');
          var hint   = document.getElementById('weightSumHint');
          function updateSum() {
            var sum = 0;
            inputs.forEach(function(i){ sum += parseFloat(i.value) || 0; });
            // Matches MatchingConfig::SUM_TOLERANCE (0.05) server-side — this
            // hint must never claim a sum is fine when the save would
            // actually be rejected, or vice versa.
            var offBy100 = Math.abs(sum - 100) > 0.05;
            hint.textContent = 'Total: ' + sum.toFixed(1) + (offBy100 ? ' — must equal 100 exactly to save' : ' ✓ ready to save');
            hint.className = 'rto-small ' + (offBy100 ? 'rto-error' : 'rto-success');
          }
          inputs.forEach(function(i){ i.addEventListener('input', updateSum); });
          updateSum();
        })();
        </script>

        <?php
        // FIX (Config Versioning wiring): every MatchingConfig::save() now
        // records a version (see MatchingConfig.php), and
        // ConfigVersionService::publish()/rollback() now actually apply a
        // version's payload back to MatchingConfig's live wp_options value
        // (see Bootstrap.php's 'rtoflow_config_published' listener) — so
        // this history panel is a genuine, working rollback UI, not just a
        // read-only log.
        $configVersionKey = 'matching_config';
        include RTOFLOW_DIR . 'resources/views/admin/partials/config-version-history.php';
        ?>

      <?php elseif ($tab==='ai_summary'): ?>
        <!-- Part 5.9: the Order Details "Lead Summary" is a rule-based
             sentence-assembly engine by default (LeadSummaryService) — real,
             always-available, never fabricated. Flipping this on and adding
             a real API key routes the same real lead data through a real
             LLM call instead (LeadSummaryService::generateViaLLM()), with an
             automatic fallback to the rule-based text on ANY failure
             (missing/invalid key, timeout, non-2xx, malformed response) —
             an admin is never shown a broken screen or a raw API error. -->
        <p class="rto-muted rto-small rto-mb-2">The Lead Summary shown on every Order Details page is generated by a
          built-in rule-based writer by default — it always works, with no key required. Enabling this routes the
          same real submitted lead data through a real AI provider for more natural, varied prose instead. If the
          call fails or times out for any reason, the built-in writer is used automatically — the summary is never
          left blank or broken.</p>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:8px">
          <input type="checkbox" name="ai_summary_enabled" value="1" <?= ($settings['ai_summary_enabled']??'0')==='1'?'checked':'' ?>>
          <strong>Use AI-generated Lead Briefing (falls back automatically)</strong>
        </label>
        <div class="rto-form-group">
          <label class="rto-label" for="s_ai_summary_provider">AI Provider</label>
          <select id="s_ai_summary_provider" name="ai_summary_provider" class="rto-select">
            <?php foreach (['anthropic'=>'Anthropic (Claude)','openai'=>'OpenAI (GPT)'] as $k=>$l): ?>
            <option value="<?= esc_attr($k) ?>" <?= ($settings['ai_summary_provider']??'anthropic')===$k?'selected':'' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_ai_summary_api_key">API Key</label>
          <input type="password" id="s_ai_summary_api_key" name="ai_summary_api_key" class="rto-input" value="" placeholder="<?= !empty($settings['ai_summary_api_key_configured']) ? '•••••••• (configured — leave blank to keep)' : 'Not configured — built-in writer will be used' ?>">
        </div>

      <?php elseif ($tab==='colors'): ?>
        <p class="rto-muted" style="margin-bottom:16px;font-size:13px">
          Customize the colors for all public-facing pages. Changes apply instantly after saving.
        </p>
        <?php
        $colorFields = [
            ['color_primary',   'Primary Color (Header, Buttons, Links)', get_option('rtoflow_color_primary', '#1B2A6B'), 'Main brand color — used for headers, nav, CTA buttons'],
            ['color_secondary', 'Secondary / CTA Color', get_option('rtoflow_color_secondary', '#E97B28'), 'Used for submit buttons and accent elements'],
            ['color_accent',    'Success / Accent Color', get_option('rtoflow_color_accent', '#16A34A'), 'Used for success states, badges, completion indicators'],
            ['color_bg_dark',   'Dark Background Color', get_option('rtoflow_color_bg_dark', '#0A1628'), 'Header, footer, and dark hero backgrounds'],
            ['color_text',      'Body Text Color', get_option('rtoflow_color_text', '#0f172a'), 'Main text color for page content'],
        ];
        ?>
        <div style="display:grid;gap:16px">
        <?php foreach ($colorFields as [$key, $label, $currentVal, $desc]): ?>
        <div class="rto-form-group">
          <label class="rto-label" for="<?= esc_attr($key) ?>"><?= esc_html($label) ?></label>
          <div style="display:flex;align-items:center;gap:12px">
            <input type="color" id="<?= esc_attr($key) ?>" name="<?= esc_attr($key) ?>"
                   value="<?= esc_attr($currentVal) ?>"
                   style="width:50px;height:40px;border:1.5px solid #e2e8f0;border-radius:6px;padding:2px;cursor:pointer">
            <input type="text" name="<?= esc_attr($key) ?>_text" aria-label="<?= esc_attr($label) ?> hex value" value="<?= esc_attr($currentVal) ?>"
                   pattern="^#[0-9A-Fa-f]{6}$" placeholder="#1B2A6B"
                   style="width:110px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:6px;font-size:13px;font-family:monospace"
                   data-color-sync-target="<?= esc_attr($key) ?>"
                   class="rto-input">
            <div style="width:40px;height:40px;border-radius:8px;background:<?= esc_attr($currentVal) ?>;border:1px solid #e2e8f0" id="preview_<?= esc_attr($key) ?>"></div>
            <span style="font-size:12px;color:#64748b"><?= esc_html($desc) ?></span>
          </div>
        </div>
        <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
        document.getElementById('<?= esc_js($key) ?>').addEventListener('input',function(){
          document.querySelector('[name="<?= esc_js($key) ?>_text"]').value=this.value;
          document.getElementById('preview_<?= esc_js($key) ?>').style.background=this.value;
        });
        (function(){
          var textInput = document.querySelector('[data-color-sync-target="<?= esc_js($key) ?>"]');
          if (textInput) {
            textInput.addEventListener('input', function(){
              document.getElementById('<?= esc_js($key) ?>').value = this.value;
            });
          }
        })();
        </script>
        <?php endforeach; ?>
        </div>

        <!-- Live preview section -->
        <div style="margin-top:24px;padding:16px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0">
          <p style="font-size:12px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px">Preview</p>
          <div id="colorPreviewBar" style="display:flex;gap:10px;flex-wrap:wrap">
            <?php foreach ($colorFields as [$key, $label, $val, $desc]): ?>
            <div style="text-align:center">
              <div style="width:60px;height:60px;border-radius:8px;background:<?= esc_attr($val) ?>;border:1px solid #e2e8f0;margin-bottom:4px" id="bigpreview_<?= esc_attr($key) ?>"></div>
              <div style="font-size:10px;color:#64748b;max-width:60px"><?= esc_html(str_replace('Color', '', $label)) ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
        // Sync color pickers with big preview
        <?php foreach ($colorFields as [$key, $label, $val, $desc]): ?>
        document.getElementById('<?= esc_js($key) ?>').addEventListener('input',function(){
          var bigP = document.getElementById('bigpreview_<?= esc_js($key) ?>');
          if(bigP) bigP.style.background = this.value;
        });
        <?php endforeach; ?>
        // Sync text inputs to color pickers
        document.querySelectorAll('[name$="_text"]').forEach(function(inp) {
          inp.addEventListener('input', function() {
            var key = this.name.replace('_text','');
            var picker = document.getElementById(key);
            var bigP = document.getElementById('bigpreview_'+key);
            if(picker && /^#[0-9A-Fa-f]{6}$/.test(this.value)) {
              picker.value = this.value;
              if(bigP) bigP.style.background = this.value;
            }
          });
        });
        // On save, sync text values back to hidden inputs
        document.querySelector('form').addEventListener('submit', function() {
          document.querySelectorAll('[name$="_text"]').forEach(function(inp) {
            var key = inp.name.replace('_text','');
            var picker = document.getElementById(key);
            if(picker) picker.value = inp.value;
          });
        });
        </script>

      <?php elseif ($tab==='privacy'): ?>
        <!-- ENTERPRISE GAP FIX (Phase 9, item — "data retention is
             reactive, not policy-driven"): GdprService::eraseSubject()
             already handles an on-demand erasure request; these fields
             configure DataRetentionService's daily cron so records past
             their own window are anonymised/purged automatically, not
             only when someone remembers to invoke erasure per subject. -->
        <p class="rto-muted" style="margin-bottom:16px;font-size:13px">
          Automatically anonymise/purge records once they age past the period you set below.
          Leave a period at <strong>0</strong> to disable automatic purging for that category —
          on-demand erasure (Leads → Compliance → Erase Subject) still works regardless.
          Runs once daily; see the audit log (action <code>retention.purged</code>) for what it actually did.
        </p>
        <div class="rto-form-group">
          <label class="rto-label" for="s_retention_leads">Completed/cancelled leads (days)</label>
          <input type="number" min="0" id="s_retention_leads" name="retention_days_leads" class="rto-input" value="<?= $s('retention_days_leads','0') ?>">
          <p style="font-size:12px;color:#64748b;margin-top:4px">Leads in a terminal status (completed/cancelled/closed/rejected) older than this are anonymised the same way GdprService anonymises a lead on request. 0 = never.</p>
        </div>
        <div class="rto-form-group">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" id="s_retention_drafts" name="retention_days_drafts" value="1" <?= $s('retention_days_drafts','0') !== '0' ? 'checked' : '' ?>>
            <span>Auto-purge expired form drafts</span>
          </label>
          <p style="font-size:12px;color:#64748b;margin-top:4px">Each draft already carries its own expiry (set when a visitor saves progress, typically a few days) — FormDraftService::cleanupExpired() removes rows past that expiry, but was never wired to run automatically until now. This is a switch, not a day count: the per-draft expiry is unaffected either way.</p>
        </div>
        <div class="rto-form-group">
          <label class="rto-label" for="s_retention_logs">Audit log entries (days)</label>
          <input type="number" min="0" id="s_retention_logs" name="retention_days_logs" class="rto-input" value="<?= $s('retention_days_logs','0') ?>">
          <p style="font-size:12px;color:#64748b;margin-top:4px">Old rto_logs rows older than this are hard-deleted. Keep this generous (or 0/off) if you rely on the audit trail for compliance evidence over a long window.</p>
        </div>
      <?php endif; ?>

      </div>
    </div>

    <div style="margin-top:20px;display:flex;gap:12px">
      <button type="submit" class="rto-btn rto-btn-primary">Save Settings</button>
    </div>
  </form>
</div>

<?php
// FIX (Part 5.4 — Help Centre audit, real bug found and fixed): this
// previously always rendered the 'settings-matching' help box regardless
// of which of this controller's 7 tabs was actually active — an admin
// looking at, say, the Payment or Colors tab would see Vendor Matching's
// help content instead, which is confusing and factually wrong for that
// tab. Fixed to show the article that actually matches the active tab: the
// Matching tab keeps its own already-documented article, and every other
// tab now shows the new general 'settings' article covering Company/
// Payment/SMS/WhatsApp/Notifications/Colors.
rto_help_box($tab === 'matching' ? 'settings-matching' : 'settings');
?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
