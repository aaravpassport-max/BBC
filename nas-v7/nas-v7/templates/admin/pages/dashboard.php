<?php /** NAS Admin — Dashboard Page */ if (!defined('ABSPATH')) exit; ?>
<div class="nas-page-wrap">

<!-- Page Header -->
<div class="nas-page-header">
  <div>
    <h1 class="nas-page-title">📊 Dashboard</h1>
    <span class="nas-page-sub"><?php echo date('l, d F Y'); ?></span>
  </div>
  <div class="nas-page-actions">
    <a href="<?php echo esc_url($admin_url('requests')); ?>" class="nas-btn nas-btn-outline">All Requests</a>
    <a href="<?php echo esc_url($admin_url('settings')); ?>" class="nas-btn nas-btn-outline">Settings</a>
    <a href="<?php echo esc_url(nas_get_page_url('nas_page_booking','/book-newspaper-ad/')); ?>" target="_blank" class="nas-btn nas-btn-primary">+ New Booking</a>
  </div>
</div>

<!-- KPI Grid — loaded via JS -->
<div class="nas-kpi-grid" id="nas-kpi-grid">
  <?php for ($i=0; $i<8; $i++): ?>
  <div class="nas-kpi-card nas-kpi-skeleton"><div class="nas-skeleton" style="height:80px;border-radius:8px"></div></div>
  <?php endfor; ?>
</div>

<!-- Quick Stats -->
<div class="nas-quick-stats" id="nas-quick-stats">
  <div class="nas-qs-card"><i class="fa-solid fa-users"></i><strong id="qs-clients">—</strong><span>Total Clients</span></div>
  <div class="nas-qs-card"><i class="fa-solid fa-truck"></i><strong id="qs-vendors">—</strong><span>Active Vendors</span></div>
  <div class="nas-qs-card"><i class="fa-solid fa-check-circle"></i><strong id="qs-published">—</strong><span>Published Ads</span></div>
  <div class="nas-qs-card"><i class="fa-solid fa-percentage"></i><strong id="qs-margin">—</strong><span>Avg Margin</span></div>
</div>

<!-- Charts Row -->
<div class="nas-charts-row">
  <div class="nas-card" style="flex:2">
    <div class="nas-card-header"><h3><i class="fa-solid fa-chart-line"></i> Revenue Trend (30 days)</h3></div>
    <div style="padding:16px 20px">
      <canvas id="nas-revenue-chart" height="200"></canvas>
    </div>
  </div>
  <div class="nas-card" style="flex:1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-chart-pie"></i> Status Breakdown</h3></div>
    <div id="nas-status-breakdown" class="nas-top-list"></div>
  </div>
</div>

<!-- Top Performers -->
<div class="nas-charts-row">
  <div class="nas-card" style="flex:1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-trophy"></i> Top Newspapers (30d)</h3></div>
    <div id="nas-top-newspapers" class="nas-top-list"></div>
  </div>
  <div class="nas-card" style="flex:1">
    <div class="nas-card-header"><h3><i class="fa-solid fa-tags"></i> Top Categories (30d)</h3></div>
    <div id="nas-top-categories" class="nas-top-list"></div>
  </div>
</div>

<!-- Recent Bookings -->
<div class="nas-card">
  <div class="nas-card-header">
    <h3><i class="fa-solid fa-list"></i> Recent Bookings</h3>
    <a href="<?php echo esc_url($admin_url('requests')); ?>" class="nas-btn nas-btn-sm nas-btn-outline">View All</a>
  </div>
  <div class="nas-table-wrap">
    <table class="nas-table nas-table-hover" id="nas-recent-table">
      <thead><tr>
        <th>Ref ID</th><th>Client</th><th>Category</th>
        <th>Newspaper / City</th><th>Pub Date</th>
        <th>Amount</th><th>Status</th><th>Payment</th><th>Action</th>
      </tr></thead>
      <tbody id="nas-recent-tbody">
        <tr><td colspan="9" class="nas-empty"><div class="nas-spinner"></div> Loading...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Quick Links -->
<div class="nas-quick-links">
  <?php foreach ([
    ['data-manager','fa-database','Data Manager'],
    ['analytics','fa-chart-bar','Analytics'],
    ['vendors','fa-truck','Vendors'],
    ['newspapers','fa-newspaper','Newspapers'],
    ['settings','fa-cog','Settings'],
  ] as $ql): ?>
  <a href="<?php echo esc_url($admin_url($ql[0])); ?>" class="nas-ql-card">
    <i class="fa-solid <?php echo $ql[1]; ?>"></i><span><?php echo $ql[2]; ?></span>
  </a>
  <?php endforeach; ?>
</div>

</div><!-- .nas-page-wrap -->

<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const SYM = C.currency;

function money(n){ return SYM + parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }

const kpiDefs = [
  {key:'total',       label:'Total Bookings',   icon:'fa-file-lines',   cls:'nas-kpi-blue',   fmt: v=>parseInt(v||0)},
  {key:'revenue',     label:'Total Revenue',    icon:'fa-rupee-sign',   cls:'nas-kpi-green',  fmt: money},
  {key:'profit',      label:'Total Profit',     icon:'fa-chart-line',   cls:'nas-kpi-purple', fmt: money},
  {key:'pending',     label:'Pending Review',   icon:'fa-clock',        cls:'nas-kpi-orange', fmt: v=>parseInt(v||0)},
  {key:'today_count', label:"Today's Bookings", icon:'fa-calendar-day', cls:'nas-kpi-teal',   fmt: v=>parseInt(v||0)},
  {key:'month_rev',   label:'This Month',       icon:'fa-calendar-check',cls:'nas-kpi-indigo',fmt: money},
  {key:'outstanding', label:'Outstanding',      icon:'fa-exclamation-circle',cls:'nas-kpi-red',fmt: money},
  {key:'total_papers',label:'Newspapers',       icon:'fa-newspaper',    cls:'nas-kpi-cyan',   fmt: v=>parseInt(v||0)},
];

const statusColors = {submitted:'#3b82f6',review:'#f59e0b',price_shared:'#202C39',approved:'#10b981',sent_to_vendor:'#06b6d4',published:'#22c55e',completed:'#16a34a',rejected:'#ef4444'};
const payColors    = {pending:'#f59e0b',partial:'#3b82f6',paid:'#10b981'};

function statusBadge(s){ const c=statusColors[s]||'#6b7280'; return `<span class="nas-badge" style="background:${c}">${(s||'').replace(/_/g,' ')}</span>`; }
function payBadge(s){ const c=payColors[s]||'#6b7280'; return `<span class="nas-badge" style="background:${c}">${s||''}</span>`; }

// Load analytics
const fd = new FormData();
fd.append('action','nas_admin_get_analytics'); fd.append('nonce',C.nonce); fd.append('days',30);
var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{
  if(!res.success) return;
  const d = res.data; const k = d.kpis;

  // Render KPI cards
  const kpiGrid = document.getElementById('nas-kpi-grid');
  kpiGrid.innerHTML = kpiDefs.map(def => {
    const val = def.key === 'month_rev' ? def.fmt(d.month_rev) :
                def.key === 'total_papers' ? def.fmt(d.total_papers) :
                def.fmt(k[def.key]);
    return `<div class="nas-kpi-card ${def.cls}">
      <div class="nas-kpi-icon"><i class="fa-solid ${def.icon}"></i></div>
      <div class="nas-kpi-body">
        <span class="nas-kpi-val">${val}</span>
        <span class="nas-kpi-label">${def.label}</span>
      </div>
    </div>`;
  }).join('');

  // Quick stats
  document.getElementById('qs-clients').textContent  = d.total_clients || 0;
  document.getElementById('qs-vendors').textContent  = 0; // loaded separately if needed
  document.getElementById('qs-published').textContent = k.published || 0;
  const rev = parseFloat(k.revenue||0), prof = parseFloat(k.profit||0);
  document.getElementById('qs-margin').textContent   = rev > 0 ? Math.round((prof/rev)*100)+'%' : '0%';

  // Status breakdown
  document.getElementById('nas-status-breakdown').innerHTML = (d.status_breakdown||[]).map((s,i) => `
    <div class="nas-top-item">
      <div class="nas-rank">${i+1}</div>
      <div class="nas-top-info">
        <strong style="text-transform:capitalize">${(s.status||'').replace(/_/g,' ')}</strong>
        <div class="nas-top-bar" style="width:${Math.min(100,(s.cnt/(k.total||1))*100)}%"></div>
      </div>
      <strong>${s.cnt}</strong>
    </div>`).join('') || '<div class="nas-no-data">No data</div>';

  // Top newspapers
  document.getElementById('nas-top-newspapers').innerHTML = (d.top_newspapers||[]).map((n,i)=>`
    <div class="nas-top-item">
      <div class="nas-rank">${i+1}</div>
      <div class="nas-top-info"><strong>${n.name||'—'}</strong><small>${n.bookings} bookings · ${money(n.revenue||0)}</small></div>
    </div>`).join('') || '<div class="nas-no-data">No data</div>';

  // Top categories
  document.getElementById('nas-top-categories').innerHTML = (d.top_categories||[]).map((c,i)=>`
    <div class="nas-top-item">
      <div class="nas-rank">${i+1}</div>
      <div class="nas-top-info"><strong>${c.name||'—'}</strong><small>${c.bookings} bookings</small></div>
    </div>`).join('') || '<div class="nas-no-data">No data</div>';

  // Revenue chart (Chart.js)
  if (window.Chart && d.trend && d.trend.length) {
    const ctx = document.getElementById('nas-revenue-chart').getContext('2d');
    new Chart(ctx,{
      type:'line',
      data:{
        labels: d.trend.map(t=>t.day),
        datasets:[
          {label:'Revenue',data:d.trend.map(t=>t.revenue),borderColor:'#2A8AFA',backgroundColor:'rgba(42,138,250,.08)',tension:.4,fill:true},
          {label:'Profit', data:d.trend.map(t=>t.profit), borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,.05)',tension:.4,fill:true},
        ]
      },
      options:{responsive:true,plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>SYM+v}}}}
    });
  }
});

// Load recent bookings
const fd2 = new FormData();
fd2.append('action','nas_admin_get_bookings'); fd2.append('nonce',C.nonce); fd2.append('page',1);
var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
fetch(C.ajaxUrl,{method:'POST',body:fd2,signal:_nc2.signal}).then(r=>r.json()).then(res=>{
  const tbody = document.getElementById('nas-recent-tbody');
  if(!res.success||!res.data?.bookings?.length){ tbody.innerHTML='<tr><td colspan="9" class="nas-empty">No bookings yet.</td></tr>'; return; }
  tbody.innerHTML = res.data.bookings.slice(0,10).map(b=>{
    const days = Math.round((new Date(b.publish_date)-new Date())/(86400000));
    const urgent = days>=0&&days<=2 ? `<br><span class="nas-badge-urgent">⚠️ ${days}d left</span>` : '';
    return `<tr>
      <td><a href="${C.adminUrl+'?nas_admin=request'}&id=${b.id}" class="nas-uid-link">${b.uid||b.id}</a></td>
      <td><strong>${b.client_name||'—'}</strong><br><small>${b.client_phone||''}</small></td>
      <td>${b.cat_name||'—'}</td>
      <td>${b.np_name||'—'}<br><small>${b.city_name||''}</small></td>
      <td>${b.publish_date||'—'}${urgent}</td>
      <td>${money(b.total_amount)}<br><small>Profit: ${money(b.profit)}</small></td>
      <td>${statusBadge(b.status)}</td>
      <td>${payBadge(b.payment_status)}</td>
      <td><a href="${C.adminUrl+'?nas_admin=request'}&id=${b.id}" class="nas-btn nas-btn-xs nas-btn-primary">View</a></td>
    </tr>`;
  }).join('');
});

})();
</script>
