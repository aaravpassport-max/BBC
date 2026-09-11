<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * Newspaper detail page — /newspapers/{newspaper-slug}/
 */
use NAS\Core\Database;

$db      = Database::instance();
$np_slug = $GLOBALS['nas_route_data']['newspaper_slug'] ?? get_query_var( 'nas_newspaper_slug', '' );
$paper   = $db->row( "SELECT * FROM `{$db->t('newspapers')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [ $np_slug ] );
if ( ! $paper ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

$name        = $paper['name'];
$language    = $paper['language'];
$desc        = wp_kses_post( $paper['description'] );
$cities      = json_decode( $paper['cities_supported'] ?? '[]', true ) ?: [];
$editions    = json_decode( $paper['editions'] ?? '[]', true ) ?: [];
$booking_url = home_url( '/book-newspaper-ad/?newspaper=' . urlencode( $paper['id'] ) );
$papers_url  = home_url( '/newspapers/' );
?>
<div class="nas-portal-page">
  <section class="nhp-hero" style="min-height:auto;padding:clamp(56px,8vw,80px) 0">
    <div class="nhp-hero__bg" aria-hidden="true"></div>
    <div class="nhp-container" style="position:relative;z-index:2">
      <div style="display:flex;align-items:center;gap:28px;flex-wrap:wrap">
        <?php if ( $paper['logo_url'] ) : ?>
        <img src="<?php echo esc_url( $paper['logo_url'] ); ?>" alt="<?php echo esc_attr( $name ); ?>" style="width:96px;height:96px;object-fit:contain;background:#fff;border-radius:16px;padding:10px;box-shadow:0 8px 32px rgba(0,0,0,.12)">
        <?php endif; ?>
        <div style="flex:1;min-width:240px">
          <span class="nhp-hero__eyebrow" style="margin-bottom:10px"><i class="fa-solid fa-newspaper"></i> Publication</span>
          <h1 class="nhp-hero__title" style="font-size:clamp(1.75rem,4vw,2.75rem);margin:0 0 8px"><?php echo esc_html( $name ); ?></h1>
          <p class="nhp-hero__subtitle" style="margin:0;text-align:left">
            <?php echo esc_html( $language ?: 'English' ); ?>
            · <?php echo count( $cities ) ? count( $cities ) . ' cities' : 'Pan India'; ?>
            <?php if ( $editions ) : ?> · <?php echo count( $editions ); ?> editions<?php endif; ?>
            <?php if ( ! empty( $paper['circulation'] ) ) : ?> · <?php echo esc_html( number_format( (int) $paper['circulation'] ) ); ?> circulation<?php endif; ?>
          </p>
        </div>
        <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--xl">Book Ad Now <i class="fa-solid fa-arrow-right"></i></a>
      </div>
    </div>
  </section>

  <?php nas_portal_block_trust_ribbon(); ?>

  <section class="nas-portal-section">
    <div class="nhp-container">
      <div class="nas-portal-detail-layout">
        <div class="nas-portal-detail-main">
          <?php if ( $desc ) : ?>
          <div class="nas-portal-card-block">
            <h2><i class="fa-solid fa-circle-info"></i> About <?php echo esc_html( $name ); ?></h2>
            <div class="nas-portal-prose"><?php echo $desc; ?></div>
          </div>
          <?php endif; ?>

          <?php if ( $editions ) : ?>
          <div class="nas-portal-card-block">
            <h2><i class="fa-solid fa-map"></i> Available Editions</h2>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
              <?php foreach ( $editions as $ed ) : ?>
              <span class="nas-portal-city-link"><?php echo esc_html( is_array( $ed ) ? ( $ed['name'] ?? '' ) : $ed ); ?></span>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

          <?php if ( $cities ) : ?>
          <div class="nas-portal-card-block">
            <h2><i class="fa-solid fa-city"></i> Cities Covered</h2>
            <div style="display:flex;flex-wrap:wrap;gap:10px">
              <?php foreach ( array_slice( $cities, 0, 40 ) as $city ) : ?>
              <a href="<?php echo esc_url( home_url( '/newspaper-ads/' . sanitize_title( $city ) . '/' ) ); ?>" class="nas-portal-city-link"><?php echo esc_html( $city ); ?></a>
              <?php endforeach; ?>
              <?php if ( count( $cities ) > 40 ) : ?>
              <span class="nas-portal-city-link" style="opacity:.7">+<?php echo count( $cities ) - 40; ?> more</span>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <aside class="nas-portal-detail-sidebar">
          <div class="nas-portal-sidebar-card">
            <h3>Ad Rates</h3>
            <p style="font-size:0.8125rem;color:var(--nas-text-muted);margin:0 0 16px">Base rates — final price depends on edition, size &amp; date.</p>
            <div class="nas-portal-rate-row">
              <span>Classified (per word)</span>
              <strong>₹<?php echo number_format( (float) $paper['base_rate_classified'], 2 ); ?></strong>
            </div>
            <div class="nas-portal-rate-row">
              <span>Display (per sq.cm)</span>
              <strong>₹<?php echo number_format( (float) $paper['base_rate_display'], 2 ); ?></strong>
            </div>
            <div class="nas-portal-rate-row">
              <span>Minimum charge</span>
              <strong>₹<?php echo number_format( (float) $paper['min_charge'], 2 ); ?></strong>
            </div>
            <a href="<?php echo esc_url( $booking_url ); ?>" class="nas-btn nas-btn-primary" style="width:100%;justify-content:center;margin-top:20px">Get Exact Quote <i class="fa-solid fa-arrow-right"></i></a>
            <ul class="nas-portal-sidebar-trust">
              <li><i class="fa-solid fa-lock"></i> Secure Razorpay payment</li>
              <li><i class="fa-solid fa-receipt"></i> GST invoice included</li>
              <li><i class="fa-solid fa-location-crosshairs"></i> Online tracking</li>
            </ul>
          </div>
        </aside>
      </div>
    </div>
  </section>

  <?php nas_portal_block_formats(); ?>

  <?php nas_portal_block_process( 'Booking Process', 'How to Book in ' . $name, 'Five steps from quote to published proof.' ); ?>

  <?php
  nas_portal_block_faq( [
      [ 'How do I book an ad in ' . $name . '?', 'Click "Book Ad Now" or use our <a href="' . esc_url( $booking_url ) . '">booking wizard</a>. Select your city edition, ad type, size, and publication date — then pay securely online.' ],
      [ 'What ad formats does ' . $name . ' accept?', 'Classified text, classified display, and full display ads. Rates shown are base rates — your exact quote appears in the wizard.' ],
      [ 'Will I receive proof of publication?', 'Yes. You can track your booking status online and receive publication proof when your ad is printed.' ],
  ], 'FAQ — ' . $name );
  ?>

  <?php nas_portal_block_cta( 'Book Your Ad in ' . $name . ' Today', 'Instant rates, secure payment, and dedicated support — all in one platform.', $booking_url ); ?>
</div>
