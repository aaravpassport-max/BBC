<?php
/**
 * Plugin Name:       RTOFLOW OS
 * Plugin URI:        https://rtoflow.com
 * Description:       Enterprise RTO Service Operating Platform — manages leads, vendors, payments, GST invoicing, and client communication.
 * Version:           3.7.155-20260915.1845
 * Author:            RTOFLOW
 * Author URI:        https://rtoflow.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       rtoflow-os
 * Domain Path:       /languages
 * Requires at least: 6.4
 * Tested up to:      6.7
 * Requires PHP:      8.1
 */

if (!defined('ABSPATH')) exit;

// ── Version guard ──────────────────────────────────────────────────────────
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>RTOFLOW OS</strong> requires PHP 8.1 or higher. You are running PHP ' . esc_html(PHP_VERSION) . '.</p></div>';
    });
    return;
}

// ── Constants ──────────────────────────────────────────────────────────────
// CRITICAL FIX (found via your screenshots — root cause of "Build Form"
// showing a bare WordPress theme page instead of the editor): this version
// number is the ONLY thing that makes Bootstrap::init()'s self-healing hook
// (see app/Bootstrap.php ~line 87) actually re-run migrations and re-flush
// rewrite rules on an already-installed site. Parts 4.10-4.12 added a new
// database column (migration 12) AND changed the /rto-admin/forms/ rewrite
// rule from a numeric [id] pattern to a category-key [a-z]+ pattern — but
// this version constant was never bumped when those changes were made. That
// self-healing hook checks `if (get_option('rtoflow_db_version') ===
// RTOFLOW_VERSION) return;` — with the version unchanged, it returned
// immediately on every request, so the new rewrite rule was NEVER
// registered on your live site. WordPress had no rule matching
// /rto-admin/forms/dl/ at all, so it fell through to normal page routing —
// exactly the bare theme page in your screenshot. Bumping this version
// number is what makes the fix actually take effect: the very next admin
// page load after these files are uploaded will re-run migrations (adding
// the `category` column), re-flush rewrite rules (registering the new
// category-key URL pattern), and then auto-seed the 7 category schemas —
// all three of which were silently inert until this number changed.
// FIX (user request — auto-versioning): RTOFLOW_VERSION is not cosmetic —
// Bootstrap.php's `add_action('init', ...)` block at line ~87 compares the
// stored 'rtoflow_db_version' option against this exact string to decide
// whether to re-run migrations and re-flush rewrite rules on the next
// admin page load (see the comment there — this is the real mechanism that
// makes an uploaded fix actually take effect on a live site). The same
// constant is also appended as a `?v=` cache-buster on every enqueued CSS/
// JS file (see Bootstrap.php's wp_enqueue_style/script calls and every
// resources/views/layouts/*-header.php/*-footer.php file), so a stale
// browser cache serving last week's admin.css after a CSS fix is deployed
// is a second real, previously-recurring failure mode this same fix
// prevents. Bumping this value is therefore required, not optional, every
// time this zip is rebuilt for delivery — a build timestamp
// (YYYYMMDD.HHMM, UTC) makes every future zip's version automatically
// unique and strictly increasing with no risk of forgetting to bump a
// manual number by hand. Never version_compare()'d anywhere in this
// codebase (grepped: only ever used for string equality and as a cache-
// buster) — a build-metadata suffix like this is safe, it does not need to
// parse as strict semver.
define('RTOFLOW_VERSION',  '3.7.155-20260915.1845');
define('RTOFLOW_FILE',     __FILE__);
define('RTOFLOW_DIR',      plugin_dir_path(__FILE__));
define('RTOFLOW_URL',      plugin_dir_url(__FILE__));
define('RTOFLOW_BASENAME', plugin_basename(__FILE__));

// ── Autoloader ─────────────────────────────────────────────────────────────
$autoload = RTOFLOW_DIR . 'vendor/autoload.php';
if (!file_exists($autoload)) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>RTOFLOW OS:</strong> Composer dependencies are not installed. Run <code>composer install</code> in the plugin directory.</p></div>';
    });
    return;
}
require_once $autoload;

// ── Integrations (non-autoloaded procedural files) ─────────────────────────
require_once RTOFLOW_DIR . 'integrations/Razorpay.php';
require_once RTOFLOW_DIR . 'integrations/Sms.php';
require_once RTOFLOW_DIR . 'integrations/WhatsApp.php';
// integrations/EmailTemplates.php (RTOFLOW_Email_Templates) removed —
// dead code audit confirmed zero call sites anywhere in the codebase
// (grep -rl "RTOFLOW_Email_Templates" matched only its own class file).
// The real, active email path is NotificationService::send()/
// sendEmail() (app/Services/NotificationService.php), which already
// queries the same rto_notification_templates table directly via
// wp_mail() — this class was a redundant, never-wired second
// implementation of the same job, not a gap.
require_once RTOFLOW_DIR . 'integrations/VendorPayouts.php';

// ── Boot the plugin ────────────────────────────────────────────────────────
add_action('plugins_loaded', function () {
    \RTOFLOW\Bootstrap::getInstance()->init();
}, 5);

// ── Activation / Deactivation hooks ───────────────────────────────────────
register_activation_hook(__FILE__, [\RTOFLOW\Bootstrap::class, 'activate']);
register_deactivation_hook(__FILE__, [\RTOFLOW\Bootstrap::class, 'deactivate']);

// ── Admin menu ─────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    if (!current_user_can('rto_admin') && !current_user_can('administrator')) return;

    add_menu_page(
        'RTOFLOW OS',
        'RTOFLOW OS',
        'rto_staff',
        'rtoflow-admin',
        function () { echo '<div>Redirecting to RTO Admin panel...</div>'; },
        'dashicons-car',
        25
    );

    add_submenu_page('rtoflow-admin', 'Dashboard',    'Dashboard',    'rto_staff', 'rtoflow-admin',        function(){wp_redirect(home_url('/rto-admin/')); exit;});
    add_submenu_page('rtoflow-admin', 'Leads',        'All Leads',    'rto_staff', 'rtoflow-leads',        function(){wp_redirect(home_url('/rto-admin/leads/')); exit;});
    add_submenu_page('rtoflow-admin', 'Vendors',      'Vendors',      'rto_staff', 'rtoflow-vendors',      function(){wp_redirect(home_url('/rto-admin/vendors/')); exit;});
        add_submenu_page('rtoflow-admin', 'Services',     'Services',     'rto_admin', 'rtoflow-services',     function(){wp_redirect(home_url('/rto-admin/services/')); exit;});
    add_submenu_page('rtoflow-admin', 'Payments',     'Payments',     'rto_staff', 'rtoflow-payments',     function(){wp_redirect(home_url('/rto-admin/payments/')); exit;});
    add_submenu_page('rtoflow-admin', 'Payouts',      'Payouts',      'rto_staff', 'rtoflow-payouts',      function(){wp_redirect(home_url('/rto-admin/payouts/')); exit;});
    add_submenu_page('rtoflow-admin', 'Reports',      'Reports',      'rto_staff', 'rtoflow-reports',      function(){wp_redirect(home_url('/rto-admin/reports/')); exit;});
        add_submenu_page('rtoflow-admin', 'Masters',      'Cities/RTOs',  'rto_admin', 'rtoflow-masters',      function(){wp_redirect(home_url('/rto-admin/masters/')); exit;});
    add_submenu_page('rtoflow-admin', 'Ratings',      'Ratings',      'rto_staff', 'rtoflow-ratings',      function(){wp_redirect(home_url('/rto-admin/ratings/')); exit;});
    add_submenu_page('rtoflow-admin', 'AI Insights',  'AI Insights',  'rto_admin', 'rtoflow-ai',           function(){wp_redirect(home_url('/rto-admin/?rto_area=admin&rto_page=ai')); exit;});
    add_submenu_page('rtoflow-admin', 'Automation',   'Automation',   'rto_admin', 'rtoflow-automation',   function(){wp_redirect(home_url('/rto-admin/?rto_area=admin&rto_page=automation')); exit;});
    add_submenu_page('rtoflow-admin', 'Features',     'Feature Flags','rto_admin', 'rtoflow-features',     function(){wp_redirect(home_url('/rto-admin/?rto_area=admin&rto_page=features')); exit;});
    add_submenu_page('rtoflow-admin', 'Settings',     'Settings',     'rto_admin', 'rtoflow-settings',     function(){wp_redirect(home_url('/rto-admin/settings/')); exit;});
    add_submenu_page('rtoflow-admin', 'Setup & Status', 'Setup & Status', 'administrator', 'rtoflow-setup', '\RTOFLOW\Admin\SetupPage::render');
    add_submenu_page('rtoflow-admin', 'Complaints',    'Complaints',    'rto_admin', 'rtoflow-complaints', function(){wp_redirect(home_url('/rto-admin/complaints/')); exit;});
    add_submenu_page('rtoflow-admin', 'Email Templates','Email Templates','rto_admin', 'rtoflow-templates',  function(){wp_redirect(home_url('/rto-admin/email-templates/')); exit;});
    // Part 5.0: the central, searchable Help Centre — visible to every staff
    // role (rto_staff), not just rto_admin, since a support/ops user needs
    // this at least as much as an admin does. Placed last in the menu order
    // deliberately (a "reference shelf", not a daily workflow screen).
    add_submenu_page('rtoflow-admin', 'Help Centre',    'Help Centre',   'rto_staff', 'rtoflow-help',       function(){wp_redirect(home_url('/rto-admin/help/')); exit;});
});

// ── Setup page form handling ───────────────────────────────────────────────
add_action('admin_init', [\RTOFLOW\Admin\SetupPage::class, 'handleSetupActions']);

// ── Admin settings page ────────────────────────────────────────────────────
add_action('admin_init', function () {
    register_setting('rtoflow_settings', 'rtoflow_company_name',    ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('rtoflow_settings', 'rtoflow_company_gstin',   ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('rtoflow_settings', 'rtoflow_company_address', ['sanitize_callback' => 'sanitize_textarea_field']);
    register_setting('rtoflow_settings', 'rtoflow_company_state',   ['sanitize_callback' => 'sanitize_text_field']);
    register_setting('rtoflow_settings', 'rtoflow_admin_user_id',   ['sanitize_callback' => 'absint']);
    register_setting('rtoflow_settings', 'rtoflow_sla_days',        ['sanitize_callback' => 'absint', 'default' => 15]);
});

// ── Shortcodes ─────────────────────────────────────────────────────────────
add_shortcode('rtoflow_dashboard', function () {
    if (!is_user_logged_in()) {
        return '<p>Please <a href="' . esc_url(rto_login_url()) . '">log in</a> to access your dashboard.</p>';
    }
    // P9-WP-006 FIX: protect ob_start() from wp_die() abandoning the buffer
    $level = ob_get_level();
    ob_start();
    try {
        \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Controllers\Client\DashboardController::class)->index();
    } catch (\Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        error_log('RTOFLOW shortcode error: ' . $e->getMessage());
        return '<div class="rto-msg rto-msg-error">Dashboard temporarily unavailable. Please refresh the page.</div>';
    }
    return ob_get_clean() ?: '';
});

add_shortcode('rtoflow_apply', function () {
    // Pre-load services and states with 1-hour cache (same as Router::routeApply)
    global $wpdb;
    $services = get_transient('rtofl_active_services');
    if ($services === false) {
        $services = $wpdb->get_results(
            "SELECT id, name, category, base_price, gst_applicable, sla_days
             FROM {$wpdb->prefix}rto_services WHERE is_active=1 ORDER BY category, display_order",
            ARRAY_A
        ) ?: [];
        set_transient('rtofl_active_services', $services, 3600);
    }
    $states = get_transient('rtofl_active_states');
    if ($states === false) {
        $states = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}rto_states ORDER BY name",
            ARRAY_A
        ) ?: [];
        set_transient('rtofl_active_states', $states, 3600);
    }
    ob_start();
    rto_view('public.apply', compact('services', 'states'));
    return ob_get_clean() ?: '';
});

// ── Homepage — served standalone via Router::interceptFrontPage() ─────────
// The plugin intercepts WordPress's template_redirect at priority 1
// and serves the plugin's own standalone homepage (no theme required).
// The [rtoflow_home] shortcode below is kept as a fallback only.
add_shortcode('rtoflow_home', function () {
    global $wpdb;
    $services = get_transient('rtofl_active_services');
    if ($services === false) {
        $services = $wpdb->get_results(
            "SELECT id, category, name, slug, base_price, sla_days FROM {$wpdb->prefix}rto_services WHERE is_active=1 ORDER BY display_order, name",
            ARRAY_A
        ) ?: [];
        set_transient('rtofl_active_services', $services, 3600);
    }
    $company    = get_option('rtoflow_company_name', 'RTOASSIST');
    $phone      = get_option('rtoflow_company_phone', '');
    $lead_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads WHERE status='completed'");
    $city_count = max((int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE is_active=1"), 78);
    $city_list  = $wpdb->get_results("SELECT name, COALESCE(slug, LOWER(REPLACE(name,' ','-'))) as slug FROM {$wpdb->prefix}rto_cities WHERE is_active=1 ORDER BY name LIMIT 48", ARRAY_A) ?: [];
    $by_cat     = [];
    foreach ($services as $s) $by_cat[$s['category']][] = $s;
    ob_start();
    // Note: outputs inline HTML without full page layout (uses WP's own head/footer)
    extract(compact('company','phone','services','by_cat','lead_count','city_count','city_list'));
    require RTOFLOW_DIR . 'resources/views/public/home-inline.php';
    return ob_get_clean() ?: '';
});

// ── REST API endpoints ─────────────────────────────────────────────────────
add_action('rest_api_init', function () {
    // Health check
    register_rest_route('rtoflow/v1', '/health', [
        'methods'             => 'GET',
        'callback'            => function () {
            // ENTERPRISE GAP FIX (Phase 8, item — "duplicate health-check
            // implementation"): this used to inline its own "SELECT 1"
            // check, duplicating the /rto-health/ rewrite route's logic.
            // Both now delegate to HealthCheckService::status().
            $health = \RTOFLOW\Services\HealthCheckService::status();
            return new WP_REST_Response($health, $health['status'] === 'healthy' ? 200 : 503);
        },
        'permission_callback' => '__return_true',
    ]);

    // API-key authenticated endpoint: lead lookup
    register_rest_route('rtoflow/v1', '/leads/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $req) {
            if (!\RTOFLOW\Config\FeatureFlags::is_enabled('api_access')) {
                return new WP_Error('api_disabled', 'The REST API is currently disabled.', ['status' => 403]);
            }
            $auth = \RTOFLOW\Auth\ApiKeyAuth::authenticate(
                $req->get_header('authorization') ?? $req->get_header('x-api-key') ?? ''
            );
            if (!$auth) return new WP_Error('unauthorized', 'Invalid API key.', ['status' => 401]);

            $lead = \RTOFLOW\Bootstrap::container()
                ->make(\RTOFLOW\Services\LeadService::class)
                ->getLead((int)$req->get_param('id'));

            return $lead
                ? new WP_REST_Response($lead, 200)
                : new WP_Error('not_found', 'Lead not found.', ['status' => 404]);
        },
        'permission_callback' => '__return_true',
    ]);
});

// ── Login page lockout display ─────────────────────────────────────────────
add_filter('wp_login_errors', function (WP_Error $errors, string $redirect) {
    $username = sanitize_text_field($_POST['log'] ?? '');
    if (!$username) return $errors;

    $info = \RTOFLOW\Auth\AccountLockout::getLockoutInfo($username);
    if ($info) {
        $errors->add('locked', '<strong>Account Locked:</strong> ' . esc_html($info['message']));
    }
    return $errors;
}, 10, 2);

add_action('wp_authenticate', function (string $username) {
    if (!$username) return;
    $ip = \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
    if (\RTOFLOW\Auth\AccountLockout::isLocked($username, $ip)) {
        $info = \RTOFLOW\Auth\AccountLockout::getLockoutInfo($username);
        $msg  = $info ? $info['message'] : 'Account locked due to too many failed attempts.';
        wp_die(esc_html($msg), 'Account Locked', ['response' => 403, 'back_link' => true]);
    }
}, 10);


// ── Admin notices ──────────────────────────────────────────────────────────
add_action('admin_notices', function () {
    if (!current_user_can('administrator')) return;

    // ── Post-activation setup guide (shows once for 2 minutes after activation)
    if (get_transient('rtoflow_just_activated')) {
        delete_transient('rtoflow_just_activated');
        $adminUrl  = home_url('/rto-admin/');
        $dashUrl   = home_url('/rto-dashboard/');
        $applyUrl  = home_url('/rto-apply/');
        $vendorUrl = home_url('/rto-vendor/');
        $permUrl   = admin_url('options-permalink.php');
        $setupUrl  = admin_url('admin.php?page=rtoflow-settings');
        ?>
        <div class="notice notice-success" style="border-left-color:#1E3A5F;padding:16px 20px">
          <h3 style="margin:0 0 12px;color:#1E3A5F;font-size:16px">
            ✅ RTOFLOW OS Activated — Here's what to do next:
          </h3>
          <table style="border-collapse:collapse;width:100%;max-width:720px">
            <tr>
              <td style="padding:6px 0;width:220px;vertical-align:top">
                <strong>Step 1 — Flush Permalinks</strong>
              </td>
              <td style="padding:6px 0">
                Visit <a href="<?= esc_url($permUrl) ?>">Settings → Permalinks</a> and click
                <strong>Save Changes</strong> (no changes needed — just click Save).
                This activates all portal URLs.
              </td>
            </tr>
            <tr>
              <td style="padding:6px 0;vertical-align:top">
                <strong>Step 2 — Configure</strong>
              </td>
              <td style="padding:6px 0">
                Visit <a href="<?= esc_url($setupUrl) ?>">RTOFLOW OS → Settings</a>
                to set your company name, GSTIN, and admin email.
                Also create a <code>.env</code> file in the plugin directory
                (copy from <code>.env.example</code>).
              </td>
            </tr>
            <tr>
              <td style="padding:6px 0;vertical-align:top">
                <strong>Step 3 — Access Portals</strong>
              </td>
              <td style="padding:6px 0">
                <ul style="margin:0;padding-left:18px">
                  <li>🏢 <strong>Admin Portal:</strong> <a href="<?= esc_url($adminUrl) ?>" target="_blank"><?= esc_html($adminUrl) ?></a> (for admin/staff)</li>
                  <li>👷 <strong>Vendor Portal:</strong> <a href="<?= esc_url($vendorUrl) ?>" target="_blank"><?= esc_html($vendorUrl) ?></a> (for vendors)</li>
                  <li>👤 <strong>Client Dashboard:</strong> <a href="<?= esc_url($dashUrl) ?>" target="_blank"><?= esc_html($dashUrl) ?></a> (for clients)</li>
                  <li>📋 <strong>Apply Form:</strong> <a href="<?= esc_url($applyUrl) ?>" target="_blank"><?= esc_html($applyUrl) ?></a> (public-facing)</li>
                </ul>
              </td>
            </tr>
          </table>
          <p style="margin:12px 0 0;color:#666;font-size:13px">
            💡 <strong>Tip:</strong> The Apply Form and Client Dashboard pages were automatically
            created in your Pages list. The Admin, Vendor, and Client portals render via custom
            URLs — <strong>you must flush permalinks first</strong> for them to work.
          </p>
        </div>
        <?php
    }

    // ── WP-Cron reliability notice (P7-PERF-006)
    if (!get_transient('rtofl_cron_notice_dismissed') && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)) {
        echo '<div class="notice notice-warning is-dismissible"><p>'
            . '<strong>RTOFLOW OS:</strong> WordPress pseudo-cron is active. '
            . 'For reliable SLA alerts and notifications add a real cron: '
            . '<code>*/5 * * * * curl -s ' . esc_url(home_url('/wp-cron.php?doing_wp_cron')) . ' &gt; /dev/null 2&gt;&amp;1</code>.</p></div>';
    }

    // ── Informational tip: .env key is more secure than auto-generated wp_options key
    if (defined('RTOFLOW_DIR')
        && class_exists('RTOFLOW\\Security\\Encryption', false)
        && !\RTOFLOW\Security\Encryption::isUsingEnvKey()
        && !get_transient('rtofl_enc_notice_dismissed')) {
        echo '<div class="notice notice-info is-dismissible"><p>'
            . '<strong>RTOFLOW OS:</strong> Encryption is active using an auto-generated key stored in your database. '
            . 'For production, optionally move this key to a <code>.env</code> file for added security '
            . '(<a href="' . esc_url(admin_url('admin.php?page=rtoflow-setup')) . '">see Setup page</a>). '
            . 'The plugin works fine without this step.</p></div>';
    }
});

// ── Remove WP version from public pages ────────────────────────────────────
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

// ── Public quick enquiry (service landing page callback form) ──────────────
// FIX P0-2/P0-3: this used to register its OWN wp_ajax_rto_public /
// wp_ajax_nopriv_rto_public callback, in a straight race against
// Router::dispatchPublicAjax() (registered for the exact same hook in
// Router::init()). WordPress fires all callbacks bound to a hook, but the
// first one to call wp_send_json_error()/wp_send_json_success() terminates
// the request via wp_die(), so only one implementation ever actually ran —
// and it depended on hook-registration order, not on which one was "correct".
// quick_enquiry is now handled inside Router::dispatchPublicAjax() (see
// Router::quickEnquiry()), the single dispatch table for all public AJAX
// actions, exactly like every other rto_area is single-dispatch already.

// ── AJAX Login handler ─────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_rtoflow_ajax_login', 'rtoflow_ajax_login_handler');
add_action('wp_ajax_rtoflow_ajax_login',        'rtoflow_ajax_login_handler');
function rtoflow_ajax_login_handler(): void {
    if (!check_ajax_referer('rtoflow_login', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $username = sanitize_text_field($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$username || !$password) { wp_send_json_error('Username and password required.'); }
    $user = wp_authenticate($username, $password);
    if (is_wp_error($user)) { wp_send_json_error($user->get_error_message()); }
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    $role = rto_user_role($user->ID);
    $redirect = match($role) {
        'admin','staff' => home_url('/rto-admin/'),
        'vendor'        => home_url('/rto-vendor/'),
        default         => home_url('/rto-dashboard/'),
    };
    wp_send_json_success(['redirect' => $redirect]);
}

// ── AJAX 2FA session verification (post-login challenge) ───────────────────
// BUG FIX (ERR_TOO_MANY_REDIRECTS): rtoflow_ajax_login_handler() above only
// ever did wp_authenticate()+wp_set_auth_cookie() — it never checked or
// satisfied \RTOFLOW\Auth\TwoFactor at all. Meanwhile Bootstrap.php's
// template_redirect hook (P10-EDGE-002) has always required
// TwoFactor::isVerifiedForSession() to be true for any account with 2FA
// enabled. Since nothing anywhere ever called TwoFactor::setVerified()
// during login, that condition could never be satisfied for a 2FA-enabled
// account, and every page load redirected back to the login page forever.
// This handler is the missing piece: called from the 2FA challenge screen
// that Router::routeWebsite()'s 'rto-login' case now renders for an
// authenticated-but-unverified session, it checks the submitted TOTP/backup
// code against the user's OWN already-authenticated account (never against
// an arbitrary user id from the request) and, on success, calls
// TwoFactor::setVerified() so isVerifiedForSession() finally returns true.
add_action('wp_ajax_rtoflow_ajax_2fa_session_verify', 'rtoflow_ajax_2fa_session_verify_handler');
function rtoflow_ajax_2fa_session_verify_handler(): void {
    if (!is_user_logged_in()) { wp_send_json_error('You must be logged in.'); }
    if (!check_ajax_referer('rtoflow_login', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $userId = get_current_user_id();
    if (!\RTOFLOW\Auth\TwoFactor::isEnabled($userId)) {
        // Nothing to verify — treat as already satisfied so the caller
        // isn't stuck on a challenge screen for an account that doesn't
        // actually have 2FA on.
        wp_send_json_success(['redirect' => home_url(rtoflow_portal_url_for_user())]);
    }
    $code = sanitize_text_field($_POST['code'] ?? '');
    if (!$code) { wp_send_json_error('Enter the 6-digit code from your authenticator app, or a backup code.'); }

    if (!\RTOFLOW\Auth\TwoFactor::verify($userId, $code) && !\RTOFLOW\Auth\TwoFactor::verifyBackup($userId, $code)) {
        wp_send_json_error('That code is incorrect or expired. Please try again.');
    }

    \RTOFLOW\Auth\TwoFactor::setVerified($userId);

    $redirect = \RTOFLOW\Security\Sanitiser::url($_POST['redirect'] ?? '');
    wp_send_json_success(['redirect' => $redirect ?: home_url(rtoflow_portal_url_for_user())]);
}

// ── Email OTP login (user request: "login with email otp maximum, never ──
// ── see the WordPress login screen, custom login screen with email otp ────
// ── or magic login") ────────────────────────────────────────────────────
// Two-step, password-free alternative to rtoflow_ajax_login_handler() above,
// offered on the same /rto-login/ screen. Deliberately does not reveal
// whether an email address has an account (rto_json_ok-shaped success either
// way) to avoid turning the login form into an account-existence oracle —
// the only observable difference for a non-existent email is that no mail
// arrives.
add_action('wp_ajax_nopriv_rtoflow_ajax_request_email_otp', 'rtoflow_ajax_request_email_otp_handler');
add_action('wp_ajax_rtoflow_ajax_request_email_otp',        'rtoflow_ajax_request_email_otp_handler');
function rtoflow_ajax_request_email_otp_handler(): void {
    if (!check_ajax_referer('rtoflow_login', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $email = sanitize_email($_POST['email'] ?? '');
    if (!$email || !is_email($email)) { wp_send_json_error('Enter a valid email address.'); }

    // Throttle: at most one OTP request per email per 60s, so the login
    // form can't be used to mail-bomb an inbox.
    $throttleKey = 'rtoflow_login_otp_throttle_' . md5(strtolower($email));
    if (get_transient($throttleKey)) {
        wp_send_json_success(['message' => 'A code was already sent — check your inbox, or wait a moment before requesting another.']);
    }
    set_transient($throttleKey, 1, 60);

    $user = get_user_by('email', $email);
    // User request: "frontend login options will be shown only for vendor
    // and client not for admin" — passwordless login is not offered to
    // admin accounts at all. Checked here (not just hidden in the UI) so a
    // direct POST to this action can't be used to route around the
    // Password-only restriction the login page enforces visually. Same
    // generic response either way — see docblock above on why this
    // function never reveals account existence or role.
    if ($user && !rto_is_admin($user->ID)) {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        set_transient('rtoflow_login_otp_' . $user->ID, $otp, 10 * MINUTE_IN_SECONDS);
        $company = get_option('rtoflow_company_name', 'RTOASSIST');
        wp_mail(
            $email,
            "[{$company}] Your login code: {$otp}",
            "Your one-time login code for {$company} is: {$otp}\n\nThis code expires in 10 minutes. If you did not request this, you can safely ignore this email."
        );
    }
    // Same response whether or not $user exists, and whether or not they're
    // an admin — see docblock above.
    wp_send_json_success(['message' => 'If that email has an account, a login code has been sent.']);
}

add_action('wp_ajax_nopriv_rtoflow_ajax_verify_email_otp', 'rtoflow_ajax_verify_email_otp_handler');
add_action('wp_ajax_rtoflow_ajax_verify_email_otp',        'rtoflow_ajax_verify_email_otp_handler');
function rtoflow_ajax_verify_email_otp_handler(): void {
    if (!check_ajax_referer('rtoflow_login', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $email = sanitize_email($_POST['email'] ?? '');
    $code  = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    if (!$email || !is_email($email)) { wp_send_json_error('Enter a valid email address.'); }
    if (!$code) { wp_send_json_error('Enter the 6-digit code we emailed you.'); }

    $user = get_user_by('email', $email);
    if (!$user) { wp_send_json_error('Incorrect or expired code. Please try again.'); }
    // Defense in depth: an OTP is never issued for an admin account (see
    // rtoflow_ajax_request_email_otp_handler() above), so no valid
    // transient should exist here either — this check just makes that
    // guarantee explicit rather than relying solely on one never having
    // been created.
    if (rto_is_admin($user->ID)) { wp_send_json_error('Email OTP sign-in is not available for admin accounts. Please use your password.'); }

    $key    = 'rtoflow_login_otp_' . $user->ID;
    $stored = get_transient($key);
    if (!$stored || !hash_equals((string)$stored, $code)) {
        wp_send_json_error('Incorrect or expired code. Please try again.');
    }
    delete_transient($key); // one-time use

    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);
    do_action('wp_login', $user->user_login, $user);

    $redirect = \RTOFLOW\Security\Sanitiser::url($_POST['redirect'] ?? '');
    wp_send_json_success(['redirect' => $redirect ?: home_url(rtoflow_portal_url_for_user())]);
}

// ── Magic-link login (same user request as Email OTP above) ────────────────
// Emails a one-time, 15-minute sign-in link instead of a code — the actual
// login is completed by Router's 'rto-login' case reading ?magic=TOKEN
// (see app/Http/Router.php). Same no-enumeration response shape as the OTP
// handler above, and the same per-email throttle.
add_action('wp_ajax_nopriv_rtoflow_ajax_request_magic_link', 'rtoflow_ajax_request_magic_link_handler');
add_action('wp_ajax_rtoflow_ajax_request_magic_link',        'rtoflow_ajax_request_magic_link_handler');
function rtoflow_ajax_request_magic_link_handler(): void {
    if (!check_ajax_referer('rtoflow_login', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $email = sanitize_email($_POST['email'] ?? '');
    if (!$email || !is_email($email)) { wp_send_json_error('Enter a valid email address.'); }

    $throttleKey = 'rtoflow_magic_link_throttle_' . md5(strtolower($email));
    if (get_transient($throttleKey)) {
        wp_send_json_success(['message' => 'A link was already sent — check your inbox, or wait a moment before requesting another.']);
    }
    set_transient($throttleKey, 1, 60);

    $user = get_user_by('email', $email);
    // Same admin exclusion as Email OTP above — passwordless sign-in is
    // client/vendor only.
    if ($user && !rto_is_admin($user->ID)) {
        $token = bin2hex(random_bytes(32));
        set_transient('rtoflow_magic_link_' . $token, $user->ID, 15 * MINUTE_IN_SECONDS);
        $redirectParam = isset($_POST['redirect']) ? \RTOFLOW\Security\Sanitiser::url($_POST['redirect']) : '';
        $link = add_query_arg(
            array_filter(['magic' => $token, 'redirect' => $redirectParam ?: null]),
            home_url('/rto-login/')
        );
        $company = get_option('rtoflow_company_name', 'RTOASSIST');
        wp_mail(
            $email,
            "[{$company}] Your login link",
            "Click the link below to log in to {$company}:\n\n{$link}\n\nThis link expires in 15 minutes and can only be used once. If you did not request this, you can safely ignore this email."
        );
    }
    wp_send_json_success(['message' => 'If that email has an account, a login link has been sent.']);
}

// ── Contact form AJAX ───────────────────────────────────────────────────────
add_action('wp_ajax_nopriv_rtoflow_contact', 'rtoflow_contact_ajax');
add_action('wp_ajax_rtoflow_contact',        'rtoflow_contact_ajax');
// ENTERPRISE GAP FIX: the comment this replaced claimed "BUG #16 FIX: contact
// form had no CSRF nonce check" as if it had already been fixed, but no
// check_ajax_referer() call was ever actually added below it — the docblock
// was describing a fix that did not exist. A real nonce check is added here
// now (contact.php's JS was updated in the same pass to create and send it).
// This is a low-severity CSRF surface (a public, unauthenticated, rate-limited
// endpoint that only sends an email — the "attack" a missing nonce enables is
// forcing a logged-in admin's browser to submit one contact-form email, not
// account takeover or data exposure) but the mismatch between the comment's
// claim and the code's actual behaviour is exactly the kind of "plausible but
// unrun" gap this audit exists to catch.
function rtoflow_contact_ajax(): void {
    if (!check_ajax_referer('rtoflow_contact', 'nonce', false)) {
        wp_send_json(['success'=>false,'message'=>'Security check failed. Please refresh the page and try again.']);
    }
    // Rate limit: 3 contact form submissions per 5 minutes per IP
    if (!\RTOFLOW\Http\Middleware\RateLimiter::check('submit', \RTOFLOW\Http\Middleware\RateLimiter::clientIp())) {
        wp_send_json(['success'=>false,'message'=>'Too many requests. Please wait before submitting again.']);
    }
    $name    = sanitize_text_field($_POST['name']    ?? '');
    $email   = sanitize_email($_POST['email']        ?? '');
    $phone   = sanitize_text_field($_POST['phone']   ?? '');
    $message = sanitize_textarea_field($_POST['message'] ?? '');
    if (!$name || !$email || !$message) { wp_send_json(['success'=>false,'message'=>'Please fill all required fields']); }
    if (!is_email($email)) { wp_send_json(['success'=>false,'message'=>'Please enter a valid email address.']); }
    $to      = get_option('rtoflow_support_email', get_option('admin_email'));
    $company = get_option('rtoflow_company_name', 'RTOASSIST');
    $body    = "Name: {$name}\nEmail: {$email}\nPhone: {$phone}\n\nMessage:\n{$message}";
    wp_mail($to, "[{$company}] Contact Form: {$name}", $body);
    wp_send_json(['success'=>true,'message'=>'Message sent! We\'ll respond within 2 hours.']);
}

// ── Features admin form save ────────────────────────────────────────────────
add_action('admin_post_rtoflow_save_features', function() {
    if (!current_user_can('administrator')) wp_die('Forbidden');
    check_admin_referer('rtoflow_features','_rto_nonce');
    \RTOFLOW\Config\FeatureFlags::bulk_save((array)($_POST['flags'] ?? []));
    wp_safe_redirect(add_query_arg(['rto_area'=>'admin','rto_page'=>'settings','tab'=>'features','saved'=>1], home_url('/')));
    exit;
});

// ── AJAX Registration handler ──────────────────────────────────────────────
// ── Registration mobile OTP verification ────────────────────────────────────
// ENTERPRISE GAP FIX: RTOFLOW_SMS::sendOtp()/verifyOtp() were fully built —
// rate-limited, hashed-at-rest, 10-minute expiry — but grep confirmed ZERO
// call sites anywhere in the codebase. Registration collected a mobile
// number and stored it as user meta, used from then on for SMS/WhatsApp
// notifications, without ever confirming the customer actually typed their
// own number — a wrong or fake number silently breaks every future
// notification with no error anywhere, and there was no way to catch it at
// the one point it's cheap to catch (registration itself).
// Made backward-compatible on purpose: OTP is only REQUIRED when an SMS
// provider is actually configured (RTOFLOW_SMS::isEnabled()) — an install
// with no SMS credentials keeps registering exactly as before rather than
// being permanently blocked by a verification step it has no way to
// complete.
add_action('wp_ajax_nopriv_rtoflow_send_registration_otp', 'rtoflow_send_registration_otp_handler');
add_action('wp_ajax_rtoflow_send_registration_otp',        'rtoflow_send_registration_otp_handler');
function rtoflow_send_registration_otp_handler(): void {
    if (!check_ajax_referer('rtoflow_register_otp', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        wp_send_json_error('Enter a valid 10-digit Indian mobile number.');
    }
    if (!\RTOFLOW_SMS::isEnabled()) {
        // No SMS provider configured on this install — tell the client to
        // skip straight to account creation rather than pretending an OTP
        // was sent when nothing will ever arrive.
        wp_send_json_success(['otp_required' => false]);
    }
    $otp = \RTOFLOW_SMS::sendOtp($mobile);
    if ($otp === null) {
        wp_send_json_error('Could not send the verification code. Please check the number and try again, or wait a few minutes if you just requested one.');
    }
    wp_send_json_success(['otp_required' => true, 'message' => 'Code sent to your mobile number.']);
}

add_action('wp_ajax_nopriv_rtoflow_ajax_register', 'rtoflow_ajax_register_handler');
add_action('wp_ajax_rtoflow_ajax_register',        'rtoflow_ajax_register_handler');
function rtoflow_ajax_register_handler(): void {
    if (!check_ajax_referer('rtoflow_register', 'nonce', false)) {
        wp_send_json_error('Security check failed.');
    }
    $name   = sanitize_text_field($_POST['name']   ?? '');
    $email  = sanitize_email($_POST['email']       ?? '');
    $mobile = preg_replace('/\D/', '', $_POST['mobile'] ?? '');
    $pass   = $_POST['password'] ?? '';
    $otp    = preg_replace('/\D/', '', $_POST['otp'] ?? '');
    if (!$name || !$email || !$mobile || strlen($pass) < 8) {
        wp_send_json_error('All fields required. Password min 8 chars.');
    }
    if (!preg_match('/^[6-9]\d{9}$/', $mobile)) {
        wp_send_json_error('Enter a valid 10-digit Indian mobile number.');
    }
    if (email_exists($email)) {
        wp_send_json_error('This email is already registered. Please login instead.');
    }
    // Only enforced when SMS is actually configured — see the docblock on
    // rtoflow_send_registration_otp_handler() above for why.
    if (\RTOFLOW_SMS::isEnabled() && !\RTOFLOW_SMS::verifyOtp($mobile, $otp)) {
        wp_send_json_error('Invalid or expired verification code. Please request a new one.');
    }
    $uid = wp_create_user($email, $pass, $email);
    if (is_wp_error($uid)) { wp_send_json_error($uid->get_error_message()); }
    wp_update_user(['ID' => $uid, 'display_name' => $name, 'role' => 'rto_client']);
    update_user_meta($uid, 'rtoflow_mobile', $mobile);
    // Auto login
    wp_set_current_user($uid);
    wp_set_auth_cookie($uid, true);
    wp_send_json_success(['redirect' => home_url('/rto-dashboard/')]);
}

// ── Redirect wp-login.php to custom login — but keep WP admin usable ──────
// HARDENED (user request: "they should never see wordpress login screen for
// any purpose"): this used to hook only login_form_login, which fires for
// the DEFAULT action (a plain GET to wp-login.php, or ?action=login) — every
// other wp-login.php action (lostpassword, register, logout, rp/resetpass,
// etc.) fell straight through to WordPress's own screen, so a user could
// still land on it via "Forgot password?", a bookmarked reset link, or
// simply guessing the URL. login_init fires for EVERY wp-login.php request
// regardless of action, so this now actually covers "for any purpose" as
// asked. The wp-admin passthrough is kept as a deliberate, narrow safety
// valve — real WordPress "administrator" accounts (distinct from this
// plugin's rto_admin role) still need working access to /wp-admin/ itself
// for core WP maintenance, and blocking that has no upside for this
// plugin's users while risking a second lockout incident.
add_action('login_init', function() {
    $action = $_REQUEST['action'] ?? 'login';
    // Password reset / registration confirmation emails link straight to
    // wp-login.php?action=rp|resetpass with a single-use key in the URL —
    // redirecting those would break the reset flow itself, not route around
    // it, so they're excluded. (RTOFLOW doesn't currently send password
    // reset emails at all — wp_lostpassword_url() elsewhere in the codebase
    // points at core's own flow — so this exclusion is a safety net, not an
    // active path today.)
    if (in_array($action, ['rp', 'resetpass', 'postpass'], true)) return;

    $redirect_to = $_GET['redirect_to'] ?? $_POST['redirect_to'] ?? '';
    // If heading to wp-admin, let it pass (real WP administrators can still
    // use wp-login for core /wp-admin/ access).
    if ($redirect_to && str_contains($redirect_to, '/wp-admin')) return;
    // If coming from wp-admin cookie check, let it pass
    if (!empty($_COOKIE['wordpress_logged_in_' . COOKIEHASH])) return;
    // Otherwise redirect to our custom login page
    $redirect = sanitize_url($redirect_to);
    wp_redirect(home_url('/rto-login/' . ($redirect ? '?redirect=' . urlencode($redirect) : '')));
    exit;
});

// ── After activation: redirect to Setup & Status page ──────────────────────
register_activation_hook(__FILE__, function() {
    set_transient('rtoflow_activated', 1, 30);
});
add_action('admin_init', function() {
    if (get_transient('rtoflow_activated')) {
        delete_transient('rtoflow_activated');
        wp_redirect(admin_url('admin.php?page=rtoflow-setup'));
        exit;
    }
});

// ── Admin notice: show plugin URL panel until dismissed ────────────────────
add_action('admin_notices', function() {
    if (!current_user_can('administrator')) return;
    if (isset($_GET['page']) && str_starts_with($_GET['page'], 'rtoflow')) return;
    if (get_option('rtoflow_notice_dismissed')) return;
    $admin_url  = home_url('/rto-admin/');
    $nav_url    = home_url('/rto-admin/nav/');
    $vendor_url = home_url('/rto-vendor/');
    $client_url = home_url('/rto-dashboard/');
    $apply_url  = home_url('/rto-apply/');
    $login_url  = home_url('/rto-login/');
    ?>
    <div class="notice notice-info" style="padding:14px 16px;border-left-color:#1B2A6B">
      <p style="margin:0 0 8px"><strong>🔑 RTOFLOW OS is active!</strong> Here are your key URLs:</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:10px">
        <?php foreach([
          ['Admin Panel',    $admin_url],
          ['All Pages Menu', $nav_url],
          ['Vendor Portal',  $vendor_url],
          ['Client Portal',  $client_url],
          ['Apply Form',     $apply_url],
          ['Login Page',     $login_url],
        ] as [$label,$url]): ?>
        <a href="<?= esc_url($url) ?>" target="_blank" style="background:#EFF6FF;color:#1D4ED8;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:700;text-decoration:none;border:1px solid #BFDBFE"><?= esc_html($label) ?> →</a>
        <?php endforeach; ?>
      </div>
      <p style="margin:0;font-size:12px;color:#555">
        ℹ️ <a href="<?= esc_url(admin_url('admin.php?page=rtoflow-setup')) ?>">View full setup checklist</a>
        &nbsp;·&nbsp;
        <a href="<?= esc_url(add_query_arg(['rtoflow_dismiss' => 1, '_nonce' => wp_create_nonce('rtoflow_dismiss')])) ?>" style="color:#888">Dismiss</a>
      </p>
    </div>
    <?php
});

// Handle dismiss
add_action('admin_init', function() {
    if (!empty($_GET['rtoflow_dismiss']) && wp_verify_nonce($_GET['_nonce'] ?? '', 'rtoflow_dismiss')) {
        update_option('rtoflow_notice_dismissed', 1);
        wp_redirect(remove_query_arg(['rtoflow_dismiss','_nonce']));
        exit;
    }
});
