<?php

namespace RTOFLOW;

use RTOFLOW\Config\Env;
use RTOFLOW\Database\MigrationRunner;
use RTOFLOW\Security\Headers;
use RTOFLOW\Http\Router;
use RTOFLOW\Services\GstService;
use RTOFLOW\Services\TdsService;
use RTOFLOW\Services\InvoiceService;
use RTOFLOW\Services\LeadService;
use RTOFLOW\Services\PaymentService;
use RTOFLOW\Services\PayoutService;
use RTOFLOW\Services\NotificationService;
use RTOFLOW\Services\AuditService;
use RTOFLOW\Controllers\Admin\DashboardController;
use RTOFLOW\Controllers\Admin\LeadsController;
use RTOFLOW\Controllers\Vendor\DashboardController as VendorDashboard;
use RTOFLOW\Controllers\Client\DashboardController as ClientDashboard;

if (!defined('ABSPATH')) exit;

/**
 * Plugin Bootstrap
 *
 * Initialises all components, registers hooks, and provides
 * a minimal service container for dependency injection.
 */
class Bootstrap
{
    private static ?Bootstrap $instance   = null;
    private static ?Container $container  = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function container(): Container
    {
        return self::$container ??= self::buildContainer();
    }

    public function init(): void
    {
        // Load environment
        Env::load();

        // ENTERPRISE GAP FIX (Phase 7, item 8 — "zero WordPress __()/_e()
        // translation calls anywhere in resources/views/public ... no path
        // to Hindi/regional-language support on an India-wide platform"):
        // wrapping strings in __()/_e() is inert without this call — WP
        // never loads a .mo translation file for this text domain otherwise,
        // so even a fully-wrapped template would still render English-only.
        // This is the piece that makes every __() call below (and any this
        // codebase adds later) actually translatable; drop .mo files at
        // languages/rtoflow-os-{locale}.mo (e.g. rtoflow-os-hi_IN.mo) to add
        // Hindi/regional support with zero further code changes.
        add_action('plugins_loaded', function() {
            load_plugin_textdomain('rtoflow-os', false, dirname(plugin_basename(RTOFLOW_DIR . 'rtoflow-os.php')) . '/languages');
        });

        // ENTERPRISE GAP FIX (Phase 2, item 6 — structured exception
        // monitoring): registered as early as possible in the request
        // lifecycle so it catches failures from as much of the plugin's own
        // bootstrap as possible, not just failures inside Router::dispatch().
        // See ExceptionMonitor's class docblock for what each handler covers
        // and why de-dup exists.
        \RTOFLOW\Services\ExceptionMonitor::register();

        // ENTERPRISE GAP FIX (Phase 8, item — "WP-Cron is the only job
        // runner, with no dead-man's-switch"): see CronMonitor's class
        // docblock. Hooks a heartbeat onto every scheduled RTOFLOW cron
        // action and surfaces overdue jobs via admin notice + both
        // health-check endpoints.
        \RTOFLOW\Services\CronMonitor::register();

        // ENTERPRISE GAP FIX (Phase 2, item 4 — partner REST API): see
        // PartnerApiController's class docblock.
        \RTOFLOW\Http\PartnerApiController::register();

        // Auto-seed if tables are empty (handles plugin updates that added seed data)
        //
        // ENTERPRISE GAP FIX (Phase 15, item — "services silently missing
        // from under apply-form categories on sites set up on an older
        // plugin version"): this guard used to be `$svcCount === 0`, which
        // only ever backfilled a site whose rto_services table was
        // completely EMPTY. A site first activated on an earlier release of
        // this plugin — before the catalog grew to today's 44 services
        // across 7 categories (see IndiaDataSeeder::seedServices()) — already
        // had a non-zero row count, so this hook silently never ran again
        // for it: every service added to the seeder in a later update never
        // reached that site's live database. The apply-form's category
        // picker and sub-service dropdowns then correctly filtered against
        // $serviceNameToId (built from real DB rows only, not the seeder's
        // intended catalog — see Router::routeApply()) and correctly hid
        // whatever was actually missing from the DB — the missing-services
        // symptom was real, just caused one layer up from where it first
        // looked. IndiaDataSeeder::seedServices() already uses
        // `INSERT IGNORE` keyed on the unique `name` column (confirmed in
        // its source), so re-running it is a pure backfill: it can only add
        // rows that don't exist yet and can NEVER touch, reactivate, or
        // overwrite an admin's own is_active/price/etc changes on an
        // existing row. Given that safety, the guard now also re-runs
        // whenever the live row count is below the seeder's own known
        // catalog size, so a site upgrading through plugin versions
        // automatically catches up to newly-added services — exactly the
        // same "safe to run multiple times" pattern this codebase already
        // documents at its own activation-flow call site below.
        add_action('init', function() {
            global $wpdb;
            $svcCount  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_services");
            $cityCount = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities");
            // 44 = IndiaDataSeeder::seedServices()'s own current catalog size
            // (7 categories: 10 DL + 7 RC + 5 HP + 4 NOC + 16 Vehicle +
            // 1 Commercial + 1 Other). Kept as a literal here (not computed)
            // so a future catalog change is a one-line, deliberate bump —
            // matching how this constant is only ever grown, never shrunk,
            // by design in that seeder.
            if ($svcCount < 44 || $cityCount === 0) {
                require_once RTOFLOW_DIR . 'database/seeds/IndiaDataSeeder.php';
                (new \IndiaDataSeeder())->run();
                require_once RTOFLOW_DIR . 'database/seeds/NotificationTemplateSeeder.php';
                (new \RTOFLOW_NotificationTemplateSeeder())->run();
                delete_transient('rtofl_active_services');
            }
            if ($cityCount < 50) {
                // Ensure CitySeeder runs to populate slugs & 300+ cities
                require_once RTOFLOW_DIR . 'database/seeds/CitySeeder.php';
                (new \RTOFLOW_CitySeeder())->run();
                delete_transient('rtofl_active_states');
            }

            // ONE-TIME REPAIR (root cause of the reported "12 duplicate
            // categories on /rto-service/all/" bug): on any site where the
            // legacy database/seeds/Seeder.php::services() catalog already
            // got inserted (see the comment in that file for how/when — it
            // happened whenever it ran before IndiaDataSeeder's slug bug was
            // fixed and the live rto_services count was still under 10),
            // those 30 rows sit alongside the current 44-service catalog
            // under different, non-canonical category labels and show up as
            // confusing duplicates everywhere the catalog is listed. This
            // deactivates exactly those known legacy rows (matched by the
            // literal slugs that seeder always used — never touches an
            // admin's own services or price/is_active edits on anything
            // else) so they stop appearing to customers, without deleting
            // the rows outright in case any past lead still references one
            // by service_id. Runs once, guarded by an option flag.
            if (!get_option('rtoflow_legacy_services_deactivated')) {
                $legacySlugs = [
                    'ownership_transfer','ownership_transfer_used','ownership_family','rc_renewal',
                    'duplicate_rc','rc_address_change','rc_name_change','change_state',
                    'noc_vehicle','noc_two_wheeler','export_noc',
                    'dl_fresh','dl_renewal','dl_duplicate','idp','dl_address_change',
                    'hypothecation_add','hypothecation_remove',
                    'fitness_certificate','cng_conversion','temp_reg','fancy_number','import_reg','scrapping',
                    'trade_certificate','commercial_permit','route_permit',
                    'consultancy','legal_clearance','accident_report',
                ];
                $placeholders = implode(',', array_fill(0, count($legacySlugs), '%s'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}rto_services SET is_active=0 WHERE slug IN ({$placeholders})",
                    ...$legacySlugs
                ));
                update_option('rtoflow_legacy_services_deactivated', 1);
                delete_transient('rtofl_active_services');
            }
        }, 5);

        // FIX (re-verification finding — critical deployment gap): both
        // MigrationRunner::run() and Router::addRewriteRules() +
        // flush_rewrite_rules() were previously called ONLY from
        // Bootstrap::activate() (the plugin-activation hook). Every
        // migration and every admin route added across this whole
        // engagement — rto_workflow_definitions/_states/_transitions,
        // rto_config_versions, rto_vendor_coverage, the new columns on
        // rto_form_schemas, and the /rto-admin/workflows/, /rto-admin/
        // customizer/, /rto-admin/forms/, /rto-documents/{id}/ etc. routes
        // — genuinely does not exist on a live site until the plugin is
        // deactivated and reactivated (or Settings → Permalinks is saved,
        // which also flushes). Simply uploading updated plugin files, as
        // happens on every normal deploy, does NOT run this. This block
        // makes updates self-healing: on the next admin page load after a
        // version bump, it re-runs migrations and re-flushes rewrite rules
        // automatically, without requiring a manual deactivate/reactivate.
        // Only records the new version once migrations succeed with zero
        // errors, so a failed migration is retried on the next request
        // instead of being silently treated as done.
        add_action('init', function () {
            if (get_option('rtoflow_db_version') === RTOFLOW_VERSION) return;
            $results = MigrationRunner::run();
            $hasError = false;
            foreach ($results as $r) {
                if (($r['status'] ?? '') === 'error') {
                    $hasError = true;
                    error_log('RTOFLOW auto-migrate on version bump failed: ' . ($r['message'] ?? 'unknown error'));
                }
            }
            Router::addRewriteRules();
            flush_rewrite_rules(false);
            if (!$hasError) {
                update_option('rtoflow_db_version', RTOFLOW_VERSION, false);
            }

            // FIX (design-settings redesign shipped invisible on live sites):
            // /rto-design.css (Router::designCss()) is served with
            // Cache-Control: public, max-age=31536000, immutable — safe ONLY
            // because the URL always carries ?v=<rtoflow_design_updated_at>,
            // which the class docblock's own comment says "changes on every
            // save". That's true for an admin's save, but a PLUGIN UPDATE
            // that changes DesignSettingsService::defaultSettings() (a new
            // default type scale, spacing, card radius/shadow, etc.) is not
            // a "save" — it never touches rtoflow_design_updated_at. On any
            // site where the Design Settings panel was opened/saved even
            // once before (so the option already exists), the ?v= URL stays
            // byte-identical across the update, and both the browser and any
            // edge/reverse-proxy cache keep serving the exact pre-update CSS
            // for up to a year — the new PHP defaults never reach the page
            // even though buildCss() would now generate different output.
            // This is the exact same "uploading files doesn't run it" class
            // of bug the rewrite-rules re-flush above already exists to fix,
            // so it's corrected the same way: on every version bump,
            // unconditionally touch the version stamp so the very next
            // request gets a fresh ?v= and therefore a fresh, uncached CSS
            // fetch — regardless of whether this particular update actually
            // changed design defaults, since Bootstrap has no cheap way to
            // know that in advance and a redundant cache-bust is harmless.
            \RTOFLOW\Services\DesignSettingsService::touchVersion();
        }, 6);

        // FIX (production crash + real half-built gap found after first
        // delivery of the Part 4.10 category re-architecture): the Form
        // Builder's 7 category schemas (RealFormSchemaSeeder) were written
        // and validated in isolation but NEVER ACTUALLY SEEDED into a live
        // database — nothing called RealFormSchemaSeeder::run(). That meant
        // even once the "class not found" autoload bug above is fixed,
        // every category's getForCategory() would still return null forever
        // (no active schema row exists) and an admin opening /rto-admin/
        // forms/ would see 7 categories all still "Default" with none of
        // the real migrated fields actually present to edit — the exact
        // "half-built" outcome this fix corrects, not a cosmetic gap.
        // Mirrors the existing auto-seed pattern above (IndiaDataSeeder/
        // CitySeeder run automatically when their tables are empty) rather
        // than requiring a manual admin trigger: runs once, only after
        // migration 12 (the `category` column) has actually landed — using
        // the same rtoflow_db_version gate so it can't fire before the
        // column exists — and only if no category-keyed schema row exists
        // yet, so it never overwrites an admin's own saved edits on a
        // later deploy.
        add_action('init', function () {
            if (get_option('rtoflow_db_version') !== RTOFLOW_VERSION) return; // migration 12 not confirmed landed yet
            if (get_option('rtoflow_form_categories_seeded')) return;
            global $wpdb;
            $col = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}rto_form_schemas LIKE 'category'");
            if (!$col) return; // migration hasn't actually added the column on this request yet — retry next time
            $existing = (int)$wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rto_form_schemas WHERE category IS NOT NULL"
            );
            if ($existing > 0) { update_option('rtoflow_form_categories_seeded', 1, false); return; }
            // No require_once needed — RealFormSchemaSeeder lives at
            // app/Database/Seeds/RealFormSchemaSeeder.php, matching its own
            // RTOFLOW\Database\Seeds namespace, so the plugin's PSR-4
            // autoloader (vendor/composer/autoload_real.php: 'RTOFLOW\\' →
            // 'app/') resolves it automatically like every other namespaced
            // class in this codebase. (The legacy IndiaDataSeeder/
            // CitySeeder/NotificationTemplateSeeder above are require_once'd
            // by hand because THEY declare no namespace and live outside
            // app/ — a different, older pattern; this file intentionally
            // does not repeat that pattern.)
            $result = \RTOFLOW\Database\Seeds\RealFormSchemaSeeder::run();
            foreach ($result['failed'] ?? [] as $catKey => $msg) {
                error_log("RTOFLOW RealFormSchemaSeeder: category '{$catKey}' failed to seed — {$msg}");
            }
            // Marked seeded even on partial failure — a failed category logs
            // loudly above and simply stays on its default built-in fields
            // (never crashes the public apply page), and is fixable by hand
            // in the Form Builder; it must not retry-and-duplicate-version
            // every single admin page load forever.
            update_option('rtoflow_form_categories_seeded', 1, false);
        }, 7);

        // P7-PERF-008: roles registered at activation only (not every request)

        // Register cron jobs
        add_action('init', [$this, 'registerCron']);

        // ENTERPRISE GAP FIX (Section 8 — "no robots.txt control"): WordPress's
        // default virtual robots.txt only ever emits the two generic
        // Disallow/Allow lines tied to the Settings → Reading "discourage
        // search engines" toggle — it has no idea any of rto-admin/,
        // rto-dashboard/, rto-vendor-portal/, rto-staff/ (all custom,
        // non-WP-post areas) even exist, so a misconfigured or absent robots
        // rule could let crawlers index internal portal URLs, and nothing
        // ever pointed crawlers at the new /sitemap.xml above.
        add_filter('robots_txt', [$this, 'appendRtoflowRobotsRules'], 20, 2);

        // P5-DB-008: invalidate dashboard cache on key events
        // City service config cache invalidation
        add_action('rtoflow_city_service_config_updated', fn(int $cityId) => delete_transient('rtofl_city_services_' . $cityId));

        // BUG #14 FIX: cache key mismatch — DashboardController uses 'rtoflow_dashboard_v2'
        // but these hooks were deleting 'rtoflow_dashboard_metrics_v2' (different key).
        // Dashboard cache was NEVER invalidated — always showed stale data.
        add_action('rtoflow_lead_created',         fn() => delete_transient('rtoflow_dashboard_v2'));
        add_action('rtoflow_payment_completed',    fn() => delete_transient('rtoflow_dashboard_v2'));
        add_action('rtoflow_lead_status_changed',  fn() => delete_transient('rtoflow_dashboard_v2'));

        // FIX (Config Versioning wiring — closes the previously-documented
        // "Config Versioning infra exists but isn't wired into any real
        // config screen" gap): ConfigVersionService::publish()/rollback()
        // previously only ever wrote to the rto_config_versions table —
        // clicking "Rollback to this version" in the admin UI recorded a
        // new, published version row but never actually changed what the
        // live feature (e.g. vendor auto-assignment scoring) uses, since
        // nothing read this table at runtime. publish() now fires this
        // action with the version's decoded payload whenever a version is
        // published from the version-history UI (manual publish or
        // rollback); each known config_key's live store applies it here.
        // MatchingConfig::save() (the normal Settings-tab save path) passes
        // fireApply=false when it records its own version, since it already
        // applied the value directly — this hook only needs to run for the
        // "activate an older version" path, and skipping it there avoids an
        // infinite save→publish→apply→save loop.
        // FIX (Config Versioning wiring, follow-up): 'feature_flags' is now
        // registered too. Deliberately calls FeatureFlags::applyBooleanMap()
        // here, NOT bulk_save() — bulk_save()'s real semantics are PHP
        // $_POST checkbox-presence (isset($submitted[$key]) means "checked"),
        // which does not match a stored version payload of real booleans (a
        // disabled flag's key is still isset()-true), so passing a version
        // payload to bulk_save() would have silently re-enabled every flag
        // on any rollback. applyBooleanMap() was added specifically to read
        // real booleans correctly.
        // FIX (Config Versioning wiring, City/Service Pricing): unlike
        // matching_config/feature_flags (one config_key for the whole
        // system), pricing is versioned per city — config_key is
        // 'city_pricing_{cityId}', since that's the unit an admin actually
        // edits and would want to roll back (a single blob covering every
        // city would make "roll back city X" also touch every other city's
        // last-saved state, which is not what a rollback should mean here).
        add_action('rtoflow_config_published', function (string $configKey, array $payload) {
            if ($configKey === 'matching_config') {
                \RTOFLOW\Config\MatchingConfig::save($payload);
            } elseif ($configKey === 'feature_flags') {
                \RTOFLOW\Config\FeatureFlags::applyBooleanMap($payload);
            } elseif (str_starts_with($configKey, 'city_pricing_')) {
                $cityId = (int)substr($configKey, strlen('city_pricing_'));
                if ($cityId > 0) {
                    \RTOFLOW\Bootstrap::container()
                        ->make(\RTOFLOW\Controllers\Admin\CityServiceConfigController::class)
                        ->applyVersionedPayload($cityId, $payload);
                }
            } elseif ($configKey === 'eligibility_rules') {
                // FIX (Config Versioning wiring, Eligibility Rules, Part 5.5):
                // EligibilityController::store()/toggle()/delete() now record
                // a full-table snapshot under this config_key after every
                // mutation (see EligibilityController::snapshotVersion()).
                // This branch is what makes "activate an older version" from
                // the Version History screen actually mean something for
                // eligibility rules — without it, publish()/rollback() would
                // still write version rows but never touch the live
                // rto_eligibility_rules table, exactly the "records history
                // but can't undo anything" gap this fixes.
                \RTOFLOW\Bootstrap::container()
                    ->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)
                    ->applyVersionedPayload($payload);
            } elseif ($configKey === 'automation_rules') {
                // ENTERPRISE GAP FIX (Phase 4, item 8 — "no versioning/
                // rollback for Automation rules ..."): same wiring pattern
                // as eligibility_rules above — see AutomationController::
                // snapshotVersion()/applyVersionedPayload().
                \RTOFLOW\Bootstrap::container()
                    ->make(\RTOFLOW\Controllers\Admin\AutomationController::class)
                    ->applyVersionedPayload($payload);
            } elseif ($configKey === 'webhook_subscriptions') {
                // ENTERPRISE GAP FIX (Phase 4, item 8 — "... or Webhooks"):
                // see WebhookController::snapshotVersion()/
                // applyVersionedPayload() — secrets are deliberately never
                // part of this snapshot/restore.
                \RTOFLOW\Bootstrap::container()
                    ->make(\RTOFLOW\Controllers\Admin\WebhookController::class)
                    ->applyVersionedPayload($payload);
            } elseif (str_starts_with($configKey, 'email_template_')) {
                // ENTERPRISE GAP FIX (Phase 4, item 8 — "... or Email
                // Templates"): keyed per-template id, same reasoning as
                // city_pricing_{id} above.
                $templateId = (int)substr($configKey, strlen('email_template_'));
                if ($templateId > 0) {
                    \RTOFLOW\Bootstrap::container()
                        ->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)
                        ->applyVersionedPayload($templateId, $payload);
                }
            } elseif (str_starts_with($configKey, 'workflow_definition_')) {
                // ENTERPRISE GAP FIX (Phase 4, item 8 — "... or Workflow
                // Definitions"): keyed per-definition id — see
                // WorkflowBuilderController::snapshotVersion()/
                // applyVersionedPayload() for why states+transitions are
                // restored together as one unit.
                $definitionId = (int)substr($configKey, strlen('workflow_definition_'));
                if ($definitionId > 0) {
                    \RTOFLOW\Bootstrap::container()
                        ->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)
                        ->applyVersionedPayload($definitionId, $payload);
                }
            }
        }, 10, 2);

        // P10-EDGE-002 — 2FA enforcement REMOVED PER EXPLICIT USER REQUEST.
        //
        // History: this hook used to force rto_admin accounts through TOTP
        // 2FA setup and then require isVerifiedForSession() on every page
        // load. That mandatory-2FA design caused a real production
        // ERR_TOO_MANY_REDIRECTS lockout (no login-time verification step
        // ever existed to satisfy isVerifiedForSession()), which was fixed
        // in v3.7.129 by adding a real 2FA challenge screen. The user has
        // since asked, explicitly: "i dont need 2 factor authentication for
        // login, being an admin i should be logged in automatically" — so
        // 2FA is no longer enforced or required for anyone, of any role.
        //
        // TwoFactor.php, TwoFactorSettingsController and the /rto-admin/
        // my-security/ setup screen are left in place and fully working —
        // an admin who WANTS TOTP 2FA can still turn it on for their own
        // account from My Security — but nothing in the platform requires
        // it or gates access on it any more. In its place, the login page
        // now offers Email OTP and Magic Link sign-in (see 'rto-login' in
        // Router.php and rtoflow-os.php's rtoflow_ajax_email_otp_* /
        // rtoflow_ajax_magic_link_* handlers) as the "maximum" verification
        // step the user asked for, applied at login time rather than as a
        // recurring per-session gate.
        add_action('template_redirect', function () {
            // Intentionally empty: mandatory-2FA gate removed. Kept as a
            // named, documented no-op (rather than deleting the hook
            // registration outright) so the history above stays attached
            // to the exact place the old behavior lived, for anyone
            // reading this file later.
        });

        // Boot router
        Router::boot();

        // Enqueue assets
        // ROOT-CAUSE FIX (platform-wide CSS audit): enqueueAdminAssets() was
        // hooked to admin_enqueue_scripts, which WordPress fires ONLY inside
        // the real /wp-admin/ dashboard. Every RTOFLOW admin screen
        // (/rto-admin/leads/, /rto-admin/nav/, etc.) is served on the PUBLIC
        // front end via custom rewrite rules (see Router::boot()) and a
        // template_redirect dispatch, not through /wp-admin/ at all — so
        // admin_enqueue_scripts never fired for any of them and
        // admin.css/admin.js have never been loaded on a single real admin
        // page. That is the actual cause of the "unstyled" screenshots: only
        // inline styles (the welcome banner) rendered, while every class
        // that depends on admin.css (.rto-nav-grid, .rto-nav-card,
        // .rto-stat-banner, .rto-card, .rto-kpi-grid, etc. — effectively the
        // entire design system) had no matching CSS delivered to the
        // browser at all. Two previous CSS-fix rounds edited real bugs in
        // admin.css itself, which is why those fixes were real and verified
        // — but they could never have been visible, because the file was
        // never being requested by the browser on these routes in the first
        // place. Moving this to wp_enqueue_scripts (the hook that DOES fire
        // on these front-end routes) — the same hook enqueuePublicAssets()
        // already correctly uses — fixes this for every admin screen at
        // once, not just Nav/Orders.
        add_action('wp_enqueue_scripts', [$this, 'enqueuePublicAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAdminAssets']);

        // Auth hooks
        add_action('wp_login_failed',       [$this, 'onLoginFailed']);
        add_action('wp_login',              [$this, 'onLoginSuccess'], 10, 2);
        add_action('login_form',            [$this, 'lockoutCheck']);

        // ENTERPRISE GAP FIX (Phase 6, item — "no staff account suspension
        // short of full role removal"): there was previously no way to
        // temporarily disable an admin/staff account's access without
        // stripping their role entirely. Priority 30 so it runs AFTER
        // WordPress's own core username/password check
        // (wp_authenticate_username_password, priority 20) — a suspended
        // account is rejected only once its credentials are already
        // confirmed correct, so this never leaks "does this account exist"
        // information via a different error path than a wrong password
        // would.
        // TRACE: wp login form submit → WP core validates credentials first
        // → if that already returned a WP_Error (wrong password), this
        // filter is a no-op and passes it straight through → otherwise
        // checks 'rtoflow_staff_suspended' usermeta on the now-authenticated
        // user → precondition: user object, not a WP_Error →
        // postcondition: suspended account gets a WP_Error instead of a
        // valid WP_User, so wp_signon() never completes the login →
        // edge cases: non-staff account (client/vendor) → never checked,
        // suspension is staff-only by design (see the admin UI toggle).
        add_filter('authenticate', function ($user) {
            if (!($user instanceof \WP_User)) return $user;
            if (!rto_is_staff($user->ID)) return $user;
            if (get_user_meta($user->ID, 'rtoflow_staff_suspended', true) === '1') {
                return new \WP_Error('rtoflow_suspended', 'Your account has been suspended. Please contact an administrator.');
            }
            return $user;
        }, 30);

        // SLA cron handler
        add_action('rtoflow_sla_check',     [$this, 'runSlaCheck']);

        // Part 11-D build: inactivity alert — fires on same hourly cron as SLA check
        // Notifies admin when a lead has been stuck in the same state for configurable days
        add_action('rtoflow_sla_check',     [$this, 'runInactivityCheck']);

        // Notification cron handler
        add_action('rtoflow_notification_queue', [NotificationService::class, 'processQueue']);

        // ENTERPRISE GAP FIX (Section 7 — webhook retry queue): fires on the
        // same 5-minute cron as the notification queue — a better fit than
        // the hourly SLA cron given the retry backoff schedule starts at 1
        // minute and 5 minutes (WebhookDispatchService::RETRY_BACKOFF_MINUTES).
        // An hourly check would leave an endpoint's first two retry windows
        // effectively meaningless (both would already be "due" by the time
        // the check ran).
        add_action('rtoflow_notification_queue', function () {
            (new \RTOFLOW\Services\WebhookDispatchService())->processRetryQueue();
        });

        // ENTERPRISE GAP FIX (Phase 1, item 1 — "webhook dispatch blocks the
        // triggering request"): the FIRST delivery attempt for each active
        // subscriber is now scheduled here rather than made inline inside
        // dispatch() — see WebhookDispatchService::dispatch()'s updated
        // docblock for the full root-cause writeup. wp_schedule_single_event
        // fires this exact hook name with the exact args dispatch() passed.
        add_action('rtoflow_webhook_deliver_now', function ($subscriptionId, $eventType, $body, $timestamp) {
            (new \RTOFLOW\Services\WebhookDispatchService())->deliverScheduled((int)$subscriptionId, (string)$eventType, (string)$body, (int)$timestamp);
        }, 10, 4);

        // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export
        // path): daily cron target — see runScheduled()'s docblock for why
        // this is a safe no-op until an admin opts in.
        add_action('rtoflow_bi_export_run', function () {
            (new \RTOFLOW\Services\BiExportService())->runScheduled();
        });

        // ENTERPRISE GAP FIX (Section 7 — payments reconciliation job)
        add_action('rtoflow_payment_reconciliation', function () {
            (new \RTOFLOW\Services\PaymentReconciliationService())->runDaily();
        });

        // ENTERPRISE GAP FIX (Phase 9, item — "data retention is reactive,
        // not policy-driven"): daily automatic purge, driven by the admin-
        // configurable periods on Settings → Data Retention — see
        // DataRetentionService.
        add_action('rtoflow_data_retention', function () {
            (new \RTOFLOW\Services\DataRetentionService())->runDaily();
        });

        // ENTERPRISE GAP FIX (Phase 4, item 3 — document expiry tracking)
        add_action('rtoflow_document_expiry_check', [$this, 'runDocumentExpiryCheck']);

        // ENTERPRISE GAP FIX (Section 9 — no scheduled/emailed reports
        // anywhere in the platform): weekly ops summary emailed to the
        // configured admin recipient. See OpsReportService for the full
        // root-cause writeup.
        add_action('rtoflow_weekly_ops_report', function () {
            (new \RTOFLOW\Services\OpsReportService())->runWeekly();
        });

        // ENTERPRISE GAP FIX (Phase 2, item 5 — backup/DR automation)
        add_action('rtoflow_weekly_backup', function () {
            $result = \RTOFLOW\Services\BackupService::run();
            if (!$result['success']) {
                error_log('RTOFLOW weekly backup failed: ' . $result['message']);
            }
        });

        // Lead lifecycle hooks
        add_action('rtoflow_lead_created',          [$this, 'onLeadCreated'],         10, 3);
        add_action('rtoflow_payment_completed',      [$this, 'onPaymentCompleted'],    10, 3);
        add_action('rtoflow_vendor_assigned',        [$this, 'onVendorAssigned'],      10, 3);
        // Known Limitations audit fix: notify the PREVIOUS vendor when a
        // lead is reassigned to someone else (see LeadService::assignVendor()).
        add_action('rtoflow_vendor_unassigned',      [$this, 'onVendorUnassigned'],    10, 4);
        add_action('rtoflow_lead_status_changed',    [$this, 'onStatusChanged'],       10, 4);
        add_action('rtoflow_sla_warning',            [$this, 'onSlaWarning']);
        add_action('rtoflow_sla_breached',           [$this, 'onSlaBreached']);

        // ENTERPRISE GAP FIX (Phase 5, item 2 — "client referral/loyalty
        // program"): captures ?ref=CODE into a cookie on any front-end hit
        // (not just the Apply page, so a referral link shared as a plain
        // home_url() still attributes correctly), then attributes it to the
        // new account on WordPress's own 'user_register' action — which
        // fires for every wp_create_user() call in this codebase (there are
        // several separate call sites across Router.php's guest-checkout and
        // registration flows; hooking the WP-core action here covers all of
        // them at once instead of editing each call site individually).
        // TRACE: 'init' (priority 1, before most other init work) → ?ref=
        // present + no existing cookie → sets a 30-day 'rtoflow_ref' cookie →
        // preconditions: headers not yet sent → postconditions: cookie set
        // for this browser only, once; edge cases: malformed ref value →
        // regex-rejected, no cookie set; headers already sent (rare, e.g. an
        // earlier plugin output) → silently skipped, referral is just not
        // captured for that one request.
        add_action('init', function () {
            if (headers_sent()) return;
            if (empty($_GET['ref']) || !empty($_COOKIE['rtoflow_ref'])) return;
            $code = sanitize_text_field(wp_unslash($_GET['ref']));
            if (preg_match('/^[A-Za-z0-9]{4,20}$/', $code)) {
                setcookie('rtoflow_ref', strtoupper($code), time() + 30 * DAY_IN_SECONDS, '/');
            }
        }, 1);
        add_action('user_register', function (int $userId) {
            if (!empty($_COOKIE['rtoflow_ref'])) {
                \RTOFLOW\Services\ReferralService::recordReferral(
                    $userId,
                    sanitize_text_field(wp_unslash($_COOKIE['rtoflow_ref']))
                );
            }
        });
        // TRACE: fired by PaymentService::record() only when payment_status
        // becomes 'paid' → ReferralService::creditOnFirstPayment() re-checks
        // eligibility and dedup itself (see that method), so this hook can
        // safely fire on every completed payment without double-crediting.
        add_action('rtoflow_payment_completed', function (int $leadId) {
            \RTOFLOW\Services\ReferralService::creditOnFirstPayment($leadId);
        }, 20, 1);
    }

    // ── Activation ────────────────────────────────────────────────────────

    public static function activate(): void
    {
        // P9-WP-002 FIX: multisite guard — only activate on the main site
        if (function_exists('is_multisite') && is_multisite() && function_exists('is_main_site') && !is_main_site()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>RTOFLOW OS</strong> '
                    . 'must be activated on the main site of a Multisite network. '
                    . 'Per-subsite activation is not supported in this version.</p></div>';
            });
            return;
        }

        // ── Part 15-B build: environment assumption checks at boot ──────────
        // Verify every assumption about the deployment environment. Any failure
        // produces a clear admin notice rather than a cryptic runtime error.
        $envErrors = [];

        // PHP version: plugin uses match(), fibers-safe, and named args (PHP 8.0+)
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $envErrors[] = 'PHP 8.0 or higher is required. Current version: ' . PHP_VERSION;
        }

        // WordPress version: plugin uses wp_create_nonce(), WP_Session_Tokens (WP 4.0+)
        global $wp_version;
        if (version_compare($wp_version, '5.9', '<')) {
            $envErrors[] = 'WordPress 5.9 or higher is required. Current version: ' . $wp_version;
        }

        // MySQL: plugin uses JSON columns (MySQL 5.7.8+) and ON UPDATE CURRENT_TIMESTAMP
        global $wpdb;
        $mysqlVersion = $wpdb->get_var('SELECT VERSION()');
        if ($mysqlVersion && version_compare(preg_replace('/[^0-9.].*/', '', $mysqlVersion), '5.7.8', '<')) {
            $envErrors[] = 'MySQL 5.7.8 or higher is required for JSON column support. Current: ' . $mysqlVersion;
        }

        // Upload directory: documents are stored in wp-content/uploads/rto-documents/
        $uploadDir = wp_upload_dir();
        if (!empty($uploadDir['error'])) {
            $envErrors[] = 'WordPress uploads directory is not accessible: ' . $uploadDir['error'];
        } elseif (!wp_mkdir_p($uploadDir['basedir'] . '/rto-documents')) {
            $envErrors[] = 'Cannot create upload directory: ' . $uploadDir['basedir'] . '/rto-documents — check server write permissions.';
        }

        // mbstring extension: used for string operations on Indian names
        if (!extension_loaded('mbstring')) {
            $envErrors[] = 'PHP mbstring extension is required but not loaded.';
        }

        if (!empty($envErrors)) {
            $msg = implode('<br>', array_map('esc_html', $envErrors));
            deactivate_plugins(plugin_basename(RTOFLOW_FILE));
            wp_die(
                '<strong>RTOFLOW OS cannot be activated:</strong><br>' . $msg,
                'Environment Check Failed',
                ['back_link' => true]
            );
        }
        // ── End environment checks ───────────────────────────────────────────

        Env::load();

        // Run migrations
        $results = MigrationRunner::run();
        foreach ($results as $r) {
            if ($r['status'] === 'error') {
                deactivate_plugins(plugin_basename(RTOFLOW_FILE));
                wp_die('RTOFLOW activation failed: ' . esc_html($r['message']));
            }
        }

        // Seed notification templates
        require_once RTOFLOW_DIR . 'database/seeds/NotificationTemplateSeeder.php';
        (new \RTOFLOW_NotificationTemplateSeeder())->run();

        // Seed India master data (states, cities, services) — safe to run multiple times
        require_once RTOFLOW_DIR . 'database/seeds/IndiaDataSeeder.php';
        (new \IndiaDataSeeder())->run();

        // Seed complete city database (300+ cities with slugs and RTO codes)
        require_once RTOFLOW_DIR . 'database/seeds/CitySeeder.php';
        (new \RTOFLOW_CitySeeder())->run();

        // Seed services, doc types, automation rules if using v2 Seeder
        require_once RTOFLOW_DIR . 'database/seeds/Seeder.php';
        (new \RTOFLOW_Seeder())->run();

        // Register roles
        self::getInstance()->registerRoles();

        // Create pages
        self::createPages();

        // Schedule cron
        if (!wp_next_scheduled('rtoflow_sla_check')) {
            wp_schedule_event(time(), 'hourly', 'rtoflow_sla_check');
        }
        if (!wp_next_scheduled('rtoflow_notification_queue')) {
            wp_schedule_event(time(), 'rtoflow_5min', 'rtoflow_notification_queue');
        }
        // ENTERPRISE GAP FIX (Section 7 — payments reconciliation job): daily
        // is the right cadence here — this is a safety net for the rare
        // "webhook was lost" case (see PaymentReconciliationService's own
        // docblock for the full rationale), not a real-time path, and its
        // 48-hour lookback window comfortably covers a daily cadence with
        // overlap to spare.
        if (!wp_next_scheduled('rtoflow_payment_reconciliation')) {
            wp_schedule_event(time(), 'daily', 'rtoflow_payment_reconciliation');
        }
        // ENTERPRISE GAP FIX (Phase 9, item — "data retention is reactive,
        // not policy-driven"): daily, matching the payment-reconciliation
        // job's own cadence above — retention purging is a background
        // compliance sweep, not something that needs finer granularity.
        if (!wp_next_scheduled('rtoflow_data_retention')) {
            wp_schedule_event(time(), 'daily', 'rtoflow_data_retention');
        }
        // ENTERPRISE GAP FIX (Section 9 — scheduled weekly report)
        if (!wp_next_scheduled('rtoflow_weekly_ops_report')) {
            wp_schedule_event(time(), 'weekly', 'rtoflow_weekly_ops_report');
        }
        // ENTERPRISE GAP FIX (Phase 2, item 5 — backup/DR automation): see
        // BackupService's class docblock. Weekly matches the cadence of the
        // existing ops report cron just above; retention (8 backups) is
        // enforced inside BackupService::run(), not here.
        if (!wp_next_scheduled('rtoflow_weekly_backup')) {
            wp_schedule_event(time(), 'weekly', 'rtoflow_weekly_backup');
        }
        // ENTERPRISE GAP FIX (Phase 4, item 3 — document expiry reminders):
        // daily matches the cadence of the reconciliation job above — an
        // expiring license/insurance/RC doesn't need real-time checking.
        if (!wp_next_scheduled('rtoflow_document_expiry_check')) {
            wp_schedule_event(time(), 'daily', 'rtoflow_document_expiry_check');
        }
        // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export
        // path): daily, same cadence as reconciliation/document-expiry above
        // — a full nightly snapshot is the right cadence for this data
        // volume, see BiExportService's docblock for scope. runScheduled()
        // itself is a no-op unless an admin has enabled it on the Data
        // Export screen, so activating the plugin does not silently start
        // writing export files nobody asked for.
        if (!wp_next_scheduled('rtoflow_bi_export_run')) {
            wp_schedule_event(time(), 'daily', 'rtoflow_bi_export_run');
        }

        // Register rewrite rules and flush them so portal URLs work immediately
        // WordPress needs add_rewrite_rule() to fire via 'init' AND rules to be flushed
        Router::addRewriteRules();
        flush_rewrite_rules(false); // false = skip .htaccess update for speed

        // Mark that setup steps are needed
        set_transient('rtoflow_just_activated', 1, 120);
    }

    // ── Deactivation ──────────────────────────────────────────────────────

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('rtoflow_sla_check');
        wp_clear_scheduled_hook('rtoflow_notification_queue');
        wp_clear_scheduled_hook('rtoflow_payment_reconciliation');
        wp_clear_scheduled_hook('rtoflow_data_retention');
        wp_clear_scheduled_hook('rtoflow_weekly_ops_report');
        wp_clear_scheduled_hook('rtoflow_weekly_backup');
        wp_clear_scheduled_hook('rtoflow_bi_export_run');
        flush_rewrite_rules();
    }

    // ── Roles ─────────────────────────────────────────────────────────────

    // TRACE: fired by init action → add_role() for rto_admin/rto_staff/rto_vendor/rto_client →
    //        each role gets named capabilities used by current_user_can() checks →
    //        preconditions: WP init hook fired →
    //        postconditions: 4 custom roles registered in wp_user_roles option if not already present →
    //        edge cases: role already exists → get_role() returns non-null → add_role() skipped (idempotent)
    public function registerRoles(): void
    {
        $roles = [
            'rto_admin'  => ['name' => 'RTO Admin',  'capabilities' => ['read' => true, 'rto_admin' => true, 'rto_staff' => true, 'rto_manage_leads' => true, 'rto_manage_vendors' => true, 'rto_manage_settings' => true]],
            'rto_staff'  => ['name' => 'RTO Staff',  'capabilities' => ['read' => true, 'rto_staff' => true, 'rto_manage_leads' => true]],
            'rto_vendor' => ['name' => 'RTO Vendor', 'capabilities' => ['read' => true, 'rto_vendor' => true]],
            'rto_client' => ['name' => 'RTO Client', 'capabilities' => ['read' => true, 'rto_client' => true]],
        ];

        foreach ($roles as $slug => $role) {
            if (!get_role($slug)) {
                add_role($slug, $role['name'], $role['capabilities']);
            }
        }
    }

    // ── Cron ─────────────────────────────────────────────────────────────

    // TRACE: fired by init action → registers 'rtoflow_5min' custom cron interval →
    //        schedules rtoflow_sla_check (hourly) and rtoflow_notification_queue (5min) if not scheduled →
    //        preconditions: WP init hook; plugin activated →
    //        postconditions: WP cron table has 2 rtoflow jobs; custom 5min interval registered →
    //        edge cases: already scheduled → wp_next_scheduled() returns truthy → skip scheduling
    public function registerCron(): void
    {
        add_filter('cron_schedules', function(array $schedules) {
            $schedules['rtoflow_5min'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
            // ENTERPRISE GAP FIX (Section 9 — scheduled weekly report): WP
            // core only ships hourly/twicedaily/daily intervals, not weekly.
            if (!isset($schedules['weekly'])) {
                $schedules['weekly'] = ['interval' => 7 * DAY_IN_SECONDS, 'display' => 'Once Weekly'];
            }
            return $schedules;
        });
    }

    // TRACE: fired by WordPress core's 'robots_txt' filter whenever /robots.txt
    //        is served (virtual, not a real file) →
    //        appends explicit Disallow rules for every internal portal area
    //        (admin/vendor/client/staff dashboards, invoice/document download
    //        endpoints, webhook receivers) and a Sitemap: line pointing at
    //        the new /sitemap.xml →
    //        preconditions: none →
    //        postconditions: none (pure string filter, no state mutation) →
    //        edge cases: site set to "discourage search engines" (blog_public=0,
    //        $public===false) → WP core already emits a blanket "Disallow: /"
    //        in that case, so appending more specific rules on top is harmless
    //        and redundant rather than contradictory
    public function appendRtoflowRobotsRules(string $output, $public): string
    {
        $rules = "\n# RTOFLOW OS — internal portal areas, never for search engines\n"
               . "Disallow: /rto-admin/\n"
               . "Disallow: /rto-dashboard/\n"
               . "Disallow: /rto-vendor/\n"
               . "Disallow: /rto-invoice/\n"
               . "Disallow: /rto-documents/\n"
               . "Disallow: /rto-webhook/\n"
               . "Disallow: /rto-track/\n"
               . "\nSitemap: " . home_url('/sitemap.xml') . "\n";

        return $output . $rules;
    }

    // TRACE: fired by rtoflow_sla_check cron hook (hourly) →
    //        resolves LeadService from container →
    //        calls LeadService::processSlaChecks() → queries overdue leads → updates sla_warned/sla_breached →
    //        sends notifications per breach →
    //        preconditions: rtoflow_sla_check cron registered; WP cron fires →
    //        postconditions: rto_leads.sla_warned/sla_breached flags updated; admin notified of breaches →
    //        edge cases: no overdue leads → no-op; DB failure → error_log; notification queue absorbs failures
    public function runSlaCheck(): void
    {
        // FIX P0-11: 'sla_enforcement' feature flag now actually gates this —
        // previously toggling it in Settings → Features had no effect at all.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('sla_enforcement')) {
            return;
        }

        $container = self::container();
        $leadSvc   = $container->make(LeadService::class);
        $leadSvc->processSlaChecks();

        // FIX P0-10: AutomationService::run_scheduled() (the SLA-approaching
        // sweep that fires 'lead.sla_warning' for any admin-configured
        // automation rule to react to) was never called by any cron —
        // LeadService::processSlaChecks() above handles the sla_warned/
        // sla_breached flag + direct notification path, but the *rule engine*
        // sweep was entirely separate and dormant. Both now run on the same
        // hourly hook.
        $container->make(\RTOFLOW\Services\AutomationService::class)->run_scheduled();

        // ENTERPRISE GAP FIX (Section 8 — "Complaints have no SLA tracking"):
        // rto_complaints.sla_deadline is now populated at filing time
        // (ComplaintsController::store()), but nothing ever flipped
        // sla_breached once that deadline passed — the column existed and
        // was displayed nowhere, computed nowhere. Mirrors
        // LeadService::processSlaChecks()'s breach half exactly (same
        // batch-update-then-audit-log pattern), scoped to complaints that
        // are still open (a resolved/closed complaint's SLA no longer
        // matters — matching how leads exclude 'completed'/'cancelled').
        $this->runComplaintSlaBreachCheck();

        // ENTERPRISE GAP FIX (Phase 1, item 3 — SLA breach auto-escalation):
        // runs on the same hourly cron, after breach detection above has had
        // a chance to set escalated_at for any newly-breached lead this run.
        $this->runSlaEscalationCheck();
    }

    // TRACE: called from runSlaCheck() on the same hourly rtoflow_sla_check cron →
    //        queries rto_complaints WHERE still open AND sla_deadline passed AND not yet breached →
    //        batch-updates sla_breached=1 → audit-logs each breach →
    //        preconditions: sla_enforcement flag enabled (checked by caller); WP cron fires →
    //        postconditions: rto_complaints.sla_breached=1 for every newly-overdue open complaint →
    //        edge cases: no overdue complaints → no-op; complaint already resolved before deadline → excluded by status filter
    private function runComplaintSlaBreachCheck(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $breached = $wpdb->get_results(
            "SELECT id, complaint_number FROM {$p}rto_complaints
             WHERE status NOT IN ('resolved','closed','rejected')
             AND sla_deadline IS NOT NULL
             AND sla_deadline < NOW()
             AND sla_breached = 0",
            ARRAY_A
        ) ?: [];

        if (empty($breached)) return;

        $ids = implode(',', array_map('intval', array_column($breached, 'id')));
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query("UPDATE {$p}rto_complaints SET sla_breached=1 WHERE id IN ({$ids})");
        foreach ($breached as $row) {
            \RTOFLOW\Services\AuditService::log('complaint.sla_breached', null, ['complaint_id' => (int)$row['id'], 'complaint_number' => $row['complaint_number']]);
        }
    }

    // ── Inactivity check (Part 11-D build) ────────────────────────────────
    // Fires on the same hourly cron as SLA. Alerts admin when a lead has not
    // moved from its current status in N days (default: 3 days for payment_received,
    // 2 days for assigned, configurable via rtoflow_inactivity_days option).
    // TRACE: fired by rtoflow_sla_check cron hook (hourly) →
    //        queries rto_leads WHERE status IN actionable states AND updated_at <= N days ago AND sla_breached=0 →
    //        per matching lead: checks rto_notifications for dedup (skip if notified in last 24h) →
    //        sends 'lead_inactive' notification to admin user →
    //        preconditions: rtoflow_admin_user_id option set OR rto_admin role user exists →
    //        postconditions: notification row written to rto_notifications per unnotified stuck lead →
    //        edge cases: no admin configured → early return; already notified in 24h → skipped;
    //                    inactivityDays=0 → max(1,0)=1 prevents runaway; empty result → no-op
    public function runInactivityCheck(): void
    {
        global $wpdb;

        $inactivityDays = max(1, (int)get_option('rtoflow_inactivity_days', 3));
        $adminId        = (int)get_option('rtoflow_admin_user_id', 0);
        if (!$adminId) {
            $admins  = get_users(['role' => 'rto_admin', 'number' => 1, 'fields' => 'ids']);
            $adminId = !empty($admins) ? (int)$admins[0] : 0;
        }
        if (!$adminId) return; // No admin configured

        // Only check actionable states — not terminal or payment-waiting states
        $stuckStatuses = "'payment_received','assigned','docs_pending'";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $stuck = $wpdb->get_results($wpdb->prepare(
            "SELECT id, lead_number, status, updated_at, service_id
             FROM {$wpdb->prefix}rto_leads
             WHERE status IN ({$stuckStatuses})
               AND deleted_at IS NULL
               AND (updated_at IS NULL OR updated_at <= %s)
               AND sla_breached = 0",
            date('Y-m-d H:i:s', strtotime("-{$inactivityDays} days"))
        ), ARRAY_A) ?: [];

        foreach ($stuck as $lead) {
            // Check if inactivity was already notified in the last 24h (avoid spam)
            $alreadyNotified = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rto_notifications
                 WHERE lead_id=%d AND template_id='lead_inactive' AND created_at >= %s",
                $lead['id'], date('Y-m-d H:i:s', strtotime('-24 hours'))
            ));
            if ($alreadyNotified) continue;

            NotificationService::send('lead_inactive', [
                'lead_number'  => $lead['lead_number'],
                'status'       => rto_status_label($lead['status']),
                'days_stuck'   => $inactivityDays,
                'lead_url'     => home_url('/rto-admin/leads/' . $lead['id']),
            ], $adminId, ['email'], (int)$lead['id']);
        }
    }

    // ── Document expiry check (Phase 4, item 3 build) ──────────────────────
    // ENTERPRISE GAP FIX (Phase 4, item 3 — "no document expiry tracking"):
    // fires daily; finds verified documents whose expiry_date falls within the
    // reminder window (default 30 days out, configurable via
    // rtoflow_document_expiry_reminder_days) and have not yet had a reminder
    // sent for their CURRENT expiry_date (dedup via expiry_reminder_sent_at —
    // set to NULL again if expiry_date is ever changed by staff, since the
    // UPDATE that changes expiry_date always resets it, so a re-dated document
    // gets a fresh reminder cycle).
    // TRACE: fired by rtoflow_document_expiry_check daily cron (Bootstrap::activate()) →
    //        queries rto_documents WHERE status='verified' AND expiry_date IS NOT NULL
    //        AND expiry_date <= window AND expiry_reminder_sent_at IS NULL →
    //        joins rto_leads for client_id + lead_number, rto_doc_types for the doc name →
    //        sends 'document_expiring' notification to the client (email + sms per their prefs) →
    //        marks expiry_reminder_sent_at=NOW() so the same document is not renotified daily →
    //        preconditions: WP cron fires; document has both expiry_date and status='verified' →
    //        postconditions: rto_documents.expiry_reminder_sent_at set for every notified row →
    //        edge cases: expiry_date already in the past (overdue) → still notified once, using
    //                    the same window (window check is "<=", not a lower bound); no matching
    //                    client user row → NotificationService::send() no-ops safely for that row;
    //                    empty result set → no-op
    public function runDocumentExpiryCheck(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        $reminderDays = max(1, (int)get_option('rtoflow_document_expiry_reminder_days', 30));
        $windowDate   = date('Y-m-d', strtotime("+{$reminderDays} days"));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $expiring = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.expiry_date, d.lead_id, l.client_id, l.lead_number, dt.name AS doc_type_name
             FROM {$p}rto_documents d
             INNER JOIN {$p}rto_leads l ON l.id = d.lead_id
             LEFT JOIN {$p}rto_doc_types dt ON dt.id = d.doc_type_id
             WHERE d.status = 'verified'
               AND d.expiry_date IS NOT NULL
               AND d.expiry_date <= %s
               AND d.expiry_reminder_sent_at IS NULL
               AND l.deleted_at IS NULL",
            $windowDate
        ), ARRAY_A) ?: [];

        if (empty($expiring)) return;

        foreach ($expiring as $doc) {
            if (empty($doc['client_id'])) continue;

            NotificationService::send('document_expiring', [
                'doc_type_name' => $doc['doc_type_name'] ?: 'Document',
                'expiry_date'   => date('d M Y', strtotime($doc['expiry_date'])),
                'lead_number'   => $doc['lead_number'],
                'documents_url' => home_url('/rto-dashboard/documents/'),
            ], (int)$doc['client_id'], ['email', 'sms'], (int)$doc['lead_id']);

            $wpdb->update(
                "{$p}rto_documents",
                ['expiry_reminder_sent_at' => current_time('mysql')],
                ['id' => (int)$doc['id']]
            );
        }
    }

    public function onLoginFailed(string $username): void
    {
        $ip = \RTOFLOW\Http\Middleware\RateLimiter::clientIp();
        \RTOFLOW\Auth\AccountLockout::recordFailure($username, $ip);
        \RTOFLOW\Services\AuditService::logLoginFailed($username);
    }

    public function onLoginSuccess(string $username, \WP_User $user): void
    {
        \RTOFLOW\Auth\AccountLockout::clearFailures($username);
        \RTOFLOW\Services\AuditService::logLogin($user->ID);
    }

    public function lockoutCheck(): void
    {
        // P1-ARCH-001 FIX: display lockout warning on WP login form
        $username = sanitize_text_field($_POST['log'] ?? '');
        if (!$username) return;

        $info = \RTOFLOW\Auth\AccountLockout::getLockoutInfo($username);
        if (!$info) return;

        printf(
            '<div class="message" style="border-left:4px solid #d63638;padding:8px 12px;margin:8px 0;background:#fef2f2;border-radius:2px">
                <strong>Account Locked:</strong> %s
             </div>',
            esc_html($info['message'])
        );
    }

    // ── Lead lifecycle ────────────────────────────────────────────────────

    // TRACE: fired by do_action('rtoflow_lead_created', $leadId, $clientId, $service) in LeadService::create() →
    //        NotificationService::notifyLeadCreated() queues email+SMS to client and admin →
    //        AutomationService::autoAssignVendor() attempts auto-assign if enabled →
    //        delete_transient('rtoflow_dashboard_v2') invalidates dashboard cache →
    //        preconditions: lead exists in DB with the given ID →
    //        postconditions: client+admin notification rows in rto_notifications; vendor optionally assigned →
    //        edge cases: auto-assign fails → error_log (try/catch); no suitable vendor → skipped
    public function onLeadCreated(int $leadId, int $clientId, array $service): void
    {
        $lead = self::container()->make(LeadService::class)->getLead($leadId);
        if (!$lead) return;

        // Send notification to client
        NotificationService::notifyLeadCreated($lead, $clientId);

        // Auto-assign vendor if feature enabled
        if (\RTOFLOW\Config\FeatureFlags::is_enabled('vendor_management')) {
            try {
                $vendorSvc = self::container()->make(\RTOFLOW\Services\VendorService::class);
                $vendorId  = $vendorSvc->autoAssign($leadId);
                if ($vendorId) {
                    error_log("RTOFLOW: Auto-assigned vendor {$vendorId} to lead {$leadId}");
                }
            } catch (\Throwable $e) {
                error_log('RTOFLOW: Auto-assign failed for lead ' . $leadId . ': ' . $e->getMessage());
            }
        }

        // Calculate and store AI risk score
        $this->calculateRiskScore($leadId, $lead, $service);
    }

    // TRACE: fired by do_action('rtoflow_payment_completed') in PaymentService::record() →
    //        NotificationService::notifyPaymentReceived() queues payment confirmation to client →
    //        delete_transient('rtoflow_dashboard_v2') invalidates dashboard cache →
    //        preconditions: payment recorded in rto_payments; lead exists →
    //        postconditions: notification queued; cache invalidated →
    //        edge cases: notification send failure → queued with retry; cache delete of non-existent key → no-op
    public function onPaymentCompleted(int $leadId, int $paymentId, ?int $invoiceId): void
    {
        global $wpdb;
        $lead = self::container()->make(LeadService::class)->getLead($leadId);
        if (!$lead) return;

        $payment = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_payments WHERE id=%d", $paymentId), ARRAY_A);
        if ($payment) {
            NotificationService::notifyPaymentReceived($lead, $payment, (int)$lead['client_id']);
        }
    }

    public function onVendorAssigned(int $leadId, int $vendorId, array $lead): void
    {
        global $wpdb;
        $vendor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_vendors WHERE id=%d", $vendorId), ARRAY_A);
        if (!$vendor) return;

        $service = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_services WHERE id=%d", $lead['service_id']), ARRAY_A);
        $vendorShare = (float)($service['vendor_share'] ?? 45);
        $vendorAmount = round((float)$lead['total_amount'] * ($vendorShare / 100), 2);

        NotificationService::notifyVendorAssigned($lead, (int)$vendor['user_id'], $vendorAmount);
    }

    // TRACE: fired by do_action('rtoflow_vendor_unassigned') in
    //        LeadService::assignVendor() whenever a lead already carrying a
    //        vendor is reassigned to a DIFFERENT vendor →
    //        looks up the PREVIOUS vendor's row for their WP user_id →
    //        NotificationService::notifyVendorUnassigned() queues a
    //        removal notice to that previous vendor →
    //        preconditions: previousVendorId really differs from the new
    //        vendor id (enforced in assignVendor(), not here) →
    //        postconditions: previous vendor has a queued email+SMS
    //        notification explaining they were removed from this job →
    //        edge cases: previous vendor row somehow no longer exists
    //        (hard-deleted, not just deactivated) → silently skipped, same
    //        defensive pattern as onVendorAssigned() above.
    public function onVendorUnassigned(int $leadId, int $previousVendorId, int $newVendorId, array $lead): void
    {
        global $wpdb;
        $vendor = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}rto_vendors WHERE id=%d", $previousVendorId), ARRAY_A);
        if (!$vendor || empty($vendor['user_id'])) return;

        NotificationService::notifyVendorUnassigned($lead, (int)$vendor['user_id']);
    }

    public function onStatusChanged(int $leadId, string $from, string $to, array $lead): void
    {
        NotificationService::notifyStatusChanged($lead, (int)$lead['client_id'], $to);
    }

    public function onSlaWarning(array $lead): void
    {
        $hours = round((strtotime($lead['sla_deadline']) - time()) / 3600);
        NotificationService::send('sla_warning', [
            'lead_number'    => $lead['lead_number'],
            'hours_remaining'=> max(1, $hours),
            'service_name'   => $lead['service_name'] ?? '',
            'city_name'      => $lead['city_name']    ?? '',
            'sla_deadline'   => rto_date($lead['sla_deadline']),
        ], (int)$lead['assigned_staff'] ?: (int)$lead['client_id'], ['email'], (int)$lead['id']);
    }

    public function onSlaBreached(array $lead): void
    {
        // P2-SEC-014 FIX: never default to user ID 1
        $adminId = (int)get_option('rtoflow_admin_user_id', 0);
        if (!$adminId) {
            $admins  = get_users(['role' => 'rto_admin', 'number' => 1, 'fields' => 'ids']);
            $adminId = !empty($admins) ? (int)$admins[0] : 0;
        }
        if (!$adminId) {
            error_log('RTOFLOW: SLA breach on lead ' . $lead['id'] . ' — no admin user configured.');
        } else {
            NotificationService::send('sla_breached', [
                'lead_number' => $lead['lead_number'],
                'sla_deadline'=> rto_date($lead['sla_deadline']),
                'admin_url'   => home_url('/rto-admin/leads/' . $lead['id']),
            ], $adminId, ['email'], (int)$lead['id']);
        }

        // ENTERPRISE GAP FIX (Phase 1, item 3 — "no automatic reassignment
        // or escalation when a vendor breaches SLA"): the notification
        // above was the ENTIRE reaction to a breach — nothing changed about
        // the lead itself, so a breached job sat exactly as it was,
        // indefinitely, unless a human noticed the email and acted. This
        // immediately bumps priority to the top of the scale (5 = Critical
        // — see LeadRepository::PRIORITIES) so the breach is visible on
        // every priority-sorted list the moment it happens, and stamps
        // escalated_at so runSlaEscalationCheck() (below, same hourly cron)
        // knows exactly when this breach's grace period started. Guarded so
        // re-firing this handler for a lead already escalated (e.g. a
        // duplicate event) does not repeatedly reset the grace-period clock.
        global $wpdb;
        $leadId = (int)$lead['id'];
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT priority, escalated_at FROM {$wpdb->prefix}rto_leads WHERE id=%d", $leadId
        ), ARRAY_A);
        if ($current && empty($current['escalated_at'])) {
            $wpdb->update($wpdb->prefix . 'rto_leads', [
                'priority'     => max(5, (int)$current['priority']),
                'escalated_at' => current_time('mysql'),
            ], ['id' => $leadId]);
            AuditService::log('lead.sla_escalated', $leadId, ['previous_priority' => (int)$current['priority']]);
        }
    }

    // TRACE: fired by the same hourly rtoflow_sla_check cron as
    //        runSlaCheck() (called right after it) →
    //        finds leads breached + escalated + not yet reassigned + still
    //        vendor-assigned + not in a terminal status + past the
    //        configurable grace period → for each: unassigns the current
    //        (underperforming) vendor, marks that assignment 'superseded',
    //        attempts VendorService::auto_assign() EXCLUDING that vendor,
    //        marks sla_reassigned=1 so this never repeats for the same
    //        breach, audit-logs the outcome either way →
    //        precondition: sla_enforcement flag enabled (checked by caller,
    //        same gate as the rest of SLA processing) →
    //        postcondition: each due lead has sla_reassigned=1; either a new
    //        vendor_id (reassignment succeeded) or vendor_id remains NULL
    //        with the failure reason audit-logged (no eligible replacement
    //        vendor found — a human needs to look at this one) →
    //        edge cases: no other eligible vendor exists → lead is left
    //        unassigned rather than silently kept with the SLA-breaching
    //        vendor, and the failure is logged, not swallowed.
    public function runSlaEscalationCheck(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // Configurable via wp_options (no UI yet — a documented, honest gap
        // rather than a hidden hard-coded constant); defaults to 4 hours.
        $graceHours = max(1, (int)get_option('rtoflow_sla_escalation_grace_hours', 4));

        $due = $wpdb->get_results($wpdb->prepare(
            "SELECT id, vendor_id, city_id, service_id, lead_number FROM {$p}rto_leads
             WHERE sla_breached = 1 AND sla_reassigned = 0 AND escalated_at IS NOT NULL
               AND vendor_id IS NOT NULL
               AND status NOT IN ('completed','cancelled')
               AND deleted_at IS NULL
               AND escalated_at <= DATE_SUB(NOW(), INTERVAL %d HOUR)
             LIMIT 100",
            $graceHours
        ), ARRAY_A) ?: [];

        if (empty($due)) return;

        $container = self::container();
        $vendorSvc = $container->make(\RTOFLOW\Services\VendorService::class);

        foreach ($due as $row) {
            $leadId       = (int)$row['id'];
            $oldVendorId  = (int)$row['vendor_id'];

            $wpdb->update($p . 'rto_leads', ['vendor_id' => null], ['id' => $leadId]);
            $wpdb->update($p . 'rto_assignments', ['status' => 'superseded'], [
                'lead_id' => $leadId, 'vendor_id' => $oldVendorId,
            ]);

            $newVendorId = $vendorSvc->auto_assign($leadId, $oldVendorId);

            // Marked regardless of outcome — success or "no eligible
            // replacement found" — so this row is never picked up again for
            // the SAME breach; see this method's docblock for why a second
            // breach (on the new vendor, if one was found) is intentionally
            // treated as a fresh case rather than looped on.
            $wpdb->update($p . 'rto_leads', ['sla_reassigned' => 1], ['id' => $leadId]);

            AuditService::log('lead.sla_auto_reassigned', $leadId, [
                'from_vendor_id' => $oldVendorId,
                'to_vendor_id'   => $newVendorId,
                'grace_hours'    => $graceHours,
                'outcome'        => $newVendorId ? 'reassigned' : 'no_eligible_vendor_found',
            ]);

            if (!$newVendorId) {
                error_log('RTOFLOW: SLA auto-escalation could not find a replacement vendor for lead ' . $leadId . ' (' . $row['lead_number'] . ') — left unassigned for staff to handle manually.');
            }
        }
    }

    // ── Assets ────────────────────────────────────────────────────────────

    // TRACE: fired by wp_enqueue_scripts action → checks get_query_var('rto_area') →
    //        if rto_area set: enqueues rtoflow-public css+js with jquery dependency →
    //        localises rtoflow object with ajax_url and all 7 client/vendor nonces →
    //        preconditions: WP init complete; get_query_var registered →
    //        postconditions: rtoflow-public assets on page; JS has ajax_url + all required nonces →
    //        edge cases: rto_area empty → no assets enqueued (non-plugin pages)
    public function enqueuePublicAssets(): void
    {
        if (!get_query_var('rto_area')) return;

        wp_enqueue_style('rtoflow-public', RTOFLOW_URL . 'resources/assets/css/public.css', [], RTOFLOW_VERSION);
        wp_enqueue_script('rtoflow-public', RTOFLOW_URL . 'resources/assets/js/public.js', ['jquery'], RTOFLOW_VERSION, true);

        // NOTE: every nonce name here must match the check_ajax_referer() call
        // in the corresponding controller method — any mismatch silently blocks all AJAX.
        // Client nonces:
        //   rto_client_pay    → ClientDashboardController::initiatePayment/verifyPayment
        //   rto_client_upload → ClientDashboardController::uploadDocument
        //   rto_client_msg    → ClientDashboardController::sendMessage
        //   rto_client_action → ComplaintsController::store, RatingsController::store
        // Vendor nonces:
        //   rto_vendor_job    → VendorDashboardController::respondToAssignment
        //   rto_vendor_upload → VendorDashboardController::uploadDocument
        //   rto_vendor_nonce  → Router::vendorAcceptJob / vendorRejectJob
        wp_localize_script('rtoflow-public', 'rtoflow', [
            'ajax_url'        => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('rto_public'),
            'nonce_pay'       => wp_create_nonce('rto_client_pay'),
            'nonce_upload'    => wp_create_nonce('rto_client_upload'),
            'nonce_msg'       => wp_create_nonce('rto_client_msg'),
            'nonce_action'    => wp_create_nonce('rto_client_action'),
            'nonce_vendor'    => wp_create_nonce('rto_vendor_nonce'),
            'nonce_vendor_job'=> wp_create_nonce('rto_vendor_job'),
            'nonce_vendor_up' => wp_create_nonce('rto_vendor_upload'),
            'razorpay_key'    => \RTOFLOW_Razorpay::isEnabled() ? Env::string('RAZORPAY_KEY') : '',
        ]);
    }

    // ROOT-CAUSE FIX (unstyled-admin-panel audit, deeper diagnosis): this
    // method's wp_enqueue_style()/wp_enqueue_script() calls have been DEAD
    // CODE on every single admin page since the day this file was written,
    // for a reason a hook-timing fix could never solve: WordPress only ever
    // *prints* the enqueue queue when something calls wp_head()/wp_footer().
    // resources/views/layouts/admin-header.php and admin-footer.php — the
    // actual templates every RTOFLOW admin screen renders through, since
    // these routes are dispatched outside the normal WP theme template
    // hierarchy (see Router::boot()) — call NEITHER hook (confirmed by
    // direct grep: zero matches for wp_head|wp_footer in either file). So
    // no matter which action this method was hooked to, or how correctly
    // it checked get_query_var('rto_area'), its wp_enqueue_style()/
    // wp_enqueue_script()/wp_localize_script() calls registered assets into
    // a queue that nothing ever flushed to the page. A previous pass's
    // "ROOT-CAUSE FIX" (moving this from admin_enqueue_scripts to
    // wp_enqueue_scripts) was a real, necessary correction for a *different*
    // bug (that hook literally never fires on these routes at all) but was
    // not sufficient — this deeper issue was still there underneath it,
    // undetected because nothing in this codebase's real-execution harnesses
    // exercises whether wp_head()/wp_footer() actually get called on a
    // rendered page.
    //
    // What has ACTUALLY been delivering admin.css/admin.js to the browser
    // this whole time is a hardcoded fallback <link>/<script> tag placed
    // directly in admin-header.php/admin-footer.php (the same pattern this
    // codebase already uses correctly for the rtoflowAdmin nonce object,
    // per the Part 4.16 comment in admin-header.php, for exactly the same
    // underlying reason). The Tabler Icons webfont enqueue had NO such
    // fallback and has therefore never loaded on any admin page at all —
    // currently harmless only because grep confirms zero admin views
    // actually use a `ti ti-*` icon class (every admin icon is a raw emoji
    // character instead), so removing it here is a real dead-code cleanup,
    // not a regression.
    //
    // Fix: stop calling the WordPress enqueue API here at all for assets
    // that this rendering path can never actually flush — it was silently
    // doing nothing and its accompanying comments incorrectly implied it
    // was the active delivery mechanism, which could mislead a future
    // change (e.g. "fixing" cache-busting here would have no effect on what
    // the browser receives, exactly as this file's own history shows).
    // Real CSS/JS delivery + cache-busting for admin pages now lives
    // entirely and honestly in admin-header.php/admin-footer.php via
    // rto_asset_version() (filemtime-based, see app/Support/helpers.php).
    // This method is kept only as a documented no-op guard in case a future
    // WordPress-idiomatic refactor adds a real wp_head()/wp_footer() call to
    // the admin template — at that point these enqueue calls could be
    // reinstated and would then genuinely take effect.
    public function enqueueAdminAssets(): void
    {
        if (get_query_var('rto_area') !== 'admin') return;
        // Intentionally no wp_enqueue_style()/wp_enqueue_script() calls here.
        // See the block comment above for why they would be silently inert
        // on every route this condition matches, and where the real asset
        // delivery actually happens instead.
    }

    // ── Pages ─────────────────────────────────────────────────────────────

    private static function createPages(): void
    {
        $pages = [
            ['title' => 'My Dashboard',     'slug' => 'rto-dashboard',   'content' => '[rtoflow_dashboard]'],
            ['title' => 'Apply for RTO',    'slug' => 'rto-apply',       'content' => '[rtoflow_apply]'],
            ['title' => 'Home',             'slug' => 'rtoflow-home',    'content' => '[rtoflow_home]'],
            ['title' => 'Login',            'slug' => 'login',           'content' => '[rtoflow_login]'],
            ['title' => 'Privacy Policy',   'slug' => 'privacy-policy',  'content' => '<!-- rtoflow privacy page -->'],
            ['title' => 'Terms & Conditions','slug' => 'terms-conditions','content' => '<!-- rtoflow terms page -->'],
        ];

        foreach ($pages as $page) {
            // P1-WP-001 FIX: get_page_by_path deprecated in WP 6.5
            $existing = get_posts([
                'name' => $page['slug'], 'post_type' => 'page',
                'post_status' => ['publish','draft','private'], 'posts_per_page' => 1,
            ]);
            if (empty($existing)) {
                wp_insert_post([
                    'post_title'   => $page['title'],
                    'post_name'    => $page['slug'],
                    'post_content' => $page['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ]);
            }
        }
    }

    // ── Service container ─────────────────────────────────────────────────

    private static function buildContainer(): Container
    {
        $c = new Container();

        $c->bind(GstService::class,     fn() => new GstService());
        $c->bind(TdsService::class,     fn() => new TdsService());
        $c->bind(AuditService::class,   fn() => new AuditService());
        // FIX P0 (live-environment fatal): NotificationService is a fully
        // static-method class (no constructor, no instance state — see
        // Services/NotificationService.php), but AutomationService's binding
        // below calls $c->make(NotificationService::class) to obtain an
        // instance to satisfy its constructor's type-hinted parameter.
        // Container::make() has no auto-wiring fallback — it throws
        // "No binding for [X]" for anything not explicitly bind()'d — and
        // this class had no binding at all. Any admin route that resolves
        // AutomationService (e.g. routeAdmin('dashboard'), which the report
        // above confirmed fatals here) hard-crashed with a 500 error before
        // this fix, on every request, since routeAdmin() runs on every
        // dashboard load. `new NotificationService()` is a harmless call —
        // the class has no constructor — so this cannot mask any different
        // real dependency; it exists purely to satisfy the container's
        // "everything must be explicitly bound" contract.
        $c->bind(NotificationService::class, fn() => new NotificationService());
        $c->bind(InvoiceService::class, fn($c) => new InvoiceService($c->make(GstService::class)));
        $c->bind(PaymentService::class, fn($c) => new PaymentService($c->make(InvoiceService::class)));
        $c->bind(\RTOFLOW\Services\WebhookDispatchService::class, fn() => new \RTOFLOW\Services\WebhookDispatchService());
        $c->bind(\RTOFLOW\Controllers\Admin\WebhookController::class, fn() => new \RTOFLOW\Controllers\Admin\WebhookController());
        // ENTERPRISE GAP FIX (Phase 12, item — generic inbound webhook framework)
        $c->bind(\RTOFLOW\Services\InboundWebhookService::class, fn() => new \RTOFLOW\Services\InboundWebhookService());
        $c->bind(\RTOFLOW\Controllers\Admin\InboundWebhookController::class, fn() => new \RTOFLOW\Controllers\Admin\InboundWebhookController());
        // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export path)
        $c->bind(\RTOFLOW\Services\BiExportService::class, fn() => new \RTOFLOW\Services\BiExportService());
        $c->bind(\RTOFLOW\Controllers\Admin\DataExportController::class, fn() => new \RTOFLOW\Controllers\Admin\DataExportController());
        $c->bind(LeadService::class,    fn($c) => new LeadService($c->make(GstService::class), $c->make(AuditService::class), $c->make(\RTOFLOW\Services\WorkflowEngineService::class), $c->make(\RTOFLOW\Services\WebhookDispatchService::class)));
        $c->bind(\RTOFLOW\Services\EligibilityService::class, fn() => new \RTOFLOW\Services\EligibilityService());
        $c->bind(PayoutService::class,  fn($c) => new PayoutService($c->make(TdsService::class)));

        // ── Repositories (new in v3.5.0) ─────────────────────────────────────
        // Load all repositories from combined file
        require_once RTOFLOW_DIR . 'app/Repositories/Repositories.php';
        $qb    = fn() => new \RTOFLOW\Database\QueryBuilder($GLOBALS['wpdb']);
        $cache = fn() => new \RTOFLOW\Support\Cache();
        $c->bind(\RTOFLOW\Repositories\LeadRepository::class,         fn($c) => new \RTOFLOW\Repositories\LeadRepository($qb(), $cache()));
        $c->bind(\RTOFLOW\Repositories\VendorRepository::class,       fn($c) => new \RTOFLOW\Repositories\VendorRepository($qb(), $cache()));
        $c->bind(\RTOFLOW\Repositories\PaymentRepository::class,      fn($c) => new \RTOFLOW\Repositories\PaymentRepository($qb()));
        $c->bind(\RTOFLOW\Repositories\DocumentRepository::class,     fn($c) => new \RTOFLOW\Repositories\DocumentRepository($qb()));
        $c->bind(\RTOFLOW\Repositories\FormRepository::class,         fn($c) => new \RTOFLOW\Repositories\FormRepository($qb()));
        $c->bind(\RTOFLOW\Repositories\GrievanceRepository::class,    fn($c) => new \RTOFLOW\Repositories\GrievanceRepository($qb()));
        $c->bind(\RTOFLOW\Repositories\NotificationRepository::class, fn($c) => new \RTOFLOW\Repositories\NotificationRepository($qb()));
        $c->bind(\RTOFLOW\Repositories\ReportRepository::class,       fn($c) => new \RTOFLOW\Repositories\ReportRepository($qb(), $cache()));

        // ── Extended Services ─────────────────────────────────────────────────
        // FIX P0-10: EventBus was previously created FRESH per-service via a
        // local `fn() => new EventBus()` closure called inline in each binding
        // below — meaning WorkflowService, VendorService, DocumentService,
        // GrievanceService, and AutomationService each held their OWN separate
        // EventBus instance with its own empty $listeners array. Pub/sub is
        // structurally impossible across services when every publisher and
        // every potential subscriber has a different bus: even if something
        // called EventBus::listen(), no other service's fire() could ever
        // reach it. Registering EventBus itself as a container singleton and
        // having every service pull the SAME instance via $c->make() is what
        // makes real pub/sub — and therefore AutomationService::handle()
        // actually receiving events — possible at all.
        $c->bind(\RTOFLOW\Support\EventBus::class, fn() => new \RTOFLOW\Support\EventBus());
        $c->bind(\RTOFLOW\Services\WorkflowService::class,    fn($c) => new \RTOFLOW\Services\WorkflowService($c->make(\RTOFLOW\Repositories\LeadRepository::class), $c->make(\RTOFLOW\Support\EventBus::class)));
        $c->bind(\RTOFLOW\Services\VendorService::class,      fn($c) => new \RTOFLOW\Services\VendorService($c->make(\RTOFLOW\Repositories\VendorRepository::class), $c->make(\RTOFLOW\Repositories\LeadRepository::class), $c->make(\RTOFLOW\Support\EventBus::class)));
        $c->bind(\RTOFLOW\Services\DocumentService::class,    fn($c) => new \RTOFLOW\Services\DocumentService($c->make(\RTOFLOW\Repositories\DocumentRepository::class), $c->make(\RTOFLOW\Support\EventBus::class)));
        $c->bind(\RTOFLOW\Services\GrievanceService::class,   fn($c) => new \RTOFLOW\Services\GrievanceService($c->make(\RTOFLOW\Repositories\GrievanceRepository::class), $c->make(\RTOFLOW\Support\EventBus::class)));
        $c->bind(\RTOFLOW\Services\AutomationService::class,  fn($c) => new \RTOFLOW\Services\AutomationService($c->make(\RTOFLOW\Repositories\LeadRepository::class), $c->make(NotificationService::class), $c->make(\RTOFLOW\Support\EventBus::class), $c->make(\RTOFLOW\Services\WorkflowEngineService::class)));

        // Controllers
        // ── Core controllers ──────────────────────────────────────────────────
        $c->bind(DashboardController::class, fn()   => new DashboardController());
        $c->bind(LeadsController::class,     fn($c) => new LeadsController($c->make(LeadService::class), $c->make(PaymentService::class), $c->make(\RTOFLOW\Services\FormEngineService::class)));
        $c->bind(VendorDashboard::class,     fn()   => new VendorDashboard());
        $c->bind(ClientDashboard::class,     fn($c) => new ClientDashboard($c->make(LeadService::class), $c->make(PaymentService::class)));

        // ── Admin controllers (all instantiate with no constructor args) ──────
        $c->bind(\RTOFLOW\Controllers\Admin\ServicesController::class,       fn() => new \RTOFLOW\Controllers\Admin\ServicesController());
        $c->bind(\RTOFLOW\Controllers\Admin\VendorsController::class,        fn() => new \RTOFLOW\Controllers\Admin\VendorsController());
        $c->bind(\RTOFLOW\Controllers\Admin\MastersController::class,        fn() => new \RTOFLOW\Controllers\Admin\MastersController());
        // BUGFIX (root cause of "Something went wrong loading this page" on
        // /rto-admin/hero-slider/ — Container::make() throws a RuntimeException
        // for any class never registered here via bind(), exactly the same
        // "unbound container class" failure mode documented in
        // ExceptionMonitor::onUncaughtException()'s own comment. This
        // controller was added to Router.php's routeAdmin()/dispatchAjax()
        // dispatch tables but never bound — every hero-slider request threw
        // immediately, before any of its own code ran.
        $c->bind(\RTOFLOW\Controllers\Admin\HeroSliderController::class,     fn() => new \RTOFLOW\Controllers\Admin\HeroSliderController());
        $c->bind(\RTOFLOW\Controllers\Admin\DesignSettingsController::class, fn() => new \RTOFLOW\Controllers\Admin\DesignSettingsController());
        $c->bind(\RTOFLOW\Controllers\Admin\PayoutsController::class,        fn($c) => new \RTOFLOW\Controllers\Admin\PayoutsController());
        $c->bind(\RTOFLOW\Controllers\Admin\ReportsController::class,        fn() => new \RTOFLOW\Controllers\Admin\ReportsController());
        $c->bind(\RTOFLOW\Controllers\Admin\SettingsController::class,       fn() => new \RTOFLOW\Controllers\Admin\SettingsController());
        $c->bind(\RTOFLOW\Controllers\Admin\EmailTemplateController::class,  fn() => new \RTOFLOW\Controllers\Admin\EmailTemplateController());
        $c->bind(\RTOFLOW\Controllers\Admin\ComplaintsController::class,     fn() => new \RTOFLOW\Controllers\Admin\ComplaintsController());
        $c->bind(\RTOFLOW\Controllers\Admin\RatingsController::class,        fn() => new \RTOFLOW\Controllers\Admin\RatingsController());
        $c->bind(\RTOFLOW\Controllers\Admin\EligibilityController::class,    fn() => new \RTOFLOW\Controllers\Admin\EligibilityController());
        // BUGFIX (found via a full make()-vs-bind() cross-reference across the whole
        // codebase, the same technique that caught the NotificationService and
        // CityServiceConfigController gaps above): HelpCenterController is resolved
        // via $container->make()/$c->make() in Router.php at the 'help' admin page
        // route (line ~1902) and the 'admin.help.search' AJAX action (line ~421),
        // but was never bound here -- meaning every visit to the Help Centre admin
        // screen, and every keystroke in its search box, would throw
        // "No binding for [RTOFLOW\Controllers\Admin\HelpCenterController]"
        // (RuntimeException) at runtime. A real, previously undetected production
        // fatal on a real, user-facing, frequently-used screen -- not hypothetical.
        $c->bind(\RTOFLOW\Controllers\Admin\HelpCenterController::class,     fn() => new \RTOFLOW\Controllers\Admin\HelpCenterController());
        // BUGFIX (found during Part 15-B webhook-consolidation self-audit): AutomationController
        // was built and wired into Router.php's dispatch table (admin.automation.create/update/
        // delete/toggle) but was never bound in the container, so those 4 live AJAX routes would
        // throw "No binding for [...]" (RuntimeException) at runtime -- a real, previously
        // undetected regression, not a hypothetical.
        $c->bind(\RTOFLOW\Controllers\Admin\AutomationController::class,     fn() => new \RTOFLOW\Controllers\Admin\AutomationController());
        // FIX P0 (live-environment fatal): CityServiceConfigController is
        // resolved via $container->make()/$c->make() in three places in
        // Router.php (the 'admin.save_city_service' AJAX action and the
        // city-pricing admin screen's index()/configure() routes) but had no
        // container binding at all — identical failure mode to the
        // NotificationService gap above (Container::make() throws
        // "No binding for [X]" with no auto-wiring fallback). Its
        // constructor takes no arguments, same as every other admin
        // controller bound in this block.
        $c->bind(\RTOFLOW\Controllers\Admin\CityServiceConfigController::class, fn() => new \RTOFLOW\Controllers\Admin\CityServiceConfigController());
        // LIVE-SITE FATAL FIX (blank white screen on /rto-admin/my-security/,
        // /rto-admin/audit-log/, /rto-admin/client-requests/, /rto-admin/
        // exceptions/ — reported after this build's 2FA rollout): identical
        // failure mode to every other "BUGFIX" comment in this block —
        // Container::make() has no auto-wiring fallback, so a class resolved
        // via $c->make()/$container->make() in Router.php but never bind()'d
        // here throws an uncaught RuntimeException("No binding for [...]").
        // ExceptionMonitor::register() (Bootstrap::init(), added this same
        // build) installs a global set_exception_handler() that catches
        // exactly that exception, logs it to rto_exception_log, and returns
        // — WordPress's default "there has been a critical error" screen
        // never runs because a handler IS registered, so the request simply
        // ends with zero bytes written to the response: a blank white page
        // instead of a visible fatal-error message, which is what made this
        // otherwise-identical bug class look different from the earlier
        // "BUGFIX" entries above (those pre-date ExceptionMonitor and would
        // have shown WordPress's normal fatal-error screen instead).
        // TwoFactorSettingsController::index() is the my-security page
        // itself — the mandatory-2FA redirect in Bootstrap.php's
        // template_redirect hook sends every un-enrolled rto_admin here,
        // so this single missing binding also explains "not able to enter
        // admin or some of the pages": any admin without 2FA enabled yet
        // gets redirected to this page for every other screen, and this
        // page itself was the one that fatally errored.
        $c->bind(\RTOFLOW\Controllers\Admin\TwoFactorSettingsController::class, fn() => new \RTOFLOW\Controllers\Admin\TwoFactorSettingsController());
        $c->bind(\RTOFLOW\Controllers\Admin\AuditLogController::class,          fn() => new \RTOFLOW\Controllers\Admin\AuditLogController());
        $c->bind(\RTOFLOW\Controllers\Admin\ClientRequestsController::class,    fn() => new \RTOFLOW\Controllers\Admin\ClientRequestsController());
        $c->bind(\RTOFLOW\Controllers\Admin\ExceptionsController::class,        fn() => new \RTOFLOW\Controllers\Admin\ExceptionsController());

        // ── Reusable-engine bindings added in the 10-workstream integration pass ──
        // NOTE: FormRepository is already bound above (line ~637) with its real
        // QueryBuilder dependency — do NOT rebind it here with a no-arg
        // constructor call, that would silently overwrite the working binding
        // (Container::bind() just overwrites the array entry for a class name;
        // whichever bind() call for the same class runs last wins) and break
        // FormRepository's actual constructor requirement.
        $c->bind(\RTOFLOW\Services\FormEngineService::class, fn($c) => new \RTOFLOW\Services\FormEngineService($c->make(\RTOFLOW\Repositories\FormRepository::class)));
        $c->bind(\RTOFLOW\Services\FormDraftService::class, fn($c) => new \RTOFLOW\Services\FormDraftService($c->make(\RTOFLOW\Services\FormEngineService::class)));
        $c->bind(\RTOFLOW\Services\FormFunnelService::class, fn($c) => new \RTOFLOW\Services\FormFunnelService());
        $c->bind(\RTOFLOW\Controllers\Admin\FormBuilderController::class, fn($c) => new \RTOFLOW\Controllers\Admin\FormBuilderController($c->make(\RTOFLOW\Services\FormEngineService::class), $c->make(\RTOFLOW\Services\FormFunnelService::class)));

        $c->bind(\RTOFLOW\Services\WorkflowEngineService::class, function ($c) {
            global $wpdb;
            return new \RTOFLOW\Services\WorkflowEngineService(
                $wpdb,
                $wpdb->prefix,
                $c->make(\RTOFLOW\Repositories\LeadRepository::class),
                $c->make(\RTOFLOW\Services\NotificationService::class)
            );
        });
        $c->bind(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class, fn() => new \RTOFLOW\Controllers\Admin\WorkflowBuilderController());

        $c->bind(\RTOFLOW\Repositories\VendorCoverageRepository::class, fn() => new \RTOFLOW\Repositories\VendorCoverageRepository());

        $c->bind(\RTOFLOW\Controllers\DocumentAccessController::class, fn($c) => new \RTOFLOW\Controllers\DocumentAccessController(
            $c->make(\RTOFLOW\Repositories\DocumentRepository::class),
            $c->make(\RTOFLOW\Repositories\LeadRepository::class),
            $c->make(\RTOFLOW\Repositories\VendorRepository::class)
        ));
        $c->bind(\RTOFLOW\Controllers\Admin\CustomizerHubController::class, fn() => new \RTOFLOW\Controllers\Admin\CustomizerHubController());

        $c->bind(\RTOFLOW\Services\ConfigVersionService::class, fn() => new \RTOFLOW\Services\ConfigVersionService());
        $c->bind(\RTOFLOW\Controllers\Admin\ConfigVersionController::class, fn() => new \RTOFLOW\Controllers\Admin\ConfigVersionController());

        // FIX P0-10: AutomationService::handle() and the real condition/action
        // engine behind it (rto_automation_rules, admin-configured at
        // resources/views/admin/automation/index.php) previously had zero
        // callers anywhere in the codebase — EventBus::listen() was never
        // called, so every rule an admin created was stored and displayed but
        // never executed. Every event this codebase actually fires (grepped
        // across all Services\*.php: lead.status_changed, lead.vendor_assigned/
        // accepted/rejected, document.uploaded/verified/rejected,
        // complaint.created, lead.sla_warning) is now subscribed to
        // AutomationService::handle() on the shared EventBus singleton above.
        // Admin-created automation rules run for real as of this fix.
        // FIX P0-11: 'automation_engine' is now an actual gate, not an inert
        // toggle — of 22 defined feature flags, this was one of 21 with no
        // runtime effect (only 'vendor_management' was ever checked anywhere).
        // Disabling it now genuinely stops every rule in rto_automation_rules
        // from executing, without needing to disable each rule individually.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('automation_engine')) {
            return $c;
        }

        $automation = $c->make(\RTOFLOW\Services\AutomationService::class);
        $bus        = $c->make(\RTOFLOW\Support\EventBus::class);
        foreach ([
            'lead.status_changed',
            'lead.vendor_assigned',
            'lead.vendor_accepted',
            'lead.vendor_rejected',
            'lead.sla_warning',
            'lead.enquiry_created',
            'lead.reassigned_on_suspension',
            'document.uploaded',
            'document.verified',
            'document.rejected',
            'complaint.created',
            'lead.created',
            'lead.sla_breach',
            'lead.completed',
            'payment.received',
        ] as $event) {
            $bus->listen($event, fn(array $payload) => $automation->handle($event, $payload));
        }

        // FIX (Automation screen audit): the Automation Engine's own admin view
        // (resources/views/admin/automation/index.php) advertises 10 "Available
        // System Events" an admin can build a rule against, including
        // lead.created, lead.sla_breach, payment.received, and lead.completed.
        // None of these 4 were ever actually EventBus::fire()'d anywhere in the
        // codebase — LeadService::create()/checkSlaBreach() and
        // PaymentService::record() only ever raised the separate WordPress
        // do_action() hooks used for notifications/cache-busting
        // ('rtoflow_lead_created', 'rtoflow_sla_breached',
        // 'rtoflow_payment_completed'), and WorkflowService::transition() never
        // distinguished a transition-to-'completed' from any other status
        // change. A rule an admin created against any of these 4 events was
        // therefore silently dead on arrival — stored, displayed as "Active",
        // run_count forever 0, and never executed. Rather than duplicate the
        // real event data by adding a second, parallel EventBus::fire() call
        // inside each service (which would need new constructor dependencies
        // in LeadService/PaymentService, both of which have exactly one bind
        // site and no EventBus today), the already-correct do_action() payloads
        // are bridged onto the same EventBus the automation engine listens on.
        //
        // IMPORTANT: EventBus::fire() itself calls
        // do_action('rtoflow_' . str_replace('.', '_', $event), $payload) so
        // external plugins can hook the same event — for 'lead.created' that
        // generates the exact same hook name ('rtoflow_lead_created') this
        // bridge is itself attached to. Calling $bus->fire('lead.created', ...)
        // from inside this callback would therefore re-trigger the same
        // do_action and recurse forever. The 'lead.created' bridge below calls
        // AutomationService::handle() directly, bypassing EventBus::fire()'s
        // own do_action re-broadcast, to avoid that self-recursion; the other
        // three bridges use distinct hook names and are unaffected.
        add_action('rtoflow_lead_created', function (int $leadId, int $clientId, array $service) use ($automation) {
            $automation->handle('lead.created', ['lead_id' => $leadId, 'client_id' => $clientId, 'service_id' => $service['id'] ?? null]);
        }, 20, 3);
        add_action('rtoflow_sla_breached', function (array $lead) use ($bus) {
            $bus->fire('lead.sla_breach', ['lead_id' => (int)($lead['id'] ?? 0)]);
        }, 20, 1);
        add_action('rtoflow_payment_completed', function (int $leadId, int $paymentId, $invoiceId = null) use ($bus) {
            $bus->fire('payment.received', ['lead_id' => $leadId, 'payment_id' => $paymentId]);
        }, 20, 3);
        add_action('rtoflow_lead_status_changed', function (int $leadId, string $from, string $to) use ($bus) {
            if ($to === 'completed') {
                $bus->fire('lead.completed', ['lead_id' => $leadId]);
            }
        }, 20, 4);

        return $c;
    }

    // ── Risk Scoring ────────────────────────────────────────────────────────
    private function calculateRiskScore(int $leadId, array $lead, array $service): void
    {
        // FIX (10-workstream integration pass, feature-flag wiring): 'ai_scoring'
        // had nothing gating it — this is its one real call site.
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('ai_scoring')) return;

        global $wpdb;
        $score = $this->computeRiskScore($lead, $service);
        $wpdb->update($wpdb->prefix . 'rto_leads', ['risk_score' => $score], ['id' => $leadId]);
    }

    // Known Limitations audit fix: the scorer "only scores a lead once, at
    // creation time, never re-scoring it afterward" — the formula itself
    // was never a limitation (it's an intentionally simple rules engine,
    // documented and kept as-is), but never being able to re-run it against
    // current data (a client's order history growing, a service's category
    // changing) was a real, closeable gap. Extracted the pure scoring math
    // out of calculateRiskScore() so both the original creation-time path
    // and this new bulk re-score path use the exact same formula — no
    // second, potentially-drifting copy of the rules.
    private function computeRiskScore(array $lead, array $service): int
    {
        global $wpdb;
        // Simple rule-based risk scoring (0–100)
        $score = 0;

        // New client = moderate risk
        $orderCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads WHERE client_id=%d AND deleted_at IS NULL",
            $lead['client_id']
        ));
        if ($orderCount <= 1)  $score += 20;  // First order
        if ($orderCount <= 0)  $score += 10;  // Brand new

        // High-value service = elevated risk
        if ((float)$lead['total_amount'] > 5000) $score += 20;
        if ((float)$lead['total_amount'] > 10000) $score += 10;

        // Service category risk
        $highRiskCategories = ['NOC', 'RC Services'];
        if (in_array($service['category'] ?? '', $highRiskCategories)) $score += 15;

        // SLA urgency
        if ((int)($service['sla_urgent_days'] ?? 0) > 0) $score += 10;

        return min(100, $score);
    }

    /**
     * Known Limitations audit fix: re-scores every non-deleted lead against
     * CURRENT data using the exact same rules computeRiskScore() applies at
     * creation time — closing the "never re-scoring it afterward" half of
     * the documented limitation. Admin-triggered (AI Insights screen), not
     * automatic, since re-scoring thousands of leads on every page load or
     * via an unattended cron would be its own performance/observability
     * concern this audit is not introducing silently.
     *
     * @return array{scored: int, skipped: int, flag_enabled: bool}
     */
    public function rescoreAllLeads(): array
    {
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('ai_scoring')) {
            return ['scored' => 0, 'skipped' => 0, 'flag_enabled' => false];
        }

        global $wpdb;
        $p = $wpdb->prefix;
        $leads = $wpdb->get_results(
            "SELECT l.id, l.client_id, l.total_amount, s.category, s.sla_urgent_days
             FROM {$p}rto_leads l
             LEFT JOIN {$p}rto_services s ON s.id = l.service_id
             WHERE l.deleted_at IS NULL",
            ARRAY_A
        ) ?: [];

        $scored = 0;
        $skipped = 0;
        foreach ($leads as $row) {
            $lead = ['client_id' => $row['client_id'], 'total_amount' => $row['total_amount']];
            $service = ['category' => $row['category'], 'sla_urgent_days' => $row['sla_urgent_days']];
            $score = $this->computeRiskScore($lead, $service);
            $updated = $wpdb->update($p . 'rto_leads', ['risk_score' => $score], ['id' => (int)$row['id']]);
            if ($updated === false) {
                $skipped++;
                error_log('[RTOFLOW] rescoreAllLeads: failed to update risk_score for lead ' . $row['id'] . ': ' . $wpdb->last_error);
            } else {
                $scored++;
            }
        }

        return ['scored' => $scored, 'skipped' => $skipped, 'flag_enabled' => true];
    }
}

/**
 * Minimal PSR-11-compatible service container.
 */
class Container
{
    private array $bindings  = [];
    private array $instances = [];

    public function bind(string $abstract, \Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
    }

    public function make(string $abstract): mixed
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        if (!isset($this->bindings[$abstract])) {
            throw new \RuntimeException("No binding for [{$abstract}]");
        }
        return $this->instances[$abstract] = ($this->bindings[$abstract])($this);
    }
}
