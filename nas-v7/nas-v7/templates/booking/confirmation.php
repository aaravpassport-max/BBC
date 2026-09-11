<?php
/**
 * NAS Booking Confirmation — portal design
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$db          = \NAS\Core\Database::instance();
$cfg         = \NAS\Core\Config::instance();
$brand       = $cfg->get('brand_name', get_bloginfo('name'));
$curr_sym    = $cfg->get('currency_symbol','₹');

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
$login_url    = nas_get_page_url('nas_page_login','/newspaper-ad-login/');
$invoice_url  = $booking ? get_permalink() ?: home_url('/') . '?action=nas_download_invoice&booking_id=' . ($booking['id']??0) . '&nonce=' . wp_create_nonce('nas_pdf') : '';
?>
<div class="nas-portal-page nas-conf-page">
  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $booking && in_array( $gateway, [ 'razorpay', 'payu', 'stripe' ], true ) ) : ?>
  <div class="nas-conf-alert">
    <div class="nas-conf-alert__inner">
      <i class="fa-solid fa-circle-check" style="font-size:20px;color:#059669"></i>
      <span>Payment received successfully! Your booking has been confirmed.</span>
    </div>
  </div>
  <?php elseif ( $booking && $gateway === 'bank_transfer' ) : ?>
  <div class="nas-conf-alert">
    <div class="nas-conf-alert__inner nas-conf-alert__inner--pending">
      <i class="fa-solid fa-clock" style="font-size:20px;color:#ca8a04"></i>
      <span>Bank transfer reference submitted. Your booking will be confirmed once payment is verified.</span>
    </div>
  </div>
  <?php endif; ?>

  <div class="nas-conf-wrap">
    <div class="nas-conf-hero">
      <span class="nas-conf-anim"><i class="fa-solid fa-check"></i></span>
      <h1>Booking Confirmed!</h1>
      <p>Thank you<?php echo $booking && $booking['client_name'] ? ', ' . esc_html( $booking['client_name'] ) : ''; ?>! Your newspaper ad is on its way.</p>
      <?php if ( $uid ) : ?>
      <div class="nas-conf-uid">
        <span>Booking ID:</span>
        <strong id="conf-uid"><?php echo esc_html( $uid ); ?></strong>
        <button type="button" class="nas-conf-uid-copy" onclick="confCopyUID()" title="Copy booking ID"><i class="fa-regular fa-copy"></i></button>
      </div>
      <?php endif; ?>
      <?php if ( $client_email ) : ?>
      <p style="margin-top:12px;font-size:0.75rem;opacity:0.85">Confirmation sent to <strong><?php echo esc_html( $client_email ); ?></strong></p>
      <?php endif; ?>
    </div>

    <?php if ( $booking ) : ?>
    <div class="nas-conf-section">
      <div class="nas-conf-section-title"><i class="fa-solid fa-receipt"></i> Booking Summary</div>
      <div class="nas-conf-rows">
        <?php if ( $booking['newspaper_name'] ) : ?>
        <div class="nas-conf-row"><span>Newspaper</span><strong><?php echo esc_html( $booking['newspaper_name'] ); ?></strong></div>
        <?php endif; ?>
        <?php if ( $booking['city_name'] ) : ?>
        <div class="nas-conf-row"><span>City</span><strong><?php echo esc_html( $booking['city_name'] ); ?></strong></div>
        <?php endif; ?>
        <?php if ( $booking['category_name'] ) : ?>
        <div class="nas-conf-row"><span>Category</span><strong><?php echo esc_html( $booking['category_name'] ); ?></strong></div>
        <?php endif; ?>
        <?php if ( ! empty( $booking['ad_type'] ) ) : ?>
        <div class="nas-conf-row"><span>Ad Type</span><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $booking['ad_type'] ) ) ); ?></strong></div>
        <?php endif; ?>
        <?php if ( ! empty( $booking['publish_date'] ) ) : ?>
        <div class="nas-conf-row"><span>Publication Date</span><strong><?php echo esc_html( date( 'D, d M Y', strtotime( $booking['publish_date'] ) ) ); ?></strong></div>
        <?php endif; ?>
        <?php if ( $total > 0 ) : ?>
        <div class="nas-conf-row total-row"><span>Total Amount</span><strong><?php echo esc_html( $curr_sym . number_format( $total, 2 ) ); ?></strong></div>
        <?php endif; ?>
      </div>
      <?php if ( ! empty( $booking['ad_content'] ) ) : ?>
      <div class="nas-conf-ad-content">
        <div class="nas-conf-ad-content__label">Ad Content</div>
        <div class="nas-conf-ad-content__box"><?php echo esc_html( $booking['ad_content'] ); ?></div>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

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

    <div class="nas-conf-section">
      <div class="nas-conf-section-title"><i class="fa-solid fa-arrow-pointer"></i> Quick Actions</div>
      <div class="nas-conf-actions">
        <a href="<?php echo esc_url( $track_url ); ?>" class="nas-conf-btn nas-conf-btn-primary">
          <i class="fa-solid fa-location-crosshairs"></i> Track My Order
        </a>
        <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-conf-btn nas-conf-btn-outline">
          <i class="fa-solid fa-gauge-high"></i> My Dashboard
        </a>
        <?php if ( $booking && $invoice_url ) : ?>
        <a href="<?php echo esc_url( $invoice_url ); ?>" class="nas-conf-btn nas-conf-btn-outline" target="_blank" rel="noopener">
          <i class="fa-solid fa-file-pdf"></i> Download Invoice
        </a>
        <?php endif; ?>
        <?php
        $wa_number = $cfg->get('whatsapp_number','');
        $wa_msg    = $uid ? urlencode("Hi! I just placed a booking. My Order ID is #{$uid}") : '';
        if ( $wa_number && $wa_msg ) :
        ?>
        <a href="https://wa.me/<?php echo esc_attr( $wa_number ); ?>?text=<?php echo $wa_msg; ?>" class="nas-conf-btn nas-conf-btn-wa" target="_blank" rel="noopener">
          <i class="fa-brands fa-whatsapp"></i> Chat on WhatsApp
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url( $book_url ); ?>" class="nas-conf-btn nas-conf-btn-outline">
          <i class="fa-solid fa-plus"></i> Place Another Ad
        </a>
      </div>
    </div>

    <?php if ( ! is_user_logged_in() && $booking && $booking['client_email'] ) : ?>
    <div class="nas-conf-section" id="nas-conf-register-section">
      <div class="nas-conf-section-title"><i class="fa-solid fa-user-plus"></i> Create Your Account</div>
      <div style="font-size:0.9375rem;font-weight:700;color:var(--nas-text);margin-bottom:6px">Track and manage all your bookings in one place</div>
      <div style="font-size:0.8125rem;color:var(--nas-text-muted);line-height:1.65;margin-bottom:16px">
        Create a free account to track your order status live, view your proof, chat with our team, download invoices, and re-book easily.
      </div>
      <div id="nas-reg-form">
        <div class="nas-conf-reg-grid">
          <div>
            <label style="font-size:0.75rem;font-weight:700;color:var(--nas-text);display:block;margin-bottom:4px">Password *</label>
            <div style="position:relative">
              <input type="password" id="conf-reg-pass" class="nas-conf-reg-input" placeholder="Min 8 characters" oninput="confRegValidate()">
              <button type="button" onclick="confTogglePw()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--nas-text-muted)"><i class="fa-regular fa-eye" id="conf-pw-eye"></i></button>
            </div>
          </div>
          <div>
            <label style="font-size:0.75rem;font-weight:700;color:var(--nas-text);display:block;margin-bottom:4px">Confirm Password *</label>
            <input type="password" id="conf-reg-pass2" class="nas-conf-reg-input" placeholder="Repeat password">
          </div>
        </div>
        <div style="font-size:0.75rem;color:var(--nas-text-muted);margin-bottom:12px">
          Your account email: <strong><?php echo esc_html( $booking['client_email'] ); ?></strong>
        </div>
        <div id="conf-reg-error" class="nas-conf-reg-error"></div>
        <button type="button" id="conf-reg-btn" class="nas-conf-reg-btn" onclick="confCreateAccount()">
          <i class="fa-solid fa-user-plus"></i> Create My Account
        </button>
        <button type="button" onclick="document.getElementById('nas-conf-register-section').style.display='none'" style="margin-left:10px;background:none;border:none;color:var(--nas-text-muted);cursor:pointer;font-size:0.8125rem">No thanks</button>
      </div>
      <div id="nas-reg-success" class="nas-conf-reg-success">
        <i class="fa-solid fa-circle-check"></i> <strong>Account created!</strong> You can now log in with your email and password to track your order.
        <a href="<?php echo esc_url( $login_url ); ?>" style="display:inline-block;margin-top:10px;padding:8px 16px;background:linear-gradient(135deg,#F59E0B,#F97316);color:#fff;border-radius:999px;text-decoration:none;font-size:0.8125rem;font-weight:700">Login Now →</a>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php
  nas_portal_block_advantages();
  nas_portal_block_quick_links();
  nas_portal_block_cta( 'Need Help With Your Booking?', 'Our support team is available to assist with ad formatting, payment queries, and publication tracking.' );
  ?>
</div>

<script>
function confCopyUID(){
  var uid=document.getElementById('conf-uid')?.textContent||'';
  navigator.clipboard?.writeText(uid).then(()=>{
    var el=document.querySelector('.nas-conf-uid-copy');
    if(el){el.innerHTML='<i class="fa-solid fa-check"></i>';setTimeout(()=>el.innerHTML='<i class="fa-regular fa-copy"></i>',1500);}
  });
}
<?php if ( ! is_user_logged_in() && $booking && $booking['client_email'] ) : ?>
function confRegValidate() {
  var pass = document.getElementById('conf-reg-pass').value;
  var strength = document.getElementById('conf-reg-pass');
  strength.style.borderColor = pass.length >= 8 ? '#059669' : pass.length > 0 ? '#ef4444' : '';
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
  fd.append('nonce',  '<?php echo esc_js(wp_create_nonce("nas_action")); ?>');
  fd.append('booking_id', <?php echo (int)($booking['id']??0); ?>);
  fd.append('password', pass);
  fetch('<?php echo esc_js(get_permalink() ?: home_url('/')); ?>', {method:'POST', body:fd})
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
<?php endif; ?>
(function(){
  if(!window.requestAnimationFrame)return;
  var canvas=document.createElement('canvas');
  canvas.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999';
  document.body.appendChild(canvas);
  var ctx=canvas.getContext('2d'),W=canvas.width=window.innerWidth,H=canvas.height=window.innerHeight;
  var colors=['#1A3A5C','#0D9488','#F59E0B','#10b981','#F97316','#3b82f6'];
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
