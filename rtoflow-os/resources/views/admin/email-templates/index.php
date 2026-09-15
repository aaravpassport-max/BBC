<?php if (!defined('ABSPATH')) exit;
/** @var array $templates @var string $channel */
$pageTitle = 'Email Templates';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
$channels = ['email'=>'Email','sms'=>'SMS','whatsapp'=>'WhatsApp'];
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title">Notification Templates</h1>
  </div>

  <?php if (isset($_GET['saved'])): ?>
  <div class="rto-msg rto-msg-success rto-mb-4">Template saved successfully.</div>
  <?php endif; ?>

  <!-- Channel filter tabs -->
  <div class="rto-tabs rto-mb-4">
    <a href="<?= esc_url(home_url('/rto-admin/email-templates/')) ?>" class="rto-tab <?= !$channel?'active':'' ?>">All</a>
    <?php foreach ($channels as $k=>$l): ?>
    <a href="<?= esc_url(add_query_arg(['rto_area'=>'admin','rto_page'=>'email-templates','channel'=>$k],home_url('/rto-admin/email-templates/'))) ?>"
       class="rto-tab <?= $channel===$k?'active':'' ?>"><?= esc_html($l) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="rto-card">
    <?php if (empty($templates)): ?>
    <div class="rto-empty-state"><p>No templates found. Re-activate the plugin to seed default templates.</p></div>
    <?php else: ?>
    <div class="rto-table-scroll">
      <table class="rto-table rto-table-hover" data-rto-responsive="cards">
        <thead><tr>
          <th>Trigger</th><th>Channel</th><th>Subject / Preview</th><th>Status</th>
          <th><span class="rto-visually-hidden">Actions</span></th>
        </tr></thead>
        <tbody>
        <?php foreach ($templates as $tpl): ?>
        <tr>
          <td data-label="Trigger">
            <strong><?= esc_html($tpl['slug']) ?></strong>
            <?php if ($tpl['description']): ?>
            <div class="rto-small rto-muted"><?= esc_html($tpl['description']) ?></div>
            <?php endif; ?>
          </td>
          <td data-label="Channel"><span class="rto-badge rto-badge-secondary"><?= esc_html(ucfirst($tpl['channel'])) ?></span></td>
          <td data-label="Subject / Preview" class="rto-small"><?= esc_html(mb_strimwidth($tpl['subject'] ?: $tpl['body'], 0, 80, '…')) ?></td>
          <td data-label="Status">
            <span class="rto-badge rto-badge-<?= $tpl['is_active']?'success':'secondary' ?>">
              <?= $tpl['is_active']?'Active':'Inactive' ?>
            </span>
          </td>
          <td data-label="Actions" style="display:flex;gap:6px">
            <a href="<?= esc_url(home_url('/rto-admin/email-templates/'.(int)$tpl['id'].'/')) ?>"
               class="rto-btn rto-btn-xs rto-btn-outline">Edit</a>
            <?php if ($tpl['channel']==='email'): ?>
            <button class="rto-btn rto-btn-xs rto-btn-outline test-tpl-btn"
                    data-id="<?= esc_attr($tpl['id']) ?>" aria-label="Send test email for template: <?= esc_attr($tpl['slug']) ?>">Test</button>
            <?php else: ?>
            <!-- Known Limitations audit fix: "No test-send capability exists
                 for SMS or WhatsApp templates." Real RTOFLOW_SMS/RTOFLOW_WhatsApp
                 send integrations already existed (and, separately, had a
                 platform-wide namespace bug that made them unusable until this
                 same audit round fixed it) — this was a genuinely missing
                 admin-facing wrapper, not a new provider integration. -->
            <button class="rto-btn rto-btn-xs rto-btn-outline test-tpl-msg-btn"
                    data-id="<?= esc_attr($tpl['id']) ?>" data-channel="<?= esc_attr($tpl['channel']) ?>" aria-label="Send test <?= esc_attr(ucfirst($tpl['channel'])) ?> for template: <?= esc_attr($tpl['slug']) ?>">Test</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Test modal -->
<div id="testModal" role="dialog" aria-modal="true" aria-labelledby="testModalTitle" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;padding:24px;max-width:400px;width:90%">
    <h3 id="testModalTitle" style="margin:0 0 16px">Send Test Email</h3>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="testEmail">Send to</label>
      <input type="email" id="testEmail" class="rto-input" value="<?= esc_attr(get_option('admin_email')) ?>">
    </div>
    <div id="testMsg" class="rto-msg" style="display:none"></div>
    <div style="display:flex;gap:10px;margin-top:12px">
      <button id="doTestBtn" class="rto-btn rto-btn-primary">Send Test</button>
      <button id="cancelTestBtn" class="rto-btn rto-btn-outline">Cancel</button>
    </div>
  </div>
</div>

<!-- Known Limitations audit fix: SMS/WhatsApp test modal -->
<div id="testMsgModal" role="dialog" aria-modal="true" aria-labelledby="testMsgModalTitle" tabindex="-1" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;padding:24px;max-width:400px;width:90%">
    <h3 id="testMsgModalTitle" style="margin:0 0 16px">Send Test <span id="testMsgChannelLabel">SMS</span></h3>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="testMobile">Send to (10-digit mobile)</label>
      <input type="tel" id="testMobile" class="rto-input" placeholder="9876543210" maxlength="10">
    </div>
    <div id="testMsgMsg" class="rto-msg" style="display:none"></div>
    <div style="display:flex;gap:10px;margin-top:12px">
      <button id="doTestMsgBtn" class="rto-btn rto-btn-primary">Send Test</button>
      <button id="cancelTestMsgBtn" class="rto-btn rto-btn-outline">Cancel</button>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var currentTplId = null;
var testModalEl = document.getElementById('testModal');
var testModalReturnFocus = null;
function closeTestModal(){
  testModalEl.style.display='none';
  if (testModalReturnFocus && typeof testModalReturnFocus.focus === 'function') { testModalReturnFocus.focus(); testModalReturnFocus = null; }
}
testModalEl.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeTestModal(); });
document.querySelectorAll('.test-tpl-btn').forEach(function(btn){
  btn.addEventListener('click',function(){currentTplId=btn.dataset.id;testModalReturnFocus=btn;testModalEl.style.display='flex';testModalEl.focus();});
});
document.getElementById('cancelTestBtn').addEventListener('click',function(){closeTestModal();});
document.getElementById('doTestBtn').addEventListener('click',function(){
  var fd=new FormData();
  fd.append('action','rto_admin');fd.append('rto_area','admin');
  fd.append('rto_action','send_test_email');fd.append('template_id',currentTplId);
  fd.append('test_email',document.getElementById('testEmail').value);
  fd.append('rto_nonce',rtoflowAdmin.nonce);
  document.getElementById('doTestBtn').disabled=true;
  fetch(rtoflowAdmin.ajax_url,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
      var m=document.getElementById('testMsg');
      m.className='rto-msg rto-msg-'+(r.success?'success':'error');
      m.textContent=r.data?r.data.message:'Done';m.style.display='block';
    }).finally(function(){document.getElementById('doTestBtn').disabled=false;});
});

// Known Limitations audit fix: SMS/WhatsApp test-send modal, mirroring the
// email Test flow above but collecting a mobile number instead of an email
// address and posting to the new admin.send_test_message action.
var currentMsgTplId = null;
var testMsgModalEl = document.getElementById('testMsgModal');
var testMsgModalReturnFocus = null;
function closeTestMsgModal(){
  testMsgModalEl.style.display='none';
  if (testMsgModalReturnFocus && typeof testMsgModalReturnFocus.focus === 'function') { testMsgModalReturnFocus.focus(); testMsgModalReturnFocus = null; }
}
testMsgModalEl.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeTestMsgModal(); });
document.querySelectorAll('.test-tpl-msg-btn').forEach(function(btn){
  btn.addEventListener('click',function(){
    currentMsgTplId = btn.dataset.id;
    document.getElementById('testMsgChannelLabel').textContent = btn.dataset.channel === 'sms' ? 'SMS' : 'WhatsApp';
    testMsgModalReturnFocus = btn;
    testMsgModalEl.style.display='flex';
    testMsgModalEl.focus();
  });
});
document.getElementById('cancelTestMsgBtn').addEventListener('click',function(){closeTestMsgModal();});
document.getElementById('doTestMsgBtn').addEventListener('click',function(){
  var fd=new FormData();
  fd.append('action','rto_admin');fd.append('rto_area','admin');
  fd.append('rto_action','send_test_message');fd.append('template_id',currentMsgTplId);
  fd.append('test_mobile',document.getElementById('testMobile').value);
  fd.append('rto_nonce',rtoflowAdmin.nonce);
  document.getElementById('doTestMsgBtn').disabled=true;
  fetch(rtoflowAdmin.ajax_url,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
      var m=document.getElementById('testMsgMsg');
      m.className='rto-msg rto-msg-'+(r.success?'success':'error');
      m.textContent=r.data?r.data.message:'Done';m.style.display='block';
    }).finally(function(){document.getElementById('doTestMsgBtn').disabled=false;});
});
</script>

<?php rto_help_box('email-templates'); ?>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
