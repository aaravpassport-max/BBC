<?php
/**
 * NAS Admin — FAQ Management
 * Restored from the orphaned templates/admin/dashboard.php. The old version used browser
 * prompt() dialogs for add/edit — replaced with a proper modal form to match every other
 * admin page in this system.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-circle-question"></i> FAQ Management</h1>
  <button class="nas-btn nas-btn-primary" onclick="fqOpenEditor(null)">+ Add FAQ</button>
</div>

<div class="nas-card">
  <div id="fq-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
</div>

<div id="fq-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content" style="max-width:600px">
  <div class="nas-modal-header">
    <h3 id="fq-modal-title">Add FAQ</h3>
    <button class="nas-modal-close" onclick="document.getElementById('fq-modal').style.display='none'">✕</button>
  </div>
  <div class="nas-modal-body">
    <input type="hidden" id="fq-id">
    <div class="nas-form-row"><label class="nas-label">Category</label>
      <select id="fq-category" class="nas-select">
        <option value="Booking">Booking</option><option value="Payment">Payment</option>
        <option value="Material">Material</option><option value="Publication">Publication</option>
        <option value="Refund">Refund</option><option value="general">General</option>
      </select>
    </div>
    <div class="nas-form-row"><label class="nas-label">Question</label><input type="text" id="fq-question" class="nas-input"></div>
    <div class="nas-form-row"><label class="nas-label">Answer</label><textarea id="fq-answer" class="nas-textarea" rows="4"></textarea></div>
    <div class="nas-form-row"><label class="nas-label">Sort Order</label><input type="number" id="fq-sort" class="nas-input" value="0"></div>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('fq-modal').style.display='none'">Cancel</button>
    <button class="nas-btn nas-btn-primary" id="fq-save-btn" onclick="fqSave()">Save FAQ</button>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
var fqData = [];

function post(action, data) {
  var fd = new FormData();
  fd.append('action', action); fd.append('nonce', C.nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  var ctrl = new AbortController(); setTimeout(function(){ ctrl.abort(); }, 30000);
  return fetch(C.ajaxUrl, { method:'POST', body:fd, signal:ctrl.signal }).then(function(r){ return r.json(); });
}
function esc(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function load() {
  var el = document.getElementById('fq-list');
  post('nas_get_faqs', {}).then(function(res){
    if (!res.success) { el.innerHTML = '<p style="color:#dc2626">Could not load FAQs.</p>'; return; }
    fqData = res.data.faqs || [];
    if (!fqData.length) { el.innerHTML = '<div class="nas-empty">No FAQs yet. Click "+ Add FAQ" to create one.</div>'; return; }
    var html = '<table class="nas-table"><thead><tr><th>Category</th><th>Question</th><th>Actions</th></tr></thead><tbody>';
    fqData.forEach(function(f){
      html += '<tr><td>'+esc(f.category)+'</td><td>'+esc(f.question)+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-xs nas-btn-primary" onclick="fqOpenEditor('+f.id+')">Edit</button>'+
        '<button class="nas-btn nas-btn-xs" style="background:#fee2e2;color:#dc2626" onclick="fqDelete('+f.id+')">Delete</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  }).catch(function(){ el.innerHTML = '<p style="color:#dc2626">Network error.</p>'; });
}

window.fqOpenEditor = function(id) {
  var f = id ? (fqData.find(function(x){ return x.id == id; }) || {}) : {};
  document.getElementById('fq-modal-title').textContent = id ? 'Edit FAQ' : 'Add FAQ';
  document.getElementById('fq-id').value = f.id || '';
  document.getElementById('fq-category').value = f.category || 'Booking';
  document.getElementById('fq-question').value = f.question || '';
  document.getElementById('fq-answer').value = f.answer || '';
  document.getElementById('fq-sort').value = f.sort_order || 0;
  document.getElementById('fq-modal').style.display = 'flex';
};

window.fqSave = function() {
  var q = document.getElementById('fq-question').value.trim();
  var a = document.getElementById('fq-answer').value.trim();
  if (!q || !a) { nasAdminToast('Question and answer are both required.', 'error'); return; }
  var btn = document.getElementById('fq-save-btn'); var orig = btn.textContent;
  btn.disabled = true; btn.textContent = 'Saving…';
  post('nas_admin_save_faq', {
    id: document.getElementById('fq-id').value,
    category: document.getElementById('fq-category').value,
    question: q, answer: a,
    sort_order: document.getElementById('fq-sort').value,
  }).then(function(res){
    btn.disabled = false; btn.textContent = orig;
    if (res.success) { nasAdminToast('FAQ saved!', 'success'); document.getElementById('fq-modal').style.display='none'; load(); }
    else nasAdminToast(res.data?.message || 'Save failed.', 'error');
  }).catch(function(){ btn.disabled=false; btn.textContent=orig; nasAdminToast('Network error.', 'error'); });
};

window.fqDelete = function(id) {
  if (!confirm('Delete this FAQ? This cannot be undone.')) return;
  post('nas_admin_delete_faq', { id: id }).then(function(res){
    if (res.success) { nasAdminToast('FAQ deleted.', 'success'); load(); }
    else nasAdminToast(res.data?.message || 'Delete failed.', 'error');
  });
};

document.addEventListener('DOMContentLoaded', load);
})();
</script>
