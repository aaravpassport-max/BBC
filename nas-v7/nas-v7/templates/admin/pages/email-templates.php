<?php
/**
 * NAS Admin — Email Templates
 * Restored from the orphaned templates/admin/dashboard.php.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-envelope"></i> Email Templates</h1>
</div>
<p style="color:#64748b;font-size:13px;margin-bottom:16px">
  Edit subject lines and HTML body for automated emails. Placeholders like <code>{client_name}</code>, <code>{order_id}</code> are replaced automatically when sent.
</p>

<div class="nas-card">
  <div id="et-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
</div>

<!-- Edit modal -->
<div id="et-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
  <div class="nas-modal-content" style="max-width:700px">
    <div class="nas-modal-header">
      <h3 id="et-modal-title">Edit Template</h3>
      <button class="nas-modal-close" onclick="document.getElementById('et-modal').style.display='none'">✕</button>
    </div>
    <div class="nas-modal-body">
      <p style="font-size:12px;color:#94a3b8;margin:0 0 14px">Placeholders: <code id="et-placeholders" style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px"></code></p>
      <input type="hidden" id="et-id">
      <div class="nas-form-row"><label class="nas-label">Subject Line</label><input type="text" id="et-subject" class="nas-input"></div>
      <div class="nas-form-row"><label class="nas-label">HTML Body</label><textarea id="et-body" class="nas-textarea" rows="14" style="font-family:monospace;font-size:12px"></textarea></div>
    </div>
    <div class="nas-modal-footer">
      <button class="nas-btn nas-btn-outline" onclick="document.getElementById('et-modal').style.display='none'">Cancel</button>
      <button class="nas-btn nas-btn-primary" id="et-save-btn" onclick="etSave()">Save Template</button>
    </div>
  </div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);

function post(action, data) {
  var fd = new FormData();
  fd.append('action', action); fd.append('nonce', C.nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  var ctrl = new AbortController(); setTimeout(function(){ ctrl.abort(); }, 30000);
  return fetch(C.ajaxUrl, { method:'POST', body:fd, signal:ctrl.signal }).then(function(r){ return r.json(); });
}
function esc(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function load() {
  var el = document.getElementById('et-list');
  post('nas_get_email_templates', {}).then(function(res){
    if (!res.success) { el.innerHTML = '<p style="color:#dc2626">Could not load templates.</p>'; return; }
    var tpls = res.data.templates || [];
    if (!tpls.length) { el.innerHTML = '<div class="nas-empty">No email templates found.</div>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Name</th><th>Key</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
    tpls.forEach(function(t){
      html += '<tr><td><strong>'+esc(t.name)+'</strong></td>'+
        '<td style="font-family:monospace;font-size:11px">'+esc(t.template_key)+'</td>'+
        '<td><span style="background:'+(t.is_enabled?'#dcfce7':'#fee2e2')+';color:'+(t.is_enabled?'#16a34a':'#dc2626')+';padding:2px 10px;border-radius:99px;font-size:11px;font-weight:700">'+(t.is_enabled?'Active':'Disabled')+'</span></td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-xs nas-btn-primary" onclick="etEdit(\''+esc(t.template_key)+'\')">Edit</button>'+
        '<button class="nas-btn nas-btn-xs" onclick="etTest(\''+esc(t.template_key)+'\')">Send Test</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  }).catch(function(){ el.innerHTML = '<p style="color:#dc2626">Network error.</p>'; });
}

window.etEdit = function(key) {
  post('nas_get_email_template', { template_key: key }).then(function(res){
    if (!res.success || !res.data.template) { nasAdminToast('Could not load template.', 'error'); return; }
    var t = res.data.template;
    document.getElementById('et-id').value = t.id;
    document.getElementById('et-modal-title').textContent = 'Edit: ' + t.name;
    document.getElementById('et-subject').value = t.subject || '';
    document.getElementById('et-body').value = t.html_body || '';
    document.getElementById('et-placeholders').textContent = t.placeholders || '';
    document.getElementById('et-modal').style.display = 'flex';
  });
};

window.etSave = function() {
  var btn = document.getElementById('et-save-btn'); var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  post('nas_save_email_template', {
    id: document.getElementById('et-id').value,
    subject: document.getElementById('et-subject').value,
    html_body: document.getElementById('et-body').value,
  }).then(function(res){
    btn.disabled = false; btn.textContent = orig;
    if (res.success) {
      nasAdminToast(res.data?.message || 'Template saved!', 'success');
      document.getElementById('et-modal').style.display = 'none';
      load();
    } else {
      nasAdminToast(res.data?.message || 'Save failed.', 'error');
    }
  }).catch(function(){ btn.disabled=false; btn.textContent=orig; nasAdminToast('Network error.', 'error'); });
};

window.etTest = function(key) {
  var email = prompt('Send a test email to:', <?php echo wp_json_encode(get_option('admin_email')); ?>);
  if (!email) return;
  post('nas_test_send_email', { template_key: key, email: email }).then(function(res){
    nasAdminToast(res.success ? (res.data?.message || 'Test email sent!') : (res.data?.message || 'Failed to send.'), res.success ? 'success' : 'error');
  });
};

document.addEventListener('DOMContentLoaded', load);
})();
</script>
