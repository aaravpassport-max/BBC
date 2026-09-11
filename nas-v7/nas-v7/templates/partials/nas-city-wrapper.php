<?php
/**
 * Wrapper for city landing pages — portal shell + full HTML page.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

extract( $GLOBALS['nas_city_data'] ?? [] );

$_nas_cfg   = \NAS\Core\Config::instance();
$_nas_brand = $_nas_cfg->get( 'brand_name', get_bloginfo('name') );
remove_action( 'wp_head', '_admin_bar_bump_cb' );
add_filter( 'show_admin_bar', '__return_false', 999 );
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( ( $city['name'] ?? 'City' ) . ' Newspaper Ads — ' . $_nas_brand ); ?></title>
<?php wp_head(); ?>
<style>
#wpadminbar,html.admin-bar{display:none!important;margin-top:0!important}
body.nas-fullpage{margin:0!important;padding:0!important;background:#fff}
body.nas-public-portal{background:#fff}
.nas-mobile-nav-drawer{display:none}
.nas-mobile-nav-drawer.is-open{display:flex}
.nas-mobile-nav-overlay{display:none}
.nas-mobile-nav-overlay.is-open{display:block}
</style>
</head>
<body class="nas-fullpage nas-public-portal nas-page-city-landing">
<?php
nas_portal_shell_open( 'newspaper-ads' );
echo '<main id="nas-main-content" class="nas-main-content" tabindex="-1">';
include NAS_PATH . 'templates/city-pages/city-landing.php';
echo '</main>';
nas_portal_shell_close();
?>
<?php wp_footer(); ?>
</body>
</html>
