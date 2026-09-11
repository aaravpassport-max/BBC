<?php
/**
 * NAS Homepage — Premium newspaper advertising marketplace
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'show_admin_bar', '__return_false', 999 );
remove_action( 'wp_head', '_admin_bar_bump_cb' );

$cfg     = \NAS\Core\Config::instance();
$db      = \NAS\Core\Database::instance();
$brand   = $cfg->get( 'brand_name', get_bloginfo( 'name' ) );
$color   = $cfg->get( 'brand_primary_color', '#1A3A5C' );
$tagline = $cfg->get( 'brand_tagline', 'Book Newspaper Ads Online — Fast, Easy, Affordable' );
$phone   = $cfg->get( 'brand_phone', '' );
$email   = $cfg->get( 'brand_email', '' );
$logo    = $cfg->get( 'logo_url', '' );
$walink  = $cfg->get( 'brand_whatsapp', '' )
    ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $cfg->get( 'brand_whatsapp', '' ) )
    : '';

$total_bookings = (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('bookings')} WHERE status != 'cancelled'" )['c'] ?? 0 );
$total_papers   = (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('newspapers')} WHERE is_active=1" )['c'] ?? 0 );
$total_cities   = (int) ( $db->row( "SELECT COUNT(*) as c FROM {$db->t('cities')} WHERE is_active=1" )['c'] ?? 0 );

$categories = $db->select( "SELECT * FROM {$db->t('categories')} WHERE is_active=1 ORDER BY sort_order ASC LIMIT 12" );
$top_cities = $db->select( "SELECT * FROM {$db->t('cities')} WHERE is_active=1 ORDER BY tier ASC, name ASC LIMIT 24" );
$newspapers = $db->select(
    "SELECT id, name, slug, language, logo_url, base_rate_classified, min_charge, editions, circulation, sort_order
     FROM {$db->t('newspapers')} WHERE is_active=1 ORDER BY sort_order ASC, name ASC LIMIT 12"
);
$faqs = $db->select( "SELECT question, answer FROM {$db->t('faqs')} WHERE is_active=1 ORDER BY sort_order ASC LIMIT 10" );

$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
$contact_url = get_permalink( get_option( 'nas_page_contact' ) ) ?: home_url( '/contact-us/' );
$faq_url     = get_permalink( get_option( 'nas_page_faq' ) ) ?: home_url( '/faq/' );
$track_url   = get_permalink( get_option( 'nas_page_track_order' ) ) ?: home_url( '/track-order/' );
$papers_url  = home_url( '/newspaper-ads/' );
$cities_url  = home_url( '/cities/' );

$fb = $cfg->get( 'social_facebook', '' );
$ig = $cfg->get( 'social_instagram', '' );
$li = $cfg->get( 'social_linkedin', '' );
$tw = $cfg->get( 'social_twitter', '' );

$cat_icons = [ '📢', '💍', '🏠', '💼', '📚', '⚖️', '🏢', '🚗', '💰', '🏆', '🌟', '🎉' ];

$paper_langs = [];
foreach ( $newspapers as $np ) {
    $lang = $np['language'] ?: 'English';
    if ( ! in_array( $lang, $paper_langs, true ) ) {
        $paper_langs[] = $lang;
    }
}
sort( $paper_langs );

/**
 * Parse editions JSON field into a readable string.
 */
function nhp_edition_label( $editions_raw, $fallback = '' ) {
    if ( empty( $editions_raw ) ) {
        return $fallback;
    }
    $editions = json_decode( $editions_raw, true );
    if ( ! is_array( $editions ) || empty( $editions ) ) {
        return $fallback;
    }
    $slice = array_slice( $editions, 0, 2 );
    $label = implode( ', ', $slice );
    if ( count( $editions ) > 2 ) {
        $label .= ' +' . ( count( $editions ) - 2 );
    }
    return $label;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
  <meta charset="<?php bloginfo( 'charset' ); ?>">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="description" content="<?php echo esc_attr( $tagline ); ?> — <?php echo esc_attr( $brand ); ?>">
  <title><?php echo esc_html( $brand ); ?> — <?php echo esc_html( $tagline ); ?></title>
  <?php wp_head(); ?>
  <style>:root{--nhp-primary:<?php echo esc_attr( $color ); ?>;--nas-primary:<?php echo esc_attr( $color ); ?>;}</style>
</head>
<body class="nas-homepage">

<!-- Header -->
<header class="nhp-header">
  <div class="nhp-container nhp-header__inner">
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="nhp-header__brand">
      <?php if ( $logo ) : ?>
        <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" class="nhp-header__logo-img">
      <?php else : ?>
        <span class="nhp-header__logo-text"><i class="fa-solid fa-newspaper"></i> <?php echo esc_html( $brand ); ?></span>
      <?php endif; ?>
    </a>
    <nav class="nhp-header__nav" aria-label="Main navigation">
      <a class="nhp-header__link is-active" href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
      <a class="nhp-header__link" href="<?php echo esc_url( $papers_url ); ?>">Newspapers</a>
      <a class="nhp-header__link" href="<?php echo esc_url( $faq_url ); ?>">FAQ</a>
      <a class="nhp-header__link" href="<?php echo esc_url( $contact_url ); ?>">Contact</a>
      <a class="nhp-header__link" href="<?php echo esc_url( $track_url ); ?>">Track Order</a>
    </nav>
    <div class="nhp-header__actions">
      <?php if ( is_user_logged_in() ) : ?>
        <a href="<?php echo esc_url( nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' ) ); ?>" class="nhp-header__login">My Dashboard</a>
      <?php else : ?>
        <a href="<?php echo esc_url( home_url( '/newspaper-ad-login/' ) ); ?>" class="nhp-header__login">Login</a>
      <?php endif; ?>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--lg">Check Ad Rates</a>
      <button class="nhp-header__menu-btn" id="nhp-menu-btn" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
    </div>
  </div>
</header>

<!-- Mobile nav -->
<div class="nhp-mobile-nav" id="nhp-mobile-nav">
  <div class="nhp-mobile-nav__panel">
    <button class="nhp-mobile-nav__close" id="nhp-mobile-close" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
    <?php
    foreach (
        [
            [ 'Home', home_url( '/' ) ],
            [ 'Newspapers', $papers_url ],
            [ 'FAQ', $faq_url ],
            [ 'Contact', $contact_url ],
            [ 'Track Order', $track_url ],
        ] as $nl
    ) :
    ?>
    <a href="<?php echo esc_url( $nl[1] ); ?>" class="nhp-mobile-nav__link"><?php echo esc_html( $nl[0] ); ?></a>
    <?php endforeach; ?>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--block" style="margin-top:16px">Check Ad Rates</a>
  </div>
</div>

<!-- Hero + Booking -->
<section class="nhp-hero" id="book">
  <div class="nhp-hero__bg" aria-hidden="true"></div>
  <svg class="nhp-hero__paper-stack" viewBox="0 0 200 260" fill="none" aria-hidden="true">
    <rect x="20" y="30" width="160" height="200" rx="4" fill="white" opacity="0.9"/>
    <rect x="30" y="20" width="160" height="200" rx="4" fill="white" opacity="0.7"/>
    <rect x="10" y="40" width="160" height="200" rx="4" fill="white"/>
    <line x1="30" y1="70" x2="150" y2="70" stroke="#1A3A5C" stroke-width="2" opacity="0.3"/>
    <line x1="30" y1="90" x2="150" y2="90" stroke="#1A3A5C" stroke-width="1" opacity="0.2"/>
    <line x1="30" y1="105" x2="130" y2="105" stroke="#1A3A5C" stroke-width="1" opacity="0.2"/>
    <line x1="30" y1="120" x2="150" y2="120" stroke="#1A3A5C" stroke-width="1" opacity="0.2"/>
    <rect x="30" y="140" width="60" height="40" rx="2" fill="#EFA414" opacity="0.4"/>
  </svg>
  <div class="nhp-container nhp-hero__grid">
    <div>
      <div class="nhp-hero__eyebrow"><i class="fa-solid fa-shield-check"></i> India's Trusted Newspaper Ad Platform</div>
      <h1 class="nhp-hero__title">Book <em>Newspaper Ads</em> Across India Online</h1>
      <p class="nhp-hero__subtitle">
        <?php echo esc_html( $tagline ); ?>.
        <?php if ( $total_papers > 0 && $total_cities > 0 ) : ?>
          Choose from <?php echo (int) $total_papers; ?>+ verified publications across <?php echo (int) $total_cities; ?>+ cities — with instant rates, secure payment, and online tracking.
        <?php else : ?>
          Choose from hundreds of verified publications nationwide — with instant rates, secure payment, and online tracking.
        <?php endif; ?>
      </p>
      <div class="nhp-hero__trust-row">
        <span class="nhp-hero__trust-item"><i class="fa-solid fa-lock"></i> Secure Payments</span>
        <span class="nhp-hero__trust-item"><i class="fa-solid fa-circle-check"></i> Verified Publishers</span>
        <span class="nhp-hero__trust-item"><i class="fa-solid fa-bolt"></i> Instant Quotes</span>
        <span class="nhp-hero__trust-item"><i class="fa-solid fa-location-dot"></i> Pan-India Coverage</span>
      </div>
      <div class="nhp-hero__stats">
        <div>
          <div class="nhp-hero__stat-value"><?php echo $total_papers > 0 ? (int) $total_papers . '+' : '94+'; ?></div>
          <div class="nhp-hero__stat-label">Newspapers</div>
        </div>
        <div>
          <div class="nhp-hero__stat-value"><?php echo $total_cities > 0 ? (int) $total_cities . '+' : '299+'; ?></div>
          <div class="nhp-hero__stat-label">Cities</div>
        </div>
        <div>
          <div class="nhp-hero__stat-value"><?php echo $total_bookings > 0 ? number_format( $total_bookings ) : '9k+'; ?></div>
          <div class="nhp-hero__stat-label">Ads Placed</div>
        </div>
        <div>
          <div class="nhp-hero__stat-value">24<span style="font-size:0.6em">hrs</span></div>
          <div class="nhp-hero__stat-label">Avg. Processing</div>
        </div>
      </div>
    </div>

    <div class="nhp-booking-panel">
      <div class="nhp-booking-panel__header">
        <h2 class="nhp-booking-panel__title">Start Your Booking</h2>
        <p class="nhp-booking-panel__subtitle">Select city, category &amp; newspaper to check rates</p>
        <div class="nhp-booking-panel__steps">
          <span class="nhp-booking-panel__step is-active">1. City</span>
          <span class="nhp-booking-panel__step">2. Category</span>
          <span class="nhp-booking-panel__step">3. Newspaper</span>
        </div>
      </div>
      <div class="nhp-booking-panel__body">
        <form id="nhp-quick-book">
          <div class="nhp-form-field">
            <label class="nhp-form-label" for="nhp-qb-city">Choose your destination <span>— City / Edition</span></label>
            <select class="nhp-form-select" id="nhp-qb-city">
              <option value="">Select City</option>
              <?php foreach ( $top_cities as $c ) : ?>
              <option value="<?php echo esc_attr( $c['id'] ); ?>"><?php echo esc_html( $c['name'] ); ?>, <?php echo esc_html( $c['state'] ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="nhp-form-field">
            <label class="nhp-form-label" for="nhp-qb-category">Choose advertisement type</label>
            <select class="nhp-form-select" id="nhp-qb-category">
              <option value="">Ad Category</option>
              <?php foreach ( $categories as $cat ) : ?>
              <option value="<?php echo esc_attr( $cat['id'] ); ?>"><?php echo esc_html( $cat['name'] ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="nhp-form-field">
            <label class="nhp-form-label" for="nhp-qb-newspaper">Choose newspaper</label>
            <select class="nhp-form-select" id="nhp-qb-newspaper">
              <option value="">Select Newspaper</option>
              <?php foreach ( $newspapers as $np ) : ?>
              <option value="<?php echo esc_attr( $np['id'] ); ?>"><?php echo esc_html( $np['name'] ); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="nhp-btn nhp-btn--secondary nhp-btn--lg nhp-btn--block">
            Check Rates &amp; Availability <i class="fa-solid fa-arrow-right"></i>
          </button>
        </form>
        <div class="nhp-booking-panel__trust">
          <span><i class="fa-solid fa-lock"></i> Secure</span>
          <span><i class="fa-solid fa-bolt"></i> Instant Quote</span>
          <span><i class="fa-solid fa-circle-check"></i> Verified Publishers</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Popular Newspapers (Marketplace) -->
<?php if ( $newspapers ) : ?>
<section class="nhp-section" id="newspapers">
  <div class="nhp-container">
    <div class="nhp-section__header">
      <span class="nhp-section__eyebrow">Popular Newspapers</span>
      <h2 class="nhp-section__title">Book India's Leading Publications</h2>
      <p class="nhp-section__subtitle">Search and compare newspapers by language, edition, and starting rates — then book in minutes.</p>
    </div>

    <div class="nhp-toolbar">
      <div class="nhp-search">
        <i class="fa-solid fa-search nhp-search__icon"></i>
        <input type="search" class="nhp-search__input" id="nhp-paper-search" placeholder="Search newspapers by name or language…" autocomplete="off">
      </div>
      <div class="nhp-filters" role="group" aria-label="Filter by language">
        <button type="button" class="nhp-filter-chip is-active" data-lang="all">All</button>
        <?php foreach ( $paper_langs as $lang ) : ?>
        <button type="button" class="nhp-filter-chip" data-lang="<?php echo esc_attr( strtolower( $lang ) ); ?>"><?php echo esc_html( $lang ); ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="nhp-papers-grid">
      <?php foreach ( $newspapers as $i => $np ) :
        $edition_label = nhp_edition_label( $np['editions'] ?? '', 'Multiple editions' );
        $from_price    = max( (float) ( $np['min_charge'] ?? 0 ), (float) ( $np['base_rate_classified'] ?? 0 ) );
        $detail_url    = ! empty( $np['slug'] )
            ? home_url( '/newspaper/' . $np['slug'] . '/' )
            : $booking_url . '?newspaper=' . urlencode( $np['id'] );
      ?>
      <article class="nhp-paper-card"
               data-name="<?php echo esc_attr( strtolower( $np['name'] ) ); ?>"
               data-lang="<?php echo esc_attr( strtolower( $np['language'] ?: 'english' ) ); ?>">
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
            <p class="nhp-paper-card__meta"><?php echo esc_html( $np['language'] ?: 'English' ); ?> · <?php echo esc_html( $edition_label ); ?></p>
            <?php if ( $i < 3 ) : ?>
              <span class="nhp-paper-card__badge"><i class="fa-solid fa-star"></i> Popular</span>
            <?php endif; ?>
          </div>
        </div>
        <?php if ( $from_price > 0 ) : ?>
        <p class="nhp-paper-card__price">From <strong>₹<?php echo number_format( $from_price, 0 ); ?></strong> <span>classified ads</span></p>
        <?php endif; ?>
        <div class="nhp-paper-card__actions">
          <a href="<?php echo esc_url( $detail_url ); ?>" class="nhp-btn nhp-btn--outline-dark">View Editions</a>
          <a href="<?php echo esc_url( $booking_url . '?newspaper=' . urlencode( $np['id'] ) ); ?>" class="nhp-btn nhp-btn--secondary">Check Rates</a>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="nhp-papers-empty" id="nhp-papers-empty" style="display:none">No newspapers match your search. Try a different term or language.</p>
    <div style="text-align:center;margin-top:40px">
      <a href="<?php echo esc_url( $papers_url ); ?>" class="nhp-btn nhp-btn--secondary nhp-btn--lg">Browse All Newspapers <i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Ad Categories -->
<section class="nhp-section nhp-section--muted" id="categories">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow">Advertisement Categories</span>
      <h2 class="nhp-section__title">Every Type of Newspaper Ad</h2>
      <p class="nhp-section__subtitle">From classified text to full-page display — select your ad type and start booking.</p>
    </div>
    <div class="nhp-categories-scroll">
      <?php if ( $categories ) :
        foreach ( $categories as $i => $cat ) :
          $icon = $cat['icon'] ?: ( $cat_icons[ $i ] ?? '📢' );
      ?>
      <a href="<?php echo esc_url( $booking_url . '?category=' . urlencode( $cat['id'] ) ); ?>" class="nhp-category-tile">
        <span class="nhp-category-tile__icon"><?php echo esc_html( $icon ); ?></span>
        <span class="nhp-category-tile__name"><?php echo esc_html( $cat['name'] ); ?></span>
        <span class="nhp-category-tile__desc"><?php echo esc_html( $cat['description'] ?: 'Book ' . $cat['name'] . ' ads online' ); ?></span>
        <i class="fa-solid fa-arrow-right nhp-category-tile__arrow"></i>
      </a>
      <?php endforeach; else :
        $default_cats = [
          [ '📢', 'Classified Text', 'Name change, lost & found, personal' ],
          [ '🖼️', 'Display Ads', 'Image + text, column based pricing' ],
          [ '💍', 'Matrimonial', 'Bride & groom wanted ads' ],
          [ '🏠', 'Property', 'Buy, sell & rent property' ],
          [ '💼', 'Recruitment', 'Job vacancies & career ads' ],
          [ '⚖️', 'Public Notice', 'Legal & government notices' ],
        ];
        foreach ( $default_cats as $c ) :
      ?>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-category-tile">
        <span class="nhp-category-tile__icon"><?php echo $c[0]; ?></span>
        <span class="nhp-category-tile__name"><?php echo esc_html( $c[1] ); ?></span>
        <span class="nhp-category-tile__desc"><?php echo esc_html( $c[2] ); ?></span>
        <i class="fa-solid fa-arrow-right nhp-category-tile__arrow"></i>
      </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</section>

<!-- How Booking Works -->
<section class="nhp-section" id="how-it-works">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow">How Booking Works</span>
      <h2 class="nhp-section__title">From Selection to Publication</h2>
      <p class="nhp-section__subtitle">A clear, guided workflow — configure your ad, preview pricing, pay securely, and track until publication.</p>
    </div>
    <div class="nhp-process">
      <?php
      $steps = [
        [ 'fa-map-location-dot', 'Select', 'Choose city, newspaper & ad category', '' ],
        [ 'fa-sliders', 'Configure', 'Set ad size, edition & compose your copy', '' ],
        [ 'fa-indian-rupee-sign', 'Preview Price', 'See transparent rates before you commit', 'Instant' ],
        [ 'fa-credit-card', 'Pay Securely', 'UPI, cards & net banking via Razorpay', '' ],
        [ 'fa-circle-check', 'Track & Confirm', 'Monitor status until publication proof', '24–48 hrs' ],
      ];
      foreach ( $steps as $s ) :
      ?>
      <div class="nhp-process__step">
        <div class="nhp-process__dot"><i class="fa-solid <?php echo esc_attr( $s[0] ); ?>"></i></div>
        <h3 class="nhp-process__title"><?php echo esc_html( $s[1] ); ?></h3>
        <p class="nhp-process__desc"><?php echo esc_html( $s[2] ); ?></p>
        <?php if ( $s[3] ) : ?>
        <span class="nhp-process__time"><?php echo esc_html( $s[3] ); ?></span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Coverage / Cities -->
<?php if ( $top_cities ) : ?>
<section class="nhp-section nhp-section--muted" id="coverage">
  <div class="nhp-container">
    <div class="nhp-section__header">
      <span class="nhp-section__eyebrow">Nationwide Coverage</span>
      <h2 class="nhp-section__title">Available in <?php echo $total_cities > 0 ? (int) $total_cities . '+' : '299+'; ?> Cities</h2>
      <p class="nhp-section__subtitle">Book newspaper ads in Tier 1, Tier 2 &amp; Tier 3 cities across every major state.</p>
    </div>
    <div class="nhp-cities-layout">
      <div class="nhp-cities-map">
        <div class="nhp-cities-map__stat">
          <div class="nhp-cities-map__number"><?php echo $total_cities > 0 ? (int) $total_cities . '+' : '299+'; ?></div>
          <div class="nhp-cities-map__label">cities covered across India</div>
        </div>
        <div class="nhp-cities-map__tiers">
          <div class="nhp-cities-map__tier"><span class="nhp-cities-map__tier-dot" style="background:#fff"></span> Tier 1 — Metro cities</div>
          <div class="nhp-cities-map__tier"><span class="nhp-cities-map__tier-dot" style="background:#0ea5e9"></span> Tier 2 — Major cities</div>
          <div class="nhp-cities-map__tier"><span class="nhp-cities-map__tier-dot" style="background:#94a3b8"></span> Tier 3 — Emerging markets</div>
        </div>
      </div>
      <div class="nhp-cities-panel">
        <div class="nhp-cities-panel__search nhp-search">
          <i class="fa-solid fa-search nhp-search__icon"></i>
          <input type="search" class="nhp-search__input" id="nhp-city-search" placeholder="Search cities…" autocomplete="off">
        </div>
        <div class="nhp-cities-list">
          <?php foreach ( $top_cities as $city ) :
            $tier = (int) ( $city['tier'] ?: 3 );
          ?>
          <a href="<?php echo esc_url( $booking_url . '?city=' . urlencode( $city['id'] ) ); ?>"
             class="nhp-city-tag nhp-city-tag--tier-<?php echo $tier <= 2 ? $tier : 3; ?>"
             data-name="<?php echo esc_attr( strtolower( $city['name'] . ' ' . $city['state'] ) ); ?>">
            <span class="nhp-city-tag__dot"></span>
            <?php echo esc_html( $city['name'] ); ?>
          </a>
          <?php endforeach; ?>
        </div>
        <div style="margin-top:24px">
          <a href="<?php echo esc_url( $cities_url ); ?>" class="nhp-btn nhp-btn--outline-dark">View All Cities <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Why Platform -->
<section class="nhp-section" id="why-us">
  <div class="nhp-container">
    <div class="nhp-section__header nhp-section__header--center">
      <span class="nhp-section__eyebrow">Why This Platform</span>
      <h2 class="nhp-section__title">Built for Newspaper Advertisers</h2>
      <p class="nhp-section__subtitle">Not a generic booking form — a complete platform for placing, paying, and tracking newspaper ads.</p>
    </div>
    <div class="nhp-benefits">
      <div class="nhp-benefit nhp-benefit--featured">
        <div>
          <div class="nhp-benefit__icon" style="background:rgba(42,138,250,.12);color:#2A8AFA"><i class="fa-solid fa-chart-line"></i></div>
          <h3 class="nhp-benefit__title">Track Everything</h3>
          <p class="nhp-benefit__desc">Real-time status from booking to publication. Get notified at every step — under review, payment received, submitted to publisher, and published.</p>
        </div>
        <div class="nhp-benefit__preview">
          <div class="nhp-preview-track">
            <div class="nhp-preview-track__item"><span class="nhp-preview-track__dot nhp-preview-track__dot--done"></span> Booking received</div>
            <div class="nhp-preview-track__item"><span class="nhp-preview-track__dot nhp-preview-track__dot--done"></span> Payment confirmed</div>
            <div class="nhp-preview-track__item"><span class="nhp-preview-track__dot nhp-preview-track__dot--active"></span> Submitted to publisher</div>
            <div class="nhp-preview-track__item"><span class="nhp-preview-track__dot nhp-preview-track__dot--pending"></span> Publication proof</div>
          </div>
        </div>
      </div>
      <div class="nhp-benefit">
        <div class="nhp-benefit__icon" style="background:rgba(239,164,20,.14);color:#D68F0A"><i class="fa-solid fa-sack-dollar"></i></div>
        <h3 class="nhp-benefit__title">Transparent Rates</h3>
        <p class="nhp-benefit__desc">Direct newspaper rates with no hidden charges. See pricing before you pay, with GST invoice included.</p>
      </div>
      <div class="nhp-benefit">
        <div class="nhp-benefit__icon" style="background:rgba(47,82,88,.12);color:#2F5258"><i class="fa-solid fa-shield-halved"></i></div>
        <h3 class="nhp-benefit__title">Secure Payments</h3>
        <p class="nhp-benefit__desc">All transactions via Razorpay — UPI, cards, and net banking with industry-standard encryption.</p>
      </div>
      <div class="nhp-benefit">
        <div class="nhp-benefit__icon" style="background:rgba(42,138,250,.12);color:#2A8AFA"><i class="fa-solid fa-file-invoice"></i></div>
        <h3 class="nhp-benefit__title">Instant Invoice</h3>
        <p class="nhp-benefit__desc">Auto-generated GST invoice after payment. Download anytime from your client dashboard.</p>
      </div>
      <div class="nhp-benefit">
        <div class="nhp-benefit__icon" style="background:rgba(239,164,20,.14);color:#D68F0A"><i class="fa-solid fa-bolt"></i></div>
        <h3 class="nhp-benefit__title">Book in Minutes</h3>
        <p class="nhp-benefit__desc">No phone calls or emails required. Complete your booking online 24/7 in under three minutes.</p>
      </div>
    </div>
  </div>
</section>

<!-- Trust bar (real platform metrics only) -->
<section class="nhp-section nhp-section--muted">
  <div class="nhp-container">
    <div class="nhp-trust-bar">
      <?php if ( $total_papers > 0 ) : ?>
      <div class="nhp-trust-bar__item">
        <div class="nhp-trust-bar__value"><?php echo (int) $total_papers; ?>+</div>
        <div class="nhp-trust-bar__label">Active Newspapers</div>
      </div>
      <?php endif; ?>
      <?php if ( $total_cities > 0 ) : ?>
      <div class="nhp-trust-bar__item">
        <div class="nhp-trust-bar__value"><?php echo (int) $total_cities; ?>+</div>
        <div class="nhp-trust-bar__label">Cities Covered</div>
      </div>
      <?php endif; ?>
      <?php if ( $total_bookings > 0 ) : ?>
      <div class="nhp-trust-bar__item">
        <div class="nhp-trust-bar__value"><?php echo number_format( $total_bookings ); ?></div>
        <div class="nhp-trust-bar__label">Ads Successfully Placed</div>
      </div>
      <?php endif; ?>
      <div class="nhp-trust-bar__item">
        <div class="nhp-trust-bar__value"><i class="fa-solid fa-shield-check" style="font-size:1.5rem"></i></div>
        <div class="nhp-trust-bar__label">Verified Publisher Network</div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ (single list — no column duplication) -->
<?php if ( $faqs ) : ?>
<section class="nhp-section" id="faq">
  <div class="nhp-container">
    <div class="nhp-section__header">
      <span class="nhp-section__eyebrow">Common Questions</span>
      <h2 class="nhp-section__title">Frequently Asked Questions</h2>
      <p class="nhp-section__subtitle">Answers to the most important questions about booking newspaper ads online.</p>
    </div>
    <div class="nhp-faq-layout">
      <aside class="nhp-faq-sidebar">
        <div class="nhp-faq-search nhp-search">
          <i class="fa-solid fa-search nhp-search__icon"></i>
          <input type="search" class="nhp-search__input" id="nhp-faq-search" placeholder="Search questions…" autocomplete="off">
        </div>
        <p style="font-size:var(--nhp-text-sm);color:var(--nhp-ink-muted);line-height:1.6">Can't find your answer? <a href="<?php echo esc_url( $contact_url ); ?>" style="color:var(--nhp-primary);font-weight:600">Contact our team</a> or browse the full FAQ.</p>
        <a href="<?php echo esc_url( $faq_url ); ?>" class="nhp-btn nhp-btn--outline-dark" style="margin-top:16px;width:100%">View All FAQs</a>
      </aside>
      <div class="nhp-faq-list">
        <?php foreach ( $faqs as $faq ) : ?>
        <div class="nhp-faq-item">
          <button type="button" class="nhp-faq-question" aria-expanded="false">
            <?php echo esc_html( $faq['question'] ); ?>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <div class="nhp-faq-answer"><?php echo wp_kses_post( $faq['answer'] ); ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Final CTA -->
<section class="nhp-cta">
  <div class="nhp-container nhp-cta__inner">
    <h2 class="nhp-cta__title">Ready to Place Your Newspaper Ad?</h2>
    <p class="nhp-cta__subtitle">
      <?php if ( $total_bookings > 0 ) : ?>
        Join <?php echo number_format( $total_bookings ); ?> advertisers who trust <?php echo esc_html( $brand ); ?> for their newspaper advertising.
      <?php else : ?>
        Start booking with <?php echo esc_html( $brand ); ?> — India's trusted newspaper ad platform.
      <?php endif; ?>
    </p>
    <div class="nhp-cta__actions">
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--white nhp-btn--xl">Check Ad Rates <i class="fa-solid fa-arrow-right"></i></a>
      <a href="<?php echo esc_url( $contact_url ); ?>" class="nhp-btn nhp-btn--ghost nhp-btn--lg">Talk to Us</a>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="nhp-footer">
  <div class="nhp-container">
    <div class="nhp-footer__grid">
      <div>
        <div class="nhp-footer__brand-name">
          <i class="fa-solid fa-newspaper"></i> <?php echo esc_html( $brand ); ?>
        </div>
        <p class="nhp-footer__tagline"><?php echo esc_html( $cfg->get( 'footer_tagline', 'Book newspaper ads online across India — fast, transparent, reliable.' ) ); ?></p>
        <?php if ( $email || $phone ) : ?>
        <div class="nhp-footer__contact">
          <?php if ( $email ) : ?><div><i class="fa-solid fa-envelope"></i> <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a></div><?php endif; ?>
          <?php if ( $phone ) : ?><div style="margin-top:6px"><i class="fa-solid fa-phone"></i> <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>"><?php echo esc_html( $phone ); ?></a></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="nhp-footer__social">
          <?php if ( $fb ) : ?><a href="<?php echo esc_url( $fb ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-facebook-f"></i></a><?php endif; ?>
          <?php if ( $ig ) : ?><a href="<?php echo esc_url( $ig ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-instagram"></i></a><?php endif; ?>
          <?php if ( $li ) : ?><a href="<?php echo esc_url( $li ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-linkedin-in"></i></a><?php endif; ?>
          <?php if ( $tw ) : ?><a href="<?php echo esc_url( $tw ); ?>" target="_blank" rel="noopener"><i class="fa-brands fa-x-twitter"></i></a><?php endif; ?>
          <?php if ( $walink ) : ?><a href="<?php echo esc_url( $walink ); ?>" target="_blank" rel="noopener" style="color:#25D366"><i class="fa-brands fa-whatsapp"></i></a><?php endif; ?>
        </div>
      </div>
      <div class="nhp-footer__col">
        <h4>Newspapers</h4>
        <ul>
          <?php foreach ( array_slice( $newspapers ?: [], 0, 5 ) as $np ) : ?>
          <li><a href="<?php echo esc_url( $booking_url . '?newspaper=' . urlencode( $np['id'] ) ); ?>"><?php echo esc_html( $np['name'] ); ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?php echo esc_url( $papers_url ); ?>">View All →</a></li>
        </ul>
      </div>
      <div class="nhp-footer__col">
        <h4>Top Cities</h4>
        <ul>
          <?php foreach ( array_slice( $top_cities ?: [], 0, 5 ) as $city ) : ?>
          <li><a href="<?php echo esc_url( $booking_url . '?city=' . urlencode( $city['id'] ) ); ?>"><?php echo esc_html( $city['name'] ); ?></a></li>
          <?php endforeach; ?>
          <li><a href="<?php echo esc_url( $cities_url ); ?>">All Cities →</a></li>
        </ul>
      </div>
      <div class="nhp-footer__col">
        <h4>Support</h4>
        <ul>
          <li><a href="<?php echo esc_url( $booking_url ); ?>">Book an Ad</a></li>
          <li><a href="<?php echo esc_url( $track_url ); ?>">Track Order</a></li>
          <li><a href="<?php echo esc_url( $faq_url ); ?>">FAQ</a></li>
          <li><a href="<?php echo esc_url( $contact_url ); ?>">Contact Us</a></li>
          <li><a href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">About Us</a></li>
        </ul>
      </div>
    </div>
    <div class="nhp-footer__bottom">
      <div class="nhp-footer__copy">© <?php echo date( 'Y' ); ?> <?php echo esc_html( $brand ); ?>. All rights reserved.</div>
      <div class="nhp-footer__legal">
        <a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a>
        <a href="<?php echo esc_url( home_url( '/terms-conditions/' ) ); ?>">Terms</a>
        <a href="<?php echo esc_url( home_url( '/refund-policy/' ) ); ?>">Refund Policy</a>
      </div>
    </div>
  </div>
</footer>

<?php if ( $walink ) : ?>
<a href="<?php echo esc_url( $walink ); ?>" class="nhp-wa-float" target="_blank" rel="noopener" title="Chat on WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
<?php endif; ?>

<?php wp_footer(); ?>
</body>
</html>
