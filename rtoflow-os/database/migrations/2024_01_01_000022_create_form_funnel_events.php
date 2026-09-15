<?php
/**
 * Migration 22: Form Funnel Event Tracking
 *
 * ROOT-CAUSE FINDING (checklist item: "Analytics for dynamic form submissions
 * did not exist / requested analytics scope exceeds what schema supports"):
 * FormBuilderController::analytics() could only ever report a completed
 * submission count, because nothing in the schema recorded any signal about
 * a visitor who reached a step but never finished, or who hit a field
 * validation error along the way. Abandonment rate, drop-off-by-step, and
 * per-field validation-failure rate are all impossible to compute without a
 * source of step-reached / validation-failure events distinct from the final
 * rto_form_submissions row.
 *
 * Adds `rto_form_funnel_events`, an anonymous, append-only event log (no
 * user_id — most visitors on this form are pre-login, matching the same
 * anonymous-visitor design already established for rto_form_drafts in
 * migration 19):
 *   - event_type   : 'step_reached' | 'validation_failed' | 'submitted'
 *   - category     : the form category (schemas are resolved by category)
 *   - service_name : the specific sub-service once chosen, nullable until then
 *   - step_index   : 0-based step number the event pertains to
 *   - field_key    : the specific field that failed validation (only set for
 *                    'validation_failed' events; null otherwise)
 *   - session_token: an opaque per-page-load random token (NOT the draft
 *                    token, NOT a WordPress session/user id) so a single
 *                    visitor's step_reached events can be correlated into one
 *                    funnel without identifying them
 *   - created_at   : event timestamp
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, matching every other migration in
 * this set.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateFormFunnelEvents extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_form_funnel_events (
            id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_type     VARCHAR(30) NOT NULL,
            category       VARCHAR(100) NOT NULL,
            service_name   VARCHAR(150) NULL,
            step_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            field_key      VARCHAR(100) NULL,
            session_token  VARCHAR(64) NOT NULL,
            created_at     DATETIME NOT NULL,
            KEY idx_category_type (category, event_type),
            KEY idx_session_token (session_token),
            KEY idx_created_at (created_at)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberately a no-op — matches this codebase's convention of never
        // hard-dropping a table that may hold real analytics data on
        // rollback (see migrations 18/19's docblocks for the same reasoning).
    }
}
