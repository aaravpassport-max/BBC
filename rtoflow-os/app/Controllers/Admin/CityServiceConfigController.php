<?php

namespace RTOFLOW\Controllers\Admin;

use RTOFLOW\Security\Sanitiser;

if (!defined('ABSPATH')) exit;

/**
 * City Service Config Controller
 *
 * Manages the per-city, per-service pricing and visibility configuration.
 *
 * Visibility logic (four independent layers):
 *   1. Service visibility  — does this service appear on the city page?
 *   2. Price visibility    — is the price shown for this service in this city?
 *   3. City-level pricing  — handled by checking if ANY service in a city has show_price=1
 *   4. Service-level price — individual show_price flag per city+service row
 *
 * Default behaviour: no config row = not visible, no price shown.
 */
class CityServiceConfigController
{
    private \wpdb   $db;
    private string  $p;
    private string  $tbl; // rto_city_service_config

    public function __construct()
    {
        global $wpdb;
        $this->db  = $wpdb;
        $this->p   = $wpdb->prefix;
        $this->tbl = $wpdb->prefix . 'rto_city_service_config';
    }

    // ── Admin: City config overview ───────────────────────────────────────

    /**
     * Show all cities with their configuration summary.
     * GET /rto-admin/city-pricing/
     */
    // TRACE: admin navigates to /rto-admin/city-pricing/ →
    //        rto_is_admin() enforced →
    //        SELECT all active cities with LEFT JOIN rto_states (state_name) +
    //        LEFT JOIN rto_city_service_config (counts per city) →
    //        require admin-header + city-pricing/index.php + admin-footer →
    //        preconditions: user is admin; rto_cities table exists →
    //        postconditions: $cities array with name,slug,state_name,configured/visible/priced counts rendered →
    //        edge cases: no active cities → empty state in index.php shown; DB error → $cities=[]
    public function index(): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        // FIX A: rto_cities has no state_name column — JOIN rto_states
        $cities = $this->db->get_results(
            "SELECT c.id, c.name, c.slug, st.name as state_name,
                    COUNT(DISTINCT cfg.service_id) as configured_services,
                    SUM(cfg.is_visible)            as visible_services,
                    SUM(cfg.show_price)            as priced_services
             FROM {$this->p}rto_cities c
             LEFT JOIN {$this->p}rto_states st ON st.id = c.state_id
             LEFT JOIN {$this->tbl} cfg ON cfg.city_id = c.id
             WHERE c.is_active = 1
             GROUP BY c.id
             ORDER BY c.name ASC",
            ARRAY_A
        ) ?: [];

        $pageTitle = 'City Pricing & Service Visibility';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
        require RTOFLOW_DIR . 'resources/views/admin/city-pricing/index.php';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
    }

    /**
     * Show and manage config for a specific city.
     * GET/POST /rto-admin/city-pricing/{city_id}/
     */
    // TRACE: admin navigates to /rto-admin/city-pricing/{cityId}/ (GET) or submits form (POST) →
    //        rto_is_admin() enforced →
    //        GET: loads city with state_name JOIN; loads all active services grouped by category;
    //             loads existing config rows keyed by service_id; renders configure.php →
    //        POST: check_admin_referer('rtoflow_city_pricing_'.$cityId) verified;
    //              saveConfig() loops POST services[] and upserts; redirect with ?saved=1 →
    //        preconditions: cityId > 0; city is active; user is admin; nonce valid on POST →
    //        postconditions: GET renders form pre-filled; POST persists all service configs;
    //                        cache invalidated; redirect to same page with saved=1 banner →
    //        edge cases: cityId not found → wp_die 404; no services → empty state shown;
    //                    POST with no services[] → saveConfig() returns early, redirect still fires
    public function configure(int $cityId): void
    {
        if (!rto_is_admin()) wp_die('Access denied.', 403);

        // FIX B: rto_cities has no state_name — JOIN rto_states
        $city = $this->db->get_row($this->db->prepare(
            "SELECT c.*, st.name as state_name
             FROM {$this->p}rto_cities c
             LEFT JOIN {$this->p}rto_states st ON st.id = c.state_id
             WHERE c.id = %d AND c.is_active = 1",
            $cityId
        ), ARRAY_A);

        if (!$city) wp_die('City not found.', 'Not Found', ['response' => 404, 'back_link' => true]);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!check_admin_referer('rtoflow_city_pricing_' . $cityId, '_rto_nonce')) {
                wp_die('Security check failed.', 403);
            }
            $this->saveConfig($cityId);
            wp_safe_redirect(add_query_arg('saved', '1', home_url('/rto-admin/city-pricing/' . $cityId . '/')));
            exit;
        }

        // Load all active services
        $services = $this->db->get_results(
            "SELECT id, name, category, base_price, govt_fee, description
             FROM {$this->p}rto_services
             WHERE is_active = 1
             ORDER BY category ASC, name ASC",
            ARRAY_A
        ) ?: [];

        // Load existing config for this city (keyed by service_id)
        $configs = [];
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->tbl} WHERE city_id = %d",
            $cityId
        ), ARRAY_A) ?: [];
        foreach ($rows as $row) {
            $configs[(int)$row['service_id']] = $row;
        }

        // Group services by category
        $byCategory = [];
        foreach ($services as $svc) {
            $byCategory[$svc['category']][] = $svc;
        }

        $saved     = !empty($_GET['saved']);
        $pageTitle = 'Configure: ' . esc_html($city['name']);
        require RTOFLOW_DIR . 'resources/views/layouts/admin-header.php';
        require RTOFLOW_DIR . 'resources/views/admin/city-pricing/configure.php';
        require RTOFLOW_DIR . 'resources/views/layouts/admin-footer.php';
    }

    // ── AJAX: Bulk update all services for a city ─────────────────────────

    /**
     * Bulk save all service configurations for a city from the form submission.
     *
     * // TRACE: admin submits the full city configure form →
     * //        nonce verified; admin role enforced →
     * //        loops through all services in POST data →
     * //        upserts one row per service in rto_city_service_config →
     * //        deletes rows for services that are now unconfigured (set to hidden + no price) →
     * //        invalidates city cache transients →
     * //        redirects with ?saved=1 →
     * //        edge cases: empty POST, invalid service IDs skipped
     */
    private function saveConfig(int $cityId): void
    {
        $services = (array)($_POST['services'] ?? []);
        if (empty($services)) return;

        // FIX (integration pass, Reporting/Audit consistency gap): this
        // bulk-form save path had no audit trail — only its sibling
        // single-row AJAX method (saveAjax()) logged 'city_service_config.updated'.
        // Since pricing overrides now flow into actual order totals
        // (Pricing Engine phase 2, LeadService::create()), an unaudited path
        // to change them is a real gap, not cosmetic. One summary entry per
        // form submission (not per row) to match how this form is actually
        // used — an admin configuring a whole city's price list at once.
        $touchedServiceIds = array_map('intval', array_keys($services));

        // FIX (Config Versioning wiring — City/Service Pricing): the
        // per-row apply logic used to live inline in this loop; it's now
        // shared with applyVersionedPayload() below (used when a version is
        // rolled back / manually published) via applyRow(), so both paths
        // apply a row identically instead of two independently-maintained
        // copies of the same upsert-or-delete logic drifting apart.
        $normalized = [];
        foreach ($services as $serviceId => $cfg) {
            $serviceId = (int)$serviceId;
            if ($serviceId < 1) continue;
            $normalized[$serviceId] = $this->applyRow($cityId, $serviceId, $cfg);
        }

        \RTOFLOW\Services\AuditService::log('city_service_config.bulk_updated', null, [
            'city_id' => $cityId, 'service_ids' => $touchedServiceIds,
        ]);

        // Invalidate caches for this city
        delete_transient('rtofl_city_services_' . $cityId);
        delete_transient('rtofl_active_services');

        // FIX (Config Versioning wiring): record a version snapshot of this
        // city's full pricing config, same pattern as MatchingConfig::save()
        // and the Feature Flags screen — publish(..., false) because the
        // value is already live via applyRow() above; this call only
        // records history. config_key is per-city ('city_pricing_{id}')
        // since that's the natural unit an admin edits and would want to
        // roll back — not a single blob for every city in the system.
        $versionId = \RTOFLOW\Services\ConfigVersionService::saveDraft(
            'city_pricing_' . $cityId, $normalized, get_current_user_id() ?: 0, 'Saved via City Pricing screen'
        );
        if ($versionId) {
            \RTOFLOW\Services\ConfigVersionService::publish($versionId, false);
        }
    }

    /**
     * Upsert (or delete-if-blank) one city+service pricing row. Shared by
     * saveConfig()'s bulk-form loop and applyVersionedPayload() (the
     * config-version rollback/publish path) so both apply a row the same
     * way. Returns the normalized values actually applied, suitable for
     * storing as (part of) a version payload.
     *
     * @param array $cfg Raw field values — either from $_POST (strings) or
     *              a decoded version payload (already-normalized scalars);
     *              every field is re-normalized here regardless of source.
     * @return array{is_visible:bool,show_price:bool,govt_fee:?float,service_charge:?float,notes:string}
     */
    private function applyRow(int $cityId, int $serviceId, array $cfg): array
    {
        $isVisible     = (bool)!empty($cfg['is_visible']);
        $showPrice     = (bool)(!empty($cfg['show_price']) && $isVisible); // price requires visibility
        $govtFee       = (isset($cfg['govt_fee'])       && $cfg['govt_fee'] !== '' && $cfg['govt_fee'] !== null)
                         ? max(0, (float)$cfg['govt_fee']) : null;
        $serviceCharge = (isset($cfg['service_charge']) && $cfg['service_charge'] !== '' && $cfg['service_charge'] !== null)
                         ? max(0, (float)$cfg['service_charge']) : null;
        $notes         = Sanitiser::text($cfg['notes'] ?? '', 500);

        $normalized = [
            'is_visible'     => $isVisible,
            'show_price'     => $showPrice,
            'govt_fee'       => $govtFee,
            'service_charge' => $serviceCharge,
            'notes'          => $notes,
        ];

        if (!$isVisible && !$showPrice && $govtFee === null && $serviceCharge === null && !$notes) {
            // Nothing configured — remove any existing row to keep the table clean
            $this->db->delete($this->tbl, ['city_id' => $cityId, 'service_id' => $serviceId]);
            return $normalized;
        }

        $r = $this->db->query($this->db->prepare(
            "INSERT INTO {$this->tbl}
                 (city_id, service_id, is_visible, show_price, govt_fee, service_charge, notes, updated_by, updated_at)
             VALUES (%d, %d, %d, %d, %s, %s, %s, %d, %s)
             ON DUPLICATE KEY UPDATE
                 is_visible     = VALUES(is_visible),
                 show_price     = VALUES(show_price),
                 govt_fee       = VALUES(govt_fee),
                 service_charge = VALUES(service_charge),
                 notes          = VALUES(notes),
                 updated_by     = VALUES(updated_by),
                 updated_at     = VALUES(updated_at)",
            $cityId,
            $serviceId,
            (int)$isVisible,
            (int)$showPrice,
            $govtFee !== null ? (string)$govtFee : null,
            $serviceCharge !== null ? (string)$serviceCharge : null,
            $notes,
            get_current_user_id(),
            current_time('mysql')
        ));
        if ($r === false) {
            error_log('RTOFLOW CityServiceConfig::applyRow failed for city=' . $cityId . ' service=' . $serviceId . ' — ' . $this->db->last_error);
            // Non-fatal: continue other services; admin sees partial save/apply on reload
        }

        return $normalized;
    }

    /**
     * Apply a whole city's pricing config from a stored version payload —
     * the counterpart to saveConfig() for the "activate an older version"
     * path (config-version publish/rollback). Any service present in the
     * CURRENT live config but absent from the payload is cleared (deleted),
     * so a rollback genuinely reproduces the older state rather than only
     * overlaying it on top of whatever is live now.
     *
     * @param array<int,array> $payload Keyed by service_id, same shape applyRow() returns.
     */
    public function applyVersionedPayload(int $cityId, array $payload): void
    {
        $existingServiceIds = array_map('intval', $this->db->get_col($this->db->prepare(
            "SELECT service_id FROM {$this->tbl} WHERE city_id = %d", $cityId
        )));

        foreach ($payload as $serviceId => $cfg) {
            $serviceId = (int)$serviceId;
            if ($serviceId < 1 || !is_array($cfg)) continue;
            $this->applyRow($cityId, $serviceId, $cfg);
        }

        // Services that had a row before this rollback but aren't in the
        // target version's payload at all did not exist as configured
        // overrides in that version — remove them so the rollback is exact.
        $payloadServiceIds = array_map('intval', array_keys($payload));
        foreach (array_diff($existingServiceIds, $payloadServiceIds) as $staleServiceId) {
            $this->db->delete($this->tbl, ['city_id' => $cityId, 'service_id' => $staleServiceId]);
        }

        delete_transient('rtofl_city_services_' . $cityId);
        delete_transient('rtofl_active_services');
    }

    // ── Public API: get visible services for a city ───────────────────────

    /**
     * Get all visible services for a city, with pricing data if show_price is enabled.
     * Used by frontend city pages.
     *
     * Returns array of services with:
     *   - All service fields
     *   - 'show_price' bool
     *   - 'display_govt_fee'     (city override OR service default)
     *   - 'display_service_charge' (city override OR service default)
     *   - 'display_total'        (sum of above, if show_price)
     *
     * @param int $cityId
     * @return array
     */
    // TRACE: called from Router::routeWebsite city page handler →
    //        checks transient 'rtofl_city_services_{cityId}' (1h cache) →
    //        on miss: INNER JOIN rto_services + rto_city_service_config WHERE city_id=%d AND is_visible=1 →
    //        computes display_govt_fee (override ?? service default), display_service_charge (override ?? base_price) →
    //        computes display_total = display_govt_fee + display_service_charge →
    //        stores result in transient; returns array →
    //        preconditions: migration 7 ran; $cityId > 0 →
    //        postconditions: returns only visible services for this city with computed display prices;
    //                        transient set for 3600s; returns [] if no visible services →
    //        edge cases: cache miss falls to DB; empty result = [] (not null); $cityId=0 returns []
    public static function getVisibleServicesForCity(int $cityId): array
    {
        $cacheKey = 'city_services_' . $cityId;
        $cached   = get_transient('rtofl_' . $cacheKey);
        if ($cached !== false && is_array($cached)) return $cached;

        global $wpdb;
        $tbl = $wpdb->prefix . 'rto_city_service_config';

        $rows = $wpdb->get_results($wpdb->prepare(
            // FIX C: s.icon does not exist in rto_services schema — removed
            "SELECT
                s.id, s.name, s.slug, s.category, s.description,
                s.base_price, s.govt_fee as default_govt_fee,
                s.sla_days,
                cfg.is_visible,
                cfg.show_price,
                cfg.govt_fee       as override_govt_fee,
                cfg.service_charge as override_service_charge,
                cfg.notes
             FROM {$wpdb->prefix}rto_services s
             INNER JOIN {$tbl} cfg ON cfg.service_id = s.id AND cfg.city_id = %d
             WHERE s.is_active = 1
               AND cfg.is_visible = 1
             ORDER BY s.category ASC, s.display_order ASC, s.name ASC",
            $cityId
        ), ARRAY_A) ?: [];

        // Compute display prices
        foreach ($rows as &$row) {
            $govtFee       = $row['override_govt_fee']      !== null
                             ? (float)$row['override_govt_fee']
                             : (float)$row['default_govt_fee'];
            $serviceCharge = $row['override_service_charge'] !== null
                             ? (float)$row['override_service_charge']
                             : (float)$row['base_price'];

            $row['display_govt_fee']       = $govtFee;
            $row['display_service_charge'] = $serviceCharge;
            $row['display_total']          = $govtFee + $serviceCharge;
        }
        unset($row);

        set_transient('rtofl_' . $cacheKey, $rows, 3600); // 1-hour cache
        return $rows;
    }

    /**
     * Get config for a single city+service pair.
     * Returns null if not configured (= not visible, no price).
     */
    // TRACE: called inline to check a specific city+service config →
    //        SELECT * FROM rto_city_service_config WHERE city_id=%d AND service_id=%d →
    //        preconditions: both IDs > 0; table exists →
    //        postconditions: returns config row as ARRAY_A, or null if not configured →
    //        edge cases: not configured = null (= not visible, no price) — callers must null-check
    public static function getConfig(int $cityId, int $serviceId): ?array
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rto_city_service_config WHERE city_id=%d AND service_id=%d",
            $cityId, $serviceId
        ), ARRAY_A) ?: null;
    }
}
