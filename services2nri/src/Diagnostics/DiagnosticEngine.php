<?php
namespace S2NRI\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * DiagnosticEngine — Universal error detection, classification, and root cause analysis.
 *
 * TRACE: Runs a full-stack diagnostic scan across all layers:
 *   PHP environment → WordPress core → database → plugins → API → performance → security.
 *   Returns structured findings with severity, root cause, and fix recommendations.
 * Preconditions: WordPress loaded, $wpdb available.
 * Postconditions: Returns array of findings, each with classification, evidence, and guidance.
 */
class DiagnosticEngine {

    /** Severity constants */
    const SEV_CRITICAL = 'critical'; // system-breaking
    const SEV_HIGH     = 'high';     // significant impact
    const SEV_MEDIUM   = 'medium';   // degraded functionality
    const SEV_LOW      = 'low';      // minor issue
    const SEV_INFO     = 'info';     // informational

    /** Category constants */
    const CAT_PHP         = 'php_environment';
    const CAT_DB          = 'database';
    const CAT_WP          = 'wordpress';
    const CAT_API         = 'api_rest';
    const CAT_PLUGIN      = 'plugin_conflict';
    const CAT_PERFORMANCE = 'performance';
    const CAT_SECURITY    = 'security';
    const CAT_NETWORK     = 'network';
    const CAT_INTEGRATION = 'integration';
    const CAT_FRONTEND    = 'frontend';
    const CAT_INFRA       = 'infrastructure';
    const CAT_UNKNOWN     = 'unknown_anomaly';

    private array  $findings    = [];
    private float  $start_time;
    private array  $start_stats;

    public function __construct() {
        $this->start_time  = microtime( true );
        $this->start_stats = [
            'memory' => memory_get_usage( true ),
            'peak'   => memory_get_peak_usage( true ),
        ];
    }

    // ── PUBLIC API ────────────────────────────────────────────────────────────

    public function runFullScan(): array {
        $this->findings = [];

        // Each scan method is individually wrapped in try/catch.
        // If one scan crashes (DB permission error, outbound HTTP timeout, missing PHP function)
        // it records itself as a CRITICAL finding and the remaining scans continue.
        // This guarantees the full scan always returns a result, never a 500 error.
        $methods = [
            'scanPhpEnvironment',
            'scanPhpRuntimeErrors',
            'scanDatabase',
            'scanDatabaseDeep',
            'scanWordPress',
            'scanWordPressDeep',
            'scanPluginConflicts',
            'scanApiRoutes',
            'scanPerformance',
            'scanPerformanceDeep',
            'scanSecurity',
            'scanSecurityDeep',
            'scanIntegrations',
            'scanIntegrationsDeep',
            'scanInfrastructure',
            'scanNetworkLayer',
            'scanFrontendAssets',
            'scanAccessibility',
            'scanCronJobs',
            'scanBrowserCapabilities',
            'scanDependencies',
            'scanReplication',
            'scanThemeUpdates',
            'scanAnalyticsAndCRM',
            'scanS2NRISpecific',
            'runAnomalyDetection',
        ];

        foreach ( $methods as $method ) {
            try {
                $this->$method();
            } catch ( \Throwable $e ) {
                // Record the crash as a finding — never let one scan kill the whole run
                $this->findings[] = [
                    'id'                 => 's2nri_diag_crash_' . $method,
                    'severity'           => self::SEV_CRITICAL,
                    'category'           => self::CAT_PHP,
                    'title'              => "Scan method crashed: {$method}()",
                    'description'        => get_class( $e ) . ': ' . $e->getMessage(),
                    'evidence'           => [
                        'method'  => $method,
                        'file'    => str_replace( ABSPATH, '', $e->getFile() ),
                        'line'    => $e->getLine(),
                        'class'   => get_class( $e ),
                    ],
                    'fix'                => "Check PHP error log for details. The scan method {$method}() threw an exception. Fix the underlying issue and re-run.",
                    'prevention'         => 'Keep PHP, WordPress, and database server updated. Ensure the DB user has SELECT privilege on all required tables.',
                    'impact'             => 'This scan section could not complete. Some issues in this category may be undetected.',
                    'reproduction_steps' => [ 'Enable WP_DEBUG_LOG=true in wp-config.php', 'Run the diagnostic scan again', 'Check wp-content/debug.log for the full stack trace' ],
                    'location'           => [ 'file' => str_replace( ABSPATH, '', $e->getFile() ), 'function' => $method ],
                    'detected_at'        => current_time( 'c' ),
                ];
            }
        }

        return $this->buildReport();
    }

    // ── PHP ENVIRONMENT ───────────────────────────────────────────────────────

    private function scanPhpEnvironment(): void {
        $version = PHP_VERSION;
        if ( version_compare( $version, '8.0', '<' ) ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_PHP,
                'PHP version below minimum requirement',
                "PHP {$version} detected. Services2NRI requires PHP 8.0+.",
                [ 'current' => $version, 'required' => '8.0.0' ],
                'Upgrade PHP to 8.1 or higher via hosting control panel.',
                __FILE__, __FUNCTION__
            );
        } elseif ( version_compare( $version, '8.1', '<' ) ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PHP,
                'PHP version below recommended',
                "PHP {$version} meets minimum but PHP 8.1+ recommended.",
                [ 'current' => $version, 'recommended' => '8.1.0' ],
                'Upgrade to PHP 8.1+ for best performance and security.',
                __FILE__, __FUNCTION__
            );
        }

        // Required extensions
        $required_ext = [ 'json', 'mysqli', 'curl', 'mbstring', 'openssl', 'zip' ];
        foreach ( $required_ext as $ext ) {
            if ( ! extension_loaded( $ext ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_PHP,
                    "PHP extension missing: {$ext}",
                    "The '{$ext}' PHP extension is required but not loaded.",
                    [ 'extension' => $ext ],
                    "Enable the '{$ext}' extension in php.ini or ask your host to enable it.",
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Memory limit
        $mem_limit = $this->parseMemoryLimit( ini_get( 'memory_limit' ) );
        if ( $mem_limit !== -1 && $mem_limit < 128 * 1024 * 1024 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PHP,
                'PHP memory_limit too low',
                "memory_limit is " . ini_get( 'memory_limit' ) . ". Minimum 128M required.",
                [ 'current' => ini_get( 'memory_limit' ), 'required' => '128M' ],
                "Set memory_limit = 256M in php.ini or wp-config.php: define('WP_MEMORY_LIMIT','256M');",
                __FILE__, __FUNCTION__
            );
        }

        // max_execution_time
        $exec_time = (int) ini_get( 'max_execution_time' );
        if ( $exec_time > 0 && $exec_time < 30 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PHP,
                'PHP max_execution_time too low',
                "max_execution_time={$exec_time}s. Long operations (PDF export, bulk import) may time out.",
                [ 'current' => $exec_time, 'recommended' => 60 ],
                'Set max_execution_time = 120 in php.ini.',
                __FILE__, __FUNCTION__
            );
        }

        // upload_max_filesize
        $upload_max = $this->parseMemoryLimit( ini_get( 'upload_max_filesize' ) );
        $post_max   = $this->parseMemoryLimit( ini_get( 'post_max_size' ) );
        if ( $upload_max < 10 * 1024 * 1024 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PHP,
                'upload_max_filesize too low for document uploads',
                "upload_max_filesize=" . ini_get( 'upload_max_filesize' ) . ". Documents > this size will fail to upload.",
                [ 'current' => ini_get( 'upload_max_filesize' ), 'recommended' => '10M' ],
                'Set upload_max_filesize = 20M and post_max_size = 25M in php.ini.',
                __FILE__, __FUNCTION__
            );
        }

        // WP_DEBUG leaking to screen in production
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_SECURITY,
                'WP_DEBUG_DISPLAY enabled — errors visible to public',
                'WP_DEBUG_DISPLAY=true exposes stack traces and internal paths to visitors.',
                [ 'WP_DEBUG' => 'true', 'WP_DEBUG_DISPLAY' => 'true' ],
                "Set define('WP_DEBUG_DISPLAY', false) and define('WP_DEBUG_LOG', true) in wp-config.php.",
                __FILE__, __FUNCTION__
            );
        }

        // opcache
        if ( ! extension_loaded( 'Zend OPcache' ) ) {
            $this->addFinding( self::SEV_LOW, self::CAT_PERFORMANCE,
                'OPcache not enabled',
                'PHP OPcache dramatically improves performance. Not detected on this server.',
                [],
                'Enable opcache in php.ini: opcache.enable=1. Ask host to enable if on shared hosting.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── DATABASE ──────────────────────────────────────────────────────────────

    private function scanDatabase(): void {
        global $wpdb;

        // Connection check
        if ( ! $wpdb->check_connection( false ) ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_DB,
                'Database connection failed',
                'WordPress cannot connect to MySQL/MariaDB. All data operations will fail.',
                [ 'host' => DB_HOST, 'name' => DB_NAME ],
                'Check DB_HOST, DB_USER, DB_PASSWORD, DB_NAME in wp-config.php. Verify database server is running.',
                __FILE__, __FUNCTION__
            );
            return;
        }

        // MySQL version
        $mysql_ver = $wpdb->get_var( 'SELECT VERSION()' );
        if ( $mysql_ver && version_compare( $mysql_ver, '5.6', '<' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                'MySQL version outdated',
                "MySQL {$mysql_ver} detected. Minimum 5.6 required; 8.0+ recommended.",
                [ 'version' => $mysql_ver ],
                'Upgrade MySQL to 8.0 or MariaDB 10.6+.',
                __FILE__, __FUNCTION__
            );
        }

        // Check all required S2NRI tables exist
        $p = $wpdb->prefix;
        $required_tables = [
            's2nri_settings', 's2nri_categories', 's2nri_services',
            's2nri_customers', 's2nri_bookings', 's2nri_quotes',
            's2nri_documents', 's2nri_messages', 's2nri_notifications',
            's2nri_audit_log', 's2nri_staff', 's2nri_reviews',
            's2nri_payments', 's2nri_tickets', 's2nri_ticket_messages',
            's2nri_blog_posts', 's2nri_cities', 's2nri_faqs',
            's2nri_diagnostics_log',
        ];
        $existing_tables = $wpdb->get_col( "SHOW TABLES LIKE '{$p}s2nri_%'" );
        foreach ( $required_tables as $table ) {
            if ( ! in_array( $p . $table, $existing_tables, true ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                    "Missing database table: {$p}{$table}",
                    "Table {$p}{$table} does not exist. Features depending on it will fail.",
                    [ 'table' => $p . $table ],
                    'Go to WP Admin → Services2NRI → Open Admin Portal → Services → ⚡ Reseed Missing. Or deactivate/reactivate the plugin.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Orphan bookings (customer_id = 0)
        if ( in_array( $p . 's2nri_bookings', $existing_tables, true ) ) {
            $orphan_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings WHERE customer_id = 0" );
            if ( $orphan_count > 0 ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_DB,
                    "Orphan bookings with customer_id=0 ({$orphan_count} rows)",
                    "These bookings were created before customer profile was linked and are invisible to the user.",
                    [ 'count' => $orphan_count ],
                    'The system auto-repairs these on next customer dashboard load. Alternatively, run the SQL repair from the Diagnostics page.',
                    __FILE__, __FUNCTION__
                );
            }

            // Slow query check — bookings table size
            $booking_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}s2nri_bookings" );
            if ( $booking_count > 10000 ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                    'Bookings table is large — index verification recommended',
                    "{$booking_count} bookings rows. Queries may slow without proper indexes.",
                    [ 'rows' => $booking_count ],
                    'Run OPTIMIZE TABLE on bookings and verify indexes exist on customer_id, status, created_at.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // wpdb last error
        if ( $wpdb->last_error ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                'Recent database error detected',
                "wpdb recorded a recent error: {$wpdb->last_error}",
                [ 'error' => $wpdb->last_error, 'query' => $wpdb->last_query ],
                'Review the last query shown above. Check for schema mismatches or syntax errors.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── WORDPRESS CORE ────────────────────────────────────────────────────────

    private function scanWordPress(): void {
        global $wp_version;

        // WP version
        if ( version_compare( $wp_version, '6.0', '<' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_WP,
                'WordPress version outdated',
                "WordPress {$wp_version} detected. Services2NRI requires WP 6.0+.",
                [ 'version' => $wp_version, 'required' => '6.0' ],
                'Update WordPress to the latest stable release via Dashboard → Updates.',
                __FILE__, __FUNCTION__
            );
        }

        // Permalink structure
        $permalink = get_option( 'permalink_structure', '' );
        if ( empty( $permalink ) ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_WP,
                'WordPress permalink structure is "Plain" — API routes will not work',
                'Plain permalinks disable pretty URLs. All /wp-json/* and /api/v1/* calls return 404.',
                [ 'current_structure' => 'plain' ],
                'Go to WP Admin → Settings → Permalinks → select "Post name" → Save Changes.',
                __FILE__, __FUNCTION__
            );
        }

        // REST API accessible
        $rest_url = rest_url( 's2nri/v1/settings/public' );
        $response = wp_remote_get( $rest_url, [ 'timeout' => 3, 'sslverify' => false ] );
        if ( is_wp_error( $response ) ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_API,
                'S2NRI REST API unreachable',
                "GET {$rest_url} failed: " . $response->get_error_message(),
                [ 'url' => $rest_url, 'error' => $response->get_error_message() ],
                'Check if REST API is blocked by security plugin (e.g. Wordfence). Also verify permalinks are not "Plain".',
                __FILE__, __FUNCTION__
            );
        } else {
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $body = wp_remote_retrieve_body( $response );
                $this->addFinding( self::SEV_HIGH, self::CAT_API,
                    "S2NRI REST API returned HTTP {$code}",
                    "Expected 200 from {$rest_url}, got {$code}.",
                    [ 'url' => $rest_url, 'status' => $code, 'body' => substr( $body, 0, 500 ) ],
                    'Check Dispatcher route registration and Auth middleware. Review PHP error log.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // s2nri_api nonce validity (creates + verifies round-trip)
        $test_nonce = wp_create_nonce( 's2nri_api' );
        if ( ! wp_verify_nonce( $test_nonce, 's2nri_api' ) ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_SECURITY,
                'Nonce system broken — all API authenticated calls will fail',
                "wp_create_nonce('s2nri_api') produces a nonce that wp_verify_nonce cannot verify. Auth will always fail.",
                [ 'action' => 's2nri_api' ],
                'Check WP secret keys in wp-config.php. Run wp-cli: wp config shuffle-salts',
                __FILE__, __FUNCTION__
            );
        }

        // Check REST API is not disabled by filter
        $rest_enabled = apply_filters( 'rest_enabled', true );
        $rest_auth    = has_filter( 'rest_authentication_errors' );
        if ( ! $rest_enabled ) {
            $this->addFinding( self::SEV_CRITICAL, self::CAT_API,
                'WordPress REST API disabled by filter',
                "The 'rest_enabled' filter returned false. All API calls will fail with 401.",
                [],
                "Remove or disable the plugin/code that hooks 'rest_enabled' and returns false.",
                __FILE__, __FUNCTION__
            );
        }

        // Cron health
        $doing_wp_cron = (bool) get_option( 'doing_cron' );
        $cron_disabled  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
        if ( $cron_disabled ) {
            $this->addFinding( self::SEV_LOW, self::CAT_WP,
                'WP Cron disabled (DISABLE_WP_CRON=true)',
                'Scheduled jobs (notifications, cleanup, sitemap) will not fire unless server cron is set up.',
                [ 'DISABLE_WP_CRON' => 'true' ],
                'Add a real server cron: */5 * * * * curl https://yoursite.com/wp-cron.php?doing_wp_cron',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── PLUGIN CONFLICTS ─────────────────────────────────────────────────────

    private function scanPluginConflicts(): void {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $all_plugins    = get_plugins();
        $active_plugins = get_option( 'active_plugins', [] );

        // Known conflict plugins (block REST API, modify JSON, etc.)
        $conflict_patterns = [
            'wordfence'             => [ 'sev' => self::SEV_MEDIUM, 'reason' => 'May block REST API or rate-limit API requests. Whitelist /wp-json/s2nri/ in Wordfence settings.' ],
            'disable-json-api'      => [ 'sev' => self::SEV_CRITICAL, 'reason' => 'Disables WordPress REST API — all S2NRI API calls will fail.' ],
            'disable-rest-api'      => [ 'sev' => self::SEV_CRITICAL, 'reason' => 'Disables WordPress REST API — all S2NRI API calls will fail.' ],
            'really-simple-ssl'     => [ 'sev' => self::SEV_LOW,    'reason' => 'May cause mixed-content issues if site transitions between HTTP/HTTPS.' ],
            'w3-total-cache'        => [ 'sev' => self::SEV_MEDIUM, 'reason' => 'May cache API responses. Exclude /wp-json/s2nri/ from page cache.' ],
            'wp-super-cache'        => [ 'sev' => self::SEV_MEDIUM, 'reason' => 'May cache API responses. Exclude /wp-json/s2nri/ from page cache.' ],
            'litespeed-cache'       => [ 'sev' => self::SEV_LOW,    'reason' => 'May cache API responses. Configure to exclude /wp-json/s2nri/ paths.' ],
            'wp-rocket'             => [ 'sev' => self::SEV_LOW,    'reason' => 'May interfere with REST API or JS bundles. Exclude /wp-json/s2nri/ from caching.' ],
            'elementor'             => [ 'sev' => self::SEV_INFO,   'reason' => 'Active. No known conflict, but verify JS does not conflict with S2NRI React bundle.' ],
            'all-in-one-wp-security' => [ 'sev' => self::SEV_MEDIUM, 'reason' => 'May block REST API access for non-logged-in users. Check firewall rules.' ],
        ];

        foreach ( $active_plugins as $plugin_file ) {
            foreach ( $conflict_patterns as $pattern => $meta ) {
                if ( strpos( $plugin_file, $pattern ) !== false ) {
                    $plugin_data = $all_plugins[ $plugin_file ] ?? [];
                    $name = $plugin_data['Name'] ?? $plugin_file;
                    $this->addFinding( $meta['sev'], self::CAT_PLUGIN,
                        "Potential conflict: {$name}",
                        $meta['reason'],
                        [ 'plugin' => $plugin_file, 'version' => $plugin_data['Version'] ?? 'unknown' ],
                        $meta['reason'],
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }

        // Hook conflict detection — actions on rest_authentication_errors
        global $wp_filter;
        $auth_hooks = $wp_filter['rest_authentication_errors'] ?? null;
        if ( $auth_hooks && count( $auth_hooks->callbacks ) > 0 ) {
            $hooked_by = [];
            foreach ( $auth_hooks->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $cb ) {
                    if ( is_array( $cb['function'] ) ) {
                        $hooked_by[] = get_class( $cb['function'][0] ) . '::' . $cb['function'][1];
                    } elseif ( is_string( $cb['function'] ) ) {
                        $hooked_by[] = $cb['function'];
                    }
                }
            }
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PLUGIN,
                'rest_authentication_errors filter has callbacks — may block API auth',
                'Something is filtering REST API authentication. If it returns a WP_Error for anonymous requests, public API endpoints will break.',
                [ 'callbacks' => $hooked_by ],
                'Identify which plugin adds rest_authentication_errors and configure it to allow /wp-json/s2nri/* paths.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── API ROUTE AUDIT ───────────────────────────────────────────────────────

    private function scanApiRoutes(): void {
        // Probe critical public endpoints (no auth required)
        $probes = [
            'settings/public'   => 'Settings endpoint',
            'categories'        => 'Categories endpoint',
            'services'          => 'Services listing endpoint',
        ];

        foreach ( $probes as $path => $label ) {
            $url      = rest_url( "s2nri/v1/{$path}" );
            $response = wp_remote_get( $url, [ 'timeout' => 3, 'sslverify' => false ] );

            if ( is_wp_error( $response ) ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_API,
                    "{$label} unreachable",
                    "GET {$url} error: " . $response->get_error_message(),
                    [ 'url' => $url, 'error' => $response->get_error_message() ],
                    'Verify Dispatcher is loaded and rest_api_init hook registered route.',
                    __FILE__, __FUNCTION__
                );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $body = wp_remote_retrieve_body( $response );
                $this->addFinding( self::SEV_HIGH, self::CAT_API,
                    "{$label} returned HTTP {$code}",
                    "GET {$url} returned {$code}. Body: " . substr( $body, 0, 300 ),
                    [ 'url' => $url, 'code' => $code ],
                    'Check PHP error log for exceptions thrown by the controller.',
                    __FILE__, __FUNCTION__
                );
            } else {
                $data = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    $this->addFinding( self::SEV_HIGH, self::CAT_API,
                        "{$label} returned invalid JSON",
                        "Response body is not valid JSON: " . json_last_error_msg(),
                        [ 'url' => $url ],
                        'Check for PHP notices/warnings being output before JSON. Set WP_DEBUG_DISPLAY=false.',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }
    }

    // ── PERFORMANCE ───────────────────────────────────────────────────────────

    private function scanPerformance(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Memory usage
        $used_mb = round( memory_get_usage( true ) / 1024 / 1024, 1 );
        $limit   = $this->parseMemoryLimit( ini_get( 'memory_limit' ) );
        $limit_mb = $limit > 0 ? round( $limit / 1024 / 1024 ) : 0;
        if ( $limit > 0 && $used_mb > $limit_mb * 0.8 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PERFORMANCE,
                "Memory usage at {$used_mb}MB — approaching limit of {$limit_mb}MB",
                "Current memory usage is over 80% of the PHP limit. Fatal OOM errors likely under load.",
                [ 'used_mb' => $used_mb, 'limit_mb' => $limit_mb ],
                "Increase memory_limit to 512M. Optimize heavy operations to use less RAM.",
                __FILE__, __FUNCTION__
            );
        }

        // Asset file sizes
        $app_js = S2NRI_DIR . 'assets/app.js';
        if ( file_exists( $app_js ) ) {
            $size_kb = round( filesize( $app_js ) / 1024 );
            if ( $size_kb > 800 ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                    "app.js bundle is {$size_kb}KB — consider code splitting",
                    "Large JS bundles delay Time to Interactive. {$size_kb}KB > recommended 500KB.",
                    [ 'file' => 'assets/app.js', 'size_kb' => $size_kb ],
                    'Use Vite dynamic imports (import()) to split admin/dashboard/public into separate chunks.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // DB query count in this request (approximation)
        $query_count = $wpdb->num_queries;
        if ( $query_count > 50 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                "High DB query count: {$query_count} queries in this request",
                "Over 50 database queries detected. N+1 patterns or missing eager loading suspected.",
                [ 'query_count' => $query_count ],
                'Enable query caching, add indexes, or use JOINs instead of separate queries per row.',
                __FILE__, __FUNCTION__
            );
        }

        // WP Object cache
        $cache_hit = wp_cache_get( '__s2nri_diag_test__', 's2nri_diag' );
        if ( $cache_hit === false ) {
            wp_cache_set( '__s2nri_diag_test__', 'ok', 's2nri_diag', 60 );
            $cache_verify = wp_cache_get( '__s2nri_diag_test__', 's2nri_diag' );
            if ( $cache_verify !== 'ok' ) {
                $this->addFinding( self::SEV_LOW, self::CAT_PERFORMANCE,
                    'Object cache not working',
                    'wp_cache_set/get test failed. Using no-persistent cache only.',
                    [],
                    'Install Redis Object Cache plugin and configure Redis on your server for persistent caching.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Execution time so far
        $elapsed = round( ( microtime( true ) - $this->start_time ) * 1000 );
        if ( $elapsed > 3000 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PERFORMANCE,
                "Diagnostic scan itself taking {$elapsed}ms — server response is very slow",
                "Server processing is extremely slow. API responses will timeout for users.",
                [ 'elapsed_ms' => $elapsed ],
                'Check for blocking network calls, slow DB queries, or high server load.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── SECURITY ─────────────────────────────────────────────────────────────

    private function scanSecurity(): void {
        // debug.log exposure
        $debug_log = ABSPATH . 'wp-content/debug.log';
        if ( file_exists( $debug_log ) ) {
            $url      = content_url( 'debug.log' );
            $response = wp_remote_head( $url, [ 'timeout' => 3, 'sslverify' => false ] );
            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_SECURITY,
                    'debug.log publicly accessible — leaks internal paths and errors',
                    "The debug log is served publicly at {$url}. Attackers can extract file paths, DB names, and stack traces.",
                    [ 'url' => $url ],
                    "Add to .htaccess: <Files debug.log>\n  Order deny,allow\n  Deny from all\n</Files>",
                    __FILE__, __FUNCTION__
                );
            }
        }

        // wp-config.php in web root (should not be directly accessible)
        $wpconfig = ABSPATH . 'wp-config.php';
        if ( ! file_exists( dirname( ABSPATH ) . '/wp-config.php' ) && file_exists( $wpconfig ) ) {
            // wp-config.php is in web root — check it's not downloadable
            $this->addFinding( self::SEV_INFO, self::CAT_SECURITY,
                'wp-config.php in web root',
                'Moving wp-config.php one level above web root adds an extra security layer.',
                [ 'path' => $wpconfig ],
                'Move wp-config.php to the parent directory of public_html/htdocs.',
                __FILE__, __FUNCTION__
            );
        }

        // Admin user with ID 1 and username 'admin'
        $admin_user = get_user_by( 'login', 'admin' );
        if ( $admin_user && $admin_user->ID === 1 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_SECURITY,
                "Default 'admin' username in use — brute-force target",
                "Username 'admin' (ID=1) is the first target in any WordPress brute-force attack.",
                [ 'user_id' => 1, 'username' => 'admin' ],
                'Rename the admin user via WP Admin → Users or WP-CLI: wp user update 1 --user_login=yourname',
                __FILE__, __FUNCTION__
            );
        }

        // S2NRI API without HTTPS
        $api_base = rest_url( 's2nri/v1' );
        if ( strpos( $api_base, 'https://' ) !== 0 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_SECURITY,
                'API running over HTTP — credentials and data transmitted in plaintext',
                "API base {$api_base} uses HTTP. Nonces, session cookies, and customer data are not encrypted.",
                [ 'api_base' => $api_base ],
                'Install an SSL certificate and redirect all traffic to HTTPS. Many hosts provide free Let\'s Encrypt certs.',
                __FILE__, __FUNCTION__
            );
        }

        // Check for direct PHP file access (Dispatcher not using ABSPATH guard)
        // We verify our own files have the guard
        $files_to_check = [
            S2NRI_DIR . 'src/Api/Dispatcher.php',
            S2NRI_DIR . 'src/Bootstrap.php',
        ];
        foreach ( $files_to_check as $file ) {
            if ( file_exists( $file ) ) {
                $content = file_get_contents( $file, false, null, 0, 200 );
                if ( strpos( $content, 'defined( \'ABSPATH\' ) || exit' ) === false &&
                     strpos( $content, "defined('ABSPATH') || exit" ) === false ) {
                    $this->addFinding( self::SEV_MEDIUM, self::CAT_SECURITY,
                        'Missing ABSPATH guard in ' . basename( $file ),
                        'Direct PHP file access not blocked. File could be executed directly.',
                        [ 'file' => $file ],
                        "Add to top of file: defined('ABSPATH') || exit;",
                        $file, 'top of file'
                    );
                }
            }
        }
    }

    // ── INTEGRATIONS ─────────────────────────────────────────────────────────

    private function scanIntegrations(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // SMTP / Email
        if ( ! function_exists( 'wp_mail' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_INTEGRATION,
                'wp_mail() not available',
                'Email notifications will not work.',
                [],
                'Ensure WordPress is fully loaded. Check for plugin conflicts overriding wp_mail.',
                __FILE__, __FUNCTION__
            );
        } else {
            // Send a test (dry run — just check mail function availability)
            $mailer = apply_filters( 'pre_wp_mail', null, [] );
            // If a plugin hijacks pre_wp_mail and returns non-null, email still works
            $smtp_host = ini_get( 'SMTP' ) ?: 'localhost';
            $this->addFinding( self::SEV_INFO, self::CAT_INTEGRATION,
                'Email system active',
                "wp_mail() available. SMTP host: {$smtp_host}. Use admin settings to configure SMTP credentials.",
                [ 'smtp' => $smtp_host ],
                'Configure SMTP in Admin Portal → Settings → Email for reliable delivery.',
                __FILE__, __FUNCTION__
            );
        }

        // Razorpay configuration
        $rp_enabled = $wpdb->get_var( "SELECT setting_value FROM {$p}s2nri_settings WHERE setting_key = 'razorpay_enabled' LIMIT 1" );
        if ( $rp_enabled === '1' ) {
            $rp_key = $wpdb->get_var( "SELECT setting_value FROM {$p}s2nri_settings WHERE setting_key = 'razorpay_key_id' LIMIT 1" );
            $rp_sec = $wpdb->get_var( "SELECT setting_value FROM {$p}s2nri_settings WHERE setting_key = 'razorpay_key_secret' LIMIT 1" );
            if ( empty( $rp_key ) || empty( $rp_sec ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_INTEGRATION,
                    'Razorpay enabled but credentials missing',
                    'Razorpay is enabled in settings but key_id or key_secret is empty. Payments will fail.',
                    [ 'key_id_set' => ! empty( $rp_key ), 'key_secret_set' => ! empty( $rp_sec ) ],
                    'Go to Admin Portal → Settings → Payment and enter your Razorpay key_id and key_secret.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // WhatsApp configuration
        $wa_num = $wpdb->get_var( "SELECT setting_value FROM {$p}s2nri_settings WHERE setting_key = 'platform_whatsapp' LIMIT 1" );
        if ( empty( $wa_num ) ) {
            $this->addFinding( self::SEV_LOW, self::CAT_INTEGRATION,
                'WhatsApp number not configured',
                'The WhatsApp contact number is empty. WhatsApp CTA buttons will not appear.',
                [],
                'Go to Admin Portal → Settings → Platform and enter the WhatsApp number.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── INFRASTRUCTURE ───────────────────────────────────────────────────────

    private function scanInfrastructure(): void {
        // Disk space
        $free  = @disk_free_space( ABSPATH );
        $total = @disk_total_space( ABSPATH );
        if ( $free !== false && $total !== false ) {
            $pct_used = round( ( ( $total - $free ) / $total ) * 100 );
            if ( $pct_used > 90 ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_INFRA,
                    "Disk space critical — {$pct_used}% used",
                    "Only " . round( $free / 1024 / 1024 ) . "MB free. File uploads, logs, and database operations will fail.",
                    [ 'free_mb' => round( $free / 1024 / 1024 ), 'pct_used' => $pct_used ],
                    'Delete unnecessary files, clear upload temp dir, archive old logs.',
                    __FILE__, __FUNCTION__
                );
            } elseif ( $pct_used > 75 ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_INFRA,
                    "Disk space at {$pct_used}% — monitor closely",
                    round( $free / 1024 / 1024 ) . "MB free of " . round( $total / 1024 / 1024 ) . "MB.",
                    [ 'free_mb' => round( $free / 1024 / 1024 ) ],
                    'Monitor disk usage and consider archiving old files.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Uploads directory writable
        $upload_dir = wp_upload_dir();
        if ( ! is_writable( $upload_dir['basedir'] ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_INFRA,
                'Uploads directory not writable',
                $upload_dir['basedir'] . ' is not writable. Document uploads will fail.',
                [ 'path' => $upload_dir['basedir'] ],
                'Run: chmod 755 ' . $upload_dir['basedir'] . ' or contact hosting support.',
                __FILE__, __FUNCTION__
            );
        }

        // Plugin directory writable (needed for auto-updates)
        if ( ! is_writable( WP_PLUGIN_DIR ) ) {
            $this->addFinding( self::SEV_LOW, self::CAT_INFRA,
                'Plugin directory not writable — auto-updates disabled',
                WP_PLUGIN_DIR . ' is read-only. Plugin updates must be done via FTP/SSH.',
                [ 'path' => WP_PLUGIN_DIR ],
                'This is a security feature on some hosts. Manual updates via WP admin file upload still work.',
                __FILE__, __FUNCTION__
            );
        }

        // SSL certificate
        $home = home_url();
        if ( strpos( $home, 'https://' ) === 0 ) {
            // Verify cert is not expired (quick check via stream)
            $host = parse_url( $home, PHP_URL_HOST );
            $ctx  = stream_context_create( [ 'ssl' => [ 'capture_peer_cert' => true ] ] );
            $conn = @stream_socket_client( "ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx );  // phpcs:ignore
            if ( $conn ) {
                $params = stream_context_get_params( $conn );
                $cert   = $params['options']['ssl']['peer_certificate'] ?? null;
                if ( $cert ) {
                    $cert_info  = openssl_x509_parse( $cert );
                    $expires_ts = $cert_info['validTo_time_t'] ?? 0;
                    $days_left  = round( ( $expires_ts - time() ) / 86400 );
                    if ( $days_left < 14 ) {
                        $this->addFinding( self::SEV_CRITICAL, self::CAT_INFRA,
                            "SSL certificate expires in {$days_left} days",
                            "SSL cert for {$host} expires in {$days_left} days. HTTPS will break and all API calls will fail.",
                            [ 'host' => $host, 'days_left' => $days_left, 'expires' => date( 'Y-m-d', $expires_ts ) ],
                            'Renew SSL certificate immediately. If using Let\'s Encrypt: certbot renew',
                            __FILE__, __FUNCTION__
                        );
                    } elseif ( $days_left < 30 ) {
                        $this->addFinding( self::SEV_MEDIUM, self::CAT_INFRA,
                            "SSL certificate expires in {$days_left} days — renew soon",
                            "Certificate for {$host} expires " . date( 'Y-m-d', $expires_ts ) . ".",
                            [ 'host' => $host, 'days_left' => $days_left ],
                            'Renew SSL certificate before expiry to avoid service interruption.',
                            __FILE__, __FUNCTION__
                        );
                    }
                }
                fclose( $conn );
            }
        }
    }

    // ── FRONTEND ASSETS ───────────────────────────────────────────────────────

    private function scanFrontendAssets(): void {
        $assets = [
            'app.js'   => S2NRI_DIR . 'assets/app.js',
            'app.css'  => S2NRI_DIR . 'assets/app.css',
        ];

        foreach ( $assets as $name => $path ) {
            if ( ! file_exists( $path ) ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_FRONTEND,
                    "Missing built asset: {$name}",
                    "The file {$path} does not exist. The SPA will not load.",
                    [ 'asset' => $name, 'expected_path' => $path ],
                    'Run: npm run build in the frontend directory. Ensure the built assets are included in the plugin ZIP.',
                    __FILE__, __FUNCTION__
                );
            } else {
                $mtime = filemtime( $path );
                $age_days = round( ( time() - $mtime ) / 86400 );
                if ( $age_days > 90 ) {
                    $this->addFinding( self::SEV_LOW, self::CAT_FRONTEND,
                        "{$name} is {$age_days} days old — may be stale build",
                        "The compiled asset hasn't been rebuilt in {$age_days} days.",
                        [ 'asset' => $name, 'age_days' => $age_days ],
                        'Rebuild frontend: npm run build.',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }

        // S2NRI: Verify hero overlay removed from app.js (compiled homepage bundle)
        $app_js_candidates = [ 'app.js', 'app.v2.js', 'app.v3.js' ];
        $app_js_content = '';
        $app_js_found   = '';
        foreach ( $app_js_candidates as $candidate ) {
            $candidate_path = S2NRI_DIR . 'assets/' . $candidate;
            if ( file_exists( $candidate_path ) ) {
                $app_js_content = file_get_contents( $candidate_path );
                $app_js_found   = $candidate;
                break;
            }
        }
        if ( $app_js_found ) {
            $overlay_strings = [ 'Trusted by NRIs Across the Globe', 'Stay Connected to', 'hero_heading_1', 'Paperwork Stress' ];
            $found_overlay = [];
            foreach ( $overlay_strings as $str ) {
                if ( strpos( $app_js_content, $str ) !== false ) $found_overlay[] = $str;
            }
            if ( ! empty( $found_overlay ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_FRONTEND,
                    "Hero overlay still present in {$app_js_found}",
                    "The compiled homepage bundle contains hero overlay strings: " . implode( ', ', $found_overlay ) . ". The dark overlay (heading, buttons, CTA) will appear over the Swiper image slider.",
                    [ 'file' => $app_js_found, 'found_strings' => $found_overlay ],
                    "Edit assets/{$app_js_found}: find the t.jsx div with position:absolute,inset:0,zIndex:10 and remove it with its leading comma. Then rename the file (e.g. app.js->app.v2.js) and update SEO.php \$js_url to bust CDN cache.",
                    S2NRI_DIR . 'assets/' . $app_js_found, 'homepage component'
                );
            } else {
                $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
                    "Hero overlay removed from {$app_js_found}",
                    'Overlay strings (Trusted by NRIs, hero_heading_1 etc) are absent from the compiled homepage bundle.',
                    [ 'file' => $app_js_found ],
                    'No action required.',
                    S2NRI_DIR . 'assets/' . $app_js_found, 'homepage component'
                );
            }
        }

        // S2NRI: Verify builder/s2nri-builder.js has no hero section
        $builder_js_path = S2NRI_DIR . 'builder/s2nri-builder.js';
        if ( file_exists( $builder_js_path ) ) {
            $builder_content = file_get_contents( $builder_js_path );
            if ( strpos( $builder_content, 'id:"hero"' ) !== false ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_FRONTEND,
                    'Hero section still present in builder/s2nri-builder.js',
                    'The builder JS has id:"hero" section config. WP Admin builder will show a Hero tab with heading/banner fields.',
                    [ 'file' => 'builder/s2nri-builder.js' ],
                    'Edit builder/s2nri-builder.js: find {id:"hero",...} in the sections array and remove it with trailing comma. Also remove hide_hero: from the vc constants object.',
                    $builder_js_path, 'sections array'
                );
            }
        }

        // S2NRI: Verify admin.js has no Hero Section card
        $admin_js_path = S2NRI_DIR . 'assets/chunks/admin.js';
        if ( file_exists( $admin_js_path ) ) {
            $admin_content = file_get_contents( $admin_js_path );
            if ( strpos( $admin_content, '"Hero Section"' ) !== false ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_FRONTEND,
                    'Hero Section card still present in assets/chunks/admin.js',
                    'The compiled admin JS has the Hero Section card with heading/banner fields. It will appear in the WP Admin builder.',
                    [ 'file' => 'assets/chunks/admin.js' ],
                    'Edit assets/chunks/admin.js: find e.jsxs(g,{title:"Hero Section",...}) and remove it with its leading comma.',
                    $admin_js_path, 'builder cards'
                );
            }
        }

        // S2NRI: Verify SEO.php has no hero override functions
        $seo_path_chk = S2NRI_DIR . 'src/SEO.php';
        if ( file_exists( $seo_path_chk ) ) {
            $seo_chk = file_get_contents( $seo_path_chk );
            $seo_issues = [];
            if ( strpos( $seo_chk, 'applyOverrides' ) !== false ) $seo_issues[] = 'applyOverrides()';
            if ( strpos( $seo_chk, 'injectSearch' ) !== false )    $seo_issues[] = 'injectSearch()';
            if ( strpos( $seo_chk, 's2nri-hero-override' ) !== false ) $seo_issues[] = 's2nri-hero-override CSS/JS block';
            if ( ! empty( $seo_issues ) ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_FRONTEND,
                    'SEO.php has hero DOM manipulation functions: ' . implode( ', ', $seo_issues ),
                    'SEO.php injects JS that tries to modify the hero at runtime (applyOverrides, injectSearch). Since hero is removed from source, this is redundant dead code.',
                    [ 'file' => 'src/SEO.php', 'functions' => $seo_issues ],
                    'Edit src/SEO.php: remove the hero override block (style and script tags with id s2nri-hero-override). This removes applyOverrides(), injectSearch(), MutationObserver, and the injected style tag.',
                    $seo_path_chk, 'homepage section'
                );
            }
        }

        // S2NRI: Verify portal JS has all required patches
        $dist_dir_chk = S2NRI_DIR . 'dist/assets/';
        $portal_js_chk = null;
        if ( is_dir( $dist_dir_chk ) ) {
            foreach ( (array) scandir( $dist_dir_chk ) as $f ) {
                if ( str_starts_with( $f, 's2nri-portal.' ) && str_ends_with( $f, '.js' ) && strpos( $f, '-xlsx' ) === false ) {
                    $portal_js_chk = $dist_dir_chk . $f;
                    break;
                }
            }
        }
        if ( $portal_js_chk && file_exists( $portal_js_chk ) ) {
            $pjs = file_get_contents( $portal_js_chk );
            $patches = [
                'is_email_sent string-to-number fix' => strpos( $pjs, 'Number(e.is_email_sent)===1&&' ) !== false,
                'ia() tab key fix'                   => strpos( $pjs, 'onClick:()=>n(e.key??e.value??e)' ) !== false,
                'independent send/email buttons'     => strpos( $pjs, '[ql,qf]=(0,_.useState)(!1)' ) !== false,
                'DataTable rows prop fix'            => strpos( $pjs, 'rows:r.rows,loading:a,onRowClick:' ) !== false,
            ];
            foreach ( $patches as $patch_name => $patch_ok ) {
                if ( ! $patch_ok ) {
                    $this->addFinding( self::SEV_CRITICAL, self::CAT_FRONTEND,
                        "Portal JS missing patch: {$patch_name}",
                        "The compiled portal JS does not contain the '{$patch_name}' fix. This causes a visible bug for users.",
                        [ 'file' => basename( $portal_js_chk ), 'patch' => $patch_name ],
                        'Re-apply the patch to ' . basename( $portal_js_chk ) . ' and reinstall the plugin.',
                        $portal_js_chk, 'compiled bundle'
                    );
                } else {
                    $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
                        "Portal JS patch OK: {$patch_name}",
                        "'{$patch_name}' is present in " . basename( $portal_js_chk ),
                        [ 'file' => basename( $portal_js_chk ) ],
                        'No action required.',
                        $portal_js_chk, 'compiled bundle'
                    );
                }
            }
        }

        // Check SPA entry point is served
        $spa_url = home_url( '/' );
        $response = wp_remote_get( $spa_url, [ 'timeout' => 3, 'sslverify' => false ] );
        if ( ! is_wp_error( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            if ( strpos( $body, 'app.js' ) === false && strpos( $body, 'S2NRI_CONFIG' ) === false ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_FRONTEND,
                    'SPA entry point not serving S2NRI JavaScript',
                    "The home page ({$spa_url}) did not contain app.js or S2NRI_CONFIG injection.",
                    [ 'url' => $spa_url ],
                    'Check that Bootstrap::serveSpa() is called and wp_head hook outputs the script tags.',
                    __FILE__, __FUNCTION__
                );
            }
        }
    }

    // ── CRON JOBS ────────────────────────────────────────────────────────────

    private function scanCronJobs(): void {
        $s2nri_crons = [
            's2nri_job_notifications',
            's2nri_job_quote_reminders',
            's2nri_job_cleanup',
            's2nri_job_sitemap',
        ];

        foreach ( $s2nri_crons as $hook ) {
            $next = wp_next_scheduled( $hook );
            if ( ! $next ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_WP,
                    "Cron job not scheduled: {$hook}",
                    "The {$hook} cron is not registered. Automated tasks for this job will not fire.",
                    [ 'hook' => $hook ],
                    'Reactivate the plugin (deactivate + activate) to re-register cron jobs.',
                    __FILE__, __FUNCTION__
                );
            } elseif ( $next < time() - 3600 ) {
                $overdue_min = round( ( time() - $next ) / 60 );
                $this->addFinding( self::SEV_LOW, self::CAT_WP,
                    "Cron job overdue by {$overdue_min} minutes: {$hook}",
                    "The {$hook} cron was due " . date( 'Y-m-d H:i', $next ) . " but hasn't run. WP Cron may not be triggering.",
                    [ 'hook' => $hook, 'scheduled' => date( 'Y-m-d H:i', $next ) ],
                    'Ensure your site receives regular traffic or set up a real server cron to trigger wp-cron.php.',
                    __FILE__, __FUNCTION__
                );
            }
        }
    }

    // ── AI-POWERED ANOMALY DETECTION ─────────────────────────────────────────

    private function runAnomalyDetection(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Pattern 1: Error rate spike — many log entries in short window
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}s2nri_diagnostics_log'" );
        if ( $table_exists ) {
            $recent_errors = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}s2nri_diagnostics_log
                 WHERE severity IN ('critical','high')
                 AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            );
            if ( $recent_errors > 20 ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_UNKNOWN,
                    "Anomaly: {$recent_errors} critical/high errors in the last hour",
                    "Error rate is abnormally high. Possible causes: bad deployment, infrastructure failure, attack.",
                    [ 'errors_last_hour' => $recent_errors ],
                    'Review the full diagnostic log. Check for a pattern — same endpoint, same error type, same user.',
                    __FILE__, __FUNCTION__
                );
            }

            // Pattern 2: Repeated same error (likely a code loop)
            $repeated = $wpdb->get_row(
                "SELECT title, COUNT(*) as cnt FROM {$p}s2nri_diagnostics_log
                 WHERE recorded_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                 GROUP BY title HAVING cnt > 10
                 ORDER BY cnt DESC LIMIT 1",
                ARRAY_A
            );
            if ( $repeated ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_UNKNOWN,
                    "Anomaly: '{$repeated['title']}' occurring {$repeated['cnt']} times in 6 hours",
                    "Repeated identical errors often indicate a code loop, missing guard, or resource contention.",
                    [ 'error' => $repeated['title'], 'count' => $repeated['cnt'] ],
                    'Find and fix the root cause of this repeated error. Add a guard condition or circuit breaker.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Pattern 3: Memory growth (compare start to now)
        $current_mem = memory_get_usage( true );
        $growth_mb   = round( ( $current_mem - $this->start_stats['memory'] ) / 1024 / 1024, 1 );
        if ( $growth_mb > 20 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_UNKNOWN,
                "Anomaly: {$growth_mb}MB memory consumed during diagnostic scan",
                "A single page request consuming this much memory indicates a memory leak or missing cleanup.",
                [ 'growth_mb' => $growth_mb ],
                'Profile memory usage with xdebug or Blackfire. Look for large arrays not being unset.',
                __FILE__, __FUNCTION__
            );
        }

        // Pattern 4: High DB query count vs expected baseline
        $query_count = $wpdb->num_queries;
        if ( $query_count > 100 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_UNKNOWN,
                "Anomaly: {$query_count} DB queries — N+1 or loop pattern suspected",
                "Unusually high query count suggests missing JOIN or eager loading.",
                [ 'queries' => $query_count ],
                'Enable SAVEQUERIES in wp-config.php and inspect $wpdb->queries array for repeated patterns.',
                __FILE__, __FUNCTION__
            );
        }
    }


    // ── PHP RUNTIME ERROR HANDLER (SEC 4: uncaught exceptions) ──────────────

    private function scanPhpRuntimeErrors(): void {
        // Register a shutdown function to catch fatal errors and log them
        // This fires AFTER scan — we capture the last recorded PHP error
        $last_error = error_get_last();
        if ( $last_error !== null ) {
            $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
            $sev = in_array( $last_error['type'], $fatal_types ) ? self::SEV_CRITICAL : self::SEV_HIGH;
            $this->addFinding( $sev, self::CAT_PHP,
                'PHP fatal/error recorded: ' . $last_error['message'],
                "PHP error type " . $last_error['type'] . " in " . $last_error['file'] . " line " . $last_error['line'],
                [ 'type' => $last_error['type'], 'file' => $last_error['file'], 'line' => $last_error['line'] ],
                'Fix the PHP error at the file and line shown. Enable WP_DEBUG_LOG=true to capture full stack.',
                $last_error['file'], 'line ' . $last_error['line']
            );
        }

        // Scan WordPress debug.log for recent entries
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if ( file_exists( $log_file ) && is_readable( $log_file ) ) {
            $size = filesize( $log_file );
            if ( $size > 0 ) {
                // Read last 20KB only — avoid loading huge log files
                $handle = fopen( $log_file, 'r' );
                fseek( $handle, max( 0, $size - 20480 ) );
                $tail = fread( $handle, 20480 );
                fclose( $handle );

                // Count recent PHP errors
                $php_errors   = preg_match_all( '/PHP (Fatal|Parse|Warning|Notice) error/i', $tail );
                $wp_db_errors = preg_match_all( '/\[wpdb\]|\[mysqli\]/i', $tail );
                $s2nri_errors = preg_match_all( '/\[S2NRI\]/i', $tail );

                if ( $php_errors > 0 ) {
                    $this->addFinding( self::SEV_HIGH, self::CAT_PHP,
                        "PHP error log contains {$php_errors} recent PHP errors",
                        "debug.log has {$php_errors} PHP errors in the last 20KB. Server is not healthy.",
                        [ 'count' => $php_errors, 'log_size_kb' => round( $size / 1024 ) ],
                        'Open wp-content/debug.log and fix the errors listed. Check file and line references.',
                        $log_file, 'tail'
                    );
                }
                if ( $wp_db_errors > 0 ) {
                    $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                        "Database errors in PHP log: {$wp_db_errors} occurrences",
                        "WordPress database errors detected in debug.log.",
                        [ 'count' => $wp_db_errors ],
                        'Review debug.log for wpdb error details. Check database schema and query syntax.',
                        $log_file, 'tail'
                    );
                }
                if ( $s2nri_errors > 0 ) {
                    $this->addFinding( self::SEV_MEDIUM, self::CAT_API,
                        "S2NRI plugin errors in log: {$s2nri_errors} occurrences",
                        "S2NRI-specific errors found in debug.log.",
                        [ 'count' => $s2nri_errors ],
                        'Search debug.log for [S2NRI] to find the specific error and affected controller.',
                        $log_file, 'tail'
                    );
                }

                if ( $php_errors === 0 && $wp_db_errors === 0 && $s2nri_errors === 0 ) {
                    $this->addFinding( self::SEV_INFO, self::CAT_PHP,
                        'PHP debug.log exists and appears clean',
                        "No PHP errors, DB errors, or S2NRI errors in the last 20KB of debug.log.",
                        [ 'log_size_kb' => round( $size / 1024 ) ],
                        'Continue monitoring. Enable debug.log rotation to prevent disk fill.',
                        $log_file, ''
                    );
                }
            }
        } else {
            $this->addFinding( self::SEV_INFO, self::CAT_PHP,
                'PHP debug.log not found or not readable',
                'No debug.log at ' . WP_CONTENT_DIR . '/debug.log. WP_DEBUG_LOG may be disabled.',
                [ 'path' => WP_CONTENT_DIR . '/debug.log' ],
                "Set define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false); in wp-config.php to enable.",
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── SECURITY: HEADERS, XSS, SQLI, SUSPICIOUS LOGINS ─────────────────────

    private function scanSecurityDeep(): void {
        // HTTP Security headers check
        $home = home_url();
        $response = wp_remote_get( $home, [ 'timeout' => 3, 'sslverify' => false ] );
        if ( ! is_wp_error( $response ) ) {
            $headers = wp_remote_retrieve_headers( $response );
            $security_headers = [
                'x-frame-options'           => [ 'required' => true,  'fix' => 'Add: Header always set X-Frame-Options "SAMEORIGIN"' ],
                'x-content-type-options'    => [ 'required' => true,  'fix' => 'Add: Header always set X-Content-Type-Options "nosniff"' ],
                'x-xss-protection'          => [ 'required' => false, 'fix' => 'Add: Header always set X-XSS-Protection "1; mode=block"' ],
                'referrer-policy'           => [ 'required' => false, 'fix' => 'Add: Header always set Referrer-Policy "strict-origin-when-cross-origin"' ],
                'permissions-policy'        => [ 'required' => false, 'fix' => 'Add: Header always set Permissions-Policy "geolocation=()"' ],
                'content-security-policy'   => [ 'required' => false, 'fix' => 'Define a Content-Security-Policy header to prevent XSS.' ],
                'strict-transport-security' => [ 'required' => is_ssl(), 'fix' => 'Add: Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"' ],
            ];
            foreach ( $security_headers as $header => $meta ) {
                $present = isset( $headers[ $header ] );
                if ( ! $present ) {
                    $sev = $meta['required'] ? self::SEV_HIGH : self::SEV_MEDIUM;
                    $this->addFinding( $sev, self::CAT_SECURITY,
                        "Missing HTTP security header: {$header}",
                        "The {$header} header is not sent by this site. Browsers cannot enforce this protection.",
                        [ 'header' => $header, 'url' => $home ],
                        $meta['fix'] . ' in .htaccess or nginx config.',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }

        // XSS indicators — check if any S2NRI output points skip escaping
        $controllers_dir = S2NRI_DIR . 'src/Api/Controllers/';
        $unescaped_output = 0;
        if ( is_dir( $controllers_dir ) ) {
            $iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $controllers_dir, \RecursiveDirectoryIterator::SKIP_DOTS ) );
            foreach ( $iter as $file ) {
                if ( ! str_ends_with( (string) $file->getPathname(), '.php' ) ) continue;
                $content = @file_get_contents( $file->getPathname() );
                if ( $content === false ) continue;
                // Look for echo of user input without sanitization
                if ( @preg_match( '/echo\s+\$_(GET|POST|REQUEST|COOKIE)\[/', $content ) ) {
                    $unescaped_output++;
                    $this->addFinding( self::SEV_CRITICAL, self::CAT_SECURITY,
                        'Potential XSS: raw $_GET/$_POST echo in ' . basename( $file->getPathname() ),
                        'Direct echo of superglobal without sanitization/escaping found.',
                        [ 'file' => str_replace( ABSPATH, '', $file->getPathname() ) ],
                        'Use esc_html(), esc_attr(), or wp_kses() before echoing any user-supplied data.',
                        $file->getPathname(), 'echo'
                    );
                }
            }
        }
        if ( $unescaped_output === 0 ) {
            $this->addFinding( self::SEV_INFO, self::CAT_SECURITY,
                'No direct superglobal echo found in controllers',
                'XSS scan: no raw $_GET/$_POST echo detected in controller files.',
                [],
                'Continue using $req->input() and Response::json() which safely encode output.',
                __FILE__, __FUNCTION__
            );
        }

        // SQL injection indicators — look for $wpdb->query with direct string interpolation
        $sqli_risk = 0;
        if ( is_dir( $controllers_dir ) ) {
            $iter = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $controllers_dir, \RecursiveDirectoryIterator::SKIP_DOTS ) );
            foreach ( $iter as $file ) {
                if ( ! str_ends_with( (string) $file->getPathname(), '.php' ) ) continue;
                $content = @file_get_contents( $file->getPathname() );
                if ( $content === false ) continue;
                // Look for wpdb->query/get_results with direct variable interpolation (not prepare)
                if ( @preg_match( '/\$wpdb->(query|get_results|get_row|get_var)\s*\(\s*"[^"]*\$_(GET|POST|REQUEST)/', $content ) ) {
                    $sqli_risk++;
                    $this->addFinding( self::SEV_CRITICAL, self::CAT_SECURITY,
                        'SQL injection risk: unparameterized query in ' . basename( $file->getPathname() ),
                        'wpdb query method called with direct superglobal interpolation — no $wpdb->prepare().',
                        [ 'file' => str_replace( ABSPATH, '', $file->getPathname() ) ],
                        'Always use $wpdb->prepare() for any query containing user input.',
                        $file->getPathname(), 'wpdb->query'
                    );
                }
            }
        }

        // Suspicious login activity — failed logins in last 24h
        global $wpdb;
        $transients = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_limit_login_%'
             OR option_name LIKE '_transient_failed_login_%'
             LIMIT 100"
        );
        if ( count( $transients ) > 10 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_SECURITY,
                'Suspicious login activity: ' . count( $transients ) . ' login-limiting transients detected',
                'High number of login limit transients suggests brute-force attempts are occurring.',
                [ 'transient_count' => count( $transients ) ],
                'Enable two-factor authentication. Use a plugin like Limit Login Attempts Reloaded. Whitelist admin IPs.',
                __FILE__, __FUNCTION__
            );
        }

        // File integrity — check core plugin file hasn't been modified unexpectedly
        $main_file  = S2NRI_DIR . 'services2nri.php';
        $disp_file  = S2NRI_DIR . 'src/Api/Dispatcher.php';
        foreach ( [ $main_file, $disp_file ] as $chk ) {
            if ( file_exists( $chk ) ) {
                $mtime   = filemtime( $chk );
                $age_hrs = round( ( time() - $mtime ) / 3600 );
                if ( $age_hrs < 1 ) {
                    $this->addFinding( self::SEV_INFO, self::CAT_SECURITY,
                        'Recently modified plugin file: ' . basename( $chk ),
                        'File was modified less than 1 hour ago. Verify this was intentional (recent deployment).',
                        [ 'file' => str_replace( ABSPATH, '', $chk ), 'age_minutes' => round( ( time() - $mtime ) / 60 ) ],
                        'If this was not a recent deployment, investigate for unauthorized modification.',
                        $chk, ''
                    );
                }
            }
        }

        // Malware indicator — look for eval(base64_decode) patterns in plugin files
        // IMPORTANT: exclude DiagnosticEngine.php itself — it contains these strings
        // as literal search patterns and would always trigger a false positive on itself.
        $suspect_patterns = [ 'eval(base64_decode', 'eval(gzinflate', 'eval(str_rot13', 'preg_replace.*\/e.*\$_' ];
        $php_files = array_merge(
            glob( S2NRI_DIR . 'src/*.php' ) ?: [],
            glob( S2NRI_DIR . 'src/*/*.php' ) ?: [],
            glob( S2NRI_DIR . 'src/*/*/*.php' ) ?: []
        );
        $this_file = realpath( __FILE__ );
        foreach ( $php_files as $phpfile ) {
            // Skip this file — it contains the patterns as literal strings for scanning
            if ( realpath( $phpfile ) === $this_file ) continue;
            $content = file_get_contents( $phpfile );
            foreach ( $suspect_patterns as $pat ) {
                if ( stripos( $content, str_replace( '*', '', $pat ) ) !== false ) {
                    $this->addFinding( self::SEV_CRITICAL, self::CAT_SECURITY,
                        'Malware indicator in ' . basename( $phpfile ),
                        "Suspected obfuscated/malicious code pattern found: '{$pat}'",
                        [ 'file' => str_replace( ABSPATH, '', $phpfile ), 'pattern' => $pat ],
                        'Immediately compare this file against the original plugin ZIP. If different, restore from backup and change all passwords.',
                        $phpfile, ''
                    );
                }
            }
        }
    }

    // ── DATABASE DEEP: DEADLOCKS, MIGRATIONS, CONSTRAINTS ────────────────────

    private function scanDatabaseDeep(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Migration version check
        $installed_ver = get_option( 's2nri_db_version', '0.0.0' );
        if ( version_compare( $installed_ver, S2NRI_VERSION, '<' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                'Database schema may be out of date',
                "Installed DB version {$installed_ver} is older than plugin version " . S2NRI_VERSION . ". Schema may be missing new columns/tables.",
                [ 'installed' => $installed_ver, 'required' => S2NRI_VERSION ],
                'Deactivate and reactivate the plugin to trigger runMigrations(). Or go to Admin → Services → Reseed.',
                __FILE__, __FUNCTION__
            );
        }

        // Check for InnoDB engine (required for foreign keys and transactions)
        $tables = $wpdb->get_results(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME LIKE '{$p}s2nri_%'",
            ARRAY_A
        ) ?: [];
        foreach ( $tables as $tbl ) {
            if ( strtolower( $tbl['ENGINE'] ) !== 'innodb' ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_DB,
                    "Table {$tbl['TABLE_NAME']} uses {$tbl['ENGINE']} instead of InnoDB",
                    "Non-InnoDB tables do not support transactions or row-level locking. Deadlock risk is higher.",
                    [ 'table' => $tbl['TABLE_NAME'], 'engine' => $tbl['ENGINE'] ],
                    "ALTER TABLE {$tbl['TABLE_NAME']} ENGINE=InnoDB;",
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Check InnoDB status for recent deadlocks
        $innodb_status = $wpdb->get_var( "SHOW ENGINE INNODB STATUS" );
        if ( $innodb_status && stripos( $innodb_status, 'DEADLOCK' ) !== false ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                'InnoDB deadlock detected in engine status',
                'MySQL InnoDB STATUS shows a recent deadlock. Concurrent write operations are conflicting.',
                [ 'hint' => 'Run SHOW ENGINE INNODB STATUS for details' ],
                'Add retry logic to critical write operations. Use SELECT ... FOR UPDATE to control locking order.',
                __FILE__, __FUNCTION__
            );
        }

        // Constraint violations — check for broken FK-like references
        // bookings with non-existent service_id
        $broken_bookings = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}s2nri_bookings b
             LEFT JOIN {$p}s2nri_services s ON s.id = b.service_id
             WHERE s.id IS NULL"
        );
        if ( $broken_bookings > 0 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_DB,
                "Referential integrity: {$broken_bookings} bookings reference non-existent services",
                "Bookings exist for service_ids that no longer exist in s2nri_services.",
                [ 'broken_count' => $broken_bookings ],
                'Run: DELETE FROM ' . $p . 's2nri_bookings WHERE service_id NOT IN (SELECT id FROM ' . $p . 's2nri_services); — BACK UP FIRST.',
                __FILE__, __FUNCTION__
            );
        }

        // S2NRI: BookingController had 'SELECT t.category' but column doesn't exist
        // This caused SQL error -> corrupted JSON response -> all 4 tabs blank
        $ticket_cols = $wpdb->get_col( "DESCRIBE {$p}s2nri_tickets", 0 ) ?: [];
        $bc_path = S2NRI_DIR . 'src/Api/Controllers/BookingController.php';
        if ( file_exists( $bc_path ) && ! in_array( 'category', $ticket_cols, true ) ) {
            $bc_content = file_get_contents( $bc_path );
            if ( strpos( $bc_content, 't.category' ) !== false ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_DB,
                    'BookingController: t.category queried but column missing in s2nri_tickets',
                    "BookingController::getBookingDetail() has SELECT t.category but s2nri_tickets has no category column. SQL error corrupts the booking response and makes all 4 tabs (Messages, Documents, Form Details, Service Requests) blank in the customer portal.",
                    [ 'file' => 'BookingController.php', 'table' => $p . 's2nri_tickets', 'actual_cols' => $ticket_cols ],
                    "Edit BookingController.php getBookingDetail(): remove 't.category' from the tickets SELECT. Change: SELECT t.id, t.subject, t.category, t.status to: SELECT t.id, t.subject, t.status",
                    $bc_path, 'getBookingDetail'
                );
            }
        }

        // Large tables without pagination risk
        foreach ( ['s2nri_audit_log' => 100000, 's2nri_email_log' => 50000] as $table => $limit ) {
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$table}" );
            if ( $count > $limit ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                    "Table {$p}{$table} has {$count} rows — consider pruning",
                    "Very large log tables slow down queries and backups.",
                    [ 'table' => $p . $table, 'rows' => $count, 'threshold' => $limit ],
                    "DELETE FROM {$p}{$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY); — BACK UP FIRST.",
                    __FILE__, __FUNCTION__
                );
            }
        }
    }

    // ── WORDPRESS DEEP: THEMES, MULTISITE, UPDATES ───────────────────────────

    private function scanWordPressDeep(): void {
        // Theme conflicts
        $theme = wp_get_theme();
        $active_theme_name = $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' );
        // Check if theme overrides wp_head or wp_footer (needed for SPA script injection)
        global $wp_filter;
        $head_hooks   = count( $wp_filter['wp_head']->callbacks ?? [] );
        $footer_hooks = count( $wp_filter['wp_footer']->callbacks ?? [] );
        if ( $head_hooks === 0 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PLUGIN,
                'wp_head hook has no callbacks — theme may not call wp_head()',
                'The active theme ' . $active_theme_name . ' may not call wp_head() in the template. SPA scripts will not load.',
                [ 'theme' => $active_theme_name ],
                'Since S2NRI uses its own SEO::render() template, this is expected. Verify Bootstrap::serveSpa() is hooked to template_redirect.',
                __FILE__, __FUNCTION__
            );
        }

        // Plugin/theme update availability (security-relevant)
        if ( ! function_exists( 'get_plugin_updates' ) ) {
            @require_once ABSPATH . 'wp-admin/includes/update.php';
            @require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $update_plugins = get_site_transient( 'update_plugins' );
        if ( $update_plugins && ! empty( $update_plugins->response ) ) {
            $count = count( $update_plugins->response );
            $this->addFinding( self::SEV_MEDIUM, self::CAT_WP,
                "{$count} plugin update(s) available",
                "Outdated plugins are a common attack vector. {$count} plugins have pending updates.",
                [ 'count' => $count, 'plugins' => array_keys( $update_plugins->response ) ],
                'Update all plugins via WP Admin → Dashboard → Updates.',
                __FILE__, __FUNCTION__
            );
        }

        // WordPress core update
        $update_core = get_site_transient( 'update_core' );
        if ( $update_core && ! empty( $update_core->updates ) ) {
            foreach ( $update_core->updates as $u ) {
                if ( $u->response === 'upgrade' ) {
                    $this->addFinding( self::SEV_MEDIUM, self::CAT_WP,
                        "WordPress core update available: {$u->version}",
                        "WordPress " . get_bloginfo('version') . " → {$u->version}. Updates often contain security patches.",
                        [ 'current' => get_bloginfo('version'), 'available' => $u->version ],
                        'Update WordPress via WP Admin → Dashboard → Updates.',
                        __FILE__, __FUNCTION__
                    );
                    break;
                }
            }
        }

        // Multisite
        if ( is_multisite() ) {
            $this->addFinding( self::SEV_INFO, self::CAT_WP,
                'WordPress Multisite detected',
                'S2NRI is running in a Multisite environment. Network activation is required for all sites.',
                [ 'network_id' => get_current_network_id() ],
                'Ensure the plugin is network-activated. Data isolation between sites is not currently implemented.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── NETWORK / CDN / PROXY / LOAD BALANCER ────────────────────────────────

    private function scanNetworkLayer(): void {
        // CDN detection and issues
        $home     = home_url();
        $response = wp_remote_get( $home, [ 'timeout' => 3, 'sslverify' => false ] );
        if ( ! is_wp_error( $response ) ) {
            $headers = wp_remote_retrieve_headers( $response );
            $via     = $headers['via'] ?? $headers['x-cache'] ?? $headers['x-cdn'] ?? '';
            $cf_ray  = $headers['cf-ray'] ?? ''; // Cloudflare
            $x_fwd   = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

            if ( $cf_ray ) {
                $this->addFinding( self::SEV_INFO, self::CAT_INFRA,
                    'Cloudflare CDN detected',
                    'Site is behind Cloudflare. API responses may be cached. Ensure /wp-json/* is set to bypass cache.',
                    [ 'cf_ray' => $cf_ray ],
                    'In Cloudflare: Page Rules → /wp-json/* → Cache Level: Bypass. Also bypass /api/v1/*.',
                    __FILE__, __FUNCTION__
                );
            } elseif ( $via ) {
                $this->addFinding( self::SEV_INFO, self::CAT_INFRA,
                    'Reverse proxy / CDN detected: ' . $via,
                    'Proxy layer detected. API calls may be cached or blocked.',
                    [ 'via' => $via ],
                    'Configure CDN/proxy to bypass cache for /wp-json/s2nri/ paths.',
                    __FILE__, __FUNCTION__
                );
            }

            if ( $x_fwd ) {
                $this->addFinding( self::SEV_INFO, self::CAT_INFRA,
                    'Load balancer / reverse proxy: X-Forwarded-For detected',
                    'Requests arrive via proxy. Ensure real client IP is preserved for rate limiting and logging.',
                    [ 'forwarded_for' => $x_fwd ],
                    'Verify \$_SERVER[\'REMOTE_ADDR\'] correctly reflects real client IP after proxy forwarding.',
                    __FILE__, __FUNCTION__
                );
            }

            // Firewall / WAF check — if our API probe was blocked by WAF
            $api_url   = rest_url( 's2nri/v1/categories' );
            $api_probe = wp_remote_get( $api_url, [ 'timeout' => 3, 'sslverify' => false,
                'headers' => [ 'X-Forwarded-For' => '1.1.1.1' ] ] ); // simulate external request
            if ( ! is_wp_error( $api_probe ) ) {
                $waf_code = wp_remote_retrieve_response_code( $api_probe );
                if ( in_array( $waf_code, [ 403, 406, 429, 503 ], true ) ) {
                    $this->addFinding( self::SEV_HIGH, self::CAT_INFRA,
                        "Firewall/WAF blocking API: HTTP {$waf_code} on {$api_url}",
                        "The API endpoint returned {$waf_code} which indicates a WAF or firewall rule is blocking the request.",
                        [ 'url' => $api_url, 'code' => $waf_code ],
                        'Whitelist /wp-json/s2nri/* in your WAF/firewall (Cloudflare, Sucuri, Wordfence, etc.).',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }

        // DNS resolution for home URL
        $host = parse_url( home_url(), PHP_URL_HOST );
        $resolved = gethostbyname( $host );
        if ( $resolved === $host ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_NETWORK,
                "DNS resolution failed for {$host}",
                "gethostbyname('{$host}') returned the same string — DNS lookup failed from server.",
                [ 'host' => $host ],
                'Check DNS records and ensure the server can reach external DNS resolvers.',
                __FILE__, __FUNCTION__
            );
        }

        // Rate limiting — check if we have any protection
        $p = $GLOBALS['wpdb']->prefix ?? 'wp_';
        global $wpdb;
        $recent_404s = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$p}s2nri_diagnostics_log
             WHERE category = 'api_rest' AND severity IN ('high','critical')
             AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        if ( $recent_404s > 30 ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_NETWORK,
                "High API error rate: {$recent_404s} API errors in last hour",
                "May indicate rate limit violations, scanning, or API abuse.",
                [ 'errors_per_hour' => $recent_404s ],
                'Consider adding rate limiting to the API. Investigate error patterns in the diagnostic log.',
                __FILE__, __FUNCTION__
            );
        }

        // Webhook failures check
        global $wpdb;
        $p = $wpdb->prefix;
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$p}s2nri_email_log'" );
        if ( $table_exists ) {
            $failed_emails = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$p}s2nri_email_log WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            );
            if ( $failed_emails > 0 ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_INTEGRATION,
                    "{$failed_emails} email delivery failure(s) in the last 24 hours",
                    "Email notifications are failing. Customers and admins are not receiving notifications.",
                    [ 'failed_24h' => $failed_emails ],
                    'Configure SMTP credentials in Admin Portal → Settings → Email. Use a transactional email service (SendGrid, Mailgun, AWS SES).',
                    __FILE__, __FUNCTION__
                );
            }
        }
    }

    // ── BROWSER STORAGE / COOKIES / SERVICE WORKERS ──────────────────────────
    // These are injected as a JS probe that runs in the browser and reports back

    private function scanBrowserCapabilities(): void {
        // This method injects a small inline script into the page that runs client-side
        // and captures: localStorage, sessionStorage, IndexedDB, cookie, SW, WS availability.
        // Results are sent to the frontend-errors endpoint.
        // The scanning itself cannot happen server-side — we register the probe here.
        $probe_script = <<<'JS'
(function(){
  var c = window.__S2NRI_DIAG__ ? window.__S2NRI_DIAG__.capture : function(){};
  // localStorage
  try { localStorage.setItem('__s2nri_test','1'); localStorage.removeItem('__s2nri_test'); }
  catch(e) { c('storage_failure',{type:'localStorage',error:e.message}); }
  // sessionStorage
  try { sessionStorage.setItem('__s2nri_test','1'); sessionStorage.removeItem('__s2nri_test'); }
  catch(e) { c('storage_failure',{type:'sessionStorage',error:e.message}); }
  // cookies
  try {
    document.cookie='__s2nri_test=1;path=/;SameSite=Lax';
    if(document.cookie.indexOf('__s2nri_test')<0) c('storage_failure',{type:'cookie',error:'cookie not set'});
    document.cookie='__s2nri_test=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
  } catch(e){ c('storage_failure',{type:'cookie',error:e.message}); }
  // IndexedDB
  if(!window.indexedDB) c('storage_failure',{type:'indexedDB',error:'not supported'});
  // Service Worker
  if('serviceWorker' in navigator){
    navigator.serviceWorker.getRegistrations().then(function(regs){
      regs.forEach(function(r){ if(r.installing===null && r.waiting===null && r.active===null)
        c('service_worker_failure',{scope:r.scope}); });
    }).catch(function(e){ c('service_worker_failure',{error:e.message}); });
  }
  // WebSocket
  try {
    var ws=new WebSocket('wss://'+location.hostname+'/ws-test-probe-404');
    setTimeout(function(){
      if(ws.readyState===0){c('browser_compat',{feature:'WebSocket',status:'connecting_timeout'}); ws.close();}
    },3000);
    ws.onerror=function(){ /* connection refused = WS is available but no server; that's OK */ };
  } catch(e){ c('browser_compat',{feature:'WebSocket',status:'not_supported',error:e.message}); }
  // Mixed content
  if(location.protocol==='https:'){
    var img=new Image();
    img.onerror=function(){};
    img.onload=function(){ c('mixed_content_detected',{resource:'http://detectmixedcontent.example'}); };
  }
  // Browser compatibility
  var compat={
    'Promise': typeof Promise !== 'undefined',
    'fetch':   typeof fetch !== 'undefined',
    'CSS Grid':CSS.supports('display','grid'),
    'CSS Custom Properties':CSS.supports('color','var(--test)'),
  };
  Object.keys(compat).forEach(function(k){ if(!compat[k]) c('browser_compat',{feature:k,status:'not_supported'}); });
  // Deprecation warning detection via mutation observer on console
})();
JS;
        // Store the probe script to be injected by SEO.php
        update_option( 's2nri_browser_probe_script', $probe_script );

        $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
            'Browser capability probe registered',
            'A JS probe has been registered to test localStorage, sessionStorage, cookies, IndexedDB, WebSocket, mixed content, and browser compatibility on the next page load.',
            [],
            'View frontend error logs after a page visit to see browser capability results.',
            __FILE__, __FUNCTION__
        );
    }

    // ── PERFORMANCE DEEP: CPU, CACHE MISSES, RENDER-BLOCKING ─────────────────

    private function scanPerformanceDeep(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Render-blocking resources — check if our CSS/JS uses defer/async
        $app_js_url  = S2NRI_ASSETS_URL . 'app.js';
        $spa_html    = wp_remote_retrieve_body( wp_remote_get( home_url(), [ 'timeout' => 3, 'sslverify' => false ] ) );
        if ( $spa_html ) {
            // Check if app.js uses type="module" (auto-deferred) or blocking <script src>
            if ( strpos( $spa_html, 'type="module"' ) !== false ) {
                $this->addFinding( self::SEV_INFO, self::CAT_PERFORMANCE,
                    'app.js loaded as ES module (non-render-blocking)',
                    'type="module" scripts are deferred by default — no render blocking.',
                    [],
                    'Good. Continue using type="module" for the main bundle.',
                    __FILE__, __FUNCTION__
                );
            } elseif ( strpos( $spa_html, 'app.js' ) !== false ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                    'app.js loaded without defer/async — may be render-blocking',
                    'A synchronous <script src="app.js"> blocks HTML parsing and delays First Paint.',
                    [],
                    'Add defer or type="module" to the app.js script tag in SEO::render().',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // Cache miss rate — check WP object cache
        $cache_stats = wp_cache_get( 'alloptions', 'options' );
        $object_cache_persistent = wp_using_ext_object_cache();
        if ( ! $object_cache_persistent ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                'No persistent object cache — high cache miss rate',
                'WordPress is using the default in-memory (non-persistent) object cache. Every request starts cold.',
                [],
                'Install Redis Object Cache plugin + configure Redis. Or use Memcached. Persistent cache dramatically reduces DB queries.',
                __FILE__, __FUNCTION__
            );
        }

        // Excessive HTTP requests — count assets on home page
        if ( $spa_html ) {
            $script_count = substr_count( $spa_html, '<script' );
            $style_count  = substr_count( $spa_html, '<link rel="stylesheet"' );
            if ( $script_count > 15 ) {
                $this->addFinding( self::SEV_MEDIUM, self::CAT_PERFORMANCE,
                    "Excessive script tags: {$script_count} <script> elements on home page",
                    "Too many individual script requests increase page load time. Each request adds DNS + TCP + TLS overhead.",
                    [ 'script_count' => $script_count ],
                    'Bundle scripts together. Remove unused plugin scripts that load on every page.',
                    __FILE__, __FUNCTION__
                );
            }
        }

        // CPU spike detection — PHP execution time for this scan
        $elapsed_ms = round( ( microtime( true ) - $this->start_time ) * 1000 );
        if ( $elapsed_ms > 5000 ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PERFORMANCE,
                "Server processing extremely slow: {$elapsed_ms}ms for diagnostic scan",
                "Normal scan should complete in < 3000ms. {$elapsed_ms}ms indicates server under heavy load or CPU throttling.",
                [ 'elapsed_ms' => $elapsed_ms ],
                'Check server CPU load average (top/htop). Consider upgrading server or reducing concurrent processes.',
                __FILE__, __FUNCTION__
            );
        }

        // Auto-increment exhaustion check
        $tables_at_limit = $wpdb->get_results(
            "SELECT TABLE_NAME, AUTO_INCREMENT, TABLE_ROWS
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME LIKE '{$p}s2nri_%'
             AND AUTO_INCREMENT > 2000000000",
            ARRAY_A
        ) ?: [];
        foreach ( $tables_at_limit as $t ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                "AUTO_INCREMENT near limit on {$t['TABLE_NAME']}",
                "AUTO_INCREMENT = {$t['AUTO_INCREMENT']}. If the column is INT (max 2,147,483,647), inserts will fail soon.",
                [ 'table' => $t['TABLE_NAME'], 'auto_increment' => $t['AUTO_INCREMENT'] ],
                "ALTER TABLE {$t['TABLE_NAME']} MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;",
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── INTEGRATION DEEP: WEBHOOKS, OAUTH ────────────────────────────────────

    private function scanIntegrationsDeep(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // OAuth / SSO — check if any WordPress auth plugins are active
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = get_option( 'active_plugins', [] );
        $oauth_plugins = array_filter( $active, fn($pl) =>
            stripos($pl,'oauth') !== false || stripos($pl,'sso') !== false ||
            stripos($pl,'google-login') !== false || stripos($pl,'social-login') !== false
        );
        if ( ! empty( $oauth_plugins ) ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_INTEGRATION,
                'OAuth/SSO plugin detected — verify compatibility with S2NRI auth',
                'OAuth or social login plugins may interfere with S2NRI\'s auto-register and nonce-based auth.',
                [ 'plugins' => array_values( $oauth_plugins ) ],
                'Test login flow and booking submission with these plugins active. Check nonce validation still works.',
                __FILE__, __FUNCTION__
            );
        }

        // Webhook endpoint availability (inbound webhooks from Razorpay)
        $rp_enabled = $wpdb->get_var(
            "SELECT setting_value FROM {$p}s2nri_settings WHERE setting_key='razorpay_enabled' LIMIT 1"
        );
        if ( $rp_enabled === '1' ) {
            $webhook_url = rest_url( 's2nri/v1/payments/webhook' );
            $probe = wp_remote_post( $webhook_url, [
                'timeout'   => 5,
                'sslverify' => false,
                'body'      => '{}',
                'headers'   => [ 'Content-Type' => 'application/json' ],
            ] );
            if ( is_wp_error( $probe ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_INTEGRATION,
                    'Razorpay webhook endpoint unreachable',
                    "POST {$webhook_url} failed: " . $probe->get_error_message() . ". Payment webhook confirmations will not work.",
                    [ 'url' => $webhook_url, 'error' => $probe->get_error_message() ],
                    'Ensure the webhook URL is publicly accessible and not blocked by firewall/WAF.',
                    __FILE__, __FUNCTION__
                );
            } else {
                $code = wp_remote_retrieve_response_code( $probe );
                if ( $code === 404 ) {
                    $this->addFinding( self::SEV_HIGH, self::CAT_INTEGRATION,
                        'Razorpay webhook route returns 404 — payment confirmations will fail',
                        "POST {$webhook_url} returned 404. The webhook handler route is not registered.",
                        [ 'url' => $webhook_url ],
                        'Verify Dispatcher has the webhook route registered. Check if Razorpay is configured.',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        }
    }

    // ── ACCESSIBILITY ─────────────────────────────────────────────────────────
    // Server-side: checks HTML output for basic a11y issues

    private function scanAccessibility(): void {
        $html = wp_remote_retrieve_body(
            wp_remote_get( home_url(), [ 'timeout' => 3, 'sslverify' => false ] )
        );
        if ( ! $html ) return;

        // Missing lang attribute
        if ( ! preg_match( '/<html[^>]+lang=/', $html ) ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_FRONTEND,
                'Missing lang attribute on <html> element',
                'Screen readers cannot determine the document language. WCAG 2.1 failure.',
                [],
                'Add lang="en" (or appropriate language) to the <html> tag in SEO::render().',
                S2NRI_DIR . 'src/SEO.php', 'render'
            );
        }

        // Missing viewport meta
        if ( strpos( $html, 'name="viewport"' ) === false ) {
            $this->addFinding( self::SEV_MEDIUM, self::CAT_FRONTEND,
                'Missing viewport meta tag',
                'Without viewport meta, mobile browsers zoom out making content unreadable.',
                [],
                'Add <meta name="viewport" content="width=device-width, initial-scale=1"> to SEO::render().',
                S2NRI_DIR . 'src/SEO.php', 'render'
            );
        }

        // No skip navigation link (WCAG 2.4.1)
        if ( strpos( $html, 'skip' ) === false && strpos( $html, 'Skip' ) === false ) {
            $this->addFinding( self::SEV_LOW, self::CAT_FRONTEND,
                'No skip navigation link found (WCAG 2.4.1)',
                'Keyboard users must tab through all navigation on every page without a skip link.',
                [],
                'Add <a href="#main-content" class="sr-only">Skip to main content</a> as first element in body.',
                __FILE__, __FUNCTION__
            );
        }

        $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
            'Full accessibility audit requires browser-based tools',
            'Server-side checks cover HTML structure only. Run axe-core, Lighthouse, or WAVE for complete WCAG audit.',
            [ 'tools' => [ 'https://www.deque.com/axe/', 'https://wave.webaim.org/', 'https://pagespeed.web.dev/' ] ],
            'Run Lighthouse audit in Chrome DevTools (F12 → Lighthouse → Accessibility).',
            __FILE__, __FUNCTION__
        );
    }



    // ── RELATED DEPENDENCIES ANALYSIS (S12 root cause — dependency chain) ────
    // Maps every S2NRI feature to its dependencies and validates each one.
    // Surfaces the full dependency chain so root cause points to the broken link.

    private function scanDependencies(): void {
        $dependency_map = [
            'Booking Submission'     => [ 'php_ext' => [ 'json', 'mysqli' ], 'wp_plugin' => [],          'php_min' => '8.0', 'note' => 'Core booking flow' ],
            'Email Notifications'    => [ 'php_ext' => [ 'openssl' ],        'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'wp_mail + SMTP' ],
            'File Uploads'           => [ 'php_ext' => [ 'fileinfo' ],        'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'Document upload feature' ],
            'PDF Report Export'      => [ 'php_ext' => [],                    'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'Requires DomPDF (optional) or browser print' ],
            'Razorpay Payments'      => [ 'php_ext' => [ 'curl', 'openssl' ],'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'Payment gateway integration' ],
            'REST API Routing'       => [ 'php_ext' => [ 'json' ],           'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'Requires pretty permalinks' ],
            'Diagnostic Log Export'  => [ 'php_ext' => [ 'json', 'zip' ],   'wp_plugin' => [],          'php_min' => '8.0', 'note' => 'JSON/CSV/HTML download' ],
            'Cron Jobs'              => [ 'php_ext' => [],                    'wp_plugin' => [],          'php_min' => '7.4', 'note' => 'WP Cron or server cron' ],
            'Object Caching'         => [ 'php_ext' => [],                    'wp_plugin' => [ 'redis-cache', 'memcached' ], 'php_min' => '7.4', 'note' => 'Optional — improves performance' ],
        ];

        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active_plugins = get_option( 'active_plugins', [] );

        foreach ( $dependency_map as $feature => $deps ) {
            $missing_ext     = [];
            $missing_plugins = [];
            $broken_chain    = [];

            // Check PHP extensions
            foreach ( $deps['php_ext'] as $ext ) {
                if ( ! extension_loaded( $ext ) ) {
                    $missing_ext[] = $ext;
                    $broken_chain[] = "PHP extension '{$ext}' not loaded";
                }
            }

            // Check PHP version
            if ( version_compare( PHP_VERSION, $deps['php_min'], '<' ) ) {
                $broken_chain[] = "PHP " . PHP_VERSION . " < required " . $deps['php_min'];
            }

            // Check optional plugins (warn if none present when feature relies on one)
            if ( ! empty( $deps['wp_plugin'] ) ) {
                $found = false;
                foreach ( $active_plugins as $plugin_file ) {
                    foreach ( $deps['wp_plugin'] as $required_plugin ) {
                        if ( strpos( $plugin_file, $required_plugin ) !== false ) {
                            $found = true;
                            break 2;
                        }
                    }
                }
                if ( ! $found ) {
                    $missing_plugins = $deps['wp_plugin'];
                    // This is a LOW severity — these are optional/enhancement dependencies
                }
            }

            if ( ! empty( $broken_chain ) ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_PHP,
                    "Dependency broken: {$feature}",
                    "The feature '{$feature}' has a broken dependency chain: " . implode( '; ', $broken_chain ),
                    [
                        'feature'          => $feature,
                        'missing_ext'      => $missing_ext,
                        'broken_chain'     => $broken_chain,
                        'affected_note'    => $deps['note'],
                        'related_deps'     => $deps['php_ext'],
                    ],
                    "Install missing PHP extensions: " . implode( ', ', $missing_ext ) . ". Ask host or update php.ini.",
                    __FILE__, __FUNCTION__
                );
            } elseif ( ! empty( $missing_plugins ) ) {
                $this->addFinding( self::SEV_LOW, self::CAT_PERFORMANCE,
                    "Optional dependency missing: {$feature}",
                    "'{$feature}' works but performs better with one of: " . implode( ', ', $missing_plugins ),
                    [
                        'feature'       => $feature,
                        'optional_deps' => $missing_plugins,
                        'note'          => $deps['note'],
                    ],
                    "Install one of: " . implode( ', ', $missing_plugins ) . " to enhance this feature.",
                    __FILE__, __FUNCTION__
                );
            } else {
                $this->addFinding( self::SEV_INFO, self::CAT_PHP,
                    "Dependency chain OK: {$feature}",
                    "All required dependencies for '{$feature}' are present. ({$deps['note']})",
                    [ 'feature' => $feature, 'deps' => $deps['php_ext'] ],
                    'No action required.',
                    __FILE__, __FUNCTION__
                );
            }
        }
    }



    private function generateReproSteps( string $category, string $title, array $evidence ): array {
        // Generate reproduction steps from category and evidence context
        $steps = [];
        switch ( $category ) {
            case self::CAT_PHP:
                $steps = [
                    'Enable WP_DEBUG and WP_DEBUG_LOG in wp-config.php.',
                    'Reproduce the action that triggers the issue (e.g. submit a booking).',
                    'Check wp-content/debug.log for the error.',
                    'Note the file:line reference and fix the code.',
                ];
                break;
            case self::CAT_DB:
                $steps = [
                    'Open WP Admin → Tools → Query Monitor (if installed).',
                    'Reproduce the action (e.g. load the bookings list).',
                    'Note any failed queries in Query Monitor or in debug.log.',
                    'Run the failing SQL manually in phpMyAdmin to confirm the error.',
                    'Fix the schema or query as indicated.',
                ];
                break;
            case self::CAT_API:
                $steps = [
                    'Open Chrome DevTools (F12) → Network tab.',
                    'Navigate to the failing page or trigger the action.',
                    'Filter by XHR/Fetch requests to ' . rest_url( 's2nri/v1' ),
                    'Find the failed request, check Status and Response body.',
                    'Cross-reference the error with the PHP log.',
                ];
                break;
            case self::CAT_SECURITY:
                $steps = [
                    'Visit the site as an anonymous user.',
                    'Attempt to access the restricted resource: ' . ( $evidence['url'] ?? home_url() ),
                    'Verify the server returns the expected HTTP status code.',
                    'Check response headers for the missing security header.',
                    'Add the header in .htaccess or nginx config as described in the fix.',
                ];
                break;
            case self::CAT_PERFORMANCE:
                $steps = [
                    'Open Chrome DevTools (F12) → Lighthouse tab.',
                    'Run a Performance audit on ' . home_url(),
                    'Review Core Web Vitals scores (LCP, CLS, FID).',
                    'Check Network tab for slow or blocking requests.',
                    'Apply the recommended fix and re-run Lighthouse to verify improvement.',
                ];
                break;
            case self::CAT_PLUGIN:
                $steps = [
                    'Deactivate the conflicting plugin: ' . ( $evidence['plugin'] ?? 'identified plugin' ),
                    'Test the affected S2NRI feature.',
                    'If it works, reactivate the plugin and configure it to whitelist /wp-json/s2nri/*.',
                    'Test again. If still broken, contact the plugin vendor.',
                ];
                break;
            case self::CAT_INFRA:
                $steps = [
                    'SSH or cPanel into the server.',
                    'Run: df -h to check disk space, free -m for memory.',
                    'Check PHP logs: tail -100 ' . ( ini_get( 'error_log' ) ?: '/var/log/php_errors.log' ),
                    'Apply the fix described and re-run the diagnostic scan to verify.',
                ];
                break;
            default:
                $steps = [
                    'Identify the trigger: what user action or scheduled event caused this issue.',
                    'Check wp-content/debug.log for related PHP errors at the same timestamp.',
                    'Check Admin Portal → Diagnostics for related findings in the same scan.',
                    'Apply the suggested fix and re-run the diagnostic scan.',
                ];
        }
        return $steps;
    }



    // ── 5.10 DATABASE REPLICATION FAILURES ───────────────────────────────────

    private function scanReplication(): void {
        global $wpdb;

        // Check if replication is configured (SHOW SLAVE STATUS or SHOW REPLICA STATUS)
        // Suppress errors — most WP DB users don't have REPLICATION CLIENT privilege
        $suppress = $wpdb->suppress_errors( true );
        $slave_status = $wpdb->get_row( "SHOW SLAVE STATUS", ARRAY_A );
        if ( ! $slave_status ) {
            $wpdb->last_error = ''; // Clear any permission error
            $slave_status = $wpdb->get_row( "SHOW REPLICA STATUS", ARRAY_A ); // MySQL 8.0.22+
        }
        $wpdb->suppress_errors( $suppress );
        $wpdb->last_error = ''; // Don't let this bleed into other checks

        if ( $slave_status ) {
            // Replication is configured — check health
            $io_running  = $slave_status['Slave_IO_Running']  ?? $slave_status['Replica_IO_Running']  ?? 'No';
            $sql_running = $slave_status['Slave_SQL_Running'] ?? $slave_status['Replica_SQL_Running'] ?? 'No';
            $lag_secs    = (int) ( $slave_status['Seconds_Behind_Master'] ?? $slave_status['Seconds_Behind_Source'] ?? 0 );
            $last_error  = $slave_status['Last_Error'] ?? $slave_status['Last_IO_Error'] ?? '';

            if ( $io_running !== 'Yes' || $sql_running !== 'Yes' ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_DB,
                    'Database replication is stopped',
                    "Slave IO: {$io_running}, SQL: {$sql_running}. Replication has broken — reads may serve stale data.",
                    [ 'io_running' => $io_running, 'sql_running' => $sql_running, 'last_error' => $last_error ],
                    'Run SHOW SLAVE STATUS\G on the replica server. Fix the error shown in Last_Error and run START SLAVE;',
                    __FILE__, __FUNCTION__
                );
            } elseif ( $lag_secs > 60 ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                    "Replication lag: {$lag_secs} seconds behind primary",
                    "The replica is {$lag_secs}s behind. Real-time reads on replica will return stale data.",
                    [ 'lag_seconds' => $lag_secs ],
                    'Investigate slow queries on replica. Consider pt-slave-delay or increasing slave_parallel_workers.',
                    __FILE__, __FUNCTION__
                );
            } else {
                $this->addFinding( self::SEV_INFO, self::CAT_DB,
                    "Database replication healthy (lag: {$lag_secs}s)",
                    "Replication IO and SQL threads running. Lag is within acceptable range.",
                    [ 'lag_seconds' => $lag_secs ],
                    'Continue monitoring replication lag. Alert at > 30s lag.',
                    __FILE__, __FUNCTION__
                );
            }
        } else {
            // No replication configured — this is normal for most WP sites
            $this->addFinding( self::SEV_INFO, self::CAT_DB,
                'No database replication configured (single-server setup)',
                'SHOW SLAVE STATUS returned empty — this server is not a MySQL replica. Normal for single-server installations.',
                [],
                'For high-availability, consider setting up MySQL primary-replica replication or use a managed DB service (AWS RDS, PlanetScale).',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── 6.10 THEME UPDATE FAILURES ───────────────────────────────────────────

    private function scanThemeUpdates(): void {
        $update_themes = get_site_transient( 'update_themes' );
        if ( $update_themes && ! empty( $update_themes->response ) ) {
            $count = count( $update_themes->response );
            $theme_names = array_keys( $update_themes->response );
            $this->addFinding( self::SEV_MEDIUM, self::CAT_WP,
                "{$count} theme update(s) available",
                "Outdated themes may contain security vulnerabilities. {$count} theme(s) have pending updates: " . implode( ', ', $theme_names ),
                [ 'count' => $count, 'themes' => $theme_names ],
                'Update themes via WP Admin → Appearance → Themes → Update Available.',
                __FILE__, __FUNCTION__
            );
        }

        // Check if active theme is compatible with current WP version
        $active_theme = wp_get_theme();
        $theme_wp_req = $active_theme->get( 'RequiresWP' );
        global $wp_version;
        if ( $theme_wp_req && version_compare( $wp_version, $theme_wp_req, '<' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_WP,
                'Active theme requires WordPress ' . $theme_wp_req . ' — current is ' . $wp_version,
                'The active theme "' . $active_theme->get( 'Name' ) . '" requires WP ' . $theme_wp_req . ' but WP ' . $wp_version . ' is installed.',
                [ 'theme' => $active_theme->get( 'Name' ), 'required_wp' => $theme_wp_req, 'current_wp' => $wp_version ],
                'Update WordPress to version ' . $theme_wp_req . ' or higher.',
                __FILE__, __FUNCTION__
            );
        }

        // Check theme PHP version requirement
        $theme_php_req = $active_theme->get( 'RequiresPHP' );
        if ( $theme_php_req && version_compare( PHP_VERSION, $theme_php_req, '<' ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_PHP,
                'Active theme requires PHP ' . $theme_php_req . ' — current is ' . PHP_VERSION,
                'The theme "' . $active_theme->get( 'Name' ) . '" requires PHP ' . $theme_php_req . '.',
                [ 'theme' => $active_theme->get( 'Name' ), 'required_php' => $theme_php_req, 'current_php' => PHP_VERSION ],
                'Upgrade PHP to ' . $theme_php_req . ' or higher.',
                __FILE__, __FUNCTION__
            );
        }
    }

    // ── 10.5 & 10.6 CRM AND ANALYTICS INTEGRATION DIAGNOSTICS ───────────────

    private function scanAnalyticsAndCRM(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Analytics — check if Google Analytics or Facebook Pixel are configured
        $settings_keys = $wpdb->get_results(
            "SELECT setting_key, setting_value FROM {$p}s2nri_settings
             WHERE setting_key IN ('google_analytics_id','fb_pixel_id','gtm_id')
             AND setting_value != ''",
            ARRAY_A
        ) ?: [];
        $analytics_configured = [];
        foreach ( $settings_keys as $row ) {
            $analytics_configured[ $row['setting_key'] ] = $row['setting_value'];
        }

        if ( ! empty( $analytics_configured ) ) {
            // Validate GA4 / GTM ID format
            if ( isset( $analytics_configured['google_analytics_id'] ) ) {
                $ga_id = $analytics_configured['google_analytics_id'];
                if ( ! preg_match( '/^(UA-\d+-\d+|G-[A-Z0-9]+|GTM-[A-Z0-9]+)$/', $ga_id ) ) {
                    $this->addFinding( self::SEV_MEDIUM, self::CAT_INTEGRATION,
                        'Google Analytics ID format invalid',
                        "Analytics ID '{$ga_id}' does not match UA-XXXXX-X, G-XXXXXXXX, or GTM-XXXXXXX format.",
                        [ 'id' => $ga_id ],
                        'Enter a valid GA4 ID (G-XXXXXXXX) or GTM ID (GTM-XXXXXXX) in Admin Settings.',
                        __FILE__, __FUNCTION__
                    );
                } else {
                    $this->addFinding( self::SEV_INFO, self::CAT_INTEGRATION,
                        'Google Analytics configured: ' . $ga_id,
                        'Analytics ID format is valid. Verify tracking is firing in Google Tag Assistant.',
                        [ 'id' => $ga_id ],
                        'Test with Google Tag Assistant Chrome extension or GA4 DebugView.',
                        __FILE__, __FUNCTION__
                    );
                }
            }

            // Facebook Pixel format check
            if ( isset( $analytics_configured['fb_pixel_id'] ) ) {
                $fb_id = $analytics_configured['fb_pixel_id'];
                if ( ! preg_match( '/^\d{15,16}$/', $fb_id ) ) {
                    $this->addFinding( self::SEV_MEDIUM, self::CAT_INTEGRATION,
                        'Facebook Pixel ID format invalid',
                        "Pixel ID '{$fb_id}' should be a 15-16 digit number.",
                        [ 'pixel_id' => $fb_id ],
                        'Get the correct Pixel ID from Facebook Events Manager → Data Sources.',
                        __FILE__, __FUNCTION__
                    );
                }
            }
        } else {
            $this->addFinding( self::SEV_INFO, self::CAT_INTEGRATION,
                'No analytics tracking configured',
                'Google Analytics, GTM, and Facebook Pixel are not configured. No conversion tracking active.',
                [],
                'Add analytics IDs in Admin Portal → Settings → Analytics to track conversions and traffic.',
                __FILE__, __FUNCTION__
            );
        }

        // CRM integration — S2NRI does not have a CRM connector yet
        // Scan for known CRM plugin integrations that might conflict
        if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $active = get_option( 'active_plugins', [] );
        $crm_plugins = array_filter( $active, function( $pl ) {
            return stripos( $pl, 'hubspot' ) !== false
                || stripos( $pl, 'salesforce' ) !== false
                || stripos( $pl, 'zoho' ) !== false
                || stripos( $pl, 'crm' ) !== false
                || stripos( $pl, 'freshdesk' ) !== false;
        } );

        if ( ! empty( $crm_plugins ) ) {
            $this->addFinding( self::SEV_INFO, self::CAT_INTEGRATION,
                'CRM plugin detected: ' . implode( ', ', array_values( $crm_plugins ) ),
                'A CRM plugin is active. S2NRI does not have native CRM integration — booking data is not automatically synced.',
                [ 'plugins' => array_values( $crm_plugins ) ],
                'Use the S2NRI export API (GET admin/export/bookings) to pull booking data into your CRM, or build a webhook bridge.',
                __FILE__, __FUNCTION__
            );
        } else {
            $this->addFinding( self::SEV_INFO, self::CAT_INTEGRATION,
                'No CRM integration active',
                'No CRM plugin detected. Booking data stays within S2NRI only.',
                [],
                'To push S2NRI bookings to a CRM: use GET /wp-json/s2nri/v1/admin/export/bookings to export data, or add a webhook on booking status change.',
                __FILE__, __FUNCTION__
            );
        }
    }



    // ── S2NRI-SPECIFIC FUNCTIONAL CHECKS ─────────────────────────────────────
    // Checks unique to this plugin's known failure patterns.
    // Each check maps to a confirmed real-world bug found during production debugging.

    private function scanS2NRISpecific(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // CHECK 1: Booking detail API — fetch a real booking and verify response structure
        // Reproduces: blank tabs issue caused by SQL error in getBookingDetail()
        $sample_booking = $wpdb->get_row(
            "SELECT id, booking_ref, status FROM {$p}s2nri_bookings ORDER BY id DESC LIMIT 1",
            ARRAY_A
        );
        if ( $sample_booking ) {
            $booking_id = (int) $sample_booking['id'];
            $api_url    = rest_url( "s2nri/v1/bookings/{$booking_id}" );

            // Use admin credentials for the test request
            $admin_users = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
            $test_user   = $admin_users[0] ?? null;

            // Use direct DB query instead of HTTP to avoid loopback timeout
            // Check that the booking can be loaded and tickets query works
            $wpdb->suppress_errors( true );
            $booking_full = $wpdb->get_row( $wpdb->prepare(
                "SELECT b.id, b.status, b.field_data FROM {$p}s2nri_bookings b WHERE b.id = %d",
                $booking_id
            ), ARRAY_A );
            $last_err = $wpdb->last_error;
            $wpdb->suppress_errors( false );

            // Test the tickets query that previously caused blank tabs
            $wpdb->suppress_errors( true );
            $ticket_test = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.id, t.subject, t.status FROM {$p}s2nri_tickets t WHERE t.booking_id = %d LIMIT 1",
                $booking_id
            ), ARRAY_A );
            $ticket_err = $wpdb->last_error;
            $wpdb->suppress_errors( false );

            if ( $ticket_err ) {
                $this->addFinding( self::SEV_CRITICAL, self::CAT_DB,
                    "Tickets query error for booking #{$booking_id}: {$ticket_err}",
                    "The tickets SELECT for booking {$booking_id} threw a SQL error: {$ticket_err}. This corrupts the booking API response and makes all 4 customer portal tabs blank.",
                    [ 'booking_id' => $booking_id, 'sql_error' => $ticket_err ],
                    'Check BookingController::getBookingDetail() for invalid column names in the tickets SELECT. Remove any column that does not exist in s2nri_tickets schema.',
                    S2NRI_DIR . 'src/Api/Controllers/BookingController.php', 'getBookingDetail'
                );
            } else {
                $msg_count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}s2nri_messages WHERE booking_id = %d AND is_internal = 0",
                    $booking_id
                ) );
                $this->addFinding( self::SEV_INFO, self::CAT_DB,
                    "Booking #{$booking_id} ({$sample_booking['booking_ref']}) DB check OK",
                    "Booking row loads correctly. Tickets query runs without error. Messages: {$msg_count}.",
                    [ 'booking_id' => $booking_id, 'booking_ref' => $sample_booking['booking_ref'], 'message_count' => $msg_count ],
                    'No action required.',
                    __FILE__, __FUNCTION__
                );
            }
        } else {
            $this->addFinding( self::SEV_INFO, self::CAT_DB,
                'No bookings in database — skipping booking detail API test',
                's2nri_bookings is empty. The booking detail API test requires at least one booking.',
                [],
                'Create a test booking via the customer portal to enable this diagnostic check.',
                __FILE__, __FUNCTION__
            );
        }

        // CHECK 2: Verify CDN/browser cache is not serving stale JS
        // The CDN at in2.cdn-alpha.com may ignore ?ver= query params and serve cached JS
        $dist_dir = S2NRI_DIR . 'dist/assets/';
        $portal_file = null;
        if ( is_dir( $dist_dir ) ) {
            foreach ( (array) scandir( $dist_dir ) as $f ) {
                if ( str_starts_with( $f, 's2nri-portal.' ) && str_ends_with( $f, '.js' ) && strpos( $f, '-xlsx' ) === false ) {
                    $portal_file = $f;
                    break;
                }
            }
        }
        if ( $portal_file ) {
            $portal_url  = S2NRI_URL . "dist/assets/{$portal_file}";
            $portal_mtime = filemtime( $dist_dir . $portal_file );
            $age_hours   = round( ( time() - $portal_mtime ) / 3600 );
            $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
                "Portal JS file: {$portal_file} (modified {$age_hours}h ago)",
                "Current portal JS filename. If CDN serves stale JS, rename the file (e.g. v2→v3) to force a new CDN cache entry.",
                [ 'filename' => $portal_file, 'url' => $portal_url, 'modified' => date( 'Y-m-d H:i:s', $portal_mtime ), 'age_hours' => $age_hours ],
                'To bust CDN cache: rename s2nri-portal.v2.js → s2nri-portal.v3.js. Portal.php findAsset() picks it up automatically.',
                $dist_dir . $portal_file, 'filename'
            );
        }

        // CHECK 3: Verify app.js/app.v2.js URL in SEO.php matches actual file on disk
        $seo_path = S2NRI_DIR . 'src/SEO.php';
        if ( file_exists( $seo_path ) ) {
            $seo_content = file_get_contents( $seo_path );
            // Find the $js_url line
            if ( preg_match( "/\$js_url\s*=\s*S2NRI_ASSETS_URL\s*\.\s*'([^']+)'/", $seo_content, $m ) ) {
                $seo_js_name = $m[1]; // e.g. 'app.js' or 'app.v2.js'
                $actual_path = S2NRI_DIR . 'assets/' . $seo_js_name;
                if ( ! file_exists( $actual_path ) ) {
                    $this->addFinding( self::SEV_CRITICAL, self::CAT_FRONTEND,
                        "SEO.php references '{$seo_js_name}' but file does not exist on disk",
                        "SEO.php enqueues assets/{$seo_js_name} but this file is not present. Homepage will not load JS. Actual files in assets/: " . implode( ', ', array_diff( scandir( S2NRI_DIR . 'assets/' ) ?: [], [ '.', '..' ] ) ),
                        [ 'referenced' => $seo_js_name, 'expected_path' => $actual_path ],
                        "Either: (a) rename the JS file to match what SEO.php expects, or (b) update SEO.php \$js_url to reference the actual filename.",
                        $seo_path, '$js_url'
                    );
                } else {
                    $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
                        "SEO.php JS reference OK: assets/{$seo_js_name}",
                        "SEO.php references assets/{$seo_js_name} and the file exists on disk.",
                        [ 'file' => $seo_js_name, 'path' => $actual_path ],
                        'No action required.',
                        $seo_path, '$js_url'
                    );
                }
            }
        }

        // CHECK 4: Verify is_email_sent column exists in s2nri_messages
        // The is_email_sent badge relies on this field — if missing, badge will never show
        $msg_cols = $wpdb->get_col( "DESCRIBE {$p}s2nri_messages", 0 ) ?: [];
        if ( ! in_array( 'is_email_sent', $msg_cols, true ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_DB,
                "s2nri_messages missing column: is_email_sent",
                "The is_email_sent column does not exist. The 'Send via Email' feature will fail silently — messages will save but the is_email_sent flag cannot be stored.",
                [ 'table' => $p . 's2nri_messages', 'actual_columns' => $msg_cols ],
                "ALTER TABLE {$p}s2nri_messages ADD COLUMN is_email_sent TINYINT(1) NOT NULL DEFAULT 0;",
                __FILE__, __FUNCTION__
            );
        }

        // CHECK 5: Customer portal URL accessibility
        $portal_url_base = home_url( '/portal/' );
        $portal_response = wp_remote_get( $portal_url_base, [ 'timeout' => 3, 'sslverify' => false ] );
        if ( is_wp_error( $portal_response ) ) {
            $this->addFinding( self::SEV_HIGH, self::CAT_API,
                'Customer portal URL unreachable: ' . $portal_url_base,
                'GET ' . $portal_url_base . ' failed: ' . $portal_response->get_error_message() . '. Customers cannot access the portal.',
                [ 'url' => $portal_url_base, 'error' => $portal_response->get_error_message() ],
                'Check WP rewrite rules are flushed. Go to WP Admin → Settings → Permalinks → Save Changes.',
                __FILE__, __FUNCTION__
            );
        } else {
            $portal_code = wp_remote_retrieve_response_code( $portal_response );
            $portal_body = wp_remote_retrieve_body( $portal_response );
            $has_cfg     = strpos( $portal_body, 'S2NRI_CFG' ) !== false;
            if ( $portal_code !== 200 ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_API,
                    "Customer portal returned HTTP {$portal_code}",
                    "GET {$portal_url_base} returned {$portal_code}. Expected 200. Portal is not serving the SPA.",
                    [ 'url' => $portal_url_base, 'code' => $portal_code ],
                    'Check Portal::matchesSlug() and Portal::renderSPA(). Verify rewrite rules are registered.',
                    S2NRI_DIR . 'src/Portal.php', 'ensureRewriteRules'
                );
            } elseif ( ! $has_cfg ) {
                $this->addFinding( self::SEV_HIGH, self::CAT_FRONTEND,
                    'Customer portal page missing S2NRI_CFG injection',
                    "GET {$portal_url_base} returned 200 but response does not contain S2NRI_CFG. React app will not initialize — nonce, API base, and user context are missing.",
                    [ 'url' => $portal_url_base ],
                    'Check SEO::injectConfig() is called in renderSPA(). Verify wp_head hook fires correctly.',
                    S2NRI_DIR . 'src/SEO.php', 'injectConfig'
                );
            } else {
                $this->addFinding( self::SEV_INFO, self::CAT_FRONTEND,
                    'Customer portal URL OK: ' . $portal_url_base,
                    "GET {$portal_url_base} returns 200 with S2NRI_CFG injected. SPA will initialize correctly.",
                    [ 'url' => $portal_url_base, 'code' => $portal_code ],
                    'No action required.',
                    __FILE__, __FUNCTION__
                );
            }
        }
    }

    // ── REPORT BUILDER ────────────────────────────────────────────────────────

    private function buildReport(): array {
        $sev_order  = [ self::SEV_CRITICAL => 0, self::SEV_HIGH => 1, self::SEV_MEDIUM => 2, self::SEV_LOW => 3, self::SEV_INFO => 4 ];
        $sorted     = $this->findings;
        usort( $sorted, fn( $a, $b ) => $sev_order[ $a['severity'] ] <=> $sev_order[ $b['severity'] ] );

        $counts = array_count_values( array_column( $sorted, 'severity' ) );

        $score = 100;
        $score -= ( $counts[ self::SEV_CRITICAL ] ?? 0 ) * 25;
        $score -= ( $counts[ self::SEV_HIGH ]     ?? 0 ) * 10;
        $score -= ( $counts[ self::SEV_MEDIUM ]   ?? 0 ) * 5;
        $score -= ( $counts[ self::SEV_LOW ]      ?? 0 ) * 2;
        $score  = max( 0, min( 100, $score ) );

        return [
            'generated_at'    => current_time( 'c' ),
            'scan_duration_ms'=> round( ( microtime( true ) - $this->start_time ) * 1000 ),
            'health_score'    => $score,
            'health_label'    => $score >= 90 ? 'Excellent' : ( $score >= 70 ? 'Good' : ( $score >= 50 ? 'Fair' : 'Poor' ) ),
            'summary'         => [
                'total'    => count( $sorted ),
                'critical' => $counts[ self::SEV_CRITICAL ] ?? 0,
                'high'     => $counts[ self::SEV_HIGH ]     ?? 0,
                'medium'   => $counts[ self::SEV_MEDIUM ]   ?? 0,
                'low'      => $counts[ self::SEV_LOW ]      ?? 0,
                'info'     => $counts[ self::SEV_INFO ]     ?? 0,
            ],
            'findings'        => $sorted,
            'environment'     => $this->collectEnvironmentSnapshot(),
        ];
    }

    private function collectEnvironmentSnapshot(): array {
        global $wp_version, $wpdb;
        return [
            'php_version'      => PHP_VERSION,
            'wp_version'       => $wp_version,
            'mysql_version'    => $wpdb->get_var( 'SELECT VERSION()' ) ?? 'unknown',
            'plugin_version'   => S2NRI_VERSION,
            'server_software'  => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'memory_limit'     => ini_get( 'memory_limit' ),
            'memory_used_mb'   => round( memory_get_usage( true ) / 1024 / 1024, 1 ),
            'max_exec_time'    => ini_get( 'max_execution_time' ),
            'upload_max'       => ini_get( 'upload_max_filesize' ),
            'opcache_enabled'  => extension_loaded( 'Zend OPcache' ),
            'site_url'         => home_url(),
            'api_base'         => rest_url( 's2nri/v1' ),
            'active_plugins'   => get_option( 'active_plugins', [] ),
            'permalink'        => get_option( 'permalink_structure', 'plain' ),
            'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
            'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
            'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
            'ssl_active'       => is_ssl(),
        ];
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    private function addFinding(
        string $severity, string $category, string $title,
        string $description, array $evidence, string $fix,
        string $file = '', string $func = '', string $prevention = ''
    ): void {
        $this->findings[] = [
            'id'           => 's2nri_diag_' . md5( $title . $file . $func ),
            'severity'     => $severity,
            'category'     => $category,
            'title'        => $title,
            'description'  => $description,
            'evidence'     => $evidence,
            'fix'          => $fix,
            'prevention'   => $prevention ?: $this->defaultPrevention( $severity, $category ),
            'impact'       => $this->estimateImpact( $severity ),
            'location'        => [
                'file'     => str_replace( ABSPATH, '', $file ),
                'function' => $func,
            ],
            'reproduction_steps' => $this->generateReproSteps( $category, $title, $evidence ),
            'detected_at'        => current_time( 'c' ),
        ];
    }

    private function defaultPrevention( string $severity, string $category ): string {
        $map = [
            self::CAT_PHP         => 'Keep PHP updated. Enable opcache. Monitor memory usage in staging before production deployments.',
            self::CAT_DB          => 'Run regular database OPTIMIZE TABLE. Monitor slow query log. Keep MySQL updated.',
            self::CAT_WP          => 'Keep WordPress core, plugins, and themes updated. Test updates in staging first.',
            self::CAT_API         => 'Add API monitoring with uptime alerts. Log all 4xx/5xx responses. Review monthly.',
            self::CAT_PLUGIN      => 'Test plugin combinations in staging. Maintain a minimal plugin count.',
            self::CAT_PERFORMANCE => 'Set performance budgets. Run Lighthouse in CI/CD. Monitor Core Web Vitals monthly.',
            self::CAT_SECURITY    => 'Run security scans monthly. Enable 2FA. Keep credentials rotated quarterly.',
            self::CAT_NETWORK     => 'Monitor uptime with external tools (UptimeRobot, Pingdom). Set SSL auto-renewal alerts.',
            self::CAT_INTEGRATION => 'Add webhook logging. Set up payment gateway sandbox for regression testing.',
            self::CAT_FRONTEND    => 'Run automated browser tests. Monitor Core Web Vitals via Search Console.',
            self::CAT_INFRA       => 'Set disk space alerts at 70% and 90%. Monitor SSL expiry 30 days before.',
            self::CAT_UNKNOWN     => 'Enable comprehensive logging. Review diagnostic reports weekly.',
        ];
        return $map[ $category ] ?? 'Review and fix this issue before it escalates. Add monitoring to detect recurrence.';
    }

    private function estimateImpact( string $severity ): string {
        return match( $severity ) {
            'critical' => 'System-breaking. Site may be down or data loss occurring. Fix immediately.',
            'high'     => 'Significant feature degradation affecting all or many users. Fix within 24 hours.',
            'medium'   => 'Partial functionality degraded. Some users affected. Fix within 1 week.',
            'low'      => 'Minor inconvenience. Performance or UX slightly degraded. Fix within 1 month.',
            default    => 'Informational. No immediate action required.',
        };
    }

    private function parseMemoryLimit( string $val ): int {
        if ( $val === '-1' ) return -1;
        $unit = strtolower( substr( $val, -1 ) );
        $num  = (int) $val;
        switch ( $unit ) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    }
}
