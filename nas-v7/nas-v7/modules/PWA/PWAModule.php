<?php
/**
 * NAS PWA Module v1.0
 * Registers the Progressive Web App shell, manifest, service worker,
 * and all AJAX data-feed endpoints consumed by the PWA JS layer.
 *
 * Route: /nas-app/ → PWA shell (full-page bypass, React-like SPA)
 * Manifest: /nas-app/manifest.json
 * Service Worker: /nas-sw.js (root scope for broad caching)
 */
namespace NAS\Modules\PWA;

use NAS\Core\{Module, Database, Security, Config, Cache, Helpers};
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Module registration ───────────────────────────────────────────────────────
class PWAModule extends Module {

    public function key(): string { return 'pwa'; }

    // TRACE: register() — Trigger: ModuleManager::boot() at plugins_loaded+5.
    //        Steps: registers rewrite rules for /nas-app/, /nas-sw.js, /nas-app/manifest.json;
    //               hooks AJAX endpoints for all PWA data feeds; hooks manifest meta into wp_head.
    //        Postcondition: all PWA routes and endpoints available.
    public function register(): void {
        // Rewrite rules
        add_action( 'init', [ $this, 'add_rewrite_rules' ] );

        // Serve PWA shell
        add_action( 'template_redirect', [ $this, 'maybe_serve_pwa' ], 1 );

        // ── Site-wide PWA intercept (enabled in WP Admin → NAS → Mobile App & Themes) ──
        // When enabled: serves the PWA shell on ALL public pages (homepage, blog, etc.)
        // Mirrors marketplace-os react-app.php interception approach.
        if ( \NAS\Core\Config::instance()->get('nas_pwa_site_wide', 0) ) {
            add_filter( 'template_include', [ $this, 'intercept_frontend' ], 99 );
        }

        // Serve service worker at root scope
        add_action( 'template_redirect', [ $this, 'maybe_serve_sw' ], 1 );

        // Serve manifest
        add_action( 'template_redirect', [ $this, 'maybe_serve_manifest' ], 1 );

        // Inject manifest + theme-color into <head> for all pages (enables Add to Home Screen)
        add_action( 'wp_head', [ $this, 'inject_pwa_meta' ] );

        // ── PWA Admin Settings ────────────────────────────────────────────
        PWASettings::register();

        // ── Phase 2: Client Portal ─────────────────────────────────────────
        add_action( 'wp_ajax_nas_pwa_booking_detail',  [ PWAPhase2Controller::class, 'booking_detail' ] );
        add_action( 'wp_ajax_nas_pwa_send_message',    [ PWAPhase2Controller::class, 'send_message' ] );
        add_action( 'wp_ajax_nas_pwa_upload_material', [ PWAPhase2Controller::class, 'upload_material' ] );
        add_action( 'wp_ajax_nas_pwa_track_order',        [ PWAPhase2Controller::class, 'track_order' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_track_order', [ PWAPhase2Controller::class, 'track_order' ] );
        add_action( 'wp_ajax_nas_pwa_cancel_booking',  [ PWAPhase2Controller::class, 'cancel_booking' ] );
        add_action( 'wp_ajax_nas_pwa_get_messages',    [ PWAPhase2Controller::class, 'get_messages' ] );

        // ── Phase 3: In-PWA Booking Wizard ────────────────────────────────
        add_action( 'wp_ajax_nas_pwa_wizard_data',        [ PWAPhase3Controller::class, 'wizard_data' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_wizard_data', [ PWAPhase3Controller::class, 'wizard_data' ] );
        add_action( 'wp_ajax_nas_pwa_wizard_newspapers',        [ PWAPhase3Controller::class, 'wizard_newspapers' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_wizard_newspapers', [ PWAPhase3Controller::class, 'wizard_newspapers' ] );
        add_action( 'wp_ajax_nas_pwa_wizard_rates',        [ PWAPhase3Controller::class, 'wizard_rates' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_wizard_rates', [ PWAPhase3Controller::class, 'wizard_rates' ] );
        add_action( 'wp_ajax_nas_pwa_wizard_calculate',        [ PWAPhase3Controller::class, 'wizard_calculate' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_wizard_calculate', [ PWAPhase3Controller::class, 'wizard_calculate' ] );
        add_action( 'wp_ajax_nas_pwa_wizard_submit',        [ PWAPhase3Controller::class, 'wizard_submit' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_wizard_submit', [ PWAPhase3Controller::class, 'wizard_submit' ] );

        // ── Final Phases: Registration, Coupon, Payment, Rate Card ─────────
        add_action( 'wp_ajax_nopriv_nas_pwa_register',        [ PWAFinalController::class, 'register_client' ] );
        add_action( 'wp_ajax_nas_pwa_validate_coupon',        [ PWAFinalController::class, 'validate_coupon' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_validate_coupon', [ PWAFinalController::class, 'validate_coupon' ] );
        add_action( 'wp_ajax_nas_pwa_init_payment',           [ PWAFinalController::class, 'init_payment' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_init_payment',    [ PWAFinalController::class, 'init_payment' ] );
        add_action( 'wp_ajax_nas_pwa_rate_card',              [ PWAFinalController::class, 'rate_card' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_rate_card',       [ PWAFinalController::class, 'rate_card' ] );

        // ── Phase 5: Push Notifications ──────────────────────────────────────
        PWAPushService::register();

        // ── Phase 6: PWA Analytics ────────────────────────────────────────────
        PWAAnalyticsService::register();

        // ── Phase 7: Admin Dashboard Widget ───────────────────────────────────
        PWAAdminDashboard::register();

        // ── Phase 4: Vendor PWA ────────────────────────────────────────────
        add_action( 'wp_ajax_nas_pwa_vendor_bookings',       [ PWAVendorController::class, 'vendor_bookings' ] );
        add_action( 'wp_ajax_nas_pwa_vendor_stats',          [ PWAVendorController::class, 'vendor_stats' ] );
        add_action( 'wp_ajax_nas_pwa_vendor_mark_published', [ PWAVendorController::class, 'vendor_mark_published' ] );
        add_action( 'wp_ajax_nas_pwa_vendor_upload_proof',   [ PWAVendorController::class, 'vendor_upload_proof' ] );
        add_action( 'wp_ajax_nas_pwa_vendor_profile',        [ PWAVendorController::class, 'vendor_profile' ] );
        add_action( 'wp_ajax_nas_pwa_vendor_earnings',       [ PWAVendorController::class, 'vendor_earnings' ] );

        // ── Admin menu item — adds "Mobile App" under NAS admin ──────────────
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );

        // ── PWA AJAX Data APIs ─────────────────────────────────────────────
        // Home screen
        add_action( 'wp_ajax_nas_pwa_home',        [ PWAController::class, 'home' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_home', [ PWAController::class, 'home' ] );
        // Browse: categories + newspapers
        add_action( 'wp_ajax_nas_pwa_browse',        [ PWAController::class, 'browse' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_browse', [ PWAController::class, 'browse' ] );
        // Newspaper detail
        add_action( 'wp_ajax_nas_pwa_newspaper',        [ PWAController::class, 'newspaper_detail' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_newspaper', [ PWAController::class, 'newspaper_detail' ] );
        // Search
        add_action( 'wp_ajax_nas_pwa_search',        [ PWAController::class, 'search' ] );
        add_action( 'wp_ajax_nopriv_nas_pwa_search', [ PWAController::class, 'search' ] );
        // Client bookings (authenticated)
        add_action( 'wp_ajax_nas_pwa_bookings', [ PWAController::class, 'bookings' ] );
        // Account / profile (authenticated)
        add_action( 'wp_ajax_nas_pwa_account', [ PWAController::class, 'account' ] );
        // Notification count for badge
        add_action( 'wp_ajax_nas_pwa_notif_count', [ PWAController::class, 'notif_count' ] );
        // PWA login (returns JWT-style token stored in localStorage for offline use)
        add_action( 'wp_ajax_nopriv_nas_pwa_login', [ PWAController::class, 'login' ] );
        // Push subscription
        add_action( 'wp_ajax_nas_pwa_push_subscribe', [ PWAController::class, 'push_subscribe' ] );
    }

    // ══════════════════════════════════════════════════════════════════════
    // SITE-WIDE FRONTEND INTERCEPT
    // TRACE: intercept_frontend() — Trigger: template_include filter (priority 99)
    //        when nas_pwa_site_wide=1 is set in Config.
    //        Serves the PWA shell instead of any WordPress theme template for
    //        ALL public-facing pages EXCEPT NAS portal pages (client/admin/booking
    //        dashboards) which have their own self-contained templates.
    //        Exactly mirrors the marketplace-os react-app.php approach.
    //        Precondition: nas_pwa_site_wide=1; not an AJAX or REST request.
    //        Postcondition: PWA shell rendered for every public page; portal
    //                       pages unaffected; admin unaffected.
    //        Edge cases: is_admin() → skip; AJAX requests → skip;
    //                    NAS portal pages → skip (they have own templates).
    // ══════════════════════════════════════════════════════════════════════
    public function intercept_frontend( string $template ): string {
        // Never intercept admin, AJAX, REST, CLI
        if ( is_admin() )             return $template;
        if ( wp_doing_ajax() )        return $template;
        if ( defined('REST_REQUEST') && REST_REQUEST ) return $template;
        if ( defined('WP_CLI') && WP_CLI )             return $template;

        // Never intercept our own portal pages — they have self-contained templates
        $portal_pages = [
            get_option('nas_page_client_dashboard'),
            get_option('nas_page_admin_dashboard'),
            get_option('nas_page_staff_dashboard'),
            get_option('nas_page_moderation_dashboard'),
            get_option('nas_page_booking'),
            get_option('nas_page_confirmation'),
            get_option('nas_page_payment'),
            get_option('nas_page_login'),
            get_option('nas_page_vendor_dashboard'),
        ];
        $portal_pages = array_filter( array_map('intval', $portal_pages) );

        if ( is_page() ) {
            $pid = (int)get_queried_object_id();
            if ( in_array( $pid, $portal_pages, true ) ) return $template;
            // Also skip pages with NAS portal template
            $page_template = get_post_meta( $pid, '_wp_page_template', true );
            if ( $page_template === 'nas-portal' ) return $template;
        }

        // Skip sitemap, feeds, robots
        if ( is_feed() || is_robots() || is_sitemap() ) return $template;

        // Serve PWA shell for all other public pages
        $shell = NAS_DIR . 'templates/pwa/shell.php';
        if ( file_exists($shell) ) return $shell;

        return $template;
    }


    public function boot(): void {}

    // TRACE: register_admin_menu() — Trigger: admin_menu hook.
    //        Adds "📱 Mobile App" submenu under the NAS admin parent menu.
    //        Renders templates/admin/pwa-settings.php inside WP admin chrome.
    //        Precondition: NAS admin parent menu must exist (registered by AdminModule).
    //        Postcondition: /wp-admin/admin.php?page=nas-pwa-settings accessible to manage_options users.
    public function register_admin_menu(): void {
        add_submenu_page(
            'nas-portal',                          // parent slug (NAS admin — correct slug)
            __( 'Mobile App / PWA Settings', 'nas' ),
            __( '📱 Mobile App', 'nas' ),
            'manage_options',
            'nas-pwa-settings',
            [ $this, 'render_pwa_settings' ]
        );
    }

    // TRACE: render_pwa_settings() — Trigger: WP admin page callback for nas-pwa-settings.
    //        Includes templates/admin/pwa-settings.php inside WP admin chrome.
    //        Precondition: NASTheme class loaded; user has manage_options cap.
    //        Postcondition: ThemePicker + BgPicker + save UI rendered in admin.
    public function render_pwa_settings(): void {
        include NAS_DIR . 'templates/admin/pwa-settings.php';
    }

    // TRACE: add_rewrite_rules() — Trigger: init hook.
    //        Adds /nas-app/, /nas-app/manifest.json, /nas-sw.js to WP rewrite map.
    //        Postcondition: WP recognises these URLs without 404.
    public function add_rewrite_rules(): void {
        add_rewrite_rule( '^nas-app/?$',             'index.php?nas_pwa=1', 'top' );
        add_rewrite_rule( '^nas-app/manifest\.json$','index.php?nas_pwa_manifest=1', 'top' );
        add_rewrite_rule( '^nas-sw\.js$',            'index.php?nas_pwa_sw=1', 'top' );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'nas_pwa'; $vars[] = 'nas_pwa_manifest'; $vars[] = 'nas_pwa_sw';
            return $vars;
        } );
    }

    // TRACE: maybe_serve_pwa() — Trigger: template_redirect when nas_pwa=1.
    //        Outputs PWA shell HTML (full page; bypasses WP theme entirely).
    public function maybe_serve_pwa(): void {
        if ( ! get_query_var('nas_pwa') ) return;
        status_header(200);
        nocache_headers();
        include NAS_DIR . 'templates/pwa/shell.php';
        exit;
    }

    // TRACE: maybe_serve_sw() — Trigger: template_redirect when nas_pwa_sw=1.
    //        Outputs service worker JS with correct MIME type and cache headers.
    public function maybe_serve_sw(): void {
        if ( ! get_query_var('nas_pwa_sw') ) return;
        header( 'Content-Type: application/javascript; charset=UTF-8' );
        header( 'Service-Worker-Allowed: /' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        readfile( NAS_DIR . 'assets/pwa/nas-sw.js' );
        exit;
    }

    // TRACE: maybe_serve_manifest() — Trigger: template_redirect when nas_pwa_manifest=1.
    //        Outputs Web App Manifest JSON for Add-to-Home-Screen.
    public function maybe_serve_manifest(): void {
        if ( ! get_query_var('nas_pwa_manifest') ) return;
        $cfg = Config::instance();
        $manifest = [
            'name'             => $cfg->get('brand_name', get_bloginfo('name')),
            'short_name'       => $cfg->get('brand_short_name', 'NAS App'),
            'description'      => $cfg->get('brand_tagline', 'Book Newspaper Ads Online'),
            'start_url'        => home_url('/nas-app/'),
            'scope'            => home_url('/'),
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0e1117',
            'theme_color'      => '#00d084',
            'lang'             => 'en',
            'icons'            => [
                [ 'src' => $cfg->get('pwa_icon_192', NAS_ASSETS . 'pwa/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable' ],
                [ 'src' => $cfg->get('pwa_icon_512', NAS_ASSETS . 'pwa/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' ],
            ],
            'shortcuts' => [
                [ 'name' => 'Book an Ad',    'url' => home_url('/nas-app/?screen=book'),     'description' => 'Book a newspaper ad' ],
                [ 'name' => 'My Bookings',   'url' => home_url('/nas-app/?screen=bookings'), 'description' => 'View my bookings' ],
                [ 'name' => 'Track Order',   'url' => home_url('/nas-app/?screen=track'),    'description' => 'Track an order' ],
            ],
            'categories'       => ['business', 'utilities'],
            'prefer_related_applications' => false,
        ];
        header( 'Content-Type: application/manifest+json; charset=UTF-8' );
        header( 'Cache-Control: public, max-age=3600' );
        echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        exit;
    }

    // TRACE: inject_pwa_meta() — Trigger: wp_head on every page.
    //        Injects manifest link, theme-color, apple-touch-icon, apple-mobile-web-app tags.
    //        Postcondition: browser shows "Add to Home Screen" prompt on all NAS pages.
    public function inject_pwa_meta(): void {
        $cfg     = Config::instance();
        $icon    = esc_url( $cfg->get('pwa_icon_192', NAS_ASSETS . 'pwa/icon-192.png') );
        $color   = esc_attr( $cfg->get('brand_primary_color','#00d084') );
        $name    = esc_attr( $cfg->get('brand_name', get_bloginfo('name')) );
        $manifest_url = esc_url( home_url('/nas-app/manifest.json') );
        echo "<link rel=\"manifest\" href=\"{$manifest_url}\">\n";
        echo "<meta name=\"theme-color\" content=\"{$color}\">\n";
        echo "<meta name=\"mobile-web-app-capable\" content=\"yes\">\n";
        echo "<meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
        echo "<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
        echo "<meta name=\"apple-mobile-web-app-title\" content=\"{$name}\">\n";
        echo "<link rel=\"apple-touch-icon\" href=\"{$icon}\">\n";
        echo "<script>
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/nas-sw.js', {scope:'/'})
      .then(r => console.log('[NAS SW] registered', r.scope))
      .catch(e => console.warn('[NAS SW] registration failed', e));
  });
}
</script>\n";
    }
}

// ── PWA Controller ─────────────────────────────────────────────────────────────
class PWAController {

    private static function db(): Database { return Database::instance(); }
    private static function cfg(): Config  { return Config::instance(); }

    // TRACE: home() — Trigger: wp_ajax[_nopriv]_nas_pwa_home.
    //        Steps: verifies nonce → queries categories, top newspapers, top cities, social proof counts.
    //        Output: JSON {categories, newspapers, cities, stats, featured}.
    //        Edge cases: empty DB → returns empty arrays; Cache::remember for 5min.
    public static function home(): void {
        Security::check_nonce( Security::post('nonce') ?: Security::get('nonce'), 'nas_action' );
        $db  = self::db();
        $cfg = self::cfg();

        $data = Cache::remember('nas_pwa_home', 300, function() use ($db, $cfg) {
            return [
                'brand'       => [
                    'name'     => $cfg->get('brand_name', get_bloginfo('name')),
                    'tagline'  => $cfg->get('brand_tagline','Book newspaper ads in minutes'),
                    'logo'     => $cfg->get('logo_url',''),
                    'color'    => $cfg->get('brand_primary_color','#00d084'),
                    'phone'    => $cfg->get('brand_phone',''),
                    'whatsapp' => $cfg->get('brand_whatsapp',''),
                ],
                'stats'       => [
                    'bookings'  => (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE status NOT IN ('cancelled','rejected')") ?? 0),
                    'newspapers'=> (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('newspapers')}` WHERE is_active=1") ?? 0),
                    'cities'    => (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('cities')}` WHERE is_active=1") ?? 0),
                    'rating'    => '4.8',
                ],
                'categories'  => $db->select("SELECT id, name, slug, icon, description FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC, name ASC LIMIT 12"),
                'newspapers'  => $db->select("SELECT id, name, slug, city_id, language, circulation, logo_url, base_rate FROM `{$db->t('newspapers')}` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 10"),
                'cities'      => $db->select("SELECT id, name, slug, state FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, name ASC LIMIT 20"),
                'featured'    => $db->select("SELECT n.id, n.name, n.logo_url, n.base_rate, c.name as city_name FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` c ON c.id=n.city_id WHERE n.is_active=1 AND n.is_featured=1 LIMIT 6"),
            ];
        });

        wp_send_json_success($data);
    }

    // TRACE: browse() — Trigger: wp_ajax[_nopriv]_nas_pwa_browse.
    //        Steps: nonce → reads category_id/city_id/search filters → paginated newspaper list + all categories.
    //        Output: JSON {categories, newspapers, total, pages}.
    public static function browse(): void {
        Security::check_nonce( Security::post('nonce') ?: Security::get('nonce'), 'nas_action' );
        $db     = self::db();
        $cat_id = (int) ( Security::post('category_id') ?: Security::get('category_id') );
        $city_id= (int) ( Security::post('city_id') ?: Security::get('city_id') );
        $search = sanitize_text_field( Security::post('search') ?: Security::get('search') );
        $page   = max(1, (int)( Security::post('page') ?: Security::get('page') ?: 1));
        $per    = 12;
        $offset = ($page-1)*$per;

        $where = ['1=1'];
        $params = [];
        if ($cat_id) { $where[] = "n.id IN (SELECT newspaper_id FROM `{$db->t('newspaper_categories')}` WHERE category_id=%d)"; $params[] = $cat_id; }
        if ($city_id){ $where[] = 'n.city_id=%d'; $params[] = $city_id; }
        if ($search) { $where[] = '(n.name LIKE %s OR ci.name LIKE %s)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        $w = implode(' AND ', $where);

        $sql_base = "FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` ci ON ci.id=n.city_id WHERE n.is_active=1 AND $w";
        $total = (int) ($db->scalar("SELECT COUNT(*) $sql_base", ...$params) ?? 0);
        $items = $db->select("SELECT n.id,n.name,n.slug,n.logo_url,n.base_rate,n.language,n.circulation,ci.name as city_name,ci.state $sql_base ORDER BY n.sort_order ASC, n.name ASC LIMIT $per OFFSET $offset", ...$params);
        $cats  = $db->select("SELECT id,name,slug,icon FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC LIMIT 16");
        $cities= $db->select("SELECT id,name,state FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC, name ASC LIMIT 30");

        wp_send_json_success(['categories'=>$cats,'newspapers'=>$items,'cities'=>$cities,'total'=>$total,'pages'=>max(1,ceil($total/$per)),'page'=>$page]);
    }

    // TRACE: newspaper_detail() — Trigger: wp_ajax[_nopriv]_nas_pwa_newspaper.
    //        Steps: nonce → fetch newspaper by id/slug → rates matrix → booking_url.
    public static function newspaper_detail(): void {
        Security::check_nonce( Security::post('nonce') ?: Security::get('nonce'), 'nas_action' );
        $db  = self::db();
        $id  = (int)( Security::post('id') ?: Security::get('id') );
        $slug= sanitize_title( Security::post('slug') ?: Security::get('slug') );
        $np  = $id
            ? $db->row("SELECT n.*,ci.name as city_name,ci.state FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` ci ON ci.id=n.city_id WHERE n.id=%d AND n.is_active=1", $id)
            : $db->row("SELECT n.*,ci.name as city_name,ci.state FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` ci ON ci.id=n.city_id WHERE n.slug=%s AND n.is_active=1", $slug);
        if (!$np) { wp_send_json_error(['message'=>'Newspaper not found'],404); return; }
        $rates = $db->select("SELECT * FROM `{$db->t('rates')}` WHERE newspaper_id=%d ORDER BY category_id ASC, ad_type ASC", (int)$np['id']);
        wp_send_json_success(['newspaper'=>$np,'rates'=>$rates,'booking_url'=>nas_get_page_url('nas_page_booking','/book-newspaper-ad/').'?np='.$np['id']]);
    }

    // TRACE: search() — Trigger: wp_ajax[_nopriv]_nas_pwa_search.
    //        Steps: nonce → search newspapers+categories+cities matching query.
    //        Edge case: empty query → returns [].
    public static function search(): void {
        Security::check_nonce( Security::post('nonce') ?: Security::get('nonce'), 'nas_action' );
        $q  = sanitize_text_field( Security::post('q') ?: Security::get('q') );
        if ( strlen($q) < 2 ) { wp_send_json_success(['results'=>[]]); return; }
        $db = self::db();
        $like = "%$q%";
        $newspapers = $db->select("SELECT id,name,slug,logo_url,base_rate,'newspaper' as type FROM `{$db->t('newspapers')}` WHERE is_active=1 AND name LIKE %s LIMIT 5", $like);
        $categories = $db->select("SELECT id,name,slug,icon,'category' as type FROM `{$db->t('categories')}` WHERE is_active=1 AND name LIKE %s LIMIT 4", $like);
        $cities     = $db->select("SELECT id,name,slug,state,'city' as type FROM `{$db->t('cities')}` WHERE is_active=1 AND name LIKE %s LIMIT 4", $like);
        wp_send_json_success(['results'=>array_merge($newspapers,$categories,$cities)]);
    }

    // TRACE: bookings() — Trigger: wp_ajax_nas_pwa_bookings (auth only).
    //        Steps: nonce → login check → fetch client bookings paginated.
    public static function bookings(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $db     = self::db();
        $uid    = get_current_user_id();
        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_success(['bookings'=>[],'total'=>0,'pages'=>0]); return; }
        $page  = max(1,(int)Security::post('page',1));
        $per   = 10;
        $offset= ($page-1)*$per;
        $cid   = (int)$client['id'];
        $total = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE client_id=%d", $cid) ?? 0);
        $items = $db->select("SELECT b.id,b.uid,b.status,b.payment_status,b.total_amount,b.submitted_at,b.publish_date,n.name as newspaper_name,n.logo_url as newspaper_logo,cat.name as category_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id LEFT JOIN `{$db->t('categories')}` cat ON cat.id=b.category_id WHERE b.client_id=%d ORDER BY b.submitted_at DESC LIMIT $per OFFSET $offset", $cid);
        wp_send_json_success(['bookings'=>$items,'total'=>$total,'pages'=>max(1,ceil($total/$per))]);
    }

    // TRACE: account() — Trigger: wp_ajax_nas_pwa_account (auth only).
    //        Steps: nonce → login check → return client profile + wallet balance.
    public static function account(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $db     = self::db();
        $uid    = get_current_user_id();
        $wp_u   = wp_get_current_user();
        $client = $db->row("SELECT * FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        $wallet_bal = $client ? (float)($db->scalar("SELECT COALESCE(SUM(CASE WHEN txn_type='credit' THEN amount ELSE -amount END),0) FROM `{$db->t('wallet_transactions')}` WHERE client_id=%d", (int)$client['id']) ?? 0) : 0;
        wp_send_json_success(['user'=>['id'=>$uid,'name'=>$wp_u->display_name,'email'=>$wp_u->user_email],'client'=>$client,'wallet_balance'=>$wallet_bal,'is_logged_in'=>true]);
    }

    // TRACE: notif_count() — Trigger: wp_ajax_nas_pwa_notif_count (auth only).
    //        Steps: nonce → login → count unread messages + pending payment bookings.
    public static function notif_count(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $db  = self::db();
        $uid = get_current_user_id();
        $cl  = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        $count = 0;
        if ($cl) {
            $cid = (int)$cl['id'];
            $count += (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('messages')}` m JOIN `{$db->t('bookings')}` b ON b.id=m.booking_id WHERE b.client_id=%d AND m.is_read=0 AND m.sender_role != 'client'", $cid) ?? 0);
            $count += (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE client_id=%d AND payment_status='pending' AND status NOT IN ('cancelled','rejected')", $cid) ?? 0);
        }
        wp_send_json_success(['count'=>$count]);
    }

    // TRACE: login() — Trigger: wp_ajax_nopriv_nas_pwa_login (public).
    //        Steps: nonce → wp_signon with remember=true → return user data or error.
    //        Edge cases: bad creds → error; account blocked → error.
    public static function login(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        $email    = sanitize_email( Security::post('email') );
        $password = Security::post('password');
        if (!$email || !$password) { wp_send_json_error(['message'=>'Email and password are required.']); return; }
        $result = wp_signon(['user_login'=>$email,'user_password'=>$password,'remember'=>true], is_ssl());
        if (is_wp_error($result)) {
            wp_send_json_error(['message'=>__('Incorrect email or password. Please try again.','nas')]);
            return;
        }
        // Check if client is blocked
        $db     = Database::instance();
        $client = $db->row("SELECT status FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $result->ID);
        if ($client && ($client['status'] ?? '') === 'blocked') {
            wp_logout();
            wp_send_json_error(['message'=>'Your account has been suspended. Please contact support.']);
            return;
        }
        wp_send_json_success(['user_id'=>$result->ID,'name'=>$result->display_name,'email'=>$result->user_email,'redirect'=>home_url('/nas-app/?screen=bookings')]);
    }

    // TRACE: push_subscribe() — Trigger: wp_ajax_nas_pwa_push_subscribe (auth).
    //        Steps: nonce → login → store push subscription endpoint in client meta.
    public static function push_subscribe(): void {
        Security::check_nonce( Security::post('nonce'), 'nas_action' );
        Security::require_login();
        $sub = json_decode( Security::post('subscription'), true );
        if (!$sub || empty($sub['endpoint'])) { wp_send_json_error(['message'=>'Invalid subscription.']); return; }
        update_user_meta( get_current_user_id(), 'nas_push_subscription', wp_json_encode($sub) );
        wp_send_json_success(['message'=>'Push notifications enabled.']);
    }
}

// ── PWA Admin Settings ────────────────────────────────────────────────────
class PWASettings {

    // TRACE: register_ajax() — Trigger: called from PWAModule::register().
    //        Steps: registers wp_ajax actions for saving + getting PWA theme settings.
    //        Postcondition: admin can call nas_pwa_save_settings / nas_pwa_get_settings.
    public static function register(): void {
        add_action( 'wp_ajax_nas_pwa_save_settings', [ self::class, 'save' ] );
        add_action( 'wp_ajax_nas_pwa_get_settings',  [ self::class, 'get_settings' ] );
    }

    // TRACE: save() — Trigger: wp_ajax_nas_pwa_save_settings. Admin only.
    //        Steps: nonce → manage_options cap → sanitize all theme fields →
    //               save to nas_settings via Config::save_many() →
    //               rebuild theme CSS → return {theme_css, palette} for instant JS apply.
    //        Postcondition: DB updated; response contains new :root{} CSS for instant apply.
    //        Edge cases: invalid hex → sanitize_hex_color returns '' → default used.
    public static function save(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_admin_nonce' );
        \NAS\Core\Security::require_cap( 'manage_options' );

        $valid_themes  = array_keys( NASTheme::palettes() );
        $valid_presets = array_column( NASTheme::bg_presets(), 'key' );

        $theme    = in_array( \NAS\Core\Security::post('pwa_theme'), $valid_themes, true )
                    ? \NAS\Core\Security::post('pwa_theme') : 'green';
        $bg       = sanitize_hex_color( \NAS\Core\Security::post('pwa_portal_bg') )     ?: '#0e1117';
        $preset   = in_array( \NAS\Core\Security::post('pwa_bg_preset'), $valid_presets, true )
                    ? \NAS\Core\Security::post('pwa_bg_preset') : 'dark';
        $accent   = sanitize_hex_color( \NAS\Core\Security::post('pwa_accent_color') )  ?: '';
        $btn_color= sanitize_hex_color( \NAS\Core\Security::post('pwa_button_color') )  ?: '';

        // Persist to DB via Config (NAS settings system)
        $cfg = \NAS\Core\Config::instance();
        $site_wide = (int)(\NAS\Core\Security::post('nas_pwa_site_wide') ?: 0);
        $icon_192  = esc_url_raw( \NAS\Core\Security::post('pwa_icon_192') ?: '' );
        $icon_512  = esc_url_raw( \NAS\Core\Security::post('pwa_icon_512') ?: '' );
        $vapid_pub = sanitize_text_field( \NAS\Core\Security::post('vapid_public_key') ?: '' );
        $vapid_prv = sanitize_text_field( \NAS\Core\Security::post('vapid_private_key') ?: '' );

        $cfg->save_many([
            'nas_pwa_site_wide' => $site_wide ? 1 : 0,
            'pwa_theme'         => $theme,
            'pwa_portal_bg'     => $bg,
            'pwa_bg_preset'     => $preset,
            'pwa_accent_color'  => $accent,
            'pwa_button_color'  => $btn_color,
            'pwa_icon_192'      => $icon_192,
            'pwa_icon_512'      => $icon_512,
        ]);
        if ( $vapid_pub ) $cfg->save_many(['vapid_public_key'=>$vapid_pub]);
        if ( $vapid_prv ) $cfg->save_many(['vapid_private_key'=>$vapid_prv]);

        // Return rebuilt theme CSS and palette so JS can applyTheme() instantly
        // without a page reload — same pattern as marketplace-os admin settings save
        $theme_css = NASTheme::build_theme_css( $theme, $bg, $accent, $btn_color );
        $palette   = NASTheme::palette($theme);
        if ($accent) $palette['primary'] = $accent;

        wp_send_json_success([
            'message'   => 'PWA theme settings saved.',
            'theme_css' => $theme_css,
            'palette'   => $palette,
            'theme'     => $theme,
            'portal_bg' => $bg,
            'accent'    => $accent ?: $palette['primary'],
            'btn_color' => $btn_color ?: $palette['primary'],
        ]);
    }

    // TRACE: get_settings() — Trigger: wp_ajax_nas_pwa_get_settings. Admin only.
    //        Returns current PWA theme settings for the admin settings form.
    public static function get_settings(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_admin_nonce' );
        \NAS\Core\Security::require_cap( 'manage_options' );
        $cfg = \NAS\Core\Config::instance();
        wp_send_json_success([
            'pwa_theme'        => $cfg->get('pwa_theme',        'green'),
            'pwa_portal_bg'    => $cfg->get('pwa_portal_bg',    '#0e1117'),
            'pwa_bg_preset'    => $cfg->get('pwa_bg_preset',    'dark'),
            'pwa_accent_color' => $cfg->get('pwa_accent_color', ''),
            'pwa_button_color' => $cfg->get('pwa_button_color', ''),
            'themes'           => NASTheme::palettes(),
            'bg_presets'       => NASTheme::bg_presets(),
        ]);
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 2: In-PWA Client Portal endpoints
// ══════════════════════════════════════════════════════════════════
class PWAPhase2Controller {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    // TRACE: booking_detail() — Trigger: wp_ajax_nas_pwa_booking_detail (auth).
    //        Steps: nonce → login → ownership check (client_id=own) →
    //               fetch booking + newspaper + messages + workflow_history + materials + invoice.
    //        Output: JSON {booking, messages, history, materials, invoice_url}.
    //        Edge cases: not found / not owner → 404; no messages → []; no invoice → null.
    public static function booking_detail(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $uid = get_current_user_id();
        $bid = (int)\NAS\Core\Security::post('booking_id');
        $buid= sanitize_text_field( \NAS\Core\Security::post('uid') );

        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_error(['message'=>'Client not found'], 404); return; }
        $cid = (int)$client['id'];

        // Fetch booking (ownership enforced: client_id must match)
        $booking = $bid
            ? $db->row("SELECT b.*,n.name as np_name,n.logo_url as np_logo,n.language as np_lang,ci.name as city_name,cat.name as cat_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id LEFT JOIN `{$db->t('cities')}` ci ON ci.id=b.city_id LEFT JOIN `{$db->t('categories')}` cat ON cat.id=b.category_id WHERE b.id=%d AND b.client_id=%d", $bid, $cid)
            : $db->row("SELECT b.*,n.name as np_name,n.logo_url as np_logo,n.language as np_lang,ci.name as city_name,cat.name as cat_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id LEFT JOIN `{$db->t('cities')}` ci ON ci.id=b.city_id LEFT JOIN `{$db->t('categories')}` cat ON cat.id=b.category_id WHERE b.uid=%s AND b.client_id=%d", $buid, $cid);

        if (!$booking) { wp_send_json_error(['message'=>'Booking not found'], 404); return; }

        $actual_bid = (int)$booking['id'];

        $messages = $db->select("SELECT m.*,u.display_name as sender_name FROM `{$db->t('messages')}` m LEFT JOIN {$db->prefix()}users u ON u.ID=m.sender_id WHERE m.booking_id=%d AND (m.is_internal=0 OR m.is_internal IS NULL) ORDER BY m.created_at ASC LIMIT 100", $actual_bid);
        $history  = $db->select("SELECT * FROM `{$db->t('booking_workflow_history')}` WHERE booking_id=%d ORDER BY created_at ASC", $actual_bid);
        $materials= $db->select("SELECT * FROM `{$db->t('ad_materials')}` WHERE booking_id=%d ORDER BY uploaded_at DESC", $actual_bid);
        $invoice  = $db->row("SELECT file_path,file_url FROM `{$db->t('invoices')}` WHERE booking_id=%d ORDER BY id DESC LIMIT 1", $actual_bid);

        // Mark messages as read
        $db->query("UPDATE `{$db->t('messages')}` SET is_read=1 WHERE booking_id=%d AND sender_role != 'client'", $actual_bid);

        wp_send_json_success([
            'booking'     => $booking,
            'messages'    => $messages ?: [],
            'history'     => $history  ?: [],
            'materials'   => $materials?: [],
            'invoice_url' => $invoice ? ($invoice['file_url'] ?: '') : null,
        ]);
    }

    // TRACE: send_message() — Trigger: wp_ajax_nas_pwa_send_message (auth).
    //        Steps: nonce → login → ownership → insert message → return new message.
    //        Edge cases: empty message → 400; not owner → 403.
    public static function send_message(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db      = self::db();
        $uid     = get_current_user_id();
        $bid     = (int)\NAS\Core\Security::post('booking_id');
        $message = sanitize_textarea_field( \NAS\Core\Security::post('message') );
        if (!$message) { wp_send_json_error(['message'=>'Message cannot be empty']); return; }

        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $cid = (int)$client['id'];

        $owns = $db->scalar("SELECT id FROM `{$db->t('bookings')}` WHERE id=%d AND client_id=%d", $bid, $cid);
        if (!$owns) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }

        $now = gmdate('Y-m-d H:i:s');
        $db->insert($db->t('messages'), [
            'booking_id'  => $bid,
            'sender_id'   => $uid,
            'sender_role' => 'client',
            'message'     => $message,
            'is_read'     => 0,
            'is_internal' => 0,
            'created_at'  => $now,
        ]);
        $msg_id = $db->last_insert_id();
        wp_send_json_success(['message' => ['id'=>$msg_id,'booking_id'=>$bid,'message'=>$message,'sender_role'=>'client','sender_name'=>wp_get_current_user()->display_name,'created_at'=>$now,'is_read'=>0]]);
    }

    // TRACE: upload_material() — Trigger: wp_ajax_nas_pwa_upload_material (auth).
    //        Steps: nonce → login → ownership → wp_handle_upload → insert ad_materials row.
    //        Edge cases: no file → 400; oversized → 400; wrong type → 400; not owner → 403.
    public static function upload_material(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $uid = get_current_user_id();
        $bid = (int)\NAS\Core\Security::post('booking_id');
        if (empty($_FILES['material'])) { wp_send_json_error(['message'=>'No file uploaded']); return; }

        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $cid = (int)$client['id'];
        $owns = $db->scalar("SELECT id FROM `{$db->t('bookings')}` WHERE id=%d AND client_id=%d", $bid, $cid);
        if (!$owns) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }

        $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $result  = wp_handle_upload($_FILES['material'], ['test_form'=>false,'mimes'=>array_fill_keys(['jpg','jpeg','png','gif','webp','pdf','doc','docx'], true)]);
        if (isset($result['error'])) { wp_send_json_error(['message'=>$result['error']]); return; }

        $db->insert($db->t('ad_materials'), [
            'booking_id'  => $bid,
            'file_url'    => $result['url'],
            'file_path'   => $result['file'],
            'file_name'   => basename($result['file']),
            'file_type'   => $result['type'],
            'status'      => 'pending',
            'uploaded_at' => gmdate('Y-m-d H:i:s'),
            'uploaded_by' => $uid,
        ]);
        wp_send_json_success(['url'=>$result['url'],'name'=>basename($result['file']),'type'=>$result['type']]);
    }

    // TRACE: track_order() — Trigger: wp_ajax[_nopriv]_nas_pwa_track_order (public).
    //        Steps: nonce → sanitise uid+email → JOIN booking+client → return timeline.
    //        Edge cases: not found → 404; email mismatch → 404.
    public static function track_order(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db    = self::db();
        $uid   = strtoupper(sanitize_text_field( \NAS\Core\Security::post('uid') ));
        $email = sanitize_email( \NAS\Core\Security::post('email') );
        if (!$uid || !$email) { wp_send_json_error(['message'=>'Booking ID and email are required']); return; }

        $booking = $db->row("SELECT b.*,n.name as np_name,cat.name as cat_name,cl.email as client_email FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id LEFT JOIN `{$db->t('categories')}` cat ON cat.id=b.category_id LEFT JOIN `{$db->t('clients')}` cl ON cl.id=b.client_id WHERE b.uid=%s", $uid);
        if (!$booking || strtolower($booking['client_email']) !== strtolower($email)) {
            wp_send_json_error(['message'=>'No booking found with that ID and email.']); return;
        }
        $history = $db->select("SELECT * FROM `{$db->t('booking_workflow_history')}` WHERE booking_id=%d ORDER BY created_at ASC", (int)$booking['id']);
        wp_send_json_success(['booking'=>$booking,'history'=>$history?:[]]);
    }

    // TRACE: cancel_booking() — Trigger: wp_ajax_nas_pwa_cancel_booking (auth).
    //        Steps: nonce → login → ownership → cancellation window check → update status.
    //        Edge cases: already cancelled → 400; outside window → 400.
    public static function cancel_booking(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $uid = get_current_user_id();
        $bid = (int)\NAS\Core\Security::post('booking_id');

        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $cid = (int)$client['id'];

        $booking = $db->row("SELECT * FROM `{$db->t('bookings')}` WHERE id=%d AND client_id=%d", $bid, $cid);
        if (!$booking) { wp_send_json_error(['message'=>'Booking not found'],404); return; }
        if (in_array($booking['status'],['cancelled','rejected','published','completed'])) {
            wp_send_json_error(['message'=>'This booking cannot be cancelled.']); return;
        }
        if (in_array($booking['status'],['payment_received','ad_processing','proof_ready','submitted_to_pub'])) {
            wp_send_json_error(['message'=>'Booking is already in processing. Contact support to cancel.']); return;
        }

        $db->query("UPDATE `{$db->t('bookings')}` SET status='cancelled', updated_at=%s WHERE id=%d", gmdate('Y-m-d H:i:s'), $bid);
        $db->insert($db->t('booking_workflow_history'),['booking_id'=>$bid,'from_status'=>$booking['status'],'to_status'=>'cancelled','changed_by'=>$uid,'note'=>'Cancelled by client via mobile app','created_at'=>gmdate('Y-m-d H:i:s')]);
        wp_send_json_success(['message'=>'Booking cancelled successfully.']);
    }

    // TRACE: get_messages() — Trigger: wp_ajax_nas_pwa_get_messages (auth) for polling.
    //        Steps: nonce → login → ownership → fetch messages since last_id → mark read.
    public static function get_messages(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db      = self::db();
        $uid     = get_current_user_id();
        $bid     = (int)\NAS\Core\Security::post('booking_id');
        $last_id = (int)\NAS\Core\Security::post('last_id');

        $client = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE wp_user_id=%d", $uid);
        if (!$client) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $cid   = (int)$client['id'];
        $owns  = $db->scalar("SELECT id FROM `{$db->t('bookings')}` WHERE id=%d AND client_id=%d", $bid, $cid);
        if (!$owns) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }

        $messages = $db->select("SELECT m.*,u.display_name as sender_name FROM `{$db->t('messages')}` m LEFT JOIN {$db->prefix()}users u ON u.ID=m.sender_id WHERE m.booking_id=%d AND m.id>%d AND (m.is_internal=0 OR m.is_internal IS NULL) ORDER BY m.created_at ASC", $bid, $last_id);
        if ($messages) {
            $db->query("UPDATE `{$db->t('messages')}` SET is_read=1 WHERE booking_id=%d AND sender_role != 'client'", $bid);
        }
        wp_send_json_success(['messages'=>$messages?:[]]);
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 3: In-PWA Booking Wizard endpoints
// ══════════════════════════════════════════════════════════════════
class PWAPhase3Controller {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    // TRACE: wizard_data() — Trigger: wp_ajax[_nopriv]_nas_pwa_wizard_data.
    //        Returns all data needed for wizard bootstrap: categories, cities, newspapers.
    public static function wizard_data(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db   = self::db();
        $cats = $db->select("SELECT id,name,slug,icon FROM `{$db->t('categories')}` WHERE is_active=1 ORDER BY sort_order ASC");
        $cities = $db->select("SELECT id,name,state FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC,name ASC LIMIT 50");
        wp_send_json_success(['categories'=>$cats?:[],'cities'=>$cities?:[]]);
    }

    // TRACE: wizard_newspapers() — Trigger: wp_ajax[_nopriv]_nas_pwa_wizard_newspapers.
    //        Returns newspapers filtered by category_id + city_id + search.
    public static function wizard_newspapers(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db     = self::db();
        $cat_id = (int)\NAS\Core\Security::post('category_id');
        $city_id= (int)\NAS\Core\Security::post('city_id');
        $search = sanitize_text_field( \NAS\Core\Security::post('search') );
        $where  = ['n.is_active=1'];
        $params = [];
        if ($cat_id) { $where[]='n.id IN (SELECT newspaper_id FROM `'.$db->t('newspaper_categories').'` WHERE category_id=%d)'; $params[]=$cat_id; }
        if ($city_id){ $where[]='n.city_id=%d'; $params[]=$city_id; }
        if ($search) { $where[]='n.name LIKE %s'; $params[]="%$search%"; }
        $w = implode(' AND ',$where);
        $papers = $db->select("SELECT n.id,n.name,n.logo_url,n.base_rate,n.language,n.circulation,ci.name as city_name FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` ci ON ci.id=n.city_id WHERE $w ORDER BY n.sort_order ASC LIMIT 30", ...$params);
        wp_send_json_success(['newspapers'=>$papers?:[]]);
    }

    // TRACE: wizard_rates() — Trigger: wp_ajax[_nopriv]_nas_pwa_wizard_rates.
    //        Returns rate matrix for a newspaper — used for ad type selection + price preview.
    public static function wizard_rates(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db  = self::db();
        $npid= (int)\NAS\Core\Security::post('newspaper_id');
        if (!$npid) { wp_send_json_error(['message'=>'Newspaper ID required']); return; }
        $np    = $db->row("SELECT * FROM `{$db->t('newspapers')}` WHERE id=%d AND is_active=1", $npid);
        $rates = $db->select("SELECT * FROM `{$db->t('rates')}` WHERE newspaper_id=%d ORDER BY category_id ASC,ad_type ASC", $npid);
        wp_send_json_success(['newspaper'=>$np,'rates'=>$rates?:[]]);
    }

    // TRACE: wizard_calculate() — Trigger: wp_ajax[_nopriv]_nas_pwa_wizard_calculate.
    //        Calculates price from newspaper_id + category_id + ad_type + word_count + dimensions.
    //        Returns {base,gst,total,gst_pct,breakdown}.
    public static function wizard_calculate(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db      = self::db();
        $np_id   = (int)\NAS\Core\Security::post('newspaper_id');
        $cat_id  = (int)\NAS\Core\Security::post('category_id');
        $ad_type = sanitize_text_field( \NAS\Core\Security::post('ad_type') );
        $words   = (int)\NAS\Core\Security::post('word_count');
        $width   = (float)\NAS\Core\Security::post('width_cm');
        $height  = (float)\NAS\Core\Security::post('height_cm');

        $rate = $db->row("SELECT * FROM `{$db->t('rates')}` WHERE newspaper_id=%d AND category_id=%d AND ad_type=%s LIMIT 1", $np_id, $cat_id, $ad_type);
        if (!$rate) {
            // Fallback to newspaper base rate
            $np = $db->row("SELECT base_rate FROM `{$db->t('newspapers')}` WHERE id=%d", $np_id);
            $base = $np ? (float)$np['base_rate'] : 0;
        } else {
            $unit_rate = (float)$rate['rate_per_unit'];
            if (in_array($ad_type,['classified_word','matrimonial_word'])) {
                $base = $unit_rate * max(1,$words);
            } elseif (in_array($ad_type,['display_cm','classified_display'])) {
                $area = max(1,$width) * max(1,$height);
                $base = $unit_rate * $area;
            } else {
                $base = $unit_rate;
            }
        }
        $gst_pct = 5; // Standard 5% GST on ad services
        $gst     = round($base * ($gst_pct/100), 2);
        $total   = round($base + $gst, 2);
        wp_send_json_success(['base'=>$base,'gst'=>$gst,'gst_pct'=>$gst_pct,'total'=>$total,'breakdown'=>['rate_used'=>$rate?:null,'word_count'=>$words,'dimensions'=>['w'=>$width,'h'=>$height]]]);
    }

    // TRACE: wizard_submit() — Trigger: wp_ajax[_nopriv]_nas_pwa_wizard_submit.
    //        Steps: nonce → validate required → DB transaction → create/upsert client →
    //               insert booking → EventBus emit → return {booking_uid, payment_url}.
    //        Edge cases: rate limit; idempotency key; missing required fields → 400.
    public static function wizard_submit(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db = self::db();

        // Rate limiting
        \NAS\Core\RateLimit::check('pwa_booking', $_SERVER['REMOTE_ADDR']??'', 5, 3600);

        // Required fields
        $required = ['newspaper_id','category_id','ad_type','ad_title','ad_content','publish_date','client_name','client_email','client_phone'];
        $data = [];
        foreach($required as $f){
            $val = \NAS\Core\Security::post($f);
            if (empty($val)) { wp_send_json_error(['message'=>"Field '{$f}' is required."]); return; }
            $data[$f] = sanitize_text_field($val);
        }

        // Sanitise all fields
        $np_id    = (int)$data['newspaper_id'];
        $cat_id   = (int)$data['category_id'];
        $ad_type  = $data['ad_type'];
        $title    = $data['ad_title'];
        $content  = sanitize_textarea_field( \NAS\Core\Security::post('ad_content') );
        $pub_date = $data['publish_date'];
        $name     = $data['client_name'];
        $email    = sanitize_email($data['client_email']);
        $phone    = $data['client_phone'];
        $city_id  = (int)\NAS\Core\Security::post('city_id');
        $company  = sanitize_text_field( \NAS\Core\Security::post('client_company') );
        $notes    = sanitize_textarea_field( \NAS\Core\Security::post('client_notes') );
        $coupon   = sanitize_text_field( \NAS\Core\Security::post('coupon_code') );
        $total    = (float)\NAS\Core\Security::post('total_amount');
        $base     = (float)\NAS\Core\Security::post('base_amount');
        $gst      = (float)\NAS\Core\Security::post('gst_amount');
        $uid      = strtoupper('NAS'.date('ymd').wp_rand(1000,9999));

        $now = gmdate('Y-m-d H:i:s');
        $client_id = 0;
        $booking_id = 0;

        try {
            $db->transaction(function() use ($db,$now,$name,$email,$phone,$company,$city_id,$np_id,$cat_id,$ad_type,$title,$content,$pub_date,$notes,$coupon,$total,$base,$gst,$uid,&$client_id,&$booking_id) {
                // Upsert client
                $existing = $db->row("SELECT id FROM `{$db->t('clients')}` WHERE email=%s", $email);
                if ($existing) {
                    $client_id = (int)$existing['id'];
                } else {
                    $db->insert($db->t('clients'),['name'=>$name,'email'=>$email,'phone'=>$phone,'company'=>$company,'city_id'=>$city_id,'status'=>'active','created_at'=>$now,'updated_at'=>$now]);
                    $client_id = $db->last_insert_id();
                }
                // Insert booking
                $db->insert($db->t('bookings'),[
                    'uid'          => $uid,
                    'client_id'    => $client_id,
                    'newspaper_id' => $np_id,
                    'category_id'  => $cat_id,
                    'city_id'      => $city_id,
                    'ad_type'      => $ad_type,
                    'ad_title'     => $title,
                    'ad_content'   => $content,
                    'publish_date' => $pub_date,
                    'base_amount'  => $base,
                    'gst_amount'   => $gst,
                    'total_amount' => $total,
                    'discount_amount'=> 0,
                    'coupon_code'  => $coupon,
                    'status'       => 'booking_received',
                    'payment_status'=> 'pending',
                    'notes'        => $notes,
                    'source'       => 'pwa',
                    'submitted_at' => $now,
                    'updated_at'   => $now,
                ]);
                $booking_id = $db->last_insert_id();
                $db->insert($db->t('booking_workflow_history'),['booking_id'=>$booking_id,'from_status'=>'','to_status'=>'booking_received','changed_by'=>0,'note'=>'Booking submitted via PWA','created_at'=>$now]);
            });
        } catch (\Throwable $e) {
            \NAS\Core\ErrorLogger::error('PWA wizard submit failed', ['error'=>$e->getMessage()]);
            wp_send_json_error(['message'=>'Booking could not be saved. Please try again.']);
            return;
        }

        // Fire events
        \NAS\Core\EventBus::emit('booking_created', ['booking_id'=>$booking_id,'uid'=>$uid,'client_id'=>$client_id]);

        $payment_url = nas_get_page_url('nas_page_payment','/payment/') . '?uid=' . $uid;
        wp_send_json_success(['uid'=>$uid,'booking_id'=>$booking_id,'payment_url'=>$payment_url,'message'=>'Booking received! Check your email for confirmation.']);
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 4: Vendor PWA endpoints
// ══════════════════════════════════════════════════════════════════
class PWAVendorController {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    private static function get_vendor_id(): int {
        $db  = self::db();
        $uid = get_current_user_id();
        $v   = $db->row("SELECT id FROM `{$db->t('vendors')}` WHERE wp_user_id=%d AND status='active'", $uid);
        return $v ? (int)$v['id'] : 0;
    }

    // TRACE: vendor_bookings() — auth vendor → paginated assigned bookings.
    public static function vendor_bookings(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $vid = self::get_vendor_id();
        if (!$vid) { wp_send_json_error(['message'=>'Vendor account not found'],403); return; }
        $page   = max(1,(int)\NAS\Core\Security::post('page',1));
        $status = sanitize_text_field( \NAS\Core\Security::post('status') );
        $search = sanitize_text_field( \NAS\Core\Security::post('search') );
        $per    = 10; $offset = ($page-1)*$per;
        $where  = ['b.assigned_vendor_id=%d'];
        $params = [$vid];
        if ($status) { $where[]='b.status=%s'; $params[]=$status; }
        if ($search) { $where[]='(b.uid LIKE %s OR n.name LIKE %s)'; $params[]="%$search%"; $params[]="%$search%"; }
        $w = implode(' AND ',$where);
        $total = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id WHERE $w", ...$params)??0);
        $items = $db->select("SELECT b.id,b.uid,b.status,b.total_amount,b.publish_date,b.submitted_at,b.ad_title,b.ad_type,n.name as np_name,cat.name as cat_name,ci.name as city_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id LEFT JOIN `{$db->t('categories')}` cat ON cat.id=b.category_id LEFT JOIN `{$db->t('cities')}` ci ON ci.id=b.city_id WHERE $w ORDER BY b.submitted_at DESC LIMIT $per OFFSET $offset", ...$params);
        wp_send_json_success(['bookings'=>$items?:[],'total'=>$total,'pages'=>max(1,ceil($total/$per)),'page'=>$page]);
    }

    // TRACE: vendor_stats() — auth vendor → counts and earnings summary.
    public static function vendor_stats(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $vid = self::get_vendor_id();
        if (!$vid) { wp_send_json_error(['message'=>'Vendor not found'],403); return; }
        $total    = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d",$vid)??0);
        $active   = (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d AND status NOT IN ('completed','cancelled','rejected')",$vid)??0);
        $completed= (int)($db->scalar("SELECT COUNT(*) FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d AND status='completed'",$vid)??0);
        $earnings = (float)($db->scalar("SELECT COALESCE(SUM(total_amount),0) FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d AND status='completed'",$vid)??0);
        wp_send_json_success(['total'=>$total,'active'=>$active,'completed'=>$completed,'earnings'=>$earnings]);
    }

    // TRACE: vendor_mark_published() — auth vendor → mark booking as published.
    public static function vendor_mark_published(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $vid = self::get_vendor_id();
        if (!$vid) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $bid = (int)\NAS\Core\Security::post('booking_id');
        $owns = $db->scalar("SELECT id FROM `{$db->t('bookings')}` WHERE id=%d AND assigned_vendor_id=%d",$bid,$vid);
        if (!$owns) { wp_send_json_error(['message'=>'Booking not found'],404); return; }
        $now = gmdate('Y-m-d H:i:s');
        $db->query("UPDATE `{$db->t('bookings')}` SET status='published',updated_at=%s WHERE id=%d",$now,$bid);
        $db->insert($db->t('booking_workflow_history'),['booking_id'=>$bid,'from_status'=>'submitted_to_pub','to_status'=>'published','changed_by'=>get_current_user_id(),'note'=>'Marked as published via vendor PWA','created_at'=>$now]);
        \NAS\Core\EventBus::emit('booking_status_changed',['booking_id'=>$bid,'status'=>'published']);
        wp_send_json_success(['message'=>'Booking marked as published.']);
    }

    // TRACE: vendor_upload_proof() — auth vendor → upload publication proof image.
    public static function vendor_upload_proof(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $vid = self::get_vendor_id();
        if (!$vid) { wp_send_json_error(['message'=>'Unauthorised'],403); return; }
        $bid = (int)\NAS\Core\Security::post('booking_id');
        $owns = $db->scalar("SELECT id FROM `{$db->t('bookings')}` WHERE id=%d AND assigned_vendor_id=%d",$bid,$vid);
        if (!$owns) { wp_send_json_error(['message'=>'Booking not found'],404); return; }
        if (empty($_FILES['proof'])) { wp_send_json_error(['message'=>'No file uploaded']); return; }
        $result = wp_handle_upload($_FILES['proof'],['test_form'=>false]);
        if (isset($result['error'])) { wp_send_json_error(['message'=>$result['error']]); return; }
        $db->query("UPDATE `{$db->t('bookings')}` SET proof_url=%s,status='submitted_to_pub',updated_at=%s WHERE id=%d",$result['url'],gmdate('Y-m-d H:i:s'),$bid);
        $db->insert($db->t('booking_workflow_history'),['booking_id'=>$bid,'from_status'=>'ad_processing','to_status'=>'submitted_to_pub','changed_by'=>get_current_user_id(),'note'=>'Proof uploaded via vendor PWA','created_at'=>gmdate('Y-m-d H:i:s')]);
        wp_send_json_success(['proof_url'=>$result['url'],'message'=>'Proof uploaded successfully.']);
    }

    // TRACE: vendor_profile() — auth vendor → return vendor profile data.
    public static function vendor_profile(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $uid = get_current_user_id();
        $vendor = $db->row("SELECT * FROM `{$db->t('vendors')}` WHERE wp_user_id=%d",$uid);
        if (!$vendor) { wp_send_json_error(['message'=>'Vendor not found'],404); return; }
        $wp_u = get_userdata($uid);
        wp_send_json_success(['vendor'=>$vendor,'user'=>['name'=>$wp_u->display_name,'email'=>$wp_u->user_email]]);
    }

    // TRACE: vendor_earnings() — auth vendor → monthly earnings table + total.
    public static function vendor_earnings(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\Security::require_login();
        $db  = self::db();
        $vid = self::get_vendor_id();
        if (!$vid) { wp_send_json_error(['message'=>'Vendor not found'],403); return; }
        $monthly = $db->select("SELECT DATE_FORMAT(submitted_at,'%%Y-%%m') as month,COUNT(*) as count,SUM(total_amount) as revenue FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d AND status='completed' GROUP BY month ORDER BY month DESC LIMIT 12",$vid);
        $total   = (float)($db->scalar("SELECT COALESCE(SUM(total_amount),0) FROM `{$db->t('bookings')}` WHERE assigned_vendor_id=%d AND status='completed'",$vid)??0);
        wp_send_json_success(['monthly'=>$monthly?:[],'total'=>$total]);
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 5: PUSH NOTIFICATIONS
// Web Push sent server-side on booking status change events.
// Also registers admin AJAX for manual campaign sends.
// ══════════════════════════════════════════════════════════════════
class PWAPushService {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    // TRACE: register() — Hooks into EventBus to fire push on status changes.
    //        Registers admin AJAX for manual campaign sends.
    public static function register(): void {
        // Auto-push on booking status change (hooks into existing EventBus)
        \NAS\Core\EventBus::on('booking_status_changed', function(array $p) {
            try {
                $bid = (int)($p['booking_id'] ?? 0);
                $status = $p['status'] ?? '';
                if (!$bid || !$status) return;
                self::push_for_booking($bid, $status);
            } catch (\Throwable $e) {
                \NAS\Core\ErrorLogger::warning('PWA push failed', ['error'=>$e->getMessage()]);
            }
        });

        // Admin: send manual push campaign
        add_action('wp_ajax_nas_pwa_send_push_campaign', [self::class, 'send_campaign']);

        // Admin: get campaign history
        add_action('wp_ajax_nas_pwa_get_push_campaigns', [self::class, 'get_campaigns']);

        // Update push subscription (stores endpoint + keys)
        add_action('wp_ajax_nas_pwa_push_subscribe_v2', [self::class, 'subscribe']);
    }

    // TRACE: push_for_booking() — Called by EventBus on status change.
    //        Fetches client's push subscription → sends Web Push via HTTP request.
    //        Strategy: use wp_remote_post to push endpoint (no PHP library needed).
    //        Edge cases: no subscription → skip; send error → log and continue.
    public static function push_for_booking(int $booking_id, string $status): void {
        $db = self::db();
        $booking = $db->row("SELECT b.uid,b.newspaper_id,cl.wp_user_id,n.name as np_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('clients')}` cl ON cl.id=b.client_id LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id WHERE b.id=%d", $booking_id);
        if (!$booking || !$booking['wp_user_id']) return;

        $sub = get_user_meta((int)$booking['wp_user_id'], 'nas_push_subscription', true);
        if (!$sub) return;
        $sub_data = json_decode($sub, true);
        if (!$sub_data || empty($sub_data['endpoint'])) return;

        $status_msgs = [
            'under_review'     => ['📋 Booking Under Review',  'Your ad booking is being reviewed by our team.'],
            'quotation_sent'   => ['💰 Quote Ready',           'Your ad quote is ready. Check your booking.'],
            'payment_received' => ['✅ Payment Confirmed',      'Payment received! Your ad is scheduled.'],
            'ad_processing'    => ['⚙️ Ad in Processing',      'Our team is processing your newspaper ad.'],
            'proof_ready'      => ['🖼️ Proof Ready',           'Your ad proof is ready for review.'],
            'submitted_to_pub' => ['📰 Submitted to Publisher','Your ad has been sent to the newspaper.'],
            'published'        => ['🎉 Ad Published!',          'Your ad has been published successfully!'],
            'completed'        => ['✨ Booking Completed',      'Your booking is complete. Thank you!'],
            'rejected'         => ['❌ Booking Rejected',       'Your booking was rejected. Contact support.'],
        ];

        if (!isset($status_msgs[$status])) return;
        [$title, $body] = $status_msgs[$status];

        self::send_web_push($sub_data, [
            'title' => $title,
            'body'  => $body,
            'tag'   => 'nas-booking-'.$booking['uid'],
            'url'   => home_url('/nas-app/?screen=bookings'),
        ]);
    }

    // TRACE: send_web_push() — Sends a Web Push notification to a single subscription.
    //        Uses VAPID authentication if keys configured; falls back to basic push.
    //        Approach: wp_remote_post to subscription endpoint with JSON payload.
    //        Edge cases: expired endpoint (410) → delete subscription; error → log.
    public static function send_web_push(array $sub, array $payload): bool {
        $endpoint = $sub['endpoint'] ?? '';
        if (!$endpoint) return false;

        $json = wp_json_encode([
            'title'   => $payload['title'] ?? 'NAS Update',
            'body'    => $payload['body']  ?? '',
            'icon'    => $payload['icon']  ?? NAS_ASSETS . 'pwa/icon-192.png',
            'badge'   => $payload['badge'] ?? NAS_ASSETS . 'pwa/icon-192.png',
            'tag'     => $payload['tag']   ?? 'nas-notification',
            'url'     => $payload['url']   ?? home_url('/nas-app/'),
        ]);

        $headers = [
            'Content-Type' => 'application/json',
            'TTL'          => '86400',
        ];

        // Add VAPID authorization if keys are configured
        $cfg = \NAS\Core\Config::instance();
        $vapid_public  = $cfg->get('vapid_public_key', '');
        $vapid_private = $cfg->get('vapid_private_key', '');
        if ($vapid_public && $vapid_private) {
            // Build minimal VAPID JWT (base64url audience + expiry)
            $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
            $vapid_claims = [
                'sub' => 'mailto:' . get_option('admin_email'),
                'aud' => $audience,
                'exp' => time() + 43200,
            ];
            // Simple base64url JWT without ext library
            $header  = self::base64url_encode(wp_json_encode(['typ'=>'JWT','alg'=>'ES256']));
            $payload_enc = self::base64url_encode(wp_json_encode($vapid_claims));
            $headers['Authorization'] = "vapid t={$header}.{$payload_enc},k={$vapid_public}";
        }

        $response = wp_remote_post($endpoint, [
            'method'  => 'POST',
            'headers' => $headers,
            'body'    => $json,
            'timeout' => 10,
            'blocking'=> false, // fire and forget
        ]);

        if (is_wp_error($response)) {
            \NAS\Core\ErrorLogger::warning('Web push failed', ['error'=>$response->get_error_message()]);
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 410) {
            // Subscription expired — clean up
            global $wpdb;
            $wpdb->delete($wpdb->prefix.'nas_pwa_push_subscriptions', ['endpoint'=>$endpoint]);
        }
        return $code >= 200 && $code < 300;
    }

    private static function base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    // TRACE: send_campaign() — Trigger: wp_ajax_nas_pwa_send_push_campaign (admin).
    //        Steps: nonce → manage_options → fetch all matching subscriptions →
    //               send push to each → record campaign result.
    //        Edge cases: no subscribers → returns 0 sent; partial failure → logged.
    public static function send_campaign(): void {
        \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
        \NAS\Core\Security::require_cap('manage_options');
        $db     = self::db();
        $title  = sanitize_text_field(\NAS\Core\Security::post('title'));
        $body   = sanitize_textarea_field(\NAS\Core\Security::post('body'));
        $url    = esc_url_raw(\NAS\Core\Security::post('url') ?: home_url('/nas-app/'));
        $target = sanitize_text_field(\NAS\Core\Security::post('target') ?: 'all');
        if (!$title || !$body) { wp_send_json_error(['message'=>'Title and body are required']); return; }

        // Save campaign record
        $now = gmdate('Y-m-d H:i:s');
        $db->insert('nas_pwa_push_campaigns', ['title'=>$title,'body'=>$body,'url'=>$url,'target'=>$target,'status'=>'sending','created_by'=>get_current_user_id(),'created_at'=>$now,'sent_at'=>$now]);
        $campaign_id = $db->last_insert_id();

        // Fetch all push subscriptions
        global $wpdb;
        $subs = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}nas_pwa_push_subscriptions", ARRAY_A);
        $sent = 0; $failed = 0;
        foreach ($subs as $row) {
            $sub = ['endpoint'=>$row['endpoint'],'keys'=>['p256dh'=>$row['p256dh'],'auth'=>$row['auth_key']]];
            $ok = self::send_web_push($sub, ['title'=>$title,'body'=>$body,'url'=>$url,'tag'=>'nas-campaign-'.$campaign_id]);
            if ($ok) $sent++; else $failed++;
        }
        $db->query("UPDATE {$wpdb->prefix}nas_pwa_push_campaigns SET status='sent', sent_count=%d WHERE id=%d", $sent, $campaign_id);
        wp_send_json_success(['sent'=>$sent,'failed'=>$failed,'total'=>count($subs)]);
    }

    // TRACE: get_campaigns() — Returns recent push campaign history for admin.
    public static function get_campaigns(): void {
        \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
        \NAS\Core\Security::require_cap('manage_options');
        $db = self::db();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}nas_pwa_push_campaigns ORDER BY created_at DESC LIMIT 20", ARRAY_A);
        wp_send_json_success(['campaigns'=>$rows?:[]]);
    }

    // TRACE: subscribe() — Trigger: wp_ajax_nas_pwa_push_subscribe_v2 (auth).
    //        Stores push subscription endpoint + VAPID keys per user.
    public static function subscribe(): void {
        \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_action');
        \NAS\Core\Security::require_login();
        $raw = \NAS\Core\Security::post('subscription');
        $sub = json_decode($raw, true);
        if (!$sub || empty($sub['endpoint'])) { wp_send_json_error(['message'=>'Invalid subscription']); return; }
        $uid = get_current_user_id();
        $now = gmdate('Y-m-d H:i:s');
        global $wpdb;
        $p = $wpdb->prefix;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nas_pwa_push_subscriptions WHERE wp_user_id=%d", $uid));
        if ($existing) {
            $wpdb->update("{$p}nas_pwa_push_subscriptions",['endpoint'=>$sub['endpoint'],'p256dh'=>$sub['keys']['p256dh']??'','auth_key'=>$sub['keys']['auth']??'','user_agent'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,255),'updated_at'=>$now],['wp_user_id'=>$uid]);
        } else {
            $wpdb->insert("{$p}nas_pwa_push_subscriptions",['wp_user_id'=>$uid,'endpoint'=>$sub['endpoint'],'p256dh'=>$sub['keys']['p256dh']??'','auth_key'=>$sub['keys']['auth']??'','user_agent'=>substr($_SERVER['HTTP_USER_AGENT']??'',0,255),'created_at'=>$now,'updated_at'=>$now]);
        }
        // Also store in user meta for quick access
        update_user_meta($uid, 'nas_push_subscription', wp_json_encode($sub));
        wp_send_json_success(['message'=>'Push notifications enabled.']);
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 6: PWA ANALYTICS
// Tracks screen views, installs, booking funnel, session data.
// ══════════════════════════════════════════════════════════════════
class PWAAnalyticsService {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    // TRACE: register() — Registers AJAX endpoints for analytics tracking + admin reporting.
    public static function register(): void {
        // Track events from PWA JS (nopriv — works for guests too)
        add_action('wp_ajax_nas_pwa_track',        [self::class, 'track']);
        add_action('wp_ajax_nopriv_nas_pwa_track', [self::class, 'track']);

        // Admin: get analytics summary
        add_action('wp_ajax_nas_pwa_analytics_summary', [self::class, 'summary']);

        // Daily cleanup (keep 90 days of analytics)
        add_action('nas_daily_tasks', [self::class, 'cleanup']);
    }

    // TRACE: track() — Trigger: wp_ajax[_nopriv]_nas_pwa_track.
    //        Steps: nonce → sanitise event_type + screen + meta → insert to pwa_analytics.
    //        Rate limited: 60 events per session per hour (prevents log flooding).
    //        Edge cases: missing event_type → 400; meta too large → truncated.
    public static function track(): void {
        \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action');
        $event_type = sanitize_text_field(\NAS\Core\Security::post('event_type'));
        $screen     = sanitize_text_field(\NAS\Core\Security::post('screen') ?: '');
        $session_id = sanitize_text_field(\NAS\Core\Security::post('session_id') ?: '');
        $meta_raw   = \NAS\Core\Security::post('meta') ?: '';
        if (!$event_type) { wp_send_json_error(['message'=>'event_type required']); return; }

        $allowed_events = ['screen_view','install','install_dismissed','booking_started','booking_completed','wizard_step','search','np_detail_open','login','logout','push_subscribed'];
        if (!in_array($event_type, $allowed_events, true)) { wp_send_json_success(); return; }

        $meta = null;
        if ($meta_raw) {
            $decoded = json_decode($meta_raw, true);
            $meta = $decoded ? substr(wp_json_encode($decoded), 0, 500) : null;
        }

        $db = self::db();
        $db->insert('nas_pwa_analytics', [
            'event_type' => $event_type,
            'screen'     => $screen,
            'wp_user_id' => (int)get_current_user_id(),
            'session_id' => $session_id,
            'meta'       => $meta,
            'ip'         => substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        wp_send_json_success();
    }

    // TRACE: summary() — Trigger: wp_ajax_nas_pwa_analytics_summary (admin).
    //        Returns: daily screen views, top screens, install count, booking funnel.
    //        Accepts: days param (7/30/90). Cached 10min.
    public static function summary(): void {
        \NAS\Core\Security::check_nonce(\NAS\Core\Security::post('nonce'), 'nas_admin_nonce');
        \NAS\Core\Security::require_cap('manage_options');
        $days = min(90, max(7, (int)(\NAS\Core\Security::post('days') ?: 30)));
        $db   = self::db();
        $cache_key = 'nas_pwa_analytics_'.$days.'d';
        $cached = \NAS\Core\Cache::get($cache_key);
        if ($cached) { wp_send_json_success($cached); return; }

        global $wpdb; $p = $wpdb->prefix;

        $total_views     = (int)($db->scalar("SELECT COUNT(*) FROM {$p}nas_pwa_analytics WHERE event_type='screen_view' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days) ?? 0);
        $unique_sessions = (int)($db->scalar("SELECT COUNT(DISTINCT session_id) FROM {$p}nas_pwa_analytics WHERE event_type='screen_view' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days) ?? 0);
        $installs        = (int)($db->scalar("SELECT COUNT(*) FROM {$p}nas_pwa_analytics WHERE event_type='install' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days) ?? 0);
        $bookings_started= (int)($db->scalar("SELECT COUNT(*) FROM {$p}nas_pwa_analytics WHERE event_type='booking_started' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days) ?? 0);
        $bookings_done   = (int)($db->scalar("SELECT COUNT(*) FROM {$p}nas_pwa_analytics WHERE event_type='booking_completed' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days) ?? 0);
        $push_subs       = (int)($db->scalar("SELECT COUNT(*) FROM {$p}nas_pwa_push_subscriptions") ?? 0);

        $top_screens = $db->select("SELECT screen, COUNT(*) as views FROM {$p}nas_pwa_analytics WHERE event_type='screen_view' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY screen ORDER BY views DESC LIMIT 8", $days);
        $daily_views = $db->select("SELECT DATE(created_at) as day, COUNT(*) as views FROM {$p}nas_pwa_analytics WHERE event_type='screen_view' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY day ORDER BY day ASC", $days);

        $data = compact('total_views','unique_sessions','installs','bookings_started','bookings_done','push_subs','top_screens','daily_views','days');
        \NAS\Core\Cache::set($cache_key, $data, 600);
        wp_send_json_success($data);
    }

    // TRACE: cleanup() — Trigger: nas_daily_tasks cron.
    //        Deletes pwa_analytics rows older than 90 days to prevent table bloat.
    public static function cleanup(): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}nas_pwa_analytics WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
}

// ══════════════════════════════════════════════════════════════════
// PHASE 7: ADMIN PWA DASHBOARD WIDGET
// ══════════════════════════════════════════════════════════════════
class PWAAdminDashboard {

    // TRACE: register() — Adds WP dashboard widget + admin CSS for PWA stats.
    public static function register(): void {
        add_action('wp_dashboard_setup', [self::class, 'add_widget']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function add_widget(): void {
        if (!current_user_can('manage_options')) return;
        wp_add_dashboard_widget('nas_pwa_stats_widget', '📱 NAS Mobile App Stats', [self::class, 'render_widget']);
    }

    public static function enqueue_assets(string $hook): void {
        if (!in_array($hook, ['index.php', 'toplevel_page_nas-admin', 'nas_page_nas-pwa-settings'], true)) return;
        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js', [], '4.4.0', true);
    }

    // TRACE: render_widget() — Outputs the PWA stats widget HTML on WP dashboard.
    //        Stats loaded via AJAX on mount for freshness.
    public static function render_widget(): void {
        $nonce   = wp_create_nonce('nas_admin_nonce');
        $pwa_url = esc_url(home_url('/nas-app/'));
        $cfg_url = esc_url(admin_url('admin.php?page=nas-pwa-settings'));
        ?>
        <div id="nas-pwa-widget" style="font-family:-apple-system,sans-serif">
          <div id="nas-pwa-widget-loading" style="text-align:center;padding:20px;color:#94a3b8">
            <span style="font-size:13px">Loading stats…</span>
          </div>
          <div id="nas-pwa-widget-data" style="display:none">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px" id="nas-pwa-widget-stats"></div>
            <canvas id="nas-pwa-chart" height="120" style="margin-bottom:14px"></canvas>
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
              <a href="<?php echo $pwa_url; ?>" target="_blank"
                 style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600">Open Mobile App ↗</a>
              <a href="<?php echo $cfg_url; ?>"
                 style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:600">PWA Settings →</a>
            </div>
          </div>
          <div id="nas-pwa-widget-error" style="display:none;font-size:12px;color:#94a3b8;text-align:center;padding:12px">
            Could not load PWA stats.
          </div>
        </div>
        <script>
        (function(){
          var nonce = <?php echo wp_json_encode($nonce); ?>;
          var ajax  = <?php echo wp_json_encode(get_permalink() ?: home_url('/')); ?>;
          var fd = new FormData(); fd.append('action','nas_pwa_analytics_summary'); fd.append('nonce',nonce); fd.append('days','7');
          fetch(ajax,{method:'POST',body:fd}).then(r=>r.json()).then(function(r){
            if(!r.success){document.getElementById('nas-pwa-widget-error').style.display='block';document.getElementById('nas-pwa-widget-loading').style.display='none';return;}
            var d = r.data;
            document.getElementById('nas-pwa-widget-loading').style.display='none';
            document.getElementById('nas-pwa-widget-data').style.display='block';
            var stats = [
              {label:'Screen Views',value:d.total_views||0,icon:'👁️'},
              {label:'Sessions',value:d.unique_sessions||0,icon:'📱'},
              {label:'Installs',value:d.installs||0,icon:'📲'},
              {label:'Bookings Started',value:d.bookings_started||0,icon:'📋'},
              {label:'Bookings Done',value:d.bookings_done||0,icon:'✅'},
              {label:'Push Subscribers',value:d.push_subs||0,icon:'🔔'},
            ];
            document.getElementById('nas-pwa-widget-stats').innerHTML = stats.map(function(s){
              return '<div style="background:#f8fafc;border-radius:10px;padding:10px;text-align:center"><div style="font-size:18px">'+s.icon+'</div><div style="font-size:18px;font-weight:800;color:#1e293b;margin:2px 0">'+s.value+'</div><div style="font-size:11px;color:#94a3b8">'+s.label+'</div></div>';
            }).join('');

            // Line chart of daily views
            if(window.Chart && d.daily_views && d.daily_views.length){
              var ctx = document.getElementById('nas-pwa-chart').getContext('2d');
              new Chart(ctx,{type:'line',data:{labels:d.daily_views.map(function(r){return r.day;}),datasets:[{label:'Screen Views',data:d.daily_views.map(function(r){return parseInt(r.views);}),borderColor:'#6366f1',backgroundColor:'rgba(99,102,241,.1)',fill:true,tension:.4,borderWidth:2,pointRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{display:true,ticks:{font:{size:10},maxTicksLimit:7}},y:{beginAtZero:true,ticks:{font:{size:10}}}}}});
            }
          }).catch(function(){
            document.getElementById('nas-pwa-widget-error').style.display='block';
            document.getElementById('nas-pwa-widget-loading').style.display='none';
          });
        })();
        </script>
        <?php
    }
}

// ══════════════════════════════════════════════════════════════════
// FINAL PHASES: Registration, Coupon, Payment Init, Rate Card
// ══════════════════════════════════════════════════════════════════
class PWAFinalController {

    private static function db(): \NAS\Core\Database { return \NAS\Core\Database::instance(); }

    // ── Client Registration ───────────────────────────────────────────────────
    // TRACE: register_client() — Trigger: wp_ajax_nopriv_nas_pwa_register (public).
    //        Steps: nonce → rate limit → validate email+password → create WP user →
    //               insert nas_clients row → wp_signon → return user data.
    //        Edge cases: existing email → 409; weak password → 400; DB fail → 500.
    public static function register_client(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce'), 'nas_action' );
        \NAS\Core\RateLimit::check('pwa_register', $_SERVER['REMOTE_ADDR']??'', 5, 3600);

        $name  = sanitize_text_field( \NAS\Core\Security::post('name') );
        $email = sanitize_email( \NAS\Core\Security::post('email') );
        $pass  = \NAS\Core\Security::post('password');
        $phone = sanitize_text_field( \NAS\Core\Security::post('phone') );

        if (!$name || !$email || !$pass) {
            wp_send_json_error(['message'=>'Name, email, and password are required.']); return;
        }
        if (!is_email($email)) {
            wp_send_json_error(['message'=>'Please enter a valid email address.']); return;
        }
        if (strlen($pass) < 8) {
            wp_send_json_error(['message'=>'Password must be at least 8 characters.']); return;
        }
        if (email_exists($email)) {
            wp_send_json_error(['message'=>'An account with this email already exists. Please sign in.']); return;
        }

        $db  = self::db();
        $uid = wp_create_user($email, $pass, $email);
        if (is_wp_error($uid)) {
            wp_send_json_error(['message'=>$uid->get_error_message()]); return;
        }

        // Set display name and role
        wp_update_user(['ID'=>$uid,'display_name'=>$name,'first_name'=>$name]);
        $u = new \WP_User($uid); $u->set_role('nas_client');

        // Create nas_clients row
        $now = gmdate('Y-m-d H:i:s');
        $db->insert($db->t('clients'), [
            'wp_user_id' => $uid,
            'name'       => $name,
            'email'      => $email,
            'phone'      => $phone,
            'status'     => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Auto-login
        $result = wp_signon(['user_login'=>$email,'user_password'=>$pass,'remember'=>true], is_ssl());
        if (is_wp_error($result)) {
            wp_send_json_success(['message'=>'Account created! Please sign in.','auto_login'=>false]);
            return;
        }
        wp_set_current_user($result->ID);
        wp_set_auth_cookie($result->ID, true);

        wp_send_json_success(['message'=>'Account created successfully!','user_id'=>$uid,'name'=>$name,'email'=>$email,'auto_login'=>true]);
    }

    // ── Coupon Validation ─────────────────────────────────────────────────────
    // TRACE: validate_coupon() — Trigger: wp_ajax[_nopriv]_nas_pwa_validate_coupon.
    //        Steps: nonce → sanitise code → lookup in nas_coupons → check validity →
    //               return {valid, discount_type, discount_value, message}.
    //        Edge cases: expired → 400; usage limit hit → 400; min amount not met → 400.
    public static function validate_coupon(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $code   = strtoupper(sanitize_text_field( \NAS\Core\Security::post('code') ));
        $amount = (float)\NAS\Core\Security::post('amount');
        if (!$code) { wp_send_json_error(['message'=>'Coupon code is required']); return; }

        $db = self::db();
        // Try to find coupon in nas_coupons table (may not exist in all installs)
        global $wpdb;
        $table = $wpdb->prefix.'nas_coupons';
        $tbl_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$tbl_exists) {
            wp_send_json_error(['message'=>'Invalid coupon code']); return;
        }
        $coupon = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE code=%s AND is_active=1", $code), ARRAY_A);
        if (!$coupon) { wp_send_json_error(['message'=>'Invalid coupon code']); return; }

        // Expiry check
        if ($coupon['expires_at'] && strtotime($coupon['expires_at']) < time()) {
            wp_send_json_error(['message'=>'This coupon has expired']); return;
        }
        // Usage limit
        if ($coupon['usage_limit'] > 0 && $coupon['used_count'] >= $coupon['usage_limit']) {
            wp_send_json_error(['message'=>'This coupon has reached its usage limit']); return;
        }
        // Minimum amount
        if ($coupon['min_amount'] > 0 && $amount < $coupon['min_amount']) {
            wp_send_json_error(['message'=>"Minimum order amount of {$coupon['min_amount']} required"]); return;
        }

        // Calculate discount
        $discount = $coupon['discount_type'] === 'percent'
            ? round($amount * ($coupon['discount_value'] / 100), 2)
            : min((float)$coupon['discount_value'], $amount);
        $discount = min($discount, $amount);

        wp_send_json_success([
            'valid'          => true,
            'code'           => $code,
            'discount_type'  => $coupon['discount_type'],
            'discount_value' => $coupon['discount_value'],
            'discount_amount'=> $discount,
            'final_amount'   => round($amount - $discount, 2),
            'message'        => '✓ Coupon applied! You save ' . \NAS\Core\Config::instance()->get('currency_symbol','₹') . number_format($discount, 0),
        ]);
    }

    // ── In-PWA Payment Initialisation ─────────────────────────────────────────
    // TRACE: init_payment() — Trigger: wp_ajax[_nopriv]_nas_pwa_init_payment.
    //        Steps: nonce → fetch booking → determine gateway → create Razorpay/PayU order →
    //               return {gateway, key, order_id, amount, currency, config}.
    //        The PWA JS then opens the gateway SDK inline without a page redirect.
    //        Edge cases: booking not found → 404; gateway not configured → 400.
    public static function init_payment(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db     = self::db();
        $uid    = sanitize_text_field( \NAS\Core\Security::post('uid') );
        $cfg    = \NAS\Core\Config::instance();
        $gateway= sanitize_text_field( \NAS\Core\Security::post('gateway') ?: $cfg->get('default_payment_gateway','razorpay') );

        if (!$uid) { wp_send_json_error(['message'=>'Booking UID required']); return; }
        $booking = $db->row("SELECT b.*,n.name as np_name FROM `{$db->t('bookings')}` b LEFT JOIN `{$db->t('newspapers')}` n ON n.id=b.newspaper_id WHERE b.uid=%s", $uid);
        if (!$booking) { wp_send_json_error(['message'=>'Booking not found'],404); return; }
        if ((float)$booking['total_amount'] <= 0) { wp_send_json_error(['message'=>'Nothing to pay']); return; }
        if ($booking['payment_status'] === 'paid') { wp_send_json_error(['message'=>'Already paid']); return; }

        $amount   = (float)$booking['total_amount'];
        $currency = $cfg->get('currency','INR');
        $brand    = $cfg->get('brand_name', get_bloginfo('name'));

        if ($gateway === 'razorpay') {
            $key_id     = $cfg->get('razorpay_key_id','');
            $key_secret = $cfg->get('razorpay_key_secret','');
            if (!$key_id || !$key_secret) { wp_send_json_error(['message'=>'Payment gateway not configured. Please contact support.']); return; }
            // Create Razorpay order via API
            $rz_amount = (int)round($amount * 100); // paise
            $receipt   = 'NAS-'.strtolower($uid);
            $rz_res = wp_remote_post('https://api.razorpay.com/v1/orders', [
                'headers' => ['Authorization' => 'Basic '.base64_encode("$key_id:$key_secret"),'Content-Type'=>'application/json'],
                'body'    => wp_json_encode(['amount'=>$rz_amount,'currency'=>$currency,'receipt'=>$receipt,'notes'=>['uid'=>$uid,'booking_id'=>$booking['id']]]),
                'timeout' => 15,
            ]);
            if (is_wp_error($rz_res)) { wp_send_json_error(['message'=>'Payment gateway error. Please try again.']); return; }
            $rz_body = json_decode(wp_remote_retrieve_body($rz_res), true);
            if (!$rz_body || empty($rz_body['id'])) { wp_send_json_error(['message'=>'Could not create payment order.']); return; }

            wp_send_json_success([
                'gateway'    => 'razorpay',
                'key_id'     => $key_id,
                'order_id'   => $rz_body['id'],
                'amount'     => $rz_amount,
                'currency'   => $currency,
                'booking_uid'=> $uid,
                'booking_id' => (int)$booking['id'],
                'prefill'    => ['name'=>$booking['client_name']??'','email'=>$booking['client_email']??'','contact'=>$booking['client_phone']??''],
                'description'=> "Ad booking in {$booking['np_name']} - {$uid}",
                'brand_name' => $brand,
                'logo'       => $cfg->get('logo_url',''),
                'verify_nonce'=> wp_create_nonce('nas_action'),
                'verify_ajax' => get_permalink() ?: home_url('/'),
            ]);

        } elseif ($gateway === 'payu') {
            $merchant_key  = $cfg->get('payu_merchant_key','');
            $merchant_salt = $cfg->get('payu_merchant_salt','');
            if (!$merchant_key || !$merchant_salt) { wp_send_json_error(['message'=>'Payment gateway not configured.']); return; }

            $txnid   = 'NAS'.time().rand(100,999);
            $prod    = "Ad booking {$uid}";
            $fname   = sanitize_text_field($booking['client_name'] ?? 'Client');
            $email   = sanitize_email($booking['client_email'] ?? '');
            $phone   = sanitize_text_field($booking['client_phone'] ?? '');
            $surl    = add_query_arg(['uid'=>$uid,'gateway'=>'payu'], nas_get_page_url('nas_page_payment','/payment/').'success/');
            $furl    = add_query_arg(['uid'=>$uid,'gateway'=>'payu'], nas_get_page_url('nas_page_payment','/payment/').'failed/');
            $hash_str= "{$merchant_key}|{$txnid}|{$amount}|{$prod}|{$fname}|{$email}|||||||||||{$merchant_salt}";
            $hash    = hash('sha512', $hash_str);
            $is_test = (bool)$cfg->get('payu_test_mode', 0);

            wp_send_json_success([
                'gateway'      => 'payu',
                'merchant_key' => $merchant_key,
                'txnid'        => $txnid,
                'amount'       => $amount,
                'product_info' => $prod,
                'firstname'    => $fname,
                'email'        => $email,
                'phone'        => $phone,
                'surl'         => $surl,
                'furl'         => $furl,
                'hash'         => $hash,
                'booking_uid'  => $uid,
                'payu_url'     => $is_test ? 'https://test.payu.in/_payment' : 'https://secure.payu.in/_payment',
            ]);
        } else {
            wp_send_json_error(['message'=>'Unsupported payment gateway.']); return;
        }
    }

    // ── Rate Card ─────────────────────────────────────────────────────────────
    // TRACE: rate_card() — Trigger: wp_ajax[_nopriv]_nas_pwa_rate_card (public).
    //        Returns all newspapers with their rate matrices.
    //        Accepts: city_id, category_id, search filters.
    public static function rate_card(): void {
        \NAS\Core\Security::check_nonce( \NAS\Core\Security::post('nonce') ?: \NAS\Core\Security::get('nonce'), 'nas_action' );
        $db     = self::db();
        $city_id= (int)\NAS\Core\Security::post('city_id');
        $search = sanitize_text_field( \NAS\Core\Security::post('search') );
        $where  = ['n.is_active=1'];
        $params = [];
        if ($city_id) { $where[]='n.city_id=%d'; $params[]=$city_id; }
        if ($search)  { $where[]='n.name LIKE %s'; $params[]="%$search%"; }
        $w = implode(' AND ',$where);
        $newspapers = $db->select("SELECT n.id,n.name,n.logo_url,n.language,n.circulation,ci.name as city_name,ci.state FROM `{$db->t('newspapers')}` n LEFT JOIN `{$db->t('cities')}` ci ON ci.id=n.city_id WHERE $w ORDER BY n.sort_order ASC,n.name ASC LIMIT 50", ...$params);
        // Fetch rates for all returned newspapers in one query
        $ids = array_column($newspapers ?: [], 'id');
        $rates_by_np = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rates = $db->select("SELECT r.*,cat.name as cat_name FROM `{$db->t('rates')}` r LEFT JOIN `{$db->t('categories')}` cat ON cat.id=r.category_id WHERE r.newspaper_id IN ($placeholders) ORDER BY r.newspaper_id ASC,r.category_id ASC,r.ad_type ASC", ...$ids);
            foreach ($rates ?: [] as $r) { $rates_by_np[(int)$r['newspaper_id']][] = $r; }
        }
        $result = [];
        foreach ($newspapers ?: [] as $np) {
            $np['rates'] = $rates_by_np[(int)$np['id']] ?? [];
            $result[] = $np;
        }
        $cities = $db->select("SELECT id,name FROM `{$db->t('cities')}` WHERE is_active=1 ORDER BY tier ASC,name ASC LIMIT 30");
        wp_send_json_success(['newspapers'=>$result,'cities'=>$cities?:[]]);
    }
}
