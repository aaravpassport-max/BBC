<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Support page — content fragment
 */
$brand       = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$email       = nas_config( 'support_email', get_option( 'admin_email' ) );
$phone       = nas_config( 'support_phone', '' );
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
$faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
$track_url   = nas_get_page_url( 'nas_page_track_order', '/track-order/' );
$dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Help Center</span>
      <h1>Help &amp; <span>Support</span></h1>
      <p>We're here to help you with every step of your newspaper ad booking.</p>
    </div>

    <div class="nas-portal-channel-grid" style="margin-bottom:48px">
      <a href="<?php echo $email ? esc_url( 'mailto:' . $email ) : esc_url( $contact_url ); ?>" class="nas-portal-channel-card">
        <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-envelope"></i></div>
        <h3>Email Support</h3>
        <div class="nas-portal-channel-card__meta">Response within 4 hours</div>
      </a>
      <a href="<?php echo esc_url( $dash_url ); ?>" class="nas-portal-channel-card">
        <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-comments"></i></div>
        <h3>Live Chat</h3>
        <div class="nas-portal-channel-card__meta">Login to chat · 9am–9pm IST</div>
      </a>
      <a href="<?php echo $phone ? esc_url( 'tel:' . preg_replace( '/\D/', '', $phone ) ) : esc_url( $contact_url ); ?>" class="nas-portal-channel-card">
        <div class="nas-portal-channel-card__icon"><i class="fa-solid fa-phone"></i></div>
        <h3>Phone Support</h3>
        <div class="nas-portal-channel-card__meta"><?php echo $phone ? esc_html( $phone ) : 'Mon–Sat 10am–6pm'; ?></div>
      </a>
    </div>

    <div class="nas-portal-section__head" style="margin-bottom:28px">
      <h2>Frequently Asked Questions</h2>
      <p>Quick answers to common support questions.</p>
    </div>

    <div class="nas-faq-list">
      <?php
      $faqs = [
          [ 'How do I track my booking?', 'Log in to your dashboard at <a href="' . esc_url( $dash_url ) . '">My Bookings</a>. You\'ll see real-time status updates at every stage.' ],
          [ 'How long does it take to publish?', 'Classified ads are typically published within 1–3 working days. Display ads take 2–5 days depending on the newspaper.' ],
          [ 'Can I make changes after booking?', 'Minor text changes are possible before the ad is submitted to the newspaper. Contact support immediately after booking.' ],
          [ 'What payment methods are accepted?', 'We accept Razorpay (UPI, credit/debit cards, net banking) and bank transfer. GST invoice is issued automatically.' ],
          [ 'Do I get a proof before publication?', 'Yes — for display ads we share a proof for your approval before submission. Classified ads follow standard newspaper templates.' ],
      ];
      foreach ( $faqs as $i => $faq ) :
      ?>
      <div class="nas-faq-item">
        <button type="button" class="nas-faq-q" aria-expanded="false">
          <span class="nas-faq-q__num"><?php echo str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ); ?></span>
          <span><?php echo esc_html( $faq[0] ); ?></span>
          <i class="fa-solid fa-chevron-down"></i>
        </button>
        <div class="nas-faq-a"><?php echo wp_kses_post( $faq[1] ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div style="text-align:center;margin-top:40px">
      <p style="color:var(--nas-text-muted);margin-bottom:16px">Still have questions?</p>
      <a href="<?php echo esc_url( $faq_url ); ?>" class="nas-btn nas-btn-ghost" style="margin-right:8px">Browse Full FAQ</a>
      <a href="<?php echo esc_url( $contact_url ); ?>" class="nas-btn nas-btn-primary">Contact Us</a>
    </div>
  </div>
</div>
