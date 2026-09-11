<?php
/**
 * NAS Admin — Contact Inbox
 * Restored from the orphaned templates/admin/dashboard.php.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-inbox"></i> Contact Inbox</h1>
</div>

<div class="nas-card">
  <div id="ct-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
</div>

<div id="ct-modal" class="nas-modal" style="display:none" onclick="if(this===event.target)this.style.display='none'">
<div class="nas-modal-content" style="max-width:560px">
  <div class="nas-modal-header">
    <h3 id="ct-modal-title">Message</h3>
    <button class="nas-modal-close" onclick="document.getElementById('ct-modal').style.display='none'">✕</button>
  </div>
  <div class="nas-modal-body">
    <p style="font-size:13px;color:#64748b;margin:0 0 12px" id="ct-modal-from"></p>
    <p style="white-space:pre-wrap;font-size:14px;line-height:1.6" id="ct-modal-message"></p>
  </div>
  <div class="nas-modal-footer">
    <button class="nas-btn nas-btn-outline" onclick="document.getElementById('ct-modal').style.display='none'">Close</button>
  </div>
</div>
</div>

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
var ctData = [];

function post(action, data) {
  var fd = new FormData();
  fd.append('action', action); fd.append('nonce', C.nonce);
  Object.keys(data||{}).forEach(function(k){ fd.append(k, data[k]); });
  var ctrl = new AbortController(); setTimeout(function(){ ctrl.abort(); }, 30000);
  return fetch(C.ajaxUrl, { method:'POST', body:fd, signal:ctrl.signal }).then(function(r){ return r.json(); });
}
function esc(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function load() {
  var el = document.getElementById('ct-list');
  post('nas_admin_get_contacts', {}).then(function(res){
    if (!res.success) { el.innerHTML = '<p style="color:#dc2626">Could not load submissions.</p>'; return; }
    ctData = res.data.submissions || [];
    if (!ctData.length) { el.innerHTML = '<div class="nas-empty">No contact submissions yet.</div>'; return; }
    var colors = { new:'#dc2626', read:'#2563eb', replied:'#16a34a', spam:'#94a3b8' };
    var html = '<table class="nas-table"><thead><tr><th>Name</th><th>Email</th><th>Subject</th><th>City</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    ctData.forEach(function(c){
      var sc = colors[c.status] || '#64748b';
      html += '<tr><td>'+esc(c.name)+'</td><td>'+esc(c.email)+'</td>'+
        '<td>'+esc(c.subject||'—')+'</td><td>'+esc(c.city||'—')+'</td>'+
        '<td><span style="color:'+sc+';font-weight:700;font-size:11px">'+esc((c.status||'').toUpperCase())+'</span></td>'+
        '<td>'+(c.created_at ? new Date(c.created_at).toLocaleDateString('en-IN') : '—')+'</td>'+
        '<td><button class="nas-btn nas-btn-xs nas-btn-primary" onclick="ctView('+c.id+')">View</button></td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  }).catch(function(){ el.innerHTML = '<p style="color:#dc2626">Network error.</p>'; });
}

window.ctView = function(id) {
  var c = ctData.find(function(x){ return x.id == id; });
  if (!c) return;
  document.getElementById('ct-modal-title').textContent = c.subject || 'Message';
  document.getElementById('ct-modal-from').textContent = 'From: ' + c.name + ' <' + c.email + '>';
  document.getElementById('ct-modal-message').textContent = c.message || '';
  document.getElementById('ct-modal').style.display = 'flex';
  if (c.status === 'new') {
    post('nas_admin_mark_contact', { id: id, status: 'read' }).then(function(){ load(); });
  }
};

document.addEventListener('DOMContentLoaded', load);
})();
</script>
