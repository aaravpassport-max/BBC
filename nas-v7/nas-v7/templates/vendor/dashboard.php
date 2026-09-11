<?php
/**
 * NAS Vendor Dashboard Template v1.0
 * Called by VendorDashboard::render() — $vendor array is available in scope.
 * 13-C: Designed empty state for empty booking list.
 * 13-B: All forms have validation, submit-disable, field-specific errors.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$nonce     = wp_create_nonce( 'nas_action' );
$ajax      = admin_url('admin-ajax.php');
$vendor_id = (int) $vendor['id'];
$cfg       = \NAS\Core\Config::instance();
$brand     = $cfg->get( 'brand_name', get_bloginfo('name') );
$av        = strtoupper( substr( $vendor['name'], 0, 1 ) ?: 'V' );
?>
<div id="nas-vendor-dashboard">
<style>
#nas-vendor-dashboard{min-height:100vh;background:#f8fafc;font-family:var(--nas-font,'Inter',sans-serif)}
.vd-topnav{position:sticky;top:0;z-index:500;background:#fff;border-bottom:1px solid #e2e8f0;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.vd-topnav-inner{max-width:1200px;margin:0 auto;display:flex;align-items:center;gap:12px;padding:0 20px;height:60px}
.vd-logo{font-weight:800;font-size:16px;color:#1e293b;text-decoration:none}
.vd-nav{display:flex;align-items:center;gap:4px;margin-left:20px}
.vd-nav-btn{background:none;border:none;cursor:pointer;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:600;color:#64748b;transition:all .15s;font-family:inherit}
.vd-nav-btn:hover,.vd-nav-btn.active{background:#ede9fe;color:#2A8AFA}
.vd-topnav-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.vd-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#059669,#047857);color:#fff;font-weight:800;font-size:14px;display:flex;align-items:center;justify-content:center}
.vd-body{max-width:1200px;margin:0 auto;padding:28px 20px}
.vd-panel{display:none}.vd-panel.show{display:block}
.vd-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
@media(max-width:700px){.vd-stats{grid-template-columns:1fr 1fr}}
.vd-stat{background:#fff;border-radius:14px;padding:16px;display:flex;align-items:center;gap:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);border:1px solid #f1f5f9}
.vd-stat-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.vd-stat-val{font-size:20px;font-weight:800;color:#1e293b;line-height:1}
.vd-stat-lbl{font-size:11px;color:#94a3b8;margin-top:3px;font-weight:600;text-transform:uppercase;letter-spacing:.4px}
.vd-card{background:#fff;border-radius:14px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:20px}
.vd-card-header{padding:16px 20px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.vd-card-header h3{font-size:15px;font-weight:700;color:#1e293b;margin:0}
.vd-card-body{padding:20px}
.vd-booking-row{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:background .1s}
.vd-booking-row:last-child{border-bottom:none}
.vd-booking-row:hover{background:#faf8ff}
.vd-booking-uid{font-size:14px;font-weight:800;color:#2A8AFA}
.vd-booking-meta{font-size:12px;color:#64748b;margin-top:2px}
.vd-booking-actions{margin-left:auto;display:flex;gap:8px;flex-shrink:0}
.vd-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;font-family:inherit;text-decoration:none}
.vd-btn-primary{background:#059669;color:#fff}.vd-btn-primary:hover{background:#047857}
.vd-btn-ghost{background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0}.vd-btn-ghost:hover{background:#e2e8f0}
.vd-btn-danger{background:#ef4444;color:#fff}.vd-btn-danger:hover{background:#dc2626}
.vd-btn:disabled{opacity:.5;cursor:not-allowed}
.vd-pill{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;background:#e0f2fe;color:#0369a1}
.vd-pill.published{background:#dcfce7;color:#15803d}
.vd-pill.completed{background:#d1fae5;color:#065f46}
.vd-pill.ad_processing{background:#fef3c7;color:#92400e}
.vd-pill.rejected,.vd-pill.cancelled{background:#fee2e2;color:#dc2626}
.vd-empty{text-align:center;padding:48px 20px;color:#94a3b8}
.vd-empty-icon{font-size:44px;display:block;margin-bottom:12px;opacity:.35}
.vd-empty-title{font-size:16px;font-weight:700;color:#374151;margin-bottom:6px}
.vd-empty-sub{font-size:13px;color:#94a3b8}
.vd-form-group{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.vd-form-label{font-size:12px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.3px}
.vd-form-control{padding:9px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;outline:none;transition:border-color .15s}
.vd-form-control:focus{border-color:#059669}
.vd-form-error{font-size:11px;color:#dc2626;margin-top:3px;display:none}
.vd-form-control.invalid{border-color:#dc2626}
.vd-toast-wrap{position:fixed;bottom:28px;right:28px;z-index:100000;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:360px}
.vd-toast{background:#fff;color:#1e293b;padding:14px 18px;border-radius:12px;font-size:13px;font-weight:500;pointer-events:all;box-shadow:0 4px 24px rgba(0,0,0,.12);transform:translateX(120%);opacity:0;transition:all .3s cubic-bezier(.175,.885,.32,1.275);border-left:4px solid #e2e8f0}
.vd-toast.in{transform:translateX(0);opacity:1}
.vd-toast.success{border-left-color:#059669}
.vd-toast.error{border-left-color:#dc2626}
.vd-spinner{width:20px;height:20px;border:2px solid #e2e8f0;border-top-color:#059669;border-radius:50%;animation:vdSpin .7s linear infinite;display:inline-block;vertical-align:middle}
@keyframes vdSpin{to{transform:rotate(360deg)}}
.vd-filter-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.vd-filter-bar input,.vd-filter-bar select{padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:9px;font-size:13px;font-family:inherit;outline:none;background:#fff;transition:border-color .15s}
.vd-filter-bar input:focus,.vd-filter-bar select:focus{border-color:#059669}
.vd-filter-bar input{flex:1;max-width:240px}
.vd-pages{display:flex;justify-content:center;gap:6px;margin-top:16px}
.vd-page-btn{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:7px;padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .15s}
.vd-page-btn.active,.vd-page-btn:hover{background:#059669;color:#fff;border-color:#059669}
.vd-upload-zone{border:2px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;cursor:pointer;transition:all .2s}
.vd-upload-zone:hover{border-color:#059669;background:#f0fdf4}
.vd-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:600px){.vd-profile-grid{grid-template-columns:1fr}}
</style>

<!-- Top Nav -->
<div class="vd-topnav">
  <div class="vd-topnav-inner">
    <a class="vd-logo" href="<?php echo esc_url( home_url('/') ); ?>">
      <?php if ( $cfg->get('logo_url') ): ?>
        <img src="<?php echo esc_url( $cfg->get('logo_url') ); ?>" alt="<?php echo esc_attr( $brand ); ?>" style="height:28px">
      <?php else: ?>
        📰 <?php echo esc_html( $brand ); ?>
      <?php endif; ?>
    </a>
    <nav class="vd-nav">
      <button class="vd-nav-btn active" data-panel="bookings" aria-label="My Bookings">📋 Bookings</button>
      <button class="vd-nav-btn" data-panel="earnings" aria-label="Earnings">💰 Earnings</button>
      <button class="vd-nav-btn" data-panel="profile" aria-label="My Profile">👤 Profile</button>
    </nav>
    <div class="vd-topnav-right">
      <div class="vd-avatar" title="<?php echo esc_attr( $vendor['name'] ); ?>"><?php echo esc_html( $av ); ?></div>
      <span style="font-size:13px;font-weight:600;color:#1e293b"><?php echo esc_html( $vendor['name'] ); ?></span>
      <a href="<?php echo esc_url( wp_logout_url( home_url('/newspaper-ad-login/') ) ); ?>" class="vd-btn vd-btn-ghost" style="font-size:12px;padding:6px 12px">Logout</a>
    </div>
  </div>
</div>

<div class="vd-body">
  <!-- Stats bar -->
  <div class="vd-stats" id="vd-stats">
    <div class="vd-stat"><div class="vd-stat-icon" style="background:#e0f2fe">📋</div><div><div class="vd-stat-val" id="vd-st-total">—</div><div class="vd-stat-lbl">Total Bookings</div></div></div>
    <div class="vd-stat"><div class="vd-stat-icon" style="background:#fef3c7">⏳</div><div><div class="vd-stat-val" id="vd-st-pending">—</div><div class="vd-stat-lbl">In Processing</div></div></div>
    <div class="vd-stat"><div class="vd-stat-icon" style="background:#dcfce7">✅</div><div><div class="vd-stat-val" id="vd-st-completed">—</div><div class="vd-stat-lbl">Completed</div></div></div>
    <div class="vd-stat"><div class="vd-stat-icon" style="background:#f0fdf4">₹</div><div><div class="vd-stat-val" id="vd-st-earned">—</div><div class="vd-stat-lbl">Total Earned</div></div></div>
  </div>

  <!-- Bookings Panel -->
  <div class="vd-panel show" id="vd-panel-bookings">
    <div class="vd-card">
      <div class="vd-card-header">
        <h3>My Assigned Bookings</h3>
        <span id="vd-booking-total" style="font-size:13px;color:#94a3b8"></span>
      </div>
      <div class="vd-card-body" style="padding:14px">
        <div class="vd-filter-bar">
          <input type="text" id="vd-search" placeholder="Search UID, client…" aria-label="Search bookings">
          <select id="vd-status-filter" aria-label="Filter by status">
            <option value="">All Statuses</option>
            <option value="ad_processing">In Processing</option>
            <option value="proof_ready">Proof Ready</option>
            <option value="submitted_to_pub">Submitted to Publisher</option>
            <option value="published">Published</option>
            <option value="completed">Completed</option>
          </select>
          <button class="vd-btn vd-btn-ghost" id="vd-clear-filter">Clear</button>
        </div>
        <div id="vd-bookings-list">
          <div style="text-align:center;padding:32px"><span class="vd-spinner"></span></div>
        </div>
        <div class="vd-pages" id="vd-pages"></div>
      </div>
    </div>
  </div>

  <!-- Earnings Panel -->
  <div class="vd-panel" id="vd-panel-earnings">
    <div class="vd-card">
      <div class="vd-card-header"><h3>💰 Earnings Summary</h3></div>
      <div class="vd-card-body" id="vd-earnings-body">
        <div style="text-align:center;padding:32px"><span class="vd-spinner"></span></div>
      </div>
    </div>
  </div>

  <!-- Profile Panel -->
  <div class="vd-panel" id="vd-panel-profile">
    <div class="vd-card">
      <div class="vd-card-header"><h3>👤 My Profile</h3></div>
      <div class="vd-card-body">
        <form id="vd-profile-form" novalidate>
          <div class="vd-profile-grid">
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-name">Business Name *</label>
              <input type="text" id="vd-pf-name" name="name" class="vd-form-control" value="<?php echo esc_attr( $vendor['name'] ?? '' ); ?>" required aria-required="true">
              <span class="vd-form-error" id="err-name">Business name is required.</span>
            </div>
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-contact">Contact Person</label>
              <input type="text" id="vd-pf-contact" name="contact_person" class="vd-form-control" value="<?php echo esc_attr( $vendor['contact_person'] ?? '' ); ?>">
            </div>
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-phone">Phone *</label>
              <input type="tel" id="vd-pf-phone" name="phone" class="vd-form-control" value="<?php echo esc_attr( $vendor['phone'] ?? '' ); ?>" required aria-required="true">
              <span class="vd-form-error" id="err-phone">Valid phone number is required.</span>
            </div>
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-email">Email</label>
              <input type="email" id="vd-pf-email" name="email" class="vd-form-control" value="<?php echo esc_attr( $vendor['email'] ?? '' ); ?>">
            </div>
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-gst">GST Number</label>
              <input type="text" id="vd-pf-gst" name="gst_number" class="vd-form-control" value="<?php echo esc_attr( $vendor['gst_number'] ?? '' ); ?>">
            </div>
            <div class="vd-form-group">
              <label class="vd-form-label" for="vd-pf-pan">PAN Number</label>
              <input type="text" id="vd-pf-pan" name="pan_number" class="vd-form-control" value="<?php echo esc_attr( $vendor['pan_number'] ?? '' ); ?>">
            </div>
          </div>
          <div class="vd-form-group">
            <label class="vd-form-label" for="vd-pf-bank">Bank Account Details</label>
            <textarea id="vd-pf-bank" name="bank_details" class="vd-form-control" rows="3"><?php echo esc_textarea( $vendor['bank_details'] ?? '' ); ?></textarea>
          </div>
          <div class="vd-form-group">
            <label class="vd-form-label" for="vd-pf-address">Address</label>
            <textarea id="vd-pf-address" name="address" class="vd-form-control" rows="2"><?php echo esc_textarea( $vendor['address'] ?? '' ); ?></textarea>
          </div>
          <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
            <button type="submit" class="vd-btn vd-btn-primary" id="vd-profile-save">💾 Save Profile</button>
            <span id="vd-profile-msg" style="font-size:13px;color:#059669;display:none"></span>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- Toast container -->
<div class="vd-toast-wrap" id="vd-toasts" aria-live="polite"></div>

<script>
(function(){
'use strict';
// TRACE: Vendor dashboard JS — DOMContentLoaded initialises stats, bookings, and panel navigation.
//        All AJAX calls use 30-second AbortController timeout. Empty states show designed UI.
//        Precondition: NAS.nonce, NAS.ajax_url injected by Enqueue. $vendor_id from PHP.
const VENDOR_ID = <?php echo (int) $vendor_id; ?>;
const NONCE     = <?php echo wp_json_encode( $nonce ); ?>;
const AJAX      = <?php echo wp_json_encode( admin_url('admin-ajax.php') ); ?>;
const CUR       = <?php echo wp_json_encode( $cfg->get('currency_symbol','₹') ); ?>;
let currentPage = 1;
let totalPages  = 1;

// TRACE: vdAjax(action, data) — builds FormData with nonce, fetches AJAX with 30s timeout.
//        Returns Promise. Rejects with Error on failure, timeout, or !success response.
function vdAjax(action, data={}) {
  const fd = new FormData();
  fd.append('action', action); fd.append('nas_action','1');
  fd.append('nonce', NONCE);
  fd.append('vendor_id', VENDOR_ID);
  for (const [k,v] of Object.entries(data)) fd.append(k, v ?? '');
  const ctrl = new AbortController();
  const tid  = setTimeout(() => ctrl.abort(), 30000);
  return fetch(AJAX, {method:'POST', credentials:'same-origin', body:fd, signal:ctrl.signal})
    .then(r => { clearTimeout(tid); return r.json(); })
    .then(r => { if (!r.success) throw new Error(r.data?.message || r.data || 'Error'); return r.data; })
    .catch(e => { clearTimeout(tid); if (e.name==='AbortError') throw new Error((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.request_timeout)||'Request timed out. Please try again.'); throw e; });
}

// TRACE: vdToast(msg, type) — shows a non-blocking toast notification bottom-right.
//        Auto-removes after 4 seconds. type: 'success' | 'error' | 'info'.
function vdToast(msg, type='success') {
  const wrap = document.getElementById('vd-toasts');
  const t = document.createElement('div');
  t.className = 'vd-toast ' + type;
  t.setAttribute('role', 'alert');
  t.innerHTML = msg;
  wrap.appendChild(t);
  requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('in')));
  setTimeout(() => { t.classList.remove('in'); setTimeout(() => t.remove(), 400); }, 4000);
}

// TRACE: statusPill(status) → span HTML with status label and appropriate colour class.
function statusPill(status) {
  const labels = {
    booking_received:'Received', under_review:'Under Review', quotation_sent:'Quoted',
    ready_to_process:'Ready', documents_received:'Docs Received', payment_received:'Paid',
    material_uploaded:'Material Uploaded', ad_processing:'In Processing',
    proof_ready:'Proof Ready', submitted_to_pub:'Submitted to Publisher',
    published:'Published', completed:'Completed', rejected:'Rejected', cancelled:'Cancelled'
  };
  return `<span class="vd-pill ${status}">${labels[status]||status}</span>`;
}

// TRACE: loadStats() — fetches vendor stats on mount; populates 4 stat cards.
//        Loading state: cards show '—'. Error state: cards show 'Error'. 
function loadStats() {
  vdAjax('nas_vendor_get_stats').then(d => {
    document.getElementById('vd-st-total').textContent     = d.total     ?? 0;
    document.getElementById('vd-st-pending').textContent   = d.pending   ?? 0;
    document.getElementById('vd-st-completed').textContent = d.completed ?? 0;
    document.getElementById('vd-st-earned').textContent    = CUR + parseFloat(d.earned || 0).toFixed(2);
  }).catch(() => {
    ['vd-st-total','vd-st-pending','vd-st-completed','vd-st-earned'].forEach(id => {
      document.getElementById(id).textContent = 'Error';
    });
  });
}

// TRACE: loadBookings(page) — fetches paginated booking list with current filter values.
//        Empty state: shows designed empty UI with call-to-action text.
//        Error state: shows readable error message.
function loadBookings(page=1) {
  currentPage = page;
  const list   = document.getElementById('vd-bookings-list');
  const search = document.getElementById('vd-search').value;
  const status = document.getElementById('vd-status-filter').value;

  list.innerHTML = '<div style="text-align:center;padding:32px"><span class="vd-spinner" aria-label="Loading bookings"></span></div>';

  vdAjax('nas_vendor_get_bookings', { page, per_page:15, search, status })
    .then(d => {
      totalPages = d.pages || 1;
      document.getElementById('vd-booking-total').textContent = d.total + ' booking' + (d.total===1?'':'s');

      if (!d.bookings || !d.bookings.length) {
        // 13-C: Designed empty state — not a blank void
        list.innerHTML = `<div class="vd-empty">
          <span class="vd-empty-icon">📭</span>
          <div class="vd-empty-title">${search||status ? (typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.no_bookings_filter)||'No bookings match your filters' : (typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.no_bookings)||'No bookings assigned yet'}</div>
          <div class="vd-empty-sub">${search||status ? 'Try clearing your search or filter.' : 'When the admin assigns a booking to you, it will appear here.'}</div>
          ${search||status ? '<button class="vd-btn vd-btn-ghost" style="margin-top:12px" onclick="document.getElementById(\'vd-clear-filter\').click()">Clear Filters</button>' : ''}
        </div>`;
        renderPages(0);
        return;
      }

      list.innerHTML = d.bookings.map(b => `
        <div class="vd-booking-row" role="row">
          <div style="flex:1;min-width:0">
            <div class="vd-booking-uid">${b.uid || b.id}</div>
            <div class="vd-booking-meta">${b.newspaper_name||''} · ${b.category_name||''} · ${b.city_name||''}</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px">${b.submitted_at ? b.submitted_at.slice(0,10) : ''}</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-shrink:0;flex-wrap:wrap">
            ${statusPill(b.status)}
            ${b.vendor_cost ? `<span style="font-size:14px;font-weight:800;color:#059669">${CUR}${parseFloat(b.vendor_cost).toFixed(2)}</span>` : ''}
            ${b.status==='ad_processing' ? `<button class="vd-btn vd-btn-primary" aria-label="Mark booking ${b.uid} as published" onclick="markPublished(${b.id},this)">Mark Published</button>` : ''}
            ${b.status==='ad_processing' ? `<button class="vd-btn vd-btn-ghost" aria-label="Upload proof for booking ${b.uid}" onclick="openProofUpload(${b.id})">Upload Proof</button>` : ''}
          </div>
        </div>`).join('');

      renderPages(d.pages);
    }).catch(e => {
      list.innerHTML = `<div class="vd-empty"><span class="vd-empty-icon">⚠️</span><div class="vd-empty-title">Could not load bookings</div><div class="vd-empty-sub">${e.message}</div><button class="vd-btn vd-btn-ghost" style="margin-top:12px" onclick="loadBookings(1)">Retry</button></div>`;
    });
}

// TRACE: renderPages(total) — builds pagination buttons for the bookings list.
function renderPages(total) {
  const pg = document.getElementById('vd-pages');
  if (total <= 1) { pg.innerHTML=''; return; }
  let html='';
  for (let i=1;i<=total;i++) html+=`<button class="vd-page-btn${i===currentPage?' active':''}" aria-label="Page ${i}" onclick="loadBookings(${i})">${i}</button>`;
  pg.innerHTML = html;
}

// TRACE: markPublished(bookingId, btn) — disables btn, sends nas_vendor_mark_published.
//        On success: reloads bookings list; shows success toast.
//        On error: re-enables btn; shows error toast.
window.markPublished = function(bookingId, btn) {
  if (!confirm('Mark this booking as published? This cannot be undone.')) return;
  btn.disabled = true;
  btn.textContent = '…';
  vdAjax('nas_vendor_mark_published', {booking_id:bookingId})
    .then(() => { vdToast((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.published_success)||'Booking marked as published ✓'); loadBookings(currentPage); loadStats(); })
    .catch(e  => { vdToast(e.message, 'error'); btn.disabled=false; btn.textContent='Mark Published'; });
};

// TRACE: openProofUpload(bookingId) — shows file input dialog; on file select uploads via nas_vendor_upload_proof.
window.openProofUpload = function(bookingId) {
  const input = document.createElement('input');
  input.type  = 'file';
  input.accept= 'image/*,.pdf';
  input.setAttribute('aria-label', 'Upload proof file');
  input.onchange = function() {
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('action',    'nas_vendor_upload_proof');
    fd.append('nonce',     NONCE);
    fd.append('booking_id',bookingId);
    fd.append('vendor_id', VENDOR_ID);
    fd.append('proof_file',input.files[0]);
    const ctrl = new AbortController();
    const tid  = setTimeout(() => ctrl.abort(), 60000);
    vdToast((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.uploading_proof)||'Uploading proof…','info');
    fetch(AJAX, {method:'POST', body:fd, signal:ctrl.signal})
      .then(r => { clearTimeout(tid); return r.json(); })
      .then(r => {
        if (r.success) { vdToast((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.proof_uploaded)||'Proof uploaded successfully ✓'); loadBookings(currentPage); loadStats(); }
        else vdToast(r.data?.message || 'Upload failed', 'error');
      })
      .catch(e => { clearTimeout(tid); vdToast(e.name==='AbortError'?((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.upload_timeout)||'Upload timed out'):e.message,'error'); });
  };
  input.click();
};

// TRACE: loadEarnings() — fetches earnings summary and renders total earned, paid, and monthly breakdown.
function loadEarnings() {
  const body = document.getElementById('vd-earnings-body');
  body.innerHTML = '<div style="text-align:center;padding:32px"><span class="vd-spinner"></span></div>';
  vdAjax('nas_vendor_get_earnings').then(d => {
    const pending = Math.max(0, (parseFloat(d.total_earned)||0) - (parseFloat(d.total_paid)||0));
    body.innerHTML = `
      <div class="vd-stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:24px">
        <div class="vd-stat"><div class="vd-stat-icon" style="background:#dcfce7">💰</div><div><div class="vd-stat-val">${CUR}${parseFloat(d.total_earned||0).toFixed(2)}</div><div class="vd-stat-lbl">Total Earned</div></div></div>
        <div class="vd-stat"><div class="vd-stat-icon" style="background:#e0f2fe">✅</div><div><div class="vd-stat-val">${CUR}${parseFloat(d.total_paid||0).toFixed(2)}</div><div class="vd-stat-lbl">Paid Out</div></div></div>
        <div class="vd-stat"><div class="vd-stat-icon" style="background:#fef3c7">⏳</div><div><div class="vd-stat-val">${CUR}${pending.toFixed(2)}</div><div class="vd-stat-lbl">Pending Payout</div></div></div>
      </div>
      ${d.monthly && d.monthly.length ? `
        <h4 style="font-size:14px;font-weight:700;color:#374151;margin:0 0 12px">Monthly Breakdown</h4>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
          <thead><tr style="background:#f8fafc"><th style="text-align:left;padding:8px 12px;font-weight:600">Month</th><th style="text-align:right;padding:8px 12px;font-weight:600">Bookings</th><th style="text-align:right;padding:8px 12px;font-weight:600">Amount</th></tr></thead>
          <tbody>${d.monthly.map(m=>`<tr style="border-bottom:1px solid #f1f5f9"><td style="padding:8px 12px">${m.month}</td><td style="text-align:right;padding:8px 12px">${m.count}</td><td style="text-align:right;padding:8px 12px;font-weight:700;color:#059669">${CUR}${parseFloat(m.amount).toFixed(2)}</td></tr>`).join('')}</tbody>
        </table>` : '<div class="vd-empty"><span class="vd-empty-icon">📊</span><div class="vd-empty-title">No earnings data yet</div><div class="vd-empty-sub">Completed bookings will appear here.</div></div>'}`;
  }).catch(e => {
    body.innerHTML = `<div class="vd-empty"><span class="vd-empty-icon">⚠️</span><div class="vd-empty-title">Could not load earnings</div><div class="vd-empty-sub">${e.message}</div></div>`;
  });
}

// TRACE: Profile form submit — validates required fields inline; disables button during save.
//        On success: shows success message inline. On error: re-enables, shows error toast.
//        Form values preserved on failure (Part 3: form reset after submit — values kept on error).
document.getElementById('vd-profile-form').addEventListener('submit', function(e) {
  e.preventDefault();
  // Inline validation
  let valid = true;
  const name = document.getElementById('vd-pf-name');
  const phone = document.getElementById('vd-pf-phone');
  const errName = document.getElementById('err-name');
  const errPhone = document.getElementById('err-phone');
  if (!name.value.trim()) {
    name.classList.add('invalid'); errName.style.display='block'; valid=false;
  } else { name.classList.remove('invalid'); errName.style.display='none'; }
  if (!phone.value.trim()) {
    phone.classList.add('invalid'); errPhone.style.display='block'; valid=false;
  } else { phone.classList.remove('invalid'); errPhone.style.display='none'; }
  if (!valid) return;

  const btn = document.getElementById('vd-profile-save');
  const msg = document.getElementById('vd-profile-msg');
  btn.disabled = true; btn.textContent = 'Saving…';

  const data = {};
  ['name','contact_person','phone','email','gst_number','pan_number','bank_details','address'].forEach(k => {
    const el = this.elements[k]; if (el) data[k] = el.value;
  });

  vdAjax('nas_vendor_update_profile', data)
    .then(() => {
      msg.style.display='inline'; msg.textContent='✓ Profile saved successfully';
      setTimeout(() => { msg.style.display='none'; }, 3000);
      vdToast((typeof NAS!=='undefined'&&NAS.i18n&&NAS.i18n.profile_saved)||'Profile updated ✓');
    })
    .catch(er => { vdToast(er.message, 'error'); })
    .finally(() => { btn.disabled=false; btn.textContent='💾 Save Profile'; });
});

// TRACE: Panel navigation — show/hide panels on nav button click.
document.querySelectorAll('.vd-nav-btn[data-panel]').forEach(btn => {
  btn.addEventListener('click', function() {
    document.querySelectorAll('.vd-nav-btn').forEach(b => b.classList.remove('active'));
    this.classList.add('active');
    const panel = this.dataset.panel;
    document.querySelectorAll('.vd-panel').forEach(p => p.classList.remove('show'));
    document.getElementById('vd-panel-' + panel).classList.add('show');
    if (panel === 'earnings') loadEarnings();
  });
});

// TRACE: Search and filter handlers — debounced 400ms to avoid rapid AJAX calls.
let searchTimer;
document.getElementById('vd-search').addEventListener('input', () => {
  clearTimeout(searchTimer); searchTimer = setTimeout(() => loadBookings(1), 400);
});
document.getElementById('vd-status-filter').addEventListener('change', () => loadBookings(1));
document.getElementById('vd-clear-filter').addEventListener('click', () => {
  document.getElementById('vd-search').value = '';
  document.getElementById('vd-status-filter').value = '';
  loadBookings(1);
});

// Boot
loadStats();
loadBookings(1);
})();
</script>
</div>
