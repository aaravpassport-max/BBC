<?php
/**
 * S2NRI Builder Page
 *
 * Serves the self-contained React Builder SPA at /s2nri-builder/.
 * The SPA provides:
 *  - Homepage Builder  (hero, marquee, sections, SEO, all public settings)
 *  - Service Page Builder (per-service hero, marquee, sections, section nav)
 *  - Form Builder      (fields, conditional logic, validation, multi-step)
 *  - Status Manager    (workflow preview, bulk update, audit log)
 *
 * Architecture:
 *  1. PHP serves a minimal HTML shell with S2NRI_BUILDER config injected
 *  2. React SPA (compiled to /builder/s2nri-builder.js) mounts inside #s2nri-builder-root
 *  3. All data flows through the existing PHP REST API (Dispatcher.php)
 *  4. Source code lives in /src-react/ — fully self-contained in this plugin
 */

namespace S2NRI;

defined( 'ABSPATH' ) || exit;

class BuilderPage {

    const SLUG = 's2nri-builder';

    // ── Entry point — called from services2nri.php ───────────────────────
    public static function boot(): void {
        $path        = self::requestPath();
        $is_builder  = self::matchesSlug( $path, self::SLUG );

        if ( ! $is_builder ) return;

        // Register rewrite rules for Nginx/Apache
        add_action( 'init', [ self::class, 'registerRewriteRules' ], 2 );
        add_filter( 'query_vars', static function ( $vars ) {
            $vars[] = 's2nri_builder';
            return $vars;
        } );

        // Intercept via template_redirect (Nginx)
        add_action( 'template_redirect', static function () {
            $path = \S2NRI\BuilderPage::requestPath();
            if ( \S2NRI\BuilderPage::matchesSlug( $path, \S2NRI\BuilderPage::SLUG ) ) {
                \S2NRI\BuilderPage::render();
                exit;
            }
        }, 1 );

        // Render at init priority 10 (after WP is set up)
        add_action( 'init', static function () {
            self::render();
            exit;
        }, 10 );
    }

    // ── Render the builder HTML shell ────────────────────────────────────
    public static function render(): void {
        // Only admin users can access the builder
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            if ( ! current_user_can( 's2nri_manager' ) && ! current_user_can( 's2nri_agent' ) ) {
                status_header( 403 );
                echo '<!doctype html><html><body style="font-family:sans-serif;padding:48px"><h2>Access Denied</h2><p>You need administrator or manager access to use the S2NRI Builder.</p><a href="' . esc_url( home_url() ) . '">← Go Home</a></body></html>';
                return;
            }
        }

        $builder_js  = S2NRI_URL . 'builder/s2nri-builder.js';
        $builder_css = S2NRI_URL . 'builder/s2nri-builder.css';
        // Content hash, not filemtime — filemtime can be preserved through
        // some FTP/zip-extraction pipelines instead of reflecting actual
        // upload time, which would silently defeat cache-busting. See the
        // detailed comment in SEO.php's chunk-loop for the full reasoning
        // (found and fixed there first, applied here preemptively).
        $js_ver      = file_exists( S2NRI_DIR . 'builder/s2nri-builder.js' )
            ? substr( md5_file( S2NRI_DIR . 'builder/s2nri-builder.js' ), 0, 12 )
            : S2NRI_VERSION;
        $css_ver     = file_exists( S2NRI_DIR . 'builder/s2nri-builder.css' )
            ? substr( md5_file( S2NRI_DIR . 'builder/s2nri-builder.css' ), 0, 12 )
            : S2NRI_VERSION;

        if ( ! file_exists( S2NRI_DIR . 'builder/s2nri-builder.js' ) ) {
            status_header( 500 );
            echo '<!doctype html><html><body style="font-family:sans-serif;padding:48px">';
            echo '<h2>Builder not compiled</h2>';
            echo '<p>Run <code>cd ' . esc_html( S2NRI_DIR ) . 'src-react && npm run build</code> to compile the builder.</p>';
            echo '</body></html>';
            return;
        }

        // Build config for the React SPA
        $primary_color = \S2NRI\Models\Setting::getPublic()['primary_color'] ?? '#4A6FA5';
        $config = [
            'apiBase'    => rtrim( rest_url( 's2nri/v1' ), '/' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'version'    => S2NRI_VERSION,
            'siteUrl'    => home_url(),
            'adminUrl'   => home_url( '/admin' ),
            'builderUrl' => home_url( '/' . self::SLUG ),
            'primaryColor' => $primary_color,
            'user'       => [
                'id'    => get_current_user_id(),
                'name'  => wp_get_current_user()->display_name,
                'email' => wp_get_current_user()->user_email,
            ],
        ];

        // Portal token for X-S2NRI-Token header
        $uid = get_current_user_id();
        if ( $uid ) {
            $token     = get_user_meta( $uid, 's2nri_portal_token', true );
            $token_exp = (int) get_user_meta( $uid, 's2nri_portal_token_exp', true );
            if ( ! $token || $token_exp < time() ) {
                $token = bin2hex( random_bytes( 32 ) );
                update_user_meta( $uid, 's2nri_portal_token', $token );
                update_user_meta( $uid, 's2nri_portal_token_exp', time() + ( 8 * HOUR_IN_SECONDS ) );
            }
            $config['portalToken'] = $token;
        }

        // FIXED: window.wp.media was never available on this page — it
        // bypasses WordPress's normal wp_head()/wp_footer() pipeline
        // entirely (this whole page is a raw echo'd HTML string), and
        // wp_enqueue_media() was never called anywhere in this codebase
        // (confirmed via full-codebase search). This is the ONLY thing
        // that populates window.wp.media; without it, ImageUpload's
        // "📁 Select from Media Library" button (used in 18 places per
        // the comment above) silently did nothing on every click, with
        // no error shown — a real, 100%-reproducible dead feature. The
        // manual text-URL input still worked, so this wasn't a full
        // blocker, but the convenient picker never functioned for any
        // admin who didn't already know how to find an image's raw URL.
        //
        // Fix: call wp_enqueue_media() (the standard WP API for this),
        // then capture what WordPress would normally print via wp_head/
        // wp_footer using output buffering, since this page can't rely
        // on those hooks firing naturally. Captured once, before the
        // headers below, so it's available to interpolate into the HTML
        // string exactly like the other pre-computed values below it.
        wp_enqueue_media();
        ob_start();
        wp_print_head_scripts();
        wp_print_styles();
        $media_head_html = ob_get_clean();

        ob_start();
        wp_print_footer_scripts();
        $media_footer_html = ob_get_clean();

        $config_json = wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );

        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=UTF-8' );
            header( 'Cache-Control: no-store, no-cache, must-revalidate' );
            header( 'X-Frame-Options: SAMEORIGIN' );
            header( 'X-Content-Type-Options: nosniff' );
            header( 'Surrogate-Control: no-store' );
            header( 'CDN-Cache-Control: no-store' );
        }

        $s2nri_version_display = S2NRI_VERSION;
        $primary_color_esc     = esc_attr( $primary_color );

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>S2NRI Builder v{$s2nri_version_display}</title>
  <link rel="stylesheet" href="{$builder_css}?v={$css_ver}">
  {$media_head_html}
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; min-height: 100%; }
    :root {
      /* Overrides the canonical --s2-primary from shared/design-tokens.css
         (loaded via {$builder_css}, which imports it) with the site's real
         configured brand color — same variable name and same pattern
         SEO.php uses for the public site. The Builder's own --brand token
         (used in 18 places across HomepageBuilder/ServiceBuilder/
         FormBuilder) now aliases --s2-primary via that shared file, so this
         one override reaches all of them with zero component changes.
         See docs/ARCHITECTURE.md, "Shared design tokens". */
      --s2-primary: {$primary_color_esc};
    }
    #s2nri-builder-root { min-height: 100vh; }
    #s2nri-builder-loading {
      display: flex; align-items: center; justify-content: center;
      min-height: 100vh; font-family: sans-serif; color: #6b7280;
      flex-direction: column; gap: 16px;
    }
    .boot-spinner {
      width: 36px; height: 36px;
      border: 3px solid #e5e7eb; border-top-color: var(--brand, #0d7ab5);
      border-radius: 50%; animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
  </style>
</head>
<body>
  <div id="s2nri-builder-root">
    <div id="s2nri-builder-loading">
      <div class="boot-spinner"></div>
      <span>Loading S2NRI Builder v<?php echo S2NRI_VERSION; ?>…</span>
    </div>
  </div>
  <script>window.S2NRI_BUILDER = {$config_json};</script>
  {$media_footer_html}
  <script type="module" src="{$builder_js}?v={$js_ver}"></script>
</body>
</html>
HTML;
        // phpcs:enable
    }

    // ── Register WP rewrite rules ────────────────────────────────────────
    public static function registerRewriteRules(): void {
        add_rewrite_rule(
            '^' . self::SLUG . '(/.*)?$',
            'index.php?s2nri_builder=1',
            'top'
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────
    public static function requestPath(): string {
        $uri  = $_SERVER['REQUEST_URI'] ?? '/';
        $path = strtok( $uri, '?' ) ?: '/';
        if ( defined( 'WP_HOME' ) ) {
            $base = rtrim( parse_url( WP_HOME, PHP_URL_PATH ) ?? '', '/' );
            if ( $base !== '' && str_starts_with( $path, $base ) ) {
                $path = substr( $path, strlen( $base ) );
            }
        }
        return '/' . ltrim( $path, '/' );
    }

    public static function matchesSlug( string $path, string $slug ): bool {
        return $path === '/' . $slug || str_starts_with( $path, '/' . $slug . '/' );
    }
}
