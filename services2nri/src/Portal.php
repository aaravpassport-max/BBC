<?php
/**
 * S2NRI Portal — Standalone React SPA
 *
 * Called directly from services2nri.php via Portal::boot() immediately after
 * the classmap autoloader. Intercepts /portal/* and /s2nri-admin/* and
 * outputs a complete standalone HTML page — no WordPress theme needed.
 *
 * Hook timing (critical):
 *   plugins_loaded  → WP core loaded, but $wp_rewrite NOT yet init'd
 *                     rest_url() / get_rest_url() CRASHES here
 *   init            → $wp_rewrite ready, rest_url() works, is_user_logged_in() works
 *
 * Therefore renderSPA() MUST fire on 'init', not 'plugins_loaded'.
 */

namespace S2NRI;

defined('ABSPATH') || exit;

class Portal {

    const CUSTOMER_SLUG  = 'portal';
    const ADMIN_SLUG     = 's2nri-admin';

    // ── Entry point — called directly from services2nri.php ──────────────────
    public static function boot(): void {
        $path        = self::requestPath();
        $is_customer = self::matchesSlug($path, self::CUSTOMER_SLUG);
        $is_admin    = self::matchesSlug($path, self::ADMIN_SLUG);

        // Write .htaccess on every init until it exists (idempotent)
        add_action('init', [self::class, 'ensureHtaccess'], 1);
        // Nginx compat: register WP rewrite rules for /portal/ and /s2nri-admin/
        add_action('init', [self::class, 'ensureRewriteRules'], 2);
        register_activation_hook(S2NRI_FILE, [self::class, 'ensureHtaccess']);

        if (!$is_customer && !$is_admin) return;

        // Handle magic-link (?s2nri_token=XXX) — needs get_user_by() → init
        if (!empty($_GET['s2nri_token'])) {
            add_action('init', [self::class, 'handleMagicLink'], 2);
        }

        // Nginx compat: intercept via template_redirect BEFORE WP outputs 404.
        // On Apache the .htaccess handles it; on Nginx WP processes every request.
        $is_admin_flag = $is_admin;
        add_action('template_redirect', static function () use ($is_admin_flag) {
            $path = \S2NRI\Portal::requestPath();
            if (self::matchesSlug($path, self::CUSTOMER_SLUG)
                || self::matchesSlug($path, self::ADMIN_SLUG)) {
                self::renderSPA($is_admin_flag);
                exit;
            }
        }, 1);

        // pre_get_posts: tell WP this is not a 404 so it doesn't abort early
        add_action('pre_get_posts', static function($q) use ($is_admin_flag) {
            if (!$q->is_main_query()) return;
            $path = \S2NRI\Portal::requestPath();
            if (\S2NRI\Portal::matchesSlug($path, \S2NRI\Portal::CUSTOMER_SLUG)
                || \S2NRI\Portal::matchesSlug($path, \S2NRI\Portal::ADMIN_SLUG)) {
                $q->set('s2nri_spa', $is_admin_flag ? 'admin' : 'portal');
                $q->is_404 = false;
                $q->is_page = true;
            }
        });

        // Render the SPA on 'init' — rest_url() needs $wp_rewrite which init sets up
        add_action('init', static function () use ($is_admin_flag) {
            self::renderSPA($is_admin_flag);
            exit;
        }, 10);
    }

    // ── Request path (strip WP subdirectory prefix) ───────────────────────────
    public static function requestPath(): string {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = strtok($uri, '?') ?: '/';

        if (defined('WP_HOME')) {
            $base = rtrim(parse_url(WP_HOME, PHP_URL_PATH) ?? '', '/');
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
        }

        return '/' . ltrim($path, '/');
    }

    public static function matchesSlug(string $path, string $slug): bool {
        return $path === '/' . $slug
            || str_starts_with($path, '/' . $slug . '/');
    }

    // ── Render full standalone HTML ───────────────────────────────────────────
    // Called at init priority 10 — $wp_rewrite is ready, rest_url() works fine.
    // Uses findAsset() to locate the compiled JS — no dependency on dist/index.html
    // filename (which changes on every Vite build due to content hashing).
    private static function renderSPA(bool $is_admin): void {
        if ( class_exists( '\S2NRI\AssetBuildStamp' ) ) {
            \S2NRI\AssetBuildStamp::ensureReleaseStaged();
        }

        $asset_ver = class_exists( '\S2NRI\AssetBuildStamp' )
            ? \S2NRI\AssetBuildStamp::publicVersion()
            : ( file_exists( S2NRI_DIR . 'assets/app.js' )
                ? substr( md5_file( S2NRI_DIR . 'assets/app.js' ), 0, 12 )
                : S2NRI_VERSION );

        $release_base = class_exists( '\S2NRI\AssetBuildStamp' )
            ? \S2NRI\AssetBuildStamp::releaseBaseUrl( $asset_ver )
            : S2NRI_ASSETS_URL;
        $js_url  = $release_base . 'app.js';
        $css_url = ( class_exists( '\S2NRI\AssetBuildStamp' )
            && is_file( \S2NRI\AssetBuildStamp::releaseAbsDir( $asset_ver ) . 'app.css' ) )
            ? $release_base . 'app.css'
            : S2NRI_ASSETS_URL . 'app.css';

        $missing = self::missingBootAssets( $asset_ver );
        if ( $missing !== [] ) {
            status_header( 500 );
            $name = class_exists( '\S2NRI\Models\Setting' )
                ? \S2NRI\Models\Setting::get( 'platform_name', get_bloginfo( 'name' ) )
                : get_bloginfo( 'name' );
            echo self::errorPage(
                $name . ' — setup incomplete',
                'Required built files are missing: ' . implode( ', ', $missing )
                    . '. Re-upload services2nri-full-source.zip (v' . S2NRI_VERSION . '+).'
            );
            return;
        }

        try {
            $config = Bootstrap::getJsConfig();
        } catch ( \Throwable $e ) {
            $settings_fallback = \S2NRI\Models\Setting::getPublic();
            $config = [
                'apiBase'     => rtrim( rest_url( 's2nri/v1' ), '/' ),
                'spaBase'     => home_url( '' ),
                'assetsUrl'   => S2NRI_ASSETS_URL,
                'nonce'       => wp_create_nonce( 's2nri_api' ),
                'portalToken' => '',
                'version'     => S2NRI_VERSION,
                'currentUser' => null,
                'settings'    => $settings_fallback,
                'design'      => [],
                'builderUrl'  => home_url( '/' . BuilderPage::SLUG ),
                'bootError'   => $e->getMessage(),
            ];
        }

        $home = rtrim( parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/', '/' );
        $slug = $is_admin ? self::ADMIN_SLUG : self::CUSTOMER_SLUG;
        $name = $config['settings']['platform_name']['value']
            ?? $config['settings']['platform_name']
            ?? get_bloginfo( 'name' );
        if ( is_array( $name ) ) {
            $name = $name['value'] ?? get_bloginfo( 'name' );
        }

        $config['basePath']  = self::basePath( $is_admin );
        $config['loginUrl']  = home_url( $home . '/' . $slug . '/login' );
        $config['isAdmin']   = $is_admin;
        $config['platformName'] = (string) $name;

        $designResolved = class_exists( '\S2NRI\Design\DesignSystem' )
            ? \S2NRI\Design\DesignSystem::resolve( [] )
            : [];
        $primary = (string) ( $designResolved['colors']['primary'] ?? '' );
        if ( $primary === '' ) {
            $primary = (string) ( $config['settings']['primary_color']['value']
                ?? $config['settings']['primary_color']
                ?? '#4A6FA5' );
        }
        $config['primaryColor'] = $primary;

        $config_json = wp_json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );

        $cfg_legacy = [
            'base'         => $config['apiBase'] ?? rest_url( 's2nri/v1' ),
            'nonce'        => $config['nonce'] ?? '',
            'basePath'     => $config['basePath'],
            'loginUrl'     => $config['loginUrl'],
            'platformName' => $config['platformName'],
            'primaryColor' => $primary,
            'design'       => $config['design'] ?? [],
            'isAdmin'      => $is_admin,
            'portalToken'  => $config['portalToken'] ?? '',
            'user_id'      => $config['currentUser']['wp_id'] ?? $config['currentUser']['id'] ?? null,
            'user'         => isset( $config['currentUser'] ) ? [
                'id'         => $config['currentUser']['wp_id'] ?? $config['currentUser']['id'],
                'name'       => $config['currentUser']['display_name'] ?? $config['currentUser']['name'] ?? '',
                'email'      => $config['currentUser']['email'] ?? '',
                's2nri_role' => $config['currentUser']['s2nri_role'] ?? null,
            ] : null,
        ];
        $cfg_legacy_json = wp_json_encode(
            $cfg_legacy,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        );

        $import_map_entries = class_exists( '\S2NRI\AssetBuildStamp' )
            ? \S2NRI\AssetBuildStamp::importMapEntries()
            : [];
        $chunk_preloads = '';
        foreach ( $import_map_entries as $specifier => $abs_url ) {
            if ( strpos( $specifier, './chunks/' ) !== 0 ) {
                continue;
            }
            $chunk_preloads .= '  <link rel="modulepreload" href="' . esc_url( $abs_url ) . '">' . "\n";
        }
        $import_map_url = home_url( '/?s2nri_import_map=1&v=' . rawurlencode( $asset_ver ) );

        $design_fonts = class_exists( '\S2NRI\Design\DesignSystem' )
            ? \S2NRI\Design\DesignSystem::renderFontLinks( $designResolved )
            : '';
        $design_css = class_exists( '\S2NRI\Design\DesignSystem' )
            ? '<style id="s2nri-design-system">' . \S2NRI\Design\DesignSystem::renderInlineCss( self::requestPath() ) . '</style>'
            : '';
        $theme_css_url = S2NRI_ASSETS_URL . 's2nri-theme.css';
        $icon_url      = esc_url( home_url( '/?s2nri_icon=1&size=192' ) );
        $title         = esc_html( (string) $name );
        $p_primary     = esc_attr( $primary ?: '#4A6FA5' );
        $stamp_attr    = $asset_ver;

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
            header( 'Pragma: no-cache' );
            header( 'Surrogate-Control: no-store' );
            header( 'CDN-Cache-Control: no-store' );
            header( 'Cloudflare-CDN-Cache-Control: no-store' );
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="en">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="UTF-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '  <meta name="robots" content="noindex, nofollow">' . "\n";
        echo '  <title>' . $title . '</title>' . "\n";
        echo '  <link rel="icon" type="image/png" href="' . $icon_url . '">' . "\n";
        echo '  <meta name="theme-color" content="#' . ltrim( $p_primary, '#' ) . '">' . "\n";
        echo '  <style>' . "\n";
        echo '    *,*::before,*::after{box-sizing:border-box}' . "\n";
        echo '    html,body{margin:0;padding:0;min-height:100%;overflow-x:hidden;max-width:100vw}' . "\n";
        echo '    #s2nri-root{min-height:100vh;font-family:var(--s2-font-body,system-ui,sans-serif)}' . "\n";
        echo '    .s2nri-splash{display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:12px}' . "\n";
        echo '    .s2nri-splash__logo{font-size:28px;font-weight:700;color:' . $p_primary . '}' . "\n";
        echo '    .s2nri-splash__spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:' . $p_primary . ';border-radius:50%;animation:spin .7s linear infinite}' . "\n";
        echo '    @keyframes spin{to{transform:rotate(360deg)}}' . "\n";
        echo '  </style>' . "\n";
        echo $design_fonts;
        echo '  <link rel="stylesheet" href="' . esc_url( $css_url ) . '?v=' . esc_attr( $asset_ver ) . '">' . "\n";
        echo '  <link rel="stylesheet" href="' . esc_url( $theme_css_url ) . '?v=' . esc_attr( $asset_ver ) . '">' . "\n";
        echo $design_css;
        echo $chunk_preloads;
        if ( $import_map_entries !== [] ) {
            echo '  <script type="importmap" src="' . esc_url( $import_map_url ) . '"></script>' . "\n";
        }
        echo '</head>' . "\n";
        echo '<body>' . "\n";
        echo '  <div id="s2nri-root" class="s2-ds s2-mobile-app-root" translate="no" spellcheck="false"'
            . ' data-s2nri-version="' . esc_attr( S2NRI_VERSION ) . '"'
            . ' data-s2nri-build="' . esc_attr( $stamp_attr ) . '"'
            . ' data-s2nri-assets="' . esc_attr( S2NRI_ASSETS_URL ) . '">' . "\n";
        echo '    <div class="s2nri-splash" aria-label="Loading">' . "\n";
        echo '      <div class="s2nri-splash__logo">' . $title . '</div>' . "\n";
        echo '      <div class="s2nri-splash__spinner" role="status"></div>' . "\n";
        echo '    </div>' . "\n";
        echo '  </div>' . "\n";
        self::echoJsonBootstrap( 'S2NRI_CONFIG', 's2nri-config-json', $config_json );
        echo '  <script type="application/json" id="s2nri-cfg-json">' . str_ireplace( '</script', '<\\/script', $cfg_legacy_json ) . '</script>' . "\n";
        echo '  <script>try{window.S2NRI_CFG=JSON.parse(document.getElementById("s2nri-cfg-json").textContent||"null")}catch(e){window.S2NRI_CFG={}}</script>' . "\n";
        echo '  <script src="' . esc_url( $release_base . 'boot-config.js' ) . '"></script>' . "\n";
        echo '  <script src="' . esc_url( $release_base . 'boot-watchdog.js' ) . '"></script>' . "\n";
        echo '  <script type="module" src="' . esc_url( $js_url ) . '?v=' . esc_attr( $asset_ver ) . '"></script>' . "\n";
        echo '  <script src="' . esc_url( $release_base . 'boot-sw-cleanup.js' ) . '"></script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>' . "\n";
        // phpcs:enable
    }

    /** @return list<string> Relative paths under plugin root required for SPA boot. */
    private static function missingBootAssets( string $stamp ): array {
        $release_prefix = $stamp !== '' ? 'assets/release/' . $stamp . '/' : 'assets/';
        $required = [
            'assets/BUILD_STAMP.txt',
            $release_prefix . 'app.js',
            $release_prefix . 'boot-config.js',
            $release_prefix . 'boot-watchdog.js',
            $release_prefix . 'chunks/booking.js',
            $release_prefix . 'chunks/router.js',
            $release_prefix . 'chunks/react.js',
            $release_prefix . 'chunks/design-system.js',
        ];
        $missing = [];
        foreach ( $required as $rel ) {
            if ( ! is_file( S2NRI_DIR . $rel ) ) {
                $missing[] = $rel;
            }
        }
        return $missing;
    }

    private static function echoJsonBootstrap( string $global, string $element_id, string $json ): void {
        $safe_json = str_ireplace( '</script', '<\\/script', $json );
        echo '  <script type="application/json" id="' . esc_attr( $element_id ) . '">' . $safe_json . '</script>' . "\n";
    }

    // ── BrowserRouter basename for React ──────────────────────────────────────
    public static function basePath(bool $is_admin): string {
        $home = rtrim(parse_url(home_url('/'), PHP_URL_PATH) ?: '/', '/');
        $slug = $is_admin ? self::ADMIN_SLUG : self::CUSTOMER_SLUG;
        return $home . '/' . $slug;
    }

    // ── WordPress rewrite rules (Nginx + Apache) ──────────────────────────────
    public static function ensureRewriteRules(): void {
        add_rewrite_rule(
            '^' . self::CUSTOMER_SLUG . '(/.*)?$',
            'index.php?s2nri_spa=portal',
            'top'
        );
        add_rewrite_rule(
            '^' . self::ADMIN_SLUG . '(/.*)?$',
            'index.php?s2nri_spa=admin',
            'top'
        );
        add_filter('query_vars', static function($vars) {
            $vars[] = 's2nri_spa';
            return $vars;
        });
    }

    // ── Magic-link login ──────────────────────────────────────────────────────
    public static function handleMagicLink(): void {
        if (is_user_logged_in()) return;

        $token = sanitize_text_field($_GET['s2nri_token'] ?? '');
        if (!$token) return;

        global $wpdb;
        $hash = hash('sha256', $token);
        // FIXED: previously did not check used_at at all, and relied
        // solely on an unchecked $wpdb->delete() afterward to prevent
        // reuse — a real gap. This is the code path that actually fires
        // when a customer clicks the real magic-link email (a GET
        // request, hooked to 'init'), distinct from but sharing the same
        // s2nri_magic_links table as AuthController::verifyMagicLink()
        // (the API-based flow), which already correctly checks
        // "used_at IS NULL" and uses an atomic conditional UPDATE for
        // single-use enforcement. If this link was opened twice — two
        // tabs, forwarded, or the unchecked delete silently failing — it
        // would work again until natural expiry. Now checks used_at here
        // too, and uses the same atomic "UPDATE ... WHERE used_at IS
        // NULL" trick instead of delete(): only ONE of two simultaneous
        // uses of the same token can ever win the atomic UPDATE, since
        // the first to run sets used_at non-null, making the second's
        // WHERE clause match zero rows.
        $link = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}s2nri_magic_links
                 WHERE token_hash = %s AND used_at IS NULL AND expires_at > NOW()
                 LIMIT 1",
                $hash
            ),
            ARRAY_A
        );

        if (!$link) return;

        $claimed = $wpdb->update(
            $wpdb->prefix . 's2nri_magic_links',
            [ 'used_at' => current_time( 'mysql' ) ],
            [ 'id' => $link['id'], 'used_at' => null ] // WHERE used_at IS NULL ensures atomicity
        );
        if ( ! $claimed ) return; // Someone else's request already claimed this exact token first

        $user = get_user_by('email', $link['email']);
        if (!$user) return;

        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);

        wp_safe_redirect(remove_query_arg('s2nri_token'));
        exit;
    }

    // ── Write .htaccess rewrite rules ─────────────────────────────────────────
    public static function ensureHtaccess(): void {
        if (!function_exists('get_home_path')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file = get_home_path() . '.htaccess';

        if (!file_exists($file) && !is_writable(dirname($file))) return;

        $current = file_exists($file) ? (string)file_get_contents($file) : '';

        $portal_slug     = self::CUSTOMER_SLUG;
        $admin_slug      = self::ADMIN_SLUG;

        $block = <<<HTACCESS

# BEGIN S2NRI Portal
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^{$portal_slug}(/.*)?$ index.php [QSA,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^{$admin_slug}(/.*)?$ index.php [QSA,L]
</IfModule>
# END S2NRI Portal

HTACCESS;

        // FIXED (bug 1): already skip if our own current block is present —
        // this is the first real fix: avoid unconditionally rewriting
        // .htaccess on EVERY page load (this function was hooked to
        // 'init', firing on every single request, not just activation).
        // Unconditional file writes on every request is wasteful I/O and
        // risky on shared hosting with strict write-quota/permission
        // constraints.
        if ( strpos( $current, $block ) !== false ) {
            return;
        }

        // FIXED (bug 2): the previous cleanup regex —
        // '/\n?# BEGIN S2NRI( Portal)?.*?# END S2NRI( Portal)?\n?/s' —
        // was not anchored to an exact marker string. '# BEGIN S2NRI'
        // matches as a literal PREFIX of Bootstrap::ensureHtaccess()'s
        // completely different marker '# BEGIN S2NRI API' (the optional
        // "( Portal)?" group simply matches zero times when it sees
        // " API" instead), and the non-greedy '.*?' then consumed
        // everything up to the NEXT '# END S2NRI' occurrence — which
        // matches Bootstrap's own '# END S2NRI API' the same way. Net
        // effect: this function's own "clean up my old block" step
        // silently deleted an ENTIRELY DIFFERENT class's rewrite block
        // as an unintended side effect, every time it ran. Fixed by
        // requiring the exact, full marker string (including "Portal")
        // so this can only ever match blocks it itself created.
        $current = preg_replace('/\n?# BEGIN S2NRI Portal.*?# END S2NRI Portal\n?/s', '', $current);

        $wp_pos = strpos($current, '# BEGIN WordPress');
        if ($wp_pos !== false) {
            $current = substr($current, 0, $wp_pos) . $block . substr($current, $wp_pos);
        } else {
            $current = $block . $current;
        }

        if ( @file_put_contents($file, $current) === false ) {
            error_log( '[S2NRI] Portal::ensureHtaccess() failed to write ' . $file . ' — check file permissions.' );
        }
    }

    // ── Public URL helpers ────────────────────────────────────────────────────
    public static function customerUrl(string $path = ''): string {
        return home_url('/' . self::CUSTOMER_SLUG . ($path ? '/' . ltrim($path, '/') : ''));
    }

    public static function adminUrl(string $path = ''): string {
        return home_url('/' . self::ADMIN_SLUG . ($path ? '/' . ltrim($path, '/') : ''));
    }

    // ── Plain error page ──────────────────────────────────────────────────────
    private static function errorPage(string $heading, string $detail): string {
        return '<!doctype html><html><head><meta charset="UTF-8">'
            . '<title>S2NRI Error</title></head>'
            . '<body style="font-family:sans-serif;padding:48px;max-width:600px;margin:0 auto">'
            . '<h2 style="color:#991b1b">' . esc_html($heading) . '</h2>'
            . '<p style="color:#555;font-size:15px">' . esc_html($detail) . '</p>'
            . '</body></html>';
    }
}
