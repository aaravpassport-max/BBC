<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Pricing page — enterprise content fragment
 */
use NAS\Core\Database;

$db          = Database::instance();
$settings    = $db->row( "SELECT gst_rate, brand_name FROM `{$db->t('settings')}` LIMIT 1" ) ?: [];
$gst_rate    = (int) ( $settings['gst_rate'] ?? 18 );
$booking_url = nas_portal_booking_url();
$categories  = $db->select( "SELECT name, icon, description FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 8" ) ?: [];
?>
<div class="nas-portal-page">
  <div class="nas-portal-wrap">
    <div class="nas-portal-hero">
      <span class="nas-portal-hero__eyebrow">Transparent Rates</span>
      <h1>Simple, <span>Honest</span> Pricing</h1>
      <p>No hidden charges. All prices include <?php echo (int) $gst_rate; ?>% GST. Get an exact, newspaper-specific quote instantly in our booking wizard.</p>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--lg" style="margin-top:20px">Get an Instant Quote <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php nas_portal_block_formats(); ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Ad Type Pricing</h2>
        <p>Base rates shown below. Final price depends on newspaper, city, edition, size, and word count.</p>
      </div>
      <div class="nas-portal-pricing-grid">
        <div class="nas-portal-pricing-card">
          <div style="font-size:2rem;margin-bottom:8px;color:var(--nas-primary)"><i class="fa-solid fa-align-left"></i></div>
          <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Classified Text</h3>
          <div class="nas-portal-pricing-card__price">₹5<span>/word</span></div>
          <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Minimum 30 words · Most affordable format</p>
          <ul>
            <li>Matrimonial, jobs, property, legal</li>
            <li>Same-day processing available</li>
            <li>All major newspapers</li>
            <li>AI writing assistance included</li>
          </ul>
          <a href="<?php echo esc_url( $booking_url . '?ad_type=classified' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book Classified Ad</a>
        </div>

        <div class="nas-portal-pricing-card nas-portal-pricing-card--featured">
          <span class="nas-portal-pricing-card__badge">Most Popular</span>
          <div style="font-size:2rem;margin-bottom:8px;color:#F59E0B"><i class="fa-solid fa-image"></i></div>
          <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Display Ad</h3>
          <div class="nas-portal-pricing-card__price" style="color:#F59E0B">₹300<span>/sq.cm</span></div>
          <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Custom sizes · Maximum visibility</p>
          <ul>
            <li>Images, logos &amp; brand colours</li>
            <li>Front page &amp; jacket options</li>
            <li>Colour or black &amp; white</li>
            <li>Proof approval before print</li>
          </ul>
          <a href="<?php echo esc_url( $booking_url . '?ad_type=display' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book Display Ad</a>
        </div>

        <div class="nas-portal-pricing-card">
          <div style="font-size:2rem;margin-bottom:8px;color:#7C3AED"><i class="fa-solid fa-newspaper"></i></div>
          <h3 style="margin:0 0 8px;font-size:1.25rem;font-weight:800">Display Classified</h3>
          <div class="nas-portal-pricing-card__price" style="color:#7C3AED">₹150<span>/sq.cm</span></div>
          <p style="color:var(--nas-text-muted);font-size:0.875rem;margin:0 0 20px">Text + small image in classified section</p>
          <ul>
            <li>Enhanced classified with visual</li>
            <li>Classified section placement</li>
            <li>More impact than text-only</li>
            <li>Budget-friendly display option</li>
          </ul>
          <a href="<?php echo esc_url( $booking_url . '?ad_type=display_classified' ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center">Book This Format</a>
        </div>
      </div>
    </div>
  </section>

  <?php if ( $categories ) : ?>
  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-section__head">
        <h2>Popular Ad Categories</h2>
        <p>Rates vary by category and newspaper. Select your category in the booking wizard for an exact quote.</p>
      </div>
      <div class="nas-portal-feature-grid">
        <?php foreach ( $categories as $cat ) : ?>
        <a href="<?php echo esc_url( $booking_url . '?category=' . urlencode( $cat['name'] ) ); ?>" class="nas-portal-feature" style="text-decoration:none;color:inherit">
          <div class="nas-portal-feature__icon"><?php echo esc_html( $cat['icon'] ?? '📰' ); ?></div>
          <h3><?php echo esc_html( $cat['name'] ); ?></h3>
          <p><?php echo esc_html( wp_trim_words( $cat['description'] ?? 'Book ' . $cat['name'] . ' ads online.', 12 ) ); ?></p>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="nas-portal-section nas-portal-section--muted">
    <div class="nhp-container" style="max-width:900px">
      <div class="nas-portal-section__head">
        <h2>What's Included in Every Booking</h2>
        <p>No surprise fees — here's what you get with every ad placed through our platform.</p>
      </div>
      <div class="nas-portal-feature-grid">
        <?php
        $included = [
            [ 'fa-file-invoice', 'GST Invoice', 'Tax-compliant invoice generated automatically for every paid booking.' ],
            [ 'fa-lock', 'Secure Payment', 'Razorpay-powered checkout with UPI, cards, and net banking.' ],
            [ 'fa-location-crosshairs', 'Online Tracking', 'Real-time status updates from submission to publication.' ],
            [ 'fa-image', 'Publication Proof', 'Receive proof of publication when your ad is printed (display ads).' ],
            [ 'fa-headset', 'Booking Support', 'Help with formatting, newspaper selection, and rate queries.' ],
            [ 'fa-rotate', 'Modification Support', 'Minor changes possible before submission to the newspaper.' ],
        ];
        foreach ( $included as $inc ) :
        ?>
        <div class="nas-portal-feature">
          <div class="nas-portal-feature__icon"><i class="fa-solid <?php echo esc_attr( $inc[0] ); ?>"></i></div>
          <h3><?php echo esc_html( $inc[1] ); ?></h3>
          <p><?php echo esc_html( $inc[2] ); ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <?php nas_portal_block_advantages(); ?>

  <?php
  nas_portal_block_faq( [
      [ 'Are the prices on this page final?', 'These are base rates. Final price depends on the specific newspaper, city, edition, ad size, and word count. Use our <a href="' . esc_url( $booking_url ) . '">booking wizard</a> for an exact quote.' ],
      [ 'Is GST included?', 'Yes. GST @ ' . (int) $gst_rate . '% is applicable and shown clearly before payment. You receive a GST invoice automatically.' ],
      [ 'Can I get a discount for multiple ads?', 'Volume and repeat bookings may qualify for special rates. Contact our sales team through the <a href="' . esc_url( nas_portal_contact_url() ) . '">contact page</a>.' ],
      [ 'What payment methods do you accept?', 'We accept UPI, credit/debit cards, net banking, and bank transfer via Razorpay. All transactions are encrypted and secure.' ],
      [ 'Do rates differ by city?', 'Yes. Newspaper rates vary by city and edition. Tier-1 metros may have higher base rates than Tier-2/3 cities for the same publication.' ],
  ], 'Pricing FAQ', 'Common questions about our rates and billing.' );
  ?>

  <?php nas_portal_block_accent_band(
      'Get Your Exact Quote in 60 Seconds',
      'Select city, newspaper, and ad type — see the final price before you pay.',
      $booking_url,
      'Start Booking Wizard'
  ); ?>
</div>
