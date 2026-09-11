<?php
/**
 * Plugin Name: NewspaperAds SaaS — Professional Booking Platform
 * Plugin URI:  https://your-domain.com/newspaper-ads-saas
 * Description: Enterprise-grade newspaper ad booking SaaS platform with custom dashboards, workflow tracking, AI content, real-time chat, WhatsApp integration, and 300 city landing pages.
 * Version:     3.6.0
 * Author:      Your Agency
 * Author URI:  https://your-domain.com
 * License:     GPL-2.0+
 * Text Domain: nas
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Global helper — used by templates that need Config without namespace syntax
if ( ! function_exists('NAS_get_config') ) {
    function NAS_get_config() {
        return class_exists('NAS\Core\Config') ? \NAS\Core\Config::instance() : null;
    }
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'NAS_VERSION',    '3.6.0' );
define( 'NAS_FILE',       __FILE__ );
define( 'NAS_DIR',        plugin_dir_path( __FILE__ ) );
define( 'NAS_PATH',       NAS_DIR );        // alias used throughout codebase
define( 'NAS_PLUGIN_DIR', NAS_DIR );        // alias used in templates
define( 'NAS_URL',        plugin_dir_url( __FILE__ ) );
define( 'NAS_ASSETS',     NAS_URL  . 'assets/' );
define( 'NAS_INCLUDES',   NAS_DIR  . 'core/' );
define( 'NAS_MODULES',    NAS_DIR  . 'modules/' );
define( 'NAS_DATA',       NAS_DIR  . 'assets/data/' );

// ── Auto-loader ───────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    $prefix = 'NAS\\';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;

    // e.g. NAS\Modules\Booking\BookingModule → after strip: Modules\Booking\BookingModule
    $relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );

    // Primary search (namespace root matches directory name literally)
    $search = [
        NAS_DIR . 'core/'       . $relative . '.php',
        NAS_DIR . 'modules/'    . $relative . '.php',
        NAS_DIR . 'database/'   . $relative . '.php',
        NAS_DIR . 'dashboards/' . $relative . '.php',
    ];

    // Secondary search: strip the leading namespace segment because it duplicates
    // the directory prefix (e.g. NAS\Modules\Booking → modules/Modules/Booking is wrong;
    // strip Modules/ → modules/Booking/BookingModule.php is correct).
    $parts = explode( DIRECTORY_SEPARATOR, $relative );
    if ( count( $parts ) > 1 ) {
        $sub = implode( DIRECTORY_SEPARATOR, array_slice( $parts, 1 ) );
        $search[] = NAS_DIR . 'core/'       . $sub . '.php';
        $search[] = NAS_DIR . 'modules/'    . $sub . '.php';
        $search[] = NAS_DIR . 'database/'   . $sub . '.php';
        $search[] = NAS_DIR . 'dashboards/' . $sub . '.php';
    }

    foreach ( $search as $file ) {
        if ( file_exists( $file ) ) { require_once $file; return; }
    }
} );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once NAS_DIR . 'core/Config.php';
require_once NAS_DIR . 'core/Database.php';
require_once NAS_DIR . 'core/Cache.php';
require_once NAS_DIR . 'core/EventBus.php';
require_once NAS_DIR . 'core/Queue.php';
require_once NAS_DIR . 'core/Router.php';
require_once NAS_DIR . 'core/ModuleManager.php';
require_once NAS_DIR . 'core/Security.php';
require_once NAS_DIR . 'core/Helpers.php';
require_once NAS_DIR . 'database/Schema.php';
require_once NAS_DIR . 'database/Seeder.php';
if ( file_exists( NAS_DIR . 'database/SchemaV3.php' ) ) require_once NAS_DIR . 'database/SchemaV3.php';
if ( file_exists( NAS_DIR . 'database/SchemaV4.php' ) ) require_once NAS_DIR . 'database/SchemaV4.php';

// ── Enterprise core utilities ──────────────────────────────────────────────
require_once NAS_DIR . 'core/AuditLogger.php';
require_once NAS_DIR . 'core/ErrorLogger.php';
require_once NAS_DIR . 'core/RateLimit.php';

// ── Enterprise feature module + SLA cron ──────────────────────────────────
require_once NAS_DIR . 'modules/Enterprise/EnterpriseModule.php';
require_once NAS_DIR . 'modules/Notifications/SLACron.php';
// ── v2.1 Additions — wrapped in file_exists for safety ────────────────────────
$_nas_v21_files = [
    NAS_DIR . 'database/SchemaV2.php',
    NAS_DIR . 'modules/Payment/PaymentModule.php',
    NAS_DIR . 'modules/Invoice/InvoiceModule.php',
    NAS_DIR . 'modules/Material/MaterialModule.php',
    NAS_DIR . 'modules/SupportTicket/SupportTicketModule.php',
    NAS_DIR . 'modules/EmailTemplate/EmailTemplateModule.php',
    // Public modules — now individual files (no multi-namespace issues)
    NAS_DIR . 'modules/TrackOrder/TrackOrderModule.php',
    NAS_DIR . 'modules/Blog/BlogModule.php',
    NAS_DIR . 'modules/FAQ/FAQModule.php',
    NAS_DIR . 'modules/Contact/ContactModule.php',
    NAS_DIR . 'modules/Wallet/WalletModule.php',
    NAS_DIR . 'modules/Branding/BrandingModule.php',
    NAS_DIR . 'modules/PWA/NASTheme.php',
    NAS_DIR . 'modules/PWA/PWAModule.php',
    // Legacy combined file still loaded as fallback (harmless if empty)
    NAS_DIR . 'modules/PublicPages/PublicPagesModule.php',
];
foreach ( $_nas_v21_files as $_f ) {
    if ( file_exists( $_f ) ) {
        require_once $_f;
    }
}
unset( $_nas_v21_files, $_f );
require_once NAS_DIR . 'dashboards/AdminDashboard.php';
require_once NAS_DIR . 'dashboards/ClientDashboard.php';
require_once NAS_DIR . 'dashboards/StaffDashboard.php';
require_once NAS_DIR . 'dashboards/ModerationDashboard.php';
require_once NAS_DIR . 'dashboards/VendorDashboard.php';
require_once NAS_DIR . 'modules/Admin/AdminModule.php';   // not in boot array — must be required explicitly
require_once NAS_DIR . 'core/Enqueue.php';
require_once NAS_DIR . 'core/AjaxAliases.php';

// ── Boot Modules ──────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    // Ensure all custom roles exist — add_role() is a no-op if already present, safe to call always
    nas_create_roles();

    $manager = \NAS\Core\ModuleManager::instance();
    $manager->boot( [
        \NAS\Modules\Booking\BookingModule::class,
        \NAS\Modules\Client\ClientModule::class,
        \NAS\Modules\Vendor\VendorModule::class,
        \NAS\Modules\Pricing\PricingModule::class,
        \NAS\Modules\Chat\ChatModule::class,
        \NAS\Modules\Notifications\NotificationsModule::class,
        \NAS\Modules\Analytics\AnalyticsModule::class,
        \NAS\Modules\AI\AIModule::class,
        \NAS\Modules\CityPages\CityPagesModule::class,
        // v2.1
        \NAS\Modules\Payment\PaymentModule::class,
        \NAS\Modules\Invoice\InvoiceModule::class,
        \NAS\Modules\Material\MaterialModule::class,
        \NAS\Modules\SupportTicket\SupportTicketModule::class,
        \NAS\Modules\EmailTemplate\EmailTemplateModule::class,
        \NAS\Modules\TrackOrder\TrackOrderModule::class,
        \NAS\Modules\Blog\BlogModule::class,
        \NAS\Modules\FAQ\FAQModule::class,
        \NAS\Modules\Contact\ContactModule::class,
        \NAS\Modules\Wallet\WalletModule::class,
        \NAS\Modules\Branding\BrandingModule::class,
        \NAS\Modules\PWA\PWAModule::class,
    ] );
}, 5 );

// ── Init Dashboards & Router ──────────────────────────────────────────────────
// Invalidate client dashboard cache when booking status changes
add_action( 'nas_booking_status_changed', function( $data ) {
    if ( ! empty($data['booking_id']) ) {
        \NAS\Dashboards\ClientDashboard::invalidate_cache( (int)$data['booking_id'] );
    }
}, 10 );

add_action( 'init', function () {
    \NAS\Core\Router::instance()->register_rewrite_rules();
    // Flush rewrite rules once when vendor-dashboard rule is new
    if ( get_option('nas_vendor_route_flushed') !== NAS_VERSION ) {
        flush_rewrite_rules( false );
        update_option( 'nas_vendor_route_flushed', NAS_VERSION, false );
    }
    \NAS\Core\AjaxAliases::register();
    \NAS\Dashboards\AdminDashboard::register();
    // Register standalone admin module (all admin AJAX handlers)
    if ( class_exists( '\\NAS\\Modules\\Admin\\AdminModule' ) ) {
        \NAS\Modules\Admin\AdminModule::register();
    }
    // Register Enterprise module (audit log, GDPR, job monitoring, vendor lifecycle, etc.)
    if ( class_exists( '\\NAS\\Modules\\Enterprise\\EnterpriseModule' ) ) {
        \NAS\Modules\Enterprise\EnterpriseModule::register();
    }
    // Register SLA cron event handlers
    if ( class_exists( '\\NAS\\Modules\\Notifications\\SLACron' ) ) {
        \NAS\Modules\Notifications\SLACron::register();
    }
    if ( class_exists( '\\NAS\\Modules\\Notifications\\NotificationsModule' ) ) {
        \NAS\Modules\Notifications\NotificationsModule::register();
    }
    \NAS\Dashboards\ClientDashboard::register();
    \NAS\Dashboards\StaffDashboard::register();
    \NAS\Dashboards\ModerationDashboard::register();
    \NAS\Dashboards\VendorDashboard::register();

    // ── CRITICAL: Register all shortcodes directly here as guaranteed fallback ──
    // This ensures shortcodes always render even if ModuleManager has any issue.
    $shortcodes = [
        'nas_booking'              => 'templates/booking/wizard.php',
        'nas_confirmation'         => 'templates/booking/confirmation.php',
        'nas_track_order'          => 'templates/public/track-order.php',
        'nas_faq'                  => 'templates/public/faq.php',
        'nas_contact'              => 'templates/public/contact.php',
        'nas_blog'                 => 'templates/public/blog-index.php',
        'nas_blog_post'            => 'templates/public/blog-post.php',
        'nas_payment'              => 'templates/payment/checkout.php',
        // nas_homepage registered separately below with embed-mode support
        'nas_city_index'           => 'templates/city-pages/city-landing.php',
        // v3 Super Combo pages — these were missing and left those pages blank
        'nas_pricing'              => 'templates/pages/pricing.php',
        'nas_about'                => 'templates/pages/about.php',
        'nas_support'              => 'templates/pages/support.php',
        'nas_cities_index'         => 'templates/pages/cities-index.php',
        'nas_newspapers_index'     => 'templates/pages/newspapers-index.php',
    ];
    foreach ( $shortcodes as $tag => $tpl ) {
        if ( ! shortcode_exists( $tag ) ) {
            add_shortcode( $tag, function( $atts = [] ) use ( $tpl ) {
                $file = NAS_DIR . $tpl;
                if ( ! file_exists( $file ) ) return '<!-- NAS: template not found: ' . esc_html( $tpl ) . ' -->';
                ob_start();
                try { include $file; } catch ( \Throwable $e ) { return '<!-- NAS error: ' . esc_html( $e->getMessage() ) . ' -->'; }
                return ob_get_clean();
            });
        }
    }

    // Login shortcode
    if ( ! shortcode_exists('nas_login') ) {
        add_shortcode( 'nas_login', function() {
            // Handle POST before any output - then include form content
            ob_start();
            include NAS_DIR . 'templates/public/login-form.php';
            return ob_get_clean();
        });
    }
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
// ── CDN Cache-Control for public pages ───────────────────────────────────────
// Reference plugin loads fast because CDN caches the HTML.
// For non-logged-in visitors, allow Cloudflare to cache static pages.
// send_headers fires after WordPress resolves the page, before output.
add_action( 'send_headers', function () {
    if ( is_admin() || wp_doing_ajax() || is_user_logged_in() ) return;
    if ( ! is_page() ) return;
    $slug = get_post_field( 'post_name', get_queried_object_id() );
    $public = [ 'book-newspaper-ad','newspaper-ads','faq','contact-us','blog','track-order','pricing','about','about-us' ];
    if ( ! in_array( $slug, $public, true ) ) return;
    if ( function_exists('header_remove') ) { header_remove('Pragma'); header_remove('Expires'); }
    header( 'Cache-Control: public, s-maxage=300, max-age=0, must-revalidate', true );
    header( 'CDN-Cache-Control: max-age=300', true );
    header( 'Vary: Cookie', true );
}, 99 );

register_activation_hook( __FILE__, function () {
    \NAS\Database\Schema::create_tables();
    \NAS\Database\SchemaV2::create_tables();
    \NAS\Database\SchemaV3::upgrade();
    \NAS\Database\Seeder::run();
    \NAS\Core\Router::instance()->register_rewrite_rules();
    flush_rewrite_rules( true );
    nas_create_pages();
    nas_create_roles();
    add_option( 'nas_version', NAS_VERSION );
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
    wp_clear_scheduled_hook( 'nas_queue_worker' );
    wp_clear_scheduled_hook( 'nas_daily_tasks' );
} );

// ── Create WordPress Pages ────────────────────────────────────────────────────
function nas_create_pages() {
    $pages = [
        [ 'slug' => 'book-newspaper-ad',    'title' => 'Book Newspaper Ad',   'content' => '[nas_booking]',          'opt' => 'nas_page_booking' ],
        [ 'slug' => 'client-dashboard',     'title' => 'My Dashboard',        'content' => '[nas_client_dashboard]', 'opt' => 'nas_page_client_dashboard' ],
        [ 'slug' => 'admin-dashboard',      'title' => 'Admin Dashboard',     'content' => '[nas_admin_dashboard]',  'opt' => 'nas_page_admin_dashboard' ],
        [ 'slug' => 'staff-dashboard',        'title' => 'Staff Dashboard',       'content' => '[nas_staff_dashboard]',       'opt' => 'nas_page_staff_dashboard' ],
        [ 'slug' => 'moderation-dashboard', 'title' => 'Moderation Dashboard',  'content' => '[nas_moderation_dashboard]',  'opt' => 'nas_page_moderation_dashboard' ],
        [ 'slug' => 'newspaper-ad-login',   'title' => 'Ad Portal Login',       'content' => '[nas_login]',                 'opt' => 'nas_page_login' ],
        [ 'slug' => 'booking-confirmation', 'title' => 'Booking Confirmed',   'content' => '[nas_confirmation]',     'opt' => 'nas_page_confirmation' ],
        [ 'slug' => 'newspaper-ads',        'title' => 'Newspaper Ads India',  'content' => '[nas_city_index]',       'opt' => 'nas_page_city_index' ],
        // v2.1 new pages
        [ 'slug' => 'track-order',          'title' => 'Track Your Order',      'content' => '[nas_track_order]',      'opt' => 'nas_page_track_order' ],
        [ 'slug' => 'faq',                  'title' => 'Frequently Asked Questions', 'content' => '[nas_faq]',          'opt' => 'nas_page_faq' ],
        [ 'slug' => 'contact-us',           'title' => 'Contact Us',            'content' => '[nas_contact]',          'opt' => 'nas_page_contact' ],
        [ 'slug' => 'blog',                 'title' => 'Blog & News',           'content' => '[nas_blog]',             'opt' => 'nas_page_blog' ],
        [ 'slug' => 'payment',              'title' => 'Complete Payment',      'content' => '[nas_payment]',          'opt' => 'nas_page_payment' ],
        [ 'slug' => 'about-us',             'title' => 'About Us',              'content' => '',                       'opt' => 'nas_page_about' ],
        [ 'slug' => 'privacy-policy',       'title' => 'Privacy Policy',        'content' => '',                       'opt' => 'nas_page_privacy' ],
        [ 'slug' => 'terms-conditions',     'title' => 'Terms & Conditions',    'content' => '',                       'opt' => 'nas_page_terms' ],
        [ 'slug' => 'refund-policy',        'title' => 'Refund Policy',         'content' => '',                       'opt' => 'nas_page_refund' ],
        [ 'slug' => 'careers',              'title' => 'Careers',               'content' => '',                       'opt' => 'nas_page_careers' ],
        [ 'slug' => 'advertise-with-us',    'title' => 'Advertise With Us',     'content' => '',                       'opt' => 'nas_page_advertise' ],
        // v3 Super Combo new pages
        [ 'slug' => 'pricing',              'title' => 'Newspaper Ad Pricing',  'content' => '[nas_pricing]',          'opt' => 'nas_page_pricing' ],
        [ 'slug' => 'about',                'title' => 'About Us',              'content' => '[nas_about]',            'opt' => 'nas_page_about_v3' ],
        [ 'slug' => 'support',              'title' => 'Help & Support',        'content' => '[nas_support]',          'opt' => 'nas_page_support' ],
        [ 'slug' => 'cities',               'title' => 'All Cities',            'content' => '[nas_cities_index]',     'opt' => 'nas_page_cities_index' ],
        [ 'slug' => 'newspapers',           'title' => 'All Newspapers',        'content' => '[nas_newspapers_index]', 'opt' => 'nas_page_newspapers_index' ],
        // Standalone Admin (no WP admin dependency)
        [ 'slug' => 'admin-dashboard',      'title' => 'Admin Dashboard',       'content' => '',                       'opt' => 'nas_page_admin_standalone' ],
        // Vendor Portal — was missing, causing /vendor-dashboard/ to 404
        [ 'slug' => 'vendor-dashboard',     'title' => 'Vendor Portal',         'content' => '[nas_vendor_dashboard]', 'opt' => 'nas_page_vendor_dashboard' ],
        // Homepage
        [ 'slug' => 'vendor-register',   'title' => 'Vendor Registration',  'content' => '[nas_vendor_register]',  'opt' => 'nas_page_vendor_register' ],
                [ 'slug' => 'nas-home',             'title' => 'Home',                  'content' => '[nas_homepage]',         'opt' => 'nas_page_home' ],
    ];

    foreach ( $pages as $pg ) {
        $eid = get_option( $pg['opt'] );
        if ( $eid && get_post( $eid ) ) {
            // Heal template assignment for existing pages
            \NAS\Core\Enqueue::assign_template_to_page( (int) $eid );
            continue;
        }
        $found = get_page_by_path( $pg['slug'] );
        if ( $found ) {
            update_option( $pg['opt'], $found->ID );
            \NAS\Core\Enqueue::assign_template_to_page( $found->ID );
            continue;
        }
        $id = wp_insert_post( [
            'post_title'   => $pg['title'],
            'post_name'    => $pg['slug'],
            'post_content' => $pg['content'],
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'meta_input'   => [ '_wp_page_template' => 'nas-portal' ],
        ] );
        if ( $id && ! is_wp_error( $id ) ) update_option( $pg['opt'], $id );
    }

    // Set the NAS home page as WordPress front page if not already configured
    if ( get_option('show_on_front') !== 'page' || ! get_option('page_on_front') ) {
        $home_id = (int) get_option('nas_page_home');
        if ( ! $home_id ) {
            $home_page = get_page_by_path('nas-home');
            if ( $home_page ) $home_id = $home_page->ID;
        }
        if ( $home_id ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front',  $home_id );
            update_option( 'nas_homepage_enabled', 1 );
        }
    }
}

// ── Create Custom Roles ───────────────────────────────────────────────────────
function nas_create_roles() {
    add_role( 'nas_vendor', 'NAS Vendor', [
        'read'                   => true,
        'nas_vendor_portal'      => true,
        'nas_send_messages'      => true,
        'nas_view_own_bookings'  => true,
    ] );
    add_role( 'nas_client', 'NAS Client', [
        'read'                  => true,
        'nas_view_own_bookings' => true,
        'nas_send_messages'     => true,
    ] );
    add_role( 'nas_staff', 'NAS Staff', [
        'read'                   => true,
        'nas_view_all_bookings'  => true,
        'nas_manage_bookings'    => true,
        'nas_send_messages'      => true,
        'nas_manage_clients'     => true,
    ] );
    add_role( 'nas_manager', 'NAS Manager', [
        'read'                   => true,
        'nas_view_all_bookings'  => true,
        'nas_manage_bookings'    => true,
        'nas_send_messages'      => true,
        'nas_manage_clients'     => true,
        'nas_manage_vendors'     => true,
        'nas_view_analytics'     => true,
        'nas_manage_settings'    => true,
    ] );

    // Add capabilities to admin
    $admin = get_role( 'administrator' );
    if ( $admin ) {
        foreach ( [ 'nas_view_own_bookings','nas_view_all_bookings','nas_manage_bookings','nas_send_messages','nas_manage_clients','nas_manage_vendors','nas_view_analytics','nas_manage_settings','nas_manage_system' ] as $cap ) {
            $admin->add_cap( $cap );
        }
    }
}




// ── NAS Login POST Handler — fires at init before any output ────────────────
add_action( 'init', function () {
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return;

    $login_page = nas_get_page_url( 'nas_page_login', '/newspaper-ad-login/' );

    /* ── Login ────────────────────────────────────────────────────── */
    if ( isset( $_POST['nas_login_submit'] ) ) {
        if ( ! wp_verify_nonce( $_POST['nas_login_nonce'] ?? '', 'nas_login_action' ) ) {
            wp_safe_redirect( add_query_arg( 'nas_err', 'nonce', $login_page ) ); exit;
        }
        $raw      = $_POST['log'] ?? '';
        $password = $_POST['pwd'] ?? '';
        $remember = ! empty( $_POST['rememberme'] );

        $user_login = sanitize_text_field( $raw );
        if ( is_email( $raw ) ) {
            $found = get_user_by( 'email', sanitize_email( $raw ) );
            if ( $found ) $user_login = $found->user_login;
        }

        $result = wp_signon( [
            'user_login'    => $user_login,
            'user_password' => $password,
            'remember'      => $remember,
        ], false );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'nas_err', 'credentials', $login_page ) ); exit;
        }

        // Logged in — redirect by role
        $user  = $result;
        $roles = (array) $user->roles;
        $dest  = sanitize_url( $_GET['redirect_to'] ?? '' );
        if ( ! $dest || strpos( $dest, home_url() ) !== 0 ) {
            if ( user_can( $user, 'manage_options' ) )   $dest = home_url( '/admin-dashboard/' );
            elseif ( in_array( 'nas_manager', $roles ) ) $dest = home_url( '/moderation-dashboard/' );
            elseif ( in_array( 'nas_staff',   $roles ) ) $dest = home_url( '/staff-dashboard/' );
            elseif ( in_array( 'nas_vendor',  $roles ) ) $dest = home_url( '/vendor-dashboard/' );
            else $dest = nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' );
        }
        wp_safe_redirect( $dest ); exit;
    }

    /* ── Forgot password ──────────────────────────────────────────── */
    if ( isset( $_POST['nas_forgot_submit'] ) ) {
        if ( ! wp_verify_nonce( $_POST['nas_forgot_nonce'] ?? '', 'nas_forgot_action' ) ) {
            wp_safe_redirect( add_query_arg( [ 'step' => 'forgot', 'nas_err' => 'nonce' ], $login_page ) ); exit;
        }
        $input    = sanitize_text_field( $_POST['forgot_email'] ?? '' );
        $user_obj = is_email( $input ) ? get_user_by( 'email', $input ) : get_user_by( 'login', $input );
        if ( $user_obj ) {
            $key = get_password_reset_key( $user_obj );
            if ( ! is_wp_error( $key ) ) {
                $reset_link = add_query_arg(
                    [ 'step' => 'reset', 'key' => $key, 'login' => rawurlencode( $user_obj->user_login ) ],
                    $login_page
                );
                $brand = nas_config( 'brand_name', get_bloginfo( 'name' ) );
                wp_mail(
                    $user_obj->user_email,
                    "Reset Your Password — {$brand}",
                    "Hi {$user_obj->display_name},\n\nReset your password here (expires in 24 hours):\n{$reset_link}\n\nIf you didn't request this, ignore this email.\n\n— {$brand}"
                );
            }
        }
        wp_safe_redirect( add_query_arg( [ 'step' => 'forgot', 'nas_msg' => 'reset_sent' ], $login_page ) ); exit;
    }

    /* ── Reset password ───────────────────────────────────────────── */
    if ( isset( $_POST['nas_reset_submit'] ) ) {
        if ( ! wp_verify_nonce( $_POST['nas_reset_nonce'] ?? '', 'nas_reset_action' ) ) {
            wp_safe_redirect( add_query_arg( 'nas_err', 'nonce', $login_page ) ); exit;
        }
        $rp_key   = sanitize_text_field( $_POST['rp_key']   ?? '' );
        $rp_login = sanitize_user(       $_POST['rp_login']  ?? '' );
        $pass1    = $_POST['pass1'] ?? '';
        $pass2    = $_POST['pass2'] ?? '';
        $rp_user  = check_password_reset_key( $rp_key, $rp_login );
        if ( is_wp_error( $rp_user ) ) {
            wp_safe_redirect( add_query_arg( [ 'step' => 'forgot', 'nas_err' => 'expired' ], $login_page ) ); exit;
        }
        if ( strlen( $pass1 ) < 8 ) {
            wp_safe_redirect( add_query_arg( [ 'step' => 'reset', 'key' => $rp_key, 'login' => rawurlencode($rp_login), 'nas_err' => 'short' ], $login_page ) ); exit;
        }
        if ( $pass1 !== $pass2 ) {
            wp_safe_redirect( add_query_arg( [ 'step' => 'reset', 'key' => $rp_key, 'login' => rawurlencode($rp_login), 'nas_err' => 'mismatch' ], $login_page ) ); exit;
        }
        reset_password( $rp_user, $pass1 );
        wp_safe_redirect( add_query_arg( 'nas_msg', 'pwd_changed', $login_page ) ); exit;
    }
}, 1 ); // Priority 1 = very early, before anything outputs

// ── Schedule Crons ────────────────────────────────────────────────────────────
add_action( 'wp', function () {
    if ( ! wp_next_scheduled( 'nas_queue_worker' ) ) wp_schedule_event( time(), 'nas_every_minute', 'nas_queue_worker' );
    if ( ! wp_next_scheduled( 'nas_daily_tasks' ) )  wp_schedule_event( time(), 'daily', 'nas_daily_tasks' );
} );

add_filter( 'cron_schedules', function ( $s ) {
    $s['nas_every_minute'] = [ 'interval' => 60, 'display' => 'Every Minute' ];
    return $s;
} );

add_action( 'nas_queue_worker', [ \NAS\Core\Queue::class, 'process' ] );
add_action( 'nas_daily_tasks',  function () {
    \NAS\Core\EventBus::emit( 'daily_tasks', [] );
} );

// ── Plugin upgrade handler ────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    $installed = get_option( 'nas_version', '0' );
    if ( version_compare( $installed, NAS_VERSION, '<' ) ) {
        \NAS\Database\Schema::create_tables();
        update_option( 'nas_version', NAS_VERSION );
        // Flush rewrite rules on upgrade so Router rules take effect immediately
        add_action( 'init', function() { flush_rewrite_rules( false ); }, 999 );
    }
} );

// ── WordPress Admin Menu ──────────────────────────────────────────────────────
add_action( 'admin_menu', function () {

    // Top-level menu — visible to admins & managers
    add_menu_page(
        'NewspaperAds SaaS',
        'NewspaperAds',
        'read',
        'nas-portal',
        'nas_admin_menu_redirect',
        'dashicons-media-document',
        25
    );

    // ── Admin / Manager submenus ──────────────────────────────────────────
    add_submenu_page(
        'nas-portal',
        'Admin Dashboard',
        '📊 Admin Dashboard',
        'nas_manage_bookings',
        'nas-admin-dashboard',
        'nas_open_admin_dashboard'
    );

    add_submenu_page(
        'nas-portal',
        'Staff Dashboard',
        '👥 Staff Dashboard',
        'nas_manage_bookings',
        'nas-staff-dashboard',
        'nas_open_staff_dashboard'
    );

    add_submenu_page(
        'nas-portal',
        'Moderation',
        '🔍 Moderation',
        'nas_manage_bookings',
        'nas-moderation-dashboard',
        'nas_open_moderation_dashboard'
    );

    add_submenu_page(
        'nas-portal',
        'My Bookings (Client)',
        '📋 My Bookings',
        'read',
        'nas-client-dashboard',
        'nas_open_client_dashboard'
    );

    add_submenu_page(
        'nas-portal',
        'Book an Ad',
        '✏️ Book an Ad',
        'read',
        'nas-book-ad',
        'nas_open_booking'
    );

    add_submenu_page(
        'nas-portal',
        'Plugin Settings',
        '⚙️ Settings',
        'manage_options',
        'nas-settings',
        'nas_settings_page'
    );
} );

// Redirect submenu callbacks to the actual front-end portal pages
function nas_admin_menu_redirect() {
    wp_redirect( nas_get_page_url( 'nas_page_admin_dashboard' ) );
    exit;
}
function nas_open_admin_dashboard() {
    wp_redirect( nas_get_page_url( 'nas_page_admin_dashboard' ) );
    exit;
}
function nas_open_staff_dashboard() {
    wp_redirect( nas_get_page_url( 'nas_page_staff_dashboard' ) );
    exit;
}
function nas_open_moderation_dashboard() {
    wp_redirect( nas_get_page_url( 'nas_page_moderation_dashboard', '/nas-moderation/' ) );
    exit;
}
function nas_open_client_dashboard() {
    wp_redirect( nas_get_page_url( 'nas_page_client_dashboard' ) );
    exit;
}
function nas_open_booking() {
    wp_redirect( nas_get_page_url( 'nas_page_booking' ) );
    exit;
}

// Inline settings page (quick-access inside WP admin without leaving)
function nas_settings_page() {
    $admin_url = nas_get_page_url( 'nas_page_admin_dashboard' );
    ?>
    <div class="wrap">
      <h1>NewspaperAds SaaS — Portal Links</h1>
      <p>All portal dashboards live on the front end. Use the links below to access them:</p>
      <table class="widefat" style="max-width:640px;">
        <thead><tr><th>Portal</th><th>URL</th><th></th></tr></thead>
        <tbody>
          <?php
          $portals = [
            [ 'Admin Dashboard',    'nas_page_admin_dashboard',      'manage_options' ],
            [ 'Staff Dashboard',    'nas_page_staff_dashboard',       'nas_manage_bookings' ],
            [ 'Moderation Panel',   'nas_page_moderation_dashboard',  'nas_manage_bookings' ],
            [ 'Client Dashboard',   'nas_page_client_dashboard',      'read' ],
            [ 'Book an Ad',         'nas_page_booking',               'read' ],
            [ 'Ad Booking Confirmation', 'nas_page_confirmation',     'read' ],
            [ 'City Index',         'nas_page_city_index',            'read' ],
          ];
          foreach ( $portals as [ $label, $opt, $cap ] ) :
              if ( ! current_user_can( $cap ) ) continue;
              $url = nas_get_page_url( $opt );
          ?>
          <tr>
            <td><strong><?php echo esc_html( $label ); ?></strong></td>
            <td><code><?php echo esc_html( $url ); ?></code></td>
            <td><a href="<?php echo esc_url( $url ); ?>" class="button button-primary" target="_blank">Open ↗</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <hr>
      <h2>Re-create Plugin Pages</h2>
      <p>If any portal page is missing, click below to recreate all pages.</p>
      <?php
      if ( isset( $_POST['nas_recreate_pages'] ) && check_admin_referer( 'nas_recreate_pages' ) ) {
          nas_create_pages();
          echo '<div class="notice notice-success"><p>Pages recreated successfully!</p></div>';
      }
      ?>
      <form method="post">
        <?php wp_nonce_field( 'nas_recreate_pages' ); ?>
        <input type="hidden" name="nas_recreate_pages" value="1">
        <button type="submit" class="button button-secondary">🔄 Recreate All Portal Pages</button>
      </form>

      <hr>
      <h2>Plugin Info</h2>
      <table class="widefat" style="max-width:640px;">
        <tbody>
          <tr><td>Version</td><td><strong><?php echo NAS_VERSION; ?></strong></td></tr>
          <tr><td>DB Tables</td><td><strong><?php echo nas_count_tables(); ?> tables installed</strong></td></tr>
          <tr><td>Plugin Directory</td><td><code><?php echo NAS_DIR; ?></code></td></tr>
        </tbody>
      </table>
    </div>
    <?php
}

function nas_count_tables(): int {
    global $wpdb;
    $count = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '{$wpdb->prefix}nas_%'" );
    return (int) $count;
}

// Helper: get a portal page URL by option key, with fallback slug
function nas_get_page_url( string $option_key, string $fallback_slug = '' ): string {
    $page_id = get_option( $option_key );
    if ( $page_id && get_post( $page_id ) ) {
        return get_permalink( $page_id );
    }
    // Derive slug from option key: nas_page_admin_dashboard → /nas-admin-dashboard/
    if ( ! $fallback_slug ) {
        $fallback_slug = '/' . str_replace( [ 'nas_page_', '_' ], [ '', '-' ], $option_key ) . '/';
    }
    return home_url( $fallback_slug );
}

// ── WP Toolbar (top bar) shortcut ────────────────────────────────────────────
add_action( 'admin_bar_menu', function ( \WP_Admin_Bar $bar ) {
    if ( ! is_user_logged_in() ) return;

    $bar->add_node( [
        'id'    => 'nas-portal',
        'title' => '📰 Ad Portal',
        'href'  => nas_get_page_url( 'nas_page_client_dashboard' ),
        'meta'  => [ 'title' => 'NewspaperAds Portal' ],
    ] );

    if ( current_user_can( 'read' ) ) {
        $bar->add_node( [
            'parent' => 'nas-portal',
            'id'     => 'nas-bar-book',
            'title'  => '✏️ Book an Ad',
            'href'   => nas_get_page_url( 'nas_page_booking' ),
        ] );
        $bar->add_node( [
            'parent' => 'nas-portal',
            'id'     => 'nas-bar-client',
            'title'  => '📋 My Bookings',
            'href'   => nas_get_page_url( 'nas_page_client_dashboard' ),
        ] );
    }

    if ( current_user_can( 'nas_manage_bookings' ) ) {
        $bar->add_node( [
            'parent' => 'nas-portal',
            'id'     => 'nas-bar-admin',
            'title'  => '📊 Admin Dashboard',
            'href'   => nas_get_page_url( 'nas_page_admin_dashboard' ),
        ] );
        $bar->add_node( [
            'parent' => 'nas-portal',
            'id'     => 'nas-bar-staff',
            'title'  => '👥 Staff Dashboard',
            'href'   => nas_get_page_url( 'nas_page_staff_dashboard' ),
        ] );
        $bar->add_node( [
            'parent' => 'nas-portal',
            'id'     => 'nas-bar-mod',
            'title'  => '🔍 Moderation',
            'href'   => nas_get_page_url( 'nas_page_moderation_dashboard', '/nas-moderation/' ),
        ] );
    }
}, 100 );

// ── Plugin action links (Plugins list page) ────────────────────────────────────
add_filter( 'plugin_action_links_' . plugin_basename( NAS_FILE ), function ( $links ) {
    $portal_links = [
        '<a href="' . esc_url( admin_url( 'admin.php?page=nas-settings' ) ) . '">Settings</a>',
        '<a href="' . esc_url( nas_get_page_url( 'nas_page_admin_dashboard' ) ) . '" target="_blank">Admin Portal ↗</a>',
        '<a href="' . esc_url( nas_get_page_url( 'nas_page_booking' ) ) . '" target="_blank">Book an Ad ↗</a>',
    ];
    return array_merge( $portal_links, $links );
} );

// ── Homepage shortcode (embed mode — no nested HTML document) ───────────────
add_shortcode( 'nas_homepage', function () {
    if ( ! defined( 'NAS_HOME_EMBED' ) ) {
        define( 'NAS_HOME_EMBED', true );
    }
    ob_start();
    include NAS_DIR . 'templates/public/home.php';
    return ob_get_clean();
} );

// ── Standalone template_include filter — catches pages the Enqueue filter may miss
// Priority 999 runs AFTER all theme/plugin template filters so we always win
add_filter( 'template_include', function( $template ) {
    if ( is_admin() || wp_doing_ajax() ) return $template;
    $nas_tpl = NAS_DIR . 'templates/partials/nas-page-template.php';
    if ( ! file_exists( $nas_tpl ) ) return $template;

    // is_nas_page() covers slugs, stored IDs, and _wp_page_template meta
    if ( \NAS\Core\Enqueue::is_nas_page() ) return $nas_tpl;

    // Extra: catch front page if it's set to a NAS page in WP Settings → Reading
    if ( is_front_page() && is_page() ) {
        $id = (int) get_queried_object_id();
        $meta = get_post_meta( $id, '_wp_page_template', true );
        if ( $meta === 'nas-portal' ) return $nas_tpl;
        // Also check stored IDs
        $nas_ids = array_filter( array_map( 'intval', [
            get_option('nas_page_booking'), get_option('nas_page_client_dashboard'),
            get_option('nas_page_homepage'),
        ] ) );
        if ( in_array( $id, $nas_ids, true ) ) return $nas_tpl;
    }

    return $template;
}, 999 );

// ── Homepage: always serve standalone home.php on NAS front page (priority 1000) ─
add_filter( 'template_include', function ( $template ) {
    if ( is_admin() || wp_doing_ajax() || ! is_front_page() ) {
        return $template;
    }

    $hp = NAS_DIR . 'templates/public/home.php';
    if ( ! file_exists( $hp ) ) {
        return $template;
    }

    $page_id = (int) get_queried_object_id();
    $content = $page_id ? (string) get_post_field( 'post_content', $page_id ) : '';

    $use_nas_home = get_option( 'nas_homepage_enabled' )
        || has_shortcode( $content, 'nas_homepage' )
        || is_page( [ 'nas-homepage', 'nas-home', 'homepage', 'home' ] );

    return $use_nas_home ? $hp : $template;
}, 1000 );

// ── Blog single post page ────────────────────────────────────────────────────
add_filter( 'template_include', function( $template ) {
    $slug = get_query_var('nas_blog_slug');
    if ( $slug && get_option('nas_page_blog') ) {
        $tpl = NAS_DIR . 'templates/public/blog-post.php';
        if ( file_exists($tpl) ) return $tpl;
    }
    return $template;
} );

// ── Admin settings page: option to set homepage ─────────────────────────────
add_action('admin_menu', function() {
    add_options_page('NAS Settings','NAS Portal','manage_options','nas-settings',function(){
        if(isset($_POST['nas_homepage_enabled'])){
            update_option('nas_homepage_enabled',(int)$_POST['nas_homepage_enabled']);
            update_option('show_on_front','page');
            $hp_id = get_option('nas_page_booking') ? (int)get_option('nas_page_booking') : 0;
            // Create homepage page if needed
            $hp = get_page_by_path('nas-homepage');
            if(!$hp){
                $id = wp_insert_post(['post_title'=>'Homepage','post_name'=>'nas-homepage','post_content'=>'[nas_homepage]','post_status'=>'publish','post_type'=>'page']);
                update_option('page_on_front',$id);
            } else {
                update_option('page_on_front',$hp->ID);
            }
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
        $enabled = get_option('nas_homepage_enabled',0);
        echo '<div class="wrap"><h1>NAS Portal Settings</h1><form method="post">';
        wp_nonce_field('nas_settings');
        echo '<table class="form-table"><tr><th>Enable NAS Homepage</th><td>';
        echo '<label><input type="checkbox" name="nas_homepage_enabled" value="1"'.($enabled?' checked':'').'> Use NAS homepage as front page</label>';
        echo '</td></tr></table>';
        echo '<p class="submit"><input type="submit" class="button-primary" value="Save"></p></form></div>';
    });
});

// ── Auto re-seed if tables are empty (handles reinstall without deactivation) ──
add_action( 'admin_init', function() {
    global $wpdb;
    $p = $wpdb->prefix . 'nas_';

    // ── Heal page template assignments ─────────────────────────────────────
    // Ensures all NAS pages have _wp_page_template = 'nas-portal' set.
    // This runs once and caches the result so it's nearly free after first run.
    if ( ! get_option('nas_templates_healed_v21') ) {
        $page_options = [
            'nas_page_booking', 'nas_page_client_dashboard', 'nas_page_admin_dashboard',
            'nas_page_staff_dashboard', 'nas_page_moderation_dashboard',
            'nas_page_confirmation', 'nas_page_city_index', 'nas_page_track_order',
            'nas_page_faq', 'nas_page_contact', 'nas_page_blog',
            'nas_page_payment', 'nas_page_login',
        ];
        foreach ( $page_options as $opt ) {
            $id = (int) get_option( $opt );
            if ( $id && get_post( $id ) ) {
                update_post_meta( $id, '_wp_page_template', 'nas-portal' );
            }
        }
        // Also find pages by slug that might not have option set
        $slugs = [
            'book-newspaper-ad','client-dashboard','admin-dashboard','staff-dashboard',
            'moderation-dashboard','booking-confirmation','newspaper-ads',
            'track-order','faq','contact-us','blog','payment','newspaper-ad-login',
        ];
        foreach ( $slugs as $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page ) {
                update_post_meta( $page->ID, '_wp_page_template', 'nas-portal' );
            }
        }
        update_option( 'nas_templates_healed_v21', '1' );
    }

    // ── Re-seed if categories empty ────────────────────────────────────────
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}categories'" );
    if ( $table_exists && ! $wpdb->get_var( "SELECT COUNT(*) FROM {$p}categories" ) ) {
        \NAS\Database\Seeder::run();
    }

    // ── Run SchemaV2 if new tables are missing ─────────────────────────────
    if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}email_templates'" ) ) {
        if ( class_exists( '\NAS\Database\SchemaV2' ) ) {
            \NAS\Database\SchemaV2::create_tables();
        }
    }
    // ── Run SchemaV3 (Super Combo upgrade) — always run, ALTER TABLE checks are idempotent ──
    if ( class_exists( '\NAS\Database\SchemaV3' ) ) {
        \NAS\Database\SchemaV3::upgrade();
    }
    // ── Run SchemaV4 (Enterprise additions: audit_log, error_log, SLA columns) — idempotent ──
    if ( class_exists( '\NAS\Database\SchemaV4' ) ) {
        \NAS\Database\SchemaV4::upgrade();
        \NAS\Database\SchemaV4::register_cron();
    }
}, 20 );
