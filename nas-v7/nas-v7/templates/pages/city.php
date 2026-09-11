<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/**
 * City landing page — /newspaper-ads/{city-slug}/
 */
use NAS\Core\Database;

$db        = Database::instance();
$city_slug = $GLOBALS['nas_route_data']['city_slug'] ?? get_query_var( 'nas_city_slug', '' );

$city = $db->row( "SELECT * FROM `{$db->t('cities')}` WHERE slug = %s AND is_active = 1 LIMIT 1", [ $city_slug ] );
if ( ! $city ) {
    $city = $db->row( "SELECT * FROM `{$db->t('cities')}` WHERE LOWER(name) = LOWER(%s) AND is_active = 1 LIMIT 1", [ str_replace( '-', ' ', $city_slug ) ] );
}
if ( ! $city ) {
    global $wp_query;
    $wp_query->set_404();
    status_header( 404 );
    include get_query_template( '404' );
    exit;
}

$city_name   = $city['name'];
$state       = $city['state'];
$tier        = (int) ( $city['tier'] ?? 2 );
$booking_url = home_url( '/book-newspaper-ad/?city=' . urlencode( $city['id'] ) );

$all_papers  = $db->select( "SELECT id, name, slug, logo_url, language, base_rate_classified, base_rate_display, description, editions, min_charge FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC" );
$newspapers  = array_values( array_filter( $all_papers, function ( $p ) use ( $city_name ) {
    $cities = json_decode( $p['cities_supported'] ?? '[]', true ) ?: [];
    return empty( $cities ) || in_array( $city_name, $cities, true ) || count( array_filter( $cities, fn( $c ) => stripos( $c, $city_name ) !== false ) );
} ) );

$categories = $db->select( "SELECT id, name, slug, icon, description FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC LIMIT 12" ) ?: [];

$cat_icons = [ 'fa-bullhorn', 'fa-ring', 'fa-house', 'fa-briefcase', 'fa-graduation-cap', 'fa-scale-balanced', 'fa-building', 'fa-car', 'fa-coins', 'fa-trophy', 'fa-star', 'fa-party-horn' ];
?>
<div class="nas-portal-page">
  <section class="nhp-hero" style="min-height:auto;padding:clamp(64px,10vw,100px) 0 48px">
    <div class="nhp-hero__bg" aria-hidden="true"></div>
    <div class="nhp-container" style="position:relative;z-index:2;text-align:center">
      <div style="margin-bottom:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <span class="nhp-hero__eyebrow" style="margin:0"><i class="fa-solid fa-location-dot"></i> <?php echo esc_html( $state ); ?></span>
        <?php if ( $tier === 1 ) : ?><span class="nhp-hero__trust-item nhp-hero__trust-item--c0">Tier 1 City</span><?php endif; ?>
      </div>
      <h1 class="nhp-hero__title" style="max-width:800px;margin:0 auto 16px">Book Newspaper Ads in <em><?php echo esc_html( $city_name ); ?></em></h1>
      <p class="nhp-hero__subtitle" style="max-width:640px;margin:0 auto 28px">Classified &amp; display ads in <?php echo esc_html( $city_name ); ?>'s top newspapers — instant rates, verified publishers, online tracking.</p>
      <div class="nhp-hero__trust-row" style="justify-content:center;margin-bottom:28px">
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c0"><i class="fa-solid fa-bolt"></i> Same-day Processing</span>
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c1"><i class="fa-solid fa-shield-check"></i> Verified Publishers</span>
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c2"><i class="fa-solid fa-receipt"></i> GST Invoice</span>
      </div>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--xl">Book an Ad in <?php echo esc_html( $city_name ); ?> <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </section>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $categories ) : ?>
  <section class="nhp-section nhp-section--warm">
    <div class="nhp-container">
      <div class="nhp-section__header nhp-section__header--center">
        <span class="nhp-section__eyebrow">Ad Categories</span>
        <h2 class="nhp-section__title">Popular Ad Types in <?php echo esc_html( $city_name ); ?></h2>
        <p class="nhp-section__subtitle">Matrimonial, property, jobs, business, legal notices, and more — book any category online.</p>
      </div>
      <div class="nhp-cat-grid">
        <?php foreach ( $categories as $ci => $cat ) : ?>
        <a href="<?php echo esc_url( $booking_url . '&category=' . urlencode( $cat['name'] ) ); ?>" class="nhp-cat-card nhp-cat-card--c<?php echo (int) ( $ci % 6 ); ?>">
          <span class="nhp-cat-card__icon"><i class="fa-solid <?php echo esc_attr( $cat_icons[ $ci % count( $cat_icons ) ] ); ?>"></i></span>
          <span class="nhp-cat-card__name"><?php echo esc_html( $cat['name'] ); ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="nhp-section nhp-section--marketplace" id="newspapers">
    <div class="nhp-marketplace-intro">
      <div class="nhp-container">
        <div class="nhp-section__header nhp-section__header--center">
          <span class="nhp-section__eyebrow"><?php echo count( $newspapers ); ?> Publications</span>
          <h2 class="nhp-section__title">Newspapers in <?php echo esc_html( $city_name ); ?></h2>
          <p class="nhp-section__subtitle">Compare rates and book directly — all publications verified and authorized.</p>
        </div>
      </div>
    </div>
    <div class="nhp-marketplace-body">
      <div class="nhp-container">
        <?php if ( $newspapers ) : ?>
        <div class="nhp-papers-grid">
          <?php foreach ( $newspapers as $i => $np ) :
              $from_price = max( (float) ( $np['min_charge'] ?? 0 ), (float) ( $np['base_rate_classified'] ?? 0 ) );
          ?>
          <article class="nhp-paper-card nhp-paper-card--a<?php echo (int) ( $i % 6 ); ?>">
            <div class="nhp-paper-card__top">
              <div class="nhp-paper-card__logo">
                <?php if ( $np['logo_url'] ) : ?>
                <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" loading="lazy">
                <?php else : ?>
                <span class="nhp-paper-card__logo-fallback"><?php echo esc_html( strtoupper( substr( $np['name'], 0, 2 ) ) ); ?></span>
                <?php endif; ?>
              </div>
              <div>
                <h3 class="nhp-paper-card__name"><?php echo esc_html( $np['name'] ); ?></h3>
                <p class="nhp-paper-card__meta"><?php echo esc_html( $np['language'] ?: 'English' ); ?> · <?php echo esc_html( $city_name ); ?> edition</p>
              </div>
            </div>
            <div style="font-size:0.8125rem;color:var(--nas-text-muted);margin-bottom:12px">
              Classified <strong>₹<?php echo number_format( (float) $np['base_rate_classified'], 0 ); ?>/word</strong> · Display <strong>₹<?php echo number_format( (float) $np['base_rate_display'], 0 ); ?>/sq.cm</strong>
            </div>
            <a href="<?php echo esc_url( $booking_url . '&newspaper=' . urlencode( $np['id'] ) ); ?>" class="nhp-paper-card__cta">Book in <?php echo esc_html( $city_name ); ?> <i class="fa-solid fa-arrow-right"></i></a>
          </article>
          <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div style="text-align:center;padding:48px;color:var(--nas-text-muted)">
          <i class="fa-solid fa-newspaper" style="font-size:3rem;margin-bottom:16px;display:block;opacity:.3"></i>
          <p>Newspaper listings for <?php echo esc_html( $city_name ); ?> are being updated. <a href="<?php echo esc_url( $booking_url ); ?>">Book directly</a> and our team will assist you.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php nas_portal_block_formats(); ?>

  <?php nas_portal_block_process( 'How It Works', 'Book in ' . $city_name . ' in 5 Steps', 'From newspaper selection to published proof — fully online.' ); ?>

  <?php nas_portal_block_advantages(); ?>

  <?php
  nas_portal_block_faq( [
      [ 'Which newspapers are available in ' . $city_name . '?', 'We list ' . count( $newspapers ) . '+ publications available in ' . $city_name . '. Browse the directory above or use the booking wizard for the complete list with live rates.' ],
      [ 'How long does it take to publish in ' . $city_name . '?', 'Classified ads typically publish within 1–3 working days. Display ads take 2–5 days depending on the newspaper and edition.' ],
      [ 'Can I book a ' . $city_name . ' edition of a national newspaper?', 'Yes. Many national newspapers have city-specific editions. Select ' . $city_name . ' in the booking wizard to see available editions and rates.' ],
  ], 'FAQ — ' . $city_name );
  ?>

  <?php nas_portal_block_cta(
      'Ready to Book Your Ad in ' . $city_name . '?',
      'Join thousands of businesses advertising in ' . $city_name . ' through our platform — instant rates, secure payment, publication proof.',
      $booking_url
  ); ?>
</div>
