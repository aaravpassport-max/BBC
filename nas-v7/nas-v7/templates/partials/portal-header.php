<?php
/**
 * Unified public portal header — matches homepage (nhp-topbar + nhp-header).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$cfg         = \NAS\Core\Config::instance();
$brand       = $cfg->get( 'brand_name', get_bloginfo( 'name' ) );
$phone       = $cfg->get( 'brand_phone', '' );
$email       = $cfg->get( 'brand_email', '' );
$logo        = $cfg->get( 'logo_url', get_option( 'nas_logo_url', '' ) );
$walink      = $cfg->get( 'brand_whatsapp', '' )
    ? 'https://wa.me/' . preg_replace( '/[^0-9]/', '', $cfg->get( 'brand_whatsapp', '' ) )
    : '';
$booking_url = nas_get_page_url( 'nas_page_booking', '/book-newspaper-ad/' );
$contact_url = nas_get_page_url( 'nas_page_contact', '/contact-us/' );
$faq_url     = nas_get_page_url( 'nas_page_faq', '/faq/' );
$track_url   = nas_get_page_url( 'nas_page_track_order', '/track-order/' );
$papers_url  = home_url( '/newspapers/' );
$cities_url  = home_url( '/cities/' );
$login_url   = nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' );
$dash_url    = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );

$active = isset( $portal_active_nav ) ? (string) $portal_active_nav : '';
if ( ! $active && function_exists( 'nas_portal_current_slug' ) ) {
    $active = nas_portal_current_slug();
}

$nav_items = [
    'home'        => [ 'label' => 'Home',        'url' => home_url( '/' ) ],
    'newspapers'  => [ 'label' => 'Newspapers',  'url' => $papers_url ],
    'cities'      => [ 'label' => 'Cities',      'url' => $cities_url ],
    'faq'         => [ 'label' => 'FAQ',         'url' => $faq_url ],
    'contact-us'  => [ 'label' => 'Contact',     'url' => $contact_url ],
    'contact'     => [ 'label' => 'Contact',     'url' => $contact_url ],
    'about'       => [ 'label' => 'About',       'url' => home_url( '/about/' ) ],
    'pricing'     => [ 'label' => 'Pricing',     'url' => home_url( '/pricing/' ) ],
    'support'     => [ 'label' => 'Support',     'url' => home_url( '/support/' ) ],
    'blog'        => [ 'label' => 'Blog',        'url' => nas_get_page_url( 'nas_page_blog', '/blog/' ) ],
    'track-order' => [ 'label' => 'Track Order', 'url' => $track_url ],
];

$primary_nav = [ 'home', 'newspapers', 'cities', 'faq', 'contact-us' ];

$is_active = function ( string $key ) use ( $active, $nav_items ): bool {
    if ( $active === $key ) {
        return true;
    }
    if ( $key === 'home' && ( $active === '' || $active === 'index' ) ) {
        return true;
    }
    if ( $key === 'contact-us' && in_array( $active, [ 'contact', 'contact-us' ], true ) ) {
        return true;
    }
    return false;
};
?>
<a href="#nas-main-content" class="nas-skip-link">Skip to main content</a>
<div class="nhp-topbar">
  <div class="nhp-container nhp-topbar__inner">
    <div class="nhp-topbar__contacts">
      <?php if ( $phone ) : ?>
      <a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $phone ) ); ?>" class="nhp-topbar__item">
        <i class="fa-solid fa-phone"></i> <?php echo esc_html( $phone ); ?>
      </a>
      <?php endif; ?>
      <?php if ( $email ) : ?>
      <a href="mailto:<?php echo esc_attr( $email ); ?>" class="nhp-topbar__item">
        <i class="fa-solid fa-envelope"></i> <?php echo esc_html( $email ); ?>
      </a>
      <?php endif; ?>
    </div>
    <div class="nhp-topbar__actions">
      <a href="<?php echo esc_url( $track_url ); ?>" class="nhp-topbar__item"><i class="fa-solid fa-location-crosshairs"></i> Track Order</a>
      <?php if ( $walink ) : ?>
      <a href="<?php echo esc_url( $walink ); ?>" class="nhp-topbar__item nhp-topbar__wa" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<header class="nhp-header nhp-header--light" id="nhp-header">
  <div class="nhp-container nhp-header__inner">
    <button class="nhp-header__menu-btn" id="nhp-menu-btn" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="nhp-mobile-nav"><i class="fa-solid fa-bars" aria-hidden="true"></i></button>
    <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="nhp-header__brand">
      <?php if ( $logo ) : ?>
        <img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" class="nhp-header__logo-img">
      <?php else : ?>
        <span class="nhp-header__logo-text"><?php echo esc_html( $brand ); ?></span>
      <?php endif; ?>
    </a>
    <nav class="nhp-header__nav" aria-label="Main navigation">
      <?php foreach ( $primary_nav as $key ) :
          if ( ! isset( $nav_items[ $key ] ) ) continue;
          $item = $nav_items[ $key ];
      ?>
      <a class="nhp-header__link<?php echo $is_active( $key ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="nhp-header__actions">
      <?php if ( is_user_logged_in() ) : ?>
        <a href="<?php echo esc_url( $dash_url ); ?>" class="nhp-header__login">Dashboard</a>
      <?php else : ?>
        <a href="<?php echo esc_url( $login_url ); ?>" class="nhp-header__login">Login</a>
      <?php endif; ?>
      <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary">Check Rates</a>
    </div>
  </div>
</header>

<div class="nhp-mobile-nav" id="nhp-mobile-nav" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Mobile navigation">
  <div class="nhp-mobile-nav__panel">
    <button class="nhp-mobile-nav__close" id="nhp-mobile-close" type="button" aria-label="Close menu"><i class="fa-solid fa-xmark"></i></button>
    <?php
    foreach ( $primary_nav as $key ) :
        if ( ! isset( $nav_items[ $key ] ) ) continue;
        $item = $nav_items[ $key ];
    ?>
    <a href="<?php echo esc_url( $item['url'] ); ?>" class="nhp-mobile-nav__link"><?php echo esc_html( $item['label'] ); ?></a>
    <?php endforeach; ?>
    <a href="<?php echo esc_url( $track_url ); ?>" class="nhp-mobile-nav__link">Track Order</a>
    <a href="<?php echo esc_url( $booking_url ); ?>" class="nhp-btn nhp-btn--primary nhp-btn--block" style="margin-top:16px">Check Ad Rates</a>
  </div>
</div>
