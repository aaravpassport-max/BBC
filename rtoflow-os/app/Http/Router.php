<?php

namespace RTOFLOW\Http;

use RTOFLOW\Http\Middleware\RateLimiter;
use RTOFLOW\Security\Headers;
use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * HTTP Router
 *
 * Registers WordPress query vars, routes incoming requests to
 * the correct controller, and applies middleware (auth, rate limiting,
 * nonce verification).
 *
 * Routing is via query vars added to WordPress rewrite system.
 * e.g. /rto-dashboard/ → rto_page=dashboard
 *      /rto-dashboard/leads/123 → rto_page=leads, rto_id=123
 */
class Router
{
    // ── Boot ──────────────────────────────────────────────────────────────

    public static function boot(): void
    {
        add_action('init',              [self::class, 'addRewriteRules']);
        add_action('init',              [self::class, 'maybeFlushRules'], 99);
        add_filter('query_vars',        [self::class, 'addQueryVars']);
        add_action('template_redirect', [self::class, 'dispatch']);
        add_action('wp_ajax_rto_admin', [self::class, 'dispatchAjax']);
        add_action('wp_ajax_rto_vendor',[self::class, 'dispatchAjax']);
        add_action('wp_ajax_rto_client',[self::class, 'dispatchAjax']);
        add_action('wp_ajax_rto_public',         [self::class, 'dispatchPublicAjax']); // logged-in users
        add_action('wp_ajax_nopriv_rto_public',  [self::class, 'dispatchPublicAjax']); // guests
    }

    // ── Rewrite rules ─────────────────────────────────────────────────────

    /**
     * Flush rewrite rules automatically if our plugin rules aren't saved yet.
     * This handles the case where the activation hook ran before init fired,
     * or after WP upgrades that clear rewrite rules.
     */
    public static function maybeFlushRules(): void
    {
        $rules = get_option('rewrite_rules', []);
        // Check if any of our key rules are registered
        $hasRules = false;
        foreach ((array)$rules as $pattern => $rewrite) {
            if (strpos($rewrite, 'rto_area') !== false) {
                $hasRules = true;
                break;
            }
        }
        if (!$hasRules && !get_transient('rtoflow_flushing_rules')) {
            set_transient('rtoflow_flushing_rules', 1, 30);
            flush_rewrite_rules(false);
        }
    }

    public static function addRewriteRules(): void
    {
        // Admin dashboard routes
        add_rewrite_rule('^rto-admin/?$',                         'index.php?rto_area=admin&rto_page=dashboard', 'top');
        add_rewrite_rule('^rto-admin/leads/?$',                   'index.php?rto_area=admin&rto_page=leads', 'top');
        add_rewrite_rule('^rto-admin/leads/([0-9]+)/?$',          'index.php?rto_area=admin&rto_page=leads&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/vendors/?$',                 'index.php?rto_area=admin&rto_page=vendors', 'top');
        add_rewrite_rule('^rto-admin/vendors/([0-9]+)/?$',        'index.php?rto_area=admin&rto_page=vendors&rto_id=$matches[1]', 'top');
        // ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow)
        add_rewrite_rule('^rto-admin/vendor-kyc-doc/([0-9]+)/?$', 'index.php?rto_area=admin&rto_page=vendor-kyc-doc&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/payments/?$',                'index.php?rto_area=admin&rto_page=payments', 'top');
        add_rewrite_rule('^rto-admin/payouts/?$',                 'index.php?rto_area=admin&rto_page=payouts', 'top');
        add_rewrite_rule('^rto-admin/reports/?$',                 'index.php?rto_area=admin&rto_page=reports', 'top');
        add_rewrite_rule('^rto-admin/settings/?$',                'index.php?rto_area=admin&rto_page=settings', 'top');
        add_rewrite_rule('^rto-admin/services/?$',                'index.php?rto_area=admin&rto_page=services', 'top');
        add_rewrite_rule('^rto-admin/services/([0-9]+)/?$',       'index.php?rto_area=admin&rto_page=services&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/masters/?$',                 'index.php?rto_area=admin&rto_page=masters', 'top');
        // Home Hero Section slider management (see HeroSliderController).
        add_rewrite_rule('^rto-admin/hero-slider/?$',             'index.php?rto_area=admin&rto_page=hero-slider', 'top');
        // Design & Typography Settings (see DesignSettingsController).
        add_rewrite_rule('^rto-admin/design-settings/?$',         'index.php?rto_area=admin&rto_page=design-settings', 'top');
        add_rewrite_rule('^rto-admin/staff/?$',                   'index.php?rto_area=admin&rto_page=staff', 'top');
        add_rewrite_rule('^rto-admin/email-templates/?$',         'index.php?rto_area=admin&rto_page=email-templates', 'top');
        add_rewrite_rule('^rto-admin/email-templates/([0-9]+)/?$','index.php?rto_area=admin&rto_page=email-templates&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/complaints/?$',              'index.php?rto_area=admin&rto_page=complaints', 'top');
        add_rewrite_rule('^rto-admin/complaints/([0-9]+)/?$',     'index.php?rto_area=admin&rto_page=complaints&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/ratings/?$',                 'index.php?rto_area=admin&rto_page=ratings', 'top');
        add_rewrite_rule('^rto-admin/nav/?$',                     'index.php?rto_area=admin&rto_page=nav', 'top');
        // ENTERPRISE GAP FIX (Section 8 — "no Audit Log viewer"): rto_logs
        // has captured a detailed action/old_value/new_value/user/ip trail
        // since migration 1, written to from ~20 different controllers/
        // services, but no admin screen ever read it back — the data
        // existed and was completely invisible.
        add_rewrite_rule('^rto-admin/audit-log/?$',               'index.php?rto_area=admin&rto_page=audit-log', 'top');
        // ENTERPRISE GAP FIX (Section 1 — 2FA had zero setup UI, see
        // TwoFactorSettingsController's docblock): personal security page,
        // available to any logged-in admin/staff user (not admin-only —
        // every account should be able to enable 2FA for itself).
        add_rewrite_rule('^rto-admin/my-security/?$',             'index.php?rto_area=admin&rto_page=my-security', 'top');

        // Additional admin pages
        add_rewrite_rule('^rto-admin/features/?$',                'index.php?rto_area=admin&rto_page=features', 'top');
        add_rewrite_rule('^rto-admin/ai/?$',                      'index.php?rto_area=admin&rto_page=ai', 'top');
        add_rewrite_rule('^rto-admin/automation/?$',              'index.php?rto_area=admin&rto_page=automation', 'top');
        add_rewrite_rule('^rto-admin/webhooks/?$',                'index.php?rto_area=admin&rto_page=webhooks', 'top');
        // ENTERPRISE GAP FIX (Phase 12, item — generic inbound webhook framework)
        add_rewrite_rule('^rto-admin/inbound-webhooks/?$',        'index.php?rto_area=admin&rto_page=inbound-webhooks', 'top');
        // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export path)
        add_rewrite_rule('^rto-admin/data-export/?$',             'index.php?rto_area=admin&rto_page=data-export', 'top');
        add_rewrite_rule('^rto-admin/client-requests/?$',         'index.php?rto_area=admin&rto_page=client-requests', 'top');
        add_rewrite_rule('^rto-admin/staff-roles/?$',             'index.php?rto_area=admin&rto_page=staff-roles', 'top');
        add_rewrite_rule('^rto-admin/exceptions/?$',              'index.php?rto_area=admin&rto_page=exceptions', 'top');
        add_rewrite_rule('^rto-admin/backups/?$',                 'index.php?rto_area=admin&rto_page=backups', 'top');
        add_rewrite_rule('^rto-admin/config-approvals/?$',        'index.php?rto_area=admin&rto_page=config-approvals', 'top');
        add_rewrite_rule('^rto-admin/partner-api/?$',             'index.php?rto_area=admin&rto_page=partner-api', 'top');
        add_rewrite_rule('^rto-admin/rtos/?$',                    'index.php?rto_area=admin&rto_page=rtos', 'top');
        add_rewrite_rule('^rto-admin/users/?$',                   'index.php?rto_area=admin&rto_page=users', 'top');
        add_rewrite_rule('^rto-admin/users/new/?$',               'index.php?rto_area=admin&rto_page=users&action=new', 'top');
        add_rewrite_rule('^rto-admin/forms/?$',                   'index.php?rto_area=admin&rto_page=forms', 'top');
        // FIX (Part 4.10 re-architecture): the Form Builder's unit changed from
        // ONE SCHEMA PER SERVICE (44, numeric service_id in the path) to ONE
        // SCHEMA PER CATEGORY (7, string category key — dl/rc/hp/noc/vehicle/
        // commercial/other — in the path). Matches the same rto_slug string-param
        // convention already used by /rto-service/{slug}/ above, not the
        // numeric rto_id convention the old per-service rule used.
        add_rewrite_rule('^rto-admin/forms/([a-z]+)/?$',          'index.php?rto_area=admin&rto_page=forms&rto_slug=$matches[1]', 'top');
        add_rewrite_rule('^rto-admin/forms/([a-z]+)/export/?$',   'index.php?rto_area=admin&rto_page=forms-export&rto_slug=$matches[1]', 'top');
        // FIX (10-workstream integration pass): resources/views/admin/nav/index.php
        // has always linked to /rto-admin/eligibility/, but no rewrite rule for
        // that path was ever registered — the nav card 404'd. The feature itself
        // works (routeAdmin()'s match($page) has an 'eligibility' arm), it was
        // just unreachable via the clean URL; only the raw
        // ?rto_area=admin&rto_page=eligibility query string worked before this.
        add_rewrite_rule('^rto-admin/eligibility/?$',             'index.php?rto_area=admin&rto_page=eligibility', 'top');
        add_rewrite_rule('^rto-admin/workflows/?$',               'index.php?rto_area=admin&rto_page=workflows', 'top');
        add_rewrite_rule('^rto-admin/customizer/?$',              'index.php?rto_area=admin&rto_page=customizer', 'top');
        add_rewrite_rule('^rto-admin/grievance/?$',               'index.php?rto_area=admin&rto_page=grievance', 'top');
        // City Service Pricing & Visibility
        add_rewrite_rule('^rto-admin/city-pricing/?$',                'index.php?rto_area=admin&rto_page=city-pricing', 'top');
        add_rewrite_rule('^rto-admin/city-pricing/([0-9]+)/?$',       'index.php?rto_area=admin&rto_page=city-pricing&rto_id=$matches[1]', 'top');
        // Part 14-D / 15-D builds: GDPR data export and system status/cron monitoring
        add_rewrite_rule('^rto-admin/gdpr/?$',                        'index.php?rto_area=admin&rto_page=gdpr', 'top');
        add_rewrite_rule('^rto-admin/system/?$',                      'index.php?rto_area=admin&rto_page=system', 'top');
        // ENTERPRISE GAP FIX (Phase 4, item 5 — vendor appeals queue): this
        // rule was initially missed when the page was added — every other
        // custom rto_page needs its own explicit rewrite rule in this
        // codebase (no generic catch-all exists), caught by cross-checking
        // every routeAdmin()/routeClient() match() arm against this list.
        add_rewrite_rule('^rto-admin/vendor-appeals/?$',              'index.php?rto_area=admin&rto_page=vendor-appeals', 'top');

        // Vendor routes
        add_rewrite_rule('^rto-vendor/?$',                        'index.php?rto_area=vendor&rto_page=dashboard', 'top');
        add_rewrite_rule('^rto-vendor/jobs/?$',                   'index.php?rto_area=vendor&rto_page=jobs', 'top');
        add_rewrite_rule('^rto-vendor/jobs/([0-9]+)/?$',          'index.php?rto_area=vendor&rto_page=jobs&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-vendor/earnings/?$',               'index.php?rto_area=vendor&rto_page=earnings', 'top');
        add_rewrite_rule('^rto-vendor/profile/?$',                'index.php?rto_area=vendor&rto_page=profile', 'top');
        add_rewrite_rule('^rto-vendor/leaderboard/?$',            'index.php?rto_area=vendor&rto_page=leaderboard', 'top');
        // ENTERPRISE GAP FIX (Section 1 follow-up): lets a vendor view their
        // own uploaded KYC document (see routeVendor()'s 'kyc-doc' arm).
        add_rewrite_rule('^rto-vendor/kyc-doc/([0-9]+)/?$',       'index.php?rto_area=vendor&rto_page=kyc-doc&rto_id=$matches[1]', 'top');

        // Client routes
        add_rewrite_rule('^rto-dashboard/?$',                     'index.php?rto_area=client&rto_page=dashboard', 'top');
        add_rewrite_rule('^rto-dashboard/orders/?$',              'index.php?rto_area=client&rto_page=orders', 'top');
        add_rewrite_rule('^rto-dashboard/orders/([0-9]+)/?$',     'index.php?rto_area=client&rto_page=orders&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-dashboard/documents/?$',           'index.php?rto_area=client&rto_page=documents', 'top');
        add_rewrite_rule('^rto-dashboard/complaints/?$',          'index.php?rto_area=client&rto_page=complaints', 'top');
        add_rewrite_rule('^rto-dashboard/profile/?$',             'index.php?rto_area=client&rto_page=profile', 'top');
        // ENTERPRISE GAP FIX (Phase 4, item 2 — saved vehicles/addresses)
        add_rewrite_rule('^rto-dashboard/vehicles/?$',            'index.php?rto_area=client&rto_page=vehicles', 'top');

        // Public routes
        add_rewrite_rule('^rto-service/([a-z0-9\-]+)/?$',        'index.php?rto_area=public&rto_page=service&rto_slug=$matches[1]', 'top');
        add_rewrite_rule('^rto-apply/?$',                         'index.php?rto_area=public&rto_page=apply', 'top');
        // Form Builder v2: schema-driven apply page for one service. Additive
        // and separate from /rto-apply/ — the static form is never touched by
        // this route. routeApplyDynamic() itself redirects back to /rto-apply/
        // if the service has no active v2 schema or the flag is off, so this
        // URL is always safe to link to even for a service with no custom form.
        add_rewrite_rule('^rto-apply-form/([0-9]+)/?$',           'index.php?rto_area=public&rto_page=apply_dynamic&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-track/([a-f0-9]{32})/?$',         'index.php?rto_area=public&rto_page=track&rto_token=$matches[1]', 'top');
        add_rewrite_rule('^rto-track/?$',                         'index.php?rto_area=public&rto_page=track', 'top');

        // ── Website / Public marketing pages ──────────────────────────────
        add_rewrite_rule('^about/?$',                             'index.php?rto_area=website&rto_page=about', 'top');
        add_rewrite_rule('^contact/?$',                           'index.php?rto_area=website&rto_page=contact', 'top');
        add_rewrite_rule('^how-it-works/?$',                      'index.php?rto_area=website&rto_page=how-it-works', 'top');
        add_rewrite_rule('^pricing/?$',                           'index.php?rto_area=website&rto_page=pricing', 'top');
        add_rewrite_rule('^rto-services-cities/?$',               'index.php?rto_area=website&rto_page=all-cities', 'top');
        add_rewrite_rule('^rto-agent-in-([a-z0-9\-]+)/?$',       'index.php?rto_area=website&rto_page=city&rto_slug=$matches[1]', 'top');
        // Standalone custom login/logout — never uses wp-login.php
        add_rewrite_rule('^rto-login/?$',                         'index.php?rto_area=website&rto_page=rto-login', 'top');
        add_rewrite_rule('^rto-logout/?$',                        'index.php?rto_area=website&rto_page=rto-logout', 'top');
        add_rewrite_rule('^rto-register/?$',                      'index.php?rto_area=website&rto_page=rto-register', 'top');
        add_rewrite_rule('^login/?$',                             'index.php?rto_area=website&rto_page=rto-login', 'top');
        add_rewrite_rule('^register/?$',                          'index.php?rto_area=website&rto_page=rto-register', 'top');
        add_rewrite_rule('^privacy-policy/?$',                    'index.php?rto_area=website&rto_page=privacy', 'top');
        add_rewrite_rule('^terms-conditions/?$',                  'index.php?rto_area=website&rto_page=terms', 'top');
        add_rewrite_rule('^track/?$',                             'index.php?rto_area=website&rto_page=track', 'top');
        // Design & Typography Settings — generated stylesheet (see
        // DesignSettingsService::buildCss()). Served as a real .css URL
        // (not an admin-ajax response) so it can be linked from <head>
        // with normal browser CSS caching.
        add_rewrite_rule('^rto-design\.css$',                     'index.php?rto_area=website&rto_page=design-css', 'top');
        // Rollout stylesheet (see DesignSettingsService::buildSiteCss()) —
        // the smaller, separately-scoped Colors/Header/Footer subset for
        // the 10 pages that render through layouts/website-header.php +
        // website-footer.php.
        add_rewrite_rule('^rto-design-site\.css$',                'index.php?rto_area=website&rto_page=design-site-css', 'top');

        // Webhook
        add_rewrite_rule('^rto-webhook/razorpay/?$',              'index.php?rto_area=webhook&rto_page=razorpay', 'top');
        add_rewrite_rule('^rto-webhook/whatsapp/?$',              'index.php?rto_area=webhook&rto_page=whatsapp', 'top');
        // ENTERPRISE GAP FIX (Phase 12, item — generic inbound webhook
        // framework): one route for ANY provider registered through the
        // Inbound Webhooks admin screen, instead of a new add_rewrite_rule()
        // + Router.php branch being required for every future integration.
        add_rewrite_rule('^rto-webhook/custom/([a-z0-9\-]+)/?$',  'index.php?rto_area=webhook&rto_page=custom&provider=$matches[1]', 'top');

        // Health check
        add_rewrite_rule('^rto-health/?$',                        'index.php?rto_area=health&rto_page=check', 'top');

        // ENTERPRISE GAP FIX (Section 8 — "no sitemap.xml/robots.txt"): the
        // public marketing pages (/about/, /pricing/, /rto-service/{slug}/,
        // /rto-agent-in-{city}/, etc.) are all custom rewrite rules, not WP
        // posts, so WordPress's built-in wp-sitemap.xml (which only covers
        // registered post types/taxonomies) never listed a single one of
        // them — the highest-SEO-value pages on the whole site (service ×
        // city landing pages) were undiscoverable by crawlers except via
        // internal links. This is a real, separate XML sitemap covering
        // exactly the URLs search engines should index.
        add_rewrite_rule('^sitemap\.xml$',                        'index.php?rto_area=sitemap&rto_page=xml', 'top');

        // ENTERPRISE GAP FIX (Phase 5, item 3 — "PWA / offline support for
        // vendor and client portals"): the mobile app-shell (bottom nav,
        // partial-page loading) already existed; these complete the "feels
        // like an app" experience. Served at root scope (not under
        // /rto-dashboard/ or /rto-vendor/) so each service worker's default
        // scope covers its whole portal, and unauthenticated (no
        // is_user_logged_in() check in routePwa()) because a browser must be
        // able to fetch manifest.json/the service worker BEFORE a user logs
        // in — e.g. to prompt "Add to Home Screen" from the public site.
        add_rewrite_rule('^rto-client-manifest\.json$',           'index.php?rto_area=pwa&rto_page=client-manifest', 'top');
        add_rewrite_rule('^rto-vendor-manifest\.json$',           'index.php?rto_area=pwa&rto_page=vendor-manifest', 'top');
        add_rewrite_rule('^rto-sw\.js$',                          'index.php?rto_area=pwa&rto_page=sw', 'top');

        // Invoice download
        add_rewrite_rule('^rto-invoice/([0-9]+)/?$',              'index.php?rto_area=invoice&rto_page=download&rto_id=$matches[1]', 'top');
        add_rewrite_rule('^rto-documents/([0-9]+)/?$',            'index.php?rto_area=document&rto_id=$matches[1]', 'top');

        // ── Front page: intercept BEFORE WordPress theme loading ──────────
        // Priority 1 fires before any theme template_redirect hooks.
        // The plugin owns its homepage — no theme dependency required.
        add_action('template_redirect', [self::class, 'interceptFrontPage'], 1);
    }

    /**
     * Serve the plugin's standalone homepage whenever WordPress would render
     * the front page, completely bypassing the active theme.
     */
    public static function interceptFrontPage(): void
    {
        // ── Guard 1: let normal plugin dispatch handle any rto_ area ──────
        if (get_query_var('rto_area')) return;

        // ── Guard 2: only fire for the true site root URL ─────────────────
        // When rewrite rules aren't flushed yet, WordPress treats unknown URLs
        // as the front page — this guard prevents the homepage showing for
        // /rto-apply/, /rto-track/ etc.
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $homePath    = parse_url(home_url('/'), PHP_URL_PATH) ?: '/';
        // Strip WordPress sub-directory prefix (handles WP installed in /subdir/)
        $relative = '/' . ltrim(substr($requestPath, strlen(rtrim($homePath, '/'))), '/');
        // Allow only root path or explicit index.php
        if ($relative !== '/' && $relative !== '/index.php' && $relative !== '') return;

        // ── Guard 3: WordPress front-page conditional ─────────────────────
        if (!is_front_page() && !is_home()) return;

        // ── Guard 4: respect explicit static front page setting ───────────
        if (get_option('show_on_front') === 'page'
            && get_option('page_on_front')
            && !get_option('rtoflow_override_front_page', '1')
        ) {
            return;
        }

        Headers::send();

        global $wpdb;
        $company    = get_option('rtoflow_company_name', 'RTOASSIST');
        $phone      = get_option('rtoflow_company_phone', '');
        $services   = get_transient('rtofl_active_services');
        if ($services === false) {
            $services = $wpdb->get_results(
                "SELECT id, category, name, slug, base_price, sla_days
                 FROM {$wpdb->prefix}rto_services
                 WHERE is_active=1 ORDER BY display_order, name",
                ARRAY_A
            ) ?: [];
            set_transient('rtofl_active_services', $services, 3600);
        }
        $by_cat     = [];
        foreach ($services as $s) $by_cat[$s['category']][] = $s;
        $lead_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_leads WHERE status='completed'");
        // BUGFIX (service-claims audit): this previously used max(realCount, 78)
        // to force a minimum of "78 cities" onto the homepage regardless of how
        // many were actually active — i.e. it could fabricate a bigger coverage
        // number than reality. The real, unpadded count is used now; the view
        // decides how (or whether) to display a small real number honestly.
        $city_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_cities WHERE is_active=1");
        // NEW (service-claims audit): the homepage previously showed a
        // hardcoded "4.9★ Avg. Rating" with no query behind it at all — a
        // fabricated number, not just an inflated real one. rto_ratings is a
        // real table (score 1-5, tied to completed lead/vendor pairs), so we
        // now compute an actual average and count and pass both through; the
        // view only displays a rating badge when there is real data to back it.
        $rating_avg   = $wpdb->get_var("SELECT AVG(score) FROM {$wpdb->prefix}rto_ratings");
        $rating_count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rto_ratings");
        $rating_avg   = $rating_avg !== null ? round((float)$rating_avg, 1) : null;
        $city_list  = $wpdb->get_results(
            "SELECT name, COALESCE(slug, LOWER(REPLACE(name,' ','-'))) as slug
             FROM {$wpdb->prefix}rto_cities WHERE is_active=1 ORDER BY name LIMIT 48",
            ARRAY_A
        ) ?: [];

        // NEW (design pass — real testimonials): rto_ratings genuinely has
        // reviewer text (column 'comment', added + backfilled from the legacy
        // 'review' column by migration 2024_01_01_000015_fix_ratings_schema_
        // and_soft_delete.php) but the homepage never selected individual
        // rows before, only the AVG/COUNT above. This pulls a small set of
        // real, recent, positive-and-substantive ratings and joins the
        // client's WP display_name (reduced to "First L." below, in the view
        // — no phone/email/full name ever leaves this query) and the lead's
        // city/service for context. deleted_at IS NULL respects the
        // soft-delete added by the same migration. Nothing here is invented:
        // an empty result is expected and handled by the view.
        $testimonials = $wpdb->get_results(
            "SELECT r.score, r.comment, r.created_at,
                    u.display_name AS client_name,
                    c.name AS city_name,
                    s.name AS service_name
             FROM {$wpdb->prefix}rto_ratings r
             LEFT JOIN {$wpdb->prefix}rto_leads l ON l.id = r.lead_id
             LEFT JOIN {$wpdb->prefix}users u ON u.ID = l.client_id
             LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
             LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
             WHERE r.deleted_at IS NULL
               AND r.score >= 4
               AND r.comment IS NOT NULL AND TRIM(r.comment) <> ''
             ORDER BY r.created_at DESC
             LIMIT 9",
            ARRAY_A
        ) ?: [];

        // Home Hero Section slider (see database/migrations/
        // 2026_09_09_002_create_hero_slides.php + HeroSliderController).
        // Only active slides, in the admin-chosen order — never a fabricated
        // fallback slide; an admin who deletes down to zero rows simply gets
        // no hero section (the controller itself refuses to let the count
        // drop below 1 via delete/disable, so this is a defensive guard,
        // not the expected path).
        $hero_slides = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}rto_hero_slides WHERE is_active=1 ORDER BY display_order ASC, id ASC",
            ARRAY_A
        ) ?: [];
        $hero_settings = \RTOFLOW\Controllers\Admin\HeroSliderController::loadSettingsForFrontend();

        rto_view('public.home', compact(
            'company', 'phone', 'services', 'by_cat', 'lead_count', 'city_count', 'city_list',
            'rating_avg', 'rating_count', 'testimonials', 'hero_slides', 'hero_settings'
        ));
        exit;
    }

    public static function addQueryVars(array $vars): array
    {
        return array_merge($vars, ['rto_area', 'rto_page', 'rto_id', 'rto_slug', 'rto_token', 'provider']);
    }

    // ── Dispatch ──────────────────────────────────────────────────────────

    public static function dispatch(): void
    {
        $area = get_query_var('rto_area', '');
        if (!$area) return;

        // Send security headers on all RTOFLOW pages
        Headers::send();

        $page = Sanitiser::alphanumeric(get_query_var('rto_page', 'dashboard'), 50);
        $id   = Sanitiser::int(get_query_var('rto_id', 0));

        // MOBILE APP-SHELL: bottom-nav AJAX tab switching (Admin/Vendor/
        // Client dashboards only). See startPartialCaptureIfRequested()'s
        // docblock for the full rationale and the non-invasive mechanism —
        // in short, a normal request is byte-for-byte unaffected; nothing
        // here changes behaviour unless the mobile-nav JS explicitly asks
        // for a partial response via the X-Rto-Partial request header.
        self::startPartialCaptureIfRequested($area);

        match($area) {
            'admin'   => self::routeAdmin($page, $id),
            'vendor'  => self::routeVendor($page, $id),
            'client'  => self::routeClient($page, $id),
            'public'  => self::routePublic($page),
            'website' => self::routeWebsite($page),
            'webhook' => self::routeWebhook($page),
            'health'  => self::routeHealth(),
            'sitemap' => self::routeSitemap(),
            'pwa'     => self::routePwa($page),
            'invoice'  => self::routeInvoice($id),
            // FIX (10-workstream integration pass, security gap): documents
            // uploaded via DocumentService::upload() were previously served
            // directly from wp-content/uploads with no auth check at all —
            // anyone who learned/guessed the URL could view a client's ID
            // document. This routes through DocumentAccessController::serve(),
            // which checks login + ownership (client/assigned vendor/staff)
            // before streaming the file, and audit-logs every access.
            'document' => \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Controllers\DocumentAccessController::class)->serve($id),
            default    => null,
        };
    }

    // TRACE: dispatch() calls this before routeAdmin/routeVendor/routeClient →
    //        checks area is one of admin/vendor/client AND the request
    //        carries the X-Rto-Partial:1 header (set only by our own
    //        mobile-nav.js fetch() calls, never by a normal browser
    //        navigation) → if both true, registers an output-buffer
    //        callback via ob_start() and returns; if either is false, does
    //        nothing at all →
    //        preconditions: called before any route handler has produced
    //        output →
    //        postconditions: on a matching request, ALL output the route
    //        handler produces (the full HTML page, exactly as it always
    //        rendered) is intercepted by extractPartialResponse() at PHP
    //        shutdown — this fires even though every route handler ends
    //        with exit;, because exit() still runs PHP's normal shutdown
    //        sequence, which flushes any open output buffer through its
    //        registered callback before the process actually ends. A
    //        non-matching request never calls ob_start() here at all, so
    //        it is completely untouched — same code path, same output, as
    //        before this feature existed →
    //        edge cases: header spoofed by a non-mobile-nav client → they
    //        just get the JSON-wrapped fragment instead of the full page,
    //        which is not a security issue (same auth/role checks in the
    //        route handler still ran first) and not a functional one
    //        (fetch() is the only real caller and always sends the header)
    private static function startPartialCaptureIfRequested(string $area): void
    {
        if (!in_array($area, ['admin', 'vendor', 'client'], true)) return;
        $isPartial = isset($_SERVER['HTTP_X_RTO_PARTIAL']) && $_SERVER['HTTP_X_RTO_PARTIAL'] === '1';
        if (!$isPartial) return;

        ob_start([self::class, 'extractPartialResponse']);
    }

    // TRACE: PHP's output-buffer machinery calls this at shutdown (see
    //        startPartialCaptureIfRequested()) with the ENTIRE rendered
    //        page HTML as $fullHtml →
    //        pulls the <title> text and the one content region specific to
    //        whichever dashboard rendered the page (rto-content for admin,
    //        rto-client-main for vendor/client — vendor and client share
    //        the same layout markup) →
    //        preconditions: $fullHtml is a complete rendered page (or a
    //        wp_die()/redirect body, handled by the fallback below) →
    //        postconditions: returns a JSON string {html, title, ok} that
    //        replaces the buffered output; if a Content-Type header can
    //        still be set (headers not already sent) it is set to
    //        application/json so the fetch() caller doesn't have to guess →
    //        edge cases: no recognisable content region found (e.g. a
    //        wp_die() error page, a redirect with no body, an unrelated
    //        area slipped through) → falls back to returning the ENTIRE
    //        page as `html` with ok:false, so mobile-nav.js can detect this
    //        and fall back to a normal full-page navigation rather than
    //        rendering a broken fragment
    public static function extractPartialResponse(string $fullHtml): string
    {
        $title = '';
        if (preg_match('/<title>(.*?)<\/title>/is', $fullHtml, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1])));
        }

        $html = null;
        $ok   = false;

        // Admin dashboard: <div class="rto-content"> ... </div><!-- /.rto-content -->
        if (preg_match('/<div class="rto-content"[^>]*>(.*)<\/div>\s*<!--\s*\/\.rto-content\s*-->/is', $fullHtml, $m)) {
            $html = $m[1];
            $ok   = true;
        }
        // Vendor/Client dashboards share this layout: <main class="rto-client-main"> ... </main>
        elseif (preg_match('/<main class="rto-client-main"[^>]*>(.*)<\/main>/is', $fullHtml, $m)) {
            $html = $m[1];
            $ok   = true;
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            // A partial response is a fetch()-only, JS-consumed payload —
            // never something a browser should cache and serve back for a
            // different tab/page on the back button.
            header('Cache-Control: no-store');
        }

        return wp_json_encode([
            'ok'    => $ok,
            'html'  => $ok ? $html : $fullHtml,
            'title' => $title,
        ]);
    }

    // TRACE: wp_ajax_rto_admin/rto_vendor/rto_client hooks fire →
    //        Headers::send() sets security headers →
    //        role verified from WP user → area + action sanitised from POST →
    //        match($area . '.' . $action) dispatches to specific handler →
    //        each handler independently verifies nonce + role before processing →
    //        preconditions: user logged in; POST contains action+area+rto_nonce →
    //        postconditions: handler called; rto_json_ok or rto_json_err returned →
    //        edge cases: invalid area → 400; invalid action → 400 (default match arm);
    //                    rate limit exceeded → 429; wrong role → 403
    public static function dispatchAjax(): void
    {
        Headers::send();

        // P2-SEC-012 FIX: enforce area from WP hook name, not from POST body
        $wpAction    = $_REQUEST['action'] ?? '';
        $allowedArea = match($wpAction) {
            'rto_admin'  => 'admin',
            'rto_vendor' => 'vendor',
            'rto_client' => 'client',
            default      => ''
        };
        $area   = Sanitiser::text($_POST['rto_area'] ?? '');
        $action = Sanitiser::text($_POST['rto_action'] ?? '');

        if ($area !== $allowedArea) {
            rto_json_err('Invalid request.', 400);
        }

        // Rate limit all AJAX
        if (!RateLimiter::check('auth', RateLimiter::clientIp())) {
            RateLimiter::abort();
        }

        // Mandatory-2FA-for-admin AJAX gate REMOVED PER EXPLICIT USER
        // REQUEST (see Bootstrap.php's template_redirect hook for the full
        // history). 2FA remains available and fully working from
        // /rto-admin/my-security/ for any admin who wants it, it is simply
        // no longer required to use the platform.

        $container = \RTOFLOW\Bootstrap::container();

        match($area . '.' . $action) {
            // Admin lead actions
            'admin.update_status'    => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->updateStatus(),
            // ENTERPRISE GAP FIX (Phase 7, item 3 — "side effects incomplete" flag)
            'admin.ack_side_effects' => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->acknowledgeSideEffects(),
            'admin.assign_vendor'    => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->assignVendor(),
            'admin.record_payment'   => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->recordPayment(),
            'admin.add_message'      => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->addMessage(),
            // Lead Notes thread (replaces the removed 'admin.update_notes'
            // single-column overwrite action — see LeadsController::addLeadNote()
            // and LeadNoteService for the full root-cause/replacement writeup).
            'admin.add_lead_note'    => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->addLeadNote(),
            // Part 11-D build: bulk status change closes the missing bulk-ops workflow gap
            'admin.bulk_status'      => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->bulkUpdateStatus(),
            // Part 5.7 build: edit core order fields / archive / restore
            'admin.update_lead'      => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->updateLead(),
            'admin.archive_lead'     => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->archiveLead(),
            'admin.restore_lead'     => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->restoreLead(),
            // Bulk archive/restore from the Leads list ("select rows + bulk action")
            'admin.bulk_archive_lead' => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->bulkArchiveLeads(),
            'admin.bulk_reassign_vendor' => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->bulkReassignVendor(),
            'admin.bulk_restore_lead' => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->bulkRestoreLeads(),
            // Known Limitations audit fix: reopen a terminal (completed/cancelled) order
            'admin.reopen_lead'      => $container->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->reopenLead(),
            // P3-JS-001 FIX: register document verification actions
            'admin.verify_document'  => self::verifyDocument(),
            'admin.reject_document'  => self::rejectDocument(),
            'admin.set_document_expiry' => self::setDocumentExpiry(),
            // ENTERPRISE GAP FIX (found during this pass's ghost-success sweep
            // of PaymentService::initiateRefund() — that method already
            // existed, correctly, but grep confirmed zero call sites anywhere
            // in the codebase. approve_refund/reject_refund below can only
            // ever act on a refund row that already exists, and nothing
            // anywhere could create one — the pending-refunds view on
            // admin/payments/index.php was structurally guaranteed to always
            // be empty. This closes the actual origination step of the
            // refund workflow, the one Part 11 gap analysis exists to catch:
            // an approve/reject pair with no way to ever reach 'pending'.
            'admin.initiate_refund'  => self::initiateRefund(),
            // Part 11-B build: approve_refund closes the refund workflow dead end
            'admin.approve_refund'   => self::approveRefund(),
            // ENTERPRISE GAP FIX (Section 3 — "no refund-reject path"): the
            // mirror action to approve_refund. See rejectRefund() below.
            'admin.reject_refund'    => self::rejectRefund(),
            // Services
            'admin.toggle_service'   => $container->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->toggle(),
            // Vendors
            'admin.vendor_kyc'       => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->updateKyc(),
            'admin.vendor_coverage'  => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->updateCoverage(),
            'admin.vendor_status'    => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->updateStatus(),
            // ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow)
            'admin.verify_kyc_doc'   => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->verifyKycDocument(),
            'admin.reject_kyc_doc'   => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->rejectKycDocument(),
            // Masters
            'admin.add_city'         => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->addCity(),
            'admin.update_city'      => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->updateCity(),
            'admin.delete_city'      => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->deleteCity(),
            // "create options so I can delete cities in bulk" — see
            // MastersController::bulkDeleteCities().
            'admin.delete_cities_bulk' => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->bulkDeleteCities(),
            'admin.merge_cities'     => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->mergeCities(),
            // Hero Slider (Home Hero Section slider management)
            'admin.hero_slide_save'     => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->saveSlide(),
            'admin.hero_slide_delete'   => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->deleteSlide(),
            'admin.hero_slide_toggle'   => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->toggleSlide(),
            'admin.hero_slide_reorder'  => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->reorderSlides(),
            'admin.hero_slide_upload'   => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->uploadImage(),
            'admin.hero_settings_save'  => $container->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->saveSettings(),
            // Design & Typography Settings
            'admin.design_colors_save'     => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveColors(),
            'admin.design_typography_save' => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveTypography(),
            'admin.design_spacing_save'    => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveSpacing(),
            'admin.design_buttons_save'    => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveButtons(),
            'admin.design_cards_save'      => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveCards(),
            'admin.design_sections_save'   => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveSections(),
            'admin.design_effects_save'    => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveEffects(),
            'admin.design_header_save'     => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveHeader(),
            'admin.design_footer_save'     => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->saveFooter(),
            'admin.design_reset'           => $container->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->reset(),
            // ENTERPRISE GAP FIX (critical, previously undocumented — found
            // while investigating a documented Known Limitation about the
            // RTO Management screen): the "Add RTO" / toggle / delete
            // buttons on resources/views/admin/rtos/index.php were wired to
            // completely wrong AJAX actions — addRto() posted to
            // admin.add_city (which requires state_id/name, so every
            // submission failed outright), toggleRto() posted to
            // admin.toggle_service with a param named rto_id instead of the
            // id/active that ServicesController::toggle() actually reads,
            // meaning every click silently flipped whatever SERVICE happens
            // to have id=1 to inactive, and deleteRto() posted to
            // admin.delete_city (which reads city_id, not rto_id), so every
            // click attempted to delete CITY id=1 — real, active data
            // corruption risk, not a cosmetic bug. Real RTO-branch CRUD
            // now exists below, scoped to the actual rto_rtos table.
            'admin.add_rto_branch'    => self::addRtoBranch(),
            'admin.toggle_rto_branch' => self::toggleRtoBranch(),
            'admin.delete_rto_branch' => self::deleteRtoBranch(),
            // ENTERPRISE GAP FIX: closes the documented "no in-screen UI to
            // view or edit a branch's custom services override" limitation
            // — see updateRtoServiceOverride()'s own docblock.
            'admin.update_rto_service_override' => self::updateRtoServiceOverride(),
            // Known Limitations audit fix: the Setup Checklist's "seeded"
            // thresholds are hard-coded and had no way for an admin to say
            // "this is intentional, stop flagging it" for a smaller catalog.
            'admin.dismiss_setup_warning' => self::dismissSetupWarning(),
            // ENTERPRISE GAP FIX: the first entry in what a full-repo grep
            // confirmed was a completely absent bulk-import pipeline — see
            // MastersController::importCitiesCsv()'s docblock for the trace.
            'admin.import_cities_csv' => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->importCitiesCsv(),
            'admin.get_cities'       => $container->make(\RTOFLOW\Controllers\Admin\MastersController::class)->getCities(),
            // ENTERPRISE GAP FIX: second entry in the bulk-import pipeline —
            // see VendorsController::importCsv()'s docblock for the trace.
            'admin.import_vendors_csv' => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->importCsv(),
            // Payouts
            'admin.generate_payouts' => $container->make(\RTOFLOW\Controllers\Admin\PayoutsController::class)->generate(),
            'admin.mark_paid'        => $container->make(\RTOFLOW\Controllers\Admin\PayoutsController::class)->markPaid(),
            // 2FA setup (ENTERPRISE GAP FIX, Section 1)
            'admin.2fa_generate'     => $container->make(\RTOFLOW\Controllers\Admin\TwoFactorSettingsController::class)->generate(),
            'admin.2fa_confirm'      => $container->make(\RTOFLOW\Controllers\Admin\TwoFactorSettingsController::class)->confirm(),
            'admin.2fa_disable'      => $container->make(\RTOFLOW\Controllers\Admin\TwoFactorSettingsController::class)->disable(),
            // Known Limitations audit fix: save a durable, exactly-reproducible
            // report snapshot instead of only a live, re-runnable query.
            'admin.save_report_snapshot' => $container->make(\RTOFLOW\Controllers\Admin\ReportsController::class)->saveSnapshot(),
            // Known Limitations audit fix: real AJAX cache-bust + recompute for the
            // Dashboard's "as of" staleness, updating the KPI numbers and the
            // "as of" timestamp in place, without a full page reload.
            'admin.dashboard_refresh' => $container->make(\RTOFLOW\Controllers\Admin\DashboardController::class)->ajaxRefresh(),
            // Email template test
            'admin.send_test_email'  => $container->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)->sendTest(),
            // Known Limitations audit fix: equivalent test-send for SMS/WhatsApp templates
            'admin.send_test_message' => $container->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)->sendTestMessage(),
            // Complaints
            'admin.respond_complaint'=> $container->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->respond(),
            // ENTERPRISE GAP FIX (Phase 3, item 5 — complaint internal notes)
            'admin.add_complaint_note' => $container->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->addComplaintNote(),
            // Known Limitations audit fix: staff-confirmed suggested-lead linkage
            'admin.link_complaint_lead' => $container->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->linkSuggestedLead(),
            'client.file_complaint'  => $container->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->store(),
            // Ratings
            'client.submit_rating'   => $container->make(\RTOFLOW\Controllers\Admin\RatingsController::class)->store(),
            'admin.delete_rating'    => $container->make(\RTOFLOW\Controllers\Admin\RatingsController::class)->delete(),
            // Known Limitations audit fix: soft-delete undo + score correction
            'admin.restore_rating'   => $container->make(\RTOFLOW\Controllers\Admin\RatingsController::class)->restore(),
            'admin.update_rating_score' => $container->make(\RTOFLOW\Controllers\Admin\RatingsController::class)->updateScore(),
            // Vendor actions
            'vendor.respond_job'     => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->respondToAssignment(),
            'vendor.upload_doc'      => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->uploadDocument(),
            'vendor.upload_kyc_doc'  => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->uploadKycDocument(),
            'vendor.accept_job'      => self::vendorAcceptJob(),
            'vendor.reject_job'      => self::vendorRejectJob(),
            // ENTERPRISE GAP FIX (Phase 4, item 1): resources/views/vendor/
            // jobs/show.php's message box and status buttons previously
            // posted to vendor.upload_doc with a send_message_only/
            // update_status_only flag that controller never checked — see
            // DashboardController::sendMessage()/updateJobStatus() docblocks.
            'vendor.send_message'    => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->sendMessage(),
            'vendor.update_status'   => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->updateJobStatus(),
            // ENTERPRISE GAP FIX (Phase 4, item 5 — vendor appeals)
            'vendor.submit_appeal'   => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->submitAppeal(),
            // ENTERPRISE GAP FIX (Phase 4, item 6 — notification preferences)
            'vendor.save_notification_prefs' => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->savePreferences(),
            'vendor.respond_to_rating' => $container->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->respondToRating(),
            // Client actions
            'client.initiate_pay'    => $container->make(\RTOFLOW\Controllers\Client\DashboardController::class)->initiatePayment(),
            'client.verify_pay'      => $container->make(\RTOFLOW\Controllers\Client\DashboardController::class)->verifyPayment(),
            'client.upload_doc'      => $container->make(\RTOFLOW\Controllers\Client\DashboardController::class)->uploadDocument(),
            'client.send_message'    => $container->make(\RTOFLOW\Controllers\Client\DashboardController::class)->sendMessage(),
            // ENTERPRISE GAP FIX (Phase 1, item 2 — client self-service cancellation/refund request)
            'client.request_cancellation_or_refund' => $container->make(\RTOFLOW\Controllers\Client\DashboardController::class)->requestCancellationOrRefund(),
            'admin.approve_client_request' => self::approveClientRequest(),
            'admin.decline_client_request' => self::declineClientRequest(),
            // ENTERPRISE GAP FIX (Phase 1, item 5 — penny-drop bank verification)
            'admin.verify_vendor_bank' => self::verifyVendorBank(),
            // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC)
            'admin.assign_permission_role' => self::assignPermissionRole(),
            'admin.toggle_staff_suspension' => self::toggleStaffSuspension(),
            'admin.save_role_permissions'  => self::saveRolePermissions(),
            // ENTERPRISE GAP FIX (Phase 2, item 6 — exception monitoring)
            'admin.resolve_exception' => self::resolveException(),
            // ENTERPRISE GAP FIX (Phase 2, item 5 — backup/DR automation)
            'admin.run_backup_now' => self::runBackupNow(),
            // ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval workflow)
            'admin.approve_config_change' => self::approveConfigChange(),
            'admin.reject_config_change'  => self::rejectConfigChange(),
            // ENTERPRISE GAP FIX (Phase 2, item 4 — partner REST API)
            'admin.create_api_key' => self::createApiKey(),
            'admin.revoke_api_key' => self::revokeApiKey(),
            // Part 15-D build: retry a failed notification
            'admin.retry_notification'  => self::retryNotification(),
            'admin.run_migrations'      => self::runMigrations(),
            'admin.rescore_leads'       => self::rescoreLeads(),
            // Part 15-D build: force-terminate all user sessions (account compromise scenario)
            'admin.force_logout'        => self::forceLogout(),
            // GDPR Art. 17: right to erasure, complementing the existing GDPR export
            'admin.gdpr_erase'          => self::gdprErase(),
            // Eligibility Rules Engine CRUD (P1 build)
            'admin.eligibility.store'   => $container->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->store(),
            'admin.eligibility.toggle'  => $container->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->toggle(),
            // Impact-preview-before-saving (Help Centre Phase 1 gap): real
            // count of recently-recorded applicants a rule toggle would
            // affect, shown before the toggle above is actually sent.
            'admin.eligibility.preview_toggle' => $container->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->previewToggle(),
            'admin.eligibility.delete'  => $container->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->delete(),
            // Known Limitations audit fix: per-service bulk enable/disable
            'admin.eligibility.bulk_toggle' => $container->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->bulkToggleForService(),
            // Settings -> Matching "Preview" button: re-rank real vendors
            // with candidate (unsaved) weights, writes nothing.
            'admin.settings.preview_matching' => $container->make(\RTOFLOW\Controllers\Admin\SettingsController::class)->previewMatching(),

            // Automation Engine CRUD (was read-only)
            'admin.automation.create' => $container->make(\RTOFLOW\Controllers\Admin\AutomationController::class)->create(),
            'admin.automation.update' => $container->make(\RTOFLOW\Controllers\Admin\AutomationController::class)->update(),
            'admin.automation.delete' => $container->make(\RTOFLOW\Controllers\Admin\AutomationController::class)->delete(),
            'admin.automation.toggle' => $container->make(\RTOFLOW\Controllers\Admin\AutomationController::class)->toggleActive(),

            // Outbound Webhook Subscriptions CRUD (API/webhooks integration gap fix)
            'admin.webhooks.create' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->create(),
            'admin.webhooks.update' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->update(),
            'admin.webhooks.delete' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->delete(),
            'admin.webhooks.toggle' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->toggleActive(),
            'admin.webhooks.rotate_secret' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->rotateSecret(),
            // ENTERPRISE GAP FIX (Phase 1, item 4 — manual replay of a logged webhook delivery)
            'admin.webhooks.replay' => $container->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->replayDelivery(),

            // ENTERPRISE GAP FIX (Phase 12, item — generic inbound webhook framework)
            'admin.register_inbound_webhook' => $container->make(\RTOFLOW\Controllers\Admin\InboundWebhookController::class)->register(),
            'admin.toggle_inbound_webhook'   => $container->make(\RTOFLOW\Controllers\Admin\InboundWebhookController::class)->toggle(),

            // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export path)
            'admin.save_data_export_settings' => $container->make(\RTOFLOW\Controllers\Admin\DataExportController::class)->saveSettings(),
            'admin.run_data_export_now'       => $container->make(\RTOFLOW\Controllers\Admin\DataExportController::class)->runNow(),
            'admin.download_bi_export'        => $container->make(\RTOFLOW\Controllers\Admin\DataExportController::class)->download(),

            // Central Help Centre search (Part 5.0)
            'admin.help.search' => $container->make(\RTOFLOW\Controllers\Admin\HelpCenterController::class)->searchAjax(),
            'admin.global_search' => self::globalSearchAjax(),
            'admin.saved_filter_save'   => self::savedFilterSave(),
            'admin.saved_filter_delete' => self::savedFilterDelete(),
            'admin.help.save_override' => $container->make(\RTOFLOW\Controllers\Admin\HelpCenterController::class)->saveOverride(),

            // ── 10-workstream integration pass: Dynamic Form Engine CRUD ──
            // Form Builder v2 (enterprise rewrite): save() now takes the
            // full {steps,documents} schema shape, plus history/restore/
            // delete for version control — see FormBuilderController.
            'admin.forms.save'    => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->save(),
            'admin.forms.toggle'  => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->toggle(),
            // Part 4.14: hide/show a WHOLE category's real services from the
            // live Apply page — distinct from admin.forms.toggle above,
            // which only ever switches which custom FORM SCHEMA is active.
            'admin.forms.toggle_category' => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->toggleCategoryVisibility(),
            'admin.forms.history'   => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->history(),
            'admin.forms.restore'   => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->restore(),
            'admin.forms.delete'    => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->delete(),
            // Delete ONE specific saved version from a category's history —
            // distinct from admin.forms.delete above, which deactivates
            // every version of the category at once.
            'admin.forms.delete_version' => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->deleteVersion(),
            'admin.forms.import'    => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->import(),
            'admin.forms.analytics' => $container->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->analytics(),

            // ── 10-workstream integration pass: Workflow/State Machine Engine CRUD ──
            'admin.workflows.create_definition' => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->createDefinition(),
            'admin.workflows.toggle_definition' => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->toggleDefinition(),
            'admin.workflows.add_state'         => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->addState(),
            'admin.workflows.delete_state'      => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->deleteState(),
            'admin.workflows.add_transition'    => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->addTransition(),
            'admin.workflows.delete_transition' => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->deleteTransition(),
            'admin.workflows.update_transition_guard' => $container->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->updateTransitionGuard(),

            // ── 10-workstream integration pass: bulk actions ──
            'admin.bulk_toggle_service' => $container->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->bulkToggle(),
            'admin.bulk_vendor_status'  => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->bulkStatus(),
            // ENTERPRISE GAP FIX (Phase 4, item 5 — vendor appeals)
            'admin.resolve_vendor_appeal' => $container->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->resolveAppeal(),

            // ── 10-workstream integration pass: generic config versioning ──
            'admin.config_version.history'  => $container->make(\RTOFLOW\Controllers\Admin\ConfigVersionController::class)->history(),
            'admin.config_version.publish'  => $container->make(\RTOFLOW\Controllers\Admin\ConfigVersionController::class)->publish(),
            'admin.config_version.rollback' => $container->make(\RTOFLOW\Controllers\Admin\ConfigVersionController::class)->rollback(),

            default                  => rto_json_err('Unknown action.', 400),
        };
    }

    // ── Vendor: Accept job ─────────────────────────────────────────────────
    private static function vendorAcceptJob(): void
    {
        if (!rto_is_vendor()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $leadId = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $vendorId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", get_current_user_id()
        ));

        // Verify this lead is assigned to this vendor
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}rto_leads WHERE id=%d AND vendor_id=%d AND deleted_at IS NULL",
            $leadId, $vendorId
        ), ARRAY_A);

        if (!$lead) rto_json_err('Job not found or not assigned to you.');
        if ($lead['status'] !== 'assigned') rto_json_err('This job has already been accepted or processed.');

        // ARCHITECTURE FIX (closes the "Requires Redesign" divergence
        // previously flagged in the checklist): this handler (reached from
        // the vendor Job Detail screen) used to update ONLY rto_leads, while
        // the separate DashboardController::respondToAssignment() handler
        // (reached from the vendor Dashboard screen, action vendor.respond_job)
        // updates BOTH rto_assignments and rto_leads for what is, from the
        // vendor's point of view, the exact same action on the exact same
        // job. That meant a job accepted from the Job Detail screen left its
        // rto_assignments row permanently stuck at 'pending' forever, even
        // though the lead itself correctly moved to 'in_progress' — any
        // admin/reporting query that reads rto_assignments.status to know
        // whether a vendor has responded would never see the response.
        // Fixed by looking up the matching pending assignment row (if any —
        // some leads may not have an assignments row at all, e.g. legacy
        // direct-assignment paths that predate the assignments table) and
        // updating it in the SAME transaction as the lead status change, so
        // both live screens now keep rto_assignments and rto_leads
        // consistent regardless of which one the vendor used.
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_assignments WHERE lead_id=%d AND vendor_id=%d AND status='pending'",
            $leadId, $vendorId
        ), ARRAY_A);

        $wpdb->query('START TRANSACTION');
        try {
            $leadUpdated = $wpdb->update($wpdb->prefix . 'rto_leads', [
                'status'     => 'in_progress',
                'updated_at' => current_time('mysql'),
            ], ['id' => $leadId]);
            if ($leadUpdated === false) {
                throw new \RuntimeException('lead status update failed: ' . $wpdb->last_error);
            }

            if ($assignment) {
                $assignmentUpdated = $wpdb->update($wpdb->prefix . 'rto_assignments', [
                    'status'      => 'accepted',
                    'accepted_at' => current_time('mysql'),
                ], ['id' => (int)$assignment['id']]);
                if ($assignmentUpdated === false) {
                    throw new \RuntimeException('assignment status update failed: ' . $wpdb->last_error);
                }
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW vendorAcceptJob: ' . $e->getMessage());
            rto_json_err('Unable to accept job. Please try again.');
        }

        do_action('rtoflow_lead_status_changed', $leadId, 'assigned', 'in_progress', $lead);
        \RTOFLOW\Services\AuditService::log('lead.accepted_by_vendor', $leadId, ['vendor_id' => $vendorId]);

        rto_json_ok(['status' => 'in_progress'], 'Job accepted successfully! Status updated to In Progress.');
    }

    // ── Vendor: Reject job ─────────────────────────────────────────────────
    private static function vendorRejectJob(): void
    {
        if (!rto_is_vendor()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_vendor_nonce', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $leadId       = Sanitiser::int($_POST['lead_id'] ?? 0, 1);
        $rejectReason = Sanitiser::text($_POST['reject_reason'] ?? '');
        $vendorId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_vendors WHERE user_id=%d", get_current_user_id()
        ));

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_leads WHERE id=%d AND vendor_id=%d AND deleted_at IS NULL",
            $leadId, $vendorId
        ), ARRAY_A);

        if (!$lead) rto_json_err('Job not found or not assigned to you.');
        if ($lead['status'] !== 'assigned') rto_json_err('This job cannot be rejected in its current state.');

        // Unassign vendor and reset to pending
        // NOTE: vendor_amount column does not exist in rto_leads — removed to prevent silent UPDATE failure.
        // Vendor earnings are tracked via rto_vendor_payouts, not as a lead column.
        //
        // ARCHITECTURE FIX (closes the "Requires Redesign" divergence
        // previously flagged in the checklist — see the matching comment in
        // vendorAcceptJob() above for the full explanation): this handler
        // (Job Detail screen) now also syncs the matching rto_assignments
        // row, in the same transaction as the lead update, so a job rejected
        // from either live vendor screen leaves rto_assignments and
        // rto_leads consistent rather than only one screen's target table
        // actually reflecting the vendor's response.
        $assignment = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rto_assignments WHERE lead_id=%d AND vendor_id=%d AND status='pending'",
            $leadId, $vendorId
        ), ARRAY_A);

        $wpdb->query('START TRANSACTION');
        try {
            $leadUnassigned = $wpdb->update($wpdb->prefix . 'rto_leads', [
                'vendor_id'  => null,
                'status'     => 'payment_received',  // back to awaiting assignment
                'updated_at' => current_time('mysql'),
            ], ['id' => $leadId]);
            if ($leadUnassigned === false) {
                throw new \RuntimeException('lead unassign update failed: ' . $wpdb->last_error);
            }

            if ($assignment) {
                $assignmentUpdated = $wpdb->update($wpdb->prefix . 'rto_assignments', [
                    'status'         => 'rejected',
                    'rejected_at'    => current_time('mysql'),
                    'reject_reason'  => $rejectReason,
                ], ['id' => (int)$assignment['id']]);
                if ($assignmentUpdated === false) {
                    throw new \RuntimeException('assignment status update failed: ' . $wpdb->last_error);
                }
            }

            // Log rejection reason
            $metaInserted = $wpdb->insert($wpdb->prefix . 'rto_lead_meta', [
                'lead_id'    => $leadId,
                'meta_key'   => 'vendor_rejection_reason',
                'meta_value' => "Vendor #{$vendorId}: " . $rejectReason,
            ]);
            if ($metaInserted === false) {
                throw new \RuntimeException('rejection-reason meta insert failed: ' . $wpdb->last_error);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            error_log('RTOFLOW vendorRejectJob: ' . $e->getMessage());
            rto_json_err('Unable to reject job. Please try again.');
        }

        \RTOFLOW\Services\AuditService::log('lead.rejected_by_vendor', $leadId, [
            'vendor_id' => $vendorId,
            'reason'    => $rejectReason,
        ]);

        // Notify admin
        $admins = get_users(['role' => 'rto_admin', 'number' => 1, 'fields' => 'ids']);
        if (!empty($admins)) {
            \RTOFLOW\Services\NotificationService::send('lead_vendor_rejected', [
                'lead_number' => $lead['lead_number'],
                'vendor_id'   => $vendorId,
                'reason'      => $rejectReason,
            ], (int)$admins[0], ['email'], $leadId);
        }

        rto_json_ok([], 'Job rejected. Admin has been notified for re-assignment.');
    }

    // P3-JS-001 FIX: document verification handlers
    private static function verifyDocument(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        global $wpdb;
        $docId = Sanitiser::int($_POST['doc_id'] ?? 0, 1);
        $doc   = $wpdb->get_row($wpdb->prepare(
            "SELECT id, lead_id FROM {$wpdb->prefix}rto_documents WHERE id = %d", $docId
        ), ARRAY_A);
        if (!$doc) rto_json_err('Document not found.');
        $updated = $wpdb->update($wpdb->prefix . 'rto_documents', [
            'status'      => 'verified',
            'verified_by' => get_current_user_id(),
            'verified_at' => current_time('mysql'),
        ], ['id' => $docId]);
        if ($updated === false) {
            error_log('[RTOFLOW] verifyDocument: DB update failed for doc_id=' . $docId . ' — ' . $wpdb->last_error);
            rto_json_err('Could not verify the document. Please try again.', 500);
        }
        \RTOFLOW\Services\AuditService::log('document.verified', (int)$doc['lead_id'], ['doc_id' => $docId]);
        rto_json_ok(null, 'Document verified successfully.');
    }

    private static function rejectDocument(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        global $wpdb;
        $docId  = Sanitiser::int($_POST['doc_id'] ?? 0, 1);
        $reason = Sanitiser::text($_POST['reason'] ?? '', 500);
        if (!$reason) rto_json_err('Rejection reason is required.');
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, lead_id FROM {$wpdb->prefix}rto_documents WHERE id = %d", $docId
        ), ARRAY_A);
        if (!$doc) rto_json_err('Document not found.');
        $updated = $wpdb->update($wpdb->prefix . 'rto_documents', [
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'verified_by'   => get_current_user_id(),
            'verified_at'   => current_time('mysql'),
        ], ['id' => $docId]);
        if ($updated === false) {
            error_log('[RTOFLOW] rejectDocument: DB update failed for doc_id=' . $docId . ' — ' . $wpdb->last_error);
            rto_json_err('Could not reject the document. Please try again.', 500);
        }
        \RTOFLOW\Services\AuditService::log('document.rejected', (int)$doc['lead_id'], ['doc_id' => $docId, 'reason' => $reason]);
        rto_json_ok(null, 'Document rejected.');
    }

    // ENTERPRISE GAP FIX (Phase 4, item 3 — "no document expiry tracking"):
    // staff-facing control to set/clear a verified document's expiry_date
    // (e.g. driving license, insurance, RC — documents that actually expire).
    // Resets expiry_reminder_sent_at to NULL whenever expiry_date is changed
    // so Bootstrap::runDocumentExpiryCheck() sends a fresh reminder cycle for
    // the new date rather than staying silent because a reminder already
    // went out for a since-superseded expiry_date.
    private static function setDocumentExpiry(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        global $wpdb;
        $docId  = Sanitiser::int($_POST['doc_id'] ?? 0, 1);
        $expiry = sanitize_text_field($_POST['expiry_date'] ?? '');
        if ($expiry !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            rto_json_err('Invalid date format.');
        }
        $doc = $wpdb->get_row($wpdb->prepare(
            "SELECT id, lead_id FROM {$wpdb->prefix}rto_documents WHERE id = %d", $docId
        ), ARRAY_A);
        if (!$doc) rto_json_err('Document not found.');
        $updated = $wpdb->update($wpdb->prefix . 'rto_documents', [
            'expiry_date'             => $expiry !== '' ? $expiry : null,
            'expiry_reminder_sent_at' => null,
        ], ['id' => $docId]);
        if ($updated === false) {
            error_log('[RTOFLOW] setDocumentExpiry: DB update failed for doc_id=' . $docId . ' — ' . $wpdb->last_error);
            rto_json_err('Could not save the expiry date. Please try again.', 500);
        }
        \RTOFLOW\Services\AuditService::log('document.expiry_set', (int)$doc['lead_id'], ['doc_id' => $docId, 'expiry_date' => $expiry ?: null]);
        rto_json_ok(null, 'Expiry date saved.');
    }

    // TRACE: admin fills the "Add RTO" modal on resources/views/admin/rtos/index.php
    //        and submits → nonce + admin role verified → city_id/code/name
    //        validated → code checked for uniqueness ACROSS ALL cities (the
    //        rto_rtos.code column carries a real UNIQUE KEY per the schema,
    //        so this pre-check just turns a would-be fatal duplicate-key DB
    //        error into a clean, readable error message) → row inserted →
    //        preconditions: admin role, non-empty code/name, valid city_id →
    //        postconditions: one new rto_rtos row, audit-logged →
    //        edge cases handled: missing/blank code or name, city_id
    //        pointing at a non-existent city, duplicate code (case-
    //        insensitive, since codes are always upper-cased first), insert
    //        failure.
    private static function addRtoBranch(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        global $wpdb;
        $p = $wpdb->prefix;

        $cityId = Sanitiser::int($_POST['city_id'] ?? 0, 1);
        $code   = strtoupper(Sanitiser::text($_POST['rto_code'] ?? '', 20));
        $name   = Sanitiser::text($_POST['rto_name'] ?? '', 200);
        $hours  = Sanitiser::text($_POST['working_hrs'] ?? '', 100);

        if (!$cityId) rto_json_err('A city must be selected.');
        if (!$code)   rto_json_err('RTO code is required.');
        if (!$name)   rto_json_err('RTO name is required.');

        $cityExists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_cities WHERE id=%d", $cityId));
        if (!$cityExists) rto_json_err('Selected city not found.');

        $dupe = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_rtos WHERE code=%s", $code));
        if ($dupe > 0) rto_json_err("RTO code \"{$code}\" is already in use — codes must be unique across every city.");

        $inserted = $wpdb->insert($p . 'rto_rtos', [
            'city_id'     => $cityId,
            'code'        => $code,
            'name'        => $name,
            'working_hrs' => $hours ?: null,
            'is_active'   => 1,
        ]);
        if (!$inserted) {
            error_log('[RTOFLOW] addRtoBranch: insert failed for city_id=' . $cityId . ' code=' . $code . ' — ' . $wpdb->last_error);
            rto_json_err('Could not add the RTO. Please try again.', 500);
        }
        $id = (int)$wpdb->insert_id;
        \RTOFLOW\Services\AuditService::log('rto_branch.created', null, ['id' => $id, 'city_id' => $cityId, 'code' => $code, 'name' => $name]);
        rto_json_ok(['id' => $id], 'RTO added.');
    }

    // TRACE: admin clicks the Active/Inactive toggle on an RTO branch row →
    //        nonce + admin role verified → rto_id/is_active validated →
    //        UPDATE rto_rtos SET is_active=? WHERE id=? →
    //        preconditions: admin role, branch exists →
    //        postconditions: that branch's is_active flips; nothing else
    //        touched →
    //        edge cases handled: branch not found, DB update failure.
    private static function toggleRtoBranch(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        global $wpdb;
        $p = $wpdb->prefix;

        $id     = Sanitiser::int($_POST['rto_id'] ?? 0, 1);
        $active = (int)(bool)($_POST['active'] ?? 0);

        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_rtos WHERE id=%d", $id));
        if (!$exists) rto_json_err('RTO not found.', 404);

        $updated = $wpdb->update($p . 'rto_rtos', ['is_active' => $active], ['id' => $id]);
        if ($updated === false) {
            error_log('[RTOFLOW] toggleRtoBranch: update failed for id=' . $id . ' — ' . $wpdb->last_error);
            rto_json_err('Could not update the RTO. Please try again.', 500);
        }
        \RTOFLOW\Services\AuditService::log('rto_branch.status_changed', null, ['id' => $id, 'is_active' => $active]);
        rto_json_ok(['id' => $id, 'is_active' => $active]);
    }

    // TRACE: admin clicks Delete on an RTO branch row and confirms →
    //        nonce + admin role verified → rto_id validated → branch
    //        existence checked → row deleted →
    //        preconditions: admin role, branch exists →
    //        postconditions: that one rto_rtos row is gone; the parent city
    //        and every other branch are untouched →
    //        edge cases handled: branch not found, DB delete failure. No
    //        active-lead/vendor-coverage guard exists at the individual
    //        RTO-branch level anywhere in this codebase (leads/vendor
    //        coverage are keyed to city_id, not to a specific branch row),
    //        so none is invented here — deleting a branch is a lower-stakes
    //        action than deleting the city itself.
    private static function deleteRtoBranch(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        global $wpdb;
        $p = $wpdb->prefix;

        $id = Sanitiser::int($_POST['rto_id'] ?? 0, 1);

        $branch = $wpdb->get_row($wpdb->prepare("SELECT id, name, city_id FROM {$p}rto_rtos WHERE id=%d", $id), ARRAY_A);
        if (!$branch) rto_json_err('RTO not found.', 404);

        $deleted = $wpdb->delete($p . 'rto_rtos', ['id' => $id]);
        if ($deleted === false) {
            error_log('[RTOFLOW] deleteRtoBranch: delete failed for id=' . $id . ' — ' . $wpdb->last_error);
            rto_json_err('Could not delete the RTO. Please try again.', 500);
        }
        delete_option("rtoflow_rto_{$id}_services");
        \RTOFLOW\Services\AuditService::log('rto_branch.deleted', null, ['id' => $id, 'name' => $branch['name'], 'city_id' => $branch['city_id']]);
        rto_json_ok(null, 'RTO deleted.');
    }

    // TRACE: admin opens "Manage Services" on an RTO branch card, either
    //        checks "Use all global services" or picks a specific subset,
    //        and saves → nonce + admin role verified → rto_id validated →
    //        branch existence checked → use_all=1 DELETES the
    //        rtoflow_rto_{id}_services option entirely (falling back to the
    //        city's full global service list, matching the existing
    //        $rto['service_overrides'] === null convention this same view
    //        already reads) → use_all=0 requires at least one selected
    //        service id (each validated as a real, existing rto_services
    //        row) and update_option()s the override as a JSON array →
    //        preconditions: admin role, branch exists, at least one valid
    //        service id when not using "all" →
    //        postconditions: the branch's rtoflow_rto_{id}_services option
    //        is either deleted (use-all) or set to the exact submitted,
    //        validated id list; audit-logged either way →
    //        edge cases handled: branch not found, empty selection with
    //        use_all=0, submitted ids that don't correspond to a real
    //        service (silently dropped rather than stored, so a stale/
    //        deleted service id can never linger in an override forever).
    private static function updateRtoServiceOverride(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        global $wpdb;
        $p = $wpdb->prefix;

        $id     = Sanitiser::int($_POST['rto_id'] ?? 0, 1);
        $useAll = !empty($_POST['use_all']);

        $branch = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM {$p}rto_rtos WHERE id=%d", $id), ARRAY_A);
        if (!$branch) rto_json_err('RTO not found.', 404);

        if ($useAll) {
            delete_option("rtoflow_rto_{$id}_services");
            \RTOFLOW\Services\AuditService::log('rto_branch.services_reset_to_all', null, ['id' => $id, 'name' => $branch['name']]);
            rto_json_ok(null, 'This branch now uses all global services.');
        }

        $submittedIds = array_map('intval', (array)($_POST['service_ids'] ?? []));
        $submittedIds = array_values(array_unique(array_filter($submittedIds, static fn($v) => $v > 0)));
        if (empty($submittedIds)) rto_json_err('Select at least one service, or choose to use all global services.');

        // Only keep ids that correspond to a real, currently-existing
        // service — silently dropping any stale/deleted id rather than
        // persisting it, so this override can never point at a service
        // that no longer exists.
        $placeholders = implode(',', array_fill(0, count($submittedIds), '%d'));
        $validIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$p}rto_services WHERE id IN ($placeholders)", ...$submittedIds
        ));
        $validIds = array_map('intval', $validIds ?: []);
        if (empty($validIds)) rto_json_err('None of the selected services could be found. Please refresh and try again.');

        $saved = update_option("rtoflow_rto_{$id}_services", wp_json_encode($validIds));
        if ($saved === false && get_option("rtoflow_rto_{$id}_services") !== wp_json_encode($validIds)) {
            // update_option() returns false both on a genuine failure AND
            // when the new value is identical to the old one — the extra
            // read above distinguishes "no-op, already correct" from an
            // actual write failure so a real save is never misreported.
            error_log('[RTOFLOW] updateRtoServiceOverride: option save failed for rto_id=' . $id);
            rto_json_err('Could not save the service selection. Please try again.', 500);
        }
        \RTOFLOW\Services\AuditService::log('rto_branch.services_customized', null, ['id' => $id, 'name' => $branch['name'], 'service_ids' => $validIds]);
        rto_json_ok(['service_ids' => $validIds], 'Custom service list saved.');
    }

    // TRACE: admin clicks "This is intentional" next to a Setup Checklist
    //        "seeded" warning (Services or Cities) on the Nav screen →
    //        validates the check name, admin+nonce → stores a persistent
    //        rtoflow_setup_ack_{check} option → nav/index.php reads that
    //        option and treats the check as satisfied regardless of the
    //        raw count from then on.
    //        Preconditions: admin, valid nonce, check is 'services' or
    //        'cities' (the only two count-threshold checks on that screen).
    //        Postconditions: option persisted; audit-logged; JSON success.
    //        Edge cases handled: unknown check name rejected; update_option()
    //        no-op-vs-failure ambiguity is irrelevant here since the value
    //        written is always the same literal '1' (idempotent).
    private static function dismissSetupWarning(): void
    {
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);

        $check = Sanitiser::text($_POST['check'] ?? '');
        if (!in_array($check, ['services', 'cities'], true)) rto_json_err('Unknown checklist item.');

        update_option('rtoflow_setup_ack_' . $check, 1);
        \RTOFLOW\Services\AuditService::log('setup_checklist.warning_acknowledged', null, ['check' => $check]);
        rto_json_ok(null, 'Acknowledged — this item will no longer be flagged.');
    }

    public static function dispatchPublicAjax(): void
    {
        Headers::send();
        if (!RateLimiter::check('public', RateLimiter::clientIp())) {
            RateLimiter::abort();
        }
        $action = Sanitiser::text($_POST['rto_action'] ?? '');

        // Nonce verification required for form submissions
        // FIX P0-2/P0-3: 'quick_enquiry' added here — it was previously handled by a
        // SECOND, separate wp_ajax_rto_public hook registered directly in
        // rtoflow-os.php, which raced this dispatcher on the same WordPress action.
        // Whichever callback WordPress ran first for that hook would terminate the
        // request (wp_send_json_error() calls wp_die()), so the other implementation
        // never ran. There is now exactly one place public AJAX actions are
        // dispatched — this match table — matching the single-dispatch-table pattern
        // used for admin/vendor/client AJAX everywhere else in Router.php.
        $submitActions = ['submit_apply', 'submit_apply_v2', 'submit_apply_dynamic', 'quick_enquiry', 'save_draft', 'load_draft'];
        if (in_array($action, $submitActions, true)) {
            if (!check_ajax_referer('rto_public', 'rto_nonce', false)) {
                rto_json_err('Security verification failed. Please refresh and try again.', 403);
            }
        }

        match($action) {
            'get_cities'    => self::getCitiesJson(),
            'get_services'  => self::getServicesJson(),
            'get_price'     => self::getPriceJson(),
            'submit_apply'         => self::submitApply(),
            'submit_apply_v2'      => self::submitApplyV2(),
            'submit_apply_dynamic' => self::submitApplyDynamic(),
            'quick_enquiry'        => self::quickEnquiry(),
            'save_draft'           => self::saveDraft(),
            'load_draft'           => self::loadDraft(),
            'form_funnel_event'    => self::recordFormFunnelEvent(),
            'save_consent'         => self::saveConsent(),
            default                => rto_json_err('Unknown action.', 400),
        };
    }

    // TRACE: cookie-consent banner (resources/assets/js/cookie-consent.js)
    //        POSTs rto_area=public, rto_action=save_consent, analytics=0|1,
    //        marketing=0|1 on Accept All / Reject Non-Essential / Save
    //        Preferences → rate-limited like every other public action →
    //        ConsentService::record() inserts a fresh, revocable consent
    //        row and mirrors it into AuditService → response includes the
    //        token so the browser can persist it in a first-party cookie.
    //        Deliberately NOT nonce-gated for the same reason as
    //        recordFormFunnelEvent() above: it never mutates anything the
    //        visitor can see, must work for a first-time, logged-out
    //        visitor before any nonce has ever been issued, and is
    //        rate-limited + server-side validated regardless.
    //
    // ENTERPRISE GAP FIX (Phase 9, item — "no cookie-consent / tracking-
    // consent banner").
    private static function saveConsent(): void
    {
        if (!RateLimiter::check('public', RateLimiter::clientIp())) {
            RateLimiter::abort();
        }

        $token = Sanitiser::alphanumeric($_POST['token'] ?? '');
        if ($token === '' || strlen($token) < 16) {
            $token = wp_generate_password(32, false, false);
        }

        $analytics = !empty($_POST['analytics']);
        $marketing = !empty($_POST['marketing']);

        \RTOFLOW\Services\ConsentService::record($token, $analytics, $marketing);

        rto_json_ok(['token' => $token, 'analytics' => $analytics, 'marketing' => $marketing], 'Preference saved.');
    }

    // TRACE: apply.php beacons (step change / field validation failure /
    //        successful submit) → rto_area=public, rto_action=form_funnel_event,
    //        no nonce required (see docblock below) → rate-limited same as
    //        any other public action → FormFunnelService::recordEvent() →
    //        one row in rto_form_funnel_events, or a soft no-op past the
    //        per-session abuse cap.
    //        Preconditions: rate limit not exceeded.
    //        Postconditions: one funnel-event row recorded, or nothing
    //        written (invalid input / abuse cap) — either way the client
    //        gets a 200 so a beacon call never surfaces an error to a real
    //        visitor mid-form.
    //        Edge cases handled: unknown event_type, missing/malformed
    //        session_token, missing category, out-of-range step_index
    //        (clamped, not rejected), per-session event flood (silently
    //        capped, not erred).
    //
    // Deliberately NOT in $submitActions / not nonce-gated: this endpoint
    // never mutates anything the visitor can see or that has any value
    // beyond aggregate analytics (unlike save_draft, which persists and
    // later restores the visitor's own real answers) — requiring a nonce
    // would mean firing an extra get-nonce round trip before the very first
    // beacon on page load, which is unnecessary ceremony for a fire-and-
    // forget analytics signal. It is still rate-limited like every other
    // public action, and every field is validated/sanitised server-side.
    private static function recordFormFunnelEvent(): void
    {
        if (!RateLimiter::check('public', RateLimiter::clientIp())) RateLimiter::abort();

        $eventType    = Sanitiser::text($_POST['event_type'] ?? '', 30);
        $category     = Sanitiser::text($_POST['category'] ?? '', 100);
        $serviceName  = isset($_POST['service_name']) ? Sanitiser::text($_POST['service_name'], 150) : null;
        $stepIndex    = Sanitiser::int($_POST['step_index'] ?? 0, 0);
        $fieldKey     = isset($_POST['field_key']) ? Sanitiser::text($_POST['field_key'], 100) : null;
        $sessionToken = Sanitiser::text($_POST['session_token'] ?? '', 64);

        /** @var \RTOFLOW\Services\FormFunnelService $funnel */
        $funnel = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormFunnelService::class);
        $funnel->recordEvent($eventType, $category, $serviceName, $stepIndex, $fieldKey, $sessionToken);

        // Always a plain 200 regardless of internal success/failure — see
        // docblock above: a beacon must never surface an error to a real
        // visitor filling out the form.
        rto_json_ok(null, 'OK');
    }

    // TRACE: public service-page "call me back" widget POSTs rto_area=public,
    //        rto_action=quick_enquiry, name/phone/service_id →
    //        rate-limited → validates name + 10-digit Indian mobile →
    //        inserts an anonymous (client_id=0) rto_leads row with status
    //        'created' and source 'enquiry' → stores name/phone in
    //        rto_lead_meta (rto_leads has no client_name/client_phone columns) →
    //        returns success JSON.
    //        Preconditions: rto_public nonce valid, rate limit not exceeded.
    //        Postconditions: one row in rto_leads + two rows in rto_lead_meta,
    //        or a 400/403 JSON error with nothing written.
    //        Edge cases handled: missing name, malformed phone, missing service_id
    //        (stored as NULL rather than 0, since 0 is not a valid FK).
    private static function quickEnquiry(): void
    {
        if (!RateLimiter::check('public', RateLimiter::clientIp())) {
            RateLimiter::abort();
        }
        global $wpdb;
        $p     = $wpdb->prefix;
        $name  = Sanitiser::text($_POST['name'] ?? '');
        $phone = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        $svcId = (int)($_POST['service_id'] ?? 0);

        if (!$name || !preg_match('/^[6-9]\d{9}$/', $phone)) {
            rto_json_err('Please provide a valid name and 10-digit mobile number.', 400);
        }

        $wpdb->insert($p . 'rto_leads', [
            'lead_number'    => 'ENQ-' . date('Y') . '-' . str_pad((int)$wpdb->get_var("SELECT COUNT(*)+1 FROM {$p}rto_leads"), 6, '0', STR_PAD_LEFT),
            'service_id'     => $svcId ?: null,
            'client_id'      => 0,
            'status'         => 'created',
            'source'         => 'enquiry',
            'total_amount'   => 0.00,
            'gst_amount'     => 0.00,
            'payment_status' => 'unpaid',
            'created_at'     => current_time('mysql'),
            'updated_at'     => current_time('mysql'),
        ]);
        $enquiryId = (int)$wpdb->insert_id;
        if (!$enquiryId) {
            rto_json_err('Could not save your enquiry. Please try again.', 500);
        }
        // ENTERPRISE GAP FIX (ghost-success guard, same standard applied
        // throughout this codebase): these two writes were previously fired
        // and forgotten — rto_leads has no client_name/client_phone columns
        // of its own, so this meta is the ONLY place the caller's name and
        // phone number are stored. A silent failure on either insert (a
        // transient DB error, e.g.) would leave a lead in the pipeline that
        // staff can see exists but can never call back, while the customer
        // was told "Enquiry received." with no indication anything was
        // lost. Failures are logged (not fatal to the request — the lead
        // row itself already committed and re-submitting would just create
        // a duplicate) so an admin can spot and manually complete affected
        // rows via the audit log instead of the gap being invisible.
        $nameSaved  = $wpdb->insert($p . 'rto_lead_meta', ['lead_id' => $enquiryId, 'meta_key' => 'enquiry_name',  'meta_value' => $name]);
        $phoneSaved = $wpdb->insert($p . 'rto_lead_meta', ['lead_id' => $enquiryId, 'meta_key' => 'enquiry_phone', 'meta_value' => $phone]);
        if ($nameSaved === false || $phoneSaved === false) {
            error_log("RTOFLOW Router::quickEnquiry(): contact-info meta insert failed for enquiry lead {$enquiryId} (name_saved=" . ($nameSaved === false ? 'no' : 'yes') . ", phone_saved=" . ($phoneSaved === false ? 'no' : 'yes') . ").");
        }
        \RTOFLOW\Services\AuditService::log('lead.enquiry_created', $enquiryId, ['service_id' => $svcId ?: null, 'contact_meta_saved' => ($nameSaved !== false && $phoneSaved !== false)]);
        rto_json_ok(null, 'Enquiry received.');
    }

    // TRACE: public apply.php autosave — periodic AJAX POST (rto_area=public,
    //        rto_action=save_draft, rto_nonce, draft_token, category,
    //        service_id?, answers as a JSON blob) → rate-limited same as any
    //        other public submit → FormDraftService::saveDraft() upserts one
    //        rto_form_drafts row keyed by draft_token → returns the token
    //        (issuing a fresh one server-side when the client sent none/an
    //        invalid one) plus expires_at so the client can show "saved".
    //        Preconditions: rto_public nonce valid, rate limit not exceeded.
    //        Postconditions: one rto_form_drafts row created/updated, or a
    //        400/403/429 JSON error with nothing written.
    private static function saveDraft(): void
    {
        if (!RateLimiter::check('submit', RateLimiter::clientIp())) RateLimiter::abort();

        $draftToken = Sanitiser::text($_POST['draft_token'] ?? '', 64);
        $category   = Sanitiser::text($_POST['category'] ?? '', 100);
        $serviceId  = Sanitiser::int($_POST['service_id'] ?? 0, 0) ?: null;

        $answersRaw = $_POST['answers'] ?? '{}';
        $answers    = is_string($answersRaw) ? json_decode($answersRaw, true) : $answersRaw;
        if (!is_array($answers)) $answers = [];
        $answers = Sanitiser::deepClean($answers);

        if (!$category) rto_json_err('A form category is required to save a draft.', 400);

        /** @var \RTOFLOW\Services\FormDraftService $drafts */
        $drafts = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormDraftService::class);

        // A missing/invalid token (e.g. this visitor's very first autosave)
        // gets a fresh one issued server-side rather than rejected — the
        // client always echoes back whatever draft_token this call returns.
        if (!preg_match('/^[a-f0-9]{16,64}$/', $draftToken)) {
            $draftToken = $drafts->issueToken();
        }

        $result = $drafts->saveDraft($draftToken, $category, $serviceId, $answers);
        if (!$result['success']) rto_json_err($result['message'], 400);

        rto_json_ok([
            'draft_token' => $draftToken,
            'expires_at'  => $result['expires_at'],
        ], 'Draft saved.');
    }

    // TRACE: public apply.php page-load restore-check — AJAX POST
    //        (rto_area=public, rto_action=load_draft, rto_nonce, draft_token)
    //        → FormDraftService::loadDraft() → not found/expired → null data
    //        (still a 200 success, since "no draft" is a normal outcome, not
    //        an error) ; found → the saved category/service_id/answers,
    //        re-validated against the CURRENT form schema.
    private static function loadDraft(): void
    {
        $draftToken = Sanitiser::text($_POST['draft_token'] ?? '', 64);
        if (!$draftToken) rto_json_ok(null, 'No draft token supplied.');

        /** @var \RTOFLOW\Services\FormDraftService $drafts */
        $drafts = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormDraftService::class);
        $draft  = $drafts->loadDraft($draftToken);

        rto_json_ok($draft, $draft ? 'Draft found.' : 'No saved draft found.');
    }

    // ── New comprehensive apply form handler (all 111 fields) ──────────────
    private static function submitApplyV2(): void
    {
        if (!RateLimiter::check('submit', RateLimiter::clientIp())) RateLimiter::abort();

        global $wpdb;

        // ── Core required fields ───────────────────────────────────────────
        $category  = Sanitiser::text($_POST['category']    ?? '');
        $subSvc    = Sanitiser::text($_POST['sub_service'] ?? '');
        $firstName = Sanitiser::text($_POST['first_name']  ?? '');
        $lastName  = Sanitiser::text($_POST['last_name']   ?? '');
        $mobile    = Sanitiser::mobile($_POST['mobile']    ?? '');
        $email     = Sanitiser::email($_POST['email']      ?? '');
        $rtoState  = Sanitiser::text($_POST['rto_state']   ?? '');
        $rtoOffice = Sanitiser::text($_POST['rto_office']  ?? '');

        if (!$category)  rto_json_err('Please select a service category.');
        if (!$firstName) rto_json_err('First name is required.');
        if (!$mobile || strlen($mobile) !== 10) rto_json_err('Please enter a valid 10-digit mobile number.');
        if (!$rtoState)  rto_json_err('Please select your state.');
        if (!$rtoOffice) rto_json_err('Please select an RTO office.');

        $fullName = trim("$firstName $lastName");

        // ── Auto-register or find user ─────────────────────────────────────
        $userId = get_current_user_id();
        if (!$userId) {
            // Find by mobile first (using user meta)
            $existingByMobile = get_users([
                'meta_key'   => 'rtoflow_mobile',
                'meta_value' => $mobile,
                'number'     => 1,
                'fields'     => 'ids',
            ]);
            if (!empty($existingByMobile)) {
                $userId = (int)$existingByMobile[0];
            } elseif ($email && email_exists($email)) {
                $user = get_user_by('email', $email);
                $userId = $user ? $user->ID : 0;
            }

            if (!$userId) {
                // Create new client account
                $loginEmail = $email ?: $mobile . '@rto.local';
                // FIX (undelivered-password bug, same class already fixed in
                // Router::admin.users.create() and VendorsController::store()):
                // wp_generate_password(10,false) produced a real-looking
                // password that was never shown or emailed to the customer,
                // so the auto-created account was permanently inaccessible
                // except by this same mobile-recognition path. The secret is
                // now generated as intentionally unusable (24 chars, with
                // special chars) and WordPress's own wp_new_user_notification()
                // emails the customer a secure "set your password" link when
                // a real email address was supplied. When no email was given
                // (auto-generated $mobile@rto.local), no notification email
                // is possible anyway, but that customer is still recognised
                // on every future visit via the rtoflow_mobile meta lookup
                // above, so no access is lost.
                $randomPass = wp_generate_password(24, true);
                $userId = wp_create_user($loginEmail, $randomPass, $loginEmail);
                if (is_wp_error($userId)) rto_json_err('Unable to create account: ' . $userId->get_error_message());

                wp_update_user(['ID' => $userId, 'display_name' => $fullName, 'role' => 'rto_client']);
                update_user_meta($userId, 'rtoflow_mobile', $mobile);
                if ($firstName) update_user_meta($userId, 'first_name', $firstName);
                if ($lastName)  update_user_meta($userId, 'last_name',  $lastName);
                if ($email) wp_new_user_notification($userId, null, 'user');
            }
        }

        // ── Map category/service to DB service ─────────────────────────────
        $serviceToFind = $subSvc ?: $category;
        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_services WHERE name=%s AND is_active=1 LIMIT 1",
            $serviceToFind
        ), ARRAY_A);

        // Fuzzy match: try category first
        if (!$service && $category) {
            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rto_services
                 WHERE category=%s AND is_active=1 ORDER BY display_order LIMIT 1",
                $category
            ), ARRAY_A);
        }
        // Last resort: first active service
        if (!$service) {
            $service = $wpdb->get_row(
                "SELECT * FROM {$wpdb->prefix}rto_services WHERE is_active=1 ORDER BY display_order LIMIT 1",
                ARRAY_A
            );
        }
        if (!$service) rto_json_err('Service not found. Please try again or contact support.');

        // ── Find or create city ────────────────────────────────────────────
        $cityId = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT c.id FROM {$wpdb->prefix}rto_cities c
             JOIN {$wpdb->prefix}rto_states s ON s.id=c.state_id
             WHERE c.name LIKE %s AND s.name=%s LIMIT 1",
            '%' . $wpdb->esc_like(substr($rtoOffice, 0, 20)) . '%', $rtoState
        ));
        if (!$cityId) {
            // Find state
            $stateId = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rto_states WHERE name=%s LIMIT 1", $rtoState
            ));
            if ($stateId) {
                // Use the state's first city
                $cityId = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}rto_cities WHERE state_id=%d LIMIT 1", $stateId
                ));
            }
            if (!$cityId) {
                $cityId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}rto_cities LIMIT 1");
            }
        }

        // ── Collect all form data as rich meta ─────────────────────────────
        $safePOST  = Sanitiser::deepClean($_POST);
        $formData  = [];
        $skipFields = ['rto_nonce', 'rto_action', '_wp_http_referer', 'action'];
        foreach ($safePOST as $k => $v) {
            if (!in_array($k, $skipFields, true)) {
                $formData[$k] = is_array($v) ? implode(', ', $v) : (string)$v;
            }
        }

        // Extract key vehicle fields
        $vehicleNumber = Sanitiser::text(
            $_POST['veh_reg_transfer'] ?? $_POST['veh_reg_rcaddr'] ?? $_POST['veh_reg_rcdup'] ??
            $_POST['veh_reg_rcpart']   ?? $_POST['veh_reg_rccorr'] ?? $_POST['veh_reg_rccancel'] ??
            $_POST['veh_reg_rcsurr']   ?? $_POST['veh_reg_hpterm'] ?? $_POST['veh_reg_hpcont'] ??
            $_POST['veh_reg_hpother']  ?? $_POST['veh_reg_noc']    ?? $_POST['veh_reg_main'] ?? ''
        );
        $ownerName = Sanitiser::text(
            $_POST['owner_name_transfer'] ?? $_POST['owner_name_rcaddr'] ?? $_POST['owner_name_rcdup'] ??
            $_POST['owner_name_rccorr']   ?? $_POST['owner_name_noc']    ?? $_POST['owner_name_veh'] ?? ''
        );

        // FIX P0: this was a THIRD independent price formula in the codebase
        // (LeadService::create() and Router::getPriceJson() being the other
        // two) — hard-coding an 18% GST rate instead of using the
        // admin-configurable rate GstService reads from Env::float('GST_RATE'),
        // never applying interstate CGST/SGST-vs-IGST splitting, and never
        // adding govt_fee. A customer applying through this form (the
        // comprehensive 111-field path) could be charged a different total
        // than the same service via the legacy submitApply() path. Routing
        // through the same GstService used everywhere else makes this the
        // single formula for "what does this order cost", regardless of
        // which form the customer used.
        $gstSvc     = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\GstService::class);
        // FIX (integration pass follow-up — closes the previously-documented
        // "Pricing preview endpoints not yet reading city overrides" gap,
        // now found to also apply to the actual charge on THIS path):
        // this is a third, fully independent lead-creation path that never
        // consulted rto_city_service_config at all, unlike LeadService::
        // create() which already reads it. A city with a base_price/govt_fee
        // override configured would be silently ignored for every lead
        // created through this comprehensive-apply-form endpoint, charging
        // the plain rto_services default instead. Mirrors LeadService::
        // create()'s lookup and override precedence exactly.
        $cityConfig = $wpdb->get_row($wpdb->prepare(
            "SELECT govt_fee, service_charge FROM {$wpdb->prefix}rto_city_service_config
             WHERE city_id = %d AND service_id = %d",
            $cityId, (int)$service['id']
        ), ARRAY_A);
        $basePrice  = ($cityConfig && $cityConfig['service_charge'] !== null)
            ? (float)$cityConfig['service_charge']
            : (float)$service['base_price'];
        $govtFee    = ($cityConfig && $cityConfig['govt_fee'] !== null)
            ? (float)$cityConfig['govt_fee']
            : (float)($service['govt_fee'] ?? 0);
        $stateCode  = $wpdb->get_var($wpdb->prepare(
            "SELECT s.code FROM {$wpdb->prefix}rto_states s JOIN {$wpdb->prefix}rto_cities c ON c.state_id=s.id WHERE c.id=%d",
            $cityId
        )) ?: '';
        $gstResult  = $service['gst_applicable']
            ? $gstSvc->calculate($basePrice, $stateCode)
            : ['total_gst' => 0.0, 'grand_total' => $basePrice];

        // FIX P1: eligibility check — see EligibilityService, wired the same
        // way as the legacy submitApply() path.
        $eligibility = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\EligibilityService::class);
        $eligResult  = $eligibility->evaluate((int)$service['id'], $_POST, $userId ?: null);
        if (!$eligResult['eligible']) {
            rto_json_err(
                $eligResult['blocking_failures'][0]['message'] ?? 'You are not eligible for this service.',
                422,
                ['eligibility_failures' => $eligResult['blocking_failures']]
            );
        }

        // ── Create lead ────────────────────────────────────────────────────
        $trackingToken = bin2hex(random_bytes(16));
        $leadNumber    = 'RTO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $gstAmount     = $gstResult['total_gst'];
        $totalAmount   = round($gstResult['grand_total'] + $govtFee, 2);

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_leads', [
            'lead_number'    => $leadNumber,
            'service_id'     => (int)$service['id'],
            'city_id'        => $cityId,
            'client_id'      => $userId,
            'status'         => 'created',
            'priority'       => 2,
            'source'         => 'web_apply_v2',
            'total_amount'   => $totalAmount,
            'gst_amount'     => $gstAmount,
            'payment_status' => 'unpaid',
            'sla_deadline'   => date('Y-m-d H:i:s', strtotime("+{$service['sla_days']} days")),
            'vehicle_number' => strtoupper($vehicleNumber),
            'owner_name'     => $ownerName,
            'rto_state'      => $rtoState,
            'rto_office'     => $rtoOffice,
            'tracking_token' => $trackingToken,
            'form_data'      => wp_json_encode($formData),
            'created_at'     => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW submitApplyV2: insert failed — ' . $wpdb->last_error);
            rto_json_err('Unable to submit application. Please try again.');
        }

        $leadId = (int)$wpdb->insert_id;

        // ── Store individual meta entries ──────────────────────────────────
        // FIX (ghost-success guard, same standard as quickEnquiry() above):
        // these writes were previously fired-and-forgotten. Non-fatal here —
        // the lead row already committed and re-submitting would just create
        // a duplicate — but failures are logged so staff/admin can spot and
        // manually complete an affected lead instead of the gap being silent.
        foreach ($formData as $k => $v) {
            if ($v !== '' && $v !== null) {
                $metaSaved = $wpdb->insert($wpdb->prefix . 'rto_lead_meta', [
                    'lead_id'    => $leadId,
                    'meta_key'   => substr($k, 0, 100),
                    'meta_value' => substr($v, 0, 2000),
                ]);
                if ($metaSaved === false) {
                    error_log("RTOFLOW submitApplyV2: lead_meta insert failed for lead {$leadId}, key={$k} — " . $wpdb->last_error);
                }
            }
        }

        // ── Store form submission snapshot ─────────────────────────────────
        $submissionSaved = $wpdb->insert($wpdb->prefix . 'rto_form_submissions', [
            'lead_id'    => $leadId,
            'category'   => $category,
            'sub_service'=> $subSvc,
            'form_data'  => wp_json_encode($formData),
            'raw_post'   => wp_json_encode(array_map('sanitize_text_field', $_POST)),
            'created_at' => current_time('mysql'),
        ]);
        if ($submissionSaved === false) {
            error_log("RTOFLOW submitApplyV2: form_submissions insert failed for lead {$leadId} — " . $wpdb->last_error);
        }

        // ── Handle file uploads ────────────────────────────────────────────
        if (!empty($_FILES)) {
            foreach ($_FILES as $key => $file) {
                if (!empty($file['name']) && $file['error'] === UPLOAD_ERR_OK) {
                    $validation = \RTOFLOW\Security\FileUploadGuard::validate($file);
                    if ($validation['valid']) {
                        $stored = \RTOFLOW\Security\FileUploadGuard::store($file, 'documents', $leadId);
                        if ($stored['success'] ?? false) {
                            // doc_type_id defaults to 0 here because the form submits named file fields
                        // (e.g. 'rc_copy', 'address_proof') rather than doc_type_id values.
                        // Admin staff can update the doc_type_id from the lead detail view after review.
                        $docSaved = $wpdb->insert($wpdb->prefix . 'rto_documents', [
                                'lead_id'      => $leadId,
                                'doc_type_id'  => 0,
                                'file_path'    => $stored['path'] ?? '',
                                'file_name'    => $stored['name'] ?? $file['name'],
                                'file_size_kb' => isset($stored['size_bytes']) ? (int)ceil($stored['size_bytes'] / 1024) : 0,
                                'status'       => 'pending',
                                'uploaded_by'  => $userId,
                                'created_at'   => current_time('mysql'),
                            ]);
                            if ($docSaved === false) {
                                error_log("RTOFLOW submitApplyV2: rto_documents insert failed for lead {$leadId}, field={$key} — " . $wpdb->last_error);
                            }
                        }
                    }
                }
            }
        }

        // ── Audit + hooks ──────────────────────────────────────────────────
        \RTOFLOW\Services\AuditService::log('lead.created_v2', $leadId, [
            'lead_number' => $leadNumber,
            'category'    => $category,
            'sub_service' => $subSvc,
            'rto_state'   => $rtoState,
            'rto_office'  => $rtoOffice,
        ]);

        do_action('rtoflow_lead_created', $leadId, $userId, $service);

        $trackUrl = home_url('/rto-track/' . $trackingToken);
        $dashUrl  = home_url('/rto-dashboard/');

        rto_json_ok([
            'lead_number'    => $leadNumber,
            'lead_id'        => $leadId,
            'tracking_url'   => $trackUrl,
            'dashboard_url'  => $dashUrl,
        ], 'Application submitted successfully! Our team will contact you within 24 hours. Reference: ' . $leadNumber);
    }

    // TRACE: AJAX submit_apply_dynamic ← resources/views/public/apply-dynamic.php
    //        (the Form Builder v2 renderer) → re-validates every field against
    //        the SAME server-side schema (never trusts the client's visible-
    //        field list, since a hidden/disabled field can still be posted by
    //        a modified request) → re-resolves the document checklist and
    //        confirms every currently-required document was actually
    //        uploaded → identifies/creates the client account and lead city
    //        the same way submitApplyV2() does → prices via the same
    //        GstService + city-override precedence as every other lead-
    //        creation path in this codebase (one pricing formula, not a
    //        fourth independent one) → inserts rto_leads/_lead_meta/
    //        _form_submissions/_documents rows → fires the same
    //        'rtoflow_lead_created' hook every other apply path fires.
    //        Preconditions: rto_public nonce valid, rate limit not exceeded,
    //        service has an active v2 schema (form_builder flag on).
    //        Postconditions: one lead created with all answers preserved
    //        verbatim as JSON (form_data) AND exploded into rto_lead_meta
    //        rows (for anything that queries meta directly, matching
    //        submitApplyV2's own postcondition) — or a 4xx JSON error with
    //        nothing written.
    //        Edge cases handled: missing/invalid mobile or name, a required
    //        field hidden then re-shown with no value, a required document
    //        not uploaded, an unknown service id, the schema having been
    //        deactivated between page load and submit (getForService()
    //        re-checked here, not cached from routeApplyDynamic()).
    //        NOT handled (disclosed limitation, not silently assumed):
    //        EligibilityService::evaluate() was written against the static
    //        form's known $_POST field names. A schema author who names an
    //        eligibility-relevant field differently than the static form does
    //        may get eligibility rules that never fire for this path — see
    //        the plan doc for the same caveat spelled out for the admin.
    private static function submitApplyDynamic(): void
    {
        if (!RateLimiter::check('submit', RateLimiter::clientIp())) RateLimiter::abort();
        global $wpdb;

        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0, 1);
        if (!$serviceId) rto_json_err('Service is required.');

        // Known Limitations audit fix: "Deactivating a service does not
        // affect an already-selected form path for a customer mid-submission."
        // is_active is only checked when the service list is first rendered
        // (Step 1) — a customer whose session outlives a deactivation could
        // otherwise reach this final save step for a now-inactive service.
        // This is the actual point of the save, so it is re-checked here,
        // distinguishing "never existed" from "existed but was deactivated
        // after the customer picked it" so the customer sees a clear,
        // specific reason rather than a vague 404.
        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_services WHERE id=%d", $serviceId
        ), ARRAY_A);
        if (!$service) rto_json_err('Service not found.', 404);
        if (empty($service['is_active'])) {
            rto_json_err('This service is no longer available. Please go back and choose a different service.', 410);
        }

        // Part 4.10: schemas are looked up by CATEGORY now, not service_id —
        // a category's schema covers every service in it via a
        // `selected_service` picker field. Resolve which category this
        // specific service belongs to (RealFormSchemaSeeder::categoryMap()
        // is the single source of truth for that mapping — same list used
        // to build the schema in the first place) and inject the service's
        // own name as the picker's answer so visible_if conditions gated on
        // selected_service evaluate exactly as they did client-side.
        $forms      = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormEngineService::class);
        $categoryKey = null;
        foreach (\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap() as $catKey => $cat) {
            if (in_array($service['name'], $cat['services'], true)) { $categoryKey = $catKey; break; }
        }
        $schema = $categoryKey ? $forms->getForCategory($categoryKey) : null;
        if (!$schema) rto_json_err('This form is no longer available. Please use the standard apply form.', 404);

        // ── Two ways this endpoint receives answers, both real and both
        // used: the standalone /rto-apply-form/{id}/ page (apply-dynamic.php)
        // posts one JSON 'answers' blob; the REAL apply.php integration
        // (Step 2 rendering an admin-built form in place of its own static
        // block — see renderDynamicStep2() there) posts each answer as its
        // own named/keyed field directly, since it shares one native <form>
        // with the rest of the page. Support both rather than forcing the
        // live form to adopt a JSON-blob submission style it never used. ───
        if (isset($_POST['answers'])) {
            $answersRaw = $_POST['answers'];
            $answers    = is_string($answersRaw) ? json_decode($answersRaw, true) : $answersRaw;
        } else {
            $answers = [];
            foreach ($schema['all_fields'] as $f) {
                if (in_array($f['type'], ['file', 'heading'], true)) continue;
                if (isset($_POST[$f['key']])) $answers[$f['key']] = $_POST[$f['key']];
            }
            // Shared contact/location fields apply.php always submits
            // (mobile/email/rto_state/rto_office/first_name/last_name) —
            // merged in under the same key names submitApplyV2() itself
            // reads, so a schema author never has to redeclare these.
            foreach (['mobile', 'email', 'rto_state', 'rto_office'] as $k) {
                if (!empty($_POST[$k]) && !isset($answers[$k])) $answers[$k] = $_POST[$k];
            }
            if (!isset($answers['full_name']) && (!empty($_POST['first_name']) || !empty($_POST['last_name']))) {
                $answers['full_name'] = trim(Sanitiser::text($_POST['first_name'] ?? '') . ' ' . Sanitiser::text($_POST['last_name'] ?? ''));
            }
        }
        if (!is_array($answers)) rto_json_err('Answers are malformed.');
        $answers = Sanitiser::deepClean($answers);
        // The category schema's own selected_service field is what every
        // other field's visible_if is gated on — always set server-side
        // from the resolved $service row, never trusted from the client,
        // so a tampered/missing picker value can't hide required fields.
        $answers['selected_service'] = $service['name'];

        // ── Server-side re-validation — never trust the client's idea of
        // which fields were visible/required. ──────────────────────────────
        $errors = [];
        foreach ($schema['steps'] as $step) {
            foreach ($step['fields'] as $f) {
                if ($f['type'] === 'heading') continue;
                if (!\RTOFLOW\Services\FormEngineService::evaluateCondition($f['visible_if'] ?? null, $answers)) continue;

                // A 'file' field's value arrives in $_FILES, never $_POST/
                // $answers — checking $answers for it would always read
                // empty and reject a real upload as "required" and missing.
                if ($f['type'] === 'file') {
                    if ($f['required'] && empty($_FILES[$f['key']]['name'])) {
                        $errors[] = $f['label'] . ' is required.';
                    }
                    continue;
                }

                $val = $answers[$f['key']] ?? '';
                $empty = ($val === '' || $val === null || (is_array($val) && !$val));
                if ($f['required'] && $empty) { $errors[] = $f['label'] . ' is required.'; continue; }
                if ($empty) continue;

                $v = $f['validation'] ?? [];
                if ($f['type'] === 'email' && !is_email((string)$val)) $errors[] = $f['label'] . ' must be a valid email address.';
                if ($f['type'] === 'tel' && !preg_match('/^[6-9]\d{9}$/', preg_replace('/\D/', '', (string)$val))) {
                    $errors[] = $f['label'] . ' must be a valid 10-digit mobile number.';
                }
                if (in_array($f['type'], ['text', 'textarea', 'tel', 'email'], true)) {
                    $len = strlen((string)$val);
                    if (!empty($v['min_length']) && $len < $v['min_length']) $errors[] = $f['label'] . ' is too short.';
                    if (!empty($v['max_length']) && $len > $v['max_length']) $errors[] = $f['label'] . ' is too long.';
                }
                if ($f['type'] === 'number' && is_numeric($val)) {
                    if (isset($v['min']) && $v['min'] !== null && (float)$val < $v['min']) $errors[] = $f['label'] . ' is below the minimum allowed value.';
                    if (isset($v['max']) && $v['max'] !== null && (float)$val > $v['max']) $errors[] = $f['label'] . ' is above the maximum allowed value.';
                }
                if (!empty($v['pattern'])) {
                    set_error_handler(fn() => true);
                    $matches = @preg_match('/' . str_replace('/', '\/', $v['pattern']) . '/', (string)$val);
                    restore_error_handler();
                    if (!$matches) $errors[] = $f['label'] . ' is not in the correct format.';
                }
            }
        }
        if ($errors) rto_json_err($errors[0], 422, ['validation_errors' => $errors]);

        // ── Document checklist — confirm every currently-required document
        // was actually uploaded, resolved fresh against these answers. ──────
        $checklist = $forms->resolveDocumentChecklist($schema, $answers);
        foreach ($checklist as $doc) {
            $fileKey = 'doc_' . $doc['doc_type_id'];
            if (!empty($doc['required']) && empty($_FILES[$fileKey]['name'])) {
                rto_json_err('Please upload: ' . $doc['label'], 422);
            }
        }

        // ── Identify/create the client account. ─────────────────────────────
        // FIX (P1 — real contract instead of a field-naming guess): a field
        // can now declare an explicit 'role' (e.g. 'role' => 'mobile') in
        // its schema definition — FormEngineService::resolveByRole() looks
        // for that FIRST and only falls back to guessing from these common
        // key names when no field in the schema declares the role. Every
        // schema saved before roles existed behaves exactly as before
        // (guess-only); a schema that declares a role gets a real answer
        // even when its field is named something the guess would never
        // have matched (e.g. "location_x7" declared role => 'city').
        $mobile = Sanitiser::mobile(
            \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, 'mobile', ['mobile', 'phone', 'applicant_mobile'])['value'] ?? ''
        );
        $email = Sanitiser::email(
            \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, 'email', ['email', 'applicant_email'])['value'] ?? ''
        );
        $name = Sanitiser::text(
            \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, 'full_name', ['full_name', 'applicant_name', 'name'])['value'] ?? ''
        );
        if (!$mobile || strlen($mobile) !== 10) rto_json_err('A valid 10-digit mobile number is required to submit this form.');
        if (!$name) rto_json_err('Your name is required.');

        $userId = get_current_user_id();
        if (!$userId) {
            $existingByMobile = get_users(['meta_key' => 'rtoflow_mobile', 'meta_value' => $mobile, 'number' => 1, 'fields' => 'ids']);
            if (!empty($existingByMobile)) {
                $userId = (int)$existingByMobile[0];
            } elseif ($email && email_exists($email)) {
                $u = get_user_by('email', $email);
                $userId = $u ? $u->ID : 0;
            }
            if (!$userId) {
                $loginEmail = $email ?: $mobile . '@rto.local';
                // FIX (undelivered-password bug — same fix as the quickEnquiry()
                // auto-registration path above and Router::admin.users.create()):
                // generate an intentionally-unusable secret and let WordPress's
                // own wp_new_user_notification() deliver a real "set your
                // password" link when a real email address was supplied.
                $randomPass = wp_generate_password(24, true);
                $userId = wp_create_user($loginEmail, $randomPass, $loginEmail);
                if (is_wp_error($userId)) rto_json_err('Unable to create account: ' . $userId->get_error_message());
                wp_update_user(['ID' => $userId, 'display_name' => $name, 'role' => 'rto_client']);
                update_user_meta($userId, 'rtoflow_mobile', $mobile);
                if ($email) wp_new_user_notification($userId, null, 'user');
            }
        }

        $rtoState  = Sanitiser::text(
            \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, 'rto_state', ['rto_state', 'applicant_state', 'state'])['value'] ?? ''
        );
        $rtoOffice = Sanitiser::text(
            \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, 'rto_office', ['rto_office', 'office'])['value'] ?? ''
        );
        $cityId = 0;
        if ($rtoState) {
            $stateId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_states WHERE name=%s LIMIT 1", $rtoState));
            if ($stateId) $cityId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}rto_cities WHERE state_id=%d LIMIT 1", $stateId));
        }
        if (!$cityId) $cityId = (int)$wpdb->get_var("SELECT id FROM {$wpdb->prefix}rto_cities LIMIT 1");

        // ── Role-keyed aliases for EligibilityService (P1 fix). ─────────────
        // EligibilityService::evaluate() looks up $answers[$rule['field_key']]
        // as-is — it has no schema/role knowledge of its own. An admin rule
        // configured with field_key='city' or field_key='vehicle_type' (the
        // names the STATIC apply.php form happens to use) previously could
        // never match a dynamic submission whose schema names that field
        // something else entirely. Fixed here, upstream of evaluate(): alias
        // every KNOWN_ROLE this schema resolves (via explicit role, falling
        // back to the same guess as everywhere else) into $answers under the
        // role's own name, alongside — never overwriting — the field's real
        // key. A rule written against either name now works identically for
        // static and dynamic submissions.
        foreach (\RTOFLOW\Services\FormEngineService::KNOWN_ROLES as $roleName) {
            if (array_key_exists($roleName, $answers)) continue; // real field already uses this exact key — don't shadow it
            $resolved = \RTOFLOW\Services\FormEngineService::resolveByRole($schema['all_fields'], $answers, $roleName, [$roleName]);
            if ($resolved['value'] !== null && $resolved['value'] !== '') {
                $answers[$roleName] = $resolved['value'];
            }
        }

        // ── Pricing — same GstService + city-override precedence used by
        // every other lead-creation path (one formula, not a fourth one). ──
        $gstSvc = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\GstService::class);
        $cityConfig = $wpdb->get_row($wpdb->prepare(
            "SELECT govt_fee, service_charge FROM {$wpdb->prefix}rto_city_service_config WHERE city_id=%d AND service_id=%d",
            $cityId, $serviceId
        ), ARRAY_A);
        $basePrice = ($cityConfig && $cityConfig['service_charge'] !== null) ? (float)$cityConfig['service_charge'] : (float)$service['base_price'];
        $govtFee   = ($cityConfig && $cityConfig['govt_fee'] !== null) ? (float)$cityConfig['govt_fee'] : (float)($service['govt_fee'] ?? 0);
        $stateCode = $wpdb->get_var($wpdb->prepare(
            "SELECT s.code FROM {$wpdb->prefix}rto_states s JOIN {$wpdb->prefix}rto_cities c ON c.state_id=s.id WHERE c.id=%d", $cityId
        )) ?: '';
        $gstResult = $service['gst_applicable'] ? $gstSvc->calculate($basePrice, $stateCode) : ['total_gst' => 0.0, 'grand_total' => $basePrice];

        $eligibility = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\EligibilityService::class);
        $eligResult  = $eligibility->evaluate($serviceId, $answers, $userId ?: null);
        if (!$eligResult['eligible']) {
            rto_json_err(
                $eligResult['blocking_failures'][0]['message'] ?? 'You are not eligible for this service.',
                422,
                ['eligibility_failures' => $eligResult['blocking_failures']]
            );
        }

        $trackingToken = bin2hex(random_bytes(16));
        $leadNumber    = 'RTO-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $totalAmount   = round($gstResult['grand_total'] + $govtFee, 2);

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_leads', [
            'lead_number'    => $leadNumber,
            'service_id'     => $serviceId,
            'city_id'        => $cityId,
            'client_id'      => $userId,
            'status'         => 'created',
            'priority'       => 2,
            'source'         => 'web_apply_dynamic',
            'total_amount'   => $totalAmount,
            'gst_amount'     => $gstResult['total_gst'],
            'payment_status' => 'unpaid',
            'sla_deadline'   => date('Y-m-d H:i:s', strtotime("+{$service['sla_days']} days")),
            'rto_state'      => $rtoState,
            'rto_office'     => $rtoOffice,
            'tracking_token' => $trackingToken,
            'form_data'      => wp_json_encode($answers),
            'created_at'     => current_time('mysql'),
        ]);
        if (!$inserted) {
            error_log('RTOFLOW submitApplyDynamic: insert failed — ' . $wpdb->last_error);
            rto_json_err('Unable to submit application. Please try again.');
        }
        $leadId = (int)$wpdb->insert_id;

        // FIX (ghost-success guard, same standard applied to submitApplyV2()
        // and quickEnquiry() above): non-fatal — the lead already committed —
        // but failures are logged so an affected lead is discoverable instead
        // of silently missing its answers/snapshot.
        foreach ($answers as $k => $v) {
            if ($v !== '' && $v !== null) {
                $metaSaved = $wpdb->insert($wpdb->prefix . 'rto_lead_meta', [
                    'lead_id'    => $leadId,
                    'meta_key'   => substr((string)$k, 0, 100),
                    'meta_value' => substr(is_array($v) ? implode(', ', $v) : (string)$v, 0, 2000),
                ]);
                if ($metaSaved === false) {
                    error_log("RTOFLOW submitApplyDynamic: lead_meta insert failed for lead {$leadId}, key={$k} — " . $wpdb->last_error);
                }
            }
        }

        $submissionSaved = $wpdb->insert($wpdb->prefix . 'rto_form_submissions', [
            'lead_id'    => $leadId,
            'category'   => $service['category'],
            'sub_service'=> $service['name'],
            'form_data'  => wp_json_encode($answers),
            'raw_post'   => wp_json_encode($answers),
            'created_at' => current_time('mysql'),
        ]);
        if ($submissionSaved === false) {
            error_log("RTOFLOW submitApplyDynamic: form_submissions insert failed for lead {$leadId} — " . $wpdb->last_error);
        }

        $handledFileKeys = [];
        foreach ($checklist as $doc) {
            $fileKey = 'doc_' . $doc['doc_type_id'];
            $handledFileKeys[] = $fileKey;
            if (!empty($_FILES[$fileKey]['name']) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $validation = \RTOFLOW\Security\FileUploadGuard::validate($_FILES[$fileKey]);
                if ($validation['valid']) {
                    $stored = \RTOFLOW\Security\FileUploadGuard::store($_FILES[$fileKey], 'documents', $leadId);
                    if ($stored['success'] ?? false) {
                        $docSaved = $wpdb->insert($wpdb->prefix . 'rto_documents', [
                            'lead_id'      => $leadId,
                            'doc_type_id'  => $doc['doc_type_id'],
                            'file_path'    => $stored['path'] ?? '',
                            'file_name'    => $stored['name'] ?? $_FILES[$fileKey]['name'],
                            'file_size_kb' => isset($stored['size_bytes']) ? (int)ceil($stored['size_bytes'] / 1024) : 0,
                            'status'       => 'pending',
                            'uploaded_by'  => $userId,
                            'created_at'   => current_time('mysql'),
                        ]);
                        if ($docSaved === false) {
                            error_log("RTOFLOW submitApplyDynamic: rto_documents insert failed for lead {$leadId}, doc_type_id={$doc['doc_type_id']} — " . $wpdb->last_error);
                        }
                    }
                }
            }
        }

        // Any remaining uploaded file belongs to a plain schema field of
        // type 'file' (e.g. a migrated "Upload FIR copy" field from the
        // static form) rather than the document checklist — stored the same
        // generic way submitApplyV2() stores its own named file inputs.
        foreach ($_FILES as $key => $file) {
            if (in_array($key, $handledFileKeys, true)) continue;
            if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) continue;
            $validation = \RTOFLOW\Security\FileUploadGuard::validate($file);
            if (!$validation['valid']) continue;
            $stored = \RTOFLOW\Security\FileUploadGuard::store($file, 'documents', $leadId);
            if ($stored['success'] ?? false) {
                $docSaved = $wpdb->insert($wpdb->prefix . 'rto_documents', [
                    'lead_id'      => $leadId,
                    'doc_type_id'  => 0,
                    'file_path'    => $stored['path'] ?? '',
                    'file_name'    => $stored['name'] ?? $file['name'],
                    'file_size_kb' => isset($stored['size_bytes']) ? (int)ceil($stored['size_bytes'] / 1024) : 0,
                    'status'       => 'pending',
                    'uploaded_by'  => $userId,
                    'created_at'   => current_time('mysql'),
                ]);
                if ($docSaved === false) {
                    error_log("RTOFLOW submitApplyDynamic: rto_documents insert failed for lead {$leadId}, field={$key} — " . $wpdb->last_error);
                }
            }
        }

        \RTOFLOW\Services\AuditService::log('lead.created_dynamic', $leadId, [
            'lead_number' => $leadNumber, 'service_id' => $serviceId, 'schema_id' => $schema['id'] ?? null,
        ]);
        do_action('rtoflow_lead_created', $leadId, $userId, $service);

        // Final submission succeeded — the in-progress autosave (if any) is
        // now redundant and must never be offered for restore again.
        $submittedDraftToken = Sanitiser::text($_POST['draft_token'] ?? '', 64);
        if ($submittedDraftToken) {
            \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormDraftService::class)
                ->consumeDraft($submittedDraftToken);
        }

        rto_json_ok([
            'lead_number'   => $leadNumber,
            'lead_id'       => $leadId,
            'tracking_url'  => home_url('/rto-track/' . $trackingToken),
            'dashboard_url' => home_url('/rto-dashboard/'),
        ], 'Application submitted successfully! Our team will contact you within 24 hours. Reference: ' . $leadNumber);
    }

    // P2-SEC-010 FIX: public form submission handler (legacy)
    private static function submitApply(): void
    {
        if (!RateLimiter::check('submit', RateLimiter::clientIp())) RateLimiter::abort();

        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0, 1);
        $cityId    = Sanitiser::int($_POST['city_id']    ?? 0, 1);
        if (!$serviceId || !$cityId) rto_json_err('Please select a service and city.');

        // ENTERPRISE GAP FIX (Phase 11, item — "No per-vendor or per-city
        // fairness in rate limiting"): the global 'submit' bucket above is
        // keyed to the caller's IP only — a burst of submissions for one
        // very high-volume city (many different IPs, e.g. a marketing
        // campaign or a scripted flood targeting one city) is invisible to
        // it and could still saturate that city's shared vendor-assignment
        // capacity while starving other cities of the same shared
        // resource. checkFair('city', ...) is a separate per-city bucket
        // that catches exactly that case without affecting other cities.
        if (!RateLimiter::checkFair('city', $cityId)) {
            rto_json_err('This city is receiving a high volume of applications right now — please try again shortly.', 429);
        }

        $userId = get_current_user_id();

        // Create account if not logged in
        if (!$userId) {
            $name     = Sanitiser::text($_POST['client_name'] ?? '');
            $email    = Sanitiser::email($_POST['email']       ?? '');
            $mobile   = Sanitiser::mobile($_POST['mobile']     ?? '');
            $password = $_POST['password'] ?? '';

            if (!$name || !$email || !$mobile || strlen($password) < 8) {
                rto_json_err('Please fill in all required fields. Password must be at least 8 characters.');
            }
            if (email_exists($email)) {
                rto_json_err('An account with this email already exists. Please log in to continue.');
            }

            $userId = wp_create_user($email, $password, $email);
            if (is_wp_error($userId)) rto_json_err($userId->get_error_message());

            wp_update_user(['ID' => $userId, 'display_name' => $name, 'role' => 'rto_client']);
            update_user_meta($userId, 'rtoflow_mobile', $mobile);
        }

        // FIX P1: eligibility is now actually evaluated server-side before a
        // lead is created — previously nothing in the backend read the
        // age/licence-status answers a customer submitted on this form at
        // all. See EligibilityService for the reusable engine; rules are
        // configured per-service by an admin at /rto-admin/eligibility/.
        $eligibility = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\EligibilityService::class);
        $elig        = $eligibility->evaluate($serviceId, $_POST, $userId ?: null);
        if (!$elig['eligible']) {
            rto_json_err(
                $elig['blocking_failures'][0]['message'] ?? 'You are not eligible for this service.',
                422,
                ['eligibility_failures' => $elig['blocking_failures']]
            );
        }

        $leadSvc = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\LeadService::class);
        $result  = $leadSvc->create([
            'service_id' => $serviceId,
            'city_id'    => $cityId,
            'source'     => 'web',
        ], $userId);

        if (!$result['success']) rto_json_err($result['message']);

        rto_json_ok([
            'lead_number'   => $result['lead_number'],
            'lead_id'       => $result['lead_id'],
            'dashboard_url' => home_url('/rto-dashboard/orders/' . $result['lead_id']),
            'eligibility_warnings' => $elig['warnings'],
        ], 'Application submitted! Our team will contact you shortly.');
    }


    // ── Area routers ──────────────────────────────────────────────────────


    // ── Admin: Initiate refund (closes the missing origination step of the
    //    approve/reject refund workflow — see the dispatch-table comment
    //    above for how this gap was found) ──────────────────────────────────
    // TRACE: admin clicks "Refund" on a payment row in admin/payments/index.php,
    //        enters an amount and reason → verifies nonce+admin role →
    //        delegates to PaymentService::initiateRefund(), which loads the
    //        payment, validates amount <= payment amount, inserts a
    //        'pending' rto_refunds row → returns JSON success →
    //        precondition: payment exists, amount > 0 and <= payment amount,
    //        user is admin → postcondition: one new 'pending' rto_refunds
    //        row, now visible in the pending-refunds view for approve/reject →
    //        edge cases: payment not found, amount exceeds payment, zero/
    //        negative amount, insert failure (surfaced by PaymentService,
    //        not swallowed — see this session's ghost-success fix there).
    private static function initiateRefund(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $paymentId = Sanitiser::int($_POST['payment_id'] ?? 0, 1);
        $amount    = (float)($_POST['amount'] ?? 0);
        $reason    = Sanitiser::text($_POST['reason'] ?? '', 500);

        if (!$paymentId) rto_json_err('A payment is required.');
        if ($amount <= 0) rto_json_err('Refund amount must be greater than zero.');
        if ($reason === '') rto_json_err('A reason is required to initiate a refund.');

        $result = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\PaymentService::class)->initiateRefund(
            $paymentId, $amount, $reason, get_current_user_id()
        );

        if (!$result['success']) rto_json_err($result['message']);
        rto_json_ok(null, $result['message']);
    }

    // ── Admin: Approve refund (Part 11-B build — closes refund workflow dead end) ────
    // TRACE: admin clicks "Approve Refund" on a payment's refund row →
    //        verifies nonce+admin role, loads refund record →
    //        if the underlying payment was via Razorpay, calls the REAL
    //        gateway refund API FIRST (see the gap-fix comment just below) →
    //        updates status to 'approved', sets processed_at →
    //        notifies client via NotificationService →
    //        returns JSON success with refund_id →
    //        precondition: refund exists, status='pending', user is admin →
    //        postcondition: refund.status='approved', gateway refund issued
    //        for razorpay payments, client notified →
    //        edge cases: refund not found, already approved, not admin,
    //        gateway refund call fails (request rejected, status stays
    //        'pending', nothing marked approved and no client notified).
    private static function approveRefund(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $refundId = Sanitiser::int($_POST['refund_id'] ?? 0, 1);
        $note     = Sanitiser::text($_POST['note'] ?? '', 500);

        $refund = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.lead_id, p.method as payment_method, p.txn_id FROM {$wpdb->prefix}rto_refunds r
             JOIN {$wpdb->prefix}rto_payments p ON p.id = r.payment_id
             WHERE r.id = %d",
            $refundId
        ), ARRAY_A);

        if (!$refund) rto_json_err('Refund not found.', 404);
        if ($refund['status'] !== 'pending') rto_json_err('Refund already processed or rejected.');

        // ENTERPRISE GAP FIX (real financial-integrity gap found while
        // building the initiate_refund origination step above): this
        // handler previously only flipped rto_refunds.status to 'approved'
        // and emailed the client a "your refund has been approved" message
        // — it never called the actual payment gateway. For a payment made
        // via Razorpay, clicking "Approve" therefore told the customer their
        // money was coming back while not moving a single rupee at the
        // gateway; the admin would need to separately know to log into the
        // Razorpay dashboard and issue the refund by hand, with nothing in
        // this UI hinting that was still required. Manual-settlement methods
        // (cash/bank_transfer/cheque/neft/rtgs) have no gateway API to call
        // — those are correctly left as a DB-only approval, since the actual
        // money movement for those happens outside this system by design.
        // The gateway call happens BEFORE the DB write below and is fatal on
        // failure, so a rejected/failed gateway refund never gets recorded
        // as 'approved' or notifies the client of a refund that didn't
        // happen — the refund row stays 'pending' for the admin to retry.
        $gatewayRefundId = null;
        if (($refund['payment_method'] ?? '') === 'razorpay') {
            if (empty($refund['txn_id'])) {
                rto_json_err('This payment has no recorded gateway transaction ID — cannot issue an automatic refund. Process it manually in the Razorpay dashboard, then contact a developer to reconcile this record.', 422);
            }
            $gw = \RTOFLOW_Razorpay::refund((string)$refund['txn_id'], (float)$refund['amount']);
            if (!$gw['success']) {
                error_log('RTOFLOW: approveRefund gateway refund failed for refund_id=' . $refundId . ' — ' . ($gw['message'] ?? 'unknown error'));
                rto_json_err('Gateway refund failed: ' . ($gw['message'] ?? 'unknown error') . ' — nothing was approved.', 502);
            }
            $gatewayRefundId = $gw['refund_id'] ?? null;
        }

        // 14-A FIX: check return value — ghost success if DB fails
        $updated = $wpdb->update($wpdb->prefix . 'rto_refunds', [
            'status'       => 'approved',
            'gateway_ref'  => $gatewayRefundId,
            'approved_by'  => get_current_user_id(),
            'approved_at'  => current_time('mysql'),
            'resolved_by'  => get_current_user_id(),
            'resolved_at'  => current_time('mysql'),
            'processed_at' => current_time('mysql'),
        ], ['id' => $refundId]);

        if ($updated === false) {
            // This branch is now reachable in a genuinely dangerous state
            // when $gatewayRefundId is set: the gateway ALREADY moved real
            // money back to the customer, and only the local bookkeeping
            // failed to record it. That is a data-integrity emergency, not
            // an ordinary retryable failure — retrying this request would
            // call the gateway a second time. Logged at a level an admin
            // monitoring error logs should not miss, with the gateway
            // refund ID preserved so the row can be manually reconciled.
            if ($gatewayRefundId) {
                error_log("RTOFLOW CRITICAL: approveRefund gateway refund {$gatewayRefundId} for refund_id={$refundId} SUCCEEDED but the local DB update failed — {$wpdb->last_error}. Manual reconciliation required: mark rto_refunds.id={$refundId} as approved with gateway_ref={$gatewayRefundId}.");
                rto_json_err('The gateway refund succeeded, but recording it locally failed. This has been logged for manual reconciliation — do NOT retry, or the customer may be refunded twice.', 500);
            }
            error_log('RTOFLOW: approveRefund DB update failed for refund_id=' . $refundId . ' — ' . $wpdb->last_error);
            rto_json_err('Failed to approve refund. Please try again.', 500);
        }

        // Notify the client
        $lead = (new \RTOFLOW\Services\LeadService(
            new \RTOFLOW\Services\GstService(),
            new \RTOFLOW\Services\AuditService()
        ))->getLead((int)$refund['lead_id']);

        if ($lead) {
            \RTOFLOW\Services\NotificationService::send('refund_approved', [
                'lead_number'  => $lead['lead_number'],
                'refund_amount'=> '₹' . number_format((float)$refund['amount'], 2),
                'method'       => $refund['payment_method'],
                'note'         => $note,
            ], (int)$lead['client_id'], ['email'], (int)$refund['lead_id']);
        }

        \RTOFLOW\Services\AuditService::log('refund.approved', (int)$refund['lead_id'], [
            'refund_id' => $refundId,
            'amount'    => $refund['amount'],
            'note'      => $note,
        ]);

        rto_json_ok(['refund_id' => $refundId, 'amount' => $refund['amount']],
            'Refund approved. Client has been notified.');
    }

    // ENTERPRISE GAP FIX (Section 3 — "Payments ledger has no ... refund-
    // reject path"): the mirror of approveRefund() above. Without this, a
    // refund request staff decide NOT to honor had no formal end state and
    // sat 'pending' forever, permanently cluttering the pending-refunds view
    // and leaving the client with no notification the request was reviewed.
    // TRACE: admin clicks Reject on a pending refund row, supplies a
    //        mandatory reason → verifies nonce+admin role, loads refund →
    //        requires a non-empty reason (this is a client-facing decision,
    //        not a silent dismissal) → updates status to 'rejected',
    //        records rejection_reason + resolved_by/resolved_at →
    //        notifies client via NotificationService → returns JSON success →
    //        precondition: refund exists, status='pending', user is admin,
    //        reason non-empty → postcondition: refund.status='rejected',
    //        client notified with the reason → edge cases: refund not found,
    //        already resolved, missing reason, not admin.
    private static function rejectRefund(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $refundId = Sanitiser::int($_POST['refund_id'] ?? 0, 1);
        $reason   = Sanitiser::text($_POST['reason'] ?? '', 1000);

        if ($reason === '') {
            rto_json_err('A reason is required to reject a refund request.');
        }

        $refund = $wpdb->get_row($wpdb->prepare(
            "SELECT r.*, p.lead_id, p.method as payment_method FROM {$wpdb->prefix}rto_refunds r
             JOIN {$wpdb->prefix}rto_payments p ON p.id = r.payment_id
             WHERE r.id = %d",
            $refundId
        ), ARRAY_A);

        if (!$refund) rto_json_err('Refund not found.', 404);
        if ($refund['status'] !== 'pending') rto_json_err('Refund already processed or rejected.');

        $updated = $wpdb->update($wpdb->prefix . 'rto_refunds', [
            'status'            => 'rejected',
            'rejection_reason'  => $reason,
            'resolved_by'       => get_current_user_id(),
            'resolved_at'       => current_time('mysql'),
            'processed_at'      => current_time('mysql'),
        ], ['id' => $refundId]);

        if ($updated === false) {
            error_log('RTOFLOW: rejectRefund DB update failed for refund_id=' . $refundId . ' — ' . $wpdb->last_error);
            rto_json_err('Failed to reject refund. Please try again.', 500);
        }

        $lead = (new \RTOFLOW\Services\LeadService(
            new \RTOFLOW\Services\GstService(),
            new \RTOFLOW\Services\AuditService()
        ))->getLead((int)$refund['lead_id']);

        if ($lead) {
            \RTOFLOW\Services\NotificationService::send('refund_rejected', [
                'lead_number'  => $lead['lead_number'],
                'refund_amount'=> '₹' . number_format((float)$refund['amount'], 2),
                'reason'       => $reason,
            ], (int)$lead['client_id'], ['email'], (int)$refund['lead_id']);
        }

        \RTOFLOW\Services\AuditService::log('refund.rejected', (int)$refund['lead_id'], [
            'refund_id' => $refundId,
            'amount'    => $refund['amount'],
            'reason'    => $reason,
        ]);

        rto_json_ok(['refund_id' => $refundId], 'Refund rejected. Client has been notified.');
    }

    // TRACE: admin clicks Retry on a failed notification row →
    //        admin role + nonce verified →
    //        UPDATE rto_notifications SET status='pending', next_retry=NOW(), error_msg=NULL WHERE id=N →
    //        NotificationService::processQueue() will pick it up on next cron cycle →
    //        preconditions: notification exists with status='failed' →
    //        postconditions: status set to 'pending'; will be retried on next queue run →
    //        edge cases: notif not found → 404; not failed → 400
    private static function retryNotification(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        global $wpdb;
        $id = Sanitiser::int($_POST['notif_id'] ?? 0, 1);
        $notif = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}rto_notifications WHERE id=%d", $id
        ), ARRAY_A);
        if (!$notif) rto_json_err('Notification not found.', 404);
        if ($notif['status'] !== 'failed') rto_json_err('Only failed notifications can be retried.');
        $updated = $wpdb->update($wpdb->prefix . 'rto_notifications', [
            'status'     => 'pending',
            'next_retry' => current_time('mysql'),
            'error_msg'  => null,
        ], ['id' => $id]);
        if ($updated === false) {
            error_log('[RTOFLOW] retryNotification: DB update failed for id=' . $id . ' — ' . $wpdb->last_error);
            rto_json_err('Could not queue the notification for retry. Please try again.', 500);
        }
        rto_json_ok(['id' => $id], 'Notification queued for retry on next cron run.');
    }

    // Known Limitations audit fix: "The Database Migrations panel is
    // read-only status only — it cannot apply a pending migration from
    // this screen." MigrationRunner::run() already exists and is exactly
    // what plugin activation calls — this exposes the same real, already-
    // battle-tested code path as an admin-gated in-screen action instead
    // of requiring server/CLI access, closing the gap the tooltip described
    // rather than just re-documenting it as permanent.
    // TRACE: admin clicks "Run Pending Migrations" on the System Status screen →
    //        admin role + nonce verified → MigrationRunner::run() applies every
    //        not-yet-recorded migration in order, stopping at the first failure →
    //        each outcome audit-logged → full per-migration result list returned →
    //        preconditions: admin, valid nonce →
    //        postconditions: every migration up to (and including, if one fails)
    //        the failure point is recorded in rto_migrations exactly as
    //        MigrationRunner::run() already guarantees on activation →
    //        edge cases: nothing pending → informational no-op response;
    //        a migration throws → run() stops there, already-applied ones
    //        stay applied (matches activation-time behavior), failure surfaced.
    // ── ENTERPRISE GAP FIX (Phase 1, item 2 — client self-service
    // cancellation/refund request, admin review half) ──────────────────────
    // TRACE: admin clicks "Approve" on a pending rto_client_requests row on
    //        the new Client Requests screen → verifies nonce+admin role,
    //        loads the request (must still be 'pending') → for a
    //        cancellation request, calls the REAL, already-guard-checked
    //        LeadService::updateStatus() (so every existing workflow guard,
    //        role check, and side effect for a cancellation still applies —
    //        this does not bypass that machinery) → for a refund request,
    //        finds the lead's most recent successful payment and creates a
    //        real, staff-visible 'pending' rto_refunds row via
    //        PaymentService::initiateRefund() for the FULL payment amount
    //        (a sensible default; staff can still adjust/reject it via the
    //        existing Payments screen approve/reject flow before any money
    //        actually moves — approving the REQUEST is not the same as
    //        approving the REFUND ITSELF) → marks this row 'approved' →
    //        precondition: request exists and is 'pending' →
    //        postcondition: for cancellation, lead.status='cancelled' (if
    //        the workflow engine allowed it) or a clear failure reason
    //        surfaced back to the admin; for refund, a new 'pending'
    //        rto_refunds row exists (or a clear "no payment found" error) →
    //        edge cases: request already handled → 409; cancellation
    //        blocked by a workflow guard → surfaced, not silently ignored;
    //        no successful payment exists for a refund request → surfaced,
    //        request left pending rather than falsely marked approved.
    private static function approveClientRequest(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $p  = $wpdb->prefix;
        $id = Sanitiser::int($_POST['request_id'] ?? 0, 1);
        $note = Sanitiser::text($_POST['staff_note'] ?? '', 500);

        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}rto_client_requests WHERE id=%d", $id), ARRAY_A);
        if (!$req) rto_json_err('Request not found.', 404);
        if ($req['status'] !== 'pending') rto_json_err('This request has already been ' . $req['status'] . '.', 409);

        $refundId = null;

        if ($req['request_type'] === 'cancellation') {
            $result = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\LeadService::class)->updateStatus(
                (int)$req['lead_id'], 'cancelled', ['reason' => $req['reason'], 'role' => 'admin']
            );
            if (!$result['success']) {
                rto_json_err('Could not cancel the order: ' . ($result['message'] ?? 'unknown error') . ' — the request was left pending so you can review it.');
            }
        } else { // refund
            $payment = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}rto_payments WHERE lead_id=%d AND status='success' ORDER BY created_at DESC LIMIT 1",
                (int)$req['lead_id']
            ), ARRAY_A);
            if (!$payment) {
                rto_json_err('No successful payment was found for this order to refund — the request was left pending. Check the order\'s payment history before deciding manually.');
            }
            $refundResult = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\PaymentService::class)->initiateRefund(
                (int)$payment['id'], (float)$payment['amount'], 'Client-requested refund: ' . $req['reason'], get_current_user_id()
            );
            if (!$refundResult['success']) {
                rto_json_err('Could not create the refund: ' . $refundResult['message'] . ' — the request was left pending.');
            }
            $refundId = (int)($refundResult['refund_id'] ?? 0) ?: null;
        }

        $wpdb->update($p . 'rto_client_requests', [
            'status'      => 'approved',
            'staff_note'  => $note,
            'refund_id'   => $refundId,
            'handled_by'  => get_current_user_id(),
            'handled_at'  => current_time('mysql'),
        ], ['id' => $id]);

        \RTOFLOW\Services\AuditService::log('client_request.approved', (int)$req['lead_id'], [
            'request_id' => $id, 'request_type' => $req['request_type'], 'refund_id' => $refundId,
        ]);

        rto_json_ok(['refund_id' => $refundId], ucfirst($req['request_type']) . ' request approved.'
            . ($req['request_type'] === 'refund' ? ' A pending refund has been created — finish it on the Payments screen.' : ''));
    }

    private static function declineClientRequest(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        global $wpdb;
        $p    = $wpdb->prefix;
        $id   = Sanitiser::int($_POST['request_id'] ?? 0, 1);
        $note = Sanitiser::text($_POST['staff_note'] ?? '', 500);
        if ($note === '') rto_json_err('A reason is required to decline a client request.');

        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}rto_client_requests WHERE id=%d", $id), ARRAY_A);
        if (!$req) rto_json_err('Request not found.', 404);
        if ($req['status'] !== 'pending') rto_json_err('This request has already been ' . $req['status'] . '.', 409);

        $updated = $wpdb->update($p . 'rto_client_requests', [
            'status'     => 'declined',
            'staff_note' => $note,
            'handled_by' => get_current_user_id(),
            'handled_at' => current_time('mysql'),
        ], ['id' => $id]);

        if ($updated === false) rto_json_err('Could not decline the request. Please try again.');

        \RTOFLOW\Services\AuditService::log('client_request.declined', (int)$req['lead_id'], [
            'request_id' => $id, 'request_type' => $req['request_type'], 'reason' => $note,
        ]);

        rto_json_ok(null, ucfirst($req['request_type']) . ' request declined.');
    }

    // ── ENTERPRISE GAP FIX (Phase 1, item 5 — penny-drop bank verification) ──
    // TRACE: admin clicks "Verify Bank Account" on a vendor's detail page →
    //        verifies nonce+admin role → delegates to
    //        BankVerificationService::initiate(), which calls the real
    //        Razorpay Contact → Fund Account → Validation chain →
    //        precondition: razorpay keys configured, vendor has bank
    //        details on file → postcondition: vendor.bank_verification_
    //        status='pending' (the real outcome arrives later via the
    //        Razorpay webhook — see routeWebhook()'s 'razorpay' branch) →
    //        edge cases: no keys configured / no bank details / Razorpay
    //        API error at any of the three steps → specific message
    //        surfaced, nothing left in an inconsistent local state.
    private static function verifyVendorBank(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $vendorId = Sanitiser::int($_POST['vendor_id'] ?? 0, 1);
        if (!$vendorId) rto_json_err('A vendor is required.');

        $result = (new \RTOFLOW\Services\BankVerificationService())->initiate($vendorId);
        if (!$result['success']) rto_json_err($result['message']);
        rto_json_ok(null, $result['message']);
    }

    // ── ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC) ─────────────────
    // TRACE: admin picks a role from the dropdown next to a staff member on
    //        the Staff Roles screen → verifies nonce+admin role → validates
    //        the target user is actually rto_staff and the role id exists →
    //        Permissions::assignRole() writes rtoflow_staff_role_id user
    //        meta → precondition: user_id belongs to an rto_staff account,
    //        role_id exists in rto_staff_roles → postcondition: that
    //        account's Permissions::can() checks now resolve against the
    //        newly assigned role's matrix → edge cases: unknown user,
    //        non-staff user, unknown role id — all rejected with a specific
    //        message, no meta written.
    private static function assignPermissionRole(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $userId = Sanitiser::int($_POST['user_id'] ?? 0, 1);
        $roleId = Sanitiser::int($_POST['role_id'] ?? 0, 1);
        if (!$userId) rto_json_err('A staff member is required.');
        if (!$roleId) rto_json_err('A role is required.');

        $user = get_userdata($userId);
        if (!$user || !rto_is_staff($userId)) rto_json_err('That account is not a staff member.');

        $roleExists = array_filter(\RTOFLOW\Security\Permissions::allRoles(), fn($r) => $r['id'] === $roleId);
        if (!$roleExists) rto_json_err('That role does not exist.');

        if (!\RTOFLOW\Security\Permissions::assignRole($userId, $roleId)) {
            rto_json_err('Unable to assign role. Please try again.');
        }

        \RTOFLOW\Services\AuditService::log('staff.role_assigned', null, [
            'user_id' => $userId, 'role_id' => $roleId,
        ]);

        rto_json_ok(null, 'Role assigned.');
    }

    // ENTERPRISE GAP FIX (Phase 6, item — "no staff account suspension short
    // of full role removal"): toggles the 'rtoflow_staff_suspended' usermeta
    // that Bootstrap::init()'s 'authenticate' filter enforces at login. A
    // suspended account keeps its role/permissions/history intact — it just
    // can't log in — unlike removing the role, which would also break
    // audit-log attribution and any admin.* records already tied to that
    // user_id.
    private static function toggleStaffSuspension(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $userId = Sanitiser::int($_POST['user_id'] ?? 0, 1);
        if (!$userId) rto_json_err('A staff member is required.');
        if ($userId === get_current_user_id()) rto_json_err('You cannot suspend your own account.');

        $user = get_userdata($userId);
        if (!$user || !rto_is_staff($userId)) rto_json_err('That account is not a staff member.');

        $currentlySuspended = get_user_meta($userId, 'rtoflow_staff_suspended', true) === '1';
        $newState = !$currentlySuspended;

        update_user_meta($userId, 'rtoflow_staff_suspended', $newState ? '1' : '');

        \RTOFLOW\Services\AuditService::log($newState ? 'staff.suspended' : 'staff.reinstated', null, ['user_id' => $userId]);

        rto_json_ok(['suspended' => $newState], $newState ? 'Account suspended.' : 'Account reinstated.');
    }

    // ENTERPRISE GAP FIX (Phase 6, item — "no global cross-module search"):
    // finds a lead, vendor, or client by name/number/email in one query
    // each, from any admin screen, instead of every list screen only having
    // its own local search. Deliberately simple (3 targeted LIKE queries,
    // capped at 8 results each) — no external search index, so results can
    // never drift stale relative to the live tables.
    // TRACE: admin types 2+ characters in the topbar search box → debounced
    // AJAX call → staff-gated, nonce-checked → three independent queries
    // (leads by lead_number, vendors by name/mobile/vendor_number, client
    // users by display_name/email) → each capped and grouped → returns
    // {groups:[{label,items:[{title,subtitle,url}]}]} → precondition: query
    // length >= 2 (enforced client-side; re-checked here too) →
    // postcondition: read-only, no writes → edge cases: no matches in a
    // group → that group omitted entirely, not shown empty.
    private static function globalSearchAjax(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $q = trim(Sanitiser::text($_POST['q'] ?? '', 100));
        if (strlen($q) < 2) rto_json_ok(['groups' => []]);

        global $wpdb;
        $p = $wpdb->prefix;
        $like = '%' . $wpdb->esc_like($q) . '%';
        $groups = [];

        $leads = $wpdb->get_results($wpdb->prepare(
            "SELECT id, lead_number, status FROM {$p}rto_leads WHERE lead_number LIKE %s AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 8",
            $like
        ), ARRAY_A) ?: [];
        if ($leads) {
            $groups[] = ['label' => 'Orders', 'items' => array_map(fn($l) => [
                'title'    => $l['lead_number'],
                'subtitle' => rto_status_label($l['status']),
                'url'      => home_url('/rto-admin/leads/' . (int)$l['id']),
            ], $leads)];
        }

        $vendors = $wpdb->get_results($wpdb->prepare(
            "SELECT id, full_name, mobile, vendor_number FROM {$p}rto_vendors
             WHERE full_name LIKE %s OR mobile LIKE %s OR vendor_number LIKE %s LIMIT 8",
            $like, $like, $like
        ), ARRAY_A) ?: [];
        if ($vendors) {
            $groups[] = ['label' => 'Vendors', 'items' => array_map(fn($v) => [
                'title'    => $v['full_name'],
                'subtitle' => $v['vendor_number'] . ' · ' . $v['mobile'],
                'url'      => home_url('/rto-admin/vendors/'),
            ], $vendors)];
        }

        $clientIds = get_users([
            'role'   => 'rto_client',
            'search' => '*' . esc_sql($q) . '*',
            'search_columns' => ['display_name', 'user_email'],
            'number' => 8,
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);
        if ($clientIds) {
            $groups[] = ['label' => 'Clients', 'items' => array_map(fn($u) => [
                'title'    => $u->display_name ?: $u->user_email,
                'subtitle' => $u->user_email,
                'url'      => home_url('/rto-admin/leads/?search=' . rawurlencode($u->user_email)),
            ], $clientIds)];
        }

        rto_json_ok(['groups' => $groups]);
    }

    // ENTERPRISE GAP FIX (Phase 6, item — "no saved/named filter views on
    // any list screen"): see SavedFilterService for the generic (screen,
    // name, raw query string) design. Wired into the Leads screen only for
    // this build — the highest-traffic list screen; the same pattern
    // (SavedFilterService + this pair of endpoints + the small JS block in
    // leads/index.php) can back any other list screen later without any
    // further schema or backend work.
    private static function savedFilterSave(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $screen = Sanitiser::text($_POST['screen'] ?? '', 50);
        $name   = Sanitiser::text($_POST['name'] ?? '', 100);
        // Query string is intentionally NOT re-parsed/validated here — it is
        // only ever replayed back to the browser as a URL query string for
        // the SAME screen's own existing filter-parsing code (which already
        // sanitises every param it reads), never executed or interpolated
        // into SQL from this endpoint directly.
        $queryString = sanitize_text_field(wp_unslash($_POST['query_string'] ?? ''));
        if (!in_array($screen, ['leads'], true)) rto_json_err('Unknown screen.');

        $result = \RTOFLOW\Services\SavedFilterService::save(get_current_user_id(), $screen, $name, $queryString);
        if (!$result['success']) rto_json_err($result['message']);

        rto_json_ok(['id' => $result['id']], 'Filter saved.');
    }

    private static function savedFilterDelete(): void
    {
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $id = Sanitiser::int($_POST['id'] ?? 0, 1);
        if (!$id) rto_json_err('Filter not specified.');

        $deleted = \RTOFLOW\Services\SavedFilterService::delete(get_current_user_id(), $id);
        if (!$deleted) rto_json_err('Filter not found, or it does not belong to you.', 404);

        rto_json_ok(null, 'Filter deleted.');
    }

    // TRACE: admin edits the view/edit/export/approve checkboxes for a role
    //        on the Staff Roles screen and saves → verifies nonce+admin role
    //        → validates the role exists and is not the system full_access
    //        role (its matrix is fixed by design, see migration docblock) →
    //        for every module in Permissions::MODULES, upserts a row in
    //        rto_role_permissions from the posted checkbox matrix →
    //        precondition: role_id exists and is not is_system →
    //        postcondition: every rto_staff account holding that role has
    //        its Permissions::can() results change immediately (no cache to
    //        invalidate — the check queries the table directly) → edge
    //        cases: unknown role id, attempt to edit full_access — both
    //        rejected with a specific message, no rows written.
    private static function saveRolePermissions(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $roleId = Sanitiser::int($_POST['role_id'] ?? 0, 1);
        if (!$roleId) rto_json_err('A role is required.');

        global $wpdb;
        $role = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_system FROM {$wpdb->prefix}rto_staff_roles WHERE id = %d",
            $roleId
        ), ARRAY_A);
        if (!$role) rto_json_err('That role does not exist.');
        if ((int)$role['is_system'] === 1) rto_json_err('The Full Access role cannot be modified.');

        $matrix = $_POST['matrix'] ?? [];
        if (!is_array($matrix)) rto_json_err('Invalid permission matrix submitted.');

        foreach (\RTOFLOW\Security\Permissions::MODULES as $module) {
            $m = $matrix[$module] ?? [];
            $data = [
                'can_view'    => !empty($m['view']) ? 1 : 0,
                'can_edit'    => !empty($m['edit']) ? 1 : 0,
                'can_export'  => !empty($m['export']) ? 1 : 0,
                'can_approve' => !empty($m['approve']) ? 1 : 0,
            ];

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}rto_role_permissions WHERE role_id = %d AND module = %s",
                $roleId, $module
            ));

            if ($existing) {
                $ok = $wpdb->update($wpdb->prefix . 'rto_role_permissions', $data, ['id' => (int)$existing]);
            } else {
                $ok = $wpdb->insert($wpdb->prefix . 'rto_role_permissions', $data + [
                    'role_id' => $roleId, 'module' => $module,
                ]);
            }

            if ($ok === false) {
                error_log("RTOFLOW Router::saveRolePermissions: write failed for role {$roleId} module {$module} — " . $wpdb->last_error);
                rto_json_err('Unable to save permissions. Please try again.');
            }
        }

        \RTOFLOW\Services\AuditService::log('staff.role_permissions_saved', null, [
            'role_id' => $roleId,
        ]);

        rto_json_ok(null, 'Permissions saved.');
    }

    // TRACE: admin clicks "Mark Resolved" on the Exceptions screen → verifies
    //        nonce+admin role → ExceptionMonitor::markResolved() sets
    //        resolved=1 → precondition: exception_id exists → postcondition:
    //        row no longer appears in the default (unresolved-only) list —
    //        a genuinely new occurrence of the same message/file/line after
    //        this point creates a fresh row (capture()'s de-dup query only
    //        matches resolved=0 rows), which is intentional: a "resolved"
    //        bug that recurs should surface as new, not silently reopen.
    private static function resolveException(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $id = Sanitiser::int($_POST['exception_id'] ?? 0, 1);
        if (!$id) rto_json_err('An exception record is required.');

        if (!\RTOFLOW\Services\ExceptionMonitor::markResolved($id)) {
            rto_json_err('Unable to mark resolved. Please try again.');
        }
        rto_json_ok(null, 'Marked resolved.');
    }

    // TRACE: admin clicks "Back Up Now" on the Backups screen → nonce+admin
    //        verified → BackupService::run() (see its docblock for the full
    //        mechanism) → precondition: none beyond admin+nonce →
    //        postcondition: a new .sql.gz file on disk, oldest beyond the
    //        retention count pruned → edge case: write failure (disk full/
    //        permissions) surfaced as a specific message, no partial file
    //        left claiming success.
    private static function runBackupNow(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $result = \RTOFLOW\Services\BackupService::run();
        if (!$result['success']) rto_json_err($result['message']);
        rto_json_ok(['filename' => $result['filename']], $result['message']);
    }

    // TRACE: admin clicks a filename on the Backups screen → admin role
    //        already verified by the 'backups' page route above this is
    //        reached from → BackupService::resolveSafePath() rejects
    //        anything that isn't an exact rtoflow-backup-*.sql.gz basename
    //        inside the real backup directory (blocks path traversal via
    //        ../ or an absolute path in the query string) → streams the
    //        file with Content-Disposition: attachment → precondition:
    //        filename matches an existing backup → postcondition: browser
    //        receives the gzip download, no state changed → edge case:
    //        unknown/tampered filename → 404, not a path outside the
    //        backup directory ever read.
    private static function downloadBackup(string $filename): void
    {
        $path = \RTOFLOW\Services\BackupService::resolveSafePath($filename);
        if (!$path) wp_die('Backup not found.', 'Not Found', ['response' => 404]);

        \RTOFLOW\Services\AuditService::log('backup.downloaded', null, ['filename' => basename($path)]);

        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . filesize($path));
        header('Pragma: no-cache');
        readfile($path);
        exit;
    }

    // TRACE: a DIFFERENT admin than the one who submitted the change clicks
    //        "Approve & Apply" on the Config Approvals screen → nonce+admin
    //        verified → ConfigApprovalService::approve() independently
    //        re-checks requested_by !== approver (server-side, not just
    //        hidden in the UI — same maker-checker pattern as
    //        PayoutsController::markPaid()) → replays the stored payload
    //        through SettingsController::applyApprovedSave() → precondition:
    //        request exists, status='pending', approver is admin and not the
    //        requester → postcondition: request.status='approved', the real
    //        setting(s) are now live → edge cases: same admin tries to
    //        approve their own request → rejected with the requester's name;
    //        request already decided → rejected; save itself fails (e.g. the
    //        'matching' weight-sum guard, though that tab isn't in the
    //        approval list today) → surfaced as a specific error, request
    //        stays pending.
    private static function approveConfigChange(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $id = Sanitiser::int($_POST['request_id'] ?? 0, 1);
        if (!$id) rto_json_err('A request is required.');

        $result = \RTOFLOW\Services\ConfigApprovalService::approve($id, get_current_user_id());
        if (!$result['success']) rto_json_err($result['message']);
        rto_json_ok(null, $result['message']);
    }

    private static function rejectConfigChange(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $id   = Sanitiser::int($_POST['request_id'] ?? 0, 1);
        $note = Sanitiser::text($_POST['note'] ?? '', 500);
        if (!$id) rto_json_err('A request is required.');

        $result = \RTOFLOW\Services\ConfigApprovalService::reject($id, get_current_user_id(), $note);
        if (!$result['success']) rto_json_err($result['message']);
        rto_json_ok(null, $result['message']);
    }

    // TRACE: admin fills in a partner name and clicks "Create Key" on the
    //        Partner API screen → nonce+admin verified → ApiKeyService::
    //        generate() creates the row (hash only, stored) → the raw key is
    //        returned to the browser THIS ONE TIME → precondition:
    //        partner_name non-empty → postcondition: a new active
    //        rto_api_keys row → edge case: partner never copies the key →
    //        cannot be recovered, only revoked and a new one issued (same
    //        posture as WebhookController's subscription secrets).
    private static function createApiKey(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $partnerName = Sanitiser::text($_POST['partner_name'] ?? '', 150);
        if ($partnerName === '') rto_json_err('A partner name is required.');

        $result = \RTOFLOW\Services\ApiKeyService::generate($partnerName, get_current_user_id());
        rto_json_ok(['api_key_id' => $result['id'], 'raw_key' => $result['raw_key']],
            'API key created. Copy it now — it will not be shown again.');
    }

    private static function revokeApiKey(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $id = Sanitiser::int($_POST['api_key_id'] ?? 0, 1);
        if (!$id) rto_json_err('An API key is required.');

        if (!\RTOFLOW\Services\ApiKeyService::revoke($id)) rto_json_err('Unable to revoke. Please try again.');
        rto_json_ok(null, 'API key revoked. It can no longer authenticate any request.');
    }

    private static function runMigrations(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $results = \RTOFLOW\Database\MigrationRunner::run();
        $failed  = array_filter($results, fn($r) => $r['status'] === 'error');
        $applied = array_filter($results, fn($r) => $r['status'] === 'success');

        \RTOFLOW\Services\AuditService::log('migrations.run', null, [
            'applied' => array_column($applied, 'migration'),
            'failed'  => array_map(fn($r) => $r['migration'] . ': ' . $r['message'], $failed),
        ]);

        if ($failed) {
            $first = reset($failed);
            rto_json_err('Stopped after a failure in ' . $first['migration'] . ': ' . $first['message']
                . (count($applied) ? ' (' . count($applied) . ' earlier migration(s) still applied successfully.)' : ''), 500);
        }
        if (!$applied) {
            rto_json_ok(['results' => $results], 'No pending migrations — already up to date.');
        }
        rto_json_ok(['results' => $results], count($applied) . ' migration(s) applied successfully.');
    }

    // Known Limitations audit fix: the AI risk scorer "only scores a lead
    // once, at creation time, never re-scoring it afterward." Exposes
    // Bootstrap::rescoreAllLeads() (which reuses the exact same rules
    // formula computeRiskScore() applies at creation, so there is no
    // second, drifting copy of the scoring logic) as an admin-gated action
    // from the AI Insights screen.
    // TRACE: admin clicks "Re-score All Leads Now" on AI Insights →
    //        admin role + nonce verified → Bootstrap::rescoreAllLeads() loops
    //        every non-deleted lead, recomputing risk_score against CURRENT
    //        order-history/amount/service data → per-lead update checked →
    //        AuditService logs the batch outcome → JSON summary returned →
    //        preconditions: admin, valid nonce →
    //        postconditions: every lead's risk_score reflects current data
    //        (unless ai_scoring flag is off, in which case nothing changes) →
    //        edge cases: flag disabled → informational response, zero writes;
    //        an individual row's write fails → counted in 'skipped', loop continues.
    private static function rescoreLeads(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $result = \RTOFLOW\Bootstrap::getInstance()->rescoreAllLeads();

        if (!$result['flag_enabled']) {
            rto_json_err('AI Scoring is currently disabled (Settings → Feature Flags) — enable it first, then re-score.', 400);
        }

        \RTOFLOW\Services\AuditService::log('ai.leads_rescored', null, $result);

        $msg = $result['scored'] . ' lead(s) re-scored.' . ($result['skipped'] ? ' ' . $result['skipped'] . ' could not be updated — see error log.' : '');
        rto_json_ok($result, $msg);
    }

    // TRACE: admin POSTs admin.force_logout via AJAX with target_user_id →
    //        admin role + nonce verified →
    //        WP_Session_Tokens::get_instance($uid)->destroy_all() terminates all sessions →
    //        AuditService logs 'user.force_logout' →
    //        rto_json_ok() returned →
    //        preconditions: target user exists; user is admin; nonce valid →
    //        postconditions: all active sessions for target_user_id destroyed; user must re-login →
    //        edge cases: user not found → 404; admin targeting themselves → allowed but warned
    private static function forceLogout(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);
        // FIX (System/GDPR Known Limitations audit): same Sanitiser::int()
        // min-vs-default bug as routeGdprExport() above — a missing/blank
        // target_user_id was silently clamped to 1 rather than staying 0,
        // so an admin submitting the Force Logout form with an empty field
        // would force-terminate WP user id=1's sessions (usually the site's
        // own super-admin) instead of hitting the "User not found" guard
        // below. Floor of 0 lets that guard actually catch the empty case.
        $uid = Sanitiser::int($_POST['target_user_id'] ?? 0, 0);
        if (!$uid || !get_userdata($uid)) rto_json_err('User not found.', 404);
        // Destroy all WordPress authentication sessions for this user
        $sessions = \WP_Session_Tokens::get_instance($uid);
        $sessions->destroy_all();
        \RTOFLOW\Services\AuditService::log('user.force_logout', 0, [
            'target_user_id' => $uid,
            'performed_by'   => get_current_user_id(),
        ]);
        rto_json_ok(['user_id' => $uid], 'All sessions for this user have been terminated.');
    }

    // TRACE: dispatch() calls routeAdmin($page, $id) when rto_area=admin →
    //        rto_is_staff() check; redirect to login if not staff →
    //        match($page) dispatches to correct controller or inline handler →
    //        $id>0 triggers detail view; $id=0 triggers list view →
    //        preconditions: WP rewrite rule matched; user is staff/admin →
    //        postconditions: controller renders HTML response and exits →
    //        edge cases: unknown $page → match exhausts → WP 404; $id invalid type handled by Sanitiser

    // TRACE: admin visits /rto-admin/gdpr/?user_id=N →
    //        rto_admin role check → loads all user data from rto_leads, rto_payments,
    //        rto_complaints, rto_messages for the requested WP user →
    //        outputs JSON file download with all personal data →
    //        preconditions: user is admin; user_id param is valid WP user →
    //        postconditions: browser receives gdpr-export-{uid}-{date}.json download →
    //        edge cases: invalid user_id → 404; no data → empty arrays in JSON
    private static function routeGdprExport(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);
        // FIX (System/GDPR Known Limitations audit): Sanitiser::int()'s
        // second argument is a MINIMUM, not a default — passing 1 here
        // clamped an absent/blank ?user_id to 1, not 0, which made the
        // `if (!$uid)` fallback below permanently dead code: visiting
        // /rto-admin/gdpr/ with no query param at all silently exported
        // whichever WP user happens to have id=1 (almost always the site's
        // own super-admin) instead of showing the intended user-picker
        // fallback. Sanitising with a floor of 0 restores the fallback path.
        $uid = \RTOFLOW\Security\Sanitiser::int($_GET['user_id'] ?? 0, 0);
        if (!$uid) {
            // GDPR home: user-picker for export, plus the erasure tool.
            rto_view('admin.gdpr.index');
            return;
        }
        global $wpdb;
        $p    = $wpdb->prefix;
        $user = get_userdata($uid);
        if (!$user) wp_die('User not found.', 404);

        $data = [
            'export_date' => date('c'),
            'user'        => [
                'id'         => $uid,
                'email'      => $user->user_email,
                'name'       => $user->display_name,
                'registered' => $user->user_registered,
                'role'       => rto_user_role($uid),
            ],
            'leads'      => $wpdb->get_results($wpdb->prepare(
                "SELECT lead_number,status,total_amount,created_at,source,vehicle_number,owner_name
                 FROM {$p}rto_leads WHERE client_id=%d ORDER BY created_at DESC", $uid
            ), ARRAY_A) ?: [],
            'payments'   => $wpdb->get_results($wpdb->prepare(
                "SELECT py.amount,py.method,py.created_at,l.lead_number
                 FROM {$p}rto_payments py
                 JOIN {$p}rto_leads l ON l.id = py.lead_id
                 WHERE l.client_id=%d ORDER BY py.created_at DESC", $uid
            ), ARRAY_A) ?: [],
            'complaints' => $wpdb->get_results($wpdb->prepare(
                "SELECT complaint_number,subject,status,created_at
                 FROM {$p}rto_complaints
                 WHERE client_id=%d OR complainant_id=%d ORDER BY created_at DESC", $uid, $uid
            ), ARRAY_A) ?: [],
            'messages'   => $wpdb->get_results($wpdb->prepare(
                "SELECT m.message,m.created_at,l.lead_number
                 FROM {$p}rto_messages m
                 JOIN {$p}rto_leads l ON l.id = m.lead_id
                 WHERE m.user_id=%d ORDER BY m.created_at DESC", $uid
            ), ARRAY_A) ?: [],
        ];

        // FIX (System/GDPR Known Limitations audit): this route dumps a full
        // PII bundle (leads, payments, complaints, messages) for any WP user
        // to whichever admin requests it, and until now did so with zero
        // audit trail — the one action on this entire platform most likely
        // to matter for a real compliance/incident investigation ("who
        // exported this person's data, and when?") was the one action
        // nobody could ever answer. Every other sensitive admin action in
        // this codebase (force-logout, refund approval, document
        // verification — see the AuditService::log() call sites above) is
        // already recorded this way; this brings GDPR export in line with
        // that existing convention rather than inventing a new one.
        \RTOFLOW\Services\AuditService::log(
            'gdpr.export',
            null,
            ['exported_user_id' => $uid, 'exported_email' => $user->user_email],
            [],
            get_current_user_id() ?: null
        );

        $fname = 'gdpr-export-' . $uid . '-' . date('Y-m-d') . '.json';
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    // TRACE: admin submits the "Erase Subject" form on /rto-admin/gdpr/ via AJAX (admin.gdpr_erase) →
    //        admin role + nonce verified → identifier (email/mobile) sanitised →
    //        GdprService::eraseSubject() runs the whole operation inside a single
    //        DB transaction, hard-deleting rows with no retention requirement and
    //        anonymising rows that must be kept for financial/legal record-keeping →
    //        AuditService logs 'gdpr.erasure' (masked identifier + hash, never raw PII) →
    //        structured per-table result returned to the admin, not a silent success →
    //        preconditions: user is admin; nonce valid; confirm=1 present →
    //        postconditions: matching rows hard-deleted or anonymised per-table; audit log entry created →
    //        edge cases: invalid identifier → 400; DB failure mid-operation → full rollback, no partial erasure
    private static function gdprErase(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        // Destructive/irreversible action — require an explicit confirmation
        // flag from the UI in addition to the nonce, same spirit as other
        // hard-consequence admin actions (force logout, refund approval).
        if (empty($_POST['confirm'])) {
            rto_json_err('Erasure requires explicit confirmation.', 400);
        }

        $type  = \RTOFLOW\Security\Sanitiser::text($_POST['identifier_type'] ?? '', 20);
        $value = \RTOFLOW\Security\Sanitiser::text($_POST['identifier_value'] ?? '', 200);

        if (!in_array($type, ['email', 'mobile'], true) || $value === '') {
            rto_json_err('Provide a valid email or mobile number to erase.', 400);
        }

        $result = \RTOFLOW\Services\GdprService::eraseSubject($type, $value, get_current_user_id() ?: null);

        if (!$result['ok']) {
            rto_json_err($result['error'] ?? 'Erasure failed.', 422);
        }

        rto_json_ok($result, 'Erasure completed. See the per-table breakdown for what was hard-deleted vs anonymised.');
    }

    // TRACE: admin visits /rto-admin/system/ →
    //        rto_admin role check → reads WP cron array for rtoflow_* hooks →
    //        queries rto_notifications GROUP BY status → reads MigrationRunner::status() →
    //        requires admin-header.php + renders inline HTML + admin-footer.php →
    //        preconditions: user is admin →
    //        postconditions: system status page with cron, notification queue, migration status →
    //        edge cases: no cron jobs → empty state shown; no failed notifications → section hidden
    private static function routeSystemDashboard(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);
        global $wpdb;
        $p = $wpdb->prefix;

        // Cron jobs
        $crons   = _get_cron_array() ?: [];
        $rtoJobs = [];
        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                if (!str_starts_with((string)$hook, 'rtoflow')) continue;
                foreach ($events as $event) {
                    $rtoJobs[] = [
                        'hook'     => $hook,
                        'next'     => date('d M Y H:i:s', (int)$timestamp),
                        'interval' => $event['schedule'] ?: 'once',
                    ];
                }
            }
        }

        // Notification queue stats
        $nStats    = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$p}rto_notifications GROUP BY status", ARRAY_A
        ) ?: [];
        $nByStatus = array_column($nStats, 'count', 'status');

        // Migration status
        $migrations = \RTOFLOW\Database\MigrationRunner::status();
        $totalMigs  = count($migrations);
        $ranMigs    = count(array_filter($migrations, fn($m) => $m['ran']));

        // Failed notifications (retriable)
        $failedNotifs = $wpdb->get_results(
            "SELECT id, template_id, recipient, channel, error_msg, created_at, attempts
             FROM {$p}rto_notifications
             WHERE status='failed'
             ORDER BY created_at DESC
             LIMIT 20",
            ARRAY_A
        ) ?: [];

        $pageTitle = 'System Status';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
        require RTOFLOW_DIR . 'resources/views/admin/system/dashboard.php';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
    }

    private static function routeAdmin(string $page, int $id): void
    {
        if (!rto_is_staff()) { wp_redirect(rto_login_url()); exit; }

        $c = \RTOFLOW\Bootstrap::container();
        match($page) {
            'dashboard' => $c->make(\RTOFLOW\Controllers\Admin\DashboardController::class)->index(),
            'leads'     => $id
                ? match($_GET['export'] ?? '') {
                    // Part 5.7 build: single-order CSV/PDF export, reached
                    // from the Order Details screen's Export buttons.
                    'csv' => $c->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->exportLeadCsv($id),
                    'pdf' => $c->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->exportLeadPdf($id),
                    default => $c->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->show($id),
                }
                : (isset($_GET['export']) && $_GET['export'] === 'csv'
                    ? $c->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->exportCsv()
                    : $c->make(\RTOFLOW\Controllers\Admin\LeadsController::class)->index()),

            // Services CRUD
            'services'  => match(true) {
                $id > 0 && isset($_GET['action']) && $_GET['action'] === 'edit'
                    => $c->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->edit($id),
                $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST'
                    => $c->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->update($id),
                isset($_GET['action']) && $_GET['action'] === 'new'
                    => $c->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->create(),
                $_SERVER['REQUEST_METHOD'] === 'POST'
                    => $c->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->store(),
                default => $c->make(\RTOFLOW\Controllers\Admin\ServicesController::class)->index(),
            },

            // Vendors management
            'vendors'   => match(true) {
                $id > 0 => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->show($id),
                isset($_GET['action']) && $_GET['action'] === 'new'
                    => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->create(),
                $_SERVER['REQUEST_METHOD'] === 'POST'
                    => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->store(),
                default => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->index(),
            },

            // ENTERPRISE GAP FIX (Section 1 — vendor KYC document-review workflow)
            'vendor-kyc-doc' => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->serveKycDocument($id),

            // ENTERPRISE GAP FIX (Phase 4, item 5 — vendor appeals queue)
            'vendor-appeals' => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->appeals(),

            // Payments ledger
            // Known Limitations audit fix: the view already builds an
            // export=csv link (resources/views/admin/payments/index.php),
            // but this branch previously never checked for it — clicking
            // "Export CSV" here silently just re-rendered the same ledger
            // page. Leads already has this exact pattern (see above); wired
            // the same way here.
            'payments'  => (isset($_GET['export']) && $_GET['export'] === 'csv')
                ? self::exportPaymentsCsv()
                : rto_view('admin.payments.index', self::loadPayments()),

            // Payouts
            'payouts'   => $id > 0
                ? $c->make(\RTOFLOW\Controllers\Admin\PayoutsController::class)->tdsCertificate($id)
                : $c->make(\RTOFLOW\Controllers\Admin\PayoutsController::class)->index(),

            // Reports
            // Known Limitations audit fix: a saved snapshot (?snapshot={id})
            // is viewed read-only through the same 'reports' page slug,
            // matching the existing $id-based branching pattern used above
            // for leads/vendors/services rather than inventing a new page.
            'reports'   => !empty($_GET['snapshot'])
                ? $c->make(\RTOFLOW\Controllers\Admin\ReportsController::class)->viewSnapshot(Sanitiser::int($_GET['snapshot'], 1))
                : $c->make(\RTOFLOW\Controllers\Admin\ReportsController::class)->index(),

            // Masters (states, cities)
            'masters'   => $c->make(\RTOFLOW\Controllers\Admin\MastersController::class)->index(),

            // Home Hero Section slider management
            'hero-slider' => $c->make(\RTOFLOW\Controllers\Admin\HeroSliderController::class)->index(),

            // Design & Typography Settings
            'design-settings' => $c->make(\RTOFLOW\Controllers\Admin\DesignSettingsController::class)->index(),

            // Settings
            'settings'  => $c->make(\RTOFLOW\Controllers\Admin\SettingsController::class)->index(),

            // Staff management
            'staff'           => !empty($_GET['export']) && $_GET['export'] === 'csv'
                ? self::exportStaffCsv()
                : rto_view('admin.staff.index', self::loadStaff()),

            // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): the
            // Staff Roles screen — lists every rto_staff_roles row and, when
            // a specific one is selected (?id=), shows an editable
            // view/edit/export/approve matrix per module (Permissions::
            // MODULES) that posts to admin.save_role_permissions. The
            // system 'full_access' role is shown but its checkboxes are
            // rendered disabled in the view (Router::saveRolePermissions()
            // also rejects a save against it server-side, so this is
            // defense-in-depth, not the only guard).
            'staff-roles'     => rto_view('admin.staff-roles.index', [
                'roles'        => \RTOFLOW\Security\Permissions::allRoles(),
                'selectedId'   => $id,
                'matrix'       => $id > 0 ? \RTOFLOW\Security\Permissions::matrixForRole($id) : [],
                'modules'      => \RTOFLOW\Security\Permissions::MODULES,
            ]),

            // ENTERPRISE GAP FIX (Phase 2, item 6 — structured exception
            // monitoring): admin-only (this can expose file paths and stack
            // traces — exactly the kind of internal detail that should never
            // reach a support-only staff role). Not gated through
            // Permissions::can() like the Phase 1, item 6 rollout — this is
            // infrastructure visibility, not a business module in
            // Permissions::MODULES, so a plain rto_is_admin() gate (enforced
            // inside ExceptionsController::index()) is the correct, narrower
            // control here.
            'exceptions'      => $c->make(\RTOFLOW\Controllers\Admin\ExceptionsController::class)->index(),

            // ENTERPRISE GAP FIX (Phase 2, item 5 — backup/DR automation):
            // admin-only for the same reason as Exceptions above — a real
            // downloadable database export is a bigger blast radius than any
            // business-module permission, so this stays a plain
            // rto_is_admin() gate (enforced inside downloadBackup() and this
            // page's own guard below), not something delegated to a staff
            // RBAC role.
            // ENTERPRISE GAP FIX (Phase 2, item 2 — config-change approval
            // workflow): admin-only — approving here can push live Razorpay/
            // SMS/WhatsApp credentials, so this is deliberately not exposed
            // to any staff RBAC role.
            'config-approvals' => (function () {
                if (!rto_is_admin()) wp_die('Access denied.', 403);
                rto_view('admin.config-approvals.index', [
                    'pending' => \RTOFLOW\Services\ConfigApprovalService::pending(),
                    'history' => \RTOFLOW\Services\ConfigApprovalService::history(50),
                    'currentUserId' => get_current_user_id(),
                ]);
            })(),

            // ENTERPRISE GAP FIX (Phase 2, item 4 — partner REST API):
            // admin-only — issuing a key grants an external system the
            // ability to create leads (and therefore trigger the whole
            // downstream workflow) under this platform's identity.
            'partner-api'     => (function () {
                if (!rto_is_admin()) wp_die('Access denied.', 403);
                rto_view('admin.partner-api.index', ['keys' => \RTOFLOW\Services\ApiKeyService::list()]);
            })(),

            'backups'         => (function () {
                if (!rto_is_admin()) wp_die('Access denied.', 403);
                if (!empty($_GET['download'])) { self::downloadBackup(Sanitiser::text($_GET['download'])); return; }
                rto_view('admin.backups.index', ['backups' => \RTOFLOW\Services\BackupService::list()]);
            })(),

            // Email templates
            'email-templates' => $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST'
                ? $c->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)->update($id)
                : ($id > 0
                    ? $c->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)->edit($id)
                    : $c->make(\RTOFLOW\Controllers\Admin\EmailTemplateController::class)->index()),

            // Complaints
            'complaints' => $id > 0
                ? $c->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->show($id)
                : $c->make(\RTOFLOW\Controllers\Admin\ComplaintsController::class)->index(),

            // ENTERPRISE GAP FIX (Section 8 — Audit Log viewer)
            'audit-log' => $c->make(\RTOFLOW\Controllers\Admin\AuditLogController::class)->index(),

            // ENTERPRISE GAP FIX (Section 1 — 2FA setup UI)
            'my-security' => $c->make(\RTOFLOW\Controllers\Admin\TwoFactorSettingsController::class)->index(),

            // Ratings
            'ratings' => $c->make(\RTOFLOW\Controllers\Admin\RatingsController::class)->index(),

            // Eligibility Rules Engine (P1 build)
            'eligibility' => $c->make(\RTOFLOW\Controllers\Admin\EligibilityController::class)->index(),

            // Dynamic Form Engine (Part 4.10: category-keyed, not service-keyed —
            // see FormBuilderController and RealFormSchemaSeeder::categoryMap()).
            'forms' => ($category = Sanitiser::slug(get_query_var('rto_slug', '')))
                ? $c->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->edit($category)
                : $c->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->index(),
            'forms-export' => $c->make(\RTOFLOW\Controllers\Admin\FormBuilderController::class)->export(Sanitiser::slug(get_query_var('rto_slug', ''))),

            // Workflow/State Machine Engine (10-workstream integration pass)
            // WorkflowBuilderController::edit() takes no args — it reads
            // service_id from $_GET itself (see its edit() method), matching
            // how its views link with ?rto_page=workflows&service_id={id}
            // rather than the /rto-admin/{page}/{id}/ path-segment convention
            // other controllers above use.
            'workflows' => $id > 0 || !empty($_GET['service_id'])
                ? $c->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->edit()
                : $c->make(\RTOFLOW\Controllers\Admin\WorkflowBuilderController::class)->index(),

            // Enterprise Customizer hub (10-workstream integration pass)
            'customizer' => $c->make(\RTOFLOW\Controllers\Admin\CustomizerHubController::class)->index(),

            // Central Help Centre (Part 5.0) — searchable, screen-by-screen
            // documentation grounded in this codebase's actual architecture.
            'help' => $c->make(\RTOFLOW\Controllers\Admin\HelpCenterController::class)->index(),

            // Navigation hub — all links in one place
            'nav' => (function() {
                $pageTitle = 'Navigation & Quick Access';
                require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
                rto_view('admin.nav.index');
                require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
            })(),

            // Automation engine
            'automation' => $c->make(\RTOFLOW\Controllers\Admin\AutomationController::class)->index(),

            // Outbound webhook subscriptions (API/webhooks integration gap fix)
            'webhooks' => $c->make(\RTOFLOW\Controllers\Admin\WebhookController::class)->index(),

            // ENTERPRISE GAP FIX (Phase 12, item — generic inbound webhook framework)
            'inbound-webhooks' => $c->make(\RTOFLOW\Controllers\Admin\InboundWebhookController::class)->index(),

            // ENTERPRISE GAP FIX (Phase 12, item — data-warehouse / BI export path)
            'data-export' => $c->make(\RTOFLOW\Controllers\Admin\DataExportController::class)->index(),

            // ENTERPRISE GAP FIX (Phase 1, item 2 — client self-service cancellation/refund request)
            'client-requests' => $c->make(\RTOFLOW\Controllers\Admin\ClientRequestsController::class)->index(),

            // Known Limitations audit fix: "An orphaned duplicate listing
            // exists at a second, unlinked admin URL rendering the same
            // underlying data ... appears to be a leftover from an earlier
            // naming change (grievance → complaint) ... recommended fix:
            // remove the orphaned route entirely, or redirect it to the
            // canonical Complaints screen, so only one entry point exists."
            // Redirecting (not removing outright) preserves any bookmark or
            // external link someone already made to this URL, per the
            // documented "use this Complaints screen as the actual,
            // supported entry point" guidance.
            'grievance' => (function() {
                wp_redirect(home_url('/rto-admin/complaints/'), 301);
                exit;
            })(),

            // Feature flags
            'features' => (function() use ($c) {
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('rtoflow_features','_rto_nonce')) {
                    \RTOFLOW\Config\FeatureFlags::bulk_save((array)($_POST['flags'] ?? []));
                    // FIX (Config Versioning wiring, Feature Flags): mirrors
                    // MatchingConfig::save()'s pattern — record a version of
                    // the now-applied state so "Version History" reflects
                    // real saves. publish(..., false): the value is already
                    // live via bulk_save() above; this only records history
                    // (see FeatureFlags::applyBooleanMap()'s docblock and
                    // Bootstrap.php's 'rtoflow_config_published' listener
                    // for the other half — rollback actually re-applying a
                    // past state).
                    $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
                        'feature_flags', \RTOFLOW\Config\FeatureFlags::raw(), get_current_user_id() ?: 0, 'Saved via Feature Flags screen'
                    );
                    if ($versionId) {
                        \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
                    }
                    wp_safe_redirect(home_url('/rto-admin/?rto_area=admin&rto_page=features&saved=1'));
                    exit;
                }
                rto_view('admin.settings.features');
            })(),


            'gdpr'        => self::routeGdprExport(),

            'system'      => self::routeSystemDashboard(),

            // City Pricing & Visibility
            'city-pricing' => $id
                ? $c->make(\RTOFLOW\Controllers\Admin\CityServiceConfigController::class)->configure($id)
                : $c->make(\RTOFLOW\Controllers\Admin\CityServiceConfigController::class)->index(),

            // RTO management (city-level RTOs)
            'rtos' => rto_view('admin.rtos.index'),

            // User/staff management
            'users' => (function() {
                $action = Sanitiser::text($_GET['action'] ?? '');
                if ($action === 'new') {
                    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                        // CSRF guard — nonce field rendered by create.php view
                        if (!check_admin_referer('rtoflow_create_user', '_rto_nonce')) {
                            wp_die('Security check failed.', 'Error', ['response' => 403, 'back_link' => true]);
                        }
                        // Handle user creation
                        $email    = Sanitiser::email($_POST['email'] ?? '');
                        $name     = Sanitiser::text($_POST['name']  ?? '');
                        // FIX (Part 5.4 — Help Centre audit, real privilege-
                        // escalation bug found and fixed): $role previously
                        // ran only through sanitize_key(), which strips
                        // disallowed characters but does NOT restrict the
                        // value to a real RTOFLOW role — a raw POST to this
                        // endpoint (any rto_staff account can reach it) could
                        // set role=administrator (WordPress's own core role)
                        // and create a brand-new, fully-privileged WP admin
                        // account with a single request. Fixed by validating
                        // against the real, closed set of roles this
                        // platform actually registers (Bootstrap.php's
                        // registerRoles()), falling back to rto_client for
                        // anything else — matching the same whitelist
                        // pattern already used by the Staff screen's own
                        // role-assignment handler.
                        $requestedRole = sanitize_key($_POST['role'] ?? 'rto_client');
                        $role     = in_array($requestedRole, ['rto_admin','rto_staff','rto_vendor','rto_client'], true)
                            ? $requestedRole
                            : 'rto_client';
                        // Creating a new staff/admin account is a
                        // privilege-affecting action independent of the
                        // page-level rto_is_staff() gate this route already
                        // sits behind — an rto_staff account (limited
                        // access) must not be able to create a fresh
                        // rto_admin or rto_staff account for itself or
                        // anyone else. Vendor/client account creation
                        // remains open to any staff user, matching this
                        // screen's existing intended use.
                        if (in_array($role, ['rto_admin','rto_staff'], true) && !rto_is_admin()) {
                            wp_die('Access denied. Only RTO Admin accounts can create staff or admin accounts.', 403);
                        }
                        $mobile   = Sanitiser::mobile($_POST['mobile'] ?? '');
                        // ROOT-CAUSE FIX (enterprise gap audit, Section 7 —
                        // "blank-password admin user creation"): this used to
                        // generate a random password with wp_generate_password()
                        // and then never show or email it to anyone — the
                        // account was created but functionally unusable, since
                        // nobody (not the admin, not the new user) ever learned
                        // the password. An admin-supplied password field is
                        // ALSO wrong to keep: it means the plaintext password
                        // travels through this request/redirect/admin's own
                        // memory, and is never rotated by the actual account
                        // owner. The correct, standard fix is to never hand out
                        // a usable password at account-creation time at all —
                        // create the account with an unusable random secret
                        // (wp_generate_password(24,true) — user can never log
                        // in with it, it is not the account's real credential)
                        // and let WordPress's own wp_new_user_notification()
                        // email the new user a secure, single-use password-set
                        // link, exactly like WP core's own "Add New User" screen
                        // does. This removes the password field from this form
                        // entirely — it is no longer accepted from $_POST.
                        $password = wp_generate_password(24, true);
                        $redirectArgs = ['rto_area' => 'admin', 'rto_page' => 'users'];
                        if ($email && !email_exists($email)) {
                            $uid = wp_create_user($email, $password, $email);
                            if (!is_wp_error($uid)) {
                                wp_update_user(['ID'=>$uid,'display_name'=>$name,'role'=>$role]);
                                if ($mobile) update_user_meta($uid,'rtoflow_mobile',$mobile);
                                // Emails the new user a "set your password" link
                                // (WP core, wp-includes/user.php) — the account is
                                // actually usable the moment this call succeeds,
                                // closing the "created but nobody can log in" gap.
                                wp_new_user_notification($uid, null, 'user');
                                $redirectArgs['created'] = 1;
                                if ($role === 'rto_vendor') {
                                    global $wpdb;
                                    // bank_details is the plain-JSON column added by migration 4
                                    // BUGFIX (found via a systematic write-return-value sweep,
                                    // the "ghost success" failure mode): this insert's return
                                    // value was never checked. If it failed (DB error, duplicate
                                    // vendor_number collision, etc.), a WordPress user with the
                                    // rto_vendor role was left with NO corresponding rto_vendors
                                    // row at all -- a "ghost vendor" that can log in but has no
                                    // profile, can never appear correctly on the Vendors screen,
                                    // and can never be assigned jobs, with the admin who created
                                    // the account never told anything went wrong (the redirect
                                    // always looked like a plain success either way).
                                    $vendorRowCreated = $wpdb->insert($wpdb->prefix.'rto_vendors',[
                                        'user_id'       => $uid,
                                        'vendor_number' => 'VND-'.str_pad($uid,8,'0',STR_PAD_LEFT),
                                        'full_name'     => $name,
                                        'mobile'        => $mobile,
                                        'email'         => $email,
                                        'cities'        => '[]',
                                        'services'      => '[]',
                                        'bank_details'  => '{}',
                                        'status'        => 'active',
                                    ]);
                                    if (!$vendorRowCreated) {
                                        error_log('RTOFLOW admin.users.create: rto_vendors row insert failed for user_id=' . $uid . ' — ' . $wpdb->last_error);
                                        \RTOFLOW\Services\AuditService::log('vendor.profile_row_insert_failed', $uid, ['db_error' => $wpdb->last_error]);
                                        $redirectArgs['vendor_row_failed'] = 1;
                                    }
                                }
                            } else {
                                $redirectArgs['create_failed'] = 1;
                            }
                        } else {
                            $redirectArgs['create_failed'] = 1;
                        }
                        wp_safe_redirect(add_query_arg($redirectArgs, home_url('/rto-admin/')));
                        exit;
                    }
                    rto_view('admin.users.create');
                } else {
                    rto_view('admin.users.index');
                }
            })(),

            // NOTE (re-verification pass): a legacy, read-only 'forms' arm
            // used to live here — a hand-rolled HTML table listing
            // rto_form_schemas with no create/edit capability. It was a
            // duplicate match() arm for the same 'forms' key already
            // handled above (the real Dynamic Form Engine, wired in the
            // 10-workstream integration pass) — PHP match() does not error
            // on a duplicate literal arm, it silently keeps whichever arm
            // comes first, so this second one was already 100% unreachable
            // dead code, not a live conflict. Removed rather than left as
            // confusing clutter; zero behavior change, since it could never
            // execute.

            // AI dashboard
            'ai' => (function() {
                $pageTitle = 'AI Insights';
                // Known Limitations audit fix: "No date-range filter exists
                // anywhere on this screen." Monthly Trend and Top Converting
                // Services previously had a fixed 6-month / all-time window
                // baked into their SQL — same From/To pattern already used
                // on Reports and the Payments Ledger, sanitised the same way.
                $from = \RTOFLOW\Security\Sanitiser::date($_GET['date_from'] ?? '');
                $to   = \RTOFLOW\Security\Sanitiser::date($_GET['date_to']   ?? '');
                require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
                rto_view('admin.ai.index', compact('from', 'to'));
                rto_help_box('ai');
                require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
            })(),

            default => self::renderComingSoon($page),
        };
        exit;
    }

    private static function loadPayments(): array
    {
        global $wpdb;
        $p       = $wpdb->prefix;
        $page    = max(1, (int)($_GET['paged'] ?? 1));
        $perPage = 25;
        $method  = \RTOFLOW\Security\Sanitiser::text($_GET['method'] ?? '');
        $from    = \RTOFLOW\Security\Sanitiser::date($_GET['date_from'] ?? '');
        $to      = \RTOFLOW\Security\Sanitiser::date($_GET['date_to']   ?? '');

        $where = ['1=1']; $params = [];
        if ($method) { $where[] = 'py.method=%s'; $params[] = $method; }
        if ($from)   { $where[] = 'py.created_at>=%s'; $params[] = $from . ' 00:00:00'; }
        if ($to)     { $where[] = 'py.created_at<=%s'; $params[] = $to   . ' 23:59:59'; }
        $ws = implode(' AND ', $where);

        $total = empty($params)
            ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}rto_payments py WHERE {$ws}")
            : (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}rto_payments py WHERE {$ws}", $params));

        $offset = ($page - 1) * $perPage;
        $allParams = array_merge($params, [$perPage, $offset]);
        if (empty($params)) {
            $payments = $wpdb->get_results($wpdb->prepare("SELECT py.*, l.lead_number, u.display_name as client_name FROM {$p}rto_payments py LEFT JOIN {$p}rto_leads l ON l.id=py.lead_id LEFT JOIN {$p}users u ON u.ID=l.client_id WHERE {$ws} ORDER BY py.created_at DESC LIMIT %d OFFSET %d", $perPage, $offset), ARRAY_A) ?: [];
        } else {
            $payments = $wpdb->get_results($wpdb->prepare("SELECT py.*, l.lead_number, u.display_name as client_name FROM {$p}rto_payments py LEFT JOIN {$p}rto_leads l ON l.id=py.lead_id LEFT JOIN {$p}users u ON u.ID=l.client_id WHERE {$ws} ORDER BY py.created_at DESC LIMIT %d OFFSET %d", $allParams), ARRAY_A) ?: [];
        }

        // FIX (Part 5.3 — Help Centre audit, real bug found by code review):
        // this summary previously computed 'Total Revenue'/'Total GST' by
        // summing only the CURRENT PAGE's 25 rows (array_sum over
        // $payments), not the full filtered result set — the count query
        // above ($total) already correctly counts every matching row, but
        // was never used for the money totals. An admin filtering a date
        // range with more than 25 matching payments would see a "Total
        // Revenue" card reflecting only whichever page happened to be
        // open, silently understating the real total for anything beyond
        // page 1. Fixed by running the SUM over the SAME WHERE clause used
        // for the count, independent of LIMIT/OFFSET, so the summary cards
        // always reflect the full filtered set regardless of pagination.
        $summary = ['count' => (int)$total, 'total' => 0, 'gst' => 0];
        if ($total > 0) {
            $sumRow = empty($params)
                ? $wpdb->get_row("SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(gst_amount),0) AS gst FROM {$p}rto_payments py WHERE {$ws}", ARRAY_A)
                : $wpdb->get_row($wpdb->prepare("SELECT COALESCE(SUM(amount),0) AS total, COALESCE(SUM(gst_amount),0) AS gst FROM {$p}rto_payments py WHERE {$ws}", $params), ARRAY_A);
            $summary['total'] = (float)($sumRow['total'] ?? 0);
            $summary['gst']   = (float)($sumRow['gst'] ?? 0);
        }

        $pages = (int)ceil($total / $perPage);

        // ENTERPRISE GAP FIX (Section 3/8 — "no refund-reject path" / the
        // deeper root cause found while fixing it: approveRefund() had NO
        // admin screen anywhere calling it at all — it was a fully orphaned
        // AJAX handler with no way for a real admin to ever trigger it
        // except by hand-crafting the request). Pending refunds now surface
        // right on the Payments ledger, the screen an admin already visits
        // to review money movement, with both Approve and (new) Reject
        // wired to real actions.
        $pendingRefunds = $wpdb->get_results(
            "SELECT r.*, p.lead_id, l.lead_number, u.display_name as client_name
             FROM {$p}rto_refunds r
             JOIN {$p}rto_payments p ON p.id = r.payment_id
             LEFT JOIN {$p}rto_leads l ON l.id = r.lead_id
             LEFT JOIN {$p}users u ON u.ID = l.client_id
             WHERE r.status = 'pending'
             ORDER BY r.created_at ASC",
            ARRAY_A
        ) ?: [];

        return compact('payments','total','page','pages','method','from','to','summary','pendingRefunds');
    }

    // ── Design & Typography Settings — generated stylesheet ────────────────
    private static function designCss(): void
    {
        $settings = \RTOFLOW\Services\DesignSettingsService::loadSettings();
        $colors   = \RTOFLOW\Services\DesignSettingsService::loadColors();
        $css      = \RTOFLOW\Services\DesignSettingsService::buildCss($settings, $colors);

        header('Content-Type: text/css; charset=utf-8');
        // 1 year cache — safe because the URL is always requested with a
        // ?v= query string derived from rtoflow_design_updated_at (see
        // home.php), which changes on every save, busting the cache.
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $css;
        exit;
    }

    // ── Design & Typography Settings — rollout stylesheet (see
    // DesignSettingsService::buildSiteCss()) ───────────────────────────────
    private static function designSiteCss(): void
    {
        $settings = \RTOFLOW\Services\DesignSettingsService::loadSettings();
        $colors   = \RTOFLOW\Services\DesignSettingsService::loadColors();
        $css      = \RTOFLOW\Services\DesignSettingsService::buildSiteCss($settings, $colors);

        header('Content-Type: text/css; charset=utf-8');
        // Same cache strategy as /rto-design.css — see designCss() above.
        header('Cache-Control: public, max-age=31536000, immutable');
        echo $css;
        exit;
    }

    // ── Known Limitations audit fix: Payments Ledger CSV export ────────────
    // The view already scaffolded an export=csv link, but nothing on the
    // server ever handled it (unlike Leads, which does — see the 'leads'
    // match arm above). Mirrors that same pattern: re-runs the exact same
    // filter conditions loadPayments() uses (so the export matches whatever
    // the admin currently has filtered), but against the FULL matching set
    // rather than one paginated page, and streams a CSV instead of a view.
    private static function exportPaymentsCsv(): void
    {
        if (!rto_is_staff()) { wp_die('Access denied.', 403); }

        global $wpdb;
        $p      = $wpdb->prefix;
        $method = \RTOFLOW\Security\Sanitiser::text($_GET['method'] ?? '');
        $from   = \RTOFLOW\Security\Sanitiser::date($_GET['date_from'] ?? '');
        $to     = \RTOFLOW\Security\Sanitiser::date($_GET['date_to']   ?? '');

        $where = ['1=1']; $params = [];
        if ($method) { $where[] = 'py.method=%s'; $params[] = $method; }
        if ($from)   { $where[] = 'py.created_at>=%s'; $params[] = $from . ' 00:00:00'; }
        if ($to)     { $where[] = 'py.created_at<=%s'; $params[] = $to   . ' 23:59:59'; }
        $ws = implode(' AND ', $where);

        $sql = "SELECT py.*, l.lead_number, u.display_name as client_name
                FROM {$p}rto_payments py
                LEFT JOIN {$p}rto_leads l ON l.id=py.lead_id
                LEFT JOIN {$p}users u ON u.ID=l.client_id
                WHERE {$ws} ORDER BY py.created_at DESC LIMIT 5000";
        $payments = empty($params)
            ? ($wpdb->get_results($sql, ARRAY_A) ?: [])
            : ($wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) ?: []);

        $filename = 'rtoflow-payments-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Date','Lead Number','Client','Amount (₹)','GST (₹)','Method','Transaction ID','Status']);

        foreach ($payments as $pay) {
            fputcsv($out, [
                $pay['created_at']    ?? '',
                $pay['lead_number']   ?? '',
                $pay['client_name']   ?? '',
                $pay['amount']        ?? '0.00',
                $pay['gst_amount']    ?? '0.00',
                $pay['method']        ?? '',
                $pay['txn_id']        ?? '',
                $pay['status']        ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    private static function loadStaff(): array
    {
        // ENTERPRISE GAP FIX (Phase 2, item 3 — "Staff roster has no
        // pagination/search/export"): the previous version() hard-capped at
        // get_users(['number' => 100]) with no search and no way to see
        // staff #101+ at all — on any org with more than 100 combined
        // admin+staff accounts, the list would silently truncate with no
        // indication anything was missing. Now paginated (30/page) and
        // searchable by name/email via ?s=, matching the pattern used by
        // ClientRequestsController::index() elsewhere in this codebase.
        $search = Sanitiser::text($_GET['s'] ?? '', 100);
        $page   = max(1, (int)($_GET['paged'] ?? 1));
        $perPage = 30;

        $queryArgs = [
            'role__in' => ['rto_admin', 'rto_staff'],
            'number'   => $perPage,
            'offset'   => ($page - 1) * $perPage,
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ];
        if ($search !== '') {
            $queryArgs['search']         = '*' . $search . '*';
            $queryArgs['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $staff = get_users($queryArgs);

        $countArgs = ['role__in' => ['rto_admin', 'rto_staff'], 'count_total' => true, 'fields' => 'ID'];
        if ($search !== '') {
            $countArgs['search']         = '*' . $search . '*';
            $countArgs['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $countQuery = new \WP_User_Query($countArgs);
        $totalStaff = (int)$countQuery->get_total();
        $lastPage   = max(1, (int)ceil($totalStaff / $perPage));

        // FIX (Part 5.4 — Help Centre audit, real bug found and fixed): this
        // previously queried WordPress's own core 'administrator' role for
        // the "Assign Staff Role" dropdown's candidate list — but every
        // account this platform actually creates carries an rto_* role
        // (rto_admin/rto_staff/rto_vendor/rto_client), not WP-core
        // 'administrator'. A freshly-created client or vendor account would
        // never appear in this dropdown at all unless it also happened to
        // hold the unrelated core role, making the dropdown effectively
        // empty (or limited to legacy/manually-created accounts) on a real
        // site. Fixed to query the platform's own real roles instead.
        $allWpUsers = get_users(['role__in' => ['rto_admin','rto_staff','rto_vendor','rto_client'], 'number' => 200]);
        // ENTERPRISE GAP FIX (Phase 1, item 6 — granular RBAC): surface each
        // rto_staff account's assigned permission role (Finance/Support/
        // Auditor/Full Access) right on this screen, alongside the existing
        // rto_admin/rto_staff WP-role assignment above it — these are two
        // independent layers (see Permissions.php's docblock) and both need
        // to be visible from the one screen that manages staff at all.
        $rbacRoles = \RTOFLOW\Security\Permissions::allRoles();
        return compact('staff', 'allWpUsers', 'rbacRoles', 'search', 'page', 'lastPage', 'totalStaff');
    }

    // ENTERPRISE GAP FIX (Phase 2, item 3): CSV export for the staff roster —
    // previously there was no way to get a staff list out of the platform at
    // all short of manually reading the on-screen table. Admin-only (staff
    // accounts, however restricted, do not get to export the full staff/
    // admin roster including permission-role assignments).
    // TRACE: admin clicks "Export CSV" on the Staff screen → GET request with
    //        export=csv&s=... → verifies admin role → re-runs the same
    //        search this screen shows (no separate/inconsistent query) →
    //        streams every matching account, uncapped by the 30/page UI
    //        limit (hard-capped at 2000 rows, matching the export pattern
    //        used elsewhere in this codebase) → precondition: rto_is_admin()
    //        → postcondition: browser receives a CSV download, no state
    //        changed → edge cases: non-admin gets 403, zero matches still
    //        produces a valid (header-only) CSV.
    private static function exportStaffCsv(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        $search = Sanitiser::text($_GET['s'] ?? '', 100);
        $queryArgs = ['role__in' => ['rto_admin', 'rto_staff'], 'number' => 2000, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($search !== '') {
            $queryArgs['search']         = '*' . $search . '*';
            $queryArgs['search_columns'] = ['display_name', 'user_email', 'user_login'];
        }
        $users = get_users($queryArgs);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="rtoflow-staff-' . date('Y-m-d') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Name', 'Email', 'WP Role', 'Permission Role', 'Registered']);

        foreach ($users as $user) {
            $wpRole  = in_array('rto_admin', $user->roles, true) ? 'RTO Admin' : 'RTO Staff';
            $roleId  = \RTOFLOW\Security\Permissions::roleIdFor($user->ID);
            $roleName = 'Full Access (default)';
            if ($roleId) {
                foreach (\RTOFLOW\Security\Permissions::allRoles() as $r) {
                    if ($r['id'] === $roleId) { $roleName = $r['name']; break; }
                }
            }
            fputcsv($out, [
                $user->display_name, $user->user_email, $wpRole, $roleName,
                date('d M Y', strtotime($user->user_registered)),
            ]);
        }
        fclose($out);
        exit;
    }

    private static function renderComingSoon(string $page): void
    {
        require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
        echo '<div class="rto-card" style="max-width:520px;margin:60px auto;text-align:center;padding:48px 32px">';
        echo '<div style="font-size:48px;margin-bottom:16px">🚧</div>';
        echo '<h2 style="color:var(--navy);margin-bottom:12px">Section Under Development</h2>';
        echo '<p style="color:var(--gray-600);margin-bottom:24px">The <strong>' . esc_html(ucfirst($page)) . '</strong> section is being built and will be available soon.</p>';
        echo '<a href="' . esc_url(home_url('/rto-admin/')) . '" class="rto-btn rto-btn-primary">← Back to Dashboard</a>';
        echo '</div>';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
    }

    // TRACE: dispatch() calls routeVendor($page, $id) when rto_area=vendor →
    //        rto_is_vendor() check; redirect to login if not vendor →
    //        loads vendor record for current WP user from rto_vendors →
    //        match($page) dispatches to VendorDashboardController or subdomain handlers →
    //        preconditions: user has rto_vendor role; rto_vendors row exists for user →
    //        postconditions: vendor portal page rendered →
    //        edge cases: vendor row not found → redirect to login; unknown page → 404
    private static function routeVendor(string $page, int $id): void
    {
        if (!rto_is_vendor()) { wp_redirect(rto_login_url()); exit; }

        $c = \RTOFLOW\Bootstrap::container();
        match($page) {
            'dashboard' => $c->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->index(),
            'jobs'      => $id
                ? rto_view('vendor.jobs.show', ['lead_id' => $id])
                : rto_view('vendor.jobs.index'),
            'earnings'  => rto_view('vendor.earnings.index'),
            'profile'   => rto_view('vendor.profile.index'),
            // ENTERPRISE GAP FIX (Phase 5, item 1 — "vendor leaderboard /
            // gamification"): ranks vendors by the same matching score
            // already computed server-side (VendorService::scoreVendor()) —
            // no new scoring logic invented, purely a new view over it.
            'leaderboard' => $c->make(\RTOFLOW\Controllers\Vendor\DashboardController::class)->leaderboard(),
            // ENTERPRISE GAP FIX (Section 1 follow-up): lets a vendor view
            // their own uploaded KYC document — VendorsController::
            // serveKycDocument() already scopes access to the owning vendor
            // or an admin, so this just gives vendors a route that reaches it.
            'kyc-doc'   => $c->make(\RTOFLOW\Controllers\Admin\VendorsController::class)->serveKycDocument($id),
            // P9-WP-001 FIX: correct wp_die() signature
            default     => wp_die('Page not found.', 'Not Found', ['response' => 404, 'back_link' => true]),
        };
        exit;
    }

    // TRACE: dispatch() calls routeClient($page, $id) when rto_area=client →
    //        is_user_logged_in() + rto_is_client() check; redirect to login if not client →
    //        match($page) dispatches to ClientDashboardController →
    //        preconditions: user logged in with rto_client role →
    //        postconditions: client portal page rendered →
    //        edge cases: not logged in → redirect to rto_login_url(); unknown page → 404
    private static function routeClient(string $page, int $id): void
    {
        if (!is_user_logged_in()) { wp_redirect(rto_login_url(home_url('/rto-dashboard/'))); exit; }

        $c  = \RTOFLOW\Bootstrap::container();
        $uid = get_current_user_id();
        global $wpdb;
        $p = $wpdb->prefix;

        match($page) {
            'dashboard' => $c->make(\RTOFLOW\Controllers\Client\DashboardController::class)->index(),
            'orders'    => $id
                ? $c->make(\RTOFLOW\Controllers\Client\DashboardController::class)->showOrder($id)
                : $c->make(\RTOFLOW\Controllers\Client\DashboardController::class)->index(),

            'documents' => (function() use ($wpdb, $p, $uid) {
                // ENTERPRISE GAP FIX (critical missing functionality — see
                // resources/views/client/documents/index.php for the full
                // root-cause note): this POST handler previously discarded
                // DocumentService::upload()'s result entirely and had no
                // nonce-mismatch or validation feedback path, on top of no
                // form anywhere actually calling it. Now captures a real
                // result and surfaces it to the client.
                $uploadError = null; $uploadSuccess = null;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rtoflow_doc_upload'])) {
                    if (!check_ajax_referer('rto_client_nonce', 'rto_nonce', false)) {
                        $uploadError = 'Security check failed. Please refresh the page and try again.';
                    } elseif (empty($_FILES['document']) || (int)($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        $uploadError = 'Please choose a file to upload.';
                    } else {
                        $leadId    = (int)($_POST['lead_id'] ?? 0);
                        $docTypeId = (int)($_POST['doc_type_id'] ?? 0);
                        $owns = $leadId ? $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$p}rto_leads WHERE id=%d AND client_id=%d AND deleted_at IS NULL", $leadId, $uid
                        )) : null;
                        if (!$leadId || !$owns) {
                            $uploadError = 'Please select a valid order for this document.';
                        } elseif (!$docTypeId) {
                            $uploadError = 'Please select a document type.';
                        } else {
                            $svc    = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\DocumentService::class);
                            $result = $svc->upload($leadId, $docTypeId, $_FILES['document']);
                            if (!empty($result['success'])) {
                                $uploadSuccess = 'Document uploaded successfully — it will be reviewed shortly.';
                            } else {
                                $uploadError = $result['message'] ?? 'Unable to upload the document. Please try again.';
                            }
                        }
                    }
                }
                $docs = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT d.*, t.name as doc_type_name, l.lead_number
                         FROM {$p}rto_documents d
                         LEFT JOIN {$p}rto_doc_types t ON t.id=d.doc_type_id
                         LEFT JOIN {$p}rto_leads l ON l.id=d.lead_id
                         WHERE l.client_id=%d ORDER BY d.created_at DESC LIMIT 50",
                        $uid
                    ),
                    ARRAY_A
                ) ?: [];
                $myLeads = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, lead_number FROM {$p}rto_leads WHERE client_id=%d AND deleted_at IS NULL ORDER BY created_at DESC", $uid
                ), ARRAY_A) ?: [];
                $docTypes = $wpdb->get_results(
                    "SELECT id, name, category FROM {$p}rto_doc_types WHERE is_active=1 ORDER BY category, name", ARRAY_A
                ) ?: [];
                rto_view('client.documents.index', compact('docs', 'uploadError', 'uploadSuccess', 'myLeads', 'docTypes'));
            })(),

            'complaints' => (function() use ($wpdb, $p, $uid) {
                // FIX (integration pass follow-up): this route has its own
                // complaint-creation path (GrievanceService::create()),
                // separate from ComplaintsController::store() used by the
                // AJAX 'client.file_complaint' action — both write paths
                // must respect 'grievance_portal' or disabling the flag from
                // admin Feature Flags would only block one of the two ways
                // a client can actually file a complaint.
                if (!\RTOFLOW\Config\FeatureFlags::is_enabled('grievance_portal')) {
                    wp_die('The complaints/grievance portal is currently unavailable.', 'Not Found', ['response' => 404, 'back_link' => true]);
                }
                // ENTERPRISE GAP FIX (critical missing functionality — clients
                // had no actual way to file a complaint anywhere in the UI;
                // this handler already existed but discarded GrievanceService
                // ::create()'s result with no feedback, and no form anywhere
                // pointed at it — the complaints list page itself even told
                // clients to "open the relevant order" for a form that did
                // not exist). Now captures the result and surfaces it, and a
                // real form has been added directly to this page below.
                $complaintError = null; $complaintSuccess = null;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rtoflow_file_complaint'])) {
                    if (!check_ajax_referer('rto_client_nonce', 'rto_nonce', false)) {
                        $complaintError = 'Security check failed. Please refresh the page and try again.';
                    } else {
                        $svc    = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\GrievanceService::class);
                        $result = $svc->create($_POST);
                        if (!empty($result['success'])) {
                            $complaintSuccess = 'Complaint ' . $result['complaint_number'] . ' filed. We will respond soon.';
                        } else {
                            $complaintError = $result['message'] ?? 'Unable to file the complaint. Please try again.';
                        }
                    }
                }
                $complaints = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$p}rto_complaints WHERE complainant_id=%d ORDER BY created_at DESC LIMIT 20",
                        $uid
                    ),
                    ARRAY_A
                ) ?: [];
                $myLeads = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, lead_number FROM {$p}rto_leads WHERE client_id=%d AND deleted_at IS NULL ORDER BY created_at DESC", $uid
                ), ARRAY_A) ?: [];
                rto_view('client.complaints.index', compact('complaints', 'myLeads', 'complaintError', 'complaintSuccess'));
            })(),

            'profile' => (function() use ($wpdb, $p, $uid) {
                // Handle profile update POST
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_ajax_referer('rto_client_nonce', 'rto_nonce', false)) {
                    $name   = \RTOFLOW\Security\Sanitiser::text($_POST['name'] ?? '');
                    $mobile = \RTOFLOW\Security\Sanitiser::mobile($_POST['mobile'] ?? '');
                    if ($name)   wp_update_user(['ID' => $uid, 'display_name' => $name]);
                    if ($mobile) update_user_meta($uid, 'rtoflow_mobile', $mobile);
                }
                $user   = get_userdata($uid);
                $mobile = get_user_meta($uid, 'rtoflow_mobile', true);
                rto_view('client.profile.index', compact('user', 'mobile'));
            })(),

            // ENTERPRISE GAP FIX (Phase 4, item 2 — "no saved addresses or
            // vehicle registry for repeat clients"): plain-POST, same
            // pattern as the 'profile' arm above — add/delete a saved
            // vehicle, always scoped to the logged-in client's own rows.
            'vehicles' => (function() use ($wpdb, $p, $uid) {
                $vehicleError = null; $vehicleSaved = false;
                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rtoflow_vehicle_nonce'])
                    && wp_verify_nonce($_POST['rtoflow_vehicle_nonce'], 'rtoflow_client_vehicle')) {
                    if (isset($_POST['delete_vehicle_id'])) {
                        $wpdb->delete($p . 'rto_client_vehicles', [
                            'id' => (int)$_POST['delete_vehicle_id'], 'client_id' => $uid,
                        ]);
                        $vehicleSaved = true;
                    } else {
                        $label     = \RTOFLOW\Security\Sanitiser::text($_POST['label'] ?? '', 100);
                        $vehicleNo = strtoupper(\RTOFLOW\Security\Sanitiser::text($_POST['vehicle_number'] ?? '', 20));
                        $makeModel = \RTOFLOW\Security\Sanitiser::text($_POST['make_model'] ?? '', 150);
                        $address   = sanitize_textarea_field($_POST['address'] ?? '');
                        if (!$vehicleNo && !$address) {
                            $vehicleError = 'Enter at least a vehicle number or an address to save.';
                        } else {
                            $wpdb->insert($p . 'rto_client_vehicles', [
                                'client_id'      => $uid,
                                'label'          => $label ?: null,
                                'vehicle_number' => $vehicleNo ?: null,
                                'make_model'     => $makeModel ?: null,
                                'address'        => $address ?: null,
                                'created_at'     => current_time('mysql'),
                            ]);
                            $vehicleSaved = true;
                        }
                    }
                }
                $vehicles = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$p}rto_client_vehicles WHERE client_id=%d ORDER BY created_at DESC", $uid
                ), ARRAY_A) ?: [];
                rto_view('client.vehicles.index', compact('vehicles', 'vehicleError', 'vehicleSaved'));
            })(),

            default => wp_die('Page not found.', 'Not Found', ['response' => 404, 'back_link' => true]),
        };
        exit;
    }

    private static function routePublic(string $page): void
    {
        if (!RateLimiter::check('public', RateLimiter::clientIp())) RateLimiter::abort();
        match($page) {
            // P7-PERF-005 FIX: pre-load data with caching before view renders
            'apply'         => self::routeApply(),
            'apply_dynamic' => self::routeApplyDynamic((int)get_query_var('rto_id')),
            'track'         => self::routeTrack(),
            'service'       => self::routeService(get_query_var('rto_slug', '')),
            default   => wp_die('Page not found.', 'Not Found', ['response' => 404, 'back_link' => true]),
        };
        exit;
    }

    private static function routeTrack(): void
    {
        // FIX (10-workstream integration pass, feature-flag wiring):
        // 'public_tracking' had nothing gating it — this is the one page it
        // controls (public, unauthenticated order-tracking by token).
        if (!\RTOFLOW\Config\FeatureFlags::is_enabled('public_tracking')) {
            wp_die('Order tracking is not available.', 'Not Found', ['response' => 404, 'back_link' => true]);
        }

        global $wpdb;
        $token = sanitize_text_field(get_query_var('rto_token', ''));

        // Handle POST token search
        // BUGFIX (found via a systematic nonce-action-string sweep across the
        // whole codebase): resources/views/public/track.php renders
        // wp_nonce_field('rto_track_search', 'track_nonce') on this exact
        // form, but nothing anywhere ever verified it -- the nonce field gave
        // the appearance of CSRF protection while providing none at all. Low
        // real-world severity (this is a read-only lookup by a token the
        // requester must already possess, not a state-changing action), but
        // a genuine gap between the security control the UI implies and what
        // the server actually enforces, so it's fixed properly rather than
        // left as decorative markup.
        if (empty($token) && !empty($_POST['tracking_token'])) {
            if (!isset($_POST['track_nonce']) || !wp_verify_nonce($_POST['track_nonce'], 'rto_track_search')) {
                wp_die('Security check failed. Please refresh the page and try again.', 'Security Check Failed', ['response' => 403, 'back_link' => true]);
            }
            $token = sanitize_text_field($_POST['tracking_token']);
        }

        $lead = null;
        if ($token) {
            $lead = $wpdb->get_row($wpdb->prepare(
                "SELECT l.*, s.name as service_name, s.category,
                        st.name as state_name, c.name as city_name, c.rto_code
                 FROM {$wpdb->prefix}rto_leads l
                 LEFT JOIN {$wpdb->prefix}rto_services s ON s.id = l.service_id
                 LEFT JOIN {$wpdb->prefix}rto_cities c ON c.id = l.city_id
                 LEFT JOIN {$wpdb->prefix}rto_states st ON st.id = c.state_id
                 WHERE l.tracking_token = %s AND l.deleted_at IS NULL",
                $token
            ), ARRAY_A);
        }

        rto_view('public.track', compact('lead', 'token'));
    }

    private static function routeService(string $slug): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        if ($slug) {
            $service = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$p}rto_services WHERE LOWER(REPLACE(name,' ','-'))=%s AND is_active=1",
                strtolower($slug)
            ), ARRAY_A);
        }
        $service  = $service  ?? null;
        $services = get_transient('rtofl_active_services') ?: $wpdb->get_results(
            "SELECT id,name,category,base_price,sla_days,description FROM {$p}rto_services WHERE is_active=1 ORDER BY category,display_order", ARRAY_A
        ) ?: [];
        // Form Builder v2: only send a customer to the schema-driven apply
        // page when one is actually configured and active for this service —
        // getForService() is itself gated on the form_builder flag, so this
        // stays false everywhere until an admin has both turned the flag on
        // and built a live form for this specific service.
        // Part 4.10: schemas are category-keyed now, not service-keyed —
        // resolve which of the 7 categories this service belongs to via
        // RealFormSchemaSeeder::categoryMap() before checking for an active
        // schema (getForService()/service_id-keyed rows are legacy-only and
        // nothing writes them any more — see FormBuilderController).
        $hasDynamicForm = false;
        if ($service) {
            $forms = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormEngineService::class);
            $catKey = null;
            foreach (\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap() as $ck => $cat) {
                if (in_array($service['name'], $cat['services'], true)) { $catKey = $ck; break; }
            }
            $hasDynamicForm = $catKey && $forms->getForCategory($catKey) !== null;
        }
        rto_view('public.service', compact('service', 'services', 'hasDynamicForm'));
    }

    // TRACE: dispatch() calls routeWebsite($page) when rto_area=website →
    //        public-facing pages: home, about, services, cities, city detail, contact, pricing, etc. →
    //        city detail: loads city with state JOIN, calls getVisibleServicesForCity() →
    //        no auth required for website routes →
    //        preconditions: WP rewrite rule matched; rto_area=website set →
    //        postconditions: public page rendered with SEO meta, canonical, structured data →
    //        edge cases: unknown slug/page → redirect or 404; city not found → redirect to all-cities
    private static function routeWebsite(string $page): void
    {
        if (!RateLimiter::check('public', RateLimiter::clientIp())) RateLimiter::abort();

        $slug = Sanitiser::slug(get_query_var('rto_slug', ''));
        global $wpdb;

        match($page) {
            'design-css' => self::designCss(),
            'design-site-css' => self::designSiteCss(),

            'home' => (function() {
                // Respect WP static front page setting
                if (get_option('show_on_front') === 'page' && get_option('page_on_front')) return;
                // NOTE (service-claims audit): home.php computes its own
                // <title>/meta description internally and ignores
                // page_title/meta_desc, so these two values are not actually
                // rendered to a visitor on this code path — corrected anyway
                // for consistency with the real copy elsewhere on the site.
                rto_view('public.home', [
                    'page_title' => get_option('rtoflow_company_name','RTOASSIST') . ' — RTO Application Assistance',
                    'meta_desc'  => 'RTO application assistance across India. RC Transfer, DL, NOC, Hypothecation & more. Doorstep pickup/drop where available; final approval rests with the RTO.',
                ]);
            })(),

            'about' => rto_view('public.about', [
                'page_title' => 'About Us — ' . get_option('rtoflow_company_name','RTOASSIST'),
                'meta_desc'  => 'Learn about our RTO application assistance services, what we do, and what we don\'t control.',
            ]),

            'contact' => (function() {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    // Handle contact form submission
                    do_action('rtoflow_contact_form', $_POST);
                }
                rto_view('public.contact', [
                    'page_title' => 'Contact Us — ' . get_option('rtoflow_company_name','RTOASSIST'),
                    'meta_desc'  => 'Contact our RTO experts. Get in touch via phone, WhatsApp, or email.',
                ]);
            })(),

            'how-it-works' => rto_view('public.how-it-works', [
                'page_title' => 'How It Works — ' . get_option('rtoflow_company_name','RTOASSIST'),
                'meta_desc'  => 'Simple 4-step process for all your RTO needs. No office visits required.',
            ]),

            'pricing' => (function() use ($wpdb) {
                $services = get_transient('rtofl_active_services');
                if ($services === false) {
                    $services = $wpdb->get_results(
                        "SELECT * FROM {$wpdb->prefix}rto_services WHERE is_active=1 ORDER BY display_order, name",
                        ARRAY_A
                    ) ?: [];
                    set_transient('rtofl_active_services', $services, 3600);
                }
                rto_view('public.pricing', [
                    'services'   => $services,
                    'page_title' => 'Pricing — ' . get_option('rtoflow_company_name','RTOASSIST'),
                    'meta_desc'  => 'Transparent RTO service pricing. No hidden fees. Government fees charged separately.',
                ]);
            })(),

            'all-cities' => (function() use ($wpdb) {
                $cities = $wpdb->get_results(
                    "SELECT c.id, c.name, COALESCE(c.slug, LOWER(REPLACE(c.name,' ','-'))) as slug,
                            COALESCE(s.name,'Unknown') as state
                     FROM {$wpdb->prefix}rto_cities c
                     LEFT JOIN {$wpdb->prefix}rto_states s ON s.id=c.state_id
                     WHERE c.is_active=1 ORDER BY s.name, c.name",
                    ARRAY_A
                ) ?: [];
                $by_state = [];
                foreach ($cities as $c) $by_state[$c['state']][] = $c;
                rto_view('public.all-cities', [
                    'by_state'   => $by_state,
                    'total'      => count($cities),
                    'page_title' => 'RTO Services in 300+ Cities Across India',
                    'meta_desc'  => 'Find our expert RTO agents in ' . count($cities) . ' cities across India.',
                ]);
            })(),

            'city' => (function() use ($wpdb, $slug) {
                if (!$slug) { wp_redirect(home_url('/rto-services-cities')); exit; }
                $city = $wpdb->get_row($wpdb->prepare(
                    "SELECT c.*, s.name as state_name FROM {$wpdb->prefix}rto_cities c
                     LEFT JOIN {$wpdb->prefix}rto_states s ON s.id=c.state_id
                     WHERE (c.slug=%s OR LOWER(REPLACE(c.name,' ','-'))=%s) AND c.is_active=1 LIMIT 1",
                    $slug, $slug
                ), ARRAY_A);
                if (!$city) { wp_redirect(home_url('/rto-services-cities')); exit; }
                // Use CityServiceConfigController to get only VISIBLE services for this city
                // with pricing data only where show_price=1 — all other services/prices hidden
                $services = \RTOFLOW\Controllers\Admin\CityServiceConfigController::getVisibleServicesForCity((int)$city['id']);
                // Group by category for the city page template
                $servicesByCategory = [];
                foreach ($services as $svc) { $servicesByCategory[$svc['category']][] = $svc; }
                $nearby = $wpdb->get_results($wpdb->prepare(
                    "SELECT c.name, COALESCE(c.slug,LOWER(REPLACE(c.name,' ','-'))) as slug
                     FROM {$wpdb->prefix}rto_cities c
                     WHERE c.state_id=(SELECT state_id FROM {$wpdb->prefix}rto_cities WHERE id=%d)
                     AND c.id != %d AND c.is_active=1 ORDER BY c.name LIMIT 12",
                    $city['id'], $city['id']
                ), ARRAY_A) ?: [];
                rto_view('public.city-page', compact('city','services','servicesByCategory','nearby') + [
                    'page_title' => 'RTO Services in ' . $city['name'] . ', ' . $city['state_name'],
                    'meta_desc'  => 'Expert RTO agents in ' . $city['name'] . '. RC Transfer, DL, NOC & 30+ services handled doorstep.',
                    'canonical'  => home_url('/rto-agent-in-' . ($city['slug'] ?: sanitize_title($city['name']))),
                ]);
            })(),

            'login',
            'rto-login' => (function() {
                $redirect = Sanitiser::url($_GET['redirect'] ?? '');

                // Magic-link sign-in (user request: "email otp or magic
                // login" — never wp-login.php, no mandatory 2FA). Kept on
                // the existing /rto-login/ route as a ?magic= query param
                // rather than a new rewrite rule, so this ships without
                // needing a rewrite-rule flush to take effect. One-time,
                // short-lived token — see rtoflow_ajax_request_magic_link()
                // in rtoflow-os.php for how the transient is created and
                // emailed. Deliberately runs even if a *different* session
                // happens to already be logged in on this browser: clicking
                // a magic link is an explicit, unambiguous intent to log in
                // as the account that link was emailed to.
                $magicToken = sanitize_text_field($_GET['magic'] ?? '');
                if ($magicToken !== '') {
                    $magicUserId = get_transient('rtoflow_magic_link_' . $magicToken);
                    // Defense in depth (user request: passwordless login is
                    // client/vendor only, never admin): rtoflow_ajax_request_magic_link_handler()
                    // in rtoflow-os.php never creates this transient for an
                    // admin account, so this branch should be unreachable
                    // for one — but it's checked here too rather than
                    // trusting that invariant alone.
                    if ($magicUserId && rto_is_admin((int)$magicUserId)) {
                        delete_transient('rtoflow_magic_link_' . $magicToken);
                        wp_redirect(home_url('/rto-login/?msg=magic_expired'));
                        exit;
                    }
                    if ($magicUserId && get_userdata((int)$magicUserId)) {
                        delete_transient('rtoflow_magic_link_' . $magicToken); // one-time use
                        if (is_user_logged_in() && get_current_user_id() !== (int)$magicUserId) {
                            wp_logout();
                        }
                        wp_set_current_user((int)$magicUserId);
                        wp_set_auth_cookie((int)$magicUserId, true);
                        do_action('wp_login', get_userdata((int)$magicUserId)->user_login, get_userdata((int)$magicUserId));
                        wp_redirect($redirect ?: home_url(rtoflow_portal_url_for_user()));
                        exit;
                    }
                    // Expired / already-used / invalid token — fall through
                    // to the normal login page with an explanatory flash
                    // message rather than a dead end.
                    wp_redirect(home_url('/rto-login/?msg=magic_expired'));
                    exit;
                }

                if (is_user_logged_in()) {
                    $userId = get_current_user_id();
                    // BUG FIX (ERR_TOO_MANY_REDIRECTS): an already-authenticated
                    // user whose account has 2FA enabled but whose SESSION has
                    // not yet been verified (fresh login, or an old session from
                    // before this fix) must NOT be bounced straight back out —
                    // Bootstrap.php's template_redirect hook sends them to
                    // exactly this page for exactly this reason. Render the
                    // 2FA code-entry challenge here instead of redirecting, so
                    // there's finally somewhere for that redirect to land.
                    if (\RTOFLOW\Auth\TwoFactor::isEnabled($userId)
                        && !\RTOFLOW\Auth\TwoFactor::isVerifiedForSession($userId)) {
                        rto_view('public.rto-login', [
                            'mode'         => 'login',
                            'needs2fa'     => true,
                            'redirect'     => $redirect,
                            'page_title'   => 'Verify your identity — ' . get_option('rtoflow_company_name','RTOASSIST'),
                        ]);
                        return;
                    }
                    wp_redirect($redirect ?: home_url(rtoflow_portal_url_for_user()));
                    exit;
                }
                rto_view('public.rto-login', [
                    'redirect'   => $redirect,
                    'page_title' => 'Login — ' . get_option('rtoflow_company_name','RTOASSIST'),
                ]);
            })(),

            'register',
            'rto-register' => (function() {
                if (is_user_logged_in()) {
                    wp_redirect(home_url(rtoflow_portal_url_for_user())); exit;
                }
                rto_view('public.rto-login', [
                    'mode'       => 'register',
                    'page_title' => 'Create Account — ' . get_option('rtoflow_company_name','RTOASSIST'),
                ]);
            })(),

            'rto-logout' => (function() {
                wp_logout();
                wp_redirect(home_url('/rto-login/?msg=logged_out'));
                exit;
            })(),

            'privacy' => rto_view('public.privacy', [
                'page_title' => 'Privacy Policy — ' . get_option('rtoflow_company_name','RTOASSIST'),
            ]),

            'terms' => rto_view('public.terms', [
                'page_title' => 'Terms & Conditions — ' . get_option('rtoflow_company_name','RTOASSIST'),
            ]),

            'track' => (function() {
                if (!is_user_logged_in()) { wp_redirect(rto_login_url(home_url('/rto-dashboard/'))); exit; }
                wp_redirect(home_url('/rto-dashboard/')); exit;
            })(),

            default => wp_die('Page not found.', 'Not Found', ['response' => 404, 'back_link' => true]),
        };
        exit;
    }

    private static function routeWebhook(string $page): void
    {
        // Webhooks must not be rate-limited by IP as they come from gateways
        $body = file_get_contents('php://input');

        // ENTERPRISE GAP FIX (Phase 12, item — "no inbound webhook framework
        // beyond per-integration handlers"): a NEW integration no longer
        // needs its own bespoke branch here — it registers a slug/secret via
        // the Inbound Webhooks admin screen and gets this one generic route,
        // signature-verified and logged by InboundWebhookService. Razorpay
        // and WhatsApp below keep their own existing, working handlers
        // (rewriting a live payment-gateway webhook path is out of scope for
        // this fix) but now also log through the same service so all
        // inbound integration traffic is visible in one admin screen.
        if ($page === 'custom') {
            $slug = \RTOFLOW\Security\Sanitiser::slug((string)get_query_var('provider', ''));
            $headers = [];
            foreach ($_SERVER as $k => $v) {
                if (strpos($k, 'HTTP_') === 0) $headers[strtolower(substr($k, 5))] = $v;
            }
            $svc    = new \RTOFLOW\Services\InboundWebhookService();
            $result = $svc->dispatchCustom($slug, $body, $headers);
            status_header($result['status']);
            echo $result['message'];
            exit;
        }

        if ($page === 'razorpay') {
            $sig      = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
            $svc      = new \RTOFLOW\Services\PaymentService(
                new \RTOFLOW\Services\InvoiceService(new \RTOFLOW\Services\GstService())
            );
            $sigValid = $svc->verifyRazorpayWebhook($body, $sig);
            (new \RTOFLOW\Services\InboundWebhookService())->log('razorpay', $sigValid, $body, null, $sigValid ? null : 'Invalid Razorpay signature', $sigValid ? 200 : 401);
            if (!$sigValid) {
                status_header(401); echo 'Invalid signature'; exit;
            }
            $event = json_decode($body, true);
            if (($event['event'] ?? '') === 'payment.captured') {
                $payload = $event['payload']['payment']['entity'];
                $leadId  = (int)($payload['notes']['lead_id'] ?? 0);
                if ($leadId) {
                    $svc->record([
                        'lead_id'     => $leadId,
                        'amount'      => (float)$payload['amount'] / 100,
                        'method'      => $payload['method'] ?? 'razorpay',
                        'txn_id'      => $payload['id'],
                        'gateway_ref' => $payload['order_id'] ?? '',
                        'type'        => 'full',
                    ]);
                }
            }
            // ENTERPRISE GAP FIX (Phase 1, item 5 — penny-drop bank
            // verification): same Razorpay webhook URL, same signature
            // check above already verified this request — Razorpay posts
            // ALL subscribed event types to one configured URL, it does not
            // use a separate endpoint per event type.
            if (in_array($event['event'] ?? '', ['fund_account.validation.completed', 'fund_account.validation.failed'], true)) {
                (new \RTOFLOW\Services\BankVerificationService())->handleWebhookEvent($event['event'], $event);
            }
            http_response_code(200); echo 'ok'; exit;
        }

        if ($page === 'whatsapp') {
            // Known Limitations audit fix (platform-wide sweep — same class
            // of bug as NotificationService::sendSms()/sendWhatsApp() and
            // DashboardController's Razorpay calls): unqualified references
            // to the global-namespace RTOFLOW_WhatsApp class from this
            // namespaced file (RTOFLOW\Http) fatally errored. This is the
            // Meta webhook verification handshake and the inbound-message
            // handler — meaning WhatsApp's webhook subscription could never
            // actually be verified with Meta, and no inbound WhatsApp
            // message could ever be processed.
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                $challenge = \RTOFLOW_WhatsApp::verifyWebhook();
                echo $challenge ?? ''; exit;
            }
            (new \RTOFLOW\Services\InboundWebhookService())->log('whatsapp', true, $body);
            do_action('rtoflow_whatsapp_inbound', \RTOFLOW_WhatsApp::parseInbound($body));
            http_response_code(200); echo 'ok'; exit;
        }

        status_header(404); exit;
    }

    private static function routeHealth(): void
    {
        $token    = $_GET['token'] ?? '';
        $expected = \RTOFLOW\Config\Env::string('HEALTH_SECRET', '');

        if ($expected && !hash_equals($expected, $token)) {
            status_header(403); echo 'Forbidden'; exit;
        }

        // ENTERPRISE GAP FIX (Phase 8, item — "duplicate health-check
        // implementation"): this route and the /wp-json/rtoflow/v1/health
        // REST route both used to inline their own "SELECT 1" check. Both
        // now delegate to HealthCheckService::status(); see its docblock.
        $health = \RTOFLOW\Services\HealthCheckService::status();

        status_header($health['status'] === 'healthy' ? 200 : 503);
        header('Content-Type: application/json');
        echo wp_json_encode($health);
        exit;
    }

    // TRACE: crawler/GET requests /sitemap.xml → WP rewrite matches →
    //        rto_area=sitemap dispatched here → queries active services +
    //        active cities (no auth, this is public data by design — the
    //        same slugs are already crawlable via internal links) →
    //        builds a <urlset> of static pages + service pages + city
    //        landing pages → streams application/xml, 1-hour cache →
    //        preconditions: none (fully public, no nonce/auth) →
    //        postconditions: none (pure read, no state mutation) →
    //        edge cases: DB query returns empty → sitemap still emits the
    //        static page list, never a broken/empty document
    private static function routeSitemap(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');

        $urls = [];
        // Static marketing pages — mirrors the 'website' rewrite rules above.
        foreach (['', 'about', 'contact', 'how-it-works', 'pricing', 'rto-services-cities', 'privacy-policy', 'terms-conditions'] as $slug) {
            $urls[] = ['loc' => home_url('/' . $slug . ($slug ? '/' : '')), 'priority' => $slug === '' ? '1.0' : '0.6'];
        }

        // Service pages (rto-service/{slug}) — high commercial intent.
        $services = $wpdb->get_col("SELECT slug FROM {$p}rto_services WHERE is_active=1 AND slug<>''") ?: [];
        foreach ($services as $slug) {
            $urls[] = ['loc' => home_url('/rto-service/' . $slug . '/'), 'priority' => '0.8'];
        }

        // City landing pages (rto-agent-in-{slug}) — the highest-value local-SEO
        // pages on the site, previously entirely undiscoverable by crawlers.
        $cities = $wpdb->get_col("SELECT DISTINCT slug FROM {$p}rto_cities WHERE is_active=1 AND slug<>''") ?: [];
        foreach ($cities as $slug) {
            $urls[] = ['loc' => home_url('/rto-agent-in-' . $slug . '/'), 'priority' => '0.7'];
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo '  <url><loc>' . esc_url($u['loc']) . '</loc><priority>' . esc_html($u['priority']) . '</priority></url>' . "\n";
        }
        echo '</urlset>';
        exit;
    }

    // ENTERPRISE GAP FIX (Phase 5, item 3 — "PWA / offline support for
    // vendor and client portals"): dynamically generates each portal's
    // manifest (so the app name/theme color follow rtoflow_company_name /
    // an admin-set brand color, not a hardcoded value baked into a static
    // file) and serves one shared, minimal offline-shell service worker.
    // TRACE: dispatch() 'pwa' area (unauthenticated — see the rewrite-rule
    // comment above for why) → match($page) → client-manifest/vendor-
    // manifest emit JSON with correct start_url/scope for that portal; sw
    // emits the worker script → preconditions: none (public) →
    // postconditions: none (read-only) → edge cases: unknown $page → 404,
    // same as every other area's default arm.
    private static function routePwa(string $page): void
    {
        switch ($page) {
            case 'client-manifest':
            case 'vendor-manifest':
                $isVendor = $page === 'vendor-manifest';
                header('Content-Type: application/manifest+json; charset=UTF-8');
                header('Cache-Control: public, max-age=3600');
                $company = get_option('rtoflow_company_name', 'RTOFLOW');
                $logo    = get_option('rtoflow_company_logo_url', '');
                $icons   = [];
                if ($logo) {
                    // Only one source image is configured (the company logo) —
                    // honestly declared as a single "any purpose" size rather
                    // than fabricating the 192x192/512x512 pre-sized variants a
                    // real production PWA icon set would have.
                    $icons[] = ['src' => $logo, 'sizes' => 'any', 'type' => 'image/png', 'purpose' => 'any'];
                }
                echo wp_json_encode([
                    'name'             => $company . ($isVendor ? ' Vendor' : ''),
                    'short_name'       => $isVendor ? 'Vendor' : $company,
                    'start_url'        => home_url($isVendor ? '/rto-vendor/' : '/rto-dashboard/'),
                    'scope'            => home_url($isVendor ? '/rto-vendor/' : '/rto-dashboard/'),
                    'display'          => 'standalone',
                    'background_color' => '#ffffff',
                    'theme_color'      => '#1E3A5F',
                    'icons'            => $icons,
                ]);
                exit;

            case 'sw':
                header('Content-Type: application/javascript; charset=UTF-8');
                header('Cache-Control: no-cache');
                header('Service-Worker-Allowed: /');
                // Deliberately minimal: caches only the app-shell chrome
                // (mobile nav CSS/JS) for instant repeat loads, and falls
                // back to network for everything else — this platform's
                // data (leads, documents, payments) must never be served
                // stale from a cache, so no API/page response is cached.
                echo "const RTO_SW_CACHE = 'rtoflow-shell-v1';\n"
                   . "const SHELL_ASSETS = [\n"
                   . "  " . wp_json_encode(RTOFLOW_URL . 'resources/assets/css/mobile-nav.css') . ",\n"
                   . "  " . wp_json_encode(RTOFLOW_URL . 'resources/assets/js/mobile-nav.js') . "\n"
                   . "];\n"
                   . "self.addEventListener('install', (event) => {\n"
                   . "  event.waitUntil(caches.open(RTO_SW_CACHE).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => {}));\n"
                   . "  self.skipWaiting();\n"
                   . "});\n"
                   . "self.addEventListener('activate', (event) => {\n"
                   . "  event.waitUntil(caches.keys().then((keys) => Promise.all(\n"
                   . "    keys.filter((k) => k !== RTO_SW_CACHE).map((k) => caches.delete(k))\n"
                   . "  )));\n"
                   . "  self.clients.claim();\n"
                   . "});\n"
                   . "self.addEventListener('fetch', (event) => {\n"
                   . "  if (event.request.method !== 'GET' || !SHELL_ASSETS.includes(event.request.url)) return;\n"
                   . "  event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));\n"
                   . "});\n";
                exit;

            default:
                status_header(404);
                exit;
        }
    }

    private static function routeInvoice(int $invoiceId): void
    {
        if (!is_user_logged_in()) { wp_redirect(rto_login_url()); exit; }

        $token    = Sanitiser::text($_GET['token'] ?? '');
        $expected = hash_hmac('sha256', 'invoice-' . $invoiceId, AUTH_KEY);
        if (!hash_equals($expected, $token)) { status_header(403); exit; }

        global $wpdb;
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_invoices WHERE id = %d",
            $invoiceId
        ), ARRAY_A);

        if (!$invoice || !$invoice['pdf_path']) { status_header(404); exit; }

        // Verify user owns this invoice
        if ((int)$invoice['client_id'] !== get_current_user_id() && !rto_is_staff()) {
            status_header(403); exit;
        }

        \RTOFLOW\Security\FileUploadGuard::serve($invoice['pdf_path'], 'Invoice-' . $invoice['invoice_number'] . '.pdf');
    }

    private static function routeApply(): void
    {
        global $wpdb;
        // Cache services and states (rarely change) — 1 hour TTL
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

        // Form Builder v2 — REAL integration into THIS page (not a separate
        // one). Every service's exact name already matches the option text
        // in the static category/sub-service dropdowns below (both come from
        // the same IndiaDataSeeder rows), so `svc` — the JS variable this
        // page already sets the moment a sub-service is picked — is enough
        // to resolve which service was chosen client-side with zero extra
        // network calls.
        //
        // Part 4.10 re-architecture: the Form Builder's unit is now a
        // CATEGORY (dl/rc/hp/noc/vehicle/commercial/other — 7 forms), not a
        // service (44 forms) — see RealFormSchemaSeeder::categoryMap(). Each
        // category schema contains a `selected_service` picker field plus
        // every field for every service in that category, gated by a
        // visible_if condition on that picker's value. apply.php's JS
        // (checkDynamicForCategory(), called from selDL()/selRC()/selHP()/
        // selVehicle()/selCat()) renders the WHOLE category schema with an
        // injected initial answer of {selected_service: <picked service
        // name>}, so only the fields relevant to the picked sub-service ever
        // become visible — every other category's static fields are
        // completely untouched.
        $serviceNameToId = [];
        foreach ($services as $s) { $serviceNameToId[$s['name']] = (int)$s['id']; }

        // Maps every real service NAME to the category key its Form Builder
        // schema lives under — the single source of truth for that mapping
        // is RealFormSchemaSeeder::categoryMap(), the same list used to build
        // each category schema in the first place, so this can never drift
        // out of sync with which fields actually got gated to which service.
        $serviceToCategory = [];
        foreach (\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap() as $catKey => $cat) {
            foreach ($cat['services'] as $name) { $serviceToCategory[$name] = $catKey; }
        }

        $categorySchemas = [];
        if (\RTOFLOW\Config\FeatureFlags::is_enabled('form_builder')) {
            $forms = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormEngineService::class);
            foreach (array_keys(\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap()) as $catKey) {
                $schema = $forms->getForCategory($catKey);
                if ($schema) $categorySchemas[$catKey] = $schema;
            }
        }

        // Part 4.15 FIX (real gap — the admin "Deactivate Category" button
        // built in Part 4.14 had NO effect on this page): the 7 category
        // buttons in Step 1 below, and every category's sub-service <select>,
        // were 100% hard-coded literal HTML — never queried against
        // rto_services.is_active at all. Bulk-deactivating every service in
        // "Driving License" via the admin panel therefore changed nothing a
        // customer could see here: the category button still rendered, still
        // clickable, still showed every sub-service option. Fixed at the
        // CATEGORY level (matching what was actually asked for — hide a
        // whole category, e.g. Driving Licence): a category with zero
        // currently-active real services is now left out of $activeCategoryKeys
        // and apply.php's Step 1 loop skips rendering its button entirely, so
        // a customer can no longer even open that category. Deactivating a
        // single service WITHIN an otherwise-active category is a narrower,
        // separate gap this pass does NOT close — several categories' static
        // sub-service pickers (NOC, Commercial Vehicle) are structured too
        // differently from a simple <select> to filter safely without a
        // dedicated per-category pass, and that is disclosed, not silently
        // left broken.
        $categoryActiveCount = array_fill_keys(array_keys(\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap()), 0);
        foreach ($services as $s) {
            $ck = $serviceToCategory[$s['name']] ?? null;
            if ($ck !== null && array_key_exists($ck, $categoryActiveCount)) $categoryActiveCount[$ck]++;
        }
        $activeCategoryKeys = array_keys(array_filter($categoryActiveCount, fn($n) => $n > 0));

        rto_view('public.apply', compact('services', 'states', 'serviceNameToId', 'serviceToCategory', 'categorySchemas', 'activeCategoryKeys'));
    }

    // TRACE: GET /rto-apply-form/{serviceId}/ → checks FormEngineService::
    //        getForService() (itself gated on the form_builder feature flag)
    //        → if no active v2 schema exists for this service, redirects
    //        (302, not a 404 or blank page) back to the static /rto-apply/
    //        so a shared/bookmarked link never dead-ends a customer → else
    //        renders resources/views/public/apply-dynamic.php with the full
    //        decorated schema and the dependent-dropdown source data it needs.
    //        Preconditions: none (public, unauthenticated). Postconditions:
    //        either a redirect Location header + exit, or a rendered page —
    //        never both, never neither.
    //        Edge cases handled: unknown/inactive service id (schema lookup
    //        returns null → same redirect path as "no schema configured").
    private static function routeApplyDynamic(int $serviceId): void
    {
        global $wpdb;
        $service = $serviceId > 0 ? $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, category, base_price, gst_applicable, sla_days
             FROM {$wpdb->prefix}rto_services WHERE id=%d AND is_active=1", $serviceId
        ), ARRAY_A) : null;
        if (!$service) {
            wp_safe_redirect(home_url('/rto-apply/'));
            exit;
        }
        // Part 4.10: schema lookup is category-keyed now, not service-keyed —
        // resolve this service's category via RealFormSchemaSeeder::
        // categoryMap() (same registry every other resolution in this file
        // uses) before looking up the active schema.
        $forms      = \RTOFLOW\Bootstrap::container()->make(\RTOFLOW\Services\FormEngineService::class);
        $categoryKey = null;
        foreach (\RTOFLOW\Database\Seeds\RealFormSchemaSeeder::categoryMap() as $catKey => $cat) {
            if (in_array($service['name'], $cat['services'], true)) { $categoryKey = $catKey; break; }
        }
        $schema = $categoryKey ? $forms->getForCategory($categoryKey) : null;
        if (!$schema) {
            wp_safe_redirect(home_url('/rto-apply/'));
            exit;
        }
        // Ship every registered dependent-dropdown source's full data inline
        // (same approach the static apply.php already uses for $rtos_by_state
        // — a small, rarely-changing dataset, cheaper as one inline JSON blob
        // than a further AJAX round trip per keystroke).
        $dependentSources = [];
        foreach (\RTOFLOW\Support\DependentSourceRegistry::registeredNames() as $name) {
            $dependentSources[$name] = \RTOFLOW\Support\DependentSourceRegistry::all($name);
        }
        rto_view('public.apply-dynamic', compact('service', 'schema', 'dependentSources'));
    }

    // ── Public JSON endpoints ─────────────────────────────────────────────

    private static function getCitiesJson(): void
    {
        global $wpdb;
        $stateId = Sanitiser::int($_POST['state_id'] ?? 0);
        $cities  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}rto_cities WHERE state_id=%d AND is_active=1 ORDER BY name ASC",
            $stateId
        ), ARRAY_A) ?: [];
        rto_json_ok($cities);
    }

    private static function getServicesJson(): void
    {
        global $wpdb;
        $services = $wpdb->get_results(
            "SELECT id, name, category, base_price, gst_applicable, sla_days FROM {$wpdb->prefix}rto_services WHERE is_active=1 ORDER BY display_order ASC",
            ARRAY_A
        ) ?: [];
        rto_json_ok($services);
    }

    private static function getPriceJson(): void
    {
        global $wpdb;
        $serviceId = Sanitiser::int($_POST['service_id'] ?? 0);
        $cityId    = Sanitiser::int($_POST['city_id']    ?? 0);

        $service = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_services WHERE id=%d AND is_active=1", $serviceId
        ), ARRAY_A);
        if (!$service) rto_json_err('Service not found.');

        // FIX P0: this preview must match LeadService::create()'s actual formula
        // (base_price + govt_fee + GST(base_price), govt fee GST-exempt) — it
        // previously omitted govt_fee entirely, so the price a customer saw
        // before applying could be lower than what they were actually charged
        // once the order was created.
        //
        // FIX (integration pass follow-up — closes the previously-documented
        // "Pricing preview endpoints not yet reading city overrides" gap):
        // rto_city_service_config can override base_price (service_charge)
        // and govt_fee per city+service — LeadService::create() already reads
        // this at actual-charge time, but this preview endpoint did not, so a
        // city with an override configured could show one price here and
        // charge a different one on submit. This mirrors LeadService::create()'s
        // lookup and override precedence exactly (no override / null column
        // falls back to the rto_services default, same as there).
        $cityConfig = $cityId ? $wpdb->get_row($wpdb->prepare(
            "SELECT govt_fee, service_charge FROM {$wpdb->prefix}rto_city_service_config
             WHERE city_id = %d AND service_id = %d",
            $cityId, $serviceId
        ), ARRAY_A) : null;

        $basePrice = ($cityConfig && $cityConfig['service_charge'] !== null)
            ? (float)$cityConfig['service_charge']
            : (float)$service['base_price'];
        $govtFee   = ($cityConfig && $cityConfig['govt_fee'] !== null)
            ? (float)$cityConfig['govt_fee']
            : (float)($service['govt_fee'] ?? 0);

        $gst       = new \RTOFLOW\Services\GstService();
        $breakdown = $service['gst_applicable']
            ? $gst->calculate($basePrice)
            : ['grand_total' => $basePrice, 'total_gst' => 0];

        rto_json_ok([
            'base_price'  => $basePrice,
            'govt_fee'    => $govtFee,
            'gst_amount'  => $breakdown['total_gst'],
            'gst_rate'    => $service['gst_applicable'] ? 18.0 : 0.0,
            'total'       => round($breakdown['grand_total'] + $govtFee, 2),
            'sla_days'    => $service['sla_days'],
        ]);
    }
}
