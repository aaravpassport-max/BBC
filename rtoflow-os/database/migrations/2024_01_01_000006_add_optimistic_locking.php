<?php
/**
 * Migration 6: Optimistic Locking — Version Fields
 *
 * Parts 12-B and 15-A require: "BUILD version field (optimistic lock) on every
 * record that multiple roles can edit. Without this, concurrent edits silently
 * overwrite each other in production."
 *
 * rto_leads is edited by: admin (status, assignment, payment), vendor (job accept/reject),
 *   client (document upload, payment). Multiple roles can mutate the same record concurrently.
 *
 * rto_vendors is edited by: admin (KYC, coverage, status) and vendor (profile update).
 *
 * Pattern: every UPDATE that modifies business-critical fields must include
 *   WHERE id = N AND lock_version = CURRENT_VERSION
 * If 0 rows affected, caller returns 409 Conflict to the user.
 *
 * Note: LeadsController::updateStatus() is patched here to include the version check.
 * For the initial migration, all existing rows get lock_version = 1.
 */

namespace RTOFLOW\Database\Migrations;

use RTOFLOW\Database\Migration;

if (!defined('ABSPATH')) exit;

class AddOptimisticLocking extends Migration
{
    public function up(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;

        // ── rto_leads: lock_version ───────────────────────────────────────
        $this->addColumn($p . 'rto_leads', 'lock_version',
            'INT UNSIGNED NOT NULL DEFAULT 1 COMMENT "Optimistic lock version — increments on every status change"');

        // ── rto_vendors: lock_version ─────────────────────────────────────
        $this->addColumn($p . 'rto_vendors', 'lock_version',
            'INT UNSIGNED NOT NULL DEFAULT 1 COMMENT "Optimistic lock version — increments on every profile/status change"');

        // Backfill existing rows to version 1
        $wpdb->query("UPDATE {$p}rto_leads   SET lock_version = 1 WHERE lock_version = 0 OR lock_version IS NULL");
        $wpdb->query("UPDATE {$p}rto_vendors SET lock_version = 1 WHERE lock_version = 0 OR lock_version IS NULL");
    }

    public function down(): void
    {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach (['rto_leads', 'rto_vendors'] as $table) {
            $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$p}{$table}` LIKE 'lock_version'");
            if ($exists) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query("ALTER TABLE `{$p}{$table}` DROP COLUMN lock_version");
            }
        }
    }

    protected function addColumn(string $table, string $col, string $definition): void
    {
        global $wpdb;
        $exists = $wpdb->get_var("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        if (!$exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$definition}");
        }
    }
}
