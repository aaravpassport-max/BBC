<?php
/**
 * Plugin Name:       Services2NRI
 * Plugin URI:        https://services2nri.org.in
 * Description:       Complete NRI Service Marketplace — bookings, quotes, payments, CRM, documents.
 * Version:           4.7.71
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Services2NRI
 * License:           Proprietary
 * Text Domain:       services2nri
 */

defined( 'ABSPATH' ) || exit;

// ─── PHP version gate ────────────────────────────────────────────────────────
// This plugin uses PHP 8.0 features (named arguments, match expressions, nullsafe
// operators). On PHP < 8.0 it fails with a fatal error and the admin sees a blank
// white screen with no indication of what went wrong.
if ( version_compare( PHP_VERSION, '8.0.0', '<' ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Services2NRI requires PHP 8.0 or higher.</strong> '
            . 'Your server is running PHP ' . esc_html( PHP_VERSION ) . '. '
            . 'Please upgrade PHP or contact your hosting provider.</p></div>';
    } );
    return; // Stop loading the plugin entirely — prevents fatal errors
}

// ─── Constants ────────────────────────────────────────────────────────────────
define( 'S2NRI_CUSTOMER_ROLE', 's2nri_customer' );
define( 'S2NRI_VERSION',    '4.7.71' );
define( 'S2NRI_FILE',       __FILE__ );
define( 'S2NRI_DIR',        plugin_dir_path( __FILE__ ) );
define( 'S2NRI_URL',        plugin_dir_url( __FILE__ ) );
define( 'S2NRI_ASSETS_URL', S2NRI_URL . 'assets/' );
define( 'S2NRI_DB_VERSION', '1.0.0' );

// ─── Request classification ───────────────────────────────────────────────────
$request_uri  = $_SERVER['REQUEST_URI'] ?? '';
$request_path = strtok( $request_uri, '?' );

// s2nri_icon and s2nri_img must be excluded from SPA so the endpoints fire
$has_s2nri_param = isset( $_GET['s2nri_icon'] )
    || isset( $_GET['s2nri_img'] )
    || isset( $_GET['s2nri_import_map'] )
    || isset( $_GET['s2nri_boot_diag'] );

$is_wp_path = (
    strpos( $request_path, '/wp-admin' )    === 0 ||
    strpos( $request_path, '/wp-json' )     === 0 ||
    strpos( $request_path, '/wp-login' )    === 0 ||
    strpos( $request_path, '/wp-content' )  === 0 ||
    strpos( $request_path, '/wp-includes' ) === 0 ||
    strpos( $request_path, '/wp-cron' )     === 0 ||
    $request_path === '/sitemap.xml'                ||
    $request_path === '/robots.txt'                 ||
    $request_path === '/manifest.json'              ||
    $request_path === '/sw.js'                      ||
    $has_s2nri_param
);

// /portal/* and /s2nri-admin/* are handled exclusively by Portal::boot().
// They must NOT be caught by S2NRI_IS_SPA (public frontend) — that would
// render the wrong SPA and exit before Portal's init hook fires.
$_s2nri_is_portal_path = (
    $request_path === '/portal' ||
    strpos( $request_path, '/portal/' ) === 0 ||
    $request_path === '/s2nri-admin' ||
    strpos( $request_path, '/s2nri-admin/' ) === 0
);

// Handle subdirectory installs: strip the WordPress base path before checking
// e.g. if WP is at /blog/, request /blog/api/v1/services → strip /blog/ → /api/v1/services
$_s2nri_home_path = rtrim( parse_url( defined('WP_HOME') ? WP_HOME : '', PHP_URL_PATH ) ?? '', '/' );
$_s2nri_rel_path  = $request_path;
if ( $_s2nri_home_path && strpos( $request_path, $_s2nri_home_path ) === 0 ) {
    $_s2nri_rel_path = substr( $request_path, strlen( $_s2nri_home_path ) );
}
// Also accept the path without leading slash (for reverse proxies that strip it)
$_s2nri_rel_clean = '/' . ltrim( $_s2nri_rel_path, '/' );

define( 'S2NRI_IS_API',    strpos( $_s2nri_rel_clean, '/api/v1/' ) === 0 );
define( 'S2NRI_IS_PORTAL', $_s2nri_is_portal_path );
define( 'S2NRI_IS_SPA',    ! S2NRI_IS_API && ! S2NRI_IS_PORTAL && ! $is_wp_path );
define( 'S2NRI_IS_APP',    S2NRI_IS_API || S2NRI_IS_SPA || S2NRI_IS_PORTAL );

// ─── Classmap autoloader ─────────────────────────────────────────────────────
// Explicit map needed because multiple classes share one file.
// Replaces PSR-4 path derivation which only works for one-class-per-file.
$s2nri_classmap = [
    'S2NRI\Api\Controllers\Admin\AnalyticsController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\AuditLogController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\DiagnosticController' => S2NRI_DIR . 'src/Api/Controllers/Admin/DiagnosticController.php',
    'S2NRI\Api\Controllers\Admin\BookingAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\CategoryAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\CustomerAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\DocumentAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\ExportController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\ContentController' => S2NRI_DIR . 'src/Api/Controllers/Admin/ContentController.php',
    'S2NRI\Api\Controllers\Admin\MediaController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\NotificationAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\PaymentAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\QuoteAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\ReviewAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\ServiceAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\SettingsAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\StaffAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Diagnostics\DiagnosticEngine'   => S2NRI_DIR . 'src/Diagnostics/DiagnosticEngine.php',
    'S2NRI\Diagnostics\DiagnosticStore'    => S2NRI_DIR . 'src/Diagnostics/DiagnosticStore.php',
    'S2NRI\Api\Controllers\Admin\TicketAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\AuthController' => S2NRI_DIR . 'src/' . 'Api/Controllers/AuthController.php',
    'S2NRI\Api\Controllers\BaseController' => S2NRI_DIR . 'src/' . 'Api/Controllers/BaseController.php',
    'S2NRI\Api\Controllers\BookingController' => S2NRI_DIR . 'src/' . 'Api/Controllers/BookingController.php',
    'S2NRI\Api\Controllers\CategoryController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\DocumentController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\MessageController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\NotificationController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\PaymentController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\ProfileController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\ServiceController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\SettingsController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\SystemController'               => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\CityController'                 => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\ServiceSectionController'       => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Controllers\Admin\CityAdminController'     => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    'S2NRI\Api\Controllers\Admin\ServiceSectionAdminController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Admin/AdminControllers.php',
    // ── Phase 1 Controllers
    'S2NRI\Api\Controllers\Admin\EmailTemplateAdminController' => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\HolidayAdminController'       => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\QuickReplyAdminController'    => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\VendorAdminController'        => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\RequestTypeAdminController'   => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\CommunicationAdminController' => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Api\Controllers\Admin\BookingPhase1AdminController' => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase1Controllers.php',
    'S2NRI\Services\DeliveryCalculator'                        => S2NRI_DIR . 'src/Services/DeliveryCalculator.php',
    // ── Phase 2 Controllers
    'S2NRI\Api\Controllers\Admin\FormSubmissionsController'    => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\BulkExportController'        => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\WhatsAppController'          => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\DocumentBulkController'      => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\FormFieldAdminController'    => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\DropdownDataController'      => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\StandaloneQuoteController'   => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    'S2NRI\Api\Controllers\Admin\BookingPhase2AdminController'=> S2NRI_DIR . 'src/Api/Controllers/Admin/Phase2Controllers.php',
    // ── Phase 2 Services
    'S2NRI\Services\WhatsAppService'                           => S2NRI_DIR . 'src/Services/WhatsAppService.php',
    // ── Shortcodes (M-19)
    'S2NRI\Shortcodes'                                         => S2NRI_DIR . 'src/Shortcodes.php',
    // React Portal (pre-built dist/)
    'S2NRI\Portal'                                             => S2NRI_DIR . 'src/Portal.php',
    // ── Phase 3 Controllers
    'S2NRI\Api\Controllers\Admin\DocumentProxyController'      => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    'S2NRI\Api\Controllers\Admin\AvifConversionController'     => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    'S2NRI\Api\Controllers\Admin\DashboardEnhancementController'=> S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    'S2NRI\Api\Controllers\Admin\Phase3BookingController'      => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    'S2NRI\Api\Controllers\Admin\AuditTrailController'         => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    'S2NRI\Api\Controllers\Admin\SystemSettingsController'     => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase3Controllers.php',
    // ── Phase 4 Controllers
    'S2NRI\Api\Controllers\Admin\GDPRController'               => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase4Controllers.php',
    'S2NRI\Api\Controllers\Admin\AdvancedAnalyticsController'  => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase4Controllers.php',
    'S2NRI\Api\Controllers\Admin\OperationsController'         => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase4Controllers.php',
    'S2NRI\Api\Controllers\Admin\PlatformHealthController'     => S2NRI_DIR . 'src/Api/Controllers/Admin/Phase4Controllers.php',
    'S2NRI\Api\Controllers\TicketController' => S2NRI_DIR . 'src/' . 'Api/Controllers/Controllers.php',
    'S2NRI\Api\Dispatcher' => S2NRI_DIR . 'src/' . 'Api/Dispatcher.php',
    'S2NRI\Api\Middleware\Auth' => S2NRI_DIR . 'src/' . 'Api/Middleware/Auth.php',
    'S2NRI\Api\Request' => S2NRI_DIR . 'src/' . 'Api/RequestResponse.php',
    'S2NRI\Api\Response' => S2NRI_DIR . 'src/' . 'Api/RequestResponse.php',
    'S2NRI\AssetBuildStamp' => S2NRI_DIR . 'src/AssetBuildStamp.php',
    'S2NRI\BootPublic'      => S2NRI_DIR . 'src/BootPublic.php',
    'S2NRI\Bootstrap' => S2NRI_DIR . 'src/' . 'Bootstrap.php',
    'S2NRI\Exceptions\AuthException' => S2NRI_DIR . 'src/' . 'Exceptions/Exceptions.php',
    'S2NRI\Exceptions\ForbiddenException' => S2NRI_DIR . 'src/' . 'Exceptions/Exceptions.php',
    'S2NRI\Exceptions\NotFoundException' => S2NRI_DIR . 'src/' . 'Exceptions/Exceptions.php',
    'S2NRI\Exceptions\ValidationException' => S2NRI_DIR . 'src/' . 'Exceptions/Exceptions.php',
    'S2NRI\Installer' => S2NRI_DIR . 'src/' . 'Installer.php',
    'S2NRI\Jobs\CleanupJob' => S2NRI_DIR . 'src/' . 'Jobs/Jobs.php',
    'S2NRI\Jobs\NotificationJob' => S2NRI_DIR . 'src/' . 'Jobs/Jobs.php',
    'S2NRI\Jobs\QuoteReminderJob' => S2NRI_DIR . 'src/' . 'Jobs/Jobs.php',
    'S2NRI\Jobs\SitemapJob' => S2NRI_DIR . 'src/' . 'Jobs/Jobs.php',
    'S2NRI\Models\Setting' => S2NRI_DIR . 'src/' . 'Models/Setting.php',
    'S2NRI\Models\User' => S2NRI_DIR . 'src/' . 'Models/User.php',
    'S2NRI\PWA' => S2NRI_DIR . 'src/' . 'PWA.php',
    'S2NRI\SEO' => S2NRI_DIR . 'src/' . 'SEO.php',
    'S2NRI\Services\CacheService' => S2NRI_DIR . 'src/' . 'Services/Services.php',
    'S2NRI\Services\EmailService' => S2NRI_DIR . 'src/' . 'Services/Services.php',
    'S2NRI\Services\NotificationService' => S2NRI_DIR . 'src/' . 'Services/Services.php',
    'S2NRI\Services\OtpService' => S2NRI_DIR . 'src/' . 'Services/Services.php',
    'S2NRI\Design\DesignSystem' => S2NRI_DIR . 'src/Design/DesignSystem.php',
    'S2NRI\Design\WidthLayout'   => S2NRI_DIR . 'src/Design/WidthLayout.php',
    'S2NRI\Design\FontLibrary' => S2NRI_DIR . 'src/Design/FontLibrary.php',
    'S2NRI\Design\DesignPresets' => S2NRI_DIR . 'src/Design/DesignPresets.php',
    'S2NRI\Services\ServiceRegistry' => S2NRI_DIR . 'src/Services/ServiceRegistry.php',
    'S2NRI\Services\PublicEntityRegistry' => S2NRI_DIR . 'src/Services/PublicEntityRegistry.php',
    'S2NRI\Api\Controllers\DesignSystemController' => S2NRI_DIR . 'src/Api/Controllers/DesignControllers.php',
    'S2NRI\Api\Controllers\NavigationController' => S2NRI_DIR . 'src/Api/Controllers/DesignControllers.php',
    'S2NRI\Api\Controllers\ServiceRegistryAdminController' => S2NRI_DIR . 'src/Api/Controllers/DesignControllers.php',
];

spl_autoload_register( static function ( string $class ) use ( $s2nri_classmap ) {
    if ( ! array_key_exists( $class, $s2nri_classmap ) ) return;
    $file = $s2nri_classmap[ $class ];
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ─── Portal interceptor — serves /portal/* and /s2nri-admin/* as React SPA ──
// Portal::boot() intercepts requests to CUSTOMER_SLUG ('portal') and ADMIN_SLUG
// ('s2nri-admin'), writes .htaccess rewrite rules for those slugs, and renders
// the pre-built React dist/ as a standalone HTML page (no WP theme).
// If the request is not to those paths, boot() returns early with no side effects.
require_once S2NRI_DIR . 'src/BootPublic.php';
\S2NRI\BootPublic::boot();

require_once S2NRI_DIR . 'src/Portal.php';
\S2NRI\Portal::boot(); // Intercept /portal/* and /s2nri-admin/*, write .htaccess

require_once S2NRI_DIR . 'src/BuilderPage.php';
\S2NRI\BuilderPage::boot(); // Intercept /s2nri-builder/* — React Builder SPA

// ─── OPcache: one-time invalidation on plugin version change only ─────────────
// REMOVED: opcache_reset() on every request caused extreme slowdown (every PHP
// file recompiled from scratch per request) → request timeouts → Cloudflare 503.
// Now invalidates only when the plugin version changes — not on every request.
if ( get_option( 's2nri_opcache_cleared' ) !== S2NRI_VERSION ) {
    if ( function_exists( 'opcache_invalidate' ) ) {
        foreach ( glob( S2NRI_DIR . 'src/**/*.php' ) ?: [] as $f ) opcache_invalidate( $f, true );
        foreach ( glob( S2NRI_DIR . 'src/*.php' ) ?: [] as $f ) opcache_invalidate( $f, true );
        opcache_invalidate( S2NRI_FILE, true );
    }
    update_option( 's2nri_opcache_cleared', S2NRI_VERSION );
}

// ─── Early API intercept ──────────────────────────────────────────────────────
if ( S2NRI_IS_API ) {
    add_action( 'plugins_loaded', function () {
        ( new \S2NRI\Api\Dispatcher() )->dispatch();
        exit;
    }, 1 );
}

// ─── NGINX / Proxy fallback: add_action('init') intercept ─────────────────────
// Some Nginx/proxy setups pass REQUEST_URI differently at plugins_loaded time.
// This second check fires at 'init' (later in the WP boot) and catches those cases.
add_action( 'init', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = strtok( $uri, '?' );
    // Strip WP base path if in subdirectory
    if ( defined( 'WP_HOME' ) ) {
        $base = rtrim( parse_url( WP_HOME, PHP_URL_PATH ) ?? '', '/' );
        if ( $base && strpos( $path, $base ) === 0 ) {
            $path = substr( $path, strlen( $base ) );
        }
    }
    $clean = '/' . ltrim( $path, '/' );
    if ( strpos( $clean, '/api/v1/' ) !== 0 ) return;
    // Already handled by early intercept if S2NRI_IS_API was true — but if not, handle now
    if ( S2NRI_IS_API ) return; // already dispatched at plugins_loaded
    ( new \S2NRI\Api\Dispatcher() )->dispatch();
    exit;
}, 5 );

// ─── SPA rendering ────────────────────────────────────────────────────────────
if ( S2NRI_IS_SPA ) {
    add_action( 'template_redirect', function () {
        try {
            ( new \S2NRI\SEO() )->render();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[S2NRI] SEO render failed: ' . $e->getMessage() );
            }
            status_header( 500 );
            header( 'Content-Type: text/html; charset=utf-8' );
            echo '<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;max-width:640px;margin:auto">';
            echo '<h1>Services2NRI boot error (v' . esc_html( S2NRI_VERSION ) . ')</h1>';
            echo '<p>The public app could not render. Fix the PHP error below or reinstall the plugin zip.</p>';
            echo '<pre style="background:#f1f5f9;padding:16px;border-radius:8px;overflow:auto">' . esc_html( $e->getMessage() ) . '</pre>';
            echo '<p><a href="' . esc_url( home_url( '/?s2nri_boot_diag=1' ) ) . '">Boot diagnostic JSON</a></p>';
            echo '</body></html>';
        }
        exit;
    }, 0 );
}

// ─── WP feeds guard ───────────────────────────────────────────────────────────
add_action( 'wp', function () {
    global $wp_query;
    if ( $wp_query && ! isset( $wp_query->feeds ) ) {
        $wp_query->feeds = [];
    }
}, 1 );

// ─── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    $stored = get_option( 's2nri_db_version', '0.0.0' );
    if ( version_compare( $stored, S2NRI_VERSION, '<' ) ) {
        \S2NRI\Installer::runMigrations();
        \S2NRI\Installer::seedData();
        \S2NRI\Installer::importAllFormFields(); // Issue 12 FIX
        \S2NRI\Design\DesignSystem::ensureSeeded();
        \S2NRI\Portal::ensureHtaccess();
        // ensureRewriteRules runs on init (registered in Portal::boot())
        // flush_rewrite_rules deferred to init so $wp_rewrite is ready
        add_action( 'init', function() { flush_rewrite_rules( false ); }, 99 );
        // Also reset the opcache_cleared flag so individual file invalidation runs once more
        delete_option( 's2nri_opcache_cleared' );
        update_option( 's2nri_db_version', S2NRI_VERSION );
    }
    \S2NRI\Bootstrap::init();

    // ── Rewrite rule self-heal ─────────────────────────────────────────────
    // If the s2nri-admin or portal rewrite rules are missing from the saved WP
    // option (e.g. another plugin flushed rules without our rules being added),
    // WordPress will 404 or 503 on /s2nri-admin/* requests.
    // This check runs on every plugins_loaded but the DB read is < 1ms.
    add_action( 'init', function() {
        $saved = get_option( 'rewrite_rules', [] );
        $needs_flush = empty( $saved )
            || ! isset( $saved['^s2nri-admin(/.*)?$'] )
            || ! isset( $saved['^portal(/.*)?$'] )
            || ! isset( $saved['^s2nri-builder(/.*)?$'] );
        if ( $needs_flush ) {
            flush_rewrite_rules( false );
        }
    }, 5 );
}, 10 );

// ─── Activation / Deactivation ────────────────────────────────────────────────
register_activation_hook(   __FILE__, [ \S2NRI\Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ \S2NRI\Installer::class, 'deactivate' ] );

// ─── Image placeholder endpoint (?s2nri_img=key) ────────────────────────────
// Returns SVG illustrations for hero banners, service cards, city cards, avatars.
// Used when admin has not uploaded custom images. No external URLs needed.
// Hooked on plugins_loaded priority 1 — before SPA intercept at priority 10.
add_action( 'plugins_loaded', function () {
    if ( ! isset( $_GET['s2nri_img'] ) ) return;

    $key      = sanitize_key( $_GET['s2nri_img'] );
    $settings = \S2NRI\Models\Setting::getPublic();
    $primary  = $settings['primary_color'] ?? '#1565c0';

    // Color and icon maps per image key
    $configs = [
        // Hero banners — India-themed blue gradients
        'hero_1'       => [ 'bg1'=>'#0d2b6e', 'bg2'=>'#1565c0', 'icon'=>'🇮🇳', 'label'=>'NRI Services', 'sub'=>'Connect with India', 'w'=>1400, 'h'=>700 ],
        'hero_2'       => [ 'bg1'=>'#0f4c81', 'bg2'=>'#1565c0', 'icon'=>'✈️', 'label'=>'Passport & Visa', 'sub'=>'Expert Assistance', 'w'=>1400, 'h'=>700 ],
        'hero_3'       => [ 'bg1'=>'#1a237e', 'bg2'=>'#283593', 'icon'=>'📋', 'label'=>'Documentation', 'sub'=>'From Anywhere', 'w'=>1400, 'h'=>700 ],
        'hero_4'       => [ 'bg1'=>'#004d40', 'bg2'=>'#00695c', 'icon'=>'🏠', 'label'=>'Property Care', 'sub'=>'While You Are Away', 'w'=>1400, 'h'=>700 ],
        // Services
        'property'     => [ 'bg1'=>'#0f4c81', 'bg2'=>'#1976d2', 'icon'=>'🏠', 'label'=>'Property Management', 'sub'=>'End-to-End Care', 'w'=>600, 'h'=>400 ],
        'housekeeping' => [ 'bg1'=>'#004d40', 'bg2'=>'#00897b', 'icon'=>'🧹', 'label'=>'Housekeeping', 'sub'=>'Professional Service', 'w'=>600, 'h'=>400 ],
        'tenancy'      => [ 'bg1'=>'#4a148c', 'bg2'=>'#7b1fa2', 'icon'=>'🤝', 'label'=>'Tenancy Management', 'sub'=>'Trusted Tenants', 'w'=>600, 'h'=>400 ],
        'rent'         => [ 'bg1'=>'#bf360c', 'bg2'=>'#e64a19', 'icon'=>'📝', 'label'=>'Rental Agreement', 'sub'=>'Legal & Binding', 'w'=>600, 'h'=>400 ],
        'financial'    => [ 'bg1'=>'#e65100', 'bg2'=>'#f57c00', 'icon'=>'💹', 'label'=>'Financial Services', 'sub'=>'Smart Investing', 'w'=>600, 'h'=>400 ],
        'tax'          => [ 'bg1'=>'#1b5e20', 'bg2'=>'#2e7d32', 'icon'=>'📊', 'label'=>'Tax Services', 'sub'=>'ITR & Compliance', 'w'=>600, 'h'=>400 ],
        'epf'          => [ 'bg1'=>'#0d47a1', 'bg2'=>'#1565c0', 'icon'=>'💰', 'label'=>'EPF Withdrawal', 'sub'=>'PF Assistance', 'w'=>600, 'h'=>400 ],
        'financial2'   => [ 'bg1'=>'#37474f', 'bg2'=>'#546e7a', 'icon'=>'🏦', 'label'=>'NRE/NRO Account', 'sub'=>'Banking Made Easy', 'w'=>600, 'h'=>400 ],
        'singlestatus' => [ 'bg1'=>'#880e4f', 'bg2'=>'#c2185b', 'icon'=>'💍', 'label'=>'Single Status', 'sub'=>'Bachelorhood Cert', 'w'=>600, 'h'=>400 ],
        'birth'        => [ 'bg1'=>'#1a237e', 'bg2'=>'#303f9f', 'icon'=>'👶', 'label'=>'Birth Certificate', 'sub'=>'Official Docs', 'w'=>600, 'h'=>400 ],
        'nabc'         => [ 'bg1'=>'#3e2723', 'bg2'=>'#5d4037', 'icon'=>'📜', 'label'=>'NABC', 'sub'=>'Non-Availability', 'w'=>600, 'h'=>400 ],
        'apostille'    => [ 'bg1'=>'#006064', 'bg2'=>'#00838f', 'icon'=>'🔏', 'label'=>'Apostille', 'sub'=>'MEA Attestation', 'w'=>600, 'h'=>400 ],
        'transcript'   => [ 'bg1'=>'#311b92', 'bg2'=>'#4527a0', 'icon'=>'🎓', 'label'=>'University Transcript', 'sub'=>'All Universities', 'w'=>600, 'h'=>400 ],
        'moi'          => [ 'bg1'=>'#1b5e20', 'bg2'=>'#388e3c', 'icon'=>'📖', 'label'=>'MOI Certificate', 'sub'=>'English Medium', 'w'=>600, 'h'=>400 ],
        'degree'       => [ 'bg1'=>'#f57f17', 'bg2'=>'#f9a825', 'icon'=>'🏅', 'label'=>'Degree Certificate', 'sub'=>'Original/Duplicate', 'w'=>600, 'h'=>400 ],
        'marksheet'    => [ 'bg1'=>'#b71c1c', 'bg2'=>'#d32f2f', 'icon'=>'📄', 'label'=>'Duplicate Marksheet', 'sub'=>'All Boards', 'w'=>600, 'h'=>400 ],
        'about'        => [ 'bg1'=>'#0d2b6e', 'bg2'=>'#1565c0', 'icon'=>'👥', 'label'=>'Our Expert Team', 'sub'=>'Trusted NRI Partners', 'w'=>800, 'h'=>500 ],
        // Cities
        'nagpur'       => [ 'bg1'=>'#e65100', 'bg2'=>'#f57c00', 'icon'=>'🏙️', 'label'=>'Nagpur', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'pune'         => [ 'bg1'=>'#1565c0', 'bg2'=>'#1976d2', 'icon'=>'🌆', 'label'=>'Pune', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'mumbai'       => [ 'bg1'=>'#0f4c81', 'bg2'=>'#1565c0', 'icon'=>'🏙️', 'label'=>'Mumbai', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'delhi'        => [ 'bg1'=>'#b71c1c', 'bg2'=>'#c62828', 'icon'=>'🕌', 'label'=>'Delhi', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'bangalore'    => [ 'bg1'=>'#1b5e20', 'bg2'=>'#2e7d32', 'icon'=>'🌃', 'label'=>'Bangalore', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'hyderabad'    => [ 'bg1'=>'#4a148c', 'bg2'=>'#6a1b9a', 'icon'=>'🕌', 'label'=>'Hyderabad', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'chennai'      => [ 'bg1'=>'#006064', 'bg2'=>'#00838f', 'icon'=>'🌊', 'label'=>'Chennai', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        'ahmedabad'    => [ 'bg1'=>'#e65100', 'bg2'=>'#ef6c00', 'icon'=>'🏛️', 'label'=>'Ahmedabad', 'sub'=>'Property Services', 'w'=>400, 'h'=>280 ],
        // Avatars — person silhouettes with colored backgrounds
        'avatar_1'     => [ 'bg1'=>'#1565c0', 'bg2'=>'#1976d2', 'icon'=>'👤', 'label'=>'V', 'sub'=>'', 'w'=>80, 'h'=>80 ],
        'avatar_2'     => [ 'bg1'=>'#2e7d32', 'bg2'=>'#388e3c', 'icon'=>'👤', 'label'=>'A', 'sub'=>'', 'w'=>80, 'h'=>80 ],
        'avatar_3'     => [ 'bg1'=>'#c62828', 'bg2'=>'#d32f2f', 'icon'=>'👤', 'label'=>'A', 'sub'=>'', 'w'=>80, 'h'=>80 ],
        'avatar_4'     => [ 'bg1'=>'#e65100', 'bg2'=>'#f57c00', 'icon'=>'👤', 'label'=>'R', 'sub'=>'', 'w'=>80, 'h'=>80 ],
        'avatar_5'     => [ 'bg1'=>'#6a1b9a', 'bg2'=>'#7b1fa2', 'icon'=>'👤', 'label'=>'P', 'sub'=>'', 'w'=>80, 'h'=>80 ],
    ];

    $c = $configs[ $key ] ?? [ 'bg1'=>$primary, 'bg2'=>$primary, 'icon'=>'📋', 'label'=>$key, 'sub'=>'', 'w'=>600, 'h'=>400 ];
    $w = (int) $c['w']; $h = (int) $c['h'];
    $cx = $w / 2; $cy = $h / 2;
    $icon_size = (int) ( $h * 0.28 );
    $label_y   = (int) ( $cy + $h * 0.12 );
    $sub_y     = (int) ( $cy + $h * 0.23 );

    $is_avatar = ( strpos( $key, 'avatar_' ) === 0 );
    $content_svg = '';
    if ( $is_avatar ) {
        $letter      = strtoupper( $c['label'] );
        $r           = (int) ( $w / 2 );
        $text_y      = (int) ( $cy + $h * 0.15 );
        $font_size   = (int) ( $w * 0.45 );
        $content_svg = '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $c['bg1'] . '"/>'
                     . '<text x="' . $cx . '" y="' . $text_y . '" font-family="system-ui,Arial" font-size="' . $font_size . '" font-weight="900" text-anchor="middle" fill="#ffffff">' . $letter . '</text>';
    } else {
        $grad_y      = (int) ( $cy - $h * 0.05 );
        $main_fs     = (int) ( $h * 0.075 );
        $sub_fs      = (int) ( $h * 0.055 );
        $content_svg = '<defs>'
                     . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
                     . '<stop offset="0" stop-color="' . $c['bg1'] . '"/>'
                     . '<stop offset="1" stop-color="' . $c['bg2'] . '"/>'
                     . '</linearGradient>'
                     . '<pattern id="p" x="0" y="0" width="30" height="30" patternUnits="userSpaceOnUse">'
                     . '<circle cx="15" cy="15" r="1" fill="rgba(255,255,255,0.07)"/>'
                     . '</pattern>'
                     . '</defs>'
                     . '<rect width="' . $w . '" height="' . $h . '" fill="url(#g)"/>'
                     . '<rect width="' . $w . '" height="' . $h . '" fill="url(#p)"/>'
                     . '<text x="' . $cx . '" y="' . $grad_y . '" font-size="' . $icon_size . '" text-anchor="middle">' . $c['icon'] . '</text>'
                     . '<text x="' . $cx . '" y="' . $label_y . '" font-family="system-ui,Arial,sans-serif" font-size="' . $main_fs . '" font-weight="700" text-anchor="middle" fill="#ffffff">' . esc_html( $c['label'] ) . '</text>';
        if ( $c['sub'] ) {
            $content_svg .= '<text x="' . $cx . '" y="' . $sub_y . '" font-family="system-ui,Arial,sans-serif" font-size="' . $sub_fs . '" font-weight="400" text-anchor="middle" fill="rgba(255,255,255,0.8)">' . esc_html( $c['sub'] ) . '</text>';
        }
    }

    header( 'Content-Type: image/svg+xml' );
    header( 'Cache-Control: public, max-age=86400' );
    header( 'Access-Control-Allow-Origin: *' );
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">' . $content_svg . '</svg>';
    exit;
} );

// ─── PNG Icon endpoint (manifest icon, favicon, apple-touch-icon) ────────────
// Generates a valid PNG from the brand primary color — no static file needed.
// Hooked on plugins_loaded priority 2 — before SPA intercept.
add_action( 'plugins_loaded', function () {
    if ( isset( $_GET['s2nri_icon'] ) ) {
        // Always generate a valid PNG programmatically — never depends on static files
        // Uses raw PNG byte generation (no GD required)
        header( 'Content-Type: image/png' );
        header( 'Cache-Control: public, max-age=604800' );
        header( 'X-Content-Type-Options: nosniff' );

        $size = isset( $_GET['size'] ) ? (int) $_GET['size'] : 192;
        $size = in_array( $size, [ 192, 512 ] ) ? $size : 192;

        $settings = \S2NRI\Models\Setting::getPublic();
        $hex      = ltrim( $settings['primary_color'] ?? '#1565c0', '#' );
        if ( strlen( $hex ) !== 6 ) $hex = '1565c0';
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );

        // Build raw PNG bytes in pure PHP — no GD, no extensions required
        $row     = chr(0) . str_repeat( chr($r) . chr($g) . chr($b), $size );
        $raw     = str_repeat( $row, $size );
        $idat    = gzcompress( $raw, 9 );
        $ihdr    = pack( 'NNCCCCC', $size, $size, 8, 2, 0, 0, 0 );

        $png_chunk = function( string $type, string $data ): string {
            $crc = crc32( $type . $data );
            // Ensure unsigned 32-bit
            if ( $crc < 0 ) $crc += 4294967296;
            return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', $crc );
        };

        $png  = "\x89PNG\r\n\x1a\n";
        $png .= $png_chunk( 'IHDR', $ihdr );
        $png .= $png_chunk( 'IDAT', $idat );
        $png .= $png_chunk( 'IEND', '' );

        echo $png;
        exit;
    }
} );

// ─── PWA / Sitemap endpoints ─────────────────────────────────────────────────
add_action( 'init', function () {
    $path = strtok( $_SERVER['REQUEST_URI'], '?' );
    // ── robots.txt ────────────────────────────────────────────────────────
    if ( $path === '/robots.txt' ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: public, max-age=86400' );
        $home = home_url();
        echo "User-agent: *
";
        echo "Disallow: /api/
";
        echo "Disallow: /portal/
";
        echo "Disallow: /s2nri-admin/
";
        echo "Allow: /

";
        echo "Sitemap: {$home}/sitemap.xml
";
        exit;
    }

    // ── sitemap.xml ───────────────────────────────────────────────────────
    if ( $path === '/sitemap.xml' ) {
        global $wpdb;
        $p    = $wpdb->prefix;
        $home = rtrim( home_url(), '/' );
        $settings = \S2NRI\Models\Setting::getPublic();

        $services = array_map(
            static fn( $s ) => [
                'slug'       => $s['slug'],
                'updated_at' => $s['updated_at'] ?? '',
            ],
            \S2NRI\Services\ServiceRegistry::forSurface( 'sitemap' )
        );

        // Get published blog posts
        $posts = [];
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}s2nri_blog_posts'" ) ) {
            $posts = $wpdb->get_results(
                "SELECT slug, updated_at FROM {$p}s2nri_blog_posts WHERE is_published = 1 ORDER BY created_at DESC LIMIT 200",
                ARRAY_A
            ) ?: [];
        }

        $static_pages = [
            ['/', '1.0', 'daily'],
            ['/services', '0.9', 'daily'],
            ['/about', '0.7', 'monthly'],
            ['/contact', '0.7', 'monthly'],
            ['/how-it-works', '0.8', 'monthly'],
            ['/faq', '0.7', 'monthly'],
            ['/pricing', '0.8', 'monthly'],
            ['/blog', '0.8', 'weekly'],
        ];

        $city_rows = \S2NRI\Services\PublicEntityRegistry::publicCities( 'sitemap' );

        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "
";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "
";

        foreach ( $static_pages as [$loc, $prio, $freq] ) {
            echo "<url><loc>{$home}{$loc}</loc><changefreq>{$freq}</changefreq><priority>{$prio}</priority></url>
";
        }

        foreach ( $city_rows as $city ) {
            $slug = esc_attr( (string) ( $city['slug'] ?? '' ) );
            if ( $slug === '' ) {
                continue;
            }
            echo "<url><loc>{$home}/cities/{$slug}</loc><changefreq>monthly</changefreq><priority>0.8</priority></url>
";
        }

        foreach ( $services as $svc ) {
            $mod = ! empty( $svc['updated_at'] ) ? date( 'Y-m-d', strtotime( $svc['updated_at'] ) ) : date( 'Y-m-d' );
            echo "<url><loc>{$home}/service/{$svc['slug']}</loc><lastmod>{$mod}</lastmod><changefreq>monthly</changefreq><priority>0.85</priority></url>
";
        }

        foreach ( $posts as $post ) {
            $mod = ! empty( $post['updated_at'] ) ? date( 'Y-m-d', strtotime( $post['updated_at'] ) ) : date( 'Y-m-d' );
            echo "<url><loc>{$home}/blog/{$post['slug']}</loc><lastmod>{$mod}</lastmod><changefreq>weekly</changefreq><priority>0.6</priority></url>
";
        }

        echo '</urlset>';
        exit;
    }

    if ( $path === '/manifest.json' ) {
        \S2NRI\PWA::serveManifest(); exit;
    }
    if ( $path === '/sw.js' ) {
        \S2NRI\PWA::serveServiceWorker(); exit;
    }
    if ( $path === '/s2nri-sitemap.xml' ) {
        $file = ABSPATH . 's2nri-sitemap.xml';
        header( 'Content-Type: application/xml; charset=utf-8' );
        header( 'Cache-Control: public, max-age=3600' );
        if ( file_exists( $file ) ) { readfile( $file ); }
        else { echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>' . esc_url( home_url( '/' ) ) . '</loc></url></urlset>'; }
        exit;
    }
}, 1 );

// ─── WP Admin notice + portal link ───────────────────────────────────────────
// FIXED: Use 64-char user_meta token (same as Bootstrap::portalUrl) instead of
// short 32-char transient token. Transient approach failed on CDN installs because
// transient storage is on origin DB but request goes through CDN. User_meta is always
// read from origin DB regardless of CDN caching layer.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'administrator' ) ) return;
    $uid   = get_current_user_id();
    // Reuse existing valid token — do NOT regenerate on every admin page load.
    // Regenerating here overwrites the token stored in DB, instantly invalidating
    // any open portal or builder tab → every API call returns 401.
    $session_token = get_user_meta( $uid, 's2nri_portal_token', true );
    $token_exp     = (int) get_user_meta( $uid, 's2nri_portal_token_exp', true );
    if ( ! $session_token || strlen( $session_token ) !== 64 || $token_exp <= time() ) {
        $session_token = bin2hex( random_bytes( 32 ) );
        update_user_meta( $uid, 's2nri_portal_token', $session_token );
        update_user_meta( $uid, 's2nri_portal_token_exp', time() + ( 8 * HOUR_IN_SECONDS ) );
    }
    $portal_url = add_query_arg( [ 's2nri_token' => $session_token ], home_url( '/admin' ) );
    ?>
    <div class="notice notice-info is-dismissible" style="display:flex;align-items:center;gap:12px;padding:10px 15px;">
        <strong>Services2NRI</strong> is active.
        <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"
           style="background:#0f4c81;color:#fff;padding:5px 16px;border-radius:6px;text-decoration:none;font-size:13px;">
            Open Admin Portal →
        </a>
    </div>
    <?php
} );

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'administrator' ) ) {
        return;
    }
    if ( is_file( S2NRI_DIR . 'assets/app.js' ) ) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>Services2NRI:</strong> '
        . '<code>assets/app.js</code> is missing — the public site will show an infinite loader. '
        . 'Re-upload the official plugin zip from GitHub (services2nri-full-source.zip, v' . esc_html( S2NRI_VERSION ) . '+).</p></div>';
} );

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'administrator' ) ) {
        return;
    }
    if ( ! class_exists( '\S2NRI\AssetBuildStamp' ) || ! \S2NRI\AssetBuildStamp::isMismatch() ) {
        return;
    }
    $recorded = \S2NRI\AssetBuildStamp::readRecorded();
    $computed = \S2NRI\AssetBuildStamp::computeFromDisk();
    echo '<div class="notice notice-error"><p><strong>Services2NRI:</strong> '
        . 'JavaScript assets are from mixed builds (BUILD_STAMP <code>' . esc_html( $recorded ) . '</code> '
        . '≠ on-disk <code>' . esc_html( $computed ) . '</code>). '
        . 'Delete <code>wp-content/plugins/services2nri/</code> and upload a fresh '
        . '<strong>services2nri-full-source.zip</strong> (v' . esc_html( S2NRI_VERSION ) . '+), then purge CDN cache. '
        . 'Mixed <code>app.js</code> and <code>chunks/*.js</code> files cause console errors like '
        . '<code>does not provide an export named</code>.</p></div>';
} );

// ─── Activation health check notice ─────────────────────────────────────────
// If rewrite rules for /s2nri-admin/ are missing, show a one-time notice with
// a direct "Fix Now" button that flushes rules. This covers the most common
// fresh-install failure: admin portal returns 404.
add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'administrator' ) ) return;
    $saved = get_option( 'rewrite_rules', [] );
    $has_admin_rule   = ! empty( $saved['^s2nri-admin(/.*)?$'] );
    $has_portal_rule  = ! empty( $saved['^portal(/.*)?$'] );
    $has_builder_rule = ! empty( $saved['^s2nri-builder(/.*)?$'] );
    if ( $has_admin_rule && $has_portal_rule && $has_builder_rule ) return;

    // Auto-fix silently in the background
    \S2NRI\Portal::ensureRewriteRules();
    \S2NRI\BuilderPage::registerRewriteRules();
    \S2NRI\Bootstrap::registerRewrites();
    flush_rewrite_rules( false );
    // Suppress the notice — we just fixed it automatically
} );

add_filter( 'robots_txt', function ( $output ) {
    $output .= "\n# Services2NRI\nSitemap: " . home_url( '/s2nri-sitemap.xml' ) . "\nUser-agent: *\nAllow: /\n";
    return $output;
} );
