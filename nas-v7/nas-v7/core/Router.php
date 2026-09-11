<?php
namespace NAS\Core;
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * NAS Router v3 — Super Combo
 * Handles all custom URL patterns for city, state, newspaper, category, and SEO pages.
 */
class Router {

    private static ?Router $instance = null;

    // TRACE: instance() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public static function instance(): Router {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    // TRACE: register_rewrite_rules() — Trigger: plugins_loaded or class instantiation.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function register_rewrite_rules(): void {

        // ── Admin dashboard route ───────────────────────────────────────────────
        add_rewrite_rule( '^newspaper-ad-login/?$',
            'index.php?nas_page=nas_login', 'top' );
        add_rewrite_rule( '^vendor-dashboard/?$',
            'index.php?nas_page=vendor_dashboard', 'top' );
        add_rewrite_rule( '^admin-dashboard/?$',
            'index.php?nas_page=admin_dashboard', 'top' );
        add_rewrite_rule( '^admin-dashboard/([a-z0-9\-]+)/?$',
            'index.php?nas_page=admin_dashboard&nas_admin=$matches[1]', 'top' );

        // ── SEO page patterns ──────────────────────────────────────────────────
        // /newspaper-ads/                       → cities index
        add_rewrite_rule( '^newspaper-ads/?$',
            'index.php?nas_page=cities_index', 'top' );

        // /newspaper-ads/{city-slug}/           → city landing page
        add_rewrite_rule( '^newspaper-ads/([a-z0-9\-]+)/?$',
            'index.php?nas_city_slug=$matches[1]', 'top' );

        // /newspapers/                          → all newspapers index
        add_rewrite_rule( '^newspapers/?$',
            'index.php?nas_page=newspapers_index', 'top' );

        // /newspapers/{newspaper-slug}/         → individual newspaper page
        add_rewrite_rule( '^newspapers/([a-z0-9\-]+)/?$',
            'index.php?nas_newspaper_slug=$matches[1]', 'top' );

        // /categories/{category-slug}/          → category SEO page
        add_rewrite_rule( '^categories/([a-z0-9\-]+)/?$',
            'index.php?nas_category_slug=$matches[1]', 'top' );

        // /states/{state-slug}/                 → state landing page
        add_rewrite_rule( '^states/([a-z0-9\-]+)/?$',
            'index.php?nas_state_slug=$matches[1]', 'top' );

        // /pricing/                             → pricing page
        add_rewrite_rule( '^pricing/?$',
            'index.php?nas_page=pricing', 'top' );

        // /about/                               → about page
        add_rewrite_rule( '^about/?$',
            'index.php?nas_page=about', 'top' );

        // /support/                             → support page
        add_rewrite_rule( '^support/?$',
            'index.php?nas_page=support', 'top' );

        // Legacy city routes (backward compat)
        add_rewrite_rule( '^newspaper-ad-([a-z0-9\-]+)/?$',
            'index.php?nas_city_slug=$matches[1]', 'top' );

        // /book-ad/{city-slug}/                 → booking for city
        add_rewrite_rule( '^book-ad/([a-z0-9\-]+)/?$',
            'index.php?nas_book_city=$matches[1]', 'top' );

        // ── Register query vars ────────────────────────────────────────────────
        add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
        add_action( 'template_redirect', [ $this, 'handle_routes' ], 5 );
    }

    // TRACE: add_query_vars() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function add_query_vars( array $vars ): array {
        return array_merge( $vars, [
            'nas_page',
            'nas_admin',
            'nas_city_slug',
            'nas_newspaper_slug',
            'nas_category_slug',
            'nas_state_slug',
            'nas_book_city',
            // Legacy
            'nas_city_page',
            // Note: nas_book_city was previously duplicated here — removed duplicate
        ]);
    }

    // TRACE: handle_routes() — Called internally or via AJAX action.
    //        Steps: processes incoming request.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    public function handle_routes(): void {
        $nas_page     = get_query_var('nas_page');
        $city_slug    = get_query_var('nas_city_slug') ?: get_query_var('nas_city_page');
        $newspaper    = get_query_var('nas_newspaper_slug');
        $category     = get_query_var('nas_category_slug');
        $state        = get_query_var('nas_state_slug');
        $book_city    = get_query_var('nas_book_city');

        // Enqueue NAS assets for all our routes
        if ( $nas_page || $city_slug || $newspaper || $category || $state || $book_city ) {
            add_filter( 'show_admin_bar', '__return_false', 999 );
            remove_action( 'wp_head', '_admin_bar_bump_cb' );
        }

        if ( $city_slug )  { $this->serve_template('city', ['city_slug' => $city_slug]); return; }
        if ( $state )      { $this->serve_template('state-page', ['state_slug' => $state]); return; }
        if ( $newspaper )  { $this->serve_template('newspaper-detail', ['newspaper_slug' => $newspaper]); return; }
        if ( $category )   { $this->serve_template('category-page', ['category_slug' => $category]); return; }
        if ( $book_city )  { $this->serve_template('city', ['city_slug' => $book_city]); return; }

        switch ( $nas_page ) {
            case 'nas_login':         $this->serve_login(); return;
            case 'vendor_dashboard': $this->serve_vendor(); return;
            case 'admin_dashboard': $this->serve_admin(); return;
            case 'cities_index':    $this->serve_template('cities-index'); return;
            case 'newspapers_index':$this->serve_template('newspapers-index'); return;
            case 'pricing':         $this->serve_template('pricing'); return;
            case 'about':           $this->serve_template('about'); return;
            case 'support':         $this->serve_template('support'); return;
        }
    }

    // TRACE: serve_template() — Called internally or via AJAX action.
    //        Steps: executes operation.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access.
    private function serve_template( string $name, array $data = [] ): void {
        $file = NAS_PLUGIN_DIR . "templates/pages/{$name}.php";
        if ( ! file_exists( $file ) ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            get_template_part( 404 );
            exit;
        }

        // Set query vars so templates can access them via get_query_var()
        foreach ( $data as $k => $v ) {
            set_query_var( 'nas_' . $k, $v );
        }
        $GLOBALS['nas_route_data'] = $data;

        // Enqueue NAS assets — these will be output by wp_head() inside the template.
        // DO NOT wrap the template in another HTML shell — every page template already
        // outputs its own <!DOCTYPE html>…</html> and calls wp_head()/wp_footer().
        add_filter( 'show_admin_bar', '__return_false', 999 );
        wp_enqueue_style(  'nas-fonts',    'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap', [], null );
        wp_enqueue_style(  'font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', [], '6.5.0' );
        wp_enqueue_style(  'nas-core',     NAS_ASSETS . 'css/nas-core.css',       ['nas-fonts'], NAS_VERSION );
        wp_enqueue_style(  'nas-booking',  NAS_ASSETS . 'css/nas-booking.css',    ['nas-core'],  NAS_VERSION );
        wp_enqueue_style(  'nas-city-pages', NAS_ASSETS . 'css/nas-city-pages.css', ['nas-core'], NAS_VERSION );
        // Note: nas-chat.css intentionally excluded — not needed on public static/city pages
        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'nas-core',       NAS_ASSETS . 'js/nas-core.js',    ['jquery'],    NAS_VERSION, true );
        wp_enqueue_script( 'nas-booking-js', NAS_ASSETS . 'js/nas-booking.js', ['nas-core'],  NAS_VERSION, true );
        wp_localize_script( 'nas-core', 'NAS', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'nas_action' ),
            'home_url'   => home_url( '/' ),
            'assets_url' => NAS_ASSETS,
            'currency'   => nas_config( 'currency_symbol', '₹' ),
            'user_id'    => get_current_user_id(),
            'user_role'  => \NAS\Core\Security::current_role(),
            'is_logged_in' => is_user_logged_in() ? 1 : 0,
            'version'    => NAS_VERSION,
        ] );

        ob_start();
        include $file;
        echo ob_get_clean();
        exit;
    }

    public function register( string $method, string $pattern, callable $handler ): void {}

    // TRACE: serve_login() — Called internally or via AJAX action.
    //        Steps: returns JSON error response on failure → redirects user.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function serve_login(): void {
        // login.php is a complete standalone HTML file - include it directly
        include NAS_PATH . 'templates/public/login.php';
        exit;
    }

    // TRACE: serve_vendor() — Called internally or via AJAX action.
    //        Steps: redirects user.
    //        Output: success/error JSON response.
    //        Edge cases: invalid input → error returned.
    private function serve_vendor(): void {
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/vendor-dashboard/'))) );
            exit;
        }

        $v = NAS_VERSION;
        $a = NAS_ASSETS;

        // Enqueue CSS — direct calls work at template_redirect time because
        // wp_enqueue_scripts fires DURING wp_head(), which we call below.
        add_filter( 'show_admin_bar', '__return_false', 999 );
        remove_action( 'wp_head', '_admin_bar_bump_cb' );

        wp_enqueue_style( 'nas-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Poppins:wght@400;500;600;700;800&display=swap',
            [], null );
        wp_enqueue_style( 'font-awesome',
            'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
            [], '6.5.0' );
        wp_enqueue_style( 'nas-core',      $a . 'css/nas-core.css',      ['nas-fonts'],       $v );
        wp_enqueue_style( 'nas-dashboard', $a . 'css/nas-dashboard.css', ['nas-core'],        $v );
        wp_enqueue_style( 'nas-chat',      $a . 'css/nas-chat.css',      ['nas-core'],        $v );
        wp_enqueue_style( 'nas-admin',     $a . 'css/nas-admin.css',     ['nas-core'],        $v );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'nas-core',
            $a . 'js/nas-core.js', ['jquery'], $v, true );
        wp_enqueue_script( 'nas-chat',
            $a . 'js/nas-chat.js', ['nas-core'], $v, true );
        wp_enqueue_script( 'nas-dashboard',
            $a . 'js/nas-dashboard.js', ['nas-core', 'nas-chat'], $v, true );

        $user = wp_get_current_user();
        wp_localize_script( 'nas-core', 'NAS', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'nas_action' ),
            'home_url'    => home_url( '/' ),
            'assets_url'  => NAS_ASSETS,
            'booking_url' => home_url( '/book-newspaper-ad/' ),
            'client_dash' => home_url( '/client-dashboard/' ),
            'admin_dash'  => home_url( '/admin-dashboard/' ),
            'vendor_dash' => home_url( '/vendor-dashboard/' ),
            'currency'    => nas_config( 'currency_symbol', '₹' ),
            'user_id'     => get_current_user_id(),
            'user_name'   => $user->display_name ?? '',
            'user_role'   => 'vendor',
            'is_logged_in'=> 1,
            'version'     => $v,
        ] );

        // Get vendor dashboard HTML via shortcode
        $content = do_shortcode( '[nas_vendor_dashboard]' );

        $cfg   = class_exists( '\NAS\Core\Config' ) ? \NAS\Core\Config::instance() : null;
        $brand = $cfg ? $cfg->get( 'brand_name', get_bloginfo('name') ) : get_bloginfo('name');
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vendor Portal — <?php echo esc_html( $brand ); ?></title>
<?php wp_head(); ?>
<style>
#wpadminbar,.wpadminbar{display:none!important}
html{margin-top:0!important;padding-top:0!important}
body{margin:0!important;padding:0!important;background:#f8fafc}
</style>
</head>
<body class="nas-fullpage">
<?php echo $content; ?>
<?php wp_footer(); ?>
</body>
</html>
        <?php
        exit;
    }

    // TRACE: serve_admin() — Called internally or via AJAX action.
    //        Steps: redirects user.
    //        Output: success/error JSON response.
    //        Edge cases: file existence checked before access.
    private function serve_admin(): void {
        $file = NAS_PLUGIN_DIR . 'templates/admin/layout.php';
        if ( ! file_exists( $file ) ) {
            wp_die( 'Admin template not found.' );
        }

        // Auth check
        if ( ! is_user_logged_in() ) {
            wp_redirect( home_url('/newspaper-ad-login/?redirect_to=' . urlencode(home_url('/admin-dashboard/'))) );
            exit;
        }
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'nas_manage_bookings' ) ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }

        // Set page from query var or GET param
        $nas_admin_page = get_query_var( 'nas_admin' ) ?: sanitize_key( $_GET['nas_admin'] ?? 'dashboard' );
        $nas_admin_id   = absint( $_GET['id'] ?? 0 );
        set_query_var( 'nas_admin_page', $nas_admin_page );
        set_query_var( 'nas_admin_id',   $nas_admin_id );

        // Enqueue admin assets BEFORE layout.php calls wp_head()
        // layout.php provides its own complete HTML document — do NOT wrap it again.
        add_filter( 'show_admin_bar', '__return_false', 999 );
        wp_enqueue_style(  'nas-fonts',     'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap', [], null );
        wp_enqueue_style(  'font-awesome',  'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css', [], '6.5.0' );
        wp_enqueue_style(  'nas-admin-css', NAS_ASSETS . 'css/nas-admin.css', ['nas-fonts', 'font-awesome'], NAS_VERSION );
        wp_enqueue_script( 'chart-js',      'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js', [], '4.4.0', true );
        wp_enqueue_script( 'nas-admin-js',  NAS_ASSETS . 'js/nas-admin.js', ['chart-js'], NAS_VERSION, true );

        // layout.php is a self-contained full HTML document (has its own DOCTYPE/head/body).
        // Include it directly — do not wrap in another HTML shell.
        ob_start();
        include $file;
        echo ob_get_clean();
        exit;
    }
}
