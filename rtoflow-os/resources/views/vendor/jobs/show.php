<?php if (!defined('ABSPATH')) exit;
/**
 * Vendor: Job Detail
 * @var int $lead_id
 */
global $wpdb;
$p    = $wpdb->prefix;
$vid  = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}rto_vendors WHERE user_id=%d", get_current_user_id()));
$lead = $wpdb->get_row($wpdb->prepare(
    "SELECT l.*, s.name as service_name, s.sla_days, c.name as city_name, c.rto_code,
     u.display_name as client_name, st.name as state_name,
     DATEDIFF(NOW(), l.created_at) as days_elapsed
     FROM {$p}rto_leads l
     JOIN {$p}rto_services s ON s.id=l.service_id
     JOIN {$p}rto_cities c ON c.id=l.city_id
     JOIN {$p}rto_states st ON st.id=c.state_id
     JOIN {$p}users u ON u.ID=l.client_id
     WHERE l.id=%d AND l.vendor_id=%d AND l.deleted_at IS NULL", $lead_id, $vid
), ARRAY_A);
if (!$lead) { wp_die('Job not found or not assigned to you.','Not Found',['response'=>404,'back_link'=>true]); }

$docs     = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$p}rto_documents WHERE lead_id=%d ORDER BY created_at DESC", $lead_id), ARRAY_A) ?: [];
// LIVE BUG FIX (Phase 4, item 1): this query joined on m.sender_id, but
// rto_messages' actual column (per Schema.php) is user_id — every load of
// this page threw "Unknown column 'm.sender_id'" and fataled before this
// fix (see ExceptionMonitor changes shipped alongside — that fatal would
// previously have been a silent blank page too).
$messages = $wpdb->get_results($wpdb->prepare("SELECT m.*, u.display_name FROM {$p}rto_messages m JOIN {$p}users u ON u.ID=m.user_id WHERE m.lead_id=%d ORDER BY m.created_at ASC", $lead_id), ARRAY_A) ?: [];

$statusFlow = ['assigned','in_progress','pending_docs','docs_submitted','rto_submitted','rto_processing','completed'];
$currentIdx  = array_search($lead['status'], $statusFlow);
$vendorStatuses = ['in_progress'=>'Mark In Progress','pending_docs'=>'Request More Documents','docs_submitted'=>'Submit to RTO','rto_submitted'=>'RTO Processing Started','completed'=>'Mark Completed'];

require RTOFLOW_DIR . 'resources/views/layouts/vendor-header.php';
?>
<div class="rto-page-wrap">
  <div class="rto-page-header rto-lead-header">
    <div>
      <h1 class="rto-page-title"><?= esc_html($lead['lead_number']) ?></h1>
      <span class="rto-badge rto-badge-<?= esc_attr(rto_status_color($lead['status'])) ?>"><?= esc_html(rto_status_label($lead['status'])) ?></span>
    </div>
    <a href="<?= esc_url(home_url('/rto-vendor/jobs/')) ?>" class="rto-back-link">← My Jobs</a>
  </div>

  <div id="jobMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <div style="display:grid;grid-template-columns:2fr 1fr;gap:20px">
    <div>

      <!-- Job Details -->
      <div class="rto-card rto-mb-4">
        <div class="rto-card-header"><h3>Job Details</h3></div>
        <div class="rto-card-body">
          <div class="rto-detail-grid">
            <?php foreach ([
              ['Service',   $lead['service_name']],
              ['City / RTO',$lead['city_name'] . ' (' . $lead['rto_code'] . ')'],
              ['State',     $lead['state_name']],
              ['Client',    $lead['client_name']],
              ['Applied On',rto_date($lead['created_at'])],
              ['SLA',       $lead['sla_days'] . ' days (Day ' . (int)$lead['days_elapsed'] . ')'],
              ['Value',     rto_format_inr((float)$lead['vendor_amount'])],
            ] as [$l,$v]): ?>
            <div class="rto-detail-item"><span><?= esc_html($l) ?></span><strong><?= esc_html($v) ?></strong></div>
            <?php endforeach; ?>
          </div>
          <?php if ($lead['notes']): ?>
          <div style="margin-top:12px;padding:12px;background:var(--gray-50);border-radius:6px">
            <div class="rto-small rto-muted">Client Notes</div>
            <p style="margin:4px 0 0"><?= esc_html($lead['notes']) ?></p>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Progress steps -->
      <div class="rto-card rto-mb-4">
        <div class="rto-card-header"><h3>Progress</h3></div>
        <div class="rto-card-body">
          <div style="display:flex;align-items:center;gap:0;overflow-x:auto;padding:8px 0">
            <?php foreach ($statusFlow as $idx=>$step): ?>
            <div style="display:flex;align-items:center;flex:1;min-width:90px">
              <div style="text-align:center;flex:1">
                <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 4px;font-size:12px;font-weight:700;
                  background:<?= $idx < $currentIdx ? 'var(--green)' : ($idx === $currentIdx ? 'var(--navy)' : 'var(--gray-200)') ?>;
                  color:<?= $idx <= $currentIdx ? '#fff' : 'var(--gray-500)' ?>">
                  <?= $idx < $currentIdx ? '✓' : ($idx + 1) ?>
                </div>
                <div style="font-size:10px;color:<?= $idx <= $currentIdx ? 'var(--navy)' : 'var(--gray-400)' ?>;line-height:1.2">
                  <?= esc_html(ucwords(str_replace('_',' ',$step))) ?>
                </div>
              </div>
              <?php if ($idx < count($statusFlow)-1): ?>
              <div style="height:2px;flex:1;background:<?= $idx < $currentIdx ? 'var(--green)' : 'var(--gray-200)' ?>"></div>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Documents -->
      <div class="rto-card rto-mb-4">
        <div class="rto-card-header">
          <h3>Documents</h3>
          <?php if (!in_array($lead['status'],['completed','cancelled'],true)): ?>
          <button id="uploadDocBtn" class="rto-btn rto-btn-sm rto-btn-outline">+ Upload Document</button>
          <?php endif; ?>
        </div>
        <?php if (empty($docs)): ?>
        <div class="rto-empty-state"><p>No documents yet.</p></div>
        <?php else: ?>
        <div class="rto-table-scroll">
          <table class="rto-table" data-rto-responsive="cards">
            <thead><tr><th>Document</th><th>Uploaded By</th><th>Status</th><th>Date</th><th><span class="rto-visually-hidden">Download</span></th></tr></thead>
            <tbody>
            <?php foreach ($docs as $doc): ?>
            <tr>
              <td data-label="Document"><?= esc_html($doc['doc_type'] ?: $doc['original_name']) ?></td>
              <td data-label="Uploaded By" class="rto-small"><?= esc_html(ucfirst($doc['uploaded_by_role'])) ?></td>
              <td data-label="Status"><span class="rto-badge rto-badge-<?= $doc['status']==='verified'?'success':($doc['status']==='rejected'?'danger':'secondary') ?>"><?= esc_html(ucfirst($doc['status'])) ?></span></td>
              <td data-label="Date" class="rto-small rto-muted"><?= esc_html(rto_date($doc['created_at'])) ?></td>
              <?php /* FIX (integration pass follow-up): see client/documents/index.php — same
                       insecure direct-attachment-URL issue, now routed through
                       DocumentAccessController::serve(), which explicitly authorises the
                       assigned vendor (via VendorRepository::find_by_user() matched to
                       lead.vendor_id) in addition to the owning client and admin/staff. */ ?>
              <td data-label="Download"><a href="<?= esc_url(home_url('/rto-documents/' . (int)$doc['id'] . '/')) ?>" class="rto-btn rto-btn-xs rto-btn-outline" target="_blank">⬇</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <!-- Messages -->
      <div class="rto-card">
        <div class="rto-card-header"><h3>Messages</h3></div>
        <div class="rto-card-body">
          <div id="msgThread" style="max-height:320px;overflow-y:auto;display:flex;flex-direction:column;gap:10px;margin-bottom:16px">
            <?php if (empty($messages)): ?>
            <p class="rto-muted rto-small">No messages yet.</p>
            <?php endif; ?>
            <?php foreach ($messages as $msg): ?>
            <?php $isMe = (int)$msg['user_id'] === get_current_user_id(); ?>
            <div style="display:flex;justify-content:<?= $isMe?'flex-end':'flex-start' ?>">
              <div style="max-width:72%;padding:10px 14px;border-radius:12px;font-size:13px;
                background:<?= $isMe?'var(--navy)':'var(--gray-100)' ?>;
                color:<?= $isMe?'#fff':'inherit' ?>">
                <?php if (!$isMe): ?><div style="font-size:11px;font-weight:600;margin-bottom:3px"><?= esc_html($msg['display_name']) ?></div><?php endif; ?>
                <?= esc_html($msg['message']) ?>
                <div style="font-size:10px;opacity:.7;margin-top:3px;text-align:right"><?= esc_html(rto_date($msg['created_at'],'d M, g:i A')) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php if (!in_array($lead['status'],['completed','cancelled'],true)): ?>
          <div style="display:flex;gap:8px">
            <input type="text" id="vendorMsgInput" class="rto-input" placeholder="Type a message…" style="flex:1">
            <button id="vendorSendMsg" class="rto-btn rto-btn-primary">Send</button>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div><!-- /left -->

    <!-- Right sidebar: actions -->
    <div>
      <?php if (!in_array($lead['status'],['completed','cancelled'],true)): ?>
      <div class="rto-card rto-mb-4">
        <div class="rto-card-header"><h3>Update Status</h3></div>
        <div class="rto-card-body" style="display:flex;flex-direction:column;gap:8px">
          <?php if ($lead['status'] === 'assigned'): ?>
          <!-- Accept / Reject Job -->
          <div style="background:#FEF9C3;border:1px solid #FDE68A;border-radius:8px;padding:14px;margin-bottom:8px">
            <p style="font-size:13px;font-weight:600;margin-bottom:10px">⚡ New job assignment — please respond:</p>
            <div style="display:flex;gap:10px">
              <button id="acceptJobBtn" class="rto-btn rto-btn-success" style="flex:1">
                ✅ Accept Job
              </button>
              <button id="rejectJobBtn" class="rto-btn rto-btn-danger" style="flex:1">
                ✗ Reject Job
              </button>
            </div>
          </div>
          <?php endif; ?>
          <?php foreach ($vendorStatuses as $s=>$l):
            $isNext = array_search($s,$statusFlow) > $currentIdx;
          ?>
          <button class="rto-btn <?= $isNext?'rto-btn-outline':'rto-btn-secondary' ?> status-update-btn"
                  data-status="<?= esc_attr($s) ?>" <?= !$isNext?'disabled':'' ?>>
            <?= esc_html($l) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- SLA indicator -->
      <?php
      $slaDays  = (int)$lead['sla_days'];
      $elapsed  = (int)$lead['days_elapsed'];
      $pct      = $slaDays > 0 ? min(100, round($elapsed/$slaDays*100)) : 0;
      $color    = $pct >= 100 ? '#dc2626' : ($pct >= 80 ? '#f59e0b' : '#16a34a');
      ?>
      <div class="rto-card">
        <div class="rto-card-header"><h3>SLA Status</h3></div>
        <div class="rto-card-body">
          <div style="font-size:24px;font-weight:700;color:<?= $color ?>;margin-bottom:4px"><?= $elapsed ?> / <?= $slaDays ?> days</div>
          <div style="height:8px;background:var(--gray-100);border-radius:4px;overflow:hidden;margin-bottom:8px">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;transition:width .3s;border-radius:4px"></div>
          </div>
          <div class="rto-small rto-muted">
            <?php if ($lead['status']==='completed'): ?>
            ✅ Completed
            <?php elseif ($pct >= 100): ?>
            ⚠️ SLA breached — please escalate
            <?php elseif ($pct >= 80): ?>
            ⚠️ <?= $slaDays - $elapsed ?> days remaining
            <?php else: ?>
            <?= $slaDays - $elapsed ?> days remaining
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Upload doc modal -->
<div id="uploadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;padding:24px;max-width:400px;width:90%">
    <h3 style="margin:0 0 16px">Upload Document</h3>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="docType">Document Type</label>
      <input type="text" id="docType" class="rto-input" placeholder="e.g. RC Copy, Aadhaar, NOC…">
    </div>
    <div class="rto-form-group rto-mb-4">
      <label class="rto-label" for="docFile">File (PDF/JPG/PNG, max 5MB)</label>
      <input type="file" id="docFile" class="rto-input" accept=".pdf,.jpg,.jpeg,.png">
    </div>
    <div style="display:flex;gap:10px">
      <button id="doUploadBtn" class="rto-btn rto-btn-primary">Upload</button>
      <button id="cancelUploadBtn" class="rto-btn rto-btn-outline">Cancel</button>
    </div>
  </div>
</div>

<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
var leadId = <?= (int)$lead_id ?>;
var nonce  = rtoflowVendor ? rtoflowVendor.nonce : '';
var ajaxUrl= rtoflowVendor ? rtoflowVendor.ajax_url : '';
var jobMsg = document.getElementById('jobMsg');

function vendorAjax(action, data) {
  var fd = new FormData();
  fd.append('action','rto_vendor'); fd.append('rto_area','vendor');
  fd.append('rto_action', action); fd.append('lead_id', leadId);
  fd.append('rto_nonce', nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  return fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd}).then(function(r){return r.json();});
}

// Accept / Reject job
var acceptBtn = document.getElementById('acceptJobBtn');
var rejectBtn = document.getElementById('rejectJobBtn');
if (acceptBtn) {
  acceptBtn.addEventListener('click', function() {
    this.disabled = true; this.textContent = 'Accepting…';
    var fd = new FormData();
    fd.append('action','rto_vendor'); fd.append('rto_area','vendor');
    fd.append('rto_action','accept_job'); fd.append('lead_id', leadId);
    fd.append('rto_nonce', nonce);
    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){return r.json();})
      .then(function(r){
        if(r.success){ window.rtoToast('Job accepted!','success'); setTimeout(function(){location.reload();},1000); }
        else { window.rtoToast(r.data?r.data.message:'Failed','error'); acceptBtn.disabled=false; acceptBtn.textContent='✅ Accept Job'; }
      }).catch(function(){ acceptBtn.disabled=false; acceptBtn.textContent='✅ Accept Job'; });
  });
}
if (rejectBtn) {
  rejectBtn.addEventListener('click', function() {
    var reason = prompt('Reason for rejecting this job (required):');
    if (!reason) return;
    this.disabled = true; this.textContent = 'Rejecting…';
    var fd = new FormData();
    fd.append('action','rto_vendor'); fd.append('rto_area','vendor');
    fd.append('rto_action','reject_job'); fd.append('lead_id', leadId);
    fd.append('reject_reason', reason); fd.append('rto_nonce', nonce);
    fetch(ajaxUrl, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){return r.json();})
      .then(function(r){
        if(r.success){ window.rtoToast('Job rejected.','info'); setTimeout(function(){window.location.href='<?= esc_js(home_url('/rto-vendor/jobs/')) ?>';},1200); }
        else { window.rtoToast(r.data?r.data.message:'Failed','error'); rejectBtn.disabled=false; rejectBtn.textContent='✗ Reject Job'; }
      }).catch(function(){ rejectBtn.disabled=false; rejectBtn.textContent='✗ Reject Job'; });
  });
}

// Status updates
// LIVE BUG FIX (Phase 4, item 1): previously posted to upload_doc, which
// has no update_status_only branch — every click here failed with "No
// file provided." Now hits the dedicated vendor.update_status action.
document.querySelectorAll('.status-update-btn:not([disabled])').forEach(function(btn) {
  btn.addEventListener('click', function() {
    if (!confirm('Update status to "' + btn.textContent.trim() + '"?')) return;
    vendorAjax('update_status', {status: btn.dataset.status})
      .then(function(r){ if(r.success) location.reload(); else window.rtoToast(r.message || 'Failed','error'); });
  });
});

// Upload doc
document.getElementById('uploadDocBtn')?.addEventListener('click',function(){
  document.getElementById('uploadModal').style.display='flex';
});
document.getElementById('cancelUploadBtn').addEventListener('click',function(){
  document.getElementById('uploadModal').style.display='none';
});
document.getElementById('doUploadBtn').addEventListener('click',function(){
  var file = document.getElementById('docFile').files[0];
  var type = document.getElementById('docType').value.trim();
  if (!file) { alert('Please select a file.'); return; }
  var fd = new FormData();
  fd.append('action','rto_vendor'); fd.append('rto_area','vendor');
  fd.append('rto_action','upload_doc'); fd.append('lead_id',leadId);
  fd.append('rto_nonce',nonce); fd.append('doc_type',type); fd.append('document',file);
  document.getElementById('doUploadBtn').disabled=true;
  document.getElementById('doUploadBtn').textContent='Uploading…';
  fetch(ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
      if(r.success){document.getElementById('uploadModal').style.display='none';location.reload();}
      else window.rtoToast(r.data?r.data.message:'Upload failed.','error');
    })
    .finally(function(){document.getElementById('doUploadBtn').disabled=false;document.getElementById('doUploadBtn').textContent='Upload';});
});

// Messaging
// LIVE BUG FIX (Phase 4, item 1): previously posted to upload_doc, which
// has no send_message_only branch — every message send failed with "No
// file provided." Now hits the dedicated vendor.send_message action.
document.getElementById('vendorSendMsg')?.addEventListener('click',function(){
  var msg = document.getElementById('vendorMsgInput').value.trim();
  if (!msg) return;
  vendorAjax('send_message',{message:msg})
    .then(function(r){
      if(r.success){document.getElementById('vendorMsgInput').value='';location.reload();}
      else window.rtoToast(r.message || 'Failed to send.','error');
    });
});

// Scroll messages to bottom
var mt = document.getElementById('msgThread');
if(mt) mt.scrollTop = mt.scrollHeight;
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/vendor-footer.php'; ?>
