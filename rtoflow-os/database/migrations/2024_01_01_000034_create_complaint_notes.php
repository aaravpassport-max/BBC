<?php
/**
 * Migration 34: Complaint Internal Notes Thread (rto_complaint_notes)
 *
 * ENTERPRISE GAP FIX (Phase 3, item 5 — "Complaints module ... lacks ...
 * internal-only notes distinct from the client-facing response"):
 * ComplaintsController::respond() only ever writes the CLIENT-FACING
 * response (resolution_note / the reply the complainant sees) — there was
 * no separate channel for staff to leave notes about a complaint that
 * should never reach the complainant (e.g. "checked with vendor, they
 * admit the delay", "escalating to manager"). Mirrors
 * migration 2024_01_01_000021_create_lead_notes exactly (same shape,
 * same append-only convention, same "resolve author via LEFT JOIN at read
 * time" pattern) for consistency with the equivalent Leads feature.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class CreateComplaintNotes extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        $c = $wpdb->get_charset_collate();

        $this->run("CREATE TABLE IF NOT EXISTS {$p}rto_complaint_notes (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            complaint_id     BIGINT UNSIGNED NOT NULL,
            author_user_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note_text        LONGTEXT NOT NULL,
            created_at       DATETIME NOT NULL,
            KEY idx_complaint_created (complaint_id, created_at)
        ) {$c};");
    }

    public function down(): void
    {
        // Deliberate no-op, matching this codebase's established convention.
    }
}
