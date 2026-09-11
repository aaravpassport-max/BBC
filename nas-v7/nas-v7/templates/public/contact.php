<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$nonce       = wp_create_nonce( 'nas_action' );
$cfg         = \NAS\Core\Config::instance();
$phone       = $cfg->get( 'brand_phone', '' );
$email       = $cfg->get( 'brand_email', '' );
$addr        = $cfg->get( 'brand_address', '' );
$wa          = $cfg->get( 'brand_whatsapp', '' );
$walink      = $wa ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $wa ) : '';
$booking_url = nas_portal_booking_url();
$faq_url     = nas_portal_faq_url();
$track_url   = nas_portal_track_url();
?>

<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Get in Touch</span>
      <h1>Contact <span>Our Team</span></h1>
      <p>Questions about rates, publications, ad formatting, or an existing booking? We're here to help — typically within 4 business hours.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section" style="padding-top:0">
    <div class="nas-contact-wrap" style="max-width:1100px;margin:0 auto;padding:0 16px">
      <div class="nas-contact-grid">

        <div class="nas-contact-info">
          <h2>Reach Us Directly</h2>
          <p>Our booking specialists can help you choose the right newspaper, format your ad, and get the best rate.</p>

          <?php if ( $phone ) : ?>
          <div class="nas-ci-row">
            <div class="nas-ci-icon"><i class="fa-solid fa-phone"></i></div>
            <div class="nas-ci-text"><strong>Phone</strong><span><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>" style="color:#fff"><?php echo esc_html( $phone ); ?></a></span></div>
          </div>
          <?php endif; ?>

          <?php if ( $walink ) : ?>
          <div class="nas-ci-row">
            <div class="nas-ci-icon"><i class="fa-brands fa-whatsapp"></i></div>
            <div class="nas-ci-text"><strong>WhatsApp</strong><span><a href="<?php echo esc_url( $walink ); ?>" target="_blank" rel="noopener" style="color:#fff"><?php echo esc_html( $wa ); ?></a></span></div>
          </div>
          <?php endif; ?>

          <?php if ( $email ) : ?>
          <div class="nas-ci-row">
            <div class="nas-ci-icon"><i class="fa-solid fa-envelope"></i></div>
            <div class="nas-ci-text"><strong>Email</strong><span><a href="mailto:<?php echo esc_attr( $email ); ?>" style="color:#fff"><?php echo esc_html( $email ); ?></a></span></div>
          </div>
          <?php endif; ?>

          <?php if ( $addr ) : ?>
          <div class="nas-ci-row">
            <div class="nas-ci-icon"><i class="fa-solid fa-location-dot"></i></div>
            <div class="nas-ci-text"><strong>Office</strong><span><?php echo esc_html( $addr ); ?></span></div>
          </div>
          <?php endif; ?>

          <div class="nas-ci-row">
            <div class="nas-ci-icon"><i class="fa-solid fa-clock"></i></div>
            <div class="nas-ci-text"><strong>Support Hours</strong><span>Mon–Sat, 10:00 AM – 6:00 PM IST</span></div>
          </div>

          <?php
          $fb = $cfg->get( 'social_facebook', '' );
          $ig = $cfg->get( 'social_instagram', '' );
          $li = $cfg->get( 'social_linkedin', '' );
          $tw = $cfg->get( 'social_twitter', '' );
          if ( $fb || $ig || $li || $tw ) :
          ?>
          <div class="nas-social-links">
            <?php if ( $fb ) : ?><a href="<?php echo esc_url( $fb ); ?>" target="_blank" rel="noopener" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a><?php endif; ?>
            <?php if ( $ig ) : ?><a href="<?php echo esc_url( $ig ); ?>" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a><?php endif; ?>
            <?php if ( $li ) : ?><a href="<?php echo esc_url( $li ); ?>" target="_blank" rel="noopener" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a><?php endif; ?>
            <?php if ( $tw ) : ?><a href="<?php echo esc_url( $tw ); ?>" target="_blank" rel="noopener" aria-label="Twitter"><i class="fa-brands fa-x-twitter"></i></a><?php endif; ?>
          </div>
          <?php endif; ?>

          <div style="margin-top:24px;display:flex;flex-direction:column;gap:10px">
            <a href="<?php echo esc_url( $faq_url ); ?>" class="nas-btn nas-btn-secondary" style="justify-content:center"><i class="fa-solid fa-circle-question"></i> Browse FAQ</a>
            <a href="<?php echo esc_url( $track_url ); ?>" class="nas-btn nas-btn-secondary" style="justify-content:center"><i class="fa-solid fa-location-crosshairs"></i> Track an Order</a>
          </div>
        </div>

        <div class="nas-contact-form-card">
          <h3>Send Us a Message</h3>
          <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:-8px 0 20px">Fill in the form and our team will respond within 4 business hours.</p>
          <div id="nas-contact-error" class="nas-form-error"></div>

          <div class="nas-form-row">
            <div class="nas-field"><label for="nas-c-name">Your Name *</label><input type="text" id="nas-c-name" placeholder="Rahul Sharma" autocomplete="name"></div>
            <div class="nas-field"><label for="nas-c-email">Email Address *</label><input type="email" id="nas-c-email" placeholder="rahul@example.com" autocomplete="email"></div>
          </div>
          <div class="nas-form-row">
            <div class="nas-field"><label for="nas-c-phone">Phone Number</label><input type="tel" id="nas-c-phone" placeholder="+91 98765 43210" autocomplete="tel"></div>
            <div class="nas-field"><label for="nas-c-city">Your City</label><input type="text" id="nas-c-city" placeholder="Delhi" autocomplete="address-level2"></div>
          </div>
          <div class="nas-field">
            <label for="nas-c-subject">Subject</label>
            <select id="nas-c-subject">
              <option value="">Select a subject…</option>
              <option>Enquiry about newspaper ad booking</option>
              <option>Pricing &amp; packages</option>
              <option>Material specifications</option>
              <option>Existing booking support</option>
              <option>Partner / Advertise with us</option>
              <option>Technical issue</option>
              <option>Other</option>
            </select>
          </div>
          <div class="nas-field">
            <label for="nas-c-message">Message *</label>
            <textarea id="nas-c-message" placeholder="Tell us how we can help you — include newspaper name, city, or Order ID if relevant…" rows="5"></textarea>
          </div>

          <button type="button" class="nas-submit-btn" id="nas-c-submit" onclick="nasSubmitContact()">Send Message <i class="fa-solid fa-paper-plane"></i></button>
          <div class="nas-form-success" id="nas-c-success"></div>
        </div>
      </div>
    </div>
  </section>

  <?php nas_portal_block_quick_links(); ?>

  <?php
  nas_portal_block_faq( [
      [ 'How quickly will I receive a response?', 'We aim to respond to all enquiries within 4 business hours during Mon–Sat, 10am–6pm IST. Urgent booking queries via WhatsApp are typically answered faster.' ],
      [ 'Can you help me choose a newspaper?', 'Yes. Tell us your target city, audience, language, and budget — our specialists will recommend the best publications and ad formats.' ],
      [ 'I have an existing booking — what should I include?', 'Please include your Order ID (e.g. BK-XXXXX) and the email used during booking so we can locate your record immediately.' ],
  ], 'Before You Write', 'Quick answers — you may find what you need without waiting.' );
  ?>

  <?php nas_portal_block_accent_band(
      'Prefer to Book Online?',
      'Get instant rates and complete your booking in minutes — no waiting for a callback.',
      $booking_url,
      'Start Booking'
  ); ?>
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
    sucEl.style.display = 'none';
    var missing = [];
    if (!name) missing.push('Name');
    if (!email || !/^[^@]+@[^@]+\.[^@]+$/.test(email)) missing.push('a valid Email address');
    if (!message || message.length < 10) missing.push('Message (at least 10 characters)');
    if (missing.length) {
        errEl.textContent = 'Please enter: ' + missing.join(', ') + '.';
        errEl.style.display = 'block';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = 'Sending… <i class="fa-solid fa-spinner fa-spin"></i>';

    jQuery.post('<?php echo esc_url( get_permalink() ?: home_url( '/' ) ); ?>', {
        action:'nas_submit_contact', nonce:'<?php echo esc_js( $nonce ); ?>',
        name, email, phone, city, subject, message
    }, function(r) {
        btn.disabled = false;
        btn.innerHTML = 'Send Message <i class="fa-solid fa-paper-plane"></i>';
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
