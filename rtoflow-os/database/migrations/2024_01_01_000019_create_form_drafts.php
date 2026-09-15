<?php
/**
 * Migration 19: Autosave / Draft Persistence for the dynamic apply form
 *
 * ROOT-CAUSE FINDING: resources/views/public/apply.php (and its dynamic
 * schema-driven Step 2, submitted via Router::submitApplyDynamic()) has no
 * mechanism to persist a visitor's in-progress answers before final
 * submission. A dropped session, an accidental tab close, or simply
 * navigating away mid-form loses every answer typed so far — there is no
 * table, no service, and no endpoint that saves a partial submission.
 *
 * Adds `rto_form_drafts`, a small append/update table keyed by an opaque
 * random `draft_token` handed to the (possibly anonymous, pre-login)
 * visitor's browser — never by session or user id, since most visitors on
 * this form have neither yet. One row per in-progress draft:
 *   - draft_token   : unique, random (FormDraftService::TOKEN_BYTES bytes of
 *                     random_bytes(), hex-encoded) — the only thing the
 *                     client needs to save/restore its own draft.
 *   - category      : the form category this draft belongs to (schemas are
 *                     resolved by category — see submitApplyDynamic()).
 *   - service_id    : nullable FK-by-convention to rto_services, set once
 *                     the visitor has picked a specific service.
 *   - answers_json  : LONGTEXT JSON blob, same shape as the final
 *                     rto_leads.form_data / rto_form_submissions.form_data
 *                     column already used elsewhere in this codebase.
 *   - updated_at    : bumped on every autosave; used for "last saved" UI.
 *   - expires_at    : updated_at + FormDraftService::TTL_HOURS hours — rows
 *                     past this are never returned by loadDraft() and are
 *                     periodically purged by FormDraftService::cleanupExpired().
 *   - ip_hash       : sha256(ip + site salt) via FormDraftService::hashIp(),
 *                     never the raw IP — basic abuse-rate signal only
 *                     (how many drafts one visitor is creating), not an
 *                     identity store.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, matching every other table
 * migration in this set (see migration 16's docblock for the same
 * reasoning applied here).
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateFormDrafts extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_form_drafts (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            draft_token  VARCHAR(64) NOT NULL,
            category     VARCHAR(100) NOT NULL,
            service_id   INT UNSIGNED NULL,
            answers_json LONGTEXT NULL,
            ip_hash      VARCHAR(64) NULL,
            updated_at   DATETIME NOT NULL,
            expires_at   DATETIME NOT NULL,
            UNIQUE KEY uniq_draft_token (draft_token),
            KEY idx_expires_at (expires_at),
            KEY idx_ip_hash (ip_hash)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberately a no-op — matches this codebase's convention of never
        // hard-dropping a table that may hold real (if short-lived)
        // visitor-entered data on rollback (see migration 18's docblock).
    }
}
