<?php
/**
 * Wrapper for city landing pages — outputs full HTML page without theme chrome.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Extract injected data
extract( $GLOBALS['nas_city_data'] ?? [] );

$_nas_cfg   = \NAS\Core\Config::instance();
$_nas_brand = $_nas_cfg->get( 'brand_name', get_bloginfo('name') );
$_nas_color = $_nas_cfg->get( 'brand_primary_color', '#1A3A5C' );
remove_action( 'wp_head', '_admin_bar_bump_cb' );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( ($city['name'] ?? 'City') . ' Newspaper Ads — ' . $_nas_brand ); ?></title>
<?php wp_head(); ?>
<style>
#wpadminbar,html.admin-bar { display:none!important; margin-top:0!important; }
body.nas-fullpage > header:not(.nas-top-nav),
body.nas-fullpage > #masthead,
body.nas-fullpage > .site-header,
body.nas-fullpage > footer,
body.nas-fullpage > .site-footer,
body.nas-fullpage > #colophon { display:none!important; }
body.nas-fullpage { margin:0!important; padding:0!important; background:#f8fafc; }
</style>
</head>
<body <?php body_class('nas-fullpage'); ?>>
<?php include NAS_PATH . 'templates/city-pages/city-landing.php'; ?>
<?php wp_footer(); ?>
</body>
</html>
