<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Support\HelpContent;
use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * TRACE: Router::routeAdmin('help', ...) → HelpCenterController::index()
 *        → reads HelpContent::all() (a real, hand-written PHP array, not a
 *        database table — see the honesty note in HelpContent.php)
 *        → renders resources/views/admin/help/index.php, which lists every
 *        module in MODULE_INDEX (documented AND not-yet-documented, clearly
 *        labelled) and, if a real ?article= slug is present, the full
 *        article for it
 *        → search is a same-page AJAX call (admin.help.search) that does a
 *        plain PHP substring match over title/summary/body text — no
 *        external search index, so it can never return a broken link to
 *        an article that does not exist
 * PRECONDITIONS: rto_is_staff() (enforced by routeAdmin() itself before this
 *        class is ever reached — see Router.php).
 * POSTCONDITIONS: renders HTML; searchAjax() returns a JSON list of
 *        {slug, title, module, snippet} — never throws, an empty/no-match
 *        query returns an empty array, not an error.
 * EDGE CASES HANDLED: unknown ?article= slug → falls back to the index
 *        listing (no fatal, no blank page); empty search query → returns
 *        every article (browsing, not erroring).
 */
class HelpCenterController
{
    /**
     * Every real rto_page slug this platform's admin menu/router exposes
     * (per the Part 5.0 audit), mapped to whether a full HelpContent
     * article exists for it yet. This list is the honest inventory Rule 28
     * of the governing brief requires — nothing here claims a module is
     * documented when it is not; the Help Centre index renders the
     * 'planned' ones with a visible "Not yet documented" label rather than
     * hiding them or silently omitting them from navigation.
     */
    public const MODULE_INDEX = [
        'dashboard'          => ['label' => 'Dashboard',                 'status' => 'documented'],
        'leads'              => ['label' => 'Leads',                     'status' => 'documented'],
        'vendors'            => ['label' => 'Vendors',                   'status' => 'documented'],
        'services'           => ['label' => 'Services',                  'status' => 'documented'],
        'forms'              => ['label' => 'Form Builder (Categories)', 'status' => 'documented'],
        'eligibility'        => ['label' => 'Eligibility Rules',         'status' => 'documented'],
        'city-pricing'       => ['label' => 'City / Service Pricing',    'status' => 'documented'],
        'settings-matching'  => ['label' => 'Settings → Vendor Matching','status' => 'documented'],
        'payouts'            => ['label' => 'Payouts',                   'status' => 'documented'],
        'complaints'         => ['label' => 'Complaints / Grievances',   'status' => 'documented'],
        'payments'           => ['label' => 'Payments Ledger',           'status' => 'documented'],
        'reports'            => ['label' => 'Reports',                   'status' => 'documented'],
        'masters'            => ['label' => 'Cities / RTOs (Masters)',   'status' => 'documented'],
        'ratings'            => ['label' => 'Ratings',                   'status' => 'documented'],
        'workflows'          => ['label' => 'Workflow Builder',          'status' => 'documented'],
        'customizer'         => ['label' => 'Customizer Hub',            'status' => 'documented'],
        'settings'           => ['label' => 'Settings (Company/SLA)',    'status' => 'documented'],
        'email-templates'    => ['label' => 'Email Templates',           'status' => 'documented'],
        'ai'                 => ['label' => 'AI Insights',               'status' => 'documented'],
        // Known Limitations audit: this used to be a documentation-only stub
        // with no real HelpContent article; a real admin screen (Router.php's
        // /rto-admin/automation/) already existed with genuinely dead event
        // wiring for 4 of its 10 advertised trigger events — both the article
        // and the event-wiring bug are now fixed (see HelpContent.php).
        'automation'         => ['label' => 'Automation Rules',          'status' => 'documented'],
        'features'           => ['label' => 'Feature Flags',             'status' => 'documented'],
        'staff'              => ['label' => 'Staff / Users',             'status' => 'documented'],
        // Known Limitations audit: previously omitted from this index
        // entirely (HelpContent.php's own header comment claimed both were
        // listed here as 'planned', which was not actually true) — added
        // now that both have real HelpContent articles.
        'gdpr'               => ['label' => 'GDPR Data Export',          'status' => 'documented'],
        'system'             => ['label' => 'System Status & Cron Monitor', 'status' => 'documented'],
        // Known Limitations audit: these four real admin screens
        // (Router.php's 'webhooks', 'users', 'rtos', and 'nav' arms) had
        // no HelpContent article and no entry in this index at all —
        // added now that full articles exist for all four.
        'webhooks'           => ['label' => 'Webhooks',                  'status' => 'documented'],
        'users'              => ['label' => 'Users & Staff',             'status' => 'documented'],
        'rtos'               => ['label' => 'RTO Management',            'status' => 'documented'],
        'nav'                => ['label' => 'Navigation & Quick Access', 'status' => 'documented'],
    ];

    public function index(): void
    {
        $slug   = Sanitiser::slug($_GET['article'] ?? '');
        $q      = Sanitiser::text($_GET['q'] ?? '');
        // ENTERPRISE GAP FIX (Phase 5, item 4 — Help Center becomes
        // admin-editable): getMerged() overlays any saved override on top
        // of the static article — see HelpContent::getMerged().
        $article = $slug ? HelpContent::getMerged($slug) : null;

        rto_view('admin.help.index', [
            'article'     => $article,
            'articleSlug' => $slug,
            'query'       => $q,
            'moduleIndex' => self::MODULE_INDEX,
        ]);
    }

    /**
     * AJAX: admin.help.search — plain-text search over the real article
     * registry. Deliberately simple (no external search engine dependency)
     * so it can never silently drift out of sync with what actually exists.
     */
    public function searchAjax(): void
    {
        // Matches the same staff-gate + nonce pattern every other admin.*
        // AJAX action in this codebase enforces individually (see
        // Router::dispatchAjax() — there is no central gate before the
        // match() table, each handler checks its own access).
        if (!rto_is_staff()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $q = trim(Sanitiser::text($_POST['q'] ?? $_GET['q'] ?? ''));

        // FIX (found while verifying this against the spec's own example
        // searches, e.g. "Why is provider not showing?"): a literal
        // full-phrase substring match almost never hits real natural-
        // language admin questions — a query has to appear byte-for-byte
        // inside an article for anything to match. Switched to a simple,
        // dependency-free word-overlap score: split the query into
        // significant words (stripping common English stopwords so "is",
        // "this", "not" etc. don't dominate the score or cause false
        // matches on every article), count how many of those words appear
        // anywhere in each article's searchable text, and rank by match
        // count — an article matching more of the query's real content
        // words ranks above one matching fewer. An empty query still
        // returns every article (browsing mode).
        static $stopwords = ['a','an','the','is','it','this','that','to','for','of','in','on','why','how','do','i','does','can','not','are','be','my','me','with'];
        // Also drop anything under 3 characters — a 2-letter query word (or
        // a stopword like "no" that slipped through) would otherwise match
        // almost every article via plain substring containment (e.g. "no"
        // inside "know"/"not"), which is exactly what turned a genuinely
        // nonsense query into a false hit when this was first verified below.
        $words = array_values(array_filter(
            preg_split('/[^a-z0-9]+/', strtolower($q)),
            fn($w) => strlen($w) >= 3 && !in_array($w, $stopwords, true)
        ));

        $scored = [];
        foreach (HelpContent::all() as $slug => $a) {
            $haystack = strtolower($a['title'] . ' ' . $a['module'] . ' ' . $a['summary'] . ' ' . ($a['what_is'] ?? '') . ' ' . ($a['known_limitation'] ?? ''));
            if (!empty($a['troubleshooting'])) $haystack .= ' ' . strtolower(implode(' ', $a['troubleshooting']));
            if (!empty($a['troubleshooting_guide']['steps'])) $haystack .= ' ' . strtolower(implode(' ', $a['troubleshooting_guide']['steps']));
            // Word-boundary match, not plain substring — otherwise a short
            // query word matches as a fragment inside an unrelated longer
            // word (the same false-positive risk noted above).
            $haystackWords = preg_split('/[^a-z0-9]+/', $haystack);

            if (empty($words)) {
                $score = 1; // browsing mode: everything matches equally
            } else {
                $score = 0;
                foreach ($words as $w) { if (in_array($w, $haystackWords, true)) $score++; }
            }
            if ($score > 0) {
                $scored[] = ['slug' => $slug, 'title' => $a['title'], 'module' => $a['module'], 'snippet' => $a['summary'], '_score' => $score];
            }
        }
        usort($scored, fn($x, $y) => $y['_score'] <=> $x['_score']);
        $results = array_map(function ($r) { unset($r['_score']); return $r; }, $scored);

        rto_json_ok(['results' => $results]);
    }

    // ENTERPRISE GAP FIX (Phase 5, item 4 — "Help Center becomes admin-
    // editable"): saves (or clears, on empty submission) the override this
    // slug's "what_is" narrative — see HelpContent::getMerged() for how it's
    // layered back on at render time. Admin-only (not the broader
    // rto_is_staff() gate the rest of this controller uses) since this is a
    // genuine content-authoring action, not read-only help browsing.
    public function saveOverride(): void
    {
        if (!rto_is_admin()) rto_json_err('Access denied.', 403);
        if (!check_ajax_referer('rto_admin_lead', 'rto_nonce', false)) rto_json_err('Security check failed.', 403);

        $slug = Sanitiser::slug($_POST['slug'] ?? '');
        if (!$slug || !HelpContent::get($slug)) rto_json_err('Unknown help article.');

        $whatIs = wp_kses_post(wp_unslash($_POST['what_is'] ?? ''));

        global $wpdb;
        $table = $wpdb->prefix . 'rto_help_overrides';

        if (trim(wp_strip_all_tags($whatIs)) === '') {
            // Empty submission clears the override — reverts to the static
            // article rather than saving an empty string that would then
            // (per getMerged()'s !empty() check) just fall back anyway; this
            // makes "revert to default" an explicit, intentional action.
            $wpdb->delete($table, ['slug' => $slug]);
            \RTOFLOW\Services\AuditService::log('help.override_cleared', null, ['slug' => $slug]);
            rto_json_ok(null, 'Reverted to the default article.');
        }

        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug=%s", $slug));
        if ($existing) {
            $wpdb->update($table, [
                'what_is'    => $whatIs,
                'updated_by' => get_current_user_id(),
                'updated_at' => current_time('mysql'),
            ], ['slug' => $slug]);
        } else {
            $wpdb->insert($table, [
                'slug'       => $slug,
                'what_is'    => $whatIs,
                'updated_by' => get_current_user_id(),
                'updated_at' => current_time('mysql'),
            ]);
        }

        \RTOFLOW\Services\AuditService::log('help.override_saved', null, ['slug' => $slug]);
        rto_json_ok(null, 'Saved.');
    }
}
