<?php
/**
 * Template Name: NAS Portal Page
 * Template Post Type: page
 *
 * Complete standalone page. WordPress serves this file directly
 * via template_include filter — no theme header/footer/sidebar ever loads.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Kill admin bar BEFORE wp_head() fires
add_filter( 'show_admin_bar', '__return_false', 999 );
remove_action( 'wp_head',   '_admin_bar_bump_cb' );
remove_action( 'wp_footer', 'wp_admin_bar_render', 1000 );

// Resolve page content
global $post;
$nas_post    = get_queried_object() instanceof WP_Post ? get_queried_object() : $post;
$nas_content = $nas_post ? do_shortcode( $nas_post->post_content ) : '';

// Slug fallback — if post_content has no shortcode or page doesn't exist
if ( trim( $nas_content ) === '' ) {
    $slug = basename( trim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' ) );
    $sc_map = [
        'book-newspaper-ad'    => '[nas_booking]',
        'client-dashboard'     => '[nas_client_dashboard]',
        'admin-dashboard'      => '[nas_admin_dashboard]',
        'staff-dashboard'      => '[nas_staff_dashboard]',
        'moderation-dashboard' => '[nas_moderation_dashboard]',
        'booking-confirmation' => '[nas_confirmation]',
        'newspaper-ads'        => '[nas_city_index]',
        'track-order'          => '[nas_track_order]',
        'faq'                  => '[nas_faq]',
        'contact-us'           => '[nas_contact]',
        'blog'                 => '[nas_blog]',
        'payment'              => '[nas_payment]',
        'newspaper-ad-login'   => '[nas_login]',
        'vendor-dashboard'     => '[nas_vendor_dashboard]',
        // v3 Super Combo pages
        'pricing'              => '[nas_pricing]',
        'about'                => '[nas_about]',
        'support'              => '[nas_support]',
        'cities'               => '[nas_cities_index]',
        'newspapers'           => '[nas_newspapers_index]',
    ];
    if ( isset( $sc_map[$slug] ) ) {
        $nas_content = do_shortcode( $sc_map[$slug] );
    }
}

$cfg   = class_exists('\NAS\Core\Config') ? \NAS\Core\Config::instance() : null;
$brand = $cfg ? $cfg->get( 'brand_name', get_bloginfo('name') ) : get_bloginfo('name');
$title = $nas_post ? get_the_title( $nas_post->ID ) : $brand;
if ( ! $title ) $title = $brand;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $title . ' — ' . $brand ); ?></title>
<?php wp_head(); // outputs our enqueued CSS/JS ONLY — no theme block templates ?>
<style>
/* Hard-reset: admin bar + any theme chrome that might bleed through */
#wpadminbar,.wpadminbar{display:none!important;}
html{margin-top:0!important;padding-top:0!important;}
body{margin:0!important;padding:0!important;background:#f8fafc;}
/* Mobile nav drawer — hidden by default, opened by JS */
.nas-mobile-nav-drawer{display:none;}
.nas-mobile-nav-drawer.is-open{display:flex;}
.nas-mobile-nav-overlay{display:none;}
.nas-mobile-nav-overlay.is-open{display:block;}
</style>
</head>
<body class="nas-fullpage">
<?php echo $nas_content; ?>
<?php wp_footer(); ?>
</body>
</html>
