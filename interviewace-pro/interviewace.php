<?php
/**
 * Plugin Name: InterviewAce
 * Plugin URI:  https://interviewace.in
 * Description: AI-powered voice interview platform — serves from root domain.
 * Version:     3.0.8
 * Author:      InterviewAce
 * License:     Proprietary
 * Text Domain: interviewace
 */
defined('ABSPATH') || exit;

/*
 * ROOT-CAUSE FIX (reported: onboarding fix "not showing up" after
 * installing an already-verified-correct build): wp_enqueue_script/style
 * load react-app/dist/app.js and app.css with this constant as their
 * cache-busting version string (see the wp_enqueue_* calls below). Every
 * rebuild across this whole project kept reusing '3.0.0' — so even after
 * the real file on the server changed, browsers and any host-level cache
 * or CDN had no signal the URL's content was different and kept serving
 * whatever copy of app.js they'd already cached under that exact
 * "?ver=3.0.0" address, including a stale pre-fix copy. Bumped here, and
 * MUST be bumped on every future frontend rebuild, so the URL itself
 * changes and every layer of caching is forced to fetch the new file.
 */
/*
 * ROOT-CAUSE FIX (reported: rebuilt frontend with company packs, the
 * restored voice flow, the round-type picker, and the audio-autoplay
 * fix — all installed on the live site — still showed the old plain-
 * chat interview screen with none of it present): this constant is the
 * cache-busting query string WordPress appends to the compiled JS/CSS
 * URL (see the wp_enqueue_* calls below — "app.js?ver=3.0.5"). It was
 * left unchanged across every one of those rebuilds, so the URL never
 * changed, so the browser AND the user's CDN (visible in their own
 * screenshot — a *.cdn-alpha.com domain with a "Purge Cache" button)
 * kept confidently serving the exact same old cached file forever, with
 * no way to know the content underneath that URL had changed. This is
 * the identical class of bug already root-caused once earlier in this
 * project (see the historical note below) — it recurred because a
 * later series of frontend-only fixes didn't re-trigger the same
 * discipline. Bumped here, and MUST be bumped on every future frontend
 * rebuild without exception.
 */
define('IA_VERSION', '3.0.8');
define('IA_MIN_PHP',  '7.4');
define('IA_MIN_WP',   '6.0');
define('IA_MIN_MYSQL','5.7');
define('IA_DIR',     plugin_dir_path(__FILE__));
define('IA_URL',     plugin_dir_url(__FILE__));
/*
 * ROOT-CAUSE FIX (reported: onboarding submit silently "looping" back):
 * this constant gates the auto-migration in the plugins_loaded hook below
 * (`if (get_option('ia_db_version') !== IA_DB_VER) IA_Activator::activate();`)
 * — dbDelta() only re-runs, and only picks up newly-added columns, when
 * this string actually changes. It was left at '3.0.0' through several
 * rounds of real schema changes this project went through (e.g. the
 * best_turn_number/worst_turn_number columns added to ia_reports). Any
 * site that had already recorded ia_db_version='3.0.0' in its options
 * table from an earlier install — which is every site running any build
 * from this whole project so far — would silently skip those later
 * schema updates on every subsequent file-replace-only install, since the
 * version string never looked different. dbDelta() is safe to re-run
 * (it only adds missing columns/tables, never drops or truncates data),
 * so bumping this forces exactly that catch-up the next time the plugin
 * loads, with zero risk to existing data.
 */
/*
 * Bumped to 3.0.3: the version option on an already-affected site was
 * ALREADY recorded as 3.0.2 by the old, unconditional update_option()
 * call in activate() — even though the real table creation had failed —
 * so leaving this at 3.0.2 would mean the new verify-and-repair logic in
 * class-activator.php never actually runs on that site at all (the
 * version-mismatch check that gates it would see no difference). Bumping
 * again forces that check, on every affected site, exactly once more.
 *
 * Bumped to 3.0.4: THE ACTUAL ROOT CAUSE, finally confirmed via a raw
 * WP-CLI error (not the swallowed "(no error text returned)" the web
 * request path was showing): the ia_profiles CREATE TABLE statement in
 * class-activator.php declared a column named `current_role` WITHOUT
 * backticks. current_role collides with MariaDB's reserved
 * CURRENT_ROLE keyword (used by its CURRENT_ROLE() function, added
 * with MariaDB's role-based privilege system) — so every attempt to
 * create this table was a genuine MySQL syntax error, on every host,
 * every time, regardless of any hosting-level permission or DDL
 * policy. This was never a permissions problem. All three copies of
 * this CREATE TABLE (dbDelta, verify_and_repair_core_tables, and the
 * new get_table_sql_map used by Diagnostics) now backtick-quote
 * `current_role`, which is the standard, safe fix for a reserved-word
 * collision — no column rename needed, so no application code changes
 * required elsewhere. Bumping again so this fix actually runs on every
 * site that hit this bug.
 */
define('IA_DB_VER',  '3.0.4');

/* ── Key reader: options DB first, wp-config constants as override ── */
/* ── Environment compatibility gate ── */
function ia_check_requirements(): bool {
    if (version_compare(PHP_VERSION, IA_MIN_PHP, '<')) {
        add_action('admin_notices', function() {
            printf('<div class="notice notice-error"><p><strong>InterviewAce</strong> requires PHP %s or higher. Current: %s</p></div>',
                IA_MIN_PHP, PHP_VERSION);
        });
        return false;
    }
    global $wp_version;
    if (version_compare($wp_version, IA_MIN_WP, '<')) {
        add_action('admin_notices', function() use ($wp_version) {
            printf('<div class="notice notice-error"><p><strong>InterviewAce</strong> requires WordPress %s or higher. Current: %s</p></div>',
                IA_MIN_WP, $wp_version);
        });
        return false;
    }
    return true;
}

function ia_key(string $name, string $default=''): string {
    // Check PHP constant first (e.g. define('IA_DEEPGRAM_KEY','dg-xxx') in wp-config.php)
    if (defined($name) && constant($name)!=='') return constant($name);
    // Convert constant name to option key: IA_DEEPGRAM_KEY → ia_deepgram_key
    $opt_key = strtolower($name);                          // ia_deepgram_key
    $v = get_option($opt_key, $default);                   // reads ia_deepgram_key directly
    return is_string($v) ? trim($v) : $default;
}
function ia_jwt_secret(): string {
    if (defined('IA_JWT_SECRET') && IA_JWT_SECRET!=='') return IA_JWT_SECRET;
    $s = get_option('ia_jwt_secret');
    if (!$s) { $s=bin2hex(random_bytes(32)); update_option('ia_jwt_secret',$s,false); }
    return $s;
}

/* ── Load all classes ── */
foreach ([
    'includes/class-jwt.php',
    'includes/class-auth-middleware.php',
    'includes/class-plan-enforcer.php',
    'includes/class-claude.php',
    'includes/class-activator.php',
    'includes/class-deactivator.php',
    'api/class-api-auth.php',
    'api/class-api-profile.php',
    'api/class-api-resume.php',
    'api/class-api-interviews.php',
    'api/class-api-reports.php',
    'api/class-api-billing.php',
    'api/class-api-history.php',
    'cron/class-report-generator.php',
    'cron/class-cron-manager.php',
    'admin/class-settings-page.php',
    'admin/class-cost-dashboard.php',
    'includes/class-pdf-report.php',
    'includes/class-cost-tracker.php',
    'api/class-api-gamification.php',
    'api/class-api-ats.php',
    'api/class-api-speech.php',
    'includes/class-admin-impersonate.php',
    'includes/class-diagnostics.php',
] as $f) require_once IA_DIR.$f;

register_activation_hook(__FILE__,   ['IA_Activator',   'activate']);
register_deactivation_hook(__FILE__, ['IA_Deactivator', 'deactivate']);

/* Must be registered before activation/scheduling ever runs, and on every
   load (not just plugins_loaded's closure) since WP-Cron itself fires
   outside the normal request lifecycle wp_schedule_event was called from. */
add_filter('cron_schedules', ['IA_Cron_Manager', 'register_schedules']);

add_action('plugins_loaded', function() {
    if (!ia_check_requirements()) return;

    /* Idempotent DB/schema migration check — runs on every load, not just
       activation, so a plugin update (not a fresh activate) still picks up
       schema changes like the ia_turns unique-key fix without requiring the
       site admin to manually deactivate/reactivate. */
    if (get_option('ia_db_version') !== IA_DB_VER) {
        IA_Activator::activate();
    }

    /*
     * ROOT-CAUSE FIX: this must run here, directly inside plugins_loaded,
     * NOT from inside the rest_api_init callback further down. `init`
     * fires once, well before `rest_api_init` does on every request — an
     * add_action('init', ...) issued from inside a rest_api_init handler
     * registers a callback for a hook that has already finished firing
     * on that same request, so it would never execute. Binding it here
     * (plugins_loaded runs before init) ensures the raw PDF-download
     * endpoint actually fires. See IA_PDF_Report::bind_download_handler().
     */
    IA_PDF_Report::bind_download_handler();

    /* ── Rewrite: serve root domain → same template ── */
    add_action('init', function() {
        /*
         * ROOT-CAUSE FIX (black homepage bug): the trailing `(.*)$` matched
         * the EMPTY path too — i.e. the bare domain "/" itself — forcing
         * every request, including the homepage, through to the
         * 'interviewace-root' page (the dark React app shell), regardless
         * of the show_on_front/page_on_front options. Changed to `(.+)$`
         * (one-or-more) so a request for "/" is no longer swallowed by
         * this rule and instead falls through to WordPress's normal
         * front-page handling, which now correctly resolves to the
         * 'ia-home' marketing page (see class-activator.php
         * create_public_pages()). All /app/* and other non-empty paths
         * are unaffected — they still match and still route to the app
         * shell exactly as before.
         */
        add_rewrite_rule(
            '^(?!wp-admin|wp-json|wp-login|wp-content|wp-cron|xmlrpc)(.+)$',
            'index.php?pagename=interviewace-root',
            'top'
        );
    });

    /* ── Template override ── */
    add_filter('template_include', function($tpl) {
        global $post;
        if ($post) {
            $map = [
                'ia-home'    => 'home.php',
                'ia-pricing' => 'pricing.php',
                'ia-about'   => 'about.php',
                'ia-privacy' => 'privacy.php',
                'ia-terms'   => 'terms.php',
            ];
            if (isset($map[$post->post_name])) {
                $tmpl = IA_DIR.'templates/public/'.$map[$post->post_name];
                if (file_exists($tmpl)) return $tmpl;
            }
        }
        if (ia_is_root_app()) return IA_DIR.'templates/app-page.php';
        return $tpl;
    });

    /* ── Assets ── */
    add_action('wp_enqueue_scripts', function() {
        if (!ia_is_root_app()) return;
        wp_enqueue_script('ia-app', IA_URL.'react-app/dist/app.js', [], IA_VERSION, true);
        wp_enqueue_style('ia-app',  IA_URL.'react-app/dist/app.css', [], IA_VERSION);
        wp_localize_script('ia-app', 'IA_CONFIG', [
            'apiBase'         => esc_url(rest_url('ia/v1')),
            'siteUrl'         => esc_url(get_site_url()),
            /*
             * SECURITY FIX: Deepgram/ElevenLabs secret API keys used to be
             * sent to every visitor's browser here, unauthenticated, before
             * login — view-source was enough to lift and reuse them against
             * the site owner's paid quota. They are no longer localized at
             * all. The frontend now calls authenticated proxy endpoints
             * (/ia/v1/speech/stt, /ia/v1/speech/tts-token — see
             * api/class-api-speech.php) which hold the real keys server-side
             * and are rate-limited per user via IA_Plan_Enforcer.
             * Razorpay's key ID is a publishable/public-by-design key
             * (not a secret) and is correctly still exposed here.
             */
            'elevenLabsVoice' => get_option('ia_elevenlabs_voice','EXAVITQu4vr4xnSDxMaL'),
            'razorpayKeyId'   => get_option('ia_razorpay_key_id',''),
            'googleClientId'  => get_option('ia_google_client_id',''),
            'version'         => IA_VERSION,
            'assetsUrl'       => esc_url(IA_URL.'assets/'),
            'priyaAvatarUrl'  => esc_url(get_option('ia_priya_avatar_url', IA_URL.'assets/priya-photo.jpg')),
            'plans'           => [
                'pro'     => [
                    'display_name'  => IA_Settings_Page::get_plan_config('pro')['display_name']  ?? 'Pro',
                    'badge_label'   => IA_Settings_Page::get_plan_config('pro')['badge_label']   ?? '⭐ Pro',
                    'price_display' => IA_Settings_Page::get_plan_config('pro')['price_display'] ?? '₹299',
                    'price_period'  => IA_Settings_Page::get_plan_config('pro')['price_period']  ?? '/month',
                    'highlight'     => (bool)(IA_Settings_Page::get_plan_config('pro')['highlight'] ?? false),
                    'ribbon_text'   => IA_Settings_Page::get_plan_config('pro')['ribbon_text']   ?? '',
                    'features'      => array_values(array_filter(array_map('trim',
                        explode("\n", IA_Settings_Page::get_plan_config('pro')['features'] ?? '')))),
                    'session_minutes' => IA_Plan_Enforcer::quota('pro_session_minutes'),
                    'monthly_minutes' => IA_Plan_Enforcer::quota('pro_monthly_minutes'),
                ],
                'premium' => [
                    'display_name'  => IA_Settings_Page::get_plan_config('premium')['display_name']  ?? 'Premium',
                    'badge_label'   => IA_Settings_Page::get_plan_config('premium')['badge_label']   ?? '💎 Premium',
                    'price_display' => IA_Settings_Page::get_plan_config('premium')['price_display'] ?? '₹599',
                    'price_period'  => IA_Settings_Page::get_plan_config('premium')['price_period']  ?? '/month',
                    'highlight'     => (bool)(IA_Settings_Page::get_plan_config('premium')['highlight'] ?? true),
                    'ribbon_text'   => IA_Settings_Page::get_plan_config('premium')['ribbon_text']   ?? 'Best value',
                    'features'      => array_values(array_filter(array_map('trim',
                        explode("\n", IA_Settings_Page::get_plan_config('premium')['features'] ?? '')))),
                    'session_minutes' => IA_Plan_Enforcer::quota('premium_session_minutes'),
                    'monthly_minutes' => IA_Plan_Enforcer::quota('premium_monthly_minutes'),
                ],
            ],
        ]);
    });

    /* ── REST API ── */
    add_action('rest_api_init', function() {
        /*
         * SECURITY FIX: /health used to be public and disclosed exactly
         * which paid integrations are configured — free reconnaissance for
         * anyone probing the site. Now returns only a bare liveness signal
         * to unauthenticated callers (useful for uptime monitors) and the
         * full integration-status breakdown only to a logged-in admin.
         */
        register_rest_route('ia/v1','/health',[
            'methods'=>'GET',
            'callback'=>function(){
                $body = ['status'=>'ok','version'=>IA_VERSION];
                if (current_user_can('manage_options')) {
                    $body['keys'] = [
                        'claude'    =>ia_key('IA_CLAUDE_KEY')!=='',
                        'deepgram'  =>ia_key('IA_DEEPGRAM_KEY')!=='',
                        'elevenlabs'=>ia_key('IA_ELEVENLABS_KEY')!=='',
                        'razorpay'  =>ia_key('IA_RAZORPAY_KEY_ID')!=='',
                    ];
                }
                return new WP_REST_Response($body);
            },
            'permission_callback'=>'__return_true',
        ]);
        IA_API_Auth::register();
        IA_API_Profile::register();
        IA_API_Resume::register();
        IA_API_Interviews::register();
        IA_API_Reports::register();
        IA_API_Billing::register();
        IA_API_History::register();
        IA_API_Gamification::register();
        IA_API_ATS::register();
        IA_PDF_Report::register();
        IA_API_Speech::register();
    });

    /*
     * SECURITY FIX: CORS used to be `Access-Control-Allow-Origin: *`
     * combined with `Authorization` in the allowed headers — any website
     * could attempt authenticated cross-origin calls against this API from
     * a visitor's browser. This app is architected to run only from its own
     * root domain (that's the entire point of the catch-all rewrite in
     * ia_is_root_app()), so there is no legitimate cross-origin caller.
     * Origin is now allow-listed to the site's own URL, plus any admin-
     * configured extra origins (e.g. a staging subdomain) via the
     * `ia_cors_allowed_origins` option — never a wildcard.
     */
    add_action('rest_api_init', function() {
        remove_filter('rest_pre_serve_request','rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function($s) {
            $allowed = array_filter(array_unique(array_merge(
                [get_site_url(), home_url()],
                (array) get_option('ia_cors_allowed_origins', [])
            )));
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if ($origin && in_array(untrailingslashit($origin), array_map('untrailingslashit', $allowed), true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
            }
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Allow-Credentials: true');
            if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){status_header(200);exit;}
            return $s;
        });
    },15);

    IA_Cron_Manager::register_hooks();

    /* ── Admin bar link ── */
    add_action('admin_bar_menu', function($bar) {
        $bar->add_node(['id'=>'ia-open-app','title'=>'🎤 InterviewAce','href'=>home_url('/'),'meta'=>['target'=>'_blank']]);
    },999);

    IA_Settings_Page::init();
    IA_Cost_Dashboard::init();
    IA_Admin_Impersonate::init();
    IA_Diagnostics::init();

    /*
     * SAFETY NET: activation already sets a non-Plain permalink structure
     * (see class-activator.php::ensure_pretty_permalinks()) since the
     * entire app breaks silently under Plain permalinks — every /app/*
     * URL falls through to the homepage with no error anywhere. This
     * catches the case where an admin (or another plugin) later changes
     * it back to Plain, which would otherwise reintroduce that exact
     * silent failure with no clue as to why.
     */
    add_action('admin_notices', function() {
        if (!current_user_can('manage_options')) return;
        if (get_option('permalink_structure')) return; // non-Plain — fine
        $url = admin_url('options-permalink.php');
        echo '<div class="notice notice-error"><p><strong>InterviewAce:</strong> your site\'s Permalinks setting is "Plain", which stops the app from working at all — every /app/* page will silently show your homepage instead. <a href="'.esc_url($url).'">Go to Settings → Permalinks</a>, choose any option other than "Plain" (e.g. "Post name"), and click Save Changes.</p></div>';
    });

    /*
     * Surfaces IA_Activator::verify_and_repair_core_tables()'s finding in
     * plain sight, in wp-admin, if a required database table still
     * couldn't be created even after the direct-SQL repair attempt — see
     * that function's root-cause note. This is the scenario where the
     * problem is outside what this plugin's own code can fix (most
     * likely the database user this site connects with doesn't have
     * permission to create tables), so it's surfaced with the literal
     * database error text rather than continuing to fail invisibly.
     */
    add_action('admin_notices', function() {
        if (!current_user_can('manage_options')) return;
        $errors = get_option('ia_db_migration_error');
        if (!$errors) return;
        echo '<div class="notice notice-error"><p><strong>InterviewAce: a required database table could not be created.</strong> This usually means the database user this site connects with does not have permission to create tables — contact your hosting provider and share the exact error below.</p><ul style="margin-left:20px;list-style:disc;">';
        foreach ($errors as $table => $err) {
            echo '<li><code>' . esc_html($table) . '</code>: ' . esc_html($err) . '</li>';
        }
        echo '</ul><p><a href="' . esc_url(admin_url('admin.php?page=interviewace-diagnostics')) . '" class="button button-primary">🩺 Open Diagnostics — see exactly what\'s wrong and get ready-to-paste SQL</a></p></div>';
    });
});

/* ── Page detection: everything that isn't WP-core ── */
function ia_is_root_app(): bool {
    $uri = trim(parse_url($_SERVER['REQUEST_URI']??'', PHP_URL_PATH), '/');
    /*
     * ROBUSTNESS FIX: this skip-list used to be missing .well-known/,
     * sitemap.xml, robots.txt and favicon.ico — a deny-list approach means
     * any future WordPress-core or third-party-plugin route that doesn't
     * happen to start with one of these prefixes gets silently swallowed by
     * the SPA shell instead of served correctly (domain-verification files,
     * SEO plugin sitemaps, etc). Extended defensively; still a deny-list by
     * design (that's intentional — this app owns the root domain), but now
     * covers the well-known cases that would otherwise break silently.
     */
    foreach (['wp-admin','wp-json','wp-login','wp-content','wp-cron','xmlrpc','feed','.well-known','sitemap','robots.txt','favicon.ico'] as $skip) {
        if ($uri==='' ? false : strncmp($uri,$skip,strlen($skip))===0) return false;
    }
    /* Skip REST requests */
    if (defined('REST_REQUEST') && REST_REQUEST) return false;
    /* Skip public marketing pages — served by PHP templates */
    $public_pages = ['ia-home','ia-pricing','ia-about','ia-privacy','ia-terms'];
    global $post;
    if ($post && in_array($post->post_name, $public_pages)) return false;
    /* Accept root and all sub-paths */
    if ($post && $post->post_name==='interviewace-root') return true;
    if (get_query_var('pagename')==='interviewace-root') return true;
    /* Catch-all for unresolved routes (login, signup, etc.) */
    return true;
}
