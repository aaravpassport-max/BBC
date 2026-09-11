<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * About page — enterprise content fragment
 */
$brand       = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$booking_url = nas_portal_booking_url();
$contact_url = nas_portal_contact_url();
$tagline     = nas_config( 'brand_tagline', 'Book Newspaper Ads Online — Fast, Easy, Affordable' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">About Us</span>
      <h1>India's Trusted <span>Newspaper Ad</span> Platform</h1>
      <p><?php echo esc_html( $brand ); ?> — <?php echo esc_html( $tagline ); ?>. We connect advertisers with verified publishers across India through a transparent, technology-driven booking experience.</p>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-split">
        <div>
          <span class="nas-portal-hero__eyebrow" style="margin-bottom:12px">Our Mission</span>
          <h2 style="font-family:var(--nas-font-display);font-size:clamp(1.5rem,3vw,2rem);font-weight:800;margin:0 0 16px;color:var(--nas-text)">Making Newspaper Advertising Accessible to Every Business</h2>
          <p style="color:var(--nas-text-muted);line-height:1.85;font-size:1rem;margin:0 0 20px">We believe every business — from a local shop to a national brand — deserves access to newspaper advertising without complexity, opaque pricing, or endless phone calls. <?php echo esc_html( $brand ); ?> digitizes the entire journey: discover rates, compose your ad, pay securely, and track publication — all in one place.</p>
          <p style="color:var(--nas-text-muted);line-height:1.85;font-size:1rem;margin:0">Our team works directly with authorized newspaper publishers to ensure your ad reaches the right edition on the right date, with proof of publication delivered to your dashboard.</p>
        </div>
        <div class="nas-portal-highlight-card">
          <div style="font-size:2.5rem;margin-bottom:12px"><i class="fa-solid fa-globe"></i></div>
          <?php $s = nas_portal_live_stats(); ?>
          <div class="nas-portal-highlight-card__value"><?php echo $s['cities'] > 0 ? (int) $s['cities'] . '+' : '300+'; ?></div>
          <div class="nas-portal-highlight-card__label">Cities Covered Pan-India</div>
        </div>
      </div>
    </div>
  </section>

  <?php nas_portal_block_stats( 'Platform at a Glance', 'Real numbers from our live booking platform.' ); ?>

  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>What We Stand For</h2>
        <p>The principles that guide every booking, every support conversation, and every publisher partnership.</p>
      </div>
      <div class="nas-portal-feature-grid">
        <?php
        $values = [
            [ 'fa-eye', 'Transparency', 'Upfront rates, clear timelines, and GST invoices on every booking — no surprises.' ],
            [ 'fa-handshake', 'Publisher Partnerships', 'We work only with authorized newspaper channels — your ad is never placed through unverified agents.' ],
            [ 'fa-rocket', 'Speed & Simplicity', 'Instant quotes, online payment, and real-time tracking replace weeks of back-and-forth.' ],
            [ 'fa-users', 'Customer First', 'Dedicated support from ad formatting advice through publication proof delivery.' ],
            [ 'fa-shield-halved', 'Secure & Compliant', 'Razorpay-powered payments, encrypted data, and audit-ready booking records.' ],
            [ 'fa-lightbulb', 'Innovation', 'AI-assisted copywriting, sample ads, and smart category recommendations built in.' ],
        ];
        foreach ( $values as $v ) :
        ?>
        <div class="nas-portal-feature">
          <div class="nas-portal-feature__icon"><i class="fa-solid <?php echo esc_attr( $v[0] ); ?>"></i></div>
          <h3><?php echo esc_html( $v[1] ); ?></h3>
          <p><?php echo esc_html( $v[2] ); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php nas_portal_block_process( 'How It Works', 'Your Ad Journey with ' . $brand, 'Five simple steps from selection to published proof.' ); ?>

  <?php nas_portal_block_advantages(); ?>

  <?php nas_portal_block_testimonials( 'Trusted by Advertisers Like You', 'Hear from businesses and families who book newspaper ads through our platform every day.' ); ?>

  <?php
  nas_portal_block_faq( [
      [ 'Who is ' . $brand . '?', esc_html( $brand ) . ' is an online newspaper ad booking platform that connects advertisers with verified publications across India. We handle rate quotes, payment, submission to newspapers, and tracking until your ad is published.' ],
      [ 'Which newspapers can I book through the platform?', 'We partner with leading English, Hindi, and regional language newspapers. Browse our <a href="' . esc_url( home_url( '/newspapers/' ) ) . '">full newspaper directory</a> or start the booking wizard to see publications available in your city.' ],
      [ 'Is this an authorized booking channel?', 'Yes. We work directly with authorized publisher networks. Every booking includes a GST invoice and publication proof when your ad is printed.' ],
      [ 'Can agencies and businesses book in bulk?', 'Absolutely. Businesses, agencies, and individuals use our platform daily. Contact our team for volume bookings or recurring campaign support.' ],
  ], 'About ' . $brand, 'Common questions about our platform and services.' );
  ?>

  <?php nas_portal_block_accent_band(
      'Start Advertising with Confidence',
      'Instant rates · Secure payment · Online tracking · Publication proof',
      $booking_url,
      'Check Ad Rates'
  ); ?>

  <?php nas_portal_block_cta(
      'Ready to Place Your Newspaper Ad?',
      'Join thousands of advertisers who trust ' . $brand . ' for transparent, reliable newspaper advertising across India.'
  ); ?>
</div>
