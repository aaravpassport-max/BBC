<?php
/**
 * NAS Admin — Support Tickets
 * Restored from the orphaned templates/admin/dashboard.php.
 */
if (!defined('ABSPATH')) exit;
$statuses = ['' => 'All', 'open' => 'Open', 'in_progress' => 'In Progress', 'waiting_client' => 'Waiting', 'resolved' => 'Resolved', 'closed' => 'Closed'];
?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-ticket"></i> Support Tickets</h1>
  <div style="display:flex;gap:6px;flex-wrap:wrap" id="tk-filters">
    <?php foreach ($statuses as $s => $l): ?>
    <button class="nas-btn nas-btn-sm nas-btn-secondary tk-filter-btn" data-status="<?php echo esc_attr($s); ?>" onclick="tkLoad('<?php echo esc_js($s); ?>', this)"><?php echo esc_html($l); ?></button>
    <?php endforeach; ?>
  </div>
</div>

<div class="nas-card">
  <div id="tk-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
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

window.tkLoad = function(status, btn) {
  document.querySelectorAll('.tk-filter-btn').forEach(function(b){ b.classList.remove('nas-btn-primary'); b.classList.add('nas-btn-secondary'); });
  if (btn) { btn.classList.remove('nas-btn-secondary'); btn.classList.add('nas-btn-primary'); }
  var el = document.getElementById('tk-list');
  el.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  post('nas_admin_get_tickets', { status: status || '', page: 1 }).then(function(res){
    if (!res.success) { el.innerHTML = '<p style="color:#dc2626">'+esc(res.data?.message||'Could not load tickets.')+'</p>'; return; }
    var tickets = res.data.tickets || [];
    if (!tickets.length) { el.innerHTML = '<div class="nas-empty">No tickets found.</div>'; return; }
    var colors = {open:'#2563eb',in_progress:'#d97706',waiting_client:'#7c3aed',resolved:'#16a34a',closed:'#94a3b8'};
    var html = '<table class="nas-table"><thead><tr><th>Ticket</th><th>Client</th><th>Subject</th><th>Category</th><th>Status</th><th>Priority</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
    tickets.forEach(function(t){
      var sc = colors[t.status] || '#64748b';
      html += '<tr><td style="font-family:monospace;font-size:12px">'+esc(t.ticket_uid)+'</td>'+
        '<td>'+esc(t.client_name||'—')+'</td><td>'+esc(t.subject)+'</td><td>'+esc(t.category)+'</td>'+
        '<td><span style="background:'+sc+'18;color:'+sc+';padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700">'+esc((t.status||'').replace(/_/g,' '))+'</span></td>'+
        '<td>'+esc(t.priority)+'</td>'+
        '<td>'+(t.created_at ? new Date(t.created_at).toLocaleDateString('en-IN') : '—')+'</td>'+
        '<td style="display:flex;gap:6px">'+
        '<button class="nas-btn nas-btn-xs nas-btn-primary" onclick="tkReply('+t.id+')">Reply</button>'+
        (t.status !== 'resolved' && t.status !== 'closed' ? '<button class="nas-btn nas-btn-xs" style="background:#dcfce7;color:#16a34a" onclick="tkResolve('+t.id+')">Resolve</button>' : '')+
        '</td></tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
  }).catch(function(){ el.innerHTML = '<p style="color:#dc2626">Network error.</p>'; });
};

window.tkReply = function(id) {
  var msg = prompt('Reply message:');
  if (!msg) return;
  post('nas_admin_reply_ticket', { ticket_id: id, message: msg }).then(function(res){
    nasAdminToast(res.success ? (res.data?.message || 'Reply sent!') : (res.data?.message || 'Failed.'), res.success ? 'success' : 'error');
  });
};

window.tkResolve = function(id) {
  if (!confirm('Mark this ticket as resolved?')) return;
  post('nas_admin_update_ticket', { ticket_id: id, status: 'resolved' }).then(function(res){
    if (res.success) { nasAdminToast('Ticket resolved.', 'success'); tkLoad('', document.querySelector('.tk-filter-btn[data-status=""]')); }
    else nasAdminToast(res.data?.message || 'Failed.', 'error');
  });
};

document.addEventListener('DOMContentLoaded', function(){ tkLoad('', document.querySelector('.tk-filter-btn[data-status=""]')); });
})();
</script>
