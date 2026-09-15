<?php
/**
 * Migration 21: Lead Notes Thread (rto_lead_notes)
 *
 * ROOT-CAUSE FINDING: the "Internal Notes" panel on the order detail
 * screen (resources/views/admin/leads/show.php) was backed by a single
 * `rto_leads.internal_notes` LONGTEXT column (migration 1, line ~149).
 * LeadsController::updateNotes() did a plain
 * `$wpdb->update(..., ['internal_notes' => $notes], ['id' => $leadId])`
 * on every save — a full column overwrite with no author, no timestamp,
 * no history. Two staff members editing the same order silently destroyed
 * each other's text.
 *
 * This migration is ADDITIVE ONLY: it does not touch or drop
 * `rto_leads.internal_notes` (kept for backward-compat/rollback safety —
 * see LeadsController's removed updateNotes() docblock history). All new
 * writes move to this append-only table instead.
 *
 *   rto_lead_notes — one row per note, never updated or deleted:
 *     - lead_id          : the order this note belongs to (indexed).
 *     - author_user_id   : WP user id of the staff member who wrote it.
 *       Deliberately NOT denormalized into an author_name snapshot column
 *       — this codebase's existing analogous feature, the Communication
 *       Thread (`rto_messages`, see LeadsController::addMessage() /
 *       show() `$messages` query), stores only `user_id` and resolves the
 *       display name live via `LEFT JOIN {$p}users u ON u.ID = m.user_id`
 *       at read time; AuditService::forLead() does the same. Matched here
 *       for consistency rather than inventing a new denormalization
 *       pattern for this one feature.
 *     - note_text        : LONGTEXT, matches the column type it replaces.
 *     - created_at        : write time; notes are never edited in place.
 *
 * Idempotent: CREATE TABLE IF NOT EXISTS, matching every other table
 * migration in this set.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateLeadNotes extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_lead_notes (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            lead_id          BIGINT UNSIGNED NOT NULL,
            author_user_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note_text        LONGTEXT NOT NULL,
            created_at       DATETIME NOT NULL,
            KEY idx_lead_created (lead_id, created_at)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberately a no-op — matches this codebase's convention of never
        // hard-dropping a table on rollback (see migration 18/19/20's docblocks).
    }
}
