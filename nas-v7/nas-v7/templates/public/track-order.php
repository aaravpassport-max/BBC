<?php if ( ! defined( 'ABSPATH' ) ) exit;
$nonce = wp_create_nonce('nas_action');
$cfg   = \NAS\Core\Config::instance();
$color = $cfg->get('brand_primary_color','#1A3A5C');
$_nas_nav_links = [
    ['label' => 'Home',       'url' => home_url('/')],
    ['label' => 'Book an Ad', 'url' => nas_get_page_url('nas_page_booking','/book-newspaper-ad/')],
    ['label' => 'My Bookings','url' => nas_get_page_url('nas_page_client_dashboard','/client-dashboard/')],
];
$nav_links = $_nas_nav_links;
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<link rel="stylesheet" href="<?php echo NAS_ASSETS; ?>css/nas-core.css">
<style>
.nas-track{max-width:700px;margin:40px auto;padding:0 16px;font-family:'Inter','Segoe UI',sans-serif}
.nas-track-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:32px;box-shadow:0 4px 20px rgba(0,0,0,.07)}
.nas-track-card h2{color:<?php echo esc_js($color); ?>;margin:0 0 8px;font-size:22px}
.nas-track-card p{color:#64748b;margin:0 0 24px}
.nas-track-form{display:flex;flex-direction:column;gap:14px}
.nas-field label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
.nas-field input{width:100%;padding:11px 14px;border:1.5px solid #d0d7de;border-radius:8px;font-size:14px;transition:border .2s;box-sizing:border-box}
.nas-field input:focus{outline:none;border-color:<?php echo esc_js($color); ?>}
.nas-track-btn{padding:13px;background:<?php echo esc_js($color); ?>;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;width:100%;transition:opacity .2s}
.nas-track-btn:hover{opacity:.9}
.nas-result{margin-top:28px;display:none}
.nas-timeline{position:relative;padding:0}
.nas-timeline-item{display:flex;gap:16px;margin-bottom:16px}
.nas-timeline-dot{width:14px;height:14px;border-radius:50%;flex-shrink:0;margin-top:3px;border:2px solid <?php echo esc_js($color); ?>;background:#fff}
.nas-timeline-dot.done{background:<?php echo esc_js($color); ?>}
.nas-timeline-dot.current{background:<?php echo esc_js($color); ?>;box-shadow:0 0 0 4px <?php echo esc_js($color); ?>33}
.nas-timeline-content h4{margin:0 0 2px;font-size:14px;color:#1e293b}
.nas-timeline-content p{margin:0;font-size:12px;color:#64748b}
.nas-status-badge{display:inline-block;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:700;background:<?php echo esc_js($color); ?>;color:#fff;margin-bottom:16px}
.nas-booking-info{background:#f8fafc;border-radius:10px;padding:16px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:8px}
.nas-bi{font-size:13px;color:#374151}
.nas-bi strong{display:block;font-size:11px;text-transform:uppercase;color:#94a3b8;font-weight:600;margin-bottom:2px}
</style>

<div class="nas-track">
  <div class="nas-track-card">
    <h2>🔍 Track Your Order</h2>
    <p>Enter your Order ID and the email address used during booking.</p>

    <div class="nas-track-form">
      <div class="nas-field">
        <label for="nas-oid">Order ID</label>
        <input type="text" id="nas-oid" placeholder="e.g. BK-10042" autocomplete="off">
      </div>
      <div class="nas-field">
        <label for="nas-temail">Email Address</label>
        <input type="email" id="nas-temail" placeholder="email@example.com">
      </div>
      <button class="nas-track-btn" onclick="nasTrackOrder()">Track Order</button>
    </div>

    <div class="nas-result" id="nas-result">
      <div id="nas-status-badge" class="nas-status-badge"></div>
      <div class="nas-booking-info" id="nas-booking-info"></div>
      <div class="nas-timeline" id="nas-timeline"></div>
    </div>

    <div id="nas-track-error" style="display:none;margin-top:16px;padding:12px 16px;background:#fef2f2;border-radius:8px;color:#dc2626;font-size:14px"></div>
  </div>
</div>

<script>
var NAS_TRACK_AJAX = '<?php echo esc_js(get_permalink() ?: home_url('/')); ?>';
var NAS_TRACK_NONCE = '<?php echo $nonce; ?>';

var STATUS_LABELS = {
    'booking_received':'Booking Received','under_review':'Under Review',
    'payment_received':'Payment Confirmed','material_uploaded':'Material Uploaded',
    'material_uploaded':'Material Under Review','material_approved':'Material Approved',
    'material_rejected':'Material Rejected — Re-upload','submitted_to_paper':'Submitted to Newspaper',
    'pub_date_confirmed':'Publication Date Confirmed','published':'Ad Published',
    'proof_delivered':'Proof Delivered','completed':'Order Completed',
    'cancelled':'Cancelled','on_hold':'On Hold'
};

var STATUS_ORDER = ['booking_received','under_review','payment_received','material_uploaded',
    'material_uploaded','material_approved','submitted_to_paper','pub_date_confirmed',
    'published','proof_delivered','completed'];

function nasTrackOrder() {
    var oid   = document.getElementById('nas-oid').value.trim();
    var email = document.getElementById('nas-temail').value.trim();
    var errEl = document.getElementById('nas-track-error');
    errEl.style.display = 'none';
    if (!oid || !email) { errEl.textContent = 'Please enter both Order ID and Email'; errEl.style.display='block'; return; }

    jQuery.post(NAS_TRACK_AJAX, {action:'nas_track_order',nonce:NAS_TRACK_NONCE,order_id:oid,email:email}, function(r) {
        if (!r.success) { errEl.textContent = r.data.message; errEl.style.display='block'; document.getElementById('nas-result').style.display='none'; return; }
        renderResult(r.data.booking, r.data.history);
    });
}

function renderResult(b, history) {
    document.getElementById('nas-result').style.display = 'block';

    var status = b.status;
    var label  = STATUS_LABELS[status] || status;
    document.getElementById('nas-status-badge').textContent = label;
      // Show proof image if available
      if (b.proof_url) {
        var proofDiv  = document.getElementById('nas-track-proof');
        var proofImg  = document.getElementById('nas-proof-img');
        var proofLink = document.getElementById('nas-proof-link');
        if (proofDiv && proofImg) {
          proofImg.src    = b.proof_url;
          proofLink.href  = b.proof_url;
          proofDiv.style.display = 'block';
          // Show approve actions if status is proof_ready
          if (b.status === 'proof_ready') {
            document.getElementById('nas-proof-actions').style.display = 'block';
          }
        }
      }

    var info = document.getElementById('nas-booking-info');
    info.innerHTML = [
        ['Order ID', '#'+b.uid], ['Newspaper', b.newspaper_name],
        ['City', b.city_name], ['Category', b.category_name],
        ['Email', b.client_email]
    ].map(r => '<div class="nas-bi"><strong>'+r[0]+'</strong>'+escHtml(r[1])+'</div>').join('');

    var currentIdx = STATUS_ORDER.indexOf(status);
    var timeline   = document.getElementById('nas-timeline');
    timeline.innerHTML = '';

    STATUS_ORDER.forEach(function(s, i) {
        var isDone    = i < currentIdx;
        var isCurrent = s === status;
        var dotClass  = isDone ? 'done' : (isCurrent ? 'current' : '');
        var histItem  = history.find(function(h){return h.status===s});
        var time      = histItem ? new Date(histItem.time).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}) : '';
        var note      = histItem ? histItem.note : '';
        if (i > currentIdx && !isCurrent) return; // only show completed + current steps
        timeline.innerHTML += '<div class="nas-timeline-item"><div class="nas-timeline-dot '+dotClass+'"></div><div class="nas-timeline-content"><h4>'+(STATUS_LABELS[s]||s)+'</h4><p>'+(time ? time : '')+(note ? ' — '+escHtml(note) : '')+'</p></div>
      <!-- Proof image display (when available) -->
      <div id="nas-track-proof" style="display:none;margin-top:20px;background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;overflow:hidden">
        <div style="padding:14px 20px;border-bottom:1px solid #e2e8f0;font-weight:700;font-size:14px;color:#0f172a">
          <i class="fa-solid fa-image" style="color:#6c47ff;margin-right:8px"></i>Ad Proof
        </div>
        <div style="padding:20px">
          <p style="font-size:13px;color:#64748b;margin:0 0 12px">This is how your ad will appear in the newspaper. Please review carefully.</p>
          <a id="nas-proof-link" href="#" target="_blank">
            <img id="nas-proof-img" src="" alt="Ad Proof" style="max-width:100%;border-radius:10px;border:1px solid #e2e8f0;cursor:zoom-in;display:block">
          </a>
          <div id="nas-proof-actions" style="margin-top:16px;display:none">
            <div style="font-size:13px;font-weight:600;color:#374151;margin-bottom:10px">Have changes? Login to your dashboard to approve or request modifications.</div>
            <a href="/client-dashboard/" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:#6c47ff;color:#fff;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700">
              <i class="fa-solid fa-gauge-high"></i> Go to My Dashboard
            </a>
          </div>
        </div>
      </div>
</div>';
    });
}

function escHtml(t){return (t||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}
</script>
