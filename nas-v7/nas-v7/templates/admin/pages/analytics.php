<?php if ( ! defined('ABSPATH') ) exit; ?>
<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-chart-line"></i> Analytics & Reports</h1>
  <div style="display:flex;gap:8px;align-items:center">
    <select id="an-days" class="nas-select" style="min-width:140px" onchange="anLoad()">
      <option value="7">Last 7 days</option>
      <option value="30" selected>Last 30 days</option>
      <option value="90">Last 90 days</option>
      <option value="365">Last 1 year</option>
    </select>
    <button class="nas-btn nas-btn-secondary" onclick="anLoad()"><i class="fa-solid fa-rotate"></i> Refresh</button>
  </div>

<!-- Analytics Controls -->
<div class="nas-analytics-controls" style="margin-bottom:20px;padding:16px 20px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
  <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:13px;font-weight:600;color:#374151">Date Range:</span>
    <input type="date" id="anal-date-from" class="nas-input nas-input--sm" style="max-width:150px">
    <span style="color:#94a3b8;font-size:13px">to</span>
    <input type="date" id="anal-date-to" class="nas-input nas-input--sm" style="max-width:150px">
    <button class="nas-btn nas-btn-primary nas-btn-sm" onclick="analApplyDateRange()">Apply</button>
    <div style="display:flex;gap:4px">
      <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analSetPeriod(7)">7d</button>
      <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analSetPeriod(30)">30d</button>
      <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analSetPeriod(90)">90d</button>
      <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analSetPeriod(365)">1yr</button>
    </div>
  </div>
  <div style="display:flex;gap:8px">
    <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analExport('csv')"><i class="fa-solid fa-file-csv"></i> Export CSV</button>
    <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="analExport('print')"><i class="fa-solid fa-print"></i> Print</button>
  </div>
</div>

<!-- Conversion Funnel -->
<div class="nas-card" style="margin-bottom:20px">
  <div class="nas-card-header"><h4 style="font-size:14px;font-weight:700;margin:0">Conversion Funnel</h4></div>
  <div class="nas-card-body" style="padding:20px">
    <div id="anal-funnel" style="display:flex;align-items:flex-end;gap:8px;justify-content:center;min-height:120px"></div>
  </div>
</div>

</div>

<!-- KPI ROW 1 -->
<div id="an-kpis" style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px">
  <?php for($i=0;$i<8;$i++) echo '<div class="nas-skeleton" style="height:88px;border-radius:12px"></div>'; ?>
</div>

<!-- ROW 1: Revenue Trend + Status Breakdown -->
<div style="display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-chart-area"></i> Daily Revenue & Profit Trend</span></div>
    <div class="nas-card-body"><canvas id="an-revenue-chart" height="220"></canvas></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-chart-pie"></i> Booking Status</span></div>
    <div class="nas-card-body"><canvas id="an-status-chart" height="220"></canvas></div>
  </div>
</div>

<!-- ROW 2: Monthly Trend + AOV -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-calendar-days"></i> Monthly Revenue (Last 12 Months)</span></div>
    <div class="nas-card-body"><canvas id="an-monthly-chart" height="220"></canvas></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-arrow-trend-up"></i> Average Order Value Trend</span></div>
    <div class="nas-card-body"><canvas id="an-aov-chart" height="220"></canvas></div>
  </div>
</div>

<!-- ROW 3: Top Cities + Revenue by Ad Type -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-city"></i> Top Performing Cities</span></div>
    <div class="nas-card-body" id="an-top-cities"><div class="nas-skeleton" style="height:200px;border-radius:8px"></div></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-newspaper"></i> Revenue by Ad Type</span></div>
    <div class="nas-card-body"><canvas id="an-type-chart" height="220"></canvas></div>
  </div>
</div>

<!-- ROW 4: Top Categories + Vendor Performance -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-tags"></i> Most Booked Ad Categories</span></div>
    <div class="nas-card-body" id="an-top-cats"><div class="nas-skeleton" style="height:200px;border-radius:8px"></div></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-truck"></i> Vendor Performance</span></div>
    <div class="nas-card-body" id="an-vendor-perf"><div class="nas-skeleton" style="height:200px;border-radius:8px"></div></div>
  </div>
</div>

<!-- ROW 5: Top Clients + Newspaper Efficiency -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-crown"></i> Top Clients by Lifetime Value</span></div>
    <div class="nas-card-body" id="an-top-clients"><div class="nas-skeleton" style="height:200px;border-radius:8px"></div></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-gauge-high"></i> Newspaper Efficiency (Avg Margin)</span></div>
    <div class="nas-card-body" id="an-newspaper-eff"><div class="nas-skeleton" style="height:200px;border-radius:8px"></div></div>
  </div>
</div>

<!-- ROW 6: Outstanding Aging + Conversion Metrics -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-clock-rotate-left"></i> Outstanding Payment Aging</span></div>
    <div class="nas-card-body" id="an-aging"><div class="nas-skeleton" style="height:160px;border-radius:8px"></div></div>
  </div>
  <div class="nas-card">
    <div class="nas-card-header"><span class="nas-card-title"><i class="fa-solid fa-funnel-dollar"></i> Conversion & Retention Metrics</span></div>
    <div class="nas-card-body" id="an-conversion"><div class="nas-skeleton" style="height:160px;border-radius:8px"></div></div>
  </div>
</div>

<div id="an-summary"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
const C = JSON.parse(document.getElementById('nas-admin-config').textContent);
const charts = {};

function money(v){ return '₹'+(parseFloat(v||0)).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function num(v){ return parseFloat(v||0).toLocaleString('en-IN'); }
function esc(s){ return (s||'—').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function mkChart(id, cfg){
  if(charts[id]) charts[id].destroy();
  const ctx = document.getElementById(id);
  if(!ctx) return;
  charts[id] = new Chart(ctx, cfg);
  return charts[id];
}

function tableRows(rows, cols){
  if(!rows||!rows.length) return '<p style="color:#94a3b8;text-align:center;padding:20px">No data for this period.</p>';
  const head = '<tr>'+cols.map(c=>'<th style="text-align:'+(c.align||'left')+'">'+c.label+'</th>').join('')+'</tr>';
  const body = rows.map(r=>'<tr>'+cols.map(c=>'<td style="text-align:'+(c.align||'left')+'">'+c.val(r)+'</td>').join('')+'</tr>').join('');
  return '<table class="nas-table nas-table-sm" style="width:100%;font-size:12px"><thead>'+head+'</thead><tbody>'+body+'</tbody></table>';
}

function kpiBox(icon, label, value, sub, color){
  return '<div style="background:#fff;border-radius:14px;padding:18px 16px;box-shadow:0 1px 6px rgba(0,0,0,.06);border:1px solid #f1f5f9;display:flex;align-items:center;gap:12px">'
    +'<div style="width:46px;height:46px;border-radius:11px;background:'+color+'18;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0">'+icon+'</div>'
    +'<div style="min-width:0"><div style="font-size:18px;font-weight:800;color:#0f172a;line-height:1.1;word-break:break-all">'+value+'</div>'
    +'<div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;margin-top:3px">'+label+'</div>'
    +(sub?'<div style="font-size:11px;color:'+color+';font-weight:600;margin-top:2px">'+sub+'</div>':'')
    +'</div></div>';
}

const COLORS = ['#2A8AFA','#3b82f6','#10b981','#f59e0b','#ef4444','#202C39','#06b6d4','#f97316','#ec4899','#84cc16'];

function anLoad(){
  const days = document.getElementById('an-days').value;
  document.getElementById('an-kpis').innerHTML = Array(8).fill('<div class="nas-skeleton" style="height:88px;border-radius:12px"></div>').join('');
  const fd = new FormData();
  fd.append('action','nas_admin_get_analytics'); fd.append('nonce',C.nonce); fd.append('days',days);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(res=>{
    if(!res.success) return;
    const d = res.data;

    // ── KPI CARDS ────────────────────────────────────────────────────────
    document.getElementById('an-kpis').innerHTML = [
      kpiBox('📋','Total Bookings',  num(d.kpis?.total||0),    null, '#2A8AFA'),
      kpiBox('💰','Total Revenue',   money(d.kpis?.revenue||0), null, '#3b82f6'),
      kpiBox('📈','Total Profit',    money(d.kpis?.profit||0),  null, '#10b981'),
      kpiBox('🎯','Avg Order Value', money(d.kpis?.avg_order||0), null, '#f59e0b'),
      kpiBox('🔄','Conversion Rate', (d.conversion_rate||0)+'%', 'bookings → paid', '#202C39'),
      kpiBox('👥','Total Leads',     num(d.total_leads||0),     'unique clients', '#06b6d4'),
      kpiBox('🔁','Repeat Clients',  num(d.repeat_clients||0),  (d.repeat_rate||0)+'% repeat rate', '#ec4899'),
      kpiBox('⏳','Outstanding',     money(d.kpis?.outstanding||0), 'payment pending', '#ef4444'),
    ].join('');

    // ── DAILY REVENUE TREND ──────────────────────────────────────────────
    if(d.trend && d.trend.length){
      mkChart('an-revenue-chart',{
        type:'line',
        data:{
          labels: d.trend.map(r=>r.day),
          datasets:[
            {label:'Revenue',data:d.trend.map(r=>parseFloat(r.revenue||0)),borderColor:'#2A8AFA',backgroundColor:'rgba(42,138,250,.08)',fill:true,tension:.4,pointRadius:3},
            {label:'Profit', data:d.trend.map(r=>parseFloat(r.profit||0)), borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.05)',fill:true,tension:.4,pointRadius:3},
          ]
        },
        options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN')}}}}
      });
    }

    // ── STATUS PIE ────────────────────────────────────────────────────────
    if(d.status_breakdown && d.status_breakdown.length){
      mkChart('an-status-chart',{
        type:'doughnut',
        data:{
          labels: d.status_breakdown.map(r=>(r.status||'').replace(/_/g,' ')),
          datasets:[{data:d.status_breakdown.map(r=>r.cnt), backgroundColor:COLORS, borderWidth:2, borderColor:'#fff'}]
        },
        options:{responsive:true,plugins:{legend:{position:'right',labels:{font:{size:11},boxWidth:12}}}}
      });
    }

    // ── MONTHLY TREND ────────────────────────────────────────────────────
    if(d.monthly_trend && d.monthly_trend.length){
      mkChart('an-monthly-chart',{
        type:'bar',
        data:{
          labels: d.monthly_trend.map(r=>r.month_label),
          datasets:[
            {label:'Revenue',data:d.monthly_trend.map(r=>parseFloat(r.revenue||0)),backgroundColor:'rgba(42,138,250,.7)',borderRadius:6},
            {label:'Profit', data:d.monthly_trend.map(r=>parseFloat(r.profit||0)), backgroundColor:'rgba(16,185,129,.6)',borderRadius:6},
          ]
        },
        options:{responsive:true,plugins:{legend:{position:'top'}},scales:{x:{ticks:{font:{size:10}}},y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN')}}}}
      });
    }

    // ── AOV TREND ────────────────────────────────────────────────────────
    if(d.aov_trend && d.aov_trend.length){
      mkChart('an-aov-chart',{
        type:'line',
        data:{
          labels: d.aov_trend.map(r=>r.week_start),
          datasets:[{label:'Avg Order Value',data:d.aov_trend.map(r=>parseFloat(r.aov||0)),borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.08)',fill:true,tension:.4,pointRadius:3}]
        },
        options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN')}}}}
      });
    }

    // ── REVENUE BY AD TYPE ────────────────────────────────────────────────
    if(d.revenue_by_type && d.revenue_by_type.length){
      mkChart('an-type-chart',{
        type:'bar',
        data:{
          labels: d.revenue_by_type.map(r=>(r.ad_type||'unknown').replace(/_/g,' ')),
          datasets:[{label:'Revenue',data:d.revenue_by_type.map(r=>parseFloat(r.revenue||0)),backgroundColor:COLORS,borderRadius:6}]
        },
        options:{responsive:true,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>'₹'+v.toLocaleString('en-IN')}}}}
      });
    }

    // ── TOP CITIES TABLE ──────────────────────────────────────────────────
    document.getElementById('an-top-cities').innerHTML = tableRows(d.top_cities,[
      {label:'City',   val:r=>esc(r.city)},
      {label:'Bookings',align:'right',val:r=>num(r.bookings)},
      {label:'Revenue', align:'right',val:r=>money(r.revenue)},
      {label:'Profit',  align:'right',val:r=>'<span style="color:#10b981">'+money(r.profit)+'</span>'},
    ]);

    // ── TOP CATEGORIES ────────────────────────────────────────────────────
    document.getElementById('an-top-cats').innerHTML = tableRows(d.top_cats_full,[
      {label:'Category', val:r=>esc(r.name)},
      {label:'Bookings', align:'right',val:r=>num(r.bookings)},
      {label:'Revenue',  align:'right',val:r=>money(r.revenue)},
      {label:'Avg Price',align:'right',val:r=>money(r.avg_price)},
    ]);

    // ── VENDOR PERFORMANCE ────────────────────────────────────────────────
    document.getElementById('an-vendor-perf').innerHTML = tableRows(d.vendor_perf,[
      {label:'Vendor',   val:r=>esc(r.vendor)},
      {label:'Assigned', align:'right',val:r=>num(r.assigned)},
      {label:'Published',align:'right',val:r=>'<span style="color:#10b981">'+num(r.published)+'</span>'},
      {label:'Avg Cost', align:'right',val:r=>money(r.avg_cost)},
    ]);

    // ── TOP CLIENTS ───────────────────────────────────────────────────────
    document.getElementById('an-top-clients').innerHTML = tableRows(d.top_clients,[
      {label:'Client',   val:r=>esc(r.client_name)},
      {label:'Bookings', align:'right',val:r=>num(r.bookings)},
      {label:'LTV',      align:'right',val:r=>'<strong style="color:#2A8AFA">'+money(r.lifetime_value)+'</strong>'},
    ]);

    // ── NEWSPAPER EFFICIENCY ──────────────────────────────────────────────
    document.getElementById('an-newspaper-eff').innerHTML = tableRows(d.newspaper_eff,[
      {label:'Newspaper', val:r=>esc(r.name)},
      {label:'Bookings',  align:'right',val:r=>num(r.bookings)},
      {label:'Avg Order', align:'right',val:r=>money(r.avg_order)},
      {label:'Margin',    align:'right',val:r=>'<span style="color:'+(parseFloat(r.avg_margin)>=20?'#10b981':'#f59e0b')+'">'+r.avg_margin+'%</span>'},
    ]);

    // ── OUTSTANDING AGING ─────────────────────────────────────────────────
    const ag = d.outstanding_aging?.[0]||{};
    document.getElementById('an-aging').innerHTML =
      '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">'
      +'<div style="background:#f0fdf4;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">0–7 days</div><div style="font-size:18px;font-weight:800;color:#15803d">'+money(ag.age_0_7)+'</div><div style="font-size:11px;color:#64748b">'+num(ag.cnt_0_7)+' bookings</div></div>'
      +'<div style="background:#fffbeb;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">8–30 days</div><div style="font-size:18px;font-weight:800;color:#d97706">'+money(ag.age_8_30)+'</div><div style="font-size:11px;color:#64748b">'+num(ag.cnt_8_30)+' bookings</div></div>'
      +'<div style="background:#fef2f2;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">30+ days</div><div style="font-size:18px;font-weight:800;color:#dc2626">'+money(ag.age_30plus)+'</div><div style="font-size:11px;color:#64748b">'+num(ag.cnt_30plus)+' bookings</div></div>'
      +'</div>';

    // ── CONVERSION & RETENTION ────────────────────────────────────────────
    document.getElementById('an-conversion').innerHTML =
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">'
      +'<div style="background:#ede9fe;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">Conversion Rate</div><div style="font-size:22px;font-weight:800;color:#2A8AFA">'+(d.conversion_rate||0)+'%</div><div style="font-size:11px;color:#64748b">leads → paid</div></div>'
      +'<div style="background:#fce7f3;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">Repeat Rate</div><div style="font-size:22px;font-weight:800;color:#db2777">'+(d.repeat_rate||0)+'%</div><div style="font-size:11px;color:#64748b">clients re-booked</div></div>'
      +'<div style="background:#f0fdf4;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">Total Leads</div><div style="font-size:22px;font-weight:800;color:#15803d">'+num(d.total_leads||0)+'</div><div style="font-size:11px;color:#64748b">unique clients</div></div>'
      +'<div style="background:#fef2f2;border-radius:10px;padding:14px;text-align:center"><div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;margin-bottom:6px">Rejection Rate</div><div style="font-size:22px;font-weight:800;color:#dc2626">'+(d.rejection_rate||0)+'%</div><div style="font-size:11px;color:#64748b">rejected/cancelled</div></div>'
      +'</div>';

  }).catch(e=>console.error('Analytics load error:',e));
}

document.addEventListener('DOMContentLoaded', anLoad);
})();
</script>

<script>
/* ── Analytics Upgrades ── */
function analSetPeriod(days) {
  var to = new Date();
  var from = new Date(to);
  from.setDate(from.getDate() - days);
  document.getElementById('anal-date-from').value = from.toISOString().slice(0,10);
  document.getElementById('anal-date-to').value   = to.toISOString().slice(0,10);
  analApplyDateRange();
}

function analApplyDateRange() {
  var from = document.getElementById('anal-date-from').value;
  var to   = document.getElementById('anal-date-to').value;
  if (!from || !to) return;
  var days = Math.ceil((new Date(to) - new Date(from)) / 86400000);
  // Reload analytics with custom period
  if (typeof loadAnalyticsOverview === 'function') loadAnalyticsOverview(days);
  if (typeof loadRevenueChart === 'function')      loadRevenueChart(days);
  if (typeof loadBookingChart === 'function')      loadBookingChart(days);
}

function analExport(type) {
  if (type === 'print') { window.print(); return; }
  var C = JSON.parse(document.getElementById('nas-admin-config').textContent);
  var fd = new FormData();
  fd.append('action','nas_admin_export_bookings_csv');
  fd.append('nonce', C.nonce);
  var from = document.getElementById('anal-date-from')?.value||'';
  var to   = document.getElementById('anal-date-to')?.value||'';
  if(from) fd.append('date_from', from);
  if(to)   fd.append('date_to', to);
  var _nc2=new AbortController();setTimeout(function(){_nc2.abort();},30000);
  fetch(C.ajaxUrl,{method:'POST',body:fd,signal:_nc2.signal}).then(r=>r.blob()).then(blob=>{
    var a=document.createElement('a');
    a.href=URL.createObjectURL(blob);
    a.download='nas-bookings-'+new Date().toISOString().slice(0,10)+'.csv';
    a.click();
  });
}

// Set default date range to last 30 days
(function() {
  var to = new Date();
  var from = new Date(to);
  from.setDate(from.getDate() - 30);
  var fromEl = document.getElementById('anal-date-from');
  var toEl   = document.getElementById('anal-date-to');
  if (fromEl) fromEl.value = from.toISOString().slice(0,10);
  if (toEl)   toEl.value   = to.toISOString().slice(0,10);
})();

// Render funnel after stats load
function renderFunnel(stats) {
  var funnel = document.getElementById('anal-funnel');
  if (!funnel || !stats) return;
  var stages = [
    {label:'Received', count: parseInt(stats.total_bookings||0), color:'#2A8AFA'},
    {label:'Payment',  count: parseInt(stats.paid_bookings||stats.total_revenue>0?Math.round(stats.total_bookings*.7):0), color:'#202C39'},
    {label:'Proof Sent',count:parseInt(stats.proof_sent||Math.round(stats.total_bookings*.55)), color:'#2A8AFA'},
    {label:'Published', count:parseInt(stats.completed_bookings||0), color:'#059669'},
  ];
  var max = Math.max(...stages.map(s=>s.count),1);
  funnel.innerHTML = stages.map(function(s,i) {
    var pct = Math.round((s.count/max)*100);
    var conv = i>0&&stages[i-1].count>0 ? Math.round((s.count/stages[i-1].count)*100)+'%' : '';
    return '<div style="flex:1;text-align:center">'+
      (conv?'<div style="font-size:11px;color:#94a3b8;margin-bottom:4px">→ '+conv+'</div>':'<div style="height:19px"></div>')+
      '<div style="background:'+s.color+';border-radius:6px 6px 0 0;margin:0 4px;min-height:20px;height:'+(pct*0.8+20)+'px;transition:height .6s ease"></div>'+
      '<div style="padding:6px 0;border-top:2px solid '+s.color+'">'+
      '<div style="font-size:16px;font-weight:800;color:#0f172a">'+s.count.toLocaleString()+'</div>'+
      '<div style="font-size:11px;color:#64748b">'+s.label+'</div></div></div>';
  }).join('');
}

// Intercept analytics overview response to render funnel
var _origLoad = window.loadAnalyticsOverview;
if (typeof _origLoad === 'function') {
  window.loadAnalyticsOverview = function(period) {
    var result = _origLoad.call(this, period);
    // The original function sets stats — hook after it
    setTimeout(function(){
      var stats = window._lastAnalyticsStats;
      if (stats) renderFunnel(stats);
    }, 800);
    return result;
  };
}
</script>
