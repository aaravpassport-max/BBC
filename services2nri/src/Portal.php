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
        $dist_dir = S2NRI_DIR . 'dist/assets/';
        $dist_url = S2NRI_URL . 'dist/assets/';

        // Find the built JS and CSS files by scanning dist/assets/ directly.
        // This avoids the dist/index.html hash-mismatch bug entirely.
        $js_file  = self::findAsset($dist_dir, '.js');
        $css_file = self::findAsset($dist_dir, '.css');

        if (!$js_file) {
            status_header(500);
            echo self::errorPage(
                'S2NRI: dist/assets/ JS file not found.',
                'The plugin ZIP was not uploaded completely. Re-upload and reactivate.'
            );
            return;
        }

        // Safe to call all WP functions now (init has fired)
        $name  = class_exists('\S2NRI\Models\Setting')
            ? \S2NRI\Models\Setting::get('platform_name', get_bloginfo('name'))
            : get_bloginfo('name');

        $designResolved = class_exists( '\S2NRI\Design\DesignSystem' )
            ? \S2NRI\Design\DesignSystem::resolve( [] )
            : [];
        $color = (string) ( $designResolved['colors']['primary'] ?? '' );
        if ( $color === '' ) {
            $color = class_exists('\S2NRI\Models\Setting')
                ? \S2NRI\Models\Setting::get('primary_color', '#4A6FA5')
                : '#4A6FA5';
        }
        $designPayload = class_exists( '\S2NRI\Design\DesignSystem' )
            ? \S2NRI\Design\DesignSystem::getPublicPayload()
            : [];

        $home   = rtrim(parse_url(home_url('/'), PHP_URL_PATH) ?: '/', '/');
        $slug   = $is_admin ? self::ADMIN_SLUG : self::CUSTOMER_SLUG;
        $config = [
            'base'         => rest_url('s2nri/v1'),
            'nonce'        => wp_create_nonce('wp_rest'),
            'basePath'     => self::basePath($is_admin),
            'loginUrl'     => home_url($home . '/' . $slug . '/login'),
            'platformName' => $name,
            'primaryColor' => $color,
            'design'       => $designPayload,
            'isAdmin'      => $is_admin,
        ];

        if (is_user_logged_in()) {
            global $wpdb;
            $uid  = get_current_user_id();
            $user = wp_get_current_user();

            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT s2nri_role FROM {$wpdb->prefix}s2nri_staff WHERE wp_user_id = %d LIMIT 1",
                    $uid
                ),
                ARRAY_A
            );

            // WP administrators are always super_admin even without a staff row
            $is_wp_admin = in_array('administrator', (array)$user->roles, true);
            $s2nri_role  = $row['s2nri_role'] ?? ($is_wp_admin ? 'super_admin' : null);

            $config['user'] = [
                'id'         => $uid,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                's2nri_role' => $s2nri_role,
            ];
            // Portal JS reads S2NRI_CFG?.user_id (top-level, not user.id).
            $config['user_id'] = $uid;

            // portalToken for X-S2NRI-Token header used by admin chunk JS
            $existing_token = get_user_meta($uid, 's2nri_portal_token', true);
            $token_exp      = (int)get_user_meta($uid, 's2nri_portal_token_exp', true);
            if ($existing_token && $token_exp > time()) {
                $config['portalToken'] = $existing_token;
            } else {
                $new_token = bin2hex(random_bytes(32));
                update_user_meta($uid, 's2nri_portal_token', $new_token);
                update_user_meta($uid, 's2nri_portal_token_exp', time() + (8 * HOUR_IN_SECONDS));
                $config['portalToken'] = $new_token;
            }
        } else {
            $config['portalToken'] = '';
        }

        $cfg_json = wp_json_encode(
            $config,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        // S2NRI_CONFIG compatibility shim.
        // The admin lazy-loaded chunks (booking-ChxRsZYG.js, admin-DC3AMdvm.js)
        // were compiled against window.S2NRI_CONFIG, not window.S2NRI_CFG.
        //
        // booking-ChxRsZYG.js (shared API layer) reads:
        //   S2NRI_CONFIG.apiBase     → base URL for every API call (M() function)
        //   S2NRI_CONFIG.nonce       → X-WP-Nonce header
        //   S2NRI_CONFIG.portalToken → X-S2NRI-Token header
        //   S2NRI_CONFIG.currentUser → initialise auth store (null = login page)
        //   S2NRI_CONFIG.settings    → primary_color, accent_color branding
        //
        // admin-DC3AMdvm.js reads the same fields for export/download calls.
        //
        // Without this shim every lazy-loaded nav page (Staff, Reviews, Tickets,
        // Audit Log, Cities, FAQs, Blog, Pricing Plans, Testimonials, Media,
        // Diagnostics, Homepage) gets apiBase='' and nonce='' → 401 on every
        // request → "Something went wrong" on every page.
        $public_settings = \S2NRI\Models\Setting::getPublic();
        $compat_config = [
            'apiBase'      => $config['base'],
            'nonce'        => $config['nonce'],
            'portalToken'  => $config['portalToken'] ?? '',
            'currentUser'  => isset($config['user']) ? [
                'id'          => $config['user']['id'],
                'wp_id'       => $config['user']['id'],
                'name'        => $config['user']['name'],
                'email'       => $config['user']['email'],
                's2nri_role'  => $config['user']['s2nri_role'],
                'is_staff'    => in_array($config['user']['s2nri_role'],
                    ['super_admin', 'manager', 'agent', 'finance'], true),
                'is_customer' => $config['user']['s2nri_role'] === 'customer',
            ] : null,
            'settings'     => $public_settings,
            'base'         => $config['base'],
            'basePath'     => $config['basePath'],
            'loginUrl'     => $config['loginUrl'],
            'platformName' => $config['platformName'],
            'primaryColor' => $color,
            'isAdmin'      => $is_admin,
            'version'      => S2NRI_VERSION,
            'builderUrl'   => home_url( '/' . \S2NRI\BuilderPage::SLUG ),
        ];
        $compat_json = wp_json_encode(
            $compat_config,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        );

        // Content hash, not a manually-maintained version number — same
        // reasoning as SEO.php/BuilderPage.php (found and fixed there
        // first): relying on S2NRI_VERSION means a change to this bundle
        // only busts cache if someone remembers to also bump that constant.
        // The CSS tag previously had NO cache-busting query string at all.
        $dist_dir  = S2NRI_DIR . 'dist/assets/';
        $js_ver_h  = $js_file && file_exists( $dist_dir . $js_file )  ? substr( md5_file( $dist_dir . $js_file ), 0, 12 )  : S2NRI_VERSION;
        $css_ver_h = $css_file && file_exists( $dist_dir . $css_file ) ? substr( md5_file( $dist_dir . $css_file ), 0, 12 ) : S2NRI_VERSION;

        $css_tag = $css_file
            ? '<link rel="stylesheet" href="' . esc_url($dist_url . $css_file) . '?v=' . $css_ver_h . '">'
            : '';
        $design_fonts = class_exists( '\S2NRI\Design\DesignSystem' )
            ? \S2NRI\Design\DesignSystem::renderFontLinks( $designResolved )
            : '';
        $design_css = class_exists( '\S2NRI\Design\DesignSystem' )
            ? '<style id="s2-design-system">' . \S2NRI\Design\DesignSystem::renderInlineCss( self::requestPath() ) . '</style>'
            : '';
        $js_src  = esc_url($dist_url . $js_file) . '?v=' . $js_ver_h;
        $favicon = esc_url(S2NRI_URL . 'dist/favicon.svg');
        $title   = esc_html($name);

        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            // Prevent Cloudflare and any CDN from caching portal SPA pages.
            // Without this, a transient 503/error response gets cached and served
            // on all subsequent requests until the CDN cache is manually purged.
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Surrogate-Control: no-store');           // Cloudflare/Varnish
            header('CDN-Cache-Control: no-store');           // Cloudflare specific
            header('Cloudflare-CDN-Cache-Control: no-store'); // Cloudflare specific
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>{$title}</title>
  <link rel="icon" type="image/svg+xml" href="{$favicon}">
  {$design_fonts}
  {$css_tag}
  {$design_css}
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; min-height: 100%; }
    #s2nri-root.s2-ds { min-height: 100vh; font-family: var(--s2-font-body, system-ui, sans-serif); }
  </style>
</head>
<body>
  <div id="s2nri-root" class="s2-ds"></div>
  <script>window.S2NRI_CFG = {$cfg_json};</script>
  <script>window.S2NRI_CONFIG = {$compat_json};</script>
  <script type="module" src="{$js_src}"></script>
</body>
</html>
HTML;
        // phpcs:enable
    }

    // ── BrowserRouter basename for React ──────────────────────────────────────
    public static function basePath(bool $is_admin): string {
        $home = rtrim(parse_url(home_url('/'), PHP_URL_PATH) ?: '/', '/');
        $slug = $is_admin ? self::ADMIN_SLUG : self::CUSTOMER_SLUG;
        return $home . '/' . $slug;
    }

    // ── Find hashed asset filename dynamically ────────────────────────────────
    // Scans dist/assets/ for the JS/CSS file — no hardcoded filename.
    // This is why beauty never had the index.html hash-mismatch problem.
    private static function findAsset(string $dir, string $ext): ?string {
        if (!is_dir($dir)) return null;
        foreach ((array)scandir($dir) as $f) {
            if (str_starts_with($f, 's2nri-portal.')
                && str_ends_with($f, $ext)
                && strpos($f, '-xlsx') === false) {
                return $f;
            }
        }
        return null;
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
