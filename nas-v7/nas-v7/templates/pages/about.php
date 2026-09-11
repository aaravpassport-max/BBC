<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * About page — content fragment (shell from nas-page-template.php)
 */
$brand       = nas_config( 'brand_name', get_bloginfo( 'name' ) );
$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">About Us</span>
      <h1>India's Trusted <span>Newspaper Ad</span> Platform</h1>
      <p><?php echo esc_html( $brand ); ?> makes newspaper advertising simple, transparent, and accessible for every business across India.</p>
    </div>
  </div>

  <section class="nas-portal-section">
    <div class="nas-portal-split">
      <div>
        <h2 style="font-family:var(--nas-font-display);font-size:1.75rem;font-weight:800;margin:0 0 16px;color:var(--nas-text)">Our Mission</h2>
        <p style="color:var(--nas-text-muted);line-height:1.8;font-size:1rem;margin:0">We make newspaper advertising simple and accessible for every business in India. From a single classified ad to a full-page display, we handle everything — so you can focus on your business.</p>
      </div>
      <div class="nas-portal-highlight-card">
        <div style="font-size:2.5rem;margin-bottom:12px"><i class="fa-solid fa-newspaper"></i></div>
        <div class="nas-portal-highlight-card__value">300+</div>
        <div class="nas-portal-highlight-card__label">Cities Covered Pan-India</div>
      </div>
    </div>
  </section>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nas-portal-section__head">
      <h2>Platform at a Glance</h2>
      <p>Trusted by thousands of advertisers, publishers, and agencies nationwide.</p>
    </div>
    <div class="nas-portal-stat-grid">
      <?php
      $stats = [
          [ 'icon' => 'fa-newspaper', 'value' => '50+', 'label' => 'Newspapers' ],
          [ 'icon' => 'fa-city', 'value' => '300+', 'label' => 'Cities' ],
          [ 'icon' => 'fa-star', 'value' => '10,000+', 'label' => 'Happy Clients' ],
      ];
      foreach ( $stats as $s ) :
      ?>
      <div class="nas-portal-stat">
        <div class="nas-portal-stat__icon"><i class="fa-solid <?php echo esc_attr( $s['icon'] ); ?>"></i></div>
        <div class="nas-portal-stat__value"><?php echo esc_html( $s['value'] ); ?></div>
        <div class="nas-portal-stat__label"><?php echo esc_html( $s['label'] ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="nas-portal-section">
    <div class="nas-portal-section__head">
      <h2>Why Choose <?php echo esc_html( $brand ); ?>?</h2>
      <p>Enterprise-grade tools with the simplicity of online booking.</p>
    </div>
    <div class="nas-portal-feature-grid" style="max-width:1000px;margin:0 auto">
      <?php
      $features = [
          [ 'fa-bolt', 'Instant Quote', 'Get exact pricing in seconds — no calls needed.' ],
          [ 'fa-shield-check', 'Verified Publishers', 'Every newspaper is directly partnered and verified.' ],
          [ 'fa-location-crosshairs', 'Real-time Tracking', 'Follow your ad from submission to publication.' ],
          [ 'fa-wand-magic-sparkles', 'AI Copywriting', 'Let AI help write a compelling, compliant ad.' ],
      ];
      foreach ( $features as $f ) :
      ?>
      <div class="nas-portal-feature">
        <div class="nas-portal-feature__icon"><i class="fa-solid <?php echo esc_attr( $f[0] ); ?>"></i></div>
        <h3><?php echo esc_html( $f[1] ); ?></h3>
        <p><?php echo esc_html( $f[2] ); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="nas-portal-cta-band">
    <h2>Ready to Place Your Newspaper Ad?</h2>
    <p>Get instant rates across 300+ cities and 50+ publications — with secure payment and online tracking.</p>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg">Check Ad Rates <i class="fa-solid fa-arrow-right"></i></a>
    <a href="<?php echo esc_url( $contact_url ); ?>" class="nhp-btn nhp-btn--ghost nhp-btn--lg" style="margin-left:12px">Talk to Us</a>
  </section>
</div>
