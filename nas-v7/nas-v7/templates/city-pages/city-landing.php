<?php if ( ! defined( 'ABSPATH' ) ) exit;

// $city, $meta, $newspapers, $categories are injected by CityPagesModule
$city_name    = esc_html( $city['name'] ?? 'Your City' );
$state        = esc_html( $city['state'] ?? '' );
$tier         = $city['tier'] ?? 2;
$hero_heading = esc_html( $meta['hero_heading'] ?? "Newspaper Ads in {$city['name']}" );
$intro        = wp_kses_post( $meta['intro_content'] ?? '' );
$faqs         = json_decode( $meta['faqs_json'] ?? '[]', true ) ?: [];
$booking_url  = home_url( '/book-ad/?city=' . urlencode( $city['slug'] ?? '' ) );

?>

<!-- Hero -->
<section class="nas-city-hero">
  <div class="nas-city-hero__inner">
    <div class="nas-city-hero__eyebrow">
      <span class="nas-badge nas-badge--tier">Tier <?php echo (int) $tier; ?></span>
      <?php if ( $state ): ?><span class="nas-badge"><?php echo $state; ?></span><?php endif; ?>
    </div>
    <h1 class="nas-city-hero__title"><?php echo $hero_heading; ?></h1>
    <p class="nas-city-hero__sub">
      Book classified &amp; display ads in <?php echo $city_name; ?>'s top newspapers — quick, simple, and at the best rates.
    </p>
    <div class="nas-city-hero__badges">
      <span><i class="fa-solid fa-bolt"></i> Same-day Processing</span>
      <span><i class="fa-solid fa-shield-check"></i> Verified Publishers</span>
      <span><i class="fa-solid fa-headset"></i> Dedicated Support</span>
    </div>
    <div class="nas-city-hero__cta">
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nas-btn nas-btn-primary nas-btn-xl">
        <i class="fa-solid fa-pen-nib"></i> Book an Ad in <?php echo $city_name; ?>
      </a>
      <a href="#newspapers" class="nas-btn nas-btn-ghost nas-btn-xl">View Newspapers</a>
    </div>
  </div>
</section>

<!-- Features Strip -->
<section class="nas-features-strip">
  <div class="nas-features-strip__inner">
    <?php
    $features = [
      [ 'icon' => 'fa-clock',              'title' => 'Quick Booking',      'desc' => 'Place your ad in under 5 minutes' ],
      [ 'icon' => 'fa-indian-rupee-sign',  'title' => 'Best Rates',         'desc' => 'Guaranteed lowest ad rates' ],
      [ 'icon' => 'fa-newspaper',          'title' => 'All Major Papers',    'desc' => 'TOI, HT, Hindu & 12 more' ],
      [ 'icon' => 'fa-wand-magic-sparkles','title' => 'AI Ad Writing',       'desc' => 'Let AI craft your perfect ad' ],
      [ 'icon' => 'fa-chart-line',         'title' => 'Live Tracking',       'desc' => 'Track every step of your booking' ],
    ];
    foreach ( $features as $f ): ?>
    <div class="nas-feature-item">
      <div class="nas-feature-item__icon"><i class="fa-solid <?php echo esc_attr( $f['icon'] ); ?>"></i></div>
      <div class="nas-feature-item__text">
        <strong><?php echo esc_html( $f['title'] ); ?></strong>
        <span><?php echo esc_html( $f['desc'] ); ?></span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- Content + Sidebar -->
<section class="nas-city-section">
  <div class="nas-city-section__inner">

    <!-- Main Content -->
    <div class="nas-city-main-content">
      <?php if ( $intro ): ?>
      <div class="nas-city-intro nas-reveal"><?php echo $intro; ?></div>
      <?php else: ?>
      <div class="nas-city-intro nas-reveal">
        <h2>Newspaper Advertising in <?php echo $city_name; ?></h2>
        <p>
          <?php echo $city_name; ?> is one of India's important advertising markets with a wide readership across
          multiple languages and publications. Whether you need to publish an obituary, matrimonial, property,
          or job advertisement, our platform connects you directly with the top newspapers serving <?php echo $city_name; ?>.
        </p>
        <p>
          Our team handles the entire process — from ad composition and editing to submission and publication —
          so you can focus on what matters most. With competitive rates, AI-assisted ad writing, and real-time
          booking tracking, placing a newspaper ad has never been easier.
        </p>
      </div>
      <?php endif; ?>

      <!-- Newspapers -->
      <div class="nas-city-newspapers nas-reveal" id="newspapers">
        <h2>Newspapers Available in <?php echo $city_name; ?></h2>
        <div class="nas-newspaper-grid">
          <?php if ( ! empty( $newspapers ) ): foreach ( $newspapers as $np ): ?>
          <a class="nas-newspaper-card" href="<?php echo esc_url( $booking_url . '&newspaper=' . urlencode( $np['slug'] ?? '' ) ); ?>">
            <?php if ( ! empty( $np['logo_url'] ) ): ?>
            <img src="<?php echo esc_url( $np['logo_url'] ); ?>" alt="<?php echo esc_attr( $np['name'] ); ?>" loading="lazy">
            <?php else: ?>
            <div class="nas-newspaper-card__placeholder"><?php echo esc_html( substr( $np['name'], 0, 2 ) ); ?></div>
            <?php endif; ?>
            <div class="nas-newspaper-card__name"><?php echo esc_html( $np['name'] ); ?></div>
            <div class="nas-newspaper-card__lang"><?php echo esc_html( $np['language'] ?? '' ); ?></div>
            <span class="nas-newspaper-card__cta">Book Ad <i class="fa-solid fa-arrow-right"></i></span>
          </a>
          <?php endforeach; else: ?>
          <p class="nas-text-muted">All major national and regional newspapers available. <a href="<?php echo esc_url( $booking_url ); ?>">Book now</a> to see options.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- FAQ -->
      <?php if ( ! empty( $faqs ) ): ?>
      <div class="nas-faq-section nas-reveal">
        <h2>Frequently Asked Questions</h2>
        <div class="nas-faq-list">
          <?php foreach ( $faqs as $faq ): ?>
          <div class="nas-faq-item">
            <button class="nas-faq-question">
              <?php echo esc_html( $faq['q'] ?? '' ); ?>
              <i class="fa-solid fa-chevron-down"></i>
            </button>
            <div class="nas-faq-answer"><?php echo wp_kses_post( $faq['a'] ?? '' ); ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /nas-city-main-content -->

    <!-- Sidebar -->
    <aside class="nas-city-sidebar">
      <div class="nas-sidebar-form-card">
        <div class="nas-sidebar-form-card__header">
          <i class="fa-solid fa-pen-nib"></i> Book Your Ad
        </div>
        <div class="nas-sidebar-form-card__body">
          <p>Ready to reach <?php echo $city_name; ?>'s readers? Book your ad in 3 simple steps.</p>
          <a href="<?php echo esc_url( $booking_url ); ?>" class="nas-btn nas-btn-primary" style="width:100%;text-align:center;margin-top:.75rem;">
            Get Started <i class="fa-solid fa-arrow-right"></i>
          </a>
          <div class="nas-sidebar-trust">
            <div><i class="fa-solid fa-phone"></i> <?php echo esc_html( get_option( 'nas_agency_phone', '' ) ); ?></div>
            <div><i class="fa-solid fa-envelope"></i> <?php echo esc_html( get_option( 'nas_agency_email', '' ) ); ?></div>
            <div><i class="fa-brands fa-whatsapp"></i> WhatsApp Support Available</div>
          </div>
        </div>
      </div>

      <!-- Categories -->
      <div class="nas-sidebar-categories">
        <h4>Ad Categories</h4>
        <?php if ( ! empty( $categories ) ): ?>
        <div class="nas-category-strip">
          <?php foreach ( array_slice( $categories, 0, 8 ) as $cat ): ?>
          <a class="nas-category-item" href="<?php echo esc_url( $booking_url . '&category=' . urlencode( $cat['slug'] ?? '' ) ); ?>">
            <?php if ( ! empty( $cat['icon'] ) ): ?><i class="fa-solid <?php echo esc_attr( $cat['icon'] ); ?>"></i><?php endif; ?>
            <?php echo esc_html( $cat['name'] ); ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </aside>

  </div>
</section>

<!-- CTA Band -->
<section class="nas-cta-band nas-reveal">
  <div class="nas-cta-band__inner">
    <div class="nas-cta-band__text">
      <h2>Place Your Ad in <?php echo $city_name; ?> Today</h2>
      <p>Join thousands of satisfied customers who trust us for their newspaper advertising needs.</p>
    </div>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nas-btn nas-btn-primary nas-btn-xl">
      <i class="fa-solid fa-paper-plane"></i> Book Now
    </a>
  </div>
</section>
