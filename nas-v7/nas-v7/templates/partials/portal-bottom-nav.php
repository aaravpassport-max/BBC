<?php
/**
 * Mobile bottom tab navigation — app-style, fixed on small screens.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'nas_portal_should_show_bottom_nav' ) || ! nas_portal_should_show_bottom_nav() ) {
    return;
}

require_once NAS_DIR . 'templates/partials/portal-nav-config.php';

$registry = nas_portal_nav_registry();
$keys     = nas_portal_bottom_nav_keys();
$active   = nas_portal_nav_active_slug();
$on_booking = in_array( $active, [ 'book-newspaper-ad', 'book-ad' ], true );
?>
<nav class="nas-app-bottom-nav" id="nas-app-bottom-nav" aria-label="App navigation" role="navigation">
  <div class="nas-app-bottom-nav__inner">
    <?php foreach ( $keys as $key ) :
        if ( ! isset( $registry[ $key ] ) ) {
            continue;
        }
        $item    = $registry[ $key ];
        $is_active = nas_portal_nav_match_slug( $key, $active );
        $no_spa  = $on_booking && $key !== 'book' ? ' data-no-spa="1"' : '';
        $classes = 'nas-app-bottom-nav__item' . ( $is_active ? ' is-active' : '' );
    ?>
    <a href="<?php echo esc_url( $item['url'] ); ?>"
       class="<?php echo esc_attr( $classes ); ?>"
       data-nas-nav="<?php echo esc_attr( $key ); ?>"
       aria-current="<?php echo $is_active ? 'page' : 'false'; ?>"
       <?php echo $no_spa; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
      <span class="nas-app-bottom-nav__icon" aria-hidden="true">
        <i class="fa-solid <?php echo esc_attr( $item['icon'] ); ?>"></i>
      </span>
      <span class="nas-app-bottom-nav__label"><?php echo esc_html( $item['label'] ); ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</nav>
<div class="nas-app-bottom-nav__spacer" aria-hidden="true"></div>
