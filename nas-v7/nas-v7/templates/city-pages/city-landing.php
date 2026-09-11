<?php if ( ! defined( 'ABSPATH' ) ) exit;

// $city, $meta, $newspapers, $categories injected by CityPagesModule
$city_name    = esc_html( $city['name'] ?? 'Your City' );
$state        = esc_html( $city['state'] ?? '' );
$tier         = (int) ( $city['tier'] ?? 2 );
$hero_heading = esc_html( $meta['hero_heading'] ?? "Newspaper Ads in {$city['name']}" );
$intro        = wp_kses_post( $meta['intro_content'] ?? '' );
$faqs         = json_decode( $meta['faqs_json'] ?? '[]', true ) ?: [];
$booking_url  = home_url( '/book-newspaper-ad/?city=' . urlencode( $city['slug'] ?? '' ) );
$cat_icons    = [ 'fa-bullhorn', 'fa-ring', 'fa-house', 'fa-briefcase', 'fa-graduation-cap', 'fa-scale-balanced', 'fa-building', 'fa-car', 'fa-coins', 'fa-trophy', 'fa-star', 'fa-party-horn' ];

$faq_pairs = [];
foreach ( $faqs as $faq ) {
    if ( ! empty( $faq['q'] ) ) {
        $faq_pairs[] = [ $faq['q'], $faq['a'] ?? '' ];
    }
}
if ( empty( $faq_pairs ) ) {
    $faq_pairs = [
        [ 'Which newspapers are available in ' . $city_name . '?', 'We list leading English, Hindi, and regional publications serving ' . $city_name . '. Browse the directory below or start the booking wizard for live rates.' ],
        [ 'How long does it take to publish in ' . $city_name . '?', 'Classified ads typically publish within 1–3 working days. Display ads take 2–5 days depending on the newspaper and edition.' ],
        [ 'Can I book a ' . $city_name . ' edition of a national newspaper?', 'Yes. Many national newspapers have city-specific editions. Select ' . $city_name . ' in the booking wizard to see available editions and rates.' ],
    ];
}
?>
<div class="nas-portal-page">
  <section class="nhp-hero" style="min-height:auto;padding:clamp(64px,10vw,100px) 0 48px">
    <div class="nhp-hero__bg" aria-hidden="true"></div>
    <div class="nhp-container" style="position:relative;z-index:2;text-align:center">
      <div style="margin-bottom:16px;display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
        <span class="nhp-hero__eyebrow" style="margin:0"><i class="fa-solid fa-location-dot"></i> <?php echo $state; ?></span>
        <?php if ( $tier === 1 ) : ?><span class="nhp-hero__trust-item nhp-hero__trust-item--c0">Tier 1 City</span><?php endif; ?>
      </div>
      <h1 class="nhp-hero__title" style="max-width:800px;margin:0 auto 16px"><?php echo $hero_heading; ?></h1>
      <p class="nhp-hero__subtitle" style="max-width:640px;margin:0 auto 28px">Classified &amp; display ads in <?php echo $city_name; ?>'s top newspapers — instant rates, verified publishers, online tracking.</p>
      <div class="nhp-hero__trust-row" style="justify-content:center;margin-bottom:28px">
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c0"><i class="fa-solid fa-bolt"></i> Same-day Processing</span>
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c1"><i class="fa-solid fa-shield-check"></i> Verified Publishers</span>
        <span class="nhp-hero__trust-item nhp-hero__trust-item--c2"><i class="fa-solid fa-receipt"></i> GST Invoice</span>
      </div>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--xl">Book an Ad in <?php echo $city_name; ?> <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </section>

  <?php nas_portal_block_trust_ribbon(); ?>

  <?php if ( $intro || true ) : ?>
  <section class="nas-portal-section">
    <div class="nhp-container" style="max-width:800px">
      <div class="nas-portal-section__head">
        <h2>Newspaper Advertising in <?php echo $city_name; ?></h2>
        <p>Everything you need to know about placing ads in <?php echo $city_name; ?>.</p>
      </div>
      <div class="nas-portal-prose">
        <?php if ( $intro ) : ?>
          <?php echo $intro; ?>
        <?php else : ?>
          <p><?php echo $city_name; ?> is one of India's important advertising markets with a wide readership across multiple languages and publications. Whether you need to publish an obituary, matrimonial, property, or job advertisement, our platform connects you directly with the top newspapers serving <?php echo $city_name; ?>.</p>
          <p>Our team handles the entire process — from ad composition and editing to submission and publication — so you can focus on what matters most. With competitive rates, AI-assisted ad writing, and real-time booking tracking, placing a newspaper ad has never been easier.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ( ! empty( $categories ) ) : ?>
  <section class="nhp-section nhp-section--warm">
    <div class="nhp-container">
      <div class="nhp-section__header nhp-section__header--center">
        <span class="nhp-section__eyebrow">Ad Categories</span>
        <h2 class="nhp-section__title">Popular Ad Types in <?php echo $city_name; ?></h2>
        <p class="nhp-section__subtitle">Matrimonial, property, jobs, business, legal notices, and more — book any category online.</p>
      </div>
      <div class="nhp-cat-grid">
        <?php foreach ( array_slice( $categories, 0, 12 ) as $ci => $cat ) : ?>
        <a href="<?php echo esc_url( $booking_url . '&category=' . urlencode( $cat['slug'] ?? $cat['name'] ) ); ?>" class="nhp-cat-card nhp-cat-card--c<?php echo (int) ( $ci % 6 ); ?>">
          <span class="nhp-cat-card__icon"><i class="fa-solid <?php echo esc_attr( ! empty( $cat['icon'] ) ? $cat['icon'] : $cat_icons[ $ci % count( $cat_icons ) ] ); ?>"></i></span>
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
          <h2 class="nhp-section__title">Newspapers in <?php echo $city_name; ?></h2>
          <p class="nhp-section__subtitle">Compare rates and book directly — all publications verified and authorized.</p>
        </div>
      </div>
    </div>
    <div class="nhp-marketplace-body">
      <div class="nhp-container">
        <?php if ( ! empty( $newspapers ) ) : ?>
        <div class="nhp-papers-grid">
          <?php foreach ( $newspapers as $i => $np ) : ?>
          <article class="nhp-paper-card nhp-paper-card--a<?php echo (int) ( $i % 6 ); ?>">
            <div class="nhp-paper-card__top">
              <div class="nhp-paper-card__logo">
                <?php if ( ! empty( $np['logo_url'] ) ) : ?>
                <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" loading="lazy">
                <?php else : ?>
                <span class="nhp-paper-card__logo-fallback"><?php echo esc_html( strtoupper( substr( $np['name'], 0, 2 ) ) ); ?></span>
                <?php endif; ?>
              </div>
              <div>
                <h3 class="nhp-paper-card__name"><?php echo esc_html( $np['name'] ); ?></h3>
                <p class="nhp-paper-card__meta"><?php echo esc_html( $np['language'] ?? 'English' ); ?> · <?php echo $city_name; ?> edition</p>
              </div>
            </div>
            <a href="<?php echo esc_url( $booking_url . '&newspaper=' . urlencode( $np['slug'] ?? $np['id'] ) ); ?>" class="nhp-paper-card__cta">Book in <?php echo $city_name; ?> <i class="fa-solid fa-arrow-right"></i></a>
          </article>
          <?php endforeach; ?>
        </div>
        <?php else : ?>
        <div style="text-align:center;padding:48px;color:var(--nas-text-muted)">
          <i class="fa-solid fa-newspaper" style="font-size:3rem;margin-bottom:16px;display:block;opacity:.3"></i>
          <p>All major national and regional newspapers available. <a href="<?php echo esc_url( $booking_url ); ?>">Book now</a> to see options for <?php echo $city_name; ?>.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <?php nas_portal_block_formats(); ?>
  <?php nas_portal_block_process( 'How It Works', 'Book in ' . $city_name . ' in 5 Steps', 'From newspaper selection to published proof — fully online.' ); ?>
  <?php nas_portal_block_advantages(); ?>
  <?php nas_portal_block_faq( $faq_pairs, 'FAQ — ' . $city_name ); ?>
  <?php nas_portal_block_cta(
      'Ready to Book Your Ad in ' . $city_name . '?',
      'Join thousands of businesses advertising in ' . $city_name . ' through our platform — instant rates, secure payment, publication proof.',
      $booking_url
  ); ?>
</div>
