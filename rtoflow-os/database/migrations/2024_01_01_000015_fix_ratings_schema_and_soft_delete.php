<?php
/**
 * Migration 15: Fix rto_ratings schema mismatch + add soft-delete/edit support
 * (Known Limitations audit — Ratings screen)
 *
 * ROOT-CAUSE FINDING (discovered while implementing the Ratings screen's
 * documented soft-delete/edit fixes, not itself one of the originally
 * documented limitations): the ORIGINAL rto_ratings table — as created by
 * both this migration set's own 2024_01_01_000001_create_core_tables.php
 * AND the separate activation-time app/Database/Schema.php — only ever
 * defined columns (id, lead_id, vendor_id, score, review, created_by,
 * created_at). RatingsController::store()/index()/delete(), however, have
 * always read and written 'rated_by', 'rated_role', and 'comment' — columns
 * that never existed in any CREATE TABLE. A raw $wpdb->insert()/get_results()
 * against unknown columns fails outright (or, for INSERT, is silently
 * ignored since RatingsController::store() never checked the insert's
 * return value) — meaning the entire client-facing "submit a rating" flow
 * and the entire admin Ratings screen have been non-functional against a
 * real database from day one, independent of anything already documented
 * as a "known limitation". This migration is a prerequisite: the
 * soft-delete and edit features below would otherwise be built on a table
 * that cannot accept a rating in the first place.
 *
 * Also adds, per the actual documented known_limitations for this screen:
 *   - deleted_at/deleted_by: soft-delete instead of a permanent,
 *     unrecoverable DELETE.
 *   - edited_at/edited_by/original_score: an edit trail so a corrected
 *     score is auditable rather than silently overwritten.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class FixRatingsSchemaAndSoftDelete extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $table = 'rto_ratings'; // base class's addColumn()/addIndex() prepend $wpdb->prefix

        // ── Columns the controller has always assumed exist ────────────────
        $this->addColumn($table, 'rated_by',   'BIGINT UNSIGNED NULL AFTER vendor_id');
        $this->addColumn($table, 'rated_role', "VARCHAR(20) NOT NULL DEFAULT 'vendor' AFTER rated_by");
        $this->addColumn($table, 'comment',    'TEXT NULL AFTER score');

        // Backfill from the columns that DO exist in the original schema,
        // so any rating row that somehow made it in under the old shape is
        // not silently orphaned from the columns the current code reads.
        $full = $wpdb->prefix . $table;
        $wpdb->query("UPDATE `{$full}` SET rated_by = created_by WHERE rated_by IS NULL AND created_by IS NOT NULL");
        $wpdb->query("UPDATE `{$full}` SET comment = review WHERE comment IS NULL AND review IS NOT NULL");

        // ── Soft-delete support (known_limitation #1: "Deletion is
        // permanent with no undo") ─────────────────────────────────────────
        $this->addColumn($table, 'deleted_at', 'DATETIME NULL AFTER created_at');
        $this->addColumn($table, 'deleted_by', 'BIGINT UNSIGNED NULL AFTER deleted_at');

        // ── Edit trail (known_limitation #2: "No edit action exists for
        // correcting a rating's score") ────────────────────────────────────
        $this->addColumn($table, 'edited_at',     'DATETIME NULL AFTER deleted_by');
        $this->addColumn($table, 'edited_by',     'BIGINT UNSIGNED NULL AFTER edited_at');
        $this->addColumn($table, 'original_score','TINYINT UNSIGNED NULL AFTER edited_by COMMENT "Score at first submission, preserved once an edit occurs"');

        // Index used by every list/average query added in this fix (all now
        // filter WHERE deleted_at IS NULL).
        $this->addIndex($table, 'idx_deleted_at', 'deleted_at');
    }

    public function down(): void
    {
        // Columns intentionally not dropped on rollback to preserve data —
        // matches the established pattern in 2024_01_01_000003_fix_missing_columns.php.
    }
}
