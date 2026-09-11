<?php
/**
 * NAS Admin — Clients v2 (Complete Rebuild)
 * Full profile management: edit, wallet, tickets, bookings, notes
 */
if ( ! defined('ABSPATH') ) exit;
?>
<style>
.nas-cl-layout{display:grid;grid-template-columns:1fr 420px;gap:20px;min-height:70vh}
.nas-cl-detail{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;position:sticky;top:80px;max-height:calc(100vh - 120px);overflow-y:auto;display:none}
.nas-cl-detail.visible{display:block}
.nas-cl-detail-head{background:linear-gradient(135deg,#2A8AFA,#202C39);padding:20px;color:#fff}
.nas-cl-detail-name{font-size:18px;font-weight:800;margin-bottom:4px}
.nas-cl-detail-meta{font-size:12px;opacity:.8}
.nas-cl-detail-avatar{width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:700;margin-bottom:10px}
.nas-cl-tabs{display:flex;border-bottom:1px solid #e2e8f0;overflow-x:auto}
.nas-cl-tab{padding:11px 14px;font-size:12px;font-weight:700;color:#94a3b8;border:none;border-bottom:2px solid transparent;background:none;cursor:pointer;white-space:nowrap;margin-bottom:-1px;transition:all .15s}
.nas-cl-tab:hover{color:#374151}
.nas-cl-tab.active{color:#2A8AFA;border-bottom-color:#2A8AFA}
.nas-cl-tab-panel{display:none;padding:16px}
.nas-cl-tab-panel.active{display:block}
.nas-cl-field{margin-bottom:12px}
.nas-cl-field label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:4px}
.nas-cl-field input,.nas-cl-field textarea,.nas-cl-field select{width:100%;padding:8px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s}
.nas-cl-field input:focus,.nas-cl-field textarea:focus{border-color:#2A8AFA}
.nas-cl-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.nas-cl-wallet-card{background:linear-gradient(135deg,#2A8AFA,#202C39);border-radius:10px;padding:16px;color:#fff;margin-bottom:12px}
.nas-cl-wallet-bal{font-size:28px;font-weight:800;line-height:1}
.nas-cl-wallet-actions{display:flex;gap:8px;margin-top:10px}
.nas-cl-wallet-actions input{flex:1;padding:6px 8px;border:none;border-radius:6px;font-size:13px}
.nas-cl-wallet-actions button{padding:6px 12px;border:none;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700}
.nas-cl-txn-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f1f5f9;font-size:12px}
.nas-cl-txn-credit{color:#059669;font-weight:700}
.nas-cl-txn-debit{color:#dc2626;font-weight:700}
@media(max-width:900px){.nas-cl-layout{grid-template-columns:1fr}.nas-cl-detail{position:static;max-height:none}}
</style>

<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-users"></i> Clients</h1>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="nas-count-badge" id="cl-count">Loading…</span>
    <button class="nas-btn nas-btn-ghost nas-btn-sm" id="cl-export-btn" onclick="clExportCSV()">
      <i class="fa-solid fa-download"></i> Export CSV
    </button>
  </div>
</div>

<div class="nas-cl-layout">
  <!-- Left: Table -->
  <div>
    <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap">
      <input type="text" id="cl-search" class="nas-input" style="max-width:280px;flex:1" placeholder="Search name, phone, email…" oninput="clFilterDebounce()">
      <select id="cl-sort" class="nas-select nas-select--sm" onchange="clLoad()">
        <option value="created_desc">Newest first</option>
        <option value="created_asc">Oldest first</option>
        <option value="spent_desc">Highest spend</option>
        <option value="orders_desc">Most orders</option>
      </select>
    </div>

    <div class="nas-card-table">
      <div class="nas-table-wrap">
        <table class="nas-table nas-table-hover" id="cl-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Client</th>
              <th>Phone</th>
              <th>Orders</th>
              <th>Total Spent</th>
              <th>GST</th>
              <th>Since</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="cl-tbody">
            <tr class="nas-skeleton-row">
              <td colspan="8" style="padding:24px;text-align:center">
                <div class="nas-spinner" style="margin:0 auto"></div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="nas-card-table-footer">
        <span id="cl-showing">—</span>
        <div id="cl-pagination"></div>
      </div>
    </div>
  </div>

  <!-- Right: Detail Panel -->
  <div class="nas-cl-detail" id="cl-detail-panel">
    <div class="nas-cl-detail-head">
      <div class="nas-cl-detail-avatar" id="cl-detail-avatar">C</div>
      <div class="nas-cl-detail-name" id="cl-detail-name">Client Name</div>
      <div class="nas-cl-detail-meta" id="cl-detail-meta">—</div>
    </div>
    <!-- Tabs -->
    <div class="nas-cl-tabs">
      <button class="nas-cl-tab active" onclick="clTab('profile',this)">Profile</button>
      <button class="nas-cl-tab" onclick="clTab('bookings',this)">Orders</button>
      <button class="nas-cl-tab" onclick="clTab('wallet',this)">Wallet</button>
      <button class="nas-cl-tab" onclick="clTab('tickets',this)">Tickets</button>
      <button class="nas-cl-tab" onclick="clTab('notes',this)">Notes</button>
    </div>
    <!-- Profile -->
    <div class="nas-cl-tab-panel active" id="cl-tp-profile">
      <div class="nas-cl-2col">
        <div class="nas-cl-field"><label>Full Name</label><input type="text" id="cl-e-name"></div>
        <div class="nas-cl-field"><label>Phone</label><input type="text" id="cl-e-phone"></div>
        <div class="nas-cl-field"><label>Email</label><input type="email" id="cl-e-email"></div>
        <div class="nas-cl-field"><label>City</label><input type="text" id="cl-e-city"></div>
      </div>
      <div class="nas-cl-field"><label>Company Name</label><input type="text" id="cl-e-company"></div>
      <div class="nas-cl-2col">
        <div class="nas-cl-field"><label>GST Number</label><input type="text" id="cl-e-gst" maxlength="15"></div>
        <div class="nas-cl-field"><label>PAN Number</label><input type="text" id="cl-e-pan" maxlength="10"></div>
      </div>
      <div class="nas-cl-field"><label>Address</label><textarea id="cl-e-address" rows="2"></textarea></div>
      <input type="hidden" id="cl-e-id">
      <div style="display:flex;gap:8px;margin-top:4px">
        <button class="nas-btn nas-btn-primary nas-btn-sm" onclick="clSaveProfile()"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="clSendResetEmail()"><i class="fa-solid fa-key"></i> Send Password Reset</button>
      </div>
    </div>
    <!-- Bookings -->
    <div class="nas-cl-tab-panel" id="cl-tp-bookings">
      <div id="cl-bookings-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>
    <!-- Wallet -->
    <div class="nas-cl-tab-panel" id="cl-tp-wallet">
      <div class="nas-cl-wallet-card">
        <div style="font-size:12px;opacity:.8;margin-bottom:4px">Wallet Balance</div>
        <div class="nas-cl-wallet-bal" id="cl-w-balance">₹0.00</div>
      </div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:12px">
        <div style="font-size:12px;font-weight:700;color:#374151;margin-bottom:8px">Adjust Balance</div>
        <div style="display:flex;gap:6px;margin-bottom:6px">
          <input type="number" id="cl-w-amount" placeholder="Amount (₹)" class="nas-input nas-input--sm" step="0.01" min="0">
          <input type="text" id="cl-w-desc" placeholder="Reason" class="nas-input nas-input--sm">
        </div>
        <div style="display:flex;gap:6px">
          <button class="nas-btn nas-btn-sm" style="background:#059669;color:#fff;flex:1" onclick="clWalletAdjust('credit')"><i class="fa-solid fa-plus"></i> Credit</button>
          <button class="nas-btn nas-btn-sm" style="background:#dc2626;color:#fff;flex:1" onclick="clWalletAdjust('debit')"><i class="fa-solid fa-minus"></i> Debit</button>
        </div>
      </div>
      <div id="cl-w-txns" style="max-height:220px;overflow-y:auto"></div>
    </div>
    <!-- Tickets -->
    <div class="nas-cl-tab-panel" id="cl-tp-tickets">
      <div id="cl-tickets-list"><div class="nas-spinner-wrap"><div class="nas-spinner"></div></div></div>
    </div>
    <!-- Notes -->
    <div class="nas-cl-tab-panel" id="cl-tp-notes">
      <div class="nas-cl-field">
        <label>Internal Notes (not visible to client)</label>
        <textarea id="cl-notes-text" rows="6" placeholder="Add admin notes about this client…" style="width:100%;padding:9px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;resize:vertical"></textarea>
      </div>
      <button class="nas-btn nas-btn-primary nas-btn-sm" onclick="clSaveNotes()"><i class="fa-solid fa-floppy-disk"></i> Save Notes</button>
    </div>
  </div>
</div>
</div>

<script>
(function(){
var C = JSON.parse(document.getElementById('nas-admin-config').textContent);
var SYM = C.currency || '₹';
var money = function(n) { return SYM + parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); };
var fdate = function(d) { return d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—'; };
var esc   = function(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
var toast = function(m,t) { if(window.nasToast) nasToast.show(m,t); else alert(m); };
var ajaxPost = function(action, data) {
  var fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', C.nonce);
  Object.entries(data).forEach(function(e){fd.append(e[0],e[1]||'');});
  var _nc=new AbortController();setTimeout(function(){_nc.abort();},30000);
  return fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc.signal}).then(function(r){return r.json();}).catch(function(e){if(e.name==='AbortError')throw new Error('Request timed out.');throw e;});
};

var clCurrentId  = null;
var clCurrentData= null;
var allClients   = [];
var clSortTimer  = null;

/* ── Load ── */
function clLoad() {
  document.getElementById('cl-tbody').innerHTML = '<tr><td colspan="8" style="padding:24px;text-align:center"><div class="nas-spinner" style="margin:0 auto"></div></td></tr>';
  ajaxPost('nas_admin_get_clients', {}).then(function(res) {
    if (!res.success) return;
    allClients = res.data || [];
    document.getElementById('cl-count').textContent = allClients.length + ' clients';
    clRender(allClients);
  });
}

function clRender(clients) {
  var sort = document.getElementById('cl-sort').value;
  var sorted = [...clients].sort(function(a,b) {
    if (sort==='spent_desc')  return (parseFloat(b.total_spent_real)||0) - (parseFloat(a.total_spent_real)||0);
    if (sort==='orders_desc') return (parseInt(b.order_count)||0) - (parseInt(a.order_count)||0);
    if (sort==='created_asc') return new Date(a.created_at) - new Date(b.created_at);
    return new Date(b.created_at) - new Date(a.created_at);
  });
  document.getElementById('cl-showing').textContent = sorted.length + ' clients';
  document.getElementById('cl-tbody').innerHTML = sorted.length ? sorted.map(function(c,i) {
    var initials = (c.name||'C').charAt(0).toUpperCase();
    var colors   = ['#2A8AFA','#059669','#d97706','#dc2626','#0284c7'];
    var color    = colors[i%colors.length];
    return '<tr data-id="'+c.id+'" data-search="'+(c.name+c.phone+c.email+c.company_name).toLowerCase().replace(/"/g,'')+'" onclick="clOpenDetail('+c.id+')" style="cursor:pointer">' +
      '<td><div style="width:32px;height:32px;border-radius:50%;background:'+color+';color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px">'+initials+'</div></td>' +
      '<td><div style="font-weight:600;font-size:13px;color:#0f172a">'+esc(c.name||'—')+'</div><div style="font-size:11px;color:#94a3b8">'+esc(c.email||'')+'</div></td>' +
      '<td><a href="tel:'+esc(c.phone)+'" style="color:#2A8AFA;text-decoration:none;font-size:13px" onclick="event.stopPropagation()">'+esc(c.phone||'—')+'</a></td>' +
      '<td><strong style="font-size:14px">'+parseInt(c.order_count||0)+'</strong></td>' +
      '<td><strong style="color:#059669;font-size:13px">'+money(c.total_spent_real)+'</strong></td>' +
      '<td style="font-size:11px;color:#94a3b8;font-family:monospace">'+esc(c.gst_number||'—')+'</td>' +
      '<td style="font-size:12px;color:#64748b">'+fdate(c.created_at)+'</td>' +
      '<td onclick="event.stopPropagation()"><button class="nas-btn nas-btn-xs nas-btn-primary" onclick="clOpenDetail('+c.id+')">View</button></td>' +
    '</tr>';
  }).join('') : '<tr><td colspan="8" class="nas-empty"><div class="nas-empty-state"><div class="nas-empty-icon">👥</div><h3>No clients yet</h3><p>Clients appear here after their first booking.</p></div></td></tr>';
}

/* ── Filter ── */
window.clFilterDebounce = function() {
  clearTimeout(clSortTimer);
  clSortTimer = setTimeout(function() {
    var q = document.getElementById('cl-search').value.toLowerCase().trim();
    if (!q) { clRender(allClients); return; }
    clRender(allClients.filter(function(c) {
      return (c.name+c.phone+c.email+c.company_name).toLowerCase().includes(q);
    }));
  }, 250);
};

/* ── Detail Panel ── */
window.clOpenDetail = function(id) {
  document.querySelectorAll('#cl-table tbody tr').forEach(function(r){ r.classList.remove('nas-table-row-active'); });
  var row = document.querySelector('#cl-table tbody tr[data-id="'+id+'"]');
  if (row) row.classList.add('nas-table-row-active');

  var c = allClients.find(function(cl){return parseInt(cl.id)===id;});
  if (!c) return;
  clCurrentId   = id;
  clCurrentData = c;

  // Fill header
  var initials = (c.name||'C').charAt(0).toUpperCase();
  document.getElementById('cl-detail-avatar').textContent = initials;
  document.getElementById('cl-detail-name').textContent   = c.name || 'Unknown';
  document.getElementById('cl-detail-meta').textContent   = (c.email||'') + (c.phone ? ' · '+c.phone : '') + ' · '+parseInt(c.order_count||0)+' orders · '+money(c.total_spent_real)+' spent';

  // Fill profile fields
  document.getElementById('cl-e-id').value      = id;
  document.getElementById('cl-e-name').value    = c.name||'';
  document.getElementById('cl-e-phone').value   = c.phone||'';
  document.getElementById('cl-e-email').value   = c.email||'';
  document.getElementById('cl-e-city').value    = c.city||'';
  document.getElementById('cl-e-company').value = c.company_name||'';
  document.getElementById('cl-e-gst').value     = c.gst_number||'';
  document.getElementById('cl-e-pan').value     = c.pan_number||'';
  document.getElementById('cl-e-address').value = c.address||'';

  // Show panel
  document.getElementById('cl-detail-panel').classList.add('visible');
  // Reset to profile tab
  clTab('profile', document.querySelector('.nas-cl-tab'));
};

/* ── Tab Switching ── */
window.clTab = function(tab, btn) {
  document.querySelectorAll('.nas-cl-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.nas-cl-tab-panel').forEach(function(p){p.classList.remove('active');});
  if(btn) btn.classList.add('active');
  var panel = document.getElementById('cl-tp-'+tab);
  if(panel) panel.classList.add('active');
  // Load data for tab
  if (tab==='bookings' && clCurrentId) clLoadBookings();
  if (tab==='wallet'   && clCurrentId) clLoadWallet();
  if (tab==='tickets'  && clCurrentId) clLoadTickets();
  if (tab==='notes'    && clCurrentId) clLoadNotes();
};

/* ── Save Profile ── */
window.clSaveProfile = function() {
  var id = parseInt(document.getElementById('cl-e-id').value);
  if (!id) return;
  ajaxPost('nas_admin_update_client', {
    client_id:    id,
    name:         document.getElementById('cl-e-name').value,
    phone:        document.getElementById('cl-e-phone').value,
    email:        document.getElementById('cl-e-email').value,
    city:         document.getElementById('cl-e-city').value,
    company_name: document.getElementById('cl-e-company').value,
    gst_number:   document.getElementById('cl-e-gst').value,
    pan_number:   document.getElementById('cl-e-pan').value,
    address:      document.getElementById('cl-e-address').value,
  }).then(function(r){
    if(r.success) { toast('Profile saved!','success'); clLoad(); }
    else toast(r.data?.message||'Save failed','error');
  });
};

window.clSendResetEmail = function() {
  var email = document.getElementById('cl-e-email').value;
  if (!email) { toast('No email address.','error'); return; }
  ajaxPost('nas_admin_send_password_reset', {email:email}).then(function(r){
    toast(r.success?'Password reset email sent!':'Failed to send reset email.', r.success?'success':'error');
  });
};

/* ── Bookings ── */
function clLoadBookings() {
  var el = document.getElementById('cl-bookings-list');
  el.innerHTML = '<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  ajaxPost('nas_get_all_bookings', {search: allClients.find(c=>parseInt(c.id)===clCurrentId)?.phone||''}).then(function(r){
    var bookings = (r.data?.bookings||r.data||[]).filter(function(b){return parseInt(b.client_id)===clCurrentId||parseInt(b.wp_user_id)===parseInt(clCurrentData?.wp_user_id);}).slice(0,20);
    if (!bookings.length) { el.innerHTML='<div class="cd-empty" style="padding:24px;text-align:center;color:#94a3b8">No orders found.</div>'; return; }
    el.innerHTML = bookings.map(function(b){
      return '<div style="padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:12px">'+
        '<div style="display:flex;justify-content:space-between;margin-bottom:3px">'+
        '<strong style="color:#0f172a">'+esc(b.uid||b.id)+'</strong>'+
        '<span style="color:#059669">'+money(b.total_amount)+'</span></div>'+
        '<div style="color:#94a3b8">'+esc(b.newspaper_name||'—')+' · '+esc(b.city_name||'—')+' · '+fdate(b.submitted_at)+'</div>'+
        '</div>';
    }).join('');
  });
}

/* ── Wallet ── */
function clLoadWallet() {
  ajaxPost('nas_admin_get_client_wallet', {client_id: clCurrentId}).then(function(r){
    if (!r.success) return;
    document.getElementById('cl-w-balance').textContent = money(r.data?.balance||0);
    var txns = r.data?.transactions||[];
    document.getElementById('cl-w-txns').innerHTML = txns.length ? txns.map(function(t){
      return '<div class="nas-cl-txn-row">'+
        '<div><div style="font-weight:600">'+esc(t.description)+'</div><div style="color:#94a3b8;font-size:11px">'+fdate(t.created_at)+'</div></div>'+
        '<span class="'+(t.type==='credit'?'nas-cl-txn-credit':'nas-cl-txn-debit')+'">'+
        (t.type==='credit'?'+':'-')+money(t.amount)+'</span></div>';
    }).join('') : '<div style="text-align:center;color:#94a3b8;padding:16px;font-size:13px">No transactions yet.</div>';
  });
}

window.clWalletAdjust = function(type) {
  var amount = parseFloat(document.getElementById('cl-w-amount').value);
  var desc   = document.getElementById('cl-w-desc').value.trim();
  if (!amount || amount <= 0) { toast('Enter a valid amount.','error'); return; }
  ajaxPost('nas_admin_'+(type==='credit'?'credit':'debit')+'_wallet', {
    client_id: clCurrentId, amount: amount, description: desc||'Admin adjustment'
  }).then(function(r){
    if(r.success) { toast('Wallet '+type+'ed!','success'); clLoadWallet(); document.getElementById('cl-w-amount').value=''; document.getElementById('cl-w-desc').value=''; }
    else toast(r.data?.message||'Failed','error');
  });
};

/* ── Tickets ── */
function clLoadTickets() {
  var el = document.getElementById('cl-tickets-list');
  el.innerHTML='<div class="nas-spinner-wrap"><div class="nas-spinner"></div></div>';
  ajaxPost('nas_admin_get_tickets',{client_id:clCurrentId}).then(function(r){
    var tickets = r.data?.tickets||[];
    if(!tickets.length){el.innerHTML='<div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px">No support tickets.</div>';return;}
    el.innerHTML=tickets.map(function(t){
      var sc={open:'#2563eb',resolved:'#059669',closed:'#94a3b8'};
      var c=sc[t.status]||'#2A8AFA';
      return'<div style="padding:10px 0;border-bottom:1px solid #f1f5f9">'+
        '<div style="display:flex;justify-content:space-between;margin-bottom:2px">'+
        '<strong style="font-size:12px;color:#0f172a">'+esc(t.subject)+'</strong>'+
        '<span style="background:'+c+'18;color:'+c+';font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px">'+t.status+'</span></div>'+
        '<div style="font-size:11px;color:#94a3b8">'+esc(t.ticket_uid)+' · '+fdate(t.created_at)+'</div></div>';
    }).join('');
  });
}

/* ── Notes ── */
function clLoadNotes() {
  ajaxPost('nas_admin_get_client_notes',{client_id:clCurrentId}).then(function(r){
    document.getElementById('cl-notes-text').value = r.data?.notes||'';
  });
}
window.clSaveNotes = function() {
  ajaxPost('nas_admin_save_client_notes',{client_id:clCurrentId,notes:document.getElementById('cl-notes-text').value}).then(function(r){
    toast(r.success?'Notes saved!':'Failed to save.', r.success?'success':'error');
  });
};

/* ── Export CSV ── */
window.clExportCSV = function() {
  var rows = [['ID','Name','Phone','Email','Company','GST','Orders','Total Spent','Since']];
  allClients.forEach(function(c){
    rows.push([c.id,c.name||'',c.phone||'',c.email||'',c.company_name||'',c.gst_number||'',c.order_count||0,c.total_spent_real||0,c.created_at||'']);
  });
  var csv = rows.map(function(r){return r.map(function(v){return '"'+(v+'').replace(/"/g,'""')+'"';}).join(',');}).join('\n');
  var blob = new Blob([csv],{type:'text/csv'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'nas-clients-'+new Date().toISOString().slice(0,10)+'.csv';
  a.click();
};

/* ── Init ── */
clLoad();
})();
</script>
