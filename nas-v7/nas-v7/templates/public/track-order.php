<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$nonce       = wp_create_nonce( 'nas_action' );
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
$faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
$dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Order Tracking</span>
      <h1>Track Your <span>Newspaper Ad</span></h1>
      <p>Enter your Order ID and email to see real-time status from submission to publication.</p>
    </div>

    <div class="nas-faq-layout">
      <aside class="nas-faq-sidebar">
        <div class="nas-faq-help-card">
          <div class="nas-faq-help-card__icon"><i class="fa-solid fa-circle-info"></i></div>
          <h3>Need help tracking?</h3>
          <p>Check your booking confirmation email for the Order ID, or log in to your dashboard for full details.</p>
          <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-btn nas-btn-primary">My Dashboard</a>
          <a href="<?php echo esc_url( $faq_url ); ?>" class="nas-btn nas-btn-secondary" style="margin-top:10px;display:flex;justify-content:center">View FAQ</a>
        </div>
      </aside>

      <div class="nas-portal-card" style="background:#fff;border:1.5px solid var(--nas-border);border-radius:20px;padding:32px;box-shadow:0 8px 32px rgba(12,18,34,.06)">
        <h2 style="font-family:var(--nas-font-display);font-size:1.25rem;font-weight:800;margin:0 0 8px;color:var(--nas-text)"><i class="fa-solid fa-magnifying-glass" style="color:var(--nas-teal,#0D9488);margin-right:8px"></i> Track Your Order</h2>
        <p style="color:var(--nas-text-muted);margin:0 0 24px;font-size:0.9375rem">Enter your Order ID and the email address used during booking.</p>

        <div class="nas-track-form" style="display:flex;flex-direction:column;gap:14px">
          <div class="nas-field">
            <label for="nas-oid">Order ID</label>
            <input type="text" id="nas-oid" placeholder="e.g. BK-10042" autocomplete="off">
          </div>
          <div class="nas-field">
            <label for="nas-temail">Email Address</label>
            <input type="email" id="nas-temail" placeholder="email@example.com">
          </div>
          <button type="button" class="nas-submit-btn" onclick="nasTrackOrder()">Track Order</button>
        </div>

        <div class="nas-result" id="nas-result" style="margin-top:28px;display:none">
          <div id="nas-status-badge" class="nas-status-badge" style="display:inline-block;padding:6px 14px;border-radius:999px;font-size:0.75rem;font-weight:700;background:var(--nas-primary);color:#fff;margin-bottom:16px"></div>
          <div class="nas-booking-info" id="nas-booking-info" style="background:#f8fafc;border-radius:12px;padding:16px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:8px"></div>
          <div class="nas-timeline" id="nas-timeline"></div>
          <div id="nas-track-proof" style="display:none;margin-top:20px;background:#fff;border:1.5px solid var(--nas-border);border-radius:14px;overflow:hidden">
            <div style="padding:14px 20px;border-bottom:1px solid var(--nas-border);font-weight:700;font-size:0.875rem;color:var(--nas-text)">
              <i class="fa-solid fa-image" style="color:var(--nas-teal,#0D9488);margin-right:8px"></i> Ad Proof
            </div>
            <div style="padding:20px">
              <p style="font-size:0.8125rem;color:var(--nas-text-muted);margin:0 0 12px">Review how your ad will appear in the newspaper.</p>
              <a id="nas-proof-link" href="#" target="_blank" rel="noopener">
                <img id="nas-proof-img" src="" alt="Ad Proof" style="max-width:100%;border-radius:10px;border:1px solid var(--nas-border);cursor:zoom-in;display:block">
              </a>
              <div id="nas-proof-actions" style="margin-top:16px;display:none">
                <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-btn nas-btn-primary" style="display:inline-flex"><i class="fa-solid fa-gauge-high"></i> Go to My Dashboard</a>
              </div>
            </div>
          </div>
        </div>

        <div id="nas-track-error" class="nas-form-error" style="display:none;margin-top:16px"></div>
      </div>
    </div>
  </div>
</div>

<style>
.nas-timeline-item{display:flex;gap:16px;margin-bottom:16px}
.nas-timeline-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;margin-top:4px;border:2px solid var(--nas-primary);background:#fff}
.nas-timeline-dot.done{background:var(--nas-primary)}
.nas-timeline-dot.current{background:var(--nas-primary);box-shadow:0 0 0 4px rgba(26,58,92,.15)}
.nas-timeline-content h4{margin:0 0 2px;font-size:0.875rem;color:var(--nas-text)}
.nas-timeline-content p{margin:0;font-size:0.75rem;color:var(--nas-text-muted)}
.nas-bi{font-size:0.8125rem;color:var(--nas-text)}
.nas-bi strong{display:block;font-size:0.6875rem;text-transform:uppercase;color:var(--nas-text-muted);font-weight:600;margin-bottom:2px}
@media(max-width:600px){.nas-booking-info{grid-template-columns:1fr!important}}
</style>

<script>
var NAS_TRACK_AJAX = '<?php echo esc_js( get_permalink() ?: home_url( '/' ) ); ?>';
var NAS_TRACK_NONCE = '<?php echo esc_js( $nonce ); ?>';

var STATUS_LABELS = {
  booking_received:'Booking Received', under_review:'Under Review',
  payment_received:'Payment Confirmed', material_uploaded:'Material Uploaded',
  material_approved:'Material Approved', material_rejected:'Material Rejected',
  submitted_to_paper:'Submitted to Newspaper', pub_date_confirmed:'Publication Date Confirmed',
  published:'Ad Published', proof_delivered:'Proof Delivered', proof_ready:'Proof Ready',
  completed:'Order Completed', cancelled:'Cancelled', on_hold:'On Hold'
};

var STATUS_ORDER = ['booking_received','under_review','payment_received','material_uploaded',
  'material_approved','submitted_to_paper','pub_date_confirmed','published','proof_delivered','completed'];

function nasTrackOrder() {
  var oid = document.getElementById('nas-oid').value.trim();
  var email = document.getElementById('nas-temail').value.trim();
  var errEl = document.getElementById('nas-track-error');
  errEl.style.display = 'none';
  if (!oid || !email) {
    errEl.textContent = 'Please enter both Order ID and Email';
    errEl.style.display = 'block';
    return;
  }
  jQuery.post(NAS_TRACK_AJAX, { action:'nas_track_order', nonce:NAS_TRACK_NONCE, order_id:oid, email:email }, function(r) {
    if (!r.success) {
      errEl.textContent = r.data.message;
      errEl.style.display = 'block';
      document.getElementById('nas-result').style.display = 'none';
      return;
    }
    renderResult(r.data.booking, r.data.history || []);
  });
}

function renderResult(b, history) {
  document.getElementById('nas-result').style.display = 'block';
  var status = b.status;
  document.getElementById('nas-status-badge').textContent = STATUS_LABELS[status] || status;

  if (b.proof_url) {
    document.getElementById('nas-proof-img').src = b.proof_url;
    document.getElementById('nas-proof-link').href = b.proof_url;
    document.getElementById('nas-track-proof').style.display = 'block';
    if (b.status === 'proof_ready') {
      document.getElementById('nas-proof-actions').style.display = 'block';
    }
  }

  document.getElementById('nas-booking-info').innerHTML = [
    ['Order ID', '#'+b.uid], ['Newspaper', b.newspaper_name],
    ['City', b.city_name], ['Category', b.category_name],
    ['Email', b.client_email]
  ].map(function(r) {
    return '<div class="nas-bi"><strong>'+r[0]+'</strong>'+escHtml(r[1])+'</div>';
  }).join('');

  var currentIdx = STATUS_ORDER.indexOf(status);
  var timeline = document.getElementById('nas-timeline');
  timeline.innerHTML = '';
  STATUS_ORDER.forEach(function(s, i) {
    if (i > currentIdx && s !== status) return;
    var isDone = i < currentIdx;
    var isCurrent = s === status;
    var dotClass = isDone ? 'done' : (isCurrent ? 'current' : '');
    var histItem = history.find(function(h){ return h.status === s; });
    var time = histItem ? new Date(histItem.time).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
    var note = histItem && histItem.note ? ' — ' + escHtml(histItem.note) : '';
    timeline.innerHTML += '<div class="nas-timeline-item"><div class="nas-timeline-dot '+dotClass+'"></div><div class="nas-timeline-content"><h4>'+(STATUS_LABELS[s]||s)+'</h4><p>'+(time||'')+note+'</p></div></div>';
  });
}

function escHtml(t){ return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>
