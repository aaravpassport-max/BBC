<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Support page — enterprise content fragment
 */
$brand       = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$email       = nas_config( 'support_email', nas_config( 'brand_email', get_option( 'admin_email' ) ) );
$phone       = nas_config( 'support_phone', nas_config( 'brand_phone', '' ) );
$contact_url = nas_portal_contact_url();
$faq_url     = nas_portal_faq_url();
$track_url   = nas_portal_track_url();
$dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
$walink      = nas_portal_whatsapp_url();
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Help Center</span>
      <h1>Help &amp; <span>Support</span></h1>
      <p>Expert assistance for newspaper selection, ad formatting, rate quotes, booking status, and publication queries.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Contact Our Support Team</h2>
        <p>Choose the channel that works best for you — we're here Mon–Sat, 10am–6pm IST.</p>
      </div>
      <div class="nas-portal-channel-grid">
        <a href="<?php echo $email ? esc_url( 'mailto:' . $email ) : esc_url( $contact_url ); ?>" class="nas-portal-channel-card">
          <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-envelope"></i></div>
          <h3>Email Support</h3>
          <div class="nas-portal-channel-card__meta"><?php echo $email ? esc_html( $email ) : 'Contact form'; ?> · Response within 4 hours</div>
        </a>
        <?php if ( $walink ) : ?>
        <a href="<?php echo esc_url( $walink ); ?>" class="nas-portal-channel-card" target="_blank" rel="noopener">
          <div class="nas-portal-channel-card__icon"><i class="fa-brands fa-whatsapp"></i></div>
          <h3>WhatsApp</h3>
          <div class="nas-portal-channel-card__meta">Quick answers · 9am–9pm IST</div>
        </a>
        <?php endif; ?>
        <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-portal-channel-card">
          <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-comments"></i></div>
          <h3>Live Chat</h3>
          <div class="nas-portal-channel-card__meta">Login to your dashboard · Real-time chat with support</div>
        </a>
        <a href="<?php echo $phone ? esc_url( 'tel:' . preg_replace( '/\D/', '', $phone ) ) : esc_url( $contact_url ); ?>" class="nas-portal-channel-card">
          <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-phone"></i></div>
          <h3>Phone Support</h3>
          <div class="nas-portal-channel-card__meta"><?php echo $phone ? esc_html( $phone ) : 'See contact page'; ?> · Mon–Sat 10am–6pm</div>
        </a>
      </div>
    </div>
  </section>

  <?php nas_portal_block_quick_links(); ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Common Support Topics</h2>
        <p>Quick guidance for the most frequent questions our team handles.</p>
      </div>
      <div class="nas-portal-feature-grid">
        <?php
        $topics = [
            [ 'fa-magnifying-glass', 'Finding the Right Newspaper', 'Not sure which publication fits your audience? Our team recommends newspapers by city, language, circulation, and budget.' ],
            [ 'fa-ruler-combined', 'Ad Size & Formatting', 'We help you choose the right ad format (classified, display, or display-classified) and meet newspaper specifications.' ],
            [ 'fa-calendar', 'Publication Dates', 'Learn about lead times, holiday editions, and how to select your preferred publication date.' ],
            [ 'fa-credit-card', 'Payment & Invoices', 'Questions about payment methods, GST invoices, refunds, or payment failures — we resolve these quickly.' ],
            [ 'fa-file-lines', 'Material & Proof Review', 'Upload your ad material or request a proof review before submission to the newspaper.' ],
            [ 'fa-clock-rotate-left', 'Changes After Booking', 'Minor text changes may be possible before the ad is submitted. Contact us immediately after booking.' ],
        ];
        foreach ( $topics as $t ) :
        ?>
        <div class="nas-portal-feature">
          <div class="nas-portal-feature__icon"><i class="fa-solid <?php echo esc_attr( $t[0] ); ?>"></i></div>
          <h3><?php echo esc_html( $t[1] ); ?></h3>
          <p><?php echo esc_html( $t[2] ); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php nas_portal_block_process( 'Support Process', 'How We Help You', 'From your first question to published proof — our support workflow.' ); ?>

  <?php
  nas_portal_block_faq( [
      [ 'How do I track my booking?', 'Log in to <a href="' . esc_url( $dash_url ) . '">My Bookings</a> or use our <a href="' . esc_url( $track_url ) . '">Track Order</a> page with your Order ID and email. You\'ll see real-time status at every stage.' ],
      [ 'How long does it take to publish?', 'Classified ads typically publish within 1–3 working days. Display ads take 2–5 days depending on the newspaper and edition.' ],
      [ 'Can I make changes after booking?', 'Minor text changes are possible before the ad is submitted to the newspaper. Contact support immediately — delays reduce the chance of changes.' ],
      [ 'What payment methods are accepted?', 'Razorpay (UPI, credit/debit cards, net banking) and bank transfer. A GST invoice is issued automatically on payment.' ],
      [ 'Do I get a proof before publication?', 'Yes — for display ads we share a proof for your approval. Classified ads follow standard newspaper templates.' ],
      [ 'How do I contact support for an urgent issue?', 'Use WhatsApp or phone during business hours for urgent matters. For existing bookings, reference your Order ID for faster resolution.' ],
  ], 'Support FAQ', 'Answers to the questions our team hears most often.' );
  ?>

  <?php nas_portal_block_accent_band(
      'Still Need Help?',
      'Our support team is ready to assist with your newspaper ad booking.',
      $contact_url,
      'Contact Support'
  ); ?>
</div>
