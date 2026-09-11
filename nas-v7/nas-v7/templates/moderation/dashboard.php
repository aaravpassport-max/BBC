<?php
/**
 * NAS Moderation Dashboard v3.1
 * Uses: nasAjax, nasToast from nas-core.js
 * CSS: nas-core.css + nas-dashboard.css + nas-chat.css
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! is_user_logged_in() ) { wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/moderation-dashboard/'))) ); exit; }

$user  = wp_get_current_user();
$av    = strtoupper( substr( $user->display_name, 0, 1 ) ?: 'M' );
$nonce = wp_create_nonce( 'nas_action' );
$ajax  = admin_url('admin-ajax.php');
?>
<div class="nas-portal-shell" id="nas-mod-shell">
  <div class="nas-portal-overlay" onclick="document.getElementById('nas-mod-shell').classList.remove('mobile-open')"></div>

  <!-- SIDEBAR -->
  <aside class="nas-portal-sidebar">
    <div class="nas-portal-sidebar-brand">
      <div class="nas-portal-sidebar-brand-icon">⚖️</div>
      <div>
        <div class="nas-portal-sidebar-brand-name">Moderation</div>
        <small class="nas-portal-sidebar-brand-sub">Content Review</small>
      </div>
    </div>
    <nav class="nas-portal-nav">
      <div class="nas-portal-nav-section">Review Queue</div>
      <button class="nas-portal-nav-item active" data-panel="mod-pending">
        <i class="fa-solid fa-hourglass-half"></i> Pending Review
        <span class="nas-portal-nav-badge" id="mod-pending-badge" style="display:none">0</span>
      </button>
      <button class="nas-portal-nav-item" data-panel="mod-flagged">
        <i class="fa-solid fa-flag"></i> Flagged
      </button>
      <div class="nas-portal-nav-section">Content</div>
      <button class="nas-portal-nav-item" data-panel="mod-samples">
        <i class="fa-solid fa-image"></i> Sample Ads
      </button>
      <button class="nas-portal-nav-item" data-panel="mod-qr">
        <i class="fa-solid fa-reply-all"></i> Quick Replies
      </button>
      <div class="nas-portal-nav-section">SEO</div>
      <button class="nas-portal-nav-item" data-panel="mod-seo">
        <i class="fa-solid fa-earth-asia"></i> City SEO
      </button>
    </nav>
    <div class="nas-portal-sidebar-footer">
      <a class="nas-portal-nav-item" href="<?php echo esc_url(home_url('/admin-dashboard/')); ?>">
        <i class="fa-solid fa-shield-halved"></i> Admin Panel
      </a>
      <a class="nas-portal-nav-item" href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="nas-portal-main">
    <header class="nas-portal-topbar">
      <button class="nas-portal-topbar-toggle" onclick="document.getElementById('nas-mod-shell').classList.toggle('mobile-open')">
        <i class="fa-solid fa-bars"></i>
      </button>
      <span class="nas-portal-topbar-title" id="mod-topbar-title">Pending Review</span>
      <div class="nas-portal-topbar-right">
        <div class="nas-nav-user" style="padding:6px 10px;cursor:default">
          <div class="nas-nav-avatar" style="background:linear-gradient(135deg,var(--nas-primary),var(--nas-purple))"><?php echo esc_html($av); ?></div>
          <div><div class="nas-nav-username"><?php echo esc_html(substr($user->display_name,0,16)); ?></div><div class="nas-nav-role">Moderator</div></div>
        </div>
      </div>
    </header>

    <div class="nas-portal-content">

      <!-- PANEL: PENDING -->
      <div class="nas-portal-panel active" style="display:block" id="mod-pending">
        <div class="nas-panel-heading">
          <h2><i class="fa-solid fa-hourglass-half"></i> Pending Review</h2>
          <div class="nas-panel-actions">
            <input type="text" id="mod-search" class="nas-input" placeholder="🔍 Search…" style="max-width:220px" oninput="modDebounce()">
            <button class="nas-btn nas-btn-secondary" onclick="modLoadPending()"><i class="fa-solid fa-rotate"></i></button>
          </div>
        </div>
        <div id="mod-pending-list"><div class="nas-loading-row"><div class="nas-spinner"></div></div></div>
      </div>

      <!-- PANEL: FLAGGED -->
      <div class="nas-portal-panel" style="display:none" id="mod-flagged">
        <div class="nas-panel-heading">
          <h2><i class="fa-solid fa-flag"></i> Flagged Bookings</h2>
          <button class="nas-btn nas-btn-secondary" onclick="modLoadFlagged()"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div id="mod-flagged-list"><div class="nas-loading-row"><div class="nas-spinner"></div></div></div>
      </div>

      <!-- PANEL: SAMPLE ADS -->
      <div class="nas-portal-panel" style="display:none" id="mod-samples">
        <div class="nas-panel-heading"><h2><i class="fa-solid fa-image"></i> Sample Ads</h2></div>
        <div class="nas-two-col-form">
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-plus"></i> Add / Edit Sample</h4>
            <input type="hidden" id="sample-id" value="">
            <div class="nas-form-row"><label>Title *</label><input type="text" id="sample-title" class="nas-input" placeholder="Sample ad title"></div>
            <div class="nas-form-row"><label>Category ID</label><input type="number" id="sample-cat" class="nas-input" placeholder="Leave blank for all"></div>
            <div class="nas-form-row"><label>Ad Content *</label><textarea id="sample-content" class="nas-textarea" rows="5" placeholder="The full ad text…"></textarea></div>
            <div class="nas-form-row"><label>Tags (comma-separated)</label><input type="text" id="sample-tags" class="nas-input" placeholder="obituary, condolence, death"></div>
            <div style="display:flex;gap:8px">
              <button class="nas-btn nas-btn-primary" id="sample-save-btn" onclick="modSaveSample()"><i class="fa-solid fa-floppy-disk"></i> Save Sample</button>
              <button class="nas-btn nas-btn-secondary" onclick="modResetSample()">Clear</button>
            </div>
          </div>
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-list"></i> Existing Samples</h4>
            <div id="samples-list"><div class="nas-loading-row"><div class="nas-spinner"></div></div></div>
          </div>
        </div>
      </div>

      <!-- PANEL: QUICK REPLIES -->
      <div class="nas-portal-panel" style="display:none" id="mod-qr">
        <div class="nas-panel-heading"><h2><i class="fa-solid fa-reply-all"></i> Quick Replies</h2></div>
        <div class="nas-two-col-form">
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-plus"></i> Add Quick Reply</h4>
            <input type="hidden" id="qr-id" value="">
            <div class="nas-form-row"><label>Title *</label><input type="text" id="qr-title" class="nas-input" placeholder="e.g. Payment Reminder"></div>
            <div class="nas-form-row"><label>Message *</label><textarea id="qr-content" class="nas-textarea" rows="4" placeholder="The quick reply text…"></textarea></div>
            <div class="nas-form-row"><label>Category</label><select id="qr-category" class="nas-select"><option value="general">General</option><option value="payment">Payment</option><option value="document">Document</option><option value="status">Status Update</option><option value="rejection">Rejection</option></select></div>
            <button class="nas-btn nas-btn-primary" id="qr-save-btn" onclick="modSaveQR()"><i class="fa-solid fa-floppy-disk"></i> Save Reply</button>
          </div>
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-list"></i> Existing Quick Replies</h4>
            <div id="qr-list"><div class="nas-loading-row"><div class="nas-spinner"></div></div></div>
          </div>
        </div>
      </div>

      <!-- PANEL: CITY SEO -->
      <div class="nas-portal-panel" style="display:none" id="mod-seo">
        <div class="nas-panel-heading">
          <h2><i class="fa-solid fa-earth-asia"></i> City Page SEO</h2>
          <button class="nas-btn nas-btn-secondary" id="bulk-seo-btn" onclick="modBulkSEO()"><i class="fa-solid fa-wand-magic-sparkles"></i> AI Bulk Generate</button>
        </div>
        <div class="nas-two-col-form">
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-pencil"></i> Edit City SEO</h4>
            <div class="nas-form-row"><label>City *</label><select id="seo-city-select" class="nas-select" onchange="modLoadCitySEO(this.value)"><option value="">— Select a city —</option></select></div>
            <div class="nas-form-row"><label>Meta Title</label><input type="text" id="seo-meta-title" class="nas-input" placeholder="Auto-generated if blank"></div>
            <div class="nas-form-row"><label>Meta Description</label><textarea id="seo-meta-desc" class="nas-textarea" rows="2" placeholder="160 chars max…"></textarea></div>
            <div class="nas-form-row"><label>Hero Heading</label><input type="text" id="seo-hero-heading" class="nas-input"></div>
            <div class="nas-form-row"><label>Intro Content</label><textarea id="seo-intro" class="nas-textarea" rows="4"></textarea></div>
            <div class="nas-form-row"><label>FAQs (JSON)</label><textarea id="seo-faqs" class="nas-textarea" rows="5" placeholder='[{"q":"…","a":"…"}]'></textarea></div>
            <input type="hidden" id="seo-record-id" value="">
            <button class="nas-btn nas-btn-primary" id="seo-save-btn" onclick="modSaveCitySEO()"><i class="fa-solid fa-floppy-disk"></i> Save SEO</button>
          </div>
          <div class="nas-form-section">
            <h4><i class="fa-solid fa-chart-simple"></i> Coverage Status</h4>
            <div id="seo-status-list"><p style="color:var(--nas-text-muted);font-size:13px">Select a city above to see its SEO status.</p></div>
          </div>
        </div>
      </div>

    </div><!-- /.nas-portal-content -->
  </div>
</div>

<!-- BOOKING REVIEW DRAWER -->
<div class="nas-drawer-overlay" id="mod-overlay"></div>
<aside class="nas-drawer" id="mod-drawer">
  <div class="nas-drawer-header">
    <div class="nas-drawer-title" id="mod-drawer-title">Booking Review</div>
    <button class="nas-drawer-close" onclick="modCloseDrawer()">✕</button>
  </div>
  <div class="nas-drawer-tabs">
    <button class="nas-drawer-tab active" onclick="modTab('info',this)"><i class="fa-solid fa-circle-info"></i> Info</button>
    <button class="nas-drawer-tab" onclick="modTab('content',this)"><i class="fa-solid fa-file-lines"></i> Content</button>
    <button class="nas-drawer-tab" onclick="modTab('chat',this)"><i class="fa-solid fa-comments"></i> Chat</button>
    <button class="nas-drawer-tab" onclick="modTab('history',this)"><i class="fa-solid fa-clock-rotate-left"></i> History</button>
  </div>
  <div id="mod-drawer-body" style="flex:1;overflow-y:auto">
    <div class="nas-drawer-panel active" id="mod-panel-info"></div>
    <div class="nas-drawer-panel" id="mod-panel-content"></div>
    <div class="nas-drawer-panel" id="mod-panel-chat" style="padding:0"></div>
    <div class="nas-drawer-panel" id="mod-panel-history"></div>
  </div>
</aside>

<script>
(function(){
'use strict';
var NONCE='<?php echo esc_js($nonce); ?>';
var AJAX='<?php echo esc_js($ajax); ?>';
var UID=<?php echo (int)get_current_user_id(); ?>;
var modCurrent=0, modChatTimer=null;
var modLoaded={flagged:false,samples:false,qr:false,seo:false};

function esc(t){return(t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function fmt(n){return'₹'+parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function ajax(action,data){
  var fd=new FormData();fd.append('action',action); fd.append('nas_action','1');fd.append('nonce',NONCE);
  Object.entries(data||{}).forEach(function(e){fd.append(e[0],e[1]??'');});
  return fetch(AJAX,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(r){
    if(!r.success)throw new Error(r.data?.message||'Error');return r.data;
  });
}
function setBtnLoading(btn,on){
  if(!btn)return;
  if(on){if(!btn._h)btn._h=btn.innerHTML;btn.innerHTML='<span style="display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:s .6s linear infinite;vertical-align:middle;margin-right:5px"></span>Saving…';btn.disabled=true;}
  else{if(btn._h)btn.innerHTML=btn._h;btn.disabled=false;}
}
document.head.insertAdjacentHTML('beforeend','<style>@keyframes s{to{transform:rotate(360deg)}}</style>');
function statusBadge(s){
  var colors={submitted:'#3b82f6',review:'#f59e0b',price_shared:'#202C39',approved:'#10b981',rejected:'#ef4444',sent_to_vendor:'#06b6d4',published:'#10b981',completed:'#059669'};
  var c=colors[s]||'#94a3b8';
  return '<span style="background:'+c+'18;color:'+c+';padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700">'+esc((s||'').replace(/_/g,' '))+'</span>';
}

/* ── Nav ─────────────────────────────────────────────────── */
var TITLES={'mod-pending':'Pending Review','mod-flagged':'Flagged Bookings','mod-samples':'Sample Ads','mod-qr':'Quick Replies','mod-seo':'City Page SEO'};
document.querySelectorAll('.nas-portal-nav-item[data-panel]').forEach(function(btn){
  btn.addEventListener('click',function(){
    var id=this.dataset.panel;
    document.querySelectorAll('.nas-portal-nav-item').forEach(function(b){b.classList.remove('active');});
    document.querySelectorAll('.nas-portal-panel').forEach(function(p){p.classList.remove('active');p.style.display='none';});
    this.classList.add('active');
    var _panelEl=document.getElementById(id);if(_panelEl){_panelEl.classList.add('active');_panelEl.style.display='block';}
    document.getElementById('mod-topbar-title').textContent=TITLES[id]||'';
    document.getElementById('nas-mod-shell').classList.remove('mobile-open');
    if(id==='mod-flagged'&&!modLoaded.flagged){modLoadFlagged();modLoaded.flagged=true;}
    if(id==='mod-samples'&&!modLoaded.samples){modLoadSamples();modLoaded.samples=true;}
    if(id==='mod-qr'&&!modLoaded.qr){modLoadQR();modLoaded.qr=true;}
    if(id==='mod-seo'&&!modLoaded.seo){modInitSEO();modLoaded.seo=true;}
  });
});

/* ── Pending ─────────────────────────────────────────────── */
var modSearchTimer;
window.modDebounce=function(){clearTimeout(modSearchTimer);modSearchTimer=setTimeout(modLoadPending,350);};
window.modLoadPending=function(){
  var el=document.getElementById('mod-pending-list');
  var search=document.getElementById('mod-search')?.value||'';
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_pending_bookings',{search:search}).then(function(data){
    var rows=data.bookings||[];
    var badge=document.getElementById('mod-pending-badge');
    if(badge){badge.textContent=rows.length;badge.style.display=rows.length?'':'none';}
    if(!rows.length){el.innerHTML='<div class="nas-empty-state" style="text-align:center;padding:40px"><i class="fa-solid fa-circle-check" style="font-size:36px;color:var(--nas-success);display:block;margin-bottom:12px"></i><p>No pending bookings!</p></div>';return;}
    el.innerHTML=rows.map(function(b){
      return '<div class="nas-review-row" id="modrow-'+b.id+'">'+
        '<div class="nas-review-row-header">'+
        '<span class="nas-review-uid">#'+esc(b.uid||(''+b.id))+'</span>'+
        statusBadge(b.status)+
        '<span class="nas-review-meta">'+esc(b.client_name||'—')+' · '+esc(b.newspaper_name||'—')+' · '+esc(b.city_name||'—')+'</span>'+
        '</div>'+
        '<div class="nas-review-content">'+esc((b.ad_content||'—').substring(0,300))+'</div>'+
        '<div class="nas-review-actions">'+
        '<button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="modReview('+b.id+')"><i class="fa-solid fa-eye"></i> Review</button>'+
        '<button class="nas-btn nas-btn-success nas-btn-sm" onclick="modApprove('+b.id+',this)"><i class="fa-solid fa-check"></i> Approve</button>'+
        '<button class="nas-btn nas-btn-secondary nas-btn-sm" onclick="modFlag('+b.id+',this)"><i class="fa-solid fa-flag"></i> Flag</button>'+
        '<button class="nas-btn nas-btn-danger nas-btn-sm" onclick="modReject('+b.id+',this)"><i class="fa-solid fa-ban"></i> Reject</button>'+
        '</div></div>';
    }).join('');
  }).catch(function(e){el.innerHTML='<div style="padding:16px;color:var(--nas-danger)">'+esc(e.message)+'</div>';});
};
window.modApprove=function(id,btn){
  setBtnLoading(btn,true);
  ajax('nas_approve_booking',{id:id}).then(function(){
    var r=document.getElementById('modrow-'+r&&id);r=document.getElementById('modrow-'+id);
    if(r){r.style.opacity='0';r.style.transition='opacity .3s';setTimeout(function(){r.remove();},300);}
    nasToast?.success('Booking approved!');
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modFlag=function(id,btn){
  setBtnLoading(btn,true);
  ajax('nas_flag_booking',{id:id}).then(function(){
    var r=document.getElementById('modrow-'+id);
    if(r){r.style.opacity='0';setTimeout(function(){r.remove();},300);}
    nasToast?.warning('Flagged for review.');
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modReject=function(id,btn){
  if(!confirm('Reject this booking? The client will be notified.'))return;
  setBtnLoading(btn,true);
  ajax('nas_reject_booking',{id:id}).then(function(){
    var r=document.getElementById('modrow-'+id);
    if(r){r.style.opacity='0';setTimeout(function(){r.remove();},300);}
    nasToast?.error('Booking rejected.');
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};

/* ── Flagged ─────────────────────────────────────────────── */
window.modLoadFlagged=function(){
  var el=document.getElementById('mod-flagged-list');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_mod_get_flagged',{}).then(function(data){
    var rows=data.bookings||[];
    if(!rows.length){el.innerHTML='<div class="nas-empty-state" style="text-align:center;padding:40px"><i class="fa-solid fa-flag" style="font-size:36px;opacity:.3;display:block;margin-bottom:12px"></i><p>No flagged bookings.</p></div>';return;}
    el.innerHTML=rows.map(function(b){
      return '<div class="nas-review-row" id="flagrow-'+b.id+'">'+
        '<div class="nas-review-row-header"><span class="nas-review-uid">#'+esc(b.uid||b.id)+'</span>'+
        '<span style="background:#fef3c718;color:#d97706;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700">⚠️ Flagged</span>'+
        '<span class="nas-review-meta">'+esc(b.client_name||'—')+' · '+esc(b.newspaper_name||'—')+'</span>'+
        '</div>'+
        '<div class="nas-review-content">'+esc((b.ad_content||'—').substring(0,200))+'</div>'+
        '<div class="nas-review-actions">'+
        '<button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="modReview('+b.id+')"><i class="fa-solid fa-eye"></i> Review</button>'+
        '<button class="nas-btn nas-btn-success nas-btn-sm" onclick="modApprove('+b.id+',this)"><i class="fa-solid fa-check"></i> Approve</button>'+
        '<button class="nas-btn nas-btn-danger nas-btn-sm" onclick="modReject('+b.id+',this)"><i class="fa-solid fa-ban"></i> Reject</button>'+
        '</div></div>';
    }).join('');
  }).catch(function(e){el.innerHTML='<div style="padding:16px;color:var(--nas-danger)">'+esc(e.message)+'</div>';});
};

/* ── Review Drawer ───────────────────────────────────────── */
window.modReview=function(id){
  modCurrent=id;
  document.getElementById('mod-drawer').classList.add('open');
  document.getElementById('mod-overlay').classList.add('open');
  document.getElementById('mod-overlay').style.cssText='opacity:1;pointer-events:all';
  document.body.style.overflow='hidden';
  document.querySelectorAll('#mod-drawer .nas-drawer-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('#mod-drawer .nas-drawer-panel').forEach(function(p){p.classList.remove('active');});
  document.querySelector('#mod-drawer .nas-drawer-tab').classList.add('active');
  document.getElementById('mod-panel-info').classList.add('active');
  modLoadInfo(id);
};
window.modCloseDrawer=function(){
  document.getElementById('mod-drawer').classList.remove('open');
  document.getElementById('mod-overlay').classList.remove('open');
  document.getElementById('mod-overlay').style.cssText='opacity:0;pointer-events:none';
  document.body.style.overflow='';
  if(modChatTimer){clearInterval(modChatTimer);modChatTimer=null;}
};
document.getElementById('mod-overlay').addEventListener('click',modCloseDrawer);

window.modTab=function(tab,btn){
  document.querySelectorAll('#mod-drawer .nas-drawer-tab').forEach(function(t){t.classList.remove('active');});
  document.querySelectorAll('#mod-drawer .nas-drawer-panel').forEach(function(p){p.classList.remove('active');});
  if(btn)btn.classList.add('active');
  document.getElementById('mod-panel-'+tab).classList.add('active');
  if(tab==='chat')    modLoadChat(modCurrent);
  if(tab==='content') modLoadContent(modCurrent);
  if(tab==='history') modLoadHistory(modCurrent);
};

function modLoadInfo(id){
  var el=document.getElementById('mod-panel-info');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_booking_detail',{id:id}).then(function(_rd){
    var b=_rd.booking||_rd;
    document.getElementById('mod-drawer-title').textContent='Booking #'+(b.uid||b.id);
    el.innerHTML=
      '<div style="margin-bottom:14px">'+statusBadge(b.status)+'</div>'+
      '<div class="nas-data-rows">'+
      '<div class="nas-data-row"><span>Client</span><strong>'+esc(b.client_name||'—')+'</strong></div>'+
      '<div class="nas-data-row"><span>Phone</span><strong>'+esc(b.client_phone||'—')+'</strong></div>'+
      '<div class="nas-data-row"><span>Newspaper</span><strong>'+esc(b.newspaper_name||'—')+'</strong></div>'+
      '<div class="nas-data-row"><span>City</span><strong>'+esc(b.city_name||'—')+'</strong></div>'+
      '<div class="nas-data-row"><span>Category</span><strong>'+esc(b.category_name||'—')+'</strong></div>'+
      '<div class="nas-data-row"><span>Ad Type</span><strong>'+esc((b.ad_type||'').replace(/_/g,' '))+'</strong></div>'+
      '<div class="nas-data-row"><span>Publish Date</span><strong>'+esc(b.publish_date||'—')+'</strong></div>'+
      '</div>'+
      '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">'+
      '<button class="nas-btn nas-btn-success" onclick="modApprove('+id+',this)"><i class="fa-solid fa-check"></i> Approve</button>'+
      '<button class="nas-btn nas-btn-secondary" onclick="modFlag('+id+',this)"><i class="fa-solid fa-flag"></i> Flag</button>'+
      '<button class="nas-btn nas-btn-danger" onclick="modReject('+id+',this)"><i class="fa-solid fa-ban"></i> Reject</button>'+
      '<a class="nas-btn nas-btn-ghost" href="<?php echo esc_js(home_url("/admin-dashboard/?nas_admin=request&id=")); ?>'+id+'" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>'+
      '</div>';
  }).catch(function(e){el.innerHTML='<div style="padding:16px;color:var(--nas-danger)">'+esc(e.message)+'</div>';});
}
function modLoadContent(id){
  var el=document.getElementById('mod-panel-content');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_booking_detail',{id:id}).then(function(_raw){
    var b=_raw.booking||_raw;
    el.innerHTML=
      '<div style="margin-bottom:12px"><strong style="font-size:13px;color:var(--nas-text-muted)">Ad Title</strong>'+
      '<div style="margin-top:6px;font-size:15px;font-weight:700">'+esc(b.ad_title||'(no title)')+'</div></div>'+
      '<div><strong style="font-size:13px;color:var(--nas-text-muted)">Ad Content</strong>'+
      '<div style="margin-top:8px;background:var(--nas-bg);border:1px solid var(--nas-border);border-radius:var(--nas-radius);padding:14px;font-size:13px;line-height:1.65;white-space:pre-wrap">'+esc(b.ad_content||'(none)')+'</div></div>'+
      '<div style="margin-top:10px;font-size:12px;color:var(--nas-text-muted)">Words: '+esc(b.word_count||0)+' · '+esc(b.width_cm||0)+'×'+esc(b.height_cm||0)+' cm</div>';
  }).catch(function(e){el.innerHTML='<div style="padding:16px;color:var(--nas-danger)">'+esc(e.message)+'</div>';});
}
function modLoadChat(id){
  var el=document.getElementById('mod-panel-chat');
  el.innerHTML=
    '<div class="nas-chat" style="height:calc(100vh - 260px);border-radius:0;border-left:none;border-right:none;border-bottom:none;box-shadow:none">'+
    '<div class="nas-chat-header"><div class="nas-chat-header-title"><i class="fa-solid fa-comments"></i> Chat — Booking #'+id+'</div></div>'+
    '<div class="nas-chat-messages" id="mod-chat-msgs"><div class="nas-chat-loading"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</div></div>'+
    '<div class="nas-chat-composer">'+
    '<textarea class="nas-chat-input" id="mod-chat-inp" rows="2" placeholder="Type a message…" onkeydown="if(event.key===\'Enter\'&&!event.shiftKey){event.preventDefault();modSendChat('+id+')}"></textarea>'+
    '<div class="nas-chat-send-group"><button class="nas-chat-send-btn" id="nas-send-chat-btn" onclick="modSendChat('+id+')"><i class="fa-solid fa-paper-plane"></i> Send</button></div>'+
    '</div></div>';
  modRefreshChat(id);
  if(modChatTimer)clearInterval(modChatTimer);
  modChatTimer=setInterval(function(){modRefreshChat(id);},5000);
}
function modRefreshChat(id){
  ajax('nas_get_messages',{booking_id:id}).then(function(d){
    var area=document.getElementById('mod-chat-msgs');if(!area)return;
    var msgs=d.messages||[];
    if(!msgs.length){area.innerHTML='<div class="nas-chat-empty"><i class="fa-solid fa-comment-dots"></i><p>No messages yet.</p></div>';return;}
    var atBot=area.scrollHeight-area.scrollTop-area.clientHeight<60;
    area.innerHTML=msgs.map(function(m){
      var mine=m.sender_id==UID;
      var t=new Date(m.created_at).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});
      return '<div class="nas-chat-bubble '+(mine?'nas-bubble-mine':'nas-bubble-theirs')+'">'+
        '<div class="nas-bubble-content">'+esc(m.message)+'</div>'+
        '<div class="nas-bubble-footer"><span class="nas-bubble-sender">'+(mine?'You':esc(m.sender_name||'Client'))+'</span><span class="nas-bubble-time"> · '+t+'</span></div></div>';
    }).join('');
    if(atBot)area.scrollTop=area.scrollHeight;
  }).catch(function(){});
}
window.modSendChat=function(id){
  var inp=document.getElementById('mod-chat-inp');
  var msg=inp?.value.trim();if(!msg)return;inp.value='';
  ajax('nas_send_message',{booking_id:id,message:msg}).then(function(){modRefreshChat(id);}).catch(function(e){nasToast?.error(e.message);});
};
function modLoadHistory(id){
  var el=document.getElementById('mod-panel-history');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_booking_detail',{id:id}).then(function(_rd){
    var b=_rd.booking||_rd;
    var hist=[];try{hist=JSON.parse(b.workflow_history||'[]');}catch(e){}
    if(!hist.length){el.innerHTML='<div class="nas-empty-state" style="text-align:center;padding:24px"><p>No history yet.</p></div>';return;}
    el.innerHTML=hist.slice().reverse().map(function(h){
      return '<div class="nas-data-row"><span style="font-size:11px;color:var(--nas-text-muted)">'+esc(h.at||'')+'</span>'+
        '<div style="flex:1"><div style="font-weight:600;text-transform:capitalize">'+esc((h.status||'').replace(/_/g,' '))+'</div>'+
        (h.note?'<div style="font-size:12px;color:var(--nas-text-muted)">'+esc(h.note)+'</div>':'')+
        '</div></div>';
    }).join('');
  }).catch(function(e){el.innerHTML='<div style="color:var(--nas-danger);padding:16px">'+esc(e.message)+'</div>';});
}

/* ── Samples ─────────────────────────────────────────────── */
function modLoadSamples(){
  var el=document.getElementById('samples-list');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_sample_ads_list',{}).then(function(d){
    var items=d.samples||[];
    if(!items.length){el.innerHTML='<p style="color:var(--nas-text-muted);font-size:13px">No samples yet.</p>';return;}
    el.innerHTML=items.map(function(s){
      return '<div class="nas-item-row" id="sr-'+s.id+'">'+
        '<div class="nas-item-row-info"><strong>'+esc(s.title)+'</strong><span>'+esc((s.tags||'').substring(0,50))+'</span></div>'+
        '<div class="nas-item-row-actions">'+
        '<button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="modEditSample('+s.id+','+JSON.stringify(s).replace(/"/g,'&quot;')+')"><i class="fa-solid fa-pen"></i></button>'+
        '<button class="nas-btn nas-btn-danger nas-btn-sm" onclick="modDeleteSample('+s.id+',this)"><i class="fa-solid fa-trash"></i></button>'+
        '</div></div>';
    }).join('');
  }).catch(function(){});
}
window.modSaveSample=function(){
  var t=document.getElementById('sample-title')?.value.trim();
  var cont=document.getElementById('sample-content')?.value.trim();
  if(!t||!cont){nasToast?.error('Title and content required.');return;}
  var btn=document.getElementById('sample-save-btn');setBtnLoading(btn,true);
  ajax('nas_mod_save_sample_ad',{id:document.getElementById('sample-id')?.value||'',title:t,category_id:document.getElementById('sample-cat')?.value||'',content:cont,tags:document.getElementById('sample-tags')?.value||''}).then(function(){
    setBtnLoading(btn,false);nasToast?.success('Sample saved!');modResetSample();modLoadSamples();
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modEditSample=function(id,obj){
  try{var s=typeof obj==='string'?JSON.parse(obj):obj;}catch(e){return;}
  document.getElementById('sample-id').value=s.id||'';
  document.getElementById('sample-title').value=s.title||'';
  document.getElementById('sample-cat').value=s.category_id||'';
  document.getElementById('sample-content').value=s.content||'';
  document.getElementById('sample-tags').value=s.tags||'';
};
window.modDeleteSample=function(id,btn){
  if(!confirm('Delete this sample ad?'))return;
  setBtnLoading(btn,true);
  ajax('nas_mod_delete_sample_ad',{id:id}).then(function(){
    var r=document.getElementById('sr-'+id);if(r)r.remove();nasToast?.success('Deleted.');
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modResetSample=function(){
  ['sample-id','sample-title','sample-cat','sample-content','sample-tags'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
};

/* ── Quick Replies ───────────────────────────────────────── */
function modLoadQR(){
  var el=document.getElementById('qr-list');
  el.innerHTML='<div class="nas-loading-row"><div class="nas-spinner"></div></div>';
  ajax('nas_get_quick_replies',{}).then(function(d){
    var items=d.replies||[];
    if(!items.length){el.innerHTML='<p style="color:var(--nas-text-muted);font-size:13px">No replies yet.</p>';return;}
    el.innerHTML=items.map(function(r){
      return '<div class="nas-item-row" id="qrr-'+r.id+'">'+
        '<div class="nas-item-row-info"><strong>'+esc(r.title)+'</strong><span>'+esc((r.content||'').substring(0,60))+'…</span></div>'+
        '<div class="nas-item-row-actions"><button class="nas-btn nas-btn-danger nas-btn-sm" onclick="modDeleteQR('+r.id+',this)"><i class="fa-solid fa-trash"></i></button></div>'+
        '</div>';
    }).join('');
  }).catch(function(){});
}
window.modSaveQR=function(){
  var t=document.getElementById('qr-title')?.value.trim();
  var cont=document.getElementById('qr-content')?.value.trim();
  if(!t||!cont){nasToast?.error('Title and message required.');return;}
  var btn=document.getElementById('qr-save-btn');setBtnLoading(btn,true);
  ajax('nas_save_quick_reply',{id:document.getElementById('qr-id')?.value||'',title:t,content:cont,category:document.getElementById('qr-category')?.value||'general'}).then(function(){
    setBtnLoading(btn,false);nasToast?.success('Reply saved!');
    document.getElementById('qr-title').value='';document.getElementById('qr-content').value='';
    modLoadQR();
  }).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modDeleteQR=function(id,btn){
  if(!confirm('Delete this quick reply?'))return;
  setBtnLoading(btn,true);
  ajax('nas_delete_quick_reply',{id:id}).then(function(){var r=document.getElementById('qrr-'+id);if(r)r.remove();nasToast?.success('Deleted.');}).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};

/* ── City SEO ────────────────────────────────────────────── */
function modInitSEO(){
  ajax('nas_get_cities',{}).then(function(d){
    var sel=document.getElementById('seo-city-select');if(!sel)return;
    var opts=(Array.isArray(d)?d:(d.cities||[])).map(function(c){return '<option value="'+c.id+'">'+esc(c.name)+(c.state?' ('+esc(c.state)+')':'')+'</option>';});
    sel.innerHTML='<option value="">— Select a city —</option>'+opts.join('');
  }).catch(function(){});
}
window.modLoadCitySEO=function(cityId){
  if(!cityId)return;
  ajax('nas_get_city_page_meta',{city_id:cityId}).then(function(d){
    var s=d.meta||{};
    document.getElementById('seo-record-id').value=s.id||'';
    document.getElementById('seo-meta-title').value=s.seo_title||'';
    document.getElementById('seo-meta-desc').value=s.seo_desc||'';
    document.getElementById('seo-hero-heading').value=s.hero_heading||'';
    document.getElementById('seo-intro').value=s.content_body||'';
    document.getElementById('seo-faqs').value=s.schema_data||'';
  }).catch(function(){['seo-record-id','seo-meta-title','seo-meta-desc','seo-hero-heading','seo-intro','seo-faqs'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});});
};
window.modSaveCitySEO=function(){
  var cityId=document.getElementById('seo-city-select')?.value;
  if(!cityId){nasToast?.error('Please select a city.');return;}
  var btn=document.getElementById('seo-save-btn');setBtnLoading(btn,true);
  ajax('nas_save_city_page_meta',{city_id:cityId,seo_title:document.getElementById('seo-meta-title')?.value||'',seo_desc:document.getElementById('seo-meta-desc')?.value||'',content_body:document.getElementById('seo-intro')?.value||'',schema_data:document.getElementById('seo-faqs')?.value||''}).then(function(){setBtnLoading(btn,false);nasToast?.success('City SEO saved!');}).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};
window.modBulkSEO=function(){
  if(!confirm('Generate AI SEO for all city pages? This may take a moment.'))return;
  var btn=document.getElementById('bulk-seo-btn');setBtnLoading(btn,true);
  ajax('nas_bulk_generate_city_pages',{}).then(function(){setBtnLoading(btn,false);nasToast?.success('SEO generated for all cities!');}).catch(function(e){setBtnLoading(btn,false);nasToast?.error(e.message);});
};

document.addEventListener('DOMContentLoaded',function(){modLoadPending();});
})();

/* ── modLoadContent — was missing, causing blank content tab ── */
window.modLoadContent = function(bookingId) {
  var panel = document.getElementById('mod-panel-content');
  if (!panel) return;
  panel.innerHTML = '<div class="nas-spinner-wrap" style="padding:32px"><div class="nas-spinner"></div></div>';
  var fd = new FormData();
  fd.append('action', 'nas_get_booking_detail');
  fd.append('booking_id', bookingId);
  fd.append('nonce', MOD_CONFIG.nonce);
  fetch(MOD_CONFIG.ajaxUrl, {method:'POST', body:fd})
    .then(function(r){return r.json();})
    .then(function(res) {
      if (!res.success) { panel.innerHTML = '<p style="color:#dc2626;padding:20px">Error loading content.</p>'; return; }
      var b = res.data.booking || res.data;
      panel.innerHTML =
        '<div style="padding:4px">' +
        '<div class="nas-form-section">' +
        '<label class="nas-label">Ad Content</label>' +
        '<textarea class="nas-textarea" id="mod-ad-content" rows="8" style="font-family:inherit">' + (b.ad_content||'').replace(/</g,'&lt;') + '</textarea>' +
        '</div>' +
        '<div class="nas-form-section" style="margin-top:12px">' +
        '<label class="nas-label">Category / Ad Type</label>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">' +
        '<span style="background:#f1f5f9;padding:4px 10px;border-radius:6px;font-size:12px">' + (b.category_name||b.category||'—') + '</span>' +
        '<span style="background:#f1f5f9;padding:4px 10px;border-radius:6px;font-size:12px">' + (b.ad_type||'—').replace(/_/g,' ') + '</span>' +
        '</div></div>' +
        (b.proof_url ? '<div class="nas-form-section" style="margin-top:12px"><label class="nas-label">Current Proof</label><a href="'+b.proof_url+'" target="_blank"><img src="'+b.proof_url+'" style="max-width:100%;max-height:400px;border-radius:10px;margin-top:8px;border:1px solid #e2e8f0;display:block"></a></div>' : '') +
        '<div style="display:flex;gap:10px;margin-top:16px">' +
        '<button class="nas-btn nas-btn-primary nas-btn-sm" onclick="modSaveContent('+bookingId+')">' +
        '<i class="fa-solid fa-floppy-disk"></i> Save Changes</button>' +
        '<button class="nas-btn nas-btn-ghost nas-btn-sm" onclick="modAIRewrite('+bookingId+')">' +
        '<i class="fa-solid fa-wand-magic-sparkles"></i> AI Rewrite</button>' +
        '</div></div>';
    })
    .catch(function() { panel.innerHTML = '<p style="color:#dc2626;padding:20px">Network error.</p>'; });
};

window.modSaveContent = function(bookingId) {
  var content_val = document.getElementById('mod-ad-content')?.value;
  if (!content_val) return;
  var fd = new FormData();
  fd.append('action', 'nas_update_booking');
  fd.append('nonce', MOD_CONFIG.nonce);
  fd.append('booking_id', bookingId);
  fd.append('ad_content', content_val);
  fetch(MOD_CONFIG.ajaxUrl, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(function(r) {
      if (r.success) { nasToast.show('Content saved!', 'success'); }
      else nasToast.show(r.data?.message||'Save failed', 'error');
    });
};

window.modAIRewrite = function(bookingId) {
  var text = document.getElementById('mod-ad-content')?.value?.trim();
  if (!text) { nasToast.show('No content to rewrite.', 'error'); return; }
  nasToast.show('AI rewriting…', 'info');
  var fd = new FormData();
  fd.append('action', 'nas_ai_rewrite');
  fd.append('nonce', MOD_CONFIG.nonce);
  fd.append('text', text);
  fd.append('tone', 'professional');
  fetch(MOD_CONFIG.ajaxUrl, {method:'POST', body:fd})
    .then(r=>r.json())
    .then(function(r) {
      if (r.success && r.data?.text) {
        document.getElementById('mod-ad-content').value = r.data.text;
        nasToast.show('AI rewrite applied! Review and save.', 'success');
      } else nasToast.show('AI rewrite failed. Check API key in Settings.', 'error');
    });
};
</script>
