<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Config\FeatureFlags;

if (!defined('ABSPATH')) exit;

/**
 * Enterprise Customizer Hub
 *
 * A single landing page linking out to every "reusable engine" config
 * surface that actually exists in the admin today (eligibility rules,
 * matching weights, feature flags, city/service pricing). Only links to
 * pages confirmed present and wired in Router.php — nothing speculative.
 */
class CustomizerHubController
{
    public function index(): void
    {
        if (!rto_is_staff()) {
            wp_die('You do not have permission to access this page.', 'Access Denied', ['response' => 403, 'back_link' => true]);
        }

        $cards = [
            [
                'icon'  => '✅',
                'title' => 'Eligibility Rules',
                'desc'  => 'Configure who qualifies per service — field, operator, value, and rejection message — with no deployment.',
                // FIX (integration pass): the clean /rto-admin/eligibility/
                // URL now has a real rewrite rule (see Router.php); the raw
                // query-string form is no longer needed as a workaround.
                'url'   => home_url('/rto-admin/eligibility/'),
            ],
            [
                'icon'  => '📝',
                'title' => 'Dynamic Forms',
                'desc'  => 'Define which fields appear on each service\'s application form — no code change per service.',
                'url'   => home_url('/rto-admin/forms/'),
            ],
            [
                'icon'  => '🔀',
                'title' => 'Workflow Engine',
                'desc'  => 'Define a custom status lifecycle per service instead of one fixed workflow for every service.',
                'url'   => home_url('/rto-admin/workflows/'),
            ],
            [
                'icon'  => '🎯',
                'title' => 'Matching Configuration',
                'desc'  => 'Tune the weights and thresholds used to auto-match leads to vendors.',
                'url'   => home_url('/rto-admin/settings/?tab=matching'),
            ],
            [
                'icon'   => '🎛',
                'title'  => 'Feature Flags',
                'desc'   => 'Enable or disable optional modules platform-wide.',
                'url'    => home_url('/rto-admin/features/'),
                'status' => $this->featureFlagsStatus(),
            ],
            [
                'icon'   => '🏙',
                'title'  => 'City & Service Pricing',
                'desc'   => 'Control service visibility and price visibility independently for each city.',
                'url'    => home_url('/rto-admin/city-pricing/'),
                'status' => $this->cityPricingStatus(),
            ],
        ];

        rto_view('admin.customizer.index', compact('cards'));
    }

    /**
     * FIX (Known Limitation: hub showed no live status from its linked
     * screens): count of non-core feature flags currently switched off,
     * read straight from FeatureFlags::raw() — the same source of truth
     * FeaturesController itself reads, so this never drifts from the real
     * Feature Flags screen. Core flags are excluded since they cannot be
     * turned off through the UI (see FeatureFlags::bulk_save()/applyBooleanMap()).
     */
    private function featureFlagsStatus(): ?string
    {
        $core = ['lead_management', 'vendor_management', 'payment_collection', 'document_management'];
        $off  = 0;
        foreach (FeatureFlags::raw() as $flag => $enabled) {
            if (in_array($flag, $core, true)) continue;
            if (!$enabled) $off++;
        }
        if ($off === 0) return null;
        return $off === 1 ? '1 flag off' : "{$off} flags off";
    }

    /**
     * FIX (Known Limitation: hub showed no live status from its linked
     * screens): count of active cities with zero visible services —
     * a single lightweight COUNT query mirroring the exact tables and
     * is_active/is_visible semantics CityServiceConfigController::index()
     * already uses, without duplicating any of its pricing logic.
     */
    private function cityPricingStatus(): ?string
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return null;

        $citiesTbl = $wpdb->prefix . 'rto_cities';
        $cfgTbl    = $wpdb->prefix . 'rto_city_service_config';

        $hidden = $wpdb->get_var(
            "SELECT COUNT(*) FROM (
                SELECT c.id, COALESCE(SUM(cfg.is_visible), 0) AS visible_services
                FROM {$citiesTbl} c
                LEFT JOIN {$cfgTbl} cfg ON cfg.city_id = c.id
                WHERE c.is_active = 1
                GROUP BY c.id
             ) hidden_cities
             WHERE visible_services = 0"
        );

        $hidden = (int) $hidden;
        if ($hidden === 0) return null;
        return $hidden === 1 ? '1 city fully hidden' : "{$hidden} cities fully hidden";
    }
}
