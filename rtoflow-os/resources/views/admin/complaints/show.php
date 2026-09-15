<?php if (!defined('ABSPATH')) exit;
/** @var array $complaint @var array $notes
 * ENTERPRISE GAP FIX (Phase 3, item 5 — complaint internal notes thread):
 * $notes comes from ComplaintNoteService::getNotes() — staff-only, never
 * shown to or emailed to the complainant (unlike $complaint['admin_response']
 * above, which the "Save & Notify Client" button emails out). */
$pageTitle = 'Complaint Detail';
require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
?>
<div class="rto-page-wrap" style="max-width:760px">
  <div class="rto-page-header rto-lead-header">
    <h1 class="rto-page-title"><?= esc_html($complaint['complaint_number']) ?></h1>
    <a href="<?= esc_url(home_url('/rto-admin/complaints/')) ?>" class="rto-back-link">← All Complaints</a>
  </div>

  <div id="cplMsg" class="rto-msg" style="display:none" aria-live="polite" role="status"></div>

  <?php if (empty($complaint['lead_id']) && !empty($suggestedLead)): ?>
  <!-- Known Limitations audit fix: "No automatic linkage suggestion
       between a newly-filed complaint and the lead it most likely
       concerns." Staff-confirmed only — never auto-linked. -->
  <div class="rto-msg rto-msg-info" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <span>This complaint has no linked order. Its filer's most recent open order is <strong><?= esc_html($suggestedLead['lead_number']) ?></strong> (<?= esc_html($suggestedLead['service_name'] ?? 'Unknown service') ?>, status: <?= esc_html(rto_status_label($suggestedLead['status'])) ?>) — likely what this concerns.</span>
    <button type="button" class="rto-btn rto-btn-sm link-lead-btn" id="linkSuggestedLeadBtn" data-lead-id="<?= (int)$suggestedLead['id'] ?>">Link this order</button>
  </div>
  <?php if (!empty($otherOpenLeads)): ?>
  <!-- Known Limitations audit follow-up: the filer has MORE than one open
       order — surfacing all of them (not just the single most recent) so
       staff aren't forced to leave this screen to cross-check the others. -->
  <div class="rto-msg rto-msg-info" style="margin-top:8px">
    <div style="margin-bottom:6px">This client also has <?= count($otherOpenLeads) ?> other open order(s) — pick one instead if it's a better match:</div>
    <?php foreach ($otherOpenLeads as $ol): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:4px 0">
        <span><strong><?= esc_html($ol['lead_number']) ?></strong> (<?= esc_html($ol['service_name'] ?? 'Unknown service') ?>, status: <?= esc_html(rto_status_label($ol['status'])) ?>)</span>
        <button type="button" class="rto-btn rto-btn-sm rto-btn-outline link-lead-btn" data-lead-id="<?= (int)$ol['id'] ?>">Link this order</button>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="rto-card rto-mb-4">
    <div class="rto-card-header">
      <h3><?= esc_html($complaint['subject']) ?></h3>
      <span class="rto-badge rto-badge-<?= esc_attr($complaint['status']==='resolved'?'success':($complaint['status']==='open'?'danger':'warning')) ?>">
        <?= esc_html(ucwords(str_replace('_',' ',$complaint['status']))) ?>
      </span>
    </div>
    <div class="rto-card-body">
      <div class="rto-detail-grid rto-mb-4">
        <?php foreach ([
          ['Client',  $complaint['client_name']],
          ['Email',   $complaint['user_email']],
          ['Order',   $complaint['lead_number']??'—'],
          ['Service', $complaint['service_name']??'—'],
          ['Category',ucfirst($complaint['category'])],
          ['Filed',   rto_date($complaint['created_at'])],
        ] as [$l,$v]): ?>
        <div class="rto-detail-item"><span><?= esc_html($l) ?></span><strong><?= esc_html($v) ?></strong></div>
        <?php endforeach; ?>
        <?php if (!empty($complaint['sla_deadline'])): ?>
        <div class="rto-detail-item">
          <span>SLA Deadline</span>
          <strong class="<?= !empty($complaint['sla_breached'])?'rto-danger':'' ?>">
            <?= esc_html(rto_date($complaint['sla_deadline'], 'd M Y H:i')) ?>
            <?= !empty($complaint['sla_breached']) ? ' ⚠ Breached' : '' ?>
          </strong>
        </div>
        <?php endif; ?>
      </div>

      <div style="background:var(--gray-50);border-radius:6px;padding:16px;margin-bottom:20px">
        <div class="rto-small rto-muted" style="margin-bottom:6px;font-weight:600">Complaint Details</div>
        <p style="margin:0;white-space:pre-wrap"><?= esc_html($complaint['body']) ?></p>
      </div>

      <?php if ($complaint['admin_response']): ?>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:16px;margin-bottom:20px">
        <div class="rto-small" style="font-weight:600;color:var(--green);margin-bottom:6px">Admin Response — <?= esc_html(rto_date($complaint['responded_at'])) ?></div>
        <p style="margin:0;white-space:pre-wrap"><?= esc_html($complaint['admin_response']) ?></p>
      </div>
      <?php endif; ?>

      <!-- Respond form -->
      <?php if (!in_array($complaint['status'],['closed','rejected'],true)): ?>
      <div style="border-top:1px solid var(--gray-200);padding-top:20px">
        <h4 style="font-size:14px;font-weight:600;margin:0 0 12px">Respond &amp; Update Status</h4>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="cplStatus">New Status</label>
          <select id="cplStatus" class="rto-select">
            <?php foreach (['under_review'=>'Under Review','resolved'=>'Resolved','closed'=>'Closed','rejected'=>'Rejected'] as $k=>$l): ?>
            <option value="<?= esc_attr($k) ?>" <?= $complaint['status']===$k?'selected':'' ?>><?= esc_html($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rto-form-group rto-mb-4">
          <label class="rto-label" for="cplResponse">Response to Client</label>
          <textarea id="cplResponse" class="rto-input" rows="4" placeholder="Your response will be emailed to the client…"><?= esc_textarea($complaint['admin_response']??'') ?></textarea>
        </div>
        <button id="cplSaveBtn" class="rto-btn rto-btn-primary">Save &amp; Notify Client</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ENTERPRISE GAP FIX (Phase 3, item 5 — complaint internal notes thread):
       staff-only channel, separate from the client-facing response above. -->
  <div class="rto-card rto-mt-4">
    <div class="rto-card-header"><h3>Internal Notes (staff only — not visible to the complainant)</h3></div>
    <div class="rto-card-body">
      <div id="cplNotesList" style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
        <?php if (empty($notes)): ?>
        <p class="rto-small rto-muted">No internal notes yet.</p>
        <?php else: foreach ($notes as $n): ?>
        <div style="padding:10px;background:#F8FAFC;border-radius:6px">
          <div class="rto-small rto-muted" style="margin-bottom:4px"><strong><?= esc_html($n['author_name'] ?? 'Unknown') ?></strong> — <?= esc_html(rto_date($n['created_at'])) ?></div>
          <p style="margin:0;white-space:pre-wrap"><?= esc_html($n['note_text']) ?></p>
        </div>
        <?php endforeach; endif; ?>
      </div>
      <div class="rto-form-group">
        <textarea id="cplNoteText" class="rto-input" rows="3" placeholder="Add an internal note (staff only)…"></textarea>
      </div>
      <button id="cplNoteSaveBtn" class="rto-btn rto-btn-outline">Add Note</button>
    </div>
  </div>
</div>
<script nonce="<?= esc_attr(\RTOFLOW\Security\Headers::nonce()) ?>">
document.getElementById('cplSaveBtn')?.addEventListener('click',function(){
  var fd=new FormData();
  fd.append('action','rto_admin');fd.append('rto_area','admin');
  fd.append('rto_action','respond_complaint');fd.append('complaint_id','<?= (int)$complaint['id'] ?>');
  fd.append('status',document.getElementById('cplStatus').value);
  fd.append('response',document.getElementById('cplResponse').value);
  fd.append('rto_nonce',rtoflowAdmin.nonce);
  document.getElementById('cplSaveBtn').disabled=true;
  fetch(rtoflowAdmin.ajax_url,{method:'POST',credentials:'same-origin',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){
      var m=document.getElementById('cplMsg');
      m.className='rto-msg rto-msg-'+(r.success?'success':'error');
      m.textContent=r.data?r.data.message:'Done';m.style.display='block';
      if(r.success)setTimeout(function(){location.reload();},1500);
    }).finally(function(){document.getElementById('cplSaveBtn').disabled=false;});
});

// FIX: was wired to a single button by ID, so the "other open orders"
// list added in this audit follow-up had no working Link button. Now
// delegates to every .link-lead-btn (the primary suggestion AND each
// additional open order). Also fixed the same message-location bug found
// elsewhere in this codebase: rto_json_ok()/rto_json_err() put the
// human-readable text on the top-level r.message, not inside r.data
// (r.data is null here) — r.data.message was always undefined.
document.querySelectorAll('.link-lead-btn').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var fd = new FormData();
    fd.append('action','rto_admin'); fd.append('rto_area','admin');
    fd.append('rto_action','link_complaint_lead');
    fd.append('complaint_id','<?= (int)$complaint['id'] ?>');
    fd.append('lead_id', btn.dataset.leadId);
    fd.append('rto_nonce', rtoflowAdmin.nonce);
    btn.disabled = true;
    fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
      .then(function(r){ return r.json(); })
      .then(function(r){
        if (window.rtoToast) rtoToast(r.message || (r.success ? 'Linked.' : 'Failed.'), r.success ? 'success' : 'error');
        if (r.success) setTimeout(function(){ location.reload(); }, 800);
        else btn.disabled = false;
      });
  });
});

document.getElementById('cplNoteSaveBtn')?.addEventListener('click', function(){
  var btn = this;
  var text = document.getElementById('cplNoteText').value.trim();
  if (!text) return;
  var fd = new FormData();
  fd.append('action','rto_admin'); fd.append('rto_area','admin');
  fd.append('rto_action','add_complaint_note');
  fd.append('complaint_id','<?= (int)$complaint['id'] ?>');
  fd.append('note', text);
  fd.append('rto_nonce', rtoflowAdmin.nonce);
  btn.disabled = true;
  fetch(rtoflowAdmin.ajax_url, {method:'POST', credentials:'same-origin', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(r){
      btn.disabled = false;
      if (r.success) { location.reload(); }
      else if (window.rtoToast) { rtoToast(r.message || 'Failed.', 'error'); }
      else { alert(r.message || 'Failed.'); }
    })
    .catch(function(){ btn.disabled = false; alert('Network error — please try again.'); });
});
</script>
<?php require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php'; ?>
