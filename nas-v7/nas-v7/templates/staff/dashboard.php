<?php
/**
 * NAS Staff Dashboard v4 — Tab-based, enterprise design, no side panel
 */
if (!defined('ABSPATH')) exit;
if (!is_user_logged_in()) {
    wp_redirect(home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/staff-dashboard/'))));
    exit;
}

$user  = wp_get_current_user();
$av    = strtoupper(substr($user->display_name, 0, 1) ?: 'S');
$nonce = wp_create_nonce('nas_action');
$ajax  = function_exists('nas_get_ajax_url')
    ? nas_get_ajax_url( (int) get_option( 'nas_page_staff_dashboard' ) ?: null )
    : admin_url('admin-ajax.php');

$db    = \NAS\Core\Database::instance();
$uid   = get_current_user_id();
$t     = $db->t('bookings');

// Live performance stats
$stats = [
    'today'     => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE DATE(submitted_at)=CURDATE() AND assigned_to=%d", $uid),
    'week'      => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE YEARWEEK(submitted_at,1)=YEARWEEK(NOW(),1) AND assigned_to=%d", $uid),
    'month'     => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE MONTH(submitted_at)=MONTH(NOW()) AND YEAR(submitted_at)=YEAR(NOW()) AND assigned_to=%d", $uid),
    'pending'   => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE status NOT IN ('published','completed','rejected','cancelled') AND assigned_to=%d", $uid),
    'completed' => (int)$db->scalar("SELECT COUNT(*) FROM $t WHERE status IN ('published','completed') AND assigned_to=%d", $uid),
    'avg_hrs'   => (float)($db->scalar("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR,submitted_at,updated_at)),0) FROM $t WHERE status='published' AND assigned_to=%d", $uid) ?? 0),
];

// Top nav
$nav_links = [];
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<div id="nas-staff-dashboard">
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Staff Dashboard — <?= esc_html(get_bloginfo('name')) ?></title>
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-core.css">
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-dashboard.css">
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-chat.css">
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-enterprise.css">
<link rel="stylesheet" href="<?= NAS_ASSETS ?>css/nas-portal-app.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
/* ── Staff dashboard ── */
/* 16-G-5: Scoped to #nas-staff-dashboard to prevent bleed into WP theme */
#nas-staff-dashboard *{box-sizing:border-box}
#nas-staff-dashboard{font-family:'Inter','Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#0f172a;min-height:100vh}
@media(max-width:1023px){#nas-staff-dashboard{padding-bottom:calc(64px + env(safe-area-inset-bottom,0px))}}
.sp-wrap{max-width:1200px;margin:0 auto;padding:0 20px 60px}

/* Top hero */
.sp-hero{background:linear-gradient(135deg,#202C39 0%,#3730a3 60%,#4f46e5 100%);padding:28px 24px 0;margin-bottom:0}
.sp-hero-inner{max-width:1200px;margin:0 auto}
.sp-hero-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.sp-hero-greeting{color:#fff}
.sp-hero-greeting h1{font-size:22px;font-weight:800;margin-bottom:2px}
.sp-hero-greeting p{font-size:13px;opacity:.7}
.sp-hero-actions{display:flex;gap:8px}
.sp-hero-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;text-decoration:none;border:1.5px solid rgba(255,255,255,.3);color:#fff;background:rgba(255,255,255,.1);transition:all .15s;cursor:pointer}
.sp-hero-btn:hover{background:rgba(255,255,255,.2)}

/* Stats row */
.sp-stats{display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:0}
.sp-stat{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:12px 12px 0 0;padding:14px 16px;text-align:left}
.sp-stat-val{font-size:26px;font-weight:800;color:#fff;line-height:1;margin-bottom:3px}
.sp-stat-lbl{font-size:10px;color:rgba(255,255,255,.6);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
@media(max-width:768px){.sp-stats{grid-template-columns:repeat(3,1fr)}.sp-stat{border-radius:8px;margin-bottom:2px}}
@media(max-width:480px){.sp-stats{grid-template-columns:repeat(2,1fr)}}

/* Tab bar */
.sp-tabs{background:#fff;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.06)}
.sp-tabs-inner{max-width:1200px;margin:0 auto;padding:0 20px;display:flex;gap:0;overflow-x:auto;-webkit-overflow-scrolling:touch}
.sp-tabs-inner::-webkit-scrollbar{display:none}
.sp-tab{padding:14px 20px;font-size:13px;font-weight:700;color:#64748b;border:none;background:none;cursor:pointer;border-bottom:3px solid transparent;margin-bottom:-1px;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:7px;flex-shrink:0}
.sp-tab:hover{color:#374151;background:#f8fafc}
.sp-tab.active{color:#2A8AFA;border-bottom-color:#2A8AFA;background:#fafafe}
.sp-tab-count{background:#f1f5f9;color:#64748b;font-size:10px;font-weight:700;padding:1px 7px;border-radius:99px;min-width:18px;text-align:center}
.sp-tab.active .sp-tab-count{background:#ede9fe;color:#2A8AFA}

/* Content panels */
.sp-panel{display:none;padding:24px 0}
.sp-panel.active{display:block}

/* Cards */
.sp-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:16px}
.sp-card-head{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.sp-card-title{font-size:15px;font-weight:700;color:#0f172a}
.sp-card-body{padding:0}

/* Filters row */
.sp-filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.sp-input{padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s;color:#0f172a;background:#fff}
.sp-input:focus{border-color:#2A8AFA}

/* Booking rows */
.sp-bk-row{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .12s}
.sp-bk-row:last-child{border-bottom:none}
.sp-bk-row:hover{background:#fafafe}
.sp-bk-uid{font-size:11px;font-weight:700;font-family:monospace;color:#2A8AFA;margin-bottom:2px}
.sp-bk-meta{font-size:13px;font-weight:600;color:#0f172a;margin-bottom:2px}
.sp-bk-sub{font-size:11px;color:#94a3b8}
.sp-bk-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-shrink:0}
.sp-badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}

/* Task rows */
.sp-task-row{display:flex;align-items:flex-start;gap:14px;padding:14px 20px;border-bottom:1px solid #f8fafc}
.sp-task-row:last-child{border-bottom:none}
.sp-task-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.sp-task-body{flex:1}
.sp-task-title{font-size:13px;font-weight:700;color:#0f172a;margin-bottom:2px}
.sp-task-meta{font-size:11px;color:#94a3b8}

/* Buttons */
.sp-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid transparent;font-family:inherit}
.sp-btn-primary{background:#2A8AFA;color:#fff;border-color:#2A8AFA}
.sp-btn-primary:hover{background:#5b3dd4}
.sp-btn-ghost{background:#fff;color:#374151;border-color:#e2e8f0}
.sp-btn-ghost:hover{border-color:#94a3b8;background:#f8fafc}
.sp-btn-sm{padding:5px 12px;font-size:12px}
.sp-btn-success{background:#059669;color:#fff;border-color:#059669}

/* Drawer */
.sp-drawer-overlay{position:fixed;inset:0;background:rgba(15,23,42,.4);z-index:800;opacity:0;pointer-events:none;transition:opacity .25s;backdrop-filter:blur(2px)}
.sp-drawer-overlay.open{opacity:1;pointer-events:all}
.sp-drawer{position:fixed;top:0;right:0;width:480px;max-width:100vw;height:100vh;background:#fff;z-index:900;transform:translateX(100%);transition:transform .3s cubic-bezier(.32,.72,0,1);display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,.12)}
.sp-drawer.open{transform:translateX(0)}
.sp-drawer-head{padding:16px 20px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
.sp-drawer-title{font-size:16px;font-weight:800;color:#0f172a}
.sp-drawer-close{background:none;border:none;font-size:20px;cursor:pointer;color:#94a3b8;padding:0;line-height:1;transition:color .15s}
.sp-drawer-close:hover{color:#374151}
.sp-drawer-tabs{display:flex;border-bottom:1px solid #e2e8f0;flex-shrink:0;overflow-x:auto}
.sp-drawer-tab{padding:11px 18px;font-size:12px;font-weight:700;color:#94a3b8;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .15s;white-space:nowrap;display:flex;align-items:center;gap:6px}
.sp-drawer-tab:hover{color:#374151}
.sp-drawer-tab.active{color:#2A8AFA;border-bottom-color:#2A8AFA}
.sp-drawer-body{flex:1;overflow-y:auto}
.sp-drawer-panel{display:none;padding:20px}
.sp-drawer-panel.active{display:block}

/* Data rows */
.sp-data-rows{background:#f8fafc;border-radius:10px;overflow:hidden;border:1px solid #f1f5f9}
.sp-data-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-bottom:1px solid #f1f5f9;font-size:13px}
.sp-data-row:last-child{border-bottom:none}
.sp-data-row span{color:#64748b}
.sp-data-row strong{color:#0f172a}

/* Empty / loading */
.sp-empty{text-align:center;padding:56px 24px;color:#94a3b8}
.sp-empty-icon{font-size:48px;margin-bottom:14px;display:block;opacity:.4}
.sp-empty h3{font-size:16px;font-weight:700;color:#374151;margin-bottom:6px}
.sp-empty p{font-size:13px;line-height:1.6}
.sp-spinner{width:28px;height:28px;border:3px solid #e2e8f0;border-top-color:#2A8AFA;border-radius:50%;animation:spSpin .7s linear infinite;margin:0 auto}
@keyframes spSpin{to{transform:rotate(360deg)}}
.sp-loading{padding:40px;text-align:center}

/* Pagination */
.sp-pages{display:flex;justify-content:center;gap:6px;margin-top:16px;flex-wrap:wrap}
.sp-page-btn{min-width:36px;padding:6px 10px;border-radius:7px;border:1.5px solid #e2e8f0;background:#fff;font-size:13px;font-weight:600;color:#374151;cursor:pointer;transition:all .15s}
.sp-page-btn:hover{border-color:#2A8AFA;color:#2A8AFA}
.sp-page-btn.active{background:#2A8AFA;border-color:#2A8AFA;color:#fff}

@media(max-width:600px){
  .sp-drawer{width:100vw}
  .sp-tab{padding:12px 14px;font-size:12px}
  .sp-bk-row{flex-wrap:wrap}
}
</style>

<!-- Performance hero -->
<div class="sp-hero">
  <div class="sp-hero-inner">
    <div class="sp-hero-top">
      <div class="sp-hero-greeting">
        <h1>👋 Hello, <?= esc_html(explode(' ', $user->display_name)[0]) ?></h1>
        <p>Staff Portal · <?= date('l, d M Y') ?></p>
      </div>
      <div class="sp-hero-actions">
        <a href="<?= esc_url(home_url('/admin-dashboard/')) ?>" class="sp-hero-btn">
          <i class="fa-solid fa-shield-halved"></i> Admin Panel
        </a>
        <a href="<?= esc_url(wp_logout_url(home_url('/'))) ?>" class="sp-hero-btn">
          <i class="fa-solid fa-right-from-bracket"></i> Logout
        </a>
      </div>
    </div>

    <div class="sp-stats">
      <?php
      $metrics = [
        ['Today',     $stats['today'],     '📅'],
        ['This Week', $stats['week'],      '📆'],
        ['This Month',$stats['month'],     '🗓️'],
        ['Pending',   $stats['pending'],   '⏳'],
        ['Completed', $stats['completed'], '✅'],
        ['Avg Hours', number_format($stats['avg_hrs'],1).'h', '⏱️'],
      ];
      foreach ($metrics as $m): ?>
      <div class="sp-stat">
        <div class="sp-stat-val"><?= esc_html($m[1]) ?></div>
        <div class="sp-stat-lbl"><?= esc_html($m[0]) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- Tab bar -->
<div class="sp-tabs">
  <div class="sp-tabs-inner">
    <button class="sp-tab active" data-tab="bookings" onclick="spSwitchTab('bookings',this)">
      <i class="fa-solid fa-list-check"></i> My Bookings
      <span class="sp-tab-count" id="sp-tab-count-bookings"><?= $stats['pending'] ?></span>
    </button>
    <button class="sp-tab" data-tab="tasks" onclick="spSwitchTab('tasks',this)">
      <i class="fa-solid fa-clock-rotate-left"></i> Today's Tasks
    </button>
    <button class="sp-tab" data-tab="search" onclick="spSwitchTab('search',this)">
      <i class="fa-solid fa-magnifying-glass"></i> Quick Search
    </button>
  </div>
</div>

<!-- Main content -->
<div class="sp-wrap">

  <!-- BOOKINGS TAB -->
  <div class="sp-panel active" id="sp-tab-bookings">
    <div class="sp-card">
      <div class="sp-card-head">
        <div class="sp-card-title">Assigned Bookings</div>
        <div class="sp-filters">
          <input type="text" id="sp-search" class="sp-input" placeholder="Search order, client…" style="max-width:200px" oninput="spDebounce()">
          <select id="sp-status" class="sp-input" onchange="spLoadBookings(1)">
            <option value="">All Statuses</option>
            <option value="booking_received">Received</option>
            <option value="under_review">Under Review</option>
            <option value="payment_received">Payment Received</option>
            <option value="proof_ready">Proof Ready</option>
            <option value="sent_to_vendor">Sent to Vendor</option>
            <option value="published">Published</option>
          </select>
          <button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="spLoadBookings(1)">
            <i class="fa-solid fa-rotate"></i>
          </button>
        </div>
      </div>
      <div class="sp-card-body" id="sp-bookings-list">
        <div class="sp-loading"><div class="sp-spinner"></div></div>
      </div>
    </div>
    <div class="sp-pages" id="sp-pages"></div>
  </div>

  <!-- TASKS TAB -->
  <div class="sp-panel" id="sp-tab-tasks">
    <div class="sp-card">
      <div class="sp-card-head">
        <div class="sp-card-title">Today's Follow-up Tasks</div>
        <button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="spLoadTasks()">
          <i class="fa-solid fa-rotate"></i> Refresh
        </button>
      </div>
      <div class="sp-card-body" id="sp-tasks-list">
        <div class="sp-loading"><div class="sp-spinner"></div></div>
      </div>
    </div>
  </div>

  <!-- SEARCH TAB -->
  <div class="sp-panel" id="sp-tab-search">
    <div class="sp-card">
      <div class="sp-card-head">
        <div class="sp-card-title">Quick Search — Any Booking</div>
      </div>
      <div style="padding:20px">
        <div style="display:flex;gap:10px;margin-bottom:16px">
          <input type="text" id="sp-global-search" class="sp-input" placeholder="Order ID, client name, phone, email…"
            style="flex:1" onkeydown="if(event.key==='Enter')spGlobalSearch()">
          <button class="sp-btn sp-btn-primary" onclick="spGlobalSearch()">
            <i class="fa-solid fa-magnifying-glass"></i> Search
          </button>
        </div>
        <div id="sp-search-results">
          <div class="sp-empty">
            <span class="sp-empty-icon">🔍</span>
            <p>Enter a search term above to find any booking</p>
          </div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /sp-wrap -->

<!-- BOOKING DETAIL DRAWER -->
<div class="sp-drawer-overlay" id="sp-overlay" onclick="spCloseDrawer()"></div>
<aside class="sp-drawer" id="sp-drawer" aria-label="Booking details" role="dialog">
  <div class="sp-drawer-head">
    <div class="sp-drawer-title" id="sp-drawer-title">Booking Details</div>
    <button class="sp-drawer-close" onclick="spCloseDrawer()" aria-label="Close">✕</button>
  </div>
  <div class="sp-drawer-tabs">
    <button class="sp-drawer-tab active" onclick="spDTab('info',this)">
      <i class="fa-solid fa-circle-info"></i> Info
    </button>
    <button class="sp-drawer-tab" onclick="spDTab('chat',this)">
      <i class="fa-solid fa-comments"></i> Chat
    </button>
    <button class="sp-drawer-tab" onclick="spDTab('actions',this)">
      <i class="fa-solid fa-gears"></i> Actions
    </button>
  </div>
  <div class="sp-drawer-body">
    <div class="sp-drawer-panel active" id="sp-dp-info"></div>
    <div class="sp-drawer-panel" id="sp-dp-chat"></div>
    <div class="sp-drawer-panel" id="sp-dp-actions"></div>
  </div>
</aside>

<!-- Simple centered success/info toast (small, unobtrusive) -->
<div id="sp-toast-container" style="position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;gap:8px;pointer-events:none;align-items:center" aria-live="polite"></div>

<script>
(function(){
'use strict';
var NONCE='<?= esc_js($nonce) ?>';
var AJAX='<?= esc_js($ajax) ?>';
var ADMIN_URL='<?= esc_js(home_url('/admin-dashboard/?nas_admin=request&id=')) ?>';
var spCurrentId=0, spChatTimer=null, spPage=1, spDebTimer=null;

/* ── Helpers ── */
function esc(t){return(t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function money(n){return'₹'+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fdate(d){if(!d)return'—';try{return new Date(d.replace(' ','T')).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;}}

function ajax(action,data){
  var fd=new FormData();
  fd.append('action',action); fd.append('nas_action','1');fd.append('nonce',NONCE);
  Object.entries(data||{}).forEach(function(e){fd.append(e[0],e[1]??'');});
  return fetch(AJAX,{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(r){if(!r.success)throw new Error(r.data?.message||r.data||'Error');return r.data;});
}

function toast(msg, type) {
  var container = document.getElementById('sp-toast-container');
  if (!container) return;
  var colors = {success:'#059669', error:'#dc2626', info:'#2563eb', warning:'#d97706'};
  var icons  = {success:'✅', error:'❌', info:'ℹ️', warning:'⚠️'};
  var t = type || 'info';
  var el = document.createElement('div');
  el.style.cssText = [
    'display:inline-flex;align-items:center;gap:10px',
    'background:#fff;border-radius:10px;padding:12px 18px',
    'box-shadow:0 4px 20px rgba(0,0,0,.12);border-left:4px solid '+(colors[t]||'#2A8AFA'),
    'font-size:13px;font-weight:600;color:#0f172a;pointer-events:all',
    'max-width:340px;animation:spToastIn .3s ease'
  ].join(';');
  el.innerHTML = '<span style="font-size:16px">'+(icons[t]||'ℹ️')+'</span><span>'+esc(msg)+'</span>';
  container.appendChild(el);
  setTimeout(function(){
    el.style.opacity='0';el.style.transform='scale(.9)';el.style.transition='all .25s';
    setTimeout(function(){el.remove();},250);
  }, 3500);
}

var style = document.createElement('style');
style.textContent = '@keyframes spToastIn{from{opacity:0;transform:translateY(-10px) scale(.95)}to{opacity:1;transform:translateY(0) scale(1)}}';
document.head.appendChild(style);

function badge(s) {
  var map = {
    booking_received:{c:'#6b7280',l:'Received'},
    under_review:{c:'#3b82f6',l:'Under Review'},
    payment_received:{c:'#10b981',l:'Payment Received'},
    proof_ready:{c:'#202C39',l:'Proof Ready'},
    sent_to_vendor:{c:'#06b6d4',l:'Sent to Vendor'},
    published:{c:'#059669',l:'Published'},
    completed:{c:'#059669',l:'Completed'},
    rejected:{c:'#ef4444',l:'Rejected'},
  };
  var m = map[s] || {c:'#94a3b8', l:(s||'').replace(/_/g,' ')};
  return '<span class="sp-badge" style="background:'+m.c+'18;color:'+m.c+'">'+esc(m.l)+'</span>';
}

/* ── Tabs ── */
window.spSwitchTab = function(name, btn) {
  document.querySelectorAll('.sp-tab').forEach(function(b){b.classList.remove('active');});
  document.querySelectorAll('.sp-panel').forEach(function(p){p.classList.remove('active');});
  if(btn) btn.classList.add('active');
  var panel = document.getElementById('sp-tab-'+name);
  if(panel) panel.classList.add('active');
  if(name==='tasks')  spLoadTasks();
  if(name==='search') document.getElementById('sp-global-search')?.focus();
};

/* ── Bookings ── */
window.spDebounce = function(){clearTimeout(spDebTimer);spDebTimer=setTimeout(function(){spLoadBookings(1);},350);};

window.spLoadBookings = function(page) {
  spPage = page || 1;
  var el  = document.getElementById('sp-bookings-list');
  var pEl = document.getElementById('sp-pages');
  el.innerHTML = '<div class="sp-loading"><div class="sp-spinner"></div></div>';
  if(pEl) pEl.innerHTML = '';

  var search = document.getElementById('sp-search')?.value || '';
  var status = document.getElementById('sp-status')?.value || '';

  ajax('nas_get_assigned_bookings', {page:spPage, per_page:15, search:search, status:status})
    .then(function(d) {
      var rows = d.bookings || [];
      document.getElementById('sp-tab-count-bookings').textContent = d.total || rows.length;

      if (!rows.length) {
        el.innerHTML = '<div class="sp-empty"><span class="sp-empty-icon">📋</span><h3>No bookings assigned</h3><p>Bookings assigned to you will appear here.</p></div>';
        return;
      }

      el.innerHTML = rows.map(function(b) {
        var uid = b.booking_uid || b.uid || b.id;
        return '<div class="sp-bk-row" onclick="spOpenDrawer('+b.id+')" role="button" tabindex="0" aria-label="Open booking '+esc(uid)+'" onkeydown="if(event.key===\'Enter\')spOpenDrawer('+b.id+')">' +
          '<div style="flex:1;min-width:0">' +
          '<div class="sp-bk-uid">#'+esc(uid)+'</div>' +
          '<div class="sp-bk-meta">'+esc(b.client_name||'—')+'</div>' +
          '<div class="sp-bk-sub">'+esc(b.newspaper_name||'—')+' · '+esc(b.city_name||'—')+' · '+fdate(b.submitted_at||b.created_at)+'</div>' +
          '</div>' +
          '<div class="sp-bk-right">' +
          badge(b.status) +
          '<span style="font-size:13px;font-weight:700;color:#059669">'+money(b.total_amount)+'</span>' +
          '<button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="event.stopPropagation();spOpenDrawer('+b.id+')">View →</button>' +
          '</div></div>';
      }).join('');

      // Pagination
      var pages = Math.ceil((d.total||rows.length) / 15);
      if (pEl && pages > 1) {
        for (var i = 1; i <= pages; i++) {
          var btn = document.createElement('button');
          btn.className = 'sp-page-btn' + (i === spPage ? ' active' : '');
          btn.textContent = i;
          (function(pg){ btn.onclick = function(){ spLoadBookings(pg); }; })(i);
          pEl.appendChild(btn);
        }
      }
    })
    .catch(function(e) {
      el.innerHTML = '<div style="padding:20px;color:#dc2626;font-size:13px;text-align:center">⚠️ '+esc(e.message)+'</div>';
    });
};

/* ── Tasks ── */
window.spLoadTasks = function() {
  var el = document.getElementById('sp-tasks-list');
  el.innerHTML = '<div class="sp-loading"><div class="sp-spinner"></div></div>';

  ajax('nas_get_today_tasks', {}).then(function(d) {
    var tasks = d.tasks || [];
    if (!tasks.length) {
      el.innerHTML = '<div class="sp-empty"><span class="sp-empty-icon">✅</span><h3>All caught up!</h3><p>No follow-up tasks for today.</p></div>';
      return;
    }
    el.innerHTML = tasks.map(function(t) {
      var urgency = t.priority === 'high' ? '#dc2626' : t.priority === 'medium' ? '#d97706' : '#6b7280';
      return '<div class="sp-task-row" id="spt-'+t.id+'">' +
        '<div class="sp-task-icon" style="background:'+urgency+'18">'+
        '<i class="fa-solid fa-clipboard-list" style="color:'+urgency+'"></i></div>' +
        '<div class="sp-task-body">' +
        '<div class="sp-task-title">'+esc(t.title||'Follow Up')+'</div>' +
        '<div class="sp-task-meta">Order #'+esc(t.booking_uid||'—')+' · '+esc(t.client_name||'—')+'</div>' +
        '</div>' +
        '<button class="sp-btn sp-btn-success sp-btn-sm" onclick="spDoneTask('+t.id+',this)">' +
        '<i class="fa-solid fa-check"></i> Done</button>' +
        '</div>';
    }).join('');
  }).catch(function(e) {
    el.innerHTML = '<div style="padding:20px;color:#dc2626;text-align:center;font-size:13px">⚠️ '+esc(e.message)+'</div>';
  });
};

window.spDoneTask = function(id, btn) {
  btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
  ajax('nas_mark_task_done', {task_id:id}).then(function() {
    var row = document.getElementById('spt-'+id);
    if (row) { row.style.transition='all .3s'; row.style.opacity='0'; row.style.transform='translateX(20px)'; setTimeout(function(){row.remove();},300); }
    toast('Task completed!', 'success');
  }).catch(function(e){ btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-check"></i> Done'; toast(e.message,'error'); });
};

/* ── Global search ── */
window.spGlobalSearch = function() {
  var q   = document.getElementById('sp-global-search')?.value.trim();
  var el  = document.getElementById('sp-search-results');
  if (!q) { toast('Please enter a search term','info'); return; }
  el.innerHTML = '<div class="sp-loading"><div class="sp-spinner"></div></div>';

  ajax('nas_get_all_bookings', {search:q, per_page:20}).then(function(d) {
    var rows = d.bookings || [];
    if (!rows.length) {
      el.innerHTML = '<div class="sp-empty"><span class="sp-empty-icon">🔍</span><h3>No results</h3><p>No bookings matched "'+esc(q)+'"</p></div>';
      return;
    }
    el.innerHTML = rows.map(function(b) {
      var uid = b.booking_uid||b.uid||b.id;
      return '<div class="sp-bk-row" onclick="spOpenDrawer('+b.id+')" style="cursor:pointer">' +
        '<div style="flex:1">' +
        '<div class="sp-bk-uid">#'+esc(uid)+'</div>' +
        '<div class="sp-bk-meta">'+esc(b.client_name||'—')+'</div>' +
        '<div class="sp-bk-sub">'+esc(b.newspaper_name||'—')+' · '+esc(b.city_name||'—')+' · '+fdate(b.submitted_at||b.created_at)+'</div>' +
        '</div>' +
        '<div class="sp-bk-right">'+badge(b.status)+'<button class="sp-btn sp-btn-ghost sp-btn-sm" onclick="event.stopPropagation();spOpenDrawer('+b.id+')">View →</button></div>' +
        '</div>';
    }).join('');
  }).catch(function(e){ el.innerHTML='<div style="padding:20px;color:#dc2626;text-align:center">⚠️ '+esc(e.message)+'</div>'; });
};

/* ── Drawer ── */
window.spOpenDrawer = function(id) {
  spCurrentId = id;
  document.getElementById('sp-overlay').classList.add('open');
  document.getElementById('sp-drawer').classList.add('open');
  document.body.style.overflow = 'hidden';
  // Reset tabs
  document.querySelectorAll('.sp-drawer-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.sp-drawer-panel').forEach(function(p){p.classList.remove('active');});
  document.querySelector('.sp-drawer-tab').classList.add('active');
  document.getElementById('sp-dp-info').classList.add('active');
  spLoadInfo(id);
};

window.spCloseDrawer = function() {
  document.getElementById('sp-overlay').classList.remove('open');
  document.getElementById('sp-drawer').classList.remove('open');
  document.body.style.overflow = '';
  if (spChatTimer) { clearInterval(spChatTimer); spChatTimer = null; }
};

window.spDTab = function(tab, btn) {
  document.querySelectorAll('.sp-drawer-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('.sp-drawer-panel').forEach(function(p){p.classList.remove('active');});
  if (btn) btn.classList.add('active');
  var panel = document.getElementById('sp-dp-'+tab);
  if (panel) panel.classList.add('active');
  if (tab === 'chat')    { if(spChatTimer)clearInterval(spChatTimer); spLoadChat(spCurrentId); spChatTimer=setInterval(function(){spRefreshChat(spCurrentId);},5000); }
  if (tab === 'actions') spLoadActions(spCurrentId);
  if (tab === 'info')    { if(spChatTimer){clearInterval(spChatTimer);spChatTimer=null;} }
};

// Keyboard close
document.addEventListener('keydown', function(e){ if(e.key==='Escape') spCloseDrawer(); });

function spLoadInfo(id) {
  var el = document.getElementById('sp-dp-info');
  el.innerHTML = '<div class="sp-loading"><div class="sp-spinner"></div></div>';

  ajax('nas_get_booking_detail', {id:id}).then(function(raw) {
    var b = raw.booking || raw;
    document.getElementById('sp-drawer-title').textContent = 'Order #'+(b.uid||b.booking_uid||b.id);

    var proofHtml = b.proof_url
      ? '<div style="margin-top:16px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px">Proof</div><a href="'+esc(b.proof_url)+'" target="_blank"><img src="'+esc(b.proof_url)+'" alt="Proof" style="max-width:100%;border-radius:10px;border:1px solid #e2e8f0"></a></div>'
      : '';
    var contentHtml = b.ad_content
      ? '<div style="margin-top:14px"><div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:6px">Ad Content</div><div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;font-size:13px;line-height:1.6;white-space:pre-wrap">'+esc(b.ad_content)+'</div></div>'
      : '';

    el.innerHTML =
      '<div style="margin-bottom:14px">'+badge(b.status)+'</div>' +
      '<div class="sp-data-rows">' +
        '<div class="sp-data-row"><span>Client</span><strong>'+esc(b.client_name||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>Phone</span><strong>'+esc(b.client_phone||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>Newspaper</span><strong>'+esc(b.newspaper_name||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>City</span><strong>'+esc(b.city_name||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>Category</span><strong>'+esc(b.category_name||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>Publish date</span><strong>'+esc(b.publish_date||'—')+'</strong></div>' +
        '<div class="sp-data-row"><span>Amount</span><strong style="color:#059669">'+money(b.total_amount)+'</strong></div>' +
        '<div class="sp-data-row"><span>Submitted</span><strong>'+fdate(b.submitted_at||b.created_at)+'</strong></div>' +
      '</div>' +
      contentHtml + proofHtml;
  }).catch(function(e){ el.innerHTML='<div style="padding:20px;color:#dc2626;text-align:center">⚠️ '+esc(e.message)+'</div>'; });
}

function spLoadChat(id) {
  var el = document.getElementById('sp-dp-chat');
  el.style.padding = '0';
  el.innerHTML =
    '<div class="nas-chat" style="height:calc(100vh - 230px);border-radius:0;border:none;box-shadow:none">' +
    '<div class="nas-chat-messages" id="sp-chat-msgs"><div class="nas-chat-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</div></div>' +
    '<div class="nas-chat-composer">' +
    '<textarea class="nas-chat-input" id="sp-chat-inp" rows="2" placeholder="Type message…" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();spSendMsg('+id+')}"></textarea>' +
    '<div class="nas-chat-send-group"><button class="nas-chat-send-btn" onclick="spSendMsg('+id+')"><i class="fa-solid fa-paper-plane"></i> Send</button></div>' +
    '</div></div>';
  spRefreshChat(id);
}

function spRefreshChat(id) {
  ajax('nas_get_messages',{booking_id:id}).then(function(d){
    var area = document.getElementById('sp-chat-msgs'); if(!area)return;
    var msgs = d.messages||[];
    if(!msgs.length){area.innerHTML='<div class="nas-chat-empty"><i class="fa-solid fa-comment-dots"></i><p>No messages yet.</p></div>';return;}
    var atBot=area.scrollHeight-area.scrollTop-area.clientHeight<80;
    var me = <?= (int)get_current_user_id() ?>;
    area.innerHTML=msgs.map(function(m){
      var mine=parseInt(m.sender_id)===me;
      var t=new Date(m.created_at).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
      return'<div class="nas-chat-bubble '+(mine?'nas-bubble-mine':'nas-bubble-theirs')+'">'+
        '<div class="nas-bubble-content">'+esc(m.message)+'</div>'+
        '<div class="nas-bubble-footer"><span class="nas-bubble-sender">'+(mine?'You':esc(m.sender_name||'—'))+'</span><span class="nas-bubble-time"> · '+t+'</span></div></div>';
    }).join('');
    if(atBot)area.scrollTop=area.scrollHeight;
  }).catch(function(){});
}

window.spSendMsg = function(id) {
  var inp=document.getElementById('sp-chat-inp');
  var msg=inp?.value.trim(); if(!msg)return; inp.value='';
  ajax('nas_send_message',{booking_id:id,message:msg}).then(function(){spRefreshChat(id);}).catch(function(e){toast(e.message,'error');});
};

function spLoadActions(id) {
  var el = document.getElementById('sp-dp-actions');
  el.innerHTML =
    '<div style="display:flex;flex-direction:column;gap:10px">' +
    '<a class="sp-btn sp-btn-primary" href="'+ADMIN_URL+id+'" target="_blank" rel="noopener">' +
    '<i class="fa-solid fa-arrow-up-right-from-square"></i> Open Full Admin View</a>' +
    '<button class="sp-btn sp-btn-ghost" onclick="spDTab(\'chat\',document.querySelectorAll(\'.sp-drawer-tab\')[1])">' +
    '<i class="fa-solid fa-comment"></i> Send Message to Client</button>' +
    '<hr style="border:none;border-top:1px solid #f1f5f9;margin:4px 0">' +
    '<div style="font-size:12px;color:#94a3b8">Use the Full Admin View to update status, upload proofs, and manage payments.</div>' +
    '</div>';
}

/* ── Init ── */
document.addEventListener('DOMContentLoaded', function(){ spLoadBookings(1); });
})();
</script>

<?php
$GLOBALS['portal_active_nav'] = 'staff-dashboard';
if ( function_exists( 'nas_portal_bottom_nav' ) ) {
    nas_portal_bottom_nav();
} elseif ( file_exists( NAS_DIR . 'templates/partials/portal-bottom-nav.php' ) ) {
    include NAS_DIR . 'templates/partials/portal-bottom-nav.php';
}
?>

</div><!-- /#nas-staff-dashboard -->
