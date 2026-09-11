<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pricing page — content fragment
 */
use NAS\Core\Database;

$db         = Database::instance();
$settings   = $db->row( "SELECT gst_rate, brand_name FROM `{$db->t('settings')}` LIMIT 1" ) ?: [];
$gst_rate   = (int) ( $settings['gst_rate'] ?? 18 );
$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Transparent Rates</span>
      <h1>Simple, <span>Transparent</span> Pricing</h1>
      <p>No hidden charges. All prices include <?php echo (int) $gst_rate; ?>% GST. Get an exact quote instantly in the booking wizard.</p>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--lg" style="margin-top:20px">Get an Instant Quote <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nas-portal-section__head">
      <h2>Ad Type Pricing</h2>
      <p>Choose the format that fits your campaign — from affordable classified text to high-impact display ads.</p>
    </div>
    <div class="nas-portal-pricing-grid">
      <div class="nas-portal-pricing-card">
        <div style="font-size:2rem;margin-bottom:8px"><i class="fa-solid fa-align-left"></i></div>
        <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Classified Text</h3>
        <div class="nas-portal-pricing-card__price">₹5<span>/word</span></div>
        <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Minimum 30 words · Most affordable</p>
        <ul>
          <li>Matrimonial, jobs, property</li>
          <li>Same-day processing</li>
          <li>All major newspapers</li>
          <li>AI writing assistance</li>
        </ul>
        <a href="<?php echo esc_url( $booking_url . '?ad_type=classified' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book Classified Ad</a>
      </div>

      <div class="nas-portal-pricing-card nas-portal-pricing-card--featured">
        <span class="nas-portal-pricing-card__badge">Most Popular</span>
        <div style="font-size:2rem;margin-bottom:8px"><i class="fa-solid fa-image"></i></div>
        <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Display Ad</h3>
        <div class="nas-portal-pricing-card__price" style="color:#F59E0B">₹300<span>/sq.cm</span></div>
        <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Custom sizes · High visibility</p>
        <ul>
          <li>Images &amp; logos allowed</li>
          <li>Front page options</li>
          <li>Colour or B&amp;W</li>
          <li>Proof before publication</li>
        </ul>
        <a href="<?php echo esc_url( $booking_url . '?ad_type=display' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book Display Ad</a>
      </div>

      <div class="nas-portal-pricing-card">
        <div style="font-size:2rem;margin-bottom:8px"><i class="fa-solid fa-newspaper"></i></div>
        <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Display Classified</h3>
        <div class="nas-portal-pricing-card__price" style="color:#7C3AED">₹150<span>/sq.cm</span></div>
        <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Best of both worlds</p>
        <ul>
          <li>Text + small image</li>
          <li>Classified section placement</li>
          <li>More impact than text-only</li>
          <li>Budget-friendly display</li>
        </ul>
        <a href="<?php echo esc_url( $booking_url . '?ad_type=display_classified' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book This Ad</a>
      </div>
    </div>
  </section>

  <section class="nas-portal-section">
    <div class="nas-portal-section__head">
      <p style="font-size:0.9375rem;color:var(--nas-text-muted);max-width:720px;margin:0 auto;line-height:1.7">
        All prices shown are base rates. Final price depends on newspaper, city, edition, and word count.
        GST @ <?php echo (int) $gst_rate; ?>% applicable. Get an exact quote instantly by
        <a href="<?php echo esc_url( $booking_url ); ?>">using the booking wizard</a>.
      </p>
    </div>
  </section>

  <section class="nas-portal-cta-band">
    <h2>Not Sure Which Format to Choose?</h2>
    <p>Our team can recommend the best ad type and newspaper for your budget and goals.</p>
    <a href="<?php echo esc_url( nas_get_page_url( 'nas_page_contact', '/contact-us/' ) ); ?>" class="nhp-btn nhp-btn--white nhp-btn--lg">Talk to an Expert</a>
  </section>
</div>
