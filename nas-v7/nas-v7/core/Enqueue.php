<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NAS Asset & Template Manager — v2.2
 *
 * Uses WordPress's native template_include filter with is_page(slug) check —
 * the most reliable method, works with all themes including FSE/block themes.
 */
class Enqueue {

    const TPL_FILE = 'templates/partials/nas-page-template.php';

    /** All NAS page slugs */
    private static function nas_slugs(): array {
        return [
            'book-newspaper-ad', 'client-dashboard', 'admin-dashboard',
            'staff-dashboard',   'moderation-dashboard', 'booking-confirmation',
            'newspaper-ads',     'track-order',     'faq',
            'contact-us',        'blog',            'payment',
            'newspaper-ad-login',
            // v3 Super Combo pages
            'pricing',           'about',           'support',
            'cities',            'newspapers',
            'vendor-dashboard',
            'vendor-register',
            // Homepage variants
            'nas-home',          'nas-homepage',    'home',
            'homepage',          'index',
        ];
    }

    public static function init(): void {
        // Register our page template in the WordPress template system
        add_filter( 'theme_page_templates', [ self::class, 'register_template' ], 10, 4 );

        // Serve NAS template for NAS pages — priority 99 runs after other plugins
        add_filter( 'template_include', [ self::class, 'use_nas_template' ], 99 );

        // Assets
        add_action( 'wp_enqueue_scripts',    [ self::class, 'homepage_assets' ], 5 );
        add_action( 'wp_enqueue_scripts',    [ self::class, 'frontend_assets' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'admin_assets' ] );

        // Performance: defer non-critical scripts + preconnect to CDN origins
        add_filter( 'script_loader_tag', [ self::class, 'defer_scripts' ], 10, 3 );
        add_action( 'wp_head',           [ self::class, 'preconnect_hints' ], 2 );

        // Hide admin bar on NAS pages — use URL slug (works before query setup)
        add_filter( 'show_admin_bar', [ self::class, 'maybe_hide_admin_bar' ], 999 );
    }

    // ── Page template registration ─────────────────────────────────────────
    public static function register_template( $templates, $theme, $post, $post_type ): array {
        if ( in_array( $post_type, [ 'page', null, '' ], true ) ) {
            $templates['nas-portal'] = 'NAS Portal Page';
        }
        return $templates;
    }

    // ── Is this a NAS portal page? ─────────────────────────────────────────
    // Uses is_page() with slug array — most reliable WordPress check.
    // Works even when option IDs are stale after reinstall.
    public static function is_nas_page(): bool {
        if ( ! is_page() && ! is_front_page() ) return false;
        // Front page with NAS content is a NAS page
        if ( is_front_page() && is_page() ) {
            $id = (int) get_queried_object_id();
            if ( self::has_nas_template() ) return true;
            if ( is_page( self::stored_page_ids() ) ) return true;
        }
        // is_page() accepts an array of slugs, IDs, or titles
        return is_page( self::nas_slugs() )
            || is_page( self::stored_page_ids() )
            || self::has_nas_template();
    }

    private static function stored_page_ids(): array {
        static $ids = null;
        if ( $ids !== null ) return $ids;
        $ids = array_values( array_filter( array_map( 'intval', [
            get_option('nas_page_booking'),
            get_option('nas_page_client_dashboard'),
            get_option('nas_page_admin_dashboard'),
            get_option('nas_page_staff_dashboard'),
            get_option('nas_page_moderation_dashboard'),
            get_option('nas_page_confirmation'),
            get_option('nas_page_city_index'),
            get_option('nas_page_track_order'),
            get_option('nas_page_faq'),
            get_option('nas_page_contact'),
            get_option('nas_page_blog'),
            get_option('nas_page_payment'),
            get_option('nas_page_login'),
            get_option('nas_page_vendor_dashboard'),  // added: was missing, caused 404
        ] ) ) );
        return $ids;
    }

    private static function has_nas_template(): bool {
        $id = (int) get_queried_object_id();
        if ( ! $id ) return false;
        return get_post_meta( $id, '_wp_page_template', true ) === 'nas-portal';
    }

    // ── Serve NAS template ─────────────────────────────────────────────────
    public static function use_nas_template( string $template ): string {
        // is_page(slugs) is the definitive check — no cache, no stale IDs
        $is_front = is_front_page() && is_page();
        if ( ! $is_front
             && ! is_page( self::nas_slugs() )
             && ! is_page( self::stored_page_ids() )
             && ! self::has_nas_template() ) {
            return $template;
        }

        // Heal page options and template meta for this page
        self::heal_current_page();

        $our_tpl = NAS_DIR . self::TPL_FILE;
        return file_exists( $our_tpl ) ? $our_tpl : $template;
    }

    // Auto-heal: store correct page ID and set template meta
    private static function heal_current_page(): void {
        $id   = (int) get_queried_object_id();
        $slug = (string) get_post_field( 'post_name', $id );
        if ( ! $id || ! $slug ) return;

        // Set template meta
        if ( get_post_meta( $id, '_wp_page_template', true ) !== 'nas-portal' ) {
            update_post_meta( $id, '_wp_page_template', 'nas-portal' );
        }

        // Heal stored option
        $map = [
            'book-newspaper-ad'    => 'nas_page_booking',
            'client-dashboard'     => 'nas_page_client_dashboard',
            'admin-dashboard'      => 'nas_page_admin_dashboard',
            'staff-dashboard'      => 'nas_page_staff_dashboard',
            'moderation-dashboard' => 'nas_page_moderation_dashboard',
            'booking-confirmation' => 'nas_page_confirmation',
            'newspaper-ads'        => 'nas_page_city_index',
            'track-order'          => 'nas_page_track_order',
            'faq'                  => 'nas_page_faq',
            'contact-us'           => 'nas_page_contact',
            'blog'                 => 'nas_page_blog',
            'payment'              => 'nas_page_payment',
            'newspaper-ad-login'   => 'nas_page_login',
            'vendor-dashboard'     => 'nas_page_vendor_dashboard',
        ];
        if ( isset( $map[$slug] ) && (int) get_option( $map[$slug] ) !== $id ) {
            update_option( $map[$slug], $id );
        }
    }

    // ── Hide admin bar on NAS pages ────────────────────────────────────────
    public static function maybe_hide_admin_bar( bool $show ): bool {
        if ( ! $show ) return false;
        // Parse the path cleanly — strip query string fragments before checking slug.
        // basename() alone is bypassable via trailing slash: /client-dashboard/?foo
        $path = parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '';
        $slug = basename( trim( $path, '/' ) );
        // Sanitize to match slug format (lowercase alphanumeric and hyphens only)
        $slug = sanitize_title( $slug );
        if ( $slug && in_array( $slug, self::nas_slugs(), true ) ) return false;
        // WP query check (if query is set up — most reliable)
        if ( did_action('parse_query') && is_page( self::nas_slugs() ) ) return false;
        return $show;
    }

    // ── Assign template to page (called from nas_create_pages) ────────────
    public static function assign_template_to_page( int $page_id ): void {
        update_post_meta( $page_id, '_wp_page_template', 'nas-portal' );
    }

    public static function is_nas_admin_page(): bool {
        return is_page([
            get_option('nas_page_admin_dashboard'),
            get_option('nas_page_staff_dashboard'),
            get_option('nas_page_moderation_dashboard'),
        ]);
    }

    /** Dashboard / app UI pages — load full dashboard CSS stack */
    public static function is_nas_dashboard_page(): bool {
        return is_page( [
            'client-dashboard',
            'admin-dashboard',
            'staff-dashboard',
            'moderation-dashboard',
            'vendor-dashboard',
            get_option( 'nas_page_client_dashboard' ),
            get_option( 'nas_page_admin_dashboard' ),
            get_option( 'nas_page_staff_dashboard' ),
            get_option( 'nas_page_moderation_dashboard' ),
            get_option( 'nas_page_vendor_dashboard' ),
        ] );
    }

    /** Public marketing pages (not home, not dashboard) */
    public static function is_nas_public_portal_page(): bool {
        if ( self::is_nas_homepage() || self::is_nas_dashboard_page() ) {
            return false;
        }
        return self::is_nas_page();
    }

    /** City listing pages that need legacy nas-city-pages.css */
    public static function needs_city_pages_css(): bool {
        if ( get_query_var( 'nas_city_page' ) || get_query_var( 'nas_city_slug' ) ) {
            return true;
        }
        $slug = function_exists( 'nas_portal_current_slug' ) ? nas_portal_current_slug() : '';
        return in_array( $slug, [ 'newspaper-ads', 'cities' ], true );
    }

    // Cache-busts a specific asset by file modified time, falling back to
    // NAS_VERSION if the file can't be read (e.g. path issue) so enqueuing
    // never breaks — just won't auto-bust in that edge case.
    private static function asset_ver( string $relative_path ): string {
        $file = NAS_PLUGIN_DIR . 'assets/' . $relative_path;
        $mtime = @filemtime( $file );
        return $mtime ? (string) $mtime : NAS_VERSION;
    }

    // ── Homepage assets (standalone front-page template) ─────────────────
    public static function is_nas_homepage(): bool {
        if ( get_option( 'nas_homepage_enabled' ) && is_front_page() ) {
            return true;
        }
        // Shortcode-based homepage on any NAS front page slug
        if ( is_page( [ 'nas-homepage', 'nas-home', 'homepage' ] ) ) {
            return true;
        }
        return false;
    }

    public static function homepage_assets(): void {
        if ( ! self::is_nas_homepage() ) {
            return;
        }

        $v = NAS_VERSION;
        $a = NAS_ASSETS;

        wp_enqueue_style( 'nas-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap',
            [], null );
        wp_enqueue_style( 'nas-core', $a . 'css/nas-core.css', [ 'nas-fonts' ], self::asset_ver( 'css/nas-core.css' ) );
        wp_enqueue_style( 'nas-portal', $a . 'css/nas-portal.css', [ 'nas-core' ], self::asset_ver( 'css/nas-portal.css' ) );
        wp_enqueue_style( 'nas-homepage', $a . 'css/nas-homepage.css', [ 'nas-core', 'nas-portal' ], self::asset_ver( 'css/nas-homepage.css' ) );
        wp_enqueue_style( 'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
            [], '6.5.0' );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'nas-core', $a . 'js/nas-core.js', [ 'jquery' ], self::asset_ver( 'js/nas-core.js' ), true );
        wp_enqueue_script( 'nas-homepage', $a . 'js/nas-homepage.js', [ 'nas-core' ], self::asset_ver( 'js/nas-homepage.js' ), true );

        wp_localize_script( 'nas-core', 'NAS', self::js_vars() );
    }

    // ── Frontend assets ───────────────────────────────────────────────────
    public static function frontend_assets(): void {
        if ( self::is_nas_homepage() ) {
            return; // homepage_assets() handles the front page
        }

        if ( ! is_page( self::nas_slugs() )
             && ! is_page( self::stored_page_ids() ) ) {
            return;
        }

        $a = NAS_ASSETS;

        wp_enqueue_style( 'nas-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap',
            [], null );
        wp_enqueue_style( 'nas-core', $a . 'css/nas-core.css', [ 'nas-fonts' ], self::asset_ver( 'css/nas-core.css' ) );
        wp_enqueue_style( 'nas-portal', $a . 'css/nas-portal.css', [ 'nas-core' ], self::asset_ver( 'css/nas-portal.css' ) );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'nas-core', $a . 'js/nas-core.js', [ 'jquery' ], self::asset_ver( 'js/nas-core.js' ), true );

        // Public marketing pages: homepage header/footer styles only — no dashboard CSS war
        // CSS budget target: nas-core + nas-portal + nas-homepage ≈ 150KB uncompressed (< 200KB)
        if ( self::is_nas_public_portal_page() ) {
            wp_enqueue_style( 'nas-homepage', $a . 'css/nas-homepage.css', [ 'nas-core', 'nas-portal' ], self::asset_ver( 'css/nas-homepage.css' ) );
            if ( self::needs_city_pages_css() ) {
                wp_enqueue_style( 'nas-city-pages', $a . 'css/nas-city-pages.css', [ 'nas-core' ], self::asset_ver( 'css/nas-city-pages.css' ) );
            }
            wp_enqueue_script( 'nas-homepage', $a . 'js/nas-homepage.js', [ 'nas-core' ], self::asset_ver( 'js/nas-homepage.js' ), true );
            wp_localize_script( 'nas-core', 'NAS', self::js_vars() );
            return;
        }

        // Booking funnel + dashboards: full app stack
        wp_enqueue_style( 'nas-dashboard', $a . 'css/nas-dashboard.css', [ 'nas-core', 'nas-portal' ], self::asset_ver( 'css/nas-dashboard.css' ) );
        wp_enqueue_style( 'nas-enterprise', $a . 'css/nas-enterprise.css', [ 'nas-core', 'nas-dashboard' ], self::asset_ver( 'css/nas-enterprise.css' ) );
        wp_enqueue_style( 'nas-booking', $a . 'css/nas-booking.css', [ 'nas-core' ], self::asset_ver( 'css/nas-booking.css' ) );
        wp_enqueue_style( 'nas-chat', $a . 'css/nas-chat.css', [ 'nas-core' ], self::asset_ver( 'css/nas-chat.css' ) );
        wp_enqueue_style( 'nas-city-pages', $a . 'css/nas-city-pages.css', [ 'nas-core' ], self::asset_ver( 'css/nas-city-pages.css' ) );
        wp_enqueue_style( 'nas-admin', $a . 'css/nas-admin.css', [ 'nas-core' ], self::asset_ver( 'css/nas-admin.css' ) );
        wp_enqueue_style( 'select2-css',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css',
            [], '4.1.0' );

        wp_enqueue_script( 'select2',
            'https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js',
            [ 'jquery' ], '4.1.0', true );
        wp_enqueue_script( 'chart-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js',
            [], '4.4.0', true );
        wp_enqueue_script( 'nas-core',
            $a . 'js/nas-core.js', [ 'jquery', 'select2' ], self::asset_ver( 'js/nas-core.js' ), true );
        wp_enqueue_script( 'nas-booking',
            $a . 'js/nas-booking.js', [ 'nas-core' ], self::asset_ver( 'js/nas-booking.js' ), true );
        wp_enqueue_script( 'nas-chat',
            $a . 'js/nas-chat.js', [ 'nas-core' ], self::asset_ver( 'js/nas-chat.js' ), true );
        wp_enqueue_script( 'nas-dashboard',
            $a . 'js/nas-dashboard.js', [ 'nas-core', 'nas-chat' ], self::asset_ver( 'js/nas-dashboard.js' ), true );
        wp_enqueue_script( 'nas-admin',
            $a . 'js/nas-admin.js', [ 'nas-core', 'chart-js' ], self::asset_ver( 'js/nas-admin.js' ), true );

        wp_localize_script( 'nas-core', 'NAS', self::js_vars() );
    }

    // Adds defer to non-critical scripts — they are already in footer,
    // defer makes them download in parallel without blocking HTML parsing.
    public static function defer_scripts( string $tag, string $handle, string $src ): string {
        if ( is_admin() ) return $tag;
        $defer = [ 'jquery', 'jquery-core', 'jquery-migrate', 'select2',
                   'nas-core', 'nas-homepage', 'nas-booking', 'nas-chat', 'nas-dashboard', 'nas-admin' ];
        if ( in_array( $handle, $defer, true )
             && strpos( $tag, 'defer' ) === false
             && strpos( $tag, 'async' ) === false ) {
            $tag = str_replace( '<script ', '<script defer ', $tag );
        }
        return $tag;
    }

    // Emits preconnect + non-blocking Font Awesome in <head>.
    public static function preconnect_hints(): void {
        if ( ! self::is_nas_page() && ! self::is_nas_homepage() ) {
            return;
        }
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . PHP_EOL;
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . PHP_EOL;
        echo '<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>' . PHP_EOL;
        $fa  = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
        $url = esc_url( $fa );
        echo '<link rel="preload" href="' . $url . '" as="style" '
           . 'onload="' . esc_attr( 'this.onload=null;this.rel=\'stylesheet\'' ) . '">' . PHP_EOL;
        echo '<noscript><link rel="stylesheet" href="' . $url . '"></noscript>' . PHP_EOL;
    }

    public static function admin_assets( string $hook ): void {
        if ( strpos( $hook, 'nas' ) === false ) return;
        $v = NAS_VERSION; $a = NAS_ASSETS;
        wp_enqueue_style( 'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
            [], '6.5.0' );
        wp_enqueue_style( 'nas-core',  $a . 'css/nas-core.css',  [], self::asset_ver('css/nas-core.css') );
        wp_enqueue_style( 'nas-admin', $a . 'css/nas-admin.css', [], self::asset_ver('css/nas-admin.css') );
        wp_enqueue_script( 'chart-js',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js',
            [], '4.4.0', true );
        wp_enqueue_script( 'nas-admin',
            $a . 'js/nas-admin.js', ['jquery', 'chart-js'], self::asset_ver('js/nas-admin.js'), true );
        wp_localize_script( 'nas-admin', 'NAS', self::js_vars() );
    }

    private static function js_vars(): array {
        $user = wp_get_current_user();
        return [
            'ajax_url'        => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'nas_action' ),
            'home_url'        => home_url( '/' ),
            'assets_url'      => NAS_ASSETS,
            'booking_url'     => nas_get_page_url( 'nas_page_booking',         '/book-newspaper-ad/' ),
            'client_dash'     => nas_get_page_url( 'nas_page_client_dashboard', '/client-dashboard/' ),
            'admin_dash'      => nas_get_page_url( 'nas_page_admin_dashboard',  '/admin-dashboard/' ),
            'vendor_dash'     => nas_get_page_url( 'nas_page_vendor_dashboard', '/vendor-dashboard/' ),
            'login_url'       => nas_get_page_url( 'nas_page_login',            '/newspaper-ad-login/' ),
            'currency'        => nas_config( 'currency_symbol', '₹' ),
            'user_id'         => get_current_user_id(),
            'user_name'       => $user->display_name ?? '',
            'user_role'       => Security::current_role(),
            'is_logged_in'    => is_user_logged_in() ? 1 : 0,
            'chat_poll_ms'    => 4000,
            'version'         => NAS_VERSION,
            // Workflow stages for booking progress tracker in nas-dashboard.js
            // (was missing — caused the booking drawer progress track to render empty)
            'workflow_stages' => array_keys( \NAS\Core\Helpers::workflow_stages() ),
        ];
    }
}

Enqueue::init();
