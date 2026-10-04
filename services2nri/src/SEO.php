<?php
namespace S2NRI;

defined( 'ABSPATH' ) || exit;

/**
 * SEO — server-renders the HTML shell for the React SPA.
 * TRACE: render() → builds meta from URL path → outputs full HTML page → React hydrates.
 *        Preconditions: S2NRI_ASSETS_URL, S2NRI_DIR, S2NRI_VERSION defined.
 *        Postconditions: Full HTML page rendered. React hydrates on client.
 *        Edge cases: missing asset files → rendered with empty version string.
 */
require_once __DIR__ . '/HeroInjector.php';
require_once __DIR__ . '/Blog.php';

class SEO {

    public function render(): void {
        $path    = strtok( $_SERVER['REQUEST_URI'] ?? '/', '?' );
        $meta    = $this->buildMeta( $path );
        try {
            $config = Bootstrap::getJsConfig();
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[S2NRI] getJsConfig failed: ' . $e->getMessage() );
            }
            $settings_fallback = \S2NRI\Models\Setting::getPublic();
            $config = [
                'apiBase'     => rtrim( rest_url( 's2nri/v1' ), '/' ),
                'spaBase'     => home_url( '' ),
                'assetsUrl'   => S2NRI_ASSETS_URL,
                'nonce'       => wp_create_nonce( 's2nri_api' ),
                'portalToken' => $GLOBALS['s2nri_portal_session_token'] ?? '',
                'version'     => S2NRI_VERSION,
                'currentUser' => null,
                'settings'    => $settings_fallback,
                'design'      => [],
                'builderUrl'  => home_url( '/' . \S2NRI\BuilderPage::SLUG ),
                'bootError'   => $e->getMessage(),
            ];
        }

        $js_url     = S2NRI_ASSETS_URL . 'app.js';
        $css_url    = S2NRI_ASSETS_URL . 'app.css';
        $chunks_url = S2NRI_ASSETS_URL . 'chunks/';

        // Versioning via content hash, not filemtime — see the long comment
        // at the chunk-loop below for why filemtime alone was insufficient.
        $js_ver  = file_exists( S2NRI_DIR . 'assets/app.js' )  ? substr( md5_file( S2NRI_DIR . 'assets/app.js' ), 0, 12 )  : S2NRI_VERSION;
        $css_ver = file_exists( S2NRI_DIR . 'assets/app.css' ) ? substr( md5_file( S2NRI_DIR . 'assets/app.css' ), 0, 12 ) : S2NRI_VERSION;

        // Build chunk modulepreload tags dynamically
        $chunk_preloads = '';
        $import_map_entries = [];
        $chunk_dir = S2NRI_DIR . 'assets/chunks/';
        if ( is_dir( $chunk_dir ) ) {
            foreach ( glob( $chunk_dir . '*.js' ) as $chunk_file ) {
                $cname = basename( $chunk_file );
                // BUG FOUND AND FIXED (traced deeply after a reported "Inquire
                // button not showing on service pages" that survived a
                // Cloudflare purge AND incognito testing — both of which
                // should have ruled out every OTHER caching layer, which is
                // exactly what pointed back to this file):
                //
                // This used $js_ver (app.js's OWN version) for every chunk
                // file's cache-busting query string, not that chunk's own
                // version. app.js and chunks/booking.js are DIFFERENT files
                // that change independently — a change that only touches
                // booking.js (like adding a tab to BottomNav.tsx, which
                // bundles into that chunk) does not necessarily change
                // app.js's content or timestamp. If it doesn't, every chunk
                // file kept the EXACT SAME ?v= query string as the previous
                // deploy, even though chunks/booking.js's actual content had
                // changed — giving Cloudflare and every browser zero signal
                // that anything needed re-fetching. This is a deploy-content
                // bug, not a proxy/browser-cache bug, which is why purging
                // Cloudflare and testing in incognito correctly changed
                // nothing: both were faithfully serving whatever this exact
                // URL pointed to, and the URL itself never changed.
                //
                // FIX: hash EACH chunk file's own content independently, so
                // a change to booking.js changes booking.js's ?v=, regardless
                // of whether app.js changed in the same deploy or not.
                $chunk_ver = substr( md5_file( $chunk_file ), 0, 12 );
                $abs_url = $chunks_url . $cname . '?v=' . $chunk_ver;
                $chunk_preloads .= '  <link rel="modulepreload" href="' . esc_url( $abs_url ) . '">' . "\n";
                // IMPORTANT: these two entries previously had NO ?v= query string,
                // unlike $abs_url above. The <script type="importmap"> built from
                // this array (see below) is what the browser's module resolver
                // ACTUALLY uses to fetch chunk files at runtime — modulepreload is
                // only a prefetch hint, not the real resolution path. Because the
                // import map URL never changed between deploys, browsers and any
                // CDN in front of this site (Cloudflare — see the no-cache headers
                // set below, added specifically because of past Cloudflare caching
                // issues) had no signal to ever re-fetch an updated chunk. A chunk
                // file could be replaced on the server and visitors would keep
                // getting the old cached one indefinitely. Adding the same ?v=
                // here closes that gap.
                $import_map_entries[ './chunks/' . $cname ] = esc_url( $abs_url );
                $import_map_entries[ 'chunks/' . $cname ]   = esc_url( $abs_url );
            }
        }

        $site_name   = $meta['site_name'];
        $title       = esc_attr( $meta['title'] );
        $description = esc_attr( $meta['description'] );
        $canonical   = esc_url( home_url( $path ) );
        $og_image    = esc_url( $meta['og_image'] );
        $schema_json = wp_json_encode( $meta['schema'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $config_json = wp_json_encode( $config,         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $primary     = esc_attr( $meta['primary_color'] ?? '#4A6FA5' );
        $settings    = $meta['settings'] ?? [];
        $lang        = get_locale() === 'hi_IN' ? 'hi' : 'en';
        $icon_url    = esc_url( home_url( '/?s2nri_icon=1&size=192' ) );

        // ── Build settings_flat EARLY — needed for CSS injection inside <head> ──
        $settings_flat = [];
        foreach ( ( $config['settings'] ?? [] ) as $key => $entry ) {
            $settings_flat[ $key ] = is_array($entry) ? ($entry['value'] ?? '') : $entry;
        }

        status_header( 200 );
        header( 'Content-Type: text/html; charset=utf-8' );
        $robots_tag = $meta['robots'] ?? 'index, follow';
        header( 'X-Robots-Tag: ' . $robots_tag );
        // Prevent CDN (Cloudflare) from caching the HTML page, because it contains
        // dynamic settings (hero_heading_1, stats, etc.) that change when admin saves.
        // Without this, Cloudflare caches the old HTML for hours even after saving.
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        header( 'Surrogate-Control: no-store' );

        echo '<!DOCTYPE html>' . "\n";
        echo '<html lang="' . $lang . '">' . "\n";
        echo '<head>' . "\n";
        echo '  <meta charset="UTF-8">' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        echo '  <title>' . $title . '</title>' . "\n";
        echo '  <meta name="description" content="' . $description . '">' . "\n";
        echo '  <meta name="robots" content="' . esc_attr( $robots_tag ) . '">' . "\n";
        echo '  <link rel="canonical" href="' . $canonical . '">' . "\n";
        echo '  <meta property="og:title" content="' . $title . '">' . "\n";
        echo '  <meta property="og:description" content="' . $description . '">' . "\n";
        echo '  <meta property="og:image" content="' . $og_image . '">' . "\n";
        echo '  <meta property="og:url" content="' . $canonical . '">' . "\n";
        echo '  <meta property="og:type" content="website">' . "\n";
        echo '  <meta property="og:site_name" content="' . esc_attr( $site_name ) . '">' . "\n";
        echo '  <meta name="twitter:card" content="summary_large_image">' . "\n";
        echo '  <meta name="twitter:title" content="' . $title . '">' . "\n";
        echo '  <meta name="twitter:description" content="' . $description . '">' . "\n";
        echo '  <meta name="twitter:image" content="' . $og_image . '">' . "\n";

        $gsc = $settings['google_site_verification'] ?? '';
        if ( $gsc ) {
            echo '  <meta name="google-site-verification" content="' . esc_attr( $gsc ) . '">' . "\n";
        }

        $fb_pixel = $settings['facebook_pixel_id'] ?? '';
        $ga_id    = $settings['google_analytics_id'] ?? '';

        echo '  <script type="application/ld+json">' . $schema_json . '</script>' . "\n";
        echo '  <link rel="manifest" href="/manifest.json">' . "\n";
        echo '  <link rel="icon" type="image/png" href="' . $icon_url . '">' . "\n";
        echo '  <link rel="apple-touch-icon" href="' . $icon_url . '">' . "\n";
        echo '  <meta name="theme-color" content="#' . ltrim( $primary, '#' ) . '">' . "\n";

        $p_primary = esc_attr( $primary ?: '#4A6FA5' );
        echo '  <style>' . "\n";
        echo '    *,*::before,*::after{box-sizing:border-box}' . "\n";
        echo '    html{font-family:system-ui,-apple-system,sans-serif}' . "\n";
        echo '    html{overflow-x:hidden}' . "\n";
        echo '    body{margin:0;background:#fff;overflow-x:hidden;max-width:100vw}' . "\n";
        echo '    #s2nri-root{min-height:100vh}' . "\n";
        echo '    .s2nri-splash{display:flex;align-items:center;justify-content:center;min-height:100vh;flex-direction:column;gap:12px}' . "\n";
        echo '    .s2nri-splash__logo{font-size:28px;font-weight:700;color:' . $p_primary . '}' . "\n";
        echo '    .s2nri-splash__spinner{width:40px;height:40px;border:3px solid #e2e8f0;border-top-color:' . $p_primary . ';border-radius:50%;animation:spin .7s linear infinite}' . "\n";
        echo '    @keyframes spin{to{transform:rotate(360deg)}}' . "\n";
        echo '    :root{'
            . '--s2-primary:' . $p_primary . ';'
            . '--s2-dark:#1E2D40;'
            . '--s2-darker:#1E2D40;'
            . '--s2-light-bg:#EBF0F8;'
            . '--s2-brand-light:rgba(74,111,165,.1);'
            . '}' . "\n";
        echo '    :root{--swiper-theme-color:' . $p_primary . '}' . "\n";
        // Device visibility classes for responsive section controls
        echo '    @media(min-width:1025px){.s2nri-hide-desktop{display:none!important}}' . "\n";
        echo '    @media(min-width:769px) and (max-width:1024px){.s2nri-hide-tablet{display:none!important}}' . "\n";
        echo '    @media(max-width:768px){.s2nri-hide-mobile{display:none!important}}' . "\n";

        echo '  </style>' . "\n";

        // Central design system — tokens, typography utilities, component classes
        if ( class_exists( '\S2NRI\Design\DesignSystem' ) ) {
            $design_config = \S2NRI\Design\DesignSystem::resolve( \S2NRI\Design\DesignSystem::pageContextFromPath( $path ) );
            echo \S2NRI\Design\DesignSystem::renderFontLinks( $design_config );
            echo '  <style id="s2nri-design-system">' . "\n";
            echo \S2NRI\Design\DesignSystem::renderInlineCss( $path );
            echo '  </style>' . "\n";
        }

        // Direct URL behavior for hidden services (before SPA boot)
        if ( preg_match( '#^/service/([^/]+)/?$#', $path, $sm ) && class_exists( '\S2NRI\Services\ServiceRegistry' ) ) {
            list( $http, $action, $redirect ) = \S2NRI\Services\ServiceRegistry::resolveDirectUrl( $sm[1] );
            if ( $http === 404 ) {
                status_header( 404 );
            } elseif ( $action === 'redirect' && $redirect ) {
                wp_safe_redirect( $redirect, 302 );
                exit;
            }
        }

        // Custom CSS from Homepage Page Builder — inside <head> where it belongs
        $custom_css = '';
        if ( ! empty( $settings_flat['custom_css_global'] ) ) {
            $custom_css .= strip_tags( $settings_flat['custom_css_global'] );
        }
        if ( $path === '/' && ! empty( $settings_flat['custom_css_homepage'] ) ) {
            // Strip the builder's section-hide CSS rules from custom_css_homepage.
            // Section visibility is now controlled exclusively by the JS applyHideSettings()
            // function which reads S2NRI_CONFIG.settings.hide_section_* at runtime.
            // Without this strip, a previously saved hide state locks sections as display:none
            // even after the user turns them back on in the builder, because CSS !important
            // always wins over JS style.removeProperty() on stylesheet rules.
            $hp_css = strip_tags( $settings_flat['custom_css_homepage'] );
            // Remove any rule that sets display:none on our known section inner elements.
            // These patterns match what the builder's L() function generates via the vc map.
            $hide_patterns = [
                '/#s2nri-root>div>section:nth-child\(1\)\{display:none!important\}[
]*/i',
                '/\.s2-stats-grid\{display:none!important\}[
]*/i',
                '/\.s2-hero-quote-wrap\{display:none!important\}[
]*/i',
                '/\.s2-svc-grid,\.s2-tabs\{display:none!important\}[
]*/i',
                '/\.s2-how-grid\{display:none!important\}[
]*/i',
            ];
            $hp_css = preg_replace( $hide_patterns, '', $hp_css );
            $custom_css .= $hp_css;
        }
        if ( $custom_css ) {
            echo '  <style id="s2nri-custom-css">' . "\n" . $custom_css . "\n  </style>\n";
        }

        echo '  <link rel="stylesheet" href="' . esc_url( $css_url ) . '?v=' . $css_ver . '">' . "\n";
        $theme_css_url = S2NRI_ASSETS_URL . 's2nri-theme.css';
        $theme_css_ver = file_exists( S2NRI_DIR . 'assets/s2nri-theme.css' )
            ? substr( md5_file( S2NRI_DIR . 'assets/s2nri-theme.css' ), 0, 12 ) : S2NRI_VERSION;
        echo '  <link rel="stylesheet" href="' . esc_url( $theme_css_url ) . '?v=' . $theme_css_ver . '">' . "\n";

        // FIXED: both GA and Meta Pixel were previously loaded
        // unconditionally with no consent gate at all — directly
        // contradicting this site's own Privacy Policy ("We use no
        // advertising or tracking cookies") whenever fb_pixel is ever
        // configured, and not properly consent-gating GA either. Gated
        // server-side on a real cookie (not a client-side-only check),
        // so these scripts genuinely never load in the HTML at all until
        // consent is given — the new cookie consent banner (App.tsx)
        // sets this exact cookie name/value on accept.
        $cookie_consent = isset( $_COOKIE['s2nri_cookie_consent'] ) && $_COOKIE['s2nri_cookie_consent'] === 'accepted';
        if ( $ga_id && $cookie_consent ) {
            echo '  <script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga_id ) . '"></script>' . "\n";
            echo '  <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("js",new Date());gtag("config","' . esc_attr( $ga_id ) . '");</script>' . "\n";
        }
        if ( $fb_pixel && $cookie_consent ) {
            echo '  <script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init","' . esc_js( $fb_pixel ) . '");fbq("track","PageView");</script>' . "\n";
        }

        echo $chunk_preloads;
        if ( ! empty( $import_map_entries ) ) {
            echo '  <script type="importmap">' . "\n";
            echo '  ' . wp_json_encode( [ 'imports' => $import_map_entries ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n";
            echo '  </script>' . "\n";
        }
        echo '</head>' . "\n";
        echo '<body>' . "\n";

        // ── Blog: /blog and /blog/:slug served as full PHP-rendered pages ────────
        // Blog class is in the S2NRI namespace (same as this class) so no \ prefix needed.
        if ( Blog::isBlogPath( $path ) ) {
            Blog::render( $path, $settings_flat, $config );
            echo '</body>' . "\n" . '</html>' . "\n";
            return;
        }

        // ── Homepage: plain image slider + service search bar ───────────────────
        // Removes overlay/text/buttons from compiled React hero; injects a clean search bar.
        if ( $path === '/' ) {        }

            // Label homepage sections for builder CSS targeting
            $sec_js = <<<'SECJS'
(function(){
// Add data-s2nri-section attributes to every homepage section so builder
// CSS selectors and show/hide toggles target the correct elements.
// Also adds .s2-hero-section to the hero div for builder style overrides.
function labelSections(){
  var root=document.getElementById("s2nri-root");
  if(!root)return;

  // Services Grid: section containing .s2-tabs
  var tabs=root.querySelector(".s2-tabs");
  if(tabs){
    var sv=tabs.parentElement;
    while(sv&&sv.tagName!=="SECTION"&&sv!==root)sv=sv.parentElement;
    if(sv&&sv!==root){sv.setAttribute("data-s2nri-section","services");sv.classList.add("s2nri-sec-services");}
  }

  // Stats Bar: section containing .s2-stats-grid
  var sg=root.querySelector(".s2-stats-grid");
  if(sg){
    var ss=sg.parentElement;
    while(ss&&ss.tagName!=="SECTION"&&ss!==root)ss=ss.parentElement;
    if(ss&&ss!==root){ss.setAttribute("data-s2nri-section","stats");ss.classList.add("s2nri-sec-stats");}
    else if(sg.parentElement){sg.parentElement.setAttribute("data-s2nri-section","stats");sg.parentElement.classList.add("s2nri-sec-stats");}
  }

  // How It Works: section containing .s2-how-grid
  var hw=root.querySelector(".s2-how-grid");
  if(hw){
    var hs=hw.parentElement;
    while(hs&&hs.tagName!=="SECTION"&&hs!==root)hs=hs.parentElement;
    if(hs&&hs!==root){hs.setAttribute("data-s2nri-section","how");hs.classList.add("s2nri-sec-how");}
    else if(hw.parentElement){hw.parentElement.setAttribute("data-s2nri-section","how");hw.parentElement.classList.add("s2nri-sec-how");}
  }

  // Tagline: the div containing home_tagline text (between stats and how-it-works)
  // It has no class — identify by its unique border-bottom style
  var allDivs=root.querySelectorAll("div[style]");
  for(var i=0;i<allDivs.length;i++){
    var s=allDivs[i].style;
    if(s.borderBottom&&s.borderBottom.indexOf("f0f0f0")!==-1&&s.textAlign==="center"){
      allDivs[i].setAttribute("data-s2nri-section","tagline");
      allDivs[i].classList.add("s2nri-sec-tagline");
      break;
    }
  }

  // Hero: already handled by hero override JS (adds .s2-hero-section)

  // Apply section visibility based on hide_section_* settings
  applyHideSettings();
}

function applyHideSettings(){
  var cfg=(window.S2NRI_CONFIG&&window.S2NRI_CONFIG.settings)||{};
  // Map: setting key -> [section class, inner selectors to force-show when visible]
  var map={
    "hide_section_services":["s2nri-sec-services",[".s2-svc-grid",".s2-tabs"]],
    "hide_section_stats":   ["s2nri-sec-stats",  [".s2-stats-grid"]],
    "hide_section_how":     ["s2nri-sec-how",    [".s2-how-grid"]],
    "hide_section_tagline": ["s2nri-sec-tagline",[]],
    "hide_section_hero":    ["s2-hero-section",  []]
  };
  Object.keys(map).forEach(function(key){
    var cls=map[key][0];
    var inner=map[key][1];
    var el=document.querySelector("."+cls);
    if(!el)return;
    if(cfg[key]==="1"){
      // Hide: add class to section (CSS handles display:none)
      el.classList.add("s2nri-section-hidden");
    } else {
      // Show: remove class AND force-show any inner elements the builder CSS may have hidden
      el.classList.remove("s2nri-section-hidden");
      el.style.removeProperty("display");
      inner.forEach(function(sel){
        var nodes=el.querySelectorAll(sel);
        nodes.forEach(function(n){n.style.removeProperty("display");});
      });
    }
  });
}

var obs2=new MutationObserver(function(_,ob){
  // Wait for at least the services section to render
  if(document.querySelector("#s2nri-root .s2-svc-grid")){
    labelSections();
    ob.disconnect();
  }
});
obs2.observe(document.getElementById("s2nri-root")||document.body,{childList:true,subtree:true});
if(document.readyState==="complete")labelSections();
else window.addEventListener("load",labelSections);
})();
SECJS;
            echo '<script id="s2nri-sec-labels">' . "\n" . $sec_js . "\n" . '</script>' . "\n";

        // ── Homepage hero overlay removal ────────────────────────────────────────
        // Actively removes any filter/overlay from the hero slider after React mounts.
        // Runs via MutationObserver + multiple timeouts to catch any timing window.
        // Works regardless of where the overlay originates (compiled JS, theme CSS, old cache).
        $hero_fix_js = <<<'HEROFIXJS'
(function(){
  'use strict';
  function removeHeroOverlay() {
    var root = document.getElementById('s2nri-root');
    if (!root) return;

    // 1. Find the outer hero wrapper div (first child of root's page div)
    var heroWrap = root.querySelector('div[style*="clamp(480px"]');
    if (!heroWrap) {
      heroWrap = root.querySelector('.swiper')
        ? root.querySelector('.swiper').parentElement
        : null;
    }

    if (heroWrap) {
      // 2. Remove any filter from all divs inside the hero wrapper
      var allDivs = heroWrap.querySelectorAll('div');
      for (var i = 0; i < allDivs.length; i++) {
        var d = allDivs[i];
        // Remove brightness/any filter
        if (d.style.filter) d.style.filter = '';
        if (d.style.webkitFilter) d.style.webkitFilter = '';

        // Remove overlay divs: those with rgba(0,0,0...) or linear-gradient backgrounds
        var bg = d.style.background || d.style.backgroundColor || '';
        if (
          bg.indexOf('rgba(0,0,0') !== -1 ||
          bg.indexOf('rgba(0, 0, 0') !== -1 ||
          bg.indexOf('linear-gradient') !== -1
        ) {
          // Only remove if inside a Swiper slide (not a content element)
          var parent = d.parentElement;
          var inSlide = false;
          while (parent && parent !== heroWrap) {
            if (parent.classList && parent.classList.contains('swiper-slide')) {
              inSlide = true;
              break;
            }
            parent = parent.parentElement;
          }
          if (inSlide) {
            d.style.background = '';
            d.style.backgroundColor = '';
            d.style.display = 'none';
          }
        }
      }

      // 3. Add class for CSS targeting
      heroWrap.classList.add('s2-hero-section');
    }

    // 4. Also remove any style tag injected by old plugin versions
    var oldStyle = document.getElementById('s2nri-hero-override');
    if (oldStyle) oldStyle.remove();
    var oldScript = document.querySelector('script[id="s2nri-hero-override"]');
    if (oldScript) oldScript.remove();
  }

  // Run immediately, then on timeouts, then watch for React mount
  removeHeroOverlay();
  setTimeout(removeHeroOverlay, 300);
  setTimeout(removeHeroOverlay, 800);
  setTimeout(removeHeroOverlay, 1500);
  setTimeout(removeHeroOverlay, 3000);

  var heroObs = new MutationObserver(function() {
    if (document.querySelector('#s2nri-root .swiper-slide')) {
      removeHeroOverlay();
    }
  });
  heroObs.observe(document.body, { childList: true, subtree: true });
  // Stop observing after 10 seconds to avoid memory leak
  setTimeout(function() { heroObs.disconnect(); }, 10000);
})();
HEROFIXJS;
        echo '<script id="s2nri-hero-fix">' . "\n" . $hero_fix_js . "\n" . '</script>' . "\n";

                // ── Service page: inject hero_settings + marquee_settings into page ─────
        // The React Dn() component reads s.image_url from the API response.
        // hero_settings and marquee_settings are saved by the Service Builder
        // but the compiled app.js does not read them (source unknown/inaccessible).
        // We inject them via window.S2NRI_SERVICE_DATA so a post-mount script
        // can apply them to the rendered DOM without modifying the React bundle.
        if ( preg_match( '#^/service/([^/?#]+)#', $path, $m ) ) {
            global $wpdb;
            $svc_slug = sanitize_key( $m[1] );

            // Always inject the section navigator — it queries sections internally.
            // Do NOT gate on $svc_row because the DB query can fail silently
            // (e.g. object cache returning stale null) even when React loads fine.
            echo \HeroInjector::renderSectionNav( $svc_slug, $settings_flat );

            // Try to load hero/marquee settings — optional, doesn't block nav
            $svc_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT hero_settings, marquee_settings, image_url, name
                 FROM `{$wpdb->prefix}s2nri_services`
                 WHERE slug = %s LIMIT 1",
                $svc_slug
            ), ARRAY_A );

            if ( $svc_row ) {
                $hero_data    = [];
                $marquee_data = [];
                if ( ! empty( $svc_row['hero_settings'] ) ) {
                    $h = json_decode( $svc_row['hero_settings'], true );
                    if ( is_array( $h ) ) $hero_data = $h;
                }
                if ( ! empty( $svc_row['marquee_settings'] ) ) {
                    $mq = json_decode( $svc_row['marquee_settings'], true );
                    if ( is_array( $mq ) ) $marquee_data = $mq;
                }
                $svc_data_json = wp_json_encode( [
                    'slug'             => $svc_slug,
                    'image_url'        => $svc_row['image_url'] ?? '',
                    'name'             => $svc_row['name'] ?? '',
                    'hero_settings'    => $hero_data,
                    'marquee_settings' => $marquee_data,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                echo '  <script>window.S2NRI_SERVICE_DATA=' . $svc_data_json . ';</script>' . "\n";
                echo \HeroInjector::renderServiceEnhancement( $svc_slug, $settings_flat );
            }
        }

        echo '  <div id="s2nri-root" class="s2-ds" translate="no" spellcheck="false">' . "\n";
        echo '    <div class="s2nri-splash" aria-label="Loading ' . esc_attr( $site_name ) . '">' . "\n";
        echo '      <div class="s2nri-splash__logo">' . esc_html( $site_name ) . '</div>' . "\n";
        echo '      <div class="s2nri-splash__spinner" role="status"></div>' . "\n";
        echo '    </div>' . "\n";
        echo '  </div>' . "\n";
        echo '  <script>window.S2NRI_CONFIG=' . $config_json . ';</script>' . "\n";

        $diag_url = S2NRI_ASSETS_URL . 'diagnostic-collector.js';
        $diag_ver = file_exists( S2NRI_DIR . 'assets/diagnostic-collector.js' )
                      ? substr( md5_file( S2NRI_DIR . 'assets/diagnostic-collector.js' ), 0, 12 )
                      : S2NRI_VERSION;
        echo '  <script src="' . esc_url( $diag_url ) . '?v=' . $diag_ver . '"></script>' . "\n";

        $browser_probe = get_option( 's2nri_browser_probe_script', '' );
        if ( $browser_probe ) {
            echo '  <script>' . "\n" . $browser_probe . "\n" . '  </script>' . "\n";
        }
        echo '  <script type="module" src="' . esc_url( $js_url ) . '?v=' . $js_ver . '"></script>' . "\n";
        echo '  <script>' . "\n";
        echo '    (function(){' . "\n";
        echo '      var t=setTimeout(function(){' . "\n";
        echo '        var root=document.getElementById("s2nri-root");' . "\n";
        echo '        if(!root||!root.querySelector(".s2nri-splash"))return;' . "\n";
        echo '        var err=(window.S2NRI_CONFIG&&window.S2NRI_CONFIG.bootError)||"";' . "\n";
        echo '        root.innerHTML=\'<div style="padding:48px 24px;text-align:center;font-family:system-ui,sans-serif;max-width:520px;margin:0 auto">\'+' . "\n";
        echo '          \'<p style="font-weight:700;color:#1e293b">App did not start</p>\'+' . "\n";
        echo '          \'<p style="color:#64748b;font-size:14px">The loading screen stayed visible. Usually this means <code>assets/app.js</code> failed to load, or the plugin needs an update (4.7.13+ fixes a common crash).</p>\'+' . "\n";
        echo '          (err?\'<pre style="text-align:left;font-size:11px;background:#f1f5f9;padding:12px;border-radius:8px;overflow:auto">\'+err.replace(/</g,"&lt;")+"</pre>\':"")+' . "\n";
        echo '          \'<p><button type="button" onclick="location.reload()" style="padding:10px 20px;border-radius:8px;border:none;background:#4A6FA5;color:#fff;font-weight:600;cursor:pointer">Reload</button></p></div>\';' . "\n";
        echo '      },15000);' . "\n";
        echo '      window.addEventListener("s2nri-app-mounted",function(){clearTimeout(t);},{once:true});' . "\n";
        echo '    })();' . "\n";
        echo '    // Kills any stale service worker automatically, on every visit, for' . "\n";
        echo '    // every visitor — no manual FTP check or DevTools step required. This' . "\n";
        echo '    // site previously registered a service worker at /sw.js; on hosts where' . "\n";
        echo '    // that file failed to write correctly, an old cache-first worker could' . "\n";
        echo '    // stay active in a visitor\'s browser indefinitely, serving stale JS/CSS' . "\n";
        echo '    // no matter what changes on the server. Registration is intentionally' . "\n";
        echo '    // NOT re-enabled below — this only tears down what may already be there.' . "\n";
        echo '    if ("serviceWorker" in navigator) {' . "\n";
        echo '      navigator.serviceWorker.getRegistrations().then(function(regs){' . "\n";
        echo '        regs.forEach(function(r){ r.unregister(); });' . "\n";
        echo '      }).catch(function(){});' . "\n";
        echo '    }' . "\n";
        echo '    if (window.caches && caches.keys) {' . "\n";
        echo '      caches.keys().then(function(keys){' . "\n";
        echo '        keys.forEach(function(k){ caches.delete(k); });' . "\n";
        echo '      }).catch(function(){});' . "\n";
        echo '    }' . "\n";
        echo '  </script>' . "\n";
        echo '</body>' . "\n";
        echo '</html>' . "\n";
    }

    // ── Meta builder ──────────────────────────────────────────────────────────

    private function buildMeta( string $path ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $settings      = \S2NRI\Models\Setting::getPublic();
        $site_name     = $settings['platform_name']   ?? 'Services2NRI';
        $tagline       = $settings['platform_tagline'] ?? 'Your Trusted NRI Service Partner';
        $default_desc  = $settings['seo_description']  ?? 'Expert services for NRIs — property, legal, banking, documentation, and more.';
        $default_title = $settings['seo_title']        ?? "{$site_name} — {$tagline}";
        $primary       = $settings['primary_color']    ?? '#4A6FA5';
        $og_image      = $settings['platform_logo_url'] ?? home_url( '/?s2nri_img=hero_1' );

        $title       = $default_title;
        $description = $default_desc;
        $schema      = [
            '@context' => 'https://schema.org',
            '@type'    => 'WebSite',
            'name'     => $site_name,
            'url'      => home_url(),
        ];

        // Enrich meta for service pages: /service/{slug}
        $robots = 'index, follow';

        if ( preg_match( '#^/service/([^/]+)#', $path, $m ) ) {
            $slug = sanitize_key( $m[1] );
            $svc  = \S2NRI\Services\ServiceRegistry::findBySlug( $slug );
            if ( $svc && ! \S2NRI\Services\ServiceRegistry::shouldIndexInSeo( $slug ) ) {
                $robots = 'noindex, nofollow';
            }
            $row = $svc ? [
                'name'       => $svc['name'],
                'short_desc' => $svc['short_desc'] ?? '',
                'seo_title'  => $svc['seo_title'] ?? '',
                'seo_desc'   => $svc['seo_desc'] ?? '',
            ] : null;
            if ( $row ) {
                $title       = $row['seo_title'] ?: "{$row['name']} — {$site_name}";
                $description = $row['seo_desc']  ?: ( $row['short_desc'] ?: $default_desc );
                $schema      = [
                    '@context'    => 'https://schema.org',
                    '@type'       => 'Service',
                    'name'        => $row['name'],
                    'description' => $description,
                    'provider'    => [ '@type' => 'Organization', 'name' => $site_name ],
                ];
            }
        }

        return compact( 'title', 'description', 'site_name', 'og_image', 'schema', 'primary', 'settings', 'robots' );
    }
}
