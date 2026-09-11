<?php /** NAS Admin — All Requests */ if (!defined('ABSPATH')) exit; ?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-file-lines"></i> All Requests</h1>
  <div class="nas-page-actions">
    <button class="nas-btn nas-btn-outline" onclick="nrExport()"><i class="fa-solid fa-download"></i> Export CSV</button>
    <span class="nas-count-badge" id="nr-total-badge">Loading...</span>
  </div>
</div>
<div class="nas-filters">
  <div class="nas-filter-form">
    <div style="position:relative">
      <i class="fa-solid fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8"></i>
      <input type="text" id="nr-search" class="nas-input nas-input-search" style="padding-left:36px" placeholder="Search ref, name, phone...">
    </div>
    <select id="nr-status" class="nas-select nas-select-sm">
      <option value="">All Statuses</option>
      <?php foreach(['booking_received','under_review','quotation_sent','ready_to_process','documents_received','payment_received','ad_processing','proof_ready','submitted_to_pub','published','completed','rejected'] as $s): ?>
      <option value="<?php echo $s; ?>"><?php echo ucwords(str_replace('_',' ',$s)); ?></option>
      <?php endforeach; ?>
    </select>
    <select id="nr-payment" class="nas-select nas-select-sm">
      <option value="">All Payments</option>
      <option value="pending">Pending</option>
      <option value="partial">Partial</option>
      <option value="paid">Paid</option>
    </select>
    <button class="nas-btn nas-btn-primary" onclick="nrLoad(1)">Filter</button>
    <button class="nas-btn nas-btn-outline" onclick="nrClear()">Clear</button>
  </div>
</div>
<div class="nas-card">
  <div class="nas-table-wrap">
    <table class="nas-table nas-table-hover">
      <thead><tr>
        <th><input type="checkbox" id="nr-check-all" onchange="document.querySelectorAll('.nr-check').forEach(c=>c.checked=this.checked)"></th>
        <th>Ref ID</th><th>Client</th><th>Category</th>
        <th>Newspaper / City</th><th>Pub Date</th>
        <th>Amount</th><th>Status</th><th>Payment</th><th>Actions</th>
      </tr></thead>
      <tbody id="nr-tbody"><tr><td colspan="10" class="nas-empty"><div class="nas-spinner"></div> Loading...</td></tr></tbody>
    </table>
  </div>
  <div id="nr-pagination" class="nas-pagination" style="padding:16px 20px"></div>
</div>
</div>
<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const SYM = C.currency;
const statusColors = {submitted:'#3b82f6',review:'#f59e0b',price_shared:'#202C39',approved:'#10b981',sent_to_vendor:'#06b6d4',published:'#22c55e',completed:'#16a34a',rejected:'#ef4444'};
const payColors = {pending:'#f59e0b',partial:'#3b82f6',paid:'#10b981'};
const money = n => SYM + parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});

window.nrLoad = function(page){
  page = page||1;
  const fd = new FormData();
  fd.append('action','nas_admin_get_bookings'); fd.append('nonce',C.nonce);
  fd.append('page',page);
  fd.append('search',document.getElementById('nr-search').value);
  fd.append('status',document.getElementById('nr-status').value);
  fd.append('payment',document.getElementById('nr-payment').value);
  document.getElementById('nr-tbody').innerHTML = '<tr><td colspan="10" class="nas-empty"><div class="nas-spinner"></div></td></tr>';
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.json()).then(res=>{
    if(!res.success){document.getElementById('nr-tbody').innerHTML='<tr><td colspan="10" class="nas-empty">Error loading data.</td></tr>'; return;}
    const d = res.data;
    document.getElementById('nr-total-badge').textContent = d.total + ' bookings';
    document.getElementById('nr-tbody').innerHTML = !d.bookings?.length
      ? '<tr><td colspan="10" class="nas-empty">No requests found.</td></tr>'
      : d.bookings.map(b=>{
        const sc = statusColors[b.status]||'#6b7280', pc = payColors[b.payment_status]||'#6b7280';
        const days = Math.round((new Date(b.publish_date)-new Date())/86400000);
        const urgent = days>=0&&days<=2 ? `<br><span class="nas-badge-urgent">⚠️ ${days}d</span>` : '';
        const detailUrl = C.adminUrl+'?nas_admin=request'+'&id='+b.id;
        return `<tr class="nr-tr-${b.status}">
          <td><input type="checkbox" class="nr-check" value="${b.id}"></td>
          <td><a href="${detailUrl}" class="nas-uid-link">${b.uid||('#'+b.id)}</a></td>
          <td><strong>${b.client_name||'—'}</strong><br><small>${b.client_phone||''}</small></td>
          <td>${b.cat_name||'—'}</td>
          <td>${b.np_name||'—'}<br><small>${b.city_name||''}</small></td>
          <td>${b.publish_date||'—'}${urgent}</td>
          <td>${money(b.total_amount)}<br><small>Profit: ${money(b.profit)}</small></td>
          <td><span class="nas-badge" style="background:${sc}">${(b.status||'').replace(/_/g,' ')}</span></td>
          <td><span class="nas-badge" style="background:${pc}">${b.payment_status||''}</span></td>
          <td class="nas-actions">
            <a href="${detailUrl}" class="nas-btn nas-btn-xs nas-btn-primary">View</a>
            <a href="${C.ajaxUrl}?action=nas_admin_gen_pdf&type=invoice&id=${b.id}&nonce=${C.pdfNonce}" target="_blank" class="nas-btn nas-btn-xs">PDF</a>
          </td>
        </tr>`;
      }).join('');
    // Pagination
    const pg = document.getElementById('nr-pagination');
    pg.innerHTML = '';
    for(let i=1;i<=d.pages;i++){
      const a=document.createElement('a');
      a.href='#'; a.className='nas-page-btn'+(i===page?' nas-page-active':'');
      a.textContent=i; a.onclick=e=>{e.preventDefault();nrLoad(i);}; pg.appendChild(a);
    }
  });
};
window.nrClear = function(){ document.getElementById('nr-search').value=''; document.getElementById('nr-status').value=''; document.getElementById('nr-payment').value=''; nrLoad(1); };
window.nrExport = function(){
  const fd = new FormData(); fd.append('action','nas_admin_export_bookings'); fd.append('nonce',C.nonce);
  fetch(C.ajaxUrl,{method:'POST',body:fd}).then(r=>r.blob()).then(blob=>{
    const a=document.createElement('a'); a.href=URL.createObjectURL(blob);
    a.download='bookings-'+new Date().toISOString().split('T')[0]+'.csv'; a.click();
  });
};
nrLoad(1);
// Enter key search
document.getElementById('nr-search').addEventListener('keypress',e=>{if(e.key==='Enter')nrLoad(1);});
})();
</script>
