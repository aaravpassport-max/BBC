<?php
/**
 * NAS Booking Confirmation Page v2 — Complete Redesign
 * Shows full booking summary, what-happens-next, actions
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$db          = \NAS\Core\Database::instance();
$cfg         = \NAS\Core\Config::instance();
$brand       = $cfg->get('brand_name', get_bloginfo('name'));
$logo_url    = $cfg->get('logo_url','');
$curr_sym    = $cfg->get('currency_symbol','₹');

// Get booking from multiple possible params
$booking_id  = (int)( $_GET['booking_id'] ?? 0 );
$booking_uid = sanitize_text_field( $_GET['ref'] ?? '' );
$gateway     = sanitize_text_field( $_GET['gateway'] ?? '' );

$booking = null;
if ( $booking_id ) {
    $booking = $db->row(
        "SELECT b.*,
                cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                n.name  AS newspaper_name, n.logo_url AS newspaper_logo,
                cat.name AS category_name,
                ci.name  AS city_name
         FROM {$db->t('bookings')} b
         LEFT JOIN {$db->t('clients')} cl  ON cl.id  = b.client_id
         LEFT JOIN {$db->t('newspapers')} n ON n.id  = b.newspaper_id
         LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
         LEFT JOIN {$db->t('cities')} ci   ON ci.id  = b.city_id
         WHERE b.id = %d",
        $booking_id
    );
} elseif ( $booking_uid ) {
    $booking = $db->row(
        "SELECT b.*,
                cl.name AS client_name, cl.email AS client_email, cl.phone AS client_phone,
                n.name  AS newspaper_name,
                cat.name AS category_name,
                ci.name  AS city_name
         FROM {$db->t('bookings')} b
         LEFT JOIN {$db->t('clients')} cl  ON cl.id  = b.client_id
         LEFT JOIN {$db->t('newspapers')} n ON n.id  = b.newspaper_id
         LEFT JOIN {$db->t('categories')} cat ON cat.id = b.category_id
         LEFT JOIN {$db->t('cities')} ci   ON ci.id  = b.city_id
         WHERE b.uid = %s",
        $booking_uid
    );
}

$uid          = $booking['uid'] ?? $booking_uid ?? '';
$client_email = $booking['client_email'] ?? ( is_user_logged_in() ? wp_get_current_user()->user_email : '' );
$total        = (float)( $booking['total_amount'] ?? 0 );
$dash_url     = nas_get_page_url('nas_page_client_dashboard','/client-dashboard/');
$track_url    = nas_get_page_url('nas_page_track_order','/track-order/');
$book_url     = nas_get_page_url('nas_page_booking','/book-newspaper-ad/');
$invoice_url  = $booking ? get_permalink() ?: home_url('/') . '?action=nas_download_invoice&booking_id=' . ($booking['id']??0) . '&nonce=' . wp_create_nonce('nas_pdf') : '';

$nav_links = [
    ['label'=>'Home',        'url'=>home_url('/')],
    ['label'=>'My Bookings', 'url'=>$dash_url],
    ['label'=>'Book an Ad',  'url'=>$book_url],
];
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>
<style>
.nas-conf-wrap{max-width:760px;margin:0 auto;padding:40px 20px 80px;font-family:'Inter','Segoe UI',sans-serif}
.nas-conf-hero{text-align:center;padding:48px 24px 36px;background:linear-gradient(135deg,#D7DBFF 0%,#F7F8FC 100%);border-radius:20px;margin-bottom:28px;position:relative;overflow:hidden}
.nas-conf-hero::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:rgba(108,71,255,.06)}
.nas-conf-anim{width:88px;height:88px;margin:0 auto 18px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(135deg,#2A8AFA,#202C39);box-shadow:0 12px 30px rgba(42,138,250,.35);animation:confPop .6s cubic-bezier(.175,.885,.32,1.275)}
.nas-conf-anim i{font-size:38px;color:#fff}
@keyframes confPop{0%{transform:scale(0) rotate(-20deg);opacity:0}100%{transform:scale(1) rotate(0);opacity:1}}
.nas-conf-hero h1{font-size:30px;font-weight:800;color:#2d1b69;margin:0 0 8px}
.nas-conf-hero p{font-size:15px;color:#6d5b9e;margin:0}
.nas-conf-uid{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1.5px solid #D7DBFF;border-radius:10px;padding:10px 20px;margin-top:16px;font-size:13px;color:#202C39}
.nas-conf-uid strong{font-size:16px;font-family:monospace;letter-spacing:1px}
.nas-conf-uid-copy{background:none;border:none;cursor:pointer;color:#2A8AFA;padding:0 0 0 8px;font-size:14px}
.nas-conf-section{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:24px;margin-bottom:20px}
.nas-conf-section-title{font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#94a3b8;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.nas-conf-rows{display:flex;flex-direction:column;gap:1px;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden}
.nas-conf-row{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#fff;font-size:14px}
.nas-conf-row span{color:#64748b}
.nas-conf-row strong{color:#0f172a;text-align:right}
.nas-conf-row.total-row{background:#D7DBFF}
.nas-conf-row.total-row span,.nas-conf-row.total-row strong{font-size:16px;font-weight:800;color:#202C39}
.nas-conf-steps{counter-reset:step;display:flex;flex-direction:column;gap:1px}
.nas-conf-step{display:flex;align-items:flex-start;gap:16px;padding:16px 0;border-bottom:1px solid #f1f5f9}
.nas-conf-step:last-child{border-bottom:none}
.nas-conf-step-num{counter-increment:step;width:36px;height:36px;border-radius:50%;background:#2A8AFA;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;font-weight:700}
.nas-conf-step-body strong{display:block;font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px}
.nas-conf-step-body p{font-size:13px;color:#64748b;margin:0;line-height:1.6}
.nas-conf-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:8px}
.nas-conf-btn{display:inline-flex;align-items:center;gap:8px;padding:13px 22px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s;text-decoration:none;border:2px solid transparent}
.nas-conf-btn-primary{background:#2A8AFA;color:#fff;border-color:#2A8AFA}
.nas-conf-btn-primary:hover{background:#1B6FD8}
.nas-conf-btn-outline{background:#fff;color:#2A8AFA;border-color:#2A8AFA}
.nas-conf-btn-outline:hover{background:#D7DBFF}
.nas-conf-btn-wa{background:#25D366;color:#fff;border-color:#25D366}
.nas-conf-btn-wa:hover{background:#1ebe5a}
.nas-conf-alert{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;font-size:13px;color:#166534;margin-bottom:20px}

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 640px) {
  .nas-conf-hero{padding:36px 18px 28px}
  .nas-conf-uid{min-width:0;width:100%;box-sizing:border-box;justify-content:center;flex-wrap:wrap}
  .nas-conf-section{padding:18px}
  .nas-reg-grid{grid-template-columns:1fr !important}
  .nas-conf-actions{flex-direction:column;align-items:stretch}
  .nas-conf-actions .nas-conf-btn{justify-content:center}
}
</style>

<?php if($booking): ?>
<!-- Payment success alert if coming from payment gateway -->
<?php if(in_array($gateway,['razorpay','payu','stripe'])): ?>
<div class="nas-conf-alert" style="max-width:760px;margin:20px auto 0;padding:0 20px">
  <i class="fa-solid fa-circle-check" style="font-size:20px;color:#059669"></i>
  <span>Payment received successfully! Your booking has been confirmed.</span>
</div>
<?php elseif($gateway==='bank_transfer'): ?>
<div class="nas-conf-alert" style="max-width:760px;margin:20px auto 0;padding:0 20px;background:#fefce8;border-color:#fde68a;color:#854d0e">
  <i class="fa-solid fa-clock" style="font-size:20px;color:#ca8a04"></i>
  <span>Bank transfer reference submitted. Your booking will be confirmed once payment is verified.</span>
</div>
<?php endif; ?>
<?php endif; ?>

<div class="nas-conf-wrap">

  <!-- Hero -->
  <div class="nas-conf-hero">
    <span class="nas-conf-anim"><i class="fa-solid fa-check"></i></span>
    <h1>Booking Confirmed!</h1>
    <p>Thank you<?= $booking && $booking['client_name'] ? ', '.esc_html($booking['client_name']) : '' ?>! Your newspaper ad is on its way.</p>
    <?php if($uid): ?>
    <div class="nas-conf-uid">
      <span>Booking ID:</span>
      <strong id="conf-uid"><?= esc_html($uid) ?></strong>
      <button class="nas-conf-uid-copy" onclick="confCopyUID()" title="Copy booking ID"><i class="fa-regular fa-copy"></i></button>
    </div>
    <?php endif; ?>
    <?php if($client_email): ?>
    <p style="margin-top:10px;font-size:12px;color:#7c5cb5">Confirmation sent to <strong><?= esc_html($client_email) ?></strong></p>
    <?php endif; ?>
  </div>

  <?php if($booking): ?>
  <!-- Booking Summary -->
  <div class="nas-conf-section">
    <div class="nas-conf-section-title"><i class="fa-solid fa-receipt"></i> Booking Summary</div>
    <div class="nas-conf-rows">
      <?php if($booking['newspaper_name']): ?>
      <div class="nas-conf-row"><span>Newspaper</span><strong><?= esc_html($booking['newspaper_name']) ?></strong></div>
      <?php endif; ?>
      <?php if($booking['city_name']): ?>
      <div class="nas-conf-row"><span>City</span><strong><?= esc_html($booking['city_name']) ?></strong></div>
      <?php endif; ?>
      <?php if($booking['category_name']): ?>
      <div class="nas-conf-row"><span>Category</span><strong><?= esc_html($booking['category_name']) ?></strong></div>
      <?php endif; ?>
      <?php if(!empty($booking['ad_type'])): ?>
      <div class="nas-conf-row"><span>Ad Type</span><strong><?= esc_html(ucwords(str_replace('_',' ',$booking['ad_type']))) ?></strong></div>
      <?php endif; ?>
      <?php if(!empty($booking['publish_date'])): ?>
      <div class="nas-conf-row"><span>Publication Date</span><strong><?= date('D, d M Y', strtotime($booking['publish_date'])) ?></strong></div>
      <?php endif; ?>
      <?php if($total > 0): ?>
      <div class="nas-conf-row total-row"><span>Total Amount</span><strong><?= esc_html($curr_sym.number_format($total,2)) ?></strong></div>
      <?php endif; ?>
    </div>
    <?php if(!empty($booking['ad_content'])): ?>
    <div style="margin-top:16px">
      <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:8px">Ad Content</div>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;font-size:13px;line-height:1.7;white-space:pre-wrap"><?= esc_html($booking['ad_content']) ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- What Happens Next -->
  <div class="nas-conf-section">
    <div class="nas-conf-section-title"><i class="fa-solid fa-road"></i> What Happens Next</div>
    <div class="nas-conf-steps">
      <div class="nas-conf-step">
        <div class="nas-conf-step-num"><i class="fa-solid fa-magnifying-glass" style="font-size:14px"></i></div>
        <div class="nas-conf-step-body">
          <strong>Review &amp; Verification</strong>
          <p>Our team reviews your booking and ad content. This usually takes 1–2 business hours. You'll receive a WhatsApp/email notification once done.</p>
        </div>
      </div>
      <div class="nas-conf-step">
        <div class="nas-conf-step-num"><i class="fa-solid fa-file-invoice" style="font-size:14px"></i></div>
        <div class="nas-conf-step-body">
          <strong>Payment &amp; Confirmation</strong>
          <p>If payment is pending, we'll share payment details via WhatsApp or email. Once payment is confirmed, your booking is locked in.</p>
        </div>
      </div>
      <div class="nas-conf-step">
        <div class="nas-conf-step-num"><i class="fa-solid fa-palette" style="font-size:14px"></i></div>
        <div class="nas-conf-step-body">
          <strong>Proof &amp; Approval</strong>
          <p>We'll send you a proof of how your ad will look in the newspaper. You can approve it or request changes from your dashboard.</p>
        </div>
      </div>
      <div class="nas-conf-step">
        <div class="nas-conf-step-num"><i class="fa-solid fa-newspaper" style="font-size:14px"></i></div>
        <div class="nas-conf-step-body">
          <strong>Publication &amp; Tear Sheet</strong>
          <p>Your ad is published on the scheduled date. We'll send you a tear sheet (photo proof from the newspaper) within 2–5 days of publication.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- Actions -->
  <div class="nas-conf-section">
    <div class="nas-conf-section-title"><i class="fa-solid fa-arrow-pointer"></i> Quick Actions</div>
    <div class="nas-conf-actions">
      <a href="<?= esc_url($dash_url) ?>" class="nas-conf-btn nas-conf-btn-primary">
        <i class="fa-solid fa-gauge-high"></i> Track My Booking
      </a>
      <?php if($booking && $invoice_url): ?>
      <a href="<?= esc_url($invoice_url) ?>" class="nas-conf-btn nas-conf-btn-outline" target="_blank">
        <i class="fa-solid fa-file-pdf"></i> Download Invoice
      </a>
      <?php endif; ?>
      <?php
      $wa_number = $cfg->get('whatsapp_number','');
      $wa_msg    = $uid ? urlencode("Hi! I just placed a booking. My Order ID is #{$uid}") : '';
      if($wa_number && $wa_msg):
      ?>
      <a href="https://wa.me/<?= esc_attr($wa_number) ?>?text=<?= $wa_msg ?>" class="nas-conf-btn nas-conf-btn-wa" target="_blank" rel="noopener">
        <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
      </a>
      <?php endif; ?>
      <a href="<?= esc_url($book_url) ?>" class="nas-conf-btn nas-conf-btn-outline">
        <i class="fa-solid fa-plus"></i> Place Another Ad
      </a>
    </div>
  </div>


  <?php if(!is_user_logged_in() && $booking && $booking['client_email']): ?>
  <!-- Client Account Creation Offer -->
  <div class="nas-conf-section" id="nas-conf-register-section">
    <div class="nas-conf-section-title"><i class="fa-solid fa-user-plus"></i> Create Your Account</div>
    <div style="display:flex;align-items:flex-start;gap:16px;flex-wrap:wrap">
      <div style="flex:1;min-width:240px">
        <div style="font-size:15px;font-weight:700;color:#0f172a;margin-bottom:6px">Track and manage all your bookings in one place</div>
        <div style="font-size:13px;color:#64748b;line-height:1.6;margin-bottom:16px">
          Create a free account to: track your order status live, view your proof, chat with our team, download invoices, and re-book easily.
        </div>
        <div id="nas-reg-form">
          <div class="nas-reg-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
            <div>
              <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px">Password *</label>
              <div style="position:relative">
                <input type="password" id="conf-reg-pass" placeholder="Min 8 characters" style="width:100%;padding:9px 36px 9px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;outline:none" oninput="confRegValidate()">
                <button type="button" onclick="confTogglePw()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:14px"><i class="fa-regular fa-eye" id="conf-pw-eye"></i></button>
              </div>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:#374151;display:block;margin-bottom:4px">Confirm Password *</label>
              <input type="password" id="conf-reg-pass2" placeholder="Repeat password" style="width:100%;padding:9px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;outline:none">
            </div>
          </div>
          <div style="font-size:12px;color:#64748b;margin-bottom:12px">
            Your account email: <strong><?= esc_html($booking['client_email']) ?></strong> · Username will be auto-generated from your name.
          </div>
          <div id="conf-reg-error" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:10px"></div>
          <button id="conf-reg-btn" onclick="confCreateAccount()" style="display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:#059669;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;transition:all .15s">
            <i class="fa-solid fa-user-plus"></i> Create My Account
          </button>
          <button onclick="document.getElementById('nas-conf-register-section').style.display='none'" style="margin-left:10px;background:none;border:none;color:#94a3b8;cursor:pointer;font-size:13px">No thanks</button>
        </div>
        <div id="nas-reg-success" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 16px;color:#166534;font-size:13px">
          <i class="fa-solid fa-circle-check"></i> <strong>Account created!</strong> You can now log in with your email and password to track your order.
          <a href="<?= esc_url(home_url('/newspaper-ad-login/')) ?>" style="display:inline-block;margin-top:10px;padding:8px 16px;background:#059669;color:#fff;border-radius:6px;text-decoration:none;font-size:13px;font-weight:700">Login Now →</a>
        </div>
      </div>
    </div>
  </div>
  <script>
  function confRegValidate() {
    var pass = document.getElementById('conf-reg-pass').value;
    var strength = document.getElementById('conf-reg-pass');
    strength.style.borderColor = pass.length >= 8 ? '#059669' : pass.length > 0 ? '#ef4444' : '#e2e8f0';
  }
  function confTogglePw() {
    var inp = document.getElementById('conf-reg-pass');
    var eye = document.getElementById('conf-pw-eye');
    inp.type = inp.type === 'password' ? 'text' : 'password';
    eye.className = inp.type === 'password' ? 'fa-regular fa-eye' : 'fa-regular fa-eye-slash';
  }
  function confCreateAccount() {
    var pass  = document.getElementById('conf-reg-pass').value;
    var pass2 = document.getElementById('conf-reg-pass2').value;
    var errEl = document.getElementById('conf-reg-error');
    var btn   = document.getElementById('conf-reg-btn');
    errEl.style.display = 'none';
    if (pass.length < 8)  { errEl.textContent='Password must be at least 8 characters.'; errEl.style.display='block'; return; }
    if (pass !== pass2)   { errEl.textContent='Passwords do not match.'; errEl.style.display='block'; return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Creating account…';
    var fd = new FormData();
    fd.append('action', 'nas_create_client_account_from_booking');
    fd.append('nonce',  '<?= esc_js(wp_create_nonce("nas_action")) ?>');
    fd.append('booking_id', <?= (int)($booking['id']??0) ?>);
    fd.append('password', pass);
    fetch('<?= esc_js(get_permalink() ?: home_url('/')) ?>', {method:'POST', body:fd})
      .then(r=>r.json())
      .then(function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Create My Account';
        if (res.success) {
          document.getElementById('nas-reg-form').style.display = 'none';
          document.getElementById('nas-reg-success').style.display = 'block';
        } else {
          errEl.textContent = res.data?.message || 'Account creation failed. Please try again.';
          errEl.style.display = 'block';
        }
      })
      .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Create My Account';
        errEl.textContent = 'Network error. Please try again.';
        errEl.style.display = 'block';
      });
  }
  </script>
  <?php endif; ?>
</div><!-- /wrap -->

<script>
function confCopyUID(){
  var uid=document.getElementById('conf-uid')?.textContent||'';
  navigator.clipboard?.writeText(uid).then(()=>{
    var el=document.querySelector('.nas-conf-uid-copy');
    if(el){el.innerHTML='<i class="fa-solid fa-check"></i>';setTimeout(()=>el.innerHTML='<i class="fa-regular fa-copy"></i>',1500);}
  });
}
// Confetti effect
(function(){
  if(!window.requestAnimationFrame)return;
  var canvas=document.createElement('canvas');
  canvas.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999';
  document.body.appendChild(canvas);
  var ctx=canvas.getContext('2d'),W=canvas.width=window.innerWidth,H=canvas.height=window.innerHeight;
  var colors=['#2A8AFA','#a78bfa','#f59e0b','#10b981','#ef4444','#3b82f6'];
  var particles=Array.from({length:100},()=>({
    x:Math.random()*W,y:Math.random()*H*-.5,r:Math.random()*6+3,
    c:colors[Math.floor(Math.random()*colors.length)],
    vx:(Math.random()-.5)*4,vy:Math.random()*3+2,
    angle:Math.random()*360,va:(Math.random()-.5)*8
  }));
  var frame=0;
  function draw(){
    ctx.clearRect(0,0,W,H);
    particles.forEach(function(p){
      ctx.save();ctx.translate(p.x,p.y);ctx.rotate(p.angle*Math.PI/180);
      ctx.fillStyle=p.c;ctx.fillRect(-p.r/2,-p.r/2,p.r,p.r);ctx.restore();
      p.x+=p.vx;p.y+=p.vy;p.angle+=p.va;p.vy+=.08;
    });
    particles=particles.filter(p=>p.y<H+20);
    if(++frame<120&&particles.length)requestAnimationFrame(draw);
    else canvas.remove();
  }
  setTimeout(draw,400);
})();
</script>
