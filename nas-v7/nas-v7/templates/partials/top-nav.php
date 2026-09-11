<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Resolve portal URLs safely — works even if pages haven't been created yet
$_nas_client_url  = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
$_nas_booking_url = nas_get_page_url( 'nas_page_booking',          '/book-newspaper-ad/' );
$_nas_admin_url   = nas_get_page_url( 'nas_page_admin_dashboard',  '/admin-dashboard/' );
$_nas_logout_url  = wp_logout_url( home_url( '/' ) );
$_nas_login_url   = home_url('/newspaper-ad-login/');
$_nas_logo_url    = get_option( 'nas_logo_url', '' );
?>
<nav class="nas-top-nav" id="nas-top-nav">
  <div class="nas-top-nav__inner">

    <div class="nas-top-nav__left">
      <a class="nas-top-nav__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
        <?php if ( $_nas_logo_url ): ?>
          <img src="<?php echo esc_url( $_nas_logo_url ); ?>" alt="<?php bloginfo( 'name' ); ?>" height="36">
        <?php else: ?>
          <span class="nas-logo-text"><?php bloginfo( 'name' ); ?></span>
        <?php endif; ?>
      </a>

      <?php if ( ! empty( $nav_links ) ): ?>
      <ul class="nas-top-nav__links">
        <?php foreach ( $nav_links as $link ): ?>
        <li>
          <a href="<?php echo esc_url( $link['url'] ); ?>"
             class="<?php echo ! empty( $link['active'] ) ? 'active' : ''; ?>">
            <?php echo esc_html( $link['label'] ); ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <div class="nas-top-nav__right">

      <?php if ( is_user_logged_in() ):
        $current_user = wp_get_current_user();
      ?>
        <button class="nas-top-nav__icon-btn" id="nas-notif-btn" aria-label="Notifications">
          <i class="fa-regular fa-bell"></i>
          <span class="nas-notif-badge" id="nas-notif-badge" style="display:none;"></span>
        </button>

        <div class="nas-notif-panel" id="nas-notif-panel">
          <div class="nas-notif-panel__header">
            <span>Notifications</span>
            <button class="nas-notif-panel__close" id="nas-notif-close"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <div class="nas-notif-list" id="nas-notif-list">
            <div class="nas-empty-state-sm">Loading…</div>
          </div>
        </div>

        <div class="nas-top-nav__user-menu">
          <button class="nas-top-nav__avatar" id="nas-user-menu-btn">
            <?php echo get_avatar( $current_user->ID, 32 ); ?>
            <span><?php echo esc_html( $current_user->display_name ); ?></span>
            <i class="fa-solid fa-chevron-down"></i>
          </button>
          <ul class="nas-user-dropdown" id="nas-user-dropdown">
            <li><a href="<?php echo esc_url( $_nas_client_url ); ?>"><i class="fa-regular fa-user"></i> My Dashboard</a></li>
            <?php if ( current_user_can( 'nas_manage_bookings' ) || current_user_can( 'manage_options' ) ): ?>
            <li><a href="<?php echo esc_url( $_nas_admin_url ); ?>"><i class="fa-solid fa-gauge-high"></i> Admin Panel</a></li>
            <?php endif; ?>
            <li><a href="<?php echo esc_url( $_nas_booking_url ); ?>"><i class="fa-solid fa-pen-nib"></i> Book an Ad</a></li>
            <li class="nas-user-dropdown__divider"></li>
            <li><a href="<?php echo esc_url( $_nas_logout_url ); ?>"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a></li>
          </ul>
        </div>

      <?php else: ?>
        <a href="<?php echo esc_url( $_nas_login_url ); ?>" class="nas-btn nas-btn-ghost nas-btn-sm">Login</a>
        <a href="<?php echo esc_url( $_nas_booking_url ); ?>" class="nas-btn nas-btn-primary nas-btn-sm">Book an Ad</a>
      <?php endif; ?>

      <button class="nas-hamburger" id="nas-hamburger" aria-label="Menu">
        <span></span><span></span><span></span>
      </button>
    </div>

  </div>
</nav>

<div class="nas-mobile-nav-drawer" id="nas-mobile-nav" style="display:none">
  <div class="nas-mobile-nav-drawer__inner">
    <button class="nas-mobile-nav-drawer__close" id="nas-mobile-nav-close"><i class="fa-solid fa-xmark"></i></button>
    <?php if ( ! empty( $nav_links ) ): ?>
    <ul>
      <?php foreach ( $nav_links as $link ): ?>
      <li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <div style="margin-top:1.5rem;display:flex;flex-direction:column;gap:.75rem;">
      <?php if ( is_user_logged_in() ): ?>
        <a href="<?php echo esc_url( $_nas_client_url ); ?>" class="nas-btn nas-btn-ghost">My Bookings</a>
        <a href="<?php echo esc_url( $_nas_logout_url ); ?>" class="nas-btn nas-btn-ghost">Logout</a>
      <?php else: ?>
        <a href="<?php echo esc_url( $_nas_login_url ); ?>" class="nas-btn nas-btn-ghost">Login</a>
      <?php endif; ?>
      <a href="<?php echo esc_url( $_nas_booking_url ); ?>" class="nas-btn nas-btn-primary">Book an Ad</a>
    </div>
  </div>
</div>
<div class="nas-mobile-nav-overlay" id="nas-mobile-overlay" style="display:none"></div>
