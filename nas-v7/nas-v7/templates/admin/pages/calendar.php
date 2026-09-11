<?php
/**
 * NAS Admin — Content Calendar (Publication Schedule)
 * Shows bookings scheduled for publication date in a calendar grid
 */
if (!defined('ABSPATH')) exit;
$C = json_encode([
    'ajaxUrl'  => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('nas_action'),
    // FIX (audit): adminUrl was never included here, but calOpenDetail() references
    // CAL.adminUrl when linking to a booking's detail page — it was always undefined.
    'adminUrl' => add_query_arg('nas_admin', 'request', home_url('/admin-dashboard/')),
]);
?>
<style>
.nas-cal-wrap{padding:0}
.nas-cal-controls{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.nas-cal-nav{display:flex;align-items:center;gap:12px}
.nas-cal-month-label{font-size:20px;font-weight:800;color:#0f172a;min-width:180px;text-align:center}
.nas-cal-nav-btn{background:#fff;border:1.5px solid #e2e8f0;border-radius:8px;padding:8px 14px;cursor:pointer;font-size:16px;color:#374151;transition:all .15s}
.nas-cal-nav-btn:hover{border-color:#2A8AFA;color:#2A8AFA}
.nas-cal-legend{display:flex;gap:12px;flex-wrap:wrap;font-size:12px}
.nas-cal-legend-item{display:flex;align-items:center;gap:5px}
.nas-cal-legend-dot{width:10px;height:10px;border-radius:50%}
.nas-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;background:#e2e8f0;border-radius:12px;overflow:hidden}
.nas-cal-dow{background:#f8fafc;padding:10px;text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#64748b}
.nas-cal-cell{background:#fff;min-height:100px;padding:8px;position:relative;cursor:pointer;transition:background .15s;overflow:hidden}
.nas-cal-cell:hover{background:#f8fafc}
.nas-cal-cell.today{background:#f5f3ff!important}
.nas-cal-cell.today .nas-cal-day-num{background:#2A8AFA;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center}
.nas-cal-cell.other-month{background:#f8fafc;opacity:.5}
.nas-cal-cell.other-month *{color:#94a3b8!important}
.nas-cal-day-num{font-size:13px;font-weight:700;color:#0f172a;margin-bottom:5px;line-height:1}
.nas-cal-event{border-radius:4px;padding:2px 6px;font-size:10px;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer}
.nas-cal-event.status-pending{background:#FEF3C7;color:#92400E}
.nas-cal-event.status-confirmed{background:#DBEAFE;color:#1E40AF}
.nas-cal-event.status-published{background:#DCFCE7;color:#14532D}
.nas-cal-event.status-rejected{background:#FEE2E2;color:#7F1D1D}
.nas-cal-more{font-size:10px;color:#2A8AFA;font-weight:700;cursor:pointer;margin-top:2px}
/* Day detail panel */
.nas-cal-detail{position:fixed;top:0;right:0;width:380px;height:100vh;background:#fff;border-left:1.5px solid #e2e8f0;box-shadow:-8px 0 32px rgba(0,0,0,.1);z-index:800;transform:translateX(100%);transition:transform .3s ease;overflow-y:auto}
.nas-cal-detail.open{transform:translateX(0)}
.nas-cal-detail-head{background:#2A8AFA;color:#fff;padding:20px 24px;position:sticky;top:0;z-index:1}
.nas-cal-detail-date{font-size:20px;font-weight:800;margin-bottom:4px}
.nas-cal-detail-count{font-size:13px;opacity:.8}
.nas-cal-detail-close{position:absolute;top:16px;right:16px;background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:14px}
.nas-cal-booking-item{padding:14px 20px;border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .15s}
.nas-cal-booking-item:hover{background:#f8fafc}
.nas-cal-booking-uid{font-size:11px;font-weight:700;font-family:monospace;color:#2A8AFA;margin-bottom:3px}
.nas-cal-booking-name{font-size:13px;font-weight:600;color:#0f172a;margin-bottom:2px}
.nas-cal-booking-meta{font-size:11px;color:#94a3b8}
@media(max-width:768px){
  .nas-cal-grid{grid-template-columns:repeat(7,minmax(40px,1fr))}
  .nas-cal-cell{min-height:60px;padding:4px}
  .nas-cal-event{display:none}
  .nas-cal-detail{width:100vw}
}
</style>

<div class="nas-page-wrap">
<div class="nas-page-header">
  <h1 class="nas-page-title"><i class="fa-solid fa-calendar-days"></i> Publication Calendar</h1>
  <div style="display:flex;gap:10px">
    <button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="calExport()"><i class="fa-solid fa-download"></i> Export ICS</button>
    <a href="<?= esc_url(add_query_arg('nas_admin','requests')) ?>" class="nas-btn nas-btn-primary nas-btn-sm"><i class="fa-solid fa-list"></i> List View</a>
  </div>
</div>

<!-- Calendar controls -->
<div class="nas-cal-controls">
  <div class="nas-cal-nav">
    <button class="nas-cal-nav-btn" onclick="calPrevMonth()"><i class="fa-solid fa-chevron-left"></i></button>
    <div class="nas-cal-month-label" id="cal-month-label">—</div>
    <button class="nas-cal-nav-btn" onclick="calNextMonth()"><i class="fa-solid fa-chevron-right"></i></button>
    <button class="nas-cal-nav-btn" onclick="calToToday()" style="font-size:13px;padding:7px 14px">Today</button>
  </div>
  <div class="nas-cal-legend">
    <div class="nas-cal-legend-item"><div class="nas-cal-legend-dot" style="background:#FEF3C7;border:1px solid #F59E0B"></div>Pending</div>
    <div class="nas-cal-legend-item"><div class="nas-cal-legend-dot" style="background:#DBEAFE;border:1px solid #2563EB"></div>Confirmed</div>
    <div class="nas-cal-legend-item"><div class="nas-cal-legend-dot" style="background:#DCFCE7;border:1px solid #059669"></div>Published</div>
    <div class="nas-cal-legend-item"><div class="nas-cal-legend-dot" style="background:#FEE2E2;border:1px solid #DC2626"></div>Rejected</div>
  </div>
</div>

<!-- Calendar grid -->
<div class="nas-card" style="overflow:hidden">
  <div class="nas-cal-grid" id="nas-cal-grid">
    <div class="nas-cal-dow">Sun</div><div class="nas-cal-dow">Mon</div><div class="nas-cal-dow">Tue</div>
    <div class="nas-cal-dow">Wed</div><div class="nas-cal-dow">Thu</div><div class="nas-cal-dow">Fri</div>
    <div class="nas-cal-dow">Sat</div>
    <div style="grid-column:1/-1;padding:40px;text-align:center;color:#94a3b8">
      <div class="nas-spinner" style="margin:0 auto 12px"></div>Loading calendar…
    </div>
  </div>
</div>
</div>

<!-- Day detail panel -->
<div class="nas-cal-detail" id="nas-cal-detail">
  <div class="nas-cal-detail-head">
    <div class="nas-cal-detail-date" id="cal-detail-date">—</div>
    <div class="nas-cal-detail-count" id="cal-detail-count">— bookings</div>
    <button class="nas-cal-detail-close" onclick="calCloseDetail()">✕ Close</button>
  </div>
  <div id="cal-detail-body"></div>
</div>

<script>
var CAL = JSON.parse('<?= addslashes($C) ?>');
var calCurrentDate = new Date();
var calAllBookings = {};
var calMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

function calGetStatusClass(status) {
  if (['published','completed'].includes(status)) return 'status-published';
  if (['rejected','not_eligible','not_able_to_process'].includes(status)) return 'status-rejected';
  if (['payment_received','client_approved','ad_processing','submitted_to_pub'].includes(status)) return 'status-confirmed';
  return 'status-pending';
}

function calLoadMonth(year, month) {
  var from = year+'-'+String(month+1).padStart(2,'0')+'-01';
  var lastDay = new Date(year, month+1, 0).getDate();
  var to = year+'-'+String(month+1).padStart(2,'0')+'-'+lastDay;
  document.getElementById('cal-month-label').textContent = calMonthNames[month]+' '+year;
  document.getElementById('nas-cal-grid').innerHTML =
    '<div class="nas-cal-dow">Sun</div><div class="nas-cal-dow">Mon</div><div class="nas-cal-dow">Tue</div><div class="nas-cal-dow">Wed</div><div class="nas-cal-dow">Thu</div><div class="nas-cal-dow">Fri</div><div class="nas-cal-dow">Sat</div>'+
    '<div style="grid-column:1/-1;padding:40px;text-align:center;color:#94a3b8"><div class="nas-spinner" style="margin:0 auto 12px"></div>Loading…</div>';
  var fd=new FormData();fd.append('action','nas_admin_get_calendar_bookings');fd.append('nonce',CAL.nonce);fd.append('date_from',from);fd.append('date_to',to);
  var _nc1=new AbortController();setTimeout(function(){_nc1.abort();},30000);
  fetch(CAL.ajaxUrl,{method:'POST',body:fd,signal:_nc1.signal}).then(r=>r.json()).then(function(res){
    calAllBookings={};
    (res.data?.bookings||[]).forEach(function(b){
      var d=b.publish_date||b.submitted_at;
      if(d){var dk=d.slice(0,10);if(!calAllBookings[dk])calAllBookings[dk]=[];calAllBookings[dk].push(b);}
    });
    calRenderGrid(year, month);
  }).catch(function(){calRenderGrid(year,month);});
}

function calRenderGrid(year, month) {
  var today = new Date(); today.setHours(0,0,0,0);
  var firstDay = new Date(year, month, 1).getDay();
  var daysInMonth = new Date(year, month+1, 0).getDate();
  var prevDays = new Date(year, month, 0).getDate();
  var html = '<div class="nas-cal-dow">Sun</div><div class="nas-cal-dow">Mon</div><div class="nas-cal-dow">Tue</div><div class="nas-cal-dow">Wed</div><div class="nas-cal-dow">Thu</div><div class="nas-cal-dow">Fri</div><div class="nas-cal-dow">Sat</div>';

  // Prev month overflow
  for (var i = firstDay - 1; i >= 0; i--) {
    var d = prevDays - i;
    var prevDate = new Date(year, month-1, d);
    var dk = prevDate.toISOString().slice(0,10);
    html += calCellHtml(d, dk, 'other-month');
  }

  // This month
  for (var d = 1; d <= daysInMonth; d++) {
    var thisDate = new Date(year, month, d); thisDate.setHours(0,0,0,0);
    var dk = thisDate.toISOString().slice(0,10);
    var cls = thisDate.getTime() === today.getTime() ? 'today' : '';
    html += calCellHtml(d, dk, cls);
  }

  // Next month overflow (fill to 42 cells)
  var totalCells = firstDay + daysInMonth;
  var nextDays = totalCells < 35 ? 35 - totalCells : 42 - totalCells;
  if (nextDays < 0) nextDays = 0;
  for (var d = 1; d <= nextDays; d++) {
    var nextDate = new Date(year, month+1, d);
    var dk = nextDate.toISOString().slice(0,10);
    html += calCellHtml(d, dk, 'other-month');
  }

  document.getElementById('nas-cal-grid').innerHTML = html;
}

function calCellHtml(dayNum, dateKey, extraClass) {
  var bookings = calAllBookings[dateKey] || [];
  var evHtml = '';
  var maxShow = 3;
  bookings.slice(0, maxShow).forEach(function(b) {
    var sc = calGetStatusClass(b.status);
    evHtml += '<div class="nas-cal-event '+sc+'" title="'+b.uid+' — '+b.client_name+'" onclick="event.stopPropagation();calOpenDetail(\''+dateKey+'\')">'+b.uid+'</div>';
  });
  if (bookings.length > maxShow) {
    evHtml += '<div class="nas-cal-more" onclick="event.stopPropagation();calOpenDetail(\''+dateKey+'\')" >+' + (bookings.length - maxShow) + ' more</div>';
  }
  var total = bookings.length ? '<span style="position:absolute;top:5px;right:6px;background:#2A8AFA;color:#fff;border-radius:99px;font-size:9px;font-weight:700;padding:1px 5px">'+bookings.length+'</span>' : '';
  return '<div class="nas-cal-cell '+extraClass+'" onclick="calOpenDetail(\''+dateKey+'\')">'
    +'<div class="nas-cal-day-num">'+dayNum+'</div>'
    +total+evHtml+'</div>';
}

window.calOpenDetail = function(dateKey) {
  var bookings = calAllBookings[dateKey] || [];
  var d = new Date(dateKey+'T00:00:00');
  document.getElementById('cal-detail-date').textContent = d.toLocaleDateString('en-IN',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
  document.getElementById('cal-detail-count').textContent = bookings.length + (bookings.length === 1 ? ' booking' : ' bookings') + ' scheduled';
  var body = document.getElementById('cal-detail-body');
  if (!bookings.length) {
    body.innerHTML = '<div style="text-align:center;padding:40px;color:#94a3b8"><div style="font-size:36px;margin-bottom:10px">📅</div><p>No bookings on this date.</p></div>';
  } else {
    body.innerHTML = bookings.map(function(b) {
      var sc = calGetStatusClass(b.status);
      return '<div class="nas-cal-booking-item" onclick="window.location.href=\''+CAL.adminUrl+'&id='+b.id+'\'">'
        +'<div class="nas-cal-booking-uid">'+b.uid+'</div>'
        +'<div class="nas-cal-booking-name">'+b.client_name+'</div>'
        +'<div class="nas-cal-booking-meta">'+b.newspaper_name+' · '+b.city_name
        +'<span style="margin-left:8px;background:'+sc.replace('status-pending','#FEF3C7').replace('status-confirmed','#DBEAFE').replace('status-published','#DCFCE7').replace('status-rejected','#FEE2E2')+';border-radius:99px;padding:1px 8px;font-size:10px;font-weight:700;color:#374151">'+b.status.replace(/_/g,' ')+'</span></div>'
        +'</div>';
    }).join('');
  }
  document.getElementById('nas-cal-detail').classList.add('open');
};

window.calCloseDetail = function() {
  document.getElementById('nas-cal-detail').classList.remove('open');
};
window.calPrevMonth = function() { calCurrentDate.setMonth(calCurrentDate.getMonth()-1); calLoadMonth(calCurrentDate.getFullYear(),calCurrentDate.getMonth()); };
window.calNextMonth = function() { calCurrentDate.setMonth(calCurrentDate.getMonth()+1); calLoadMonth(calCurrentDate.getFullYear(),calCurrentDate.getMonth()); };
window.calToToday  = function() { calCurrentDate=new Date(); calLoadMonth(calCurrentDate.getFullYear(),calCurrentDate.getMonth()); };
window.calExport = function() {
  // FIX (audit): this was a stub — alert('ICS export: generates a downloadable calendar
  // file...') described the feature instead of implementing it. Building a real .ics file from
  // the bookings already loaded for the visible month (calAllBookings) — no backend call needed.
  var lines = ['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//NAS//Publication Calendar//EN'];
  var any = false;
  Object.keys(calAllBookings).forEach(function(dateKey){
    calAllBookings[dateKey].forEach(function(b){
      any = true;
      var dt = dateKey.replace(/-/g,'');
      lines.push('BEGIN:VEVENT');
      lines.push('UID:'+b.uid+'@nas-booking');
      lines.push('DTSTART;VALUE=DATE:'+dt);
      lines.push('DTEND;VALUE=DATE:'+dt);
      lines.push('SUMMARY:'+(b.uid||'')+' — '+(b.client_name||'').replace(/[\r\n]/g,' '));
      lines.push('DESCRIPTION:'+(b.newspaper_name||'')+' · '+(b.city_name||'')+' · Status: '+(b.status||'').replace(/_/g,' '));
      lines.push('END:VEVENT');
    });
  });
  lines.push('END:VCALENDAR');
  if (!any) { nasAdminToast ? nasAdminToast('No bookings in the current view to export.','error') : alert('No bookings in the current view to export.'); return; }
  var blob = new Blob([lines.join('\r\n')], {type:'text/calendar'});
  var a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'publication-calendar-'+calCurrentDate.getFullYear()+'-'+String(calCurrentDate.getMonth()+1).padStart(2,'0')+'.ics';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
};

document.addEventListener('DOMContentLoaded', function() {
  calLoadMonth(calCurrentDate.getFullYear(), calCurrentDate.getMonth());
});
</script>
