<?php if ( ! defined( 'ABSPATH' ) ) exit;
$nonce = wp_create_nonce('nas_action');
$cfg   = \NAS\Core\Config::instance();
$color = $cfg->get('brand_primary_color','#1A3A5C');
$phone = $cfg->get('brand_phone','');
$email = $cfg->get('brand_email','');
$addr  = $cfg->get('brand_address','');
$wa    = $cfg->get('brand_whatsapp','');
$_nas_nav_links = [
    ['label' => 'Home',       'url' => home_url('/')],
    ['label' => 'Book an Ad', 'url' => nas_get_page_url('nas_page_booking','/book-newspaper-ad/')],
    ['label' => 'My Bookings','url' => nas_get_page_url('nas_page_client_dashboard','/client-dashboard/')],
];
$nav_links = $_nas_nav_links;
include NAS_PLUGIN_DIR . 'templates/partials/top-nav.php';
?>

<div class="nas-portal-page">
<div class="nas-portal-wrap">
  <div class="nas-portal-hero">
    <span class="nas-portal-hero__eyebrow">Get in Touch</span>
    <h1>Contact <span>Our Team</span></h1>
    <p>Questions about rates, publications, or your booking? We're here to help.</p>
  </div>

<div class="nas-contact-wrap" style="max-width:none;padding:0;margin:0">
  <div class="nas-contact-grid">

    <!-- Contact Info Panel -->
    <div class="nas-contact-info">
      <h2>Get In Touch</h2>
      <p>Our team is here to help you book the perfect newspaper ad.</p>
      <?php if($phone): ?>
      <div class="nas-ci-row">
        <div class="nas-ci-icon">📞</div>
        <div class="nas-ci-text"><strong>Phone</strong><span><?php echo esc_html($phone); ?></span></div>
      </div>
      <?php endif; ?>
      <?php if($wa): ?>
      <div class="nas-ci-row">
        <div class="nas-ci-icon">💬</div>
        <div class="nas-ci-text"><strong>WhatsApp</strong><span><a href="https://wa.me/<?php echo preg_replace('/[^0-9]/','',$wa); ?>" style="color:#fff"><?php echo esc_html($wa); ?></a></span></div>
      </div>
      <?php endif; ?>
      <?php if($email): ?>
      <div class="nas-ci-row">
        <div class="nas-ci-icon">✉️</div>
        <div class="nas-ci-text"><strong>Email</strong><span><a href="mailto:<?php echo esc_attr($email); ?>" style="color:#fff"><?php echo esc_html($email); ?></a></span></div>
      </div>
      <?php endif; ?>
      <?php if($addr): ?>
      <div class="nas-ci-row">
        <div class="nas-ci-icon">📍</div>
        <div class="nas-ci-text"><strong>Address</strong><span><?php echo esc_html($addr); ?></span></div>
      </div>
      <?php endif; ?>
      <?php
      $fb = $cfg->get('social_facebook','');
      $ig = $cfg->get('social_instagram','');
      $li = $cfg->get('social_linkedin','');
      $tw = $cfg->get('social_twitter','');
      if($fb||$ig||$li||$tw): ?>
      <div class="nas-social-links">
        <?php if($fb): ?><a href="<?php echo esc_url($fb); ?>" target="_blank" rel="noopener">f</a><?php endif; ?>
        <?php if($ig): ?><a href="<?php echo esc_url($ig); ?>" target="_blank" rel="noopener">📷</a><?php endif; ?>
        <?php if($li): ?><a href="<?php echo esc_url($li); ?>" target="_blank" rel="noopener">in</a><?php endif; ?>
        <?php if($tw): ?><a href="<?php echo esc_url($tw); ?>" target="_blank" rel="noopener">𝕏</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Contact Form -->
    <div class="nas-contact-form-card">
      <h3>Send Us a Message</h3>
      <div id="nas-contact-error" class="nas-form-error"></div>

      <div class="nas-form-row">
        <div class="nas-field"><label>Your Name *</label><input type="text" id="nas-c-name" placeholder="Rahul Sharma"></div>
        <div class="nas-field"><label>Email Address *</label><input type="email" id="nas-c-email" placeholder="rahul@example.com"></div>
      </div>
      <div class="nas-form-row">
        <div class="nas-field"><label>Phone Number</label><input type="tel" id="nas-c-phone" placeholder="+91 98765 43210"></div>
        <div class="nas-field"><label>Your City</label><input type="text" id="nas-c-city" placeholder="Delhi"></div>
      </div>
      <div class="nas-field">
        <label>Subject</label>
        <select id="nas-c-subject">
          <option value="">Select a subject…</option>
          <option>Enquiry about newspaper ad booking</option>
          <option>Pricing & packages</option>
          <option>Material specifications</option>
          <option>Existing booking support</option>
          <option>Partner / Advertise with us</option>
          <option>Technical issue</option>
          <option>Other</option>
        </select>
      </div>
      <div class="nas-field">
        <label>Message *</label>
        <textarea id="nas-c-message" placeholder="Tell us how we can help you…"></textarea>
      </div>

      <button class="nas-submit-btn" id="nas-c-submit" onclick="nasSubmitContact()">Send Message →</button>
      <div class="nas-form-success" id="nas-c-success"></div>
    </div>

  </div>
</div>

<script>
function nasSubmitContact() {
    var name    = document.getElementById('nas-c-name').value.trim();
    var email   = document.getElementById('nas-c-email').value.trim();
    var phone   = document.getElementById('nas-c-phone').value.trim();
    var city    = document.getElementById('nas-c-city').value.trim();
    var subject = document.getElementById('nas-c-subject').value;
    var message = document.getElementById('nas-c-message').value.trim();
    var errEl   = document.getElementById('nas-contact-error');
    var sucEl   = document.getElementById('nas-c-success');
    var btn     = document.getElementById('nas-c-submit');

    errEl.style.display = 'none';
    if (!name || !email || !message) {
        // Field-specific errors (13-B requirement)
        var missing = [];
        if (!name.trim()) missing.push('Name');
        if (!email.trim() || !/^[^@]+@[^@]+\.[^@]+$/.test(email)) missing.push('a valid Email address');
        if (!msg.trim() || msg.trim().length < 10) missing.push('Message (at least 10 characters)');
        errEl.textContent = 'Please enter: ' + missing.join(', ') + '.';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Sending…';

    jQuery.post('<?php echo get_permalink() ?: home_url('/'); ?>', {
        action:'nas_submit_contact', nonce:'<?php echo $nonce; ?>',
        name, email, phone, city, subject, message
    }, function(r) {
        btn.disabled = false;
        btn.textContent = 'Send Message →';
        if (r.success) {
            sucEl.textContent = r.data.message;
            sucEl.style.display = 'block';
            ['nas-c-name','nas-c-email','nas-c-phone','nas-c-city','nas-c-message'].forEach(function(id){ document.getElementById(id).value=''; });
            document.getElementById('nas-c-subject').value='';
        } else {
            errEl.textContent = r.data.message || 'Something went wrong. Please try again.';
            errEl.style.display = 'block';
        }
    });
}
</script>
