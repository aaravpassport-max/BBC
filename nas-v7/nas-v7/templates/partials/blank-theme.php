<?php
/**
 * NAS Blank Theme Template — zero theme chrome.
 * Completely bypasses all theme header/footer/sidebar/admin bar.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Remove admin bar from DOM entirely
remove_action( 'wp_head',   '_admin_bar_bump_cb' );
remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );
add_filter( 'show_admin_bar', '__return_false', 999 );

$_nas_cfg   = \NAS\Core\Config::instance();
$_nas_brand = $_nas_cfg->get( 'brand_name', get_bloginfo('name') );
$_nas_color = $_nas_cfg->get( 'brand_primary_color', '#1A3A5C' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( get_the_title() ?: $_nas_brand ); ?> — <?php echo esc_html( $_nas_brand ); ?></title>
<?php wp_head(); ?>
<style id="nas-fullpage-reset">
  /* Hard-kill every known theme header/footer/sidebar pattern */
  #wpadminbar,
  .admin-bar-offset { display:none!important; height:0!important; }
  html.admin-bar    { margin-top:0!important; padding-top:0!important; }

  body.nas-fullpage > header,
  body.nas-fullpage > .site-header,
  body.nas-fullpage > #masthead,
  body.nas-fullpage > #site-header,
  body.nas-fullpage > .header-wrap,
  body.nas-fullpage > .ast-above-header-wrap,
  body.nas-fullpage > .ast-below-header,
  body.nas-fullpage > #ast-fixed-footer,
  body.nas-fullpage > .elementor-location-header,
  body.nas-fullpage > .elementor-location-footer,
  body.nas-fullpage > footer,
  body.nas-fullpage > .site-footer,
  body.nas-fullpage > #colophon,
  body.nas-fullpage > #footer,
  body.nas-fullpage > aside,
  body.nas-fullpage > .sidebar,
  body.nas-fullpage > #secondary,
  body.nas-fullpage .wp-site-blocks > header,
  body.nas-fullpage .wp-site-blocks > footer,
  body.nas-fullpage .wp-block-template-part[class*="header"],
  body.nas-fullpage .wp-block-template-part[class*="footer"],
  body.nas-fullpage nav.navigation-bar:not(.nas-top-nav):not(.nas-topnav) {
    display:none!important;
    visibility:hidden!important;
    height:0!important;
    overflow:hidden!important;
    position:absolute!important;
    pointer-events:none!important;
  }

  /* Remove theme body margin/padding */
  body.nas-fullpage,
  html.admin-bar body.nas-fullpage {
    margin: 0!important;
    padding: 0!important;
  }

  /* Ensure page fills viewport properly */
  body.nas-fullpage {
    background: #f8fafc;
    min-height: 100vh;
  }
</style>
</head>
<body <?php body_class( 'nas-fullpage' ); ?>>
<?php
// Render page content (runs shortcodes which render dashboards/wizard/etc.)
if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        the_content();
    }
}
wp_footer();
?>
</body>
</html>
