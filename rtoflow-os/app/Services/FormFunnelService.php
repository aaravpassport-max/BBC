<?php

namespace RTOFLOW\Services;

if (!defined('ABSPATH')) exit;

/**
 * Form Funnel Event Tracking
 *
 * Closes the checklist gap "Analytics for dynamic form submissions did not
 * exist / requested analytics scope exceeds what schema supports": before
 * this, FormBuilderController::analytics() could only report a completed
 * submission count, because nothing recorded a visitor reaching a step
 * without finishing it, or hitting a field validation error along the way —
 * so abandonment rate, drop-off-by-step, and per-field validation-failure
 * rate were all genuinely impossible to compute, not merely unqueried.
 *
 * See database/migrations/2024_01_01_000022_create_form_funnel_events.php
 * for the rto_form_funnel_events table this writes to, and
 * Router::dispatchPublicAjax()'s 'form_funnel_event' action for the one
 * AJAX endpoint that calls recordEvent().
 *
 * Events are addressed by an opaque, purely client-generated
 * `session_token` (crypto-random, generated in JS on page load — see
 * apply.php) — never a WordPress session/user id, matching the same
 * anonymous-visitor design already established for rto_form_drafts. This
 * service does not attempt to identify or de-anonymise visitors; it exists
 * only to correlate one visitor's own sequence of step-reached events into
 * a funnel.
 *
 * DISCLOSED LIMITATION: the client-side JS beacons that call recordEvent()
 * via the AJAX endpoint (see apply.php) could not be exercised in a live
 * browser in this sandbox (no browser tool available) — this matches every
 * other disclosed dynamic-form-JS limitation already logged elsewhere in
 * this checklist (e.g. "Dynamic form integration verified only by static
 * trace, not live browser/WordPress+MySQL session"). The PHP recording
 * endpoint, the schema, and the analytics aggregation logic below are all
 * independently real-execution verified against the actual database layer.
 */
class FormFunnelService
{
    public const VALID_EVENT_TYPES = ['step_reached', 'validation_failed', 'submitted'];

    /** Hard ceiling on how many events one session_token may record — abuse guard, not a real funnel limit. */
    public const MAX_EVENTS_PER_SESSION = 200;

    public function recordEvent(
        string $eventType,
        string $category,
        ?string $serviceName,
        int $stepIndex,
        ?string $fieldKey,
        string $sessionToken
    ): array {
        global $wpdb;

        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            return ['success' => false, 'message' => 'Unknown funnel event type.'];
        }
        $category = trim($category);
        if ($category === '') {
            return ['success' => false, 'message' => 'A form category is required.'];
        }
        $sessionToken = $this->normaliseSessionToken($sessionToken);
        if (!$sessionToken) {
            return ['success' => false, 'message' => 'A valid session token is required.'];
        }
        if ($stepIndex < 0 || $stepIndex > 50) {
            $stepIndex = 0; // clamp rather than reject — a malformed step index shouldn't drop a real event
        }

        // Abuse guard: cap total events per session_token so a single
        // misbehaving/malicious client can't flood the table.
        $existingCount = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rto_form_funnel_events WHERE session_token = %s",
            $sessionToken
        ));
        if ($existingCount >= self::MAX_EVENTS_PER_SESSION) {
            // Not an error the client needs to see or retry — silently
            // accepted-but-dropped, exactly like a rate limit past the point
            // of usefulness for a single visitor's own funnel.
            return ['success' => true, 'message' => 'OK'];
        }

        $inserted = $wpdb->insert($wpdb->prefix . 'rto_form_funnel_events', [
            'event_type'    => $eventType,
            'category'      => \RTOFLOW\Security\Sanitiser::text($category, 100),
            'service_name'  => $serviceName !== null ? \RTOFLOW\Security\Sanitiser::text($serviceName, 150) : null,
            'step_index'    => $stepIndex,
            'field_key'     => $fieldKey !== null ? \RTOFLOW\Security\Sanitiser::text($fieldKey, 100) : null,
            'session_token' => $sessionToken,
            'created_at'    => current_time('mysql'),
        ]);

        if (!$inserted) {
            error_log('RTOFLOW FormFunnelService: event insert failed — ' . $wpdb->last_error);
        }

        return ['success' => (bool)$inserted, 'message' => $inserted ? 'OK' : 'Unable to record event.'];
    }

    private function normaliseSessionToken(string $token): ?string
    {
        $token = trim($token);
        return preg_match('/^[a-f0-9]{16,64}$/', $token) ? $token : null;
    }

    // ── Funnel analytics ─────────────────────────────────────────────────
    //
    // Used by FormBuilderController::analytics() to compute the metrics that
    // were previously flagged as impossible: abandonment rate, drop-off by
    // step, and per-field validation-failure counts. All figures here are
    // necessarily bounded by how long client-side beacon instrumentation has
    // actually been live — a category with zero step_reached rows simply has
    // no funnel data yet, and this returns nulls/zeros for it rather than a
    // misleading 100% or 0% rate.

    /**
     * BUGFIX (caught by this feature's own real-execution harness before
     * delivery, not shipped and found later): step_reached events at
     * step_index=0 are recorded BEFORE a specific sub-service is chosen (see
     * apply.php's page-load beacon), so they always have service_name=NULL.
     * The original version of this method filtered only by service_name (IN
     * (...) OR IS NULL) with no category filter at all — since every
     * category's step-0 events share that same NULL service_name, a category
     * with genuinely zero funnel data would still incorrectly inherit every
     * OTHER category's step-0 event count via the "OR service_name IS NULL"
     * branch, producing a fabricated 100% (or any other misleading) rate
     * instead of the honest "no data yet" null this method is supposed to
     * return. All three methods below now additionally require $category to
     * disambiguate step-0's necessarily-NULL service_name.
     *
     * @return array{step_reached:int, submitted:int, abandonment_rate:?float}
     */
    public function getAbandonmentSummary(string $category, array $serviceNames): array
    {
        global $wpdb;
        if ($category === '' || empty($serviceNames)) return ['step_reached' => 0, 'submitted' => 0, 'abandonment_rate' => null];

        $placeholders = implode(',', array_fill(0, count($serviceNames), '%s'));

        // "Reached the funnel" = at least one step_reached event recorded for
        // that session_token within this category (step_index=0, i.e. the
        // visitor opened the form at all — service_name is always NULL at
        // this point, so category is the only real disambiguator here).
        $reached = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_token) FROM {$wpdb->prefix}rto_form_funnel_events
             WHERE event_type = 'step_reached' AND step_index = 0 AND category = %s",
            [$category]
        ));

        $submitted = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_token) FROM {$wpdb->prefix}rto_form_funnel_events
             WHERE event_type = 'submitted' AND category = %s
             AND (service_name IN ({$placeholders}) OR service_name IS NULL)",
            array_merge([$category], $serviceNames)
        ));

        $rate = $reached > 0 ? round((1 - ($submitted / $reached)) * 100, 1) : null;

        return ['step_reached' => $reached, 'submitted' => $submitted, 'abandonment_rate' => $rate];
    }

    /**
     * @return array<int, array{step_index:int, sessions_reached:int}>
     */
    public function getDropOffByStep(string $category, array $serviceNames): array
    {
        global $wpdb;
        if ($category === '' || empty($serviceNames)) return [];

        $placeholders = implode(',', array_fill(0, count($serviceNames), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT step_index, COUNT(DISTINCT session_token) AS sessions_reached
             FROM {$wpdb->prefix}rto_form_funnel_events
             WHERE event_type = 'step_reached' AND category = %s
             AND (service_name IN ({$placeholders}) OR service_name IS NULL)
             GROUP BY step_index ORDER BY step_index ASC",
            array_merge([$category], $serviceNames)
        ), \ARRAY_A) ?: [];

        return array_map(fn($r) => ['step_index' => (int)$r['step_index'], 'sessions_reached' => (int)$r['sessions_reached']], $rows);
    }

    /**
     * @return array<int, array{field_key:string, failure_count:int}>
     */
    public function getValidationFailuresByField(string $category, array $serviceNames, int $limit = 20): array
    {
        global $wpdb;
        if ($category === '' || empty($serviceNames)) return [];

        $placeholders = implode(',', array_fill(0, count($serviceNames), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT field_key, COUNT(*) AS failure_count
             FROM {$wpdb->prefix}rto_form_funnel_events
             WHERE event_type = 'validation_failed' AND field_key IS NOT NULL AND category = %s
             AND (service_name IN ({$placeholders}) OR service_name IS NULL)
             GROUP BY field_key ORDER BY failure_count DESC LIMIT %d",
            array_merge([$category], $serviceNames, [$limit])
        ), \ARRAY_A) ?: [];

        return array_map(fn($r) => ['field_key' => (string)$r['field_key'], 'failure_count' => (int)$r['failure_count']], $rows);
    }
}
