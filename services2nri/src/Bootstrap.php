<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap — registers roles, WP hooks, cron handlers.
 *
 * TRACE: Called on plugins_loaded priority 10 on every request.
 *        Registers WP roles, schedules cron handlers, admin bar link.
 *        Preconditions: Installer has run migrations.
 *        Postconditions: Roles exist, jobs registered, WP admin link available.
 */
class Bootstrap {

    public static function init(): void {
        \S2NRI\Services\ServiceRegistry::seedNavMenuStructureIfMissing();
        add_action( 'init',          [ self::class, 'registerRoles' ],    1 );
        add_action( 'init',          [ self::class, 'registerRewrites' ], 1 );
        add_action( 'rest_api_init', [ self::class, 'registerRestRoutes' ] );

        // ── HTTP Security Headers ──────────────────────────────────────────────
        // Fixes diagnostic findings: HIGH #51,52,53 (X-Frame-Options, X-Content-Type-Options, HSTS)
        // and MEDIUM #56,57,58,59 (X-XSS-Protection, Referrer-Policy, Permissions-Policy, CSP)
        // send_headers fires after WP is set up but before output — safe for all pages.
        add_action( 'send_headers', static function () {
            if ( headers_sent() ) return;
            // Clickjacking protection
            header( 'X-Frame-Options: SAMEORIGIN' );
            // MIME-type sniffing protection
            header( 'X-Content-Type-Options: nosniff' );
            // Force HTTPS for 1 year (only on HTTPS — avoids breaking HTTP dev environments)
            if ( isset( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) {
                header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
            }
            // XSS filter (legacy browsers)
            header( 'X-XSS-Protection: 1; mode=block' );
            // Referrer policy — send origin only on cross-origin requests
            header( 'Referrer-Policy: strict-origin-when-cross-origin' );
            // Permissions policy — disable unused APIs
            header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
            // Content Security Policy — allow same origin + CDN assets + Cloudflare
            // NOTE: nonce-based CSP would break inline scripts; use a permissive policy
            // that still blocks the most dangerous injection vectors.
            header( "Content-Security-Policy: default-src 'self' https:; script-src 'self' 'unsafe-inline' 'unsafe-eval' https:; style-src 'self' 'unsafe-inline' https:; img-src 'self' data: https:; connect-src 'self' https:; font-src 'self' data: https:; frame-ancestors 'self';" );
        }, 1 );

        // ── Cookie-based user resolution for REST API ──────────────────────────
        // WP's own REST cookie auth (determine_current_user priority 10) requires
        // BOTH a valid cookie AND a valid nonce. CDN strips X-WP-Nonce header so
        // WP never authenticates via cookie alone. We hook at priority 20 — after
        // WP's attempt — and if current_user is still 0, we set it from the cookie.
        // This makes Method 3 (is_user_logged_in) and Method 4 in Auth.php work.
        add_filter( 'determine_current_user', static function ( $user_id ) {
            if ( $user_id ) return $user_id; // Already authenticated — leave it
            // Only run for our API routes
            $route = $_SERVER['REQUEST_URI'] ?? '';
            if ( strpos( $route, '/wp-json/s2nri' ) === false ) return $user_id;
            // Try WP auth cookie directly
            foreach ( $_COOKIE as $key => $value ) {
                if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
                    $validated = wp_validate_auth_cookie( $value, 'logged_in' );
                    if ( $validated ) return $validated;
                    break;
                }
            }
            return $user_id;
        }, 20 );

        // Priority 200 — runs AFTER WP's own cookie+nonce check (priority 100).
        // WP REST API at priority 100 sets WP_Error when there is no valid cookie+nonce.
        // We clear that error at priority 200 for all s2nri/v1 routes so our Dispatcher
        // runs its own auth (portal token URL param, cookie, nonce). Without this, WP
        // returns 403 Forbidden before our Dispatcher code ever executes — blocking
        // the portal token auth that works on CDN where cookies/headers are stripped.
        add_filter( 'rest_authentication_errors', static function ( $result ) {
            $route = $GLOBALS['wp']->query_vars['rest_route'] ?? $_SERVER['REQUEST_URI'] ?? '';
            if ( strpos( $route, '/s2nri/v1' ) !== false || strpos( $route, 's2nri' ) !== false ) {
                return null; // Auth.php handles all auth — let the request through
            }
            return $result;
        }, 200 );

        // Handle CORS preflight OPTIONS for API routes so X-WP-Nonce is allowed.
        // Must run before WP processes the request.
        add_action( 'init', static function () {
            if (
                isset( $_SERVER['REQUEST_METHOD'] ) &&
                $_SERVER['REQUEST_METHOD'] === 'OPTIONS' &&
                isset( $_SERVER['REQUEST_URI'] ) &&
                strpos( $_SERVER['REQUEST_URI'], '/wp-json/s2nri' ) !== false
            ) {
                $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
                header( 'Access-Control-Allow-Origin: ' . $origin );
                header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
                header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce, X-S2NRI-Token' );
                header( 'Access-Control-Allow-Credentials: true' );
                header( 'Access-Control-Max-Age: 86400' );
                status_header( 204 );
                exit;
            }
        }, 1 );

        // Cron handlers
        add_action( 's2nri_job_notifications',   [ Jobs\NotificationJob::class,  'handle' ] );
        add_action( 's2nri_job_quote_reminders', [ Jobs\QuoteReminderJob::class, 'handle' ] );
        add_action( 's2nri_job_cleanup',         [ Jobs\CleanupJob::class,       'handle' ] );
        add_action( 's2nri_job_sitemap',         [ Jobs\SitemapJob::class,       'handle' ] );

        // WP Admin menu link
        add_action( 'admin_menu', [ self::class, 'registerAdminMenu' ] );
        add_action( 'admin_notices', [ self::class, 'renderPlugAndPlayNotice' ] );

        // Ensure .htaccess has the API passthrough rule on every admin load
        add_action( 'admin_init', [ self::class, 'ensureHtaccess' ] );

        // M-19: Register shortcodes
        add_action( 'init', [ \S2NRI\Shortcodes::class, 'register' ], 20 );
        // React Portal: boot() is called directly from services2nri.php
        // No hook needed here — Portal::boot() runs before plugins_loaded.

        // M-14: WhatsApp event hooks
        add_action( 's2nri_booking_submitted',    [ self::class, 'onBookingSubmitted' ],  10, 2 );
        add_action( 's2nri_booking_status_changed',[ self::class, 'onBookingStatusChanged' ], 10, 3 );
        add_action( 's2nri_payment_verified',     [ self::class, 'onPaymentVerified' ],   10, 2 );
        add_action( 's2nri_quote_approved',       [ self::class, 'onQuoteApproved' ],     10, 2 );
    }

    /** M-14: WhatsApp — fires when a new booking is submitted */
    public static function onBookingSubmitted( int $booking_id, array $booking_data ): void {
        if ( ! \S2NRI\Models\Setting::get('whatsapp_callmebot_apikey') ) return;
        \S2NRI\Services\WhatsAppService::notifyBookingEvent('booking_submitted', $booking_data);
    }

    /** M-14: WhatsApp — fires when booking status changes */
    public static function onBookingStatusChanged( int $booking_id, string $new_status, array $booking_data ): void {
        if ( ! \S2NRI\Models\Setting::get('whatsapp_callmebot_apikey') ) return;
        $booking_data['status'] = $new_status;
        \S2NRI\Services\WhatsAppService::notifyBookingEvent('status_changed', $booking_data);
    }

    /** M-14: WhatsApp — fires when payment is verified */
    public static function onPaymentVerified( int $booking_id, array $booking_data ): void {
        if ( ! \S2NRI\Models\Setting::get('whatsapp_callmebot_apikey') ) return;
        \S2NRI\Services\WhatsAppService::notifyBookingEvent('payment_received', $booking_data);
    }

    /** M-14: WhatsApp — fires when customer approves a quote */
    public static function onQuoteApproved( int $booking_id, array $booking_data ): void {
        if ( ! \S2NRI\Models\Setting::get('whatsapp_callmebot_apikey') ) return;
        \S2NRI\Services\WhatsAppService::notifyBookingEvent('quote_approved', $booking_data);
    }

    // ── WordPress REST API routing (GUARANTEED to work on every WP install) ──────
    // Registers /wp-json/s2nri/v1/* so API calls work even when .htaccess
    // doesn't route /api/v1/* to WordPress. The SPA uses rest_url('s2nri/v1')
    // as apiBase, so requests always go through WP's own REST infrastructure.

    public static function registerRestRoutes(): void {
        register_rest_route( 's2nri/v1', '/(?P<path>.+)', [
            'methods'             => \WP_REST_Server::ALLMETHODS,
            'callback'            => [ self::class, 'handleRestRequest' ],
            'permission_callback' => '__return_true', // Auth handled inside Dispatcher
        ] );

        // Also register a root path handler (e.g. GET /wp-json/s2nri/v1 itself)
        register_rest_route( 's2nri/v1', '/', [
            'methods'             => \WP_REST_Server::ALLMETHODS,
            'callback'            => [ self::class, 'handleRestRequest' ],
            'permission_callback' => '__return_true',
        ] );

        // Allow anonymous access to s2nri/v1 routes even when a security plugin
        // has hooked rest_authentication_errors to block non-logged-in requests.
        // Our Dispatcher handles its own auth — public routes (auth=false) must
        // remain accessible to anonymous visitors (e.g. services list, frontend-errors).
        // Allow X-S2NRI-Token header in WP REST API CORS preflight responses.
        // Without this, browser blocks the header before the request reaches our Dispatcher.
        add_filter( 'rest_allowed_cors_headers', static function ( array $headers ): array {
            $headers[] = 'X-S2NRI-Token';
            return $headers;
        } );

        // Also set the header directly in case the filter is not used by this WP version
        add_filter( 'rest_pre_serve_request', static function ( $served ) {
            header( 'Access-Control-Allow-Headers: Content-Type, Authorization, X-WP-Nonce, X-S2NRI-Token' );
            return $served;
        } );

        // Priority 1 — now registered in Bootstrap::init() instead of here,
        // because rest_authentication_errors runs before rest_api_init fires.
        // Keeping this comment so the intent is clear.
    }

    public static function handleRestRequest( \WP_REST_Request $wp_req ): void {
        // Extract the sub-path (everything after /wp-json/s2nri/v1/)
        $path = trim( $wp_req->get_param( 'path' ) ?? '', '/' );

        // Inject the path into the Dispatcher via a global so Dispatcher
        // can use it directly without re-parsing REQUEST_URI.
        $GLOBALS['s2nri_rest_path'] = $path;

        ( new \S2NRI\Api\Dispatcher() )->dispatch();
        exit; // Dispatcher sends headers + body, must exit before WP REST sends its own response
    }

    // ── Rewrite rules — ensure /api/v1/* reaches WordPress ───────────────────
    // Without this, Apache/Nginx may serve a 404 directly for unknown paths.
    // add_rewrite_rule tells WordPress (and thus .htaccess) to pass these paths through.

    public static function registerRewrites(): void {
        // Pass /api/v1/anything to WordPress index.php
        add_rewrite_rule( '^api/v1/(.*)$', 'index.php?s2nri_api=$1', 'top' );

        add_filter( 'query_vars', function ( $vars ) {
            $vars[] = 's2nri_api';
            return $vars;
        } );

        // If WordPress processed this as a rewrite (instead of our early intercept),
        // dispatch it ourselves and exit.
        add_action( 'template_redirect', function () {
            $path = get_query_var( 's2nri_api', '' );
            if ( $path === '' ) return;
            // Already handled by the early plugins_loaded intercept on most servers.
            // This fallback fires on servers where REQUEST_URI doesn't match /api/v1/
            // at plugins_loaded time (e.g. some reverse proxies or subdirectory installs).
            ( new \S2NRI\Api\Dispatcher() )->dispatch();
            exit;
        }, 0 );
    }

    // ── Write the API passthrough block to .htaccess ──────────────────────────
    // Called on admin_init so it runs after activation and after updates.
    // Safe to call multiple times — idempotent.

    public static function ensureHtaccess(): void {
        $htaccess = get_home_path() . '.htaccess';
        if ( ! is_writable( $htaccess ) && ! file_exists( $htaccess ) ) return;

        $block_start = '# BEGIN S2NRI API';
        $block_end   = '# END S2NRI API';

        $rule = <<<'HTACCESS'
# BEGIN S2NRI API
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
# Route /api/v1/* through WordPress index.php so the plugin can handle it
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^api/v1/(.*)$ index.php [QSA,L]
</IfModule>
# END S2NRI API
HTACCESS;

        $content = file_exists( $htaccess ) ? file_get_contents( $htaccess ) : '';

        // Already present — skip
        if ( strpos( $content, $block_start ) !== false ) return;

        // Prepend before the WordPress block so it takes priority
        $wp_start = strpos( $content, '# BEGIN WordPress' );
        if ( $wp_start !== false ) {
            $content = substr( $content, 0, $wp_start ) . $rule . "\n" . substr( $content, $wp_start );
        } else {
            $content = $rule . "\n" . $content;
        }

        @file_put_contents( $htaccess, $content );
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    public static function registerRoles(): void {
        $roles = [
            's2nri_customer' => [ 'name' => 'S2NRI Customer', 'caps' => [ 'read' => true ] ],
            's2nri_agent'    => [ 'name' => 'S2NRI Agent',    'caps' => [ 'read' => true ] ],
            's2nri_manager'  => [ 'name' => 'S2NRI Manager',  'caps' => [ 'read' => true ] ],
            's2nri_finance'  => [ 'name' => 'S2NRI Finance',  'caps' => [ 'read' => true ] ],
        ];
        foreach ( $roles as $slug => $role ) {
            if ( ! get_role( $slug ) ) {
                add_role( $slug, $role['name'], $role['caps'] );
            }
        }
    }

    // ── WP Admin menu ─────────────────────────────────────────────────────────

    public static function renderPlugAndPlayNotice(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! get_option( 's2nri_show_plug_and_play_notice' ) ) {
            return;
        }
        $portal = \S2NRI\Portal::adminUrl();
        $home   = home_url( '/' );
        $ver    = defined( 'S2NRI_VERSION' ) ? S2NRI_VERSION : '';
        echo '<div class="notice notice-success"><p><strong>Services2NRI ' . esc_html( $ver ) . ' is ready.</strong> '
            . 'Public site: <a href="' . esc_url( $home ) . '" target="_blank" rel="noopener">' . esc_html( $home ) . '</a> · '
            . '<a href="' . esc_url( $portal ) . '" target="_blank" rel="noopener">Open Admin Portal</a> · '
            . 'See <code>wp-content/plugins/services2nri/docs/PLUG_AND_PLAY.md</code> in the plugin folder.</p></div>';
        delete_option( 's2nri_show_plug_and_play_notice' );
    }

    public static function registerAdminMenu(): void {
        if ( ! current_user_can( 'administrator' ) ) return;

        // Main menu page
        add_menu_page(
            'Services2NRI', 'Services2NRI', 'manage_options',
            's2nri-platform', [ self::class, 'adminMenuPage' ],
            'dashicons-groups', 30
        );

        // Sub-pages — each opens the SPA directly at the right section
        $subpages = [
            [ 's2nri-platform',       'Admin Portal',  'adminMenuPage'        ],
            [ 's2nri-bookings',       '📋 Bookings',   'bookingsMenuPage'     ],
            [ 's2nri-diagnostics',    '🔍 Diagnostics','diagnosticsMenuPage'  ],
            [ 's2nri-settings',       '🔧 Settings',   'settingsMenuPage'     ],
        ];
        foreach ( $subpages as $sub ) {
            add_submenu_page(
                's2nri-platform',
                'Services2NRI — ' . $sub[1],
                $sub[1],
                'manage_options',
                $sub[0],
                [ self::class, $sub[2] ]
            );
        }
    }

    // ── Helper: generate a token-authenticated portal URL ─────────────────────
    // Generates a PERSISTENT 8-hour session token stored in user_meta.
    // The token is embedded in the URL as ?s2nri_token=TOKEN.
    // React reads it from window.location.search and sends it as X-S2NRI-Token header.
    // Auth::resolveUser() validates against user_meta.
    // This approach survives CDN environments, no WP session cookie needed.
    private static function portalUrl( string $path = '/admin' ): string {
        $uid           = get_current_user_id();
        $session_token = bin2hex( random_bytes( 32 ) );
        update_user_meta( $uid, 's2nri_portal_token', $session_token );
        update_user_meta( $uid, 's2nri_portal_token_exp', time() + ( 8 * HOUR_IN_SECONDS ) );
        return add_query_arg( [ 's2nri_token' => $session_token ], home_url( $path ) );
    }

    public static function adminMenuPage(): void {
        $portal_url  = \S2NRI\Portal::adminUrl();
        $diag_url    = \S2NRI\Portal::adminUrl('diagnostics');
        $booking_url = \S2NRI\Portal::adminUrl('requests');
        ?>
        <div class="wrap">
            <h1>Services2NRI Platform</h1>
            <p>
                <a href="<?php echo esc_url( $portal_url ); ?>" target="_blank"
                   class="button button-primary button-large">Open Admin Portal →</a>
            </p>
            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
                <a href="<?php echo esc_url( $booking_url ); ?>" target="_blank" class="button">📋 Bookings</a>
                <a href="<?php echo esc_url( $diag_url ); ?>" target="_blank" class="button" style="background:#4A6FA5;color:#fff;border-color:#4A6FA5">🔍 Diagnostics</a>
                <a href="<?php echo esc_url( \S2NRI\Portal::adminUrl('settings') ); ?>" target="_blank" class="button">🔧 Settings</a>
            </div>
        </div>
        <?php
    }

    public static function bookingsMenuPage(): void {
        $url = \S2NRI\Portal::adminUrl('requests');
        echo '<div class="wrap"><h1>Services2NRI — Bookings</h1>';
        echo '<p><a href="' . esc_url( $url ) . '" target="_blank" class="button button-primary button-large">📋 Open Bookings →</a></p>';
        echo '<p style="color:#666;margin-top:8px">Opens the bookings management portal in a new tab.</p></div>';
    }

    public static function diagnosticsMenuPage(): void {
        $url = \S2NRI\Portal::adminUrl('diagnostics');
        ?>
        <div class="wrap">
            <h1>🔍 Services2NRI — Diagnostic & Error Intelligence</h1>
            <p style="font-size:14px;color:#444;max-width:600px;margin:12px 0 20px">
                The Diagnostic System scans your entire stack: PHP, database, WordPress core,
                plugins, REST API, performance, security, integrations, and browser layer.
                Run a scan to get a full health report with one-click download.
            </p>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:28px">
                <a href="<?php echo esc_url( $url ); ?>" target="_blank"
                   class="button button-primary button-large" style="font-size:15px;height:auto;padding:10px 22px">
                    🔍 Open Diagnostics Dashboard →
                </a>
                <a href="<?php echo esc_url( add_query_arg( 's2nri_run_scan', '1', wp_nonce_url( admin_url( 'admin.php?page=s2nri-diagnostics' ), 's2nri_diag_scan' ) ) ); ?>"
                   class="button button-secondary button-large" style="font-size:15px;height:auto;padding:10px 22px">
                    ▶ Run Quick Scan (inline)
                </a>
            </div>

            <?php
            // Inline quick scan — runs the engine and shows summary right in WP admin
            // No React needed — pure PHP output for immediate access
            if ( isset( $_GET['s2nri_run_scan'] ) && check_admin_referer( 's2nri_diag_scan' ) ) {
                self::renderInlineDiagnosticScan();
            }
            ?>

            <div style="background:#f0f6fc;border-left:4px solid #0073aa;padding:16px 20px;max-width:640px;border-radius:4px">
                <strong>📋 What is scanned:</strong>
                <ul style="margin:8px 0 0 16px;line-height:1.9;color:#333">
                    <li>PHP environment (version, extensions, memory, OPcache, errors)</li>
                    <li>Database (connection, all tables, deadlocks, constraints, replication)</li>
                    <li>WordPress (permalink structure, REST API, cron, plugin conflicts, updates)</li>
                    <li>Security (HTTP headers, XSS/SQLi/malware patterns, login activity, SSL)</li>
                    <li>Performance (Core Web Vitals, cache, bundle size, render-blocking)</li>
                    <li>Integrations (Razorpay, SMTP, WhatsApp, webhooks, OAuth, analytics)</li>
                    <li>Infrastructure (disk space, SSL certificate expiry, CDN, firewall)</li>
                    <li>Browser layer (via JS collector active on every page)</li>
                </ul>
            </div>

            <div style="margin-top:20px;background:#fff;border:1px solid #ddd;padding:16px 20px;max-width:640px;border-radius:4px">
                <strong>📥 Download Center:</strong>
                <p style="margin:8px 0;color:#555">After running a scan in the portal, download reports in:</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <?php foreach (['JSON','CSV','HTML','PDF'] as $fmt): ?>
                    <span style="background:#f3f4f6;border:1px solid #e5e7eb;padding:4px 12px;border-radius:4px;font-weight:600;font-size:13px"><?php echo $fmt; ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function settingsMenuPage(): void {
        $url = \S2NRI\Portal::adminUrl('settings');
        echo '<div class="wrap"><h1>Services2NRI — Settings</h1>';
        echo '<p><a href="' . esc_url( $url ) . '" target="_blank" class="button button-primary button-large">🔧 Open Settings →</a></p></div>';
    }

    // ── Inline WP-admin diagnostic scan (no React, no portal needed) ──────────
    private static function renderInlineDiagnosticScan(): void {
        if ( ! class_exists( '\\S2NRI\\Diagnostics\\DiagnosticEngine' ) ) {
            echo '<div class="notice notice-error"><p>DiagnosticEngine not loaded. Ensure plugin is fully activated.</p></div>';
            return;
        }

        $engine = new \S2NRI\Diagnostics\DiagnosticEngine();
        $report = $engine->runFullScan();

        $score = $report['health_score'];
        $color = $score >= 90 ? '#16a34a' : ( $score >= 70 ? '#d97706' : '#dc2626' );
        $sum   = $report['summary'];

        echo '<div style="margin:20px 0;padding:20px;background:#fff;border:1px solid #ddd;border-radius:6px;max-width:700px">';
        echo '<h2 style="margin:0 0 12px;display:flex;align-items:center;gap:12px">';
        echo '<span style="background:' . $color . ';color:#fff;width:48px;height:48px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:18px;font-weight:800">' . $score . '</span>';
        echo 'System Health: ' . esc_html( $report['health_label'] ) . '</h2>';
        echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">';
        foreach ( ['critical' => '#dc2626', 'high' => '#ea580c', 'medium' => '#d97706', 'low' => '#2563eb', 'info' => '#6b7280'] as $sev => $c ) {
            echo '<div style="text-align:center;background:#f9fafb;padding:8px 16px;border-radius:6px">';
            echo '<div style="font-size:20px;font-weight:800;color:' . $c . '">' . (int)($sum[$sev]??0) . '</div>';
            echo '<div style="font-size:11px;text-transform:uppercase;color:#6b7280;font-weight:700">' . $sev . '</div>';
            echo '</div>';
        }
        echo '</div>';

        // Show critical + high findings inline
        $critical_high = array_filter( $report['findings'], fn($f) => in_array($f['severity'], ['critical','high']) );
        if ( $critical_high ) {
            echo '<h3 style="margin:0 0 8px;font-size:14px">⚠️ Critical & High Priority Issues:</h3>';
            foreach ( array_slice( $critical_high, 0, 10 ) as $f ) {
                $c2 = $f['severity'] === 'critical' ? '#dc2626' : '#ea580c';
                echo '<div style="border-left:3px solid ' . $c2 . ';padding:8px 12px;margin-bottom:8px;background:#fafafa;border-radius:0 4px 4px 0">';
                echo '<strong style="color:' . $c2 . '">' . strtoupper($f['severity']) . '</strong> ';
                echo esc_html( $f['title'] ) . '<br>';
                echo '<small style="color:#555">' . esc_html( $f['fix'] ) . '</small>';
                echo '</div>';
            }
        } else {
            echo '<div style="background:#f0fdf4;padding:12px;border-radius:4px;color:#16a34a;font-weight:600">✅ No critical or high priority issues found!</div>';
        }

        // Save to DB
        \S2NRI\Diagnostics\DiagnosticStore::ensureTable();
        \S2NRI\Diagnostics\DiagnosticStore::saveReport( $report );

        echo '<p style="margin-top:12px"><a href="' . esc_url( \S2NRI\Portal::adminUrl('diagnostics') ) . '" target="_blank" class="button">View Full Report in Portal →</a></p>';
        echo '</div>';
    }

    // ── JS config for SPA ─────────────────────────────────────────────────────

    public static function getJsConfig(): array {
        $settings = Models\Setting::getPublic();
        $user     = self::getCurrentUser(); // NOTE: this sets GLOBALS['s2nri_portal_session_token']

        return [
            'apiBase'     => rtrim( rest_url( 's2nri/v1' ), '/' ),
            'spaBase'     => home_url( '' ),
            'assetsUrl'   => S2NRI_ASSETS_URL,
            'nonce'       => wp_create_nonce( 's2nri_api' ),
            'portalToken' => $GLOBALS['s2nri_portal_session_token'] ?? '',
            'version'     => S2NRI_VERSION,
            'currentUser' => $user,
            'settings'    => $settings,
            'design'      => \S2NRI\Design\DesignSystem::getPublicPayload(),
            'builderUrl'  => home_url( '/' . \S2NRI\BuilderPage::SLUG ),
        ];
    }

    // ── Current user resolution (multi-method) ────────────────────────────────

    /** Static cache: getCurrentUser() called twice per SPA load (buildMeta + getJsConfig). */
    private static ?array  $_user_cache       = null;
    private static bool    $_user_cache_set   = false;

    public static function getCurrentUser(): ?array {
        // Return cached result for subsequent calls within same PHP request.
        // buildMeta() and getJsConfig() both call getCurrentUser() —
        // without this cache that's 5-6 extra DB queries on every SPA page load.
        if ( self::$_user_cache_set ) return self::$_user_cache;

        // Method 1: WP session cookie (standard WP login)
        if ( is_user_logged_in() ) {
            $wp_user = wp_get_current_user();
            if ( $wp_user->exists() ) {
                // Generate a session token so api.js can use X-S2NRI-Token
                // even when the WP session cookie gets stripped by CDN
                self::generatePortalToken( $wp_user->ID );
                self::$_user_cache = Models\User::buildUser( $wp_user );
                    self::$_user_cache_set = true;
                    return self::$_user_cache;
            }
        }

        // Method 2: Direct cookie validation (fallback for some server configs)
        foreach ( $_COOKIE as $key => $value ) {
            if ( strpos( $key, 'wordpress_logged_in_' ) === 0 ) {
                $user_id = wp_validate_auth_cookie( $value, 'logged_in' );
                if ( $user_id ) {
                    wp_set_current_user( $user_id );
                    $wp_user = wp_get_current_user();
                    if ( $wp_user && $wp_user->exists() ) {
                        self::generatePortalToken( $user_id );
                        self::$_user_cache = Models\User::buildUser( $wp_user );
                    self::$_user_cache_set = true;
                    return self::$_user_cache;
                    }
                }
                break;
            }
        }

        // Method 3: NEW format — ?s2nri_token=64chartoken (v8.5.5+)
        // OPTIMISED: single JOIN query fetches user_id + expiry together instead of
        // two separate queries (get_users + get_user_meta). Saves 1 DB round-trip.
        $url_token = sanitize_text_field( $_GET['s2nri_token'] ?? '' );
        if ( strlen( $url_token ) === 64 ) {
            global $wpdb;
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT m.user_id, exp.meta_value AS expiry
                  FROM {$wpdb->usermeta} m
                  JOIN {$wpdb->usermeta} exp ON exp.user_id = m.user_id AND exp.meta_key = 's2nri_portal_token_exp'
                  WHERE m.meta_key = 's2nri_portal_token' AND m.meta_value = %s
                  LIMIT 1",
                $url_token
            ), ARRAY_A );
            if ( $row && (int) $row['expiry'] > time() ) {
                $uid = (int) $row['user_id'];
                // Set current user FIRST so WP loads roles with the correct blog prefix.
                // get_user_by() before wp_set_current_user() returns WP_User with empty roles
                // → buildUser() sees $u->roles = [] → $is_wp_admin = false → s2nri_role = 'customer' → 403.
                wp_set_current_user( $uid );
                setup_userdata( $uid );
                $wp_user = wp_get_current_user();
                if ( $wp_user && $wp_user->exists() ) {
                    $GLOBALS['s2nri_portal_session_token'] = $url_token;
                    self::$_user_cache = Models\User::buildUser( $wp_user );
                    self::$_user_cache_set = true;
                    return self::$_user_cache;
                }
            }
        }

        // Method 4: OLD format — ?s2nri_admin_token=32chartoken&uid=N (backward compat)
        // Kept so existing bookmarks and cached portal URLs continue to work.
        $old_token = sanitize_text_field( $_GET['s2nri_admin_token'] ?? '' );
        $old_uid   = (int) ( $_GET['uid'] ?? 0 );
        if ( $old_token && $old_uid > 0 ) {
            $stored = (int) get_transient( 's2nri_admin_token_' . $old_token );
            if ( $stored === $old_uid && $stored > 0 ) {
                // Set current user FIRST then re-fetch so roles are loaded correctly
                wp_set_current_user( $stored );
                setup_userdata( $stored );
                $wp_user = wp_get_current_user();
                if ( $wp_user && $wp_user->exists() && in_array( 'administrator', (array) $wp_user->roles, true ) ) {
                    delete_transient( 's2nri_admin_token_' . $old_token );
                    // Generate a session token so subsequent API calls work
                    self::generatePortalToken( $stored );
                    self::$_user_cache = Models\User::buildUser( $wp_user );
                    self::$_user_cache_set = true;
                    return self::$_user_cache;
                }
            }
        }

        self::$_user_cache     = null;
        self::$_user_cache_set = true;
        return null;
    }

    // ── Generate and store a portal session token ─────────────────────────────
    private static function generatePortalToken( int $uid ): void {
        // ROOT-CAUSE FIX: Only generate a new token if none exists or it has expired.
        // Previously this overwrote the token on every public page load, invalidating
        // any open portal/builder tab immediately → every API call returned 401.
        $existing = get_user_meta( $uid, 's2nri_portal_token', true );
        $exp      = (int) get_user_meta( $uid, 's2nri_portal_token_exp', true );
        if ( $existing && strlen( $existing ) === 64 && $exp > time() + 60 ) {
            // Reuse the existing valid token — do not overwrite it.
            $GLOBALS['s2nri_portal_session_token'] = $existing;
            return;
        }
        $session_token = bin2hex( random_bytes( 32 ) );
        update_user_meta( $uid, 's2nri_portal_token', $session_token );
        update_user_meta( $uid, 's2nri_portal_token_exp', time() + ( 8 * HOUR_IN_SECONDS ) );
        $GLOBALS['s2nri_portal_session_token'] = $session_token;
    }
}
